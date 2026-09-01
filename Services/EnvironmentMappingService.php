<?php

namespace Modules\RondoIntegration\Services;

use App\Mailbox;
use Illuminate\Support\Facades\DB;

class EnvironmentMappingService
{
    public function parse($json, array $allowedKeys)
    {
        $json = trim((string) $json);
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('RONDO_MANAGED_MAILBOX_MAPPINGS must be a JSON object.');
        }

        $result = [];
        $mailboxIds = [];
        foreach ($decoded as $key => $mailboxId) {
            if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
                throw new \InvalidArgumentException('Environment mapping contains an unsupported Rondo mailbox key.');
            }
            if (filter_var($mailboxId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new \InvalidArgumentException('Environment mailbox IDs must be positive integers.');
            }
            $mailboxId = (int) $mailboxId;
            if (in_array($mailboxId, $mailboxIds, true)) {
                throw new \InvalidArgumentException('Each environment-managed mailbox may be mapped only once.');
            }
            $result[$key] = $mailboxId;
            $mailboxIds[] = $mailboxId;
        }

        return $result;
    }

    public function apply(array $catalog)
    {
        $entries = [];
        foreach ($catalog as $entry) {
            if (isset($entry['key'])) {
                $entries[(string) $entry['key']] = $entry;
            }
        }

        $configured = $this->parse(config('rondointegration.managed_mailbox_mappings', ''), array_keys($entries));
        $now = gmdate('Y-m-d H:i:s');
        return DB::transaction(function () use ($configured, $entries, $now) {
        $removedQuery = DB::table('rondo_mailbox_mappings')->where('source', 'environment')->lockForUpdate();
        if ($configured) {
            $removedQuery->whereNotIn('stable_key', array_keys($configured));
        }
        foreach ($removedQuery->get() as $removed) {
            DB::table('rondo_mailbox_mappings')->where('id', $removed->id)->update([
                'state' => $removed->state === 'active' ? 'paused' : $removed->state,
                'source' => 'ui',
                'last_error_code' => 'environment_mapping_removed',
                'updated_at' => $now,
            ]);
        }
        foreach ($configured as $key => $mailboxId) {
            $mailbox = Mailbox::find($mailboxId);
            if (!$mailbox || !$mailbox->isActive()) {
                throw new \RuntimeException('Environment mapping references an inactive or missing mailbox.');
            }
            $duplicate = DB::table('rondo_mailbox_mappings')
                ->where('mailbox_id', $mailboxId)
                ->where('stable_key', '<>', $key)
                ->exists();
            if ($duplicate) {
                throw new \RuntimeException('Environment mapping conflicts with another mailbox mapping.');
            }

            $existing = DB::table('rondo_mailbox_mappings')->where('stable_key', $key)->lockForUpdate()->first();
            $state = $existing && in_array($existing->state, ['active', 'paused'], true) ? $existing->state : 'verified';
            DB::table('rondo_mailbox_mappings')->updateOrInsert(
                ['stable_key' => $key],
                [
                    'mailbox_id' => $mailboxId,
                    'verified_name' => $mailbox->name,
                    'verified_email' => $mailbox->email,
                    'policy_version' => isset($entries[$key]['sidebar_policy']) ? $entries[$key]['sidebar_policy'] : null,
                    'state' => $state,
                    'source' => 'environment',
                    'verified_at' => $now,
                    'last_error_code' => null,
                    'created_at' => $existing ? $existing->created_at : $now,
                    'updated_at' => $now,
                ]
            );
        }

        return $configured;
        });
    }
}

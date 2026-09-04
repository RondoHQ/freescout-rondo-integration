<?php

namespace Modules\RondoIntegration\Services;

use App\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ProvisioningEventService
{
    const CLAIM_TTL_SECONDS = 300;

    private $rondo;
    private $mailboxes;
    private $settings;

    public function __construct(RondoApiClient $rondo, MailboxAccessService $mailboxes, SettingsService $settings)
    {
        $this->rondo = $rondo;
        $this->mailboxes = $mailboxes;
        $this->settings = $settings;
    }

    public function prune($limit = 200)
    {
        $retentionDays = $this->settings->auditRetentionDays();
        if ($retentionDays === null) {
            return 0;
        }
        $ids = DB::table('rondo_provisioning_events')
            ->where('state', 'processed')
            ->where('processed_at', '<', gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400)))
            ->orderBy('id')
            ->limit(max(1, min(200, (int) $limit)))
            ->pluck('id')
            ->all();
        if ($ids === []) {
            return 0;
        }
        return DB::table('rondo_provisioning_events')->whereIn('id', $ids)->delete();
    }

    public function handle(array $event)
    {
        $claim = $this->claim($event['eventId']);
        if ($claim === 'processed') {
            return 'already_processed';
        }
        if ($claim === 'processing') {
            throw new ProvisioningRequestException('event_in_progress', 409);
        }

        try {
            $binding = DB::table('rondo_oidc_bindings')
                ->where('issuer', $event['issuer'])
                ->where('subject', $event['subject'])
                ->where('status', 'active')
                ->whereNotNull('active_user_id')
                ->first();
            if (!$binding) {
                $this->markProcessed($event['eventId']);
                $this->audit($event['eventId'], 'unbound', null);
                return 'unbound';
            }

            $user = User::find($binding->active_user_id);
            if (!$user || $user->isDeleted()) {
                throw new \RuntimeException('user_unavailable');
            }
            $access = $this->rondo->access($binding->issuer, $binding->subject, $user->id);
            $desired = $access['active'] ? $access['managed_mailboxes'] : [];
            $result = DB::transaction(function () use ($user, $desired, $event) {
                $reconciled = $this->mailboxes->reconcile($user, $desired, true);
                $this->markProcessed($event['eventId']);
                return $reconciled;
            }, 3);
            $affected = (int) $result['attached'] + (int) $result['detached'];
            $this->audit($event['eventId'], 'reconciled', $affected);
            return 'reconciled';
        } catch (\Throwable $failure) {
            $reason = $this->safeReason($failure);
            $this->markFailed($event['eventId'], $reason);
            $this->audit($event['eventId'], 'failed', null, $reason);
            throw new ProvisioningRequestException('provisioning_failed', 503);
        }
    }

    private function claim($eventId)
    {
        $row = DB::table('rondo_provisioning_events')->where('event_id', $eventId)->first();
        if (!$row) {
            try {
                $now = gmdate('Y-m-d H:i:s');
                DB::table('rondo_provisioning_events')->insert([
                    'event_id' => $eventId,
                    'state' => 'processing',
                    'attempts' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                return 'claimed';
            } catch (QueryException $failure) {
                unset($failure);
                $row = DB::table('rondo_provisioning_events')->where('event_id', $eventId)->first();
            }
        }
        if (!$row) {
            throw new ProvisioningRequestException('event_claim_failed', 503);
        }
        if ($row->state === 'processed') {
            return 'processed';
        }
        if ($row->state === 'processing' && strtotime((string) $row->updated_at) > time() - self::CLAIM_TTL_SECONDS) {
            return 'processing';
        }
        $claimed = DB::table('rondo_provisioning_events')
            ->where('id', $row->id)
            ->where('state', $row->state)
            ->update([
                'state' => 'processing',
                'attempts' => DB::raw('attempts + 1'),
                'last_error_code' => null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        return $claimed === 1 ? 'claimed' : 'processing';
    }

    private function markProcessed($eventId)
    {
        DB::table('rondo_provisioning_events')->where('event_id', $eventId)->update([
            'state' => 'processed',
            'last_error_code' => null,
            'processed_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function markFailed($eventId, $reason)
    {
        DB::table('rondo_provisioning_events')->where('event_id', $eventId)->update([
            'state' => 'failed',
            'last_error_code' => $reason,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function safeReason(\Throwable $failure)
    {
        $reason = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', (string) $failure->getMessage()));
        return substr(trim($reason, '_') ?: 'unexpected_failure', 0, 64);
    }

    private function audit($eventId, $result, $affected = null, $reason = null)
    {
        DB::table('rondo_integration_audit')->insert([
            'event_type' => 'provisioning_event',
            'actor_user_id' => null,
            'stable_key' => null,
            'old_mailbox_id' => null,
            'new_mailbox_id' => null,
            'affected_count' => (int) ($affected ?: 0),
            'result' => $result,
            'correlation_id' => substr(str_replace('-', '', $eventId), 0, 16),
            'reason' => $reason,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}

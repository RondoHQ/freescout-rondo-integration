<?php

namespace Modules\RondoIntegration\Services;

use App\User;
use Illuminate\Support\Facades\DB;

class MappingImpactService
{
    private $rondo;

    public function __construct(RondoApiClient $rondo)
    {
        $this->rondo = $rondo;
    }

    public function preview($key, $mailboxId, $action)
    {
        $impact = [
            'grant' => 0,
            'move' => 0,
            'revoke' => 0,
            'unchanged' => 0,
            'manual_preserved' => 0,
            'ineligible' => 0,
            'failed' => 0,
        ];
        $managedRows = DB::table('rondo_managed_mailbox_users')->where('stable_key', $key)->get()->keyBy('user_id');

        if ($action === 'disable') {
            $impact['revoke'] = $managedRows->count();
            $managedIds = $managedRows->pluck('user_id')->all();
            $manual = DB::table('mailbox_user')->where('mailbox_id', $mailboxId);
            if ($managedIds) {
                $manual->whereNotIn('user_id', $managedIds);
            }
            $impact['manual_preserved'] = $manual->count();
            return $impact;
        }
        if ($action === 'pause') {
            $impact['unchanged'] = $managedRows->count();
            return $impact;
        }

        foreach (DB::table('rondo_oidc_bindings')->where('status', 'active')->whereNotNull('active_user_id')->orderBy('id')->get() as $binding) {
            try {
                $user = User::find($binding->active_user_id);
                if (!$user || $user->isDeleted()) {
                    throw new \RuntimeException('user_unavailable');
                }
                $access = $this->rondo->access($binding->issuer, $binding->subject, $user->id);
                $desired = $access['active'] && in_array($key, $access['managed_mailboxes'], true);
                $managed = $managedRows->get($user->id);
                if (!$desired) {
                    $impact[$managed ? 'revoke' : 'ineligible']++;
                    continue;
                }
                if ($user->isAdmin()) {
                    $impact['manual_preserved']++;
                    continue;
                }
                $attached = $user->mailboxes()->where('mailboxes.id', $mailboxId)->exists();
                if ($managed && (int) $managed->mailbox_id !== (int) $mailboxId) {
                    $impact['move']++;
                } elseif ($managed) {
                    $impact['unchanged']++;
                } elseif ($attached) {
                    $impact['manual_preserved']++;
                } else {
                    $impact['grant']++;
                }
            } catch (\Exception $e) {
                $impact['failed']++;
            }
        }
        return $impact;
    }
}

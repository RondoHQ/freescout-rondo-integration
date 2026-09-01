<?php

namespace Modules\RondoIntegration\Services;

use App\User;
use Illuminate\Support\Facades\DB;

class AccessReconciler
{
    private $rondo;
    private $mailboxes;

    public function __construct(RondoApiClient $rondo, MailboxAccessService $mailboxes)
    {
        $this->rondo = $rondo;
        $this->mailboxes = $mailboxes;
    }

    public function run($userId = null)
    {
        $query = DB::table('rondo_oidc_bindings')->where('status', 'active')->whereNotNull('active_user_id');
        if ($userId) {
            $query->where('active_user_id', (int) $userId);
        }
        $totals = ['users' => 0, 'attached' => 0, 'detached' => 0, 'unchanged' => 0, 'failed' => 0];
        foreach ($query->orderBy('id')->get() as $binding) {
            try {
                $user = User::find($binding->active_user_id);
                if (!$user || $user->isDeleted()) {
                    throw new \RuntimeException('user_unavailable');
                }
                $access = $this->rondo->access($binding->issuer, $binding->subject, $user->id);
                $result = $this->mailboxes->reconcile($user, $access['active'] ? $access['managed_mailboxes'] : []);
                $totals['users']++;
                foreach (['attached', 'detached', 'unchanged'] as $key) {
                    $totals[$key] += (int) $result[$key];
                }
            } catch (\Exception $e) {
                $totals['failed']++;
            }
        }
        $now = gmdate('Y-m-d H:i:s');
        DB::table('rondo_mailbox_mappings')->where('state', 'active')->update([
            'last_reconciled_at' => $now,
            'last_error_code' => $totals['failed'] ? 'reconcile_partial' : null,
            'updated_at' => $now,
        ]);
        DB::table('rondo_mailbox_mappings')->where('state', 'drifted')->update([
            'last_reconciled_at' => $now,
            'updated_at' => $now,
        ]);
        return $totals;
    }
}

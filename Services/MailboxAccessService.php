<?php

namespace Modules\RondoIntegration\Services;

use App\Mailbox;
use App\User;
use Illuminate\Support\Facades\DB;

class MailboxAccessService
{
    public function mappedIds(array $keys, $lock = false)
    {
        $keys = array_values(array_unique(array_map('strval', $keys)));
        $query = DB::table('rondo_mailbox_mappings')
            ->where('state', 'active')
            ->whereIn('stable_key', $keys)
            ->orderBy('stable_key');
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = $query->get();
        $mapped = [];
        foreach ($rows as $row) {
            $mailbox = Mailbox::find($row->mailbox_id);
            if (!$mailbox || !$mailbox->isActive()
                || !hash_equals((string) $row->verified_name, (string) $mailbox->name)
                || !hash_equals(strtolower((string) $row->verified_email), strtolower((string) $mailbox->email))
            ) {
                DB::table('rondo_mailbox_mappings')->where('id', $row->id)->update([
                    'state' => 'drifted',
                    'last_error_code' => 'mailbox_drift',
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
                continue;
            }
            $mapped[$row->stable_key] = (int) $row->mailbox_id;
        }
        if (count($mapped) !== count($keys)) {
            throw new \RuntimeException('managed_mailbox_unavailable');
        }
        return $mapped;
    }

    public function reconcile(User $user, array $desiredKeys, $lock = false)
    {
        $desired = $this->mappedIds($desiredKeys, $lock);
        $managedQuery = DB::table('rondo_managed_mailbox_users')->where('user_id', $user->id)->orderBy('stable_key');
        if ($lock) {
            $managedQuery->lockForUpdate();
        }
        $managedRows = $managedQuery->get();
        $existingManaged = [];
        foreach ($managedRows as $row) {
            $existingManaged[$row->stable_key] = (int) $row->mailbox_id;
        }
        $attached = 0;
        $detached = 0;
        foreach ($desired as $key => $mailboxId) {
            $alreadyAttached = $user->mailboxes()->where('mailboxes.id', $mailboxId)->exists();
            $alreadyManaged = isset($existingManaged[$key]) && $existingManaged[$key] === $mailboxId;
            $previousManagedId = isset($existingManaged[$key]) ? $existingManaged[$key] : null;
            if (!$alreadyAttached) {
                $user->mailboxes()->attach($mailboxId);
                $attached++;
            }
            if (!$user->isAdmin() && (!$alreadyAttached || $alreadyManaged || $previousManagedId !== null)) {
                DB::table('rondo_managed_mailbox_users')->updateOrInsert(
                    ['stable_key' => $key, 'user_id' => $user->id],
                    ['mailbox_id' => $mailboxId, 'updated_at' => gmdate('Y-m-d H:i:s'), 'created_at' => gmdate('Y-m-d H:i:s')]
                );
            }
            if ($previousManagedId !== null && $previousManagedId !== $mailboxId && !$user->isAdmin()) {
                $user->mailboxes()->detach($previousManagedId);
                $detached++;
            }
        }
        foreach ($existingManaged as $key => $mailboxId) {
            if (!isset($desired[$key])) {
                if (!$user->isAdmin()) {
                    $user->mailboxes()->detach($mailboxId);
                }
                DB::table('rondo_managed_mailbox_users')->where('stable_key', $key)->where('user_id', $user->id)->delete();
                $detached += $user->isAdmin() ? 0 : 1;
            }
        }

        $managedUser = DB::table('rondo_managed_users')->where('user_id', $user->id)->first();
        $remaining = $user->mailboxes()->count();
        if ($remaining === 0) {
            if ($managedUser && !$user->isAdmin()) {
                $user->status = User::STATUS_DISABLED;
                $user->save();
                DB::table('rondo_managed_users')->where('user_id', $user->id)->update([
                    'deactivated_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            $this->invalidateSessions($user);
        } elseif ($managedUser && !$user->isAdmin() && (int) $user->status === User::STATUS_DISABLED) {
            $user->status = User::STATUS_ACTIVE;
            $user->save();
            DB::table('rondo_managed_users')->where('user_id', $user->id)->update([
                'deactivated_at' => null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }

        return [
            'attached' => $attached,
            'detached' => $detached,
            'unchanged' => count($desired) - $attached,
            'remaining' => $remaining,
        ];
    }

    public function invalidateSessions(User $user)
    {
        $user->setRememberToken(bin2hex(random_bytes(30)));
        $user->save();
        DB::table('rondo_managed_users')->where('user_id', $user->id)->increment('session_generation');
        DB::table('rondo_oidc_bindings')->where('active_user_id', $user->id)->increment('session_generation');
    }
}

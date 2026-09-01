<?php

namespace Modules\RondoIntegration\Services;

use App\Email;
use App\User;
use Illuminate\Support\Facades\DB;

class BindingService
{
    private $settings;
    private $mailboxes;

    public function __construct(SettingsService $settings, MailboxAccessService $mailboxes)
    {
        $this->settings = $settings;
        $this->mailboxes = $mailboxes;
    }

    public function resolve(array $identity, array $access, $recoveryToken = null)
    {
        if (!$access['active'] || empty($access['managed_mailboxes'])) {
            throw new \RuntimeException('identity_ineligible');
        }
        $fingerprint = $this->fingerprint($identity['issuer'], $identity['subject']);
        $candidateIds = $this->mailboxes->mappedIds($access['managed_mailboxes']);

        return DB::transaction(function () use ($identity, $fingerprint, $candidateIds, $access, $recoveryToken) {
            $binding = DB::table('rondo_oidc_bindings')->where('identity_fingerprint', $fingerprint)->lockForUpdate()->first();
            if ($binding) {
                if (!hash_equals($binding->issuer, $identity['issuer']) || !hash_equals($binding->subject, $identity['subject'])) {
                    throw new \RuntimeException('identity_collision');
                }
                if ($binding->status !== 'active' || !$binding->active_user_id) {
                    throw new \RuntimeException('binding_unavailable');
                }
                $user = User::lockForUpdate()->find($binding->active_user_id);
                if (!$user || $user->isDeleted()) {
                    throw new \RuntimeException('user_unavailable');
                }
                $this->mailboxes->reconcile($user, $access['managed_mailboxes'], true);
                return $user;
            }

            if ($recoveryToken) {
                return $this->consumeRecovery($recoveryToken, $identity, $fingerprint, $access);
            }

            $email = Email::sanitizeEmail($identity['email']) . '';
            if (!$email) {
                throw new \RuntimeException('email_invalid');
            }
            $candidates = User::whereRaw('LOWER(email) = ?', [strtolower($email)])->lockForUpdate()->get();
            if ($candidates->count() > 1) {
                throw new \RuntimeException('email_ambiguous');
            }
            $created = false;
            if ($candidates->count() === 1) {
                $user = $candidates->first();
                $alreadyBound = DB::table('rondo_oidc_bindings')->where('active_user_id', $user->id)->lockForUpdate()->exists();
                if ($alreadyBound || $user->isDeleted()) {
                    throw new \RuntimeException('user_already_bound');
                }
            } else {
                if (!$this->settings->automaticCreationEnabled() || !$this->creationPrerequisitesMet()) {
                    throw new \RuntimeException('account_creation_disabled');
                }
                $user = $this->createOrdinaryUser($identity);
                $created = true;
            }

            $now = gmdate('Y-m-d H:i:s');
            DB::table('rondo_oidc_bindings')->insert([
                'active_user_id' => $user->id,
                'last_user_id' => $user->id,
                'issuer' => $identity['issuer'],
                'subject' => $identity['subject'],
                'identity_fingerprint' => $fingerprint,
                'status' => 'active',
                'linked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->mailboxes->reconcile($user, $access['managed_mailboxes'], true);
            $this->audit($created ? 'binding_created_with_user' : 'binding_created', $user->id, null, $fingerprint, null);
            return $user;
        }, 3);
    }

    public function activeForUser($userId)
    {
        return DB::table('rondo_oidc_bindings')->where('active_user_id', $userId)->where('status', 'active')->first();
    }

    public function disable(User $user, User $actor, $reason)
    {
        return DB::transaction(function () use ($user, $actor, $reason) {
            $binding = DB::table('rondo_oidc_bindings')->where('active_user_id', $user->id)->lockForUpdate()->first();
            if (!$binding) {
                throw new \RuntimeException('binding_not_found');
            }
            DB::table('rondo_oidc_bindings')->where('id', $binding->id)->update([
                'status' => 'disabled',
                'disabled_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->mailboxes->invalidateSessions($user);
            $this->audit('binding_disabled', $user->id, $binding->identity_fingerprint, null, $reason, $actor->id);
        });
    }

    public function createRecovery(User $user, User $actor, $reason)
    {
        $token = $this->base64Url(random_bytes(32));
        DB::transaction(function () use ($user, $actor, $reason, $token) {
            $binding = DB::table('rondo_oidc_bindings')->where('active_user_id', $user->id)->lockForUpdate()->first();
            if (!$binding) {
                throw new \RuntimeException('binding_not_found');
            }
            DB::table('rondo_oidc_bindings')->where('id', $binding->id)->update([
                'status' => 'recovery_pending',
                'disabled_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            DB::table('rondo_oidc_binding_recoveries')->insert([
                'token_hash' => hash('sha256', $token),
                'target_user_id' => $user->id,
                'actor_user_id' => $actor->id,
                'reason' => $reason,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 600),
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->mailboxes->invalidateSessions($user);
            $this->audit('binding_recovery_started', $user->id, $binding->identity_fingerprint, null, $reason, $actor->id);
        });
        return route('rondointegration.oidc.recovery', ['token' => $token]);
    }

    public function retireForDeletedUser($userId)
    {
        $binding = DB::table('rondo_oidc_bindings')->where('active_user_id', $userId)->first();
        if (!$binding) {
            return;
        }
        DB::table('rondo_oidc_bindings')->where('id', $binding->id)->update([
            'active_user_id' => null,
            'last_user_id' => $userId,
            'status' => 'retired',
            'retired_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->audit('binding_retired_user_deleted', $userId, $binding->identity_fingerprint, null, null);
    }

    public function fingerprint($issuer, $subject)
    {
        return hash('sha256', strlen($issuer) . ':' . $issuer . ':' . strlen($subject) . ':' . $subject);
    }

    private function consumeRecovery($token, array $identity, $fingerprint, array $access)
    {
        $recovery = DB::table('rondo_oidc_binding_recoveries')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('consumed_at')
            ->lockForUpdate()
            ->first();
        if (!$recovery || strtotime($recovery->expires_at) < time()) {
            throw new \RuntimeException('recovery_invalid');
        }
        $old = DB::table('rondo_oidc_bindings')->where('active_user_id', $recovery->target_user_id)->lockForUpdate()->first();
        if (!$old || $old->status !== 'recovery_pending') {
            throw new \RuntimeException('recovery_binding_invalid');
        }
        $user = User::lockForUpdate()->find($recovery->target_user_id);
        if (!$user || $user->isDeleted()) {
            throw new \RuntimeException('recovery_user_invalid');
        }
        $now = gmdate('Y-m-d H:i:s');
        DB::table('rondo_oidc_bindings')->where('id', $old->id)->update([
            'active_user_id' => null,
            'status' => 'retired',
            'retired_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('rondo_oidc_bindings')->insert([
            'active_user_id' => $user->id,
            'last_user_id' => $user->id,
            'issuer' => $identity['issuer'],
            'subject' => $identity['subject'],
            'identity_fingerprint' => $fingerprint,
            'status' => 'active',
            'linked_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('rondo_oidc_binding_recoveries')->where('id', $recovery->id)->update(['consumed_at' => $now, 'updated_at' => $now]);
        $this->mailboxes->reconcile($user, $access['managed_mailboxes'], true);
        $this->audit('binding_replaced', $user->id, $old->identity_fingerprint, $fingerprint, $recovery->reason, $recovery->actor_user_id);
        return $user;
    }

    private function createOrdinaryUser(array $identity)
    {
        $given = substr($identity['given_name'] ?: $identity['name'] ?: 'Rondo', 0, 20);
        $family = substr($identity['family_name'], 0, 30);
        $user = User::create([
            'first_name' => $given,
            'last_name' => $family,
            'email' => $identity['email'],
            'password' => $this->base64Url(random_bytes(48)),
        ]);
        if (!$user) {
            throw new \RuntimeException('user_creation_failed');
        }
        $user->role = User::ROLE_USER;
        $user->status = User::STATUS_ACTIVE;
        $user->invite_state = User::INVITE_STATE_ACTIVATED;
        $user->timezone = config('app.timezone') ?: User::DEFAULT_TIMEZONE;
        $user->save();
        DB::table('rondo_managed_users')->insert([
            'user_id' => $user->id,
            'oidc_only' => true,
            'session_generation' => 1,
            'created_by_rondo_at' => gmdate('Y-m-d H:i:s'),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $user;
    }

    private function creationPrerequisitesMet()
    {
        $visibility = filter_var(env('APP_LIMIT_USER_CUSTOMER_VISIBILITY', false), FILTER_VALIDATE_BOOLEAN);
        $breakGlass = User::where('role', User::ROLE_ADMIN)->where('status', User::STATUS_ACTIVE)->exists();
        $mapping = DB::table('rondo_mailbox_mappings')->where('state', 'active')->exists();
        return $visibility && $breakGlass && $mapping && $this->settings->isVerified() && $this->settings->hasSecrets();
    }

    private function audit($event, $target, $old, $new, $reason, $actor = null)
    {
        DB::table('rondo_oidc_binding_audit')->insert([
            'event_type' => $event,
            'target_user_id' => $target,
            'actor_user_id' => $actor,
            'old_fingerprint' => $old ? $this->shortFingerprint($old) : null,
            'new_fingerprint' => $new ? $this->shortFingerprint($new) : null,
            'reason' => $reason,
            'correlation_id' => substr(bin2hex(random_bytes(8)), 0, 12),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function shortFingerprint($fingerprint)
    {
        $value = preg_match('/^[a-f0-9]{64}$/i', (string) $fingerprint) ? (string) $fingerprint : bin2hex((string) $fingerprint);
        return substr($value, 0, 12);
    }

    private function base64Url($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

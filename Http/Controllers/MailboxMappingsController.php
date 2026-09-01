<?php

namespace Modules\RondoIntegration\Http\Controllers;

use App\Mailbox;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\RondoIntegration\Services\AccessReconciler;
use Modules\RondoIntegration\Services\MailboxAccessService;
use Modules\RondoIntegration\Services\EnvironmentMappingService;
use Modules\RondoIntegration\Services\MappingImpactService;
use Modules\RondoIntegration\Services\RondoApiClient;
use Modules\RondoIntegration\Services\SettingsService;

class MailboxMappingsController extends Controller
{
    public function index(SettingsService $settings, RondoApiClient $rondo, EnvironmentMappingService $environmentMappings)
    {
        $catalog = [];
        $catalogError = false;
        try {
            $response = $rondo->configuration();
            $catalog = isset($response['mappings']) && is_array($response['mappings']) ? $response['mappings'] : [];
            $environmentMappings->apply($catalog);
        } catch (\Exception $e) {
            $catalogError = true;
        }
        $storedMappings = DB::table('rondo_mailbox_mappings')->get()->keyBy('stable_key');
        if (!$catalog) {
            foreach ($storedMappings as $stored) {
                $catalog[] = [
                    'key' => $stored->stable_key,
                    'label' => ucfirst($stored->stable_key),
                    'required_capability' => 'unavailable',
                    'sidebar_policy' => $stored->policy_version ?: 'unavailable',
                    'enabled' => false,
                ];
            }
        }
        return view('rondointegration::settings.mailboxes', [
            'status' => $settings->publicStatus(),
            'catalog' => $catalog,
            'catalog_error' => $catalogError,
            'mappings' => $storedMappings,
            'mailboxes' => Mailbox::all()->filter(function ($mailbox) { return $mailbox->isActive(); }),
            'managed_counts' => DB::table('rondo_managed_mailbox_users')->select('stable_key', DB::raw('COUNT(*) AS total'))->groupBy('stable_key')->pluck('total', 'stable_key'),
        ]);
    }

    public function verify($key, Request $request, RondoApiClient $rondo)
    {
        $request->validate(['mailbox_id' => 'required|integer|min:1']);
        list($mailbox, $entry) = $this->validatedCandidate($key, (int) $request->mailbox_id, $rondo);
        $existing = DB::table('rondo_mailbox_mappings')->where('stable_key', $key)->first();
        if ($existing && $existing->source === 'environment') {
            abort(422, 'This mailbox mapping is managed by the environment.');
        }
        if ($existing && in_array($existing->state, ['active', 'paused'], true)) {
            abort(422, 'Preview the protected Change mailbox workflow for an active or paused mapping.');
        }
        $now = gmdate('Y-m-d H:i:s');
        DB::table('rondo_mailbox_mappings')->updateOrInsert(
            ['stable_key' => $key],
            [
                'mailbox_id' => $mailbox->id,
                'verified_name' => $mailbox->name,
                'verified_email' => $mailbox->email,
                'policy_version' => $entry['sidebar_policy'],
                'state' => 'verified',
                'source' => 'ui',
                'verified_at' => $now,
                'last_error_code' => null,
                'created_at' => $existing ? $existing->created_at : $now,
                'updated_at' => $now,
            ]
        );
        $this->audit('mapping_verified', $key, null, $mailbox->id, 0, 'success');
        \Session::flash('flash_success_floating', __('Mailbox mapping verified. No access changed.'));
        return redirect()->route('rondointegration.mailboxes');
    }

    public function preview($key, Request $request, MappingImpactService $impact, RondoApiClient $rondo)
    {
        $request->validate([
            'action' => 'required|in:activate,pause,resume,disable,change',
            'mailbox_id' => 'nullable|integer|min:1',
            'reason' => 'required|string|min:5|max:1000',
        ]);
        $mapping = DB::table('rondo_mailbox_mappings')->where('stable_key', $key)->first();
        if (!$mapping) {
            abort(404);
        }
        $this->assertTransition($mapping, $request->action);
        if (in_array($request->action, ['activate', 'resume'], true)) {
            $this->assertCatalogCurrent($mapping, $rondo);
        }
        $targetMailbox = null;
        $targetId = (int) $mapping->mailbox_id;
        if ($request->action === 'change') {
            if ($mapping->source === 'environment') {
                abort(422, 'This mailbox mapping is managed by the environment.');
            }
            list($targetMailbox) = $this->validatedCandidate($key, (int) $request->mailbox_id, $rondo);
            $targetId = (int) $targetMailbox->id;
            if ($targetId === (int) $mapping->mailbox_id) {
                abort(422, 'Choose a different mailbox.');
            }
        }
        return view('rondointegration::settings.mapping_preview', [
            'mapping' => $mapping,
            'action' => $request->action,
            'reason' => $request->reason,
            'target_mailbox' => $targetMailbox,
            'impact' => $impact->preview($key, $targetId, $request->action),
        ]);
    }

    public function changeState(
        $key,
        Request $request,
        MailboxAccessService $mailboxes,
        AccessReconciler $reconciler,
        RondoApiClient $rondo
    )
    {
        $request->validate([
            'action' => 'required|in:activate,pause,resume,disable,change',
            'mailbox_id' => 'nullable|integer|min:1',
            'password_current' => 'required|string',
            'reason' => 'required|string|min:5|max:1000',
        ]);
        if (!Hash::check($request->password_current, auth()->user()->password)) {
            return redirect()->back()->withErrors(['password_current' => __('This password is incorrect.')]);
        }
        $mapping = DB::table('rondo_mailbox_mappings')->where('stable_key', $key)->first();
        if (!$mapping) {
            abort(404);
        }
        $action = $request->action;
        $this->assertTransition($mapping, $action);
        if (in_array($action, ['activate', 'resume'], true)) {
            $this->assertCatalogCurrent($mapping, $rondo);
        }
        $targetMailbox = null;
        $entry = null;
        if ($action === 'change') {
            if ($mapping->source === 'environment') {
                abort(422, 'This mailbox mapping is managed by the environment.');
            }
            list($targetMailbox, $entry) = $this->validatedCandidate($key, (int) $request->mailbox_id, $rondo);
        }
        $affected = 0;
        $newMailboxId = $targetMailbox ? (int) $targetMailbox->id : (int) $mapping->mailbox_id;
        DB::transaction(function () use ($mapping, $action, $targetMailbox, $entry) {
            $locked = DB::table('rondo_mailbox_mappings')->where('id', $mapping->id)->lockForUpdate()->first();
            $this->assertTransition($locked, $action);
            $values = ['state' => $this->targetState($locked->state, $action), 'updated_at' => gmdate('Y-m-d H:i:s')];
            if ($targetMailbox) {
                $values = array_merge($values, [
                    'mailbox_id' => $targetMailbox->id,
                    'verified_name' => $targetMailbox->name,
                    'verified_email' => $targetMailbox->email,
                    'policy_version' => $entry['sidebar_policy'],
                    'verified_at' => gmdate('Y-m-d H:i:s'),
                    'last_error_code' => null,
                ]);
            }
            DB::table('rondo_mailbox_mappings')->where('id', $locked->id)->update($values);
        });

        $result = ['attached' => 0, 'detached' => 0, 'failed' => 0];
        if ($action === 'disable') {
            foreach (DB::table('rondo_managed_mailbox_users')->where('stable_key', $key)->pluck('user_id') as $userId) {
                try {
                    $user = \App\User::find($userId);
                    if (!$user) {
                        throw new \RuntimeException('user_unavailable');
                    }
                    $desired = DB::table('rondo_managed_mailbox_users')
                        ->where('user_id', $userId)
                        ->where('stable_key', '<>', $key)
                        ->pluck('stable_key')
                        ->all();
                    $row = $mailboxes->reconcile($user, $desired);
                    $result['detached'] += $row['detached'];
                } catch (\Exception $e) {
                    $result['failed']++;
                }
            }
            $remaining = DB::table('rondo_managed_mailbox_users')->where('stable_key', $key)->count();
            DB::table('rondo_mailbox_mappings')->where('id', $mapping->id)->update([
                'state' => $remaining ? 'disabling' : 'disabled',
                'last_error_code' => $remaining ? 'revoke_partial' : null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $affected = $result['detached'];
        } elseif (in_array($action, ['activate', 'resume', 'change'], true)) {
            $result = $reconciler->run();
            $affected = $result['attached'] + $result['detached'];
        }

        $auditResult = $result['failed'] ? 'partial' : 'success';
        $this->audit('mapping_' . $action, $key, $mapping->mailbox_id, $newMailboxId, $affected, $auditResult, $request->reason);
        $message = $result['failed']
            ? __('Mapping changed; some users remain queued for reconciliation.')
            : __('Mailbox mapping updated and reconciled.');
        \Session::flash($result['failed'] ? 'flash_warning_floating' : 'flash_success_floating', $message);
        return redirect()->route('rondointegration.mailboxes');
    }

    private function validatedCandidate($key, $mailboxId, RondoApiClient $rondo)
    {
        $catalog = $rondo->configuration();
        $entry = $this->catalogEntry($catalog, $key);
        if (!$entry || empty($entry['enabled'])) {
            abort(422, 'Unsupported Rondo mailbox key.');
        }
        $mailbox = Mailbox::findOrFail((int) $mailboxId);
        if (!$mailbox->isActive()) {
            abort(422, 'The selected mailbox is not active.');
        }
        if (DB::table('rondo_mailbox_mappings')->where('mailbox_id', $mailbox->id)->where('stable_key', '<>', $key)->exists()) {
            abort(422, 'This mailbox is already mapped.');
        }
        return [$mailbox, $entry];
    }

    private function assertCatalogCurrent($mapping, RondoApiClient $rondo)
    {
        $entry = $this->catalogEntry($rondo->configuration(), $mapping->stable_key);
        if (!$entry || empty($entry['enabled'])
            || empty($entry['sidebar_policy'])
            || !hash_equals((string) $mapping->policy_version, (string) $entry['sidebar_policy'])
        ) {
            abort(422, 'The verified Rondo mailbox policy is no longer current.');
        }
    }

    private function assertTransition($mapping, $action)
    {
        $transitions = [
            'activate' => ['verified'],
            'pause' => ['active'],
            'resume' => ['paused'],
            'disable' => ['verified', 'paused', 'active', 'drifted', 'disabling'],
            'change' => ['active', 'paused', 'drifted'],
        ];
        if (!isset($transitions[$action]) || !in_array($mapping->state, $transitions[$action], true)) {
            abort(422, 'Invalid mapping state transition.');
        }
    }

    private function targetState($current, $action)
    {
        if ($action === 'pause') {
            return 'paused';
        }
        if ($action === 'disable') {
            return 'disabling';
        }
        return 'active';
    }

    private function catalogEntry(array $catalog, $key)
    {
        foreach (isset($catalog['mappings']) && is_array($catalog['mappings']) ? $catalog['mappings'] : [] as $entry) {
            if (isset($entry['key']) && hash_equals((string) $entry['key'], (string) $key)) {
                return $entry;
            }
        }
        return null;
    }

    private function audit($event, $key, $oldMailbox, $newMailbox, $affected, $result, $reason = null)
    {
        DB::table('rondo_integration_audit')->insert([
            'event_type' => $event,
            'actor_user_id' => auth()->id(),
            'stable_key' => $key,
            'old_mailbox_id' => $oldMailbox,
            'new_mailbox_id' => $newMailbox,
            'affected_count' => $affected,
            'result' => $result,
            'correlation_id' => substr(bin2hex(random_bytes(8)), 0, 12),
            'reason' => $reason,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}

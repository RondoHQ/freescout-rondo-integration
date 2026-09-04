<?php

namespace Modules\RondoIntegration\Http\Controllers;

use App\Mailbox;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\RondoIntegration\Services\OidcClient;
use Modules\RondoIntegration\Services\RondoApiClient;
use Modules\RondoIntegration\Services\SettingsService;

class SettingsController extends Controller
{
    public function index(SettingsService $settings)
    {
        return view('rondointegration::settings.index', [
            'settings' => $settings->all(),
            'status' => $settings->publicStatus(),
            'environment' => [
                'base_url' => (bool) config('rondointegration.base_url'),
                'client_id' => (bool) config('rondointegration.client_id'),
                'client_secret' => (bool) config('rondointegration.client_secret'),
                'signing_key' => (bool) config('rondointegration.signing_key'),
            ],
            'sidebar_mailboxes' => Mailbox::all()->filter(function ($mailbox) { return $mailbox->isActive(); }),
            'sidebar_mailbox_ids' => $settings->sidebarMailboxIds(),
        ]);
    }

    public function save(Request $request, SettingsService $settings)
    {
        $request->validate([
            'base_url' => 'required|string|max:500',
            'client_id' => 'required|string|max:255',
            'client_secret' => 'nullable|string|min:32|max:500',
            'signing_key' => 'nullable|string|min:32|max:500',
            'accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent_surface' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sidebar_max_width' => 'required|integer|min:280|max:420',
            'sidebar_mailboxes' => 'nullable|array',
            'sidebar_mailboxes.*' => 'integer|min:1',
        ]);
        $activeMailboxIds = Mailbox::all()
            ->filter(function ($mailbox) { return $mailbox->isActive(); })
            ->map(function ($mailbox) { return (int) $mailbox->id; })
            ->values()
            ->all();
        $selectedMailboxIds = array_values(array_unique(array_map('intval', (array) $request->input('sidebar_mailboxes', []))));
        if (array_diff($selectedMailboxIds, $activeMailboxIds)) {
            return redirect()->back()->withErrors(['sidebar_mailboxes' => __('Choose active mailboxes only.')])->withInput();
        }
        try {
            $settings->save([
                'base_url' => $request->base_url,
                'client_id' => $request->client_id,
                'client_secret' => $request->client_secret,
                'signing_key' => $request->signing_key,
                'accent' => $request->accent,
                'accent_surface' => $request->accent_surface,
                'sidebar_max_width' => $request->sidebar_max_width,
                'appearance_enabled' => $request->has('appearance_enabled'),
                'automatic_user_creation' => $request->has('automatic_user_creation'),
                'sidebar_mailbox_ids' => $selectedMailboxIds,
            ]);
            \Session::flash('flash_success_floating', __('Rondo Integration settings saved. Verify the connection before use.'));
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['base_url' => $e->getMessage()])->withInput();
        }
        return redirect()->route('rondointegration.settings');
    }

    public function verifyConnection(SettingsService $settings, OidcClient $oidc, RondoApiClient $rondo)
    {
        try {
            $metadata = $oidc->metadata();
            if (!in_array('S256', isset($metadata['code_challenge_methods_supported']) ? $metadata['code_challenge_methods_supported'] : [], true)) {
                throw new \RuntimeException('PKCE S256 is not advertised.');
            }
            $configuration = $rondo->configuration();
            $settings->markVerified($configuration);
            \Session::flash('flash_success_floating', __('Rondo OpenID Connect and signed integration configuration verified.'));
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['connection' => __('Rondo connection verification failed.')]);
        }
        return redirect()->route('rondointegration.settings');
    }
}

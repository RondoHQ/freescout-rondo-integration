<?php

namespace Modules\RondoIntegration\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\RondoIntegration\Services\BindingService;
use Modules\RondoIntegration\Services\OidcClient;
use Modules\RondoIntegration\Services\RondoApiClient;
use Modules\RondoIntegration\Services\SettingsService;

class OidcController extends Controller
{
    const FLOW_SESSION = 'rondointegration.oidc_flow';
    const FLOW_TTL = 600;

    private $settings;
    private $oidc;
    private $rondo;
    private $bindings;

    public function __construct(SettingsService $settings, OidcClient $oidc, RondoApiClient $rondo, BindingService $bindings)
    {
        $this->settings = $settings;
        $this->oidc = $oidc;
        $this->rondo = $rondo;
        $this->bindings = $bindings;
    }

    public function redirectToProvider(Request $request)
    {
        return $this->start($request, null);
    }

    public function recovery(Request $request, $token)
    {
        return $this->start($request, trim((string) $token));
    }

    public function callback(Request $request)
    {
        $flow = $request->session()->pull(self::FLOW_SESSION);
        try {
            if (!is_array($flow) || empty($flow['state']) || empty($flow['nonce']) || empty($flow['verifier'])
                || empty($flow['created_at']) || time() - (int) $flow['created_at'] > self::FLOW_TTL
            ) {
                throw new \RuntimeException('flow_expired');
            }
            $state = (string) $request->get('state', '');
            if (!$state || !hash_equals($flow['state'], $state)) {
                throw new \RuntimeException('state_invalid');
            }
            if ($request->get('error')) {
                throw new \RuntimeException('provider_denied');
            }
            $code = trim((string) $request->get('code', ''));
            if (!$code) {
                throw new \RuntimeException('code_missing');
            }
            $identity = $this->oidc->exchange($code, $flow);
            $access = $this->rondo->access($identity['issuer'], $identity['subject']);
            $user = $this->bindings->resolve($identity, $access, isset($flow['recovery']) ? $flow['recovery'] : null);
            \Auth::login($user, false);
            $generation = \DB::table('rondo_oidc_bindings')->where('active_user_id', $user->id)->value('session_generation');
            if ($generation !== null) {
                $request->session()->put('rondointegration.binding_generation', (int) $generation);
            }
            return redirect($request->session()->pull('url.intended', '/'));
        } catch (\Exception $e) {
            return $this->fail($e);
        } finally {
            $request->session()->forget(self::FLOW_SESSION);
        }
    }

    private function start(Request $request, $recovery)
    {
        try {
            if (!$this->settings->isVerified() || !$this->settings->hasSecrets()) {
                throw new \RuntimeException('configuration_unavailable');
            }
            $flow = [
                'state' => $this->randomValue(32),
                'nonce' => $this->randomValue(32),
                'verifier' => $this->randomValue(64),
                'created_at' => time(),
                'recovery' => $recovery,
            ];
            $request->session()->put(self::FLOW_SESSION, $flow);
            return redirect()->away($this->oidc->authorizationUrl($flow));
        } catch (\Exception $e) {
            return $this->fail($e);
        }
    }

    private function fail(\Exception $failure)
    {
        $reason = $failure->getMessage();
        $allowed = [
            'configuration_unavailable', 'flow_expired', 'state_invalid', 'provider_denied', 'code_missing',
            'identity_ineligible', 'binding_unavailable', 'account_creation_disabled', 'managed_mailbox_unavailable',
        ];
        $safe = in_array($reason, $allowed, true) ? $reason : 'authentication_failed';
        $correlation = substr(bin2hex(random_bytes(8)), 0, 12);
        \Log::warning('Rondo sign-in failed.', [
            'reference' => $correlation,
            'reason' => $safe,
            'exception' => get_class($failure),
            'diagnostic' => $this->diagnosticMessage($failure),
            'location' => basename($failure->getFile()) . ':' . $failure->getLine(),
        ]);
        \Helper::addFloatingFlash('Rondo sign-in failed (' . e($safe) . '). Reference ' . e($correlation) . '.');
        return redirect()->route('login', ['rondo_oauth' => 0]);
    }

    private function diagnosticMessage(\Exception $failure)
    {
        $message = (string) $failure->getMessage();
        $message = @preg_replace('#\b(Bearer|Basic)\s+[A-Za-z0-9._~+/=:-]+#i', '$1 [redacted]', $message);
        $message = is_string($message)
            ? @preg_replace('#([?&](?:code|access_token|id_token|client_secret|token)=)[^&\s]+#i', '$1[redacted]', $message)
            : null;
        return substr(is_string($message) ? $message : 'diagnostic_unavailable', 0, 1000);
    }

    private function randomValue($bytes)
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}

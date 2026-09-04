<?php

namespace Modules\RondoIntegration\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Modules\RondoIntegration\Services\InboundHmacVerifier;
use Modules\RondoIntegration\Services\ProvisioningEventPayload;
use Modules\RondoIntegration\Services\ProvisioningEventService;
use Modules\RondoIntegration\Services\ProvisioningRequestException;
use Modules\RondoIntegration\Services\SettingsService;

class ProvisioningEventsController extends Controller
{
    public function access(
        Request $request,
        InboundHmacVerifier $verifier,
        ProvisioningEventPayload $payload,
        ProvisioningEventService $events,
        SettingsService $settings
    ) {
        try {
            if (!$request->isJson()) {
                throw new ProvisioningRequestException('content_type_invalid', 415);
            }
            $decoded = $verifier->verify(
                $request->getContent(),
                [
                    'timestamp' => $request->header('X-Rondo-Timestamp', ''),
                    'nonce' => $request->header('X-Rondo-Nonce', ''),
                    'signature' => $request->header('X-Rondo-Signature', ''),
                ],
                static function ($nonceHash, $ttl) {
                    // FreeScout's Laravel version interprets integer cache TTLs as minutes.
                    return Cache::add('rondointegration.event_nonce.' . $nonceHash, true, (int) ceil($ttl / 60));
                }
            );
            $event = $payload->validate($decoded, $settings->issuer());
            return response()->json(['status' => $events->handle($event)]);
        } catch (ProvisioningRequestException $failure) {
            return response()->json(['status' => 'error', 'code' => $failure->getMessage()], $failure->httpStatus());
        } catch (\Throwable $failure) {
            try {
                \Log::warning('Rondo provisioning event failed.', ['exception' => get_class($failure)]);
            } catch (\Throwable $loggingFailure) {
                unset($loggingFailure);
            }
            return response()->json(['status' => 'error', 'code' => 'provisioning_failed'], 503);
        }
    }
}

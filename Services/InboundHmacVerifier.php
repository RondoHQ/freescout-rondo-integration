<?php

namespace Modules\RondoIntegration\Services;

class InboundHmacVerifier
{
    const MAX_BODY_BYTES = 8192;
    const CLOCK_SKEW_SECONDS = 300;

    private $settings;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    public function verify($body, array $headers, callable $claimNonce)
    {
        if (!$this->settings->isVerified() || !$this->settings->hasSecrets()) {
            throw new ProvisioningRequestException('integration_unavailable', 503);
        }
        $body = (string) $body;
        if ($body === '' || strlen($body) > self::MAX_BODY_BYTES) {
            throw new ProvisioningRequestException('payload_invalid', 400);
        }
        $timestamp = (string) ($headers['timestamp'] ?? '');
        $nonce = (string) ($headers['nonce'] ?? '');
        $signature = (string) ($headers['signature'] ?? '');
        if (!preg_match('/^[0-9]{10}$/', $timestamp) || abs(time() - (int) $timestamp) > self::CLOCK_SKEW_SECONDS) {
            throw new ProvisioningRequestException('timestamp_invalid', 401);
        }
        if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $nonce)) {
            throw new ProvisioningRequestException('nonce_invalid', 401);
        }
        if (!preg_match('/^v1=([a-f0-9]{64})$/i', $signature, $match)) {
            throw new ProvisioningRequestException('signature_invalid', 401);
        }
        $expected = hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, (string) $this->settings->get('signing_key'));
        if (!hash_equals($expected, strtolower($match[1]))) {
            throw new ProvisioningRequestException('signature_invalid', 401);
        }
        if (!$claimNonce(hash('sha256', $nonce), self::CLOCK_SKEW_SECONDS)) {
            throw new ProvisioningRequestException('request_replayed', 409);
        }
        $payload = json_decode($body, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            throw new ProvisioningRequestException('json_invalid', 400);
        }

        return $payload;
    }
}

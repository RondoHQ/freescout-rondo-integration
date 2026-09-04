<?php

use Modules\RondoIntegration\Services\InboundHmacVerifier;
use Modules\RondoIntegration\Services\ProvisioningEventPayload;
use Modules\RondoIntegration\Services\ProvisioningRequestException;
use Modules\RondoIntegration\Services\SettingsService;
use PHPUnit\Framework\TestCase;

class ProvisioningSecuritySettings extends SettingsService
{
    public function isVerified()
    {
        return true;
    }

    public function hasSecrets()
    {
        return true;
    }

    public function get($key, $default = null)
    {
        return $key === 'signing_key' ? str_repeat('k', 32) : $default;
    }
}

class ProvisioningSecurityTest extends TestCase
{
    public function testExactSignedEventIsAcceptedOnce()
    {
        $body = json_encode([
            'version' => 1,
            'eventId' => '09d8ef3a-fd4d-41d5-8bd7-ebfd74958b37',
            'issuer' => 'https://rondo.example/oauth',
            'subject' => str_repeat('s', 43),
        ], JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $nonce = str_repeat('n', 32);
        $headers = [
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => 'v1=' . hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, str_repeat('k', 32)),
        ];
        $claims = [];
        $claim = static function ($nonceHash, $ttl) use (&$claims) {
            if (isset($claims[$nonceHash])) {
                return false;
            }
            $claims[$nonceHash] = $ttl;
            return true;
        };
        $verifier = new InboundHmacVerifier(new ProvisioningSecuritySettings());
        $decoded = $verifier->verify($body, $headers, $claim);
        $event = (new ProvisioningEventPayload())->validate($decoded, 'https://rondo.example/oauth');

        $this->assertSame('09d8ef3a-fd4d-41d5-8bd7-ebfd74958b37', $event['eventId']);
        $this->assertSame(300, current($claims));

        $this->expectException(ProvisioningRequestException::class);
        $this->expectExceptionMessage('request_replayed');
        $verifier->verify($body, $headers, $claim);
    }

    public function testBodyTamperingIsRejected()
    {
        $body = '{"version":1}';
        $timestamp = (string) time();
        $nonce = str_repeat('n', 32);
        $verifier = new InboundHmacVerifier(new ProvisioningSecuritySettings());

        $this->expectException(ProvisioningRequestException::class);
        $this->expectExceptionMessage('signature_invalid');
        $verifier->verify($body . ' ', [
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => 'v1=' . hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, str_repeat('k', 32)),
        ], static function () { return true; });
    }

    public function testEventSchemaRejectsAdditionalData()
    {
        $this->expectException(ProvisioningRequestException::class);
        $this->expectExceptionMessage('event_schema_invalid');
        (new ProvisioningEventPayload())->validate([
            'version' => 1,
            'eventId' => '09d8ef3a-fd4d-41d5-8bd7-ebfd74958b37',
            'issuer' => 'https://rondo.example/oauth',
            'subject' => str_repeat('s', 43),
            'email' => 'private@example.com',
        ], 'https://rondo.example/oauth');
    }
}

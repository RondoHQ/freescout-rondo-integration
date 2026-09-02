<?php

namespace Modules\RondoIntegration\Services;

class RondoApiClient
{
    const SUPPORTED_MAPPINGS = [
        'ledenadministratie' => [
            'required_capability' => 'ledenadministratie',
            'sidebar_policy' => 'ledenadministratie.v1',
        ],
    ];

    private $settings;
    private $http;
    private $signer;

    public function __construct(SettingsService $settings, BoundedHttpClient $http, HmacSigner $signer)
    {
        $this->settings = $settings;
        $this->http = $http;
        $this->signer = $signer;
    }

    public function configuration()
    {
        $response = $this->signedJson('/wp-json/rondo/v1/integrations/freescout/configuration', [
            'version' => 1,
            'instance' => rtrim(config('app.url'), '/'),
        ]);
        if (!isset($response['version']) || (int) $response['version'] !== 1
            || !isset($response['mappings']) || !is_array($response['mappings'])
        ) {
            throw new \RuntimeException('configuration_response_invalid');
        }
        $seen = [];
        foreach ($response['mappings'] as $entry) {
            if (!is_array($entry) || empty($entry['key']) || !isset(self::SUPPORTED_MAPPINGS[$entry['key']])
                || empty($entry['label']) || !is_string($entry['label'])
                || !isset($entry['enabled']) || !is_bool($entry['enabled'])
            ) {
                throw new \RuntimeException('configuration_mapping_invalid');
            }
            $expected = self::SUPPORTED_MAPPINGS[$entry['key']];
            if (isset($seen[$entry['key']])
                || !isset($entry['required_capability'], $entry['sidebar_policy'])
                || !hash_equals($expected['required_capability'], (string) $entry['required_capability'])
                || !hash_equals($expected['sidebar_policy'], (string) $entry['sidebar_policy'])
            ) {
                throw new \RuntimeException('configuration_mapping_invalid');
            }
            $seen[$entry['key']] = true;
        }
        return $response;
    }

    public function access($issuer, $subject, $userId = null)
    {
        $response = $this->signedJson('/wp-json/rondo/v1/integrations/freescout/access', [
            'version' => 1,
            'issuer' => $issuer,
            'subject' => $subject,
            'freescoutUserId' => $userId ? (int) $userId : null,
        ]);
        if (!isset($response['subject']) || !hash_equals($subject, (string) $response['subject'])
            || !isset($response['active']) || !is_bool($response['active'])
            || !isset($response['managed_mailboxes']) || !is_array($response['managed_mailboxes'])
        ) {
            throw new \RuntimeException('access_response_invalid');
        }
        $keys = array_values(array_unique(array_map('strval', $response['managed_mailboxes'])));
        if (count($keys) !== count($response['managed_mailboxes'])) {
            throw new \RuntimeException('access_response_invalid');
        }
        foreach ($keys as $key) {
            if (!isset(self::SUPPORTED_MAPPINGS[$key])) {
                throw new \RuntimeException('access_response_invalid');
            }
        }
        $response['managed_mailboxes'] = $keys;
        return $response;
    }

    public function sidebar(array $payload)
    {
        $body = $this->encode($payload);
        $headers = array_merge([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $this->signer->headers($body, (string) $this->settings->get('signing_key')));
        return $this->http->json('POST', $this->settings->endpoint('/wp-json/rondo/v1/integrations/freescout/sidebar'), [
            'headers' => $headers,
            'body' => $body,
        ]);
    }

    public function activity(array $payload)
    {
        return $this->signedJson('/wp-json/rondo/v1/integrations/freescout/activity', $payload);
    }

    private function signedJson($path, array $payload)
    {
        $body = $this->encode($payload);
        $headers = array_merge([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $this->signer->headers($body, (string) $this->settings->get('signing_key')));
        return $this->http->json('POST', $this->settings->endpoint($path), [
            'headers' => $headers,
            'body' => $body,
        ]);
    }

    private function encode(array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new \RuntimeException('payload_encoding_failed');
        }
        return $body;
    }
}

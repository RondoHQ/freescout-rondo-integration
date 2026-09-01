<?php

namespace Modules\RondoIntegration\Services;

use GuzzleHttp\Client;

class BoundedHttpClient
{
    private $settings;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    public function json($method, $url, array $options = [])
    {
        list($body, $contentType) = $this->request($method, $url, $options);
        if (stripos($contentType, 'application/json') !== 0) {
            throw new \RuntimeException('unexpected_content_type');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('invalid_json');
        }
        return $decoded;
    }

    public function html($method, $url, array $options = [])
    {
        list($body, $contentType) = $this->request($method, $url, $options);
        if (stripos($contentType, 'text/html') !== 0 && stripos($contentType, 'application/json') !== 0) {
            throw new \RuntimeException('unexpected_content_type');
        }
        return [$body, $contentType];
    }

    private function request($method, $url, array $options)
    {
        $this->assertAllowedUrl($url);
        $client = new Client();
        $options = array_merge([
            'allow_redirects' => false,
            'connect_timeout' => (float) config('rondointegration.connect_timeout', 2.0),
            'timeout' => (float) config('rondointegration.timeout', 5.0),
            'http_errors' => false,
            'verify' => true,
            'stream' => true,
        ], $options);
        $response = $client->request(strtoupper($method), $url, $options);
        $status = (int) $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('http_' . $status);
        }
        $body = '';
        $stream = $response->getBody();
        $maximum = (int) config('rondointegration.max_response_bytes', 262144);
        while (!$stream->eof()) {
            $body .= $stream->read(8192);
            if (strlen($body) > $maximum) {
                throw new \RuntimeException('response_too_large');
            }
        }
        return [$body, (string) $response->getHeaderLine('Content-Type')];
    }

    private function assertAllowedUrl($url)
    {
        $base = parse_url($this->settings->baseUrl());
        $target = parse_url($url);
        if (!is_array($base) || !is_array($target)) {
            throw new \RuntimeException('invalid_destination');
        }
        $basePort = isset($base['port']) ? (int) $base['port'] : ($base['scheme'] === 'https' ? 443 : 80);
        $targetPort = isset($target['port']) ? (int) $target['port'] : ($target['scheme'] === 'https' ? 443 : 80);
        $prefix = rtrim(isset($base['path']) ? $base['path'] : '', '/');
        $path = isset($target['path']) ? $target['path'] : '/';
        if (strtolower($base['scheme']) !== strtolower($target['scheme'])
            || strtolower($base['host']) !== strtolower($target['host'])
            || $basePort !== $targetPort
            || ($prefix && strpos($path, $prefix . '/') !== 0 && $path !== $prefix)
        ) {
            throw new \RuntimeException('destination_not_allowed');
        }
    }
}


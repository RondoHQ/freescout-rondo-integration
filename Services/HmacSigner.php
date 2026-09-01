<?php

namespace Modules\RondoIntegration\Services;

class HmacSigner
{
    public function headers($body, $key)
    {
        if (!is_string($key) || strlen($key) < 32) {
            throw new \RuntimeException('signing_key_invalid');
        }
        $timestamp = (string) time();
        $nonce = $this->base64Url(random_bytes(24));
        $signature = hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $key);
        return [
            'X-Rondo-Timestamp' => $timestamp,
            'X-Rondo-Nonce' => $nonce,
            'X-Rondo-Signature' => 'v1=' . $signature,
        ];
    }

    private function base64Url($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

<?php

namespace Modules\RondoIntegration\Services;

class OidcClient
{
    const CLOCK_SKEW = 60;

    private $settings;
    private $http;

    public function __construct(SettingsService $settings, BoundedHttpClient $http)
    {
        $this->settings = $settings;
        $this->http = $http;
    }

    public function metadata()
    {
        $issuer = $this->settings->issuer();
        $metadata = $this->http->json('GET', $issuer . '/.well-known/openid-configuration', [
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (!isset($metadata['issuer']) || !hash_equals($issuer, (string) $metadata['issuer'])) {
            throw new \RuntimeException('issuer_mismatch');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'jwks_uri'] as $key) {
            if (empty($metadata[$key]) || !is_string($metadata[$key])) {
                throw new \RuntimeException('metadata_incomplete');
            }
        }
        return $metadata;
    }

    public function authorizationUrl(array $flow)
    {
        $metadata = $this->metadata();
        $query = http_build_query([
            'client_id' => $this->settings->get('client_id'),
            'redirect_uri' => route('rondointegration.oidc.callback'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $flow['state'],
            'nonce' => $flow['nonce'],
            'code_challenge' => $this->base64Url(hash('sha256', $flow['verifier'], true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
        return $metadata['authorization_endpoint'] . '?' . $query;
    }

    public function exchange($code, array $flow)
    {
        $metadata = $this->metadata();
        $clientId = (string) $this->settings->get('client_id');
        $secret = (string) $this->settings->get('client_secret');
        $token = $this->http->json('POST', $metadata['token_endpoint'], [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $secret),
            ],
            'body' => http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => route('rondointegration.oidc.callback'),
                'client_id' => $clientId,
                'code_verifier' => $flow['verifier'],
            ], '', '&', PHP_QUERY_RFC3986),
        ]);
        if (empty($token['access_token']) || empty($token['id_token'])
            || empty($token['token_type']) || strcasecmp($token['token_type'], 'Bearer') !== 0
        ) {
            throw new \RuntimeException('token_invalid');
        }

        $claims = $this->validateIdToken(
            $token['id_token'],
            $metadata,
            $flow['nonce'],
            $token['access_token']
        );
        $userinfo = $this->http->json('GET', $metadata['userinfo_endpoint'], [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token['access_token'],
            ],
        ]);
        if (empty($userinfo['sub'])
            || !hash_equals((string) $claims['sub'], (string) $userinfo['sub'])
            || !isset($userinfo['email_verified'])
            || $userinfo['email_verified'] !== true
            || empty($userinfo['email'])
        ) {
            throw new \RuntimeException('userinfo_invalid');
        }

        return [
            'issuer' => (string) $claims['iss'],
            'subject' => (string) $claims['sub'],
            'email' => strtolower(trim((string) $userinfo['email'])),
            'email_verified' => true,
            'name' => isset($userinfo['name']) ? trim((string) $userinfo['name']) : '',
            'given_name' => isset($userinfo['given_name']) ? trim((string) $userinfo['given_name']) : '',
            'family_name' => isset($userinfo['family_name']) ? trim((string) $userinfo['family_name']) : '',
        ];
    }

    private function validateIdToken($jwt, array $metadata, $nonce, $accessToken)
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('id_token_structure');
        }
        $header = json_decode($this->base64UrlDecode($parts[0]), true);
        $claims = json_decode($this->base64UrlDecode($parts[1]), true);
        $signature = $this->base64UrlDecode($parts[2]);
        if (!is_array($header) || !is_array($claims) || !isset($header['alg'], $header['kid']) || $header['alg'] !== 'RS256') {
            throw new \RuntimeException('id_token_header');
        }
        $jwks = $this->http->json('GET', $metadata['jwks_uri'], ['headers' => ['Accept' => 'application/json']]);
        $key = null;
        foreach (isset($jwks['keys']) && is_array($jwks['keys']) ? $jwks['keys'] : [] as $candidate) {
            if (isset($candidate['kid'], $candidate['kty'])
                && hash_equals((string) $header['kid'], (string) $candidate['kid'])
                && $candidate['kty'] === 'RSA'
                && (!isset($candidate['alg']) || $candidate['alg'] === 'RS256')
            ) {
                $key = $candidate;
                break;
            }
        }
        if (!$key || empty($key['n']) || empty($key['e'])) {
            throw new \RuntimeException('jwks_key_missing');
        }
        $pem = $this->rsaPublicKeyPem($this->base64UrlDecode($key['n']), $this->base64UrlDecode($key['e']));
        if (openssl_verify($parts[0] . '.' . $parts[1], $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('id_token_signature');
        }

        $clientId = (string) $this->settings->get('client_id');
        $audiences = isset($claims['aud']) && is_array($claims['aud']) ? $claims['aud'] : [isset($claims['aud']) ? $claims['aud'] : null];
        $now = time();
        if (empty($claims['iss']) || !hash_equals($this->settings->issuer(), (string) $claims['iss'])
            || !in_array($clientId, $audiences, true)
            || (count($audiences) > 1 && (!isset($claims['azp']) || !hash_equals($clientId, (string) $claims['azp'])))
            || empty($claims['sub'])
            || empty($claims['nonce']) || !hash_equals($nonce, (string) $claims['nonce'])
            || !isset($claims['exp'], $claims['iat'])
            || (int) $claims['exp'] < $now - self::CLOCK_SKEW
            || (int) $claims['iat'] > $now + self::CLOCK_SKEW
            || !isset($claims['email_verified']) || $claims['email_verified'] !== true
        ) {
            throw new \RuntimeException('id_token_claims');
        }
        if (isset($claims['at_hash'])) {
            $expected = $this->base64Url(substr(hash('sha256', $accessToken, true), 0, 16));
            if (!is_string($claims['at_hash']) || !hash_equals($expected, $claims['at_hash'])) {
                throw new \RuntimeException('at_hash_invalid');
            }
        }
        return $claims;
    }

    private function rsaPublicKeyPem($modulus, $exponent)
    {
        $rsa = $this->derSequence($this->derInteger($modulus) . $this->derInteger($exponent));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $subject = $this->derSequence($algorithm . "\x03" . $this->derLength(strlen($rsa) + 1) . "\x00" . $rsa);
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($subject), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function derInteger($value)
    {
        $value = ltrim($value, "\x00");
        if ($value === '' || (ord($value[0]) & 0x80)) {
            $value = "\x00" . $value;
        }
        return "\x02" . $this->derLength(strlen($value)) . $value;
    }

    private function derSequence($value)
    {
        return "\x30" . $this->derLength(strlen($value)) . $value;
    }

    private function derLength($length)
    {
        if ($length < 128) {
            return chr($length);
        }
        $encoded = '';
        while ($length > 0) {
            $encoded = chr($length & 0xff) . $encoded;
            $length >>= 8;
        }
        return chr(0x80 | strlen($encoded)) . $encoded;
    }

    private function base64Url($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode($value)
    {
        $padding = strlen($value) % 4;
        if ($padding) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}


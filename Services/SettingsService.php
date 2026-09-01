<?php

namespace Modules\RondoIntegration\Services;

use App\Option;
use Illuminate\Support\Facades\Crypt;

class SettingsService
{
    const OPTION = 'rondointegration.settings';

    public function all()
    {
        $stored = Option::get(self::OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    public function get($key, $default = null)
    {
        $environment = $this->environmentValue($key);
        if ($environment !== null && $environment !== '') {
            return $environment;
        }

        $settings = $this->all();
        if (in_array($key, ['client_secret', 'signing_key'], true)) {
            return $this->decrypt(isset($settings[$key]) ? $settings[$key] : null);
        }
        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    public function save(array $input)
    {
        $current = $this->all();
        $base = $this->normalizeBaseUrl(isset($input['base_url']) ? $input['base_url'] : '');
        if ($base === false) {
            throw new \InvalidArgumentException('Configure a valid HTTPS Rondo base URL.');
        }

        if (!empty($current['base_url']) && $current['base_url'] !== $base) {
            $current['connection_verified_at'] = null;
            $current['verified_base_url'] = null;
        }
        $current['base_url'] = $base;

        foreach (['client_id', 'accent', 'accent_surface', 'sidebar_max_width'] as $key) {
            if (array_key_exists($key, $input)) {
                $current[$key] = trim((string) $input[$key]);
            }
        }
        foreach (['client_secret', 'signing_key'] as $key) {
            if (isset($input[$key]) && trim((string) $input[$key]) !== '') {
                $current[$key] = Crypt::encryptString(trim((string) $input[$key]));
            }
        }
        foreach (['appearance_enabled', 'automatic_user_creation'] as $key) {
            if (array_key_exists($key, $input)) {
                $current[$key] = (bool) $input[$key];
            }
        }

        $this->validateAppearance($current);
        Option::set(self::OPTION, $current);
        return $current;
    }

    public function markVerified()
    {
        $settings = $this->all();
        $settings['verified_base_url'] = $this->baseUrl();
        $settings['verification_fingerprint'] = $this->verificationFingerprint();
        $settings['connection_verified_at'] = gmdate('Y-m-d H:i:s');
        Option::set(self::OPTION, $settings);
    }

    public function isVerified()
    {
        $settings = $this->all();
        return !empty($settings['connection_verified_at'])
            && isset($settings['verified_base_url'])
            && hash_equals((string) $settings['verified_base_url'], (string) $this->baseUrl())
            && !empty($settings['verification_fingerprint'])
            && hash_equals((string) $settings['verification_fingerprint'], $this->verificationFingerprint());
    }

    public function baseUrl()
    {
        return $this->normalizeBaseUrl($this->get('base_url', '')) ?: '';
    }

    public function endpoint($path)
    {
        return rtrim($this->baseUrl(), '/') . '/' . ltrim($path, '/');
    }

    public function issuer()
    {
        return $this->endpoint('/oauth');
    }

    public function hasSecrets()
    {
        return (bool) $this->get('client_id')
            && (bool) $this->get('client_secret')
            && is_string($this->get('signing_key'))
            && strlen($this->get('signing_key')) >= 32;
    }

    public function automaticCreationEnabled()
    {
        $forced = $this->environmentValue('automatic_user_creation');
        if ($forced !== null && filter_var($forced, FILTER_VALIDATE_BOOLEAN) === false) {
            return false;
        }
        return (bool) $this->get('automatic_user_creation', false);
    }

    public function forceLoginEnabled()
    {
        $value = $this->environmentValue('force_login');
        if ($value !== null) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        return (bool) $this->get('force_login', false);
    }

    public function publicStatus()
    {
        return [
            'base_url' => $this->baseUrl(),
            'issuer' => $this->issuer(),
            'discovery_url' => $this->issuer() . '/.well-known/openid-configuration',
            'callback_url' => route('rondointegration.oidc.callback'),
            'sidebar_url' => $this->endpoint('/wp-json/rondo/v1/integrations/freescout/sidebar'),
            'access_url' => $this->endpoint('/wp-json/rondo/v1/integrations/freescout/access'),
            'configuration_url' => $this->endpoint('/wp-json/rondo/v1/integrations/freescout/configuration'),
            'verified' => $this->isVerified(),
            'has_client_secret' => (bool) $this->get('client_secret'),
            'has_signing_key' => (bool) $this->get('signing_key'),
        ];
    }

    private function environmentValue($key)
    {
        $map = [
            'base_url' => 'base_url',
            'client_id' => 'client_id',
            'client_secret' => 'client_secret',
            'signing_key' => 'signing_key',
            'force_login' => 'force_login',
            'automatic_user_creation' => 'automatic_user_creation',
        ];
        if (!isset($map[$key])) {
            return null;
        }
        $value = config('rondointegration.' . $map[$key]);
        return $value === '' ? null : $value;
    }

    private function decrypt($value)
    {
        if (!$value) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function verificationFingerprint()
    {
        return hash('sha256', implode("\n", [
            (string) $this->baseUrl(),
            (string) $this->get('client_id'),
            (string) $this->get('client_secret'),
            (string) $this->get('signing_key'),
        ]));
    }

    private function normalizeBaseUrl($url)
    {
        $url = rtrim(trim((string) $url), '/');
        $parts = parse_url($url);
        if (!$url || !is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $local = $host === 'localhost' || $host === '127.0.0.1' || substr($host, -10) === '.localhost';
        $allowLocal = (bool) config('rondointegration.local_http') && (app()->environment('local') || app()->environment('testing'));
        if ($scheme !== 'https' && !($scheme === 'http' && $local && $allowLocal)) {
            return false;
        }
        return $url;
    }

    private function validateAppearance(array &$settings)
    {
        $accent = isset($settings['accent']) && $settings['accent'] ? $settings['accent'] : '#0069aa';
        $surface = isset($settings['accent_surface']) && $settings['accent_surface'] ? $settings['accent_surface'] : '#d9edf7';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent) || !preg_match('/^#[0-9a-fA-F]{6}$/', $surface)) {
            throw new \InvalidArgumentException('Accent colors must use six-digit hexadecimal values.');
        }
        if ($this->contrast($accent, '#ffffff') < 4.5 || $this->contrast($accent, $surface) < 4.5) {
            throw new \InvalidArgumentException('The accent colors do not meet WCAG AA contrast.');
        }
        $width = isset($settings['sidebar_max_width']) ? (int) $settings['sidebar_max_width'] : 360;
        if ($width < 280 || $width > 420) {
            throw new \InvalidArgumentException('Sidebar width must be between 280 and 420 pixels.');
        }
        $settings['accent'] = strtoupper($accent);
        $settings['accent_surface'] = strtoupper($surface);
        $settings['sidebar_max_width'] = $width;
    }

    private function contrast($first, $second)
    {
        $a = $this->luminance($first);
        $b = $this->luminance($second);
        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    private function luminance($hex)
    {
        $channels = [];
        foreach ([1, 3, 5] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.03928 ? $value / 12.92 : pow(($value + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}

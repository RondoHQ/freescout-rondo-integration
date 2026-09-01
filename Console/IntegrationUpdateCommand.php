<?php

namespace Modules\RondoIntegration\Console;

use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Modules\RondoIntegration\Services\UpdateBackupService;

class IntegrationUpdateCommand extends Command
{
    protected $signature = 'rondo:integration-update {--release=} {--sha256=} {--check} {--install}';
    protected $description = 'Preflight or install one exact, checksum-approved Rondo Integration release';

    public function handle(UpdateBackupService $backups)
    {
        $version = (string) $this->option('release');
        $expected = strtolower((string) $this->option('sha256'));
        if (!preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/', $version) || !preg_match('/^[a-f0-9]{64}$/', $expected)) {
            $this->error('Provide an exact --release=vX.Y.Z and --sha256=<64-hex>.');
            return 2;
        }
        if ((bool) $this->option('check') === (bool) $this->option('install')) {
            $this->error('Choose exactly one of --check or --install.');
            return 2;
        }

        $work = storage_path('app/rondo-update-preflight/' . bin2hex(random_bytes(8)));
        File::makeDirectory($work, 0700, true);
        try {
            $assets = $this->downloadRelease($version, $work);
            $actual = hash_file('sha256', $assets['zip']);
            if (!hash_equals($expected, $actual)) {
                throw new \RuntimeException('artifact_checksum_mismatch');
            }
            $manifest = json_decode(file_get_contents($assets['manifest']), true);
            if (!is_array($manifest) || ($manifest['alias'] ?? null) !== 'rondointegration'
                || ($manifest['version'] ?? null) !== substr($version, 1)
            ) {
                throw new \RuntimeException('manifest_mismatch');
            }
            $checksums = file_get_contents($assets['checksums']);
            if (strpos($checksums, $actual . '  rondo-integration.zip') === false) {
                throw new \RuntimeException('published_checksum_mismatch');
            }
            $this->validateArchive($assets['zip'], $manifest['version']);
            $this->info('Preflight passed for ' . $version . ' (' . $actual . ').');
            if ($this->option('check')) {
                return 0;
            }

            $backup = $backups->create($version);
            try {
                $this->installArchive($assets['zip']);
                $installed = json_decode(file_get_contents(\Module::getPath() . '/RondoIntegration/module.json'), true);
                if (!is_array($installed) || ($installed['version'] ?? null) !== substr($version, 1)) {
                    throw new \RuntimeException('running_version_mismatch');
                }
                \Option::set('rondointegration.update_history', [
                    'tag' => $version,
                    'version' => $installed['version'],
                    'sha256' => $actual,
                    'installed_at' => gmdate('c'),
                    'backup' => $backup,
                ]);
                $this->info('Rondo Integration ' . $version . ' installed and verified.');
            } catch (\Exception $e) {
                $backups->restore($backup);
                \Artisan::call('freescout:module-install', ['module_alias' => 'rondointegration']);
                throw $e;
            }
            return 0;
        } catch (\Exception $e) {
            $this->error('Update failed safely: ' . $e->getMessage());
            return 1;
        } finally {
            File::deleteDirectory($work);
        }
    }

    private function downloadRelease($version, $work)
    {
        $base = 'https://github.com/RondoHQ/freescout-rondo-integration/releases/download/' . rawurlencode($version) . '/';
        $files = [
            'manifest' => 'module.json',
            'zip' => 'rondo-integration.zip',
            'checksums' => 'SHA256SUMS',
            'sbom' => 'rondo-integration.spdx.json',
        ];
        $client = new Client();
        $paths = [];
        foreach ($files as $key => $name) {
            $path = $work . '/' . $name;
            $response = $client->request('GET', $base . $name, [
                'allow_redirects' => ['max' => 3, 'strict' => true, 'referer' => false, 'protocols' => ['https']],
                'connect_timeout' => 5,
                'timeout' => 30,
                'http_errors' => false,
                'sink' => $path,
                'verify' => true,
            ]);
            if ($response->getStatusCode() !== 200 || !is_file($path) || filesize($path) === 0) {
                throw new \RuntimeException('release_asset_unavailable_' . $name);
            }
            $paths[$key] = $path;
        }
        return $paths;
    }

    private function validateArchive($path, $version)
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('invalid_zip');
        }
        $manifestFound = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, 'RondoIntegration/') !== 0 || strpos($name, '../') !== false || substr($name, 0, 1) === '/') {
                $zip->close();
                throw new \RuntimeException('invalid_archive_layout');
            }
            if ($name === 'RondoIntegration/module.json') {
                $inside = json_decode($zip->getFromIndex($i), true);
                $manifestFound = is_array($inside) && ($inside['version'] ?? null) === $version;
            }
        }
        $zip->close();
        if (!$manifestFound) {
            throw new \RuntimeException('archive_manifest_missing');
        }
    }

    private function installArchive($path)
    {
        $modules = \Module::getPath();
        $current = $modules . '/RondoIntegration';
        $staging = $modules . '/.RondoIntegration-' . bin2hex(random_bytes(6));
        File::makeDirectory($staging, 0755, true);
        $zip = new \ZipArchive();
        $zip->open($path);
        $zip->extractTo($staging);
        $zip->close();
        $new = $staging . '/RondoIntegration';
        File::deleteDirectory($current);
        if (!File::moveDirectory($new, $current)) {
            throw new \RuntimeException('module_install_copy_failed');
        }
        File::deleteDirectory($staging);
        \Module::clearCache();
        \Artisan::call('freescout:module-install', ['module_alias' => 'rondointegration']);
        \App\Module::setActive('rondointegration', true);
        \Artisan::call('freescout:clear-cache');
    }
}

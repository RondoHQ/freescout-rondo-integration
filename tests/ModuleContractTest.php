<?php

use PHPUnit\Framework\TestCase;

class ModuleContractTest extends TestCase
{
    public function testManifestUsesTheApprovedStableUpdateContract()
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__) . '/module.json'), true);
        $this->assertSame('rondointegration', $manifest['alias']);
        $this->assertSame('1.0.1', $manifest['version']);
        $this->assertSame('1.8.238', $manifest['requiredAppVersion']);
        $this->assertSame('AGPL-3.0-only', $manifest['license']);
        $this->assertSame('https://github.com/RondoHQ/freescout-rondo-integration/releases/latest/download/module.json', $manifest['latestVersionUrl']);
        $this->assertSame('https://github.com/RondoHQ/freescout-rondo-integration/releases/latest/download/rondo-integration.zip', $manifest['latestVersionZipUrl']);
    }

    public function testRoutesUseTheDedicatedRondoNamespaceAndProtectSidebarAjax()
    {
        $routes = file_get_contents(dirname(__DIR__) . '/Http/routes.php');
        $this->assertStringContainsString("'/rondo/oidc/login'", $routes);
        $this->assertStringContainsString("'/rondo/oidc/callback'", $routes);
        $this->assertStringContainsString("'middleware' => ['web', 'auth']", $routes);
        $this->assertStringNotContainsString('/sidebarwebhook/ajax', $routes);
    }

    public function testRuntimeContainsNoClubSpecificHostname()
    {
        $root = dirname(__DIR__);
        $paths = ['Config', 'Console', 'Database', 'Http', 'Providers', 'Public', 'Resources', 'Services', 'module.json', 'start.php'];
        foreach ($paths as $path) {
            $absolute = $root . '/' . $path;
            if (is_file($absolute)) {
                $this->assertStringNotContainsString('svawc.nl', strtolower(file_get_contents($absolute)));
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $this->assertStringNotContainsString('svawc.nl', strtolower(file_get_contents($file->getPathname())));
                }
            }
        }
    }
}

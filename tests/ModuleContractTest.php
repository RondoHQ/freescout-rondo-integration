<?php

use PHPUnit\Framework\TestCase;

class ModuleContractTest extends TestCase
{
    public function testManifestUsesTheApprovedStableUpdateContract()
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__) . '/module.json'), true);
        $this->assertSame('rondointegration', $manifest['alias']);
        $this->assertSame('/modules/rondointegration/img/rondo-integration.png', $manifest['img']);
        $this->assertSame('1.1.0', $manifest['version']);
        $this->assertSame('1.8.238', $manifest['requiredAppVersion']);
        $this->assertSame('AGPL-3.0-only', $manifest['license']);
        $this->assertSame('https://github.com/RondoHQ/freescout-rondo-integration/releases/latest/download/module.json', $manifest['latestVersionUrl']);
        $this->assertSame('https://github.com/RondoHQ/freescout-rondo-integration/releases/latest/download/rondo-integration.zip', $manifest['latestVersionZipUrl']);
        $this->assertFileExists(dirname(__DIR__) . '/Public/img/rondo-integration.png');
    }

    public function testUpdaterDoesNotUseTheReservedArtisanVersionOption()
    {
        $command = file_get_contents(dirname(__DIR__) . '/Console/IntegrationUpdateCommand.php');
        $this->assertStringContainsString('{--release=}', $command);
        $this->assertStringContainsString("option('release')", $command);
        $this->assertStringNotContainsString('{--version=}', $command);
    }

    public function testUpdaterValidatesThePublishedSbomAgainstTheApprovedZip()
    {
        $command = file_get_contents(dirname(__DIR__) . '/Console/IntegrationUpdateCommand.php');
        $this->assertStringContainsString("validateSbom(\$assets['sbom'], \$manifest['version'], \$actual)", $command);
        $this->assertStringContainsString("'spdxVersion'", $command);
        $this->assertStringContainsString("'checksumValue'", $command);
        $this->assertStringContainsString("'licenseDeclared'", $command);
    }

    public function testCustomerVisibilityPrerequisiteUsesCachedModuleConfiguration()
    {
        $config = file_get_contents(dirname(__DIR__) . '/Config/config.php');
        $settings = file_get_contents(dirname(__DIR__) . '/Services/SettingsService.php');
        $binding = file_get_contents(dirname(__DIR__) . '/Services/BindingService.php');
        $view = file_get_contents(dirname(__DIR__) . '/Resources/views/settings/mailboxes.blade.php');

        $this->assertStringContainsString("'limit_user_customer_visibility' => env('APP_LIMIT_USER_CUSTOMER_VISIBILITY', false)", $config);
        $this->assertStringContainsString("config('rondointegration.limit_user_customer_visibility', false)", $settings);
        $this->assertStringContainsString('customerVisibilityRestricted()', $binding);
        $this->assertStringContainsString("\$status['customer_visibility_restricted']", $view);
        $this->assertStringNotContainsString("env('APP_LIMIT_USER_CUSTOMER_VISIBILITY'", $binding);
        $this->assertStringNotContainsString("env('APP_LIMIT_USER_CUSTOMER_VISIBILITY'", $view);
    }

    public function testOidcFailuresLogSafeDiagnosticsWithTheVisibleReference()
    {
        $controller = file_get_contents(dirname(__DIR__) . '/Http/Controllers/OidcController.php');

        $admin = file_get_contents(dirname(__DIR__) . '/Http/Controllers/BindingAdminController.php');
        $view = file_get_contents(dirname(__DIR__) . '/Resources/views/settings/bindings.blade.php');

        $this->assertStringContainsString("\\Log::error('Rondo sign-in failed.'", $controller);
        $this->assertStringContainsString("'reference' => \$correlation", $controller);
        $this->assertStringContainsString("if (\$safe === 'authentication_failed')", $controller);
        $this->assertStringContainsString("'event_type' => 'oidc_sign_in_failed'", $controller);
        $this->assertStringContainsString("'reason' => \$details", $controller);
        $this->assertStringContainsString("'exception' => get_class(\$failure)", $controller);
        $this->assertStringContainsString("'location' => basename(\$failure->getFile())", $controller);
        $this->assertStringContainsString("'$1 [redacted]'", $controller);
        $this->assertStringContainsString("'$1[redacted]'", $controller);
        $this->assertStringContainsString("->where('event_type', 'oidc_sign_in_failed')", $admin);
        $this->assertStringContainsString('Recent technical sign-in failures', $view);
        $this->assertStringContainsString('{{ $failure->correlation_id }}', $view);
    }

    public function testRoutesUseTheDedicatedRondoNamespaceAndProtectSidebarAjax()
    {
        $routes = file_get_contents(dirname(__DIR__) . '/Http/routes.php');
        $this->assertStringContainsString("'/rondo/oidc/login'", $routes);
        $this->assertStringContainsString("'/rondo/oidc/callback'", $routes);
        $this->assertStringContainsString("'middleware' => ['web', 'auth']", $routes);
        $this->assertStringNotContainsString('/sidebarwebhook/ajax', $routes);
    }

    public function testSidebarLoadsSrcdocOnlyAfterTheFrameIsVisible()
    {
        $javascript = file_get_contents(dirname(__DIR__) . '/Public/js/module.js');
        $visible = strpos($javascript, ".removeClass('hide');");
        $navigate = strpos($javascript, 'frame.srcdoc = response.srcdoc;');

        $this->assertNotFalse($visible);
        $this->assertNotFalse($navigate);
        $this->assertLessThan($navigate, $visible);
        $this->assertStringContainsString('window.requestAnimationFrame(function ()', $javascript);
        $this->assertStringContainsString('data.rendered !== true', $javascript);
        $this->assertStringContainsString(".one('load.rondoSidebar'", $javascript);
        $this->assertStringContainsString(".css('height', '700px')", $javascript);
        $this->assertStringNotContainsString(".attr('srcdoc', response.srcdoc)", $javascript);
    }

    public function testSidebarHeightReporterIsAnExternalCspCompatibleAsset()
    {
        $javascript = file_get_contents(dirname(__DIR__) . '/Public/js/sidebar-frame.js');

        $this->assertStringContainsString("body.getAttribute('data-rondo-channel')", $javascript);
        $this->assertStringContainsString("body.getAttribute('data-rondo-parent-origin')", $javascript);
        $this->assertStringContainsString("type: 'rondo-sidebar-height'", $javascript);
        $this->assertStringContainsString('parent.postMessage', $javascript);
        $this->assertStringContainsString('new ResizeObserver(sendHeight)', $javascript);
    }

    public function testConversationActivitiesUseABoundedScheduledDeliveryQueue()
    {
        $provider = file_get_contents(dirname(__DIR__) . '/Providers/RondoIntegrationServiceProvider.php');
        $delivery = file_get_contents(dirname(__DIR__) . '/Services/ActivityDeliveryService.php');
        $queue = file_get_contents(dirname(__DIR__) . '/Services/ActivityQueueService.php');
        $migration = file_get_contents(dirname(__DIR__) . '/Database/Migrations/2026_09_01_000002_create_rondo_managed_access_tables.php');

        $this->assertStringContainsString("rondo:deliver-activities')->everyMinute()->withoutOverlapping()", $provider);
        $this->assertStringContainsString('const BATCH_SIZE = 25;', $delivery);
        $this->assertStringContainsString("Conversation::with(['customer.emails'])", $delivery);
        $this->assertStringContainsString('Integration queue failures must not block normal FreeScout conversation handling.', $queue);
        $this->assertStringContainsString("'/wp-json/rondo/v1/integrations/freescout/activity'", file_get_contents(dirname(__DIR__) . '/Services/RondoApiClient.php'));
        $this->assertStringNotContainsString('customer_email', strtolower($migration));
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

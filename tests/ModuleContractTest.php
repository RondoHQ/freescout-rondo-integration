<?php

use PHPUnit\Framework\TestCase;

class ModuleContractTest extends TestCase
{
    public function testManifestUsesTheApprovedStableUpdateContract()
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__) . '/module.json'), true);
        $this->assertSame('rondointegration', $manifest['alias']);
        $this->assertSame('/modules/rondointegration/img/rondo-integration.png', $manifest['img']);
        $this->assertSame('1.13.0', $manifest['version']);
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

    public function testClosedMailboxCatalogSupportsMembershipAndContributionPolicies()
    {
        $client = file_get_contents(dirname(__DIR__) . '/Services/RondoApiClient.php');

        $this->assertStringContainsString("'ledenadministratie' => [", $client);
        $this->assertStringContainsString("'sidebar_policy' => 'ledenadministratie.v2'", $client);
        $this->assertStringContainsString("'contributie' => [", $client);
        $this->assertStringContainsString("'required_capability' => 'financieel'", $client);
        $this->assertStringContainsString("'sidebar_policy' => 'contributie.v1'", $client);
    }

    public function testSidebarMailboxSelectionIsSeparateFromManagedAccessMappings()
    {
        $provider = file_get_contents(dirname(__DIR__) . '/Providers/RondoIntegrationServiceProvider.php');
        $controller = file_get_contents(dirname(__DIR__) . '/Http/Controllers/SidebarController.php');
        $settings = file_get_contents(dirname(__DIR__) . '/Services/SettingsService.php');
        $settingsController = file_get_contents(dirname(__DIR__) . '/Http/Controllers/SettingsController.php');
        $view = file_get_contents(dirname(__DIR__) . '/Resources/views/settings/index.blade.php');
        $client = file_get_contents(dirname(__DIR__) . '/Services/RondoApiClient.php');

        $this->assertStringContainsString('sidebarEnabledForMailbox($mailbox->id)', $provider);
        $this->assertStringContainsString('sidebarEnabledForMailbox($conversation->mailbox_id)', $controller);
        $this->assertStringContainsString("\$mapping ? \$mapping->stable_key : 'basis'", $controller);
        $this->assertStringContainsString('function sidebarMailboxIds()', $settings);
        $this->assertStringContainsString("'sidebar_mailboxes' => 'nullable|array'", $settingsController);
        $this->assertStringContainsString('name="sidebar_mailboxes[]"', $view);
        $this->assertStringContainsString("hash_equals('basis.v1'", $client);
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
        $this->assertStringContainsString("event.target.matches('[data-rondo-profile-switcher]')", $javascript);
        $this->assertStringContainsString("event.target.closest('[data-rondo-tab]')", $javascript);
        $this->assertStringContainsString("card.querySelectorAll('[data-rondo-tab-panel]')", $javascript);
        $this->assertStringContainsString("querySelectorAll('[data-rondo-profile-panel]')", $javascript);
    }

    public function testSidebarUsesCompactPlacementAndASecondaryActionStyle()
    {
        $moduleCss = file_get_contents(dirname(__DIR__) . '/Public/css/module.css');
        $document = file_get_contents(dirname(__DIR__) . '/Services/SidebarDocument.php');

        $this->assertStringContainsString('.conv-sidebar-block.rondo-sidebar', $moduleCss);
        $this->assertStringContainsString('margin-top: 4px;', $moduleCss);
        $this->assertStringContainsString('.rondo-action--secondary', $document);
    }

    public function testSidebarSendsOnlyTheValidatedSportlinkPersonReference()
    {
        $controller = file_get_contents(dirname(__DIR__) . '/Http/Controllers/SidebarController.php');

        $this->assertStringContainsString("Thread::TYPE_CUSTOMER", $controller);
        $this->assertStringContainsString("Thread::STATE_PUBLISHED", $controller);
        $this->assertStringContainsString("'personReference'", $controller);
        $this->assertStringContainsString("'source' => 'sportlink_transfer_request'", $controller);
        $this->assertStringNotContainsString("'messageHtml'", $controller);
        $this->assertStringNotContainsString("'messageBody'", $controller);
    }

    public function testSidebarAndActivitiesShareInternalSenderRecipientMatching()
    {
        $controller = file_get_contents(dirname(__DIR__) . '/Http/Controllers/SidebarController.php');
        $delivery = file_get_contents(dirname(__DIR__) . '/Services/ActivityDeliveryService.php');
        $emails = file_get_contents(dirname(__DIR__) . '/Services/CustomerEmailService.php');

        $this->assertStringContainsString('forConversation($conversation->customer, $firstIncomingThread, $conversation->mailbox)', $controller);
        $this->assertStringContainsString('forConversation($conversation->customer, $firstIncomingThread, $conversation->mailbox)', $delivery);
        $this->assertStringContainsString('mailboxDomainRecipients($firstIncomingThread, $mailbox)', $emails);
        $this->assertStringContainsString('getToArray()', $emails);
        $this->assertStringContainsString('getEmails()', $emails);
        $this->assertStringContainsString("\$payload['fromName']", $controller);
        $this->assertStringContainsString('getFullName()', $controller);
    }

    public function testMailboxDomainConversationsCanSwitchTheirNativeFreeScoutCustomer()
    {
        $provider = file_get_contents(dirname(__DIR__) . '/Providers/RondoIntegrationServiceProvider.php');
        $service = file_get_contents(dirname(__DIR__) . '/Services/ConversationCustomerService.php');
        $command = file_get_contents(dirname(__DIR__) . '/Console/ReconcileConversationCustomerCommand.php');

        $this->assertStringContainsString("addFilter('conversation.created_by_customer'", $provider);
        $this->assertStringContainsString("addFilter('conversation.customer_replied'", $provider);
        $this->assertStringContainsString('sidebarEnabledForMailbox($conversation->mailbox_id)', $provider);
        $this->assertStringContainsString('Customer::getByEmail($email)', $service);
        $this->assertStringContainsString("count(\$recipients) !== 1", $service);
        $this->assertStringContainsString("{conversation} {--apply}", $command);
        $this->assertStringContainsString("enqueue('conversation_customer_changed'", $command);
        $this->assertStringNotContainsString('Customer::create(', $service);
    }

    public function testConversationActivitiesUseABoundedScheduledDeliveryQueue()
    {
        $provider = file_get_contents(dirname(__DIR__) . '/Providers/RondoIntegrationServiceProvider.php');
        $delivery = file_get_contents(dirname(__DIR__) . '/Services/ActivityDeliveryService.php');
        $queue = file_get_contents(dirname(__DIR__) . '/Services/ActivityQueueService.php');
        $migration = file_get_contents(dirname(__DIR__) . '/Database/Migrations/2026_09_01_000002_create_rondo_managed_access_tables.php');
        $replyMigration = file_get_contents(dirname(__DIR__) . '/Database/Migrations/2026_09_02_000003_add_thread_id_to_rondo_activity_delivery_queue.php');

        $this->assertStringContainsString("rondo:deliver-activities')->everyMinute()->withoutOverlapping()", $provider);
        $this->assertStringContainsString('const BATCH_SIZE = 25;', $delivery);
        $this->assertStringContainsString("Conversation::with(['customer.emails', 'mailbox'])", $delivery);
        $this->assertStringContainsString('Integration queue failures must not block normal FreeScout conversation handling.', $queue);
        $this->assertStringContainsString("'App\\\\Events\\\\CustomerReplied'", $provider);
        $this->assertStringContainsString("'conversation.user_replied'", $provider);
        $this->assertStringContainsString("['event_type', 'conversation_id', 'thread_id']", $replyMigration);
        $this->assertStringContainsString("Thread::STATE_PUBLISHED", $delivery);
        $this->assertStringNotContainsString('->body', $delivery);
        $this->assertStringContainsString("'/wp-json/rondo/v1/integrations/freescout/activity'", file_get_contents(dirname(__DIR__) . '/Services/RondoApiClient.php'));
        $this->assertStringNotContainsString('customer_email', strtolower($migration));
    }

    public function testRealtimeProvisioningReceiverIsSignedIdempotentAndRetained()
    {
        $routes = file_get_contents(dirname(__DIR__) . '/Http/routes.php');
        $provider = file_get_contents(dirname(__DIR__) . '/Providers/RondoIntegrationServiceProvider.php');
        $controller = file_get_contents(dirname(__DIR__) . '/Http/Controllers/ProvisioningEventsController.php');
        $service = file_get_contents(dirname(__DIR__) . '/Services/ProvisioningEventService.php');
        $migration = file_get_contents(dirname(__DIR__) . '/Database/Migrations/2026_09_03_000004_create_rondo_provisioning_events_table.php');
        $client = file_get_contents(dirname(__DIR__) . '/Services/RondoApiClient.php');

        $this->assertStringContainsString("'/rondo/integration/events/access'", $routes);
        $this->assertStringContainsString('InboundHmacVerifier', $controller);
        $this->assertStringContainsString("Cache::add('rondointegration.event_nonce.'", $controller);
        $this->assertStringContainsString("->where('event_id', \$eventId)", $service);
        $this->assertStringContainsString("->where('state', 'processed')", $service);
        $this->assertStringContainsString("\$table->uuid('event_id')->unique()", $migration);
        $this->assertStringContainsString("rondo:prune-provisioning-events')->daily()->withoutOverlapping()", $provider);
        $this->assertStringContainsString("\$response['audit']['retention_days'] < 90", $client);
        $this->assertStringNotContainsString('email', strtolower($migration));
        $this->assertStringNotContainsString('capability', strtolower($migration));
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

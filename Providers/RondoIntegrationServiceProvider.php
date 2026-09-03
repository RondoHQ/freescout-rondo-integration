<?php

namespace Modules\RondoIntegration\Providers;

use App\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\RondoIntegration\Console\DeliverActivitiesCommand;
use Modules\RondoIntegration\Console\IntegrationUpdateCommand;
use Modules\RondoIntegration\Console\ReconcileAccessCommand;
use Modules\RondoIntegration\Services\ActivityDeliveryPolicy;
use Modules\RondoIntegration\Services\ActivityDeliveryService;
use Modules\RondoIntegration\Services\ActivityQueueService;
use Modules\RondoIntegration\Services\AccessReconciler;
use Modules\RondoIntegration\Services\BindingService;
use Modules\RondoIntegration\Services\EnvironmentMappingService;
use Modules\RondoIntegration\Services\BoundedHttpClient;
use Modules\RondoIntegration\Services\CustomerEmailService;
use Modules\RondoIntegration\Services\HmacSigner;
use Modules\RondoIntegration\Services\MailboxAccessService;
use Modules\RondoIntegration\Services\MappingImpactService;
use Modules\RondoIntegration\Services\OidcClient;
use Modules\RondoIntegration\Services\RondoApiClient;
use Modules\RondoIntegration\Services\SettingsService;
use Modules\RondoIntegration\Services\SidebarDocument;
use Modules\RondoIntegration\Services\SportlinkRelationCodeExtractor;

class RondoIntegrationServiceProvider extends ServiceProvider
{
    const MODULE = 'rondointegration';

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../Config/config.php', self::MODULE);
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(HmacSigner::class);
        $this->app->singleton(BoundedHttpClient::class);
        $this->app->singleton(OidcClient::class);
        $this->app->singleton(RondoApiClient::class);
        $this->app->singleton(MailboxAccessService::class);
        $this->app->singleton(AccessReconciler::class);
        $this->app->singleton(MappingImpactService::class);
        $this->app->singleton(BindingService::class);
        $this->app->singleton(EnvironmentMappingService::class);
        $this->app->singleton(SidebarDocument::class);
        $this->app->singleton(SportlinkRelationCodeExtractor::class);
        $this->app->singleton(ActivityQueueService::class);
        $this->app->singleton(CustomerEmailService::class);
        $this->app->singleton(ActivityDeliveryPolicy::class);
        $this->app->singleton(ActivityDeliveryService::class);
        $this->commands([IntegrationUpdateCommand::class, ReconcileAccessCommand::class, DeliverActivitiesCommand::class]);
    }

    public function boot()
    {
        $this->loadRoutesFrom(__DIR__ . '/../Http/routes.php');
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', self::MODULE);
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->hooks();
    }

    private function hooks()
    {
        \Eventy::addFilter('stylesheets', function ($styles) {
            $styles[] = \Module::getPublicPath(self::MODULE) . '/css/module.css';
            return $styles;
        });
        \Eventy::addFilter('javascripts', function ($scripts) {
            $scripts[] = \Module::getPublicPath(self::MODULE) . '/js/module.js';
            return $scripts;
        });
        \Eventy::addAction('login_form.after', function () {
            $settings = app(SettingsService::class);
            if ($settings->isVerified() && $settings->hasSecrets()) {
                echo view('rondointegration::partials.login_button')->render();
            }
        }, 20);
        \Eventy::addAction('menu.manage.append', function () {
            if (auth()->check() && auth()->user()->isAdmin()) {
                echo '<li><a href="' . e(route('rondointegration.settings')) . '">Rondo Integration</a></li>';
            }
        }, 30);
        \Eventy::addAction('conversation.after_prev_convs', function ($customer, $conversation, $mailbox) {
            if (!auth()->check() || !auth()->user()->can('view', $conversation)) {
                return;
            }
            $active = app(SettingsService::class)->sidebarEnabledForMailbox($mailbox->id);
            if ($active) {
                echo view('rondointegration::partials.sidebar', ['conversation' => $conversation])->render();
            }
        }, -1, 3);
        \Eventy::addAction('body.class', function () {
            $settings = app(SettingsService::class);
            if ((bool) $settings->get('appearance_enabled', true)) {
                echo ' rondo-appearance';
            }
        });
        \Eventy::addAction('layout.head', function () {
            $settings = app(SettingsService::class);
            if (!(bool) $settings->get('appearance_enabled', true)) {
                return;
            }
            $accent = $settings->get('accent', '#0069AA');
            $surface = $settings->get('accent_surface', '#D9EDF7');
            $width = (int) $settings->get('sidebar_max_width', 360);
            echo '<style id="rondo-appearance-vars">:root{--rondo-accent:' . e($accent)
                . ';--rondo-accent-surface:' . e($surface) . ';--rondo-sidebar-max-width:' . $width . 'px}</style>';
        });
        \Eventy::addFilter('middleware.web.custom_handle.response', function ($response, $request) {
            $route = $request->route();
            if ($route && $route->getName() === 'login' && !auth()->check()
                && app(SettingsService::class)->forceLoginEnabled()
                && (int) $request->get('rondo_oauth', -1) !== 0
            ) {
                return redirect()->route('rondointegration.oidc.login');
            }
            return $response;
        }, 20, 2);
        \Eventy::addAction('middleware.web.custom_handle', function ($request) {
            if (!auth()->check()) {
                return;
            }
            $binding = DB::table('rondo_oidc_bindings')->where('active_user_id', auth()->id())->first();
            if (!$binding) {
                return;
            }
            $sessionGeneration = $request->session()->get('rondointegration.binding_generation');
            if ($binding->status !== 'active'
                || ($sessionGeneration !== null && (int) $sessionGeneration !== (int) $binding->session_generation)
            ) {
                auth()->logout();
                $request->session()->invalidate();
            }
        }, 10);
        \Eventy::addFilter('login.custom_check', function ($errors, $request) {
            $email = strtolower(trim((string) $request->get('email')));
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
            if ($user && DB::table('rondo_managed_users')->where('user_id', $user->id)->where('oidc_only', true)->exists()) {
                $errors['email'] = __('Use Sign in with Rondo for this account.');
            }
            return $errors;
        }, 20, 2);
        \Eventy::addAction('user.deleted', function ($deleted) {
            app(BindingService::class)->retireForDeletedUser($deleted->id);
        }, 10, 2);

        Event::listen(PasswordReset::class, function ($event) {
            if (DB::table('rondo_managed_users')->where('user_id', $event->user->id)->where('oidc_only', true)->exists()) {
                $event->user->setPassword(rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '='));
                $event->user->setRememberToken(bin2hex(random_bytes(30)));
                $event->user->save();
            }
        });
        \Eventy::addAction('conversation.created_by_customer', function ($conversation) {
            app(ActivityQueueService::class)->enqueue('conversation_created', $conversation);
        }, 20, 3);
        \Eventy::addAction('conversation.created_by_user_can_undo', function ($conversation) {
            app(ActivityQueueService::class)->enqueue('conversation_created', $conversation);
        }, 20, 2);
        Event::listen('App\\Events\\CustomerReplied', function ($event) {
            app(ActivityQueueService::class)->enqueue('customer_replied', $event->conversation, $event->thread);
        });
        \Eventy::addAction('conversation.user_replied', function ($conversation, $thread) {
            app(ActivityQueueService::class)->enqueue('user_replied', $conversation, $thread);
        }, 20, 2);
        Event::listen('App\\Events\\ConversationCustomerChanged', function ($event) {
            app(ActivityQueueService::class)->enqueue('conversation_customer_changed', $event->conversation);
        });
        \Eventy::addFilter('schedule', function ($schedule) {
            $schedule->command('rondo:reconcile-access')->hourly()->withoutOverlapping();
            $schedule->command('rondo:deliver-activities')->everyMinute()->withoutOverlapping();
            return $schedule;
        });
    }
}

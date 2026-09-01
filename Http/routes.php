<?php

Route::group([
    'middleware' => 'web',
    'prefix' => \Helper::getSubdirectory(),
    'namespace' => 'Modules\\RondoIntegration\\Http\\Controllers',
], function () {
    Route::get('/rondo/oidc/login', 'OidcController@redirectToProvider')
        ->name('rondointegration.oidc.login');
    Route::get('/rondo/oidc/callback', 'OidcController@callback')
        ->name('rondointegration.oidc.callback');
    Route::get('/rondo/oidc/recover/{token}', 'OidcController@recovery')
        ->name('rondointegration.oidc.recovery');
});

Route::group([
    'middleware' => ['web', 'auth'],
    'prefix' => \Helper::getSubdirectory(),
    'namespace' => 'Modules\\RondoIntegration\\Http\\Controllers',
], function () {
    Route::post('/rondo/integration/sidebar', 'SidebarController@load')
        ->name('rondointegration.sidebar.load');
});

Route::group([
    'middleware' => ['web', 'auth', 'roles'],
    'roles' => ['admin'],
    'prefix' => \Helper::getSubdirectory(),
    'namespace' => 'Modules\\RondoIntegration\\Http\\Controllers',
], function () {
    Route::get('/settings/rondo-integration', 'SettingsController@index')
        ->name('rondointegration.settings');
    Route::post('/settings/rondo-integration', 'SettingsController@save')
        ->name('rondointegration.settings.save');
    Route::post('/settings/rondo-integration/verify', 'SettingsController@verifyConnection')
        ->name('rondointegration.settings.verify');
    Route::get('/settings/rondo-integration/mailboxes', 'MailboxMappingsController@index')
        ->name('rondointegration.mailboxes');
    Route::post('/settings/rondo-integration/mailboxes/{key}/verify', 'MailboxMappingsController@verify')
        ->name('rondointegration.mailboxes.verify');
    Route::post('/settings/rondo-integration/mailboxes/{key}/preview', 'MailboxMappingsController@preview')
        ->name('rondointegration.mailboxes.preview');
    Route::post('/settings/rondo-integration/mailboxes/{key}/state', 'MailboxMappingsController@changeState')
        ->name('rondointegration.mailboxes.state');
    Route::get('/settings/rondo-integration/bindings', 'BindingAdminController@index')
        ->name('rondointegration.bindings');
    Route::post('/settings/rondo-integration/bindings/{user}/disable', 'BindingAdminController@disable')
        ->name('rondointegration.bindings.disable');
    Route::post('/settings/rondo-integration/bindings/{user}/replace', 'BindingAdminController@replace')
        ->name('rondointegration.bindings.replace');
});

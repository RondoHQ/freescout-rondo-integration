@extends('layouts.app')

@section('title_full', 'Rondo Integration')

@section('content')
<div class="section-heading">Rondo Integration</div>
@include('partials/flash_messages')
@include('rondointegration::settings._nav')

@if ($errors->any())
    <div class="alert alert-danger"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<div class="panel panel-default">
    <div class="panel-heading"><strong>{{ __('Connection') }}</strong></div>
    <div class="panel-body">
        <p><span class="label label-{{ $status['verified'] ? 'success' : 'warning' }}">{{ $status['verified'] ? __('Verified') : __('Action required') }}</span></p>
        <form class="form-horizontal" method="POST" action="{{ route('rondointegration.settings.save') }}">
            {{ csrf_field() }}
            <div class="form-group">
                <label class="col-sm-3 control-label">{{ __('Rondo base URL') }}</label>
                <div class="col-sm-7"><input class="form-control" type="url" name="base_url" required value="{{ old('base_url', config('rondointegration.base_url') ?: ($settings['base_url'] ?? '')) }}" {{ $environment['base_url'] ? 'readonly' : '' }}></div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label">{{ __('OIDC client ID') }}</label>
                <div class="col-sm-7"><input class="form-control" type="text" name="client_id" required value="{{ old('client_id', config('rondointegration.client_id') ?: ($settings['client_id'] ?? '')) }}" {{ $environment['client_id'] ? 'readonly' : '' }}></div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label">{{ __('OIDC client secret') }}</label>
                <div class="col-sm-7"><input class="form-control" type="password" name="client_secret" placeholder="{{ $status['has_client_secret'] ? '••••••••' : __('Required') }}" {{ $environment['client_secret'] ? 'disabled' : '' }}></div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label">{{ __('HMAC signing key') }}</label>
                <div class="col-sm-7"><input class="form-control" type="password" name="signing_key" placeholder="{{ $status['has_signing_key'] ? '••••••••' : __('Required') }}" {{ $environment['signing_key'] ? 'disabled' : '' }}></div>
            </div>
            <div class="form-group"><label class="col-sm-3 control-label">{{ __('OIDC callback') }}</label><div class="col-sm-7"><p class="form-control-static"><code>{{ $status['callback_url'] }}</code></p></div></div>
            <hr>
            <div class="form-group"><label class="col-sm-3 control-label">{{ __('Interface accent') }}</label><div class="col-sm-3"><input class="form-control" type="text" name="accent" value="{{ old('accent', $settings['accent'] ?? '#0069AA') }}" pattern="#[0-9A-Fa-f]{6}" required></div></div>
            <div class="form-group"><label class="col-sm-3 control-label">{{ __('Interface accent surface') }}</label><div class="col-sm-3"><input class="form-control" type="text" name="accent_surface" value="{{ old('accent_surface', $settings['accent_surface'] ?? '#D9EDF7') }}" pattern="#[0-9A-Fa-f]{6}" required></div></div>
            <div class="form-group"><label class="col-sm-3 control-label">{{ __('Maximum conversation sidebar width') }}</label><div class="col-sm-3"><div class="input-group"><input class="form-control" type="number" name="sidebar_max_width" min="280" max="420" value="{{ old('sidebar_max_width', $settings['sidebar_max_width'] ?? 360) }}" required><span class="input-group-addon">px</span></div></div></div>
            <div class="form-group">
                <label class="col-sm-3 control-label">{{ __('Preview') }}</label>
                <div class="col-sm-7">
                    <div class="rondo-appearance-preview" data-rondo-appearance-preview>
                        <div class="rondo-preview-toolbar">{{ __('Conversation toolbar') }}</div>
                        <div class="rondo-preview-row"><strong>{{ __('Active mailbox') }}</strong><br><a href="#">{{ __('Member profile link') }}</a></div>
                    </div>
                    <p class="help-block">{{ __('The two colors are previewed together; Save validates WCAG AA contrast.') }}</p>
                </div>
            </div>
            <div class="form-group"><div class="col-sm-7 col-sm-offset-3"><label><input type="checkbox" name="appearance_enabled" value="1" {{ old('appearance_enabled', $settings['appearance_enabled'] ?? true) ? 'checked' : '' }}> {{ __('Enable controlled appearance overrides') }}</label></div></div>
            <div class="form-group"><div class="col-sm-7 col-sm-offset-3"><label><input type="checkbox" name="automatic_user_creation" value="1" {{ old('automatic_user_creation', $settings['automatic_user_creation'] ?? false) ? 'checked' : '' }}> {{ __('Allow guarded creation of ordinary Rondo-only agents') }}</label><p class="help-block">{{ __('Requires limited customer visibility, a verified connection, an active mailbox mapping and a local break-glass administrator.') }}</p></div></div>
            <div class="form-group"><div class="col-sm-7 col-sm-offset-3"><button class="btn btn-primary" type="submit">{{ __('Save') }}</button></div></div>
        </form>
        <form method="POST" action="{{ route('rondointegration.settings.verify') }}" class="form-horizontal">
            {{ csrf_field() }}
            <div class="form-group"><div class="col-sm-7 col-sm-offset-3"><button class="btn btn-default" type="submit">{{ __('Verify OIDC discovery') }}</button></div></div>
        </form>
    </div>
</div>
@endsection

@extends('layouts.app')

@section('title_full', __('Mailbox mappings').' - Rondo Integration')

@section('content')
<div class="section-heading">{{ __('Mailbox mappings') }}</div>
@include('partials/flash_messages')
@include('rondointegration::settings._nav')

<div class="alert alert-{{ $status['verified'] && !$catalog_error ? 'success' : 'warning' }}">
    <strong>{{ __('Prerequisites') }}:</strong>
    {{ __('Rondo connection') }}: {{ $status['verified'] ? __('Verified') : __('Action required') }} ·
    {{ __('configuration service') }}: {{ $catalog_error ? __('Action required') : __('Available') }} ·
    APP_LIMIT_USER_CUSTOMER_VISIBILITY: {{ filter_var(env('APP_LIMIT_USER_CUSTOMER_VISIBILITY', false), FILTER_VALIDATE_BOOLEAN) ? __('Verified') : __('Blocking') }}
</div>

@if ($catalog_error)
    <p>{{ __('The verified Rondo configuration service is unavailable. Existing mappings remain visible, but no mapping can be added or changed.') }}</p>
@endif

@foreach ($catalog as $entry)
    @php $mapping = $mappings->get($entry['key']); @endphp
    <div class="panel panel-default">
        <div class="panel-heading"><strong>{{ $entry['label'] }}</strong> <code>{{ $entry['key'] }}</code> <span class="label label-default">{{ $mapping->state ?? 'draft' }}</span></div>
        <div class="panel-body">
            <p>{{ __('Required capability') }}: <code>{{ $entry['required_capability'] }}</code> · {{ __('Sidebar policy') }}: <code>{{ $entry['sidebar_policy'] }}</code> · {{ __('Managed relations') }}: {{ (int) ($managed_counts[$entry['key']] ?? 0) }}</p>
            @if ($mapping)
                <p>{{ __('Verified mailbox') }}: {{ $mapping->verified_name }} &lt;{{ $mapping->verified_email }}&gt; · ID {{ $mapping->mailbox_id }} · {{ __('Source') }}: {{ $mapping->source }}</p>
                <p class="small text-muted">{{ __('Last verified') }}: {{ $mapping->verified_at ?: '—' }} · {{ __('Last reconciled') }}: {{ $mapping->last_reconciled_at ?: '—' }} · {{ __('Health') }}: {{ $mapping->last_error_code ?: __('OK') }}</p>
            @endif
            @if ($mapping && $mapping->source === 'environment')
                <p class="help-block">{{ __('This mailbox selection is locked by RONDO_MANAGED_MAILBOX_MAPPINGS. State changes still require a local administrator password and reason.') }}</p>
            @elseif (!$catalog_error && (!$mapping || !in_array($mapping->state, ['active','paused'])))
            <form class="form-inline" method="POST" action="{{ route('rondointegration.mailboxes.verify', ['key' => $entry['key']]) }}">
                {{ csrf_field() }}
                <select class="form-control" name="mailbox_id" required>
                    <option value="">{{ __('Choose active mailbox') }}</option>
                    @foreach ($mailboxes as $mailbox)<option value="{{ $mailbox->id }}" {{ $mapping && $mapping->mailbox_id == $mailbox->id ? 'selected' : '' }}>{{ $mailbox->name }} &lt;{{ $mailbox->email }}&gt; · ID {{ $mailbox->id }}</option>@endforeach
                </select>
                <button class="btn btn-default" type="submit">{{ __('Verify without changing access') }}</button>
            </form>
            @endif
            @if ($mapping && !$catalog_error && in_array($mapping->state, ['verified','active','paused','drifted','disabling']))
                <hr>
                <form class="form-inline" method="POST" action="{{ route('rondointegration.mailboxes.preview', ['key' => $entry['key']]) }}">
                    {{ csrf_field() }}
                    <select class="form-control" name="action" required>
                        @if ($mapping->state === 'verified')<option value="activate">{{ __('Activate') }}</option>@endif
                        @if ($mapping->state === 'active')<option value="pause">{{ __('Pause') }}</option><option value="disable">{{ __('Disable and revoke') }}</option>@endif
                        @if ($mapping->state === 'paused')<option value="resume">{{ __('Resume') }}</option><option value="disable">{{ __('Disable and revoke') }}</option>@endif
                        @if (in_array($mapping->state, ['verified','drifted']))<option value="disable">{{ __('Disable') }}</option>@endif
                        @if ($mapping->state === 'disabling')<option value="disable">{{ __('Retry pending revocations') }}</option>@endif
                    </select>
                    <input class="form-control" type="text" name="reason" placeholder="{{ __('Reason') }}" minlength="5" required>
                    <button class="btn btn-default" type="submit">{{ __('Preview impact') }}</button>
                </form>
                @if ($mapping->source !== 'environment' && in_array($mapping->state, ['active','paused','drifted']))
                    <form class="form-inline rondo-mapping-change" method="POST" action="{{ route('rondointegration.mailboxes.preview', ['key' => $entry['key']]) }}">
                        {{ csrf_field() }}
                        <input type="hidden" name="action" value="change">
                        <select class="form-control" name="mailbox_id" required>
                            <option value="">{{ __('Choose replacement mailbox') }}</option>
                            @foreach ($mailboxes as $mailbox)
                                @if ($mailbox->id != $mapping->mailbox_id)<option value="{{ $mailbox->id }}">{{ $mailbox->name }} &lt;{{ $mailbox->email }}&gt; · ID {{ $mailbox->id }}</option>@endif
                            @endforeach
                        </select>
                        <input class="form-control" type="text" name="reason" placeholder="{{ __('Reason') }}" minlength="5" required>
                        <button class="btn btn-default" type="submit">{{ __('Preview mailbox change') }}</button>
                    </form>
                @endif
            @endif
        </div>
    </div>
@endforeach
@endsection

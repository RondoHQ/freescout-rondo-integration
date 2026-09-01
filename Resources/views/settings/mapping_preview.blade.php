@extends('layouts.app')

@section('title_full', __('Confirm mailbox mapping').' - Rondo Integration')

@section('content')
<div class="section-heading">{{ __('Confirm mailbox mapping') }}</div>
@include('partials/flash_messages')
@include('rondointegration::settings._nav')

<div class="panel panel-default">
    <div class="panel-heading"><strong>{{ ucfirst($action) }}</strong> · <code>{{ $mapping->stable_key }}</code></div>
    <div class="panel-body">
        @if ($target_mailbox)
            <p>{{ __('Mailbox change') }}: {{ $mapping->verified_name }} · ID {{ $mapping->mailbox_id }} → {{ $target_mailbox->name }} · ID {{ $target_mailbox->id }}</p>
        @endif
        <table class="table table-condensed rondo-impact-table">
            <tbody>
                <tr><th>{{ __('Managed grants') }}</th><td>{{ $impact['grant'] }}</td></tr>
                <tr><th>{{ __('Managed moves') }}</th><td>{{ $impact['move'] }}</td></tr>
                <tr><th>{{ __('Managed revocations') }}</th><td>{{ $impact['revoke'] }}</td></tr>
                <tr><th>{{ __('Unchanged managed access') }}</th><td>{{ $impact['unchanged'] }}</td></tr>
                <tr><th>{{ __('Manual access preserved') }}</th><td>{{ $impact['manual_preserved'] }}</td></tr>
                <tr><th>{{ __('Currently ineligible') }}</th><td>{{ $impact['ineligible'] }}</td></tr>
                <tr class="{{ $impact['failed'] ? 'warning' : '' }}"><th>{{ __('Unresolved failures') }}</th><td>{{ $impact['failed'] }}</td></tr>
            </tbody>
        </table>
        <p class="help-block">{{ __('Only module-owned mailbox relations can be removed. No individual user names or email addresses are shown or logged here.') }}</p>
        <form method="POST" action="{{ route('rondointegration.mailboxes.state', ['key' => $mapping->stable_key]) }}">
            {{ csrf_field() }}
            <input type="hidden" name="action" value="{{ $action }}">
            <input type="hidden" name="reason" value="{{ $reason }}">
            @if ($target_mailbox)<input type="hidden" name="mailbox_id" value="{{ $target_mailbox->id }}">@endif
            <div class="form-group">
                <label>{{ __('Confirm with local administrator password') }}</label>
                <input class="form-control" type="password" name="password_current" required autocomplete="current-password">
            </div>
            <button class="btn btn-warning" type="submit">{{ __('Confirm and reconcile') }}</button>
            <a class="btn btn-link" href="{{ route('rondointegration.mailboxes') }}">{{ __('Cancel') }}</a>
        </form>
    </div>
</div>
@endsection

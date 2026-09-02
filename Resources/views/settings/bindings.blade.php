@extends('layouts.app')

@section('title_full', __('Rondo identities').' - Rondo Integration')

@section('content')
<div class="section-heading">{{ __('Rondo identities') }}</div>
@include('partials/flash_messages')
@include('rondointegration::settings._nav')

<div class="table-responsive"><table class="table">
    <thead><tr><th>{{ __('User') }}</th><th>{{ __('Issuer') }}</th><th>{{ __('Subject fingerprint') }}</th><th>{{ __('Status') }}</th><th>{{ __('Linked') }}</th><th>{{ __('Actions') }}</th></tr></thead>
    <tbody>
    @foreach ($bindings as $binding)
        <tr>
            <td>{{ trim($binding->first_name.' '.$binding->last_name) }} · ID {{ $binding->last_user_id }}</td>
            <td>{{ $binding->issuer }}</td>
            <td><code>{{ substr($binding->identity_fingerprint, 0, 12) }}</code></td>
            <td>{{ $binding->status }}</td>
            <td>{{ $binding->linked_at }}</td>
            <td>
                @if ($binding->active_user_id && in_array($binding->status, ['active','disabled']))
                <details><summary>{{ __('Manage') }}</summary>
                    <form method="POST" action="{{ route('rondointegration.bindings.disable', ['user' => $binding->active_user_id]) }}">{{ csrf_field() }}<input type="password" name="password_current" placeholder="{{ __('Local admin password') }}" required><input type="text" name="reason" placeholder="{{ __('Reason') }}" minlength="5" required><button class="btn btn-xs btn-warning">{{ __('Disable sign-in') }}</button></form>
                    <form method="POST" action="{{ route('rondointegration.bindings.replace', ['user' => $binding->active_user_id]) }}">{{ csrf_field() }}<input type="password" name="password_current" placeholder="{{ __('Local admin password') }}" required><input type="text" name="reason" placeholder="{{ __('Reason') }}" minlength="5" required><button class="btn btn-xs btn-danger">{{ __('Replace identity') }}</button></form>
                </details>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table></div>
{{ $bindings->links() }}

<div class="section-heading">{{ __('Recent technical sign-in failures') }}</div>
<p class="text-help">{{ __('The latest 20 unexpected failures are shown with redacted diagnostics. Use the reference from the login screen to find the matching attempt.') }}</p>
@if (count($failures))
<div class="table-responsive"><table class="table">
    <thead><tr><th>{{ __('Time') }}</th><th>{{ __('Reference') }}</th><th>{{ __('Reason') }}</th><th>{{ __('Technical details') }}</th></tr></thead>
    <tbody>
    @foreach ($failures as $failure)
        <tr>
            <td>{{ $failure->created_at }}</td>
            <td><code>{{ $failure->correlation_id }}</code></td>
            <td><code>{{ $failure->failure_reason }}</code></td>
            <td>
                <details>
                    <summary>{{ $failure->exception ?: __('View diagnostic') }}</summary>
                    @if ($failure->diagnostic)<div style="overflow-wrap:anywhere"><code>{{ $failure->diagnostic }}</code></div>@endif
                    @if ($failure->location)<div class="text-help"><code>{{ $failure->location }}</code></div>@endif
                </details>
            </td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@else
<p>{{ __('No technical Rondo sign-in failures have been recorded.') }}</p>
@endif
@endsection

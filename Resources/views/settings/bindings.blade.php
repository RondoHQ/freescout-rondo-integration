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
@endsection


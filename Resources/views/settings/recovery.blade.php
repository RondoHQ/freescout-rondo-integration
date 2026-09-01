@extends('layouts.app')

@section('title_full', __('Rondo identity recovery'))

@section('content')
<div class="section-heading">{{ __('Rondo identity recovery') }}</div>
<div class="alert alert-warning">{{ __('The previous identity is disabled. Give this single-use link to the intended user; it expires in 10 minutes.') }}</div>
<p><code>{{ $recovery_url }}</code></p>
<p><a class="btn btn-default" href="{{ route('rondointegration.bindings') }}">{{ __('Back to identities') }}</a></p>
@endsection


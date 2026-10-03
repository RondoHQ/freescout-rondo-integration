<div class="form-horizontal rondo-login">
    <div class="form-group">
        <div class="col-md-6 col-md-offset-4">
            <div class="rondo-login-divider">{{ __('of') }}</div>
            <a class="btn rondo-login-button" href="{{ route('rondointegration.oidc.login') }}" aria-describedby="rondo-login-help">
                <svg class="rondo-login-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <path d="M10 17l5-5-5-5M15 12H3M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
                </svg>
                <span>{{ __('Inloggen met Rondo') }}</span>
            </a>
            <p class="rondo-login-help" id="rondo-login-help">{{ __('Gebruik je Rondo-account om in te loggen.') }}</p>
        </div>
    </div>
</div>

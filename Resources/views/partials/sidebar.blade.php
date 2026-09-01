<div class="conv-sidebar-block rondo-sidebar" data-rondo-sidebar data-endpoint="{{ route('rondointegration.sidebar.load') }}" data-conversation-id="{{ (int) $conversation->id }}">
    <div class="rondo-sidebar-status" aria-live="polite">{{ __('Loading live Rondo information…') }}</div>
    <iframe class="rondo-sidebar-frame hide" title="{{ __('Live Rondo information') }}" sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" scrolling="auto"></iframe>
    <div class="text-right small rondo-sidebar-actions">
        <a href="#" class="rondo-sidebar-refresh sidebar-block-link"><i class="glyphicon glyphicon-refresh"></i> {{ __('Refresh') }}</a>
    </div>
</div>


<div class="conv-sidebar-block rondo-sidebar" data-rondo-sidebar data-endpoint="{{ route('rondointegration.sidebar.load') }}" data-link-endpoint="{{ route('rondointegration.sidebar.link') }}" data-conversation-id="{{ (int) $conversation->id }}">
    <div class="rondo-sidebar-status" aria-live="polite">{{ __('Loading live Rondo information…') }}</div>
    <div class="rondo-activity-link hide">
        <label for="rondo-activity-person-{{ (int) $conversation->id }}">Activiteiten koppelen aan…</label>
        <select id="rondo-activity-person-{{ (int) $conversation->id }}" class="form-control rondo-activity-person" aria-describedby="rondo-activity-help-{{ (int) $conversation->id }}"></select>
        <p class="small rondo-activity-help" id="rondo-activity-help-{{ (int) $conversation->id }}" role="status"></p>
        <button type="button" class="btn btn-default btn-sm rondo-activity-save">Keuze opslaan</button>
    </div>
    <iframe class="rondo-sidebar-frame hide" title="{{ __('Live Rondo information') }}" sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" scrolling="auto"></iframe>
    <div class="text-right small rondo-sidebar-actions">
        <a href="#" class="rondo-sidebar-refresh sidebar-block-link"><i class="glyphicon glyphicon-refresh"></i> {{ __('Refresh') }}</a>
    </div>
</div>


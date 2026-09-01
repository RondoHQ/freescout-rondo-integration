(function ($) {
    'use strict';

    function loadSidebar($root) {
        var endpoint = $root.data('endpoint');
        var conversationId = parseInt($root.data('conversation-id'), 10);
        var $status = $root.find('.rondo-sidebar-status');
        var $frame = $root.find('.rondo-sidebar-frame');
        $status.removeClass('hide').text('Loading live Rondo information…');
        $frame.addClass('hide').attr('srcdoc', '');

        $.ajax({
            url: endpoint,
            method: 'POST',
            dataType: 'json',
            data: {conversation_id: conversationId, _token: $('meta[name="csrf-token"]').attr('content')}
        }).done(function (response) {
            if (!response || !response.srcdoc || !response.channel) {
                $status.text('Rondo information is unavailable.');
                return;
            }
            var frame = $frame.get(0);
            $frame.data('channel', response.channel).css('height', '480px').attr('srcdoc', response.srcdoc).removeClass('hide');
            $status.addClass('hide');
            frame.setAttribute('sandbox', 'allow-scripts allow-popups allow-popups-to-escape-sandbox');
        }).fail(function (xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Rondo is temporarily unavailable.';
            $status.text(message);
        });
    }

    window.addEventListener('message', function (event) {
        $('.rondo-sidebar-frame').each(function () {
            var frame = this;
            var data = event.data;
            if (event.source !== frame.contentWindow || !data || data.type !== 'rondo-sidebar-height' || data.version !== 1
                || data.channel !== $(frame).data('channel') || !Number.isFinite(data.height)) {
                return;
            }
            var height = Math.max(160, Math.min(1600, Math.round(data.height)));
            $(frame).css('height', height + 'px');
        });
    });

    $(document).on('click', '.rondo-sidebar-refresh', function (event) {
        event.preventDefault();
        loadSidebar($(this).closest('[data-rondo-sidebar]'));
    });

    $(function () {
        $('[data-rondo-sidebar]').each(function () { loadSidebar($(this)); });
        var $preview = $('[data-rondo-appearance-preview]');
        if ($preview.length) {
            var updatePreview = function () {
                var accent = $('input[name="accent"]').val();
                var surface = $('input[name="accent_surface"]').val();
                if (/^#[0-9a-f]{6}$/i.test(accent) && /^#[0-9a-f]{6}$/i.test(surface)) {
                    $preview.css({'--rondo-preview-accent': accent, '--rondo-preview-surface': surface});
                }
            };
            $('input[name="accent"], input[name="accent_surface"]').on('input', updatePreview);
            updatePreview();
        }
    });
}(jQuery));

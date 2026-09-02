(function ($) {
    'use strict';

    function loadSidebar($root) {
        var endpoint = $root.data('endpoint');
        var conversationId = parseInt($root.data('conversation-id'), 10);
        var $status = $root.find('.rondo-sidebar-status');
        var $frame = $root.find('.rondo-sidebar-frame');
        var frame = $frame.get(0);
        window.clearTimeout($frame.data('render-timeout'));
        $status.removeClass('hide').text('Loading live Rondo information…');
        $frame.addClass('hide').removeData('channel').removeData('rendered').off('load.rondoSidebar');
        if (frame) {
            frame.removeAttribute('srcdoc');
        }

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
            if (!frame) {
                $status.text('Rondo information could not be displayed.');
                return;
            }
            frame.setAttribute('sandbox', 'allow-scripts allow-popups allow-popups-to-escape-sandbox');
            $frame.data('channel', response.channel).css('height', '160px').removeClass('hide');
            $frame.one('load.rondoSidebar', function () {
                if ($frame.data('channel') !== response.channel) {
                    return;
                }
                window.clearTimeout($frame.data('render-timeout'));
                $frame.removeData('render-timeout');
                $status.addClass('hide');
                if ($frame.data('rendered') !== true) {
                    $frame.css('height', '700px');
                }
            });

            // A srcdoc loaded while the iframe is display:none can retain a zero-sized body.
            // Wait until the visible iframe has a real viewport before navigating it.
            window.requestAnimationFrame(function () {
                frame.srcdoc = response.srcdoc;
                $frame.data('render-timeout', window.setTimeout(function () {
                    if ($frame.data('channel') === response.channel && !$status.hasClass('hide')) {
                        $status.text('Rondo information could not be displayed. Refresh to try again.');
                    }
                }, 3000));
            });
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
                || data.channel !== $(frame).data('channel') || data.rendered !== true || !Number.isFinite(data.height)) {
                return;
            }
            var height = Math.max(160, Math.min(1600, Math.round(data.height)));
            var $frame = $(frame);
            window.clearTimeout($frame.data('render-timeout'));
            $frame.removeData('render-timeout').data('rendered', true).css('height', height + 'px');
            $frame.closest('[data-rondo-sidebar]').find('.rondo-sidebar-status').addClass('hide');
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

(function () {
    'use strict';

    var body = document.body;
    var channel = body ? body.getAttribute('data-rondo-channel') : '';
    var parentOrigin = body ? body.getAttribute('data-rondo-parent-origin') : '';
    var timer = null;

    if (!body || !channel || !/^https?:\/\//i.test(parentOrigin)) {
        return;
    }

    function sendHeight() {
        window.clearTimeout(timer);
        timer = window.setTimeout(function () {
            var bounds = body.getBoundingClientRect();
            var height = Math.ceil(Math.max(document.documentElement.scrollHeight, body.scrollHeight));
            var rendered = bounds.width > 0 && body.children.length > 0;
            if (Number.isFinite(height)) {
                parent.postMessage({
                    type: 'rondo-sidebar-height',
                    version: 1,
                    channel: channel,
                    height: Math.max(160, Math.min(1600, height)),
                    rendered: rendered
                }, parentOrigin);
            }
        }, 40);
    }

    var observer = new ResizeObserver(sendHeight);
    observer.observe(document.documentElement);
    observer.observe(body);
    window.addEventListener('load', sendHeight);
    sendHeight();
}());

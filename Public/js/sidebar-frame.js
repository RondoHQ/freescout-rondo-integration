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

    function selectProfile(select) {
        var panels = document.querySelectorAll('[data-rondo-profile-panel]');
        Array.prototype.forEach.call(panels, function (panel) {
            panel.hidden = panel.id !== select.value;
        });
        sendHeight();
    }

    function selectTab(tab) {
        var card = tab.closest('[data-rondo-card]');
        var selected = tab.getAttribute('data-rondo-tab');
        if (!card || !selected) {
            return;
        }
        Array.prototype.forEach.call(card.querySelectorAll('[data-rondo-tab]'), function (item) {
            var active = item === tab;
            item.classList.toggle('is-active', active);
            item.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        Array.prototype.forEach.call(card.querySelectorAll('[data-rondo-tab-panel]'), function (panel) {
            var active = panel.getAttribute('data-rondo-tab-panel') === selected;
            panel.classList.toggle('is-active', active);
            panel.hidden = !active;
        });
        sendHeight();
    }

    document.addEventListener('change', function (event) {
        if (event.target && event.target.matches('[data-rondo-profile-switcher]')) {
            selectProfile(event.target);
        }
    });
    document.addEventListener('click', function (event) {
        var tab = event.target && event.target.closest('[data-rondo-tab]');
        if (tab) {
            selectTab(tab);
        }
    });

    var observer = new ResizeObserver(sendHeight);
    observer.observe(document.documentElement);
    observer.observe(body);
    window.addEventListener('load', sendHeight);
    sendHeight();
}());

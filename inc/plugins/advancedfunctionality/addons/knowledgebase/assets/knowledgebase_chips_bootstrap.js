(function (window, document) {
    'use strict';

    if (window.__afKbChipBootstrap) return;
    window.__afKbChipBootstrap = true;

    var script = document.currentScript;
    var runtimeUrl = '';
    var loading = null;
    var active = true;
    var eventNames = ['click', 'focusin'];

    if ('PointerEvent' in window) eventNames.unshift('pointerover');
    else eventNames.unshift('mouseover');

    function resolveRuntimeUrl() {
        if (runtimeUrl) return runtimeUrl;
        var source = script && script.src ? script.src : '';
        if (!source) return '';
        var url = new URL(source, document.baseURI);
        url.pathname = url.pathname.replace(/knowledgebase_chips_bootstrap\.js$/, 'knowledgebase_chips.js');
        runtimeUrl = url.href;
        return runtimeUrl;
    }

    function findChip(event) {
        var target = event && event.target;
        return target && target.closest ? target.closest('.af-kb-chip') : null;
    }

    function isPlainPrimaryClick(event) {
        return !event
            || ((typeof event.button !== 'number' || event.button === 0)
                && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey);
    }

    function detach() {
        if (!active) return;
        active = false;
        eventNames.forEach(function (name) {
            document.removeEventListener(name, onInteraction, true);
        });
    }

    function loadRuntime() {
        if (typeof window.afKbHandleChipInteraction === 'function') {
            return Promise.resolve();
        }
        if (loading) return loading;

        var src = resolveRuntimeUrl();
        if (!src) return Promise.reject(new Error('KB chip runtime URL is unavailable'));

        loading = new Promise(function (resolve, reject) {
            var existing = Array.prototype.find.call(document.querySelectorAll('script[src]'), function (node) {
                try { return new URL(node.src, document.baseURI).href === src; } catch (e) { return false; }
            });
            if (existing) {
                if (typeof window.afKbHandleChipInteraction === 'function') {
                    resolve();
                    return;
                }
                existing.addEventListener('load', resolve, { once: true });
                existing.addEventListener('error', function () {
                    reject(new Error('KB chip runtime failed to load'));
                }, { once: true });
                return;
            }

            var node = document.createElement('script');
            node.src = src;
            node.async = false;
            node.setAttribute('data-af-kb-chip-runtime', '1');
            node.onload = resolve;
            node.onerror = function () {
                reject(new Error('KB chip runtime failed to load'));
            };
            document.head.appendChild(node);
        }).then(function () {
            if (typeof window.afKbHandleChipInteraction !== 'function') {
                throw new Error('KB chip runtime loaded without interaction handler');
            }
            detach();
        }).catch(function (error) {
            loading = null;
            throw error;
        });

        return loading;
    }

    function eventContext(event, chip) {
        return {
            type: event.type,
            chip: chip,
            button: typeof event.button === 'number' ? event.button : 0,
            metaKey: !!event.metaKey,
            ctrlKey: !!event.ctrlKey,
            shiftKey: !!event.shiftKey,
            altKey: !!event.altKey
        };
    }

    function onInteraction(event) {
        var chip = findChip(event);
        if (!chip) return;

        if (event.type === 'click') {
            if (!isPlainPrimaryClick(event)) return;
            // Stop navigation while the real runtime is arriving. The runtime
            // receives this same semantic click immediately after load.
            event.preventDefault();
            event.stopImmediatePropagation();
        }

        var context = eventContext(event, chip);
        loadRuntime().then(function () {
            window.afKbHandleChipInteraction(context);
        }).catch(function (error) {
            if (window.console && console.warn) console.warn('[KnowledgeBase] chip runtime:', error);
        });
    }

    eventNames.forEach(function (name) {
        document.addEventListener(name, onInteraction, true);
    });
})(window, document);

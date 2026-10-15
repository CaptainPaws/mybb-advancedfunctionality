(function () {
    'use strict';
    function boot() {
        // Native panel links keep their presentation; profile/forum links leave the iframe.
        if (parent !== window && document.querySelector('[data-am-panel-page]')) {
            document.addEventListener('click', event => {
                const link = event.target.closest('a[href]');
                if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
                const url = new URL(link.href, location.href);
                if (url.origin !== location.origin) return;
                if (url.pathname.endsWith('/private.php')) {
                    url.searchParams.set('am_panel', '1');
                    link.href = url.href;
                } else if (!link.hasAttribute('target') && !link.getAttribute('href').startsWith('#') && /^https?:$/.test(url.protocol)) {
                    link.target = '_top';
                }
            });
        }

        const result = document.getElementById('am-result');
        if (result) {
            try {
                const pending = sessionStorage.getItem('am:pending:' + result.dataset.uid);
                if (pending) sessionStorage.removeItem(pending);
                sessionStorage.removeItem('am:pending:' + result.dataset.uid);
            } catch (_) { /* Storage may be disabled. */ }
            if (parent !== window) parent.postMessage({ type: 'advancedmessenger:complete', kind: result.dataset.kind }, location.origin);
            else location.replace('private.php');
            return;
        }
        const form = document.querySelector('form[data-am-editor]');
        if (!form) {
            document.querySelectorAll('.am-root form').forEach(native => {
                native.addEventListener('submit', event => {
                    if (native.querySelector('[name="action"][value="do_empty"]') &&
                        !window.confirm(document.querySelector('.am-root')?.dataset.amEmptyConfirm || 'Очистить выбранные папки?')) event.preventDefault();
                });
            });
            return;
        }
        const textarea = form.querySelector('textarea[name="message"]');
        if (!textarea) return;
        // Measure the compact document, not its viewport: resizing must not loop.
        if (parent !== window && window.frameElement?.id === 'am-composer') {
            const panel = document.querySelector('[data-am-panel-page]');
            let lastHeight = 0;
            let scheduled = false;
            function reportHeight() {
                scheduled = false;
                const height = Math.ceil(panel.getBoundingClientRect().height);
                if (height !== lastHeight) {
                    lastHeight = height;
                    parent.postMessage({ type: 'advancedmessenger:composer-size', height }, location.origin);
                }
            }
            function scheduleHeight() {
                if (!scheduled) { scheduled = true; requestAnimationFrame(reportHeight); }
            }
            if (panel && 'ResizeObserver' in window) new ResizeObserver(scheduleHeight).observe(panel);
            window.addEventListener('load', scheduleHeight);
            document.addEventListener('af:editor-ready', scheduleHeight);
            form.addEventListener('toggle', scheduleHeight, true);
            scheduleHeight();
        }
        const key = 'am:draft:' + form.dataset.amUid + ':' + form.dataset.amDraft;
        let instance = null;
        let submitting = false;
        let timer;
        let pendingText = null;
        const status = document.createElement('p');
        status.className = 'am-composer-status';
        status.setAttribute('role', 'status');
        form.append(status);
        function editor() {
            if (instance) return instance;
            try { return window.jQuery(textarea).sceditor('instance'); } catch (_) { return null; }
        }
        function text() {
            const inst = editor();
            try { if (inst) return String(inst.val() || ''); } catch (_) { /* Native textarea fallback. */ }
            return textarea.value;
        }
        function save() {
            clearTimeout(timer);
            const value = text();
            const fields = {};
            for (const name of ['subject', 'to', 'bcc']) {
                const field = form.elements.namedItem(name);
                if (field) fields[name] = field.value;
            }
            const options = {};
            form.querySelectorAll('input[type="checkbox"]').forEach(input => { options[input.name] = input.checked; });
            try {
                if (value || fields.subject || fields.to) sessionStorage.setItem(key, JSON.stringify({ message: value, fields, options }));
                else sessionStorage.removeItem(key);
            } catch (_) { /* A quota/storage error never blocks native sending. */ }
        }
        function schedule() { clearTimeout(timer); timer = setTimeout(save, 200); }
        window.AMComposer = { save };
        let stored;
        try { stored = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (_) { stored = null; }
        if (form.dataset.amQuote === '1' && stored && typeof stored.message === 'string' && stored.message) {
            textarea.value = stored.message + '\n\n' + textarea.value;
            pendingText = textarea.value;
            const inst = editor();
            if (inst) inst.val(textarea.value);
            stored = null;
        }
        function restore() {
            if (!stored || typeof stored.message !== 'string') return;
            textarea.value = stored.message;
            pendingText = stored.message;
            const inst = editor();
            if (inst) inst.val(stored.message);
            for (const [name, value] of Object.entries(stored.fields || {})) {
                // For an existing dialog, keep core's current recipient name
                // (UID survives renames). New compose/drafts retain recipient edits.
                if (form.dataset.amDraft.startsWith('user:') && name !== 'subject') continue;
                const field = form.elements.namedItem(name);
                if (field && typeof value === 'string') field.value = value;
            }
            form.querySelectorAll('input[type="checkbox"]').forEach(input => {
                if (typeof stored.options?.[input.name] === 'boolean') input.checked = stored.options[input.name];
            });
            restoreButton?.remove();
        }
        let restoreButton;
        if (stored && typeof stored.message === 'string' && stored.message !== textarea.value) {
            restoreButton = document.createElement('button');
            restoreButton.type = 'button';
            restoreButton.textContent = form.dataset.amRestore;
            restoreButton.addEventListener('click', restore);
            form.prepend(restoreButton);
            if (!textarea.value) restore();
        }
        document.addEventListener('af:editor-ready', event => {
            if (event.detail?.textarea !== textarea) return;
            instance = event.detail.instance;
            queueMicrotask(() => {
                if (pendingText !== null && instance) { instance.val(pendingText); textarea.value = pendingText; pendingText = null; }
            });
            try { instance?.bind('valuechanged', schedule); } catch (_) { /* Native textarea. */ }
        });
        form.addEventListener('input', schedule);
        form.addEventListener('change', schedule);
        // SCEditor can already exist if its lifecycle completed before DOM ready.
        try { editor()?.bind('valuechanged', schedule); } catch (_) { /* Optional capability. */ }
        form.addEventListener('submit', event => {
            if (submitting) { event.preventDefault(); return; }
            save();
            const preview = event.submitter?.name === 'preview';
            // Run after AdvancedEditor's submit synchronization. Never submit
            // programmatically or disable submitter fields (native options rely on them).
            queueMicrotask(() => {
                if (event.defaultPrevented) return;
                submitting = true;
                form.setAttribute('aria-busy', 'true');
                status.textContent = form.dataset.amSending;
                if (!preview) {
                    try { sessionStorage.setItem('am:pending:' + form.dataset.amUid, key); } catch (_) { /* Optional. */ }
                }
            });
        });
        window.addEventListener('pagehide', save);
        window.addEventListener('pageshow', () => { submitting = false; form.removeAttribute('aria-busy'); status.textContent = ''; });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();

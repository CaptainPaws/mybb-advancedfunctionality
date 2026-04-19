(function () {
    'use strict';

    var cache = { types: null, lists: {} };

    function afKbEndpoint(name, fallback) {
        var map = (window && window.afKbEndpoints) ? window.afKbEndpoints : null;
        return (map && map[name]) ? map[name] : fallback;
    }

    // ─────────────────────────────────────────────────────────────
    // Active target tracking + SCEditor instance discovery
    // ─────────────────────────────────────────────────────────────

    var lastActive = { textarea: null, editor: null };

    function setLastTextarea(ta) {
        if (ta && ta.nodeName === 'TEXTAREA') lastActive.textarea = ta;
    }
    function setLastEditor(ed) {
        if (ed && typeof ed.getRangeHelper === 'function') lastActive.editor = ed;
    }

    function getJQ() {
        return window.jQuery || window.$ || null;
    }

    function getSceditorInstances() {
        var $ = getJQ();
        try {
            if ($ && $.sceditor && $.sceditor.instances && $.sceditor.instances.length) {
                return $.sceditor.instances;
            }
        } catch (e) {}
        return [];
    }

    function tryGetSceditorInstanceByTextarea(textarea) {
        var $ = getJQ();
        try {
            if (!$ || !textarea) return null;

            // Common SCEditor jQuery plugin API
            if (typeof $(textarea).sceditor === 'function') {
                var inst = $(textarea).sceditor('instance');
                if (inst && typeof inst.getRangeHelper === 'function') return inst;
            }

            // Some builds expose $.sceditor.instance(textarea)
            if ($.sceditor && typeof $.sceditor.instance === 'function') {
                var inst2 = $.sceditor.instance(textarea);
                if (inst2 && typeof inst2.getRangeHelper === 'function') return inst2;
            }
        } catch (e) {}
        return null;
    }

    function findInstanceByContainer(container) {
        if (!container) return null;
        var list = getSceditorInstances();
        for (var i = 0; i < list.length; i++) {
            try {
                var inst = list[i];
                if (inst && typeof inst.getContainer === 'function' && inst.getContainer() === container) {
                    return inst;
                }
            } catch (e) {}
        }
        return null;
    }

    function isElementVisible(el) {
        if (!el) return false;
        if (el.offsetParent !== null) return true;
        try {
            var r = el.getClientRects();
            return !!(r && r.length);
        } catch (e) {}
        return false;
    }

    // Track focus on any textarea
    document.addEventListener('focusin', function (e) {
        var t = e.target;
        if (!t || t.nodeName !== 'TEXTAREA') return;

        setLastTextarea(t);

        // If it's SCEditor internal textarea, find instance by nearest container
        var container = t.closest ? t.closest('.sceditor-container') : null;
        if (container) {
            var inst = findInstanceByContainer(container);
            if (inst) setLastEditor(inst);
            return;
        }

        // Otherwise try by textarea binding
        var inst2 = tryGetSceditorInstanceByTextarea(t);
        if (inst2) setLastEditor(inst2);
    }, true);

    // Track clicks inside SCEditor container to pin correct instance
    document.addEventListener('mousedown', function (e) {
        var el = e.target;
        if (!el || !el.closest) return;

        var c = el.closest('.sceditor-container');
        if (!c) return;

        var inst = findInstanceByContainer(c);
        if (inst) {
            setLastEditor(inst);
            // keep original textarea if доступна
            try {
                if (inst.textarea && inst.textarea.nodeName === 'TEXTAREA') setLastTextarea(inst.textarea);
            } catch (e2) {}
        }
    }, true);

    // ─────────────────────────────────────────────────────────────

    function getLang(key, fallback) {
        if (window.afKbLang && window.afKbLang[key]) return window.afKbLang[key];
        return fallback;
    }

    function detectActiveMechanic() {
        var fromAtf = document.querySelector('.af-atf-character-mechanic');
        if (fromAtf) {
            var atfMode = String(fromAtf.value || '').trim().toLowerCase();
            if (atfMode === 'arpg' || atfMode === 'dnd') return atfMode;
        }

        var fromDataset = document.querySelector('[data-mechanic]');
        if (fromDataset) {
            var dataMode = String(fromDataset.getAttribute('data-mechanic') || '').trim().toLowerCase();
            if (dataMode === 'arpg' || dataMode === 'dnd') return dataMode;
        }

        var fallback = String((window && window.afKbInsertMechanic) ? window.afKbInsertMechanic : '').trim().toLowerCase();
        if (fallback === 'arpg' || fallback === 'dnd') return fallback;

        return 'dnd';
    }

    function fetchTypes(mechanic) {
        var mode = (mechanic === 'arpg' || mechanic === 'dnd') ? mechanic : detectActiveMechanic();
        var cacheKey = 'types:' + mode;
        if (cache.types && cache.types[cacheKey]) return Promise.resolve(cache.types[cacheKey]);

        var url = afKbEndpoint('types', 'misc.php?action=kb_types');
        url += (url.indexOf('?') === -1 ? '?' : '&') + 'mechanic=' + encodeURIComponent(mode);

        return fetch(url)
            .then(function (res) {
                var ct = res.headers.get('content-type') || '';
                if (!res.ok || ct.indexOf('application/json') === -1) throw new Error('Invalid response');
                return res.json();
            })
            .then(function (data) {
                if (!cache.types || typeof cache.types !== 'object') cache.types = {};
                cache.types[cacheKey] = data.items || [];
                return cache.types[cacheKey];
            })
            .catch(function () { return []; });
    }

    function fetchList(type, query) {
        var cacheKey = type + ':' + (query || '');
        if (cache.lists[cacheKey]) return Promise.resolve(cache.lists[cacheKey]);

        var url = afKbEndpoint('list', 'misc.php?action=kb_list') + '&type=' + encodeURIComponent(type);
        if (query) url += '&q=' + encodeURIComponent(query);

        return fetch(url)
            .then(function (res) {
                var ct = res.headers.get('content-type') || '';
                if (!res.ok || ct.indexOf('application/json') === -1) throw new Error('Invalid response');
                return res.json();
            })
            .then(function (data) {
                cache.lists[cacheKey] = data.items || [];
                return cache.lists[cacheKey];
            })
            .catch(function () { return []; });
    }

    // ─────────────────────────────────────────────────────────────
    // SCEditor helpers (source mode aware)
    // ─────────────────────────────────────────────────────────────

    function getSceditorContainer(editor) {
        try {
            if (editor && typeof editor.getContainer === 'function') return editor.getContainer();
        } catch (e) {}
        return null;
    }

    function getVisibleTextareaIn(container) {
        if (!container) return null;
        var all = container.querySelectorAll('textarea');
        if (!all || !all.length) return null;

        // prefer visible
        for (var i = 0; i < all.length; i++) {
            if (isElementVisible(all[i])) return all[i];
        }
        return all[0];
    }

    function getSceditorSourceTextarea(editor) {
        try {
            if (editor && editor.sourceEditor && editor.sourceEditor.nodeType === 1) return editor.sourceEditor;
        } catch (e) {}

        var c = getSceditorContainer(editor);
        if (!c) return null;
        return getVisibleTextareaIn(c);
    }

    function isSceditorInSourceMode(editor) {
        try {
            if (editor && typeof editor.inSourceMode === 'function') return !!editor.inSourceMode();
        } catch (e) {}

        var c = getSceditorContainer(editor);
        if (c && c.classList && c.classList.contains('sourceMode')) return true;

        var src = getSceditorSourceTextarea(editor);
        return !!(src && isElementVisible(src));
    }

    function syncSceditorAfterSourceInsert(editor, sourceTextarea) {
        if (!editor) return;

        try { if (typeof editor.updateOriginal === 'function') editor.updateOriginal(); } catch (e) {}
        try { if (typeof editor.updateTextareaValue === 'function') editor.updateTextareaValue(); } catch (e2) {}

        // Hard fallback: copy source -> original hidden textarea (MyBB quick reply case)
        try {
            var orig = null;
            if (editor.textarea && editor.textarea.nodeName === 'TEXTAREA') orig = editor.textarea;
            if (!orig) orig = document.querySelector('textarea#message, textarea[name="message"]');
            if (orig && sourceTextarea && typeof sourceTextarea.value === 'string') {
                orig.value = sourceTextarea.value;
            }
        } catch (e3) {}
    }

    // ─────────────────────────────────────────────────────────────
    // Target resolving (IMPORTANT: avoid hidden #message in quick reply)
    // ─────────────────────────────────────────────────────────────

    function resolveTarget(preferred) {
        // 1) SCEditor instance provided
        if (preferred && typeof preferred.getRangeHelper === 'function') {
            setLastEditor(preferred);
            return preferred;
        }

        // 2) textarea provided: try to lift to editor instance
        if (preferred && preferred.nodeName === 'TEXTAREA') {
            setLastTextarea(preferred);

            // if it's internal SCEditor textarea -> find instance by container
            var c = preferred.closest ? preferred.closest('.sceditor-container') : null;
            if (c) {
                var instC = findInstanceByContainer(c);
                if (instC) { setLastEditor(instC); return instC; }
            }

            var inst = tryGetSceditorInstanceByTextarea(preferred);
            if (inst) { setLastEditor(inst); return inst; }

            return preferred;
        }

        // 3) last active editor
        if (lastActive.editor) return lastActive.editor;

        // 4) last active textarea -> maybe has instance
        if (lastActive.textarea) {
            var inst2 = tryGetSceditorInstanceByTextarea(lastActive.textarea);
            if (inst2) { setLastEditor(inst2); return inst2; }
            return lastActive.textarea;
        }

        // 5) find any visible SCEditor container and use its instance
        var visC = document.querySelector('.sceditor-container');
        if (visC) {
            var inst3 = findInstanceByContainer(visC);
            if (inst3) return inst3;
        }

        // 6) fallback message textarea
        var msg = document.querySelector('textarea#message, textarea[name="message"]');
        if (msg) {
            var inst4 = tryGetSceditorInstanceByTextarea(msg);
            if (inst4) return inst4;
            return msg;
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────
    // Selection snapshot + insert
    // ─────────────────────────────────────────────────────────────

    function snapshotSelection(target) {
        target = resolveTarget(target);

        // SCEditor
        try {
            if (target && typeof target.getRangeHelper === 'function') {
                try { if (typeof target.focus === 'function') target.focus(); } catch (e) {}

                if (isSceditorInSourceMode(target)) {
                    var src = getSceditorSourceTextarea(target);
                    if (src) {
                        try { src.focus(); } catch (e2) {}
                        return {
                            kind: 'sceditor_source',
                            editor: target,
                            el: src,
                            start: (typeof src.selectionStart === 'number') ? src.selectionStart : 0,
                            end: (typeof src.selectionEnd === 'number') ? src.selectionEnd : 0,
                            scrollTop: src.scrollTop || 0
                        };
                    }
                }

                var rh = target.getRangeHelper();
                if (rh && typeof rh.saveRange === 'function') {
                    rh.saveRange();
                    return { kind: 'sceditor', rangeHelper: rh, editor: target };
                }
            }
        } catch (e3) {}

        // textarea
        if (target && target.nodeName === 'TEXTAREA') {
            return {
                kind: 'textarea',
                el: target,
                start: (typeof target.selectionStart === 'number') ? target.selectionStart : 0,
                end: (typeof target.selectionEnd === 'number') ? target.selectionEnd : 0,
                scrollTop: target.scrollTop || 0
            };
        }

        return { kind: 'unknown', target: target };
    }

    function restoreSelection(snap) {
        if (!snap) return;

        if (snap.kind === 'sceditor_source') {
            try {
                var src = snap.el || (snap.editor ? getSceditorSourceTextarea(snap.editor) : null);
                if (src) {
                    src.focus();
                    if (typeof src.setSelectionRange === 'function') src.setSelectionRange(snap.start, snap.end);
                    src.scrollTop = snap.scrollTop || 0;
                } else if (snap.editor && typeof snap.editor.focus === 'function') {
                    snap.editor.focus();
                }
            } catch (e) {}
            return;
        }

        if (snap.kind === 'sceditor') {
            try {
                if (snap.editor && typeof snap.editor.focus === 'function') snap.editor.focus();
                if (snap.rangeHelper && typeof snap.rangeHelper.restoreRange === 'function') snap.rangeHelper.restoreRange();
            } catch (e2) {}
            return;
        }

        if (snap.kind === 'textarea' && snap.el) {
            try {
                snap.el.focus();
                if (typeof snap.el.setSelectionRange === 'function') snap.el.setSelectionRange(snap.start, snap.end);
                snap.el.scrollTop = snap.scrollTop || 0;
            } catch (e3) {}
        }
    }

    function insertAtCursor(textarea, text, start, end) {
        if (!textarea) return false;

        var value = textarea.value || '';
        var s = (typeof start === 'number') ? start : (textarea.selectionStart || 0);
        var e = (typeof end === 'number') ? end : (textarea.selectionEnd || 0);

        try {
            if (typeof textarea.setRangeText === 'function') {
                textarea.setRangeText(text, s, e, 'end');
                textarea.focus();
                return true;
            }
        } catch (e2) {}

        textarea.value = value.substring(0, s) + text + value.substring(e);
        var pos = s + text.length;
        try { textarea.selectionStart = textarea.selectionEnd = pos; } catch (err) {}
        try { textarea.focus(); } catch (e3) {}
        return true;
    }

    function insertIntoTarget(preferredTarget, text, snap) {
        var target = resolveTarget(preferredTarget);

        if (snap) restoreSelection(snap);

        // 1) SCEditor source mode: insert into container textarea (NOT hidden #message)
        try {
            if (target && typeof target.getRangeHelper === 'function' && isSceditorInSourceMode(target)) {
                var src = getSceditorSourceTextarea(target);
                if (src) {
                    var s = (snap && snap.kind === 'sceditor_source') ? snap.start : (src.selectionStart || 0);
                    var e = (snap && snap.kind === 'sceditor_source') ? snap.end : (src.selectionEnd || 0);

                    var ok = insertAtCursor(src, text, s, e);
                    if (ok) {
                        syncSceditorAfterSourceInsert(target, src);
                        setLastEditor(target);
                        return true;
                    }
                }
            }
        } catch (e) {}

        // 2) SCEditor WYSIWYG API
        try {
            if (target && typeof target.getRangeHelper === 'function') {
                try { if (typeof target.focus === 'function') target.focus(); } catch (e4) {}
                try {
                    var rh = target.getRangeHelper && target.getRangeHelper();
                    if (rh && typeof rh.restoreRange === 'function') rh.restoreRange();
                } catch (e5) {}

                if (typeof target.insertText === 'function') {
                    target.insertText(text);
                    setLastEditor(target);
                    return true;
                }
                if (typeof target.insert === 'function') {
                    target.insert(text);
                    setLastEditor(target);
                    return true;
                }
            }
        } catch (e6) {}

        // 3) Plain textarea: DO NOT write into hidden #message if there is a visible SCEditor source textarea
        if (target && target.nodeName === 'TEXTAREA') {
            if (!isElementVisible(target)) {
                // try visible source textarea near any container
                var c = document.querySelector('.sceditor-container.sourceMode, .sceditor-container');
                if (c) {
                    var inst = findInstanceByContainer(c);
                    if (inst && isSceditorInSourceMode(inst)) {
                        var src2 = getSceditorSourceTextarea(inst);
                        if (src2 && isElementVisible(src2)) {
                            var ok0 = insertAtCursor(src2, text);
                            if (ok0) {
                                syncSceditorAfterSourceInsert(inst, src2);
                                setLastEditor(inst);
                                return true;
                            }
                        }
                    }
                }
            }

            var ok2 = insertAtCursor(target, text, snap && snap.kind === 'textarea' ? snap.start : undefined, snap && snap.kind === 'textarea' ? snap.end : undefined);
            if (ok2) { setLastTextarea(target); return true; }
        }

        // 4) final fallback: any visible message textarea
        var msgAll = document.querySelectorAll('textarea#message, textarea[name="message"]');
        for (var i = 0; i < msgAll.length; i++) {
            var ta = msgAll[i];
            if (!isElementVisible(ta)) continue;
            var ok3 = insertAtCursor(ta, text);
            if (ok3) { setLastTextarea(ta); return true; }
        }

        return false;
    }

    // ─────────────────────────────────────────────────────────────
    // Modal UI
    // ─────────────────────────────────────────────────────────────

    function buildModal() {
        var backdrop = document.querySelector('.af-kb-modal-backdrop.af-kb-insert');
        if (backdrop) return backdrop;

        backdrop = document.createElement('div');
        backdrop.className = 'af-kb-modal-backdrop af-kb-insert';
        backdrop.innerHTML =
            '<div class="af-kb-modal">' +
                '<div class="af-kb-modal-header">' +
                    '<h3>' + getLang('kbInsertTitle', 'Insert KB') + '</h3>' +
                    '<button type="button" class="af-kb-modal-close">&times;</button>' +
                '</div>' +
                '<div class="af-kb-insert-controls">' +
                    '<input type="text" class="af-kb-insert-search" placeholder="' + getLang('kbInsertSearch', 'Search...') + '" />' +
                    '<select class="af-kb-insert-select"></select>' +
                '</div>' +
                '<div class="af-kb-insert-hint"></div>' +
                '<div class="af-kb-insert-list"></div>' +
            '</div>';

        document.body.appendChild(backdrop);

        backdrop.addEventListener('click', function (event) {
            if (event.target === backdrop || (event.target && event.target.classList && event.target.classList.contains('af-kb-modal-close'))) {
                backdrop.classList.remove('is-active');
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && backdrop.classList.contains('is-active')) {
                backdrop.classList.remove('is-active');
            }
        });

        return backdrop;
    }

    function renderTypeOptions(select, types, currentType) {
        select.innerHTML = '';
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = getLang('kbInsertSelect', 'Select category');
        select.appendChild(placeholder);

        types.forEach(function (item) {
            var option = document.createElement('option');
            option.value = item.type;
            option.textContent = item.title || item.type;
            if (item.type === currentType) option.selected = true;
            select.appendChild(option);
        });
    }

    function renderHint(container, text) {
        container.textContent = text || '';
        container.style.display = text ? 'block' : 'none';
    }

    function renderList(container, items, insertCallback) {
        container.innerHTML = '';
        if (!items.length) {
            var empty = document.createElement('div');
            empty.className = 'af-kb-insert-empty';
            empty.textContent = getLang('kbInsertEmpty', 'Nothing found');
            container.appendChild(empty);
            return;
        }

        items.forEach(function (item) {
            var row = document.createElement('div');
            row.className = 'af-kb-insert-item';

            var title = document.createElement('div');
            title.className = 'af-kb-insert-item-title';

            if (item.icon_url) {
                var img = document.createElement('img');
                img.className = 'af-kb-icon-img';
                img.src = item.icon_url;
                img.alt = '';
                title.appendChild(img);
            } else if (item.icon_class) {
                var icon = document.createElement('i');
                icon.className = item.icon_class;
                title.appendChild(icon);
            }

            var span = document.createElement('span');
            span.textContent = item.title || item.key;
            title.appendChild(span);
            row.appendChild(title);

            if (item.tech) {
                var meta = document.createElement('div');
                meta.className = 'af-kb-insert-item-meta';
                meta.textContent = item.tech;
                row.appendChild(meta);
            }

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'af-kb-btn af-kb-btn--edit';
            btn.textContent = getLang('kbInsertButton', 'Insert');
            btn.addEventListener('click', function () { insertCallback(item); });
            row.appendChild(btn);

            container.appendChild(row);
        });
    }

    function openInsertModal(preferredTarget) {
        var target = resolveTarget(preferredTarget);
        var snap = snapshotSelection(target);
        var activeMechanic = detectActiveMechanic();

        var backdrop = buildModal();
        var searchInput = backdrop.querySelector('.af-kb-insert-search');
        var typeSelect = backdrop.querySelector('.af-kb-insert-select');
        var hint = backdrop.querySelector('.af-kb-insert-hint');
        var list = backdrop.querySelector('.af-kb-insert-list');
        var state = { type: '', query: '' };

        function insertItem(item) {
            var resolvedType = item.type || state.type;
            var tag = '[kb=' + resolvedType + ':' + item.key + ']';

            var ok = insertIntoTarget(target, tag, snap);

            // If editor was recreated while modal open: retry with current best target
            if (!ok) {
                var rt = resolveTarget(null);
                ok = insertIntoTarget(rt, tag, snapshotSelection(rt));
            }

            backdrop.classList.remove('is-active');
        }

        function loadList() {
            if (!state.type) {
                list.innerHTML = '';
                renderHint(hint, getLang('kbInsertHint', 'Select category or continue search'));
                return;
            }
            renderHint(hint, '');
            fetchList(state.type, state.query).then(function (items) {
                renderList(list, items, insertItem);
            });
        }

        fetchTypes(activeMechanic).then(function (types) {
            if (state.type && !types.some(function (item) { return item && item.type === state.type; })) {
                state.type = '';
            }
            renderTypeOptions(typeSelect, types, state.type);
            typeSelect.value = state.type;
            loadList();
        });

        typeSelect.onchange = function () {
            state.type = typeSelect.value;
            loadList();
        };
        searchInput.oninput = function () {
            state.query = searchInput.value.trim();
            if (!state.type) {
                list.innerHTML = '';
                renderHint(hint, getLang('kbInsertHint', 'Select category or continue search'));
                return;
            }
            loadList();
        };

        searchInput.value = '';
        typeSelect.value = '';

        backdrop.classList.add('is-active');
        searchInput.focus();
    }

    // ─────────────────────────────────────────────────────────────
    // Toolbar button: force-add for existing editors (fix "button disappeared")
    // ─────────────────────────────────────────────────────────────

    function ensureToolbarButton(toolbarEl, editorInstance) {
        if (!toolbarEl) return;

        // Already exists?
        if (toolbarEl.querySelector('.sceditor-button-af_kb_insert')) return;

        // Put into last group (or create new group)
        var group = toolbarEl.querySelector('.sceditor-group:last-child');
        if (!group) {
            group = document.createElement('div');
            group.className = 'sceditor-group';
            toolbarEl.appendChild(group);
        }

        var a = document.createElement('a');
        a.href = '#';
        a.className = 'sceditor-button sceditor-button-af_kb_insert';
        a.setAttribute('data-sceditor-command', 'af_kb_insert');
        a.setAttribute('unselectable', 'on');
        a.title = getLang('kbInsertLabel', 'KB');
        a.setAttribute('data-af-title', getLang('kbInsertLabel', 'KB'));

        var inner = document.createElement('div');
        inner.setAttribute('unselectable', 'on');
        inner.textContent = getLang('kbInsertLabel', 'KB');
        a.appendChild(inner);

        a.addEventListener('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            openInsertModal(editorInstance || resolveTarget(null));
        });

        group.appendChild(a);
    }

    function ensureToolbarButtonsForAll() {
        var toolbars = document.querySelectorAll('.sceditor-container .sceditor-toolbar');
        toolbars.forEach(function (tb) {
            var cont = tb.closest ? tb.closest('.sceditor-container') : null;
            var inst = cont ? findInstanceByContainer(cont) : null;
            if (inst) setLastEditor(inst);
            ensureToolbarButton(tb, inst);
        });
    }

    // ─────────────────────────────────────────────────────────────
    // SCEditor command registration + init hooks
    // ─────────────────────────────────────────────────────────────

    function ensureCommandRegistered() {
        var $ = getJQ();
        if (!$ || !$.sceditor || !$.sceditor.command) return false;

        if ($.sceditor.command.get && $.sceditor.command.get('af_kb_insert')) return true;

        $.sceditor.command.set('af_kb_insert', {
            tooltip: getLang('kbInsertLabel', 'KB'),
            exec: function () { openInsertModal(this); },
            txtExec: function () { openInsertModal(this); }
        });
        return true;
    }

    function patchToolbarString(toolbar) {
        if (!toolbar || toolbar.indexOf('af_kb_insert') !== -1) return toolbar;
        return toolbar + '|af_kb_insert';
    }

    function hookSceditorCreate() {
        var $ = getJQ();
        if (!$ || !$.fn || typeof $.fn.sceditor !== 'function') return false;

        // Patch default options (future inits)
        if (window.sceditor_options && typeof window.sceditor_options === 'object' && typeof window.sceditor_options.toolbar === 'string') {
            window.sceditor_options.toolbar = patchToolbarString(window.sceditor_options.toolbar);
        }

        if ($.fn.sceditor.__afKbInsertWrapped) return true;

        var orig = $.fn.sceditor;
        var wrapped = function (options) {
            ensureCommandRegistered();
            try {
                if (options && typeof options === 'object' && typeof options.toolbar === 'string') {
                    options.toolbar = patchToolbarString(options.toolbar);
                }
            } catch (e) {}
            var res = orig.apply(this, arguments);

            // After init, ensure toolbar button appears
            setTimeout(function () { ensureToolbarButtonsForAll(); }, 0);
            return res;
        };

        wrapped.__afKbInsertWrapped = true;
        $.fn.sceditor = wrapped;
        $.fn.sceditor.__afKbInsertWrapped = true;
        return true;
    }

    function attachPlainTextareaButtons() {
        // Leave only for visible plain editors (not SCEditor quick reply)
        var targets = document.querySelectorAll('textarea[name="message"], textarea#message');
        targets.forEach(function (textarea) {
            if (!isElementVisible(textarea)) return;

            // Skip SCEditor internal ones
            var cls = textarea.className || '';
            if (cls.indexOf('sceditor') !== -1) return;

            if (textarea.dataset.afKbInsertReady === '1') return;
            textarea.dataset.afKbInsertReady = '1';

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'af-kb-btn af-kb-btn--edit';
            button.textContent = getLang('kbInsertLabel', 'KB');
            button.addEventListener('click', function () { openInsertModal(textarea); });

            textarea.parentNode.insertBefore(button, textarea);
        });
    }

    function installObserver() {
        if (window.__afKbInsertObserver) return;
        window.__afKbInsertObserver = true;

        try {
            var mo = new MutationObserver(function (mutations) {
                for (var i = 0; i < mutations.length; i++) {
                    var m = mutations[i];
                    if (!m.addedNodes || !m.addedNodes.length) continue;
                    // if any SCEditor UI added -> ensure button
                    ensureToolbarButtonsForAll();
                }
            });
            mo.observe(document.body, { childList: true, subtree: true });
        } catch (e) {}
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Поднять чипы (если knowledgebase_chips.js подключён)
        if (typeof window.afKbInitChips === 'function') {
            window.afKbInitChips();
        }

        var hasTextarea = document.querySelector('textarea[name="message"], textarea#message');
        var hasSceditor = !!(getJQ() && getJQ().fn && typeof getJQ().fn.sceditor === 'function');

        if (!hasTextarea && !hasSceditor && !(window.sceditor_options && typeof window.sceditor_options === 'object')) return;

        if (hasSceditor || (window.sceditor_options && typeof window.sceditor_options === 'object')) {
            ensureCommandRegistered();
            hookSceditorCreate();
        }

        // Force-add toolbar button for already-created editors
        ensureToolbarButtonsForAll();
        setTimeout(ensureToolbarButtonsForAll, 250);
        setTimeout(ensureToolbarButtonsForAll, 1000);

        if (hasTextarea) attachPlainTextareaButtons();

        installObserver();
    });
})();

(function () {
    'use strict';

    if (window.afAamMentionsInit) return;
    window.afAamMentionsInit = true;

    var DEBUG = !!window.afAamMentionsDebug;

    var mentionSuggestUrl = (typeof window.af_aam_mention_suggest_url === 'string' && window.af_aam_mention_suggest_url)
        ? window.af_aam_mention_suggest_url
        : 'xmlhttp.php?action=af_aam_api&op=mention_suggest';

    // UI
    var box = null;
    var boxHost = null;
    var items = [];
    var selectedIndex = -1;

    // editor refs
    var textarea = null;
    var sceditor = null;
    var scBody = null;
    var scWin = null;
    var scDoc = null;
    var scIframe = null;

    // state about current "@prefix"
    var state = {
        mode: '',              // 'textarea' | 'sceditor'
        prefix: '',
        atIndex: -1,
        caret: 0,
        textNode: null,
        textNodeAtOffset: 0,
        textNodeCaretOffset: 0
    };

    // request control
    var debounceTimer = null;
    var activeController = null;
    var lastQuery = '';

    // client cache
    var cache = new Map(); // key -> {ts, data}
    var CACHE_TTL_MS = 60 * 1000;
    var MAX_CACHE_KEYS = 200;

    function log() {
        if (!DEBUG || !window.console) return;
        try { console.log.apply(console, arguments); } catch (e) {}
    }

    function isLikelyEditorTextarea(el) {
        try {
            if (!el || el.nodeType !== 1 || el.tagName !== 'TEXTAREA') return false;

            if (el.name === 'message') return true;
            if (el.id === 'message' || el.id === 'message_new') return true;
            if (el.id && el.id.indexOf('message') === 0) return true;

            if (el.classList && el.classList.contains('sceditor-source')) return true;

            if (el.closest && el.closest('.sceditor-container, #quickreply_e')) return true;
        } catch (e) {}
        return false;
    }

    function ready(fn) {
        if (document.readyState === 'complete' || document.readyState === 'interactive') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    function findTextarea() {
        return (
            document.querySelector('textarea[name="message"]') ||
            document.getElementById('message') ||
            document.getElementById('message_new') ||
            document.querySelector('textarea[id^="message"]') ||
            null
        );
    }

    function getActiveTextareaFallback() {
        var ae = document.activeElement;
        if (ae && ae.tagName === 'TEXTAREA' && isLikelyEditorTextarea(ae)) return ae;

        var ta = findTextarea();
        return ta || null;
    }

    // ====== BOX HOSTING INSIDE SCEDITOR-CONTAINER ======

    function getClosestSceditorContainerFrom(node) {
        try {
            if (!node) return null;
            if (node.closest) return node.closest('.sceditor-container');
        } catch (e) {}
        return null;
    }

    function guessHostForContext(ctxWin) {
        // 1) WYSIWYG (iframe) — контейнер вокруг iframe
        if (ctxWin && ctxWin !== window) {
            if (scIframe) {
                var c1 = getClosestSceditorContainerFrom(scIframe);
                if (c1) return c1;
            }
            // fallback: content area container
            try {
                if (sceditor && typeof sceditor.getContentAreaContainer === 'function') {
                    var area = sceditor.getContentAreaContainer();
                    var c2 = getClosestSceditorContainerFrom(area);
                    if (c2) return c2;
                    if (area) return area;
                }
            } catch (e1) {}
        }

        // 2) Source/textarea — ближайший sceditor-container от textarea
        if (textarea) {
            var c3 = getClosestSceditorContainerFrom(textarea);
            if (c3) return c3;
        }

        // 3) last resort: body
        return document.body;
    }

    function ensureHostPositioning(host) {
        // Чтобы absolute-дети позиционировались внутри host
        try {
            if (!host || host === document.body) return;
            var cs = window.getComputedStyle(host);
            if (!cs) return;
            if (cs.position === 'static') host.style.position = 'relative';
        } catch (e) {}
    }

    function ensureBoxHost(ctxWin) {
        var host = guessHostForContext(ctxWin) || document.body;

        if (boxHost !== host) {
            boxHost = host;
            ensureHostPositioning(boxHost);

            if (box && box.parentNode !== boxHost) {
                try {
                    if (box.parentNode) box.parentNode.removeChild(box);
                } catch (e0) {}

                try {
                    boxHost.appendChild(box);
                } catch (e1) {
                    // fallback
                    boxHost = document.body;
                    document.body.appendChild(box);
                }
            }
        }

        // position mode
        if (boxHost === document.body) {
            box.style.position = 'fixed';
        } else {
            box.style.position = 'absolute';
        }
    }

    function createBox() {
        if (box && box.parentNode) {
            try { box.parentNode.removeChild(box); } catch (e0) {}
        }

        box = document.createElement('div');
        box.className = 'af-aam-suggest-box';
        box.style.display = 'none';
        box.style.zIndex = '999999';
        box.style.maxHeight = '260px';
        box.style.overflowY = 'auto';
        box.tabIndex = -1;

        // start in body, потом перепривяжем в ensureBoxHost()
        boxHost = document.body;
        box.style.position = 'fixed';
        document.body.appendChild(box);
    }

    function clearState() {
        selectedIndex = -1;
        items = [];
        state.mode = '';
        state.prefix = '';
        state.atIndex = -1;
        state.caret = 0;
        state.textNode = null;
        state.textNodeAtOffset = 0;
        state.textNodeCaretOffset = 0;
    }

    function hideBox() {
        if (box) {
            box.style.display = 'none';
            box.innerHTML = '';
        }
        clearState();
    }

    function highlight(index) {
        if (!box) return;
        var children = box.children || [];
        Array.prototype.forEach.call(children, function (el, i) {
            if (i === index) el.classList.add('is-selected');
            else el.classList.remove('is-selected');
        });
    }

    function clamp(n, a, b) {
        n = Number(n);
        if (isNaN(n)) return a;
        return Math.max(a, Math.min(b, n));
    }

    function placeBoxByRect(rect, ctxWin) {
        if (!box || !rect) return;

        ensureBoxHost(ctxWin);

        var left = rect.left;
        var bottom = rect.bottom;
        if (typeof left !== 'number' || typeof bottom !== 'number') return;

        var globalLeft = left;
        var globalBottom = bottom;

        // rect from iframe viewport -> translate into main window viewport
        if (ctxWin && ctxWin !== window) {
            if (scIframe && scIframe.getBoundingClientRect) {
                var iframeRect = scIframe.getBoundingClientRect();
                globalLeft = iframeRect.left + left;
                globalBottom = iframeRect.top + bottom;
            }
        }

        // desired anchor in viewport coords:
        var vx = Math.max(0, globalLeft);
        var vy = Math.max(0, globalBottom + 6);

        // If host is body -> fixed, use viewport coords directly
        if (boxHost === document.body) {
            var x1 = Math.max(8, vx);
            var y1 = Math.max(8, vy);

            try {
                var maxX1 = window.innerWidth - 8;
                var bw1 = box.offsetWidth || 240;
                if (x1 + bw1 > maxX1) x1 = Math.max(8, maxX1 - bw1);
            } catch (e1) {}

            box.style.left = x1 + 'px';
            box.style.top = y1 + 'px';
            return;
        }

        // Otherwise: absolute inside host
        var hostRect = null;
        try { hostRect = boxHost.getBoundingClientRect(); } catch (e2) { hostRect = null; }
        if (!hostRect) return;

        // Convert viewport coords -> host local coords
        var lx = vx - hostRect.left;
        var ly = vy - hostRect.top;

        // Clamp within host box
        var pad = 6;
        var hostW = (boxHost.clientWidth || (hostRect.right - hostRect.left) || 0);
        var hostH = (boxHost.clientHeight || (hostRect.bottom - hostRect.top) || 0);

        // Ensure we have width to clamp against
        var bw = box.offsetWidth || 240;
        var bh = box.offsetHeight || 120;

        var maxX = Math.max(pad, hostW - pad - bw);
        var maxY = Math.max(pad, hostH - pad - bh);

        lx = clamp(lx, pad, maxX);
        ly = clamp(ly, pad, maxY);

        box.style.left = lx + 'px';
        box.style.top = ly + 'px';
    }

    // --- helpers: HTML in JSON ---
    function decodeHtmlEntities(s) {
        s = String(s == null ? '' : s);
        if (!s) return '';
        if (s.indexOf('&') === -1) return s;
        try {
            var ta = document.createElement('textarea');
            ta.innerHTML = s;
            return ta.value;
        } catch (e) {
            return s;
        }
    }

    function stripTagsToText(s) {
        s = String(s == null ? '' : s);
        if (!s) return '';
        if (s.indexOf('<') !== -1 && s.indexOf('>') !== -1) {
            try {
                var div = document.createElement('div');
                div.innerHTML = s;
                return (div.textContent || div.innerText || '').trim();
            } catch (e) {}
        }
        return s.trim();
    }

    function extractUsername(it) {
        if (it == null) return '';
        if (typeof it === 'string') {
            return stripTagsToText(decodeHtmlEntities(it));
        }
        if (typeof it === 'object') {
            var u =
                (typeof it.username === 'string' && it.username) ||
                (typeof it.user === 'string' && it.user) ||
                (typeof it.name === 'string' && it.name) ||
                (typeof it.label === 'string' && it.label) ||
                (typeof it.value === 'string' && it.value) ||
                '';
            u = stripTagsToText(decodeHtmlEntities(u));
            return u;
        }
        return stripTagsToText(decodeHtmlEntities(String(it)));
    }

    function extractAvatarUrl(it) {
        if (!it || typeof it !== 'object') return '';

        var v =
            (typeof it.avatar === 'string' && it.avatar) ||
            (typeof it.avatar_url === 'string' && it.avatar_url) ||
            (typeof it.avatarUrl === 'string' && it.avatarUrl) ||
            (typeof it.avatar_small === 'string' && it.avatar_small) ||
            (typeof it.avatarSmall === 'string' && it.avatarSmall) ||
            (typeof it.avatar_thumb === 'string' && it.avatar_thumb) ||
            (typeof it.avatarThumb === 'string' && it.avatarThumb) ||
            '';

        v = String(v == null ? '' : v);

        // если вдруг прилетает HTML с <img ...>, вытаскиваем src
        if (v.indexOf('<') !== -1 && v.toLowerCase().indexOf('img') !== -1) {
            var m = v.match(/\bsrc\s*=\s*["']([^"']+)["']/i);
            if (m && m[1]) v = m[1];
        }

        v = stripTagsToText(decodeHtmlEntities(v)).trim();
        if (!v) return '';

        // 1) абсолютный или protocol-relative
        if (/^(https?:)?\/\//i.test(v)) return v;

        // 2) абсолютный от корня сайта
        if (v.charAt(0) === '/') {
            try { return new URL(v, document.baseURI).href; } catch (e0) { return v; }
        }

        // 3) относительный путь (например uploads/avatars/...)
        // super-важно: baseURI учитывает подпапку форума
        try {
            return new URL(v, document.baseURI).href;
        } catch (e1) {
            return '';
        }
    }


    function extractUid(it) {
        if (!it || typeof it !== 'object') return 0;
        var v = (it.uid != null ? it.uid : (it.id != null ? it.id : (it.user_id != null ? it.user_id : 0)));
        var n = parseInt(v, 10) || 0;
        return n > 0 ? n : 0;
    }

    function renderItems(list, rect, ctxWin) {
        if (!box) return;

        ensureBoxHost(ctxWin);

        box.innerHTML = '';
        items = Array.isArray(list) ? list : [];
        selectedIndex = -1;

        if (!items.length) {
            hideBox();
            return;
        }

        items.forEach(function (it) {
            var username = extractUsername(it);
            var avatarUrl = extractAvatarUrl(it);

            var item = document.createElement('div');
            item.className = 'af-aam-suggest-item';

            // avatar
            var av = document.createElement('span');
            av.className = 'af-aam-suggest-avatar';

            if (avatarUrl) {
                var img = document.createElement('img');
                img.src = avatarUrl;
                img.alt = username ? (username + ' avatar') : 'avatar';
                img.loading = 'lazy';

                img.addEventListener('error', function () {
                    // если картинка не загрузилась — откатываемся на букву
                    try { av.innerHTML = ''; } catch (e0) {}
                    var ph = document.createElement('span');
                    ph.className = 'af-aam-suggest-avatar-placeholder';
                    ph.textContent = (username && username.trim()) ? username.trim().charAt(0).toUpperCase() : '?';
                    av.appendChild(ph);
                });

                av.appendChild(img);
            } else {
                var ph = document.createElement('span');
                ph.className = 'af-aam-suggest-avatar-placeholder';
                ph.textContent = (username && username.trim()) ? username.trim().charAt(0).toUpperCase() : '?';
                av.appendChild(ph);
            }


            // name
            var nm = document.createElement('span');
            nm.className = 'af-aam-suggest-name';
            nm.textContent = username;

            item.appendChild(av);
            item.appendChild(nm);

            item.addEventListener('mousedown', function (e) {
                e.preventDefault();
                applySuggestion(it);
            });

            box.appendChild(item);
        });

        placeBoxByRect(rect, ctxWin);
        box.style.display = 'block';
    }


    function abortActive() {
        if (activeController && typeof activeController.abort === 'function') {
            try { activeController.abort(); } catch (e) {}
        }
        activeController = null;
    }

    function normPrefix(p) {
        p = String(p == null ? '' : p).trim();
        p = p.replace(/^@+/, '');
        return p;
    }

    function cacheGet(key) {
        var rec = cache.get(key);
        if (!rec) return null;
        if ((Date.now() - rec.ts) > CACHE_TTL_MS) {
            cache.delete(key);
            return null;
        }
        return rec.data;
    }

    function cacheSet(key, data) {
        if (cache.size >= MAX_CACHE_KEYS) {
            var oldestKey = null;
            var oldestTs = Infinity;
            cache.forEach(function (v, k) {
                if (v.ts < oldestTs) { oldestTs = v.ts; oldestKey = k; }
            });
            if (oldestKey !== null) cache.delete(oldestKey);
        }
        cache.set(key, { ts: Date.now(), data: data });
    }

    // ====== Robust JSON extraction from garbage HTML ======

    function normalizeList(data) {
        var list = null;

        if (Array.isArray(data)) list = data;
        else if (data && Array.isArray(data.users)) list = data.users;
        else if (data && Array.isArray(data.items)) list = data.items;
        else if (data && Array.isArray(data.results)) list = data.results;
        else if (data && Array.isArray(data.suggestions)) list = data.suggestions;
        else if (data && Array.isArray(data.matches)) list = data.matches;

        else if (data && data.data && Array.isArray(data.data)) list = data.data;
        else if (data && data.data && Array.isArray(data.data.users)) list = data.data.users;
        else if (data && data.data && Array.isArray(data.data.items)) list = data.data.items;
        else if (data && data.data && Array.isArray(data.data.results)) list = data.data.results;
        else if (data && data.payload && Array.isArray(data.payload)) list = data.payload;
        else if (data && data.payload && Array.isArray(data.payload.users)) list = data.payload.users;

        return Array.isArray(list) ? list : null;
    }

    function scoreList(list) {
        if (!Array.isArray(list) || !list.length) return 0;

        var score = 0;
        score += Math.min(50, list.length * 2);

        for (var i = 0; i < list.length && i < 25; i++) {
            var it = list[i];
            var u = extractUsername(it);
            if (u) score += 10;
            if (it && typeof it === 'object') {
                var uid = extractUid(it);
                if (uid > 0) score += 4;
            }
        }
        return score;
    }

    function findMatchingChunk(text, startIndex, openCh, closeCh) {
        var depth = 0;
        var inStr = false;
        var strQ = '';
        var esc = false;

        for (var i = startIndex; i < text.length; i++) {
            var ch = text.charAt(i);

            if (inStr) {
                if (esc) { esc = false; continue; }
                if (ch === '\\') { esc = true; continue; }
                if (ch === strQ) { inStr = false; strQ = ''; continue; }
                continue;
            } else {
                if (ch === '"' || ch === "'") { inStr = true; strQ = ch; continue; }
                if (ch === openCh) depth++;
                else if (ch === closeCh) {
                    depth--;
                    if (depth === 0) return text.substring(startIndex, i + 1);
                }
            }
        }
        return '';
    }

    function tryParseCandidate(chunk) {
        if (!chunk) return null;
        try { return JSON.parse(chunk); } catch (e) { return null; }
    }

    function stripJsonPrefix(s) {
        s = String(s == null ? '' : s);
        s = s.replace(/^\s*while\s*\(\s*1\s*\)\s*;\s*/i, '');
        s = s.replace(/^\s*\)\]\}',\s*\n?/, '');
        return s;
    }

    function parseResponseToList(rawText) {
        var t = String(rawText || '');
        if (!t) return null;

        if (t.length > 300000) t = t.slice(0, 300000);

        t = stripJsonPrefix(t);

        if (t.indexOf('&quot;') !== -1 || t.indexOf('&#34;') !== -1 || t.indexOf('&amp;') !== -1) {
            t = decodeHtmlEntities(t);
        }

        var trimmed = t.trim();
        if ((trimmed.charAt(0) === '[' && trimmed.charAt(trimmed.length - 1) === ']') ||
            (trimmed.charAt(0) === '{' && trimmed.charAt(trimmed.length - 1) === '}')) {
            var direct = tryParseCandidate(trimmed);
            if (direct != null) {
                var dl = normalizeList(direct);
                if (dl) return dl;
            }
        }

        var bestList = null;
        var bestScore = 0;
        var tries = 0;
        var maxTries = 120;

        var idx = -1;
        while (tries < maxTries) {
            idx = t.indexOf('[', idx + 1);
            if (idx < 0) break;

            var chunk = findMatchingChunk(t, idx, '[', ']');
            if (!chunk) continue;

            var parsed = tryParseCandidate(chunk);
            if (parsed == null) continue;

            var list = normalizeList(parsed);
            if (!list) continue;

            var s = scoreList(list);
            if (s > bestScore) {
                bestScore = s;
                bestList = list;
            }
            tries++;
        }

        idx = -1;
        while (tries < maxTries) {
            idx = t.indexOf('{', idx + 1);
            if (idx < 0) break;

            var chunk2 = findMatchingChunk(t, idx, '{', '}');
            if (!chunk2) continue;

            var parsed2 = tryParseCandidate(chunk2);
            if (parsed2 == null) continue;

            var list2 = normalizeList(parsed2);
            if (!list2) continue;

            var s2 = scoreList(list2);
            if (s2 > bestScore) {
                bestScore = s2;
                bestList = list2;
            }
            tries++;
        }

        return bestList;
    }

    function requestSuggest(prefix, rect, ctxWin) {
        prefix = normPrefix(prefix);

        if (!prefix || prefix.length < 2) {
            hideBox();
            return;
        }

        var key = prefix.toLowerCase();
        var cached = cacheGet(key);
        if (cached) {
            renderItems(cached, rect, ctxWin);
            return;
        }

        if (debounceTimer) clearTimeout(debounceTimer);

        debounceTimer = setTimeout(function () {
            abortActive();

            lastQuery = prefix;

            var controller = null;
            try { controller = new AbortController(); } catch (e) { controller = null; }
            activeController = controller;

            var url = mentionSuggestUrl;
            url += (url.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(prefix);
            url += '&_af_xhr=1&_=' + Date.now();

            function doRenderList(list) {
                if (controller && controller !== activeController) return;
                activeController = null;

                if (!Array.isArray(list)) {
                    hideBox();
                    return;
                }
                if (prefix !== lastQuery) return;

                cacheSet(key, list);
                renderItems(list, rect, ctxWin);
            }

            if (window.fetch) {
                window.fetch(url, {
                    signal: controller ? controller.signal : undefined,
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Accept': 'application/json, text/plain;q=0.9, */*;q=0.8',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                    .then(function (resp) {
                        if (!resp) return '';
                        return resp.text().then(function (txt) {
                            if (!resp.ok && DEBUG) {
                                log('[AAM] HTTP', resp.status, 'first 400:', String(txt || '').slice(0, 400));
                            }
                            return txt;
                        });
                    })
                    .then(function (text) {
                        if (controller && controller !== activeController) return;

                        if (DEBUG) {
                            log('[AAM] suggest url=', url);
                            log('[AAM] resp first 300=', String(text || '').slice(0, 300));
                        }

                        var list = parseResponseToList(text);
                        if (!list) {
                            if (DEBUG) log('[AAM] No valid mentions list parsed.');
                            doRenderList([]);
                            return;
                        }

                        doRenderList(list);
                    })
                    .catch(function (err) {
                        if (err && err.name === 'AbortError') return;
                        if (DEBUG) log('[AAM] fetch error:', err);
                        hideBox();
                    });
            } else {
                try {
                    var xhr = new XMLHttpRequest();
                    xhr.open('GET', url, true);
                    xhr.withCredentials = true;
                    xhr.setRequestHeader('Accept', 'application/json, text/plain;q=0.9, */*;q=0.8');
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                    xhr.onreadystatechange = function () {
                        if (xhr.readyState !== 4) return;

                        var raw = xhr.responseText || '';
                        if (DEBUG) log('[AAM] xhr status=', xhr.status, 'first 300=', String(raw).slice(0, 300));

                        if (xhr.status >= 200 && xhr.status < 300) {
                            var list = parseResponseToList(raw);
                            doRenderList(list || []);
                        } else {
                            hideBox();
                        }
                    };
                    xhr.send(null);
                } catch (e3) {
                    hideBox();
                }
            }
        }, 180);
    }

    function getStableRangeRect(win, doc, range) {
        try {
            if (range && range.getClientRects) {
                var rects = range.getClientRects();
                if (rects && rects.length) {
                    var r0 = rects[0];
                    if (r0 && (r0.left || r0.top || r0.bottom || r0.right)) return r0;
                }
            }
        } catch (e) {}

        try {
            var marker = doc.createElement('span');
            marker.textContent = '\u200b';
            marker.style.display = 'inline-block';
            marker.style.width = '1px';
            marker.style.height = '1em';
            marker.style.padding = '0';
            marker.style.margin = '0';

            var r = range.cloneRange();
            r.collapse(true);
            r.insertNode(marker);

            var rect = marker.getBoundingClientRect();

            marker.parentNode && marker.parentNode.removeChild(marker);

            return rect || null;
        } catch (e2) {
            return null;
        }
    }

    function buildMentionText(username, uid) {
        var u = extractUsername(username)
            .replace(/[\r\n]+/g, ' ')
            .replace(/"/g, '')
            .trim();
        if (!u) return '';

        var mentionUid = parseInt(uid || '0', 10) || 0;
        return (mentionUid > 0)
            ? '[mention=' + mentionUid + ']' + u + '[/mention] '
            : '[mention]' + u + '[/mention] ';
    }

    function insertIntoTextarea(ta, text) {
        try {
            var start = ta.selectionStart || ta.value.length;
            var end = ta.selectionEnd || ta.value.length;
            var before = ta.value.substring(0, start);
            var after = ta.value.substring(end);
            ta.value = before + text + after;
            var pos = before.length + text.length;
            ta.selectionStart = ta.selectionEnd = pos;
            ta.focus();
        } catch (e) {
            try { document.execCommand('insertText', false, text); } catch (e2) {}
        }
    }

    function applySuggestion(item) {
        try {
            if (typeof window.afAamJumpToReplyAndFocus === 'function') {
                window.afAamJumpToReplyAndFocus();
            }
        } catch (e0) {}

        try {
            textarea = getActiveTextareaFallback();
            refreshSceditorRefs();
        } catch (e1) {}

        var username = extractUsername(item);
        var uid = extractUid(item);

        var mentionText = buildMentionText(username, uid);
        if (!mentionText) return;

        if (state.mode === 'textarea' && textarea) {
            var value = textarea.value || '';
            var caret = textarea.selectionStart || state.caret || value.length;
            var atPos = state.atIndex;

            if (atPos < 0 || atPos >= caret) {
                insertIntoTextarea(textarea, mentionText);
                hideBox();
                return;
            }

            var before = value.substring(0, atPos);
            var after = value.substring(caret);
            textarea.value = before + mentionText + after;

            var newCaret = before.length + mentionText.length;
            textarea.focus();
            textarea.selectionStart = textarea.selectionEnd = newCaret;

            hideBox();
            return;
        }

        if (state.mode === 'sceditor' && sceditor && scWin && scBody && scDoc) {
            try {
                sceditor.focus();

                var sel = scWin.getSelection ? scWin.getSelection() : null;
                if (!sel || sel.rangeCount === 0) {
                    sceditor.insertText(mentionText);
                    hideBox();
                    return;
                }

                var r = sel.getRangeAt(0);
                var endNode = state.textNode && state.textNode.nodeType === 3 ? state.textNode : r.endContainer;
                var endOffset = state.textNodeCaretOffset || r.endOffset;

                if (!endNode || endNode.nodeType !== 3) {
                    sceditor.insertText(mentionText);
                    hideBox();
                    return;
                }

                var startOffset = state.textNodeAtOffset;
                if (startOffset < 0 || startOffset > endOffset) {
                    sceditor.insertText(mentionText);
                    hideBox();
                    return;
                }

                var rr = scDoc.createRange();
                rr.setStart(endNode, startOffset);
                rr.setEnd(endNode, endOffset);
                rr.deleteContents();

                var tn = scDoc.createTextNode(mentionText);
                rr.insertNode(tn);

                rr.setStartAfter(tn);
                rr.collapse(true);
                sel.removeAllRanges();
                sel.addRange(rr);

                hideBox();
                return;
            } catch (e) {
                try { sceditor.insertText(mentionText); } catch (e2) {}
                hideBox();
                return;
            }
        }

        if (sceditor) {
            try { sceditor.focus(); sceditor.insertText(mentionText); hideBox(); return; } catch (e3) {}
        }
        if (textarea) {
            insertIntoTextarea(textarea, mentionText);
            hideBox();
            return;
        }

        hideBox();
    }

    function getTextareaCaretRect(el, position) {
        var div = document.createElement('div');
        var style = window.getComputedStyle(el);
        Array.prototype.forEach.call(style, function (prop) {
            div.style[prop] = style[prop];
        });
        div.style.position = 'absolute';
        div.style.visibility = 'hidden';
        div.style.whiteSpace = 'pre-wrap';
        div.style.wordWrap = 'break-word';
        div.style.overflow = 'hidden';
        div.style.width = el.clientWidth + 'px';

        var elRect = el.getBoundingClientRect();
        div.style.left = elRect.left + 'px';
        div.style.top = elRect.top + 'px';

        div.textContent = (el.value || '').substring(0, position);
        var span = document.createElement('span');
        span.textContent = (el.value || '').substring(position) || '.';
        div.appendChild(span);

        document.body.appendChild(div);
        var rect = span.getBoundingClientRect();
        document.body.removeChild(div);

        return { left: rect.left, top: rect.top, bottom: rect.bottom };
    }

    function parseTextareaContext() {
        var ta = getActiveTextareaFallback();
        if (!ta) return null;
        if (!isLikelyEditorTextarea(ta)) return null;

        textarea = ta;

        var pos = textarea.selectionStart || 0;
        var value = textarea.value || '';
        var slice = value.substring(0, pos);

        var atPos = slice.lastIndexOf('@');
        if (atPos === -1) return null;

        var prefix = slice.substring(atPos + 1);
        if (!prefix || /\s/.test(prefix)) return null;

        prefix = normPrefix(prefix);
        if (prefix.length < 2) return null;

        state.mode = 'textarea';
        state.prefix = prefix;
        state.atIndex = atPos;
        state.caret = pos;

        var rect = getTextareaCaretRect(textarea, pos);
        return { prefix: prefix, rect: rect, win: window };
    }

    function refreshSceditorRefs() {
        if (!(window.jQuery && window.jQuery.fn && window.jQuery.fn.sceditor)) return false;

        if (!sceditor) {
            try {
                var baseTa = findTextarea();
                if (baseTa) sceditor = window.jQuery(baseTa).sceditor('instance') || null;
            } catch (e) { sceditor = null; }
        }

        if (!sceditor) return false;

        try { scBody = sceditor.getBody(); } catch (e2) { scBody = null; }
        if (!scBody) return false;

        try {
            var area = (typeof sceditor.getContentAreaContainer === 'function')
                ? sceditor.getContentAreaContainer()
                : null;

            var iframe = area ? area.querySelector('iframe') : null;
            scIframe = iframe || null;

            if (iframe && iframe.contentWindow) {
                scWin = iframe.contentWindow;
                scDoc = iframe.contentDocument || scWin.document;
            } else {
                scWin = scBody.ownerDocument && scBody.ownerDocument.defaultView ? scBody.ownerDocument.defaultView : null;
                scDoc = scBody.ownerDocument || null;
            }
        } catch (e3) {
            scWin = null;
            scDoc = null;
            scIframe = null;
        }

        return !!(sceditor && scBody && scWin && scDoc);
    }

    function parseSceditorContext() {
        if (!sceditor || !scBody || !scWin || !scDoc) return null;

        var sel = scWin.getSelection ? scWin.getSelection() : null;
        if (!sel || sel.rangeCount === 0) return null;

        var r = sel.getRangeAt(0);
        if (!r || !r.endContainer) return null;

        var endNode = r.endContainer;
        var endOffset = r.endOffset;

        if (!scBody.contains(endNode)) return null;

        if (endNode.nodeType === 1) {
            var child = endNode.childNodes[endOffset - 1] || endNode.childNodes[endOffset] || null;
            if (child && child.nodeType === 3) {
                endNode = child;
                endOffset = child.nodeValue ? child.nodeValue.length : 0;
            }
        }

        if (!endNode || endNode.nodeType !== 3) return null;

        var t = endNode.nodeValue || '';
        var i = endOffset - 1;

        while (i >= 0) {
            var ch = t.charAt(i);
            if (ch === '@') break;
            if (/\s/.test(ch)) return null;
            i--;
        }

        if (i < 0 || t.charAt(i) !== '@') return null;

        var prefix = t.substring(i + 1, endOffset);
        prefix = normPrefix(prefix);
        if (!prefix || prefix.length < 2) return null;

        state.mode = 'sceditor';
        state.prefix = prefix;
        state.textNode = endNode;
        state.textNodeAtOffset = i;
        state.textNodeCaretOffset = endOffset;

        var rect = getStableRangeRect(scWin, scDoc, r);
        if (!rect && scBody) {
            try { rect = scBody.getBoundingClientRect(); } catch (e2) {}
        }

        return { prefix: prefix, rect: rect, win: scWin };
    }

    function onKeyUp() {
        var ctx = null;

        if (scBody && scWin) ctx = parseSceditorContext();
        if (!ctx) ctx = parseTextareaContext();

        if (!ctx) {
            hideBox();
            return;
        }

        requestSuggest(ctx.prefix, ctx.rect, ctx.win);
    }

    function onKeyDown(e) {
        if (!box || box.style.display !== 'block') return;

        var handled = false;

        if (e.key === 'ArrowDown') {
            selectedIndex = Math.min(items.length - 1, selectedIndex + 1);
            highlight(selectedIndex);
            handled = true;
        } else if (e.key === 'ArrowUp') {
            selectedIndex = (selectedIndex <= 0) ? (items.length - 1) : (selectedIndex - 1);
            highlight(selectedIndex);
            handled = true;
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            handled = true;
            if (selectedIndex >= 0 && items[selectedIndex]) applySuggestion(items[selectedIndex]);
            else hideBox();
        } else if (e.key === 'Escape') {
            hideBox();
            handled = true;
        }

        if (handled) e.preventDefault();
    }

    function bindTextarea() {
        if (document.__afAamMentionsTextareaDelegated) return;
        document.__afAamMentionsTextareaDelegated = true;

        function isOurTa(t) {
            return t && t.tagName === 'TEXTAREA' && isLikelyEditorTextarea(t);
        }

        document.addEventListener('keyup', function (e) {
            var t = e && e.target;
            if (!isOurTa(t)) return;
            onKeyUp();
        }, true);

        document.addEventListener('input', function (e) {
            var t = e && e.target;
            if (!isOurTa(t)) return;
            onKeyUp();
        }, true);

        document.addEventListener('keydown', function (e) {
            var t = e && e.target;
            if (!isOurTa(t)) return;
            onKeyDown(e);
        }, true);

        document.addEventListener('blur', function (e) {
            var t = e && e.target;
            if (!isOurTa(t)) return;
            setTimeout(hideBox, 150);
        }, true);
    }

    function bindSceditor() {
        if (!sceditor) return;
        if (!refreshSceditorRefs()) return;
        if (!scDoc) return;

        if (scDoc.__afAamMentionsBound) return;
        scDoc.__afAamMentionsBound = true;

        scDoc.addEventListener('keyup', onKeyUp, true);
        scDoc.addEventListener('input', onKeyUp, true);
        scDoc.addEventListener('keydown', onKeyDown, true);

        scDoc.addEventListener('blur', function () {
            setTimeout(hideBox, 150);
        }, true);
    }

    function initMentions() {
        createBox();

        textarea = findTextarea();
        bindTextarea();

        if (refreshSceditorRefs()) {
            bindSceditor();
        } else {
            var tries = 0;
            var poll = setInterval(function () {
                tries++;
                if (tries > 60) { clearInterval(poll); return; }
                if (!textarea) textarea = findTextarea();
                if (refreshSceditorRefs()) {
                    bindSceditor();
                    clearInterval(poll);
                }
            }, 350);
        }

        document.addEventListener('mousedown', function (e) {
            if (!box || box.style.display !== 'block') return;
            if (e.target === box || box.contains(e.target)) return;
            hideBox();
        });

        // Скролл окна/вложенных областей может смещать каретку -> проще закрыть
        window.addEventListener('scroll', function () {
            if (box && box.style.display === 'block') hideBox();
        }, true);

        function findMentionElement(node) {
            var cur = node;
            while (cur && cur !== document) {
                if (cur.nodeType === 1) {
                    if (cur.classList && cur.classList.contains('af-aam-mention-button')) return cur;
                    if ((cur.classList && (cur.classList.contains('mention_user') || cur.classList.contains('af-aam-mention-user')))
                        || (cur.getAttribute && cur.getAttribute('data-mention') === '1')) return cur;
                }
                cur = cur.parentNode;
            }
            return null;
        }

        // ---- Mention button click (deduped) ----
        if (!document.__afAamMentionButtonsBound) {
            document.__afAamMentionButtonsBound = true;

            // global dedupe
            var __afAamLastMentionInsertTs = 0;
            var __afAamLastMentionInsertText = '';

            document.addEventListener('click', function (e) {
                var node = findMentionElement(e.target);
                if (!node) return;

                // stop other listeners from also handling this click
                e.preventDefault();
                e.stopPropagation();
                if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();

                var username = '';
                var uid = 0;

                if (node.getAttribute) {
                    username = (node.getAttribute('data-username') || node.getAttribute('data-mention-username') || node.textContent || '').trim();
                    uid = parseInt(node.getAttribute('data-uid') || '0', 10) || 0;
                }

                var mentionText = buildMentionText(username, uid);
                if (!mentionText) return;

                // node-level dedupe (fast double click / double handler)
                var now = Date.now();
                try {
                    var lastNodeTs = parseInt(node.getAttribute('data-af-aam-lastclick') || '0', 10) || 0;
                    var lastNodeText = node.getAttribute('data-af-aam-lasttext') || '';
                    if (lastNodeTs && (now - lastNodeTs) < 250 && lastNodeText === mentionText) {
                        return;
                    }
                    node.setAttribute('data-af-aam-lastclick', String(now));
                    node.setAttribute('data-af-aam-lasttext', mentionText);
                } catch (e0) {}

                // global dedupe (если обработчик всё равно вызвался дважды)
                if (__afAamLastMentionInsertTs && (now - __afAamLastMentionInsertTs) < 250 && __afAamLastMentionInsertText === mentionText) {
                    return;
                }
                __afAamLastMentionInsertTs = now;
                __afAamLastMentionInsertText = mentionText;

                // Insert
                if (sceditor) {
                    try { sceditor.focus(); sceditor.insertText(mentionText); return; } catch (e2) {}
                }
                if (!textarea) textarea = findTextarea();
                if (textarea) insertIntoTextarea(textarea, mentionText);
            }, true); // capture=true helps intercept earlier
        }

        log('[AAM mentions] init ok. textarea=', !!textarea, 'sceditor=', !!sceditor, 'url=', mentionSuggestUrl);
    }

    ready(initMentions);
})();

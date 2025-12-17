(function () {
    'use strict';

    if (window.afAamMentionsInit) return;
    window.afAamMentionsInit = true;

    var DEBUG = !!window.afAamMentionsDebug;

    var mentionSuggestUrl = (typeof window.af_aam_mention_suggest_url === 'string' && window.af_aam_mention_suggest_url)
        ? window.af_aam_mention_suggest_url
        : 'misc.php?action=af_mention_suggest';

    // UI
    var box = null;
    var boxHost = null; // контейнер, внутри которого живёт бокс (quickreply_e)
    var items = [];
    var selectedIndex = -1;

    // editor refs
    var textarea = null;
    var sceditor = null;
    var scBody = null;
    var scWin = null; // iframe window for SCEditor
    var scDoc = null;
    var scIframe = null; // сам iframe SCEditor

    // state about current "@prefix"
    var state = {
        mode: '',              // 'textarea' | 'sceditor' | 'ckeditor'
        prefix: '',
        // textarea indexes
        atIndex: -1,
        caret: 0,
        // contenteditable node/range info
        textNode: null,
        textNodeAtOffset: 0,
        textNodeCaretOffset: 0
    };

    // request control
    var debounceTimer = null;
    var activeController = null;
    var lastQuery = '';

    // client cache (reduce server load)
    var cache = new Map(); // key -> {ts, data}
    var CACHE_TTL_MS = 60 * 1000;
    var MAX_CACHE_KEYS = 200;

    function log() {
        if (!DEBUG || !window.console) return;
        try { console.log.apply(console, arguments); } catch (e) {}
    }

    function ready(fn) {
        if (document.readyState === 'complete' || document.readyState === 'interactive') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    function findBoxHost() {
        // просили прямо внутри quickreply_e
        var host = document.getElementById('quickreply_e');
        if (host) return host;

        // запасные варианты (если страница без quickreply)
        return (
            document.getElementById('message') ||
            document.querySelector('.quickreply') ||
            document.body
        );
    }

    function ensureHostPositioning(host) {
        if (!host || host === document.body) return;
        try {
            var cs = window.getComputedStyle(host);
            if (cs && cs.position === 'static') {
                host.style.position = 'relative';
            }
        } catch (e) {}
    }

    function createBox() {
        boxHost = findBoxHost();
        ensureHostPositioning(boxHost);

        box = document.createElement('div');
        box.className = 'af-aam-suggest-box';
        box.style.display = 'none';

        // ВАЖНО: позиционируем ОТНОСИТЕЛЬНО boxHost
        box.style.position = 'absolute';
        box.style.zIndex = '999999';
        box.style.maxHeight = '260px';
        box.style.overflowY = 'auto';
        box.tabIndex = -1;

        // кладём внутрь quickreply_e (или фоллбек)
        (boxHost || document.body).appendChild(box);
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

    /**
     * rect приходит в координатах viewport (top window), или в координатах iframe viewport (для SCEditor).
     * Мы переводим это в координаты ВНУТРИ boxHost (quickreply_e), и ставим box.style.left/top.
     */
    function placeBoxByRect(rect, ctxWin) {
        if (!box || !rect) return;

        // обновим host на всякий случай (иногда quickreply грузится/перерисовывается)
        if (!boxHost || !document.body.contains(boxHost)) {
            boxHost = findBoxHost();
            ensureHostPositioning(boxHost);
            if (box && box.parentNode !== boxHost && boxHost) {
                try { boxHost.appendChild(box); } catch (e) {}
            }
        }

        if (!boxHost) return;

        // rect.left / rect.bottom должны быть числами
        var left = rect.left;
        var bottom = rect.bottom;
        if (typeof left !== 'number' || typeof bottom !== 'number') return;

        // 1) получаем координаты caret в viewport TOP окна
        var globalLeft = left;
        var globalBottom = bottom;

        if (ctxWin && ctxWin !== window) {
            // rect из iframe viewport -> переводим через iframe.getBoundingClientRect()
            if (scIframe && scIframe.getBoundingClientRect) {
                var iframeRect = scIframe.getBoundingClientRect();
                globalLeft = iframeRect.left + left;
                globalBottom = iframeRect.top + bottom;
            }
        }

        // 2) переводим viewport coords -> coords внутри boxHost
        var hostRect = boxHost.getBoundingClientRect ? boxHost.getBoundingClientRect() : { left: 0, top: 0 };
        var hostScrollLeft = boxHost.scrollLeft || 0;
        var hostScrollTop = boxHost.scrollTop || 0;

        var x = (globalLeft - hostRect.left) + hostScrollLeft;
        var y = (globalBottom - hostRect.top) + hostScrollTop + 6;

        // минимальные отступы, чтобы не липло к краю
        x = Math.max(8, x);
        y = Math.max(8, y);

        box.style.left = x + 'px';
        box.style.top = y + 'px';
    }

    function renderItems(list, rect, ctxWin) {
        if (!box) return;

        box.innerHTML = '';
        items = Array.isArray(list) ? list : [];
        selectedIndex = -1;

        if (!items.length) {
            hideBox();
            return;
        }

        items.forEach(function (it) {
            var item = document.createElement('div');
            item.className = 'af-aam-suggest-item';
            item.textContent = it && it.username ? it.username : String(it || '');
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
        p = String(p || '').trim();
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

            var url = mentionSuggestUrl + (mentionSuggestUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(prefix);

            var doRender = function (data) {
                if (controller && controller !== activeController) return;
                activeController = null;

                if (!Array.isArray(data)) {
                    hideBox();
                    return;
                }
                if (prefix !== lastQuery) return;

                cacheSet(key, data);
                renderItems(data, rect, ctxWin);
            };

            if (window.fetch) {
                window.fetch(url, {
                    signal: controller ? controller.signal : undefined,
                    credentials: 'same-origin'
                })
                    .then(function (resp) { return resp && resp.ok ? resp.json() : []; })
                    .then(doRender)
                    .catch(function (err) {
                        if (err && err.name === 'AbortError') return;
                        hideBox();
                    });
            } else {
                try {
                    var xhr = new XMLHttpRequest();
                    xhr.open('GET', url, true);
                    xhr.withCredentials = true;
                    xhr.onreadystatechange = function () {
                        if (xhr.readyState !== 4) return;
                        if (xhr.status >= 200 && xhr.status < 300) {
                            try { doRender(JSON.parse(xhr.responseText || '[]')); } catch (e2) { hideBox(); }
                        } else hideBox();
                    };
                    xhr.send(null);
                } catch (e3) {
                    hideBox();
                }
            }
        }, 180);
    }

    function getStableRangeRect(win, doc, range) {
        // 1) пробуем client rects
        try {
            if (range && range.getClientRects) {
                var rects = range.getClientRects();
                if (rects && rects.length) {
                    var r0 = rects[0];
                    // иногда браузер отдаёт нули — это и даёт "в левый верх"
                    if (r0 && (r0.left || r0.top || r0.bottom || r0.right)) return r0;
                }
            }
        } catch (e) {}

        // 2) фоллбек: маркер (самый надёжный способ)
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

            if (rect && (rect.left || rect.top || rect.bottom || rect.right)) return rect;
            return rect || null;
        } catch (e2) {
            return null;
        }
    }

    function buildMentionText(username, uid) {
        var u = String(username || '')
            .replace(/[\r\n]+/g, ' ')
            .replace(/"/g, '')
            .trim();
        if (!u) return '';

        var mentionUid = parseInt(uid || '0', 10) || 0;
        return (mentionUid > 0)
            ? '[mention=' + mentionUid + ']' + u + '[/mention] '
            : '[mention]' + u + '[/mention] ';
    }

    function applySuggestion(item) {
        // 0) сначала прыгнуть к quickreply и раскрыть его (если он свернут/скрыт)
        // это нужно именно для кейса "клик по подсказке — но редактор далеко/скрыт"
        try {
            if (typeof window.afAamJumpToReplyAndFocus === 'function') {
                window.afAamJumpToReplyAndFocus();
            }
        } catch (e0) {}

        // после прыжка quickreply мог раскрыться, и инстанс SCEditor мог появиться/обновиться
        try {
            if (!textarea) textarea = findTextarea();
            refreshSceditorRefs();
        } catch (e1) {}

        var username = item && item.username ? item.username : item;
        var uid = item && item.uid ? parseInt(item.uid, 10) || 0 : 0;

        var mentionText = buildMentionText(username, uid);
        if (!mentionText) return;

        // TEXTAREA replace
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

        // SCEDITOR replace (iframe selection!)
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

        // fallback
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
        if (!textarea) return null;

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
        if (!(window.jQuery && window.jQuery.fn && window.jQuery.fn.sceditor)) return;

        if (textarea && !sceditor) {
            try { sceditor = window.jQuery(textarea).sceditor('instance') || null; } catch (e) { sceditor = null; }
        }

        if (!sceditor) return;

        try { scBody = sceditor.getBody(); } catch (e2) { scBody = null; }
        if (!scBody) return;

        try {
            var area = (typeof sceditor.getContentAreaContainer === 'function') ? sceditor.getContentAreaContainer() : null;
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

        return true;
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
        if (!ctx && textarea) ctx = parseTextareaContext();

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
        if (!textarea) return;
        textarea.addEventListener('keyup', onKeyUp);
        textarea.addEventListener('keydown', onKeyDown);
        textarea.addEventListener('blur', function () { setTimeout(hideBox, 150); });
    }

    function bindSceditor() {
        if (!sceditor || !scBody) return;
        scBody.addEventListener('keyup', onKeyUp);
        scBody.addEventListener('keydown', onKeyDown);
        scBody.addEventListener('blur', function () { setTimeout(hideBox, 150); });
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

        // close box on outside click / scroll
        document.addEventListener('mousedown', function (e) {
            if (!box || box.style.display !== 'block') return;
            if (e.target === box || box.contains(e.target)) return;
            hideBox();
        });

        // если скроллят сам quickreply_e — тоже прячем
        window.addEventListener('scroll', function () {
            if (box && box.style.display === 'block') hideBox();
        }, true);

        // mention button / click on username in postbit
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

        document.addEventListener('click', function (e) {
            var node = findMentionElement(e.target);
            if (!node) return;

            e.preventDefault();
            var username = '';
            var uid = 0;

            if (node.getAttribute) {
                username = (node.getAttribute('data-username') || node.getAttribute('data-mention-username') || node.textContent || '').trim();
                uid = parseInt(node.getAttribute('data-uid') || '0', 10) || 0;
            }

            var mentionText = buildMentionText(username, uid);
            if (!mentionText) return;

            if (sceditor) {
                try { sceditor.focus(); sceditor.insertText(mentionText); return; } catch (e2) {}
            }
            if (!textarea) textarea = findTextarea();
            if (textarea) insertIntoTextarea(textarea, mentionText);
        });

        log('[AAM mentions] init ok. host=', boxHost && boxHost.id, 'textarea=', !!textarea, 'sceditor=', !!sceditor);
    }

    ready(initMentions);
})();

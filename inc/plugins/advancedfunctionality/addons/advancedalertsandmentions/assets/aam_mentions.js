(function () {
    if (window.afAamMentionsInit) {
        return;
    }
    window.afAamMentionsInit = true;

    var mentionSuggestUrl = (typeof window.af_aam_mention_suggest_url === 'string' && window.af_aam_mention_suggest_url)
        ? window.af_aam_mention_suggest_url
        : 'misc.php?action=af_mention_suggest';

    var box = null;
    var items = [];
    var selectedIndex = -1;
    var state = { mode: '', prefix: '', lastAtIndex: -1, lastCaret: 0, lastTextNode: null, lastTextNodeAtOffset: 0 };
    var textarea = null;
    var sceditor = null;
    var scBody = null;
    var scPoll = null;
    var scPollTries = 0;
    var debounceTimer = null;
    var activeController = null;
    var lastQuery = '';

    function ready(fn) {
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function createBox() {
        box = document.createElement('div');
        box.className = 'af-aam-suggest-box';
        box.style.display = 'none';
        box.tabIndex = -1;
        document.body.appendChild(box);
    }

    function clearState() {
        selectedIndex = -1;
        items = [];
        state.mode = '';
        state.prefix = '';
        state.lastAtIndex = -1;
    }

    function hideBox() {
        if (box) {
            box.style.display = 'none';
            box.innerHTML = '';
        }
        clearState();
    }

    function highlight(index) {
        var children = box ? box.children : [];
        Array.prototype.forEach.call(children, function (el, i) {
            if (i === index) {
                el.classList.add('is-selected');
            } else {
                el.classList.remove('is-selected');
            }
        });
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
        div.style.left = (elRect.left + window.scrollX) + 'px';
        div.style.top = (elRect.top + window.scrollY) + 'px';
        div.textContent = el.value.substring(0, position);
        var span = document.createElement('span');
        span.textContent = el.value.substring(position) || '.';
        div.appendChild(span);
        document.body.appendChild(div);
        var rect = span.getBoundingClientRect();
        var result = { left: rect.left, top: rect.top, bottom: rect.bottom };
        document.body.removeChild(div);
        return result;
    }

    function placeBoxByRect(rect) {
        if (!rect) return;
        var x = (rect.left + window.scrollX);
        var y = (rect.bottom + window.scrollY + 6);
        box.style.left = Math.max(8, x) + 'px';
        box.style.top = Math.max(8, y) + 'px';
    }

    function renderItems(list, rect) {
        box.innerHTML = '';
        items = list || [];
        selectedIndex = -1;

        if (!items.length) {
            hideBox();
            return;
        }

        items.forEach(function (it) {
            var item = document.createElement('div');
            item.className = 'af-aam-suggest-item';
            item.textContent = it.username;
            item.addEventListener('mousedown', function (e) {
                e.preventDefault();
                applySuggestion(it);
            });
            box.appendChild(item);
        });

        placeBoxByRect(rect);
        box.style.display = 'block';
    }

    function abortActive() {
        if (activeController && typeof activeController.abort === 'function') {
            activeController.abort();
        }
        activeController = null;
    }

    function requestSuggest(prefix, rect) {
        if (!prefix || prefix.length < 2) {
            hideBox();
            return;
        }

        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }

        debounceTimer = setTimeout(function () {
            abortActive();
            lastQuery = prefix;
            var controller = new AbortController();
            activeController = controller;
            var url = mentionSuggestUrl + (mentionSuggestUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(prefix);
            fetch(url, { signal: controller.signal, credentials: 'same-origin' })
                .then(function (resp) { return resp.ok ? resp.json() : []; })
                .then(function (data) {
                    if (controller !== activeController) {
                        return;
                    }
                    activeController = null;
                    if (!Array.isArray(data)) {
                        hideBox();
                        return;
                    }
                    if (prefix !== lastQuery) {
                        return;
                    }
                    renderItems(data, rect);
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') return;
                    hideBox();
                });
        }, 200);
    }

    function jumpToMessageEditor() {
        function findTextarea() {
            return (
                document.querySelector('textarea[name="message"]') ||
                document.getElementById('message') ||
                document.getElementById('message_new') ||
                document.querySelector('textarea[id^="message"]') ||
                null
            );
        }

        var ta = findTextarea();
        var sc = null;

        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.sceditor) {
            try {
                if (ta) {
                    var $ta = window.jQuery(ta);
                    sc = $ta.sceditor('instance') || null;
                }
                if (!sc) {
                    var $any = window.jQuery('textarea').filter(function () {
                        try { return !!window.jQuery(this).sceditor('instance'); } catch (e) { return false; }
                    }).first();
                    if ($any.length) {
                        ta = $any.get(0);
                        sc = $any.sceditor('instance') || null;
                    }
                }
            } catch (e2) {
                sc = null;
            }
        }

        if (sc) {
            var container = null;
            try {
                if (typeof sc.getContainer === 'function') container = sc.getContainer();
            } catch (e3) {}
            if (!container && ta) {
                container = ta.closest ? ta.closest('.sceditor-container') : null;
            }
            if (container && container.scrollIntoView) {
                try { container.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e4) { container.scrollIntoView(true); }
            } else if (ta && ta.scrollIntoView) {
                try { ta.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e5) { ta.scrollIntoView(true); }
            }
            try { if (typeof sc.focus === 'function') sc.focus(); else if (typeof sc.getBody === 'function') sc.getBody().focus(); } catch (e6) {}
            return true;
        }

        if (ta) {
            try { ta.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e7) { ta.scrollIntoView(true); }
            try { ta.focus(); } catch (e8) {}
            return true;
        }
        return false;
    }

    function insertMention(username, uid) {
        var u = String(username || '')
            .replace(/[\r\n]+/g, ' ')
            .replace(/"/g, '')
            .trim();
        if (!u) return;

        jumpToMessageEditor();
        var mentionUid = parseInt(uid || '0', 10) || 0;
        var text = (mentionUid > 0)
            ? '[mention=' + mentionUid + ']' + u + '[/mention] '
            : '[mention]' + u + '[/mention] ';

        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.sceditor) {
            try {
                var ta = document.querySelector('textarea[name="message"]') ||
                        document.getElementById('message') ||
                        document.getElementById('message_new') ||
                        document.querySelector('textarea[id^="message"]');
                if (ta) {
                    var editor = window.jQuery(ta).sceditor('instance');
                    if (editor) {
                        if (typeof editor.focus === 'function') editor.focus();
                        if (typeof editor.insertText === 'function') {
                            editor.insertText(text);
                            return;
                        }
                    }
                }
            } catch (e9) {}
        }

        var textarea2 = document.querySelector('textarea[name="message"]') || document.getElementById('message');
        if (textarea2 && typeof textarea2.value === 'string') {
            try {
                var start = textarea2.selectionStart || textarea2.value.length;
                var end = textarea2.selectionEnd || textarea2.value.length;
                var before = textarea2.value.substring(0, start);
                var after = textarea2.value.substring(end);
                textarea2.value = before + text + after;
                var newPos = before.length + text.length;
                textarea2.selectionStart = textarea2.selectionEnd = newPos;
                textarea2.focus();
                return;
            } catch (e10) {}
        }
        document.execCommand('insertText', false, text);
    }

    function applySuggestion(item) {
        var username = item && item.username ? item.username : item;
        var uid = item && item.uid ? parseInt(item.uid, 10) || 0 : 0;
        var u = String(username || '').replace(/"/g, '').trim();
        if (!u) return;

        var mentionText = (uid > 0)
            ? '[mention=' + uid + ']' + u + '[/mention] '
            : '[mention]' + u + '[/mention] ';

        if (state.mode === 'textarea' && textarea) {
            var value = textarea.value;
            var caret = textarea.selectionStart || state.lastCaret || value.length;
            var atPos = state.lastAtIndex;
            if (atPos < 0 || atPos >= caret) {
                insertMention(u, uid);
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

        if (state.mode === 'sceditor' && sceditor && scBody) {
            try {
                sceditor.focus();
                var sel = window.getSelection ? window.getSelection() : null;
                if (!sel || sel.rangeCount === 0) {
                    sceditor.insertText(mentionText);
                    hideBox();
                    return;
                }
                var r = sel.getRangeAt(0);
                var endNode = r.endContainer;
                var endOffset = r.endOffset;
                if (state.lastTextNode && state.lastTextNode.nodeType === 3) {
                    endNode = state.lastTextNode;
                    endOffset = state.lastCaret;
                }
                if (!endNode || endNode.nodeType !== 3) {
                    sceditor.insertText(mentionText);
                    hideBox();
                    return;
                }
                var startOffset = state.lastTextNodeAtOffset;
                if (startOffset < 0 || startOffset > endOffset) {
                    sceditor.insertText(mentionText);
                    hideBox();
                    return;
                }
                var rr = document.createRange();
                rr.setStart(endNode, startOffset);
                rr.setEnd(endNode, endOffset);
                rr.deleteContents();
                var tn = document.createTextNode(mentionText);
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

        insertMention(u, uid);
        hideBox();
    }

    function parseTextareaContext() {
        var pos = textarea.selectionStart || 0;
        var value = textarea.value || '';
        var slice = value.substring(0, pos);
        var atPos = slice.lastIndexOf('@');
        if (atPos === -1) return null;
        var prefix = slice.substring(atPos + 1);
        if (!prefix || /\s/.test(prefix)) return null;
        if (prefix.length < 2) return null;
        state.mode = 'textarea';
        state.prefix = prefix;
        state.lastAtIndex = atPos;
        state.lastCaret = pos;
        var rect = getTextareaCaretRect(textarea, pos);
        return { prefix: prefix, rect: rect };
    }

    function parseSceditorContext() {
        var sel = window.getSelection ? window.getSelection() : null;
        if (!sel || sel.rangeCount === 0) return null;
        var r = sel.getRangeAt(0);
        if (!scBody || !scBody.contains(r.endContainer)) return null;
        var endNode = r.endContainer;
        var endOffset = r.endOffset;
        if (endNode && endNode.nodeType === 1) {
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
        if (!prefix || prefix.length < 2) return null;
        state.mode = 'sceditor';
        state.prefix = prefix;
        state.lastAtIndex = i;
        state.lastTextNode = endNode;
        state.lastTextNodeAtOffset = i;
        state.lastCaret = endOffset;
        var rect = null;
        try { rect = r.getBoundingClientRect && r.getBoundingClientRect(); } catch (e) { rect = null; }
        if ((!rect || (!rect.left && !rect.top)) && scBody) {
            try { rect = scBody.getBoundingClientRect(); } catch (e2) {}
        }
        return { prefix: prefix, rect: rect };
    }

    function onKeyUp() {
        var ctx = null;
        if (scBody) {
            ctx = parseSceditorContext();
        }
        if (!ctx && textarea) {
            ctx = parseTextareaContext();
        }
        if (!ctx) {
            hideBox();
            return;
        }
        requestSuggest(ctx.prefix, ctx.rect);
    }

    function onKeyDown(e) {
        if (box.style.display !== 'block') return;
        var handled = false;
        if (e.key === 'ArrowDown') {
            selectedIndex = Math.min(items.length - 1, selectedIndex + 1);
            highlight(selectedIndex);
            handled = true;
        } else if (e.key === 'ArrowUp') {
            selectedIndex = selectedIndex <= 0 ? items.length - 1 : selectedIndex - 1;
            highlight(selectedIndex);
            handled = true;
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            handled = true;
            if (selectedIndex >= 0 && items[selectedIndex]) {
                applySuggestion(items[selectedIndex]);
            } else {
                hideBox();
            }
        } else if (e.key === 'Escape') {
            hideBox();
            handled = true;
        }
        if (handled) {
            e.preventDefault();
        }
    }

    function bindTextarea() {
        if (!textarea) return;
        textarea.addEventListener('keyup', onKeyUp);
        textarea.addEventListener('keydown', onKeyDown);
        textarea.addEventListener('blur', function () { setTimeout(hideBox, 150); });
    }

    function bindSceditorEvents() {
        if (!sceditor) return;
        try {
            scBody = sceditor.getBody();
        } catch (e) { scBody = null; }
        if (!scBody) return;
        scBody.addEventListener('keyup', onKeyUp);
        scBody.addEventListener('keydown', onKeyDown);
        scBody.addEventListener('blur', function () { setTimeout(hideBox, 150); });
    }

    function refreshSceditor() {
        if (!(window.jQuery && window.jQuery.fn && window.jQuery.fn.sceditor)) {
            return;
        }
        if (textarea && !sceditor) {
            try { sceditor = window.jQuery(textarea).sceditor('instance') || null; } catch (e) { sceditor = null; }
        }
        if (sceditor && !scBody) {
            bindSceditorEvents();
        }
    }

    function initMentions() {
        createBox();
        textarea = document.querySelector('textarea[name="message"]') || document.getElementById('message') || document.getElementById('message_new') || document.querySelector('textarea[id^="message"]');
        bindTextarea();
        refreshSceditor();
        if (!scBody) {
            scPoll = setInterval(function () {
                if (scPollTries++ > 50) {
                    clearInterval(scPoll);
                    scPoll = null;
                    return;
                }
                refreshSceditor();
                if (scBody && scPoll) {
                    clearInterval(scPoll);
                    scPoll = null;
                }
            }, 500);
        }

        document.addEventListener('mousedown', function (e) {
            if (box.style.display === 'block' && e.target !== box && !box.contains(e.target)) {
                hideBox();
            }
        });
        window.addEventListener('scroll', function () {
            if (box.style.display === 'block') hideBox();
        }, true);

        function findMentionElement(node) {
            var cur = node;
            while (cur && cur !== document) {
                if (cur.nodeType === 1) {
                    if (cur.classList && cur.classList.contains('af-aam-mention-button')) {
                        return cur;
                    }
                    if ((cur.classList && (cur.classList.contains('mention_user') || cur.classList.contains('af-aam-mention-user')))
                        || (cur.getAttribute && cur.getAttribute('data-mention') === '1')) {
                        return cur;
                    }
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
            insertMention(username, uid);
        });
    }

    ready(initMentions);
})();

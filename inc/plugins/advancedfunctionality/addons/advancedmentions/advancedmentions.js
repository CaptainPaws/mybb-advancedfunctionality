(function () {
    'use strict';

    // ========= вспомогалка: пытаемся вычислить bburl по src скриптов =========
    function detectBaseUrl() {
        var scripts = document.getElementsByTagName('script');
        for (var i = 0; i < scripts.length; i++) {
            var src = scripts[i].getAttribute('src') || '';
            // Ищем первый скрипт из /jscripts/ форума
            var idx = src.indexOf('/jscripts/');
            if (idx !== -1) {
                // Обрезаем до корня форума: https://site.tld[/forum]
                return src.slice(0, idx);
            }
        }
        // Фолбэк: домен без пути — в твоём случае форум в корне, этого достаточно.
        var loc = window.location;
        var base = loc.protocol + '//' + loc.host;
        return base;
    }

    // ========= конфиг из PHP (если есть) =========
    var cfg = window.afAdvancedMentionsConfig || {};
    if (typeof cfg.minChars !== 'number') {
        cfg.minChars = 2;
    }
    if (typeof cfg.clickInsert !== 'boolean') {
        cfg.clickInsert = false;
    }
    if (typeof cfg.suggestUrl !== 'string') {
        cfg.suggestUrl = '';
    }

    // Если PHP-конфиг не подставился — строим URL сами
    if (!cfg.suggestUrl) {
        var base = detectBaseUrl();
        if (base) {
            cfg.suggestUrl = base + '/misc.php?action=af_mention_suggest';
        }
    }

    var activeTextarea = null;
    var lastQuery = '';
    var suggestBox = null;
    var suggestItems = [];
    var suggestVisible = false;
    var fetchController = null;

    // список { textarea, editor, lastVal }
    var editorEntries = [];

    // ========= helpers =========

    function findTextareas() {
        return Array.prototype.slice.call(document.querySelectorAll('textarea[name="message"]'));
    }

    function setActiveTextarea(el) {
        activeTextarea = el;
    }

    function getEditorForTextarea(textarea) {
        if (!window.jQuery || !window.jQuery.fn || typeof window.jQuery.fn.sceditor !== 'function') {
            return null;
        }

        try {
            var $ = window.jQuery;
            var $ta = $(textarea);
            var editor = $ta.sceditor('instance');
            if (editor && typeof editor.insertText === 'function' && typeof editor.val === 'function') {
                return editor;
            }
        } catch (e) {
            // глушим
        }
        return null;
    }

    // якорь для позиционирования подсказки:
    // в WYSIWYG — контейнер редактора, в простом режиме — textarea
    function getAnchorElement(textarea) {
        if (!textarea) return null;

        var editor = getEditorForTextarea(textarea);
        if (editor && typeof editor.getContentAreaContainer === 'function') {
            var area = editor.getContentAreaContainer();
            if (area && area.getBoundingClientRect) {
                return area;
            }
        }

        return textarea;
    }

    // ЕДИНЫЙ ФОРМАТ: всегда @"Имя Фамилия"
    function buildMentionText(name) {
        return '@"' + name + '" ';
    }

    function insertAtCursor(textarea, text) {
        if (!textarea) {
            return;
        }

        // 1) SCEditor
        var editor = getEditorForTextarea(textarea);
        if (editor) {
            editor.insertText(text);
            editor.focus();
            return;
        }

        // 2) обычная textarea
        textarea.focus();

        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        var value = textarea.value;

        if (typeof start === 'number' && typeof end === 'number') {
            textarea.value = value.slice(0, start) + text + value.slice(end);
            var newPos = start + text.length;
            textarea.selectionStart = textarea.selectionEnd = newPos;
        } else {
            textarea.value += text;
        }
    }

    // Простая вставка: используется для кликов по нику/кнопке "Упомянуть"
    function insertMention(name) {
        if (!activeTextarea) {
            var areas = findTextareas();
            if (areas.length > 0) {
                activeTextarea = areas[0];
            }
        }

        if (!activeTextarea) {
            return;
        }

        var mention = buildMentionText(name);
        insertAtCursor(activeTextarea, mention);
    }

    function isProfileLink(el) {
        if (!el || el.tagName !== 'A') {
            return false;
        }
        var href = el.getAttribute('href') || '';
        return href.indexOf('member.php') !== -1 &&
               href.indexOf('action=profile') !== -1 &&
               href.indexOf('uid=') !== -1;
    }

    // ========= клики по никам / кнопке =========

    function handleUsernameClick(e) {
        var target = e.target;

        // Кнопка "Упомянуть"
        var node = target;
        while (node && node !== document) {
            if (node.classList && node.classList.contains('af-mention-button')) {
                e.preventDefault();
                var name = (node.getAttribute('data-username') || '').trim();
                if (name) {
                    insertMention(name);
                }
                return;
            }
            node = node.parentNode;
        }

        // Клик по нику-профилю
        if (!cfg.clickInsert) {
            return;
        }

        node = target;
        while (node && node !== document) {
            if (isProfileLink(node)) {
                var text = (node.textContent || '').trim();
                if (!text.length) {
                    return;
                }
                e.preventDefault();
                insertMention(text);
                return;
            }
            node = node.parentNode;
        }
    }

    // ========= выпадашка подсказок =========

    function createSuggestBox() {
        if (suggestBox) {
            return;
        }

        suggestBox = document.createElement('div');
        suggestBox.className = 'af-mention-suggest';
        suggestBox.style.display = 'none';

        var list = document.createElement('ul');
        list.className = 'af-mention-suggest-list';
        suggestBox.appendChild(list);

        document.body.appendChild(suggestBox);
    }

    function clearSuggestBox() {
        if (!suggestBox) return;
        var list = suggestBox.querySelector('.af-mention-suggest-list');
        if (!list) return;
        while (list.firstChild) {
            list.removeChild(list.firstChild);
        }
        suggestItems = [];
    }

    function hideSuggestBox() {
        if (!suggestBox) return;
        suggestBox.style.display = 'none';
        suggestVisible = false;
        clearSuggestBox();
        lastQuery = '';
    }

    function positionSuggestBox() {
        if (!suggestBox || !activeTextarea) {
            return;
        }

        var anchor = getAnchorElement(activeTextarea);
        if (!anchor || !anchor.getBoundingClientRect) {
            return;
        }

        var rect = anchor.getBoundingClientRect();
        var scrollY = window.scrollY || window.pageYOffset || document.documentElement.scrollTop || 0;
        var scrollX = window.scrollX || window.pageXOffset || document.documentElement.scrollLeft || 0;

        suggestBox.style.position = 'absolute';
        suggestBox.style.left = (rect.left + scrollX + 4) + 'px';
        suggestBox.style.top = (rect.bottom + scrollY - 4) + 'px';
        suggestBox.style.minWidth = (rect.width * 0.5) + 'px';
    }

    function renderSuggest(results) {
        createSuggestBox();
        clearSuggestBox();

        if (!results || !results.length) {
            hideSuggestBox();
            return;
        }

        var list = suggestBox.querySelector('.af-mention-suggest-list');
        if (!list) {
            hideSuggestBox();
            return;
        }

        results.forEach(function (item) {
            var li = document.createElement('li');
            li.className = 'af-mention-suggest-item';
            li.setAttribute('data-uid', String(item.uid || 0));
            li.setAttribute('data-username', item.username);

            var label = item.formatted || item.display || item.username;
            li.innerHTML = label;

            li.addEventListener('mousedown', function (e) {
                e.preventDefault();
                var name = li.getAttribute('data-username') || '';
                if (name) {
                    applySuggestion(name);
                }
            });

            list.appendChild(li);
            suggestItems.push(li);
        });

        positionSuggestBox();
        suggestBox.style.display = 'block';
        suggestVisible = true;
    }

    function fetchSuggestions(query) {
        if (!cfg.suggestUrl) {
            // если даже после autodetect ничего не получилось — просто выходим
            return;
        }

        if (fetchController && typeof fetchController.abort === 'function') {
            fetchController.abort();
        }

        if (window.AbortController) {
            fetchController = new AbortController();
        } else {
            fetchController = null;
        }

        var url = cfg.suggestUrl + '&query=' + encodeURIComponent(query);

        var options = {};
        if (fetchController) {
            options.signal = fetchController.signal;
        }

        fetch(url, options)
            .then(function (resp) {
                return resp.json();
            })
            .then(function (data) {
                if (!data || !data.ok) {
                    hideSuggestBox();
                    return;
                }
                renderSuggest(data.results || []);
            })
            .catch(function () {
                // молча игнорим
            });
    }

    // ========= извлечение текущего @фрагмента =========
    // Возвращает:
    //   null  — нет активного упоминания (подсказки не нужны)
    //   ''    — есть @, но после него ещё ничего нет (подсказки скрыть)
    //   'ga'  — текст после @ (для запроса)
    function getQueryFromText(value, pos) {
        if (!value) return null;

        if (typeof pos !== 'number' || pos < 0 || pos > value.length) {
            pos = value.length;
        }

        if (pos <= 0) return null;

        var slice = value.slice(0, pos);
        var atPos = slice.lastIndexOf('@');
        if (atPos === -1) {
            return null;
        }

        if (atPos > 0) {
            var prev = slice.charAt(atPos - 1);
            // если перед @ буква/цифра — считаем, что это часть слова/емейла
            if (/\w/.test(prev)) {
                return null;
            }
        }

        // текст от @ (не включая) до каретки
        var afterAt = value.slice(atPos + 1, pos);

        // вообще ничего не набрано после @
        if (!afterAt.length) {
            return '';
        }

        // если есть хотя бы один не-пробел И при этом строка после @
        // заканчивается пробелом — считаем упоминание завершённым:
        // "@Game Master " → null (список убираем)
        if (/\S/.test(afterAt) && /\s$/.test(afterAt)) {
            return null;
        }

        // сам запрос — просто обрезаем пробелы по краям, но оставляем
        // возможный пробел внутри ника: "Game Ma"
        var query = afterAt.trim();

        if (!query.length) {
            // было что-то вроде "@   " — подсказки прячем
            return '';
        }

        return query;
    }

    // ========= замена текущего @фрагмента на выбранный ник =========
    // Возвращает { text, caret }
    function replaceCurrentMention(value, name, pos) {
        var mentionText = buildMentionText(name);

        if (!value) {
            return {
                text: mentionText,
                caret: mentionText.length
            };
        }

        if (typeof pos !== 'number' || pos < 0 || pos > value.length) {
            pos = value.length;
        }

        var slice = value.slice(0, pos);
        var atPos = slice.lastIndexOf('@');

        if (atPos === -1) {
            // Нет @ — просто вставляем в позицию каретки
            var beforeIns = value.slice(0, pos);
            var afterIns = value.slice(pos);
            var textIns = beforeIns + mentionText + afterIns;
            return {
                text: textIns,
                caret: beforeIns.length + mentionText.length
            };
        }

        if (atPos > 0) {
            var prev = slice.charAt(atPos - 1);
            if (/\w/.test(prev)) {
                // @ как часть слова — не трогаем, просто вставляем
                var beforeIns2 = value.slice(0, pos);
                var afterIns2 = value.slice(pos);
                var textIns2 = beforeIns2 + mentionText + afterIns2;
                return {
                    text: textIns2,
                    caret: beforeIns2.length + mentionText.length
                };
            }
        }

        // Заменяем всё от @ до каретки на полный ник
        var before = value.slice(0, atPos);
        var after = value.slice(pos);

        var newText = before + mentionText + after;
        var caretPos = before.length + mentionText.length;

        return {
            text: newText,
            caret: caretPos
        };
    }

    function applySuggestion(name) {
        if (!activeTextarea) {
            return;
        }

        lastQuery = '';

        // Сначала пробуем работать через SCEditor
        var editor = getEditorForTextarea(activeTextarea);
        if (editor) {
            var val = '';
            try {
                val = editor.val() || '';
            } catch (e) {
                val = '';
            }

            var resEditor = replaceCurrentMention(val, name, val.length);
            editor.val(resEditor.text);
            editor.focus();
            // SCEditor сам обычно ставит каретку в конец содержимого
            hideSuggestBox();
            return;
        }

        // Обычная textarea
        var ta = activeTextarea;
        var value = ta.value || '';
        var pos = (typeof ta.selectionStart === 'number') ? ta.selectionStart : value.length;

        var res = replaceCurrentMention(value, name, pos);
        ta.value = res.text;

        // ВАЖНО: сначала фокус, потом позиция каретки
        ta.focus();
        if (typeof ta.selectionStart === 'number' && typeof ta.selectionEnd === 'number') {
            ta.selectionStart = ta.selectionEnd = res.caret;
        }

        hideSuggestBox();
    }

    function processQuery(q) {
        if (q === null) {
            hideSuggestBox();
            lastQuery = '';
            return;
        }

        if (q === '') {
            hideSuggestBox();
            lastQuery = '';
            return;
        }

        if (q === lastQuery) {
            return;
        }

        lastQuery = q;

        if (q.length < cfg.minChars) {
            hideSuggestBox();
            return;
        }

        fetchSuggestions(q);
    }

    // ========= textarea (без редактора) =========

    function handleTextareaInput(e) {
        var textarea = e.target;
        if (!textarea || textarea.tagName !== 'TEXTAREA' || textarea.name !== 'message') {
            return;
        }

        setActiveTextarea(textarea);

        var pos = (typeof textarea.selectionStart === 'number')
            ? textarea.selectionStart
            : (textarea.value || '').length;

        var q = getQueryFromText(textarea.value || '', pos);
        processQuery(q);
    }

    function handleTextareaBlur(e) {
        var textarea = e.target;
        if (!textarea || textarea.tagName !== 'TEXTAREA' || textarea.name !== 'message') {
            return;
        }

        setTimeout(function () {
            if (!suggestVisible) return;
            if (!suggestBox) return;
            hideSuggestBox();
        }, 200);
    }

    function initDelegatedTextareaEvents() {
        document.addEventListener('focusin', function (e) {
            var t = e.target;
            if (t && t.tagName === 'TEXTAREA' && t.name === 'message') {
                setActiveTextarea(t);
            }
        });

        document.addEventListener('input', handleTextareaInput);
        document.addEventListener('blur', handleTextareaBlur, true);
    }

    // ========= интеграция с SCEditor через опрос =========

    function scanEditors() {
        if (!window.jQuery || !window.jQuery.fn || typeof window.jQuery.fn.sceditor !== 'function') {
            return;
        }
        var $ = window.jQuery;

        $('textarea[name="message"]').each(function () {
            var textarea = this;
            var editor = getEditorForTextarea(textarea);
            if (!editor) return;

            var exists = editorEntries.some(function (entry) {
                return entry.textarea === textarea;
            });
            if (exists) return;

            editorEntries.push({
                textarea: textarea,
                editor: editor,
                lastVal: editor.val() || ''
            });
        });
    }

    function pollEditors() {
        if (!editorEntries.length) {
            return;
        }

        editorEntries.forEach(function (entry) {
            var editor = entry.editor;
            var textarea = entry.textarea;

            var val = '';
            try {
                val = editor.val() || '';
            } catch (e) {
                return;
            }

            if (val === entry.lastVal) {
                return;
            }

            entry.lastVal = val;
            setActiveTextarea(textarea);

            var q = getQueryFromText(val, val.length);
            processQuery(q);
        });
    }

    // ========= init =========

    function init() {
        createSuggestBox();
        initDelegatedTextareaEvents();
        document.addEventListener('click', handleUsernameClick, false);

        // Периодически ищем новые редакторы и опрашиваем содержимое
        scanEditors();
        setInterval(scanEditors, 1000);
        setInterval(pollEditors, 300);
    }

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        init();
    } else {
        document.addEventListener('DOMContentLoaded', init);
    }
})();

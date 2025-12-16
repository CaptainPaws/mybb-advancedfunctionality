(function () {
  'use strict';

  // защита от многократного подключения
  if (window.afAamMentionsInitialized) return;
  window.afAamMentionsInitialized = true;

  function getCfg() {
    var c = window.afAamConfig && typeof window.afAamConfig === 'object' ? window.afAamConfig : {};
    var bburl = (c.bburl || '').replace(/\/+$/, '');
    return {
      bburl: bburl,
      mentionSuggestUrl: c.mentionSuggestUrl || (bburl ? (bburl + '/misc.php?action=af_aam_mention_suggest') : 'misc.php?action=af_aam_mention_suggest'),
      xmlhttpUrl: c.xmlhttpUrl || (bburl ? (bburl + '/xmlhttp.php') : 'xmlhttp.php')
    };
  }

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  // ---------- редактор / textarea ----------
  function getMessageTextareas() {
    var set = qsa('textarea#message');
    // quick reply иногда другой id, но name обычно message
    qsa('textarea[name="message"]').forEach(function (ta) {
      if (set.indexOf(ta) === -1) set.push(ta);
    });
    return set;
  }

  function pickActiveTextarea() {
    var all = getMessageTextareas();
    if (!all.length) return null;

    var focused = document.activeElement;
    if (focused && focused.tagName === 'TEXTAREA' && all.indexOf(focused) !== -1) {
      return focused;
    }

    // выберем первый видимый textarea
    for (var i = 0; i < all.length; i++) {
      var ta = all[i];
      if (ta && ta.offsetParent !== null) return ta;
    }

    return all[0];
  }

  function tryGetSceditorInstance(textarea) {
    try {
      if (!textarea) return null;
      if (!window.jQuery) return null;
      var $ = window.jQuery;
      if (typeof $(textarea).sceditor !== 'function') return null;
      var inst = $(textarea).sceditor('instance');
      return inst || null;
    } catch (e) {
      return null;
    }
  }

  function insertAtCursor(textarea, text) {
    if (!textarea) return;
    try {
      textarea.focus();

      var start = textarea.selectionStart;
      var end = textarea.selectionEnd;

      if (typeof start === 'number' && typeof end === 'number') {
        var before = textarea.value.slice(0, start);
        var after = textarea.value.slice(end);
        textarea.value = before + text + after;
        var pos = before.length + text.length;
        textarea.selectionStart = textarea.selectionEnd = pos;
      } else {
        // древний fallback
        textarea.value += text;
      }
    } catch (e) {}
  }

  function insertMention(uid, username) {
    username = String(username || '').trim();
    if (!username) return;

    var tag = uid && parseInt(uid, 10) > 0
      ? ('[mention=' + parseInt(uid, 10) + ']' + username + '[/mention] ')
      : ('[mention]' + username + '[/mention] ');

    var ta = pickActiveTextarea();
    var sc = tryGetSceditorInstance(ta);

    // SCEditor: вставка прямо в редактор
    if (sc && typeof sc.insertText === 'function') {
      try {
        sc.insertText(tag);
        return;
      } catch (e) {
        // упадёт — вставим в textarea
      }
    }

    insertAtCursor(ta, tag);
  }

  // ---------- нормализация упоминаний перед отправкой ----------
  function protectMentionBlocks(text) {
    var stash = [];
    var out = String(text || '');

    // защитим уже существующие [mention]...[/mention], чтобы не трогать повторно
    out = out.replace(/\[mention(?:=[0-9]+)?\][\s\S]*?\[\/mention\]/gi, function (m) {
      var key = '__AAM_MENTION_BLOCK_' + stash.length + '__';
      stash.push(m);
      return key;
    });

    return { text: out, stash: stash };
  }

  function restoreMentionBlocks(text, stash) {
    var out = String(text || '');
    if (!stash || !stash.length) return out;

    for (var i = 0; i < stash.length; i++) {
      var key = '__AAM_MENTION_BLOCK_' + i + '__';
      out = out.split(key).join(stash[i]);
    }
    return out;
  }

  function normalizeMentionsInBbcode(bbcode) {
    var orig = String(bbcode || '');
    if (!orig) return orig;

    // быстрый выход: нет @ вообще — не трогаем
    if (orig.indexOf('@') === -1 && orig.toLowerCase().indexOf('[mention') === -1) return orig;

    // защитим уже размеченные mention-теги
    var p = protectMentionBlocks(orig);
    var s = p.text;

    // НЕ превращаем email в mention: требуем "границу" перед @
    // 1) @"Имя Фамилия" -> [mention]Имя Фамилия[/mention]
    // 2) @username -> [mention]username[/mention]
    // исключаем @all (оставляем как есть, у тебя сервер сам решит права)
    s = s.replace(/(^|[\s\(\[\{>])@\"([^"]+)\"/gu, function (_, pre, name) {
      name = String(name || '').trim();
      if (!name) return _;
      if (name.toLowerCase() === 'all') return pre + '@all';
      return pre + '[mention]' + name + '[/mention]';
    });

    s = s.replace(/(^|[\s\(\[\{>])@([\p{L}\p{N}_\.]{2,})/gu, function (_, pre, name) {
      name = String(name || '').trim();
      if (!name) return _;
      if (name.toLowerCase() === 'all') return pre + '@all';
      // защита от типа "foo@bar" — там перед @ будет буква, а не граница. сюда не попадём.
      return pre + '[mention]' + name + '[/mention]';
    });

    // вернём защищённые блоки
    s = restoreMentionBlocks(s, p.stash);

    return s;
  }

  function normalizeOnSubmit(form) {
    try {
      if (!form) return;

      var ta = form.querySelector('textarea[name="message"], textarea#message');
      if (!ta) return;

      var sc = tryGetSceditorInstance(ta);

      // Синхронизируем SCEditor -> textarea, чтобы AJAX-отправка брала актуальный текст
      if (sc && typeof sc.updateOriginal === 'function') {
        try { sc.updateOriginal(); } catch (e3) {}
      }

      // берём BBCode из sceditor, если он есть
      var current = '';
      if (sc && typeof sc.val === 'function') {
        try { current = sc.val(); } catch (e) { current = ta.value || ''; }
      } else {
        current = ta.value || '';
      }

      var normalized = normalizeMentionsInBbcode(current);

      if (normalized !== current) {
        if (sc && typeof sc.val === 'function') {
          try { sc.val(normalized); } catch (e2) {}
        }
        ta.value = normalized;
      }

      // и обратно в textarea (sceditor)
      if (sc && typeof sc.updateOriginal === 'function') {
        try { sc.updateOriginal(); } catch (e4) {}
      }
    } catch (e) {}
  }

  function bindSubmitNormalization() {
    // ВАЖНО: никаких preventDefault. Мы просто подменяем текст и уходим.
    var forms = qsa('form');
    forms.forEach(function (f) {
      try {
        if (f.getAttribute('data-aam-mentions-bound') === '1') return;

        // форма должна содержать message textarea
        var hasMsg = f.querySelector('textarea[name="message"], textarea#message');
        if (!hasMsg) return;

        f.setAttribute('data-aam-mentions-bound', '1');

        f.addEventListener('submit', function () {
          normalizeOnSubmit(f);
        }, true);
      } catch (e) {}
    });
  }

  // ---------- кнопка @ в постбите ----------
  function bindMentionButtons() {
    document.addEventListener('click', function (ev) {
      var t = ev.target;
      if (!t) return;

      // делегирование: <a class="af-aam-mention-button" ...>@</a>
      var btn = (t.closest ? t.closest('.af-aam-mention-button') : null);
      var link = btn || (t.closest ? t.closest('[data-mention="1"][data-mention-username]') : null);
      if (!link) return;

      // НЕ блокируем другие клики случайно
      ev.preventDefault();

      var username = link.getAttribute('data-username') || link.getAttribute('data-mention-username') || '';
      var uid = link.getAttribute('data-uid') || link.getAttribute('data-mention-uid') || '0';

      insertMention(uid, username);
    }, false);
  }

  // ---------- подсказки по @ (только textarea, безопасно) ----------
  var sugg = {
    box: null,
    items: [],
    active: -1,
    lastQuery: '',
    timer: null,
    ta: null,
    range: null
  };

  function ensureSuggestBox() {
    if (sugg.box) return sugg.box;
    var div = document.createElement('div');
    div.className = 'af-aam-mention-suggest';
    div.style.position = 'absolute';
    div.style.zIndex = 99999;
    div.style.display = 'none';
    document.body.appendChild(div);
    sugg.box = div;
    return div;
  }

  function hideSuggest() {
    if (!sugg.box) return;
    sugg.box.style.display = 'none';
    sugg.items = [];
    sugg.active = -1;
    sugg.range = null;
  }

  function renderSuggest(items) {
    var box = ensureSuggestBox();
    box.innerHTML = '';
    sugg.items = Array.isArray(items) ? items : [];
    sugg.active = -1;

    if (!sugg.items.length) {
      hideSuggest();
      return;
    }

    sugg.items.forEach(function (it, idx) {
      var row = document.createElement('div');
      row.className = 'af-aam-mention-suggest-item';
      row.setAttribute('data-idx', String(idx));
      row.textContent = it.username;

      row.addEventListener('mousedown', function (e) {
        // mousedown чтобы textarea не теряла фокус до вставки
        e.preventDefault();
        chooseSuggest(idx);
      });

      box.appendChild(row);
    });

    box.style.display = 'block';
  }

  function positionSuggestNearTextarea(textarea) {
    var box = ensureSuggestBox();
    var r = textarea.getBoundingClientRect();
    // простая позиция: под textarea слева (без плясок с кареткой)
    box.style.left = (window.scrollX + r.left + 8) + 'px';
    box.style.top = (window.scrollY + r.top + 8) + 'px';
    box.style.minWidth = Math.max(220, Math.floor(r.width * 0.35)) + 'px';
  }

  function parseAtToken(text, caretPos) {
    // ищем последнее "граница + @" перед курсором
    var left = text.slice(0, caretPos);

    // найдём позицию @, которая имеет границу слева
    // граница: начало или пробел/скобка/перевод строки
    var m = left.match(/(^|[\s\(\[\{>])@([^\s\]\)\}<>"]*)$/u);
    if (!m) return null;

    var token = m[2] || '';
    // не триггерим на пустое "@"
    if (token.length < 1) return null;
    // не триггерим @all
    if (token.toLowerCase() === 'all') return null;

    var atIndex = left.length - token.length - 1;
    return { atIndex: atIndex, token: token };
  }

  function xhrJson(url, cb) {
    try {
      var x = new XMLHttpRequest();
      x.open('GET', url, true);
      x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      x.onreadystatechange = function () {
        if (x.readyState !== 4) return;
        var data = null;
        try { data = JSON.parse(x.responseText); } catch (e) {}
        cb(data);
      };
      x.send(null);
    } catch (e) {
      cb(null);
    }
  }

  function requestSuggest(query) {
    var cfg = getCfg();
    var url = cfg.mentionSuggestUrl;
    if (!url) return;

    // q=
    if (url.indexOf('?') === -1) url += '?';
    if (url[url.length - 1] !== '&' && url[url.length - 1] !== '?') url += '&';
    url += 'q=' + encodeURIComponent(query) + '&limit=10&_ts=' + Date.now();

    xhrJson(url, function (resp) {
      if (!resp || !resp.items || !Array.isArray(resp.items)) {
        renderSuggest([]);
        return;
      }
      renderSuggest(resp.items);
    });
  }

  function chooseSuggest(idx) {
    try {
      idx = parseInt(idx, 10);
      if (!sugg.ta || !sugg.range) return;
      if (!sugg.items || !sugg.items.length) return;
      if (idx < 0 || idx >= sugg.items.length) return;

      var it = sugg.items[idx];
      if (!it || !it.username) return;

      var ta = sugg.ta;
      var value = ta.value || '';
      var start = sugg.range.atIndex;
      var caret = ta.selectionStart;

      // заменяем "@token" на mention-тег
      var before = value.slice(0, start);
      var after = value.slice(caret);

      var uid = it.uid ? parseInt(it.uid, 10) : 0;
      var tag = (uid > 0)
        ? ('[mention=' + uid + ']' + it.username + '[/mention] ')
        : ('[mention]' + it.username + '[/mention] ');

      ta.value = before + tag + after;

      var pos = (before + tag).length;
      ta.focus();
      ta.selectionStart = ta.selectionEnd = pos;

      hideSuggest();
    } catch (e) {
      hideSuggest();
    }
  }

  function bindTextareaSuggest() {
    var textareas = getMessageTextareas();
    if (!textareas.length) return;

    textareas.forEach(function (ta) {
      // если SCEditor — подсказки тут могут быть неуместны (iframe),
      // но мы НЕ ломаем: просто работаем по textarea, если в ней реально печатают
      ta.addEventListener('keyup', function () {
        try {
          var caret = ta.selectionStart;
          if (typeof caret !== 'number') { hideSuggest(); return; }

          var info = parseAtToken(ta.value || '', caret);
          if (!info) { hideSuggest(); return; }

          sugg.ta = ta;
          sugg.range = info;
          positionSuggestNearTextarea(ta);

          var q = info.token.trim();
          if (!q) { hideSuggest(); return; }

          // debounce
          clearTimeout(sugg.timer);
          sugg.timer = setTimeout(function () {
            requestSuggest(q);
          }, 160);
        } catch (e) {
          hideSuggest();
        }
      });

      ta.addEventListener('keydown', function (ev) {
        if (!sugg.box || sugg.box.style.display === 'none') return;

        // Enter -> выбрать первый
        if (ev.key === 'Enter') {
          ev.preventDefault();
          chooseSuggest(0);
        }
        // Escape -> закрыть
        if (ev.key === 'Escape') {
          ev.preventDefault();
          hideSuggest();
        }
      });
    });

    document.addEventListener('click', function (ev) {
      if (!sugg.box) return;
      if (ev.target && (ev.target === sugg.box || (sugg.box.contains && sugg.box.contains(ev.target)))) return;
      hideSuggest();
    }, false);
  }

  // ---------- init ----------
  function init() {
    // 1) submit normalization (самое важное: НЕ ломаем отправку)
    bindSubmitNormalization();

    // 2) postbit @ button
    bindMentionButtons();

    // 3) textarea suggest (не критично, но удобно)
    bindTextareaSuggest();
  }

  // DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();

(function () {
  'use strict';

  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  // Чтобы не переинициализировать файл дважды
  if (window.afAqrFontFamilyInitialized) return;
  window.afAqrFontFamilyInitialized = true;

  var ID = 'fontfamily';
  var CMD = 'af_fontfamily';

  function asText(x) { return String(x == null ? '' : x); }

  function safeCssString(s) {
    // простая зачистка для CSS-строк (без кавычек и управляющих)
    s = asText(s).replace(/[\u0000-\u001f\u007f]/g, '');
    return s.replace(/["'\\]/g, '');
  }

  function safeBbValue(s) {
    // чтобы не ломать [font=...]
    s = asText(s);
    s = s.replace(/[\[\]]/g, '');
    s = s.replace(/[\r\n\t]/g, ' ');
    return s.trim();
  }

  function getPayload() {
    return window.afAqrPayload && typeof window.afAqrPayload === 'object'
      ? window.afAqrPayload
      : {};
  }

  function getCfg() {
    var p = getPayload();
    return p.cfg && typeof p.cfg === 'object' ? p.cfg : {};
  }

  function getAssetsBaseUrl() {
    // bburl + /inc/plugins/.../assets/
    var cfg = getCfg();
    var bburl = asText(cfg.bburl).replace(/\/+$/, '');
    if (!bburl) return '';
    return bburl + '/inc/plugins/advancedfunctionality/addons/advancedquickreply/assets/';
  }

  function getFamilies() {
    var cfg = getCfg();
    var list = cfg.fontFamilies;
    if (Array.isArray(list) && list.length) return list;

    // фоллбэк
    return [
      { id: 'arial', name: 'Arial', system: 1 },
      { id: 'helvetica', name: 'Helvetica', system: 1 },
      { id: 'verdana', name: 'Verdana', system: 1 },
      { id: 'tahoma', name: 'Tahoma', system: 1 },
      { id: 'trebuchet_ms', name: 'Trebuchet MS', system: 1 },
      { id: 'georgia', name: 'Georgia', system: 1 },
      { id: 'times_new_roman', name: 'Times New Roman', system: 1 },
      { id: 'garamond', name: 'Garamond', system: 1 },
      { id: 'courier_new', name: 'Courier New', system: 1 }
    ];
  }

  function buildFontFaceCss(families) {
    var base = getAssetsBaseUrl();
    if (!base) return '';

    var css = '';

    families.forEach(function (f) {
      if (!f || !f.name || !f.files || typeof f.files !== 'object') return;

      var name = safeCssString(f.name);
      if (!name) return;

      var src = [];
      function add(ext, fmt) {
        var file = f.files[ext];
        if (!file) return;
        file = asText(file).trim();
        if (!file) return;

        var url = base + 'fonts/' + encodeURIComponent(file).replace(/%2F/gi, '/');
        src.push('url("' + url.replace(/"/g, '\\"') + '") format("' + fmt + '")');
      }

      add('woff2', 'woff2');
      add('woff', 'woff');
      add('ttf', 'truetype');
      add('otf', 'opentype');

      if (!src.length) return;

      css += '\n@font-face{'
        + 'font-family:"' + name + '";'
        + 'src:' + src.join(',') + ';'
        + 'font-style:normal;'
        + 'font-weight:400;'
        + 'font-display:swap;'
        + '}\n';
    });

    return css;
  }

  function ensureFontFacesInjected() {
    var id = 'af_aqr_fontfaces';
    if (document.getElementById(id)) return;

    var css = buildFontFaceCss(getFamilies());
    if (!css) return;

    var st = document.createElement('style');
    st.id = id;
    st.type = 'text/css';
    st.appendChild(document.createTextNode(css));
    document.head.appendChild(st);
  }

  function getTextareaFromCtx(ctx) {
    if (ctx && ctx.textarea && ctx.textarea.nodeType === 1) return ctx.textarea;
    if (ctx && ctx.ta && ctx.ta.nodeType === 1) return ctx.ta;

    var ae = document.activeElement;
    if (ae && ae.tagName === 'TEXTAREA') return ae;

    return document.querySelector('textarea#message') ||
      document.querySelector('textarea[name="message"]') ||
      null;
  }

  function getSceditorInstanceFromCtx(ctx) {
    if (ctx && typeof ctx.insertText === 'function') return ctx;
    if (ctx && typeof ctx.createDropDown === 'function') return ctx;
    if (ctx && ctx.sceditor && typeof ctx.sceditor.insertText === 'function') return ctx.sceditor;
    if (ctx && ctx.inst && typeof ctx.inst.insertText === 'function') return ctx.inst;
    if (ctx && ctx.instance && typeof ctx.instance.insertText === 'function') return ctx.instance;

    try {
      if (window.jQuery) {
        var $ = window.jQuery;
        var $ta = $('textarea#message, textarea[name="message"]').first();
        if ($ta.length) {
          var inst = $ta.sceditor && $ta.sceditor('instance');
          if (inst && typeof inst.insertText === 'function') return inst;
        }
      }
    } catch (e) {}

    return null;
  }

  function insertWrap(open, close, ctx) {
    var inst = getSceditorInstanceFromCtx(ctx);
    if (inst && typeof inst.insertText === 'function') {
      inst.insertText(open, close);
      if (typeof inst.focus === 'function') inst.focus();
      return true;
    }

    var ta = getTextareaFromCtx(ctx);
    if (!ta) return false;

    try {
      var start = ta.selectionStart || 0;
      var end = ta.selectionEnd || 0;
      var val = String(ta.value || '');
      var before = val.slice(0, start);
      var sel = val.slice(start, end);
      var after = val.slice(end);

      ta.value = before + open + sel + close + after;

      var caret = (sel.length
        ? (before.length + open.length + sel.length + close.length)
        : (before.length + open.length)
      );

      ta.focus();
      ta.setSelectionRange(caret, caret);
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      return true;
    } catch (e) {
      return false;
    }
  }

  function applyFont(editor, familyName) {
    familyName = safeBbValue(familyName);
    if (!familyName) return;

    var open = '[font=' + familyName + ']';
    var close = '[/font]';

    try {
      if (editor && typeof editor.insertText === 'function') {
        editor.insertText(open, close);
        if (typeof editor.focus === 'function') editor.focus();
        return;
      }
    } catch (e0) {}

    insertWrap(open, close, { sceditor: editor });
  }

  function makeDropdown(editor, caller) {
    ensureFontFacesInjected();

    var families = getFamilies().slice();

    // разделим: кастомные с файлами vs системные
    var custom = [];
    var system = [];

    families.forEach(function (f) {
      if (!f || !f.name) return;
      if (f.files && typeof f.files === 'object' && (f.files.woff2 || f.files.woff || f.files.ttf || f.files.otf)) {
        custom.push(f);
      } else {
        system.push(f);
      }
    });

    function sortByName(a, b) {
      return asText(a.name).toLowerCase().localeCompare(asText(b.name).toLowerCase());
    }
    custom.sort(sortByName);
    system.sort(sortByName);

    var wrap = document.createElement('div');
    wrap.className = 'af-ff-dd';

    // чтобы список не раздувал дропдаун на полэкрана
    wrap.style.maxHeight = '260px';
    wrap.style.overflow = 'hidden';

    var search = document.createElement('input');
    search.className = 'af-ff-search';
    search.type = 'text';
    search.placeholder = 'Поиск шрифта...';
    wrap.appendChild(search);

    var list = document.createElement('div');
    list.className = 'af-ff-list';
    list.style.maxHeight = '220px';
    list.style.overflowY = 'auto';
    list.style.overflowX = 'hidden';
    wrap.appendChild(list);

    function addGroupTitle(text) {
      var d = document.createElement('div');
      d.className = 'af-ff-group';
      d.textContent = text;
      list.appendChild(d);
    }

    function addItem(f) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'af-ff-item';

      var name = asText(f.name).trim();

      var nm = document.createElement('div');
      nm.className = 'af-ff-name';
      nm.textContent = name;

      var sm = document.createElement('div');
      sm.className = 'af-ff-sample';
      sm.textContent = 'The quick brown fox — 1234567890';

      btn.style.fontFamily = '"' + safeCssString(name) + '", sans-serif';
      btn.dataset.afName = name.toLowerCase();

      btn.appendChild(nm);
      btn.appendChild(sm);

      btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        applyFont(editor, name);
        try { editor.closeDropDown(true); } catch (e0) {}
      });

      list.appendChild(btn);
    }

    var hasAny = false;

    // ✅ ВАЖНО: СНАЧАЛА системные, ПОТОМ загруженные
    if (system.length) {
      addGroupTitle('Системные');
      system.forEach(function (f) { hasAny = true; addItem(f); });
    }

    if (custom.length) {
      addGroupTitle('Загруженные');
      custom.forEach(function (f) { hasAny = true; addItem(f); });
    }

    if (!hasAny) {
      var empty = document.createElement('div');
      empty.className = 'af-ff-empty';
      empty.textContent = 'Шрифтов нет. Загрузись в ACP 🙂';
      list.appendChild(empty);
    }

    function filterNow() {
      var q = asText(search.value).trim().toLowerCase();
      var items = list.querySelectorAll('.af-ff-item');
      var any = false;

      for (var i = 0; i < items.length; i++) {
        var el = items[i];
        var ok = !q || (el.dataset.afName && el.dataset.afName.indexOf(q) !== -1);
        el.style.display = ok ? '' : 'none';
        if (ok) any = true;
      }

      var emptyMsg = list.querySelector('.af-ff-empty');
      if (!any) {
        if (!emptyMsg) {
          emptyMsg = document.createElement('div');
          emptyMsg.className = 'af-ff-empty';
          emptyMsg.textContent = 'Ничего не найдено.';
          list.appendChild(emptyMsg);
        } else {
          emptyMsg.textContent = 'Ничего не найдено.';
        }
      } else if (emptyMsg && emptyMsg.textContent === 'Ничего не найдено.') {
        emptyMsg.parentNode.removeChild(emptyMsg);
      }
    }

    search.addEventListener('input', filterNow);

    // автофокус
    setTimeout(function () {
      try { search.focus(); } catch (e0) {}
    }, 1);

    // ВАЖНО: SCEditor ждёт DOM-элемент, а не jQuery-объект
    try {
      editor.createDropDown(caller, 'sceditor-font-picker', wrap);
    } catch (e1) {}
  }

  function openSceditorDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;

    try { editor.closeDropDown(true); } catch (e0) {}

    makeDropdown(editor, caller);
    return true;
  }

  function patchSceditorFontCommand() {
    if (!window.jQuery) return false;
    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.command) return false;

    // Перехватываем стандартный 'font' (на случай стандартной кнопки)
    $.sceditor.command.set('font', {
      _dropDown: function (editor, caller) {
        makeDropdown(editor, caller);
      },
      exec: function (caller) {
        try { $.sceditor.command.get('font')._dropDown(this, caller); } catch (e0) {}
      },
      txtExec: function (caller) {
        try { $.sceditor.command.get('font')._dropDown(this, caller); } catch (e1) {}
      },
      tooltip: 'Шрифт (семейства)'
    });

    return true;
  }

  function registerSceditorCmdAlias() {
    if (!window.jQuery) return false;
    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.command) return false;

    // Алиас для AQR-кнопки (data-cmd="af_fontfamily")
    $.sceditor.command.set(CMD, {
      exec: function (caller) {
        if (!openSceditorDropdown(this, caller)) {
          insertWrap('[font=Arial]', '[/font]', { sceditor: this });
        }
      },
      txtExec: function (caller) {
        if (!openSceditorDropdown(this, caller)) {
          insertWrap('[font=Arial]', '[/font]', { sceditor: this });
        }
      },
      tooltip: 'Шрифт (семейства)'
    });

    return true;
  }

  function waitAnd(fn, maxTries) {
    var tries = 0;
    (function tick() {
      tries++;
      if (fn()) return;
      if (tries > (maxTries || 150)) return;
      setTimeout(tick, 100);
    })();
  }

  // старт патчей
  waitAnd(patchSceditorFontCommand, 150);
  waitAnd(registerSceditorCmdAlias, 150);
  ensureFontFacesInjected();

  // ====== AQR handler (как в fontsize.js) ======
  function aqrOpen(ctx, ev) {
    var editor = getSceditorInstanceFromCtx(ctx);
    var caller =
      (ctx && (ctx.buttonEl || ctx.btn || ctx.caller)) ||
      (ev && (ev.currentTarget || ev.target)) ||
      null;

    if (editor && caller && caller.nodeType === 1) {
      if (ev && ev.preventDefault) ev.preventDefault();
      openSceditorDropdown(editor, caller);
      return;
    }

    insertWrap('[font=Arial]', '[/font]', ctx || {});
  }

  var handler = {
    id: ID,
    title: 'Шрифт (семейства)',
    onClick: aqrOpen,
    click: aqrOpen,
    action: aqrOpen,
    run: aqrOpen,
    init: function () {}
  };

  window.afAqrBuiltinHandlers[ID] = handler;
  window.afAqrBuiltinHandlers[CMD] = handler;

  // ====== СТРАХОВКА capture (как в fontsize.js) ======
  function findButtonAtPoint(e) {
    try {
      var x = e.clientX, y = e.clientY;
      var el = document.elementFromPoint(x, y);
      if (!el) return null;

      var btn = el.closest ? el.closest('a,button') : el;
      if (!btn || btn.nodeType !== 1) return null;

      var cls = String(btn.className || '');
      var data =
        (btn.getAttribute('data-cmd') || btn.getAttribute('data-command') || btn.getAttribute('data-id') || '') +
        ' ' +
        (btn.getAttribute('aria-label') || '') +
        ' ' +
        (btn.getAttribute('title') || '');

      var s = (cls + ' ' + data).toLowerCase();

      // наш AQR cmd/id
      if (s.indexOf(CMD) !== -1) return btn;
      if (s.indexOf(ID) !== -1) return btn;

      // стандартная кнопка SCEditor font
      if (cls.indexOf('sceditor-button-font') !== -1) return btn;

      return null;
    } catch (e0) {
      return null;
    }
  }

  function nearestEditorFromButton(btn) {
    try {
      if (!window.jQuery || !btn) return getSceditorInstanceFromCtx({});
      var $ = window.jQuery;

      var $container = $(btn).closest('.sceditor-container');
      if ($container.length) {
        var $ta = $container.prevAll('textarea').first();
        if ($ta.length) {
          var inst = $ta.sceditor && $ta.sceditor('instance');
          if (inst && typeof inst.insertText === 'function') return inst;
        }
      }
    } catch (e1) {}

    return getSceditorInstanceFromCtx({});
  }

  function captureClick(e) {
    var btn = findButtonAtPoint(e);
    if (!btn) return;

    try { e.preventDefault(); } catch (e2) {}
    try { e.stopPropagation(); } catch (e3) {}
    try { e.stopImmediatePropagation(); } catch (e4) {}

    var inst = nearestEditorFromButton(btn);
    if (inst) openSceditorDropdown(inst, btn);
  }

  (function bindCapture() {
    if (window.__af_fontfamily_capture_bound) return;
    window.__af_fontfamily_capture_bound = true;

    document.addEventListener('pointerdown', captureClick, true);
    document.addEventListener('mousedown', captureClick, true);
    document.addEventListener('click', captureClick, true);
  })();

})();

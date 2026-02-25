(function () {
  'use strict';

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  if (window.__afAeListsInitialized) return;
  window.__afAeListsInitialized = true;

  function asText(x) { return String(x == null ? '' : x); }

  var LIST_BTNS = [
    { cmd: 'af_ul_disc',        attr: '',            tooltip: 'Список: точки (•)' },
    { cmd: 'af_ul_square',      attr: 'square',      tooltip: 'Список: квадраты (■)' },
    { cmd: 'af_ul_decimal',     attr: 'i',           tooltip: 'Список: нумерация (1,2,3)' },
    { cmd: 'af_ul_upper_roman', attr: 'upper-roman', tooltip: 'Список: римские (I, II, III)' },
    { cmd: 'af_ul_upper_alpha', attr: 'upper-alpha', tooltip: 'Список: буквы (A, B, C)' },
    { cmd: 'af_ul_lower_alpha', attr: 'lower-alpha', tooltip: 'Список: буквы (a, b, c)' }
  ];

  function cmdToAttr(cmd) {
    cmd = asText(cmd).trim();
    for (var i = 0; i < LIST_BTNS.length; i++) {
      if (LIST_BTNS[i].cmd === cmd) return LIST_BTNS[i].attr;
    }
    return '';
  }

  function hasSceditor() {
    return !!(window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.sceditor === 'function');
  }

  function isSourceMode(inst) {
    try {
      if (!inst) return false;
      if (typeof inst.sourceMode === 'function') return !!inst.sourceMode();
      if (typeof inst.isSourceMode === 'function') return !!inst.isSourceMode();
    } catch (e) {}
    return false;
  }

  function getSceditorInstanceFromCtx(ctx) {
    if (ctx && typeof ctx.insertText === 'function') return ctx;
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

  function getTextareaFromCtx(ctx) {
    if (ctx && ctx.textarea && ctx.textarea.nodeType === 1) return ctx.textarea;
    if (ctx && ctx.ta && ctx.ta.nodeType === 1) return ctx.ta;

    var ae = document.activeElement;
    if (ae && ae.tagName === 'TEXTAREA') return ae;

    return document.querySelector('textarea#message') ||
      document.querySelector('textarea[name="message"]') ||
      null;
  }

  function insertIntoTextarea(chunk, ctx) {
    var ta = getTextareaFromCtx(ctx);
    if (!ta) return false;

    try {
      var start = ta.selectionStart || 0;
      var end = ta.selectionEnd || 0;
      var val = String(ta.value || '');
      var before = val.slice(0, start);
      var sel = val.slice(start, end);
      var after = val.slice(end);

      ta.value = before + chunk + sel + after;

      var caret = before.length + chunk.length;
      ta.focus();
      ta.setSelectionRange(caret, caret);
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      return true;
    } catch (e) {
      return false;
    }
  }

  // ===============================
  // Canonical BBCode chunk (твой канон)
  // ===============================
  function buildCanonicalChunk(attr) {
    attr = asText(attr).trim();
    var open = attr ? ('[ul=' + attr + ']') : '[ul]';
    return open + '\n' +
      '[li]...[/li]\n' +
      '[li]...[/li]\n' +
      '[/ul]';
  }

  // ===============================
  // WYSIWYG HTML chunk (для вставки в iframe)
  // ===============================
  function normAttr(a) {
    a = (a == null) ? '' : String(a);
    return a.trim();
  }

  // ТВОЙ КАНОН: [ul=i] => decimal (да, звучит странно, но это твой договор)
  function olStyleForAttr(a) {
    a = normAttr(a);

    // сохраняем регистр для A/I, если прилетит именно так
    var low = a.toLowerCase();

    if (low === 'i') return 'decimal';
    if (a === 'A') return 'upper-alpha';
    if (low === 'a') return 'lower-alpha';
    if (a === 'I') return 'upper-roman';
    if (low === 'r') return 'lower-roman';

    // если админка хранит сразу CSS list-style-type
    if (low === 'upper-roman' || low === 'lower-roman' ||
        low === 'upper-alpha' || low === 'lower-alpha' ||
        low === 'decimal') return low;

    return 'decimal';
  }

  function attrForOlStyle(listStyleType) {
    var t = String(listStyleType || '').trim();
    var low = t.toLowerCase();

    if (!low) return 'i';

    // буллеты
    if (low === 'disc' || low === 'circle' || low === 'square') return '';

    // твой канон
    if (low === 'decimal') return 'i';
    if (low === 'lower-alpha') return 'a';
    if (low === 'upper-alpha') return 'A';
    if (low === 'upper-roman') return 'I';
    if (low === 'lower-roman') return 'r';

    // если вдруг что-то экзотическое — считаем это “нумерацией”
    return 'i';
  }

  function buildHtmlListChunk(attr) {
    attr = normAttr(attr);

    // без атрибута — буллеты
    if (!attr) {
      return '<ul style="list-style-type:disc; padding-left:1.4em;">' +
        '<li>...</li><li>...</li>' +
      '</ul>';
    }

    // с атрибутом — нумерация
    var lst = olStyleForAttr(attr);
    return '<ol style="list-style-type:' + lst + '; padding-left:1.6em;">' +
      '<li>...</li><li>...</li>' +
    '</ol>';
  }

  // ===============================
  // WYSIWYG CSS (чтобы SCEditor не “съедал” типы)
  // ===============================
  function ensureListCss(inst) {
    try {
      if (!inst || typeof inst.getBody !== 'function') return;

      var body = inst.getBody();
      if (!body || !body.ownerDocument) return;

      var doc = body.ownerDocument;
      var head = doc.head || doc.getElementsByTagName('head')[0];
      if (!head) return;

      if (doc.getElementById('af-ae-lists-css')) return;

      var css =
        'ol{padding-left:1.6em !important;}' +
        'ul{padding-left:1.4em !important;}' +
        'li{display:list-item !important;}';

      var st = doc.createElement('style');
      st.id = 'af-ae-lists-css';
      st.type = 'text/css';
      st.appendChild(doc.createTextNode(css));
      head.appendChild(st);
    } catch (e) {}
  }

  // ===============================
  // BBCode plugin patch (ГЛАВНОЕ)
  // ===============================
  function afAeEnsureMybbListsBbcode(inst) {
    if (!hasSceditor()) return;

    function getBb() {
      try {
        var bb = jQuery.sceditor.plugins && jQuery.sceditor.plugins.bbcode
          ? jQuery.sceditor.plugins.bbcode.bbcode
          : null;
        if (bb && typeof bb.set === 'function') return bb;
      } catch (e0) {}

      try {
        if (inst && typeof inst.getPlugin === 'function') {
          var p = inst.getPlugin('bbcode');
          if (p && p.bbcode && typeof p.bbcode.set === 'function') return p.bbcode;
        }
      } catch (e1) {}

      return null;
    }

    var bb = getBb();
    if (!bb) {
      var t = 0;
      (function retry() {
        t++;
        var b2 = getBb();
        if (b2) { try { afAeEnsureMybbListsBbcode(inst); } catch (e2) {} return; }
        if (t < 25) return setTimeout(retry, 120);
      })();
      return;
    }

    if (bb.__afAeMybbListsPatched) return;
    bb.__afAeMybbListsPatched = true;

    // [li]..[/li] <-> <li>..</li>
    try {
      bb.set('li', {
        isInline: false,
        html: function (_token, _attrs, content) {
          return '<li>' + (content || '') + '</li>';
        },
        format: function (_el, content) {
          return '[li]' + (content || '') + '[/li]';
        }
      });
    } catch (eLI) {}

    // [ul] / [ul=i]  <->  <ul> / <ol>
    try {
      bb.set('ul', {
        isInline: false,

        html: function (_token, attrs, content) {
          var a = '';
          try { a = (attrs && attrs.defaultattr != null) ? String(attrs.defaultattr) : ''; } catch (e0) { a = ''; }
          a = normAttr(a);

          if (!a) {
            return '<ul style="list-style-type:disc; padding-left:1.4em;">' + (content || '') + '</ul>';
          }

          var lst = olStyleForAttr(a);
          return '<ol style="list-style-type:' + lst + '; padding-left:1.6em;">' + (content || '') + '</ol>';
        },

        format: function (el, content) {
          try {
            if (!el || !el.tagName) return '[ul]' + (content || '') + '[/ul]';

            var tag = String(el.tagName).toUpperCase();

            // иногда style.listStyleType пустой, берём computed
            function getListStyleType(node) {
              var v = '';
              try { v = (node.style && node.style.listStyleType) ? String(node.style.listStyleType) : ''; } catch (e0) { v = ''; }
              if (v) return v;
              try {
                if (node.ownerDocument && node.ownerDocument.defaultView && node.ownerDocument.defaultView.getComputedStyle) {
                  var cs = node.ownerDocument.defaultView.getComputedStyle(node);
                  if (cs && cs.listStyleType) return String(cs.listStyleType);
                }
              } catch (e1) {}
              return '';
            }

            if (tag === 'OL') {
              var lst = getListStyleType(el);
              var a = attrForOlStyle(lst);
              return '[ul=' + (a || 'i') + ']' + (content || '') + '[/ul]';
            }

            // UL
            return '[ul]' + (content || '') + '[/ul]';
          } catch (e2) {
            return '[ul]' + (content || '') + '[/ul]';
          }
        }
      });
    } catch (eUL) {}

    // ВАЖНО: “bulletlist/orderedlist” иногда сериализуются как [bulletlist]
    // Мы забиваем это и заставляем всё уходить через ul.
    try {
      bb.set('bulletlist', {
        isInline: false,
        html: function (_t, _a, c) {
          return '<ul style="list-style-type:disc; padding-left:1.4em;">' + (c || '') + '</ul>';
        },
        format: function (_el, c) {
          return '[ul]' + (c || '') + '[/ul]';
        }
      });
    } catch (eBL) {}

    try {
      bb.set('orderedlist', {
        isInline: false,
        html: function (_t, _a, c) {
          return '<ol style="list-style-type:decimal; padding-left:1.6em;">' + (c || '') + '</ol>';
        },
        format: function (_el, c) {
          return '[ul=i]' + (c || '') + '[/ul]';
        }
      });
    } catch (eOL) {}
  }

  // ===============================
  // Insert list (единственная точка)
  // ===============================
  function insertCanonicalList(editorOrCtx, attr) {
    var inst = getSceditorInstanceFromCtx(editorOrCtx);

    // без SCEditor
    if (!inst) {
      return insertIntoTextarea(buildCanonicalChunk(attr), editorOrCtx);
    }

    // патчим bbcode и css
    try { afAeEnsureMybbListsBbcode(inst); } catch (e0) {}
    try { ensureListCss(inst); } catch (e1) {}

    // SOURCE: вставляем BBCode
    if (isSourceMode(inst)) {
      var chunk = buildCanonicalChunk(attr);
      try {
        if (typeof inst.insertText === 'function') inst.insertText(chunk, '');
        else if (typeof inst.insert === 'function') inst.insert(chunk, '');
      } catch (e2) {}
      try { if (typeof inst.updateOriginal === 'function') inst.updateOriginal(); } catch (e3) {}
      return true;
    }

    // WYSIWYG: вставляем HTML, bbcode-плагин сам сериализует в [ul...]
    var html = buildHtmlListChunk(attr);
    try {
      if (typeof inst.insert === 'function') {
        inst.insert(html, '');
      } else if (typeof inst.wysiwygEditorInsertHtml === 'function') {
        inst.wysiwygEditorInsertHtml(html);
      } else {
        // крайний фоллбек: не красиво, но лучше чем “ничего”
        inst.insertText(buildCanonicalChunk(attr), '');
      }
    } catch (e4) {}

    try { if (typeof inst.updateOriginal === 'function') inst.updateOriginal(); } catch (e5) {}
    try { if (typeof inst.focus === 'function') inst.focus(); } catch (e6) {}
    return true;
  }

  // ===============================
  // cmd detection (для dropdown и обычных кнопок)
  // ===============================
  function detectCmd(def, caller) {
    try {
      var c = asText(def && def.cmd ? def.cmd : '').trim();
      if (c) return c;
    } catch (e0) {}

    try {
      var el = caller && caller.nodeType === 1 ? caller : null;
      if (!el) return '';

      var a = el.closest ? el.closest('a') : el;
      if (!a) a = el;

      for (var i = 0; i < LIST_BTNS.length; i++) {
        var cmd = LIST_BTNS[i].cmd;
        if (a.classList && a.classList.contains('sceditor-button-' + cmd)) return cmd;
      }

      if (a.className) {
        var m = String(a.className).match(/sceditor-button-([a-z0-9_\-]+)/i);
        if (m && m[1]) return String(m[1]);
      }
    } catch (e1) {}
    return '';
  }

  // ===============================
  // AE handler (для pack handler: af_ae_lists_exec)
  // ===============================
  function aeListsHandler(editor, def, caller) {
    var cmd = detectCmd(def, caller);
    var attr = cmdToAttr(cmd);
    insertCanonicalList(editor || {}, attr);
  }

  function registerHandlers() {
    window.afAeBuiltinHandlers.lists = aeListsHandler;
    window.afAqrBuiltinHandlers.lists = { onClick: aeListsHandler };

    for (var i = 0; i < LIST_BTNS.length; i++) {
      var c = LIST_BTNS[i].cmd;
      window.afAeBuiltinHandlers[c] = aeListsHandler;
      window.afAqrBuiltinHandlers[c] = { onClick: aeListsHandler };
    }
  }

  // if AE core calls window.af_ae_lists_exec(...)
  window.af_ae_lists_exec = function (editor, def, caller) {
    aeListsHandler(editor, def, caller);
  };

  // ===============================
  // SCEditor commands (важно: не [bulletlist])
  // ===============================
  function registerSceditorCommands() {
    if (!hasSceditor()) return false;
    var $ = window.jQuery;

    function setCmd(name, def) {
      try {
        if ($.sceditor.command && typeof $.sceditor.command.set === 'function') {
          $.sceditor.command.set(name, def);
          return true;
        }
      } catch (e0) {}
      try {
        if (!$.sceditor.commands) $.sceditor.commands = {};
        $.sceditor.commands[name] = def;
        return true;
      } catch (e1) {}
      return false;
    }

    // наши кастомные кнопки
    LIST_BTNS.forEach(function (it) {
      setCmd(it.cmd, {
        tooltip: it.tooltip || it.cmd,
        exec: function () {
          try { afAeEnsureMybbListsBbcode(this); } catch (e0) {}
          try { ensureListCss(this); } catch (e1) {}
          insertCanonicalList(this, it.attr);
        },
        txtExec: function () {
          insertCanonicalList(this, it.attr);
        }
      });
    });

    // перехват стандартных SCEditor list-команд:
    // чтобы не получалось [bulletlist][/bulletlist] и подобная хтонь
    setCmd('bulletlist', {
      tooltip: 'Список: точки (•)',
      exec: function () {
        try { afAeEnsureMybbListsBbcode(this); } catch (e0) {}
        try { ensureListCss(this); } catch (e1) {}
        insertCanonicalList(this, '');
      },
      txtExec: function () {
        insertCanonicalList(this, '');
      }
    });

    setCmd('orderedlist', {
      tooltip: 'Список: нумерация (1,2,3)',
      exec: function () {
        try { afAeEnsureMybbListsBbcode(this); } catch (e0) {}
        try { ensureListCss(this); } catch (e1) {}
        insertCanonicalList(this, 'i');
      },
      txtExec: function () {
        insertCanonicalList(this, 'i');
      }
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

  // ===============================
  // Light patch of existing instances (без мигания)
  // ===============================
  function tryPatchInstances() {
    if (!hasSceditor() || !window.jQuery) return false;
    var $ = window.jQuery;

    try {
      var tas = document.querySelectorAll('textarea');
      for (var i = 0; i < tas.length; i++) {
        var ta = tas[i];
        if (!ta || ta.nodeType !== 1) continue;

        var inst = null;
        try { inst = $(ta).sceditor('instance'); } catch (e0) { inst = null; }
        if (!inst) continue;

        try { afAeEnsureMybbListsBbcode(inst); } catch (e1) {}
        try { ensureListCss(inst); } catch (e2) {}
      }
    } catch (e3) {}

    return true;
  }

  // ===============================
  // boot
  // ===============================
  registerHandlers();
  for (var k = 1; k <= 10; k++) setTimeout(registerHandlers, k * 250);

  waitAnd(registerSceditorCommands, 150);

  var tries2 = 0;
  (function tick2() {
    tries2++;
    tryPatchInstances();
    if (tries2 < 60) setTimeout(tick2, 150);
  })();

})();

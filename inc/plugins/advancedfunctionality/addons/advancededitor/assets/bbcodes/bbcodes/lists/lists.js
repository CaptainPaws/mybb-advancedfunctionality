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
      '[li][/li]\n' +
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

    var low = a.toLowerCase();
    if (low === 'i') return 'decimal';
    if (low === 'upper-roman') return 'upper-roman';
    if (low === 'upper-alpha') return 'upper-alpha';
    if (low === 'lower-alpha') return 'lower-alpha';

    return 'decimal';
  }

  function attrForOlStyle(listStyleType) {
    var t = String(listStyleType || '').trim();
    var low = t.toLowerCase();

    if (!low || low === 'decimal') return 'i';
    if (low === 'upper-roman') return 'upper-roman';
    if (low === 'upper-alpha') return 'upper-alpha';
    if (low === 'lower-alpha') return 'lower-alpha';

    return 'i';
  }

  function attrForUlStyle(listStyleType) {
    var low = String(listStyleType || '').trim().toLowerCase();
    if (low === 'square') return 'square';
    return '';
  }

  function extractListStyle(el, fallback) {
    var style = '';

    try {
      if (el && typeof el.getAttribute === 'function') {
        var dataList = el.getAttribute('data-list');
        if (dataList) style = String(dataList);
      }
    } catch (e0) {}

    if (!style) {
      try { style = String((el && el.style && el.style.listStyleType) ? el.style.listStyleType : ''); } catch (e1) { style = ''; }
    }

    if (!style && el && el.ownerDocument && el.ownerDocument.defaultView && el.ownerDocument.defaultView.getComputedStyle) {
      try {
        var cs = el.ownerDocument.defaultView.getComputedStyle(el);
        style = cs && cs.listStyleType ? String(cs.listStyleType) : '';
      } catch (e2) { style = ''; }
    }

    style = String(style || '').trim().toLowerCase();
    return style || String(fallback || '').toLowerCase();
  }

  function isOrderedAttr(attr) {
    var low = String(attr || '').trim().toLowerCase();
    return low === 'i' || low === 'upper-roman' || low === 'upper-alpha' || low === 'lower-alpha';
  }

  function buildHtmlListChunk(attr) {
    attr = normAttr(attr);

    // без атрибута — буллеты
    if (!attr) {
      return '<ul style="list-style-type:disc; padding-left:1.4em;">' +
        '<li><br></li>' +
      '</ul>';
    }

    if (attr === 'square') {
      return '<ul style="list-style-type:square; padding-left:1.4em;">' +
        '<li><br></li>' +
      '</ul>';
    }

    var lst = olStyleForAttr(attr);
    return '<ol style="list-style-type:' + lst + '; padding-left:1.6em;">' +
      '<li><br></li>' +
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

  function bindToSourceListNormalization(inst) {
    if (!inst || inst.__afAeListToSourceBound) return;
    inst.__afAeListToSourceBound = true;

    try {
      if (typeof inst.bind !== 'function') return;
      inst.bind('toSource', function (html) {
        html = String(html == null ? '' : html);

        html = html.replace(/<ul[^>]*style="[^"]*list-style-type:([^;"]+)[^"]*"[^>]*>/gi, function (_m, type) {
          return '<ul data-list="' + String(type || '').trim().toLowerCase() + '">';
        });

        html = html.replace(/<ol[^>]*style="[^"]*list-style-type:([^;"]+)[^"]*"[^>]*>/gi, function (_m, type) {
          return '<ol data-list="' + String(type || '').trim().toLowerCase() + '">';
        });

        return html;
      });
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
        html: '<li>{0}</li>',
        format: '[li]{0}[/li]',
        tags: {
          li: {
            format: function (el, content) {
              content = (content || '');
              if (!String(content).trim()) content = '';
              return '[li]' + content + '[/li]';
            }
          }
        }
      });
    } catch (eLI) {}

    // [ul] / [ul=i]  <->  <ul> / <ol>
    try {
      bb.set('ul', {

        isBlock: true,

        html: function (_token, attrs, content) {

          var type = (attrs && attrs.defaultattr != null) ? String(attrs.defaultattr) : 'disc';
          type = type || 'disc';

          var map = {
            disc: ['ul', 'disc'],
            square: ['ul', 'square'],
            i: ['ol', 'decimal'],
            'upper-roman': ['ol', 'upper-roman'],
            'upper-alpha': ['ol', 'upper-alpha'],
            'lower-alpha': ['ol', 'lower-alpha']
          };

          var conf = map[type] || map.disc;
          var tag = conf[0];
          var style = conf[1];

          return '<' + tag + ' style="list-style-type:' + style + '; padding-left:1.6em;">' + (content || '') + '</' + tag + '>';
        },

        format: function (el, content) {

          var style = extractListStyle(el, 'disc');

          var map = {
            disc: '',
            square: 'square',
            decimal: 'i',
            'upper-roman': 'upper-roman',
            'upper-alpha': 'upper-alpha',
            'lower-alpha': 'lower-alpha'
          };

          var attr = map[style] || '';

          if (attr) return '[ul=' + attr + ']' + (content || '') + '[/ul]';

          return '[ul]' + (content || '') + '[/ul]';
        },

        tags: {
          ul: {
            format: function (el, content) {
              var style = extractListStyle(el, 'disc');

              var map = {
                disc: '',
                square: 'square',
                decimal: 'i',
                'upper-roman': 'upper-roman',
                'upper-alpha': 'upper-alpha',
                'lower-alpha': 'lower-alpha'
              };

              var attr = map[style] || '';

              if (attr) return '[ul=' + attr + ']' + (content || '') + '[/ul]';

              return '[ul]' + (content || '') + '[/ul]';
            }
          },

          ol: {
            format: function (el, content) {
              var style = extractListStyle(el, 'decimal');

              var map = {
                decimal: 'i',
                'upper-roman': 'upper-roman',
                'upper-alpha': 'upper-alpha',
                'lower-alpha': 'lower-alpha'
              };

              var attr = map[style] || 'i';
              return '[ul=' + attr + ']' + (content || '') + '[/ul]';
            }
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

  function bindListEnterBehavior(inst) {
    if (!inst || inst.__afAeListEnterBound) return;
    inst.__afAeListEnterBound = true;

    function closestLi(node) {
      while (node && node.nodeType === 1) {
        if (String(node.nodeName).toLowerCase() === 'li') return node;
        node = node.parentNode;
      }
      return null;
    }

    function isLiEmpty(li) {
      if (!li) return true;
      var text = String(li.textContent || '').replace(/\u00a0/g, ' ').trim();
      if (text.length) return false;
      var media = li.querySelector && li.querySelector('img,video,audio,iframe,table,blockquote,pre');
      return !media;
    }

    function placeCaretAtStart(node, doc) {
      if (!node || !doc) return;
      var sel = doc.getSelection && doc.getSelection();
      if (!sel) return;
      var range = doc.createRange();
      range.selectNodeContents(node);
      range.collapse(true);
      sel.removeAllRanges();
      sel.addRange(range);
    }

    function onEnter(e) {
      if (!e || e.key !== 'Enter' || e.shiftKey || e.ctrlKey || e.metaKey || e.altKey) return;

      var node = null;
      try { node = (typeof inst.currentNode === 'function') ? inst.currentNode() : null; } catch (e0) { node = null; }
      if (!node) return;

      var li = closestLi(node.nodeType === 1 ? node : node.parentNode);
      if (!li) return;

      e.preventDefault();

      var doc = li.ownerDocument;
      var list = li.parentNode;
      if (!doc || !list) return false;

      if (isLiEmpty(li)) {
        var listParent = list.parentNode;
        var marker = doc.createElement('p');
        marker.appendChild(doc.createElement('br'));

        li.parentNode.removeChild(li);

        if (!list.querySelector('li')) {
          if (listParent) {
            if (list.nextSibling) listParent.insertBefore(marker, list.nextSibling);
            else listParent.appendChild(marker);
            listParent.removeChild(list);
            placeCaretAtStart(marker, doc);
          }
        } else {
          if (list.nextSibling) listParent.insertBefore(marker, list.nextSibling);
          else listParent.appendChild(marker);
          placeCaretAtStart(marker, doc);
        }
        return false;
      }

      var newLi = doc.createElement('li');
      newLi.appendChild(doc.createElement('br'));
      if (li.nextSibling) list.insertBefore(newLi, li.nextSibling);
      else list.appendChild(newLi);
      placeCaretAtStart(newLi, doc);
      return false;
    }

    try {
      if (typeof inst.keyDown === 'function') {
        inst.keyDown(onEnter);
        return;
      }
    } catch (e1) {}

    try {
      if (typeof inst.getBody === 'function') {
        var body = inst.getBody();
        if (body && !body.__afAeListEnterBound) {
          body.__afAeListEnterBound = true;
          body.addEventListener('keydown', onEnter, false);
        }
      }
    } catch (e2) {}
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
    try { bindToSourceListNormalization(inst); } catch (e2) {}
    try { bindListEnterBehavior(inst); } catch (e3) {}

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
        try { bindToSourceListNormalization(this); } catch (e2) {}
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
        try { bindToSourceListNormalization(this); } catch (e2) {}
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
        try { bindToSourceListNormalization(inst); } catch (e3) {}
        try { bindListEnterBehavior(inst); } catch (e4) {}
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

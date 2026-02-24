(function () {
  'use strict';

  if (window.__afAdvancedEditorLoaded) return;
  window.__afAdvancedEditorLoaded = true;

  var P = window.afAePayload || window.afAdvancedEditorPayload || {};
  var CFG = (P && P.cfg) ? P.cfg : {};

  if (typeof window.__afAeGlobalToggling === 'undefined') window.__afAeGlobalToggling = 0;
  if (typeof window.__afAeIgnoreMutationsUntil === 'undefined') window.__afAeIgnoreMutationsUntil = 0;

  function now() { return Date.now ? Date.now() : +new Date(); }
  function asText(x) { return String(x == null ? '' : x); }

  function log() {
    if (!window.__afAeDebug) return;
    try { console.log.apply(console, arguments); } catch (e) {}
  }

  function hasJq() { return !!(window.jQuery && window.jQuery.fn); }
  function hasSceditor() { return hasJq() && typeof window.jQuery.fn.sceditor === 'function'; }

  function isHidden(el) {
    if (!el || el.nodeType !== 1) return true;
    if (el.disabled) return true;
    if (el.type === 'hidden') return true;
    if (el.offsetParent === null && el.getClientRects().length === 0) return true;
    return false;
  }

  function isEligibleTextarea(ta) {
    if (!ta || ta.nodeType !== 1 || ta.tagName !== 'TEXTAREA') return false;

    var cls = ta.className || '';
    if (/\bsceditor-source\b/i.test(cls)) return false;
    if (/\bsceditor-textarea\b/i.test(cls)) return false;

    if (ta.getAttribute('data-af-ae-skip') === '1') return false;

    var name = (ta.getAttribute('name') || '').toLowerCase();
    if (name === 'subject') return false;

    return true;
  }

  function normalizeLayout(x) {
    if (!x || typeof x !== 'object' || !Array.isArray(x.sections)) {
      return {
        v: 1,
        sections: [
          {
            id: 'main',
            type: 'group',
            title: 'Основное',
            items: [
              'bold', 'italic', 'underline', 'strike', 'subscript', 'superscript',
              '|',
              'font', 'size', 'color', 'removeformat',
              '|',
              'undo', 'redo', 'pastetext', 'horizontalrule',
              '|',
              'left', 'center', 'right', 'justify',
              '|',
              'bulletlist', 'orderedlist',
              '|',
              'quote', 'code',
              '|',
              'link', 'unlink', 'email', 'image', 'youtube', 'emoticon',
              '|',
              // ВАЖНО: больше НЕ добавляем af_togglemode по умолчанию
              'maximize'
            ]
          },
          { id: 'addons', type: 'group', title: 'Доп. кнопки', items: [] }
        ]
      };
    }
    if (!x.v) x.v = 1;
    if (!Array.isArray(x.sections)) x.sections = [];
    return x;
  }


  function buildAvailableMap() {
    var map = Object.create(null);
    var list = Array.isArray(P.available) ? P.available : [];
    list.forEach(function (b) {
      if (!b || !b.cmd) return;
      map[String(b.cmd)] = b;
    });
    return map;
  }

  function buildCustomDefMap() {
    var map = Object.create(null);
    var list = Array.isArray(P.customDefs) ? P.customDefs : [];
    list.forEach(function (b) {
      if (!b || !b.cmd) return;
      map[String(b.cmd)] = b;
    });
    return map;
  }

  function afAeParseBbTagFromOpenClose(openTag, closeTag) {
    openTag = asText(openTag).trim();
    closeTag = asText(closeTag).trim();

    // ждём формат вида: [tag] или [tag=...]
    var m = openTag.match(/^\[([a-z0-9_]+)(?:=([^\]]*))?\]/i);
    if (!m) return null;

    var tag = String(m[1] || '').toLowerCase();
    var defAttr = (typeof m[2] !== 'undefined') ? String(m[2] || '') : '';

    var hasClose = false;
    if (closeTag) {
      var mc = closeTag.match(/^\[\/([a-z0-9_]+)\]/i);
      if (mc && String(mc[1] || '').toLowerCase() === tag) hasClose = true;
    } else {
      // self-closing типа [hr]
      hasClose = false;
    }

    return {
      tag: tag,
      defaultAttr: defAttr,
      openRaw: openTag,
      closeRaw: closeTag,
      hasClose: hasClose
    };
  }

  function afAeEscAttr(s) {
    s = asText(s);
    return s
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function afAeMakePlaceholderOpenClose(tagInfo, attrsOrDefault) {
    var tag = tagInfo.tag;
    var attr = (attrsOrDefault != null) ? String(attrsOrDefault) : String(tagInfo.defaultAttr || '');
    var dataAttr = attr ? (' data-af-bb-attr="' + afAeEscAttr(attr) + '"') : '';
    // inline placeholder по умолчанию
    var open = '<span class="af-ae-bb af-ae-bb-' + afAeEscAttr(tag) + '" data-af-bb="' + afAeEscAttr(tag) + '"' + dataAttr + '>';
    var close = '</span>';
    return { open: open, close: close };
  }

  // Универсальная проверка режима
  function afAeIsSourceMode(ed) {
    try {
      if (!ed) return false;
      if (typeof ed.sourceMode === 'function') return !!ed.sourceMode();
      if (typeof ed.isSourceMode === 'function') return !!ed.isSourceMode();
    } catch (e) {}
    return false;
  }

  function afAeGetSourceTextarea(ed) {
    try {
      // Некоторые сборки SCEditor держат ссылку так
      if (ed && ed.sourceEditor && ed.sourceEditor.nodeType === 1) return ed.sourceEditor;
    } catch (e0) {}

    try {
      // Пробуем найти textarea внутри контейнера
      var cont = null;
      try { cont = (ed && typeof ed.getContainer === 'function') ? ed.getContainer() : null; } catch (e1) { cont = null; }
      if (!cont && ed && ed.container && ed.container.nodeType === 1) cont = ed.container;
      if (cont && cont.querySelector) {
        var ta = cont.querySelector('textarea.sceditor-textarea');
        if (ta) return ta;
        // fallback: первый textarea в контейнере
        ta = cont.querySelector('textarea');
        if (ta) return ta;
      }
    } catch (e2) {}

    return null;
  }

  function afAeInsertIntoTextareaAtCursor(ta, text) {
    try {
      if (!ta) return false;
      text = String(text == null ? '' : text);

      // Фокус обязателен, иначе selectionStart/End может быть 0/0 или не обновиться
      try { ta.focus(); } catch (e0) {}

      var start = 0, end = 0;
      try {
        start = (typeof ta.selectionStart === 'number') ? ta.selectionStart : ta.value.length;
        end = (typeof ta.selectionEnd === 'number') ? ta.selectionEnd : start;
      } catch (e1) {
        start = ta.value.length;
        end = start;
      }

      var v = String(ta.value || '');
      ta.value = v.slice(0, start) + text + v.slice(end);

      var pos = start + text.length;
      try { ta.selectionStart = ta.selectionEnd = pos; } catch (e2) {}

      // триггерим input, чтобы SCEditor/валидаторы увидели изменение
      try { ta.dispatchEvent(new Event('input', { bubbles: true })); } catch (e3) {}
      return true;
    } catch (e) {}
    return false;
  }

  function afAeInsertOpenClose(ed, open, close) {
    open = String(open == null ? '' : open);
    close = String(close == null ? '' : close);

    // SOURCE MODE — вставляем прямо в source-textarea
    try {
      if (afAeIsSourceMode(ed)) {
        var src = afAeGetSourceTextarea(ed);
        if (src) {
          return afAeInsertIntoTextareaAtCursor(src, open + close);
        }
      }
    } catch (e0) {}

    // WYSIWYG/общий случай — BBCode через insert()
    try {
      if (ed && typeof ed.insert === 'function') {
        ed.insert(open, close);
        return true;
      }
    } catch (e1) {}

    // fallback: через val()
    try {
      if (ed && typeof ed.val === 'function') {
        var cur = ed.val();
        ed.val(String(cur || '') + open + close);
        return true;
      }
    } catch (e2) {}

    return false;
  }
 

  function buildAllowedCmdSet() {
    var s = Object.create(null);
    var list = Array.isArray(P.available) ? P.available : [];
    list.forEach(function (b) {
      if (!b || !b.cmd) return;
      s[String(b.cmd)] = true;
    });
    s['|'] = true;

    // Канон dropdown-команд: af_menu_dropdown1, af_menu_dropdown2, ...
    // (sanitize должен их пропускать всегда)
    return s;
  }


  function ensureToggleCommand(layout) {
    // Никакой авто-вставки кнопки переключения режимов.
    // Кнопка появляется ТОЛЬКО если ты сама добавила её в layout из ACP.
    return layout;
  }


  function sanitizeLayout(lay) {
    lay = normalizeLayout(lay);

    var allowed = buildAllowedCmdSet();

    (lay.sections || []).forEach(function (sec) {
      if (!sec || typeof sec !== 'object') return;

      sec.id = String(sec.id || ('sec_' + Math.random().toString(16).slice(2)));
      sec.type = String(sec.type || 'group').toLowerCase();
      sec.title = String(sec.title || (sec.type === 'dropdown' ? '★' : 'Секция'));

      if (!Array.isArray(sec.items)) sec.items = [];

      sec.items = sec.items
        .map(function (x) { return String(x == null ? '' : x).trim(); })
        .filter(function (cmd) {
          if (!cmd) return false;
          if (cmd === '|') return true;

          // канон dropdown-команд
          if (/^af_menu_dropdown\d+$/i.test(cmd)) return true;

          return !!allowed[cmd];
        });
    });

    // ВАЖНО: больше НЕ вызываем ensureToggleCommand(lay)
    // Никакой автодобавки af_togglemode.
    return lay;
  }



  function buildToolbarFromLayout(lay) {
    var parts = [];
    var menus = [];

    var dropdownN = 0;

    (lay.sections || []).forEach(function (sec, idx) {
      if (!sec) return;

      var type = String(sec.type || 'group').toLowerCase();
      var id = String(sec.id || ('sec' + idx));
      var title = String(sec.title || '');
      var items = Array.isArray(sec.items) ? sec.items.slice() : [];

      if (type === 'dropdown') {
        dropdownN++;
        var cmd = 'af_menu_dropdown' + dropdownN;

        parts.push(cmd);
        menus.push({
          id: id,          // внутренний id секции
          cmd: cmd,        // имя команды SCEditor
          title: (title || '★'),
          items: items.slice()
        });
        parts.push('|');
        return;
      }

      var group = [];
      items.forEach(function (it) {
        it = String(it || '').trim();
        if (!it) return;

        if (it === '|') {
          if (group.length) parts.push(group.join(','));
          group = [];
          parts.push('|');
          return;
        }

        group.push(it);
      });

      if (group.length) parts.push(group.join(','));
      parts.push('|');
    });

    var toolbar = parts.join(',');
    toolbar = toolbar.replace(/,+\|,+/g, '|').replace(/\|{2,}/g, '|');
    toolbar = toolbar.replace(/^,|,$/g, '').replace(/^\|+|\|+$/g, '');

    return { toolbar: toolbar, menus: menus };
  }

  function setCommand(name, def) {
    try {
      if (jQuery.sceditor.command && typeof jQuery.sceditor.command.set === 'function') {
        jQuery.sceditor.command.set(name, def);
        return true;
      }
    } catch (e0) {}
    try {
      if (!jQuery.sceditor.commands) jQuery.sceditor.commands = {};
      jQuery.sceditor.commands[name] = def;
      return true;
    } catch (e1) {}
    return false;
  }

  function getCommand(name) {
    try {
      if (jQuery.sceditor.command && typeof jQuery.sceditor.command.get === 'function') {
        return jQuery.sceditor.command.get(name);
      }
    } catch (e0) {}
    try {
      if (jQuery.sceditor.commands) return jQuery.sceditor.commands[name];
    } catch (e1) {}
    return null;
  }

  function afAeForceAlignEverywhere(inst) {
    if (!hasSceditor()) return;

    function isSource(ed) {
      try {
        if (!ed) return false;
        if (typeof ed.sourceMode === 'function') return !!ed.sourceMode();
        if (typeof ed.isSourceMode === 'function') return !!ed.isSourceMode();
        if (typeof ed.sourceMode === 'boolean') return !!ed.sourceMode;
      } catch (e) {}
      return false;
    }

    // ---------- 1) HARD OVERRIDE COMMANDS (кнопки) ----------
    // ВАЖНО: больше НЕ делаем execCommand(justifyLeft).
    // Вставляем ТОЛЬКО [align=...] — и в source, и в WYSIWYG.
    function hardCmd(cmd, alignVal) {
      try {
        var def = {
          tooltip: cmd,

          exec: function () {
            var ed = this;

            // Всегда вставляем MyBB-валидный BBCode.
            // В WYSIWYG SCEditor bbcode-плагин должен превратить это в HTML <div style="text-align:...">
            try {
              if (ed && typeof ed.insert === 'function') {
                ed.insert('[align=' + alignVal + ']', '[/align]');
                return;
              }
            } catch (e0) {}

            // fallback: если вдруг insert не доступен — хотя бы в textarea
            try {
              if (ed && typeof ed.val === 'function') {
                var cur = ed.val();
                ed.val(String(cur || '') + '[align=' + alignVal + '][/align]');
                return;
              }
            } catch (e1) {}
          },

          txtExec: function () {
            // source mode: тоже только align
            try {
              if (this && typeof this.insert === 'function') {
                this.insert('[align=' + alignVal + ']', '[/align]');
                return;
              }
            } catch (e0) {}
            try { this.exec(); } catch (e1) {}
          }
        };

        def.__afAeHardAlign = true;
        setCommand(cmd, def); // перезаписываем всегда
      } catch (e) {}
    }

    hardCmd('left', 'left');
    hardCmd('center', 'center');
    hardCmd('right', 'right');
    hardCmd('justify', 'justify');

    // ---------- 2) HARD OVERRIDE BBCODE MAP (WYSIWYG <-> BBCode) ----------
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

    function patchBb(bb) {
      if (!bb) return;

      // основной тег [align=...]
      try {
        bb.set('align', {
          isInline: false,
          html: function (token, attrs, content) {
            var a = '';
            try { a = (attrs && attrs.defaultattr != null) ? String(attrs.defaultattr) : ''; } catch (e0) { a = ''; }
            a = (a || '').toLowerCase().trim();

            if (a === 'start') a = 'left';
            if (a === 'end') a = 'right';
            if (a !== 'left' && a !== 'center' && a !== 'right' && a !== 'justify') a = 'left';

            return '<div style="text-align:' + a + '">' + content + '</div>';
          },
          format: function (el, content) {
            try {
              var a = '';
              try { a = (el && el.style && el.style.textAlign) ? String(el.style.textAlign) : ''; } catch (e0) { a = ''; }
              a = (a || '').toLowerCase().trim();

              if (a === 'start') a = 'left';
              if (a === 'end') a = 'right';
              if (a !== 'left' && a !== 'center' && a !== 'right' && a !== 'justify') return content;

              return '[align=' + a + ']' + content + '[/align]';
            } catch (e1) {
              return '[align=left]' + content + '[/align]';
            }
          }
        });
      } catch (eA0) {}

      // legacy-теги сериализуем как [align=...]
      function hardLegacy(tag, val) {
        try {
          bb.set(tag, {
            isInline: false,
            html: '<div style="text-align:' + val + '">{0}</div>',
            format: '[align=' + val + ']{0}[/align]'
          });
        } catch (e) {}
      }
      hardLegacy('left', 'left');
      hardLegacy('center', 'center');
      hardLegacy('right', 'right');
      hardLegacy('justify', 'justify');

      // div/p с text-align -> [align=...]
      function formatAlignFromEl(el, content) {
        try {
          var a = '';
          try { a = (el && el.style && el.style.textAlign) ? String(el.style.textAlign) : ''; } catch (e0) { a = ''; }
          a = (a || '').toLowerCase().trim();

          if (a === 'start') a = 'left';
          if (a === 'end') a = 'right';

          if (a !== 'left' && a !== 'center' && a !== 'right' && a !== 'justify') return content;
          return '[align=' + a + ']' + content + '[/align]';
        } catch (e1) {
          return content;
        }
      }

      try {
        bb.set('div', {
          styles: { 'text-align': 'align' },
          format: function (el, content) { return formatAlignFromEl(el, content); }
        });
      } catch (eD) {}

      try {
        bb.set('p', {
          styles: { 'text-align': 'align' },
          format: function (el, content) { return formatAlignFromEl(el, content); }
        });
      } catch (eP) {}
    }

    // bbcode plugin может быть поздним — ретраи
    var bb = getBb();
    if (!bb) {
      var tries = 0;
      (function retry() {
        tries++;
        var b2 = getBb();
        if (b2) {
          patchBb(b2);
          // и сразу чистим инстанс, если он уже есть
          try { if (inst) afAeNormalizeAlignInInstance(inst); } catch (e0) {}
          return;
        }
        if (tries < 25) return setTimeout(retry, 120);
      })();
      return;
    }

    patchBb(bb);

    // добиваем уже существующее содержимое редактора (чтобы прямо ВНУТРИ стало [align=...])
    try { if (inst) afAeNormalizeAlignInInstance(inst); } catch (eZ) {}
  }

  function afAeEnsureMycodePassthroughBbcode(inst) {
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
      // ретраи — bbcode плагин иногда поднимается позже
      var t = 0;
      (function retry() {
        t++;
        var b2 = getBb();
        if (b2) {
          try { afAeEnsureMycodePassthroughBbcode(inst); } catch (e2) {}
          return;
        }
        if (t < 25) return setTimeout(retry, 120);
      })();
      return;
    }

    if (bb.__afAeMycodePassthroughPatched) return;
    bb.__afAeMycodePassthroughPatched = true;

    // Берём все customDefs: DB-кнопки + pack-кнопки (если ты их туда кладёшь)
    var defs = buildCustomDefMap();
    Object.keys(defs).forEach(function(cmd) {
      if (!cmd) return;

      // не трогаем системные команды/дропдауны
      if (/^af_menu_dropdown\d+$/i.test(cmd)) return;
      if (cmd === 'af_togglemode') return;

      var d = defs[cmd] || {};
      var ti = afAeParseBbTagFromOpenClose(d.opentag, d.closetag);
      if (!ti || !ti.tag) return;

      var tag = ti.tag;

      // уже есть правило — не перетираем
      try {
        if (bb.get && bb.get(tag)) return;
      } catch (e0) {}

      try {
        bb.set(tag, {
          isInline: true,
          html: function(token, attrs, content) {
            var a = '';
            try { a = (attrs && attrs.defaultattr != null) ? String(attrs.defaultattr) : ''; } catch (e1) { a = ''; }
            var ph = afAeMakePlaceholderOpenClose({ tag: tag, defaultAttr: '' }, a);
            return ph.open + (content || '') + ph.close;
          },
          format: function(el, content) {
            try {
              var a = '';
              try { a = (el && el.getAttribute) ? String(el.getAttribute('data-af-bb-attr') || '') : ''; } catch (e2) { a = ''; }
              a = (a || '').trim();

              var open = '[' + tag + (a ? '=' + a : '') + ']';
              var close = '[/'+ tag +']';
              return open + (content || '') + close;
            } catch (e3) {
              return '[' + tag + ']' + (content || '') + '[/'+ tag +']';
            }
          }
        });
      } catch (eSet) {
        // если bb.set на этот тег не принял — просто игнорим
      }
    });
  }
  
  function ensureToggleCommandDefinition() {
    if (!window.jQuery || !jQuery.sceditor) return;
    if (getCommand('af_togglemode')) return;

    setCommand('af_togglemode', {
      tooltip: 'BBCode ⇄ Визуальный',
      exec: function () {
        try {
          if (typeof this.toggleSourceMode === 'function') {
            this.toggleSourceMode();
            return;
          }
        } catch (e0) {}
        try {
          if (this.command && typeof this.command.exec === 'function') {
            this.command.exec('source');
            return;
          }
        } catch (e1) {}
      },
      txtExec: function () {
        try { this.exec(); } catch (e2) {}
      }
    });
  }

  function ensureMybbTagAliases() {
    if (!hasSceditor()) return;

    if (window.__afAeMybbTagAliasesDone) return;
    window.__afAeMybbTagAliasesDone = true;

    function hard(cmd, open, close) {
      cmd = String(cmd || '').trim();
      if (!cmd) return;

      setCommand(cmd, {
        tooltip: cmd,

        exec: function () {
          // железно: sourceMode -> пишем в source textarea; иначе insert()
          try {
            afAeInsertOpenClose(this, open, close);
          } catch (e0) {}
        },

        txtExec: function () {
          try {
            afAeInsertOpenClose(this, open, close);
          } catch (e1) {}
        },

        __afAeHardMybbAlias: true
      });
    }

    // ВОТ ОН — [hr] в sourceMode должен работать идеально
    hard('horizontalrule', '[hr]\n', '');

    // остальные алиасы
    hard('subscript', '[sub]', '[/sub]');
    hard('superscript', '[sup]', '[/sup]');

    hard('af_Subscript', '[sub]', '[/sub]');
    hard('af_Superscript', '[sup]', '[/sup]');

    hard('af_subscript', '[sub]', '[/sub]');
    hard('af_superscript', '[sup]', '[/sup]');
  }

  function ensureCustomCommands() {
    if (!hasSceditor()) return;

    var defs = buildCustomDefMap();

    Object.keys(defs).forEach(function (cmd) {
      var b = defs[cmd] || {};
      var handler = asText(b.handler).trim();
      var openTag = asText(b.opentag);
      var closeTag = asText(b.closetag);

      var title = asText(b.title || b.name || cmd).trim();
      var tooltip = title || cmd;

      // dropdown команды отдельно
      if (/^af_menu_dropdown\d+$/i.test(cmd)) return;

      // toggle mode отдельно
      if (cmd === 'af_togglemode') {
        setCommand(cmd, {
          tooltip: tooltip || 'BBCode / WYSIWYG',
          exec: function () {
            try {
              if (typeof this.toggleSourceMode === 'function') {
                this.toggleSourceMode();
                return;
              }
            } catch (e0) {}
            try {
              if (typeof this.sourceMode === 'function') {
                this.sourceMode(!this.sourceMode());
                return;
              }
            } catch (e1) {}
          },
          txtExec: function () { try { this.exec(); } catch (e2) {} }
        });
        return;
      }

      // если есть pack-handler — оставляем как есть (он сам решит что вставлять)
      function tryHandler(ed) {
        if (!handler) return false;
        try {
          var fn = window['af_ae_' + handler + '_exec'];
          if (typeof fn === 'function') {
            fn(ed, b);
            return true;
          }
        } catch (e0) {}
        return false;
      }

      function insertInSource(ed) {
        // sourceMode: вставляем РЕАЛЬНЫЙ BBCode (MyBB MyCode)
        try {
          if (ed && typeof ed.insertText === 'function') {
            ed.insertText(String(openTag || '') + String(closeTag || ''));
            return true;
          }
        } catch (e0) {}

        try {
          if (ed && typeof ed.insert === 'function') {
            ed.insert(openTag, closeTag);
            return true;
          }
        } catch (e1) {}

        try {
          if (ed && typeof ed.val === 'function') {
            var cur = ed.val();
            ed.val(String(cur || '') + String(openTag || '') + String(closeTag || ''));
            return true;
          }
        } catch (e2) {}

        return false;
      }

      function insertInWysiwyg(ed) {
        // ВАЖНО: в формате bbcode editor.insert() ожидает BBCode, а не HTML.
        // Поэтому в WYSIWYG тоже вставляем BBCode.
        try {
          if (ed && typeof ed.insert === 'function') {
            ed.insert(openTag, closeTag);
            return true;
          }
        } catch (e0) {}

        // fallback: если insert внезапно недоступен — вставим как текст
        try {
          if (ed && typeof ed.insertText === 'function') {
            ed.insertText(String(openTag || '') + String(closeTag || ''));
            return true;
          }
        } catch (e1) {}

        return false;
      }

      setCommand(cmd, {
        tooltip: tooltip,

        exec: function () {
          var ed = this;
          try {
            // handler имеет приоритет
            if (tryHandler(ed)) return;

            if (afAeIsSourceMode(ed)) {
              insertInSource(ed);
              return;
            }

            insertInWysiwyg(ed);
          } catch (e0) {}
        },

        txtExec: function () {
          var ed = this;
          try {
            if (tryHandler(ed)) return;
            insertInSource(ed);
          } catch (e1) {}
        }
      });
    });
  }

  function afAeEnsureMybbAlignBbcode(inst) {
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
      if (window.__afAeAlignBbPatchRetrying) return;
      window.__afAeAlignBbPatchRetrying = true;

      var t = 0;
      (function retry() {
        t++;
        var b2 = getBb();
        if (b2) {
          window.__afAeAlignBbPatchRetrying = false;
          try { afAeEnsureMybbAlignBbcode(inst); } catch (e2) {}
          return;
        }
        if (t < 25) return setTimeout(retry, 120);
        window.__afAeAlignBbPatchRetrying = false;
      })();
      return;
    }

    if (bb.__afAeMybbAlignPatched) return;
    bb.__afAeMybbAlignPatched = true;

    // =========================
    // ALIGN: MyBB style [align=...]
    // =========================

    try {
      bb.set('align', {
        isInline: false,
        html: function (token, attrs, content) {
          var a = '';
          try { a = (attrs && attrs.defaultattr != null) ? String(attrs.defaultattr) : ''; } catch (e0) { a = ''; }
          a = (a || '').toLowerCase().trim();

          if (a === 'start') a = 'left';
          if (a === 'end') a = 'right';
          if (a !== 'left' && a !== 'center' && a !== 'right' && a !== 'justify') a = 'left';

          return '<div style="text-align:' + a + '">' + content + '</div>';
        },
        format: function (el, content) {
          try {
            var a = '';
            try { a = (el && el.style && el.style.textAlign) ? String(el.style.textAlign) : ''; } catch (e0) { a = ''; }
            a = (a || '').toLowerCase().trim();

            if (a === 'start') a = 'left';
            if (a === 'end') a = 'right';
            if (a !== 'left' && a !== 'center' && a !== 'right' && a !== 'justify') return content;

            return '[align=' + a + ']' + content + '[/align]';
          } catch (e1) {
            return '[align=left]' + content + '[/align]';
          }
        }
      });
    } catch (eA0) {}

    function patchLegacyAlign(tag, val) {
      try {
        bb.set(tag, {
          isInline: false,
          html: '<div style="text-align:' + val + '">{0}</div>',
          format: '[align=' + val + ']{0}[/align]'
        });
      } catch (e) {}
    }

    patchLegacyAlign('left', 'left');
    patchLegacyAlign('center', 'center');
    patchLegacyAlign('right', 'right');
    patchLegacyAlign('justify', 'justify');

    try {
      bb.set('div', {
        styles: { 'text-align': 'align' },
        format: function (el, content) {
          try {
            var a = '';
            try { a = (el && el.style && el.style.textAlign) ? String(el.style.textAlign) : ''; } catch (e0) { a = ''; }
            a = a.toLowerCase().trim();

            if (a === 'start') a = 'left';
            if (a === 'end') a = 'right';
            if (a !== 'left' && a !== 'center' && a !== 'right' && a !== 'justify') return content;

            return '[align=' + a + ']' + content + '[/align]';
          } catch (e1) {
            return '[align=left]' + content + '[/align]';
          }
        },
        html: function (token, attrs, content) {
          var a = (attrs && attrs.defaultattr) ? String(attrs.defaultattr) : '';
          a = a.toLowerCase().trim();
          if (a !== 'left' && a !== 'center' && a !== 'right' && a !== 'justify') a = 'left';
          return '<div style="text-align:' + a + '">' + content + '</div>';
        }
      });
    } catch (eD) {}
  }

  function afAePatchAlignCommandsForSourceMode() {
    // теперь это просто “вызов жёсткого режима”
    try { afAeForceAlignEverywhere(null); } catch (e) {}
  }

  function ensureDefaultSourceMode(inst) {
    if (!inst) return;
    var inSource = false;
    try {
      if (typeof inst.sourceMode === 'function') inSource = !!inst.sourceMode();
      else if (typeof inst.isSourceMode === 'function') inSource = !!inst.isSourceMode();
      else if (typeof inst.sourceMode === 'boolean') inSource = inst.sourceMode;
    } catch (e0) { inSource = false; }

    if (!inSource && typeof inst.toggleSourceMode === 'function') {
      try { inst.toggleSourceMode(); } catch (e1) {}
    }
  }

  function svgStarMarkup() {
    return '' +
      '<svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
      '<path d="M12 17.3l-6.18 3.73 1.64-7.03L2 9.24l7.19-.62L12 2l2.81 6.62 7.19.62-5.46 4.76 1.64 7.03z"></path>' +
      '</svg>';
  }

  function resolveCaller(a, b) {
    // DOM
    if (a && a.nodeType === 1) return a;
    if (b && b.nodeType === 1) return b;

    // jQuery object
    if (a && a.jquery && a[0] && a[0].nodeType === 1) return a[0];
    if (b && b.jquery && b[0] && b[0].nodeType === 1) return b[0];

    // event -> currentTarget/target
    if (a && a.currentTarget && a.currentTarget.nodeType === 1) return a.currentTarget;
    if (b && b.currentTarget && b.currentTarget.nodeType === 1) return b.currentTarget;

    if (a && a.target && a.target.nodeType === 1) return a.target;
    if (b && b.target && b.target.nodeType === 1) return b.target;

    return null;
  }

  function openDropdown(editor, caller, menu, availableMap) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;

    // caller должен быть DOM-элементом (как в floatbb)
    var el = resolveCaller(caller, null);

    // Если кликнули по svg/div внутри кнопки — поднимаемся к <a>
    try {
      if (el && el.nodeType === 1 && el.closest) {
        var a = el.closest('a.sceditor-button');
        if (a) el = a;
      }
    } catch (e0) {}

    if (!el || el.nodeType !== 1) return false;

    // Закрываем предыдущий dropdown
    try { if (typeof editor.closeDropDown === 'function') editor.closeDropDown(true); } catch (e1) {}

    var wrap = null;
    try { wrap = buildDropdownContent(editor, menu, availableMap); } catch (e2) { wrap = null; }
    if (!wrap) return false;

    // ВАЖНО: ровно как ты показала:
    // sceditor-dropdown sceditor-sceditor-af_menu_dropdown1-picker
    var id = 'sceditor-sceditor-' + String(menu && menu.cmd ? menu.cmd : 'af_menu_dropdown') + '-picker';

    try {
      editor.createDropDown(el, id, wrap);
      return true;
    } catch (e3) {
      // Фоллбек: иногда сборки хотят jQuery(caller)
      try {
        if (window.jQuery) {
          editor.createDropDown(window.jQuery(el), id, wrap);
          return true;
        }
      } catch (e4) {}
    }

    return false;
  }

  function buildDropdownContent(editor, menu, availableMap) {
    var wrap = document.createElement('div');
    wrap.className = 'af-ae-dd';

    var customDefs = null;
    try { customDefs = buildCustomDefMap(); } catch (e0) { customDefs = Object.create(null); }

    function addSeparator() {
      var sep = document.createElement('div');
      sep.className = 'sceditor-separator';
      wrap.appendChild(sep);
    }

    function isVisibleEl(el) {
      try {
        if (!el || el.nodeType !== 1) return false;
        if (el.disabled) return false;
        if (el.offsetParent === null && el.getClientRects().length === 0) return false;
        return true;
      } catch (e) { return false; }
    }

    function findToolbarCallerForCmd(ed, cmd) {
      cmd = String(cmd || '').trim();
      if (!cmd) return null;

      var sel = 'a.sceditor-button-' + cmd;

      try {
        var cont = null;
        try { cont = (ed && typeof ed.getContainer === 'function') ? ed.getContainer() : null; } catch (e0) { cont = null; }
        if (!cont && ed && ed.container && ed.container.nodeType === 1) cont = ed.container;

        if (cont && cont.querySelector) {
          var a1 = cont.querySelector(sel);
          if (isVisibleEl(a1)) return a1;

          var near = cont.closest ? cont.closest('.sceditor-container') : null;
          if (near && near.querySelector) {
            var a2 = near.querySelector(sel);
            if (isVisibleEl(a2)) return a2;
          }
        }
      } catch (e1) {}

      try {
        var all = document.querySelectorAll(sel);
        if (all && all.length) {
          for (var i = all.length - 1; i >= 0; i--) {
            if (isVisibleEl(all[i])) return all[i];
          }
          return all[0] || null;
        }
      } catch (e2) {}

      return null;
    }

    function getDefByCmdAnyCase(map, cmd) {
      if (!map || !cmd) return null;
      if (map[cmd]) return map[cmd];

      // кейс-инсенситив
      var low = String(cmd).toLowerCase();
      if (map[low]) return map[low];

      // перебор ключей (редко, но безопасно)
      try {
        var keys = Object.keys(map);
        for (var i = 0; i < keys.length; i++) {
          if (String(keys[i]).toLowerCase() === low) return map[keys[i]];
        }
      } catch (e) {}
      return null;
    }

    // ---- глобальный резолвер "cmd -> {open,close}" ----
    function resolveInsertForCmd(cmd) {
      cmd = String(cmd || '').trim();
      var low = cmd.toLowerCase();

      // 1) если есть opentag/closetag в availableMap
      try {
        var b1 = getDefByCmdAnyCase(availableMap, cmd);
        if (b1) {
          var ot1 = String(b1.opentag || b1.openTag || b1.open || '').trim();
          var ct1 = String(b1.closetag || b1.closeTag || b1.close || '').trim();
          if (ot1 || ct1) return { open: ot1, close: ct1 };
        }
      } catch (e1) {}

      // 2) если есть opentag/closetag в customDefs
      try {
        var b2 = getDefByCmdAnyCase(customDefs, cmd);
        if (b2) {
          var ot2 = String(b2.opentag || b2.openTag || b2.open || '').trim();
          var ct2 = String(b2.closetag || b2.closeTag || b2.close || '').trim();
          if (ot2 || ct2) return { open: ot2, close: ct2 };
        }
      } catch (e2) {}

      // 3) алиасы команд -> MyBB теги (глобально)
      // сюда добавляешь любые “несовпадающие” команды
      var ALIAS = {
        horizontalrule: { open: '[hr]\n', close: '' },

        subscript: { open: '[sub]', close: '[/sub]' },
        superscript: { open: '[sup]', close: '[/sup]' },

        // твои кастомные имена
        af_subscript: { open: '[sub]', close: '[/sub]' },
        af_superscript: { open: '[sup]', close: '[/sup]' },

      };

      if (ALIAS[low]) return ALIAS[low];

      // 4) общий фоллбек
      // Для стандартных команд лучше ничего не делать, чем гадить мусором.
      if (getCommand(cmd)) {
        return { open: '', close: '' };
      }
      return { open: '[' + cmd + ']', close: '[/' + cmd + ']' };

    }

    function execCmd(cmd, callerEl) {
      cmd = String(cmd || '').trim();
      if (!cmd) return;

      if (/^af_menu_dropdown\d+$/i.test(cmd)) return;

      try { if (editor && typeof editor.focus === 'function') editor.focus(); } catch (e0) {}

      var caller = findToolbarCallerForCmd(editor, cmd) || callerEl || null;

      // 1) НОРМАЛЬНЫЙ штатный запуск SCEditor: ВАЖНО — БЕЗ .call(editor,...)
      try {
        if (editor && editor.command && typeof editor.command.exec === 'function') {
          editor.command.exec(cmd, caller);
          return;
        }
      } catch (e1) {}

      // 2) Фоллбек №1: дергаем definition команды напрямую (если она есть)
      try {
        var def = getCommand(cmd);
        if (def) {
          var isSrc = false;
          try { isSrc = afAeIsSourceMode(editor); } catch (e2a) { isSrc = false; }

          if (isSrc && typeof def.txtExec === 'function') {
            def.txtExec.call(editor, caller);
            return;
          }
          if (typeof def.exec === 'function') {
            def.exec.call(editor, caller);
            return;
          }
        }
      } catch (e2) {}

      // 3) Фоллбек №2: ВСТАВЛЯЕМ ТОЛЬКО если это реально "неизвестная" команда.
      // Сначала пытаемся получить реальные open/close из available/customDefs/alias.
      var ins = resolveInsertForCmd(cmd);

      try {
        // важно: sourceMode -> вставляем в source textarea по курсору
        if (editor) {
          if (afAeInsertOpenClose(editor, ins.open || '', ins.close || '')) return;
        }
      } catch (e3) {}


      try {
        if (editor && typeof editor.val === 'function') {
          var cur = editor.val();
          editor.val(String(cur || '') + String(ins.open || '') + String(ins.close || ''));
        }
      } catch (e4) {}
    }

    function addItem(cmd, titleText) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'af-ae-dd-item';
      btn.setAttribute('data-cmd', String(cmd || ''));
      btn.textContent = String(titleText || cmd || '').trim() || cmd;

      btn.addEventListener('click', function (ev) {
        try { ev.preventDefault(); } catch (e0) {}

        execCmd(cmd, btn);

        try { if (editor && typeof editor.closeDropDown === 'function') editor.closeDropDown(true); } catch (e1) {}
      });

      wrap.appendChild(btn);
    }

    function expandItems(arr) {
      var out = [];
      (arr || []).forEach(function (raw) {
        raw = String(raw || '').trim();
        if (!raw) return;

        if (raw === '|') { out.push('|'); return; }

        if (raw.indexOf(',') !== -1) {
          raw.split(',').forEach(function (p) {
            p = String(p || '').trim();
            if (p) out.push(p);
          });
          return;
        }

        out.push(raw);
      });
      return out;
    }

    var items0 = (menu && Array.isArray(menu.items)) ? menu.items : [];
    var items = expandItems(items0);

    var hasAny = false;

    for (var i = 0; i < items.length; i++) {
      var cmd = String(items[i] || '').trim();
      if (!cmd) continue;

      if (cmd === '|') { addSeparator(); continue; }
      if (/^af_menu_dropdown\d+$/i.test(cmd)) continue;

      var b = (availableMap && availableMap[cmd]) ? availableMap[cmd] : null;
      var title = b ? (b.title || b.name || cmd) : cmd;

      addItem(cmd, title);
      hasAny = true;
    }

    if (!hasAny) {
      var empty = document.createElement('div');
      empty.className = 'smalltext';
      empty.style.padding = '6px 2px';
      empty.textContent = 'Пустое меню';
      wrap.appendChild(empty);
    }

    return wrap;
  }

  function ensureDropdownCommands(out, availableMap) {
    if (!hasSceditor()) return;
    if (!out || !Array.isArray(out.menus) || !out.menus.length) return;

    out.menus.forEach(function (m) {
      if (!m || !m.cmd) return;

      // уже патчили — не трогаем
      try {
        var ex = getCommand(m.cmd);
        if (ex && ex.__afAeDropdownPatched) return;
      } catch (eX) {}

      setCommand(m.cmd, {
        tooltip: asText(m.title || '★'),

        // SCEditor иногда дергает dropDown, иногда exec/txtExec — дадим всё
        dropDown: function (caller) {
          openDropdown(this, caller, m, availableMap);
        },
        exec: function (caller) {
          openDropdown(this, caller, m, availableMap);
        },
        txtExec: function (caller) {
          openDropdown(this, caller, m, availableMap);
        },

        __afAeDropdownPatched: true
      });
    });
  }

  function decorateDropdownButtons(ta, out) {
    try {
      var cont = ta.previousElementSibling;
      var tb = cont ? cont.querySelector('.sceditor-toolbar') : null;
      if (!tb || !out || !Array.isArray(out.menus)) return;

      function parseRgb(s) {
        s = String(s || '').trim();
        var m = s.match(/rgba?\(\s*([0-9.]+)\s*,\s*([0-9.]+)\s*,\s*([0-9.]+)(?:\s*,\s*([0-9.]+))?\s*\)/i);
        if (m) return { r: +m[1], g: +m[2], b: +m[3], a: (m[4] == null ? 1 : +m[4]) };
        return null;
      }
      function isTransparentRgb(x) { return !x || (typeof x.a === 'number' && x.a <= 0.02); }
      function getBg(el) {
        var cur = el;
        for (var i = 0; i < 8 && cur; i++) {
          var cs = null;
          try { cs = getComputedStyle(cur); } catch (e) { cs = null; }
          if (cs) {
            var bg = parseRgb(cs.backgroundColor);
            if (bg && !isTransparentRgb(bg)) return bg;
          }
          cur = cur.parentElement;
        }
        return null;
      }

      // автоцвет иконок (mask) — если уже есть, не трогаем
      try {
        var already = getComputedStyle(tb).getPropertyValue('--af-ae-icon-color');
        if (!String(already || '').trim()) {
          var bgc = getBg(tb) || getBg(cont) || getBg(document.body);
          var r = bgc ? bgc.r : 255, g = bgc ? bgc.g : 255, b = bgc ? bgc.b : 255;
          var lum = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;
          var chosen = (lum < 0.48) ? 'rgba(255,255,255,.92)' : 'rgba(0,0,0,.72)';
          tb.style.setProperty('--af-ae-icon-color', chosen);
        }
      } catch (eCol) {}

      function isUrl(x) {
        x = String(x || '').trim();
        return /^(https?:)?\/\//i.test(x) || x.startsWith('/');
      }

      function isSvg(x) {
        x = String(x || '').trim();
        if (!x) return false;
        if (!(x.startsWith('<svg') && x.includes('</svg>'))) return false;
        var low = x.toLowerCase();
        if (low.includes('<script') || low.includes('onload=') || low.includes('onerror=')) return false;
        return true;
      }

      function looksLikeSvgUrl(u) {
        u = String(u || '').trim().toLowerCase();
        return u.includes('.svg') || u.startsWith('data:image/svg');
      }

      function titleSpec(t) {
        t = String(t || '').trim();
        if (isSvg(t)) return { kind: 'svg', value: t };
        if (isUrl(t)) return { kind: 'url', value: t };
        if (t) return { kind: 'text', value: t };
        return { kind: 'svg', value: svgStarMarkup() };
      }

      function applyUrlIcon(el, url) {
        url = String(url || '').trim();
        if (!url) return;

        el.style.backgroundImage = 'none';
        el.style.webkitMaskImage = 'none';
        el.style.maskImage = 'none';
        el.style.backgroundColor = '';

        if (looksLikeSvgUrl(url)) {
          el.style.webkitMaskImage = 'url("' + url.replace(/"/g, '\\"') + '")';
          el.style.maskImage = 'url("' + url.replace(/"/g, '\\"') + '")';
          el.style.webkitMaskRepeat = 'no-repeat';
          el.style.maskRepeat = 'no-repeat';
          el.style.webkitMaskPosition = 'center';
          el.style.maskPosition = 'center';
          el.style.webkitMaskSize = '16px 16px';
          el.style.maskSize = '16px 16px';
          el.style.backgroundColor = 'var(--af-ae-icon-color, currentColor)';
        } else {
          el.style.backgroundImage = 'url("' + url.replace(/"/g, '\\"') + '")';
          el.style.backgroundRepeat = 'no-repeat';
          el.style.backgroundPosition = 'center';
          el.style.backgroundSize = '16px 16px';
        }
      }

      out.menus.forEach(function (m) {
        var a = tb.querySelector('a.sceditor-button-' + m.cmd);
        if (!a) return;

        // чтобы currentColor работал
        try { a.style.color = 'var(--af-ae-icon-color, currentColor)'; } catch (e0) {}

        var d = a.querySelector('div');
        if (!d) return;

        var spec = titleSpec(m.title);

        d.innerHTML = '';
        d.textContent = '';
        d.style.backgroundImage = 'none';
        d.style.textIndent = '0';

        d.style.display = 'flex';
        d.style.alignItems = 'center';
        d.style.justifyContent = 'center';
        d.style.height = '16px';
        d.style.lineHeight = '16px';
        d.style.padding = '0';

        d.style.width = '16px';
        a.style.width = '';
        a.style.minWidth = '';
        a.style.padding = '';

        if (spec.kind === 'url') {
          applyUrlIcon(d, spec.value);
        } else if (spec.kind === 'svg') {
          d.innerHTML = spec.value;
        } else {
          d.textContent = String(spec.value).trim();
          d.style.fontSize = '12px';
          d.style.fontWeight = '700';

          d.style.width = 'auto';
          d.style.padding = '0 6px';

          a.style.width = 'auto';
          a.style.minWidth = '16px';
          a.style.padding = '0 2px';
        }
      });
    } catch (e) {}
  }

  function afAeEnsureFrontendCodeCss() {
    if (window.__afAeFrontendCodeCssDone) return;
    window.__afAeFrontendCodeCssDone = true;
  }

  function afAeApplyWysiwygLocalFontsCss(inst) {
    try {
      if (!inst || typeof inst.getBody !== 'function') return;

      var tries = 0;
      (function tick() {
        tries++;

        var body = null;
        try { body = inst.getBody(); } catch (e0) { body = null; }
        if (!body || !body.ownerDocument) {
          if (tries < 40) return setTimeout(tick, 50);
          return;
        }

        var doc = body.ownerDocument;
        var head = doc.head || doc.getElementsByTagName('head')[0];
        if (!head) {
          if (tries < 40) return setTimeout(tick, 50);
          return;
        }

        if (doc.getElementById('af-ae-local-fonts-iframe')) return;

        var hostStyle = document.getElementById('af-ae-local-fonts');
        if (!hostStyle) return;

        var cssText = hostStyle.textContent || hostStyle.innerText || '';
        if (!cssText) return;

        var st = doc.createElement('style');
        st.id = 'af-ae-local-fonts-iframe';
        st.type = 'text/css';
        st.appendChild(doc.createTextNode(cssText));
        head.appendChild(st);
      })();
    } catch (e) {}
  }

  function afAeApplyWysiwygCodeQuoteCss(inst) {
    try {
      if (!inst || typeof inst.getBody !== 'function') return;

      // Ретраи: iframe может появиться чуть позже
      var tries = 0;

      (function tick() {
        tries++;

        var body = null;
        try { body = inst.getBody(); } catch (e0) { body = null; }
        if (!body || !body.ownerDocument) {
          if (tries < 40) return setTimeout(tick, 50);
          return;
        }

        var doc = body.ownerDocument;
        var head = doc.head || doc.getElementsByTagName('head')[0];
        if (!head) {
          if (tries < 40) return setTimeout(tick, 50);
          return;
        }

        // Если iframe пересоздался — стиль надо вставлять заново (в новом документе)
        if (doc.getElementById('af-ae-wysiwyg-codequote')) return;

        // CSS именно для WYSIWYG-iframe: blockquote + pre/code + общий читабельный вид
        var css =
          "blockquote{padding:10px 12px;margin:10px 0;border-left:4px solid rgba(255,255,255,.15);background:rgba(0,0,0,.18);}\n" +
          "blockquote cite{display:block;opacity:.75;font-style:normal;margin-bottom:6px;}\n" +

          // inline code
          "code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;" +
          "padding:.12em .35em;border-radius:6px;background:rgba(0,0,0,.22);border:1px solid rgba(255,255,255,.10);}\n" +

          // pre/code blocks
          "pre,pre code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;}\n" +
          "pre{padding:10px 12px;margin:10px 0;background:rgba(0,0,0,.28);" +
          "border:1px solid rgba(255,255,255,.12);border-radius:10px;overflow:auto;white-space:pre;}\n" +
          "pre code{background:transparent;border:0;padding:0;}\n" +

          // если в WYSIWYG вдруг попадает mybb-ish разметка codeblock
          ".codeblock{margin:12px 0;border:1px solid rgba(255,255,255,.12);border-radius:10px;overflow:hidden;background:rgba(0,0,0,.18)}\n" +
          ".codeblock .title{padding:8px 10px;font-weight:700;opacity:.85;border-bottom:1px solid rgba(255,255,255,.10)}\n" +
          ".codeblock .body{padding:10px 12px}\n";

        var style = doc.createElement('style');
        style.id = 'af-ae-wysiwyg-codequote';
        style.type = 'text/css';
        style.appendChild(doc.createTextNode(css));
        head.appendChild(style);
      })();
    } catch (e) {}
  }

  function decorateCustomButtons(ta) {
    try {
      var cont = ta.previousElementSibling;
      var tb = cont ? cont.querySelector('.sceditor-toolbar') : null;
      if (!tb) return;

      var customDefs = buildCustomDefMap();

      function asText(x) { return String(x == null ? '' : x); }

      function guessBburl() {
        var bburl = '';
        try { bburl = asText((CFG && CFG.bburl) ? CFG.bburl : '').replace(/\/+$/, ''); } catch (e0) { bburl = ''; }
        if (bburl) return bburl;

        try {
          if (window.MyBB && window.MyBB.settings && window.MyBB.settings.bburl) {
            bburl = asText(window.MyBB.settings.bburl).replace(/\/+$/, '');
            if (bburl) return bburl;
          }
        } catch (e1) {}

        try { return (location && location.origin) ? String(location.origin) : ''; } catch (e2) {}
        return '';
      }

      function getAssetsBaseUrl() {
        var bburl = guessBburl();
        if (!bburl) return '';
        return bburl + '/inc/plugins/advancedfunctionality/addons/advancededitor/assets/';
      }

      function isSvgMarkupSafe(x) {
        x = String(x || '').trim();
        if (!x) return false;
        if (!(x.startsWith('<svg') && x.includes('</svg>'))) return false;
        var low = x.toLowerCase();
        if (low.includes('<script') || low.includes('onload=') || low.includes('onerror=')) return false;
        return true;
      }

      function looksLikeSvgUrl(u) {
        u = String(u || '').trim().toLowerCase();
        return u.includes('.svg') || u.startsWith('data:image/svg');
      }

      function resolveIconUrl(icon) {
        icon = String(icon || '').trim();
        if (!icon) return '';

        // уже абсолютный
        if (/^(https?:)?\/\//i.test(icon) || icon.startsWith('/') || icon.startsWith('data:')) return icon;

        // защита от "assets/assets/..."
        if (icon.startsWith('assets/')) icon = icon.slice('assets/'.length);

        var base = getAssetsBaseUrl(); // bburl + '/inc/plugins/.../assets/'
        if (!base) return icon;

        icon = icon.replace(/^\.?\//, '');
        return base + icon;
      }

      function applyMaskIcon(el, url) {
        url = resolveIconUrl(url);
        if (!url) return;

        // сброс любых старых спрайтов SCEditor
        el.style.backgroundImage = 'none';
        el.style.backgroundRepeat = '';
        el.style.backgroundPosition = '';
        el.style.backgroundSize = '';

        el.style.webkitMaskImage = 'url("' + url.replace(/"/g, '\\"') + '")';
        el.style.maskImage = 'url("' + url.replace(/"/g, '\\"') + '")';
        el.style.webkitMaskRepeat = 'no-repeat';
        el.style.maskRepeat = 'no-repeat';
        el.style.webkitMaskPosition = 'center';
        el.style.maskPosition = 'center';
        el.style.webkitMaskSize = '16px 16px';
        el.style.maskSize = '16px 16px';

        // ВАЖНО: цвет иконки именно через background-color при mask
        el.style.backgroundColor = 'var(--af-ae-icon-color, currentColor)';
      }

      Object.keys(customDefs).forEach(function (cmd) {
        if (!cmd || !/^af_/i.test(cmd)) return;

        var a = tb.querySelector('a.sceditor-button-' + cmd);
        if (!a) return;

        var b = customDefs[cmd] || {};
        var t = String(b.title || b.hint || cmd).trim();
        if (t) {
          try { a.setAttribute('title', t); } catch (e0) {}
        }

        var d = a.querySelector('div');
        if (!d) return;

        // базовая геометрия: если div “нулевой”, маска тоже “не видна”
        d.style.display = 'flex';
        d.style.alignItems = 'center';
        d.style.justifyContent = 'center';
        d.style.width = '16px';
        d.style.height = '16px';
        d.style.lineHeight = '16px';
        d.style.padding = '0';

        // жёсткий сброс
        d.innerHTML = '';
        d.textContent = '';
        d.style.backgroundImage = 'none';
        d.style.webkitMaskImage = 'none';
        d.style.maskImage = 'none';
        d.style.backgroundColor = '';

        var icon = String(b.icon || '').trim();

        // SVG markup
        if (icon && isSvgMarkupSafe(icon)) {
          d.innerHTML = icon;
          return;
        }

        // URL/path
        if (icon) {
          // svg -> mask, raster -> background-image
          if (looksLikeSvgUrl(icon)) {
            applyMaskIcon(d, icon);
            return;
          }

          // растровая картинка
          var u = resolveIconUrl(icon);
          if (u) {
            d.style.backgroundImage = 'url("' + u.replace(/"/g, '\\"') + '")';
            d.style.backgroundRepeat = 'no-repeat';
            d.style.backgroundPosition = 'center';
            d.style.backgroundSize = '16px 16px';
            return;
          }
        }

        // fallback label
        var label = String(b.label || 'AE').trim();
        d.textContent = label || 'AE';
        d.style.fontSize = '11px';
        d.style.fontWeight = '800';
      });
    } catch (e) {}
  }

  function safeGetInstance($ta) {
    try { return $ta.sceditor('instance'); } catch (e) { return null; }
  }

  function updateOriginal(inst) {
    if (!inst) return;
    try { inst.updateOriginal(); } catch (e) {}
  }

  function normalizeLegacyAlignBbcode(s) {
    s = String(s == null ? '' : s);

    // парные
    s = s.replace(/\[left\]([\s\S]*?)\[\/left\]/gi, '[align=left]$1[/align]');
    s = s.replace(/\[center\]([\s\S]*?)\[\/center\]/gi, '[align=center]$1[/align]');
    s = s.replace(/\[right\]([\s\S]*?)\[\/right\]/gi, '[align=right]$1[/align]');
    s = s.replace(/\[justify\]([\s\S]*?)\[\/justify\]/gi, '[align=justify]$1[/align]');

    // одиночные/кривые
    s = s.replace(/\[(\/?)left\]/gi, '[$1align=left]').replace(/\[\/align=left\]/gi, '[/align]');
    s = s.replace(/\[(\/?)center\]/gi, '[$1align=center]').replace(/\[\/align=center\]/gi, '[/align]');
    s = s.replace(/\[(\/?)right\]/gi, '[$1align=right]').replace(/\[\/align=right\]/gi, '[/align]');
    s = s.replace(/\[(\/?)justify\]/gi, '[$1align=justify]').replace(/\[\/align=justify\]/gi, '[/align]');

    return s;
  }

  function afAeNormalizeAlignInInstance(inst) {
    if (!inst || inst.__afAeAlignNormalizing) return;
    inst.__afAeAlignNormalizing = true;

    try {
      if (typeof inst.val === 'function') {
        var v = inst.val();
        var nv = normalizeLegacyAlignBbcode(v);
        if (nv !== v) {
          // важно: задаём обратно — чтобы изменения были В РЕДАКТОРЕ, а не только в textarea перед submit
          inst.val(nv);
        }
      }
      try { if (typeof inst.updateOriginal === 'function') inst.updateOriginal(); } catch (e0) {}
    } catch (e1) {
      // no-op
    } finally {
      inst.__afAeAlignNormalizing = false;
    }
  }

  function bindSubmitSync(form, inst, ta) {
    if (!form || !inst || !ta) return;

    var key = '__afAeSubmitBound_' + (ta.name || ta.id || 'message');
    if (form[key]) return;
    form[key] = true;

    function syncNow() {
      try {
        // 1) стандартный метод SCEditor
        try { if (typeof inst.updateOriginal === 'function') inst.updateOriginal(); } catch (e0) {}

        // 2) железный фоллбек: значение из инстанса в textarea
        try {
          if (typeof inst.val === 'function') {
            var v = inst.val();
            if (typeof v === 'string') {
              v = normalizeLegacyAlignBbcode(v);
              ta.value = v;
            }
          } else {
            // если inst.val нет — хотя бы textarea прогоняем
            ta.value = normalizeLegacyAlignBbcode(ta.value);
          }
        } catch (e1) {
          try { ta.value = normalizeLegacyAlignBbcode(ta.value); } catch (e2) {}
        }
      } catch (e3) {}
    }

    form.addEventListener('submit', function () {
      syncNow();
    }, true);

    form.addEventListener('click', function (e) {
      try {
        var t = e.target;
        if (!t) return;
        if (t.tagName === 'BUTTON' || t.tagName === 'INPUT') {
          var type = (t.getAttribute('type') || '').toLowerCase();
          if (type === 'submit') syncNow();
        }
      } catch (e0) {}
    }, true);
  }

  function ensurePostKeyInput(form) {
    if (!form || !P.postKey) return;
    try {
      if (form.querySelector('input[name="my_post_key"]')) return;
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'my_post_key';
      input.value = String(P.postKey || '');
      form.appendChild(input);
    } catch (e) {}
  }

  function patchEditorInstanceForSafeToggle(inst) {
    if (!inst || inst.__afAePatchedToggle) return;
    inst.__afAePatchedToggle = true;

    var origToggle = inst.toggleSourceMode;

    if (typeof origToggle === 'function') {
      inst.toggleSourceMode = function () {
        window.__afAeGlobalToggling++;
        window.__afAeIgnoreMutationsUntil = now() + 1200;

        try { return origToggle.apply(inst, arguments); }
        finally {
          // ВАЖНО: iframe может появиться не мгновенно — даём микропаузу и ретраи внутри функций
          setTimeout(function () {
            try { afAeApplyWysiwygCodeQuoteCss(inst); } catch (e0) {}
            try { afAeApplyWysiwygLocalFontsCss(inst); } catch (e0b) {}

            try { afAePatchAlignCommandsForSourceMode(); } catch (eX) {}
            try { afAeEnsureMybbAlignBbcode(inst); } catch (eY) {}
            try { afAeForceAlignEverywhere(inst); } catch (eZ) {}
            try { afAeNormalizeAlignInInstance(inst); } catch (eN) {}

            window.__afAeIgnoreMutationsUntil = now() + 250;
            window.__afAeGlobalToggling = Math.max(0, (window.__afAeGlobalToggling | 0) - 1);

          }, 30);
        }
      };
    }

    // На старте тоже пинаем: иногда инстанс уже есть, но iframe ещё догружается
    try {
      setTimeout(function () {
        try { afAeNormalizeAlignInInstance(inst); } catch (eN2) {}
        try { afAeApplyWysiwygCodeQuoteCss(inst); } catch (e1) {}
        try { afAeApplyWysiwygLocalFontsCss(inst); } catch (e1b) {}
      }, 30);
    } catch (e2) {}
  }

  function initOneTextarea(ta) {
    if (!isEligibleTextarea(ta)) return false;
    if (isHidden(ta)) return false;

    if (ta.__afAeInited) return true;
    if (now() < (window.__afAeIgnoreMutationsUntil || 0)) return false;
    if ((window.__afAeGlobalToggling || 0) > 0) return false;

    if (!hasSceditor()) return false;

    var $ = window.jQuery;
    var $ta = $(ta);

    // === ВОТ ТУТ И ДОЛЖНО БЫТЬ ===
    var layout = sanitizeLayout(P.layout || null);
    var availableMap = buildAvailableMap();
    var out = buildToolbarFromLayout(layout);

    var existing = safeGetInstance($ta);
    if (existing) {
      ta.__afAeInited = true;
      existing.__afAeOwned = true;

      // dropdown-меню (afmenu_*) — чтобы кнопки точно были “живые”
      try { ensureDropdownCommands(out, availableMap); } catch (eD0) {}
      // bbcode passthrough для MyCode-тегов (WYSIWYG <-> BBCode) — НА ИМЕННО ЭТОМ инстансе
      try { afAeEnsureMycodePassthroughBbcode(existing); } catch (eM0) {}

      // остальные кастомные команды (af_*)
      try { ensureCustomCommands(); } catch (eC0) {}
      try { ensureToggleCommandDefinition(); } catch (eT0) {}
      try { ensureMybbTagAliases(); } catch (eTA0) {}


      try { afAeEnsureMybbAlignBbcode(existing); } catch (eA0) {}
      try { afAePatchAlignCommandsForSourceMode(); } catch (eA0b) {}
      try { afAeEnsureFrontendCodeCss(); } catch (eA1) {}
      try { afAeForceAlignEverywhere(existing); } catch (eAA) {}

      try { patchEditorInstanceForSafeToggle(existing); } catch (e0) {}
      try { ensureDefaultSourceMode(existing); } catch (e0b) {}
      try { bindSubmitSync(ta.form, existing, ta); } catch (e1) {}

      try { afAeApplyWysiwygCodeQuoteCss(existing); } catch (e2) {}
      try { afAeApplyWysiwygLocalFontsCss(existing); } catch (e2b) {}

      try {
        decorateDropdownButtons(ta, out);
        decorateCustomButtons(ta);
      } catch (e3) {}

      return true;
    }

    try {
      // регаем всё ДО создания инстанса
      ensurePostKeyInput(ta.form);

      try { ensureDropdownCommands(out, availableMap); } catch (eD1) {}
      try { ensureCustomCommands(); } catch (eC1) {}
      try { ensureToggleCommandDefinition(); } catch (eT1) {}
      try { ensureMybbTagAliases(); } catch (eTA1) {}


      // Патчи bbcode (до инициализации) — и потом ещё раз после
      try { afAeEnsureMybbAlignBbcode(null); } catch (eP0) {}
      try { afAeForceAlignEverywhere(null); } catch (eX) {}

      $ta.sceditor({
        format: 'bbcode',
        toolbar: out.toolbar,

        // ВАЖНО: WYSIWYG iframe CSS
        style: (P.sceditorContentCss || P.sceditorCss || ''),

        height: 180,
        width: '100%',
        resizeEnabled: true,
        autoExpand: false,
        startInSourceMode: true
      });

      var inst = safeGetInstance($ta);
      if (inst) {
        ta.__afAeInited = true;
        inst.__afAeOwned = true;
        inst.__afAeToolbarSig = asText(out.toolbar);

        // bbcode passthrough для MyCode-тегов (WYSIWYG <-> BBCode)
        try { afAeEnsureMycodePassthroughBbcode(inst); } catch (eM1) {}
        // алиасы MyBB-тегов и команды — один раз глобально, но тут безопасно
        try { ensureMybbTagAliases(); } catch (eTA2) {}
        try { ensureCustomCommands(); } catch (eC2) {}


        try { afAeEnsureMybbAlignBbcode(inst); } catch (eP1) {}
        try { afAePatchAlignCommandsForSourceMode(); } catch (eP1b) {}
        try { afAeEnsureFrontendCodeCss(); } catch (eP2) {}
        try { afAeForceAlignEverywhere(inst); } catch (ePP) {}

        try { patchEditorInstanceForSafeToggle(inst); } catch (e3) {}
        try { ensureDefaultSourceMode(inst); } catch (e3b) {}
        try { bindSubmitSync(ta.form, inst, ta); } catch (e4) {}

        try { afAeApplyWysiwygCodeQuoteCss(inst); } catch (e5) {}
        try { afAeApplyWysiwygLocalFontsCss(inst); } catch (e5b) {}

        decorateDropdownButtons(ta, out);
        decorateCustomButtons(ta);

        return true;
      }
    } catch (e) {
      log('[AE] init error', e);
    }

    return false;
  }

  function getEditorSelector() {
    var sel = (CFG && typeof CFG.editorSelector === 'string') ? CFG.editorSelector : '';
    sel = asText(sel).trim();
    return sel;
  }

  function af_ae_has_editor(root) {
    root = root || document;
    if (!root || !root.querySelector) return false;

    var sel = getEditorSelector();
    if (sel) {
      try {
        if (root.querySelector(sel)) return true;
      } catch (e) {}
    }

    return !!(
      root.querySelector('textarea[name="message"]') ||
      root.querySelector('textarea#message') ||
      root.querySelector('.sceditor-container')
    );
  }

  window.af_ae_has_editor = af_ae_has_editor;
  window.AFAE = window.AFAE || {};
  window.AFAE.hasEditor = function () { return af_ae_has_editor(document); };

  function collectTargets(root) {
    var sel = getEditorSelector();
    if (sel) {
      try { return root.querySelectorAll(sel); } catch (e) {}
    }
    return root.querySelectorAll('textarea');
  }

  function scanAndInit(root) {
    root = root || document;
    if (!root.querySelectorAll) return;

    var list = collectTargets(root);
    for (var i = 0; i < list.length; i++) {
      initOneTextarea(list[i]);
    }
  }

  function observeDynamicEditors() {
    if (!window.MutationObserver) return;
    if (window.__afAeObserverAttached) return;
    window.__afAeObserverAttached = true;

    var obs = new MutationObserver(function (muts) {
      if (now() < (window.__afAeIgnoreMutationsUntil || 0)) return;
      if ((window.__afAeGlobalToggling || 0) > 0) return;

      for (var i = 0; i < muts.length; i++) {
        var m = muts[i];
        if (!m.addedNodes || !m.addedNodes.length) continue;

        for (var j = 0; j < m.addedNodes.length; j++) {
          var n = m.addedNodes[j];
          if (!n || n.nodeType !== 1) continue;

          var selector = getEditorSelector();
          if (selector) {
            if (n.matches && n.matches(selector)) {
              initOneTextarea(n);
            } else if (n.querySelectorAll) {
              var matched = n.querySelectorAll(selector);
              if (matched && matched.length) {
                for (var k = 0; k < matched.length; k++) initOneTextarea(matched[k]);
              }
            }
          } else if (n.tagName === 'TEXTAREA') {
            initOneTextarea(n);
          } else if (n.querySelectorAll) {
            var tas = n.querySelectorAll('textarea');
            if (tas && tas.length) {
              for (var k2 = 0; k2 < tas.length; k2++) initOneTextarea(tas[k2]);
            }
          }
        }
      }
    });

    obs.observe(document.documentElement || document.body, {
      childList: true,
      subtree: true
    });
  }

  function boot() {
    var tries = 0;
    (function wait() {
      tries++;
      if (hasSceditor()) {
        scanAndInit(document);
        observeDynamicEditors();
        return;
      }
      if (tries < 80) return setTimeout(wait, 50);
    })();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

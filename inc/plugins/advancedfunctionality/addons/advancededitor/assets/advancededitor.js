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
              'af_togglemode', 'maximize'
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

  function buildAllowedCmdSet() {
    var s = Object.create(null);
    var list = Array.isArray(P.available) ? P.available : [];
    list.forEach(function (b) {
      if (!b || !b.cmd) return;
      s[String(b.cmd)] = true;
    });
    s['|'] = true;
    return s;
  }

  function ensureToggleCommand(layout) {
    var hasToggle = false;
    (layout.sections || []).forEach(function (sec) {
      if (!sec || !Array.isArray(sec.items)) return;
      sec.items.forEach(function (cmd) {
        cmd = String(cmd || '').trim();
        if (cmd === 'source' || cmd === 'af_togglemode') hasToggle = true;
      });
    });

    if (hasToggle) return layout;

    if (!layout.sections || !layout.sections.length) {
      layout.sections = [{ id: 'main', type: 'group', title: 'Основное', items: [] }];
    }

    var last = layout.sections[layout.sections.length - 1];
    if (!Array.isArray(last.items)) last.items = [];
    if (last.items.length && last.items[last.items.length - 1] !== '|') {
      last.items.push('|');
    }
    last.items.push('af_togglemode');
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
        .map(function (x) { return String(x || '').trim(); })
        .filter(function (cmd) {
          if (!cmd) return false;
          if (cmd === '|') return true;
          if (/^afmenu_/i.test(cmd)) return true;
          return !!allowed[cmd];
        });
    });

    return ensureToggleCommand(lay);
  }

  function buildToolbarFromLayout(lay) {
    var parts = [];
    var menus = [];

    (lay.sections || []).forEach(function (sec, idx) {
      if (!sec) return;

      var type = String(sec.type || 'group').toLowerCase();
      var id = String(sec.id || ('sec' + idx));
      var title = String(sec.title || '');
      var items = Array.isArray(sec.items) ? sec.items.slice() : [];

      if (type === 'dropdown') {
        var cmd = 'afmenu_' + id.replace(/[^a-z0-9_\-]/gi, '_');
        parts.push(cmd);
        menus.push({ id: id, cmd: cmd, title: (title || '★'), items: items.slice() });
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

  function ensureCustomCommands() {
    if (!window.jQuery || !jQuery.sceditor) return;

    ensureToggleCommandDefinition();

    var customDefs = buildCustomDefMap();

    Object.keys(customDefs).forEach(function (cmd) {
      if (!cmd || cmd === '|') return;
      if (!/^af_/i.test(cmd)) return;
      if (getCommand(cmd)) return;

      var defData = customDefs[cmd] || {};
      var handler = String(defData.handler || '').trim();
      var opentag = String(defData.opentag || '');
      var closetag = String(defData.closetag || '');
      var tooltip = String(defData.title || defData.hint || cmd).trim() || cmd;

      var def = {
        tooltip: tooltip,

        exec: function (caller) {
          var ed = this;

          if (cmd === 'af_togglemode') {
            try { if (ed && typeof ed.toggleSourceMode === 'function') ed.toggleSourceMode(); } catch (e0) {}
            return;
          }

          // handler (spoiler.js и др.)
          if (handler) {
            try {
              var h = null;
              if (window.afAeBuiltinHandlers && window.afAeBuiltinHandlers[handler]) h = window.afAeBuiltinHandlers[handler];
              else if (window.afAqrBuiltinHandlers && window.afAqrBuiltinHandlers[handler]) h = window.afAqrBuiltinHandlers[handler];

              if (typeof h === 'function') {
                // КАНОН: отдаём инстанс SCEditor (как ждёт spoiler.js)
                h(ed, caller, cmd, defData);
                return;
              }
              if (h && typeof h.exec === 'function') {
                h.exec(ed, caller, cmd, defData);
                return;
              }
            } catch (e1) {}
          }

          // open/close tags
          try {
            if (opentag && typeof ed.insert === 'function') {
              ed.insert(opentag, closetag || '');
              return;
            }
          } catch (e2) {}

          // fallback
          try { if (typeof ed.insert === 'function') ed.insert('[' + cmd + ']', '[/' + cmd + ']'); } catch (e3) {}
        },

        txtExec: function (caller) {
          try { def.exec.call(this, caller); } catch (e4) {}
        }
      };

      setCommand(cmd, def);
    });
  }

  function afAeEnsureMybbListAndAlignBbcode(inst) {
    if (!hasSceditor()) return;

    function getBb() {
      try {
        // глобально
        var bb = jQuery.sceditor.plugins && jQuery.sceditor.plugins.bbcode
          ? jQuery.sceditor.plugins.bbcode.bbcode
          : null;
        if (bb && typeof bb.set === 'function') return bb;
      } catch (e0) {}

      // через инстанс (иногда так надёжнее)
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
      // bbcode plugin мог подгрузиться позже — повторим пару раз
      if (window.__afAeBbPatchRetrying) return;
      window.__afAeBbPatchRetrying = true;

      var t = 0;
      (function retry() {
        t++;
        var b2 = getBb();
        if (b2) {
          window.__afAeBbPatchRetrying = false;
          try { afAeEnsureMybbListAndAlignBbcode(inst); } catch (e2) {}
          return;
        }
        if (t < 25) return setTimeout(retry, 120);
        window.__afAeBbPatchRetrying = false;
      })();
      return;
    }

    if (bb.__afAeMybbPatched) return;
    bb.__afAeMybbPatched = true;

    // =========================
    // LISTS: [ol]/[ul]/[li]
    // =========================
    try {
      bb.set('list', {
        isInline: false,
        format: function (el, content) {
          try {
            var tag = (el && el.tagName) ? String(el.tagName).toUpperCase() : '';
            if (tag === 'OL') return '[ol]{0}[/ol]';
            return '[ul]{0}[/ul]';
          } catch (e) {
            return '[ul]{0}[/ul]';
          }
        },
        html: function (token, attrs, content) {
          var t = '';
          try { t = (attrs && attrs.defaultattr != null) ? String(attrs.defaultattr) : ''; } catch (e0) { t = ''; }
          t = (t || '').toLowerCase().trim();

          if (t === '1') return '<ol>{0}</ol>'.replace('{0}', content);
          return '<ul>{0}</ul>'.replace('{0}', content);
        }
      });
    } catch (eL1) {}

    try {
      bb.set('li', {
        format: '[li]{0}[/li]',
        html: '<li>{0}</li>',
        isInline: false
      });
      bb.set('ul', {
        format: '[ul]{0}[/ul]',
        html: '<ul>{0}</ul>',
        isInline: false
      });
      bb.set('ol', {
        format: '[ol]{0}[/ol]',
        html: '<ol>{0}</ol>',
        isInline: false
      });
    } catch (eL2) {}

    // =========================
    // ALIGN: MyBB style [align=...]
    // =========================

    // 1) сам тег [align=...]
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

    // 2) перебиваем дефолтные теги SCEditor [left]/[center]/...
    // чтобы HTML->BBCode отдавал [align=...]
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

    // 3) div с text-align -> [align=...]
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
            if (a !== 'left' && a !== 'center' && a !== 'right' && a !== 'justify') {
              return content;
            }
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
    if (!hasSceditor()) return;

    function patch(cmd, alignVal) {
      try {
        var c = getCommand(cmd);
        if (!c || c.__afAeAlignPatched) return;

        var origTxt = c.txtExec;
        c.txtExec = function () {
          try {
            if (typeof this.insert === 'function') {
              this.insert('[align=' + alignVal + ']', '[/align]');
              return;
            }
          } catch (e0) {}
          try { if (origTxt) return origTxt.apply(this, arguments); } catch (e1) {}
        };

        c.__afAeAlignPatched = true;
        setCommand(cmd, c);
      } catch (e2) {}
    }

    patch('left', 'left');
    patch('center', 'center');
    patch('right', 'right');
    patch('justify', 'justify');
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

  function buildDropdownContent(editor, menu, availableMap) {
    var $content = jQuery('<div class="af-ae-dd" role="menu"></div>');

    // ВАЖНО: не даём SCEditor “съесть” клики
    $content.on('mousedown click', function (e) {
      try { e.stopPropagation(); } catch (e0) {}
    });

    (menu.items || []).forEach(function (cmd) {
      cmd = String(cmd || '').trim();
      if (!cmd || cmd === '|') return;

      var meta = availableMap[cmd] || {};
      var title = String(meta.title || meta.hint || cmd || '').trim() || cmd;

      var $btn = jQuery(
        '<button type="button" class="af-ae-dd-item" role="menuitem">' +
          '<span class="af-ae-dd-text"></span>' +
        '</button>'
      );
      $btn.find('.af-ae-dd-text').text(title);

      $btn.on('mousedown', function (e) {
        try { e.preventDefault(); } catch (e0) {}
        try { e.stopPropagation(); } catch (e1) {}
      });

      $btn.on('click', function (e) {
        try { e.preventDefault(); } catch (e0) {}
        try { e.stopPropagation(); } catch (e1) {}

        try {
          if (editor && editor.command && typeof editor.command.exec === 'function') {
            editor.command.exec(cmd);
          } else if (editor && typeof editor.execCommand === 'function') {
            editor.execCommand(cmd);
          } else if (editor && typeof editor.insert === 'function') {
            editor.insert('', '');
          }
        } catch (e2) {}

        try { if (editor && typeof editor.closeDropDown === 'function') editor.closeDropDown(true); } catch (e3) {}
      });

      $content.append($btn);
    });

    if (!$content.children().length) {
      $content.append(jQuery('<div class="af-ae-dd-empty">Пустое меню</div>'));
    }

    return $content;
  }

  function ensureDropdownCommands(out, availableMap) {
    if (!hasSceditor()) return;
    if (!out || !Array.isArray(out.menus)) return;

    out.menus.forEach(function (m) {
      if (!m || !m.cmd) return;

      // Регистрируем команду ДО инициализации редактора,
      // иначе SCEditor может нарисовать кнопку, но не повесить обработчик нормально.
      var existing = getCommand(m.cmd);
      if (existing && existing.__afAeDropdownPatched) return;

      var def = {
        tooltip: String(m.title || '★'),
        dropDown: function (editor, caller) {
          try {
            var $content = buildDropdownContent(editor, m, availableMap);
            editor.createDropDown(caller, m.cmd, $content);
          } catch (e0) {}
        },
        exec: function (caller) {
          try { this.command && this.command.dropDown
            ? this.command.dropDown(caller, m.cmd)
            : def.dropDown(this, caller);
          } catch (e1) { try { def.dropDown(this, caller); } catch (e2) {} }
        },
        txtExec: function (caller) {
          try { def.dropDown(this, caller); } catch (e3) {}
        }
      };

      def.__afAeDropdownPatched = true;
      setCommand(m.cmd, def);
    });
  }

  function decorateDropdownButtons(ta, out) {
    try {
      var cont = ta.previousElementSibling;
      var tb = cont ? cont.querySelector('.sceditor-toolbar') : null;
      if (!tb || !out || !Array.isArray(out.menus)) return;

      function isUrl(x) {
        x = String(x || '').trim();
        return /^https?:\/\//i.test(x) || x.startsWith('/');
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

      function titleSpec(t) {
        t = String(t || '').trim();
        if (isSvg(t)) return { kind: 'svg', value: t };
        if (isUrl(t)) return { kind: 'url', value: t };
        if (t) return { kind: 'text', value: t };
        return { kind: 'text', value: '★' };
      }

      out.menus.forEach(function (m) {
        var a = tb.querySelector('a.sceditor-button-' + m.cmd);
        if (!a) return;

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

      function isUrl(x) {
        x = String(x || '').trim();
        return /^https?:\/\//i.test(x) || x.startsWith('/');
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

      function applyMaskIcon(el, url) {
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

        d.innerHTML = '';
        d.textContent = '';
        d.style.backgroundImage = 'none';
        d.style.webkitMaskImage = 'none';
        d.style.maskImage = 'none';
        d.style.backgroundColor = '';

        d.style.display = 'flex';
        d.style.alignItems = 'center';
        d.style.justifyContent = 'center';
        d.style.width = '16px';
        d.style.height = '16px';
        d.style.lineHeight = '16px';
        d.style.padding = '0';

        var icon = String(b.icon || '').trim();
        if (icon && isSvgMarkupSafe(icon)) {
          d.innerHTML = icon;
          return;
        }

        if (icon && isUrl(icon)) {
          if (looksLikeSvgUrl(icon)) {
            applyMaskIcon(d, icon);
          } else {
            d.style.backgroundImage = 'url("' + icon.replace(/"/g, '\\"') + '")';
            d.style.backgroundRepeat = 'no-repeat';
            d.style.backgroundPosition = 'center';
            d.style.backgroundSize = '16px 16px';
          }
          return;
        }

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

  function bindSubmitSync(form, inst, ta) {
    if (!form || !inst || !ta) return;

    // можно биндинг на каждый инстанс, но без дублей
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
            if (typeof v === 'string') ta.value = v;
          }
        } catch (e1) {}
      } catch (e2) {}
    }

    // submit capture — раньше всего
    form.addEventListener('submit', function () {
      syncNow();
    }, true);

    // на всякий: клик по submit-кнопкам (иногда формы валидируются до submit)
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
          // ВАЖНО: iframe может появиться не мгновенно — даём микропаузу и ретраи внутри функции
          setTimeout(function () {
            try { afAeApplyWysiwygCodeQuoteCss(inst); } catch (e0) {}

            window.__afAeIgnoreMutationsUntil = now() + 250;
            window.__afAeGlobalToggling = Math.max(0, (window.__afAeGlobalToggling | 0) - 1);
          }, 30);
        }
      };
    }

    // На старте тоже пинаем: иногда инстанс уже есть, но iframe ещё догружается
    try { setTimeout(function () { try { afAeApplyWysiwygCodeQuoteCss(inst); } catch (e1) {} }, 30); } catch (e2) {}
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
    var layout = sanitizeLayout(P.layout || null);
    var availableMap = buildAvailableMap();
    var out = buildToolbarFromLayout(layout);

    var existing = safeGetInstance($ta);
    if (existing) {
      ta.__afAeInited = true;
      existing.__afAeOwned = true;

      try { ensureCustomCommands(); } catch (eC0) {}
      try { ensureDropdownCommands(out, availableMap); } catch (eD0) {}

      try { afAeEnsureMybbListAndAlignBbcode(existing); } catch (eA0) {}
      try { afAePatchAlignCommandsForSourceMode(); } catch (eA0b) {}
      try { afAeEnsureFrontendCodeCss(); } catch (eA1) {}

      try { patchEditorInstanceForSafeToggle(existing); } catch (e0) {}
      try { ensureDefaultSourceMode(existing); } catch (e0b) {}
      try { bindSubmitSync(ta.form, existing, ta); } catch (e1) {}
      try { afAeApplyWysiwygCodeQuoteCss(existing); } catch (e2) {}

      try {
        decorateDropdownButtons(ta, out);
        decorateCustomButtons(ta);
      } catch (e3) {}

      return true;
    }

    try {
      ensureCustomCommands();
      ensureDropdownCommands(out, availableMap);
      ensurePostKeyInput(ta.form);

      // Патчи bbcode (до инициализации) — и потом ещё раз после, чтобы точно
      try { afAeEnsureMybbListAndAlignBbcode(null); } catch (eP0) {}

      $ta.sceditor({
        format: 'bbcode',
        toolbar: out.toolbar,
        style: P.sceditorCss || '',
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

        // Патчи bbcode/стилей уже по факту инстанса
        try { afAeEnsureMybbListAndAlignBbcode(inst); } catch (eP1) {}
        try { afAePatchAlignCommandsForSourceMode(); } catch (eP1b) {}
        try { afAeEnsureFrontendCodeCss(); } catch (eP2) {}

        try { patchEditorInstanceForSafeToggle(inst); } catch (e3) {}
        try { ensureDefaultSourceMode(inst); } catch (e3b) {}
        try { bindSubmitSync(ta.form, inst, ta); } catch (e4) {}
        try { afAeApplyWysiwygCodeQuoteCss(inst); } catch (e5) {}

        decorateDropdownButtons(ta, out);
        decorateCustomButtons(ta);

        return true;
      }
    } catch (e) {
      log('[AE] init error', e);
    }

    return false;
  }

  function scanAndInit(root) {
    root = root || document;
    if (!root.querySelectorAll) return;

    var list = root.querySelectorAll('textarea');
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

          if (n.tagName === 'TEXTAREA') {
            initOneTextarea(n);
          } else if (n.querySelectorAll) {
            var tas = n.querySelectorAll('textarea');
            if (tas && tas.length) {
              for (var k = 0; k < tas.length; k++) initOneTextarea(tas[k]);
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

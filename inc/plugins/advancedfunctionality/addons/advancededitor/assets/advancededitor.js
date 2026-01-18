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
            try { if (ed.toggleSourceMode) ed.toggleSourceMode(); } catch (e0) {}
            return;
          }

          if (handler && window.afAeBuiltinHandlers && window.afAeBuiltinHandlers[handler]) {
            try {
              var h = window.afAeBuiltinHandlers[handler];
              if (typeof h === 'function') {
                h({ editor: ed, caller: caller, cmd: cmd, payload: defData });
                return;
              }
              if (h && typeof h.exec === 'function') {
                h.exec({ editor: ed, caller: caller, cmd: cmd, payload: defData });
                return;
              }
            } catch (e1) {}
          }

          try {
            if (opentag && typeof ed.insert === 'function') {
              ed.insert(opentag, closetag || '');
              return;
            }
          } catch (e2) {}

          try {
            if (typeof ed.insert === 'function') ed.insert('[' + cmd + ']', '[/' + cmd + ']');
          } catch (e3) {}
        },
        txtExec: function (caller) {
          try { def.exec.call(this, caller); } catch (e4) {}
        }
      };

      setCommand(cmd, def);
    });
  }

  function buildDropdownContent(editor, menu, availableMap) {
    var $content = jQuery('<div class="af-ae-dd"></div>');

    (menu.items || []).forEach(function (cmd) {
      cmd = String(cmd || '').trim();
      if (!cmd || cmd === '|') return;

      var meta = availableMap[cmd] || {};
      var title = String(meta.title || meta.hint || cmd || '').trim() || cmd;

      var $btn = jQuery(
        '<button type="button" class="af-ae-dd-item">' +
        '<span class="af-ae-dd-text"></span>' +
        '</button>'
      );
      $btn.find('.af-ae-dd-text').text(title);

      $btn.on('click', function (e) {
        e.preventDefault();

        try {
          if (editor && editor.command && typeof editor.command.exec === 'function') {
            editor.command.exec(cmd);
          } else if (editor && typeof editor.execCommand === 'function') {
            editor.execCommand(cmd);
          } else if (editor && typeof editor.insert === 'function') {
            editor.insert(cmd, null);
          }
        } catch (e1) {
          try { if (editor && typeof editor.insert === 'function') editor.insert(cmd, null); } catch (e2) {}
        }

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
    if (!window.jQuery || !jQuery.sceditor) return;
    if (!out || !Array.isArray(out.menus)) return;

    out.menus.forEach(function (m) {
      if (!m || !m.cmd) return;
      if (getCommand(m.cmd)) return;

      var def = {
        tooltip: 'Dropdown: ' + (m.title || '★'),
        dropDown: function (editor, caller) {
          try {
            var $content = buildDropdownContent(editor, m, availableMap);
            editor.createDropDown(caller, m.cmd, $content);
          } catch (e0) {}
        },
        exec: function (caller) {
          try {
            var ed = this;
            if (def.dropDown) def.dropDown(ed, caller);
          } catch (e1) {}
        },
        txtExec: function (caller) {
          try {
            var ed = this;
            if (def.dropDown) def.dropDown(ed, caller);
          } catch (e2) {}
        }
      };

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
    if (form.__afAeSubmitBound) return;
    form.__afAeSubmitBound = true;

    form.addEventListener('submit', function () {
      try { updateOriginal(inst); } catch (e) {}
      try {
        if (ta && typeof ta.value === 'string') {
          if (ta.value === '' || ta.value == null) {
            try {
              var val = '';
              try { val = inst.val ? inst.val() : ''; } catch (e2) { val = ''; }
              if (val && typeof val === 'string') ta.value = val;
            } catch (e3) {}
          }
        }
      } catch (e4) {}
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
          setTimeout(function () {
            window.__afAeIgnoreMutationsUntil = now() + 250;
            window.__afAeGlobalToggling = Math.max(0, (window.__afAeGlobalToggling | 0) - 1);
          }, 0);
        }
      };
    }
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

    var existing = safeGetInstance($ta);
    if (existing) {
      ta.__afAeInited = true;
      existing.__afAeOwned = true;
      try { patchEditorInstanceForSafeToggle(existing); } catch (e0) {}
      try { bindSubmitSync(ta.form, existing, ta); } catch (e1) {}
      return true;
    }

    var layout = sanitizeLayout(P.layout || null);
    var availableMap = buildAvailableMap();
    var out = buildToolbarFromLayout(layout);

    try {
      ensureCustomCommands();
      ensureDropdownCommands(out, availableMap);
      ensurePostKeyInput(ta.form);

      $ta.sceditor({
        format: 'bbcode',
        toolbar: out.toolbar,
        style: P.sceditorCss || '',
        height: 180,
        width: '100%',
        resizeEnabled: true,
        autoExpand: false
      });

      var inst = safeGetInstance($ta);
      if (inst) {
        ta.__afAeInited = true;
        inst.__afAeOwned = true;
        inst.__afAeToolbarSig = asText(out.toolbar);

        try { patchEditorInstanceForSafeToggle(inst); } catch (e2) {}
        try { bindSubmitSync(ta.form, inst, ta); } catch (e3) {}

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

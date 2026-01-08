(function () {
  'use strict';

  // это НЕ трогает QR: мы работаем только с другими textarea, не внутри #quick_reply_form
  if (window.afAqrExtInitialized) return;
  window.afAqrExtInitialized = true;

  var payload = window.afAqrPayload || {};
  var buttons = Array.isArray(payload.buttons) ? payload.buttons : [];
  var builtins = Array.isArray(payload.builtins) ? payload.builtins : [];
  var cfg = payload.cfg || {};

  function asText(x) { return String(x == null ? '' : x); }
  function escCss(s) { return String(s || '').replace(/[^a-z0-9_\-]/gi, '_'); }

  function hasJQEditor() {
    return !!(window.jQuery && jQuery.fn && jQuery.fn.sceditor);
  }

  function getToolbarLayout() {
    var l = cfg && cfg.toolbarLayout;
    if (!l || !l.sections || !Array.isArray(l.sections)) return null;
    return l;
  }

  // ---------------------------
  // URL / assets resolution
  // ---------------------------
  function looksLikeUrl(x) {
    x = asText(x).trim();
    if (!x) return false;
    if (/^data:/i.test(x)) return true;
    if (/^https?:\/\//i.test(x)) return true;
    if (x.startsWith('//')) return true;
    if (x.startsWith('/')) return true;
    return false;
  }

  function looksLikeAssetPath(x) {
    x = asText(x).trim();
    if (!x) return false;
    if (looksLikeUrl(x)) return true;
    return /\.(svg|png|jpe?g|webp|gif)(?:[?#].*)?$/i.test(x);
  }

  function getAssetsBase() {
    var base = (cfg && (cfg.assetsBaseUrl || cfg.aqrAssetsBaseUrl || cfg.addonAssetsBaseUrl))
      ? String(cfg.assetsBaseUrl || cfg.aqrAssetsBaseUrl || cfg.addonAssetsBaseUrl)
      : '/inc/plugins/advancedfunctionality/addons/advancedquickreply/assets/';
    base = base.replace(/\/+$/, '') + '/';
    return base;
  }

  function getSceditorStylesBase() {
    var base = (cfg && (cfg.sceditorStylesBaseUrl || cfg.sceditorBaseUrl))
      ? String(cfg.sceditorStylesBaseUrl || cfg.sceditorBaseUrl)
      : '/jscripts/sceditor/styles/';
    base = base.replace(/\/+$/, '') + '/';
    return base;
  }

  function resolveIconUrl(u) {
    u = asText(u).trim();
    if (!u) return u;

    if (looksLikeUrl(u)) return u;
    if (!looksLikeAssetPath(u)) return u;

    u = u.replace(/^\.\//, '');

    var addonBase = getAssetsBase();
    var sceditorStylesBase = getSceditorStylesBase();

    u = u.replace(/^\/+/, '');

    // sceditor:img/bold.svg -> /jscripts/sceditor/styles/img/bold.svg
    if (/^sceditor:/i.test(u)) {
      var rest = u.replace(/^sceditor:\s*/i, '').replace(/^\/+/, '');
      if (/^img\//i.test(rest)) return sceditorStylesBase + rest;
      if (/^styles\/img\//i.test(rest)) return '/jscripts/sceditor/' + rest;
      return sceditorStylesBase + rest;
    }

    if (/^sceditor\//i.test(u)) {
      var rest2 = u.replace(/^sceditor\/+/i, '').replace(/^\/+/, '');
      if (/^img\//i.test(rest2)) return sceditorStylesBase + rest2;
      if (/^styles\/img\//i.test(rest2)) return '/jscripts/sceditor/' + rest2;
      return sceditorStylesBase + rest2;
    }

    if (/^styles\/img\//i.test(u)) {
      return '/jscripts/sceditor/' + u;
    }

    // img/... = assets/img/...
    if (/^img\//i.test(u)) return addonBase + u;

    // bbcodes/... = assets/bbcodes/...
    return addonBase + u;
  }

  function isSvgMarkupSafe(x) {
    x = asText(x).trim();
    if (!x) return false;
    if (!(x.startsWith('<svg') && x.includes('</svg>'))) return false;
    var low = x.toLowerCase();
    if (low.includes('<script') || low.includes('onload=') || low.includes('onerror=')) return false;
    return true;
  }

  function normalizeIconSpec(icon) {
    icon = asText(icon).trim();
    if (!icon) return { kind: 'empty', value: '' };

    if (isSvgMarkupSafe(icon)) return { kind: 'svg', value: icon };

    if (looksLikeUrl(icon) || looksLikeAssetPath(icon)) {
      return { kind: 'url', value: resolveIconUrl(icon) };
    }

    return { kind: 'text', value: icon };
  }

  // ---------------------------
  // Toolbar build (ACP layout -> SCEditor toolbar string)
  // ---------------------------
  function buildToolbarFromLayout(lay) {
    var out = { toolbar: '', menus: [] };
    if (!lay || !Array.isArray(lay.sections)) return out;

    var parts = [];

    lay.sections.forEach(function (sec, idx) {
      if (!sec || typeof sec !== 'object') return;

      var type = String(sec.type || 'group').toLowerCase();
      var id = String(sec.id || ('sec' + idx));
      var title = String(sec.title || '');
      var items = Array.isArray(sec.items) ? sec.items.slice() : [];

      if (type === 'dropdown') {
        var cmd = 'afmenu_' + id.replace(/[^a-z0-9_\-]/gi, '_');
        parts.push(cmd);
        out.menus.push({ id: id, cmd: cmd, title: title, items: items.slice() });
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
    out.toolbar = toolbar;
    return out;
  }

  // ---------------------------
  // Tooltips
  // ---------------------------
  function extractSvgTooltip(svg) {
    svg = asText(svg).trim();
    if (!svg) return '';
    var m1 = svg.match(/\stitle\s*=\s*"([^"]+)"/i);
    if (m1 && m1[1]) return String(m1[1]).trim();
    var m2 = svg.match(/<title>\s*([^<]+?)\s*<\/title>/i);
    if (m2 && m2[1]) return String(m2[1]).trim();
    return '';
  }

  function normalizeDropdownTooltip(rawTitle) {
    rawTitle = asText(rawTitle).trim();
    if (!rawTitle) return 'Доп. меню';

    if (isSvgMarkupSafe(rawTitle)) {
      var t = extractSvgTooltip(rawTitle);
      return t || 'Доп. меню';
    }

    if (looksLikeUrl(rawTitle) || looksLikeAssetPath(rawTitle)) return 'Доп. меню';
    return rawTitle;
  }

  function svgStarMarkup() {
    return '' +
      '<svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
      '<path d="M12 17.3l-6.18 3.73 1.64-7.03L2 9.24l7.19-.62L12 2l2.81 6.62 7.19.62-5.46 4.76 1.64 7.03z"></path>' +
      '</svg>';
  }

  // ---------------------------
  // Builtin handlers bucket
  // ---------------------------
  function ensureBuiltinHandlersBucket() {
    try {
      if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);
    } catch (e) {}
  }

  // ---------------------------
  // Commands registry (custom + dropdown)
  // ---------------------------
  function buildMetaByCmd() {
    var meta = Object.create(null);

    function add(cmd, base) {
      cmd = asText(cmd).trim();
      if (!cmd) return;
      if (meta[cmd]) return;
      meta[cmd] = base;
    }

    function normalizeOne(b) {
      if (!b) return null;

      var cmdRaw = asText(b.cmd || '').trim();
      var name = asText(b.name || '').trim();

      if (!cmdRaw && name) cmdRaw = 'af_' + name;
      if (!cmdRaw) return null;

      var cmdEsc = cmdRaw;
      if (/^af_/i.test(cmdRaw) && name) {
        cmdEsc = 'af_' + escCss(name);
      }

      var base = {
        cmd: cmdRaw,
        name: name || cmdRaw,
        title: asText(b.title || b.hint || name || cmdRaw).trim() || cmdRaw,
        opentag: asText(b.opentag || ''),
        closetag: asText(b.closetag || ''),
        iconSpec: normalizeIconSpec(b.icon),
        handler: asText(b.handler || '').trim() || ''
      };

      add(cmdRaw, base);

      if (cmdEsc && cmdEsc !== cmdRaw) {
        add(cmdEsc, {
          cmd: cmdEsc,
          name: base.name,
          title: base.title,
          opentag: base.opentag,
          closetag: base.closetag,
          iconSpec: base.iconSpec,
          handler: base.handler
        });
      }

      return base;
    }

    buttons.forEach(normalizeOne);
    builtins.forEach(normalizeOne);

    return meta;
  }

  function ensureDropdownCommands(out) {
    if (!window.jQuery || !jQuery.sceditor || !jQuery.sceditor.command) return;

    (out.menus || []).forEach(function (m) {
      if (!m || !m.cmd) return;

      try { if (jQuery.sceditor.command.get(m.cmd)) return; } catch (e0) {}

      jQuery.sceditor.command.set(m.cmd, {
        _dropDown: function (editor, caller) {
          var $content = jQuery('<div class="af-aqr-dd"></div>');

          (m.items || []).forEach(function (cmd) {
            cmd = String(cmd || '').trim();
            if (!cmd || cmd === '|') return;

            var $btn = jQuery('<button type="button" class="button" style="display:block;width:100%;margin:4px 0;text-align:left;"></button>');
            $btn.text(cmd);

            $btn.on('click', function (e) {
              e.preventDefault();
              try { editor.command.exec(cmd); }
              catch (e1) { try { editor.insert(cmd, null); } catch (e2) {} }
              try { editor.closeDropDown(true); } catch (e3) {}
            });

            $content.append($btn);
          });

          if (!$content.children().length) {
            $content.append(jQuery('<div class="smalltext" style="padding:6px 2px;">Пустое меню</div>'));
          }

          try { editor.createDropDown(caller, m.cmd, $content); } catch (e4) {}
        },

        exec: function (caller) { try { jQuery.sceditor.command.get(m.cmd)._dropDown(this, caller); } catch (e) {} },
        txtExec: function (caller) { try { jQuery.sceditor.command.get(m.cmd)._dropDown(this, caller); } catch (e) {} },

        tooltip: 'Dropdown: ' + (normalizeDropdownTooltip(m.title) || '★')
      });
    });
  }

  function ensureCommands(out) {
    if (!window.jQuery || !jQuery.sceditor || !jQuery.sceditor.command) return false;

    ensureBuiltinHandlersBucket();
    var metaByCmd = buildMetaByCmd();

    function commandExists(cmd) {
      try { return !!jQuery.sceditor.command.get(cmd); } catch (e0) {}
      return false;
    }

    Object.keys(metaByCmd).forEach(function (cmd) {
      var m = metaByCmd[cmd];
      if (!m) return;

      // не перетираем системные команды SCEditor
      if (commandExists(cmd)) return;

      var open = asText(m.opentag);
      var close = asText(m.closetag);

      if (m.handler) {
        try {
          jQuery.sceditor.command.set(cmd, {
            tooltip: m.title || cmd,
            exec: function (caller) {
              try {
                if (window.afAqrBuiltinHandlers && typeof window.afAqrBuiltinHandlers[m.handler] === 'function') {
                  window.afAqrBuiltinHandlers[m.handler](this, caller);
                  return;
                }
              } catch (e1) {}
              if (open || close) { try { this.insert(open, close); } catch (e2) {} }
            },
            txtExec: function (caller) {
              try {
                if (window.afAqrBuiltinHandlers && typeof window.afAqrBuiltinHandlers[m.handler] === 'function') {
                  window.afAqrBuiltinHandlers[m.handler](this, caller);
                  return;
                }
              } catch (e3) {}
              if (open || close) { try { this.insert(open, close); } catch (e4) {} }
            }
          });
        } catch (eSet1) {}
        return;
      }

      try {
        jQuery.sceditor.command.set(cmd, {
          tooltip: m.title || cmd,
          txtExec: [open, close],
          exec: function () { try { this.insert(open, close); } catch (e1) {} }
        });
      } catch (eSet2) {}
    });

    ensureDropdownCommands(out || { toolbar: '', menus: [] });
    return true;
  }

  // ---------------------------
  // CSS injection fallback
  // ---------------------------
  function injectExtCssOnce() {
    try {
      if (document.getElementById('af-aqr-ext-css')) return;

      var links = document.querySelectorAll('link[rel="stylesheet"]');
      for (var i = 0; i < links.length; i++) {
        var href = String(links[i].getAttribute('href') || '');
        if (href.indexOf('advancedquickreply.ext.css') !== -1) return;
      }

      var base = getAssetsBase();
      var link = document.createElement('link');
      link.id = 'af-aqr-ext-css';
      link.rel = 'stylesheet';
      link.href = base.replace(/\/+$/, '') + '/advancedquickreply.ext.css?v=' + Date.now();
      document.head.appendChild(link);
    } catch (e) {}
  }

  // ---------------------------
  // Editor/container helpers
  // ---------------------------
  function isInsideQuickReply(ta) {
    try { return !!(ta && ta.closest && ta.closest('#quick_reply_form')); }
    catch (e) { return false; }
  }

  function isQuickEditTextarea(ta) {
    try {
      if (!ta) return false;
      var id = String(ta.id || '');
      var nm = String(ta.name || '');
      return /^quickedit/i.test(id) || /^quickedit/i.test(nm);
    } catch (e) {}
    return false;
  }

  function isMainMessageTextarea(ta) {
    try {
      if (!ta) return false;
      var id = String(ta.id || '').toLowerCase();
      var nm = String(ta.name || '').toLowerCase();
      return (id === 'message' || nm === 'message') && !isQuickEditTextarea(ta) && !isInsideQuickReply(ta);
    } catch (e) {}
    return false;
  }

  function getEditorContainerEl(ta) {
    try {
      if (!ta || !window.jQuery) return null;

      var $ta = jQuery(ta);
      var inst = null;

      try { inst = $ta.sceditor('instance'); } catch (e0) { inst = null; }

      if (inst) {
        try {
          if (typeof inst.getContainer === 'function') {
            var c1 = inst.getContainer();
            if (c1 && c1.nodeType === 1) return c1;
          }
        } catch (e1) {}
        try { if (inst.container && inst.container.nodeType === 1) return inst.container; } catch (e2) {}
      }

      try {
        var next = ta.nextElementSibling;
        if (next && next.classList && next.classList.contains('sceditor-container')) return next;
      } catch (e3) {}

      try {
        var $n = $ta.next('.sceditor-container');
        if ($n.length) return $n.get(0);
      } catch (e4) {}

      try {
        var $s = $ta.siblings('.sceditor-container');
        if ($s.length) return $s.get(0);
      } catch (e5) {}

      try {
        var p = ta.parentElement;
        if (p) {
          var c2 = p.querySelector('.sceditor-container');
          if (c2) return c2;
        }
      } catch (e6) {}

      try {
        var form = ta.closest ? ta.closest('form') : null;
        if (form) {
          var all = form.querySelectorAll('.sceditor-container');
          if (all && all.length === 1) return all[0];
        }
      } catch (e7) {}

    } catch (e) {}
    return null;
  }

  function markScope(ta) {
    try {
      if (!ta || !window.jQuery) return;

      var c = getEditorContainerEl(ta);
      if (!c) {
        var tries = (ta.__afAqrScopeTries || 0);
        if (tries < 3) {
          ta.__afAqrScopeTries = tries + 1;
          setTimeout(function () { try { markScope(ta); } catch (e) {} }, 60);
        }
        return;
      }

      var $c = jQuery(c);
      if (!$c.hasClass('af-aqr-ext-scope')) $c.addClass('af-aqr-ext-scope');
      try { $c.find('.sceditor-toolbar').addClass('af-aqr-ext-toolbar'); } catch (e1) {}
    } catch (e) {}
  }

  function isSceditorInternalTextarea(ta) {
    try {
      if (!ta) return false;
      if (ta.classList && ta.classList.contains('sceditor-source')) return true;
      var c = ta.closest ? ta.closest('.sceditor-container') : null;
      if (c) return true;
    } catch (e) {}
    return false;
  }

  // ---------------------------
  // strictMatch crash hardening (главный фикс)
  // ---------------------------
  function sanitizeBbcodeDefinitionsOnce() {
    try {
      if (!window.jQuery || !jQuery.sceditor || !jQuery.sceditor.plugins || !jQuery.sceditor.plugins.bbcode) return;

      if (window.__afAqrBbcodeSanitized) return;
      window.__afAqrBbcodeSanitized = true;

      var proto = jQuery.sceditor.plugins.bbcode.prototype;
      if (!proto) return;

      if (!proto.bbcodes || typeof proto.bbcodes !== 'object') proto.bbcodes = Object.create(null);

      var b = proto.bbcodes;

      function ensureObj(key) {
        var v = b[key];
        if (!v || typeof v !== 'object') v = {};
        if (v.strictMatch == null) v.strictMatch = false;
        b[key] = v;
        return v;
      }

      // оздоравливаем существующие
      Object.keys(b).forEach(function (k) { ensureObj(k); });

      // минимальные дефы для критичных тегов (чтобы dropdown size/source не падали)
      function def(tag, obj) {
        var cur = b[tag];
        if (!cur || typeof cur !== 'object') cur = {};
        if (cur.strictMatch == null) cur.strictMatch = false;

        var hasFormat = typeof cur.format === 'string' && cur.format.length;
        var hasHtml = typeof cur.html === 'string' && cur.html.length;
        var hasTags = cur.tags && typeof cur.tags === 'object';

        if (!hasFormat && obj.format) cur.format = obj.format;
        if (!hasHtml && obj.html) cur.html = obj.html;
        if (!hasTags && obj.tags) cur.tags = obj.tags;

        b[tag] = cur;
      }

      def('b', { tags: { b: null, strong: null }, format: '[b]{0}[/b]', html: '<strong>{0}</strong>' });
      def('i', { tags: { i: null, em: null }, format: '[i]{0}[/i]', html: '<em>{0}</em>' });
      def('u', { tags: { u: null }, format: '[u]{0}[/u]', html: '<u>{0}</u>' });
      def('s', { tags: { s: null, strike: null, del: null }, format: '[s]{0}[/s]', html: '<del>{0}</del>' });

      def('quote', { tags: { blockquote: null }, format: '[quote]{0}[/quote]', html: '<blockquote>{0}</blockquote>' });
      def('code', { tags: { pre: null, code: null }, format: '[code]{0}[/code]', html: '<code>{0}</code>' });

      def('url', { tags: { a: { href: null } }, format: '[url={0}]{1}[/url]', html: '<a href="{0}">{1}</a>' });
      def('img', { tags: { img: { src: null } }, format: '[img]{0}[/img]', html: '<img src="{0}">' });

      def('size', { tags: { font: { size: null } }, format: '[size={0}]{1}[/size]', html: '<font size="{0}">{1}</font>' });
      def('color', { tags: { font: { color: null } }, format: '[color={0}]{1}[/color]', html: '<font color="{0}">{1}</font>' });
      def('font', { tags: { font: { face: null } }, format: '[font={0}]{1}[/font]', html: '<font face="{0}">{1}</font>' });

      def('left', { tags: { div: { align: 'left' } }, format: '[left]{0}[/left]', html: '<div align="left">{0}</div>' });
      def('center', { tags: { div: { align: 'center' } }, format: '[center]{0}[/center]', html: '<div align="center">{0}</div>' });
      def('right', { tags: { div: { align: 'right' } }, format: '[right]{0}[/right]', html: '<div align="right">{0}</div>' });
      def('justify', { tags: { div: { align: 'justify' } }, format: '[justify]{0}[/justify]', html: '<div align="justify">{0}</div>' });

      def('list', { tags: { ul: null, ol: null }, format: '[list]{0}[/list]', html: '<ul>{0}</ul>' });
      def('*', { tags: { li: null }, format: '[*]{0}', html: '<li>{0}</li>' });

      // главный щит: если движок полезет в b[e] для неизвестного тега — отдаём "пустой объект", а не undefined
      // Это ровно то, что гасит "can't access property strictMatch, b[e] is undefined".
      if (window.Proxy && !proto.__afAqrBbProxyInstalled) {
        proto.__afAqrBbProxyInstalled = true;

        var fallbackDef = {
          strictMatch: false,
          tags: null,
          format: '',
          html: ''
        };

        try {
          proto.bbcodes = new Proxy(proto.bbcodes, {
            get: function (target, prop) {
              if (prop in target) return target[prop];
              if (typeof prop === 'string') return fallbackDef;
              return target[prop];
            }
          });
        } catch (eP) {
          // если Proxy не завёлся — просто живём на дефах выше
        }
      }

    } catch (e) {}
  }

  // ---------------------------
  // Decorate toolbar buttons (иконки)
  // ---------------------------
  function decorateDropdownButtons(ta, out) {
    try {
      var cont = getEditorContainerEl(ta);
      var tb = cont ? cont.querySelector('.sceditor-toolbar') : null;
      if (!tb) return;

      function isUrl(x) { return looksLikeUrl(x) || looksLikeAssetPath(x); }
      function looksLikeSvgUrl(u) {
        u = String(u || '').trim().toLowerCase();
        return u.includes('.svg') || u.startsWith('data:image/svg');
      }

      function applyUrlIcon(el, url) {
        url = String(url || '').trim();
        if (!url) return;

        url = resolveIconUrl(url);

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
          el.style.backgroundColor = 'var(--af-aqr-icon-color, currentColor)';
        } else {
          el.style.backgroundImage = 'url("' + url.replace(/"/g, '\\"') + '")';
          el.style.backgroundRepeat = 'no-repeat';
          el.style.backgroundPosition = 'center';
          el.style.backgroundSize = '16px 16px';
        }
      }

      function titleSpec(t) {
        t = String(t || '').trim();
        if (isSvgMarkupSafe(t)) return { kind: 'svg', value: t };
        if (isUrl(t)) return { kind: 'url', value: t };
        if (t) return { kind: 'text', value: t };
        return { kind: 'svg', value: svgStarMarkup() };
      }

      (out.menus || []).forEach(function (m) {
        var a = tb.querySelector('a.sceditor-button-' + m.cmd);
        if (!a) return;

        try { a.style.color = 'var(--af-aqr-icon-color, currentColor)'; } catch (e0) {}

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

        try { a.title = normalizeDropdownTooltip(m.title); } catch (e1) {}
      });
    } catch (e) {}
  }

  function decorateCommandButtons(ta) {
    try {
      if (!window.jQuery) return;

      var cont = getEditorContainerEl(ta);
      var tb = cont ? cont.querySelector('.sceditor-toolbar') : null;
      if (!tb) return;

      var metaByCmd = buildMetaByCmd();
      if (!metaByCmd) return;

      function looksLikeSvgUrl(u) {
        u = String(u || '').trim().toLowerCase();
        return u.endsWith('.svg') || u.indexOf('.svg?') !== -1 || u.indexOf('.svg#') !== -1 || u.startsWith('data:image/svg');
      }

      function applyUrlIcon(divEl, url) {
        url = String(url || '').trim();
        if (!url) return;

        url = resolveIconUrl(url);

        divEl.style.background = 'none';
        divEl.style.backgroundImage = 'none';
        divEl.style.webkitMaskImage = 'none';
        divEl.style.maskImage = 'none';

        if (looksLikeSvgUrl(url)) {
          divEl.style.webkitMaskImage = 'url("' + url.replace(/"/g, '\\"') + '")';
          divEl.style.maskImage = 'url("' + url.replace(/"/g, '\\"') + '")';
          divEl.style.webkitMaskRepeat = 'no-repeat';
          divEl.style.maskRepeat = 'no-repeat';
          divEl.style.webkitMaskPosition = 'center';
          divEl.style.maskPosition = 'center';
          divEl.style.webkitMaskSize = '16px 16px';
          divEl.style.maskSize = '16px 16px';
          divEl.style.backgroundColor = 'var(--af-aqr-icon-color, currentColor)';
        } else {
          divEl.style.backgroundImage = 'url("' + url.replace(/"/g, '\\"') + '")';
          divEl.style.backgroundRepeat = 'no-repeat';
          divEl.style.backgroundPosition = 'center';
          divEl.style.backgroundSize = '16px 16px';
        }
      }

      function applyIconSpec(divEl, iconSpec) {
        if (!divEl || !iconSpec || !iconSpec.kind) return;

        divEl.innerHTML = '';
        divEl.textContent = '';
        divEl.style.textIndent = '0';
        divEl.style.display = 'flex';
        divEl.style.alignItems = 'center';
        divEl.style.justifyContent = 'center';
        divEl.style.height = '16px';
        divEl.style.lineHeight = '16px';
        divEl.style.padding = '0';

        divEl.style.background = 'none';
        divEl.style.backgroundImage = 'none';

        if (iconSpec.kind === 'svg') { divEl.innerHTML = iconSpec.value || ''; return; }
        if (iconSpec.kind === 'url') { applyUrlIcon(divEl, iconSpec.value); return; }

        if (iconSpec.kind === 'text') {
          divEl.textContent = String(iconSpec.value || '').trim();
          divEl.style.fontSize = '12px';
          divEl.style.fontWeight = '700';
          divEl.style.width = 'auto';
          divEl.style.padding = '0 6px';
        }
      }

      Object.keys(metaByCmd).forEach(function (cmd) {
        var m = metaByCmd[cmd];
        if (!m) return;

        var a = tb.querySelector('a.sceditor-button-' + cmd);
        if (!a) return;

        var d = a.querySelector('div');
        if (!d) return;

        try { a.style.color = 'var(--af-aqr-icon-color, currentColor)'; } catch (e2) {}
        applyIconSpec(d, m.iconSpec);
        try { a.title = m.title || cmd; } catch (e3) {}
      });

    } catch (e) {}
  }

  function applyStandardIconsInline(ta) {
    try {
      var cont = getEditorContainerEl(ta);
      var tb = cont ? cont.querySelector('.sceditor-toolbar') : null;
      if (!tb) return;

      var base = getAssetsBase().replace(/\/+$/, '') + '/img/';

      var supportsMask = false;
      try {
        supportsMask = !!(window.CSS && CSS.supports && (
          CSS.supports('(-webkit-mask-image: url("x.svg"))') ||
          CSS.supports('(mask-image: url("x.svg"))')
        ));
      } catch (eS) { supportsMask = false; }

      var map = {
        bold: 'bold.svg', italic: 'italic.svg', underline: 'underline.svg', strike: 'strike.svg',
        font: 'font.svg', size: 'size.svg', color: 'color.svg',
        removeformat: 'removeformat.svg', undo: 'undo.svg', redo: 'redo.svg',
        left: 'left.svg', center: 'center.svg', right: 'right.svg', justify: 'justify.svg',
        bulletlist: 'bulletlist.svg', orderedlist: 'orderedlist.svg',
        quote: 'quote.svg', code: 'code.svg', link: 'link.svg', unlink: 'unlink.svg',
        email: 'email.svg', image: 'image.svg', source: 'source.svg', maximize: 'maximize.svg'
      };

      Object.keys(map).forEach(function (cmd) {
        var a = tb.querySelector('a.sceditor-button-' + cmd);
        if (!a) return;

        var d = a.querySelector('div');
        if (!d) return;

        var url = base + map[cmd];
        url = 'url("' + url.replace(/"/g, '\\"') + '")';

        d.style.color = 'inherit';
        d.style.background = 'none';
        d.style.filter = 'none';

        if (supportsMask) {
          d.style.backgroundImage = 'none';
          d.style.backgroundColor = 'currentColor';

          d.style.webkitMaskImage = url;
          d.style.maskImage = url;

          d.style.webkitMaskRepeat = 'no-repeat';
          d.style.maskRepeat = 'no-repeat';

          d.style.webkitMaskPosition = 'center';
          d.style.maskPosition = 'center';

          d.style.webkitMaskSize = '16px 16px';
          d.style.maskSize = '16px 16px';
        } else {
          d.style.webkitMaskImage = 'none';
          d.style.maskImage = 'none';

          d.style.backgroundImage = url;
          d.style.backgroundRepeat = 'no-repeat';
          d.style.backgroundPosition = 'center';
          d.style.backgroundSize = '16px 16px';
          d.style.backgroundColor = '';
        }
      });

    } catch (e) {}
  }

  // ---------------------------
  // Patch global sceditor_opts BEFORE MyBB init (чтобы в полном редакторе сразу был кастомный тулбар)
  // ---------------------------
  function patchGlobalToolbarOnce(toolbar) {
    try {
      if (!toolbar) return;
      if (window.__afAqrExtGlobalToolbarPatched) return;
      window.__afAqrExtGlobalToolbarPatched = true;

      function patchObj(o) {
        if (!o || typeof o !== 'object') return;
        o.toolbar = toolbar;
        if (!o.plugins) o.plugins = 'bbcode';
        if (String(o.plugins).toLowerCase().indexOf('bbcode') === -1) o.plugins = 'bbcode';
        if (o.format == null) o.format = 'bbcode';

        if (!o.style) {
          var bburl = (cfg && cfg.bburl) ? String(cfg.bburl).replace(/\/+$/, '') : '';
          o.style = bburl
            ? (bburl + '/jscripts/sceditor/styles/jquery.sceditor.mybb.css')
            : '/jscripts/sceditor/styles/jquery.sceditor.mybb.css';
        }
      }

      try { patchObj(window.sceditor_opts); } catch (e1) {}
      try { patchObj(window.sceditorOptions); } catch (e2) {}

    } catch (e) {}
  }

  // ---------------------------
  // Conversion helpers (submit sync)
  // ---------------------------
  function looksLikeHtmlPayload(s) {
    s = asText(s);
    if (!s) return false;
    return /<\/?(p|br|div|span|strong|b|em|i|ul|ol|li|blockquote|pre|code|font|a|img)\b/i.test(s) && s.indexOf('<') !== -1;
  }

  function normalizeOutgoingBbcode(s) {
    s = asText(s);
    s = s.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    s = s.replace(/[ \t]+\n/g, '\n');
    s = s.replace(/\n{3,}/g, '\n\n');
    s = s.replace(/^\n+/, '');
    s = s.replace(/\n+$/, '');
    return s;
  }

  function decodeHtmlEntities(s) {
    s = asText(s);
    if (!s) return '';
    try {
      var ta = document.createElement('textarea');
      ta.innerHTML = s;
      return ta.value;
    } catch (e) {}
    return s;
  }

  function stripDangerousSceditorSourceTextarea(html) {
    html = asText(html);
    if (!html) return '';
    try {
      html = html.replace(
        /<textarea\b[^>]*class\s*=\s*(['"])[^'"]*\bsceditor-source\b[^'"]*\1[^>]*>[\s\S]*?<\/textarea>/gi,
        ''
      );
    } catch (e) {}
    return html;
  }

  function htmlToPlainTextFallback(html) {
    html = asText(html);
    if (!html) return '';
    try {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      var text = (doc && doc.body) ? (doc.body.textContent || '') : '';
      return normalizeOutgoingBbcode(text);
    } catch (e) {}

    var t = html
      .replace(/<br\s*\/?>/gi, '\n')
      .replace(/<\/p>\s*<p[^>]*>/gi, '\n\n')
      .replace(/<\/?p[^>]*>/gi, '')
      .replace(/<[^>]+>/g, '');

    return normalizeOutgoingBbcode(t);
  }

  function htmlToBbcodeBasic(html) {
    html = stripDangerousSceditorSourceTextarea(html);
    html = asText(html);
    if (!html) return '';

    html = html
      .replace(/\r\n/g, '\n')
      .replace(/\r/g, '\n')
      .replace(/<\s*br\s*\/?>/gi, '\n')
      .replace(/<\/\s*(p|div|pre|blockquote)\s*>/gi, '\n\n')
      .replace(/<\s*(p|div|pre|blockquote)\b[^>]*>/gi, '')
      .replace(/<\/\s*li\s*>/gi, '\n')
      .replace(/<\s*li\b[^>]*>/gi, '[*] ')
      .replace(/<\/\s*(ul|ol)\s*>/gi, '\n')
      .replace(/<\s*(ul|ol)\b[^>]*>/gi, '');

    html = html
      .replace(/<\s*(strong|b)\b[^>]*>/gi, '[b]')
      .replace(/<\/\s*(strong|b)\s*>/gi, '[/b]')
      .replace(/<\s*(em|i)\b[^>]*>/gi, '[i]')
      .replace(/<\/\s*(em|i)\s*>/gi, '[/i]')
      .replace(/<\s*u\b[^>]*>/gi, '[u]')
      .replace(/<\/\s*u\s*>/gi, '[/u]')
      .replace(/<\s*(s|del|strike)\b[^>]*>/gi, '[s]')
      .replace(/<\/\s*(s|del|strike)\s*>/gi, '[/s]');

    html = html.replace(/<img\b[^>]*src\s*=\s*(['"])(.*?)\1[^>]*>/gi, function (_, q, src) {
      src = decodeHtmlEntities(String(src || '').trim());
      if (!src) return '';
      return '[img]' + src + '[/img]';
    });

    html = html.replace(/<a\b[^>]*href\s*=\s*(['"])(.*?)\1[^>]*>([\s\S]*?)<\/a>/gi, function (_, q, href, text) {
      href = decodeHtmlEntities(String(href || '').trim());
      text = String(text || '');
      text = text.replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]+>/g, '');
      text = decodeHtmlEntities(text).trim();
      if (!href) return text;
      if (!text || text === href) return '[url]' + href + '[/url]';
      return '[url=' + href + ']' + text + '[/url]';
    });

    html = html.replace(/<[^>]+>/g, '');
    html = decodeHtmlEntities(html).replace(/\u00a0/g, ' ');
    return normalizeOutgoingBbcode(html);
  }

  // Важно: вешаем submit-хук ОДИН раз на форму, иначе можно ловить дубли/пересохранения
  function ensureBbcodeOnSubmitForForm(form) {
    if (!form || form.__afAqrExtSubmitHooked) return;
    form.__afAqrExtSubmitHooked = true;

    form.addEventListener('submit', function () {
      try {
        if (!window.jQuery) return;

        // синкаем все textarea message/quickedit* в этой форме
        var tas = form.querySelectorAll('textarea#message, textarea[name="message"], textarea[id^="quickedit"], textarea[name^="quickedit"]');
        for (var i = 0; i < tas.length; i++) {
          var ta = tas[i];
          if (!ta) continue;
          if (isInsideQuickReply(ta)) continue; // QR отдельной логикой
          if (isSceditorInternalTextarea(ta)) continue;

          var $ta = jQuery(ta);
          var inst = null;
          try { inst = $ta.sceditor('instance'); } catch (e0) { inst = null; }
          if (!inst) continue;

          try { if (typeof inst.updateOriginal === 'function') inst.updateOriginal(); } catch (e1) {}
          try { if (typeof inst.save === 'function') inst.save(); } catch (e2) {}

          function isHtmlish(s) {
            s = asText(s).trim();
            if (!s) return false;
            if (s.indexOf('<') !== -1 && s.indexOf('>') !== -1) return true;
            return looksLikeHtmlPayload(s);
          }

          var vInst = '';
          try { if (typeof inst.val === 'function') vInst = String(inst.val() || ''); } catch (e3) { vInst = ''; }
          vInst = normalizeOutgoingBbcode(vInst.replace(/\u00a0/g, ' '));

          if (vInst.trim() && !isHtmlish(vInst)) {
            ta.value = vInst;
            continue;
          }

          var vTa = normalizeOutgoingBbcode(String(ta.value || '').replace(/\u00a0/g, ' '));
          if (vTa.trim() && !isHtmlish(vTa)) {
            ta.value = vTa;
            continue;
          }

          var html = '';
          try {
            if (typeof inst.getBody === 'function' && inst.getBody()) html = String(inst.getBody().innerHTML || '');
          } catch (e4) { html = ''; }

          html = stripDangerousSceditorSourceTextarea(html);

          var bb = '';
          try {
            if (html && typeof inst.toBBCode === 'function') bb = String(inst.toBBCode(html) || '');
          } catch (e5) { bb = ''; }

          if (!bb && html) {
            try {
              if (jQuery.sceditor && jQuery.sceditor.plugins && jQuery.sceditor.plugins.bbcode
                && jQuery.sceditor.plugins.bbcode.prototype
                && typeof jQuery.sceditor.plugins.bbcode.prototype.toBBCode === 'function') {
                bb = String(jQuery.sceditor.plugins.bbcode.prototype.toBBCode.call(inst, html) || '');
              }
            } catch (e6) { bb = ''; }
          }

          if (!bb) bb = htmlToBbcodeBasic(html || vInst || vTa);
          else bb = normalizeOutgoingBbcode(bb);

          if (bb && bb.trim()) {
            ta.value = bb;
            continue;
          }

          ta.value = normalizeOutgoingBbcode(htmlToPlainTextFallback(html || vInst || vTa));
        }
      } catch (e) {}
    }, true);
  }

  // ---------------------------
  // Options clone (когда мы сами инициируем редактор)
  // ---------------------------
  function cloneEditorOptions() {
    var o = null;

    try { if (window.sceditor_opts && typeof window.sceditor_opts === 'object') o = window.sceditor_opts; } catch (e0) {}
    try { if (!o && window.sceditorOptions && typeof window.sceditorOptions === 'object') o = window.sceditorOptions; } catch (e1) {}

    var out = {};
    if (o) Object.keys(o).forEach(function (k) { out[k] = o[k]; });

    if (!out.plugins) out.plugins = 'bbcode';
    if (String(out.plugins).toLowerCase().indexOf('bbcode') === -1) out.plugins = 'bbcode';
    if (out.format == null) out.format = 'bbcode';

    if (!out.style) {
      var bburl = (cfg && cfg.bburl) ? String(cfg.bburl).replace(/\/+$/, '') : '';
      out.style = bburl
        ? (bburl + '/jscripts/sceditor/styles/jquery.sceditor.mybb.css')
        : '/jscripts/sceditor/styles/jquery.sceditor.mybb.css';
    }

    return out;
  }

  // ---------------------------
  // Main per-textarea logic
  // ---------------------------
  function reinitOnTextarea(ta) {
    if (!ta) return;
    if (ta.__afAqrExtDone) return;
    if (isInsideQuickReply(ta)) return;
    if (!hasJQEditor()) return;
    if (isSceditorInternalTextarea(ta)) return;

    sanitizeBbcodeDefinitionsOnce();

    // ширина textarea
    try {
      ta.style.width = '100%';
      ta.style.maxWidth = '100%';
      ta.style.boxSizing = 'border-box';
      if (ta.parentElement) ta.parentElement.style.maxWidth = '100%';
    } catch (eW) {}

    var layout = getToolbarLayout();
    var out = buildToolbarFromLayout(layout);

    // регаем команды заранее
    ensureCommands(out);
    injectExtCssOnce();

    // Патчим глобальные opts (важно для полного редактора ДО init)
    if (out.toolbar) patchGlobalToolbarOnce(out.toolbar);

    var $ta = jQuery(ta);

    function finalize(inst) {
      if (!inst) return;

      // НЕ включаем flex на контейнер — он у тебя уже однажды ломал высоты/отступы после ошибок
      try {
        var cont0 = (typeof inst.getContainer === 'function') ? inst.getContainer() : null;
        if (cont0) {
          cont0.style.width = '100%';
          cont0.style.maxWidth = '100%';
          cont0.style.boxSizing = 'border-box';
        }
      } catch (eC0) {}

      markScope(ta);

      decorateDropdownButtons(ta, out);
      decorateCommandButtons(ta);
      setTimeout(function () { decorateCommandButtons(ta); }, 60);

      applyStandardIconsInline(ta);
      setTimeout(function () { applyStandardIconsInline(ta); }, 60);

      try {
        var form = ta.closest ? ta.closest('form') : null;
        if (form) ensureBbcodeOnSubmitForForm(form);
      } catch (eF) {}

      ta.__afAqrExtDone = true;
    }

    // 1) если инстанс уже есть — попробуем ДОБАВИТЬ кастомный тулбар безопасно
    var instExisting = null;
    try { instExisting = $ta.sceditor('instance'); } catch (e0) { instExisting = null; }

    if (instExisting) {
      // если в тулбаре явно нет наших кнопок — делаем один безопасный rebuild (destroy + init) с сохранением текста
      // это лечит ситуацию "скрипт подключили поздно и MyBB собрал дефолтный тулбар".
      try {
        if (out.toolbar && !ta.__afAqrExtRebuiltOnce) {
          var cont = getEditorContainerEl(ta);
          var tb = cont ? cont.querySelector('.sceditor-toolbar') : null;

          // эвристика: если нет ни одной кнопки из наших custom meta — значит тулбар дефолтный
          var meta = buildMetaByCmd();
          var hasAnyCustom = false;
          if (tb && meta) {
            Object.keys(meta).some(function (cmd) {
              if (!cmd) return false;
              // пропустим системные команды, нам нужны именно наши (обычно af_*)
              if (!/^af_/i.test(cmd) && !/^afmenu_/i.test(cmd)) return false;
              var a = tb.querySelector('a.sceditor-button-' + cmd);
              if (a) { hasAnyCustom = true; return true; }
              return false;
            });
          }

          if (!hasAnyCustom) {
            ta.__afAqrExtRebuiltOnce = true;

            var keep = '';
            try { keep = (typeof instExisting.val === 'function') ? String(instExisting.val() || '') : String(ta.value || ''); } catch (eK) { keep = String(ta.value || ''); }

            var opts = cloneEditorOptions();
            opts.width = '100%';
            opts.toolbar = out.toolbar;

            try { $ta.sceditor('destroy'); } catch (eD) {}

            try { $ta.sceditor(opts); } catch (eInit) {
              // если не получилось — просто финализируем как есть
              finalize(instExisting);
              return;
            }

            var inst2 = null;
            try { inst2 = $ta.sceditor('instance'); } catch (eI2) { inst2 = null; }
            try { if (inst2 && typeof inst2.val === 'function') inst2.val(keep); else ta.value = keep; } catch (eV) {}

            finalize(inst2 || instExisting);
            return;
          }
        }
      } catch (eRB) {}

      finalize(instExisting);
      return;
    }

    // 2) инстанса нет — дальше важная логика:
    // - для textarea#message (полный редактор) НЕ создаём сами, чтобы не словить двойной init и дубли.
    // - для quickedit — создаём быстро, чтобы не было задержки.
    // - для прочих (если такие есть) — ждём чуть-чуть и потом фоллбек-инициализируем.

    if (ta.__afAqrExtWaiting) return;
    ta.__afAqrExtWaiting = true;

    var tries = 0;
    var delay = 60;

    var maxWaitMain = 80;   // ~4.8s (полный редактор может подтянуться не мгновенно)
    var maxWaitOther = 18;  // ~1.0s
    var maxWaitQuick = 3;   // ~0.18s

    var isMain = isMainMessageTextarea(ta);
    var isQuick = isQuickEditTextarea(ta);

    var maxTries = isMain ? maxWaitMain : (isQuick ? maxWaitQuick : maxWaitOther);

    (function waitLoop() {
      tries++;

      var inst = null;
      try { inst = $ta.sceditor('instance'); } catch (e1) { inst = null; }

      if (inst) {
        ta.__afAqrExtWaiting = false;
        finalize(inst);
        return;
      }

      if (tries < maxTries) {
        setTimeout(waitLoop, delay);
        return;
      }

      ta.__afAqrExtWaiting = false;

      // Для главного #message: НЕ фоллбек-инициализируем (чтобы не плодить инстансы и не дублировать контент)
      if (isMain) return;

      // Фоллбек init (quickedit / нестандартные формы)
      var layout2 = getToolbarLayout();
      var out2 = buildToolbarFromLayout(layout2);
      ensureCommands(out2);

      var opts2 = cloneEditorOptions();
      opts2.width = '100%';
      if (out2.toolbar) opts2.toolbar = out2.toolbar;

      var keep2 = '';
      try { keep2 = String(ta.value || ''); } catch (eK2) { keep2 = ''; }

      try { $ta.sceditor(opts2); } catch (eInit2) { return; }

      var inst3 = null;
      try { inst3 = $ta.sceditor('instance'); } catch (e3) { inst3 = null; }
      try { if (inst3 && typeof inst3.val === 'function') inst3.val(keep2); } catch (eV3) {}

      finalize(inst3);
    })();
  }

  // ---------------------------
  // Targets + scan
  // ---------------------------
  function findTargets() {
    var list = Array.prototype.slice.call(document.querySelectorAll('textarea#message, textarea[name="message"]'));
    list = list.concat(Array.prototype.slice.call(document.querySelectorAll('textarea[id^="quickedit"], textarea[name^="quickedit"]')));

    var seen = new Set();
    var out = [];
    list.forEach(function (ta) {
      if (!ta || seen.has(ta)) return;
      seen.add(ta);
      out.push(ta);
    });

    return out;
  }

  function scan() {
    if (!hasJQEditor()) return;

    sanitizeBbcodeDefinitionsOnce();

    var layout = getToolbarLayout();
    var out = buildToolbarFromLayout(layout);

    // готовим систему заранее (важно для полного редактора)
    ensureCommands(out);
    injectExtCssOnce();
    if (out.toolbar) patchGlobalToolbarOnce(out.toolbar);

    var tas = findTargets();
    tas.forEach(function (ta) {
      if (!ta) return;
      if (isInsideQuickReply(ta)) return;
      if (isSceditorInternalTextarea(ta)) return;

      try { if (ta.disabled) return; } catch (e) {}

      reinitOnTextarea(ta);
    });
  }

  // старт
  scan();

  // наблюдаем DOM (quickedit вставляет textarea динамически)
  if (window.MutationObserver) {
    var t = null;
    var mo = new MutationObserver(function (mutations) {
      try {
        // если изменения внутри sceditor-container — не дёргаем scan
        var useful = false;

        for (var i = 0; i < mutations.length; i++) {
          var m = mutations[i];
          var target = m && m.target;

          if (!target || target.nodeType !== 1) { useful = true; break; }

          try { if (target.closest && target.closest('.sceditor-container')) continue; }
          catch (e0) {}

          useful = true;
          break;
        }

        if (!useful) return;

        if (t) clearTimeout(t);
        t = setTimeout(scan, 60);
      } catch (e) {
        if (t) clearTimeout(t);
        t = setTimeout(scan, 120);
      }
    });

    mo.observe(document.documentElement, { childList: true, subtree: true });
  } else {
    setInterval(scan, 600);
  }

})();

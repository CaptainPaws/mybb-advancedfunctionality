/* Combined editor script: quick reply + full editor */
(function () {
  'use strict';

  if (window.afAqrInitialized) return;
  window.afAqrInitialized = true;

  var payload = window.afAqrPayload || {};
  var buttons = Array.isArray(payload.buttons) ? payload.buttons : [];
  var builtins = Array.isArray(payload.builtins) ? payload.builtins : [];
  var cfg = payload.cfg || {};


  var PREVIEW_LS_KEY = 'af_aqr_quickpreview_enabled';

  function asText(x) { return String(x == null ? '' : x); }

  function looksLikeUrl(x) {
    x = asText(x).trim();
    if (!x) return false;

    // data-uri
    if (/^data:/i.test(x)) return true;

    // absolute / protocol-relative
    if (/^https?:\/\//i.test(x)) return true;
    if (x.startsWith('//')) return true;

    // root-relative
    if (x.startsWith('/')) return true;

    return false;
  }

  // Относительный путь к картинке/иконке считаем “URL-иконкой” тоже.
  // Примеры, которые должны стать валидными:
  //  - bbcodes/table/icon.svg
  //  - img/starmenu.svg
  //  - assets/img/starmenu.svg
  function looksLikeAssetPath(x) {
    x = asText(x).trim();
    if (!x) return false;

    // уже URL — пусть looksLikeUrl это поймает
    if (looksLikeUrl(x)) return true;

    // “похоже на путь”: есть слэши и расширение картинки
    // (специально не требуем '/', потому что может быть "bbcodes/table/icon.svg")
    return /\.(svg|png|jpe?g|webp|gif)(?:[?#].*)?$/i.test(x);
  }

  // Превращает относительные пути в корректный URL до assets AQR.
  function resolveIconUrl(u) {
    u = asText(u).trim();
    if (!u) return u;

    // уже нормальный URL (data:, http(s), //, /...)
    if (looksLikeUrl(u)) return u;

    // если это не “путь к картинке” — не трогаем
    if (!looksLikeAssetPath(u)) return u;

    // чистим "./"
    u = u.replace(/^\.\//, '');

    // базовый путь к assets AQR (каноничный)
    var addonBase = (cfg && (cfg.assetsBaseUrl || cfg.aqrAssetsBaseUrl || cfg.addonAssetsBaseUrl))
      ? String(cfg.assetsBaseUrl || cfg.aqrAssetsBaseUrl || cfg.addonAssetsBaseUrl)
      : '/inc/plugins/advancedfunctionality/addons/advancedquickreply/assets/';
    addonBase = addonBase.replace(/\/+$/, '') + '/';

    // базовый путь к SCEditor styles (где обычно лежит img/*.svg)
    var sceditorStylesBase = (cfg && (cfg.sceditorStylesBaseUrl || cfg.sceditorBaseUrl))
      ? String(cfg.sceditorStylesBaseUrl || cfg.sceditorBaseUrl)
      : '/jscripts/sceditor/styles/';
    sceditorStylesBase = sceditorStylesBase.replace(/\/+$/, '') + '/';

    // убираем ведущие слэши, чтобы не получилось //
    u = u.replace(/^\/+/, '');

    // ====== ЯВНЫЕ ПРЕФИКСЫ ДЛЯ SCEDITOR ======
    // "sceditor:img/bold.svg"  -> /jscripts/sceditor/styles/img/bold.svg
    // "sceditor/img/bold.svg"  -> /jscripts/sceditor/styles/img/bold.svg
    if (/^sceditor:/i.test(u)) {
      var rest = u.replace(/^sceditor:\s*/i, '');
      rest = rest.replace(/^\/+/, '');
      if (/^img\//i.test(rest)) return sceditorStylesBase + rest;
      if (/^styles\/img\//i.test(rest)) return '/jscripts/sceditor/' + rest;
      return sceditorStylesBase + rest;
    }

    if (/^sceditor\//i.test(u)) {
      var rest2 = u.replace(/^sceditor\/+/i, '');
      rest2 = rest2.replace(/^\/+/, '');
      if (/^img\//i.test(rest2)) return sceditorStylesBase + rest2;
      if (/^styles\/img\//i.test(rest2)) return '/jscripts/sceditor/' + rest2;
      return sceditorStylesBase + rest2;
    }

    // ====== СТАРЫЙ КЕЙС styles/img/... (редкий) ======
    if (/^styles\/img\//i.test(u)) {
      return '/jscripts/sceditor/' + u;
    }

    // ====== ГЛАВНОЕ ИЗМЕНЕНИЕ ======
    // "img/..." теперь считается папкой assets аддона:
    // /inc/plugins/.../assets/img/...
    if (/^img\//i.test(u)) {
      return addonBase + u;
    }

    // Всё остальное считаем ассетами AQR (bbcodes/..., icons/..., assets/...)
    return addonBase + u;
  }


  function afAqrGetIconRev(cfg) {
    function asText(x) { return String(x == null ? '' : x); }

    try {
      var v = getComputedStyle(document.documentElement).getPropertyValue('--af-aqr-icon-rev');
      v = asText(v).trim();
      if (v) return v;
    } catch (e0) {}

    try {
      var c = cfg || {};
      var vv = asText(c.iconRev || c.iconsRev || c.assetsRev || c.build || c.version).trim();
      if (vv) return vv;
    } catch (e1) {}

    return '';
  }

  function afAqrAppendRev(url, cfg) {
    function asText(x) { return String(x == null ? '' : x); }

    url = asText(url).trim();
    if (!url) return url;

    // НЕ добавляем ?v=... к абсолютным URL (https://...) и protocol-relative (//...)
    // иначе у тебя получается "почти такая же ссылка", но уже другая, и сервер/кеш/правила могут её убить.
    if (/^https?:\/\//i.test(url) || url.startsWith('//')) return url;

    // data: тоже не трогаем
    if (/^data:/i.test(url)) return url;

    var rev = afAqrGetIconRev(cfg);
    if (!rev) return url;

    // сохраним #hash
    var base = url, hash = '';
    var hi = base.indexOf('#');
    if (hi !== -1) { hash = base.slice(hi); base = base.slice(0, hi); }

    if (/[?&]v=/.test(base)) {
      base = base.replace(/([?&])v=[^&]*/i, '$1v=' + encodeURIComponent(rev));
      return base + hash;
    }

    return base + (base.indexOf('?') === -1 ? '?' : '&') + 'v=' + encodeURIComponent(rev) + hash;
  }

  function afAqrSupportsMask() {
    try {
      if (window.CSS && typeof CSS.supports === 'function') {
        // базовая проверка поддержки mask
        return (
          CSS.supports('mask-image', 'url("data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22/%3E")') ||
          CSS.supports('-webkit-mask-image', 'url("data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22/%3E")')
        );
      }
    } catch (e) {}
    return false;
  }

  function isSvgMarkupSafe(x) {
    x = asText(x).trim();
    if (!x) return false;
    if (!(x.startsWith('<svg') && x.includes('</svg>'))) return false;
    // минимальная защита от очевидного мусора
    var low = x.toLowerCase();
    if (low.includes('<script') || low.includes('onload=') || low.includes('onerror=')) return false;
    return true;
  }

  function normalizeIconSpec(icon) {
    icon = asText(icon).trim();
    if (!icon) return { kind: 'empty', value: '' };

    // inline SVG
    if (isSvgMarkupSafe(icon)) return { kind: 'svg', value: icon };

    // URL / root-relative / data:  + относительные пути к файлам иконок
    if (looksLikeUrl(icon) || looksLikeAssetPath(icon)) {
      return { kind: 'url', value: resolveIconUrl(icon) };
    }

    // обычный текст (unicode/буква)
    return { kind: 'text', value: icon };
  }


  function extractSvgTooltip(svg) {
    svg = asText(svg).trim();
    if (!svg) return '';

    // 1) title="..."
    var m1 = svg.match(/\stitle\s*=\s*"([^"]+)"/i);
    if (m1 && m1[1]) return String(m1[1]).trim();

    // 2) <title>...</title>
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

    // и абсолютные URL, и относительные пути к иконке — это не tooltip-текст
    if (looksLikeUrl(rawTitle) || looksLikeAssetPath(rawTitle)) return 'Доп. меню';

    // обычный текст
    return rawTitle;
  }

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

      // built-in может прийти как {cmd:'af_table', ...}
      var cmdRaw = asText(b.cmd || '').trim();
      var name = asText(b.name || '').trim();

      // DB-кнопки: name => cmd = af_<name>
      if (!cmdRaw && name) cmdRaw = 'af_' + name;

      if (!cmdRaw) return null;

      // esc-вариант (на случай layout с экранированием)
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
        handler: asText(b.handler || '').trim() || '' // <-- важно для таблиц
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

    // 1) DB кнопки
    buttons.forEach(normalizeOne);

    // 2) Built-in pack
    builtins.forEach(normalizeOne);

    return meta;
  }


  function execCmd(inst, cmd, caller) {
    try {
      if (inst && typeof inst.execCommand === 'function') {
        inst.execCommand(cmd);
        return true;
      }
    } catch (e0) {}

    try {
      if (inst && inst.command && typeof inst.command.exec === 'function') {
        inst.command.exec(cmd);
        return true;
      }
    } catch (e1) {}

    try {
      if (window.jQuery && jQuery.sceditor && jQuery.sceditor.command) {
        var c = jQuery.sceditor.command.get(cmd);
        if (c && typeof c.exec === 'function') {
          c.exec.call(inst, caller || null);
          return true;
        }
      }
    } catch (e2) {}

    return false;
  }

  function execCmdWithAliases(inst, cmd, caller) {
    cmd = asText(cmd).trim();
    if (!cmd) return false;

    // 1) пробуем как есть
    if (execCmd(inst, cmd, caller)) return true;

    // 2) если это наша кастомная команда — пробуем альтернативы
    //    (на случай, если layout хранит escCss-вариант, а команда заведена raw, или наоборот)
    if (/^af_/i.test(cmd)) {
      // если cmd уже escCss-вида — откатить мы не можем (сырой name не восстановить),
      // но можем попробовать “ещё раз экранировать” (для случаев вроде af__ уже странных)
      var alt1 = 'af_' + escCss(cmd.slice(3));
      if (alt1 && alt1 !== cmd) {
        if (execCmd(inst, alt1, caller)) return true;
      }

      // и наоборот: если cmd выглядит как raw (есть символы), попробуем escCss-версию
      // (это покрывает самый частый кейс: af_my.button -> af_my_button)
      var alt2 = cmd.indexOf('.') !== -1 || cmd.indexOf(' ') !== -1 || /[^a-z0-9_\-]/i.test(cmd)
        ? ('af_' + escCss(cmd.slice(3)))
        : null;

      if (alt2 && alt2 !== cmd) {
        if (execCmd(inst, alt2, caller)) return true;
      }
    }

    return false;
  }

  function insertTagsFallback(inst, ta, open, close) {
    open = asText(open);
    close = asText(close);

    // 1) Нормальный путь: SCEditor умеет оборачивать выделение сам
    try {
      if (inst && typeof inst.insert === 'function') {
        inst.insert(open, close);
        return true;
      }
    } catch (e0) {}

    // 2) Фоллбэк: меняем значение через inst.val()
    // (курсор не идеален, но вставка гарантируется)
    try {
      if (inst && typeof inst.val === 'function') {
        var before = String(inst.val() || '');
        inst.val(before + open + close);
        return true;
      }
    } catch (e1) {}

    // 3) Самый последний фоллбэк: textarea напрямую (если она видима/жива)
    try {
      if (!ta) return false;

      var val = String(ta.value || '');
      var start = (typeof ta.selectionStart === 'number') ? ta.selectionStart : val.length;
      var end = (typeof ta.selectionEnd === 'number') ? ta.selectionEnd : val.length;

      ta.value = val.slice(0, start) + open + val.slice(start, end) + close + val.slice(end);

      var caret = start + open.length;
      try { ta.selectionStart = ta.selectionEnd = caret; } catch (e2) {}

      return true;
    } catch (e3) {}

    return false;
  }


  function escCss(s) {
    return String(s || '').replace(/[^a-z0-9_\-]/gi, '_');
  }

  function getForm() {
    return document.getElementById('quick_reply_form') || document.querySelector('form#quick_reply_form');
  }

  function getTA(form) {
    if (!form) return null;
    return form.querySelector('textarea#message') ||
      form.querySelector('textarea[name="message"]') ||
      null;
  }

  function getQuickReplyTbody(form) {
    if (!form) return null;
    return form.querySelector('#quickreply_e') || document.getElementById('quickreply_e');
  }

  function afAqrGetSceditorInstance(textarea) {
    try {
      if (window.jQuery && window.jQuery.fn && window.jQuery.fn.sceditor) {
        return window.jQuery(textarea).sceditor('instance') || null;
      }
    } catch (e) {}
    return null;
  }

  // НЕ НАЗЫВАЕМ это afAqrClearEditor, чтобы не перезатереть твою функцию ниже
  function afAqrClearEditorLite(form) {
    try {
      if (!form) return;
      var ta =
        form.querySelector('textarea#message') ||
        form.querySelector('textarea[name="message"]') ||
        null;
      if (!ta) return;

      var inst = afAqrGetSceditorInstance(ta);
      if (inst && typeof inst.val === 'function') {
        inst.val('');
      }

      ta.value = '';

      ta.dispatchEvent(new Event('input', { bubbles: true }));
      ta.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (e) {}
  }

  function afAqrFindPostsRoot() {
    return (
      document.getElementById('posts') ||
      document.getElementById('posts_container') ||
      document.querySelector('#content') ||
      document.body
    );
  }

  function afAqrNodeLooksLikeNewPost(node) {
    try {
      if (!node || node.nodeType !== 1) return false;

      var id = node.id || '';
      if (/^(post|pid)_\d+$/i.test(id)) return true;

      if (node.querySelector) {
        return !!node.querySelector('[id^="post_"],[id^="pid_"]');
      }
    } catch (e) {}
    return false;
  }

  function afAqrInstallAutoClearOnSubmit(form, ta) {
    if (!form || form.__afAqrAutoClearInstalled) return;
    form.__afAqrAutoClearInstalled = true;

    form.addEventListener(
      'submit',
      function () {
        var root = afAqrFindPostsRoot();
        if (!root || !window.MutationObserver) return;

        var cleared = false;
        var obs = new MutationObserver(function (mutations) {
          if (cleared) return;

          for (var i = 0; i < mutations.length; i++) {
            var m = mutations[i];
            if (!m.addedNodes || !m.addedNodes.length) continue;

            for (var j = 0; j < m.addedNodes.length; j++) {
              var n = m.addedNodes[j];
              if (afAqrNodeLooksLikeNewPost(n)) {
                cleared = true;
                try { obs.disconnect(); } catch (e) {}

                // ВАЖНО: используем ТВОЮ основную очистку, если она есть
                try {
                  if (typeof window.afAqrClearEditor === 'function') {
                    // если вдруг у тебя было как глобал (обычно нет)
                    window.afAqrClearEditor(form, ta || null);
                  } else if (typeof afAqrClearEditor === 'function') {
                    // твоя функция из этого же файла (ниже)
                    afAqrClearEditor(form, ta || (function(){ 
                      try { return form.querySelector('textarea#message') || form.querySelector('textarea[name="message"]') || null; }
                      catch(e){ return null; }
                    })());
                  } else {
                    // запасной вариант
                    afAqrClearEditorLite(form);
                  }
                } catch (e2) {
                  afAqrClearEditorLite(form);
                }

                return;
              }
            }
          }
        });

        obs.observe(root, { childList: true, subtree: true });

        setTimeout(function () {
          if (!cleared) {
            try { obs.disconnect(); } catch (e) {}
          }
        }, 8000);
      },
      true
    );
  }

  function getAnchorTable(form) {
    if (!form) return null;
    return form.querySelector('table.tborder') || form.querySelector('table');
  }

  function hasSceditor() {
    return !!(window.jQuery && jQuery.fn && jQuery.fn.sceditor);
  }

  function getInstance($ta) {
    try { return $ta.sceditor('instance'); } catch (e) { return null; }
  }

  function lsGetBool(key, defVal) {
    try {
      var v = localStorage.getItem(key);
      if (v === null || v === undefined) return !!defVal;
      return (v === '1' || v === 'true' || v === 'yes');
    } catch (e) { return !!defVal; }
  }

  function lsSetBool(key, val) {
    try { localStorage.setItem(key, val ? '1' : '0'); } catch (e) {}
  }

  function getPostKey(form) {
    if (!form) return '';
    var inp = form.querySelector('input[name="my_post_key"]') || form.querySelector('#my_post_key');
    if (inp && inp.value) return String(inp.value);
    if (cfg && cfg.postKey) return String(cfg.postKey);
    return '';
  }

  function getTid(form) {
    if (!form) return '';
    var inp = form.querySelector('input[name="tid"]');
    if (inp && inp.value) return String(inp.value);
    return '';
  }

  /* -------------------- SCEditor commands -------------------- */
  function ensureCommands() {
    if (!window.jQuery || !jQuery.sceditor || !jQuery.sceditor.command) return false;

    // ПАКИ САМИ РЕГИСТРИРУЮТ window.afAqrBuiltinHandlers[handlerName] в своих JS.
    // В core-файле НИКАКИХ встроенных UI/модалок/стилей.
    try {
      if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);
    } catch (e0) {}

    var metaByCmd = buildMetaByCmd();

    Object.keys(metaByCmd).forEach(function (cmd) {
      var m = metaByCmd[cmd];
      if (!m) return;

      try {
        if (jQuery.sceditor.command.exists && jQuery.sceditor.command.exists(cmd)) return;
      } catch (e0) {}

      var open = asText(m.opentag);
      var close = asText(m.closetag);

      // 1) BUILTIN HANDLER (из паков)
      if (m.handler) {
        jQuery.sceditor.command.set(cmd, {
          tooltip: m.title || cmd,
          exec: function (caller) {
            // если хендлер есть — выполняем
            try {
              if (window.afAqrBuiltinHandlers && typeof window.afAqrBuiltinHandlers[m.handler] === 'function') {
                window.afAqrBuiltinHandlers[m.handler](this, caller);
                return;
              }
            } catch (e1) {}

            // если хендлера НЕТ — мягко фоллбэчим в теги (если они есть)
            if (open || close) {
              try { this.insert(open, close); } catch (e2) {}
            }
          },
          txtExec: function (caller) {
            try {
              if (window.afAqrBuiltinHandlers && typeof window.afAqrBuiltinHandlers[m.handler] === 'function') {
                window.afAqrBuiltinHandlers[m.handler](this, caller);
                return;
              }
            } catch (e3) {}

            if (open || close) {
              try { this.insert(open, close); } catch (e4) {}
            }
          }
        });
        return;
      }

      // 2) DEFAULT: вставка тегов
      jQuery.sceditor.command.set(cmd, {
        tooltip: m.title || cmd,
        txtExec: [open, close],
        exec: function () {
          try { this.insert(open, close); } catch (e1) {}
        }
      });
    });

    // dropdown-команды (как было)
    if (cfg && cfg.toolbarLayout && cfg.toolbarLayout.sections && Array.isArray(cfg.toolbarLayout.sections)) {
      cfg.toolbarLayout.sections.forEach(function (sec, idx) {
        if (!sec || typeof sec !== 'object') return;
        if (String(sec.type || '').toLowerCase() !== 'dropdown') return;

        var id = String(sec.id || ('sec' + idx)).trim() || ('sec' + idx);
        var menuCmd = 'afmenu_' + id.replace(/[^a-z0-9_\-]/gi, '_');

        try {
          if (jQuery.sceditor.command.exists && jQuery.sceditor.command.exists(menuCmd)) return;
        } catch (e2) {}

        jQuery.sceditor.command.set(menuCmd, {
          tooltip: normalizeDropdownTooltip(sec.title || ''),
          exec: function () {},
          txtExec: function () {}
        });
      });
    }

    return true;
  }


  function injectIconCSS() {
    if (document.getElementById('af-aqr-iconcss')) return;

    function asText(x) { return String(x == null ? '' : x); }

    function looksLikeSvgUrl(u) {
      u = asText(u).trim().toLowerCase();
      if (!u) return false;
      return (u.indexOf('.svg') !== -1) || (u.indexOf('data:image/svg') === 0);
    }

    function iconSafe(s) {
      // безопасно для attribute selector
      return asText(s).replace(/["\\\]\n\r]/g, '');
    }

    var metaByCmd = buildMetaByCmd();
    var css = '';

    // СКОП: все тулбары SCEditor (не только quick reply)
    var SCOPE = '.sceditor-container .sceditor-toolbar ';

    // 0) ВАЖНО: делаем цвет/hover для ВСЕХ кнопок тулбара,
    // чтобы mask на currentColor реально менялся по hover
    css += ''
      + SCOPE + 'a.sceditor-button{'
      +   'color: var(--af-aqr-icon-color, var(--af-aqr-toolbar-icon, currentColor));'
      + '}\n';

    css += ''
      + SCOPE + 'a.sceditor-button:hover,'
      + SCOPE + 'a.sceditor-button.active,'
      + SCOPE + 'a.sceditor-button:focus{'
      +   'color: var(--af-aqr-icon-color-hover, var(--af-aqr-toolbar-icon-hover, currentColor));'
      + '}\n';

    // 1) База для ВСЕХ кнопок: геометрия div + убираем спрайты темы корректно
    css += ''
      + SCOPE + 'a.sceditor-button > div{'
      +   'text-indent:0;'
      +   'background-repeat:no-repeat;'
      +   'background-position:center;'
      +   'background-size:16px 16px;'
      +   'width:16px;'
      +   'height:16px;'
      +   'color: inherit;'
      + '}\n';

    // 2) Inline SVG (если кто-то вставляет) — слушает currentColor
    css += ''
      + SCOPE + 'a.sceditor-button svg{'
      +   'width:16px;'
      +   'height:16px;'
      +   'display:block;'
      +   'color: currentColor;'
      + '}\n';

    var maskOk = afAqrSupportsMask();

    // 3) СТАНДАРТНЫЕ команды SCEditor: иконки берём ИЗ AQR assets/img
    // (ты как раз их туда сложила)
    var stdMap = {
      bold: 'bold.svg',
      italic: 'italic.svg',
      underline: 'underline.svg',
      strike: 'strike.svg',

      font: 'font.svg',
      size: 'size.svg',
      color: 'color.svg',

      removeformat: 'removeformat.svg',
      undo: 'undo.svg',
      redo: 'redo.svg',

      left: 'left.svg',
      center: 'center.svg',
      right: 'right.svg',
      justify: 'justify.svg',

      bulletlist: 'bulletlist.svg',
      orderedlist: 'orderedlist.svg',

      quote: 'quote.svg',
      code: 'code.svg',
      link: 'link.svg',
      unlink: 'unlink.svg',
      email: 'email.svg',
      image: 'image.svg',

      source: 'source.svg',
      maximize: 'maximize.svg',

      horizontalrule: 'horizontalrule.svg',
      spoiler: 'spoiler.svg',
      table: 'table.svg',
      emoticon: 'emoticon.svg'
    };

    // Соберём селекторы для одного общего @supports (так чище)
    var maskSelectors = [];

    Object.keys(stdMap).forEach(function (cmd) {
      var file = stdMap[cmd];
      if (!file) return;

      // Абсолютный URL: /inc/plugins/.../assets/img/<file>
      var iconUrl = afAqrAppendRev(resolveIconUrl('img/' + file), cfg);
      iconUrl = iconUrl.replace(/"/g, '\\"');

      var sel = SCOPE + 'a.sceditor-button-' + iconSafe(cmd) + ' > div';

      // fallback: background-image
      css += ''
        + sel + '{'
        +   'background-image:url("' + iconUrl + '");'
        +   'background-repeat:no-repeat;'
        +   'background-position:center;'
        +   'background-size:16px 16px;'
        + '}\n';

      // маска: только если браузер умеет и это svg
      if (maskOk && looksLikeSvgUrl(iconUrl)) {
        maskSelectors.push(sel);
        // запомним сам url в CSS-переменной на конкретном div,
        // чтобы общий @supports не плодил урлы
        css += ''
          + sel + '{'
          +   '--af-aqr-std-mask:url("' + iconUrl + '");'
          + '}\n';
      }
    });

    // 4) AQR (af_*/afmenu_*) из БД/builtins: как и было — fallback + mask
    Object.keys(metaByCmd).forEach(function (cmd) {
      var m = metaByCmd[cmd];
      if (!m || !m.iconSpec || m.iconSpec.kind !== 'url') return;

      var icon = asText(m.iconSpec.value).trim();
      if (!icon) return;

      var iconUrl = afAqrAppendRev(resolveIconUrl(icon), cfg);
      iconUrl = iconUrl.replace(/"/g, '\\"');

      var sel = SCOPE + 'a[class*="sceditor-button-' + iconSafe(cmd) + '"] > div';

      css += ''
        + sel + '{'
        +   'background-image:url("' + iconUrl + '");'
        +   'background-repeat:no-repeat;'
        +   'background-position:center;'
        +   'background-size:16px 16px;'
        + '}\n';

      if (maskOk && looksLikeSvgUrl(iconUrl)) {
        css += ''
          + sel + '{'
          +   '--af-aqr-af-mask:url("' + iconUrl + '");'
          + '}\n';
      }
    });

    // 5) ЕДИНЫЙ mask-блок: красим через currentColor => hover снова живой
    if (maskOk) {
      css += ''
        + '@supports ((mask-image: url("x.svg")) or (-webkit-mask-image: url("x.svg"))) {\n';

      // стандартные
      if (maskSelectors.length) {
        css += maskSelectors.join(',\n') + '{'
          + 'background-image:none;'
          + 'background-color: currentColor;'
          + '-webkit-mask-image: var(--af-aqr-std-mask);'
          + 'mask-image: var(--af-aqr-std-mask);'
          + '-webkit-mask-repeat:no-repeat;'
          + 'mask-repeat:no-repeat;'
          + '-webkit-mask-position:center;'
          + 'mask-position:center;'
          + '-webkit-mask-size:16px 16px;'
          + 'mask-size:16px 16px;'
          + 'mask-mode: alpha;'
          + '-webkit-mask-mode: alpha;'
          + '}\n';
      }

      // кастомные (af_*/afmenu_*) — по переменной, если задана
      css += ''
        + SCOPE + 'a[class*="sceditor-button-af_"] > div,'
        + SCOPE + 'a[class*="sceditor-button-afmenu_"] > div{'
        + '}\n';

      css += ''
        + SCOPE + 'a[class*="sceditor-button-af_"] > div{'
        + '}\n';

      // универсально: если переменная --af-aqr-af-mask есть, используем её
      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      // отдельным правилом — только когда переменная есть (иначе не трогаем)
      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        + '}\n';

      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        + '}\n';

      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        + '}\n';

      // Практичный способ: применяем mask по атрибуту style-переменной
      css += ''
        + SCOPE + 'a.sceditor-button > div[style*="--af-aqr-af-mask"],'
        + SCOPE + 'a.sceditor-button > div[style*="--af-aqr-std-mask"]{'
        + '}\n';

      // А теперь нормальный — через var(), без привязки к style:
      // (работает, когда переменные заданы в CSS-правилах выше)
      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      // Объединяем: если есть std-mask — берём его, иначе если есть af-mask — берём его
      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '-webkit-mask-image: var(--af-aqr-std-mask, var(--af-aqr-af-mask, none));'
        +   'mask-image: var(--af-aqr-std-mask, var(--af-aqr-af-mask, none));'
        +   '-webkit-mask-repeat:no-repeat;'
        +   'mask-repeat:no-repeat;'
        +   '-webkit-mask-position:center;'
        +   'mask-position:center;'
        +   '-webkit-mask-size:16px 16px;'
        +   'mask-size:16px 16px;'
        +   'mask-mode: alpha;'
        +   '-webkit-mask-mode: alpha;'
        + '}\n';

      // И красим только там, где mask реально есть
      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      // финальный штрих: если mask-image не none — выключаем bg-image и включаем заливку
      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      // (делаем просто и надёжно — повторим явное правило)
      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   'background-color: transparent;'
        + '}\n';

      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      // Реально рабочий критерий без плясок: если у div есть любая из переменных — красим.
      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      // Окей, хватит “пустых” страховок — делаем коротко и жёстко:
      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      // Последняя финальная строка:
      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      // И вот это — то, что действительно нужно:
      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   '}\n';

      // Ладно. Без магии: применяем заливку и убираем bg-image ВСЕГДА в @supports,
      // но только если mask-image не none (браузер сам игнорит none).
      css += ''
        + SCOPE + 'a.sceditor-button > div{'
        +   'background-image:none;'
        +   'background-color: currentColor;'
        + '}\n';

      css += '}\n';
    }

    var st = document.createElement('style');
    st.type = 'text/css';
    st.id = 'af-aqr-iconcss';
    st.appendChild(document.createTextNode(css));
    document.head.appendChild(st);
  }

  function afAqrEnsureToolbarIconColor(container) {
    try {
      if (!container) return;

      var tb = container.querySelector('.sceditor-toolbar');
      if (!tb) return;

      // 1) Если уже задано inline — уважаем и не трогаем
      try {
        var inlineVal = tb.style.getPropertyValue('--af-aqr-icon-color');
        if (String(inlineVal || '').trim()) return;
      } catch (e0) {}

      // 2) Если тема/наш CSS уже задаёт computed --af-aqr-icon-color — НЕ ПЕРЕЗАТИРАЕМ
      //    (это твой кейс: ты хочешь фиксированный цвет, а не авто-выбор по фону)
      var csTb = null;
      try { csTb = getComputedStyle(tb); } catch (e1) { csTb = null; }

      if (csTb) {
        var computedIcon = String(csTb.getPropertyValue('--af-aqr-icon-color') || '').trim();
        if (computedIcon) return;

        // 3) Если задан --af-aqr-toolbar-icon — используем его как icon-color (inline),
        //    чтобы маски/inline-style в JS тоже имели значение.
        var toolbarIcon = String(csTb.getPropertyValue('--af-aqr-toolbar-icon') || '').trim();
        if (toolbarIcon) {
          tb.style.setProperty('--af-aqr-icon-color', toolbarIcon);

          var toolbarHover = String(csTb.getPropertyValue('--af-aqr-toolbar-icon-hover') || '').trim();
          if (toolbarHover) tb.style.setProperty('--af-aqr-icon-color-hover', toolbarHover);

          return;
        }
      }

      // 4) Фоллбэк авто-подбор (только если вообще нет ни одной переменной)
      function parseRgb(s) {
        s = String(s || '').trim();
        var m = s.match(/rgba?\(\s*([0-9.]+)\s*,\s*([0-9.]+)\s*,\s*([0-9.]+)(?:\s*,\s*([0-9.]+))?\s*\)/i);
        if (m) return { r: +m[1], g: +m[2], b: +m[3], a: (m[4] == null ? 1 : +m[4]) };
        return null;
      }

      function isTransparentRgb(x) {
        return !x || (typeof x.a === 'number' && x.a <= 0.02);
      }

      function getBg(el) {
        var cur = el;
        for (var i = 0; i < 10 && cur; i++) {
          var cs = null;
          try { cs = getComputedStyle(cur); } catch (e2) { cs = null; }
          if (cs) {
            var bg = parseRgb(cs.backgroundColor);
            if (bg && !isTransparentRgb(bg)) return bg;
          }
          cur = cur.parentElement;
        }
        return null;
      }

      var bgc = getBg(tb) || getBg(container) || getBg(document.body);

      var r = bgc ? bgc.r : 255;
      var g = bgc ? bgc.g : 255;
      var b = bgc ? bgc.b : 255;

      var lum = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;

      var chosen = (lum < 0.48) ? 'rgba(255,255,255,.92)' : 'rgba(0,0,0,.72)';
      tb.style.setProperty('--af-aqr-icon-color', chosen);
    } catch (e3) {}
  }

  try {
    window.afAqrInjectIconCSS = injectIconCSS;
  } catch (e0) {}

  function buildAddonToolbarChunk() {
    var add = buttons.map(function (b) { return 'af_' + escCss(b.name); }).join(',');
    return add ? (',|,' + add) : '';
  }

  function uniqId(prefix) {
    return (prefix || 'id') + '_' + Math.random().toString(16).slice(2) + Date.now().toString(16);
  }

  function isObj(x) { return x && typeof x === 'object' && !Array.isArray(x); }

  function normalizeCmd(s) {
    return String(s || '').trim();
  }

  // строим toolbar string + словарь dropdown-меню
  function buildToolbarFromLayout(layout) {
    var res = { toolbar: '', menus: [] };

    if (!layout || !isObj(layout) || !Array.isArray(layout.sections)) return res;

    var parts = [];

    layout.sections.forEach(function (sec, idx) {
      if (!sec || !isObj(sec)) return;

      var type = String(sec.type || 'group').toLowerCase();
      var id = String(sec.id || ('sec' + idx)).trim() || ('sec' + idx);
      var title = String(sec.title || '').trim();

      // items могут содержать и "виртуальный" разделитель "|"
      var items = Array.isArray(sec.items) ? sec.items.slice() : [];

      if (type === 'dropdown') {
        // dropdown — это ОДНА кнопка на тулбаре
        var menuCmd = 'afmenu_' + id.replace(/[^a-z0-9_\-]/gi, '_');
        parts.push(menuCmd);

        // а вот его содержимое — отдельно
        res.menus.push({
          id: id,
          cmd: menuCmd,
          title: title || '★',
          items: items.map(normalizeCmd).filter(Boolean)
        });

        return;
      }

      // group: собираем группу кнопок
      var group = [];
      items.forEach(function (it) {
        var v = normalizeCmd(it);
        if (!v) return;

        // допускаем явный разделитель группы внутри группы
        if (v === '|') {
          if (group.length) {
            parts.push(group.join(','));
            group = [];
          }
          parts.push('|');
          return;
        }

        group.push(v);
      });

      if (group.length) parts.push(group.join(','));
      // между секциями делаем разделитель групп
      if (idx !== layout.sections.length - 1) parts.push('|');
    });

    // подчистим "||" и "|," артефакты
    var toolbar = parts.join(',');
    toolbar = toolbar.replace(/,+\|,+/g, '|');
    toolbar = toolbar.replace(/\|{2,}/g, '|');
    toolbar = toolbar.replace(/^,|,$/g, '');
    toolbar = toolbar.replace(/^\|+|\|+$/g, '');

    res.toolbar = toolbar;
    return res;
  }

  function parseToolbarString(toolbar) {
    toolbar = String(toolbar || '');
    if (!toolbar) return [];

    var groups = toolbar.split('|').map(function (group) {
      return group.split(',').map(function (cmd) {
        return String(cmd || '').trim();
      }).filter(Boolean);
    }).filter(function (group) {
      return group.length > 0;
    });

    return groups;
  }

  function toolbarGroupsToString(groups) {
    if (!groups || !groups.length) return '';
    return groups.map(function (group) { return group.join(','); }).join('|');
  }

  function filterToolbar(toolbar, predicate) {
    var groups = parseToolbarString(toolbar);
    var out = [];
    groups.forEach(function (group) {
      var filtered = group.filter(function (cmd) {
        return predicate(cmd);
      });
      if (filtered.length) out.push(filtered);
    });
    return toolbarGroupsToString(out);
  }

  function isCustomCmd(cmd) {
    cmd = String(cmd || '').trim();
    if (!cmd) return false;
    return /^af_/i.test(cmd) || /^afmenu_/i.test(cmd);
  }

  function buildCustomToolbar(layout) {
    var built = buildToolbarFromLayout(layout);
    return filterToolbar(built.toolbar || '', isCustomCmd);
  }

  function mergeToolbarStrings(baseToolbar, extraToolbar) {
    var baseGroups = parseToolbarString(baseToolbar);
    var extraGroups = parseToolbarString(extraToolbar);

    if (!extraGroups.length) return toolbarGroupsToString(baseGroups);

    var baseSet = Object.create(null);
    baseGroups.forEach(function (group) {
      group.forEach(function (cmd) {
        baseSet[cmd] = true;
      });
    });

    var filteredExtra = [];
    extraGroups.forEach(function (group) {
      var filtered = group.filter(function (cmd) {
        return !baseSet[cmd];
      });
      if (filtered.length) filteredExtra.push(filtered);
    });

    if (!filteredExtra.length) return toolbarGroupsToString(baseGroups);

    var merged = baseGroups.concat(filteredExtra);
    return toolbarGroupsToString(merged);
  }

  function getGlobalToolbarFallback() {
    try {
      if (window.sceditor_opts && typeof window.sceditor_opts === 'object') {
        return String(window.sceditor_opts.toolbar || '');
      }
    } catch (e0) {}

    try {
      if (window.sceditorOptions && typeof window.sceditorOptions === 'object') {
        return String(window.sceditorOptions.toolbar || '');
      }
    } catch (e1) {}

    return '';
  }

  function ensureDropdownMenus(form, ta, inst, menus) {
    if (!form || !ta || !inst || !menus || !menus.length) return;

    var metaByCmd = buildMetaByCmd();

    // контейнер тулбара
    var container = null;
    try {
      var prev = ta.previousElementSibling;
      if (prev && prev.classList && prev.classList.contains('sceditor-container')) container = prev;
    } catch (e0) {}
    if (!container) container = form.querySelector('.sceditor-container');
    if (!container) return;

    var toolbar = container.querySelector('.sceditor-toolbar');
    if (!toolbar) return;

    // гарантируем цвет для mask-иконок (SVG по URL) на тулбаре
    try { afAqrEnsureToolbarIconColor(container); } catch (eColor) {}

    function looksLikeSvgUrl(u) {
      u = asText(u).trim().toLowerCase();
      if (!u) return false;
      return (u.indexOf('.svg') !== -1) || (u.indexOf('data:image/svg') === 0);
    }

    // Принуждаем SVG слушать currentColor (для dropdown-кнопки на тулбаре, если там SVG)
    function forceSvgCurrentColor(root) {
      if (!root) return;
      var svg = root.querySelector ? root.querySelector('svg') : null;
      if (!svg) return;

      try { svg.style.color = 'currentColor'; } catch (e0) {}

      var nodes = svg.querySelectorAll('*');
      for (var i = 0; i < nodes.length; i++) {
        var el = nodes[i];
        if (!el || !el.getAttribute) continue;

        var fill = el.getAttribute('fill');
        var stroke = el.getAttribute('stroke');

        if (fill && String(fill).toLowerCase() === 'none') {
          // keep
        } else {
          try { el.setAttribute('fill', 'currentColor'); } catch (e1) {}
        }

        if (stroke && String(stroke).toLowerCase() === 'none') {
          // keep
        } else {
          try { el.setAttribute('stroke', 'currentColor'); } catch (e2) {}
        }
      }
    }

    function applyUrlIcon(el, url, sizePx) {
      url = asText(url).trim();
      if (!url) return;

      var sz = (sizePx || 16) + 'px ' + (sizePx || 16) + 'px';
      var esc = url.replace(/"/g, '\\"');

      el.style.display = 'flex';
      el.style.alignItems = 'center';
      el.style.justifyContent = 'center';
      el.style.width = (sizePx || 16) + 'px';
      el.style.height = (sizePx || 16) + 'px';
      el.style.lineHeight = (sizePx || 16) + 'px';
      el.style.padding = '0';
      el.style.textIndent = '0';
      el.style.backgroundRepeat = 'no-repeat';
      el.style.backgroundPosition = 'center';
      el.style.backgroundSize = sz;

      el.style.backgroundImage = 'none';
      el.style.backgroundColor = 'transparent';

      el.style.webkitMaskImage = 'none';
      el.style.maskImage = 'none';
      el.style.webkitMaskRepeat = '';
      el.style.maskRepeat = '';
      el.style.webkitMaskPosition = '';
      el.style.maskPosition = '';
      el.style.webkitMaskSize = '';
      el.style.maskSize = '';

      try { el.style.setProperty('mask-mode', 'alpha'); } catch (e0) {}
      try { el.style.setProperty('-webkit-mask-mode', 'alpha'); } catch (e1) {}

      if (looksLikeSvgUrl(url)) {
        el.style.webkitMaskImage = 'url("' + esc + '")';
        el.style.maskImage = 'url("' + esc + '")';
        el.style.webkitMaskRepeat = 'no-repeat';
        el.style.maskRepeat = 'no-repeat';
        el.style.webkitMaskPosition = 'center';
        el.style.maskPosition = 'center';
        el.style.webkitMaskSize = sz;
        el.style.maskSize = sz;
        el.style.backgroundColor = 'currentColor';
      } else {
        el.style.backgroundImage = 'url("' + esc + '")';
      }
    }

    // Фоллбэк-мета для стандартных команд SCEditor (НУЖНО для insertTagsFallback)
    function builtinFallbackMeta(cmd) {
      cmd = asText(cmd).trim().toLowerCase();
      if (!cmd) return null;

      var map = {
        bold:        { title: 'Жирный',            icon: 'sceditor:img/bold.svg' },
        italic:      { title: 'Курсив',            icon: 'sceditor:img/italic.svg' },
        underline:   { title: 'Подчёркнутый',      icon: 'sceditor:img/underline.svg' },
        strike:      { title: 'Зачёркнутый',       icon: 'sceditor:img/strike.svg' },

        font:        { title: 'Шрифт',             icon: 'sceditor:img/font.svg' },
        size:        { title: 'Размер',            icon: 'sceditor:img/size.svg' },
        color:       { title: 'Цвет',              icon: 'sceditor:img/color.svg' },

        removeformat:{ title: 'Очистить формат',   icon: 'sceditor:img/removeformat.svg' },
        undo:        { title: 'Отменить',          icon: 'sceditor:img/undo.svg' },
        redo:        { title: 'Повторить',         icon: 'sceditor:img/redo.svg' },

        left:        { title: 'По левому краю',    icon: 'sceditor:img/left.svg' },
        center:      { title: 'По центру',         icon: 'sceditor:img/center.svg' },
        right:       { title: 'По правому краю',   icon: 'sceditor:img/right.svg' },
        justify:     { title: 'По ширине',         icon: 'sceditor:img/justify.svg' },

        bulletlist:  { title: 'Маркированный список', icon: 'sceditor:img/bulletlist.svg' },
        orderedlist: { title: 'Нумерованный список',  icon: 'sceditor:img/orderedlist.svg' },

        quote:       { title: 'Цитата',            icon: 'sceditor:img/quote.svg' },
        code:        { title: 'Код',               icon: 'sceditor:img/code.svg' },
        link:        { title: 'Ссылка',            icon: 'sceditor:img/link.svg' },
        unlink:      { title: 'Убрать ссылку',     icon: 'sceditor:img/unlink.svg' },
        email:       { title: 'Email',             icon: 'sceditor:img/email.svg' },
        image:       { title: 'Изображение',       icon: 'sceditor:img/image.svg' },

        source:      { title: 'Источник',          icon: 'sceditor:img/source.svg' },
        maximize:    { title: 'На весь экран',     icon: 'sceditor:img/maximize.svg' },

        horizontalrule: { title: 'Горизонтальная линия', icon: 'sceditor:img/horizontalrule.svg' },
        spoiler:        { title: 'Спойлер',              icon: 'sceditor:img/spoiler.svg' },
        table:          { title: 'Таблица',              icon: 'sceditor:img/table.svg' },
        emoticon:       { title: 'Смайлы',               icon: 'sceditor:img/emoticon.svg' }
      };

      if (map[cmd]) {
        return {
          title: map[cmd].title || cmd,
          iconSpec: { kind: 'url', value: resolveIconUrl(map[cmd].icon) },
          opentag: '',
          closetag: ''
        };
      }

      if (/^[a-z0-9_-]+$/i.test(cmd)) {
        return {
          title: cmd,
          iconSpec: { kind: 'url', value: resolveIconUrl('sceditor:img/' + cmd + '.svg') },
          opentag: '',
          closetag: ''
        };
      }

      return null;
    }

    function svgStarMarkup() {
      return '' +
        '<svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
        '<path d="M12 17.3l-6.18 3.73 1.64-7.03L2 9.24l7.19-.62L12 2l2.81 6.62 7.19.62-5.46 4.76 1.64 7.03z"></path>' +
        '</svg>';
    }

    function renderMenuTitle(title) {
      title = asText(title).trim();

      if (isSvgMarkupSafe(title)) return { kind: 'svg', value: title };

      if (looksLikeUrl(title) || looksLikeAssetPath(title)) {
        return { kind: 'url', value: resolveIconUrl(title) };
      }

      if (title) return { kind: 'text', value: title };

      return { kind: 'svg', value: svgStarMarkup() };
    }

    function closeAll() {
      menus.forEach(function (m) {
        if (m._menuEl) m._menuEl.style.display = 'none';
        if (m._btnEl) m._btnEl.classList && m._btnEl.classList.remove('is-open');
      });
    }

    function isInsideAnyDropdown(target) {
      for (var i = 0; i < menus.length; i++) {
        var mm = menus[i];
        if (mm && mm._btnEl && mm._btnEl.contains && mm._btnEl.contains(target)) return true;
        if (mm && mm._menuEl && mm._menuEl.contains && mm._menuEl.contains(target)) return true;
      }
      return false;
    }

    function handleOutsideEvent(ev) {
      try {
        if (!document.body || !document.body.contains(form)) return;
        var t = ev && ev.target ? ev.target : null;
        if (!t) { closeAll(); return; }
        if (isInsideAnyDropdown(t)) return;
        closeAll();
      } catch (e) {}
    }

    function bindIframeOutsideCloser() {
      try {
        var ifr = container.querySelector('iframe');
        if (!ifr) return;

        if (ifr.dataset && ifr.dataset.afAqrOutsideBound === '1') return;

        var doc = null;
        try { doc = ifr.contentDocument || (ifr.contentWindow ? ifr.contentWindow.document : null); } catch (e0) { doc = null; }
        if (!doc) {
          try {
            ifr.addEventListener('load', function () {
              try {
                if (ifr.dataset) ifr.dataset.afAqrOutsideBound = '0';
                bindIframeOutsideCloser();
              } catch (e1) {}
            }, { once: true });
          } catch (e2) {}
          return;
        }

        if (ifr.dataset) ifr.dataset.afAqrOutsideBound = '1';

        doc.addEventListener('pointerdown', function () { closeAll(); }, true);
        doc.addEventListener('mousedown', function () { closeAll(); }, true);
        doc.addEventListener('touchstart', function () { closeAll(); }, { passive: true, capture: true });
        doc.addEventListener('click', function () { closeAll(); }, true);
      } catch (e3) {}
    }

    if (!form._afAqrDropBound) {
      form._afAqrDropBound = true;

      document.addEventListener('pointerdown', handleOutsideEvent, true);
      document.addEventListener('mousedown', handleOutsideEvent, true);
      document.addEventListener('touchstart', handleOutsideEvent, { passive: true, capture: true });
      document.addEventListener('click', handleOutsideEvent, true);

      document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') closeAll();
      }, true);

      window.addEventListener('blur', function () { closeAll(); }, true);
    }

    bindIframeOutsideCloser();

    menus.forEach(function (m) {
      if (!m || !m.cmd) return;

      var btn = toolbar.querySelector('a.sceditor-button-' + m.cmd);
      if (!btn) return;
      m._btnEl = btn;

      try {
        var tip = normalizeDropdownTooltip(m.title || '');
        btn.setAttribute('title', tip);
        btn.setAttribute('aria-label', tip);
      } catch (eTip) {}

      // кнопка dropdown на тулбаре (оставляем как было: может быть svg/url/текст)
      try {
        var div = btn.querySelector('div');
        if (div) {
          div.style.backgroundImage = 'none';
          div.style.background = 'none';
          div.style.display = 'flex';
          div.style.alignItems = 'center';
          div.style.justifyContent = 'center';
          div.style.width = '16px';
          div.style.height = '16px';
          div.style.lineHeight = '16px';
          div.style.padding = '0';
          div.style.textIndent = '0';
          div.style.color = 'inherit';

          var tSpec = renderMenuTitle(m.title);

          div.innerHTML = '';
          div.textContent = '';
          div.style.backgroundImage = 'none';
          div.style.background = 'none';
          div.style.textIndent = '0';

          div.style.width = '16px';
          btn.style.width = '';
          btn.style.minWidth = '';
          btn.style.padding = '';

          if (tSpec.kind === 'url') {
            applyUrlIcon(div, tSpec.value, 16);
          } else if (tSpec.kind === 'svg') {
            div.style.width = '16px';
            div.innerHTML = tSpec.value;
            forceSvgCurrentColor(div);
          } else {
            var txt = asText(tSpec.value).trim();
            div.textContent = txt;

            div.style.fontSize = '12px';
            div.style.fontWeight = '700';

            div.style.width = 'auto';
            div.style.padding = '0 6px';

            btn.style.width = 'auto';
            btn.style.minWidth = '16px';
            btn.style.padding = '0 2px';
          }
        }
      } catch (e1) {}

      if (!m._menuEl) {
        var menu = document.createElement('div');
        menu.className = 'af-aqr-ddmenu';
        menu.style.position = 'absolute';
        menu.style.display = 'none';
        menu.style.zIndex = '9999';
        menu.style.minWidth = '220px';

        menu.style.background = '#1f1f1f';
        menu.style.border = '1px solid rgba(255,255,255,.12)';
        menu.style.borderRadius = '10px';
        menu.style.padding = '6px';
        menu.style.boxShadow = '0 12px 30px rgba(0,0,0,.35)';

        var list = document.createElement('div');
        list.className = 'af-aqr-ddlist';

        (m.items || []).forEach(function (cmd) {
          cmd = normalizeCmd(cmd);
          if (!cmd || cmd === '|') return;

          // 1) мета (DB/builtins)
          var meta = metaByCmd[cmd] || null;

          // 2) fallback для стандартных команд SCEditor
          if (!meta) meta = builtinFallbackMeta(cmd);

          // 3) если всё равно пусто — минимальная заглушка
          if (!meta) {
            meta = { title: cmd, iconSpec: { kind: 'empty', value: '' }, opentag: '', closetag: '' };
          }

          // === ВАЖНО: пункт меню ТОЛЬКО ТЕКСТ, БЕЗ ИКОНОК ===
          var item = document.createElement('a');
          item.href = '#';
          item.className = 'af-aqr-dditem';

          item.style.display = 'block';
          item.style.padding = '8px 10px';
          item.style.borderRadius = '8px';
          item.style.textDecoration = 'none';
          item.style.opacity = '0.95';
          item.style.color = '#fff';

          var title = document.createElement('span');
          title.className = 'af-aqr-ddtitle';
          title.textContent = asText(meta.title || cmd);
          title.style.color = '#fff';

          item.appendChild(title);

          item.addEventListener('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();

            var instNow = null;
            try { instNow = getInstance(jQuery(ta)); } catch (e0) { instNow = null; }

            try { instNow && instNow.focus && instNow.focus(); } catch (e1) {}

            var before = '';
            try { before = readEditorValue(form, ta); } catch (e2) { before = ''; }

            if (instNow) {
              execCmdWithAliases(instNow, cmd, btn);
            }

            setTimeout(function () {
              try {
                if (!document.body || !document.body.contains(form)) return;

                var after = '';
                try { after = readEditorValue(form, ta); } catch (e3) { after = ''; }

                if (after === before) {
                  var mm = metaByCmd[cmd];
                  if (!mm) mm = builtinFallbackMeta(cmd);

                  if (!mm && /^af_/i.test(cmd)) {
                    var alt = 'af_' + escCss(cmd.slice(3));
                    mm = metaByCmd[alt] || null;
                  }

                  if (mm) {
                    insertTagsFallback(instNow, ta, mm.opentag || '', mm.closetag || '');
                  }
                }

                try { instNow && instNow.focus && instNow.focus(); } catch (e4) {}

                try { updateCounter(form, ta); } catch (e5) {}
                try {
                  var p = ta._afAqrPreview;
                  if (p && p.enabled) schedulePreview(form, ta);
                } catch (e6) {}
              } catch (e7) {}
            }, 0);

            closeAll();
          }, { passive: false });

          list.appendChild(item);
        });

        if (!list.firstChild) {
          var empty = document.createElement('div');
          empty.textContent = 'Пусто';
          empty.style.padding = '8px 10px';
          empty.style.color = 'rgba(255,255,255,.65)';
          list.appendChild(empty);
        }

        menu.appendChild(list);
        document.body.appendChild(menu);
        m._menuEl = menu;
      }

      if (!btn.dataset.afAqrDdBound) {
        btn.dataset.afAqrDdBound = '1';

        var toggle = function (ev) {
          ev.preventDefault();
          ev.stopPropagation();

          closeAll();

          var r = btn.getBoundingClientRect();
          var top = r.bottom + window.scrollY + 6;
          var left = r.left + window.scrollX;

          m._menuEl.style.top = top + 'px';
          m._menuEl.style.left = left + 'px';
          m._menuEl.style.display = 'block';

          btn.classList && btn.classList.add('is-open');
        };

        btn.addEventListener('click', toggle, { passive: false, capture: true });
        btn.addEventListener('mousedown', toggle, { passive: false, capture: true });
        btn.addEventListener('pointerdown', toggle, { passive: false, capture: true });
      }
    });
  }

  /* -------------------- layout helpers (СТАБИЛЬНОЕ) -------------------- */

  function removeLeftColumn(form, ta) {
    if (!form || !ta) return;

    var tr = ta.closest('tr');
    if (!tr) return;

    var tds = tr.querySelectorAll('td');
    if (!tds || tds.length < 2) return;

    try { tds[0].parentNode && tds[0].parentNode.removeChild(tds[0]); } catch (e) {}

    try {
      var td = tr.querySelector('td');
      if (td) {
        td.setAttribute('colspan', '2');
        td.style.width = '100%';
      }
    } catch (e2) {}
  }

  function bindSceditorResizeSync(container) {
    if (!container) return;

    if (container._afAqrResizeSyncBound) return;
    container._afAqrResizeSyncBound = true;

    var LS_KEY = 'af_aqr_editor_area_h';

    var DEFAULT_AREA_H = 180; // старт (если нет сохранённого)
    var MIN_AREA_H = 180;     // минимум 100-150, берём 150
    var MAX_AREA_H = 2000;    // страховка

    var ro = null;
    var timer = null;

    function num(x) {
      x = parseFloat(x);
      return isFinite(x) ? x : 0;
    }

    function clamp(v, a, b) {
      v = parseInt(v, 10);
      if (!isFinite(v)) v = a;
      if (v < a) v = a;
      if (v > b) v = b;
      return v;
    }

    function getSavedAreaH() {
      try {
        var v = localStorage.getItem(LS_KEY);
        if (v == null) return DEFAULT_AREA_H;
        return clamp(v, MIN_AREA_H, MAX_AREA_H);
      } catch (e) {
        return DEFAULT_AREA_H;
      }
    }

    function saveAreaH(h) {
      try { localStorage.setItem(LS_KEY, String(clamp(h, MIN_AREA_H, MAX_AREA_H))); } catch (e) {}
    }

    function getMetrics() {
      var toolbar = container.querySelector('.sceditor-toolbar');
      var cs = null;
      try { cs = window.getComputedStyle ? getComputedStyle(container) : null; } catch (e0) { cs = null; }

      var padTop = cs ? num(cs.paddingTop) : 0;
      var padBot = cs ? num(cs.paddingBottom) : 0;
      var borTop = cs ? num(cs.borderTopWidth) : 0;
      var borBot = cs ? num(cs.borderBottomWidth) : 0;

      var toolbarH = 0;
      if (toolbar) {
        var tr = toolbar.getBoundingClientRect();
        toolbarH = Math.floor(tr.height || 0);
      }

      var chrome = Math.floor(padTop + padBot + borTop + borBot);
      return { toolbarH: toolbarH, chrome: chrome };
    }

    function applyFillRules() {
      if (!document.body || !document.body.contains(container)) return;

      // контейнер — колонка
      container.style.display = 'flex';
      container.style.flexDirection = 'column';
      container.style.alignItems = 'stretch';
      container.style.boxSizing = 'border-box';
      container.style.maxHeight = 'none';

      var toolbar = container.querySelector('.sceditor-toolbar');
      if (toolbar) {
        toolbar.style.flex = '0 0 auto';
        toolbar.style.width = '100%';
        toolbar.style.boxSizing = 'border-box';
        toolbar.style.position = 'relative';
        toolbar.style.zIndex = '5';
      }

      var iframe = container.querySelector('iframe');
      var srcTa = container.querySelector('textarea');

      // КЛЮЧ: НЕ фиксируем height в px — даём flex заполнить место.
      if (iframe) {
        iframe.style.flex = '1 1 auto';
        iframe.style.width = '100%';
        iframe.style.boxSizing = 'border-box';
        iframe.style.minHeight = MIN_AREA_H + 'px';
        iframe.style.height = 'auto';
        iframe.style.position = 'relative';
        iframe.style.top = 'auto';
        iframe.style.left = 'auto';
        iframe.style.zIndex = '1';
      }

      if (srcTa) {
        srcTa.style.flex = '1 1 auto';
        srcTa.style.width = '100%';
        srcTa.style.boxSizing = 'border-box';
        srcTa.style.minHeight = MIN_AREA_H + 'px';
        srcTa.style.height = 'auto';
      }

      // режимы отображения
      if (container.classList && container.classList.contains('sourceMode')) {
        if (iframe) iframe.style.display = 'none';
        if (srcTa) srcTa.style.display = 'block';
      } else {
        if (srcTa) srcTa.style.display = 'none';
        if (iframe) iframe.style.display = 'block';
      }
    }

    function ensureMinTotalHeight() {
      var m = getMetrics();
      var minTotal = m.toolbarH + m.chrome + MIN_AREA_H;

      // если контейнер слишком низкий — поднимем до минимума
      var r = container.getBoundingClientRect();
      var totalH = Math.floor(r.height || 0);

      // важно: НЕ лезем, если пользователь уже растянул больше
      if (totalH && totalH < minTotal) {
        container.style.minHeight = minTotal + 'px';
        container.style.height = minTotal + 'px';
      } else {
        // минимум всё равно ставим, чтобы нельзя было ужать в 150px ад
        container.style.minHeight = minTotal + 'px';
      }
    }

    function setInitialHeightFromLS() {
      var m = getMetrics();
      var areaH = getSavedAreaH();
      var total = m.toolbarH + m.chrome + areaH;

      // не навязываем, если тема уже дала большую высоту
      var r = container.getBoundingClientRect();
      var cur = Math.floor(r.height || 0);
      if (!cur || cur < (m.toolbarH + m.chrome + MIN_AREA_H)) {
        container.style.height = total + 'px';
      } else if (cur < total) {
        // если текущая меньше сохранённой — аккуратно поднимем
        container.style.height = total + 'px';
      }
    }

    function computeAreaFromContainer() {
      var m = getMetrics();
      var r = container.getBoundingClientRect();
      var totalH = Math.floor(r.height || 0);
      if (!totalH) return null;

      var areaH = totalH - m.toolbarH - m.chrome;
      return clamp(areaH, MIN_AREA_H, MAX_AREA_H);
    }

    function schedulePersist() {
      if (timer) return;
      timer = setTimeout(function () {
        timer = null;
        try {
          var area = computeAreaFromContainer();
          if (area != null) {
            container.dataset.afAqrAreaH = String(area);
            saveAreaH(area);
          }
        } catch (e0) {}
      }, 120);
    }

    // Публичные хелперы (если где-то дергаешь)
    container._afAqrResizeSyncApplyNow = function () {
      try { applyFillRules(); ensureMinTotalHeight(); } catch (e) {}
    };
    container._afAqrResizeSyncPersist = function () {
      try { schedulePersist(); } catch (e) {}
    };

    // ResizeObserver — при любом изменении размеров просто переубеждаемся, что flex-правила на месте
    if (window.ResizeObserver) {
      try {
        ro = new ResizeObserver(function () {
          try {
            applyFillRules();
            ensureMinTotalHeight();
            // сохраняем после ресайза (и по высоте, и если тема/скрипт дёрнул)
            schedulePersist();
          } catch (e0) {}
        });
        ro.observe(container);
        container._afAqrResizeSyncRO = ro;
      } catch (e1) {
        ro = null;
      }
    }

    // Подстраховка: когда пользователь отпускает grip — сохраним высоту
    (function bindGripPersist() {
      var tries = 0;
      (function tick() {
        if (!document.body || !document.body.contains(container)) return;

        var grip = container.querySelector('.sceditor-grip');
        if (grip) {
          if (!grip.dataset.afAqrPersistBound) {
            grip.dataset.afAqrPersistBound = '1';
            grip.addEventListener('pointerup', function () { schedulePersist(); }, true);
            grip.addEventListener('mouseup', function () { schedulePersist(); }, true);
            grip.addEventListener('touchend', function () { schedulePersist(); }, true);
          }
          return;
        }

        tries++;
        if (tries < 20) setTimeout(tick, 150);
      })();
    })();

    // первичная настройка
    setTimeout(function () {
      try { applyFillRules(); } catch (e0) {}
      try { setInitialHeightFromLS(); } catch (e1) {}
      try { ensureMinTotalHeight(); } catch (e2) {}
      try { schedulePersist(); } catch (e3) {}
    }, 0);

    setTimeout(function () {
      try { applyFillRules(); ensureMinTotalHeight(); } catch (e4) {}
    }, 200);
  }

  function bindSceditorGripResize(container) {
    if (!container) return;
    if (container._afAqrGripBound) return;
    container._afAqrGripBound = true;

    var tries = 0;
    (function tick() {
      if (!document.body || !document.body.contains(container)) return;

      var grip = container.querySelector('.sceditor-grip');
      if (grip) {
        // диагональный курсор — как ожидается у “уголка”
        try {
          grip.style.cursor = 'nwse-resize';
          grip.style.userSelect = 'none';
        } catch (e0) {}
        return;
      }

      tries++;
      if (tries < 20) setTimeout(tick, 150);
    })();
  }

  function enforceSingleEditor(form, ta) {
    if (!form || !ta) return;

    var containers = form.querySelectorAll('.sceditor-container');
    if (!containers || !containers.length) {
      form.classList && form.classList.remove('af-aqr-has-sceditor');
      try { ta.style.display = ''; ta.style.visibility = ''; } catch (e0) {}
      return;
    }

    var keep = null;
    try {
      var prev = ta.previousElementSibling;
      if (prev && prev.classList && prev.classList.contains('sceditor-container')) {
        keep = prev;
      }
    } catch (e1) {}

    if (!keep) {
      var best = null;
      var bestDist = 1e9;

      for (var i = 0; i < containers.length; i++) {
        var c = containers[i];
        if (!c || !c.parentNode) continue;

        var dist = 0;
        var n = c;
        while (n && n !== ta && dist < 200) {
          n = n.nextSibling;
          dist++;
        }
        if (n === ta && dist < bestDist) {
          bestDist = dist;
          best = c;
        }
      }

      keep = best || containers[containers.length - 1];
    }

    for (var j = 0; j < containers.length; j++) {
      if (containers[j] !== keep) {
        try { containers[j].parentNode && containers[j].parentNode.removeChild(containers[j]); } catch (e2) {}
      }
    }

    try { form.classList && form.classList.add('af-aqr-has-sceditor'); } catch (e3) {}

    try {
      ta.style.display = 'none';
      ta.style.visibility = 'hidden';
      ta.style.height = '0px';
      ta.style.minHeight = '0px';
      ta.style.padding = '0';
      ta.style.margin = '0';
      ta.setAttribute('aria-hidden', 'true');
    } catch (e4) {}

    try {
      // НЕ прибиваем width навечно — но по умолчанию пусть будет 100%
      keep.style.display = 'flex';
      keep.style.flexDirection = 'column';
      keep.style.alignItems = 'stretch';
      keep.style.boxSizing = 'border-box';
      keep.style.width = '100%';     // стартовое значение
      keep.style.maxWidth = '100%';  // чтобы не вылезать из ячейки

      var toolbar = keep.querySelector('.sceditor-toolbar');
      var iframe = keep.querySelector('iframe');
      var srcTa = keep.querySelector('textarea');

      if (toolbar) {
        toolbar.style.flex = '0 0 auto';
        toolbar.style.position = 'relative';
        toolbar.style.zIndex = '5';
        toolbar.style.width = '100%';
        toolbar.style.boxSizing = 'border-box';
      }

      // КЛЮЧ: flex:1, minHeight 150, height auto
      if (iframe) {
        iframe.style.width = '100%';
        iframe.style.boxSizing = 'border-box';
        iframe.style.flex = '1 1 auto';
        iframe.style.minHeight = '180px';
        iframe.style.height = 'auto';
        iframe.style.position = 'relative';
        iframe.style.top = 'auto';
        iframe.style.left = 'auto';
        iframe.style.zIndex = '1';
        iframe.style.display = 'block';
      }

      if (srcTa) {
        srcTa.style.width = '100%';
        srcTa.style.boxSizing = 'border-box';
        srcTa.style.flex = '1 1 auto';
        srcTa.style.minHeight = '180px';
        srcTa.style.height = 'auto';
      }

      if (keep.classList && keep.classList.contains('wysiwygMode')) {
        if (srcTa) srcTa.style.display = 'none';
        if (iframe) iframe.style.display = 'block';
      }

      if (keep.classList && keep.classList.contains('sourceMode')) {
        if (iframe) iframe.style.display = 'none';
        if (srcTa) srcTa.style.display = 'block';
      }

      bindSceditorResizeSync(keep);

      // больше не ломаем диагональ — только курсор
      bindSceditorGripResize(keep);

    } catch (e5) {}
  }

  function patchToolbarIfNeeded(form, ta) {
    if (!hasSceditor()) return;
    if (!ta) return;

    var $ta = jQuery(ta);
    var inst = getInstance($ta);
    if (!inst || !inst.opts) return;

    // если уже патчили — не трогаем
    if (ta._afAqrToolbarPatched) {
      // но dropdown-меню надо уметь подвесить повторно (после пересоздания)
      try {
        var lay0 = cfg && cfg.toolbarLayout ? cfg.toolbarLayout : null;
        var built0 = buildToolbarFromLayout(lay0);
        if (built0 && built0.menus && built0.menus.length) {
          ensureDropdownMenus(form, ta, inst, built0.menus);
        }
      } catch (e0) {}
      return;
    }

    // ИСТОЧНИК ИСТИНЫ: layout из ACP.
    // Если layout не задан/не пришёл — НИЧЕГО не добавляем автоматически.
    var layout = (cfg && cfg.toolbarLayout) ? cfg.toolbarLayout : null;
    if (!layout) return;

    var built = buildToolbarFromLayout(layout);
    var toolbarNew = String(built.toolbar || '');
    var menus = built.menus || [];

    ta._afAqrToolbarPatched = true;

    var val = '';
    try { val = inst.val(); } catch (e1) {}

    var opts = {};
    for (var k in inst.opts) opts[k] = inst.opts[k];

    opts.toolbar = toolbarNew;

    // важное: ресайз включён
    opts.resizeEnabled = true;

    // Высотой рулит bindSceditorResizeSync (localStorage + MIN/DEFAULT).
    // Если оставить height как есть — многие темы выставляют 250 по умолчанию.
    // Поэтому специально НИЧЕГО не форсим здесь.

    opts.width = '100%';

    try { inst.destroy(); } catch (e2) {}

    // подчистка контейнеров на всякий
    try {
      var all = form.querySelectorAll('.sceditor-container');
      for (var j = 0; j < all.length; j++) {
        try { all[j].parentNode && all[j].parentNode.removeChild(all[j]); } catch (e3) {}
      }
    } catch (e4) {}

    try { $ta.sceditor(opts); } catch (e5) {}

    try {
      var inst2 = getInstance($ta);
      if (inst2 && typeof inst2.val === 'function') inst2.val(val);

      // dropdown-меню подвешиваем после создания
      if (menus && menus.length) {
        ensureDropdownMenus(form, ta, inst2, menus);
      }
    } catch (e6) {}

    try {
      ta.style.display = 'none';
      ta.style.visibility = 'hidden';
    } catch (e7) {}
  }

  /* -------------------- UI before table (ПРЕВЬЮ + СЧЁТЧИК) -------------------- */

  function ensureTopUi(form, ta) {
    if (!form || !ta) return null;

    ta._afAqrUi = ta._afAqrUi || {
      wrap: null,
      previewBtn: null,
      previewBox: null,
      previewContent: null,
      counterNum: null
    };

    if (ta._afAqrUi.wrap && document.body.contains(ta._afAqrUi.wrap)) {
      return ta._afAqrUi;
    }

    var table = getAnchorTable(form);
    if (!table) return null;

    var wrap = form.querySelector('.af-aqr-topwrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'af-aqr-topwrap';
      wrap.style.margin = '8px 0';

      // 1) строка предпросмотра (слева кнопка)
      var previewRow = document.createElement('div');
      previewRow.className = 'af-aqr-previewrow';
      previewRow.style.display = 'flex';
      previewRow.style.alignItems = 'center';
      previewRow.style.justifyContent = 'space-between';
      previewRow.style.gap = '10px';
      previewRow.style.margin = '0 0 6px 0';

      var left = document.createElement('div');
      left.className = 'af-aqr-previewleft';

      var btn = document.createElement('a');
      btn.href = '#';
      btn.className = 'af-aqr-previewbtn is-off';
      btn.setAttribute('role', 'button');
      btn.setAttribute('aria-pressed', 'false');
      btn.title = 'Быстрый предпросмотр';
      btn.textContent = '👁 Предпросмотр';

      left.appendChild(btn);
      previewRow.appendChild(left);

      // 2) сам блок предпросмотра (под строкой)
      var box = document.createElement('div');
      box.className = 'af-aqr-previewbox';
      box.style.display = 'none';
      box.style.margin = '0 0 6px 0';

      var content = document.createElement('div');
      content.className = 'af-aqr-previewcontent';
      content.innerHTML = '<div class="af-aqr-previewempty">Предпросмотр включён. Начни печатать…</div>';

      box.appendChild(content);

      // 3) строка счётчика (справа блок)
      var counterRow = document.createElement('div');
      counterRow.className = 'af-aqr-counterrow';
      counterRow.style.display = 'flex';
      counterRow.style.justifyContent = 'flex-end';

      var counter = document.createElement('div');
      counter.className = 'af-aqr-counter';
      counter.innerHTML = '<span class="af-aqr-counter-num">0000</span><sup>sym</sup>';

      counterRow.appendChild(counter);

      wrap.appendChild(previewRow);
      wrap.appendChild(box);
      wrap.appendChild(counterRow);

      form.insertBefore(wrap, table);
    }

    ta._afAqrUi.wrap = wrap;
    ta._afAqrUi.previewBtn = wrap.querySelector('.af-aqr-previewbtn');
    ta._afAqrUi.previewBox = wrap.querySelector('.af-aqr-previewbox');
    ta._afAqrUi.previewContent = wrap.querySelector('.af-aqr-previewcontent');
    ta._afAqrUi.counterNum = wrap.querySelector('.af-aqr-counter-num');

    return ta._afAqrUi;
  }

  /* -------------------- COUNTER (СТАБИЛЬНО, но вывод в top UI) -------------------- */

  function stripBbcode(s) {
    return String(s || '').replace(/\[\/?[a-z0-9*]+(?:=[^\]]*)?\]/gi, '');
  }

  function readEditorValue(form, ta) {
    if (!form || !ta) return '';

    if (hasSceditor()) {
      try {
        var inst = getInstance(jQuery(ta));
        if (inst && typeof inst.val === 'function') {
          return String(inst.val() || '');
        }
      } catch (e0) {}
    }

    return String(ta.value || '');
  }

  // старый оверлей оставляем как ФОЛЛБЭК, но по умолчанию не используем
  function ensureCounterOverlayFallback(form, ta) {
    var tbody = getQuickReplyTbody(form);
    if (!tbody) return null;

    try {
      var cs = window.getComputedStyle ? getComputedStyle(tbody) : null;
      if (!cs || cs.position === 'static') tbody.style.position = 'relative';
    } catch (e0) { try { tbody.style.position = 'relative'; } catch (e1) {} }

    var el = tbody.querySelector('.af-aqr-counter');
    if (!el) {
      el = document.createElement('div');
      el.className = 'af-aqr-counter';
      el.innerHTML = '<span class="af-aqr-counter-num">0000</span><sup>sym</sup>';

      el.style.position = 'absolute';
      el.style.top = '6px';
      el.style.right = '10px';
      el.style.zIndex = '30';
      el.style.pointerEvents = 'none';
      el.style.userSelect = 'none';

      try { tbody.insertBefore(el, tbody.firstChild); } catch (e2) { try { tbody.appendChild(el); } catch (e3) {} }
    }

    return el;
  }

  function ensureCounterTarget(form, ta) {
    var ui = ensureTopUi(form, ta);

    // приоритет: top UI (как ты просишь)
    if (ui && ui.counterNum) {
      ta._afAqrCounterEls = ta._afAqrCounterEls || {};
      ta._afAqrCounterEls.num = ui.counterNum;
      return true;
    }

    // фоллбэк: старый оверлей (если вдруг не удалось вставить вверх)
    var ov = ensureCounterOverlayFallback(form, ta);
    if (ov) {
      ta._afAqrCounterEls = ta._afAqrCounterEls || {};
      ta._afAqrCounterEls.num = ov.querySelector('.af-aqr-counter-num');
      return true;
    }

    return false;
  }

  function updateCounter(form, ta) {
    if (!form || !ta) return;
    if (!ensureCounterTarget(form, ta)) return;
    if (!ta._afAqrCounterEls || !ta._afAqrCounterEls.num) return;

    var raw = readEditorValue(form, ta);
    var text = (cfg && cfg.countBbcode) ? raw : stripBbcode(raw);
    var n = text.length;

    var shown = (n < 10000) ? String(n).padStart(4, '0') : String(n);
    if (ta._afAqrCounterEls.num.textContent !== shown) {
      ta._afAqrCounterEls.num.textContent = shown;
    }
  }

  /* -------------------- PREVIEW (только по кнопке, безопасно) -------------------- */

  function ensurePreviewState(ta) {
    ta._afAqrPreview = ta._afAqrPreview || {
      enabled: false,
      lastSeen: null,
      timer: null,
      inflight: null,
      bound: false
    };
    return ta._afAqrPreview;
  }

  function renderPreview(form, ta, html) {
    var ui = ensureTopUi(form, ta);
    if (!ui || !ui.previewContent) return;
    ui.previewContent.innerHTML = html;
  }

  function abortPreview(ta) {
    var p = ensurePreviewState(ta);
    try {
      if (p.inflight && p.inflight.abort) p.inflight.abort();
    } catch (e0) {}
    p.inflight = null;

    // если fetch через AbortController (на всякий)
    try {
      if (p._abortController && p._abortController.abort) p._abortController.abort();
    } catch (e1) {}
    p._abortController = null;
  }

  function doAjaxPreview(form, ta, raw) {
    var p = ensurePreviewState(ta);
    if (!p.enabled) return;

    var url = (cfg && cfg.previewUrl) ? String(cfg.previewUrl) : '';
    if (!url) return;

    var postKey = getPostKey(form);
    if (!postKey) {
      renderPreview(form, ta, '<div class="af-aqr-previewempty">Нет my_post_key.</div>');
      return;
    }

    var trimmed = String(raw || '').trim();
    if (!trimmed) {
      renderPreview(form, ta, '<div class="af-aqr-previewempty">Пусто. Напиши что-нибудь 🙂</div>');
      return;
    }

    renderPreview(form, ta, '<div class="af-aqr-previewloading">⏳ Предпросмотр…</div>');

    abortPreview(ta);

    var tid = getTid(form);
    var data = { my_post_key: postKey, message: raw, ajax: 1 };
    if (tid) data.tid = tid;

    // jQuery.ajax (предпочтительно, чтобы уметь abort)
    if (window.jQuery && jQuery.ajax) {
      p.inflight = jQuery.ajax({
        type: 'POST',
        url: url,
        data: data,
        dataType: 'html',
        cache: false
      }).done(function (html) {
        if (!p.enabled) return;
        var out = String(html || '');
        if (!out.trim()) {
          renderPreview(form, ta, '<div class="af-aqr-previewempty">Сервер вернул пустой ответ.</div>');
          return;
        }
        renderPreview(form, ta, out);
      }).fail(function () {
        if (!p.enabled) return;
        renderPreview(form, ta, '<div class="af-aqr-previewempty">Ошибка предпросмотра.</div>');
      });

      return;
    }

    // fetch fallback (безопасно, без “каждый символ”)
    try {
      var body = 'my_post_key=' + encodeURIComponent(postKey) +
        '&message=' + encodeURIComponent(raw) +
        '&ajax=1' +
        (tid ? ('&tid=' + encodeURIComponent(tid)) : '');

      var ac = null;
      try { ac = (window.AbortController ? new AbortController() : null); } catch (e0) { ac = null; }
      p._abortController = ac;

      fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        credentials: 'same-origin',
        body: body,
        signal: ac ? ac.signal : undefined
      }).then(function (r) { return r.text(); })
        .then(function (html) {
          if (!p.enabled) return;
          var out = String(html || '');
          if (!out.trim()) {
            renderPreview(form, ta, '<div class="af-aqr-previewempty">Сервер вернул пустой ответ.</div>');
            return;
          }
          renderPreview(form, ta, out);
        })
        .catch(function () {
          if (!p.enabled) return;
          renderPreview(form, ta, '<div class="af-aqr-previewempty">Ошибка предпросмотра.</div>');
        });
    } catch (e3) {
      renderPreview(form, ta, '<div class="af-aqr-previewempty">Ошибка предпросмотра.</div>');
    }
  }

  function setPreviewEnabled(form, ta, enabled) {
    var ui = ensureTopUi(form, ta);
    if (!ui || !ui.previewBtn || !ui.previewBox) return;

    var p = ensurePreviewState(ta);
    p.enabled = !!enabled;
    lsSetBool(PREVIEW_LS_KEY, p.enabled);

    ui.previewBtn.classList.toggle('is-on', p.enabled);
    ui.previewBtn.classList.toggle('is-off', !p.enabled);
    ui.previewBtn.setAttribute('aria-pressed', p.enabled ? 'true' : 'false');

    ui.previewBox.style.display = p.enabled ? '' : 'none';

    if (!p.enabled) {
      abortPreview(ta);
      if (p.timer) { clearTimeout(p.timer); p.timer = null; }
      return;
    }

    // при включении — сразу планируем
    p.lastSeen = null;
    schedulePreview(form, ta);
  }

  function schedulePreview(form, ta) {
    var p = ensurePreviewState(ta);
    if (!p.enabled) return;

    var raw = readEditorValue(form, ta);

    // не трогаем DOM/сервер, если текст не менялся
    if (raw === p.lastSeen) return;
    p.lastSeen = raw;

    if (p.timer) { clearTimeout(p.timer); p.timer = null; }

    // мягкий debounce, чтобы не долбить сервер и не лагать ввод
    p.timer = setTimeout(function () {
      try { doAjaxPreview(form, ta, p.lastSeen || ''); } catch (e0) {}
    }, 800);
  }

  function bindPreviewButtonOnce(form, ta) {
    var ui = ensureTopUi(form, ta);
    if (!ui || !ui.previewBtn) return;

    var p = ensurePreviewState(ta);
    if (p.bound) return;
    p.bound = true;

    var startEnabled = lsGetBool(PREVIEW_LS_KEY, false);
    setPreviewEnabled(form, ta, startEnabled);

    ui.previewBtn.addEventListener('click', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      setPreviewEnabled(form, ta, !p.enabled);
    }, { passive: false });
  }

  /* -------------------- COUNTER LOOP (СТАБИЛЬНЫЙ ТАЙМЕР + сюда же preview) -------------------- */

  function startCounter(form, ta) {
    if (!form || !ta) return;
    if (ta._afAqrCounterStarted) return;
    ta._afAqrCounterStarted = true;

    // гарантируем верхний UI (как ты просишь)
    ensureTopUi(form, ta);

    // привязываем кнопку предпросмотра
    bindPreviewButtonOnce(form, ta);

    updateCounter(form, ta);

    var lastRaw = null;

    // оставляем твой безопасный polling, только добавляем внутрь schedulePreview
    ta._afAqrCounterTimer = window.setInterval(function () {
      try {
        if (!document.body || !document.body.contains(form)) {
          if (ta._afAqrCounterTimer) {
            clearInterval(ta._afAqrCounterTimer);
            ta._afAqrCounterTimer = null;
          }
          return;
        }

        // UI мог появиться позднее — подстрахуем
        ensureTopUi(form, ta);
        ensureCounterTarget(form, ta);

        var raw = readEditorValue(form, ta);
        if (raw !== lastRaw) {
          lastRaw = raw;
          updateCounter(form, ta);

          // предпросмотр — только если включён
          var p = ta._afAqrPreview;
          if (p && p.enabled) {
            schedulePreview(form, ta);
          }
        }
      } catch (e0) {}
    }, 250);

    // если вдруг обычная textarea без SCEditor — пусть мгновенно обновляет счётчик
    try {
      ta.addEventListener('input', function () { updateCounter(form, ta); }, { passive: true });
      ta.addEventListener('keyup', function () { updateCounter(form, ta); }, { passive: true });
      ta.addEventListener('change', function () { updateCounter(form, ta); }, { passive: true });
    } catch (e1) {}
  }

  function afAqrClearEditor(form, ta) {
    if (!form || !ta) return;

    function hardClearContainer() {
      try {
        // контейнер рядом с textarea — самый правильный
        var container = null;
        try {
          var prev = ta.previousElementSibling;
          if (prev && prev.classList && prev.classList.contains('sceditor-container')) container = prev;
        } catch (e0) {}
        if (!container) container = form.querySelector('.sceditor-container');
        if (!container) return;

        // 1) исходная textarea внутри контейнера (source textarea SCEditor)
        try {
          var srcTa = container.querySelector('textarea');
          if (srcTa) srcTa.value = '';
        } catch (e1) {}

        // 2) iframe WYSIWYG — ВОТ ЭТО ТВОЙ КЕЙС
        try {
          var ifr = container.querySelector('iframe');
          if (ifr) {
            var doc = null;
            try { doc = ifr.contentDocument || (ifr.contentWindow ? ifr.contentWindow.document : null); } catch (e2) { doc = null; }
            if (doc && doc.body) {
              // чистим DOM редактора
              doc.body.innerHTML = '';
              // чтобы курсор не сходил с ума — минимальный контент
              doc.body.appendChild(doc.createElement('br'));
            }
          }
        } catch (e3) {}

        // 3) на всякий: убрать класс sourceMode/wysiwygMode мы не трогаем,
        // только содержимое. Но можно “пнуть” инпуты.
        try {
          ta.dispatchEvent(new Event('input', { bubbles: true }));
          ta.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (e4) {}
      } catch (e5) {}
    }

    // 1) стоп предпросмотра
    try { abortPreview(ta); } catch (e0) {}
    try {
      var p = ensurePreviewState(ta);
      p.lastSeen = null;
      if (p.timer) { clearTimeout(p.timer); p.timer = null; }
    } catch (e1) {}

    // 2) чистим textarea “как источник истины”
    try { ta.value = ''; } catch (e2) {}

    // 3) чистим SCEditor инстанс (если он реально есть)
    if (hasSceditor()) {
      try {
        var inst = getInstance(jQuery(ta));
        if (inst) {
          if (typeof inst.val === 'function') inst.val('');
          if (typeof inst.updateOriginal === 'function') inst.updateOriginal();

          // супер-важно: некоторые сборки держат ссылку на body
          try {
            if (typeof inst.getBody === 'function') {
              var b = inst.getBody();
              if (b) { b.innerHTML = ''; b.appendChild(b.ownerDocument.createElement('br')); }
            }
          } catch (e3b) {}

          try { if (typeof inst.focus === 'function') inst.focus(); } catch (e3c) {}
        }
      } catch (e3) {}
    } else {
      try { ta.focus(); } catch (e4) {}
    }

    // 4) сброс UI превью + счётчик
    try {
      var ui = ensureTopUi(form, ta);
      if (ui && ui.previewContent) {
        ui.previewContent.innerHTML = '<div class="af-aqr-previewempty">Предпросмотр включён. Начни печатать…</div>';
      }
    } catch (e5) {}
    try { updateCounter(form, ta); } catch (e6) {}

    // 5) ЖЁСТКО чистим DOM контейнера (iframe/textarea внутри .sceditor-container)
    //    и делаем это несколько раз, чтобы прибить гонки после ajax/перерисовок.
    hardClearContainer();
    setTimeout(hardClearContainer, 0);
    setTimeout(hardClearContainer, 25);
    setTimeout(hardClearContainer, 120);
  }

  function afAqrBindClearAfterSend(form, ta) {
    if (!form || !ta) return;
    if (form._afAqrClearAfterSendBound) return;
    form._afAqrClearAfterSendBound = true;

    // Глобальное состояние ожидания “успешного” AJAX после submit
    if (!window.afAqrAjaxClearState) {
      window.afAqrAjaxClearState = { last: null };
    }

    function now() { return Date.now ? Date.now() : +new Date(); }

    function asText(x) { return String(x == null ? '' : x); }

    function getFormTidSafe(f) {
      try { return getTid(f) || ''; } catch (e) { return ''; }
    }

    function getDataString(settings) {
      var d = settings ? settings.data : '';
      try {
        if (typeof d === 'string') return d;
        if (d && typeof d === 'object' && window.jQuery) return jQuery.param(d);
      } catch (e) {}
      return '';
    }

    function parseParam(str, key) {
      str = asText(str);
      key = asText(key);
      if (!str || !key) return '';
      // key=value in querystring-like payload
      var m = str.match(new RegExp('(?:^|[&?])' + key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^&]*)', 'i'));
      if (!m) return '';
      try { return decodeURIComponent(m[1].replace(/\+/g, '%20')); } catch (e) { return m[1]; }
    }

    // Признаки “это отправка quick reply/new reply”
    function looksLikeQuickReplyRequest(settings) {
      if (!settings) return false;

      var url = asText(settings.url).toLowerCase();
      var dataStr = asText(getDataString(settings));

      // Быстрый ответ MyBB часто идёт через xmlhttp.php?action=quickreply
      var action = (parseParam(url, 'action') || parseParam(dataStr, 'action') || '').toLowerCase();
      if (action === 'quickreply') return true;

      // Классический newreply.php (иногда ajax=1/quickreply=1)
      if (url.indexOf('newreply.php') !== -1) return true;

      // Иногда улетает в xmlhttp.php (без action в url, но в data)
      if (url.indexOf('xmlhttp.php') !== -1) {
        // если в data есть message+post_key — очень похоже на отправку
        var hasPostKey = dataStr.indexOf('my_post_key=') !== -1;
        var hasMessage = dataStr.indexOf('message=') !== -1;
        if (hasPostKey && hasMessage) return true;
      }

      // MyBB иногда использует do_newreply в payload
      if (dataStr.indexOf('do_newreply') !== -1) return true;

      return false;
    }

    function responseLooksSuccessful(xhr, data) {
      // 1) JSON/object
      if (data && typeof data === 'object') {
        if (data.success === false) return false;
        if (data.error && asText(data.error).trim()) return false;
        if (Array.isArray(data.errors) && data.errors.length) return false;
        if (typeof data.errors === 'string' && data.errors.trim()) return false;

        // success markers
        if (data.postbit) return true;
        if (data.html) return true;
        if (data.pid) return true;
        if (data.redirect) return true;

        // объект без ошибок — считаем успехом
        return true;
      }

      // 2) text/html
      var txt = '';
      try { txt = asText(xhr && xhr.responseText ? xhr.responseText : ''); } catch (e) { txt = ''; }
      if (!txt) return false;

      // пробуем JSON из текста
      try {
        var j = JSON.parse(txt);
        return responseLooksSuccessful(null, j);
      } catch (e0) {}

      var low = txt.toLowerCase();

      // явные ошибки MyBB
      if (low.indexOf('class="error"') !== -1) return false;
      if (low.indexOf('error_inline') !== -1) return false;
      if (low.indexOf('post_errors') !== -1) return false;

      // успех-паттерны (прилетел пост/постбит)
      if (low.indexOf('id="post_') !== -1) return true;
      if (low.indexOf('id="pid_') !== -1) return true;
      if (low.indexOf('postbit') !== -1) return true;
      if (low.indexOf('class="post') !== -1) return true;

      return false;
    }

    function clearDraftsIfAny(f) {
      try {
        if (f && typeof f._afDraftsDelete === 'function') f._afDraftsDelete();
        if (f) f._afDraftsLast = '';
      } catch (e) {}
    }

    function markAttempt() {
      try {
        var raw = '';
        try { raw = readEditorValue(form, ta); } catch (e0) { raw = ''; }
        if (!asText(raw).trim()) return;

        window.afAqrAjaxClearState.last = {
          form: form,
          ta: ta,
          tid: getFormTidSafe(form),
          at: now()
        };
      } catch (e1) {}
    }

    // 1) Любая попытка submit — “ожидаем” успешный ajax-ответ
    try {
      form.addEventListener('submit', function () {
        markAttempt();
      }, true);
      try {
        var sb = form.querySelector('input[type="submit"], button[type="submit"]');
        if (sb) sb.addEventListener('click', function () { markAttempt(); }, true);
      } catch (eX) {}
    } catch (e2) {}

    // 2) Глобальный хук на ajaxSuccess — чистим ТОЛЬКО когда совпало + успех
    if (!window.afAqrAjaxClearHooked && window.jQuery && jQuery.ajax) {
      window.afAqrAjaxClearHooked = true;

      // ВАЖНО: 4-й аргумент `data` jQuery реально передаёт
      jQuery(document).ajaxSuccess(function (ev, xhr, settings, data) {
        try {
          var st = window.afAqrAjaxClearState;
          if (!st || !st.last) return;

          // TTL ожидания (20 сек)
          if (!st.last.at || (now() - st.last.at) > 20000) return;

          // форма должна быть жива на странице
          if (!document.body || !document.body.contains(st.last.form)) return;

          // это должен быть именно quick reply/new reply запрос
          if (!looksLikeQuickReplyRequest(settings)) return;

          // успех
          if (!responseLooksSuccessful(xhr, data)) return;

          // сверяем tid, если можем вытащить из запроса/формы
          try {
            var tidFromReq = '';
            try {
              var dataStr = getDataString(settings);
              tidFromReq = parseParam(dataStr, 'tid') || parseParam(asText(settings.url), 'tid') || '';
            } catch (e0) { tidFromReq = ''; }

            var tidNow = getFormTidSafe(st.last.form);

            // если у нас есть tid в last и есть tidNow — должны совпасть
            if (st.last.tid && tidNow && st.last.tid !== tidNow) return;

            // если tidFromReq есть и tidNow есть — тоже должны совпасть
            if (tidFromReq && tidNow && tidFromReq !== tidNow) return;
          } catch (eTid) {}

          // Чистим редактор (лучше в microtask/таймер — чтобы не конфликтовать с чужими DOM-апдейтами)
          setTimeout(function () {
            try {
              afAqrClearEditor(st.last.form, st.last.ta);
              clearDraftsIfAny(st.last.form);
            } catch (e3) {}
          }, 0);

          // сброс ожидания
          st.last = null;
        } catch (e4) {}
      });

      // На ajaxError ничего не делаем — текст должен остаться.
      jQuery(document).ajaxError(function () {});
    }
  }

  /* -------------------- MAIN (СТАБИЛЬНОЕ) -------------------- */
  function ensureQrWrap(form) {
    if (!form) return null;

    // 1) если родитель уже обёртка — ок
    var parent = form.parentElement;
    if (parent && parent.classList && parent.classList.contains('af-aqr-qr-wrap')) {
      return parent;
    }

    // 2) иначе создаём и оборачиваем
    var wrap = document.createElement('div');
    wrap.className = 'af-aqr-qr-wrap';

    if (form.parentNode) {
      form.parentNode.insertBefore(wrap, form);
      wrap.appendChild(form);
    }

    return wrap;
  }

  function removeQuickReplyTitleRow(form) {
    if (!form) return;

    // Самый безопасный вариант: удалить первый separator-row внутри формы, если он выглядит как заголовок
    // Обычно это <tr><td class="trow_sep" colspan="2"><strong>Быстрый ответ</strong>...
    var td = form.querySelector('td.trow_sep > strong');
    if (!td) return;

    var text = (td.textContent || '').trim().toLowerCase();
    if (!text) return;

    // попадём и в ru, и в en, и в кастомные переводы
    var looksLikeTitle =
      text.indexOf('быстрый') !== -1 ||
      text.indexOf('quick') !== -1 ||
      text.indexOf('reply') !== -1;

    if (!looksLikeTitle) return;

    var tr = td.closest('tr');
    if (tr && tr.parentNode) {
      try { tr.parentNode.removeChild(tr); } catch (e) {}
    }
  }

  function ensureToolbarButtons(form, ta) {
    if (!form || !ta) return;
    if (!hasSceditor()) return;

    var layout = (cfg && cfg.toolbarLayout) ? cfg.toolbarLayout : null;
    if (!layout || !layout.sections || !Array.isArray(layout.sections)) return;

    // Соберём set команд, которые реально должны быть КНОПКАМИ на тулбаре (только group),
    // а не пунктами dropdown.
    var inLayout = Object.create(null);
    layout.sections.forEach(function (sec) {
      if (!sec || !sec.items || !Array.isArray(sec.items)) return;

      var type = String(sec.type || 'group').toLowerCase();
      if (type === 'dropdown') return; // КРИТИЧНО: dropdown items НЕ рисуются как кнопки

      sec.items.forEach(function (cmd) {
        cmd = String(cmd || '').trim();
        if (!cmd) return;
        inLayout[cmd] = true;
      });
    });

    var container = null;
    try {
      var prev = ta.previousElementSibling;
      if (prev && prev.classList && prev.classList.contains('sceditor-container')) {
        container = prev;
      }
    } catch (e0) {}
    if (!container) container = form.querySelector('.sceditor-container');
    if (!container) return;

    var toolbar = container.querySelector('.sceditor-toolbar');
    if (!toolbar) return;

    // Если SCEditor уже создал кнопки — НЕ лезем руками.
    var metaByCmd = buildMetaByCmd();
    var anyCmd = null;
    Object.keys(metaByCmd).some(function (k) { anyCmd = k; return true; });

    if (anyCmd) {
      // class* чтобы пережить точки/нестандартные символы
      var already = toolbar.querySelector('a[class*="sceditor-button-' + anyCmd.replace(/"/g, '') + '"]');
      if (already) return;
    }

    var groups = toolbar.querySelectorAll('.sceditor-group');
    var targetGroup = groups && groups.length ? groups[groups.length - 1] : null;
    if (!targetGroup) {
      targetGroup = document.createElement('div');
      targetGroup.className = 'sceditor-group';
      try { toolbar.appendChild(targetGroup); } catch (e1) {}
    }

    function bindButtonOnce(a, cmd) {
      if (!a || a.dataset.afAqrBound) return;

      var handler = function (ev) {
        try { ev.preventDefault(); ev.stopPropagation(); } catch (e0) {}

        var instNow = null;
        try { instNow = getInstance(jQuery(ta)); } catch (e1) { instNow = null; }
        if (instNow) {
          execCmd(instNow, cmd, a);
          try { instNow.focus && instNow.focus(); } catch (e2) {}
        }

        return false;
      };

      try { a.addEventListener('click', handler, { passive: false, capture: true }); } catch (e4) {}
      try { a.addEventListener('pointerdown', handler, { passive: false, capture: true }); } catch (e5) {}
      try { a.addEventListener('mousedown', handler, { passive: false, capture: true }); } catch (e6) {}
      try { a.addEventListener('touchstart', handler, { passive: false, capture: true }); } catch (e7) {}

      a.dataset.afAqrBound = '1';
    }

    buttons.forEach(function (b) {
      if (!b || !b.name) return;

      var name = asText(b.name).trim();
      if (!name) return;

      var cmd = 'af_' + name;

      // главное: если команды нет в group layout — кнопку не добавляем
      if (!inLayout[cmd]) return;

      var existing = targetGroup.querySelector('a[class*="sceditor-button-' + cmd.replace(/"/g, '') + '"]');
      if (existing) {
        bindButtonOnce(existing, cmd);
        return;
      }

      var a = document.createElement('a');
      a.href = '#';
      a.className = 'sceditor-button sceditor-button-' + cmd;
      a.title = b.title || b.name;

      var d = document.createElement('div');
      a.appendChild(d);

      bindButtonOnce(a, cmd);
      targetGroup.appendChild(a);
    });
  }

  function decorateToolbarButtons(form, ta) {
    if (!form || !ta) return;
    if (!hasSceditor()) return;

    function asText(x) { return String(x == null ? '' : x); }

    function looksLikeSvgUrl(u) {
      u = asText(u).trim().toLowerCase();
      if (!u) return false;
      return (u.indexOf('.svg') !== -1) || (u.indexOf('data:image/svg') === 0);
    }

    function setImp(el, prop, val) {
      try { el.style.setProperty(prop, val, 'important'); }
      catch (e0) { try { el.style[prop] = val; } catch (e1) {} }
    }

    function paintUrlIconOnDiv(div, url) {
      if (!div || !url) return;

      // нормализуем + ревизия
      var u = afAqrAppendRev(resolveIconUrl(url), cfg);
      var isSvg = looksLikeSvgUrl(u);
      var maskOk = afAqrSupportsMask();

      // база — убиваем тему/спрайты на div
      setImp(div, 'text-indent', '0');
      setImp(div, 'width', '16px');
      setImp(div, 'height', '16px');
      setImp(div, 'display', 'flex');
      setImp(div, 'align-items', 'center');
      setImp(div, 'justify-content', 'center');
      setImp(div, 'background-repeat', 'no-repeat');
      setImp(div, 'background-position', 'center');
      setImp(div, 'background-size', '16px 16px');
      setImp(div, 'color', 'inherit');

      // сначала всегда ставим “чисто”
      setImp(div, 'background-color', 'transparent');

      // чистим маску (на случай мусора от темы/прошлой отрисовки)
      setImp(div, '-webkit-mask-image', 'none');
      setImp(div, 'mask-image', 'none');
      setImp(div, '-webkit-mask-repeat', 'no-repeat');
      setImp(div, 'mask-repeat', 'no-repeat');
      setImp(div, '-webkit-mask-position', 'center');
      setImp(div, 'mask-position', 'center');
      setImp(div, '-webkit-mask-size', '16px 16px');
      setImp(div, 'mask-size', '16px 16px');

      // Firefox: alpha
      setImp(div, 'mask-mode', 'alpha');
      setImp(div, '-webkit-mask-mode', 'alpha');

      if (isSvg && maskOk) {
        var esc = String(u).replace(/"/g, '\\"');
        setImp(div, 'background-image', 'none');
        setImp(div, '-webkit-mask-image', 'url("' + esc + '")');
        setImp(div, 'mask-image', 'url("' + esc + '")');
        setImp(div, '-webkit-mask-repeat', 'no-repeat');
        setImp(div, 'mask-repeat', 'no-repeat');
        setImp(div, '-webkit-mask-position', 'center');
        setImp(div, 'mask-position', 'center');
        setImp(div, '-webkit-mask-size', '16px 16px');
        setImp(div, 'mask-size', '16px 16px');

        // ВАЖНО: цвет берём из currentColor — тогда :hover на <a> реально меняет цвет
        setImp(div, 'background-color', 'currentColor');
      } else {
        setImp(div, 'background-image', 'url("' + String(u).replace(/"/g, '\\"') + '")');
      }
    }

    function getCmdFromButton(a) {
      if (!a || !a.classList) return '';
      for (var i = 0; i < a.classList.length; i++) {
        var c = a.classList[i];
        if (c && c.indexOf('sceditor-button-') === 0 && c !== 'sceditor-button') {
          return c.slice('sceditor-button-'.length);
        }
      }
      return '';
    }

    var metaByCmd = buildMetaByCmd();

    // найти контейнер/тулбар
    var container = null;
    try {
      var prev = ta.previousElementSibling;
      if (prev && prev.classList && prev.classList.contains('sceditor-container')) container = prev;
    } catch (e0) {}
    if (!container) container = form.querySelector('.sceditor-container');
    if (!container) return;

    var toolbar = container.querySelector('.sceditor-toolbar');
    if (!toolbar) return;

    // гарантируем цвет-переменные (ок)
    try { afAqrEnsureToolbarIconColor(container); } catch (e1) {}

    var btns = toolbar.querySelectorAll('a.sceditor-button');
    for (var b = 0; b < btns.length; b++) {
      var a = btns[b];
      if (!a) continue;

      var cmd = getCmdFromButton(a);
      if (!cmd) continue;
      if (!/^af_/i.test(cmd)) continue;

      var meta = metaByCmd[cmd] || null;
      if (!meta && /^af_/i.test(cmd)) {
        var alt = 'af_' + escCss(cmd.slice(3));
        meta = metaByCmd[alt] || null;
      }
      if (!meta) continue;

      var d = a.querySelector('div');
      if (!d) continue;

      setImp(d, 'display', 'flex');
      setImp(d, 'align-items', 'center');
      setImp(d, 'justify-content', 'center');
      setImp(d, 'width', '16px');
      setImp(d, 'height', '16px');
      setImp(d, 'line-height', '16px');
      setImp(d, 'padding', '0');
      setImp(d, 'text-indent', '0');
      setImp(d, 'color', 'inherit');

      // КЛЮЧЕВОЕ: НЕ трогаем inline color у <a> (особенно !important),
      // иначе hover никогда не победит.
      try {
        a.style.removeProperty('color');
      } catch (eClr) {}

      var spec = meta.iconSpec || { kind: 'empty', value: '' };

      if (spec.kind === 'url') {
        d.textContent = '';
        d.innerHTML = '';
        paintUrlIconOnDiv(d, spec.value);
      } else if (spec.kind === 'svg') {
        setImp(d, 'background-image', 'none');
        setImp(d, 'background-color', 'transparent');
        d.innerHTML = spec.value;
      } else if (spec.kind === 'text') {
        setImp(d, 'background-image', 'none');
        setImp(d, 'background-color', 'transparent');
        d.textContent = asText(spec.value);
        setImp(d, 'font-size', '14px');
        setImp(d, 'font-weight', '700');
      }

      try {
        a.setAttribute('title', meta.title || cmd);
        a.setAttribute('aria-label', meta.title || cmd);
      } catch (e2) {}
    }
  }

  function afAqrForceNormalSubmit(form, ta) {
    if (!form || !ta) return;
    if (form._afAqrForceNormalSubmitBound) return;
    form._afAqrForceNormalSubmitBound = true;

    function isSubmitEl(el) {
      if (!el || el.nodeType !== 1) return false;
      var tag = (el.tagName || '').toLowerCase();
      if (tag === 'button') {
        var t = (el.getAttribute('type') || '').toLowerCase();
        return !t || t === 'submit';
      }
      if (tag === 'input') {
        var tt = (el.getAttribute('type') || '').toLowerCase();
        return (tt === 'submit' || tt === 'image');
      }
      return false;
    }

    function syncEditorToTextarea() {
      try {
        if (!hasSceditor()) return;

        var inst = null;
        try { inst = getInstance(jQuery(ta)); } catch (e0) { inst = null; }
        if (!inst) return;

        // Самое важное: чтобы в textarea ушёл актуальный текст
        try {
          if (typeof inst.updateOriginal === 'function') inst.updateOriginal();
        } catch (e1) {}

        try {
          if (typeof inst.val === 'function') ta.value = String(inst.val() || '');
        } catch (e2) {}
      } catch (e3) {}
    }

    // Убираем inline onsubmit, если тема/плагин его вешали
    try { form.onsubmit = null; } catch (eA) {}
    try { form.removeAttribute('onsubmit'); } catch (eB) {}

    // Если какие-то скрипты уже повесили submit через jQuery — снимем
    try { if (window.jQuery) jQuery(form).off('submit'); } catch (eC) {}

    // 1) CAPTURE submit: глушим чужие обработчики (AJAX), но НЕ отменяем отправку
    form.addEventListener('submit', function (ev) {
      try { ev.stopImmediatePropagation(); ev.stopPropagation(); } catch (e0) {}
      syncEditorToTextarea();
      // ничего не preventDefault => обычный submit + reload
    }, true);

    // 2) CAPTURE click по submit-кнопкам: тоже глушим AJAX-хендлеры, но даём клику “дожить”
    form.addEventListener('click', function (ev) {
      try {
        var t = ev && ev.target ? ev.target : null;
        if (!t) return;

        // если кликнули по иконке/спану внутри кнопки — поднимемся к самой кнопке
        var el = t.closest ? t.closest('button, input[type="submit"], input[type="image"]') : t;
        if (!isSubmitEl(el)) return;

        ev.stopImmediatePropagation();
        ev.stopPropagation();
        // НЕ preventDefault — пусть кнопка инициирует submit как обычно
        syncEditorToTextarea();
      } catch (e1) {}
    }, true);

    // Подстраховка: некоторые темы дергают отправку через keydown Enter внутри формы
    form.addEventListener('keydown', function (ev) {
      try {
        if (!ev) return;
        if (ev.key !== 'Enter') return;
        // не мешаем обычному Enter — просто синхронизируем текст заранее
        syncEditorToTextarea();
      } catch (e2) {}
    }, true);
  }

  function fixOnce() {
    var form = getForm();
    if (!form) return;

    ensureQrWrap(form);

    var ta = getTA(form);

    try { form.setAttribute('autocomplete', 'off'); } catch (e) {}
    if (ta) {
      try { ta.setAttribute('autocomplete', 'off'); } catch (e) {}
      try { ta.setAttribute('autocorrect', 'off'); } catch (e) {}
      try { ta.setAttribute('autocapitalize', 'off'); } catch (e) {}
      try { ta.setAttribute('spellcheck', 'false'); } catch (e) {}
    }

    if (!ta) return;

    // === КЛЮЧ: ОТПРАВКА ТЕПЕРЬ ТОЛЬКО ОБЫЧНАЯ (НЕ AJAX) ===
    afAqrForceNormalSubmit(form, ta);

    removeQuickReplyTitleRow(form);
    removeLeftColumn(form, ta);

    // UI + счётчик/превью можно и без SCEditor
    startCounter(form, ta);

    if (window.afAqrUnifiedEditor && typeof window.afAqrUnifiedEditor.initTextarea === 'function') {
      window.afAqrUnifiedEditor.initTextarea(ta);
    }
  }

  function start() {
    // стартовые прогоны (как было)
    fixOnce();
    setTimeout(fixOnce, 250);
    setTimeout(fixOnce, 600);
    setTimeout(fixOnce, 1200);
    setTimeout(fixOnce, 2000);

    var form = getForm();
    if (!form || !window.MutationObserver) return;

    var scheduled = false;
    var running = false;

    function requestFix() {
      if (scheduled) return;
      scheduled = true;

      setTimeout(function () {
        scheduled = false;
        if (running) return;
        running = true;
        try { fixOnce(); } catch (e0) {}
        running = false;
      }, 120);
    }

    var mo = new MutationObserver(function () {
      requestFix();
    });

    mo.observe(form, { childList: true, subtree: true });
  }


  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();

(function () {
  'use strict';

  // Editor Core: работает со всеми textarea (включая quick reply / quick edit)
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

  function normalizeSelectorList(input) {
    var out = [];
    if (!input) return out;
    if (typeof input === 'string') input = input.split(',');
    if (!Array.isArray(input)) return out;
    input.forEach(function (sel) {
      sel = asText(sel).trim();
      if (!sel) return;
      out.push(sel);
    });
    return out;
  }

  function getEditorSelectors() {
    var list = [
      'textarea#message',
      'textarea[name="message"]',
      'textarea[id^="quickedit"]',
      'textarea[name^="quickedit"]',
      'textarea'
    ];
    var extra = normalizeSelectorList(cfg && cfg.editorSelectors);
    list = list.concat(extra);
    var seen = Object.create(null);
    var out = [];
    list.forEach(function (sel) {
      if (!sel) return;
      if (seen[sel]) return;
      seen[sel] = true;
      out.push(sel);
    });
    return out;
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

  function parseToolbarString(toolbar) {
    toolbar = String(toolbar || '');
    if (!toolbar) return [];

    var groups = toolbar.split('|').map(function (group) {
      return group.split(',').map(function (cmd) {
        return String(cmd || '').trim();
      }).filter(Boolean);
    }).filter(function (group) {
      return group.length > 0;
    });

    return groups;
  }

  function toolbarGroupsToString(groups) {
    if (!groups || !groups.length) return '';
    return groups.map(function (group) { return group.join(','); }).join('|');
  }

  function filterToolbar(toolbar, predicate) {
    var groups = parseToolbarString(toolbar);
    var out = [];
    groups.forEach(function (group) {
      var filtered = group.filter(function (cmd) {
        return predicate(cmd);
      });
      if (filtered.length) out.push(filtered);
    });
    return toolbarGroupsToString(out);
  }

  function isCustomCmd(cmd) {
    cmd = String(cmd || '').trim();
    if (!cmd) return false;
    return /^af_/i.test(cmd) || /^afmenu_/i.test(cmd);
  }

  function buildCustomToolbar(layout) {
    var built = buildToolbarFromLayout(layout);
    return filterToolbar(built.toolbar || '', isCustomCmd);
  }

  function mergeToolbarStrings(baseToolbar, extraToolbar) {
    var baseGroups = parseToolbarString(baseToolbar);
    var extraGroups = parseToolbarString(extraToolbar);

    if (!extraGroups.length) return toolbarGroupsToString(baseGroups);

    var baseSet = Object.create(null);
    baseGroups.forEach(function (group) {
      group.forEach(function (cmd) {
        baseSet[cmd] = true;
      });
    });

    var filteredExtra = [];
    extraGroups.forEach(function (group) {
      var filtered = group.filter(function (cmd) {
        return !baseSet[cmd];
      });
      if (filtered.length) filteredExtra.push(filtered);
    });

    if (!filteredExtra.length) return toolbarGroupsToString(baseGroups);

    var merged = baseGroups.concat(filteredExtra);
    return toolbarGroupsToString(merged);
  }

  function getGlobalToolbarFallback() {
    try {
      if (window.sceditor_opts && typeof window.sceditor_opts === 'object') {
        return String(window.sceditor_opts.toolbar || '');
      }
    } catch (e0) {}

    try {
      if (window.sceditorOptions && typeof window.sceditorOptions === 'object') {
        return String(window.sceditorOptions.toolbar || '');
      }
    } catch (e1) {}

    return '';
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

  function insertAtCursor(ta, open, close) {
    open = asText(open);
    close = asText(close);
    if (!ta) return;
    try { ta.focus(); } catch (e0) {}
    var val = asText(ta.value || '');
    var start = (typeof ta.selectionStart === 'number') ? ta.selectionStart : val.length;
    var end = (typeof ta.selectionEnd === 'number') ? ta.selectionEnd : val.length;
    ta.value = val.slice(0, start) + open + val.slice(start, end) + close + val.slice(end);
    var caret = start + open.length;
    try { ta.selectionStart = ta.selectionEnd = caret; } catch (e1) {}
    try { ta.dispatchEvent(new Event('input', { bubbles: true })); } catch (e2) {}
    try { ta.dispatchEvent(new Event('change', { bubbles: true })); } catch (e3) {}
  }

  function createTextareaAdapter(ta) {
    return {
      textarea: ta,
      ta: ta,
      focus: function () { try { ta && ta.focus && ta.focus(); } catch (e0) {} },
      insert: function (open, close) { insertAtCursor(ta, open, close); },
      insertText: function (open, close) { insertAtCursor(ta, open, close); },
      val: function (value) {
        if (value === undefined) return ta ? asText(ta.value || '') : '';
        if (ta) ta.value = asText(value);
      }
    };
  }

  function getMetaForCmd(meta, cmd) {
    if (!meta || !cmd) return null;
    if (meta[cmd]) return meta[cmd];
    if (/^af_/i.test(cmd)) {
      var esc = 'af_' + escCss(cmd.slice(3));
      if (meta[esc]) return meta[esc];
    }
    return null;
  }

  function buildFallbackEntries(layout, meta) {
    var entries = [];
    function pushSeparator() {
      if (!entries.length) return;
      var last = entries[entries.length - 1];
      if (last && last.type === 'separator') return;
      entries.push({ type: 'separator' });
    }
    if (layout && Array.isArray(layout.sections)) {
      layout.sections.forEach(function (sec, idx) {
        if (!sec || typeof sec !== 'object') return;
        var type = String(sec.type || 'group').toLowerCase();
        var title = String(sec.title || '');
        var items = Array.isArray(sec.items) ? sec.items.slice() : [];
        if (type === 'dropdown') {
          entries.push({ type: 'dropdown', title: title, items: items.slice(), id: String(sec.id || ('sec' + idx)) });
          pushSeparator();
          return;
        }
        items.forEach(function (cmd) {
          cmd = asText(cmd).trim();
          if (!cmd) return;
          if (cmd === '|') { pushSeparator(); return; }
          if (!getMetaForCmd(meta, cmd)) return;
          entries.push({ type: 'button', cmd: cmd });
        });
        pushSeparator();
      });
    } else {
      var used = Object.create(null);
      function addButton(cmd) {
        cmd = asText(cmd).trim();
        if (!cmd || used[cmd]) return;
        if (!getMetaForCmd(meta, cmd)) return;
        used[cmd] = true;
        entries.push({ type: 'button', cmd: cmd });
      }
      buttons.forEach(function (b) {
        var cmd = asText(b && (b.cmd || '')).trim();
        if (!cmd && b && b.name) cmd = 'af_' + b.name;
        addButton(cmd);
      });
      builtins.forEach(function (b) {
        var cmd = asText(b && (b.cmd || '')).trim();
        if (!cmd && b && b.name) cmd = 'af_' + b.name;
        addButton(cmd);
      });
    }
    while (entries.length && entries[0].type === 'separator') entries.shift();
    while (entries.length && entries[entries.length - 1].type === 'separator') entries.pop();
    return entries;
  }

  function ensureFallbackOutsideClose() {
    if (window.__afAqrFallbackDocBound) return;
    window.__afAqrFallbackDocBound = true;
    document.addEventListener('click', function (ev) {
      var target = ev && ev.target;
      if (target && target.closest && target.closest('.af-aqr-fallback-dropdown')) return;
      var menus = document.querySelectorAll('.af-aqr-fallback-dropdown-menu.is-open');
      for (var i = 0; i < menus.length; i++) menus[i].classList.remove('is-open');
      var btns = document.querySelectorAll('.af-aqr-fallback-dropdown-button.is-open');
      for (var j = 0; j < btns.length; j++) btns[j].classList.remove('is-open');
    });
  }

  function executeFallbackCommand(meta, cmd, ta, caller) {
    var info = getMetaForCmd(meta, cmd);
    if (!info) return;
    if (info.handler && window.afAqrBuiltinHandlers && typeof window.afAqrBuiltinHandlers[info.handler] === 'function') {
      try { window.afAqrBuiltinHandlers[info.handler](createTextareaAdapter(ta), caller); } catch (e0) {}
      return;
    }
    if (!info.opentag && !info.closetag) return;
    insertAtCursor(ta, info.opentag, info.closetag);
  }

  function renderFallbackToolbar(ta, layout, meta) {
    if (!ta || !ta.parentNode) return;
    if (ta.__afAqrFallbackDone) return;
    if (ta.getAttribute && ta.getAttribute('data-af-aqr-ignore') === '1') return;
    if (ta.classList && ta.classList.contains('af-aqr-ignore')) return;
    var entries = buildFallbackEntries(layout, meta);
    if (!entries.length) return;
    var bar = document.createElement('div');
    bar.className = 'af-aqr-fallback-toolbar';
    bar.setAttribute('data-af-aqr-fallback', '1');
    entries.forEach(function (entry) {
      if (!entry) return;
      if (entry.type === 'separator') {
        var sep = document.createElement('span');
        sep.className = 'af-aqr-fallback-sep';
        bar.appendChild(sep);
        return;
      }
      if (entry.type === 'dropdown') {
        var wrap = document.createElement('div');
        wrap.className = 'af-aqr-fallback-dropdown';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'af-aqr-fallback-button af-aqr-fallback-dropdown-button';
        btn.title = normalizeDropdownTooltip(entry.title || '');
        btn.innerHTML = '<span class="af-aqr-fallback-icon">' + svgStarMarkup() + '</span>';
        var menu = document.createElement('div');
        menu.className = 'af-aqr-fallback-dropdown-menu';
        (entry.items || []).forEach(function (cmd) {
          cmd = asText(cmd).trim();
          if (!cmd || cmd === '|') return;
          var info = getMetaForCmd(meta, cmd);
          if (!info) return;
          var item = document.createElement('button');
          item.type = 'button';
          item.className = 'af-aqr-fallback-dropdown-item';
          item.textContent = info.title || cmd;
          item.addEventListener('click', function (ev) {
            ev.preventDefault();
            executeFallbackCommand(meta, cmd, ta, item);
            menu.classList.remove('is-open');
            btn.classList.remove('is-open');
          });
          menu.appendChild(item);
        });
        btn.addEventListener('click', function (ev) {
          ev.preventDefault();
          ensureFallbackOutsideClose();
          var open = menu.classList.contains('is-open');
          var menus = document.querySelectorAll('.af-aqr-fallback-dropdown-menu.is-open');
          for (var i = 0; i < menus.length; i++) menus[i].classList.remove('is-open');
          var btns = document.querySelectorAll('.af-aqr-fallback-dropdown-button.is-open');
          for (var j = 0; j < btns.length; j++) btns[j].classList.remove('is-open');
          if (!open) {
            menu.classList.add('is-open');
            btn.classList.add('is-open');
          }
        });
        wrap.appendChild(btn);
        wrap.appendChild(menu);
        bar.appendChild(wrap);
        return;
      }
      if (entry.type === 'button') {
        var info2 = getMetaForCmd(meta, entry.cmd);
        if (!info2) return;
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'af-aqr-fallback-button';
        b.title = info2.title || entry.cmd;
        var icon = document.createElement('span');
        icon.className = 'af-aqr-fallback-icon';
        if (info2.iconSpec && info2.iconSpec.kind === 'svg') {
          icon.classList.add('is-svg');
          icon.innerHTML = info2.iconSpec.value;
        } else if (info2.iconSpec && info2.iconSpec.kind === 'url') {
          icon.style.backgroundImage = 'url("' + info2.iconSpec.value + '")';
        } else if (info2.iconSpec && info2.iconSpec.kind === 'text') {
          icon.textContent = info2.iconSpec.value;
        } else {
          icon.textContent = info2.name || entry.cmd;
        }
        b.appendChild(icon);
        b.addEventListener('click', function (ev) {
          ev.preventDefault();
          executeFallbackCommand(meta, entry.cmd, ta, b);
        });
        bar.appendChild(b);
      }
    });
    ta.parentNode.insertBefore(bar, ta);
    ta.__afAqrFallbackToolbar = bar;
    ta.__afAqrFallbackDone = true;
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
        if (href.indexOf('advancededitor.css') !== -1) return;
      }

      var base = getAssetsBase();
      var link = document.createElement('link');
      link.id = 'af-aqr-ext-css';
      link.rel = 'stylesheet';
      link.href = base.replace(/\/+$/, '') + '/advancededitor.css?v=' + Date.now();
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
      if ((id === 'message' || nm === 'message') && !isQuickEditTextarea(ta)) {
        if (ta.closest && (ta.closest('#quick_reply_form') || ta.closest('#quickreply_e'))) {
          return false;
        }
        return true;
      }
      return false;
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
  function patchGlobalToolbarOnce(toolbar, replace) {
    try {
      if (!toolbar && !replace) return;
      if (window.__afAqrExtGlobalToolbarPatched) return;
      window.__afAqrExtGlobalToolbarPatched = true;

      function patchObj(o) {
        if (!o || typeof o !== 'object') return;
        if (replace) {
          o.toolbar = String(toolbar || '');
        } else {
          var baseToolbar = String(o.toolbar || '');
          var merged = mergeToolbarStrings(baseToolbar, toolbar);
          if (merged || toolbar === '') o.toolbar = merged;
        }
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
    var baseToolbar = getGlobalToolbarFallback();
    var mergedToolbar = mergeToolbarStrings(baseToolbar, buildCustomToolbar(layout));
    var desiredToolbar = (layout ? String(out.toolbar || '') : mergedToolbar);

    // регаем команды заранее
    ensureCommands(out);
    injectExtCssOnce();

    // Патчим глобальные opts (важно для полного редактора ДО init)
    if (layout) {
      patchGlobalToolbarOnce(desiredToolbar, true);
    } else if (mergedToolbar) {
      patchGlobalToolbarOnce(mergedToolbar);
    }

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
      var baseToolbarInst = (instExisting && instExisting.opts) ? String(instExisting.opts.toolbar || '') : '';
      if (!baseToolbarInst) baseToolbarInst = getGlobalToolbarFallback();
      var mergedToolbarInst = mergeToolbarStrings(baseToolbarInst, buildCustomToolbar(layout));
      var desiredToolbarInst = (layout ? String(out.toolbar || '') : mergedToolbarInst);

      // если в тулбаре явно нет наших кнопок — делаем один безопасный rebuild (destroy + init) с сохранением текста
      // это лечит ситуацию "скрипт подключили поздно и MyBB собрал дефолтный тулбар".
      try {
        if ((layout || mergedToolbarInst) && !ta.__afAqrExtRebuiltOnce) {
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

          var normalizedCurrent = String(baseToolbarInst || '').trim();
          var normalizedDesired = String(desiredToolbarInst || '').trim();
          var needsLayoutRebuild = layout && normalizedDesired !== normalizedCurrent;

          if (!hasAnyCustom || needsLayoutRebuild) {
            ta.__afAqrExtRebuiltOnce = true;

            var keep = '';
            try { keep = (typeof instExisting.val === 'function') ? String(instExisting.val() || '') : String(ta.value || ''); } catch (eK) { keep = String(ta.value || ''); }

            var opts = cloneEditorOptions();
            opts.width = '100%';
            if (layout || mergedToolbarInst || desiredToolbarInst === '') {
              opts.toolbar = desiredToolbarInst;
            }

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
      var baseToolbar2 = getGlobalToolbarFallback();
      var mergedToolbar2 = mergeToolbarStrings(baseToolbar2, buildCustomToolbar(layout2));
      var desiredToolbar2 = (layout2 ? String(out2.toolbar || '') : mergedToolbar2);
      ensureCommands(out2);

      var opts2 = cloneEditorOptions();
      opts2.width = '100%';
      if (layout2 || desiredToolbar2 || desiredToolbar2 === '') {
        opts2.toolbar = desiredToolbar2;
      }

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
    var selectors = getEditorSelectors();
    var list = [];
    selectors.forEach(function (sel) {
      try { list = list.concat(Array.prototype.slice.call(document.querySelectorAll(sel))); } catch (e0) {}
    });

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
    var hasEditor = hasJQEditor();
    var layout = getToolbarLayout();
    var tas = findTargets();

    if (window.afAqrInjectIconCSS && typeof window.afAqrInjectIconCSS === 'function') {
      window.afAqrInjectIconCSS();
    }

    if (hasEditor) {
      sanitizeBbcodeDefinitionsOnce();

      var out = buildToolbarFromLayout(layout);
      var baseToolbar = getGlobalToolbarFallback();
      var mergedToolbar = mergeToolbarStrings(baseToolbar, buildCustomToolbar(layout));
      var desiredToolbar = (layout ? String(out.toolbar || '') : mergedToolbar);

      // готовим систему заранее (важно для полного редактора)
      ensureCommands(out);
      injectExtCssOnce();
      if (layout) {
        patchGlobalToolbarOnce(desiredToolbar, true);
      } else if (mergedToolbar) {
        patchGlobalToolbarOnce(mergedToolbar);
      }

      tas.forEach(function (ta) {
        if (!ta) return;
        if (isSceditorInternalTextarea(ta)) return;

        try { if (ta.disabled) return; } catch (e) {}

        if (ta.__afAqrFallbackToolbar) {
          try { ta.__afAqrFallbackToolbar.remove(); } catch (e0) {}
          ta.__afAqrFallbackToolbar = null;
        }
        reinitOnTextarea(ta);
      });
      return;
    }

    var meta = buildMetaByCmd();
    tas.forEach(function (ta) {
      if (!ta) return;
      if (isSceditorInternalTextarea(ta)) return;

      try { if (ta.disabled) return; } catch (e1) {}

      renderFallbackToolbar(ta, layout, meta);
    });
  }

  // API
  window.afAqrUnifiedEditor = window.afAqrUnifiedEditor || {};
  window.afAqrUnifiedEditor.initAll = scan;
  window.afAqrUnifiedEditor.refresh = scan;
  window.afAqrUnifiedEditor.initTextarea = function (ta) {
    if (!ta) return;
    if (hasJQEditor()) {
      reinitOnTextarea(ta);
      return;
    }
    renderFallbackToolbar(ta, getToolbarLayout(), buildMetaByCmd());
  };

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

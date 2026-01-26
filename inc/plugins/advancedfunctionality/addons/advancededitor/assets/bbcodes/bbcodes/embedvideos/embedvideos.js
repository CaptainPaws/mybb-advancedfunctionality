(function () {
  'use strict';

  // ===== registries =====
  if (!window.afAeBuiltinHandlers)  window.afAeBuiltinHandlers  = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  // one-shot
  if (window.afAeEmbedVideosInitialized) return;
  window.afAeEmbedVideosInitialized = true;

  // НЕ ТРОГАЕМ: чтобы не сломать кнопку/конструктор/manifest
  var ID  = 'embedvideos';
  var CMD = 'af_embedvideos';

  function asText(x) { return String(x == null ? '' : x); }

  // payload (если AE core прокидывает)
  var P = window.afAePayload || window.afAdvancedEditorPayload || {};
  var BB = (P && P.bbcodes && P.bbcodes.embedvideos) ? P.bbcodes.embedvideos : (window.afAeEmbedVideos || {});
  var providers = (BB && BB.providers) ? BB.providers : null;

  if (!providers) {
    providers = {
      youtube: { label: 'YouTube',  domains: ['youtube.com', 'youtu.be'] },
      rutube:  { label: 'RuTube',   domains: ['rutube.ru'] },
      coub:    { label: 'Coub',     domains: ['coub.com'] },
      kodik:   { label: 'Kodik',    domains: ['kodik.info'] },
      tme:     { label: 'Telegram', domains: ['t.me'] },
      other:   { label: 'Другой',   domains: [] }
    };
  }

  function normalizeUrl(u) {
    u = asText(u).trim();
    if (!u) return '';
    if (!/^https?:\/\//i.test(u) && !/^\/\//.test(u)) u = 'https://' + u;
    return u;
  }

  function looksLikeIframe(s) {
    s = asText(s).trim();
    if (!s) return false;
    return /<iframe\b/i.test(s) && /<\/iframe>/i.test(s);
  }

  // ===== instance helpers (копия паттерна из floatbb/indent/fontfamily) =====
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

  function insertTextFallback(text, ctx) {
    var ta = getTextareaFromCtx(ctx);
    if (!ta) return false;

    try {
      var start = ta.selectionStart || 0;
      var end = ta.selectionEnd || 0;
      var val = String(ta.value || '');
      var before = val.slice(0, start);
      var after = val.slice(end);

      ta.value = before + text + after;

      var caret = before.length + text.length;
      ta.focus();
      ta.setSelectionRange(caret, caret);
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      return true;
    } catch (e) {
      return false;
    }
  }

  function insertVideo(editor, value, providerKey) {
    value = asText(value).trim();
    if (!value) return;

    // "Другой" — вставляем как HTML-блок (iframe/embed-код)
    if (providerKey === 'other') {
      var bbHtml = '[html]' + value + '[/html]';

      try {
        if (editor && typeof editor.insertText === 'function') {
          editor.insertText(bbHtml);
          if (typeof editor.focus === 'function') editor.focus();
          return;
        }
      } catch (e0) {}

      insertTextFallback(bbHtml, { sceditor: editor });
      return;
    }

    // Остальные — обычный [video]url[/video]
    var url = normalizeUrl(value);
    if (!url) return;

    var bb = '[video]' + url + '[/video]';

    try {
      if (editor && typeof editor.insertText === 'function') {
        editor.insertText(bb);
        if (typeof editor.focus === 'function') editor.focus();
        return;
      }
    } catch (e1) {}

    insertTextFallback(bb, { sceditor: editor });
  }


  // ===== dropdown =====
  function makeProvidersDropdown(editor, caller) {
    var wrap = document.createElement('div');
    wrap.className = 'af-ev-dd';

    var title = document.createElement('div');
    title.className = 'af-ev-title';
    title.textContent = 'Выбери хостинг';
    wrap.appendChild(title);

    var grid = document.createElement('div');
    grid.className = 'af-ev-grid';
    wrap.appendChild(grid);

    Object.keys(providers).forEach(function (key) {
      var item = providers[key] || {};
      var label = asText(item.label || key);

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'af-ev-item';
      btn.textContent = label;

      btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        makeInputDropdown(editor, caller, key);
      });

      grid.appendChild(btn);
    });

    editor.createDropDown(caller, 'sceditor-embedvideos-picker', wrap);
  }

  function placeholderFor(key) {
    if (key === 'tme') return 'Напр.: https://t.me/retra/32697?embed=1&tme_mode=1';
    if (key === 'youtube') return 'Напр.: https://www.youtube.com/watch?v=...';
    if (key === 'rutube') return 'Напр.: https://rutube.ru/video/...';
    if (key === 'coub') return 'Напр.: https://coub.com/view/...';
    if (key === 'kodik') return 'Напр.: https://kodik.info/...';
    if (key === 'other') return 'Вставь ссылку или embed-код (iframe)…';
    return 'Вставь ссылку на видео…';
  }

  function makeInputDropdown(editor, caller, providerKey) {
    try { editor.closeDropDown(true); } catch (e0) {}

    var wrap = document.createElement('div');
    wrap.className = 'af-ev-dd';

    var item = providers[providerKey] || {};
    var label = asText(item.label || providerKey);

    var title = document.createElement('div');
    title.className = 'af-ev-title';
    title.textContent = label;
    wrap.appendChild(title);

    // подсказка именно для "Другой"
    if (providerKey === 'other') {
      var hint = document.createElement('div');
      hint.className = 'af-ev-hint';
      hint.textContent = 'Для “Другое” можно вставлять кодом для вставки (iframe), например embed-код от хостинга.';
      wrap.appendChild(hint);
    }

    var row = document.createElement('div');
    row.className = 'af-ev-row';

    var input;
    if (providerKey === 'other') {
      // textarea удобнее под iframe
      input = document.createElement('textarea');
      input.className = 'af-ev-input af-ev-textarea';
      input.rows = 4;
    } else {
      input = document.createElement('input');
      input.type = 'text';
      input.className = 'af-ev-input';
    }

    input.placeholder = placeholderFor(providerKey);
    row.appendChild(input);
    wrap.appendChild(row);

    var actions = document.createElement('div');
    actions.className = 'af-ev-actions';

    var back = document.createElement('button');
    back.type = 'button';
    back.className = 'af-ev-back';
    back.textContent = '← Назад';

    var ins = document.createElement('button');
    ins.type = 'button';
    ins.className = 'af-ev-insert';
    ins.textContent = 'Вставить';

    back.addEventListener('click', function (ev) {
      ev.preventDefault();
      try { editor.closeDropDown(true); } catch (e0) {}
      makeProvidersDropdown(editor, caller);
    });

    function doInsert(ev) {
      if (ev && ev.preventDefault) ev.preventDefault();
      insertVideo(editor, input.value, providerKey);
      try { editor.closeDropDown(true); } catch (e0) {}
    }

    ins.addEventListener('click', doInsert);

    input.addEventListener('keydown', function (ev) {
      var k = ev && (ev.key || ev.keyCode);
      // Enter в textarea не должен ломать ввод, но Ctrl+Enter — удобно “вставить”
      if (providerKey === 'other') {
        if ((ev.ctrlKey || ev.metaKey) && (k === 'Enter' || k === 13)) doInsert(ev);
        return;
      }
      if (k === 'Enter' || k === 13) doInsert(ev);
    });

    actions.appendChild(back);
    actions.appendChild(ins);
    wrap.appendChild(actions);

    editor.createDropDown(caller, 'sceditor-embedvideos-picker', wrap);

    setTimeout(function () {
      try { input.focus(); } catch (e0) {}
    }, 0);
  }

  function openSceditorDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;
    try { editor.closeDropDown(true); } catch (e0) {}
    makeProvidersDropdown(editor, caller);
    return true;
  }

  // ===== SCEditor command =====
  function patchSceditorEmbedVideosCommand() {
    if (!window.jQuery) return false;
    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.command) return false;

    $.sceditor.command.set(CMD, {
      exec: function (caller) {
        if (!openSceditorDropdown(this, caller)) insertTextFallback('[video]https://[/video]', { sceditor: this });
      },
      txtExec: function (caller) {
        if (!openSceditorDropdown(this, caller)) insertTextFallback('[video]https://[/video]', { sceditor: this });
      },
      tooltip: 'Вставить видео'
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

  waitAnd(patchSceditorEmbedVideosCommand, 150);

  // ===== handlers for AQR/AE core =====
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

    insertTextFallback('[video]https://[/video]', ctx || {});
  }

  var handlerObj = {
    id: ID,
    title: 'Вставить видео',
    onClick: aqrOpen,
    click: aqrOpen,
    action: aqrOpen,
    run: aqrOpen,
    init: function () {}
  };

  function handlerFn(inst, caller) {
    var editor = getSceditorInstanceFromCtx(inst || {});
    if (!editor) editor = getSceditorInstanceFromCtx({});
    if (!editor) return;

    if (caller && caller.nodeType === 1) openSceditorDropdown(editor, caller);
    else insertTextFallback('[video]https://[/video]', { sceditor: editor });
  }

  function registerHandlers() {
    // AQR
    window.afAqrBuiltinHandlers[ID] = handlerObj;
    window.afAqrBuiltinHandlers[CMD] = handlerObj;

    // AE
    window.afAeBuiltinHandlers[ID] = handlerFn;
    window.afAeBuiltinHandlers[CMD] = handlerFn;
  }

  registerHandlers();
  for (var i = 1; i <= 20; i++) setTimeout(registerHandlers, i * 250);

  // =====================================================
  // Frontend renderer:
  // converts <div class="af-ev-embed" data-af-ev-*>...</div> into iframe / telegram widget
  // =====================================================

  function ensureTelegramWidgetScript() {
    if (document.getElementById('af-ev-telegram-widget')) return;
    var s = document.createElement('script');
    s.id = 'af-ev-telegram-widget';
    s.async = true;
    s.src = 'https://telegram.org/js/telegram-widget.js?22';
    document.head.appendChild(s);
  }

  function renderOneEmbed(el) {
    try {
      if (!el || el.__afEvRendered) return;
      el.__afEvRendered = true;

      var type  = (el.getAttribute('data-af-ev-type') || '').toLowerCase();
      var src   = el.getAttribute('data-af-ev-src') || '';
      var id    = el.getAttribute('data-af-ev-id') || '';
      var allow = el.getAttribute('data-af-ev-allow') || '';
      var afull = el.getAttribute('data-af-ev-allowfullscreen') || '';

      var fallbackHtml = el.innerHTML;

      function makeResponsiveIframe(url, opts) {
        opts = opts || {};
        if (!url) return false;

        el.style.position = 'relative';
        el.style.width = '100%';
        el.style.maxWidth = '100%';
        el.style.paddingTop = '56.25%'; // 16:9
        el.style.margin = '8px 0';

        var iframe = document.createElement('iframe');
        iframe.src = url;
        iframe.loading = 'lazy';
        iframe.setAttribute('frameborder', '0');
        iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');


        // allow/allowfullscreen — для “Другой”
        if (opts.allow) iframe.setAttribute('allow', opts.allow);
        if (opts.allowfullscreen) iframe.allowFullscreen = true;

        // базовый allow для нормальных хостингов
        if (!opts.allow) {
          iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
          iframe.allowFullscreen = true;
        }

        iframe.style.position = 'absolute';
        iframe.style.left = '0';
        iframe.style.top = '0';
        iframe.style.width = '100%';
        iframe.style.height = '100%';
        iframe.style.border = '0';

        el.innerHTML = '';
        el.appendChild(iframe);
        return true;
      }

      // ---- Telegram: виджет ----
      if (type === 'telegram' && id) {
        el.style.position = 'relative';
        el.style.width = '100%';
        el.style.maxWidth = '100%';
        el.style.margin = '8px 0';

        el.innerHTML = '';

        var bq = document.createElement('blockquote');
        bq.className = 'telegram-post';
        bq.setAttribute('data-telegram-post', id);
        bq.setAttribute('data-width', '100%');
        el.appendChild(bq);

        ensureTelegramWidgetScript();
        return;
      }

      // ---- Telegram iframe fallback ----
      if (type === 'telegram_iframe' && src) {
        if (makeResponsiveIframe(src)) return;
      }

      // ---- “Другой”: сырой iframe -> src уже готов ----
      if (type === 'iframe_raw' && src) {
        if (makeResponsiveIframe(src, {
          allow: allow,
          allowfullscreen: (afull === '1' || afull === 'true')
        })) return;
      }

      // ---- Kodik ----
      if (type === 'kodik' && src) {
        if (makeResponsiveIframe(src)) return;
      }

      // ---- Остальные iframe-типы ----
      if (src && (type === 'youtube' || type === 'rutube' || type === 'coub')) {
        if (makeResponsiveIframe(src)) return;
      }

      // fallback
      el.__afEvRendered = true;
      el.innerHTML = fallbackHtml;

    } catch (e) {
      try { el.__afEvRendered = false; } catch (_) {}
    }
  }

  function renderAllEmbeds(root) {
    root = root || document;
    var list = root.querySelectorAll ? root.querySelectorAll('.af-ev-embed[data-af-ev-type]') : [];
    for (var i = 0; i < (list ? list.length : 0); i++) renderOneEmbed(list[i]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { renderAllEmbeds(document); });
  } else {
    renderAllEmbeds(document);
  }

  if (window.MutationObserver) {
    try {
      var mo = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
          var m = mutations[i];
          if (m && m.addedNodes) {
            for (var j = 0; j < m.addedNodes.length; j++) {
              var n = m.addedNodes[j];
              if (n && n.nodeType === 1) {
                if (n.matches && n.matches('.af-ev-embed[data-af-ev-type]')) renderOneEmbed(n);
                else renderAllEmbeds(n);
              }
            }
          }
        }
      });
      mo.observe(document.body, { childList: true, subtree: true });
    } catch (e) {}
  }

})();

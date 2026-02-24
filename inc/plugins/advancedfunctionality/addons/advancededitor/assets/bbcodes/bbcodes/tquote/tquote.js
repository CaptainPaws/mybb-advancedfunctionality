(function () {
  'use strict';

  // ===== registries =====
  if (!window.afAeBuiltinHandlers)  window.afAeBuiltinHandlers  = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  // one-shot
  if (window.__afAeTquoteLoaded) return;
  window.__afAeTquoteLoaded = true;
  if (!window.AFAE || typeof window.AFAE.hasEditor !== 'function' || !window.AFAE.hasEditor()) return;

  var ID  = 'tquote';
  var CMD = 'af_tquote';

  function asText(x) { return String(x == null ? '' : x); }

  function normSide(x) {
    x = asText(x).trim().toLowerCase();
    return (x === 'right' || x === 'r' || x === '2') ? 'right' : 'left';
  }

  function normHex(x) {
    x = asText(x).trim();
    if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(x)) return x.toLowerCase();
    return '';
  }

  // ===== instance helpers (паттерн как floatbb) =====
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
      try { ta.dispatchEvent(new Event('input', { bubbles: true })); } catch (e0) {}
      return true;
    } catch (e) {
      return false;
    }
  }

  function buildTag(side, accent, bg) {
    side = normSide(side);
    accent = normHex(accent);
    bg = normHex(bg);

    var open = '[tquote side=' + side;
    if (accent) open += ' accent=' + accent;
    if (bg) open += ' bg=' + bg;
    open += ']';

    return { open: open, close: '[/tquote]' };
  }

  // ===== dropdown =====
  function makeDropdown(editor, caller) {
    var wrap = document.createElement('div');
    wrap.className = 'af-tquote-dd';
    wrap.setAttribute('data-side', 'left');

    wrap.innerHTML =
      '<div class="af-tquote-dd-hd">' +
      '  <div class="af-tquote-dd-title">Типографическая цитата</div>' +
      '</div>' +
      '<div class="af-tquote-dd-body">' +

      '  <div class="af-tquote-dd-row">' +
      '    <div class="af-tquote-dd-label">Сторона акцента</div>' +
      '    <div class="af-tquote-dd-seg" role="group" aria-label="Сторона">' +
      '      <button type="button" class="af-tquote-dd-segbtn" data-side="left">Слева</button>' +
      '      <button type="button" class="af-tquote-dd-segbtn" data-side="right">Справа</button>' +
      '    </div>' +
      '  </div>' +

      '  <div class="af-tquote-dd-grid">' +

      '    <label class="af-tquote-dd-field">' +
      '      <span>Цвет акцента</span>' +
      '      <div class="af-tquote-dd-color">' +
      '        <input class="af-tquote-accent" type="color" value="#ffffff">' +
      '        <input class="af-tquote-accent-hex" type="text" value="#ffffff" maxlength="7" placeholder="#aabbcc">' +
      '      </div>' +
      '    </label>' +

      '    <label class="af-tquote-dd-field">' +
      '      <span>Цвет блока</span>' +
      '      <div class="af-tquote-dd-color">' +
      '        <input class="af-tquote-bg" type="color" value="#111111">' +
      '        <input class="af-tquote-bg-hex" type="text" value="#111111" maxlength="7" placeholder="#112233">' +
      '      </div>' +
      '    </label>' +

      '  </div>' +

      '  <div class="af-tquote-dd-preview" aria-hidden="true">' +
      '    <div class="af-tquote-dd-previewbox" data-side="left">' +
      '      <span class="af-tquote-dd-previewtext">Предпросмотр блока</span>' +
      '    </div>' +
      '  </div>' +

      '  <div class="af-tquote-dd-actions">' +
      '    <button type="button" class="button af-tquote-insert">Вставить</button>' +
      '  </div>' +

      '</div>';

    function closeDd() {
      try { editor.closeDropDown(true); } catch (e0) {}
    }

    var btns = wrap.querySelectorAll('.af-tquote-dd-segbtn');
    var btnInsert = wrap.querySelector('.af-tquote-insert');

    var inpAccent = wrap.querySelector('.af-tquote-accent');
    var inpAccentHex = wrap.querySelector('.af-tquote-accent-hex');
    var inpBg = wrap.querySelector('.af-tquote-bg');
    var inpBgHex = wrap.querySelector('.af-tquote-bg-hex');
    var preview = wrap.querySelector('.af-tquote-dd-previewbox');

    function setSide(side) {
      side = normSide(side);
      wrap.setAttribute('data-side', side);

      for (var i = 0; i < btns.length; i++) {
        btns[i].classList.toggle('is-active', btns[i].getAttribute('data-side') === side);
      }

      applyPreview();
    }

    function syncHexFromColor(inpColor, inpHex) {
      try { inpHex.value = asText(inpColor.value).toLowerCase(); } catch (e0) {}
    }

    function syncColorFromHex(inpHex, inpColor) {
      var v = normHex(inpHex.value);
      if (v) {
        try { inpColor.value = v; } catch (e0) {}
        inpHex.value = v;
      }
    }

    function applyPreview() {
      if (!preview) return;

      var side = wrap.getAttribute('data-side') || 'left';
      var accent = normHex(inpAccentHex ? inpAccentHex.value : (inpAccent ? inpAccent.value : ''));
      var bg = normHex(inpBgHex ? inpBgHex.value : (inpBg ? inpBg.value : ''));

      // CSS variables
      preview.style.setProperty('--af-tq-accent', accent || '#ffffff');
      preview.style.setProperty('--af-tq-bg', bg || '#111111');

      // side for полосы + позиционирования кавычек
      preview.setAttribute('data-side', side);
    }

    // init
    setSide('left');
    if (inpAccent && inpAccentHex) syncHexFromColor(inpAccent, inpAccentHex);
    if (inpBg && inpBgHex) syncHexFromColor(inpBg, inpBgHex);
    applyPreview();

    wrap.addEventListener('click', function (e) {
      var b = e.target && e.target.closest ? e.target.closest('button[data-side]') : null;
      if (!b) return;
      e.preventDefault();
      setSide(b.getAttribute('data-side'));
    }, false);

    if (inpAccent) inpAccent.addEventListener('input', function () {
      if (inpAccentHex) syncHexFromColor(inpAccent, inpAccentHex);
      applyPreview();
    });

    if (inpBg) inpBg.addEventListener('input', function () {
      if (inpBgHex) syncHexFromColor(inpBg, inpBgHex);
      applyPreview();
    });

    if (inpAccentHex) inpAccentHex.addEventListener('change', function () {
      if (inpAccent) syncColorFromHex(inpAccentHex, inpAccent);
      applyPreview();
    });

    if (inpBgHex) inpBgHex.addEventListener('change', function () {
      if (inpBg) syncColorFromHex(inpBgHex, inpBg);
      applyPreview();
    });

    function insertNow() {
      var side = wrap.getAttribute('data-side') || 'left';
      var accent = normHex(inpAccentHex ? inpAccentHex.value : '');
      var bg = normHex(inpBgHex ? inpBgHex.value : '');

      var t = buildTag(side, accent, bg);
      insertWrap(t.open, t.close, { sceditor: editor });
      closeDd();
    }

    if (btnInsert) {
      btnInsert.addEventListener('click', function (ev) {
        ev.preventDefault();
        insertNow();
      });
    }

    // Enter = вставить (если фокус на hex)
    function onEnter(ev) {
      if (!ev) return;
      if (ev.key === 'Enter') {
        ev.preventDefault();
        insertNow();
      }
    }
    if (inpAccentHex) inpAccentHex.addEventListener('keydown', onEnter);
    if (inpBgHex) inpBgHex.addEventListener('keydown', onEnter);

    editor.createDropDown(caller, 'sceditor-tquote-picker', wrap);
  }

  function openSceditorDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;
    try { editor.closeDropDown(true); } catch (e0) {}
    makeDropdown(editor, caller);
    return true;
  }

  // ===== SCEditor command =====
  function patchSceditorTquoteCommand() {
    if (!window.jQuery) return false;
    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.command) return false;

    function fallbackInsert(ed) {
      insertWrap('[tquote side=left accent=#ffffff bg=#111111]', '[/tquote]', { sceditor: ed });
    }

    $.sceditor.command.set(CMD, {
      exec: function (caller) {
        if (!openSceditorDropdown(this, caller)) fallbackInsert(this);
      },
      txtExec: function (caller) {
        if (!openSceditorDropdown(this, caller)) fallbackInsert(this);
      },
      tooltip: 'Типографическая цитата'
    });

    // алиас на всякий (если layout позовёт tquote)
    $.sceditor.command.set('tquote', {
      exec: function (caller) {
        if (!openSceditorDropdown(this, caller)) fallbackInsert(this);
      },
      txtExec: function (caller) {
        if (!openSceditorDropdown(this, caller)) fallbackInsert(this);
      },
      tooltip: 'Типографическая цитата'
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

  waitAnd(patchSceditorTquoteCommand, 150);

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

    insertWrap('[tquote side=left accent=#ffffff bg=#111111]', '[/tquote]', ctx || {});
  }

  var handlerObj = {
    id: ID,
    title: 'Типографическая цитата',
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
    else insertWrap('[tquote side=left accent=#ffffff bg=#111111]', '[/tquote]', { sceditor: editor });
  }

  function registerHandlers() {
    window.afAqrBuiltinHandlers[ID] = handlerObj;
    window.afAqrBuiltinHandlers[CMD] = handlerObj;

    window.afAeBuiltinHandlers[ID] = handlerFn;
    window.afAeBuiltinHandlers[CMD] = handlerFn;
  }

  registerHandlers();
  for (var i = 1; i <= 20; i++) setTimeout(registerHandlers, i * 250);

})();

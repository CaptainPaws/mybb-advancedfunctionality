(function () {
  'use strict';

  // ===== registries =====
  if (!window.afAeBuiltinHandlers)  window.afAeBuiltinHandlers  = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  // one-shot
  if (window.afAeIndentInitialized) return;
  window.afAeIndentInitialized = true;
  function afAeRunWhenReady(cb) {
    if (window.AFAE && typeof window.AFAE.onEditorReady === 'function') {
      window.AFAE.onEditorReady(cb);
      return;
    }
    window.__AFAE_QUEUE = window.__AFAE_QUEUE || [];
    window.__AFAE_QUEUE.push(function () {
      if (window.AFAE && typeof window.AFAE.onEditorReady === 'function') {
        window.AFAE.onEditorReady(cb);
      }
    });
  }

  afAeRunWhenReady(function () {

  var ID  = 'indent';
  var CMD = 'af_indent';

  function safeLevel(x) {
    x = parseInt(x, 10);
    if (!isFinite(x)) return 1;
    if (x < 1) return 1;
    if (x > 3) return 3;
    return x;
  }

  // ===== instance helpers (как в fontfamily) =====
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

  function insertPrefix(open, ctx) {
    var inst = getSceditorInstanceFromCtx(ctx);
    if (inst && typeof inst.insertText === 'function') {
      // SCEditor: open + (selection) + close; close='' => просто префикс
      inst.insertText(open, '');
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

      ta.value = before + open + sel + after;

      var caret = before.length + open.length + sel.length;
      ta.focus();
      ta.setSelectionRange(caret, caret);
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      return true;
    } catch (e) {
      return false;
    }
  }

  function applyIndent(editor, level) {
    level = safeLevel(level);
    // ОДИНАРНЫЙ тег — без закрывающего
    var open = '[indent=' + level + ']';

    try {
      if (editor && typeof editor.insertText === 'function') {
        editor.insertText(open, '');
        if (typeof editor.focus === 'function') editor.focus();
        return;
      }
    } catch (e0) {}

    insertPrefix(open, { sceditor: editor });
  }

  // ===== dropdown =====
  function makeDropdown(editor, caller) {
    var wrap = document.createElement('div');
    wrap.className = 'af-indent-dd';

    function addItem(level, title, sample) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'af-indent-item';

      var nm = document.createElement('div');
      nm.className = 'af-indent-name';
      nm.textContent = title;

      var sm = document.createElement('div');
      sm.className = 'af-indent-sample';
      sm.textContent = sample;

      btn.appendChild(nm);
      btn.appendChild(sm);

      btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        applyIndent(editor, level);
        try { editor.closeDropDown(true); } catch (e0) {}
      });

      wrap.appendChild(btn);
    }

    addItem(1, 'Отступ 1em', 'Отступ первой строки: 1em');
    addItem(2, 'Отступ 2em', 'Отступ первой строки: 2em');
    addItem(3, 'Отступ 3em', 'Отступ первой строки: 3em');

    editor.createDropDown(caller, 'sceditor-indent-picker', wrap);
  }

  function openSceditorDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;
    try { editor.closeDropDown(true); } catch (e0) {}
    makeDropdown(editor, caller);
    return true;
  }

  // ===== SCEditor command =====
  function patchSceditorIndentCommand() {
    if (!window.jQuery) return false;
    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.command) return false;

    $.sceditor.command.set(CMD, {
      exec: function (caller) {
        if (!openSceditorDropdown(this, caller)) insertPrefix('[indent=1]', { sceditor: this });
      },
      txtExec: function (caller) {
        if (!openSceditorDropdown(this, caller)) insertPrefix('[indent=1]', { sceditor: this });
      },
      tooltip: 'Отступ первой строки (1–3em)'
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

  waitAnd(patchSceditorIndentCommand, 150);

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

    insertPrefix('[indent=1]', ctx || {});
  }

  var handlerObj = {
    id: ID,
    title: 'Отступ первой строки (1–3em)',
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
    else insertPrefix('[indent=1]', { sceditor: editor });
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

  });
})();

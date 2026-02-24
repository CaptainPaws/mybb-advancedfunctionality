(function () {
  'use strict';

  // ===== registries =====
  if (!window.afAeBuiltinHandlers)  window.afAeBuiltinHandlers  = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  // one-shot
  if (window.afAeFloatbbInitialized) return;
  window.afAeFloatbbInitialized = true;
  if (!window.AFAE || typeof window.AFAE.onEditorReady !== 'function') return;
  window.AFAE.onEditorReady(function () {

  // НЕ ТРОГАЕМ: чтобы не сломать кнопку/конструктор/manifest
  var ID  = 'floatbb';
  var CMD = 'af_floatbb';

  function asText(x) { return String(x == null ? '' : x); }

  function normDir(x) {
    x = asText(x).trim().toLowerCase();
    if (x === 'left' || x === 'l' || x === '1') return 'left';
    if (x === 'right' || x === 'r' || x === '2') return 'right';
    return 'left';
  }

  // ===== instance helpers (копия паттерна из indent/fontfamily) =====
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
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      return true;
    } catch (e) {
      return false;
    }
  }

  function applyFloat(editor, dir) {
    dir = normDir(dir);

    // ТЕГ ДОЛЖЕН БЫТЬ float
    var open = '[float=' + dir + ']';
    var close = '[/float]';

    try {
      if (editor && typeof editor.insertText === 'function') {
        editor.insertText(open, close);
        if (typeof editor.focus === 'function') editor.focus();
        return;
      }
    } catch (e0) {}

    insertWrap(open, close, { sceditor: editor });
  }

  // ===== dropdown (ОДИН В ОДИН как indent.js) =====
  function makeDropdown(editor, caller) {
    var wrap = document.createElement('div');
    wrap.className = 'af-floatbb-dd';

    function addItem(dir, title, sample) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'af-floatbb-item';

      var nm = document.createElement('div');
      nm.className = 'af-floatbb-name';
      nm.textContent = title;

      var sm = document.createElement('div');
      sm.className = 'af-floatbb-sample';
      sm.textContent = sample;

      btn.appendChild(nm);
      btn.appendChild(sm);

      btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        applyFloat(editor, dir);
        try { editor.closeDropDown(true); } catch (e0) {}
      });

      wrap.appendChild(btn);
    }

    addItem('left',  'Обтекание слева',  'Блок слева, текст справа');
    addItem('right', 'Обтекание справа', 'Блок справа, текст слева');

    // ВАЖНО: создаём dropdown через SCEditor как в indent.js
    editor.createDropDown(caller, 'sceditor-floatbb-picker', wrap);
  }

  function openSceditorDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;
    try { editor.closeDropDown(true); } catch (e0) {}
    makeDropdown(editor, caller);
    return true;
  }

  // ===== SCEditor command =====
  function patchSceditorFloatbbCommand() {
    if (!window.jQuery) return false;
    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.command) return false;

    $.sceditor.command.set(CMD, {
      exec: function (caller) {
        if (!openSceditorDropdown(this, caller)) insertWrap('[float=left]', '[/float]', { sceditor: this });
      },
      txtExec: function (caller) {
        if (!openSceditorDropdown(this, caller)) insertWrap('[float=left]', '[/float]', { sceditor: this });
      },
      tooltip: 'Обтекание (слева/справа)'
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

  waitAnd(patchSceditorFloatbbCommand, 150);

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

    insertWrap('[float=left]', '[/float]', ctx || {});
  }

  var handlerObj = {
    id: ID,
    title: 'Обтекание (слева/справа)',
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
    else insertWrap('[float=left]', '[/float]', { sceditor: editor });
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

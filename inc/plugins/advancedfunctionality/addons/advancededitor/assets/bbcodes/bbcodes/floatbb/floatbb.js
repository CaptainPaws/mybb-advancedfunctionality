(function () {
  'use strict';

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  if (window.afAeFloatbbInitialized) return;
  window.afAeFloatbbInitialized = true;

  var ID = 'floatbb';
  var CMD = 'af_floatbb';

  function asText(x) {
    return String(x == null ? '' : x);
  }

  function normDir(x) {
    x = asText(x).trim().toLowerCase();
    if (x === 'right' || x === 'r' || x === '2') return 'right';
    return 'left';
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

  function getSceditorInstanceFromCtx(ctx) {
    if (ctx && typeof ctx.insertText === 'function') return ctx;
    if (ctx && ctx.sceditor && typeof ctx.sceditor.insertText === 'function') return ctx.sceditor;

    try {
      if (window.jQuery) {
        var $ = window.jQuery;
        var $ta = $('textarea#message, textarea[name="message"]').first();
        if ($ta.length) {
          var inst = $ta.sceditor && $ta.sceditor('instance');
          if (inst) return inst;
        }
      }
    } catch (e) {}

    return null;
  }

  function insertWrap(open, close, ctx) {
    var inst = getSceditorInstanceFromCtx(ctx);

    if (inst) {
      inst.insertText(open, close);
      inst.focus();
      return true;
    }

    var ta = getTextareaFromCtx(ctx);
    if (!ta) return false;

    var start = ta.selectionStart || 0;
    var end = ta.selectionEnd || 0;

    var before = ta.value.slice(0, start);
    var sel = ta.value.slice(start, end);
    var after = ta.value.slice(end);

    ta.value = before + open + sel + close + after;

    var caret = before.length + open.length + sel.length + close.length;

    ta.focus();
    ta.setSelectionRange(caret, caret);
    ta.dispatchEvent(new Event('input', { bubbles: true }));

    return true;
  }

  function applyFloat(editor, dir) {
    dir = normDir(dir);

    var open = '[float=' + dir + ']';
    var close = '[/float]';

    try {
      if (editor && editor.insertText) {
        editor.insertText(open, close);
        editor.focus();
        return;
      }
    } catch (e) {}

    insertWrap(open, close, { sceditor: editor });
  }

  function makeDropdown(editor, caller) {
    var wrap = document.createElement('div');
    wrap.className = 'af-floatbb-dd';

    function addItem(dir, title) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'af-floatbb-item';
      btn.textContent = title;

      btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        applyFloat(editor, dir);
        try { editor.closeDropDown(true); } catch (e) {}
      });

      wrap.appendChild(btn);
    }

    addItem('left', 'Обтекание слева');
    addItem('right', 'Обтекание справа');

    editor.createDropDown(caller, 'sceditor-floatbb-picker', wrap);
  }

  function openSceditorDropdown(editor, caller) {
    if (!editor || !editor.createDropDown) return false;

    try { editor.closeDropDown(true); } catch (e) {}

    makeDropdown(editor, caller);
    return true;
  }

  function patchSceditorCommand() {
    if (!window.jQuery) return false;

    var $ = window.jQuery;

    if (!$.sceditor || !$.sceditor.command) return false;

    $.sceditor.command.set(CMD, {
      exec: function (caller) {
        if (!openSceditorDropdown(this, caller)) {
          insertWrap('[float=left]', '[/float]', { sceditor: this });
        }
      },
      txtExec: function (caller) {
        if (!openSceditorDropdown(this, caller)) {
          insertWrap('[float=left]', '[/float]', { sceditor: this });
        }
      },
      tooltip: 'Обтекание (слева/справа)'
    });

    return true;
  }

  function waitAnd(fn, max) {
    var tries = 0;

    (function tick() {
      tries++;

      if (fn()) return;

      if (tries > (max || 150)) return;

      setTimeout(tick, 100);
    })();
  }

  waitAnd(patchSceditorCommand, 150);

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
    if (!editor) return;

    if (caller && caller.nodeType === 1)
      openSceditorDropdown(editor, caller);
    else
      insertWrap('[float=left]', '[/float]', { sceditor: editor });
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



/* ---------- WYSIWYG BBCode mapping ---------- */

(function () {

  function register() {

    if (!window.jQuery) return false;

    var $ = window.jQuery;

    if (!$.sceditor || !$.sceditor.plugins || !$.sceditor.plugins.bbcode) return false;

    var bb = $.sceditor.plugins.bbcode.bbcode;

    if (!bb) return false;

    if (bb.__afFloatbbRegistered) return true;
    bb.__afFloatbbRegistered = true;

    bb.set('float', {

      isInline: false,

      html: function (token, attrs, content) {

        var dir = 'left';

        if (attrs && attrs.defaultattr) {
          var v = String(attrs.defaultattr).toLowerCase();
          if (v === 'right' || v === 'r' || v === '2') dir = 'right';
        }

        return '<div class="af-floatbb af-floatbb-' + dir + '" data-af-bb="float" data-af-dir="' + dir + '">' + content + '</div>';
      },

      format: function (el, content) {

        var dir = 'left';

        if (el.getAttribute) {
          var a = el.getAttribute('data-af-dir');
          if (a) dir = a;
        }

        if ((!dir || dir === 'left') && el.className) {
          var cls = String(el.className);

          if (cls.indexOf('af-floatbb-right') !== -1) dir = 'right';
          else if (cls.indexOf('af-floatbb-left') !== -1) dir = 'left';
        }

        return '[float=' + dir + ']' + content + '[/float]';
      }

    });

    /* --- ПЕРЕПАРСИТЬ редактор после регистрации --- */

    try {
      var inst = jQuery('textarea#message, textarea[name="message"]').sceditor('instance');

      if (inst) {
        var val = inst.val();
        inst.val('');
        inst.val(val);
      }

    } catch (e) {}

    return true;
  }

  var tries = 0;

  (function wait() {

    tries++;

    if (register()) return;

    if (tries > 100) return;

    setTimeout(wait, 100);

  })();

})();

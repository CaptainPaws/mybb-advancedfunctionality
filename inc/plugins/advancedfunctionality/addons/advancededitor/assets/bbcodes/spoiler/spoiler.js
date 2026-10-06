(function () {
  'use strict';

  function asText(x) { return String(x == null ? '' : x); }

  // registries
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);
  if (!window.afAeBuiltinHandlers)  window.afAeBuiltinHandlers  = Object.create(null);

  // one-shot
  if (window.__afAeSpoilerPackLoaded) return;
  window.__afAeSpoilerPackLoaded = true;

  var ID  = 'spoiler';
  var CMD = 'af_spoiler';

  // ===== instance helpers (как в floatbb/table) =====
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

  function insertAtCursor(ta, text) {
    if (!ta) return false;
    text = asText(text);

    try {
      var start = (typeof ta.selectionStart === 'number') ? ta.selectionStart : 0;
      var end   = (typeof ta.selectionEnd === 'number') ? ta.selectionEnd : start;
      var val   = String(ta.value || '');

      ta.value = val.slice(0, start) + text + val.slice(end);

      var caret = start + text.length;
      ta.focus();
      try { ta.setSelectionRange(caret, caret); } catch (e0) {}
      try { ta.dispatchEvent(new Event('input', { bubbles: true })); } catch (e1) {}
      return true;
    } catch (e) {
      return false;
    }
  }

  function insertTextToEditor(editor, bb) {
    bb = asText(bb);
    if (!bb) return false;

    try {
      if (editor && typeof editor.insertText === 'function') {
        editor.insertText(bb, '');
        if (typeof editor.focus === 'function') editor.focus();
        return true;
      }
    } catch (e0) {}

    var ta = getTextareaFromCtx({ sceditor: editor });
    return insertAtCursor(ta, bb);
  }

  // ===== title helpers =====
  function normalizeTitle(raw) {
    raw = asText(raw).replace(/\r?\n/g, ' ').trim();
    if (!raw) raw = 'Спойлер';

    // чтобы не ломать [spoiler="..."] кавычками
    raw = raw.replace(/"/g, '“').replace(/'/g, '’');
    return raw;
  }

  function buildSpoilerOpenTag(title) {
    title = normalizeTitle(title);
    return '[spoiler="' + title + '"]';
  }

  function wrapInInput(el, open, close) {
    try {
      el.focus();
      var s = el.selectionStart || 0;
      var e = el.selectionEnd || 0;
      var v = asText(el.value);
      var sel = v.substring(s, e);
      el.value = v.substring(0, s) + open + sel + close + v.substring(e);
      var p = s + open.length;
      el.setSelectionRange(p, p + sel.length);
    } catch (e) {}
  }

  function getFontFamilies() {
    try {
      var P = window.afAePayload || window.afAdvancedEditorPayload || window.afAdvancedEditorPayload || {};
      var cfg = (P && P.cfg) ? P.cfg : {};
      var list = cfg && cfg.fontFamilies;
      if (Array.isArray(list) && list.length) return list;
    } catch (e) {}
    return [];
  }


  // ===== dropdown UI =====
  function makeDropdown(editor, caller) {
    var wrap = document.createElement('div');
    wrap.className = 'af-spoiler-dd';

    wrap.innerHTML =
      '<div class="af-spoiler-dd-hd">' +
      '  <div class="af-spoiler-dd-title">Спойлер</div>' +
      '</div>' +
      '<div class="af-spoiler-dd-body">' +
      '  <label class="af-spoiler-dd-row">' +
      '    <span>Заголовок (поддерживает BBCode)</span>' +
      '    <input type="text" class="af-spoiler-dd-input" placeholder=\'например: [align=center][b]Заголовок[/b][/align]\'>' +
      '  </label>' +
      '  <div class="af-spoiler-dd-hint">Вставится как <code>[spoiler=&quot;...&quot;]</code>. Кавычки в заголовке будут заменены на типографские.</div>' +
      '  <div class="af-spoiler-dd-actions">' +
      '    <button type="button" class="button af-spoiler-dd-insert">Вставить</button>' +
      '  </div>' +
      '</div>';

    var input = wrap.querySelector('.af-spoiler-dd-input');
    var btnInsert = wrap.querySelector('.af-spoiler-dd-insert');

    function closeDd() {
      try { editor.closeDropDown(true); } catch (e0) {}
    }

    function insertNow() {
      var title = normalizeTitle(input ? input.value : '');
      var bb = buildSpoilerOpenTag(title) + '\n\n[/spoiler]';
      insertTextToEditor(editor, bb);
      closeDd();
    }

    // Enter = вставить
    if (input && !input._afSpoilerBound) {
      input._afSpoilerBound = true;
      input.addEventListener('keydown', function (ev) {
        if (!ev) return;
        if (ev.key === 'Enter') {
          ev.preventDefault();
          insertNow();
        }
      }, false);
    }

    if (btnInsert && !btnInsert._afBound) {
      btnInsert._afBound = true;
      btnInsert.addEventListener('click', function (ev) {
        ev.preventDefault();
        insertNow();
      }, false);
    }

    // autofocus
    try { if (input) input.focus(); } catch (e2) {}

    return wrap;
  }


  function openSceditorDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;
    try { editor.closeDropDown(true); } catch (e0) {}

    var wrap = makeDropdown(editor, caller);
    editor.createDropDown(caller, 'sceditor-spoiler-picker', wrap);
    return true;
  }

  // ===== SCEditor command =====
  function patchSceditorSpoilerCommand() {
    if (!window.jQuery) return false;
    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.command) return false;

    $.sceditor.command.set(CMD, {
      exec: function (caller) {
        if (!openSceditorDropdown(this, caller)) {
          insertTextToEditor(this, '[spoiler="Спойлер"]\n\n[/spoiler]');
        }
      },
      txtExec: function (caller) {
        if (!openSceditorDropdown(this, caller)) {
          insertTextToEditor(this, '[spoiler="Спойлер"]\n\n[/spoiler]');
        }
      },
      tooltip: 'Спойлер'
    });

    // на всякий — если где-то layout зовёт spoiler вместо af_spoiler
    $.sceditor.command.set('spoiler', {
      exec: function (caller) {
        if (!openSceditorDropdown(this, caller)) {
          insertTextToEditor(this, '[spoiler="Спойлер"]\n\n[/spoiler]');
        }
      },
      txtExec: function (caller) {
        if (!openSceditorDropdown(this, caller)) {
          insertTextToEditor(this, '[spoiler="Спойлер"]\n\n[/spoiler]');
        }
      },
      tooltip: 'Спойлер'
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

  function registerWysiwyg() { patchSceditorSpoilerCommand(); }
  registerWysiwyg();
  document.addEventListener('af:capability-ready', function(e) { if (e.detail.capability === 'wysiwyg') registerWysiwyg(); });


  // ===== lazy load activation (остаётся как было) =====
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

    // fallback
    insertTextToEditor(editor, '[spoiler="Спойлер"]\n\n[/spoiler]');
  }

  // handlers for AQR/AE core
  var handlerObj = {
    id: ID,
    title: 'Спойлер',
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
    else insertTextToEditor(editor, '[spoiler="Спойлер"]\n\n[/spoiler]');
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

})();

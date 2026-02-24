(function () {
  'use strict';

  if (!window.afAeBuiltinHandlers)  window.afAeBuiltinHandlers  = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  if (window.afAeHtmlbbInitialized) return;
  window.afAeHtmlbbInitialized = true;
  if (!window.AFAE || typeof window.AFAE.hasEditor !== 'function' || !window.AFAE.hasEditor()) return;

  var ID  = 'htmlbb';
  var CMD = 'af_htmlbb';

  function asText(x) { return String(x == null ? '' : x); }

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

  function insertHtml(editor, value) {
    value = asText(value).trim();
    if (!value) return;

    var bb = '[html]' + value + '[/html]';

    try {
      if (editor && typeof editor.insertText === 'function') {
        editor.insertText(bb);
        if (typeof editor.focus === 'function') editor.focus();
        return;
      }
    } catch (e0) {}
  }

  function openDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;

    try { editor.closeDropDown(true); } catch (e0) {}

    var wrap = document.createElement('div');
    wrap.className = 'af-htmlbb-dd';

    var title = document.createElement('div');
    title.className = 'af-htmlbb-title';
    title.textContent = 'HTML-блок';
    wrap.appendChild(title);

    var hint = document.createElement('div');
    hint.className = 'af-htmlbb-hint';
    hint.textContent = 'Сюда можно вставлять embed-код (например iframe). Скрипты (JS) для обычных пользователей будут вырезаны.';
    wrap.appendChild(hint);

    var ta = document.createElement('textarea');
    ta.className = 'af-htmlbb-input';
    ta.rows = 6;
    ta.placeholder = 'Вставь HTML/iframe/CSS…';
    wrap.appendChild(ta);

    var actions = document.createElement('div');
    actions.className = 'af-htmlbb-actions';

    var ins = document.createElement('button');
    ins.type = 'button';
    ins.className = 'af-htmlbb-insert';
    ins.textContent = 'Вставить';

    ins.addEventListener('click', function (ev) {
      ev.preventDefault();
      insertHtml(editor, ta.value);
      try { editor.closeDropDown(true); } catch (e0) {}
    });

    ta.addEventListener('keydown', function (ev) {
      var k = ev && (ev.key || ev.keyCode);
      if ((ev.ctrlKey || ev.metaKey) && (k === 'Enter' || k === 13)) {
        ev.preventDefault();
        insertHtml(editor, ta.value);
        try { editor.closeDropDown(true); } catch (e0) {}
      }
    });

    actions.appendChild(ins);
    wrap.appendChild(actions);

    editor.createDropDown(caller, 'sceditor-htmlbb', wrap);

    setTimeout(function () { try { ta.focus(); } catch (e) {} }, 0);
    return true;
  }

  function patchCommand() {
    if (!window.jQuery) return false;
    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.command) return false;

    $.sceditor.command.set(CMD, {
      exec: function (caller) {
        openDropdown(this, caller);
      },
      txtExec: function (caller) {
        openDropdown(this, caller);
      },
      tooltip: 'HTML-блок'
    });

    return true;
  }

  (function waitAnd(fn, maxTries) {
    var tries = 0;
    (function tick() {
      tries++;
      if (fn()) return;
      if (tries > (maxTries || 150)) return;
      setTimeout(tick, 100);
    })();
  })(patchCommand, 150);

  // handlers for AE/AQR
  function clickHandler(ctx, ev) {
    var editor = getSceditorInstanceFromCtx(ctx);
    var caller =
      (ctx && (ctx.buttonEl || ctx.btn || ctx.caller)) ||
      (ev && (ev.currentTarget || ev.target)) ||
      null;

    if (editor && caller && caller.nodeType === 1) {
      if (ev && ev.preventDefault) ev.preventDefault();
      openDropdown(editor, caller);
    }
  }

  window.afAqrBuiltinHandlers[ID]  = { id: ID, title: 'HTML-блок', onClick: clickHandler, click: clickHandler, init: function(){} };
  window.afAqrBuiltinHandlers[CMD] = window.afAqrBuiltinHandlers[ID];

  window.afAeBuiltinHandlers[ID]  = function (inst, caller) {
    var editor = getSceditorInstanceFromCtx(inst || {});
    if (!editor) return;
    if (caller && caller.nodeType === 1) openDropdown(editor, caller);
  };
  window.afAeBuiltinHandlers[CMD] = window.afAeBuiltinHandlers[ID];

  // ===== Frontend render placeholders =====
  function decodeB64(s) {
    s = asText(s);
    try { return decodeURIComponent(escape(window.atob(s))); } catch (e) {}
    try { return window.atob(s); } catch (e2) {}
    return '';
  }

  function renderOne(el) {
    if (!el || el.__afHtmlbbRendered) return;
    el.__afHtmlbbRendered = true;

    var b64 = el.getAttribute('data-af-html-b64') || '';
    var html = decodeB64(b64);

    if (!html) return;

    // вставляем HTML внутрь контейнера
    el.innerHTML = '';
    var box = document.createElement('div');
    box.className = 'af-htmlbb-box';
    box.innerHTML = html;
    el.appendChild(box);
  }

  function renderAll(root) {
    root = root || document;
    var list = root.querySelectorAll ? root.querySelectorAll('.af-htmlbb[data-af-html-b64]') : [];
    for (var i = 0; i < list.length; i++) renderOne(list[i]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { renderAll(document); });
  } else {
    renderAll(document);
  }

  if (window.MutationObserver) {
    try {
      var mo = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
          var m = mutations[i];
          if (!m || !m.addedNodes) continue;

          for (var j = 0; j < m.addedNodes.length; j++) {
            var n = m.addedNodes[j];
            if (n && n.nodeType === 1) {
              if (n.matches && n.matches('.af-htmlbb[data-af-html-b64]')) renderOne(n);
              else renderAll(n);
            }
          }
        }
      });
      mo.observe(document.body, { childList: true, subtree: true });
    } catch (e) {}
  }

})();

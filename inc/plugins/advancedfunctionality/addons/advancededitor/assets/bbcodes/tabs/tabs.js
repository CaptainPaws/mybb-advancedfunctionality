(function (window, document) {
  'use strict';

  if (window.__afAeTabsPackLoaded) return;
  window.__afAeTabsPackLoaded = true;

  if (!window.afAeBuiltinHandlers) {
    window.afAeBuiltinHandlers = Object.create(null);
  }

  var CMD = 'af_tabs';
  var POSITIONS = ['top', 'bottom', 'left', 'right'];
  var DROPDOWN_ID = 'sceditor-sceditor-af_tabs-picker';

  function asText(x) {
    return String(x == null ? '' : x);
  }

  function sanitizePosition(position) {
    position = asText(position).toLowerCase().trim();
    return POSITIONS.indexOf(position) !== -1 ? position : 'top';
  }

  function resolveEditor(ctx) {
    if (ctx && typeof ctx.insertText === 'function') return ctx;
    if (ctx && ctx.sceditor && typeof ctx.sceditor.insertText === 'function') return ctx.sceditor;
    if (ctx && ctx.inst && typeof ctx.inst.insertText === 'function') return ctx.inst;
    if (ctx && ctx.instance && typeof ctx.instance.insertText === 'function') return ctx.instance;
    return null;
  }

  function resolveCaller(primary, secondary) {
    var candidate = secondary || primary;

    if (candidate && candidate.nodeType === 1) return candidate;
    if (candidate && candidate.jquery && candidate[0] && candidate[0].nodeType === 1) return candidate[0];
    if (candidate && candidate.currentTarget && candidate.currentTarget.nodeType === 1) return candidate.currentTarget;
    if (candidate && candidate.target && candidate.target.nodeType === 1) return candidate.target;

    return null;
  }

  function buildTemplate(position) {
    position = sanitizePosition(position);
    return '[tabs position="' + position + '"]\n'
      + '[tab title="Вкладка 1"]\n'
      + 'Контент вкладки 1\n'
      + '[/tab]\n'
      + '[tab title="Вкладка 2"]\n'
      + 'Контент вкладки 2\n'
      + '[/tab]\n'
      + '[/tabs]';
  }

  function insertTemplate(inst, position) {
    var template = buildTemplate(position);

    try {
      if (inst && typeof inst.insertText === 'function') {
        inst.insertText(template, '');
        return true;
      }
    } catch (e0) {}

    try {
      if (inst && typeof inst.insert === 'function') {
        inst.insert(template, '');
        return true;
      }
    } catch (e1) {}

    try {
      if (inst && typeof inst.val === 'function') {
        inst.val(asText(inst.val()) + template);
        return true;
      }
    } catch (e2) {}

    var ta = document.querySelector('textarea#message') || document.querySelector('textarea[name="message"]');
    if (!ta) return false;

    try {
      var start = (typeof ta.selectionStart === 'number') ? ta.selectionStart : ta.value.length;
      var end = (typeof ta.selectionEnd === 'number') ? ta.selectionEnd : start;
      var val = asText(ta.value);
      ta.value = val.slice(0, start) + template + val.slice(end);
      var pos = start + template.length;
      ta.focus();
      ta.setSelectionRange(pos, pos);
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      return true;
    } catch (e3) {
      return false;
    }
  }

  function makeDirectionDropdown(editor) {
    var wrap = document.createElement('div');
    wrap.className = 'af-ae-tabs-picker';
    wrap.innerHTML = ''
      + '<div class="af-ae-tabs-picker__title">Положение табов</div>'
      + '<div class="af-ae-tabs-picker__grid">'
      + '  <button type="button" class="button af-ae-tabs-picker__btn" data-pos="top">top</button>'
      + '  <button type="button" class="button af-ae-tabs-picker__btn" data-pos="bottom">bottom</button>'
      + '  <button type="button" class="button af-ae-tabs-picker__btn" data-pos="left">left</button>'
      + '  <button type="button" class="button af-ae-tabs-picker__btn" data-pos="right">right</button>'
      + '</div>';

    wrap.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || !target.closest) return;

      var btn = target.closest('[data-pos]');
      if (!btn) return;

      event.preventDefault();
      insertTemplate(editor, sanitizePosition(btn.getAttribute('data-pos')));

      try {
        if (editor && typeof editor.closeDropDown === 'function') {
          editor.closeDropDown(true);
        }
      } catch (e0) {}
    }, false);

    return wrap;
  }

  function openDirectionDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;

    var anchor = resolveCaller(caller, null);
    if (!anchor || typeof anchor.getBoundingClientRect !== 'function') return false;

    try {
      if (anchor.closest) {
        var button = anchor.closest('a.sceditor-button');
        if (button) anchor = button;
      }
    } catch (e0) {}

    try { editor.closeDropDown(true); } catch (e1) {}
    editor.createDropDown(anchor, DROPDOWN_ID, makeDirectionDropdown(editor));
    return true;
  }

  window.af_ae_tabs_exec = function (ctx, maybeDefOrCaller, maybeCaller) {
    var editor = resolveEditor(ctx) || ctx;
    var caller = resolveCaller(maybeDefOrCaller, maybeCaller);

    if (openDirectionDropdown(editor, caller)) {
      return;
    }

    insertTemplate(editor, 'top');
  };

  window.afAeBuiltinHandlers.tabs = window.af_ae_tabs_exec;
  window.afAeBuiltinHandlers[CMD] = window.af_ae_tabs_exec;

})(window, document);

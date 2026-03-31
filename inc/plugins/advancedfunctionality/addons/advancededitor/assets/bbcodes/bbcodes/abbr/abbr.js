(function () {
  'use strict';

  if (window.__afAeAbbrPackLoaded) return;
  window.__afAeAbbrPackLoaded = true;

  var CMD = 'af_abbr';

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  function asText(v) { return String(v == null ? '' : v); }

  function escHtml(s) {
    return asText(s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function escBbAttr(s) {
    return asText(s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/\]/g, '&#93;')
      .trim();
  }

  function getBb() {
    try {
      var bb = window.jQuery && jQuery.sceditor && jQuery.sceditor.plugins && jQuery.sceditor.plugins.bbcode
        ? jQuery.sceditor.plugins.bbcode.bbcode
        : null;
      if (bb && typeof bb.set === 'function') return bb;
    } catch (e0) {}
    return null;
  }

  function buildOpenTag(tooltip) {
    tooltip = escBbAttr(tooltip);
    return '[abbr="' + tooltip + '"]';
  }

  function getSourceTextArea(editor) {
    var c, ta;
    try { c = editor && typeof editor.getContainer === 'function' ? editor.getContainer() : null; } catch (e0) { c = null; }
    if (c && c.querySelector) {
      ta = c.querySelector('textarea.sceditor-textarea') || c.querySelector('textarea');
      if (ta) return ta;
    }
    return null;
  }

  function resolveEditor(ctx) {
    if (!ctx) return null;
    if (typeof ctx.createDropDown === 'function') return ctx;
    if (ctx.editor && typeof ctx.editor.createDropDown === 'function') return ctx.editor;

    try {
      if (window.jQuery && ctx.textarea) {
        var inst = window.jQuery(ctx.textarea).sceditor('instance');
        if (inst) return inst;
      }
    } catch (e0) {}

    return null;
  }

  function resolveCaller(ctx, maybeCaller) {
    if (maybeCaller && maybeCaller.nodeType === 1) return maybeCaller;
    if (ctx && ctx.caller && ctx.caller.nodeType === 1) return ctx.caller;
    return null;
  }

  function isSourceMode(editor) {
    try {
      if (editor && typeof editor.sourceMode === 'function') return !!editor.sourceMode();
    } catch (e0) {}
    return false;
  }

  function hasSelection(editor) {
    if (!editor) return false;

    if (isSourceMode(editor)) {
      var ta = getSourceTextArea(editor);
      if (!ta) return false;
      try { return (ta.selectionEnd - ta.selectionStart) > 0; } catch (e1) { return false; }
    }

    try {
      var helper = editor.getRangeHelper && editor.getRangeHelper();
      var r = helper && helper.selectedRange ? helper.selectedRange() : null;
      return !!(r && asText(r.toString()).length);
    } catch (e2) {}

    return false;
  }

  function insertBb(editor, tooltip, text, selectedMode) {
    var open = buildOpenTag(tooltip);
    var close = '[/abbr]';

    if (selectedMode) {
      try {
        if (editor && typeof editor.insertText === 'function') {
          editor.insertText(open, close);
          return;
        }
      } catch (e0) {}

      try {
        if (editor && typeof editor.insert === 'function') {
          editor.insert(open, close);
          return;
        }
      } catch (e1) {}
    }

    var body = open + asText(text) + close;

    try {
      if (editor && typeof editor.insertText === 'function') {
        editor.insertText(body, '');
        return;
      }
    } catch (e2) {}

    try {
      if (editor && typeof editor.insert === 'function') {
        editor.insert(body, '');
      }
    } catch (e3) {}
  }

  function createDropdown(editor, caller) {
    var selectedMode = hasSelection(editor);

    var root = document.createElement('div');
    root.className = 'af-ae-abbr-dd';
    root.innerHTML =
      '<div class="af-ae-abbr-dd__title">Поясняющий текст</div>' +
      (selectedMode ? '<div class="af-ae-abbr-dd__selected">Будет использован выделенный текст.</div>' : '') +
      (!selectedMode ?
        '<label class="af-ae-abbr-dd__field"><span>Текст</span><input type="text" data-role="text" maxlength="255"></label>' :
        '') +
      '<label class="af-ae-abbr-dd__field"><span>Подсказка</span><input type="text" data-role="tooltip" maxlength="255"></label>' +
      '<div class="af-ae-abbr-dd__actions">' +
      '  <button type="button" class="button" data-role="cancel">Отмена</button>' +
      '  <button type="button" class="button" data-role="apply">Применить</button>' +
      '</div>';

    var textInput = root.querySelector('[data-role="text"]');
    var tooltipInput = root.querySelector('[data-role="tooltip"]');
    var cancelBtn = root.querySelector('[data-role="cancel"]');
    var applyBtn = root.querySelector('[data-role="apply"]');

    function closeDd() {
      try { if (editor && typeof editor.closeDropDown === 'function') editor.closeDropDown(true); } catch (e0) {}
    }

    function applyNow() {
      var tooltip = asText(tooltipInput && tooltipInput.value).trim();
      var text = asText(textInput && textInput.value).trim();

      if (!tooltip) {
        if (tooltipInput && typeof tooltipInput.focus === 'function') tooltipInput.focus();
        return;
      }

      if (!selectedMode && !text) {
        if (textInput && typeof textInput.focus === 'function') textInput.focus();
        return;
      }

      insertBb(editor, tooltip, text, selectedMode);
      closeDd();
    }

    if (cancelBtn) cancelBtn.addEventListener('click', function (e) { e.preventDefault(); closeDd(); });
    if (applyBtn) applyBtn.addEventListener('click', function (e) { e.preventDefault(); applyNow(); });

    root.addEventListener('keydown', function (e) {
      if (!e) return;
      if (e.key === 'Enter') {
        e.preventDefault();
        applyNow();
      }
    });

    setTimeout(function () {
      try {
        if (!selectedMode && textInput) textInput.focus();
        else if (tooltipInput) tooltipInput.focus();
      } catch (e) {}
    }, 0);

    return root;
  }

  function openDialog(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;
    var node = createDropdown(editor, caller || null);
    try { editor.closeDropDown(true); } catch (e0) {}
    editor.createDropDown(caller || null, 'sceditor-abbr-picker', node);
    return true;
  }

  function ensureBbcodeDef() {
    var bb = getBb();
    if (!bb) return;

    try {
      bb.set('abbr', {
        isInline: true,
        html: function (token, attrs, content) {
          var tip = asText(attrs && attrs.defaultattr).trim();
          return '<span class="af-ae-abbr" data-af-bb="abbr" data-af-abbr-title="' + escHtml(tip) + '" title="' + escHtml(tip) + '">' + (content || '') + '</span>';
        },
        format: function (el, content) {
          var tip = '';
          try { tip = asText(el && el.getAttribute && el.getAttribute('data-af-abbr-title')); } catch (e0) { tip = ''; }
          if (!tip) {
            try { tip = asText(el && el.getAttribute && el.getAttribute('title')); } catch (e1) { tip = ''; }
          }
          return buildOpenTag(tip) + (content || '') + '[/abbr]';
        }
      });
    } catch (e2) {}
  }

  function execute(editor, def, caller) {
    editor = resolveEditor(editor) || editor;
    ensureBbcodeDef();

    if (editor && typeof editor.createDropDown === 'function') {
      if (openDialog(editor, caller || null)) return;
    }

    var tooltip = window.prompt('Введите текст подсказки', '');
    if (tooltip == null || !asText(tooltip).trim()) return;

    if (hasSelection(editor)) {
      insertBb(editor, tooltip, '', true);
      return;
    }

    var text = window.prompt('Введите отображаемый текст', '');
    if (text == null || !asText(text).trim()) return;

    insertBb(editor, tooltip, text, false);
  }

  function registerCommand() {
    try {
      if (!window.jQuery || !jQuery.sceditor || !jQuery.sceditor.command) return;
      var impl = {
        tooltip: 'Поясняющий текст',
        exec: function (caller) { execute(this, null, caller); },
        txtExec: function (caller) { execute(this, null, caller); }
      };
      jQuery.sceditor.command.set(CMD, impl);
      jQuery.sceditor.command.set('abbr', impl);
    } catch (e0) {}
  }

  window.af_ae_abbr_exec = function (editor, def, caller) {
    execute(resolveEditor(editor) || editor, def, resolveCaller(editor, caller));
  };

  window.afAeBuiltinHandlers.abbr = execute;
  window.afAeBuiltinHandlers.af_abbr = execute;
  window.afAqrBuiltinHandlers.abbr = execute;
  window.afAqrBuiltinHandlers.af_abbr = execute;

  ensureBbcodeDef();
  registerCommand();

  for (var i = 1; i <= 12; i++) {
    setTimeout(function () {
      ensureBbcodeDef();
      registerCommand();
    }, i * 250);
  }
})();

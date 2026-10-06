(function (window, document) {
  'use strict';

  if (window.__afAeAccordionPackLoaded) return;
  window.__afAeAccordionPackLoaded = true;

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  var ID = 'accordion';
  var CMD = 'af_accordion';

  function asText(value) {
    return String(value == null ? '' : value);
  }

  function buildTemplate() {
    return '[accordion]\n'
      + '[accitem title="Заголовок 1"]\n'
      + 'Контент 1\n'
      + '[/accitem]\n'
      + '[accitem title="Заголовок 2"]\n'
      + 'Контент 2\n'
      + '[/accitem]\n'
      + '[/accordion]';
  }

  function getEditorFromCtx(ctx) {
    if (!ctx) return null;
    if (ctx.editor && typeof ctx.editor.createDropDown === 'function') return ctx.editor;
    if (typeof ctx.createDropDown === 'function') return ctx;
    return null;
  }

  function getTextareaFromEditor(editor) {
    try {
      if (editor && editor.sourceEditor && editor.sourceEditor.nodeType === 1) return editor.sourceEditor;
    } catch (e0) {}

    try {
      var container = editor && typeof editor.getContainer === 'function' ? editor.getContainer() : null;
      if (container && container.querySelector) {
        return container.querySelector('textarea.sceditor-textarea') || container.querySelector('textarea');
      }
    } catch (e1) {}

    return document.querySelector('textarea#message') || document.querySelector('textarea[name="message"]');
  }

  function insertIntoTextarea(textarea, text) {
    if (!textarea) return false;

    var value = asText(textarea.value);
    var start = textarea.selectionStart || 0;
    var end = textarea.selectionEnd || 0;

    textarea.value = value.slice(0, start) + text + value.slice(end);

    var caret = start + text.length;

    try {
      textarea.focus();
      textarea.selectionStart = caret;
      textarea.selectionEnd = caret;
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
    } catch (e) {}

    return true;
  }

  function insertTemplate(editor, template) {
    try {
      if (editor && typeof editor.insertText === 'function') {
        editor.insertText(template, '');
        try { if (typeof editor.updateOriginal === 'function') editor.updateOriginal(); } catch (e0) {}
        try { if (typeof editor.focus === 'function') editor.focus(); } catch (e1) {}
        return true;
      }
    } catch (e2) {}

    try {
      if (editor && typeof editor.insert === 'function') {
        editor.insert(template, '');
        try { if (typeof editor.updateOriginal === 'function') editor.updateOriginal(); } catch (e3) {}
        try { if (typeof editor.focus === 'function') editor.focus(); } catch (e4) {}
        return true;
      }
    } catch (e5) {}

    return insertIntoTextarea(getTextareaFromEditor(editor), template);
  }

  function execute(editor) {
    insertTemplate(editor, buildTemplate());
  }

  function aqrOpen(ctx) {
    var editor = getEditorFromCtx(ctx) || getEditorFromCtx({ editor: window.sceditor && window.sceditor.instance && window.sceditor.instance(document.getElementById('message')) });
    if (!editor) return;
    execute(editor);
  }

  var aqrHandler = {
    id: ID,
    title: 'Аккордеон',
    onClick: aqrOpen,
    click: aqrOpen,
    action: aqrOpen,
    run: aqrOpen,
    init: function () {}
  };

  function registerHandlers() {
    window.af_ae_accordion_exec = function (editor) {
      execute(editor);
    };

    window.afAeBuiltinHandlers[ID] = execute;
    window.afAeBuiltinHandlers[CMD] = execute;

    window.afAqrBuiltinHandlers[ID] = aqrHandler;
    window.afAqrBuiltinHandlers[CMD] = aqrHandler;
  }

  registerHandlers();
  for (var t = 1; t <= 10; t++) window.setTimeout(registerHandlers, t * 200);

})(window, document);

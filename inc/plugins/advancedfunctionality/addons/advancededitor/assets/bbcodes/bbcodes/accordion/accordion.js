(function (window, document) {
  'use strict';

  if (window.__afAeAccordionPackLoaded) return;
  window.__afAeAccordionPackLoaded = true;

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);

  var CMD = 'af_accordion';

  function asText(value) {
    return String(value == null ? '' : value);
  }

  function normalizeDirection(value) {
    value = asText(value).trim().toLowerCase();
    if (value === 'up' || value === 'down' || value === 'left' || value === 'right') {
      return value;
    }
    return 'down';
  }

  function buildTemplate(direction) {
    direction = normalizeDirection(direction);

    return '[accordion direction="' + direction + '"]\n' +
      '[accitem title="Заголовок 1"]\n' +
      'Контент 1\n' +
      '[/accitem]\n' +
      '[accitem title="Заголовок 2"]\n' +
      'Контент 2\n' +
      '[/accitem]\n' +
      '[/accordion]';
  }

  function chooseDirection() {
    var input = 'down';

    try {
      input = window.prompt('Направление аккордеона: up, down, left, right', 'down') || 'down';
    } catch (e) {}

    return normalizeDirection(input);
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
    if (editor && typeof editor.insertText === 'function') {
      editor.insertText(template, '');
      try { if (typeof editor.updateOriginal === 'function') editor.updateOriginal(); } catch (e0) {}
      try { if (typeof editor.focus === 'function') editor.focus(); } catch (e1) {}
      return true;
    }

    return insertIntoTextarea(getTextareaFromEditor(editor), template);
  }

  function togglePanel(toggle) {
    if (!toggle || toggle.nodeType !== 1) return;

    var expanded = toggle.getAttribute('aria-expanded') === 'true';
    var panelId = toggle.getAttribute('aria-controls') || '';
    var panel = panelId ? document.getElementById(panelId) : null;
    if (!panel) return;

    toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    panel.hidden = expanded;

    var item = toggle.closest('.af-accordion-item');
    if (item) {
      if (expanded) item.classList.remove('is-open');
      else item.classList.add('is-open');
    }
  }

  function bindAccordion(root) {
    if (!root || root.nodeType !== 1 || root.getAttribute('data-af-accordion-bound') === '1') {
      return;
    }

    root.setAttribute('data-af-accordion-bound', '1');

    root.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || !target.closest) return;
      var toggle = target.closest('[data-af-acc-toggle="1"]');
      if (!toggle || !root.contains(toggle)) return;

      event.preventDefault();
      togglePanel(toggle);
    }, false);
  }

  function initAccordions() {
    var list = document.querySelectorAll('[data-af-accordion="1"]');
    for (var i = 0; i < list.length; i++) {
      bindAccordion(list[i]);
    }
  }

  window.af_ae_accordion_exec = function (editor) {
    var direction = chooseDirection();
    var template = buildTemplate(direction);
    insertTemplate(editor, template);
  };

  window.afAeBuiltinHandlers[CMD] = window.af_ae_accordion_exec;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAccordions);
  } else {
    initAccordions();
  }

  window.addEventListener('load', initAccordions);
})(window, document);

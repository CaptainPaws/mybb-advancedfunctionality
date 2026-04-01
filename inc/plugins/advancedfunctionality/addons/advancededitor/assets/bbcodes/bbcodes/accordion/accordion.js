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

  function setPanelState(toggle, panel, isOpen) {
    var item;

    if (!toggle || !panel) return;

    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    panel.hidden = false;

    if (isOpen) {
      panel.classList.add('is-open');
      panel.classList.remove('is-closing');
      panel.style.maxHeight = (panel.scrollHeight + 20) + 'px';
    } else {
      panel.classList.remove('is-open');
      panel.classList.add('is-closing');
      panel.style.maxHeight = '0px';
    }

    item = toggle.closest('.af-accordion-item');
    if (item) {
      if (isOpen) item.classList.add('is-open');
      else item.classList.remove('is-open');
    }

    if (!isOpen) {
      window.setTimeout(function () {
        if (toggle.getAttribute('aria-expanded') !== 'true') {
          panel.hidden = true;
        }
      }, 230);
    }
  }

  function togglePanel(toggle) {
    var expanded;
    var panelId;
    var panel;

    if (!toggle || toggle.nodeType !== 1) return;

    expanded = toggle.getAttribute('aria-expanded') === 'true';
    panelId = toggle.getAttribute('aria-controls') || '';
    panel = panelId ? document.getElementById(panelId) : null;
    if (!panel) return;

    setPanelState(toggle, panel, !expanded);
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

  function initAccordions(scope) {
    var root = scope && scope.querySelectorAll ? scope : document;
    var list = root.querySelectorAll('[data-af-accordion="1"]');

    for (var i = 0; i < list.length; i++) {
      bindAccordion(list[i]);
    }
  }

  function bindDynamicInit() {
    if (window.__afAeAccordionDynamicInitBound) return;
    window.__afAeAccordionDynamicInitBound = true;

    if (window.MutationObserver && document.body) {
      var observer = new MutationObserver(function (records) {
        for (var i = 0; i < records.length; i++) {
          var rec = records[i];
          if (!rec || !rec.addedNodes || !rec.addedNodes.length) continue;

          for (var j = 0; j < rec.addedNodes.length; j++) {
            var node = rec.addedNodes[j];
            if (!node || node.nodeType !== 1) continue;

            if (node.matches && node.matches('[data-af-accordion="1"]')) {
              bindAccordion(node);
            }

            if (node.querySelectorAll) {
              initAccordions(node);
            }
          }
        }
      });

      observer.observe(document.body, { childList: true, subtree: true });
    }

    document.addEventListener('af:preview-updated', function (event) {
      initAccordions(event && event.detail && event.detail.root ? event.detail.root : document);
    });
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

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initAccordions(document);
      bindDynamicInit();
    });
  } else {
    initAccordions(document);
    bindDynamicInit();
  }

  window.addEventListener('load', function () {
    initAccordions(document);
  });
})(window, document);

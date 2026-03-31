(function (window, document) {
  'use strict';

  if (window.__afAeAccordionPackLoaded) return;
  window.__afAeAccordionPackLoaded = true;

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  var ID = 'accordion';
  var CMD = 'af_accordion';
  var DROPDOWN_ID = 'sceditor-sceditor-af_accordion-picker';

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

    return '[accordion direction="' + direction + '"]\n'
      + '[accitem title="Заголовок 1"]\n'
      + 'Контент 1\n'
      + '[/accitem]\n'
      + '[accitem title="Заголовок 2"]\n'
      + 'Контент 2\n'
      + '[/accitem]\n'
      + '[/accordion]';
  }

  function resolveCaller(caller) {
    if (caller && caller.nodeType === 1) return caller;
    if (caller && caller.jquery && caller[0] && caller[0].nodeType === 1) return caller[0];
    if (caller && caller.currentTarget && caller.currentTarget.nodeType === 1) return caller.currentTarget;
    if (caller && caller.target && caller.target.nodeType === 1) return caller.target;
    return null;
  }

  function getEditorFromCtx(ctx) {
    if (!ctx) return null;
    if (ctx.editor && typeof ctx.editor.createDropDown === 'function') return ctx.editor;
    if (typeof ctx.createDropDown === 'function') return ctx;
    return null;
  }

  function findCallerForEditor(editor) {
    var cont;
    var selectors = [
      'a.sceditor-button-' + CMD,
      'a.sceditor-button-' + ID,
      'a.sceditor-button-af_menu_dropdown1',
      '.sceditor-button.active'
    ];

    if (!editor) return null;

    try {
      cont = (typeof editor.getContainer === 'function') ? editor.getContainer() : null;
    } catch (e0) {
      cont = null;
    }

    if (cont && cont.querySelector) {
      for (var i = 0; i < selectors.length; i++) {
        var found = cont.querySelector(selectors[i]);
        if (found) return found;
      }
      try {
        var fromTb = cont.querySelector('.sceditor-toolbar a.sceditor-button');
        if (fromTb) return fromTb;
      } catch (e1) {}
    }

    for (var j = 0; j < selectors.length; j++) {
      var any = document.querySelector(selectors[j]);
      if (any) return any;
    }

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

  function getDialogRoot() {
    return document.getElementById('af-accordion-direction-dialog');
  }

  function closeDirectionDialog() {
    var root = getDialogRoot();
    if (!root) return;
    root.classList.remove('is-open');
    root.setAttribute('aria-hidden', 'true');
  }

  function ensureDirectionDialog(onPick) {
    var root = getDialogRoot();
    if (root) return root;

    root = document.createElement('div');
    root.id = 'af-accordion-direction-dialog';
    root.className = 'af-accordion-modal';
    root.setAttribute('aria-hidden', 'true');
    root.innerHTML = ''
      + '<div class="af-accordion-modal__overlay" data-af-acc-close="1"></div>'
      + '<div class="af-accordion-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="af-accordion-modal-title">'
      + '  <button type="button" class="af-accordion-modal__close" data-af-acc-close="1" aria-label="Close">×</button>'
      + '  <div class="af-accordion-modal__title" id="af-accordion-modal-title">Направление аккордеона</div>'
      + '  <div class="af-accordion-dd-grid">'
      + '    <button type="button" class="button af-accordion-dd-btn" data-direction="down">down</button>'
      + '    <button type="button" class="button af-accordion-dd-btn" data-direction="up">up</button>'
      + '    <button type="button" class="button af-accordion-dd-btn" data-direction="left">left</button>'
      + '    <button type="button" class="button af-accordion-dd-btn" data-direction="right">right</button>'
      + '  </div>'
      + '</div>';

    root.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || !target.closest) return;

      if (target.closest('[data-af-acc-close="1"]')) {
        closeDirectionDialog();
        return;
      }

      var btn = target.closest('[data-direction]');
      if (!btn) return;

      event.preventDefault();
      closeDirectionDialog();
      onPick(normalizeDirection(btn.getAttribute('data-direction')));
    }, false);

    document.body.appendChild(root);
    return root;
  }

  function openDirectionDialog(onPick) {
    var root = ensureDirectionDialog(onPick);
    if (!root) return false;

    root.classList.add('is-open');
    root.setAttribute('aria-hidden', 'false');
    return true;
  }

  function makeDirectionDropdown(editor) {
    var wrap = document.createElement('div');
    wrap.className = 'af-accordion-dd';
    wrap.innerHTML = ''
      + '<div class="af-accordion-dd-title">Направление аккордеона</div>'
      + '<div class="af-accordion-dd-grid">'
      + '  <button type="button" class="button af-accordion-dd-btn" data-direction="down">down</button>'
      + '  <button type="button" class="button af-accordion-dd-btn" data-direction="up">up</button>'
      + '  <button type="button" class="button af-accordion-dd-btn" data-direction="left">left</button>'
      + '  <button type="button" class="button af-accordion-dd-btn" data-direction="right">right</button>'
      + '</div>';

    wrap.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || !target.closest) return;
      var btn = target.closest('[data-direction]');
      if (!btn) return;

      event.preventDefault();
      var direction = normalizeDirection(btn.getAttribute('data-direction'));
      insertTemplate(editor, buildTemplate(direction));
      try { if (editor && typeof editor.closeDropDown === 'function') editor.closeDropDown(true); } catch (e0) {}
    }, false);

    return wrap;
  }

  function openDirectionDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;

    var anchor = resolveCaller(caller) || findCallerForEditor(editor);
    if (!anchor) return false;

    try { if (typeof editor.closeDropDown === 'function') editor.closeDropDown(true); } catch (e0) {}
    editor.createDropDown(anchor, DROPDOWN_ID, makeDirectionDropdown(editor));
    return true;
  }

  function setPanelState(toggle, panel, isOpen, direction) {
    var item;
    if (!toggle || !panel) return;

    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    panel.hidden = false;

    if (isOpen) {
      panel.classList.add('is-open');
      panel.classList.remove('is-closing');
    } else {
      panel.classList.remove('is-open');
      panel.classList.add('is-closing');
    }

    item = toggle.closest('.af-accordion-item');
    if (item) {
      if (isOpen) item.classList.add('is-open');
      else item.classList.remove('is-open');
    }

    if (direction === 'left' || direction === 'right') {
      panel.style.maxHeight = 'none';
      panel.style.maxWidth = isOpen ? (panel.scrollWidth + 20) + 'px' : '0px';
    } else {
      panel.style.maxWidth = 'none';
      panel.style.maxHeight = isOpen ? (panel.scrollHeight + 20) + 'px' : '0px';
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
    var root;
    var direction;

    if (!toggle || toggle.nodeType !== 1) return;

    expanded = toggle.getAttribute('aria-expanded') === 'true';
    panelId = toggle.getAttribute('aria-controls') || '';
    panel = panelId ? document.getElementById(panelId) : null;
    if (!panel) return;

    root = toggle.closest('[data-af-accordion="1"]');
    direction = normalizeDirection(root ? root.getAttribute('data-direction') : 'down');

    setPanelState(toggle, panel, !expanded, direction);
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

  function execute(editor, caller) {
    if (openDirectionDropdown(editor, caller)) return;

    openDirectionDialog(function (direction) {
      insertTemplate(editor, buildTemplate(direction));
    });
  }

  function aqrOpen(ctx, ev) {
    var editor = getEditorFromCtx(ctx) || getEditorFromCtx({ editor: window.sceditor && window.sceditor.instance && window.sceditor.instance(document.getElementById('message')) });
    var caller =
      (ctx && (ctx.buttonEl || ctx.btn || ctx.caller)) ||
      (ev && (ev.currentTarget || ev.target)) ||
      null;

    if (!editor) return;
    execute(editor, caller);
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
    window.af_ae_accordion_exec = function (editor, _def, caller) {
      execute(editor, caller);
    };

    window.afAeBuiltinHandlers[ID] = execute;
    window.afAeBuiltinHandlers[CMD] = execute;

    window.afAqrBuiltinHandlers[ID] = aqrHandler;
    window.afAqrBuiltinHandlers[CMD] = aqrHandler;
  }

  registerHandlers();
  for (var t = 1; t <= 10; t++) window.setTimeout(registerHandlers, t * 200);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAccordions);
  } else {
    initAccordions();
  }

  window.addEventListener('load', initAccordions);
})(window, document);

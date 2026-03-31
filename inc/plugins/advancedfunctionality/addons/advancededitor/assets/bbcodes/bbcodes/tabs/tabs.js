(function () {
  'use strict';

  if (window.__afAeTabsPackLoaded) return;
  window.__afAeTabsPackLoaded = true;

  if (!window.afAeBuiltinHandlers) {
    window.afAeBuiltinHandlers = Object.create(null);
  }

  function asText(x) {
    return String(x == null ? '' : x);
  }

  function insertTemplate(inst) {
    var template = '[tabs position="top"]\n'
      + '[tab title="Вкладка 1"]\n'
      + 'Контент вкладки 1\n'
      + '[/tab]\n'
      + '[tab title="Вкладка 2"]\n'
      + 'Контент вкладки 2\n'
      + '[/tab]\n'
      + '[/tabs]';

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

  window.af_ae_tabs_exec = function (inst) {
    insertTemplate(inst);
  };

  window.afAeBuiltinHandlers.tabs = window.af_ae_tabs_exec;

  function activateTabs(root, idx) {
    if (!root) return;

    var tabs = root.querySelectorAll('[data-af-tabs-trigger]');
    var panels = root.querySelectorAll('[data-af-tabs-panel]');

    if (!tabs.length || !panels.length) return;

    if (idx < 0 || idx >= tabs.length) idx = 0;

    for (var i = 0; i < tabs.length; i += 1) {
      var active = i === idx;
      tabs[i].setAttribute('aria-selected', active ? 'true' : 'false');
      tabs[i].setAttribute('tabindex', active ? '0' : '-1');
      tabs[i].classList.toggle('is-active', active);
    }

    for (var j = 0; j < panels.length; j += 1) {
      var pActive = j === idx;
      panels[j].hidden = !pActive;
      panels[j].classList.toggle('is-active', pActive);
    }

    root.setAttribute('data-active-index', String(idx));
  }

  function initTabs(root) {
    if (!root || root.getAttribute('data-af-tabs-init') === '1') return;
    root.setAttribute('data-af-tabs-init', '1');

    var first = 0;
    var tabs = root.querySelectorAll('[data-af-tabs-trigger]');

    for (var i = 0; i < tabs.length; i += 1) {
      if (tabs[i].getAttribute('aria-selected') === 'true') {
        first = i;
        break;
      }
    }

    activateTabs(root, first);
  }

  function initAll() {
    var roots = document.querySelectorAll('[data-af-tabs-root]');
    for (var i = 0; i < roots.length; i += 1) {
      initTabs(roots[i]);
    }
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target && ev.target.closest ? ev.target.closest('[data-af-tabs-trigger]') : null;
    if (!btn) return;

    var root = btn.closest('[data-af-tabs-root]');
    if (!root) return;

    var idx = parseInt(btn.getAttribute('data-index') || '0', 10);
    if (isNaN(idx)) idx = 0;

    activateTabs(root, idx);
  }, false);

  document.addEventListener('keydown', function (ev) {
    var btn = ev.target && ev.target.closest ? ev.target.closest('[data-af-tabs-trigger]') : null;
    if (!btn) return;

    var key = ev.key;
    if (key !== 'ArrowLeft' && key !== 'ArrowRight' && key !== 'ArrowUp' && key !== 'ArrowDown' && key !== 'Home' && key !== 'End') {
      return;
    }

    var root = btn.closest('[data-af-tabs-root]');
    if (!root) return;

    var tabs = root.querySelectorAll('[data-af-tabs-trigger]');
    if (!tabs.length) return;

    var current = parseInt(btn.getAttribute('data-index') || '0', 10);
    if (isNaN(current)) current = 0;

    var next = current;

    if (key === 'Home') next = 0;
    else if (key === 'End') next = tabs.length - 1;
    else if (key === 'ArrowLeft' || key === 'ArrowUp') next = (current - 1 + tabs.length) % tabs.length;
    else if (key === 'ArrowRight' || key === 'ArrowDown') next = (current + 1) % tabs.length;

    ev.preventDefault();
    activateTabs(root, next);
    try { tabs[next].focus(); } catch (e) {}
  }, false);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll, false);
  } else {
    initAll();
  }
})();

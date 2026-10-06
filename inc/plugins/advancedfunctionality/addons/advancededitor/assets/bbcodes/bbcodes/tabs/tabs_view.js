(function(window, document) {
  "use strict";
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
    if (key === 'ArrowLeft' || key === 'ArrowUp') next = current - 1;
    if (key === 'ArrowRight' || key === 'ArrowDown') next = current + 1;
    if (key === 'Home') next = 0;
    if (key === 'End') next = tabs.length - 1;

    if (next < 0) next = tabs.length - 1;
    if (next >= tabs.length) next = 0;

    ev.preventDefault();
    activateTabs(root, next);

    try {
      tabs[next].focus();
    } catch (e0) {}
  }, false);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

  window.addEventListener('load', initAll);

})(window, document);

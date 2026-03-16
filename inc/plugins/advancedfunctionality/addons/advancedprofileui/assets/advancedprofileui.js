(function () {
  'use strict';

  function initTabs(root) {
    if (!root || root.dataset.afApuiInit === '1') {
      return;
    }
    root.dataset.afApuiInit = '1';

    var tabs = root.querySelectorAll('[data-tab]');
    var panels = root.querySelectorAll('[data-panel]');
    if (!tabs.length || !panels.length) {
      return;
    }

    function activate(name, pushHash) {
      tabs.forEach(function (tab) {
        var active = tab.getAttribute('data-tab') === name;
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
      });

      panels.forEach(function (panel) {
        var active = panel.getAttribute('data-panel') === name;
        panel.classList.toggle('is-active', active);
        panel.hidden = !active;
      });

      panels.forEach(function (panel) {
        var empty = panel.querySelector('.af-apui-empty');
        if (!empty) {
          return;
        }
        var html = panel.innerHTML.replace(empty.outerHTML, '').trim();
        empty.style.display = html ? 'none' : '';
      });

      if (pushHash && window.history && window.history.replaceState) {
        window.history.replaceState(null, '', '#af-tab-' + name);
      }
    }

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        activate(tab.getAttribute('data-tab'), true);
      });
    });

    var fromHash = (window.location.hash || '').replace('#af-tab-', '');
    var initial = root.querySelector('[data-tab="' + fromHash + '"]') ? fromHash : 'info';
    activate(initial, false);
  }

  function boot() {
    var roots = document.querySelectorAll('[data-af-apui-tabs]');
    if (!roots.length) {
      return;
    }

    roots.forEach(function (root) {
      initTabs(root);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

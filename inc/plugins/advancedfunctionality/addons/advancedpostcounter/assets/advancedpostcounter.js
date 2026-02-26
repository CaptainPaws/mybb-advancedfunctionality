(function () {
  'use strict';

  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
      return;
    }

    fn();
  }

  function setActive(tabId) {
    document.querySelectorAll('.af-apc-tab').forEach(function (a) {
      var active = a.getAttribute('data-apc-tab') === tabId;
      a.classList.toggle('is-active', active);
      a.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    document.querySelectorAll('.af-apc-tabpanel').forEach(function (p) {
      var active = p.getAttribute('data-apc-panel') === tabId;
      p.classList.toggle('is-active', active);
    });
  }

  onReady(function () {
    if (!document.querySelector('.af-apc-tabs')) {
      return;
    }

    var tabs = document.querySelector('.af-apc-tabs');
    tabs.addEventListener('click', function (e) {
      var a = e.target.closest('.af-apc-tab');
      if (!a) return;

      e.preventDefault();

      var tabId = a.getAttribute('data-apc-tab');
      if (!tabId) return;

      setActive(tabId);

      try {
        history.replaceState(null, '', '#' + tabId);
      } catch (err) {
        location.hash = tabId;
      }
    });

    var hash = (location.hash || '').replace('#', '');
    if (hash === 'apc-months' || hash === 'apc-users') {
      setActive(hash);
      return;
    }

    setActive('apc-users');
  });
})();

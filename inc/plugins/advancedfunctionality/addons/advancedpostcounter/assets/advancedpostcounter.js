(function () {
  'use strict';

  function onReady(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
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
    var tabs = document.querySelector('.af-apc-tabs');
    if (!tabs) return;

    tabs.addEventListener('click', function (e) {
      var a = e.target.closest('.af-apc-tab');
      if (!a) return;
      e.preventDefault();

      var tabId = a.getAttribute('data-apc-tab');
      if (!tabId) return;

      setActive(tabId);

      // обновим hash без дерганья страницы
      try {
        history.replaceState(null, '', '#' + tabId);
      } catch (err) {
        location.hash = tabId;
      }
    });

    // стартуем по hash, если он есть
    var hash = (location.hash || '').replace('#', '');
    if (hash === 'apc-months' || hash === 'apc-users') {
      setActive(hash);
    } else {
      setActive('apc-users');
    }
  });
})();

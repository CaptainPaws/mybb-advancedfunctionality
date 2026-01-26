(function () {
  'use strict';

  if (window.__afAdvancedStatisticInit) return;
  window.__afAdvancedStatisticInit = true;

  function onReady(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  onReady(function () {
    var root = document.querySelector('[data-af-as="1"]');
    if (!root) return;

    // Добавим класс, чтобы можно было навесить эффекты через CSS, если захочешь
    root.classList.add('af_as_ready');

    // Title на аватарках уже есть, но на всякий случай:
    root.querySelectorAll('.af_as_avatar[title]').forEach(function (a) {
      if (!a.getAttribute('aria-label')) a.setAttribute('aria-label', a.getAttribute('title'));
    });
  });
})();

(function () {
  'use strict';

  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function removeThreadedLinks(root) {
    root = root || document;

    // Ищем любые ссылки на каскадный/древовидный режим: mode=threaded
    var links = root.querySelectorAll('a[href*="mode=threaded"]');
    if (!links || !links.length) return;

    links.forEach(function (a) {
      // На всякий: проверим, что это именно showthread и именно mode=threaded
      var href = a.getAttribute('href') || '';
      if (href.indexOf('showthread.php') !== -1 && href.indexOf('mode=threaded') !== -1) {
        // если ссылка обёрнута в li / div, можно удалить контейнер — но безопаснее удалять саму ссылку
        a.remove();
      }
    });
  }

  onReady(function () {
    // Удаляем сразу
    removeThreadedLinks(document);

    // На случай, если тема/шапка подгружается динамически (редко, но бывает)
    var mo = new MutationObserver(function (mutations) {
      for (var i = 0; i < mutations.length; i++) {
        var m = mutations[i];
        if (m.addedNodes && m.addedNodes.length) {
          removeThreadedLinks(document);
          break;
        }
      }
    });

    mo.observe(document.documentElement || document.body, { childList: true, subtree: true });
  });
})();

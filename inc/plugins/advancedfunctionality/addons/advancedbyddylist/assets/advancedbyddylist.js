(function () {
  'use strict';

  if (window.__afABDLLoaded) return;
  window.__afABDLLoaded = true;

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function closest(el, sel) {
    if (!el) return null;
    if (el.closest) return el.closest(sel);
    while (el) {
      if (el.matches && el.matches(sel)) return el;
      el = el.parentNode;
    }
    return null;
  }

  function showPane(modal, name) {
    if (!modal) return;

    qsa('.af-abdl-tab', modal).forEach(function (t) {
      t.classList.toggle('is-active', t.getAttribute('data-tab') === name);
    });

    qsa('.af-abdl-pane', modal).forEach(function (p) {
      p.style.display = (p.getAttribute('data-pane') === name) ? '' : 'none';
    });
  }

  function hardRemove(modal) {
    try {
      var blocker = qs('.blocker');
      if (blocker && blocker.parentNode) blocker.parentNode.removeChild(blocker);
    } catch (e0) {}

    try {
      if (modal && modal.parentNode) modal.parentNode.removeChild(modal);
    } catch (e1) {}
  }

function closeNow(modal) {
  // Определяем, что мы на реальной странице popup окна buddypopup,
  // а не на обычной странице форума с модалкой, вставленной в DOM.
  var search = String(window.location && window.location.search ? window.location.search : '');
  var isBuddyPopupPage = /(?:\?|&)action=buddypopup(?:&|$)/i.test(search);
  var hasOpener = false;

  try {
    hasOpener = !!(window.opener && window.opener !== window && !window.opener.closed);
  } catch (e0) {
    hasOpener = false;
  }

  // 1) Если это реально popup окно (есть opener и мы на misc.php?action=buddypopup) — тогда закрываем окно
  if (isBuddyPopupPage && hasOpener) {
    try {
      window.close();
      return true;
    } catch (e1) {
      // если браузер не дал закрыть — идём дальше и закрываем как модалку
    }
  }

  // 2) Закрываем как модалку (jQuery.modal), НО только если есть blocker (признак открытой модалки)
  try {
    var blocker = qs('.blocker');
    if (blocker && window.jQuery && window.jQuery.modal && typeof window.jQuery.modal.close === 'function') {
      window.jQuery.modal.close();
      return true;
    }
  } catch (e2) {}

  // 3) Если у MyBB есть свой закрыватель модалок — пробуем
  try {
    if (window.MyBB && typeof window.MyBB.closeModal === 'function') {
      window.MyBB.closeModal();
      return true;
    }
  } catch (e3) {}

  // 4) Гарант: прибиваем DOM руками (и blocker тоже, если он есть)
  hardRemove(modal);
  return true;
}


  // ====== ВЕШАЕМ ПРЯМЫЕ ИВЕНТЫ НА КНОПКУ (как ты требуешь) ======
  function wireModal(modal) {
    if (!modal || modal.__afAbdlWired) return;
    modal.__afAbdlWired = true;

    // дефолт: friends
    try { showPane(modal, 'friends'); } catch (e0) {}

    var closeBtn = qs('.af-abdl-modal-close', modal);
    var backdrop = qs('.af-abdl-modal-backdrop', modal);

    function doClose(e) {
      if (e) { e.preventDefault(); e.stopPropagation(); }
      closeNow(modal);
    }

    // ВОТ ОНО: прямой обработчик на кнопку, как в AAS
    if (closeBtn) {
      closeBtn.addEventListener('click', function (e) {
        e.preventDefault();
        doClose(e);
      }, true);
    }

    if (backdrop) {
      backdrop.addEventListener('click', function (e) {
        e.preventDefault();
        doClose(e);
      }, true);
    }

    // табы — тоже прямые
    qsa('.af-abdl-tab', modal).forEach(function (tab) {
      tab.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        showPane(modal, tab.getAttribute('data-tab') || 'friends');
      }, true);
    });
  }

  function findAndWire() {
    var modal = qs('#af_abdl_modal');
    if (modal) wireModal(modal);
  }

  // 1) пробуем сразу (если модалка уже в DOM)
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', findAndWire);
  } else {
    findAndWire();
  }

  // 2) ключ: наблюдаем, когда popupWindow вставит HTML-фрагмент, и только тогда вешаем ивенты
  (function observe() {
    var root = document.body || document.documentElement;
    if (!root || typeof MutationObserver === 'undefined') return;

    var obs = new MutationObserver(function (mutList) {
      for (var i = 0; i < mutList.length; i++) {
        var m = mutList[i];
        if (!m.addedNodes || !m.addedNodes.length) continue;

        for (var j = 0; j < m.addedNodes.length; j++) {
          var n = m.addedNodes[j];
          if (!n || n.nodeType !== 1) continue;

          // если вставили саму модалку или контейнер с ней — цепляем
          if (n.id === 'af_abdl_modal') { wireModal(n); return; }
          var inside = (n.querySelector) ? n.querySelector('#af_abdl_modal') : null;
          if (inside) { wireModal(inside); return; }
        }
      }
    });

    try { obs.observe(root, { childList: true, subtree: true }); } catch (e) {}
  })();

  // 3) страховка: делегирование на документ (на случай, если тема/скрипты мешают прямым)
  document.addEventListener('click', function (e) {
    var t = e.target;

    // если модалки нет — не делаем ничего
    var modal = qs('#af_abdl_modal');
    if (!modal) return;

    // закрытие по клику на крестик/бекдроп (даже если прямой обработчик не повесился)
    var closer = closest(t, '[data-af-abdl-close="1"]');
    if (closer) {
      e.preventDefault();
      e.stopPropagation();
      closeNow(modal);
      return;
    }

    // табы
    var tab = closest(t, '.af-abdl-tab');
    if (tab) {
      e.preventDefault();
      e.stopPropagation();
      showPane(modal, tab.getAttribute('data-tab') || 'friends');
      return;
    }
  }, true);

  // Escape
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var modal = qs('#af_abdl_modal');
    if (!modal) return;
    e.preventDefault();
    e.stopPropagation();
    closeNow(modal);
  }, true);

})();

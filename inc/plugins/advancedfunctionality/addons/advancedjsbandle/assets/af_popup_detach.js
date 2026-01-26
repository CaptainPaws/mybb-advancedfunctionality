(function () {
  'use strict';

  if (window.__afDetachMybbPopupMenus) return;
  window.__afDetachMybbPopupMenus = true;

  function qs(id) { try { return document.getElementById(id); } catch (e) { return null; } }
  function isOpen(menu) {
    if (!menu) return false;
    // MyBB часто управляет inline style="display: none"
    var st = menu.style && menu.style.display;
    if (st && st.toLowerCase() === 'none') return false;
    // если display не задан инлайном — смотрим computed
    try {
      return window.getComputedStyle(menu).display !== 'none';
    } catch (e) {}
    return true;
  }

  function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }

  // держим связи menu -> trigger
  var state = new WeakMap();

  function positionMenu(trigger, menu) {
    if (!trigger || !menu) return;

    var r = trigger.getBoundingClientRect();

    // IMPORTANT: menu должен быть в потоке измеряемым
    // (если он был display:none — MyBB уже сделал display:block к моменту вызова)
    menu.style.left = '0px';
    menu.style.top = '0px';

    var mw = menu.offsetWidth || 0;
    var mh = menu.offsetHeight || 0;

    var vw = document.documentElement.clientWidth;
    var vh = document.documentElement.clientHeight;

    var gap = 6;

    // по умолчанию: вниз и вправо от кнопки
    var left = r.right - mw;
    var top = r.bottom + gap;

    // если слишком влево — прижимаем к левому краю
    left = clamp(left, 8, Math.max(8, vw - mw - 8));

    // если снизу не влезает — вверх
    if (top + mh > vh - 8) {
      top = r.top - mh - gap;
      // если и сверху не влезло — прижимаем
      top = clamp(top, 8, Math.max(8, vh - mh - 8));
    }

    menu.style.left = Math.round(left) + 'px';
    menu.style.top = Math.round(top) + 'px';
  }

  function detach(trigger, menu) {
    if (!trigger || !menu) return;

    // запомним оригинального родителя, если вдруг захочешь возвращать
    if (!state.has(menu)) {
      state.set(menu, {
        parent: menu.parentNode,
        next: menu.nextSibling,
        trigger: trigger
      });
    } else {
      state.get(menu).trigger = trigger;
    }

    // переносим в body
    if (menu.parentNode !== document.body) {
      document.body.appendChild(menu);
    }

    menu.classList.add('af-popup-detached');

    // MyBB иногда оставляет position absolute/top/left огромные по документу — убьём это
    menu.style.position = 'fixed';
    menu.style.margin = '0';
    menu.style.right = 'auto';
    menu.style.bottom = 'auto';

    positionMenu(trigger, menu);
  }

  function onAfterToggle(trigger) {
    if (!trigger || !trigger.id) return;

    var menu = qs(trigger.id + '_popup');
    if (!menu) return;

    if (!menu.classList.contains('popup_menu')) return;

    if (!isOpen(menu)) return;

    detach(trigger, menu);
  }

  // клик по триггеру popupMenu — в capture, чтобы отработать при любых раскладах
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t) return;

    // ищем ближайший элемент с id, который похож на триггер меню
    var trigger = null;
    if (t.closest) {
      trigger = t.closest('[id]');
    } else {
      // fallback
      trigger = t;
      while (trigger && trigger.nodeType === 1 && !trigger.getAttribute('id')) trigger = trigger.parentNode;
    }
    if (!trigger || !trigger.id) return;

    // фильтр: у триггера должен существовать id_popup.popup_menu
    var menu = qs(trigger.id + '_popup');
    if (!menu) return;
    if (!menu.classList || !menu.classList.contains('popup_menu')) return;

    // дать jQuery popupMenu() открыть меню
    setTimeout(function () { onAfterToggle(trigger); }, 0);
  }, true);

  // поддержка: при скролле/ресайзе — перепозиционируем открытое меню
  function repointAllOpen() {
    // в DOM может быть несколько popup_menu, но нам важны только detached
    var menus = document.querySelectorAll('.popup_menu.af-popup-detached');
    for (var i = 0; i < menus.length; i++) {
      var menu = menus[i];
      if (!isOpen(menu)) continue;
      var st = state.get(menu);
      if (!st || !st.trigger) continue;
      positionMenu(st.trigger, menu);
    }
  }

  window.addEventListener('scroll', repointAllOpen, true);
  window.addEventListener('resize', repointAllOpen, true);
})();

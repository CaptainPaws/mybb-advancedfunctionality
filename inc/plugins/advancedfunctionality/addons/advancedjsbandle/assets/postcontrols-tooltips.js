(function () {
  'use strict';

  if (window.__afGlobalTitleTooltips) return;
  window.__afGlobalTitleTooltips = true;

  // Глобальный селектор: ВСЕ элементы с title
  // Исключения (если нужно) добавишь сюда: [title]:not(.no-af-tip):not([data-native-title="1"])
  var SEL = '[title]';

  // Не трогаем системные/спец-элементы
  function shouldIgnore(el) {
    if (!el || el.nodeType !== 1) return true;

    // если явно попросили оставить нативный тултип
    if (el.hasAttribute('data-native-title') || el.getAttribute('data-native-title') === '1') return true;

    // SVG <title> не трогаем
    if (el.tagName && el.tagName.toLowerCase() === 'title') return true;

    // пустой title — мимо
    var t = (el.getAttribute('title') || '').trim();
    if (!t) return true;

    // disabled/hidden
    if (el.disabled) return true;

    return false;
  }

  function findWithTitle(start) {
    var el = start;
    while (el && el !== document && el.nodeType === 1) {
      if (el.matches && el.matches(SEL)) return el;
      el = el.parentElement;
    }
    return null;
  }

  // --- Tooltip DOM (один на весь сайт) ---
  var tip = document.createElement('div');
  tip.className = 'af-title-tip';
  tip.style.display = 'none';

  var inner = document.createElement('div');
  inner.className = 'af-title-tip__inner';
  inner.style.whiteSpace = 'pre-line';

  tip.appendChild(inner);
  document.addEventListener('DOMContentLoaded', function () {
    // на всякий — если скрипт в <head>
    if (!tip.parentNode) document.body.appendChild(tip);
  });
  // если body уже есть — сразу
  if (document.body && !tip.parentNode) document.body.appendChild(tip);

  var activeEl = null;
  var activeTitle = '';
  var restoreTimer = 0;

  function setText(txt) {
    inner.textContent = txt;
  }

  // clamp по горизонтали, чтобы не улетал за края экрана
  function positionTip(clientX, clientY, anchorRect) {
    // показываем "внизу" от элемента (как ты хотела)
    var gap = 10;

    // если есть rect элемента — позиционируем от него (стабильнее, чем от мыши)
    var x = 0;
    var y = 0;

    if (anchorRect) {
      x = anchorRect.left + anchorRect.width / 2;
      y = anchorRect.bottom + gap;
    } else {
      x = clientX;
      y = clientY + gap;
    }

    // временно делаем видимым для измерений
    tip.style.display = 'block';
    tip.style.left = '0px';
    tip.style.top = '0px';

    var w = tip.offsetWidth;
    var h = tip.offsetHeight;

    // clamp внутри viewport
    var vw = document.documentElement.clientWidth;
    var vh = document.documentElement.clientHeight;

    var left = Math.round(x - w / 2);
    var top = Math.round(y);

    var pad = 8;
    if (left < pad) left = pad;
    if (left + w > vw - pad) left = Math.max(pad, vw - pad - w);

    // если снизу не влезает — кидаем вверх (авто), но ориентация по умолчанию вниз
    if (top + h > vh - pad) {
      if (anchorRect) top = Math.round(anchorRect.top - gap - h);
      else top = Math.round(clientY - gap - h);
      if (top < pad) top = pad;
    }

    tip.style.left = left + 'px';
    tip.style.top = top + 'px';
  }

  function showFor(el, clientX, clientY) {
    if (shouldIgnore(el)) return;

    var title = (el.getAttribute('title') || '').trim();
    if (!title) return;

    // запоминаем, чтобы восстановить
    activeEl = el;
    activeTitle = title;

    // убираем нативный тултип: сохраняем в data, убираем title
    if (!el.hasAttribute('data-af-title')) {
      el.setAttribute('data-af-title', title);
    }
    el.removeAttribute('title');

    setText(title);
    tip.style.display = 'block';

    var rect = null;
    try { rect = el.getBoundingClientRect(); } catch (e) { rect = null; }
    positionTip(clientX, clientY, rect);
  }

  function hide() {
    tip.style.display = 'none';

    if (restoreTimer) {
      clearTimeout(restoreTimer);
      restoreTimer = 0;
    }

    // мягко возвращаем title (чтобы работали accessibility/клавиатура)
    if (activeEl) {
      var el = activeEl;
      var t = activeTitle;

      restoreTimer = setTimeout(function () {
        try {
          // если за время показа элемент не получил новый title — восстановим
          if (!el.getAttribute('title')) {
            el.setAttribute('title', t);
          }
          // оставим data-af-title как кэш, он не мешает
        } catch (e) {}
      }, 10);
    }

    activeEl = null;
    activeTitle = '';
  }

  // --- Делегирование событий на документ ---
  document.addEventListener('mouseover', function (e) {
    var el = findWithTitle(e.target);
    if (!el) return;
    showFor(el, e.clientX, e.clientY);
  }, true);

  document.addEventListener('mousemove', function (e) {
    if (!activeEl || tip.style.display === 'none') return;
    // при движении — держим рядом с элементом, а не с мышью (устойчиво)
    var rect = null;
    try { rect = activeEl.getBoundingClientRect(); } catch (err) { rect = null; }
    positionTip(e.clientX, e.clientY, rect);
  }, true);

  document.addEventListener('mouseout', function (e) {
    // если ушли с активного элемента (или его детей) — скрываем
    if (!activeEl) return;

    var to = e.relatedTarget;
    if (to && activeEl.contains && activeEl.contains(to)) return;

    // если курсор ушёл на другой title-элемент — быстро переключим
    var next = findWithTitle(to);
    if (next && next !== activeEl && !shouldIgnore(next)) {
      // спрячем старый (с восстановлением)
      hide();
      // покажем новый
      showFor(next, e.clientX, e.clientY);
      return;
    }

    hide();
  }, true);

  // Клавиатура: focus/blur
  document.addEventListener('focusin', function (e) {
    var el = findWithTitle(e.target);
    if (!el) return;

    // для фокуса координаты берём из rect
    showFor(el, 0, 0);
  }, true);

  document.addEventListener('focusout', function () {
    hide();
  }, true);

  // При скролле/ресайзе — перепозиционируем
  window.addEventListener('scroll', function () {
    if (!activeEl || tip.style.display === 'none') return;
    var rect = null;
    try { rect = activeEl.getBoundingClientRect(); } catch (e) { rect = null; }
    positionTip(0, 0, rect);
  }, true);

  window.addEventListener('resize', function () {
    if (!activeEl || tip.style.display === 'none') return;
    var rect = null;
    try { rect = activeEl.getBoundingClientRect(); } catch (e) { rect = null; }
    positionTip(0, 0, rect);
  }, true);
})();

(function () {
  'use strict';

  if (window.__afScrollButtonsInit) return;
  window.__afScrollButtonsInit = true;

  function onReady(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  function createButton(type) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'af-sb-btn af-sb-btn--' + type;
    btn.setAttribute('aria-label', type === 'up' ? 'Вверх' : 'Вниз');
    btn.setAttribute('title', type === 'up' ? 'Вверх' : 'Вниз');

    var icon = document.createElement('i');
    icon.className = (type === 'up')
      ? 'fa-solid fa-chevron-up'
      : 'fa-solid fa-chevron-down';
    icon.setAttribute('aria-hidden', 'true');

    btn.appendChild(icon);
    return btn;
  }

  function maxScrollTop() {
    var de = document.documentElement;
    var b = document.body;
    var scrollHeight = Math.max(
      de.scrollHeight, b.scrollHeight,
      de.offsetHeight, b.offsetHeight,
      de.clientHeight
    );
    return Math.max(0, scrollHeight - window.innerHeight);
  }

  function getScrollTop() {
    return window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
  }

  function smoothScrollTo(y) {
    var maxY = maxScrollTop();
    if (y < 0) y = 0;
    if (y > maxY) y = maxY;

    // если браузер поддерживает smooth — отлично
    try {
      window.scrollTo({ top: y, behavior: 'smooth' });
    } catch (e) {
      window.scrollTo(0, y);
    }
  }

  function setVisible(el, visible) {
    if (!el) return;
    if (visible) el.classList.add('is-visible');
    else el.classList.remove('is-visible');
  }

  function updateVisibility(upBtn, downBtn) {
    var y = getScrollTop();
    var maxY = maxScrollTop();

    // показываем вверх, когда есть куда возвращаться
    setVisible(upBtn, y > 80);

    // показываем вниз, когда есть что листать
    setVisible(downBtn, (maxY - y) > 120);
  }

  onReady(function () {
    // если страниц короткая — вообще не рисуем
    if (maxScrollTop() < 200) return;

    var wrap = document.createElement('div');
    wrap.className = 'af-sb-wrap';
    wrap.setAttribute('aria-label', 'Навигация по странице');

    var upBtn = createButton('up');
    var downBtn = createButton('down');

    wrap.appendChild(upBtn);
    wrap.appendChild(downBtn);
    document.body.appendChild(wrap);

    upBtn.addEventListener('click', function () {
      smoothScrollTo(0);
    });

    downBtn.addEventListener('click', function () {
      smoothScrollTo(maxScrollTop());
    });

    // обновляем видимость при скролле/ресайзе
    var ticking = false;
    function onTick() {
      ticking = false;
      updateVisibility(upBtn, downBtn);
    }
    function requestTick() {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(onTick);
    }

    window.addEventListener('scroll', requestTick, { passive: true });
    window.addEventListener('resize', requestTick);

    // первый расчёт
    updateVisibility(upBtn, downBtn);
  });

})();

(function (window, document) {
  'use strict';
  if (window.__afCharacterSheetsTrigger) return;
  window.__afCharacterSheetsTrigger = true;

  var modal = null;
  var keyHandler = null;

  function normalizeUrl(raw) {
    var url = String(raw || '').trim();
    if (!url) return '';
    try {
      var parsed = new URL(url, document.baseURI);
      if (!parsed.searchParams.has('embed')) parsed.searchParams.set('embed', '1');
      return parsed.href;
    } catch (_) {
      return url;
    }
  }

  function resolveTriggerUrl(trigger) {
    var url = trigger.getAttribute('data-afcs-sheet') || trigger.getAttribute('href') || '';
    if (!url) {
      var slug = trigger.getAttribute('data-slug') || '';
      if (slug) url = 'charactersheets.php?slug=' + encodeURIComponent(slug);
    }
    return normalizeUrl(url);
  }

  function cleanup() {
    if (!modal) return;
    var frame = modal.querySelector('[data-afcs-frame]');
    if (frame) frame.removeAttribute('src');
    if (keyHandler) document.removeEventListener('keydown', keyHandler);
    modal.remove();
    modal = null;
    keyHandler = null;
    document.body.classList.remove('af-cs-modal-open');
  }

  function buildModal(url) {
    cleanup();

    modal = document.createElement('div');
    modal.className = 'af-cs-modal is-open';
    modal.setAttribute('data-afcs-modal', '1');
    modal.setAttribute('data-af-modal-kind', 'sheet');
    modal.innerHTML =
      '<div class="af-cs-modal__backdrop" data-afcs-close="1"></div>' +
      '<div class="af-cs-modal__dialog" role="dialog" aria-modal="true" aria-label="Лист персонажа">' +
        '<button type="button" class="af-cs-modal__close" data-afcs-close="1" aria-label="Закрыть">×</button>' +
        '<div class="af-cs-modal__body">' +
          '<iframe class="af-cs-modal__frame" data-afcs-frame="1" title="Лист персонажа"></iframe>' +
          '<div class="af-cs-modal__loader" data-afcs-loader="1" role="status" aria-live="polite">Загрузка листа персонажа…</div>' +
        '</div>' +
      '</div>';

    var frame = modal.querySelector('[data-afcs-frame]');
    var loader = modal.querySelector('[data-afcs-loader]');
    frame.addEventListener('load', function () {
      if (loader) loader.hidden = true;
    }, { once: true });

    modal.addEventListener('click', function (event) {
      if (event.target.closest('[data-afcs-close="1"]')) {
        event.preventDefault();
        cleanup();
      }
    });

    keyHandler = function (event) {
      if (event.key === 'Escape') cleanup();
    };
    document.addEventListener('keydown', keyHandler);

    (window.AFModalHost ? window.AFModalHost.mount(modal) : document.body.appendChild(modal));
    document.body.classList.add('af-cs-modal-open');

    // This is deliberately the first point where the iframe receives a URL.
    frame.setAttribute('src', url);
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target && event.target.closest
      ? event.target.closest('[data-afcs-open="1"], [data-afcs-sheet]')
      : null;
    if (!trigger) return;

    var url = resolveTriggerUrl(trigger);
    if (!url) return;

    event.preventDefault();
    event.stopPropagation();
    if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
    buildModal(url);
  }, true);
})(window, document);

(function (window, document) {
  'use strict';
  if (window.__afCharacterSheetsTrigger) return;
  window.__afCharacterSheetsTrigger = true;

  var modal = null;
  var keyHandler = null;
  var loadingState = null;
  var previousFocus = null;

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
    var url = trigger.getAttribute('data-afcs-sheet') || trigger.getAttribute('data-afcs-application') || trigger.getAttribute('href') || '';
    if (!url) {
      var slug = trigger.getAttribute('data-slug') || '';
      if (slug) url = 'charactersheets.php?slug=' + encodeURIComponent(slug);
    }
    return normalizeUrl(url);
  }

  // Also used by APUI's application fragment/iframe paths. No sheet runtime.
  function beginLoading(body, fallbackUrl) {
    var loader = document.createElement('div');
    loader.className = 'af-cs-modal__loader';
    loader.setAttribute('role', 'status');
    loader.setAttribute('aria-live', 'polite');
    loader.textContent = 'Загрузка…';
    body.setAttribute('aria-busy', 'true');
    body.appendChild(loader);
    var disposed = false;
    var fadeTimer = null;
    var timer = window.setTimeout(fail, 30000);
    function fail() {
      if (disposed) return;
      window.clearTimeout(timer);
      body.setAttribute('aria-busy', 'false');
      loader.classList.add('is-error');
      loader.textContent = 'Не удалось загрузить содержимое. ';
      if (fallbackUrl) {
        var link = document.createElement('a');
        link.href = fallbackUrl;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = 'Открыть отдельно';
        loader.appendChild(link);
      }
    }
    return {
      fail: fail,
      finish: function () {
        if (disposed) return;
        window.clearTimeout(timer);
        body.setAttribute('aria-busy', 'false');
        loader.classList.add('is-loaded');
        fadeTimer = window.setTimeout(function () { loader.hidden = true; }, 180);
      },
      dispose: function () {
        disposed = true;
        window.clearTimeout(timer);
        window.clearTimeout(fadeTimer);
        body.setAttribute('aria-busy', 'false');
        loader.remove();
      }
    };
  }
  window.AFCharacterSheetsTrigger = { beginLoading: beginLoading };

  function pinSheetFrameLayout(modalRoot, frame) {
    if (!modalRoot || !frame) return;
    var dialog = modalRoot.querySelector('.af-cs-modal__dialog');
    var body = modalRoot.querySelector('.af-cs-modal__body');
    if (!dialog || !body) return;

    modalRoot.classList.add('af-cs-modal--sheet-frame');

    // The sheet modal has no header row: bind the iframe directly to the
    // already-sized dialog instead of relying on percentage/flex sizing of a
    // replaced element (whose fallback height is 150px).
    dialog.style.position = 'relative';
    dialog.style.overflow = 'visible';

    var close = dialog.querySelector('.af-cs-modal__close');
    if (close) {
      close.style.position = 'absolute';
      close.style.top = '0';
      close.style.right = '0';
      close.style.margin = '0';
      close.style.transform = 'translate(50%, -50%)';
      close.style.zIndex = '5';
    }

    body.style.position = 'absolute';
    body.style.inset = '0';
    body.style.width = 'auto';
    body.style.height = 'auto';
    body.style.minHeight = '0';
    body.style.overflow = 'hidden';

    frame.style.position = 'absolute';
    frame.style.inset = '0';
    frame.style.display = 'block';
    frame.style.width = '100%';
    frame.style.height = '100%';
    frame.style.minHeight = '100%';
    frame.style.maxHeight = 'none';
    frame.style.border = '0';
  }

  function cleanup() {
    if (!modal) return;
    if (loadingState) loadingState.dispose();
    loadingState = null;
    var frame = modal.querySelector('[data-afcs-frame]');
    if (frame) frame.removeAttribute('src');
    if (keyHandler) document.removeEventListener('keydown', keyHandler);
    modal.remove();
    modal = null;
    keyHandler = null;
    document.body.classList.remove('af-cs-modal-open');
    if (previousFocus && previousFocus.isConnected) previousFocus.focus();
  }

  function buildModal(url) {
    cleanup();
    previousFocus = document.activeElement;

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
        '</div>' +
      '</div>';

    var frame = modal.querySelector('[data-afcs-frame]');
    pinSheetFrameLayout(modal, frame);
    loadingState = beginLoading(modal.querySelector('.af-cs-modal__body'), url);
    var currentLoading = loadingState;
    frame.addEventListener('load', function () {
      // Ignore the initial empty document and loads belonging to a closed shell.
      if (!frame.getAttribute('src') || !frame.isConnected) return;
      try {
        if (frame.contentDocument && frame.contentDocument.URL === 'about:blank') return;
      } catch (_) { /* Cross-origin frames still have a load lifecycle. */ }
      frame.classList.add('is-loaded');
      currentLoading.finish();
    });
    frame.addEventListener('error', function () { currentLoading.fail(); });

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
    modal.querySelector('button[data-afcs-close="1"]').focus();
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target && event.target.closest
      ? (event.target.closest('[data-afcs-open="1"], [data-afcs-sheet]')
        || event.target.closest('[data-afcs-application]'))
      : null;
    if (!trigger) return;

    var url = resolveTriggerUrl(trigger);
    if (!url) return;

    event.preventDefault();
    event.stopPropagation();
    if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
    buildModal(url);
    if (trigger.hasAttribute('data-afcs-application')) {
      modal.setAttribute('data-af-modal-kind', 'application');
      modal.querySelector('[role="dialog"]').setAttribute('aria-label', 'Анкета');
      modal.querySelector('[data-afcs-frame]').title = 'Анкета';
    }
  }, true);
})(window, document);

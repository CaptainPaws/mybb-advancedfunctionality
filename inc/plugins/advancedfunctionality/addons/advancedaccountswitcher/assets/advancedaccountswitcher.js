(function () {
  'use strict';

  if (window.afAasInitialized) return;
  window.afAasInitialized = true;

  function qs(sel, root) { return (root || document).querySelector(sel); }

  function getBburl() {
    if (window.afAasConfig && typeof window.afAasConfig.bburl === 'string' && window.afAasConfig.bburl) {
      return window.afAasConfig.bburl.replace(/\/+$/, '');
    }
    return '';
  }

  function buildUrl(path) {
    var bburl = getBburl();
    if (bburl) return bburl + '/' + String(path).replace(/^\/+/, '');
    return String(path).replace(/^\/+/, '');
  }

  // Header modal (👥)
  (function initModal() {
    var trigger = qs('#af_aas_trigger');
    var modal = qs('#af_aas_modal');
    var closeBtn = modal ? modal.querySelector('.af-aas-modal-close') : null;
    var backdrop = modal ? modal.querySelector('.af-aas-modal-backdrop') : null;

    if (!trigger || !modal) return;

    function openModal() {
      modal.classList.add('af-aas-modal-open');
      modal.style.display = 'flex';
      trigger.setAttribute('aria-expanded', 'true');
    }

    function closeModal() {
      modal.classList.remove('af-aas-modal-open');
      modal.style.display = 'none';
      trigger.setAttribute('aria-expanded', 'false');
    }

    trigger.addEventListener('click', function (e) {
      if (e.button === 0 && !e.ctrlKey && !e.metaKey && !e.shiftKey && !e.altKey) {
        e.preventDefault();
        if (modal.classList.contains('af-aas-modal-open')) closeModal();
        else openModal();
      }
    });

    if (closeBtn) closeBtn.addEventListener('click', function (e) { e.preventDefault(); closeModal(); });
    if (backdrop) backdrop.addEventListener('click', function (e) { e.preventDefault(); closeModal(); });

    document.addEventListener('click', function (e) {
      if (modal.style.display === 'none') return;
      if (e.target.closest('#af_aas_modal') || e.target.closest('#af_aas_trigger')) return;
      closeModal();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeModal();
    });
  })();

  // UCP suggest
(function initUcpSuggest() {
  var input = qs('#afAasLinkUsername');
  var hiddenUid = qs('#afAasLinkUid');
  var suggestBox = qs('#afAasSuggest');

  if (!input || !hiddenUid || !suggestBox) return;

  // максимально глушим браузерные подсказки
  input.setAttribute('autocomplete', 'off');
  input.setAttribute('autocorrect', 'off');
  input.setAttribute('autocapitalize', 'off');
  input.setAttribute('spellcheck', 'false');

  // делаем контейнер относительным, чтобы suggestBox можно было позиционировать
  var host = input.parentElement || input.closest('div') || input.closest('td') || document.body;
  if (host && host !== document.body) {
    var cs = window.getComputedStyle(host);
    if (cs && cs.position === 'static') {
      host.style.position = 'relative';
    }
  }

  // позиционируем подсказки НАД полем
  suggestBox.style.position = 'absolute';
  suggestBox.style.left = '0';
  suggestBox.style.right = '0';
  suggestBox.style.bottom = '100%';
  suggestBox.style.top = 'auto';
  suggestBox.style.marginBottom = '6px';
  suggestBox.style.zIndex = '9999';

  var timer = null;
  var lastQuery = '';

  function clearSuggest() {
    suggestBox.innerHTML = '';
    suggestBox.setAttribute('hidden', 'hidden');
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function render(items) {
    if (!items || !items.length) { clearSuggest(); return; }
    suggestBox.innerHTML = items.map(function (it) {
      return '<div class="af-aas-suggest-item" role="button" tabindex="0" ' +
        'data-uid="' + String(it.uid) + '" data-username="' + escapeHtml(it.username) + '">' +
        escapeHtml(it.username) + ' <span class="smalltext">(#' + String(it.uid) + ')</span></div>';
    }).join('');
    suggestBox.removeAttribute('hidden');
  }

  function load(q) {
    var query = q.trim();
    hiddenUid.value = '0';

    if (query.length < 2) { clearSuggest(); return; }
    if (query === lastQuery) return;
    lastQuery = query;

    if (timer) clearTimeout(timer);
    timer = setTimeout(function () {
      var url = buildUrl('misc.php?action=af_aas_user_suggest&query=' + encodeURIComponent(query));

      fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.ok) { clearSuggest(); return; }
          render(j.items || []);
        })
        .catch(function () { clearSuggest(); });
    }, 160);
  }

  input.addEventListener('input', function () {
    load(input.value || '');
  });

  input.addEventListener('focus', function () {
    if ((input.value || '').trim().length >= 2) load(input.value || '');
  });

  suggestBox.addEventListener('click', function (e) {
    var item = e.target.closest('.af-aas-suggest-item');
    if (!item) return;
    input.value = item.getAttribute('data-username') || '';
    hiddenUid.value = item.getAttribute('data-uid') || '0';
    clearSuggest();
    input.focus();
  });

  suggestBox.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    var item = e.target.closest('.af-aas-suggest-item');
    if (!item) return;
    input.value = item.getAttribute('data-username') || '';
    hiddenUid.value = item.getAttribute('data-uid') || '0';
    clearSuggest();
    input.focus();
  });

  document.addEventListener('click', function (e) {
    if (e.target === input || e.target.closest('#afAasSuggest')) return;
    clearSuggest();
  });
})();
})();

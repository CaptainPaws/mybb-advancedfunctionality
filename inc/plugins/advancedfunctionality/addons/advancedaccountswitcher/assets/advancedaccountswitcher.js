(function () {
  'use strict';

  if (window.afAasInitialized) return;
  window.afAasInitialized = true;

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  // Header modal (👥)
  (function initModal() {
    var trigger = qs('#af_aas_trigger');
    var modal = qs('#af_aas_modal');
    var closeBtn = modal ? modal.querySelector('.af-aas-modal-close') : null;
    var backdrop = modal ? modal.querySelector('.af-aas-modal-backdrop') : null;

    if (!trigger || !modal) {
      return;
    }

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
        if (modal.classList.contains('af-aas-modal-open')) {
          closeModal();
        } else {
          openModal();
        }
      }
    });

    if (closeBtn) {
      closeBtn.addEventListener('click', function (e) {
        e.preventDefault();
        closeModal();
      });
    }

    if (backdrop) {
      backdrop.addEventListener('click', function (e) {
        e.preventDefault();
        closeModal();
      });
    }

    document.addEventListener('click', function (e) {
      if (modal.style.display === 'none') return;
      if (e.target.closest('#af_aas_modal') || e.target.closest('#af_aas_trigger')) return;
      closeModal();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeModal();
      }
    });
  })();

  // UCP suggest
  var input = qs('#afAasLinkUsername');
  var hiddenUid = qs('#afAasLinkUid');
  var suggestBox = qs('#afAasSuggest');

  if (input && hiddenUid && suggestBox) {
    var timer = null;

    function clearSuggest() {
      suggestBox.innerHTML = '';
      suggestBox.setAttribute('hidden', 'hidden');
    }

    function render(items) {
      if (!items || !items.length) { clearSuggest(); return; }
      suggestBox.innerHTML = items.map(function (it) {
        return '<div class="af-aas-suggest-item" data-uid="' + it.uid + '" data-username="' + String(it.username).replace(/"/g, '&quot;') + '">' +
          it.username + ' <span class="smalltext">(#' + it.uid + ')</span></div>';
      }).join('');
      suggestBox.removeAttribute('hidden');
    }

    input.addEventListener('input', function () {
      hiddenUid.value = '0';
      var q = input.value.trim();
      if (q.length < 2) { clearSuggest(); return; }

      if (timer) clearTimeout(timer);
      timer = setTimeout(function () {
        fetch('misc.php?action=af_aas_user_suggest&query=' + encodeURIComponent(q), { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (!j || !j.ok) { clearSuggest(); return; }
            render(j.items || []);
          })
          .catch(function () { clearSuggest(); });
      }, 150);
    });

    suggestBox.addEventListener('click', function (e) {
      var item = e.target.closest('.af-aas-suggest-item');
      if (!item) return;
      input.value = item.getAttribute('data-username') || '';
      hiddenUid.value = item.getAttribute('data-uid') || '0';
      clearSuggest();
    });

    document.addEventListener('click', function (e) {
      if (e.target === input || e.target.closest('#afAasSuggest')) return;
      clearSuggest();
    });
  }
})();

(function () {
  'use strict';

  if (window.afAasInitialized) return;
  window.afAasInitialized = true;

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  // Panel dropdown (👥)
  qsa('.af-aas-panel-toggle').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();

      var wrap = btn.closest('.af-aas-panel');
      if (!wrap) return;

      var menu = qs('.af-aas-panel-menu', wrap);
      if (!menu) return;

      var open = !menu.hasAttribute('hidden');
      if (open) {
        menu.setAttribute('hidden', 'hidden');
        btn.setAttribute('aria-expanded', 'false');
      } else {
        menu.removeAttribute('hidden');
        btn.setAttribute('aria-expanded', 'true');
      }
    });
  });

  function closeAll(exceptWrap) {
    qsa('.af-aas-panel').forEach(function (wrap) {
      if (exceptWrap && wrap === exceptWrap) return;
      var btn = qs('.af-aas-panel-toggle', wrap);
      var menu = qs('.af-aas-panel-menu', wrap);
      if (menu && !menu.hasAttribute('hidden')) menu.setAttribute('hidden', 'hidden');
      if (btn) btn.setAttribute('aria-expanded', 'false');
    });
  }

  document.addEventListener('click', function (e) {
    var wrap = e.target.closest('.af-aas-panel');
    closeAll(wrap);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAll(null);
  });

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

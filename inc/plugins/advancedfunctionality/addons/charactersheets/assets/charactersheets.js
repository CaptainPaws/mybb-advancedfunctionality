(function () {
  var modal = document.querySelector('[data-afcs-modal]');
  var frame = modal ? modal.querySelector('[data-afcs-frame]') : null;

  function closeModal() {
    if (!modal) {
      return;
    }
    modal.classList.remove('is-open');
    if (frame) {
      frame.removeAttribute('src');
    }
  }

  function openModal(url) {
    if (!modal || !frame) {
      window.open(url, '_blank');
      return;
    }
    var loadUrl = url;
    if (loadUrl.indexOf('ajax=1') === -1) {
      loadUrl += (loadUrl.indexOf('?') === -1 ? '?' : '&') + 'ajax=1';
    }
    frame.setAttribute('src', loadUrl);
    modal.classList.add('is-open');
  }

  document.addEventListener('click', function (event) {
    var plaque = event.target.closest('.af-cs-plaque__btn[data-afcs-open="1"]');
    if (plaque) {
      event.preventDefault();
      var slug = plaque.getAttribute('data-slug');
      if (slug) {
        openModal('misc.php?action=af_charactersheet&slug=' + encodeURIComponent(slug));
      }
      return;
    }

    var trigger = event.target.closest('[data-afcs-sheet]');
    if (trigger) {
      event.preventDefault();
      var url = trigger.getAttribute('data-afcs-sheet');
      if (url) {
        openModal(url);
      }
      return;
    }

    if (event.target.closest('[data-afcs-close]')) {
      closeModal();
    }
  });

  document.querySelectorAll('[data-afcs-tabs]').forEach(function (tabs) {
    var buttons = tabs.querySelectorAll('[data-afcs-tab]');
    var panels = tabs.querySelectorAll('[data-afcs-tab-content]');

    function activateTab(name) {
      buttons.forEach(function (btn) {
        btn.classList.toggle('is-active', btn.getAttribute('data-afcs-tab') === name);
      });
      panels.forEach(function (panel) {
        panel.classList.toggle('is-active', panel.getAttribute('data-afcs-tab-content') === name);
      });
    }

    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var name = btn.getAttribute('data-afcs-tab');
        if (name) {
          activateTab(name);
        }
      });
    });
  });

  var catalog = document.querySelector('[data-afcs-catalog]');
  if (catalog) {
    var searchInput = document.querySelector('[data-afcs-search]');
    var filterRace = document.querySelector('[data-afcs-filter="race"]');
    var filterClass = document.querySelector('[data-afcs-filter="class"]');
    var filterTheme = document.querySelector('[data-afcs-filter="theme"]');
    var cards = Array.prototype.slice.call(catalog.querySelectorAll('[data-afcs-card]'));

    function normalize(value) {
      return (value || '').toLowerCase();
    }

    function collectOptions(select, key) {
      var values = {};
      cards.forEach(function (card) {
        var val = card.getAttribute('data-' + key) || '';
        val = val.trim();
        if (val) {
          values[val] = true;
        }
      });
      Object.keys(values).sort().forEach(function (val) {
        var option = document.createElement('option');
        option.value = val;
        option.textContent = val;
        select.appendChild(option);
      });
    }

    if (filterRace) {
      collectOptions(filterRace, 'race');
    }
    if (filterClass) {
      collectOptions(filterClass, 'class');
    }
    if (filterTheme) {
      collectOptions(filterTheme, 'theme');
    }

    function applyFilters() {
      var query = normalize(searchInput ? searchInput.value : '');
      var race = normalize(filterRace ? filterRace.value : '');
      var klass = normalize(filterClass ? filterClass.value : '');
      var theme = normalize(filterTheme ? filterTheme.value : '');

      cards.forEach(function (card) {
        var name = normalize(card.getAttribute('data-name'));
        var cardRace = normalize(card.getAttribute('data-race'));
        var cardClass = normalize(card.getAttribute('data-class'));
        var cardTheme = normalize(card.getAttribute('data-theme'));

        var matches = true;
        if (query && name.indexOf(query) === -1) {
          matches = false;
        }
        if (race && cardRace !== race) {
          matches = false;
        }
        if (klass && cardClass !== klass) {
          matches = false;
        }
        if (theme && cardTheme !== theme) {
          matches = false;
        }
        card.style.display = matches ? '' : 'none';
      });
    }

    if (searchInput) {
      searchInput.addEventListener('input', applyFilters);
    }
    [filterRace, filterClass, filterTheme].forEach(function (select) {
      if (select) {
        select.addEventListener('change', applyFilters);
      }
    });
  }

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeModal();
    }
  });
})();

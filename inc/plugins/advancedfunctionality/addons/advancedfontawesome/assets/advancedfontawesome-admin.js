(function () {
  'use strict';

  if (window.__afAdvancedFontAwesomeAdminLoaded) return;
  window.__afAdvancedFontAwesomeAdminLoaded = true;

  function getConfig() {
    return (window.afAdvancedFontAwesomeAdminConfig && typeof window.afAdvancedFontAwesomeAdminConfig === 'object')
      ? window.afAdvancedFontAwesomeAdminConfig
      : {};
  }

  var iconListPromise = null;

  function parseIconsFromCss(cssText) {
    var re = /\.fa-([a-z0-9-]+):before/g;
    var seen = Object.create(null);
    var out = [];
    var match;
    while ((match = re.exec(cssText))) {
      var name = match[1];
      if (!name || seen[name]) continue;
      seen[name] = true;
      out.push('fa-' + name);
    }
    return out;
  }

  function loadIconList() {
    if (window.__afAfoAdminIconList) return Promise.resolve(window.__afAfoAdminIconList);
    if (iconListPromise) return iconListPromise;

    var cfg = getConfig();
    var cssUrl = String(cfg.cssUrl || '').trim();
    if (!cssUrl) {
      window.__afAfoAdminIconList = [];
      return Promise.resolve([]);
    }

    iconListPromise = fetch(cssUrl, { credentials: 'same-origin' })
      .then(function (resp) { return resp.text(); })
      .then(function (text) {
        var list = parseIconsFromCss(text);
        list.sort();
        window.__afAfoAdminIconList = list;
        return list;
      })
      .catch(function () {
        window.__afAfoAdminIconList = [];
        return [];
      });

    return iconListPromise;
  }

  function init() {
    var root = document.querySelector('.af-afo-admin');
    if (!root) return;

    var cfg = getConfig();
    var texts = cfg.texts || {};
    var defaultStyle = String(cfg.defaultStyle || 'fa-solid');
    var minSearchLength = Number(cfg.minSearchLength || 2);
    var maxResults = Number(cfg.maxResults || 400);

    var forumSearch = root.querySelector('[data-role="forum-search"]');
    var filterIcon = root.querySelector('[data-role="filter-icon"]');
    var rows = Array.prototype.slice.call(root.querySelectorAll('.af-afo-admin-row'));

    function updatePreview(row, value) {
      var box = row.querySelector('.af-afo-admin-preview-box');
      if (!box) return;
      box.innerHTML = value ? '<i class="' + value + '"></i>' : '';
    }

    function markDirty(row) {
      row.dataset.dirty = '1';
      row.classList.add('is-dirty');
    }

    function updateHasIcon(row, value) {
      row.dataset.hasicon = value ? '1' : '0';
    }

    rows.forEach(function (row) {
      var input = row.querySelector('.af-afo-admin-input');
      if (!input) return;
      updatePreview(row, input.value);
      updateHasIcon(row, input.value);
      input.addEventListener('input', function () {
        updatePreview(row, input.value);
        updateHasIcon(row, input.value);
        markDirty(row);
        applyFilters();
      });
    });

    function applyFilters() {
      var term = forumSearch ? String(forumSearch.value || '').trim().toLowerCase() : '';
      var onlyIcon = filterIcon ? filterIcon.checked : false;

      rows.forEach(function (row) {
        var name = String(row.dataset.name || '');
        var hasIcon = row.dataset.hasicon === '1';
        var matchName = !term || name.indexOf(term) !== -1;
        var show = matchName && (!onlyIcon || hasIcon);
        row.style.display = show ? '' : 'none';
      });
    }

    if (forumSearch) {
      forumSearch.addEventListener('input', applyFilters);
    }
    if (filterIcon) {
      filterIcon.addEventListener('change', applyFilters);
    }

    applyFilters();

    var pickerState = {
      overlay: null,
      searchInput: null,
      grid: null,
      count: null,
      empty: null,
      styleSelect: null,
      activeInput: null,
      activeRow: null,
    };

    function buildPicker() {
      if (pickerState.overlay) return;

      var overlay = document.querySelector('.af-afo-admin-picker-overlay');
      if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'af-afo-admin-picker-overlay';
      }

      var panel = document.createElement('div');
      panel.className = 'af-afo-admin-picker-panel';

      var header = document.createElement('div');
      header.className = 'af-afo-admin-picker-header';

      var title = document.createElement('strong');
      title.textContent = texts.pickerTitle || 'Выбор иконки';

      var close = document.createElement('button');
      close.type = 'button';
      close.className = 'button button_small';
      close.textContent = texts.close || 'Закрыть';
      close.addEventListener('click', function () {
        hidePicker();
      });

      header.appendChild(title);
      header.appendChild(close);

      var controls = document.createElement('div');
      controls.className = 'af-afo-admin-picker-controls';

      var search = document.createElement('input');
      search.type = 'text';
      search.className = 'af-afo-admin-picker-search';
      search.placeholder = texts.searchIconsPlaceholder || 'Поиск иконок...';
      controls.appendChild(search);

      var styles = Array.isArray(cfg.styles) && cfg.styles.length
        ? cfg.styles
        : [{ value: defaultStyle, label: defaultStyle }];

      var select = document.createElement('select');
      select.className = 'af-afo-admin-picker-style';
      styles.forEach(function (style) {
        var opt = document.createElement('option');
        opt.value = style.value;
        opt.textContent = style.label || style.value;
        if (style.value === defaultStyle) {
          opt.selected = true;
        }
        select.appendChild(opt);
      });
      controls.appendChild(select);

      var meta = document.createElement('div');
      meta.className = 'af-afo-admin-picker-meta';

      var count = document.createElement('span');
      count.className = 'af-afo-admin-picker-count';
      meta.appendChild(count);

      var empty = document.createElement('span');
      empty.className = 'af-afo-admin-picker-empty';
      meta.appendChild(empty);

      var grid = document.createElement('div');
      grid.className = 'af-afo-admin-picker-grid';

      panel.appendChild(header);
      panel.appendChild(controls);
      panel.appendChild(meta);
      panel.appendChild(grid);

      overlay.innerHTML = '';
      overlay.appendChild(panel);
      document.body.appendChild(overlay);

      overlay.addEventListener('click', function (event) {
        if (event.target === overlay) {
          hidePicker();
        }
      });

      pickerState.overlay = overlay;
      pickerState.searchInput = search;
      pickerState.grid = grid;
      pickerState.count = count;
      pickerState.empty = empty;
      pickerState.styleSelect = select;
    }

    function hidePicker() {
      if (!pickerState.overlay) return;
      pickerState.overlay.classList.remove('is-open');
      pickerState.overlay.setAttribute('aria-hidden', 'true');
      pickerState.activeInput = null;
      pickerState.activeRow = null;
    }

    function renderIconList(list, filter) {
      var term = String(filter || '').trim().toLowerCase();
      pickerState.grid.innerHTML = '';

      if (!term || term.length < minSearchLength) {
        pickerState.count.textContent = '';
        pickerState.empty.textContent = texts.typeMore || 'Введите 2+ символа';
        return;
      }

      var filtered = list.filter(function (icon) {
        return icon.indexOf(term) !== -1;
      });

      var limited = filtered.slice(0, maxResults);

      pickerState.count.textContent = (texts.found || 'Найдено') + ': ' + filtered.length;
      pickerState.empty.textContent = filtered.length ? '' : (texts.noResults || 'Иконки не найдены');

      limited.forEach(function (icon) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'af-afo-admin-picker-icon';
        btn.title = icon;
        btn.innerHTML = '<i class="' + (pickerState.styleSelect.value || defaultStyle) + ' ' + icon + '"></i>';
        btn.addEventListener('click', function () {
          if (!pickerState.activeInput || !pickerState.activeRow) return;
          var value = (pickerState.styleSelect.value || defaultStyle) + ' ' + icon;
          pickerState.activeInput.value = value;
          updatePreview(pickerState.activeRow, value);
          updateHasIcon(pickerState.activeRow, value);
          markDirty(pickerState.activeRow);
          applyFilters();
          hidePicker();
        });
        pickerState.grid.appendChild(btn);
      });
    }

    function openPicker(targetInput) {
      buildPicker();
      pickerState.activeInput = targetInput;
      pickerState.activeRow = targetInput.closest('.af-afo-admin-row');
      pickerState.overlay.classList.add('is-open');
      pickerState.overlay.setAttribute('aria-hidden', 'false');
      pickerState.searchInput.value = '';

      loadIconList().then(function (list) {
        renderIconList(list, pickerState.searchInput.value);
      });

      pickerState.searchInput.focus();
    }

    if (pickerState.overlay === null) {
      buildPicker();
    }

    root.addEventListener('click', function (event) {
      var target = event.target;
      if (!(target instanceof HTMLElement)) return;

      var action = target.getAttribute('data-action');
      if (!action) {
        var parentAction = target.closest('[data-action]');
        if (parentAction) {
          action = parentAction.getAttribute('data-action');
          target = parentAction;
        }
      }

      if (!action) return;
      var row = target.closest('.af-afo-admin-row');
      if (!row) return;
      var input = row.querySelector('.af-afo-admin-input');
      if (!input) return;

      if (action === 'pick') {
        openPicker(input);
        return;
      }

      if (action === 'clear') {
        input.value = '';
        updatePreview(row, '');
        updateHasIcon(row, '');
        markDirty(row);
        applyFilters();
      }
    });

    if (pickerState.searchInput) {
      pickerState.searchInput.addEventListener('input', function () {
        loadIconList().then(function (list) {
          renderIconList(list, pickerState.searchInput.value);
        });
      });
    }

    if (pickerState.styleSelect) {
      pickerState.styleSelect.addEventListener('change', function () {
        loadIconList().then(function (list) {
          renderIconList(list, pickerState.searchInput.value);
        });
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

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

  function buildRow() {
    var cfg = getConfig();
    var form = document.querySelector('form[action*="forum-management"]');
    if (!form) return;

    var table = form.querySelector('table.general');
    if (!table) return;

    var descriptionField = table.querySelector('#description');
    var anchorRow = descriptionField ? descriptionField.closest('tr') : table.querySelector('tr');

    var row = document.createElement('tr');
    row.className = anchorRow ? anchorRow.className : '';
    row.classList.add('af-fa-admin-row');

    var labelCell = document.createElement('td');
    labelCell.innerHTML = '<strong>' + (cfg.label || 'Icon') + '</strong>';

    var inputCell = document.createElement('td');

    var input = document.createElement('input');
    input.type = 'text';
    input.name = 'af_fa_icon';
    input.value = cfg.icon || '';
    input.className = 'af-fa-admin-input';

    var preview = document.createElement('span');
    preview.className = 'af-fa-admin-preview';

    var desc = document.createElement('div');
    desc.className = 'smalltext';
    desc.textContent = cfg.description || '';

    inputCell.appendChild(input);
    inputCell.appendChild(preview);
    if (cfg.description) {
      inputCell.appendChild(desc);
    }

    row.appendChild(labelCell);
    row.appendChild(inputCell);

    if (anchorRow && anchorRow.parentNode) {
      anchorRow.parentNode.insertBefore(row, anchorRow.nextSibling);
    } else {
      table.appendChild(row);
    }

    var picker = document.createElement('div');
    picker.className = 'af-fa-admin-picker';

    var search = document.createElement('input');
    search.type = 'text';
    search.className = 'af-fa-admin-search';
    search.placeholder = cfg.searchPlaceholder || 'Search icons...';
    picker.appendChild(search);

    var grid = document.createElement('div');
    grid.className = 'af-fa-admin-grid';
    picker.appendChild(grid);

    inputCell.appendChild(picker);

    function updatePreview(value) {
      preview.innerHTML = '';
      if (!value) return;
      preview.innerHTML = '<i class="' + value + '"></i>';
    }

    updatePreview(input.value);

    function render(list, filter) {
      grid.innerHTML = '';
      var term = String(filter || '').toLowerCase();

      list.forEach(function (icon) {
        if (term && icon.indexOf(term) === -1) return;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'af-fa-admin-icon';
        var className = 'fa-solid ' + icon;
        btn.innerHTML = '<i class="' + className + '"></i>';
        btn.addEventListener('click', function () {
          input.value = className;
          updatePreview(className);
        });
        grid.appendChild(btn);
      });
    }

    input.addEventListener('input', function () {
      updatePreview(input.value);
    });

    loadIconList().then(function (list) {
      render(list, '');
      search.addEventListener('input', function () {
        render(list, search.value);
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', buildRow);
  } else {
    buildRow();
  }
})();

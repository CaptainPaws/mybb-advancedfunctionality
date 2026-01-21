(function () {
  'use strict';

  if (window.__afAdvancedFontAwesomeLoaded) return;
  window.__afAdvancedFontAwesomeLoaded = true;

  function getConfig() {
    return (window.afAdvancedFontAwesomeConfig && typeof window.afAdvancedFontAwesomeConfig === 'object')
      ? window.afAdvancedFontAwesomeConfig
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
    if (window.__afAfoIconList) return Promise.resolve(window.__afAfoIconList);
    if (iconListPromise) return iconListPromise;

    var cfg = getConfig();
    var cssUrl = String(cfg.cssUrl || '').trim();
    if (!cssUrl) {
      window.__afAfoIconList = [];
      return Promise.resolve([]);
    }

    iconListPromise = fetch(cssUrl, { credentials: 'same-origin' })
      .then(function (resp) { return resp.text(); })
      .then(function (text) {
        var list = parseIconsFromCss(text);
        list.sort();
        window.__afAfoIconList = list;
        return list;
      })
      .catch(function () {
        window.__afAfoIconList = [];
        return [];
      });

    return iconListPromise;
  }

  function buildDropdown(editor, onSelect) {
    var wrap = document.createElement('div');
    wrap.className = 'af-fa-dropdown';

    var status = document.createElement('div');
    status.className = 'af-fa-status';
    status.textContent = 'Загрузка иконок...';
    wrap.appendChild(status);

    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'af-fa-search';
    input.placeholder = 'Поиск иконок...';
    wrap.appendChild(input);

    var grid = document.createElement('div');
    grid.className = 'af-fa-grid';
    wrap.appendChild(grid);

    function render(list, filter) {
      grid.innerHTML = '';
      var shown = 0;
      var term = String(filter || '').toLowerCase();

      list.forEach(function (icon) {
        if (term && icon.indexOf(term) === -1) return;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'af-fa-icon-btn';

        var className = 'fa-solid ' + icon;
        btn.setAttribute('data-icon', className);
        btn.innerHTML = '<i class="' + className + '"></i>';

        btn.addEventListener('click', function () {
          onSelect(className);
          if (editor && typeof editor.closeDropDown === 'function') {
            editor.closeDropDown(true);
          }
        });

        grid.appendChild(btn);
        shown++;
      });

      status.textContent = shown ? ('Иконок: ' + shown) : 'Ничего не найдено.';
    }

    loadIconList().then(function (list) {
      render(list, '');
      setTimeout(function () { input.focus(); }, 0);

      input.addEventListener('input', function () {
        render(list, input.value);
      });
    });

    return wrap;
  }

  function ensureCommandRegistered() {
    var $ = window.jQuery;
    if (!$ || !$.sceditor || !$.sceditor.command) return false;

    if ($.sceditor.command.get && $.sceditor.command.get('advancedfontawesome')) {
      return true;
    }

    $.sceditor.command.set('advancedfontawesome', {
      tooltip: 'Font Awesome',

      exec: function (caller) {
        var editor = this;
        var dd = buildDropdown(editor, function (icon) {
          var text = '[fa]' + icon + '[/fa]';
          if (typeof editor.insertText === 'function') editor.insertText(text);
          else if (typeof editor.insert === 'function') editor.insert(text);
        });
        editor.createDropDown(caller, 'advancedfontawesome', dd);
      },

      txtExec: function (caller) {
        var editor = this;
        var dd = buildDropdown(editor, function (icon) {
          editor.insertText('[fa]' + icon + '[/fa]');
        });
        editor.createDropDown(caller, 'advancedfontawesome', dd);
      }
    });

    return true;
  }

  function patchToolbarString(toolbar) {
    if (typeof toolbar !== 'string' || !toolbar) return toolbar;
    if (toolbar.indexOf('advancedfontawesome') !== -1) return toolbar;

    if (toolbar.indexOf('color') !== -1) {
      return toolbar.replace('color', 'color,advancedfontawesome');
    }
    return toolbar.replace(/\s+$/, '') + ',advancedfontawesome';
  }

  function hookSceditorCreate() {
    var $ = window.jQuery;
    if (!$ || !$.fn || typeof $.fn.sceditor !== 'function') return false;

    if (window.sceditor_options && typeof window.sceditor_options === 'object' && typeof window.sceditor_options.toolbar === 'string') {
      window.sceditor_options.toolbar = patchToolbarString(window.sceditor_options.toolbar);
    }

    if ($.fn.sceditor.__afAdvancedFontAwesomeWrapped) return true;

    var orig = $.fn.sceditor;

    var wrapped = function (options) {
      ensureCommandRegistered();
      try {
        if (options && typeof options === 'object' && typeof options.toolbar === 'string') {
          options.toolbar = patchToolbarString(options.toolbar);
        }
      } catch (e) {}
      return orig.apply(this, arguments);
    };

    wrapped.__afAdvancedFontAwesomeWrapped = true;
    $.fn.sceditor = wrapped;
    $.fn.sceditor.__afAdvancedFontAwesomeWrapped = true;

    return true;
  }

  function applyForumIcons() {
    var cfg = getConfig();
    var icons = cfg.icons || {};
    if (!icons || typeof icons !== 'object') return;

    var targets = [].slice.call(document.querySelectorAll('span.forum_status, div.subforumicon'));
    if (!targets.length) return;

    function findForumId(el) {
      var node = el;
      while (node && node !== document) {
        if (node.querySelector) {
          var link = node.querySelector('a[href*="forumdisplay.php?fid="]');
          if (link) {
            var href = link.getAttribute('href') || '';
            var match = href.match(/(?:\?|&)fid=(\d+)/);
            if (match) return match[1];
          }
        }
        node = node.parentElement || node.parentNode;
      }
      return null;
    }

    targets.forEach(function (el) {
      if (el.getAttribute('data-af-fa') === '1') return;
      var fid = findForumId(el);
      if (!fid || !icons[fid]) return;

      el.setAttribute('data-af-fa', '1');
      el.classList.add('af-fa-forum-icon');
      el.style.backgroundImage = 'none';
      el.innerHTML = '<i class="' + icons[fid] + '"></i>';
    });
  }

  function boot() {
    var okHook = hookSceditorCreate();
    var okCmd = ensureCommandRegistered();
    if (okHook || okCmd) {
      // no-op
    }
    applyForumIcons();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

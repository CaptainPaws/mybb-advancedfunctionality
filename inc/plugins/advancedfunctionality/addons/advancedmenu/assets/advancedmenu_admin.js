/* AdvancedMenu — ACP Icon Picker (Font Awesome) */
(function () {
  'use strict';

  if (window.__afAdvancedMenuAdminLoaded) return;
  window.__afAdvancedMenuAdminLoaded = true;

  function asText(x) { return String(x == null ? '' : x); }

  // Конфиг можно прокинуть из admin.php:
  // window.afAdvancedMenuIconPickerConfig = { cssUrl: 'https://.../all.min.css' }
  function getCfg() {
    var cfg = window.afAdvancedMenuIconPickerConfig;
    return (cfg && typeof cfg === 'object') ? cfg : {};
  }

  var iconListPromise = null;

  function parseIconsFromCss(cssText) {
    // ловим ".fa-house:before" из FA CSS
    var re = /\.fa-([a-z0-9-]+):before\b/g;
    var seen = Object.create(null);
    var out = [];
    var m;
    while ((m = re.exec(cssText))) {
      var name = m[1];
      if (!name || seen[name]) continue;
      seen[name] = true;
      out.push('fa-' + name);
    }
    out.sort();
    return out;
  }

  function guessFontAwesomeCssUrl() {
    var links = document.getElementsByTagName('link');
    for (var i = 0; i < links.length; i++) {
      var href = asText(links[i].getAttribute('href')).toLowerCase();
      if (href.indexOf('fontawesome') !== -1 || href.indexOf('font-awesome') !== -1) {
        return links[i].href;
      }
    }
    return '';
  }

  function loadIconList() {
    if (window.__afAmAdminIconList) return Promise.resolve(window.__afAmAdminIconList);
    if (iconListPromise) return iconListPromise;

    var cfg = getCfg();
    var cssUrl = asText(cfg.cssUrl || '').trim();
    if (!cssUrl) cssUrl = guessFontAwesomeCssUrl();

    if (!cssUrl) {
      window.__afAmAdminIconList = [];
      return Promise.resolve([]);
    }

    iconListPromise = fetch(cssUrl, { credentials: 'omit' })
      .then(function (r) { return r.text(); })
      .then(function (txt) {
        var list = parseIconsFromCss(txt);
        window.__afAmAdminIconList = list;
        return list;
      })
      .catch(function () {
        window.__afAmAdminIconList = [];
        return [];
      });

    return iconListPromise;
  }

  function normalizeToPreviewHtml(raw) {
    raw = asText(raw).trim();
    if (!raw) return '';

    // если админ руками вставил HTML — в превью не рендерим (безопасность/предсказуемость)
    if (raw.indexOf('<') !== -1 || raw.indexOf('>') !== -1) return '';

    // картинка по URL
    if (/^(https?:)?\/\//i.test(raw) || raw.charAt(0) === '/' || /\.(png|jpe?g|gif|svg|webp)$/i.test(raw)) {
      var safe = raw.replace(/'/g, "\\'");
      return '<span class="af-am-ico af-am-ico-img" style="--af-am-icon-url:url(\'' + safe + '\');" aria-hidden="true"></span>';
    }

    // FontAwesome классы
    if (/\bfa-[a-z0-9-]+\b/i.test(raw) || /\bfa(s|r|b)?\b/i.test(raw)) {
      if (!/\bfa-(solid|regular|brands|light|thin|duotone)\b/i.test(raw)) {
        raw = 'fa-solid ' + raw;
      }
      raw = raw.replace(/[^a-z0-9_\-\s]/gi, '').trim();
      return '<i class="' + raw + '" aria-hidden="true"></i>';
    }

    // текст/эмодзи
    var t = raw.replace(/[<>&]/g, '');
    return '<span class="af-am-icp-text" aria-hidden="true">' + t + '</span>';
  }

  function setPreview(previewEl, value) {
    if (!previewEl) return;
    previewEl.innerHTML = normalizeToPreviewHtml(value);
  }

  function openPicker(input) {
    if (!input) return;

    var overlay = document.createElement('div');
    overlay.className = 'af-am-icp-overlay';

    var modal = document.createElement('div');
    modal.className = 'af-am-icp-modal';

    var head = document.createElement('div');
    head.className = 'af-am-icp-head';

    var title = document.createElement('div');
    title.className = 'af-am-icp-title';
    title.textContent = 'Выбор иконки Font Awesome';
    head.appendChild(title);

    var search = document.createElement('input');
    search.type = 'text';
    search.className = 'af-am-icp-search';
    search.placeholder = 'Поиск… (house, user, discord, bell)';
    head.appendChild(search);

    var styleWrap = document.createElement('div');
    styleWrap.className = 'af-am-icp-style';

    var styles = [
      { key: 'fa-solid', label: 'Solid' },
      { key: 'fa-regular', label: 'Regular' },
      { key: 'fa-brands', label: 'Brands' }
    ];

    var activeStyle = 'fa-solid';

    function mkStyleBtn(s) {
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = s.label;
      b.setAttribute('data-style', s.key);
      if (s.key === activeStyle) b.classList.add('is-active');

      b.addEventListener('click', function () {
        activeStyle = s.key;
        var all = styleWrap.querySelectorAll('button[data-style]');
        for (var i = 0; i < all.length; i++) all[i].classList.remove('is-active');
        b.classList.add('is-active');
        renderGrid(window.__afAmAdminIconList || [], search.value);
      });

      return b;
    }

    styles.forEach(function (s) { styleWrap.appendChild(mkStyleBtn(s)); });
    head.appendChild(styleWrap);

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'af-am-icp-close';
    close.textContent = 'Закрыть';
    head.appendChild(close);

    modal.appendChild(head);

    var status = document.createElement('div');
    status.className = 'af-am-icp-status';
    status.textContent = 'Загрузка списка иконок…';
    modal.appendChild(status);

    var grid = document.createElement('div');
    grid.className = 'af-am-icp-grid';
    modal.appendChild(grid);

    function teardown() {
      try { document.body.removeChild(overlay); } catch (e) {}
      try { document.body.removeChild(modal); } catch (e2) {}
      document.removeEventListener('keydown', onKeydown);
    }

    function onKeydown(e) {
      if (e && e.key === 'Escape') teardown();
    }

    function onPick(iconName) {
      var cls = activeStyle + ' ' + iconName; // "fa-solid fa-house"
      input.value = cls;
      try { input.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
      teardown();
    }

    function renderGrid(list, filter) {
      var term = asText(filter || '').trim().toLowerCase();
      grid.innerHTML = '';

      var shown = 0;
      for (var i = 0; i < list.length; i++) {
        var icon = list[i];
        if (term && icon.indexOf(term) === -1) continue;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'af-am-icp-btn';
        btn.innerHTML = '<i class="' + activeStyle + ' ' + icon + '" aria-hidden="true"></i>';
        btn.addEventListener('click', (function (ic) {
          return function () { onPick(ic); };
        })(icon));

        grid.appendChild(btn);
        shown++;
      }

      status.textContent = shown ? ('Найдено: ' + shown) : 'Ничего не найдено.';
    }

    close.addEventListener('click', teardown);
    overlay.addEventListener('click', teardown);
    document.addEventListener('keydown', onKeydown);

    document.body.appendChild(overlay);
    document.body.appendChild(modal);

    loadIconList().then(function (list) {
      status.textContent = list.length
        ? ('Иконок в базе: ' + list.length)
        : 'Список пустой (не удалось загрузить CSS FontAwesome).';

      renderGrid(list, '');
      try { search.focus(); } catch (e) {}

      search.addEventListener('input', function () {
        renderGrid(list, search.value);
      });
    });
  }

  function findElements() {
    // input: либо явно помеченный, либо name=icon
    var input =
      document.querySelector('[data-af-am-icon-input="1"]') ||
      document.querySelector('input[name="icon"]');

    var pickBtn = document.querySelector('[data-af-am-iconpick="1"]');
    var clearBtn = document.querySelector('[data-af-am-iconclear="1"]');
    var preview = document.querySelector('[data-af-am-iconpreview="1"]');

    return { input: input, pickBtn: pickBtn, clearBtn: clearBtn, preview: preview };
  }

  function boot() {
    var els = findElements();
    if (!els.input || !els.pickBtn || !els.preview) return;

    setPreview(els.preview, els.input.value);

    els.input.addEventListener('input', function () {
      setPreview(els.preview, els.input.value);
    });

    els.pickBtn.addEventListener('click', function () {
      openPicker(els.input);
    });

    if (els.clearBtn) {
      els.clearBtn.addEventListener('click', function () {
        els.input.value = '';
        setPreview(els.preview, '');
        try { els.input.focus(); } catch (e) {}
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

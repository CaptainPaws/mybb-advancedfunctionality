(function () {
  'use strict';

  var APUI_CLASS_RE = /(^|\s)af-apui-/;
  var TAB_ROOT_SELECTOR = '[data-af-apui-tabs]';
  var POSTBIT_SELECTOR = '.af-apui-postbit';
  var POSTBIT_AUTHOR_SELECTOR = '.af-apui-postbit-author';
  var POSTBIT_USERDETAILS_SELECTOR = '.af-apui-postbit-userdetails';
  var STAT_ITEM_SELECTOR = '.af-apui-postbit-userdetails .af-apui-stat-item';
  var APUI_MODAL_OPENER_SELECTOR = [
    '.af-apui-profile-page [data-af-apui-modal-url]',
    '.af-apui-profile [data-af-apui-modal-url]',
    '.af-apui-member-profile [data-af-apui-modal-url]',
    '.af-apui-postbit [data-af-apui-modal-url]',
    TAB_ROOT_SELECTOR + ' [data-af-apui-modal-url]',
    '[data-af-apui-modal-owner="1"][data-af-apui-modal-url]',
    '[data-af-apui-owned-modal="1"][data-af-apui-modal-url]',
    '[data-af-apui-owned="1"][data-af-apui-modal-url]'
  ].join(', ');

  var modalState = {
    token: 0
  };

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function textOf(node) {
    return String(node && node.textContent ? node.textContent : '').replace(/\s+/g, ' ').trim();
  }

  function toArray(list) {
    return Array.prototype.slice.call(list || []);
  }

  function hasMeaningfulContent(node) {
    if (!node) {
      return false;
    }

    var clone = node.cloneNode(true);

    Array.prototype.forEach.call(
      clone.querySelectorAll('script, style, .af-apui-empty'),
      function (el) {
        if (el && el.parentNode) {
          el.parentNode.removeChild(el);
        }
      }
    );

    var html = clone.innerHTML
      .replace(/<!--[\s\S]*?-->/g, '')
      .replace(/<br\s*\/?>/gi, '')
      .replace(/&nbsp;/gi, '')
      .replace(/\s+/g, '');

    return html.length > 0;
  }

  function detectStatType(stat) {
    var labelEl = stat.querySelector('.af-apf-stat-label');
    var source = textOf(labelEl || stat).toLowerCase();

    if (!source) {
      return 'extra';
    }

    if (source.indexOf('зарегистр') !== -1 || source.indexOf('registered') !== -1) {
      return 'hidden';
    }

    if (source.indexOf('предупрежд') !== -1 || source.indexOf('warning') !== -1) {
      return 'hidden';
    }

    if (source.indexOf('сообщен') !== -1 || source.indexOf('posts') !== -1) {
      return 'posts';
    }

    if (source.indexOf('тем') !== -1 || source.indexOf('threads') !== -1) {
      return 'threads';
    }

    if (source.indexOf('репутац') !== -1 || source.indexOf('reputation') !== -1) {
      return 'reputation';
    }

    return 'extra';
  }

  function extractStatValue(stat) {
    var valueEl = stat.querySelector('.af-apf-stat-value');
    var raw = textOf(valueEl || stat);
    var match = raw.match(/[+\-]?\d[\d\s.,%]*/);
    return match ? match[0].trim() : raw;
  }

  function normalizePostbitUserDetails() {
    var wraps = document.querySelectorAll(POSTBIT_USERDETAILS_SELECTOR);
    if (!wraps.length) {
      return;
    }

    Array.prototype.forEach.call(wraps, function (wrap) {
      var stats = wrap.querySelectorAll('.af-apf-stat');
      if (!stats.length) {
        return;
      }

      Array.prototype.forEach.call(stats, function (stat) {
        stat.classList.remove(
          'af-apui-stat-posts',
          'af-apui-stat-threads',
          'af-apui-stat-reputation',
          'af-apui-stat-extra',
          'af-apui-stat-hidden'
        );

        stat.hidden = false;
        stat.style.display = '';

        var type = detectStatType(stat);

        if (type === 'hidden') {
          stat.classList.add('af-apui-stat-hidden');
          stat.hidden = true;
          stat.style.display = 'none';
          return;
        }

        if (type === 'posts' || type === 'threads' || type === 'reputation') {
          var number = extractStatValue(stat);
          var title = 'Сообщений';

          if (type === 'threads') {
            title = 'Тем';
          } else if (type === 'reputation') {
            title = 'Репутация';
          }

          stat.classList.add('af-apui-stat-' + type);
          stat.setAttribute('title', title);
          stat.innerHTML = '<span class="af-apui-stat-number">' + escapeHtml(number || '0') + '</span>';
          return;
        }

        stat.classList.add('af-apui-stat-extra');
      });
    });
  }

  function cleanupExtraPanel(root) {
    if (!root) {
      return;
    }

    var panel = root.querySelector('.af-apui-panel--extra');
    if (!panel) {
      return;
    }

    var adminCard = panel.querySelector('.af-apui-extra-admin-card');
    if (adminCard) {
      var adminBody = adminCard.querySelector('.af-apui-extra-admin-body');
      var adminHasContent = hasMeaningfulContent(adminBody);

      adminCard.hidden = !adminHasContent;
      adminCard.style.display = adminHasContent ? '' : 'none';
    }

    var actions = panel.querySelectorAll('.af-apui-extra-action');
    var visibleActions = 0;

    Array.prototype.forEach.call(actions, function (action) {
      var body = action.querySelector('.af-apui-extra-action__body');
      var hasContent = hasMeaningfulContent(body);

      action.hidden = !hasContent;
      action.style.display = hasContent ? '' : 'none';

      if (hasContent) {
        visibleActions++;
      }
    });

    var actionsCard = panel.querySelector('.af-apui-extra-actions-card');
    if (actionsCard) {
      var showActionsCard = visibleActions > 0;
      actionsCard.hidden = !showActionsCard;
      actionsCard.style.display = showActionsCard ? '' : 'none';
    }
  }

  function toggleEmptyState(panel) {
    var empty = panel.querySelector('.af-apui-empty');
    if (!empty) {
      return;
    }

    var clone = panel.cloneNode(true);
    var cloneEmpty = clone.querySelector('.af-apui-empty');
    if (cloneEmpty && cloneEmpty.parentNode) {
      cloneEmpty.parentNode.removeChild(cloneEmpty);
    }

    Array.prototype.forEach.call(
      clone.querySelectorAll('[hidden], [style*="display: none"]'),
      function (el) {
        if (el && el.parentNode) {
          el.parentNode.removeChild(el);
        }
      }
    );

    var hasContent = clone.innerHTML.replace(/\s+/g, '').length > 0;
    empty.style.display = hasContent ? 'none' : '';
  }

  function initTabs(root) {
    if (!root || root.__afApuiTabsInit) {
      return;
    }
    root.__afApuiTabsInit = true;

    var tabs = toArray(root.querySelectorAll('[data-tab]'));
    var panels = toArray(root.querySelectorAll('[data-panel]'));

    if (!tabs.length || !panels.length) {
      return;
    }

    function activate(name, updateHash) {
      tabs.forEach(function (tab) {
        var active = tab.getAttribute('data-tab') === name;
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.setAttribute('tabindex', active ? '0' : '-1');
      });

      panels.forEach(function (panel) {
        var active = panel.getAttribute('data-panel') === name;
        panel.classList.toggle('is-active', active);
        panel.hidden = !active;
        panel.style.display = active ? '' : 'none';

        if (active) {
          toggleEmptyState(panel);
        }
      });

      if (updateHash && window.history && typeof window.history.replaceState === 'function') {
        window.history.replaceState(null, '', '#af-tab-' + name);
      }
    }

    root.addEventListener('click', function (event) {
      var tab = event.target && event.target.closest ? event.target.closest('[data-tab]') : null;
      if (!tab || !root.contains(tab)) {
        return;
      }

      event.preventDefault();
      activate(tab.getAttribute('data-tab'), true);
    });

    root.addEventListener('keydown', function (event) {
      var current = event.target && event.target.closest ? event.target.closest('[data-tab]') : null;
      if (!current || !root.contains(current)) {
        return;
      }

      var index = tabs.indexOf(current);
      if (index === -1) {
        return;
      }

      var nextIndex = index;

      if (event.key === 'ArrowRight') {
        nextIndex = (index + 1) % tabs.length;
      } else if (event.key === 'ArrowLeft') {
        nextIndex = (index - 1 + tabs.length) % tabs.length;
      } else {
        return;
      }

      event.preventDefault();
      tabs[nextIndex].focus();
      activate(tabs[nextIndex].getAttribute('data-tab'), true);
    });

    var fromHash = (window.location.hash || '').replace('#af-tab-', '');
    var initial = tabs.some(function (tab) {
      return tab.getAttribute('data-tab') === fromHash;
    }) ? fromHash : 'info';

    activate(initial, false);
  }

  function ensureSharedModal() {
    var modal = document.querySelector('[data-af-apui-modal]');
    if (modal) {
      return {
        modal: modal,
        frame: modal.querySelector('[data-af-apui-modal-frame]'),
        title: modal.querySelector('[data-af-apui-modal-title]'),
        content: modal.querySelector('[data-af-apui-modal-content]')
      };
    }

    var wrap = document.createElement('div');
    wrap.className = 'af-cs-modal';
    wrap.setAttribute('data-af-apui-modal', '1');
    wrap.setAttribute('aria-hidden', 'true');
    wrap.innerHTML =
      '<div class="af-cs-modal__backdrop" data-af-apui-modal-close="1"></div>' +
      '<div class="af-cs-modal__dialog" role="dialog" aria-modal="true">' +
        '<div class="af-cs-modal__header">' +
          '<div class="af-cs-modal__title" data-af-apui-modal-title="1"></div>' +
          '<button type="button" class="af-cs-modal__close" data-af-apui-modal-close="1" aria-label="Закрыть">×</button>' +
        '</div>' +
        '<div class="af-cs-modal__body">' +
          '<div class="af-cs-modal__content" data-af-apui-modal-content="1" style="display:none;"></div>' +
          '<iframe class="af-cs-modal__frame" data-af-apui-modal-frame="1" src="about:blank" loading="lazy"></iframe>' +
        '</div>' +
      '</div>';

    document.body.appendChild(wrap);

    return {
      modal: wrap,
      frame: wrap.querySelector('[data-af-apui-modal-frame]'),
      title: wrap.querySelector('[data-af-apui-modal-title]'),
      content: wrap.querySelector('[data-af-apui-modal-content]')
    };
  }

  function normalizeModalUrl(url) {
    var raw = String(url || '').trim();
    if (!raw) {
      return '';
    }

    if (/action=af_charactersheet_api|action=cs_.*_ajax/i.test(raw)) {
      return '';
    }

    var hash = '';
    var hashIndex = raw.indexOf('#');

    if (hashIndex !== -1) {
      hash = raw.substring(hashIndex);
      raw = raw.substring(0, hashIndex);
    }

    var basePath = raw.split('?')[0].toLowerCase();

    if (/(^|\/)showthread\.php$/i.test(basePath)) {
      return raw + hash;
    }

    if (raw.indexOf('embed=1') === -1 && raw.indexOf('ajax=1') === -1) {
      raw += (raw.indexOf('?') === -1 ? '?' : '&') + 'ajax=1';
    }

    return raw + hash;
  }

  function normalizeFetchUrl(url) {
    var raw = String(url || '').trim();
    if (!raw) {
      return '';
    }

    var hashIndex = raw.indexOf('#');
    if (hashIndex !== -1) {
      raw = raw.substring(0, hashIndex);
    }

    return raw;
  }

  function setUniversalModalKind(modalParts, kind) {
    modalParts = modalParts || ensureSharedModal();

    var modal = modalParts && modalParts.modal ? modalParts.modal : null;
    if (!modal) {
      return;
    }

    var knownKinds = ['application', 'inventory', 'sheet', 'achievements', 'iframe'];
    var i;

    modal.removeAttribute('data-af-apui-modal-kind');
    modal.removeAttribute('data-af-modal-kind');

    for (i = 0; i < knownKinds.length; i++) {
      modal.classList.remove('af-cs-modal--' + knownKinds[i]);
    }

    kind = String(kind || '').trim().toLowerCase();
    if (!kind) {
      return;
    }

    modal.setAttribute('data-af-apui-modal-kind', kind);
    modal.setAttribute('data-af-modal-kind', kind);
    modal.classList.add('af-cs-modal--' + kind);
  }

  function isAjaxLikeUrl(url) {
    var raw = normalizeFetchUrl(url);
    if (!raw) {
      return false;
    }

    return /(?:^|[?&])(ajax=1|action=(?:af_charactersheet_api|cs_[^&]*_ajax))(?:&|$)/i.test(raw);
  }

  function clearUniversalModalState(modalParts) {
    modalParts = modalParts || ensureSharedModal();

    if (modalState.controller && typeof modalState.controller.abort === 'function') {
      modalState.controller.abort();
    }
    modalState.controller = null;

    setUniversalModalKind(modalParts, '');

    if (modalParts.frame) {
      modalParts.frame.onload = null;
      modalParts.frame.style.visibility = 'hidden';
      modalParts.frame.style.display = 'none';
      setFrameSrc(modalParts.frame, 'about:blank');
    }

    if (modalParts.content) {
      modalParts.content.innerHTML = '';
      modalParts.content.style.display = 'none';
    }
  }

  function absolutizeFragmentLinks(root, baseUrl) {
    if (!root || !root.querySelectorAll || !baseUrl) {
      return;
    }

    Array.prototype.forEach.call(root.querySelectorAll('[href], [src], [poster]'), function (el) {
      ['href', 'src', 'poster'].forEach(function (attr) {
        var value = el.getAttribute(attr);
        if (!value || /^(?:[a-z]+:|#|\/\/|data:|mailto:|tel:|javascript:)/i.test(value)) {
          return;
        }

        try {
          el.setAttribute(attr, new URL(value, baseUrl).toString());
        } catch (error) {
          return;
        }
      });

      if (el.tagName === 'IMG') {
        el.setAttribute('loading', 'lazy');
        el.setAttribute('decoding', 'async');
      }
    });
  }

  function extractApplicationFragment(htmlText, baseUrl, pid, selector) {
    var parser = new window.DOMParser();
    var doc = parser.parseFromString(String(htmlText || ''), 'text/html');
    var node = null;

    function querySafe(root, sel) {
      if (!root || !sel) {
        return null;
      }

      try {
        return root.querySelector(sel);
      } catch (error) {
        return null;
      }
    }

    function firstExisting(root, selectors) {
      var i;
      var found = null;

      for (i = 0; i < selectors.length; i++) {
        found = querySafe(root, selectors[i]);
        if (found) {
          return found;
        }
      }

      return null;
    }

    function resolveContentNode(root) {
      if (!root) {
        return null;
      }

      if (root.matches && root.matches(
        '.af-apui-postbit-message, .af-apui-postbit-content, .af-apui-message, .post_body, .post_content, .post_message, .postbit_message, .scaleimages'
      )) {
        return root;
      }

      return firstExisting(root, [
        '.af-apui-postbit-message',
        '.af-apui-postbit-content',
        '.af-apui-message',
        '.post_body',
        '.post_content',
        '.post_message',
        '.postbit_message',
        '.scaleimages'
      ]) || root;
    }

    if (selector) {
      node = querySafe(doc, selector);
    }

    if (!node && pid > 0) {
      node = firstExisting(doc, [
        '#post_' + pid,
        '[data-pid="' + pid + '"]',
        '#pid' + pid,
        'a[name="pid' + pid + '"]'
      ]);

      if (node && node.matches && node.matches('a[name], [id^="pid"]') && node.closest) {
        node = node.closest(
          '.af-apui-postbit, .post, .postbit, article, [id^="post_"], table, .tborder'
        ) || node;
      }
    }

    if (!node) {
      node = firstExisting(doc, [
        '.af-apui-postbit',
        '.post',
        '.postbit',
        'article[id^="post_"]',
        '[id^="post_"]',
        '.af-apui-postbit-message',
        '.af-apui-postbit-content',
        '.af-apui-message',
        '.post_body',
        '.post_content',
        '.post_message',
        '.postbit_message',
        '.scaleimages',
        '#content',
        'body'
      ]);
    }

    if (!node) {
      return '';
    }

    node = resolveContentNode(node);

    if (!node) {
      return '';
    }

    var clone = node.cloneNode(true);

    Array.prototype.forEach.call(
      clone.querySelectorAll('script, style, link[rel="stylesheet"], noscript, iframe'),
      function (el) {
        if (el && el.parentNode) {
          el.parentNode.removeChild(el);
        }
      }
    );

    absolutizeFragmentLinks(clone, baseUrl);

    var html = clone.innerHTML ? clone.innerHTML : '';
    if (!html && clone.outerHTML) {
      html = clone.outerHTML;
    }

    if (!html) {
      return '';
    }

    var ownerUid = parseInt(clone.getAttribute('data-af-apui-owner-uid') || clone.getAttribute('data-owner') || '0', 10) || 0;

    return '<div class="af-apui-application-fragment af-aa-context af-aa-context--application" data-af-apui-surface="application"' + (ownerUid > 0 ? ' data-af-apui-owner-uid="' + ownerUid + '"' : '') + '>' + html + '</div>';
  }

  function openApplicationModal(opener) {
    var rawFetchUrl = normalizeFetchUrl(
      opener.getAttribute('data-af-apui-fetch-url')
      || opener.getAttribute('data-af-apui-modal-url')
      || opener.getAttribute('href')
    );

    var fallbackUrl = normalizeFetchUrl(
      opener.getAttribute('href')
      || opener.getAttribute('data-af-apui-source-url')
      || rawFetchUrl
    );

    var fetchUrl = rawFetchUrl;

    if ((!fetchUrl || isAjaxLikeUrl(fetchUrl)) && fallbackUrl) {
      fetchUrl = fallbackUrl;
    }

    var selector = normalizeFetchUrl(opener.getAttribute('data-af-apui-fragment-selector'));
    var pid = parseInt(opener.getAttribute('data-af-apui-application-pid'), 10) || 0;
    var title = String(
      opener.getAttribute('data-af-apui-modal-title')
      || opener.getAttribute('title')
      || ''
    ).trim();

    if (!fetchUrl) {
      return;
    }

    var modalParts = ensureSharedModal();
    var token = ++modalState.token;

    modalParts.modal.classList.add('is-open');
    modalParts.modal.setAttribute('aria-hidden', 'false');
    setUniversalModalKind(modalParts, 'application');

    if (modalParts.title) {
      modalParts.title.textContent = title;
      modalParts.title.style.display = title ? '' : 'none';
    }

    clearUniversalModalState(modalParts);
    setUniversalModalKind(modalParts, 'application');

    if (!modalParts.content) {
      return;
    }

    modalParts.content.style.display = 'block';
    modalParts.content.innerHTML = '<div class="af-apui-modal-loading">Загрузка…</div>';

    modalState.controller = typeof window.AbortController === 'function'
      ? new window.AbortController()
      : null;

    var fetchOptions = {
      credentials: 'same-origin'
    };

    if (modalState.controller) {
      fetchOptions.signal = modalState.controller.signal;
    }

    window.fetch(fetchUrl, fetchOptions)
      .then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }

        return response.text();
      })
      .then(function (htmlText) {
        if (token !== modalState.token) {
          return;
        }

        var fragmentHtml = extractApplicationFragment(htmlText, fetchUrl, pid, selector);
        if (!fragmentHtml) {
          throw new Error('fragment_not_found');
        }

        modalParts.content.innerHTML = fragmentHtml;
        normalizeApplicationModalFragmentLayout(modalParts);
      })
      .catch(function (error) {
        if (token !== modalState.token) {
          return;
        }

        if (error && error.name === 'AbortError') {
          return;
        }

        modalParts.content.innerHTML =
          '<div class="af-apui-modal-error">' +
            '<p>Не удалось загрузить анкету в модалку.</p>' +
            (fallbackUrl
              ? '<p><a href="' + escapeHtml(fallbackUrl) + '">Открыть тему целиком</a></p>'
              : '') +
          '</div>';
      });
  }

  function normalizeApplicationModalFragmentLayout(modalParts) {
    if (!modalParts || !modalParts.modal || !modalParts.content) {
      return;
    }

    var kind = String(
      modalParts.modal.getAttribute('data-af-modal-kind')
      || modalParts.modal.getAttribute('data-af-apui-modal-kind')
      || ''
    ).trim().toLowerCase();

    if (kind !== 'application') {
      return;
    }

    var fragments = modalParts.content.querySelectorAll('.af-apui-application-fragment');
    if (!fragments.length) {
      return;
    }

    Array.prototype.forEach.call(fragments, function (fragment) {
      if (!fragment || !fragment.style) {
        return;
      }

      if (String(fragment.style.position || '').trim().toLowerCase() !== 'absolute') {
        return;
      }

      fragment.style.removeProperty('position');

      if (!String(fragment.getAttribute('style') || '').trim()) {
        fragment.removeAttribute('style');
      }
    });
  }

  function setFrameSrc(frame, value) {
    if (!frame) {
      return;
    }

    frame.setAttribute('src', value || 'about:blank');
  }

  function closeUniversalModal() {
    var modalParts = ensureSharedModal();
    modalState.token++;

    modalParts.modal.classList.remove('is-open');
    modalParts.modal.setAttribute('aria-hidden', 'true');

    if (modalParts.title) {
      modalParts.title.textContent = '';
      modalParts.title.style.display = 'none';
    }

    clearUniversalModalState(modalParts);
  }

  function defer(fn) {
    if (typeof window.requestAnimationFrame === 'function') {
      window.requestAnimationFrame(fn);
      return;
    }

    window.setTimeout(fn, 0);
  }

  function openUniversalModal(url, title, kind) {
    var loadUrl = normalizeModalUrl(url);
    if (!loadUrl) {
      return;
    }

    var modalParts = ensureSharedModal();
    var token = ++modalState.token;
    var modalKind = String(kind || 'iframe').trim().toLowerCase();

    modalParts.modal.classList.add('is-open');
    modalParts.modal.setAttribute('aria-hidden', 'false');

    if (modalParts.title) {
      var safeTitle = String(title || '').trim();
      modalParts.title.textContent = safeTitle;
      modalParts.title.style.display = safeTitle ? '' : 'none';
    }

    clearUniversalModalState(modalParts);
    setUniversalModalKind(modalParts, modalKind);

    if (!modalParts.frame) {
      return;
    }

    modalParts.frame.style.display = 'block';
    modalParts.frame.style.visibility = 'hidden';
    modalParts.frame.onload = function () {
      if (token !== modalState.token) {
        return;
      }

      if (modalParts.frame.getAttribute('src') === 'about:blank') {
        return;
      }

      modalParts.frame.style.visibility = '';
    };

    setFrameSrc(modalParts.frame, 'about:blank');

    defer(function () {
      if (token !== modalState.token) {
        return;
      }

      setFrameSrc(modalParts.frame, loadUrl);
    });
  }

  function isApuiOwnedNode(node) {
    while (node && node !== document && node !== document.documentElement) {
      if (node.nodeType !== 1) {
        node = node.parentNode;
        continue;
      }

      if (node.matches && (
        node.matches(TAB_ROOT_SELECTOR) ||
        node.matches(POSTBIT_SELECTOR) ||
        node.matches('.af-apui-profile-page') ||
        node.matches('.af-apui-profile') ||
        node.matches('.af-apui-member-profile') ||
        node.matches('[data-af-apui-modal-owner="1"], [data-af-apui-owned-modal="1"], [data-af-apui-owned="1"]')
      )) {
        return true;
      }

      if (node.className && APUI_CLASS_RE.test(String(node.className))) {
        return true;
      }

      node = node.parentNode;
    }

    return false;
  }

  function isApuiModalOpener(node) {
    if (!node || !node.matches || !node.matches('[data-af-apui-modal-url]')) {
      return false;
    }

    if (node.hasAttribute('data-af-apui-modal-owner') || node.hasAttribute('data-af-apui-owned-modal') || node.hasAttribute('data-af-apui-owned')) {
      return true;
    }

    return isApuiOwnedNode(node);
  }

  function initPostbitStatInteractions() {
    if (window.__afApuiPostbitStatInteractionsInit) {
      return;
    }

    var initialStats = document.querySelectorAll(STAT_ITEM_SELECTOR);
    if (!initialStats.length) {
      return;
    }

    window.__afApuiPostbitStatInteractionsInit = true;

    function ensureStatAccessibility(item) {
      if (!item) {
        return;
      }

      if (!item.hasAttribute('tabindex')) {
        item.setAttribute('tabindex', '0');
      }

      if (!item.hasAttribute('role')) {
        item.setAttribute('role', 'button');
      }

      if (!item.hasAttribute('aria-expanded')) {
        item.setAttribute('aria-expanded', 'false');
      }
    }

    function prepareStatsAccessibility(root) {
      var base = root && root.querySelectorAll ? root : document;
      Array.prototype.forEach.call(base.querySelectorAll(STAT_ITEM_SELECTOR), ensureStatAccessibility);
    }

    function closeAllStats(exceptNode) {
      Array.prototype.forEach.call(document.querySelectorAll(STAT_ITEM_SELECTOR + '.is-open'), function (item) {
        if (exceptNode && item === exceptNode) {
          return;
        }

        item.classList.remove('is-open');
        item.setAttribute('aria-expanded', 'false');
      });
    }

    prepareStatsAccessibility(document);

    document.addEventListener('click', function (event) {
      var target = event.target;
      var interactiveInsideValue = target && target.closest ? target.closest(
        '.af-apui-stat-item__value a, .af-apui-stat-item__value button, .af-apui-stat-item__value input, .af-apui-stat-item__value select, .af-apui-stat-item__value textarea, .af-apui-stat-item__value label'
      ) : null;

      if (interactiveInsideValue) {
        return;
      }

      var stat = target && target.closest ? target.closest(STAT_ITEM_SELECTOR) : null;

      if (!stat) {
        closeAllStats(null);
        return;
      }

      ensureStatAccessibility(stat);

      var opened = stat.classList.contains('is-open');
      closeAllStats(stat);

      if (opened) {
        stat.classList.remove('is-open');
        stat.setAttribute('aria-expanded', 'false');
        return;
      }

      stat.classList.add('is-open');
      stat.setAttribute('aria-expanded', 'true');
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeAllStats(null);
        return;
      }

      var target = event.target;
      var stat = target && target.closest ? target.closest(STAT_ITEM_SELECTOR) : null;
      if (!stat) {
        return;
      }

      ensureStatAccessibility(stat);

      if (event.key !== 'Enter' && event.key !== ' ') {
        return;
      }

      event.preventDefault();

      var opened = stat.classList.contains('is-open');
      closeAllStats(stat);

      if (opened) {
        stat.classList.remove('is-open');
        stat.setAttribute('aria-expanded', 'false');
        return;
      }

      stat.classList.add('is-open');
      stat.setAttribute('aria-expanded', 'true');
    });

    document.addEventListener('focusin', function (event) {
      var target = event.target;
      var stat = target && target.closest ? target.closest(STAT_ITEM_SELECTOR) : null;
      if (stat) {
        ensureStatAccessibility(stat);
      }
    });
  }

  function initPostbitModalActions() {
    if (window.__afApuiPostbitModalInit) {
      return;
    }

    var hasApuiOpener = document.querySelector(APUI_MODAL_OPENER_SELECTOR);

    if (!hasApuiOpener) {
      return;
    }

    window.__afApuiPostbitModalInit = true;

    document.addEventListener('click', function (event) {
      var target = event.target;

      if (target && target.closest && target.closest('[data-af-apui-modal-close]')) {
        closeUniversalModal();
        return;
      }

      var opener = target && target.closest ? target.closest('[data-af-apui-modal-url]') : null;
      if (!isApuiModalOpener(opener)) {
        return;
      }

      if (opener.hasAttribute('data-afcs-open') || opener.hasAttribute('data-afcs-sheet')) {
        return;
      }

      event.preventDefault();

      var modalKind = String(opener.getAttribute('data-af-apui-modal-kind') || 'iframe').toLowerCase();

      if (modalKind === 'application') {
        openApplicationModal(opener);
        return;
      }

      openUniversalModal(
        opener.getAttribute('data-af-apui-modal-url'),
        opener.getAttribute('data-af-apui-modal-title'),
        modalKind
      );
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        var modal = document.querySelector('[data-af-apui-modal].is-open');
        if (modal) {
          closeUniversalModal();
        }
      }
    });
  }

  function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
  }

  function initStickyPostbits() {
    if (window.__afApuiStickyPostbitsInit) {
      return;
    }

    var rows = toArray(document.querySelectorAll(POSTBIT_SELECTOR));
    if (!rows.length) {
      return;
    }

    var items = [];
    var stickyTop = 12;
    var rafId = 0;
    var refreshTimer = 0;

    function setAuthorTranslate(item, translateY) {
      if (translateY === item.lastTranslate) {
        return;
      }

      item.lastTranslate = translateY;

      if (!translateY) {
        item.author.style.removeProperty('transform');
        item.author.style.removeProperty('will-change');
        return;
      }

      item.author.style.willChange = 'transform';
      item.author.style.transform = 'translate3d(0, ' + translateY + 'px, 0)';
    }

    function collectRows() {
      items = toArray(document.querySelectorAll(POSTBIT_SELECTOR)).map(function (row) {
        var author = row.querySelector(POSTBIT_AUTHOR_SELECTOR);
        if (!author) {
          return null;
        }

        return {
          row: row,
          author: author,
          lastTranslate: null
        };
      }).filter(function (item) {
        return !!item;
      });
    }

    function updateOne(item) {
      if (!item || !item.row || !item.author) {
        return;
      }

      if (window.innerWidth <= 1024) {
        setAuthorTranslate(item, 0);
        return;
      }

      var rowHeight = item.row.offsetHeight;
      var authorHeight = item.author.offsetHeight;

      if (!rowHeight || !authorHeight) {
        setAuthorTranslate(item, 0);
        return;
      }

      if (rowHeight <= authorHeight + stickyTop + 12) {
        setAuthorTranslate(item, 0);
        return;
      }

      var scrollTop = window.pageYOffset || document.documentElement.scrollTop || 0;
      var rowRect = item.row.getBoundingClientRect();
      var rowTop = scrollTop + rowRect.top;
      var maxTranslate = Math.max(0, rowHeight - authorHeight);
      var wantedTranslate = scrollTop + stickyTop - rowTop;
      var translateY = clamp(wantedTranslate, 0, maxTranslate);

      setAuthorTranslate(item, translateY);
    }

    function updateAll() {
      rafId = 0;

      if (!items.length) {
        return;
      }

      items.forEach(updateOne);
    }

    function scheduleUpdate() {
      if (rafId) {
        return;
      }

      if (typeof window.requestAnimationFrame === 'function') {
        rafId = window.requestAnimationFrame(updateAll);
        return;
      }

      rafId = window.setTimeout(updateAll, 16);
    }

    function scheduleRefresh() {
      if (refreshTimer) {
        window.clearTimeout(refreshTimer);
      }

      refreshTimer = window.setTimeout(function () {
        refreshTimer = 0;
        collectRows();
        scheduleUpdate();
      }, 80);
    }

    collectRows();
    if (!items.length) {
      return;
    }

    window.__afApuiStickyPostbitsInit = true;

    window.addEventListener('scroll', scheduleUpdate, { passive: true });
    window.addEventListener('resize', scheduleRefresh);
    window.addEventListener('load', scheduleRefresh);
    window.addEventListener('orientationchange', scheduleRefresh);

    scheduleUpdate();
    window.setTimeout(scheduleRefresh, 180);
  }

  function boot() {
    var hasTabs = !!document.querySelector(TAB_ROOT_SELECTOR);
    var hasPostbitUserDetails = !!document.querySelector(POSTBIT_USERDETAILS_SELECTOR);
    var hasPostbits = !!document.querySelector(POSTBIT_SELECTOR);
    var hasApuiModalOpeners = !!document.querySelector(APUI_MODAL_OPENER_SELECTOR);

    if (hasPostbitUserDetails) {
      normalizePostbitUserDetails();
      initPostbitStatInteractions();
    }

    if (hasApuiModalOpeners) {
      initPostbitModalActions();
    }

    if (hasPostbits) {
      initStickyPostbits();
    }

    if (!hasTabs) {
      return;
    }

    Array.prototype.forEach.call(document.querySelectorAll(TAB_ROOT_SELECTOR), function (root) {
      cleanupExtraPanel(root);
      initTabs(root);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();

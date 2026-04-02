(function () {
  if (typeof document === 'undefined') return;

  var body = document.body;
  if (!body || !body.classList.contains('af-rwd-enabled')) return;

  var state = {
    initialized: false,
    drawerOpen: false,
    drawerId: 'af-rwd-right-menu',
    triggerId: 'af-rwd-right-trigger',
    mainNavHostId: 'af-rwd-main-nav-host',
    mainNavBarClass: 'af-rwd-main-nav-bar',
    mainNavShellClass: 'af-rwd-main-nav-shell',
    placeholderClass: 'af-rwd-main-nav-placeholder',
    extraPlaceholderClass: 'af-rwd-extra-menu-placeholder',
    listenersBound: false
  };

  function cssVarInt(name, fallback) {
    var raw = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    var num = parseInt(raw, 10);
    return Number.isFinite(num) && num > 0 ? num : fallback;
  }

  function isMobileViewport() {
    return window.matchMedia('(max-width: 767.98px)').matches;
  }

  function isTabletViewport() {
    return window.matchMedia('(min-width: 768px) and (max-width: 1024px)').matches;
  }

  function updateViewportClasses() {
    body.classList.toggle('af-rwd-viewport-mobile', isMobileViewport());
    body.classList.toggle('af-rwd-viewport-tablet', isTabletViewport());
  }

  function normalizeLabel(text) {
    return (text || '').replace(/\s+/g, ' ').trim();
  }

  function buildHeaderMap(table) {
    var headers = [];
    table.querySelectorAll('thead th, tr.thead th').forEach(function (th) {
      headers.push(normalizeLabel(th.textContent));
    });
    return headers;
  }

  function markTableAsCards(table, force) {
    var enableCards = isMobileViewport() || !!force;
    table.classList.toggle('af-rwd-mobile-cards', enableCards);
    table.classList.toggle('af-rwd-tablet-compact', isTabletViewport());
    if (!enableCards) return;

    var headers = buildHeaderMap(table);
    table.querySelectorAll('tbody tr, tr.inline_row, tr[id^="thread_"], tr[id^="forum_"], tr[id^="pm_"]').forEach(function (row) {
      if (row.querySelector('th')) return;
      row.querySelectorAll('td').forEach(function (cell, index) {
        if (cell.dataset.afRwdLabel) return;
        var label = headers[index] || cell.getAttribute('data-label') || '';
        if (label) cell.dataset.afRwdLabel = normalizeLabel(label);
      });
    });
  }

  function adaptCoreTables() {
    var selectors = [
      'body.af-rwd-userlist table',
      'body.af-rwd-postsactivity table',
      'body.af-rwd-private table.pm_table',
      'body.af-rwd-search table',
      'body.af-rwd-usercp table',
      'body.af-rwd-gallery table',
      'body.af-rwd-kb table',
      'body.af-rwd-shop table',
      'body.af-rwd-charactersheets table',
      'body.af-rwd-forumdisplay table#threads',
      'body.af-rwd-index table#forums'
    ];

    document.querySelectorAll(selectors.join(',')).forEach(function (table) {
      markTableAsCards(table);
    });
  }

  function wrapNarrowTables() {
    document.querySelectorAll('body.af-rwd-enabled table').forEach(function (table) {
      if (table.closest('.af-rwd-table-wrap')) return;
      if (table.classList.contains('af-rwd-mobile-cards')) return;
      if (!isTabletViewport()) return;

      var parent = table.parentElement;
      if (!parent) return;
      var wrap = document.createElement('div');
      wrap.className = 'af-rwd-table-wrap';
      parent.insertBefore(wrap, table);
      wrap.appendChild(table);
    });
  }

  function ensureMainNavHost() {
    var host = document.getElementById(state.mainNavHostId);
    if (host) return host;

    host = document.createElement('div');
    host.id = state.mainNavHostId;
    host.className = 'af-rwd-main-nav-host';
    host.hidden = true;
    host.setAttribute('role', 'navigation');
    host.setAttribute('aria-label', 'Main forum navigation');
    body.appendChild(host);
    return host;
  }

  function ensureMainNavBar(host) {
    var bar = host.querySelector('.' + state.mainNavBarClass);
    if (bar) return bar;

    bar = document.createElement('div');
    bar.className = state.mainNavBarClass;

    var left = document.createElement('button');
    left.type = 'button';
    left.className = 'af-rwd-main-nav-scroll af-rwd-main-nav-scroll--left';
    left.setAttribute('aria-label', 'Scroll menu left');
    left.textContent = '‹';

    var shell = document.createElement('div');
    shell.className = state.mainNavShellClass;

    var actions = document.createElement('div');
    actions.className = 'af-rwd-main-nav-actions';

    var right = document.createElement('button');
    right.type = 'button';
    right.className = 'af-rwd-main-nav-scroll af-rwd-main-nav-scroll--right';
    right.setAttribute('aria-label', 'Scroll menu right');
    right.textContent = '›';

    bar.appendChild(left);
    bar.appendChild(shell);
    bar.appendChild(actions);
    bar.appendChild(right);
    host.appendChild(bar);

    function scrollByDelta(delta) {
      shell.scrollBy({ left: delta, behavior: 'smooth' });
    }

    left.addEventListener('click', function () { scrollByDelta(-160); });
    right.addEventListener('click', function () { scrollByDelta(160); });
    shell.addEventListener('scroll', function () { updateNavArrowState(shell, left, right); }, { passive: true });

    return bar;
  }

  function updateNavArrowState(shell, left, right) {
    var max = Math.max(0, shell.scrollWidth - shell.clientWidth);
    left.disabled = shell.scrollLeft <= 4;
    right.disabled = shell.scrollLeft >= (max - 4);
  }

  function mountMainMenu() {
    if (!body.classList.contains('af-rwd-main-nav-sticky')) return;

    var menu = document.querySelector(
      '#header ul.menu.top_links, #header .menu.top_links, .top_links ul.menu, nav ul.menu.top_links, #panel ul.menu.top_links'
    );
    if (!menu) return;

    var host = ensureMainNavHost();
    var bar = ensureMainNavBar(host);
    var shell = bar.querySelector('.' + state.mainNavShellClass);

    if (!isMobileViewport()) {
      restoreMainMenu(menu, host);
      return;
    }

    if (!menu.classList.contains('af-rwd-main-nav-live')) {
      var ph = document.createElement('span');
      ph.className = state.placeholderClass;
      ph.hidden = true;
      menu.parentNode.insertBefore(ph, menu);
    }

    menu.classList.add('af-rwd-main-nav-live');
    shell.appendChild(menu);
    host.hidden = false;
    body.classList.add('af-rwd-main-nav-mounted');

    var hostH = host.getBoundingClientRect().height || 56;
    document.documentElement.style.setProperty('--af-rwd-main-nav-offset', Math.ceil(hostH) + 'px');

    var left = bar.querySelector('.af-rwd-main-nav-scroll--left');
    var right = bar.querySelector('.af-rwd-main-nav-scroll--right');
    updateNavArrowState(shell, left, right);
  }

  function restoreMainMenu(menu, host) {
    if (!menu || !menu.classList.contains('af-rwd-main-nav-live')) {
      if (host) host.hidden = true;
      body.classList.remove('af-rwd-main-nav-mounted');
      return;
    }

    var placeholder = document.querySelector('.' + state.placeholderClass);
    if (placeholder && placeholder.parentNode) {
      placeholder.parentNode.insertBefore(menu, placeholder);
      placeholder.remove();
    }

    menu.classList.remove('af-rwd-main-nav-live');
    body.classList.remove('af-rwd-main-nav-mounted');
    if (host) host.hidden = true;
  }

  function getExtraNavNode() {
    var lower = document.querySelector('#panel .lower, .panel .lower, #header .lower, .menu.panel_links, .top_links + .lower');
    if (!lower) return null;
    if (lower.closest('#' + state.drawerId)) return null;
    return lower;
  }

  function ensureRightMenu(host) {
    var bar = host.querySelector('.' + state.mainNavBarClass);
    if (!bar) return null;

    var actions = bar.querySelector('.af-rwd-main-nav-actions');
    var trigger = document.getElementById(state.triggerId);
    if (!trigger) {
      trigger = document.createElement('button');
      trigger.type = 'button';
      trigger.id = state.triggerId;
      trigger.className = 'af-rwd-right-trigger';
      trigger.setAttribute('aria-label', 'Open extra menu');
      trigger.setAttribute('aria-controls', state.drawerId);
      trigger.setAttribute('aria-expanded', 'false');
      trigger.innerHTML = '<span class="af-rwd-right-trigger__icon" aria-hidden="true"></span>';
    }
    if (actions && !actions.contains(trigger)) actions.appendChild(trigger);

    var overlay = document.querySelector('.af-rwd-extra-overlay');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.className = 'af-rwd-extra-overlay';
      overlay.hidden = true;
      body.appendChild(overlay);
    }

    var drawer = document.getElementById(state.drawerId);
    if (!drawer) {
      drawer = document.createElement('aside');
      drawer.id = state.drawerId;
      drawer.className = 'af-rwd-right-menu';
      drawer.hidden = true;
      drawer.setAttribute('aria-hidden', 'true');
      drawer.setAttribute('aria-label', 'Extra navigation menu');
      body.appendChild(drawer);
    }

    return { trigger: trigger, overlay: overlay, drawer: drawer };
  }

  function moveExtraMenu(shell) {
    var lower = getExtraNavNode();
    if (!lower) return;

    if (!isMobileViewport()) {
      restoreExtraMenu();
      return;
    }

    if (!lower.classList.contains('af-rwd-extra-menu-live')) {
      var ph = document.createElement('span');
      ph.className = state.extraPlaceholderClass;
      ph.hidden = true;
      lower.parentNode.insertBefore(ph, lower);
    }

    lower.classList.add('af-rwd-extra-menu-live');
    shell.drawer.appendChild(lower);
  }

  function restoreExtraMenu() {
    var lower = document.querySelector('#' + state.drawerId + ' .lower.af-rwd-extra-menu-live');
    if (!lower) return;
    var placeholder = document.querySelector('.' + state.extraPlaceholderClass);
    if (placeholder && placeholder.parentNode) {
      placeholder.parentNode.insertBefore(lower, placeholder);
      placeholder.remove();
    }
    lower.classList.remove('af-rwd-extra-menu-live');
  }

  function setRightMenuState(open, shell) {
    state.drawerOpen = !!open;
    body.classList.toggle('af-rwd-right-menu-open', state.drawerOpen);
    shell.trigger.setAttribute('aria-expanded', state.drawerOpen ? 'true' : 'false');
    shell.drawer.setAttribute('aria-hidden', state.drawerOpen ? 'false' : 'true');
    shell.overlay.hidden = !state.drawerOpen;
    shell.drawer.hidden = !state.drawerOpen;
  }

  function setupMenus() {
    var host = ensureMainNavHost();
    mountMainMenu();

    if (!body.classList.contains('af-rwd-right-burger')) return;

    var shell = ensureRightMenu(host);
    if (!shell) return;
    moveExtraMenu(shell);

    if (!state.listenersBound) {
      state.listenersBound = true;

      shell.trigger.addEventListener('click', function () {
        if (!isMobileViewport()) return;
        setRightMenuState(!state.drawerOpen, shell);
      });

      shell.overlay.addEventListener('click', function () { setRightMenuState(false, shell); });

      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') setRightMenuState(false, shell);
      });

      document.addEventListener('click', function (event) {
        if (!state.drawerOpen) return;
        if (shell.trigger.contains(event.target) || shell.drawer.contains(event.target)) return;
        setRightMenuState(false, shell);
      });
    }

    window.addEventListener('resize', function () {
      updateViewportClasses();
      mountMainMenu();
      moveExtraMenu(shell);
      if (!isMobileViewport()) setRightMenuState(false, shell);
      adaptCoreTables();
      wrapNarrowTables();
    }, { passive: true });
  }

  function stabilizeAvatars() {
    if (!isMobileViewport()) return;

    document.querySelectorAll('.post .author_avatar img, .af-apui-postbit-avatar-shell img').forEach(function (img) {
      img.style.width = '86px';
      img.style.height = '86px';
      img.style.objectFit = 'cover';
    });

    document.querySelectorAll('#panel .avatar img, #panel .user-avatar img').forEach(function (img) {
      img.style.width = '34px';
      img.style.height = '34px';
      img.style.objectFit = 'cover';
    });
  }

  function fixPostbitFlow() {
    if (!isMobileViewport()) return;
    document.querySelectorAll('.post.classic, .af-apui-postbit').forEach(function (post) {
      post.style.overflow = 'visible';
    });
    document.querySelectorAll('.post_author, .af-apui-postbit-author').forEach(function (author) {
      author.style.minWidth = '0';
    });
  }

  function tuneMobileEditor() {
    if (!isMobileViewport()) return;
    document.querySelectorAll('.sceditor-container, #quickreply_e, #quickreply').forEach(function (editor) {
      editor.style.minWidth = '0';
    });
  }

  function syncModalClass() {
    var hasModal = !!document.querySelector('.modal, .af-modal, .af-cs-modal, .af-apui-modal, .af-shop-modal__dialog');
    body.classList.toggle('af-rwd-modal-surface', hasModal);
  }

  function initOnce() {
    if (state.initialized) return;
    state.initialized = true;

    updateViewportClasses();
    setupMenus();
    adaptCoreTables();
    wrapNarrowTables();
    stabilizeAvatars();
    fixPostbitFlow();
    tuneMobileEditor();
    syncModalClass();

    var observer = new MutationObserver(function (mutations) {
      var changed = mutations.some(function (m) { return m.addedNodes && m.addedNodes.length; });
      if (!changed) return;
      updateViewportClasses();
      mountMainMenu();
      adaptCoreTables();
      wrapNarrowTables();
      stabilizeAvatars();
      fixPostbitFlow();
      tuneMobileEditor();
      syncModalClass();
    });

    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initOnce);
  } else {
    initOnce();
  }
})();

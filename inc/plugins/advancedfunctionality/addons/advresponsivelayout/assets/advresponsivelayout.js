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
    mainNavPlaceholderClass: 'af-rwd-main-nav-placeholder',
    extraMenuPlaceholderClass: 'af-rwd-extra-menu-placeholder'
  };

  function cssVarInt(name, fallback) {
    var value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    var parsed = parseInt(value, 10);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
  }

  function isMobileHeaderViewport() {
    var bp = cssVarInt('--af-rwd-mobile-header-breakpoint', 900);
    return window.matchMedia('(max-width: ' + bp + 'px)').matches;
  }

  function wrapWideTables(root) {
    if (!body.classList.contains('af-rwd-table-wrap-on')) return;
    var scope = root || document;
    var tables = scope.querySelectorAll('table');

    tables.forEach(function (table) {
      if (table.closest('.af-rwd-table-wrap')) return;
      if (table.closest('.sceditor-container, .sceditor-group, .CodeMirror')) return;
      if (table.matches('.af-rwd-no-wrap, [data-af-rwd-no-wrap]')) return;
      var parent = table.parentElement;
      if (!parent) return;

      var wrapper = document.createElement('div');
      wrapper.className = 'af-rwd-table-wrap';
      parent.insertBefore(wrapper, table);
      wrapper.appendChild(table);
    });
  }

  function syncModalClass() {
    if (!body.classList.contains('af-rwd-modal-fixes-on')) return;
    var modalNode = document.querySelector('.modal, .af-modal, .af-cs-modal, .af-apui-modal, [data-af-apui-surface]');
    body.classList.toggle('af-rwd-modal-surface', !!modalNode);
  }

  function ensureMainNavHost() {
    var host = document.getElementById(state.mainNavHostId);
    if (host) return host;

    host = document.createElement('div');
    host.id = state.mainNavHostId;
    host.className = 'af-rwd-main-nav-host';
    host.setAttribute('role', 'navigation');
    host.setAttribute('aria-label', 'Main forum navigation');
    host.hidden = true;
    body.appendChild(host);
    return host;
  }

  function moveMainMenuForMobile() {
    if (!body.classList.contains('af-rwd-main-nav-sticky')) return;
    var menu = document.querySelector('#header ul.menu.top_links, #header .menu.top_links');
    if (!menu) return;

    var host = ensureMainNavHost();

    if (isMobileHeaderViewport()) {
      if (!menu.classList.contains('af-rwd-main-nav-live')) {
        var ph = document.createElement('span');
        ph.className = state.mainNavPlaceholderClass;
        ph.hidden = true;
        menu.parentNode.insertBefore(ph, menu);
      }
      menu.classList.add('af-rwd-main-nav-live');
      host.hidden = false;
      host.appendChild(menu);
      body.classList.add('af-rwd-main-nav-mounted');
      return;
    }

    if (!menu.classList.contains('af-rwd-main-nav-live')) return;

    var placeholder = document.querySelector('.' + state.mainNavPlaceholderClass);
    if (placeholder && placeholder.parentNode) {
      placeholder.parentNode.insertBefore(menu, placeholder);
      placeholder.remove();
    }
    menu.classList.remove('af-rwd-main-nav-live');
    body.classList.remove('af-rwd-main-nav-mounted');
    host.hidden = true;
  }

  function getExtraNavNode() {
    var lower = document.querySelector('#panel .lower');
    if (!lower) return null;
    if (lower.closest('#' + state.drawerId)) return null;
    return lower;
  }

  function ensureRightMenuShell() {
    var trigger = document.getElementById(state.triggerId);
    if (!trigger) {
      trigger = document.createElement('button');
      trigger.type = 'button';
      trigger.id = state.triggerId;
      trigger.className = 'af-rwd-right-trigger';
      trigger.setAttribute('aria-expanded', 'false');
      trigger.setAttribute('aria-label', 'Open extra menu');
      trigger.setAttribute('aria-controls', state.drawerId);
      trigger.innerHTML = '<span class="af-rwd-right-trigger__icon" aria-hidden="true"></span>';
      document.body.appendChild(trigger);
    }

    var overlay = document.querySelector('.af-rwd-extra-overlay');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.className = 'af-rwd-extra-overlay';
      overlay.hidden = true;
      document.body.appendChild(overlay);
    }

    var drawer = document.getElementById(state.drawerId);
    if (!drawer) {
      drawer = document.createElement('aside');
      drawer.id = state.drawerId;
      drawer.className = 'af-rwd-right-menu';
      drawer.hidden = true;
      drawer.setAttribute('aria-hidden', 'true');
      drawer.setAttribute('aria-label', 'Extra navigation menu');
      document.body.appendChild(drawer);
    }

    return { trigger: trigger, overlay: overlay, drawer: drawer };
  }

  function moveExtraMenuForMobile(drawer) {
    var lower = getExtraNavNode();
    if (!lower) return;

    if (isMobileHeaderViewport()) {
      if (!lower.classList.contains('af-rwd-extra-menu-live')) {
        var ph = document.createElement('span');
        ph.className = state.extraMenuPlaceholderClass;
        ph.hidden = true;
        lower.parentNode.insertBefore(ph, lower);
      }
      lower.classList.add('af-rwd-extra-menu-live');
      drawer.appendChild(lower);
      body.classList.add('af-rwd-has-extra-menu');
      return;
    }

    restoreExtraMenu();
  }

  function restoreExtraMenu() {
    var lower = document.querySelector('#' + state.drawerId + ' .lower.af-rwd-extra-menu-live');
    if (!lower) return;

    var placeholder = document.querySelector('.' + state.extraMenuPlaceholderClass);
    if (placeholder && placeholder.parentNode) {
      placeholder.parentNode.insertBefore(lower, placeholder);
      placeholder.remove();
    }

    lower.classList.remove('af-rwd-extra-menu-live');
    body.classList.remove('af-rwd-has-extra-menu');
  }

  function setRightMenuState(isOpen, shell) {
    state.drawerOpen = !!isOpen;

    body.classList.toggle('af-rwd-right-menu-open', state.drawerOpen);
    body.classList.toggle('af-rwd-right-menu-closed', !state.drawerOpen);

    shell.trigger.setAttribute('aria-expanded', state.drawerOpen ? 'true' : 'false');
    shell.drawer.setAttribute('aria-hidden', state.drawerOpen ? 'false' : 'true');

    shell.overlay.hidden = !state.drawerOpen;
    shell.drawer.hidden = !state.drawerOpen;
  }

  function closeRightMenu(shell) {
    setRightMenuState(false, shell);
  }

  function setupRightBurgerMenu() {
    if (!body.classList.contains('af-rwd-right-burger')) return;

    var shell = ensureRightMenuShell();
    moveExtraMenuForMobile(shell.drawer);

    shell.trigger.addEventListener('click', function () {
      if (!isMobileHeaderViewport()) return;
      moveExtraMenuForMobile(shell.drawer);
      setRightMenuState(!state.drawerOpen, shell);
    });

    shell.overlay.addEventListener('click', function () {
      closeRightMenu(shell);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') closeRightMenu(shell);
    });

    document.addEventListener('click', function (event) {
      if (!state.drawerOpen) return;
      if (!shell.drawer.contains(event.target) && !shell.trigger.contains(event.target)) {
        closeRightMenu(shell);
      }
    });

    window.addEventListener('resize', function () {
      if (!isMobileHeaderViewport()) {
        closeRightMenu(shell);
      }
      moveMainMenuForMobile();
      moveExtraMenuForMobile(shell.drawer);
    }, { passive: true });
  }

  function applyMobileTableLabels(tableSelector) {
    document.querySelectorAll(tableSelector).forEach(function (table) {
      var headers = [];
      table.querySelectorAll('thead th, tr.thead th').forEach(function (th) {
        headers.push((th.textContent || '').trim());
      });

      table.querySelectorAll('tbody tr, tr.inline_row, tr[id^="thread_"], tr[id^="forum_"]').forEach(function (row) {
        if (row.querySelector('th')) return;
        row.classList.add('af-rwd-mobile-row');

        row.querySelectorAll('td').forEach(function (cell, index) {
          if (cell.dataset.afRwdLabel) return;
          var label = headers[index] || cell.getAttribute('data-label') || '';
          if (label) cell.dataset.afRwdLabel = label;
        });
      });
    });
  }

  function adaptIndex() {
    if (!body.classList.contains('af-rwd-index') || !body.classList.contains('af-rwd-forumdisplay-fixes-on')) return;
    applyMobileTableLabels('table#forums, table.forum_table, table.tborder[id*="forum"]');
  }

  function adaptForumdisplay() {
    if (!body.classList.contains('af-rwd-forumdisplay') || !body.classList.contains('af-rwd-forumdisplay-fixes-on')) return;
    applyMobileTableLabels('table#threads, table.threadlist, table.tborder[id*="thread"]');
  }

  function adaptShowthread() {
    if (!body.classList.contains('af-rwd-showthread') || !body.classList.contains('af-rwd-postbit-fixes-on')) return;
    document.querySelectorAll('.post.classic, .af-apui-postbit').forEach(function (postbit) {
      postbit.classList.add('af-rwd-postbit-mobile-ready');
    });
  }

  function adaptProfile() {
    if (!body.classList.contains('af-rwd-profile-fixes-on') || !body.classList.contains('af-rwd-member')) return;
    document.querySelectorAll('.af-apui-profile-tabs__nav').forEach(function (tabs) {
      tabs.classList.add('af-rwd-tabs-scroll');
    });
  }

  function adaptUtilityPages() {
    applyMobileTableLabels('body.af-rwd-postsactivity table, body.af-rwd-userlist table, body.af-rwd-search table, body.af-rwd-private table, body.af-rwd-usercp table');
  }

  function initPageAdapters() {
    adaptIndex();
    adaptForumdisplay();
    adaptShowthread();
    adaptProfile();
    adaptUtilityPages();
  }

  function initOnce() {
    if (state.initialized) return;
    state.initialized = true;

    wrapWideTables(document);
    syncModalClass();
    moveMainMenuForMobile();
    setupRightBurgerMenu();
    initPageAdapters();

    var observer = new MutationObserver(function (mutations) {
      var shouldRefresh = false;

      for (var i = 0; i < mutations.length; i++) {
        if (mutations[i].addedNodes && mutations[i].addedNodes.length) {
          shouldRefresh = true;
          break;
        }
      }

      if (!shouldRefresh) return;
      wrapWideTables(document);
      syncModalClass();
      moveMainMenuForMobile();
      initPageAdapters();
    });

    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initOnce);
  } else {
    initOnce();
  }
})();

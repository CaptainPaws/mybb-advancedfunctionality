(function () {
  if (typeof document === 'undefined') return;

  var body = document.body;
  if (!body || !body.classList.contains('af-rwd-enabled')) return;

  var state = {
    initialized: false,
    drawerOpen: false,
    drawerId: 'af-rwd-right-menu',
    triggerId: 'af-rwd-right-trigger'
  };

  function isMobileHeaderViewport() {
    var value = getComputedStyle(document.documentElement).getPropertyValue('--af-rwd-mobile-header-breakpoint').trim();
    var bp = parseInt(value, 10);
    if (!Number.isFinite(bp) || bp <= 0) bp = 900;
    return window.matchMedia('(max-width: ' + bp + 'px)').matches;
  }

  function wrapWideTables(root) {
    if (!body.classList.contains('af-rwd-table-wrap-on')) return;
    var scope = root || document;
    var tables = scope.querySelectorAll('table');

    tables.forEach(function (table) {
      if (table.closest('.af-rwd-table-wrap')) return;
      if (table.closest('.sceditor-container, .sceditor-group, .CodeMirror')) return;
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

  function getExtraNavNodes() {
    var lower = document.querySelector('#panel .lower');
    if (!lower || lower.closest('#' + state.drawerId)) {
      return [];
    }

    return [lower];
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

  function cloneToDrawer(drawer) {
    drawer.innerHTML = '';

    var nodes = getExtraNavNodes();
    if (!nodes.length) return;

    nodes.forEach(function (node) {
      var wrap = document.createElement('section');
      wrap.className = 'af-rwd-right-menu__block';

      var title = document.createElement('h2');
      title.className = 'af-rwd-right-menu__title';
      title.textContent = 'User menu';
      wrap.appendChild(title);

      var clone = node.cloneNode(true);
      clone.removeAttribute('id');
      wrap.appendChild(clone);
      drawer.appendChild(wrap);
    });
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

    var nodes = getExtraNavNodes();
    if (!nodes.length) return;

    var shell = ensureRightMenuShell();
    cloneToDrawer(shell.drawer);

    shell.trigger.addEventListener('click', function () {
      if (!isMobileHeaderViewport()) return;
      if (!state.drawerOpen) cloneToDrawer(shell.drawer);
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
    }, { passive: true });
  }

  function applyMobileTableLabels(tableSelector) {
    document.querySelectorAll(tableSelector).forEach(function (table) {
      var headers = [];
      table.querySelectorAll('thead th, tr.thead th').forEach(function (th) {
        headers.push((th.textContent || '').trim());
      });

      table.querySelectorAll('tbody tr, tr.inline_row, tr[id^="thread_"]').forEach(function (row) {
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

  function initPageAdapters() {
    adaptIndex();
    adaptForumdisplay();
    adaptShowthread();
    adaptProfile();
  }

  function initOnce() {
    if (state.initialized) return;
    state.initialized = true;

    wrapWideTables(document);
    syncModalClass();
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

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
    var result = [];
    [
        '#panel .lower'
    ].forEach(function (selector) {
        document.querySelectorAll(selector).forEach(function (node) {
        if (!node) return;
        if (node.closest('#' + state.drawerId)) return;
        if (result.indexOf(node) !== -1) return;
        result.push(node);
        });
    });
    return result;
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

    nodes.forEach(function (node, index) {
      var wrap = document.createElement('section');
      wrap.className = 'af-rwd-right-menu__block';

      var title = document.createElement('h2');
      title.className = 'af-rwd-right-menu__title';
      title.textContent = index === 0 ? 'Extra menu' : 'Navigation ' + (index + 1);
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
    body.classList.toggle('af-rwd-extra-nav-open', state.drawerOpen);
    body.classList.toggle('af-rwd-extra-nav-closed', !state.drawerOpen);

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

  function initOnce() {
    if (state.initialized) return;
    state.initialized = true;

    wrapWideTables(document);
    syncModalClass();
    setupRightBurgerMenu();

    var observer = new MutationObserver(function (mutations) {
      var tableFound = false;
      var modalFound = false;

      for (var i = 0; i < mutations.length; i++) {
        var mutation = mutations[i];
        if (!mutation.addedNodes || mutation.addedNodes.length === 0) continue;

        for (var j = 0; j < mutation.addedNodes.length; j++) {
          var node = mutation.addedNodes[j];
          if (!node || node.nodeType !== 1) continue;

          if (node.matches && node.matches('table')) tableFound = true;
          if (node.matches && node.matches('.modal, .af-modal, .af-cs-modal, .af-apui-modal, [data-af-apui-surface]')) modalFound = true;

          if (tableFound && modalFound) break;
        }

        if (tableFound && modalFound) break;
      }

      if (tableFound) wrapWideTables(document);
      if (modalFound) syncModalClass();
    });

    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initOnce);
  } else {
    initOnce();
  }
})();

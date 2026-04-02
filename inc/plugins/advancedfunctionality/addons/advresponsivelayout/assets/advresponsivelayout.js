(function () {
  if (typeof document === 'undefined') return;

  var body = document.body;
  if (!body || !body.classList.contains('af-rwd-enabled')) return;

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

  function setupMobileNav() {
    if (!body.classList.contains('af-rwd-mobile-nav-on')) return;

    var nav = document.querySelector('#header ul.menu, .navigation, .top_links');
    if (!nav || document.getElementById('af-rwd-nav-toggle')) return;

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'af-rwd-nav-toggle';
    btn.className = 'af-rwd-nav-toggle';
    btn.setAttribute('aria-expanded', 'false');
    btn.textContent = 'Menu';

    btn.addEventListener('click', function () {
      var expanded = btn.getAttribute('aria-expanded') === 'true';
      btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      body.classList.toggle('af-rwd-nav-open', !expanded);
    });

    nav.parentNode.insertBefore(btn, nav);
  }

  wrapWideTables(document);
  syncModalClass();
  setupMobileNav();

  window.addEventListener('resize', syncModalClass, { passive: true });

  var observer = new MutationObserver(function (mutations) {
    var needsTableScan = false;

    for (var i = 0; i < mutations.length; i++) {
      var mutation = mutations[i];
      if (!mutation.addedNodes || mutation.addedNodes.length === 0) continue;
      for (var j = 0; j < mutation.addedNodes.length; j++) {
        var node = mutation.addedNodes[j];
        if (!node || node.nodeType !== 1) continue;
        if (node.matches && node.matches('table, .modal, .af-modal, .af-cs-modal, .af-apui-modal, [data-af-apui-surface]')) {
          needsTableScan = true;
          break;
        }
      }
      if (needsTableScan) break;
    }

    if (needsTableScan) {
      wrapWideTables(document);
      syncModalClass();
    }
  });

  observer.observe(document.documentElement, { childList: true, subtree: true });
})();

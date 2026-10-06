(function () {
  'use strict';
  if (window.AFModalHost) return;
  var providerRoots = [
    '#af_aas_modal', '#af_aam_modal', '#af-wanted-modal-host', '#af-kb-modal-host',
    '#af-character-sheet-modal-host', '[data-afcs-modal]', '[data-af-apui-modal]',
    '[data-af-shop-modal]', '[data-af-balance-modal]', '.af-wanted-modal-backdrop',
    '.af-atf-kb-modal-backdrop', '.af-kb-chip-modal', '.af-kb-insert',
    '[data-af-kb-status-modal]', '.af-inv-support-modal'
  ];
  var providerSelector = providerRoots.join(',');
  var mounted = Object.create(null);

  function host() { return document.getElementById('atf-global-modal-host'); }

  function mount(root) {
    var target = host();
    if (!target || !root || root.nodeType !== 1) return root;
    if (root.matches('#af_aas_modal, #af_aam_modal')) root.classList.remove('modal');
    var identity = providerRoots.find(function (selector) { return root.matches(selector); });
    if (identity) {
      var previous = mounted[identity];
      if (previous && previous !== root && previous.isConnected) {
        root.remove();
        return previous;
      }
      mounted[identity] = root;
    }
    if (root.parentNode !== target) target.appendChild(root);
    return root;
  }

  // Providers retain content, actions and lifecycle ownership.
  window.AFModalHost = { get: host, mount: mount };

  function mountCandidate(node) {
    if (!node || node.nodeType !== 1) return;
    var target = host();
    // Ignore observer records caused by our own move into the canonical host.
    if (!target || node === target || node.parentNode === target) return;
    if (node.matches(providerSelector)) mount(node);
    Array.prototype.forEach.call(node.querySelectorAll(providerSelector), mount);
  }

  function canonicalize() {
    Array.prototype.forEach.call(document.querySelectorAll(providerSelector), mount);
  }

  function ready() {
    canonicalize();
    if (window.MutationObserver && host()) {
      new MutationObserver(function (records) {
        records.forEach(function (record) {
          Array.prototype.forEach.call(record.addedNodes || [], mountCandidate);
        });
      }).observe(document.body, { childList: true, subtree: true });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
  else ready();
}());

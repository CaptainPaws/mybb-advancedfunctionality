(function () {
  'use strict';

  var providerRoots = [
    '#af_aas_modal', '#af_aam_modal', '#af-wanted-modal-host', '#af-kb-modal-host',
    '#af-character-sheet-modal-host', '[data-afcs-modal]', '[data-af-apui-modal]',
    '[data-af-shop-modal]', '[data-af-balance-modal]', '.af-wanted-modal-backdrop',
    '.af-atf-kb-modal-backdrop', '.af-kb-chip-modal', '.af-kb-insert',
    '[data-af-kb-status-modal]', '.af-inv-support-modal'
  ];

  function host() {
    return document.getElementById('atf-global-modal-host');
  }

  function canonicalize() {
    var target = host();
    if (!target) return;
    providerRoots.forEach(function (selector) {
      var roots = Array.prototype.slice.call(document.querySelectorAll(selector));
      if (!roots.length) return;
      var root = roots[0];
      // MyBB's reputation popup owns the generic `.modal` selector. Keeping
      // provider shells in that selector makes one reputation invocation adopt
      // AAM/AAS as additional popup content. Providers already have namespaced
      // classes and lifecycle handlers, so remove only the ambiguous class.
      if (root.matches('#af_aas_modal, #af_aam_modal')) root.classList.remove('modal');
      roots.slice(1).forEach(function (duplicate) {
        if (duplicate !== root) duplicate.remove();
      });
      if (root.parentNode !== target) target.appendChild(root);
    });
  }

  // Public composition helper: providers can mount lazily without knowing the
  // footer DOM. It deliberately contains no request, content or submit logic.
  window.AFModalHost = window.AFModalHost || {
    get: host,
    mount: function (root) {
      var target = host();
      if (target && root && root.parentNode !== target) target.appendChild(root);
      return root;
    }
  };

  var providerSelector = providerRoots.join(',');

  function mountCandidate(node) {
    if (!node || node.nodeType !== 1) return;
    var roots = [];
    if (node.matches && node.matches(providerSelector)) roots.push(node);
    if (node.querySelectorAll) {
      roots = roots.concat(Array.prototype.slice.call(node.querySelectorAll(providerSelector)));
    }
    roots.forEach(function (root) {
      if (root.matches('#af_aas_modal, #af_aam_modal')) root.classList.remove('modal');
      window.AFModalHost.mount(root);
    });
  }

  function ready() {
    canonicalize();
    if (window.MutationObserver) {
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

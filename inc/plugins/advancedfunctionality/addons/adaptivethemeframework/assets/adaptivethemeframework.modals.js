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

  function ready() {
    canonicalize();
    if (window.MutationObserver) {
      var queued = false;
      new MutationObserver(function () {
        if (queued) return;
        queued = true;
        window.requestAnimationFrame(function () { queued = false; canonicalize(); });
      }).observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
  else ready();
}());

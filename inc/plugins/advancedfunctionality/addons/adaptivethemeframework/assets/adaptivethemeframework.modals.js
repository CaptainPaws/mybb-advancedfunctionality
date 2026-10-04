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

(function () {
  'use strict';

  var desktopQuery = window.matchMedia('(min-width: 48.0625rem)');
  var items = [];
  var frame = 0;

  function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
  }

  function translate(element, value) {
    var previous = Number(element.dataset.atfStickyTranslate || 0);
    if (previous === value) return;
    element.dataset.atfStickyTranslate = String(value);
    element.style.transform = value ? 'translate3d(0,' + value + 'px,0)' : '';
    element.style.willChange = value ? 'transform' : '';
  }

  function collect() {
    items = Array.prototype.map.call(document.querySelectorAll('.atf-post'), function (post) {
      var topbar = post.querySelector('.atf-post__topbar');
      var sidebar = post.querySelector('.atf-post__sidebar-inner');
      return topbar && sidebar ? { post: post, topbar: topbar, sidebar: sidebar } : null;
    }).filter(Boolean);
  }

  function updateItem(item, scrollTop) {
    if (!desktopQuery.matches) {
      translate(item.topbar, 0);
      translate(item.sidebar, 0);
      return;
    }

    var postRect = item.post.getBoundingClientRect();
    var postTop = scrollTop + postRect.top;
    var postHeight = item.post.offsetHeight;
    var topbarHeight = item.topbar.offsetHeight;
    var sidebarHeight = item.sidebar.offsetHeight;
    var topbarY = clamp(scrollTop - postTop, 0, Math.max(0, postHeight - topbarHeight));
    var sidebarTop = postTop + topbarHeight;
    var sidebarY = clamp(scrollTop + topbarHeight - sidebarTop, 0,
      Math.max(0, postHeight - topbarHeight - sidebarHeight));

    translate(item.topbar, Math.round(topbarY));
    translate(item.sidebar, Math.round(sidebarY));
  }

  function update() {
    frame = 0;
    var scrollTop = window.pageYOffset || document.documentElement.scrollTop || 0;
    items.forEach(function (item) { updateItem(item, scrollTop); });
  }

  function schedule() {
    if (!frame) frame = window.requestAnimationFrame(update);
  }

  function refresh() {
    collect();
    schedule();
  }

  function boot() {
    collect();
    if (!items.length) return;
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', refresh);
    window.addEventListener('load', refresh);
    window.addEventListener('orientationchange', refresh);
    if (desktopQuery.addEventListener) desktopQuery.addEventListener('change', refresh);
    schedule();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
}());

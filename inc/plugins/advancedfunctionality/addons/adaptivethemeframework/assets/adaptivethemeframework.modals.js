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
  var resizeFrame = 0;
  var stickyOffset = 0;
  var resizeObserver = null;

  function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
  }

  function currentTranslate(element) {
    return Number(element.dataset.atfStickyTranslate || 0);
  }

  function translate(element, value) {
    var rounded = Math.round(value);
    var previous = currentTranslate(element);
    if (previous === rounded) return;
    element.dataset.atfStickyTranslate = String(rounded);
    element.style.transform = rounded ? 'translate3d(0,' + rounded + 'px,0)' : '';
    element.style.willChange = rounded ? 'transform' : '';
  }

  function documentTop(element, scrollTop) {
    return scrollTop + element.getBoundingClientRect().top - currentTranslate(element);
  }

  function navigationOffset() {
    var navigation = document.querySelector('.af-am-navigation');
    if (!navigation) return 0;
    return Math.ceil(navigation.getBoundingClientRect().height);
  }

  function measureItem(item, scrollTop) {
    var postRect = item.post.getBoundingClientRect();
    item.postTop = scrollTop + postRect.top;
    item.postHeight = item.post.offsetHeight;
    item.topbarHeight = item.topbar.offsetHeight;
    item.sidebarHeight = item.sidebar.offsetHeight;
    item.metaHeight = item.meta.offsetHeight;
    item.sidebarTop = documentTop(item.sidebar, scrollTop);
    item.metaTop = documentTop(item.meta, scrollTop);

    var postBottom = item.postTop + item.postHeight;
    var topbarBottom = item.postTop + item.topbarHeight;
    var sidebarBottom = item.sidebarTop + item.sidebarHeight;
    var metaBottom = item.metaTop + item.metaHeight;
    item.maxTravel = Math.max(0, Math.min(
      postBottom - topbarBottom,
      postBottom - sidebarBottom,
      postBottom - metaBottom
    ));
  }

  function measure() {
    var scrollTop = window.pageYOffset || document.documentElement.scrollTop || 0;
    stickyOffset = Math.max(0, navigationOffset() - 8);
    items.forEach(function (item) { measureItem(item, scrollTop); });
  }

  function collect() {
    items = Array.prototype.map.call(document.querySelectorAll('.atf-post'), function (post) {
      var topbar = post.querySelector('.atf-post__topbar');
      var sidebar = post.querySelector('.atf-post__sidebar-inner');
      var meta = post.querySelector('.atf-post__meta-line');
      return topbar && sidebar && meta ? {
        post: post,
        topbar: topbar,
        sidebar: sidebar,
        meta: meta,
        postTop: 0,
        postHeight: 0,
        topbarHeight: 0,
        sidebarHeight: 0,
        metaHeight: 0,
        sidebarTop: 0,
        metaTop: 0,
        maxTravel: 0
      } : null;
    }).filter(Boolean);
    measure();
  }

  function updateItem(item, scrollTop) {
    if (!desktopQuery.matches) {
      translate(item.topbar, 0);
      translate(item.sidebar, 0);
      translate(item.meta, 0);
      return;
    }

    var topbarY = clamp(scrollTop + stickyOffset - item.postTop, 0, item.maxTravel);
    var sidebarY = clamp(
      scrollTop + stickyOffset + item.topbarHeight - item.sidebarTop,
      0,
      item.maxTravel
    );
    var metaY = clamp(
      scrollTop + stickyOffset + item.topbarHeight - item.metaTop,
      0,
      item.maxTravel
    );

    translate(item.topbar, topbarY);
    translate(item.sidebar, sidebarY);
    translate(item.meta, metaY);
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
    if (resizeFrame) return;
    resizeFrame = window.requestAnimationFrame(function () {
      resizeFrame = 0;
      measure();
      schedule();
    });
  }

  function observeGeometry() {
    if (!window.ResizeObserver) return;
    resizeObserver = new ResizeObserver(refresh);
    var navigation = document.querySelector('.af-am-navigation');
    if (navigation) resizeObserver.observe(navigation);
    items.forEach(function (item) {
      resizeObserver.observe(item.post);
      resizeObserver.observe(item.topbar);
      resizeObserver.observe(item.sidebar);
      resizeObserver.observe(item.meta);
    });
  }

  function boot() {
    collect();
    if (!items.length) return;
    observeGeometry();
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

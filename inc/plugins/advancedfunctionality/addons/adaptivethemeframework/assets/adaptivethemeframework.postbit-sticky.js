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
    // A post resize subsumes changes to its topbar/sidebar/meta. Observing each
    // child multiplied native observer targets by four on long threads.
    items.forEach(function (item) { resizeObserver.observe(item.post); });
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

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

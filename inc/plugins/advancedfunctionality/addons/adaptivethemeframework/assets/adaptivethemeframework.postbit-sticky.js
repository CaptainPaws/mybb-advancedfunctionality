(function () {
  'use strict';
  if (window.AFPostbitSticky) return;
  window.AFPostbitSticky = true;

  var desktopQuery = window.matchMedia('(min-width: 48.0625rem)');
  var items = [];
  var frame = 0;
  var active = false;
  var offsetDirty = true;
  var targets = new WeakMap();
  var mutationObserver = null;
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

  function invalidateFrom(index) {
    for (var i = index; i < items.length; i++) items[i].dirty = true;
  }

  function measure(scrollTop) {
    if (offsetDirty) {
      stickyOffset = Math.max(0, navigationOffset() - 8);
      offsetDirty = false;
    }
    // All reads precede all transform writes. A resized post also shifts the
    // document position of following posts, so those positions become dirty.
    items.forEach(function (item) {
      if (!item.dirty) return;
      measureItem(item, scrollTop);
      item.dirty = false;
    });
  }

  function collect() {
    items = Array.prototype.map.call(document.querySelectorAll('.atf-post'), function (post) {
      var topbar = post.querySelector('.atf-post__topbar');
      var sidebar = post.querySelector('.atf-post__sidebar-inner');
      var meta = post.querySelector('.atf-post__meta-line');
      return topbar && sidebar && meta ? {
        dirty: true,
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
    invalidateFrom(0);
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
    if (!active) return;
    var scrollTop = window.pageYOffset || document.documentElement.scrollTop || 0;
    measure(scrollTop);
    items.forEach(function (item) { updateItem(item, scrollTop); });
  }

  function schedule() {
    if (active && !frame) frame = window.requestAnimationFrame(update);
  }

  function refresh() {
    if (!active) return;
    offsetDirty = true;
    invalidateFrom(0);
    schedule();
  }

  function observeGeometry() {
    if (!window.ResizeObserver) return;
    if (!resizeObserver) resizeObserver = new ResizeObserver(function (entries) {
      entries.forEach(function (entry) {
        var item = targets.get(entry.target);
        if (!item) {
          offsetDirty = true;
          invalidateFrom(0);
        } else if (entry.target === item.post) {
          invalidateFrom(items.indexOf(item));
        } else {
          item.dirty = true;
        }
      });
      schedule();
    });
    resizeObserver.disconnect();
    targets = new WeakMap();
    var navigation = document.querySelector('.af-am-navigation');
    if (navigation) resizeObserver.observe(navigation);
    // Observe only the measured roots. Sidebar/meta can resize while the tall
    // message keeps the post's height unchanged; observing just posts misses it.
    items.forEach(function (item) {
      [item.post, item.topbar, item.sidebar, item.meta].forEach(function (root) {
        targets.set(root, item);
        resizeObserver.observe(root);
      });
    });
    ['.atf-thread__header', '.atf-thread__poll', '.atf-thread__slot--before'].forEach(function (selector) {
      var root = document.querySelector(selector);
      if (root) resizeObserver.observe(root);
    });
  }

  function postsChanged(records) {
    var changed = records.some(function (record) {
      return Array.prototype.some.call(record.addedNodes, isPostSubtree)
        || Array.prototype.some.call(record.removedNodes, isPostSubtree);
    });
    if (!changed) return;
    collect();
    observeGeometry();
    schedule();
  }

  function isPostSubtree(node) {
    return node.nodeType === 1 && (node.matches('.atf-post') || !!node.querySelector('.atf-post'));
  }

  function setDesktop() {
    var enabled = desktopQuery.matches;
    if (active === enabled) return;
    active = enabled;
    if (!active) {
      if (frame) window.cancelAnimationFrame(frame);
      frame = 0;
      if (resizeObserver) resizeObserver.disconnect();
      if (mutationObserver) mutationObserver.disconnect();
      window.removeEventListener('scroll', schedule);
      window.removeEventListener('resize', refresh);
      window.removeEventListener('load', refresh);
      window.removeEventListener('orientationchange', refresh);
      items.forEach(function (item) {
        translate(item.topbar, 0);
        translate(item.sidebar, 0);
        translate(item.meta, 0);
      });
      return;
    }
    collect();
    offsetDirty = true;
    observeGeometry();
    var posts = document.getElementById('posts');
    if (posts && window.MutationObserver) {
      if (!mutationObserver) mutationObserver = new MutationObserver(postsChanged);
      mutationObserver.observe(posts, { childList: true, subtree: true });
    }
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', refresh);
    window.addEventListener('load', refresh);
    window.addEventListener('orientationchange', refresh);
    schedule();
  }

  function boot() {
    if (desktopQuery.addEventListener) desktopQuery.addEventListener('change', setDesktop);
    else if (desktopQuery.addListener) desktopQuery.addListener(setDesktop);
    setDesktop();
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(refresh);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
}());

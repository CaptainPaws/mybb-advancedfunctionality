(function () {
  'use strict';

  if (typeof document === 'undefined') return;

  var body = document.body;
  if (!body || !body.classList.contains('af-rwd-enabled')) return;

  var MOBILE_QUERY = '(max-width: 767.98px)';
  var state = {
    refs: null,
    extraOpen: false,
    extraSource: null,
    extraPlaceholder: null,
    resizeTimer: null
  };

  function isMobile() {
    return window.matchMedia(MOBILE_QUERY).matches;
  }

  function $(selector, root) {
    return (root || document).querySelector(selector);
  }

  function $all(selector, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(selector));
  }

  function createEl(tag, className) {
    var el = document.createElement(tag);
    if (className) el.className = className;
    return el;
  }

  function textNorm(value) {
    return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
  }

  function ensureRefs() {
    if (state.refs) return state.refs;

    var host = $('.af-rwd-main-nav-host');
    if (!host) {
      host = createEl('div', 'af-rwd-main-nav-host');

      var bar = createEl('div', 'af-rwd-main-nav-bar');

      var prevBtn = createEl('button', 'af-rwd-main-nav-scroll af-rwd-main-nav-scroll--prev');
      prevBtn.type = 'button';
      prevBtn.setAttribute('aria-label', 'Прокрутить меню влево');
      prevBtn.textContent = '‹';

      var shell = createEl('div', 'af-rwd-main-nav-shell');

      var actions = createEl('div', 'af-rwd-main-nav-actions');

      var burger = createEl('button', 'af-rwd-right-trigger');
      burger.type = 'button';
      burger.setAttribute('aria-label', 'Открыть дополнительное меню');
      burger.setAttribute('aria-expanded', 'false');
      burger.innerHTML = '<span class="af-rwd-right-trigger__icon" aria-hidden="true"></span>';

      var nextBtn = createEl('button', 'af-rwd-main-nav-scroll af-rwd-main-nav-scroll--next');
      nextBtn.type = 'button';
      nextBtn.setAttribute('aria-label', 'Прокрутить меню вправо');
      nextBtn.textContent = '›';

      actions.appendChild(burger);

      bar.appendChild(prevBtn);
      bar.appendChild(shell);
      bar.appendChild(actions);
      bar.appendChild(nextBtn);
      host.appendChild(bar);
      document.body.appendChild(host);
    }

    var overlay = $('.af-rwd-extra-overlay');
    if (!overlay) {
      overlay = createEl('div', 'af-rwd-extra-overlay');
      document.body.appendChild(overlay);
    }

    var drawer = $('.af-rwd-right-menu');
    if (!drawer) {
      drawer = createEl('aside', 'af-rwd-right-menu');
      document.body.appendChild(drawer);
    }

    state.refs = {
      host: host,
      shell: $('.af-rwd-main-nav-shell', host),
      prevBtn: $('.af-rwd-main-nav-scroll--prev', host),
      nextBtn: $('.af-rwd-main-nav-scroll--next', host),
      burger: $('.af-rwd-right-trigger', host),
      overlay: overlay,
      drawer: drawer
    };

    bindStaticEvents();
    return state.refs;
  }

  function bindStaticEvents() {
    var refs = state.refs;
    if (!refs || refs.host.__afRwdBound) return;

    refs.prevBtn.addEventListener('click', function () {
      refs.shell.scrollBy({ left: -160, behavior: 'smooth' });
    });

    refs.nextBtn.addEventListener('click', function () {
      refs.shell.scrollBy({ left: 160, behavior: 'smooth' });
    });

    refs.shell.addEventListener('scroll', syncNavButtons, { passive: true });

    refs.burger.addEventListener('click', function () {
      if (!isMobile() || !body.classList.contains('af-rwd-extra-nav-mounted')) return;
      setDrawerOpen(!state.extraOpen);
    });

    refs.overlay.addEventListener('click', function () {
      setDrawerOpen(false);
    });

    refs.drawer.addEventListener('click', function (e) {
      var trigger = e.target.closest('a, button, [role="button"]');
      if (!trigger) return;

      var text = textNorm(
        trigger.textContent + ' ' +
        (trigger.getAttribute('title') || '') + ' ' +
        (trigger.getAttribute('aria-label') || '')
      );
      var href = textNorm(trigger.getAttribute('href') || '');
      var cls = textNorm(trigger.className || '');

      var looksLikeAccountUi =
        text.indexOf('аккаунт') !== -1 ||
        text.indexOf('account') !== -1 ||
        text.indexOf('switch') !== -1 ||
        href.indexOf('account') !== -1 ||
        href.indexOf('switch') !== -1 ||
        cls.indexOf('account') !== -1 ||
        cls.indexOf('switch') !== -1;

      if (looksLikeAccountUi) {
        setTimeout(function () {
          setDrawerOpen(false);
        }, 0);
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        setDrawerOpen(false);
      }
    });

    window.addEventListener('resize', function () {
      clearTimeout(state.resizeTimer);
      state.resizeTimer = setTimeout(applyMode, 80);
    }, { passive: true });

    refs.host.__afRwdBound = true;
  }

  function setDrawerOpen(open) {
    var refs = ensureRefs();
    state.extraOpen = !!open;
    body.classList.toggle('af-rwd-right-menu-open', state.extraOpen);
    refs.burger.setAttribute('aria-expanded', state.extraOpen ? 'true' : 'false');
  }

  function updateMainNavOffset() {
    var refs = ensureRefs();
    var h = refs.host.offsetHeight || 56;
    document.documentElement.style.setProperty('--af-rwd-main-nav-offset', h + 'px');
  }

  function syncNavButtons() {
    var refs = ensureRefs();
    var maxScroll = Math.max(0, refs.shell.scrollWidth - refs.shell.clientWidth);
    refs.prevBtn.disabled = refs.shell.scrollLeft <= 2;
    refs.nextBtn.disabled = refs.shell.scrollLeft >= maxScroll - 2;
  }

  function findBestMainNavSource() {
    var candidates = [];
    var selectors = [
      '#header ul.menu.top_links',
      '#header .menu.top_links',
      '.header ul.menu.top_links',
      '.header .menu.top_links',
      '#panel ul.menu.top_links',
      'ul.menu.top_links'
    ];

    selectors.forEach(function (selector) {
      $all(selector).forEach(function (node) {
        if (candidates.indexOf(node) === -1) {
          candidates.push(node);
        }
      });
    });

    if (!candidates.length) return null;

    candidates.sort(function (a, b) {
      var aLinks = a.querySelectorAll('li > a').length;
      var bLinks = b.querySelectorAll('li > a').length;
      return bLinks - aLinks;
    });

    return candidates[0];
  }

  function mountMainNav() {
    var refs = ensureRefs();
    refs.shell.innerHTML = '';
    body.classList.remove('af-rwd-main-nav-mounted');

    if (!isMobile()) return;

    var source = findBestMainNavSource();
    if (!source) return;

    source.classList.add('af-rwd-main-nav-source');

    var clone = source.cloneNode(true);
    clone.classList.remove('af-rwd-main-nav-source');
    clone.classList.add('af-rwd-main-nav-clone');

    refs.shell.appendChild(clone);
    body.classList.add('af-rwd-main-nav-mounted');

    updateMainNavOffset();
    syncNavButtons();
  }

  function findExtraNavSource() {
    return state.extraSource || $('#panel .lower') || $('.panel .lower') || $('#header .lower') || $('.lower');
  }

  function mountExtraNav() {
    var refs = ensureRefs();
    body.classList.remove('af-rwd-extra-nav-mounted');

    if (!isMobile()) {
      restoreExtraNav();
      refs.drawer.innerHTML = '';
      return;
    }

    var source = findExtraNavSource();
    if (!source) {
      refs.drawer.innerHTML = '';
      setDrawerOpen(false);
      return;
    }

    state.extraSource = source;

    if (!state.extraPlaceholder && source.parentNode !== refs.drawer) {
      state.extraPlaceholder = document.createComment('af-rwd-extra-nav-placeholder');
      source.parentNode.insertBefore(state.extraPlaceholder, source);
    }

    if (source.parentNode !== refs.drawer) {
      refs.drawer.innerHTML = '';
      refs.drawer.appendChild(source);
    }

    body.classList.add('af-rwd-extra-nav-mounted');
  }

  function restoreExtraNav() {
    if (!state.extraSource || !state.extraPlaceholder || !state.extraPlaceholder.parentNode) return;

    state.extraPlaceholder.parentNode.insertBefore(state.extraSource, state.extraPlaceholder);
    state.extraPlaceholder.remove();
    state.extraPlaceholder = null;
  }

  function moveNode(node, target, beforeNode, post) {
    if (!node || !target) return;

    if (!node.__afRwdPlaceholder && node.parentNode) {
      node.__afRwdPlaceholder = document.createComment('af-rwd-restore:' + (node.className || node.nodeName));
      node.parentNode.insertBefore(node.__afRwdPlaceholder, node);
    }

    if (post) {
      if (!post.__afRwdMovedNodes) post.__afRwdMovedNodes = [];
      if (post.__afRwdMovedNodes.indexOf(node) === -1) {
        post.__afRwdMovedNodes.push(node);
      }
    }

    if (beforeNode) {
      target.insertBefore(node, beforeNode);
    } else {
      target.appendChild(node);
    }
  }

  function restoreNode(node) {
    if (!node || !node.__afRwdPlaceholder || !node.__afRwdPlaceholder.parentNode) return;
    node.__afRwdPlaceholder.parentNode.insertBefore(node, node.__afRwdPlaceholder);
  }

  function mountPostbitsMobile() {
    if (!isMobile()) {
      restorePostbitsDesktop();
      return;
    }

    $all('.post.classic').forEach(function (post) {
      if (post.classList.contains('af-rwd-postbit-mobile-mounted')) return;

      var inner = $('.af-apui-postbit-author__inner', post);
      if (!inner) return;

      var nameWrap = $('.af-apui-postbit-name-wrap', inner);
      var rank = $('.af-apui-postbit-rank', inner);
      var avatarShell = $('.af-apui-postbit-avatar-shell', inner);
      var profileFields = $('.af-apui-postbit-profilefields', inner);
      var plaqueSlot = $('.af-apui-postbit-plaque-slot', inner);

      var extras = $('.af-rwd-postbit-extras', inner);
      if (!extras) {
        extras = createEl('div', 'af-rwd-postbit-extras');
        if (profileFields) {
          inner.insertBefore(extras, profileFields);
        } else if (plaqueSlot) {
          inner.insertBefore(extras, plaqueSlot);
        } else {
          inner.appendChild(extras);
        }
      }

      Array.prototype.slice.call(inner.children).forEach(function (child) {
        if (
          child === nameWrap ||
          child === rank ||
          child === avatarShell ||
          child === profileFields ||
          child === plaqueSlot ||
          child === extras
        ) {
          return;
        }

        if (child.classList.contains('af-apui-postbit-online') && avatarShell) {
          moveNode(child, avatarShell, null, post);
          return;
        }

        moveNode(child, extras, null, post);
      });

      post.classList.add('af-rwd-postbit-mobile-mounted');
    });
  }

  function restorePostbitsDesktop() {
    $all('.post.classic.af-rwd-postbit-mobile-mounted').forEach(function (post) {
      if (post.__afRwdMovedNodes && post.__afRwdMovedNodes.length) {
        post.__afRwdMovedNodes.forEach(function (node) {
          restoreNode(node);
        });
        post.__afRwdMovedNodes = [];
      }

      var extras = $('.af-rwd-postbit-extras', post);
      if (extras && !extras.children.length) {
        extras.remove();
      }

      post.classList.remove('af-rwd-postbit-mobile-mounted');
    });
  }

  function stabilizeAvatars() {
    if (!isMobile()) return;

    $all('.post .author_avatar img, .af-apui-postbit-avatar-shell img, .af-apui-author-avatar img').forEach(function (img) {
      img.style.width = '88px';
      img.style.height = '88px';
      img.style.maxWidth = '88px';
      img.style.maxHeight = '88px';
      img.style.objectFit = 'cover';
    });

    $all('#panel .avatar img, #panel .user-avatar img').forEach(function (img) {
      img.style.width = '34px';
      img.style.height = '34px';
      img.style.maxWidth = '34px';
      img.style.maxHeight = '34px';
      img.style.objectFit = 'cover';
    });

    $all('.af-hw-avatarlink img').forEach(function (img) {
      img.style.width = '65px';
      img.style.height = '65px';
      img.style.maxWidth = '65px';
      img.style.maxHeight = '65px';
      img.style.objectFit = 'cover';
    });
  }

  function markMobileTables() {
    if (!isMobile()) {
      $all('.af-rwd-mobile-cards').forEach(function (table) {
        table.classList.remove('af-rwd-mobile-cards');
      });
      return;
    }

    $all(
      'body.af-rwd-script-userlist table,' +
      'body.af-rwd-script-postsactivity table,' +
      'body.af-rwd-script-private table.pm_table,' +
      'body.af-rwd-script-search table'
    ).forEach(function (table) {
      table.classList.add('af-rwd-mobile-cards');
    });
  }

  function syncModalClass() {
    var hasModal = !!document.querySelector('.modal, .af-modal, .af-cs-modal, .af-apui-modal, .af-shop-modal__dialog');
    body.classList.toggle('af-rwd-modal-surface', hasModal);
  }

  function applyMode() {
    ensureRefs();

    if (isMobile()) {
      mountMainNav();
      mountExtraNav();
      mountPostbitsMobile();
      markMobileTables();
      stabilizeAvatars();
      syncModalClass();
      updateMainNavOffset();
      syncNavButtons();
    } else {
      setDrawerOpen(false);
      restoreExtraNav();
      restorePostbitsDesktop();
      markMobileTables();

      var refs = ensureRefs();
      refs.shell.innerHTML = '';
      refs.drawer.innerHTML = '';

      body.classList.remove('af-rwd-main-nav-mounted', 'af-rwd-extra-nav-mounted', 'af-rwd-right-menu-open');

      $all('.af-rwd-main-nav-source').forEach(function (node) {
        node.classList.remove('af-rwd-main-nav-source');
      });
    }
  }

  function init() {
    applyMode();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

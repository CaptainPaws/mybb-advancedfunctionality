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
    resizeTimer: null,
    activeTooltip: null,
    userdetailsTooltipTimer: null,
    userdetailsTooltipToken: 0
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

      if (trigger.getAttribute('data-af-rwd-proxy-account-trigger') === '1') {
        e.preventDefault();
        e.stopPropagation();

        proxyExistingAccountSwitcherTrigger();

        setTimeout(function () {
          setDrawerOpen(false);
        }, 0);
        return;
      }

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
        clearActiveUserdetailsPopup();
      }
    });

    window.addEventListener('resize', function () {
      clearTimeout(state.resizeTimer);
      state.resizeTimer = setTimeout(applyMode, 80);
    }, { passive: true });

    document.addEventListener('click', function (e) {
      var railStatTrigger = e.target.closest(
        '.af-apui-postbit-rail .af-apui-stat-item, ' +
        '.af-apui-postbit-rail .af-apui-stat-item__icon, ' +
        '.af-apui-postbit-rail .af-apui-stat-item__value'
      );

      if (railStatTrigger && !railStatTrigger.closest('.post_controls')) {
        var railStatItem = railStatTrigger.closest('.af-apui-stat-item') || railStatTrigger;
        normalizeRailStatTooltipSource(railStatItem);
        clearActiveUserdetailsPopup();
        return;
      }

      var trigger = e.target.closest(
        '.author_statistics .af-apui-stat-item__value, ' +
        '.author_statistics .af-apui-stat-item__trigger, ' +
        '.author_statistics a, .author_statistics button, .author_statistics [role="button"], ' +
        '.af-apui-postbit-userdetails .af-apui-stat-item__value, ' +
        '.af-apui-postbit-userdetails .af-apui-stat-item__trigger, ' +
        '.af-apui-postbit-userdetails a, .af-apui-postbit-userdetails button, .af-apui-postbit-userdetails [role="button"]'
      );

      if (trigger && !trigger.closest('.post_controls')) {
        scheduleUserdetailsPopupPosition(trigger);
        return;
      }

      if (state.activeTooltip) {
        setTimeout(function () {
          if (!state.activeTooltip) return;
          if (state.activeTooltip.popup && state.activeTooltip.popup.contains(e.target)) return;
          clearActiveUserdetailsPopup();
        }, 0);
      }
    });

    document.addEventListener('scroll', function () {
      positionActiveUserdetailsPopup();
    }, true);

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

  function setExtraSourceHidden(source, hidden) {
    if (!source) return;

    source.classList.add('af-rwd-extra-nav-source');

    if (hidden) {
      if (!source.hasAttribute('data-af-rwd-prev-display')) {
        source.setAttribute('data-af-rwd-prev-display', source.style.display || '');
      }

      source.style.display = 'none';
      source.setAttribute('aria-hidden', 'true');
      return;
    }

    if (source.hasAttribute('data-af-rwd-prev-display')) {
      var prevDisplay = source.getAttribute('data-af-rwd-prev-display');
      if (prevDisplay) {
        source.style.display = prevDisplay;
      } else {
        source.style.removeProperty('display');
      }
      source.removeAttribute('data-af-rwd-prev-display');
    } else {
      source.style.removeProperty('display');
    }

    source.removeAttribute('aria-hidden');
  }

  function buildExtraNavClone(source) {
    if (!source) return null;

    var clone = source.cloneNode(true);
    clone.classList.add('af-rwd-extra-nav-clone');

    [clone].concat($all('[id]', clone)).forEach(function (node) {
      if (!node.id) return;
      node.setAttribute('data-af-rwd-cloned-id', node.id);
      node.removeAttribute('id');
    });

    [clone].concat($all('.af-aas-trigger', clone)).forEach(function (node) {
      if (!node || !node.getAttribute) return;
      node.setAttribute('data-af-rwd-proxy-account-trigger', '1');
    });

    return clone;
  }

  function findOriginalAccountSwitcherTrigger() {
    var refs = ensureRefs();
    var blockedContainers = [
      refs.drawer,
      refs.shell,
      $('.af-rwd-extra-nav-clone', refs.drawer),
      $('.af-rwd-main-nav-clone', refs.shell)
    ].filter(Boolean);

    function isBlocked(node) {
      if (!node) return true;
      for (var i = 0; i < blockedContainers.length; i++) {
        if (blockedContainers[i].contains(node)) return true;
      }
      return node.getAttribute('data-af-rwd-proxy-account-trigger') === '1';
    }

    var byIdCandidates = $all('#af_aas_trigger');
    for (var j = 0; j < byIdCandidates.length; j++) {
      if (isBlocked(byIdCandidates[j])) continue;
      return byIdCandidates[j];
    }

    var candidates = $all('.af-aas-trigger');
    for (var k = 0; k < candidates.length; k++) {
      if (isBlocked(candidates[k])) continue;
      return candidates[k];
    }

    return null;
  }

  function proxyExistingAccountSwitcherTrigger() {
    var original = findOriginalAccountSwitcherTrigger();
    if (!original) return false;

    try {
      original.dispatchEvent(new MouseEvent('click', {
        bubbles: true,
        cancelable: true,
        view: window
      }));
      return true;
    } catch (err) {
      if (typeof original.click === 'function') {
        original.click();
        return true;
      }
    }

    return false;
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
      restoreExtraNav();
      refs.drawer.innerHTML = '';
      setDrawerOpen(false);
      return;
    }

    if (state.extraSource && state.extraSource !== source) {
      setExtraSourceHidden(state.extraSource, false);
    }

    state.extraSource = source;
    refs.drawer.innerHTML = '';

    var clone = buildExtraNavClone(source);
    if (!clone) {
      setDrawerOpen(false);
      return;
    }

    setExtraSourceHidden(source, true);
    refs.drawer.appendChild(clone);

    body.classList.add('af-rwd-extra-nav-mounted');
  }

  function restoreExtraNav() {
    if (state.extraSource) {
      setExtraSourceHidden(state.extraSource, false);
    }

    if (state.extraPlaceholder && state.extraPlaceholder.parentNode) {
      state.extraPlaceholder.parentNode.removeChild(state.extraPlaceholder);
    }
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
      var rail = $('.af-apui-postbit-rail', inner);
      var profileFields = $('.af-apui-postbit-profilefields', inner);
      var plaqueSlot = $('.af-apui-postbit-plaque-slot', inner);

      var railHost = null;
      if (rail) {
        if (rail.parentNode === inner) {
          railHost = rail;
        } else {
          Array.prototype.slice.call(inner.children).some(function (child) {
            if (child === rail || (child.contains && child.contains(rail))) {
              railHost = child;
              return true;
            }
            return false;
          });
        }

        if (railHost) {
          railHost.classList.add('af-rwd-postbit-rail-host');
        }
      }

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

      if (railHost) {
        var railBeforeNode = extras || profileFields || plaqueSlot || null;
        var shouldMoveRail = false;

        if (railBeforeNode) {
          shouldMoveRail = railHost.nextElementSibling !== railBeforeNode;
        } else {
          shouldMoveRail = inner.lastElementChild !== railHost;
        }

        if (shouldMoveRail) {
          moveNode(railHost, inner, railBeforeNode, post);
        }
      }

      Array.prototype.slice.call(inner.children).forEach(function (child) {
        if (
          child === nameWrap ||
          child === rank ||
          child === avatarShell ||
          child === railHost ||
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
          if (node.classList && node.classList.contains('af-rwd-postbit-rail-host')) {
            node.classList.remove('af-rwd-postbit-rail-host');
          }
        });
        post.__afRwdMovedNodes = [];
      }

      var extras = $('.af-rwd-postbit-extras', post);
      if (extras && !extras.children.length) {
        extras.remove();
      }

      $all('.af-rwd-postbit-rail-host', post).forEach(function (node) {
        node.classList.remove('af-rwd-postbit-rail-host');
      });

      post.classList.remove('af-rwd-postbit-mobile-mounted');
    });
  }

  function railTextMeta(node) {
    if (!node) return '';
    return textNorm(
      (node.textContent || '') + ' ' +
      (node.getAttribute && node.getAttribute('title') || '') + ' ' +
      (node.getAttribute && node.getAttribute('aria-label') || '') + ' ' +
      (node.getAttribute && node.getAttribute('href') || '') + ' ' +
      (node.className || '')
    );
  }

  function findRailLevelNode(rail) {
    if (!rail) return null;

    var explicit = rail.querySelector(
      '.af-apui-postbit-rail__item--level, ' +
      '.af-apui-postbit-rail-item--level, ' +
      '[data-af-apui-rail-action="level"], ' +
      '[data-af-apui-action="level"]'
    );

    if (explicit) {
      return explicit.closest('li, .af-apui-postbit-rail__item, .af-apui-postbit-rail-item, a, button, [role="button"]') || explicit;
    }

    var nodes = $all('li, a, button, [role="button"], .af-apui-postbit-rail__item, .af-apui-postbit-rail-item', rail);
    for (var i = 0; i < nodes.length; i++) {
      var meta = railTextMeta(nodes[i]);
      if (meta.indexOf('уров') !== -1 || meta.indexOf('level') !== -1) {
        return nodes[i].closest('li, .af-apui-postbit-rail__item, .af-apui-postbit-rail-item, a, button, [role="button"]') || nodes[i];
      }
    }

    return null;
  }

  function railUnitNodes(rail) {
    if (!rail) return [];

    return Array.prototype.slice.call(rail.children).filter(function (node) {
      if (!node || node.nodeType !== 1) return false;

      var tag = (node.tagName || '').toLowerCase();
      return tag !== 'script' && tag !== 'style' && tag !== 'template';
    });
  }

  function isRailModalItem(node) {
    if (!node) return false;

    var selector = [
      '[data-af-apui-modal-url]',
      '[data-af-apui-modal]',
      '.af-apui-modal-trigger',
      '[data-modal-url]',
      '[href*="ajax=1"]',
      '[href*="modal="]'
    ].join(', ');

    if (node.matches && node.matches(selector)) {
      return true;
    }

    return !!(node.querySelector && node.querySelector(selector));
  }

  function normalizeRailStatTooltipSource(statItem) {
    if (!statItem) return;

    if (statItem.hasAttribute('title') && !statItem.hasAttribute('data-af-rwd-title-backup')) {
      statItem.setAttribute('data-af-rwd-title-backup', statItem.getAttribute('title') || '');
    }
    statItem.removeAttribute('title');

    if (statItem.hasAttribute('data-af-title') && !statItem.hasAttribute('data-af-rwd-data-title-backup')) {
      statItem.setAttribute('data-af-rwd-data-title-backup', statItem.getAttribute('data-af-title') || '');
    }
    statItem.removeAttribute('data-af-title');
  }

  function restoreRailStatTooltipSource(statItem) {
    if (!statItem) return;

    if (statItem.hasAttribute('data-af-rwd-title-backup')) {
      statItem.setAttribute('title', statItem.getAttribute('data-af-rwd-title-backup'));
      statItem.removeAttribute('data-af-rwd-title-backup');
    }

    if (statItem.hasAttribute('data-af-rwd-data-title-backup')) {
      statItem.setAttribute('data-af-title', statItem.getAttribute('data-af-rwd-data-title-backup'));
      statItem.removeAttribute('data-af-rwd-data-title-backup');
    }
  }

  function normalizeRailButtonsMobile() {
    if (!isMobile()) {
      restoreRailButtonsDesktop();
      return;
    }

    $all('.af-apui-postbit-rail').forEach(function (rail) {
      var host = rail.closest('.af-rwd-postbit-rail-host') || rail;
      var units = railUnitNodes(rail);
      var levelNode = findRailLevelNode(rail);
      var levelUnit = null;

      rail.classList.add('af-rwd-postbit-rail-ready');

      if (!units.length) {
        return;
      }

      units.forEach(function (unit, index) {
        if (!unit.hasAttribute('data-af-rwd-rail-order')) {
          unit.setAttribute('data-af-rwd-rail-order', String(index));
        }

        if (levelNode && (unit === levelNode || (unit.contains && unit.contains(levelNode)))) {
          levelUnit = unit;
        }
      });

      units.sort(function (a, b) {
        var aIsLevel = !!(levelUnit && a === levelUnit);
        var bIsLevel = !!(levelUnit && b === levelUnit);

        var aWeight = aIsLevel ? 2 : (isRailModalItem(a) ? 1 : 0);
        var bWeight = bIsLevel ? 2 : (isRailModalItem(b) ? 1 : 0);

        if (aWeight !== bWeight) {
          return aWeight - bWeight;
        }

        var aOrder = parseInt(a.getAttribute('data-af-rwd-rail-order') || '0', 10);
        var bOrder = parseInt(b.getAttribute('data-af-rwd-rail-order') || '0', 10);
        return aOrder - bOrder;
      });

      units.forEach(function (unit) {
        rail.appendChild(unit);

        unit.style.position = 'static';
        unit.style.inset = 'auto';
        unit.style.transform = 'none';
        unit.style.float = 'none';
        unit.style.display = 'block';
        unit.style.width = '26px';
        unit.style.height = '26px';
        unit.style.minWidth = '26px';
        unit.style.minHeight = '26px';
        unit.style.maxWidth = '26px';
        unit.style.maxHeight = '26px';
        unit.style.margin = '0';
        unit.style.padding = '0';
        unit.style.overflow = 'visible';

        if (unit.classList.contains('af-apui-stat-item')) {
          normalizeRailStatTooltipSource(unit);
          unit.classList.remove('is-open');
          unit.setAttribute('aria-expanded', 'false');
        }

        $all('.af-apui-stat-item', unit).forEach(function (nestedStat) {
          normalizeRailStatTooltipSource(nestedStat);
          nestedStat.classList.remove('is-open');
          nestedStat.setAttribute('aria-expanded', 'false');
        });

        var trigger = null;
        if (unit.matches('a, button, [role="button"]')) {
          trigger = unit;
        } else if (unit.querySelector) {
          trigger = unit.querySelector('a, button, [role="button"]');
        }

        if (trigger) {
          trigger.style.position = 'static';
          trigger.style.inset = 'auto';
          trigger.style.transform = 'none';
          trigger.style.float = 'none';
          trigger.style.display = 'flex';
          trigger.style.alignItems = 'center';
          trigger.style.justifyContent = 'center';
          trigger.style.width = '26px';
          trigger.style.height = '26px';
          trigger.style.minWidth = '26px';
          trigger.style.minHeight = '26px';
          trigger.style.maxWidth = '26px';
          trigger.style.maxHeight = '26px';
          trigger.style.margin = '0';
          trigger.style.padding = '0';
          trigger.style.whiteSpace = 'nowrap';
        }
      });

      host.style.position = 'static';
      host.style.inset = 'auto';
      host.style.transform = 'none';
      host.style.float = 'none';
      host.style.clear = 'none';
      host.style.display = 'block';
      host.style.width = '100%';
      host.style.maxWidth = '280px';
      host.style.minWidth = '0';
      host.style.height = '60px';
      host.style.minHeight = '60px';
      host.style.maxHeight = '60px';
      host.style.margin = '0';
      host.style.padding = '0';
      host.style.overflow = 'hidden';

      rail.style.position = 'static';
      rail.style.inset = 'auto';
      rail.style.transform = 'none';
      rail.style.float = 'none';
      rail.style.clear = 'none';
      rail.style.display = 'grid';
      rail.style.gridAutoFlow = 'column';
      rail.style.gridTemplateRows = 'repeat(2, 26px)';
      rail.style.gridAutoColumns = '26px';
      rail.style.columnGap = '8px';
      rail.style.rowGap = '8px';
      rail.style.justifyContent = 'start';
      rail.style.alignContent = 'start';
      rail.style.width = '100%';
      rail.style.maxWidth = '280px';
      rail.style.minWidth = '0';
      rail.style.height = '60px';
      rail.style.minHeight = '60px';
      rail.style.maxHeight = '60px';
      rail.style.margin = '0';
      rail.style.padding = '0';
      rail.style.overflow = 'hidden';

      if (levelUnit && !levelUnit.hasAttribute('data-af-rwd-hidden-level')) {
        levelUnit.setAttribute('data-af-rwd-hidden-level', '1');
        levelUnit.setAttribute('data-af-rwd-prev-display', levelUnit.style.display || '');
        levelUnit.style.display = 'none';
        levelUnit.setAttribute('aria-hidden', 'true');
      }
    });
  }

  function restoreRailButtonsDesktop() {
    var props = [
      'position',
      'inset',
      'top',
      'right',
      'bottom',
      'left',
      'transform',
      'float',
      'clear',
      'display',
      'width',
      'height',
      'min-width',
      'min-height',
      'max-width',
      'max-height',
      'margin',
      'padding',
      'overflow',
      'white-space',
      'justify-content',
      'align-content',
      'align-items',
      'font-size',
      'line-height',
      'grid-template-columns',
      'grid-template-rows',
      'grid-auto-columns',
      'grid-auto-rows',
      'grid-auto-flow',
      'column-gap',
      'row-gap'
    ];

    $all('.af-apui-postbit-rail').forEach(function (rail) {
      var units = railUnitNodes(rail);

      units.sort(function (a, b) {
        var aOrder = parseInt(a.getAttribute('data-af-rwd-rail-order') || '0', 10);
        var bOrder = parseInt(b.getAttribute('data-af-rwd-rail-order') || '0', 10);
        return aOrder - bOrder;
      });

      units.forEach(function (unit) {
        if (unit.parentNode === rail) {
          rail.appendChild(unit);
        }

        props.forEach(function (prop) {
          unit.style.removeProperty(prop);
        });

        if (unit.classList.contains('af-apui-stat-item')) {
          restoreRailStatTooltipSource(unit);
        }

        $all('.af-apui-stat-item', unit).forEach(function (nestedStat) {
          restoreRailStatTooltipSource(nestedStat);
        });

        var trigger = null;
        if (unit.matches('a, button, [role="button"]')) {
          trigger = unit;
        } else if (unit.querySelector) {
          trigger = unit.querySelector('a, button, [role="button"]');
        }

        if (trigger) {
          props.forEach(function (prop) {
            trigger.style.removeProperty(prop);
          });
        }
      });

      props.forEach(function (prop) {
        rail.style.removeProperty(prop);
      });

      var host = rail.closest('.af-rwd-postbit-rail-host');
      if (host) {
        props.forEach(function (prop) {
          host.style.removeProperty(prop);
        });
      }

      rail.classList.remove('af-rwd-postbit-rail-ready');
    });

    $all('[data-af-rwd-hidden-level="1"]').forEach(function (node) {
      var prevDisplay = node.getAttribute('data-af-rwd-prev-display');
      if (prevDisplay) {
        node.style.display = prevDisplay;
      } else {
        node.style.removeProperty('display');
      }

      node.removeAttribute('data-af-rwd-hidden-level');
      node.removeAttribute('data-af-rwd-prev-display');
      node.removeAttribute('aria-hidden');
    });
  }

  function isNodeVisible(node) {
    return !!(node && document.body.contains(node) && (node.offsetWidth || node.offsetHeight || node.getClientRects().length));
  }

  function findUserdetailsPopup(trigger) {
    if (!trigger) return null;

    if (trigger.closest && trigger.closest('.af-apui-postbit-rail .af-apui-stat-item')) {
      return null;
    }

    var scopes = [];
    var item = trigger.closest('.af-apui-stat-item, .author_statistics > *, .af-apui-postbit-userdetails > *');
    var root = trigger.closest('.author_statistics, .af-apui-postbit-userdetails');

    if (item) scopes.push(item);
    if (trigger.parentNode) scopes.push(trigger.parentNode);
    if (root) scopes.push(root);

    var popupSelector = [
      '.popup_menu',
      '.author_popup',
      '.af-apui-stat-item__popup',
      '.af-apui-stat-item__tooltip',
      '.af-apui-stat-item__details',
      '.af-apui-stat-item__panel',
      '.af-apui-stat-item__popover',
      '.af-apui-stat-item__dropdown',
      '.af-apui-stat-item__content',
      '.af-apui-stat-item__body',
      '.af-apui-stat-item__expanded',
      '.af-apui-stat-item__box',
      '[class*="tooltip"]',
      '[class*="popover"]',
      '[class*="popup"]',
      '[role="tooltip"]'
    ].join(', ');

    for (var i = 0; i < scopes.length; i++) {
      var scope = scopes[i];
      if (!scope) continue;

      var popups = $all(popupSelector, scope);
      for (var j = 0; j < popups.length; j++) {
        if (popups[j].closest('.post_controls')) continue;
        if (isNodeVisible(popups[j])) {
          return popups[j];
        }
      }
    }

    if (item) {
      var descendants = $all('*', item).filter(function (node) {
        if (!isNodeVisible(node)) return false;
        if (node === trigger) return false;
        if (node.contains && node.contains(trigger)) return false;
        if (trigger.contains && trigger.contains(node)) return false;

        var cls = textNorm(node.className || '');
        if (
          cls.indexOf('label') !== -1 ||
          cls.indexOf('icon') !== -1 ||
          cls.indexOf('value') !== -1 ||
          cls.indexOf('trigger') !== -1
        ) {
          return false;
        }

        var text = textNorm(node.textContent || '');
        var hasMedia = !!node.querySelector('img, svg, canvas, iframe');
        return !!text || hasMedia;
      });

      if (descendants.length) {
        return descendants[descendants.length - 1];
      }
    }

    return null;
  }

  function clearActiveUserdetailsPopup() {
    if (!state.activeTooltip) return;

    var popup = state.activeTooltip.popup;
    if (popup) {
      popup.classList.remove('af-rwd-mobile-userdetails-popup');
      popup.style.removeProperty('position');
      popup.style.removeProperty('left');
      popup.style.removeProperty('top');
      popup.style.removeProperty('right');
      popup.style.removeProperty('bottom');
      popup.style.removeProperty('transform');
      popup.style.removeProperty('margin');
      popup.style.removeProperty('max-width');
      popup.style.removeProperty('z-index');
    }

    state.activeTooltip = null;
  }

  function positionActiveUserdetailsPopup() {
    if (!state.activeTooltip) return;
    if (!isMobile()) {
      clearActiveUserdetailsPopup();
      return;
    }

    var popup = state.activeTooltip.popup;
    var trigger = state.activeTooltip.trigger;

    if (!popup || !trigger || !document.body.contains(popup) || !document.body.contains(trigger) || !isNodeVisible(popup)) {
      clearActiveUserdetailsPopup();
      return;
    }

    var triggerRect = trigger.getBoundingClientRect();
    var viewportPad = 8;
    var maxWidth = Math.min(window.innerWidth - (viewportPad * 2), 220);

    popup.classList.add('af-rwd-mobile-userdetails-popup');
    popup.style.position = 'fixed';
    popup.style.left = viewportPad + 'px';
    popup.style.top = viewportPad + 'px';
    popup.style.right = 'auto';
    popup.style.bottom = 'auto';
    popup.style.transform = 'none';
    popup.style.margin = '0';
    popup.style.maxWidth = maxWidth + 'px';
    popup.style.zIndex = '10050';

    var popupRect = popup.getBoundingClientRect();
    var width = Math.ceil(popupRect.width || popup.offsetWidth || 160);
    var height = Math.ceil(popupRect.height || popup.offsetHeight || 80);

    var left = triggerRect.left + (triggerRect.width / 2) - (width / 2);
    left = Math.max(viewportPad, Math.min(left, window.innerWidth - width - viewportPad));

    var top = triggerRect.top - height - 8;
    if (top < viewportPad) {
      top = triggerRect.bottom + 8;
    }
    if (top + height > window.innerHeight - viewportPad) {
      top = Math.max(viewportPad, window.innerHeight - height - viewportPad);
    }

    popup.style.left = Math.round(left) + 'px';
    popup.style.top = Math.round(top) + 'px';
  }

  function scheduleUserdetailsPopupPosition(trigger) {
    if (!trigger || !isMobile()) return;

    if (trigger.closest && trigger.closest('.af-apui-postbit-rail .af-apui-stat-item')) {
      return;
    }

    state.userdetailsTooltipToken += 1;
    var token = state.userdetailsTooltipToken;
    var delays = [0, 16, 40, 80, 140, 220, 320];

    clearTimeout(state.userdetailsTooltipTimer);

    delays.forEach(function (delay, index) {
      setTimeout(function () {
        if (token !== state.userdetailsTooltipToken) return;

        var popup = findUserdetailsPopup(trigger);

        if (!popup) {
          if (index === delays.length - 1) {
            clearActiveUserdetailsPopup();
          }
          return;
        }

        if (!state.activeTooltip || state.activeTooltip.popup !== popup || state.activeTooltip.trigger !== trigger) {
          clearActiveUserdetailsPopup();
          state.activeTooltip = {
            trigger: trigger,
            popup: popup
          };
        }

        positionActiveUserdetailsPopup();
      }, delay);
    });

    state.userdetailsTooltipTimer = setTimeout(function () {}, delays[delays.length - 1]);
  }

  function stabilizeAvatars() {
    if (!isMobile()) return;

    $all('.post .author_avatar img, .af-apui-postbit-avatar-shell img, .af-apui-author-avatar img').forEach(function (img) {
      img.style.width = '64px';
      img.style.height = '64px';
      img.style.maxWidth = '64px';
      img.style.maxHeight = '64px';
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
      normalizeRailButtonsMobile();
      markMobileTables();
      stabilizeAvatars();
      syncModalClass();
      updateMainNavOffset();
      syncNavButtons();
      positionActiveUserdetailsPopup();
    } else {
      setDrawerOpen(false);
      clearActiveUserdetailsPopup();
      restoreExtraNav();
      restoreRailButtonsDesktop();
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

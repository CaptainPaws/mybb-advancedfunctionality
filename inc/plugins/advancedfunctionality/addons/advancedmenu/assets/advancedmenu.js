/* AdvancedMenu: one drawer, registry-backed sections. */
(function () {
  'use strict';
  var navigation = document.querySelector('[data-af-am-navigation]');
  if (!navigation || navigation.dataset.afAmBound) return;
  navigation.dataset.afAmBound = '1';
  var triggers = Array.from(document.querySelectorAll('[data-af-am-category], .af-am-burger'));
  var shell = document.querySelector('[data-af-am-drawer-shell]');
  var drawer = document.getElementById('af-am-user-drawer');
  if (!triggers.length || !shell || !drawer) return;
  var closeButton = shell.querySelector('.af-am-drawer-close');
  var overlay = shell.querySelector('.af-am-drawer-overlay');
  var tabs = Array.from(drawer.querySelectorAll('[role="tab"][data-af-am-tab]'));
  var panels = drawer.querySelectorAll('[role="tabpanel"][data-af-am-panel]');
  var lastFocus = null, activeSection = '';

  function activateTab(tab, moveFocus) {
    if (!tab) return;
    activeSection = tab.getAttribute('data-af-am-tab');
    tabs.forEach(function (item) {
      var active = item === tab;
      item.classList.toggle('is-active', active);
      item.setAttribute('aria-selected', active ? 'true' : 'false');
      item.setAttribute('tabindex', active ? '0' : '-1');
    });
    panels.forEach(function (panel) {
      var active = panel.getAttribute('data-af-am-panel') === activeSection;
      panel.hidden = !active;
      panel.classList.toggle('is-active', active);
    });
    var title = drawer.querySelector('[data-af-am-drawer-title]');
    if (title) title.textContent = tab.textContent;
    triggers.forEach(function (trigger) {
      var active = !shell.hidden && (!trigger.dataset.afAmCategory || trigger.dataset.afAmCategory === activeSection);
      trigger.classList.toggle('is-active', active);
      trigger.setAttribute('aria-expanded', active ? 'true' : 'false');
    });
    if (moveFocus) tab.focus();
  }
  tabs.forEach(function (tab, index) {
    tab.addEventListener('click', function () { activateTab(tab, false); });
    tab.addEventListener('keydown', function (event) {
      var next = null;
      if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = tabs[(index + 1) % tabs.length];
      else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') next = tabs[(index - 1 + tabs.length) % tabs.length];
      else if (event.key === 'Home') next = tabs[0];
      else if (event.key === 'End') next = tabs[tabs.length - 1];
      if (!next) return;
      event.preventDefault(); activateTab(next, true);
    });
  });
  function focusables() {
    return Array.from(drawer.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
      .filter(function (node) { return !node.closest('[hidden]') && node.getClientRects().length > 0; });
  }
  function openDrawer(trigger) {
    hideTips();
    lastFocus = trigger;
    shell.hidden = false;
    document.body.classList.add('af-am-drawer-open');
    activateTab(tabs.find(function (tab) { return tab.dataset.afAmTab === trigger.dataset.afAmCategory; }) || tabs[0], false);
    (closeButton || drawer).focus();
  }
  function closeDrawer(restoreFocus) {
    shell.hidden = true;
    document.body.classList.remove('af-am-drawer-open');
    triggers.forEach(function (trigger) { trigger.setAttribute('aria-expanded', 'false'); trigger.classList.remove('is-active'); });
    if (restoreFocus !== false && lastFocus && lastFocus.isConnected) lastFocus.focus();
  }
  triggers.forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      if (!shell.hidden && (!trigger.dataset.afAmCategory || trigger.dataset.afAmCategory === activeSection)) closeDrawer();
      else openDrawer(trigger);
    });
  });
  closeButton.addEventListener('click', function () { closeDrawer(); });
  overlay.addEventListener('click', function () { closeDrawer(); });
  drawer.addEventListener('click', function (event) {
    if (event.target.closest('a')) closeDrawer();
  });
  document.addEventListener('click', function (event) {
    if (!shell.hidden && !drawer.contains(event.target) && !event.target.closest('[data-af-am-category], .af-am-burger')) closeDrawer();
  });
  document.addEventListener('keydown', function (event) {
    if (shell.hidden) { if (event.key === 'Escape') hideTips(); return; }
    if (event.key === 'Escape') { event.preventDefault(); closeDrawer(); return; }
    if (event.key !== 'Tab') return;
    var nodes = focusables();
    if (!nodes.length) { event.preventDefault(); drawer.focus(); return; }
    var first = nodes[0], last = nodes[nodes.length - 1];
    if (event.shiftKey && (document.activeElement === first || !drawer.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && (document.activeElement === last || !drawer.contains(document.activeElement))) { event.preventDefault(); first.focus(); }
  });
  // Extend the existing data-af-am-tip contract with one shared portal. The
  // avatar retains its rich tooltip, using the same positioning/lifecycle.
  var rail = navigation.querySelector('.af-am-rail');
  var railScroll = rail.querySelector('.af-am-rail-scroll');
  var avatar = rail.querySelector('.af-am-avatar-control');
  var accountTip = document.getElementById('af-am-account-tooltip');
  var tooltip = document.createElement('div');
  tooltip.id = 'af-am-rail-tooltip'; tooltip.className = 'af-am-tooltip';
  tooltip.setAttribute('role', 'tooltip'); tooltip.hidden = true;
  navigation.appendChild(tooltip);
  if (accountTip) { accountTip.hidden = true; navigation.appendChild(accountTip); }
  var tipTarget = null, activeTip = null;
  rail.querySelectorAll('.af-am-link, .af-am-guest-action, [data-af-am-category], .af-am-burger').forEach(function (link) {
    var title = link.querySelector('.af-am-title');
    var name = title ? title.textContent.trim() : link.getAttribute('aria-label') || link.textContent.trim();
    if (!link.hasAttribute('aria-label')) link.setAttribute('aria-label', name);
    if (!link.hasAttribute('data-af-am-tip')) link.setAttribute('data-af-am-tip', name);
    link.dataset.afAmInitial = Array.from(name)[0] || '·';
    link.removeAttribute('title'); // The accessible portal replaces native-only hints.
  });
  if (avatar) avatar.querySelector('a, .af-am-guest-avatar').removeAttribute('title');
  function hideTips() {
    tooltip.hidden = true; if (accountTip) accountTip.hidden = true;
    if (tipTarget && activeTip === tooltip) {
      var described = (tipTarget.getAttribute('aria-describedby') || '').split(/\s+/).filter(function (id) { return id && id !== tooltip.id; });
      if (described.length) tipTarget.setAttribute('aria-describedby', described.join(' '));
      else tipTarget.removeAttribute('aria-describedby');
    }
    tipTarget = activeTip = null;
  }
  function positionTip() {
    if (!tipTarget || !activeTip) return;
    var box = tipTarget.getBoundingClientRect(), bounds = railScroll.getBoundingClientRect();
    if (box.bottom <= bounds.top || box.top >= bounds.bottom || box.right <= bounds.left || box.left >= bounds.right) { hideTips(); return; }
    var mobile = window.matchMedia('(max-width: 768px)').matches;
    var size = activeTip.getBoundingClientRect(), railBox = rail.getBoundingClientRect();
    var x = mobile ? box.left : railBox.right + 8;
    var y = mobile ? railBox.top - size.height - 8 : box.top + (box.height - size.height) / 2;
    activeTip.style.left = Math.max(8, Math.min(x, window.innerWidth - size.width - 8)) + 'px';
    activeTip.style.top = Math.max(8, Math.min(y, window.innerHeight - size.height - 8)) + 'px';
  }
  function showTip(target) {
    hideTips();
    if (!shell.hidden || !target) return;
    var rich = avatar && avatar.contains(target);
    activeTip = rich ? accountTip : tooltip;
    if (!activeTip) return;
    tipTarget = target;
    if (!rich) {
      var name = target.getAttribute('aria-label') || '', hint = target.dataset.afAmTip || name;
      tooltip.textContent = name && name !== hint ? name + ' — ' + hint : hint;
      var described = target.getAttribute('aria-describedby') || '';
      target.setAttribute('aria-describedby', (described + ' ' + tooltip.id).trim());
    }
    activeTip.hidden = false; positionTip();
  }
  function tipTrigger(target) {
    return target instanceof Element ? target.closest('[data-af-am-tip], .af-am-avatar-control > a, .af-am-guest-avatar') : null;
  }
  rail.addEventListener('mouseover', function (event) {
    var target = tipTrigger(event.target);
    if (target && !target.contains(event.relatedTarget)) showTip(target);
  });
  rail.addEventListener('mouseout', function (event) {
    var target = tipTrigger(event.target);
    if (target && !target.contains(event.relatedTarget) && document.activeElement !== target && !(avatar && avatar.classList.contains('is-info-open'))) hideTips();
  });
  rail.addEventListener('focusin', function (event) { showTip(tipTrigger(event.target)); });
  rail.addEventListener('focusout', function () { if (!(avatar && avatar.classList.contains('is-info-open'))) hideTips(); });
  railScroll.addEventListener('scroll', positionTip, { passive: true });
  window.addEventListener('resize', positionTip);
  if (avatar && avatar.querySelector('.af-am-account-info')) {
    var info = avatar.querySelector('.af-am-account-info');
    info.addEventListener('click', function () {
      var visible = avatar.classList.toggle('is-info-open'); info.setAttribute('aria-expanded', String(visible));
      if (visible) showTip(avatar.querySelector('a')); else hideTips();
    });
    document.addEventListener('click', function (event) {
      if (!avatar.contains(event.target)) { avatar.classList.remove('is-info-open'); info.setAttribute('aria-expanded', 'false'); if (activeTip === accountTip) hideTips(); }
    });
    avatar.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { avatar.classList.remove('is-info-open'); info.setAttribute('aria-expanded', 'false'); hideTips(); info.focus(); }
    });
  }
  var guestAvatar = rail.querySelector('.af-am-guest-avatar');
  if (guestAvatar) guestAvatar.addEventListener('click', function () { showTip(guestAvatar); });
  document.documentElement.classList.add('af-advancedmenu-ready');
})();

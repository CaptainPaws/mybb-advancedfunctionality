/* AdvancedMenu: one drawer, registry-backed sections. */
(function () {
  'use strict';
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
    if (event.target.closest('a')) closeDrawer(false);
  });
  document.addEventListener('click', function (event) {
    if (!shell.hidden && !drawer.contains(event.target) && !event.target.closest('[data-af-am-category], .af-am-burger')) closeDrawer();
  });
  document.addEventListener('keydown', function (event) {
    if (shell.hidden) return;
    if (event.key === 'Escape') { event.preventDefault(); closeDrawer(); return; }
    if (event.key !== 'Tab') return;
    var nodes = focusables();
    if (!nodes.length) { event.preventDefault(); drawer.focus(); return; }
    var first = nodes[0], last = nodes[nodes.length - 1];
    if (event.shiftKey && (document.activeElement === first || !drawer.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && (document.activeElement === last || !drawer.contains(document.activeElement))) { event.preventDefault(); first.focus(); }
  });
  var avatar = document.querySelector('.af-am-avatar-control');
  if (avatar) {
    var info = avatar.querySelector('.af-am-account-info');
    info.addEventListener('click', function () {
      var visible = avatar.classList.toggle('is-info-open'); info.setAttribute('aria-expanded', String(visible));
    });
    document.addEventListener('click', function (event) { if (!avatar.contains(event.target)) { avatar.classList.remove('is-info-open'); info.setAttribute('aria-expanded', 'false'); } });
    avatar.addEventListener('keydown', function (event) { if (event.key === 'Escape') { avatar.classList.remove('is-info-open'); info.setAttribute('aria-expanded', 'false'); info.focus(); } });
  }
  document.documentElement.classList.add('af-advancedmenu-ready');
})();

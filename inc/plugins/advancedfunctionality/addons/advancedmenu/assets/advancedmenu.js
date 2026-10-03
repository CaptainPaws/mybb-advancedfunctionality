/* AdvancedMenu user drawer */
(function () {
  'use strict';
  var trigger = document.querySelector('.af-am-burger');
  var shell = document.querySelector('[data-af-am-drawer-shell]');
  var drawer = document.getElementById('af-am-user-drawer');
  if (!trigger || !shell || !drawer) return;
  var closeButton = shell.querySelector('.af-am-drawer-close');
  var overlay = shell.querySelector('.af-am-drawer-overlay');
  var tabs = drawer.querySelectorAll('[role="tab"][data-af-am-tab]');
  var panels = drawer.querySelectorAll('[role="tabpanel"][data-af-am-panel]');
  var lastFocus = null;

  function activateTab(tab, moveFocus) {
    if (!tab) return;
    var target = tab.getAttribute('data-af-am-tab');
    tabs.forEach(function (item) {
      var active = item === tab;
      item.classList.toggle('is-active', active);
      item.setAttribute('aria-selected', active ? 'true' : 'false');
      item.setAttribute('tabindex', active ? '0' : '-1');
    });
    panels.forEach(function (panel) {
      var active = panel.getAttribute('data-af-am-panel') === target;
      panel.hidden = !active;
      panel.classList.toggle('is-active', active);
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
      event.preventDefault();
      activateTab(next, true);
    });
  });

  function focusables() {
    return drawer.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])');
  }
  function openDrawer() {
    lastFocus = document.activeElement;
    shell.hidden = false;
    document.body.classList.add('af-am-drawer-open');
    trigger.setAttribute('aria-expanded', 'true');
    (closeButton || drawer).focus();
  }
  function closeDrawer() {
    shell.hidden = true;
    document.body.classList.remove('af-am-drawer-open');
    trigger.setAttribute('aria-expanded', 'false');
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  trigger.addEventListener('click', openDrawer);
  closeButton.addEventListener('click', closeDrawer);
  overlay.addEventListener('click', closeDrawer);
  drawer.addEventListener('click', function (event) {
    if (event.target.closest('a')) window.setTimeout(closeDrawer, 0);
  });
  document.addEventListener('keydown', function (event) {
    if (shell.hidden) return;
    if (event.key === 'Escape') { event.preventDefault(); closeDrawer(); return; }
    if (event.key !== 'Tab') return;
    var nodes = focusables();
    if (!nodes.length) { event.preventDefault(); drawer.focus(); return; }
    var first = nodes[0], last = nodes[nodes.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });
  document.documentElement.classList.add('af-advancedmenu-ready');
})();

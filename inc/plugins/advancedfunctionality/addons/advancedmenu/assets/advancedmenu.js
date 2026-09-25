/* AdvancedMenu user drawer */
(function () {
  'use strict';
  var trigger = document.querySelector('.af-am-burger');
  var shell = document.querySelector('[data-af-am-drawer-shell]');
  var drawer = document.getElementById('af-am-user-drawer');
  if (!trigger || !shell || !drawer) return;
  var closeButton = shell.querySelector('.af-am-drawer-close');
  var overlay = shell.querySelector('.af-am-drawer-overlay');
  var lastFocus = null;

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

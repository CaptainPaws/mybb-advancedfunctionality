/* One delegated dropdown interaction for permission-checked AF management menus. */
(function () {
  'use strict';
  if (window.afAtfDropdownBound) return;
  window.afAtfDropdownBound = true;
  var active = null;

  function items(panel) {
    return Array.prototype.slice.call(panel.querySelectorAll('[role="menuitem"]:not([disabled])'));
  }
  function position(menu) {
    var rect = menu.button.getBoundingClientRect();
    var width = menu.panel.getBoundingClientRect().width;
    menu.panel.style.left = Math.max(12, Math.min(rect.right - width, window.innerWidth - width - 12)) + 'px';
    menu.panel.style.top = (rect.bottom + 6) + 'px';
    menu.panel.style.maxHeight = Math.max(48, window.innerHeight - rect.bottom - 18) + 'px';
  }
  function close(restoreFocus) {
    if (!active) return;
    var menu = active;
    active = null;
    if (menu.topLayer) menu.panel.hidePopover();
    menu.panel.hidden = true;
    menu.button.setAttribute('aria-expanded', 'false');
    if (menu.placeholder) menu.placeholder.replaceWith(menu.panel);
    if (restoreFocus && menu.button.isConnected) menu.button.focus();
  }
  function open(button, focusLast) {
    close(false);
    var root = button.closest('.atf-dropdown');
    var panel = root && root.querySelector('.atf-dropdown__panel');
    if (!panel) return;
    var menu = { root: root, panel: panel, button: button, topLayer: typeof panel.showPopover === 'function' };
    // The portal also isolates presentation from addon ancestor selectors.
    menu.placeholder = document.createComment('atf-dropdown');
    panel.replaceWith(menu.placeholder);
    document.body.appendChild(panel);
    if (menu.topLayer) panel.setAttribute('popover', 'manual');
    panel.hidden = false;
    if (menu.topLayer) panel.showPopover();
    button.setAttribute('aria-expanded', 'true');
    active = menu;
    position(menu);
    var list = items(panel);
    if (list.length) list[focusLast ? list.length - 1 : 0].focus();
  }
  document.addEventListener('click', function (event) {
    if (!document.body.classList.contains('atf-active')) return;
    var button = event.target.closest('.atf-dropdown__toggle');
    if (button) {
      if (active && active.button === button) close(false);
      else open(button, false);
      return;
    }
    if (active && !active.panel.contains(event.target)) close(false);
  });
  document.addEventListener('keydown', function (event) {
    if (!document.body.classList.contains('atf-active')) return;
    if (event.key === 'Escape' && active) {
      event.preventDefault(); close(true); return;
    }
    var button = event.target.closest('.atf-dropdown__toggle');
    if (button && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
      event.preventDefault(); open(button, event.key === 'ArrowUp'); return;
    }
    if (!active || !active.panel.contains(event.target)) return;
    var list = items(active.panel);
    var index = list.indexOf(event.target);
    if (index < 0 || !list.length) return;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault(); list[(index + (event.key === 'ArrowDown' ? 1 : list.length - 1)) % list.length].focus();
    } else if (event.key === 'Home' || event.key === 'End') {
      event.preventDefault(); list[event.key === 'Home' ? 0 : list.length - 1].focus();
    }
  });
  document.addEventListener('focusin', function (event) {
    if (active && event.target !== active.button && !active.panel.contains(event.target)) close(false);
  });
  // Wanted owns its confirmation message; KB retains its existing inline confirmation/CSRF form.
  document.addEventListener('submit', function (event) {
    if (!active || !active.panel.contains(event.target)) return;
    var message = event.target.getAttribute('data-af-wanted-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault(); close(true);
    } else close(false);
  });
  window.addEventListener('resize', function () { if (active) position(active); });
  document.addEventListener('scroll', function () { if (active) position(active); }, true);
})();

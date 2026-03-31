(function () {
  'use strict';

  if (window.__afAeTabsPackLoaded) return;
  window.__afAeTabsPackLoaded = true;

  if (!window.afAeBuiltinHandlers) {
    window.afAeBuiltinHandlers = Object.create(null);
  }

  var POSITIONS = ['top', 'bottom', 'left', 'right'];

  function asText(x) {
    return String(x == null ? '' : x);
  }

  function sanitizePosition(position) {
    position = asText(position).toLowerCase().trim();
    return POSITIONS.indexOf(position) !== -1 ? position : 'top';
  }

  function buildTemplate(position) {
    position = sanitizePosition(position);
    return '[tabs position="' + position + '"]\n'
      + '[tab title="Вкладка 1"]\n'
      + 'Контент вкладки 1\n'
      + '[/tab]\n'
      + '[tab title="Вкладка 2"]\n'
      + 'Контент вкладки 2\n'
      + '[/tab]\n'
      + '[/tabs]';
  }

  function insertTemplate(inst, position) {
    var template = buildTemplate(position);

    try {
      if (inst && typeof inst.insertText === 'function') {
        inst.insertText(template, '');
        return true;
      }
    } catch (e0) {}

    try {
      if (inst && typeof inst.insert === 'function') {
        inst.insert(template, '');
        return true;
      }
    } catch (e1) {}

    try {
      if (inst && typeof inst.val === 'function') {
        inst.val(asText(inst.val()) + template);
        return true;
      }
    } catch (e2) {}

    var ta = document.querySelector('textarea#message') || document.querySelector('textarea[name="message"]');
    if (!ta) return false;

    try {
      var start = (typeof ta.selectionStart === 'number') ? ta.selectionStart : ta.value.length;
      var end = (typeof ta.selectionEnd === 'number') ? ta.selectionEnd : start;
      var val = asText(ta.value);
      ta.value = val.slice(0, start) + template + val.slice(end);
      var pos = start + template.length;
      ta.focus();
      ta.setSelectionRange(pos, pos);
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      return true;
    } catch (e3) {
      return false;
    }
  }

  function removeMenu(menu) {
    if (!menu || !menu.parentNode) return;
    menu.parentNode.removeChild(menu);
  }

  function openPositionMenu(caller, onPick) {
    if (!caller || typeof caller.getBoundingClientRect !== 'function') return false;

    var menu = document.createElement('div');
    menu.className = 'af-ae-tabs-position-menu';
    menu.setAttribute('role', 'menu');
    menu.style.position = 'fixed';
    menu.style.zIndex = '100000';
    menu.style.minWidth = '160px';
    menu.style.padding = '6px';
    menu.style.borderRadius = '8px';
    menu.style.border = '1px solid #3c4656';
    menu.style.background = '#1d2430';
    menu.style.boxShadow = '0 10px 30px rgba(0,0,0,.3)';

    var rect = caller.getBoundingClientRect();
    menu.style.left = Math.max(8, Math.round(rect.left)) + 'px';
    menu.style.top = Math.max(8, Math.round(rect.bottom + 6)) + 'px';

    function pick(pos) {
      removeMenu(menu);
      if (typeof onPick === 'function') onPick(pos);
    }

    POSITIONS.forEach(function (position) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'af-ae-tabs-position-menu__item';
      btn.setAttribute('role', 'menuitem');
      btn.setAttribute('data-pos', position);
      btn.style.display = 'block';
      btn.style.width = '100%';
      btn.style.textAlign = 'left';
      btn.style.margin = '0';
      btn.style.padding = '8px 10px';
      btn.style.border = '0';
      btn.style.borderRadius = '6px';
      btn.style.background = 'transparent';
      btn.style.color = '#e9edf4';
      btn.style.cursor = 'pointer';
      btn.textContent = 'Tabs ' + position;

      btn.addEventListener('mouseenter', function () {
        btn.style.background = '#2e3f58';
      }, false);
      btn.addEventListener('mouseleave', function () {
        btn.style.background = 'transparent';
      }, false);
      btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        pick(position);
      }, false);

      menu.appendChild(btn);
    });

    document.body.appendChild(menu);

    function onDocClick(ev) {
      if (!menu.contains(ev.target) && ev.target !== caller) {
        cleanup();
      }
    }

    function onEsc(ev) {
      if (ev.key === 'Escape') {
        cleanup();
      }
    }

    function cleanup() {
      document.removeEventListener('mousedown', onDocClick, true);
      document.removeEventListener('keydown', onEsc, true);
      removeMenu(menu);
    }

    document.addEventListener('mousedown', onDocClick, true);
    document.addEventListener('keydown', onEsc, true);

    return true;
  }

  function askPosition(caller, callback) {
    if (openPositionMenu(caller, callback)) return;

    var raw = window.prompt('Положение tabs: top, bottom, left или right', 'top');
    if (raw == null) return;

    callback(sanitizePosition(raw));
  }

  window.af_ae_tabs_exec = function (inst, caller) {
    askPosition(caller || null, function (position) {
      insertTemplate(inst, position);
    });
  };

  window.afAeBuiltinHandlers.tabs = window.af_ae_tabs_exec;

  function activateTabs(root, idx) {
    if (!root) return;

    var tabs = root.querySelectorAll('[data-af-tabs-trigger]');
    var panels = root.querySelectorAll('[data-af-tabs-panel]');

    if (!tabs.length || !panels.length) return;

    if (idx < 0 || idx >= tabs.length) idx = 0;

    for (var i = 0; i < tabs.length; i += 1) {
      var active = i === idx;
      tabs[i].setAttribute('aria-selected', active ? 'true' : 'false');
      tabs[i].setAttribute('tabindex', active ? '0' : '-1');
      tabs[i].classList.toggle('is-active', active);
    }

    for (var j = 0; j < panels.length; j += 1) {
      var pActive = j === idx;
      panels[j].hidden = !pActive;
      panels[j].classList.toggle('is-active', pActive);
    }

    root.setAttribute('data-active-index', String(idx));
  }

  function initTabs(root) {
    if (!root || root.getAttribute('data-af-tabs-init') === '1') return;
    root.setAttribute('data-af-tabs-init', '1');

    var first = 0;
    var tabs = root.querySelectorAll('[data-af-tabs-trigger]');

    for (var i = 0; i < tabs.length; i += 1) {
      if (tabs[i].getAttribute('aria-selected') === 'true') {
        first = i;
        break;
      }
    }

    activateTabs(root, first);
  }

  function initAll() {
    var roots = document.querySelectorAll('[data-af-tabs-root]');
    for (var i = 0; i < roots.length; i += 1) {
      initTabs(roots[i]);
    }
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target && ev.target.closest ? ev.target.closest('[data-af-tabs-trigger]') : null;
    if (!btn) return;

    var root = btn.closest('[data-af-tabs-root]');
    if (!root) return;

    var idx = parseInt(btn.getAttribute('data-index') || '0', 10);
    if (isNaN(idx)) idx = 0;

    activateTabs(root, idx);
  }, false);

  document.addEventListener('keydown', function (ev) {
    var btn = ev.target && ev.target.closest ? ev.target.closest('[data-af-tabs-trigger]') : null;
    if (!btn) return;

    var key = ev.key;
    if (key !== 'ArrowLeft' && key !== 'ArrowRight' && key !== 'ArrowUp' && key !== 'ArrowDown' && key !== 'Home' && key !== 'End') {
      return;
    }

    var root = btn.closest('[data-af-tabs-root]');
    if (!root) return;

    var tabs = root.querySelectorAll('[data-af-tabs-trigger]');
    if (!tabs.length) return;

    var current = parseInt(btn.getAttribute('data-index') || '0', 10);
    if (isNaN(current)) current = 0;

    var next = current;

    if (key === 'Home') next = 0;
    else if (key === 'End') next = tabs.length - 1;
    else if (key === 'ArrowLeft' || key === 'ArrowUp') next = (current - 1 + tabs.length) % tabs.length;
    else if (key === 'ArrowRight' || key === 'ArrowDown') next = (current + 1) % tabs.length;

    ev.preventDefault();
    activateTabs(root, next);
    try { tabs[next].focus(); } catch (e) {}
  }, false);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll, false);
  } else {
    initAll();
  }
})();

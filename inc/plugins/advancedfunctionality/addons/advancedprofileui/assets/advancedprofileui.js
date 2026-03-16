(function () {
  'use strict';

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function textOf(node) {
    return String(node && node.textContent ? node.textContent : '').replace(/\s+/g, ' ').trim();
  }

  function hasMeaningfulContent(node) {
    if (!node) {
      return false;
    }

    var clone = node.cloneNode(true);

    Array.prototype.forEach.call(
      clone.querySelectorAll('script, style, .af-apui-empty'),
      function (el) {
        if (el && el.parentNode) {
          el.parentNode.removeChild(el);
        }
      }
    );

    var html = clone.innerHTML
      .replace(/<!--[\s\S]*?-->/g, '')
      .replace(/<br\s*\/?>/gi, '')
      .replace(/&nbsp;/gi, '')
      .replace(/\s+/g, '');

    return html.length > 0;
  }

  function detectStatType(stat) {
    var labelEl = stat.querySelector('.af-apf-stat-label');
    var source = textOf(labelEl || stat).toLowerCase();

    if (!source) {
      return 'extra';
    }

    if (source.indexOf('зарегистр') !== -1 || source.indexOf('registered') !== -1) {
      return 'hidden';
    }

    if (source.indexOf('предупрежд') !== -1 || source.indexOf('warning') !== -1) {
      return 'hidden';
    }

    if (source.indexOf('сообщен') !== -1 || source.indexOf('posts') !== -1) {
      return 'posts';
    }

    if (source.indexOf('тем') !== -1 || source.indexOf('threads') !== -1) {
      return 'threads';
    }

    if (source.indexOf('репутац') !== -1 || source.indexOf('reputation') !== -1) {
      return 'reputation';
    }

    return 'extra';
  }

  function extractStatValue(stat) {
    var valueEl = stat.querySelector('.af-apf-stat-value');
    var raw = textOf(valueEl || stat);
    var match = raw.match(/[+\-]?\d[\d\s.,%]*/);
    return match ? match[0].trim() : raw;
  }

  function normalizePostbitUserDetails() {
    var wraps = document.querySelectorAll('.af-apui-postbit-userdetails');
    if (!wraps.length) {
      return;
    }

    Array.prototype.forEach.call(wraps, function (wrap) {
      var stats = wrap.querySelectorAll('.af-apf-stat');
      if (!stats.length) {
        return;
      }

      Array.prototype.forEach.call(stats, function (stat) {
        stat.classList.remove(
          'af-apui-stat-posts',
          'af-apui-stat-threads',
          'af-apui-stat-reputation',
          'af-apui-stat-extra',
          'af-apui-stat-hidden'
        );

        stat.hidden = false;
        stat.style.display = '';

        var type = detectStatType(stat);

        if (type === 'hidden') {
          stat.classList.add('af-apui-stat-hidden');
          stat.hidden = true;
          stat.style.display = 'none';
          return;
        }

        if (type === 'posts' || type === 'threads' || type === 'reputation') {
          var number = extractStatValue(stat);
          var title = 'Сообщений';

          if (type === 'threads') {
            title = 'Тем';
          } else if (type === 'reputation') {
            title = 'Репутация';
          }

          stat.classList.add('af-apui-stat-' + type);
          stat.setAttribute('title', title);
          stat.innerHTML = '<span class="af-apui-stat-number">' + escapeHtml(number || '0') + '</span>';
          return;
        }

        stat.classList.add('af-apui-stat-extra');
      });
    });
  }

  function cleanupExtraPanel(root) {
    if (!root) {
      return;
    }

    var panel = root.querySelector('.af-apui-panel--extra');
    if (!panel) {
      return;
    }

    var adminCard = panel.querySelector('.af-apui-extra-admin-card');
    if (adminCard) {
      var adminBody = adminCard.querySelector('.af-apui-extra-admin-body');
      var adminHasContent = hasMeaningfulContent(adminBody);

      adminCard.hidden = !adminHasContent;
      adminCard.style.display = adminHasContent ? '' : 'none';
    }

    var actions = panel.querySelectorAll('.af-apui-extra-action');
    var visibleActions = 0;

    Array.prototype.forEach.call(actions, function (action) {
      var body = action.querySelector('.af-apui-extra-action__body');
      var hasContent = hasMeaningfulContent(body);

      action.hidden = !hasContent;
      action.style.display = hasContent ? '' : 'none';

      if (hasContent) {
        visibleActions++;
      }
    });

    var actionsCard = panel.querySelector('.af-apui-extra-actions-card');
    if (actionsCard) {
      var showActionsCard = visibleActions > 0;
      actionsCard.hidden = !showActionsCard;
      actionsCard.style.display = showActionsCard ? '' : 'none';
    }
  }

  function toggleEmptyState(panel) {
    var empty = panel.querySelector('.af-apui-empty');
    if (!empty) {
      return;
    }

    var clone = panel.cloneNode(true);
    var cloneEmpty = clone.querySelector('.af-apui-empty');
    if (cloneEmpty && cloneEmpty.parentNode) {
      cloneEmpty.parentNode.removeChild(cloneEmpty);
    }

    Array.prototype.forEach.call(
      clone.querySelectorAll('[hidden], [style*="display: none"]'),
      function (el) {
        if (el && el.parentNode) {
          el.parentNode.removeChild(el);
        }
      }
    );

    var hasContent = clone.innerHTML.replace(/\s+/g, '').length > 0;
    empty.style.display = hasContent ? 'none' : '';
  }

  function initTabs(root) {
    if (!root || root.__afApuiTabsInit) {
      return;
    }
    root.__afApuiTabsInit = true;

    var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-tab]'));
    var panels = Array.prototype.slice.call(root.querySelectorAll('[data-panel]'));

    if (!tabs.length || !panels.length) {
      return;
    }

    function activate(name, updateHash) {
      tabs.forEach(function (tab) {
        var active = tab.getAttribute('data-tab') === name;
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.setAttribute('tabindex', active ? '0' : '-1');
      });

      panels.forEach(function (panel) {
        var active = panel.getAttribute('data-panel') === name;
        panel.classList.toggle('is-active', active);
        panel.hidden = !active;
        panel.style.display = active ? '' : 'none';

        if (active) {
          toggleEmptyState(panel);
        }
      });

      if (updateHash && window.history && typeof window.history.replaceState === 'function') {
        window.history.replaceState(null, '', '#af-tab-' + name);
      }
    }

    root.addEventListener('click', function (event) {
      var tab = event.target.closest('[data-tab]');
      if (!tab || !root.contains(tab)) {
        return;
      }

      event.preventDefault();
      activate(tab.getAttribute('data-tab'), true);
    });

    root.addEventListener('keydown', function (event) {
      var current = event.target.closest('[data-tab]');
      if (!current || !root.contains(current)) {
        return;
      }

      var index = tabs.indexOf(current);
      if (index === -1) {
        return;
      }

      var nextIndex = index;

      if (event.key === 'ArrowRight') {
        nextIndex = (index + 1) % tabs.length;
      } else if (event.key === 'ArrowLeft') {
        nextIndex = (index - 1 + tabs.length) % tabs.length;
      } else {
        return;
      }

      event.preventDefault();
      tabs[nextIndex].focus();
      activate(tabs[nextIndex].getAttribute('data-tab'), true);
    });

    var fromHash = (window.location.hash || '').replace('#af-tab-', '');
    var initial = tabs.some(function (tab) {
      return tab.getAttribute('data-tab') === fromHash;
    }) ? fromHash : 'info';

    activate(initial, false);
  }

  function boot() {
    normalizePostbitUserDetails();

    var roots = document.querySelectorAll('[data-af-apui-tabs]');
    if (!roots.length) {
      return;
    }

    Array.prototype.forEach.call(roots, function (root) {
      cleanupExtraPanel(root);
      initTabs(root);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();

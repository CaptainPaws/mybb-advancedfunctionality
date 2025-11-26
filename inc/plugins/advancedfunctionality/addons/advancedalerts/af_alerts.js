(function () {
  'use strict';

  if (!window.jQuery) return;
  var $ = jQuery;

  if (window.__AFAlertsBooted) return;
  window.__AFAlertsBooted = true;

  var cfg = window.AFAlertsCfg || {};
  cfg.pollSec       = Math.max(5, parseInt(cfg.pollSec,10) || 20);
  cfg.dropdownLimit = Math.max(1, parseInt(cfg.dropdownLimit,10) || 10);
  cfg.toastLimit    = Math.max(1, parseInt(cfg.toastLimit,10) || 5);
  cfg.defAvatar     = String(cfg.defAvatar || '');
  cfg.userSound  = (typeof cfg.userSound  !== 'undefined') ? !!cfg.userSound : true;
  cfg.userToasts = (typeof cfg.userToasts !== 'undefined') ? !!cfg.userToasts : true;
  cfg.userId     = cfg.userId || 0;

  var api = 'misc.php?action=af_alerts_api';

  // === localStorage для запоминания показанных тостов ===
  var STORAGE_KEY = 'af_alerts_seen_' + (cfg.userId || 'guest');

  function loadSeenIds() {
    try {
      var raw = window.localStorage.getItem(STORAGE_KEY);
      if (!raw) return [];
      var arr = JSON.parse(raw);
      if (!Array.isArray(arr)) return [];
      return arr.map(function (x) { return parseInt(x, 10) || 0; }).filter(function (x) { return x > 0; });
    } catch (e) {
      return [];
    }
  }

  function saveSeenIds(ids) {
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(ids));
    } catch (e) {}
  }

  var state = {
    open: false,
    lastIds: loadSeenIds(), // уже показанные
    queue: [],
    showing: 0,
    prefsLoaded: false,
    types: [],
    view: 'list',
    headTitle: ''
  };

  var pollTimer = null;

  function getCsrf() {
    if (window.my_post_key) return String(window.my_post_key);
    var found = $('input[name=my_post_key]').first().val()
      || $('meta[name="my_post_key"]').attr('content') || '';
    if (found) window.my_post_key = found;
    return String(found || '');
  }

  function post(op, data) {
    data = data || {};
    data.op = op;
    data.my_post_key = getCsrf();
    return $.ajax({
      url: api,
      method: 'POST',
      data: data,
      dataType: 'json'
    }).fail(function (xhr) {
      try { console.warn('AF Alerts API FAIL', op, xhr.status, xhr.responseText && xhr.responseText.slice(0, 200)); } catch (e) { }
    });
  }

  function safeAvatar(src) {
    if (!src) return cfg.defAvatar || '';
    return src;
  }

  function goAfterMarkRead(id, href) {
    $('[data-afaa-toasts] .afaa-toast[data-id="' + id + '"]').remove();
    $('[data-afaa-list] .afaa-item[data-id="' + id + '"]').addClass('is-read').css('opacity', 0.4);

    post('mark_read', { ids: [id] }).always(function () {
      setTimeout(function () { window.location.href = href; }, 50);
    });
  }

  function renderList(items) {
    var $list = $('[data-afaa-list]');
    var $empty = $('[data-afaa-empty]');
    $list.empty();
    if (!items || !items.length) { $empty.show(); return; }
    $empty.hide();

    items.forEach(function (it) {
      var li = $('<li class="afaa-item">')
        .toggleClass('is-unread', it.read == 0)
        .attr('data-id', it.id);

      var ava = $('<img class="afaa-ava" alt="">')
        .attr('src', safeAvatar(it.avatar))
        .on('error', function () { $(this).attr('src', cfg.defAvatar || '').show(); });

      var aTitle = $('<a class="afaa-text">')
        .attr('href', it.link || 'javascript:void(0)')
        .html(it.html || (it.title || 'Уведомление'))
        .on('click', function (e) {
          if (!it.link) return;
          e.preventDefault();
          goAfterMarkRead(it.id, it.link);
        });

      var tm = $('<span class="afaa-time">').html(it.time || '');
      var left = $('<div class="afaa-item-left">').append(ava);
      var mid = $('<div class="afaa-item-mid">').append(aTitle).append(tm);

      var mark = $('<button class="afaa-mark" type="button" title="Отметить прочитанным">✓</button>').on('click', function () {
        post('mark_read', { ids: [it.id] }).done(function (r) {
          li.removeClass('is-unread').css('opacity', 0.4);
          updateBadge(r);
        });
      });
      var del = $('<button class="afaa-del" type="button" title="Удалить">×</button>').on('click', function () {
        post('delete', { ids: [it.id] }).done(function (r) {
          li.remove();
          updateBadge(r);
        });
      });
      var right = $('<div class="afaa-item-right">').append(mark, del);

      li.append(left, mid, right);
      $list.append(li);
    });
  }

  function updateBadge(resp) {
    if (resp && typeof resp.badge !== 'undefined') {
      $('[data-afaa-badge]').text(resp.badge);
    } else {
      post('badge', {}).done(function (r) {
        if (r && r.ok) $('[data-afaa-badge]').text(r.badge);
      });
    }
  }

  function collectTypePayload() {
    var payload = {};
    $('[data-afaa-type-toggle]').each(function () {
      var $el = $(this);
      if ($el.is(':checked')) {
        payload[$el.val()] = 1;
      }
    });
    return payload;
  }

  function renderPrefs(types, prefs) {
    var $wrap = $('[data-afaa-prefs]');
    $wrap.empty();

    if (!types || !types.length) {
      $wrap.append('<div class="afaa-empty">Нет доступных типов уведомлений</div>');
      return;
    }

    var $list = $('<div class="afaa-prefs-types">');

    types.forEach(function (t) {
      if (!t.can_disable) return;
      var id = 'afaa-type-' + t.code;
      var $chk = $('<input type="checkbox">')
        .attr('id', id)
        .attr('value', t.code)
        .attr('data-afaa-type-toggle', '1')
        .prop('checked', !!t.user_enabled);

      var $lbl = $('<label class="afaa-chk" for="' + id + '">').text(' ' + (t.title || t.code));
      $lbl.prepend($chk);
      $list.append($lbl);
    });

    var $ui = $('<div class="afaa-prefs-ui">');

    var $toastToggle = $('<label class="afaa-chk">')
      .append($('<input type="checkbox" data-afaa-toast-toggle>')
        .prop('checked', !!(prefs && prefs.toasts)))
      .append(' Показывать тост-плашки');

    var $soundToggle = $('<label class="afaa-chk">')
      .append($('<input type="checkbox" data-afaa-sound-toggle-popup>')
        .prop('checked', !!(prefs && prefs.sound)))
      .append(' Звук при новых уведомлениях');

    $ui.append('<div class="afaa-prefs-legend">Интерфейс</div>');
    $ui.append($soundToggle).append($toastToggle);

    $wrap.append('<div class="afaa-prefs-legend">Типы уведомлений</div>');
    $wrap.append($list);
    $wrap.append($ui);
  }

  function syncPrefs() {
    var payload = {
      sound: cfg.userSound ? 1 : 0,
      toasts: cfg.userToasts ? 1 : 0,
      types: collectTypePayload()
    };
    post('prefs', payload).done(function (r) {
      if (r && r.ok && typeof r.badge !== 'undefined') {
        updateBadge(r);
      }
    });
  }

  function ensureTypesLoaded() {
    if (state.prefsLoaded) {
      renderPrefs(state.types, { sound: cfg.userSound, toasts: cfg.userToasts });
      return $.Deferred().resolve().promise();
    }

    return post('types', {}).done(function (r) {
      if (!r || !r.ok) return;
      state.prefsLoaded = true;
      state.types = r.types || [];
      if (r.prefs) {
        cfg.userSound = !!r.prefs.sound;
        cfg.userToasts = !!r.prefs.toasts;
      }
      renderPrefs(state.types, r.prefs || {});
    });
  }

  function switchView(name) {
    state.view = name;
    var showingPrefs = name === 'prefs';
    $('[data-afaa-view="list"]').toggle(!showingPrefs);
    $('[data-afaa-view="prefs"]').toggle(showingPrefs);
    $('[data-afaa-prefs-back]').toggle(showingPrefs);

    var $title = $('[data-afaa-head-title]');
    if (showingPrefs) {
      $title.text('Настройки уведомлений');
    } else {
      $title.text(state.headTitle || 'Уведомления');
    }
  }

  function ensureToasts(items) {
    if (!cfg.userToasts) return;

    var changed = false;

    (items || []).forEach(function (it) {
      if (state.lastIds.indexOf(it.id) === -1 && it.read == 0) {
        state.lastIds.push(it.id);
        state.queue.push(it);
        changed = true;
      }
    });

    if (state.lastIds.length > 500) {
      state.lastIds = state.lastIds.slice(-200);
      changed = true;
    }

    if (changed) {
      saveSeenIds(state.lastIds);
    }

    pumpToasts();
  }

  // Опрос сервера
  function poll() {
    post('list', { limit: cfg.dropdownLimit }).done(function (r) {
      if (!r || !r.ok) return;

      var items = r.items || [];
      var unreadIds = items
        .filter(function (x) { return x.read == 0; })
        .map(function (x) { return x.id; });

      var hasUnread = unreadIds.length > 0;
      var hasNew = unreadIds.some(function (id) {
        return state.lastIds.indexOf(id) === -1;
      });

      ensureToasts(items);

      if (hasUnread && hasNew && cfg.userSound) {
        ensureLoadedSound();
        playSound();
      }

      updateBadge(r);
      if (state.open) {
        renderList(items);
      }
    });
  }

  function pumpToasts() {
    var wrap = $('[data-afaa-toasts]');
    while (state.showing < cfg.toastLimit && state.queue.length) {
      var it = state.queue.shift();
      state.showing++;

      var node = $('<div class="afaa-toast">').attr('data-id', it.id);
      var left = $('<div class="afaa-toast-left">')
        .append($('<img class="afaa-ava" alt="">')
          .attr('src', safeAvatar(it.avatar))
          .on('error', function () { $(this).attr('src', cfg.defAvatar || '').show(); })
        );

      var text = $('<a class="afaa-toast-text">')
        .attr('href', it.link || 'javascript:void(0)')
        .html(it.html || (it.title || 'Уведомление'))
        .on('click', function (e) {
          if (!it.link) return;
          e.preventDefault();
          node.remove(); state.showing--;
          goAfterMarkRead(it.id, it.link);
        });

      var mark = $('<button class="afaa-toast-mark" type="button" title="Прочитано">✓</button>').on('click', function () {
        post('mark_read', { ids: [it.id] }).done(function (r) {
          node.css('opacity', 0.4);
          setTimeout(function () { node.remove(); state.showing--; pumpToasts(); }, 150);
          updateBadge(r);
        });
      });

      var close = $('<button class="afaa-toast-close" type="button" title="Удалить">×</button>').on('click', function () {
        post('delete', { ids: [it.id] }).always(function () {
          node.remove(); state.showing--; pumpToasts();
        });
      });

      var right = $('<div class="afaa-toast-right">').append(mark, close);

      node.append(left, $('<div class="afaa-toast-mid">').append(text), right);
      wrap.append(node);

      setTimeout(function () { node.addClass('show'); }, 10);

      // Авто-скрытие через ~10 секунд
      setTimeout(function () {
        node.removeClass('show');
        setTimeout(function () { node.remove(); state.showing--; pumpToasts(); }, 250);
      }, 10000);
    }
  }

  function markAllRead() {
    post('mark_all_read', {}).done(function (r) {
      $('[data-afaa-toasts]').empty();
      state.queue = [];
      state.showing = 0;
      updateBadge(r);

      if (state.open) {
        post('list', { limit: cfg.dropdownLimit }).done(function (r2) {
          if (r2 && r2.ok) renderList(r2.items || []);
        });
      }
    });
  }

  var audioLoaded = false;
  function ensureLoadedSound() {
    if (audioLoaded) return;
    var a = document.getElementById('afaa-audio');
    if (!a) return;
    try { a.load(); audioLoaded = true; } catch (e) { }
  }
  function playSound() {
    var a = document.getElementById('afaa-audio');
    if (!a) return;
    try { a.currentTime = 0; a.play().catch(function () { }); } catch (e) { }
  }

  function closeAllPopups() {
    $('.afaa-popup:visible').hide();
    state.open = false;
  }

  var Popup = {
    open: function (link) {
      var $li = $(link).closest('.afaa-li');
      var $popup = $li.find('.afaa-popup');
      $('.afaa-popup').not($popup).hide();
      $popup.show();
      state.open = true;
      switchView('list');
      post('list', { limit: cfg.dropdownLimit }).done(function (r) {
        if (r && r.ok) renderList(r.items || []);
      });
      return false;
    },
    close: function (btn) {
      var $pop = $(btn).closest('.afaa-popup');
      if ($pop.length) $pop.hide();
      state.open = false;
      return false;
    },
    toggle: function (link) {
      var $li = $(link).closest('.afaa-li');
      var $p = $li.find('.afaa-popup');
      if ($p.is(':visible')) { return Popup.close($p.find('.afaa-close')[0] || link); }
      return Popup.open(link);
    }
  };

  $(document).on('click', function (e) {
    var $t = $(e.target);
    if ($t.closest('.afaa-li .afaa-popup, .afaa-li .afaa-bell-link').length === 0) {
      closeAllPopups();
    }
  });

  $(document).on('keydown', function (e) {
    if (e.key === 'Escape') closeAllPopups();
  });

  // Переключатель "Звук" в попапе
  $(document).on('change', '[data-afaa-sound-toggle]', function () {
    cfg.userSound = !!$(this).is(':checked');
    syncPrefs();
  });

  // Переключатель звука внутри настроек
  $(document).on('change', '[data-afaa-sound-toggle-popup]', function () {
    cfg.userSound = !!$(this).is(':checked');
    syncPrefs();
  });

  // Тосты в настройках
  $(document).on('change', '[data-afaa-toast-toggle]', function () {
    cfg.userToasts = !!$(this).is(':checked');
    syncPrefs();
  });

  // Переключение чекбоксов типов
  $(document).on('change', '[data-afaa-type-toggle]', function () {
    syncPrefs();
  });

  // Открываем настройки в поп-апе
  $(document).on('click', '[data-afaa-open-prefs]', function (e) {
    e.preventDefault();
    ensureTypesLoaded().done(function () {
      switchView('prefs');
    });
  });

  // Назад к списку уведомлений
  $(document).on('click', '[data-afaa-prefs-back]', function (e) {
    e.preventDefault();
    switchView('list');
  });

  window.AFAlerts = {
    togglePopup: Popup.toggle,
    closePopup: Popup.close,
    markAllRead: markAllRead
  };

  function startPolling() {
    if (pollTimer) return;
    poll();
    pollTimer = setInterval(poll, cfg.pollSec * 1000);
  }

  function stopPolling() {
    if (!pollTimer) return;
    clearInterval(pollTimer);
    pollTimer = null;
  }

  $(function () {
    state.headTitle = $('[data-afaa-head-title]').text();

    $(document).on('click', '.afaa-row-link', function (e) {
      var id = parseInt($(this).closest('tr').find('input[name="ids[]"]').val(), 10);
      if (!id) return;
      e.preventDefault();
      goAfterMarkRead(id, $(this).attr('href'));
    });

    // Стартуем опрос только на видимой вкладке
    if (!document.hidden) {
      startPolling();
    }
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) stopPolling();
      else startPolling();
    });
  });

  // Отладка из консоли: afAlertsDebug('diag', {uid:1}), и т.п.
  window.afAlertsDebug = function (op, payload) {
    payload = payload || {};
    payload.my_post_key = getCsrf();
    payload.op = op;
    return fetch(api, {
      method: 'POST', credentials: 'include',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'Accept': 'application/json'
      },
      body: Object.entries(payload).map(function (p) {
        return encodeURIComponent(p[0]) + '=' + encodeURIComponent(p[1]);
      }).join('&')
    }).then(function (r) { return r.text(); }).then(function (t) {
      try { return JSON.parse(t); } catch (e) { return { ok: 0, raw: t }; }
    });
  };
})();

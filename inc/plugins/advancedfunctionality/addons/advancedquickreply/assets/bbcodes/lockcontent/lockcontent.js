(function () {
  'use strict';

  function asText(x) { return String(x == null ? '' : x); }

  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  // --- editor helpers ---
  function getTextareaFromInst(inst) {
    if (inst && inst.textarea && inst.textarea.nodeType === 1) return inst.textarea;
    if (inst && inst.ta && inst.ta.nodeType === 1) return inst.ta;
    return document.querySelector('textarea#message') || document.querySelector('textarea[name="message"]');
  }

  function getScEditorInstance(ta) {
    try {
      if (!ta) return null;
      if (window.jQuery && window.jQuery.fn && window.jQuery.fn.sceditor) {
        var $ = window.jQuery;
        var inst = $(ta).sceditor('instance');
        return inst || null;
      }
    } catch (e) {}
    return null;
  }

  function wrapTextareaSelection(ta, open, close) {
    try {
      ta.focus();
      var start = ta.selectionStart || 0;
      var end = ta.selectionEnd || 0;
      var val = asText(ta.value);
      var sel = val.substring(start, end);

      ta.value = val.substring(0, start) + open + sel + close + val.substring(end);

      var pos = start + open.length;
      var posEnd = pos + sel.length;
      ta.setSelectionRange(pos, posEnd);
    } catch (e) {
      try { ta.value += open + close; } catch (e2) {}
    }
  }

  function insertWithEditor(inst, open, close) {
    var ta = getTextareaFromInst(inst);
    var ed = getScEditorInstance(ta);

    if (ed && typeof ed.insertText === 'function') {
      ed.insertText(open, close);
      return;
    }
    if (ta) wrapTextareaSelection(ta, open, close);
  }

  // --- data helpers ---
  function normalizeUidList(raw) {
    raw = asText(raw).trim();
    if (!raw) return '';
    var parts = raw.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    var seen = Object.create(null);
    var out = [];
    for (var i = 0; i < parts.length; i++) {
      var n = parseInt(parts[i], 10);
      if (!isFinite(n) || n <= 0) continue;
      var k = String(n);
      if (seen[k]) continue;
      seen[k] = true;
      out.push(k);
    }
    return out.join(',');
  }

  // --- modal ---
  function openModal(onOk) {
    var overlay = document.createElement('div');
    overlay.className = 'af-aqr-lc-overlay';

    // метка: это НАШ новый модал, не “чужой”
    overlay.__afLcFinalModal = true;

    overlay.innerHTML =
      '<div class="af-aqr-lc-modal" role="dialog" aria-modal="true">' +
        '<div class="af-aqr-lc-head">' +
          '<div class="af-aqr-lc-title">Скрыть содержимое</div>' +
          '<button type="button" class="af-aqr-lc-close" aria-label="Закрыть">×</button>' +
        '</div>' +

        '<div class="af-aqr-lc-body">' +

          '<div class="af-aqr-lc-tabs" role="tablist" aria-label="Режим скрытия">' +
            '<label class="af-aqr-lc-tab">' +
              '<input type="radio" name="af_aqr_lc_mode" value="posts" checked />' +
              '<span>По сообщениям</span>' +
            '</label>' +
            '<label class="af-aqr-lc-tab">' +
              '<input type="radio" name="af_aqr_lc_mode" value="users" />' +
              '<span>По пользователям</span>' +
            '</label>' +
          '</div>' +

          '<div class="af-aqr-lc-panels">' +

            '<div class="af-aqr-lc-panel" data-panel="posts">' +
              '<p class="af-aqr-lc-help">Показывать содержимое тем, у кого достаточно сообщений на форуме.</p>' +
              '<div class="af-aqr-lc-posts">' +
                '<span>Минимум:</span>' +
                '<input class="af-aqr-lc-input" type="number" min="0" step="1" value="10" inputmode="numeric" />' +
                '<span>сообщений</span>' +
              '</div>' +
            '</div>' +

            '<div class="af-aqr-lc-panel" data-panel="users">' +
              '<p class="af-aqr-lc-help">Показывать содержимое только выбранным пользователям (UID).</p>' +
              '<input class="af-aqr-lc-uids" type="text" value="" placeholder="UID через запятую, например: 12,34,56" />' +
            '</div>' +

          '</div>' +

        '</div>' +

        '<div class="af-aqr-lc-foot">' +
          '<button type="button" class="af-aqr-lc-btn">Отмена</button>' +
          '<button type="button" class="af-aqr-lc-btn primary">Применить</button>' +
        '</div>' +
      '</div>';

    function close() {
      try { overlay.remove(); } catch (e) {}
      document.removeEventListener('keydown', onKeyDown, true);
    }

    function onKeyDown(e) {
      if (e && e.key === 'Escape') close();
    }

    function getMode() {
      var m = overlay.querySelector('input[name="af_aqr_lc_mode"]:checked');
      return m ? m.value : 'posts';
    }

    function getMinPosts() {
      var inp = overlay.querySelector('.af-aqr-lc-input');
      var v = inp ? parseInt(inp.value, 10) : 0;
      return isFinite(v) && v >= 0 ? v : 0;
    }

    function getUids() {
      var inp = overlay.querySelector('.af-aqr-lc-uids');
      return normalizeUidList(inp ? inp.value : '');
    }

    function syncDisabled() {
      var mode = getMode();

      // для CSS (табы показывают соответствующую панель)
      overlay.setAttribute('data-mode', mode);

      var inpPosts = overlay.querySelector('.af-aqr-lc-input');
      var inpUids  = overlay.querySelector('.af-aqr-lc-uids');

      if (inpPosts) inpPosts.disabled = (mode !== 'posts');
      if (inpUids)  inpUids.disabled  = (mode !== 'users');
    }

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) close();
    });

    overlay.addEventListener('change', function (e) {
      if (e && e.target && e.target.name === 'af_aqr_lc_mode') syncDisabled();
    });

    var btnClose  = overlay.querySelector('.af-aqr-lc-close');
    var btnCancel = overlay.querySelector('.af-aqr-lc-btn');
    var btnOk     = overlay.querySelector('.af-aqr-lc-btn.primary');

    btnClose.addEventListener('click', close);
    btnCancel.addEventListener('click', close);

    btnOk.addEventListener('click', function () {
      var mode = getMode();
      var minPosts = getMinPosts();
      var uids = getUids();

      close();
      onOk({ mode: mode, minPosts: minPosts, uids: uids });
    });

    document.body.appendChild(overlay);
    document.addEventListener('keydown', onKeyDown, true);
    syncDisabled();
  }


  // --- если вдруг модалку нарисовал старый скрипт: выпиливаем "гостей" и добавляем users ---
  function patchForeignModal(overlay) {
    try {
      if (!overlay || overlay.nodeType !== 1) return;
      if (overlay.__afLcFinalModal) return;


      // 1) удалить guests-строку (любой вариант)
      var guestRadio = overlay.querySelector('input[type="radio"][name="af_aqr_lc_mode"][value="guests"]');
      if (guestRadio) {
        var row = guestRadio.closest('.af-aqr-lc-row') || guestRadio.closest('div') || null;
        if (row && row.parentNode) row.parentNode.removeChild(row);
      }

      // 2) убедиться, что есть users
      var usersRadio = overlay.querySelector('input[type="radio"][name="af_aqr_lc_mode"][value="users"]');
      if (!usersRadio) {
        var body = overlay.querySelector('.af-aqr-lc-body');
        if (body) {
          var rowWrap = document.createElement('div');
          rowWrap.className = 'af-aqr-lc-row';
          rowWrap.setAttribute('data-mode', 'users');
          rowWrap.innerHTML =
            '<label><input type="radio" name="af_aqr_lc_mode" value="users"> Скрыть от всех, кроме определенных пользователей</label>' +
            '<div class="af-aqr-lc-users" style="margin-top:6px;">' +
              '<input class="af-aqr-lc-uids" type="text" value="" placeholder="UID через запятую, например: 12,34,56" />' +
            '</div>';
          body.appendChild(rowWrap);
        }
      }

      // 3) синхронизировать disabled (если старый код это делает — ок, если нет — сделаем сами)
      var modeEl = overlay.querySelector('input[name="af_aqr_lc_mode"]:checked');
      var mode = modeEl ? modeEl.value : 'posts';
      var inpPosts = overlay.querySelector('.af-aqr-lc-input');
      var inpUids = overlay.querySelector('.af-aqr-lc-uids');

      if (inpPosts) inpPosts.disabled = (mode !== 'posts');
      if (inpUids) inpUids.disabled = (mode !== 'users');
    } catch (e) {}
  }

  // Перехват клика по "Применить" в чужой модалке и вставка правильного BBCode (вместо старого поведения)
  function hijackApply(overlay) {
    try {
      if (!overlay || overlay.nodeType !== 1) return;
      if (overlay.__afLcFinalModal) return;
      if (overlay.__afLcHijacked) return;
      overlay.__afLcHijacked = true;

      var btnOk = overlay.querySelector('.af-aqr-lc-btn.primary');
      if (!btnOk) return;

      btnOk.addEventListener('click', function (e) {
        // Важно: останавливаем чужие обработчики "Применить"
        try {
          e.preventDefault();
          e.stopPropagation();
          if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
        } catch (e2) {}

        var modeEl = overlay.querySelector('input[name="af_aqr_lc_mode"]:checked');
        var mode = modeEl ? modeEl.value : 'posts';

        var minPosts = 10;
        var inpPosts = overlay.querySelector('.af-aqr-lc-input');
        if (inpPosts) {
          var v = parseInt(inpPosts.value, 10);
          if (isFinite(v) && v >= 0) minPosts = v;
        }

        var uids = '';
        var inpUids = overlay.querySelector('.af-aqr-lc-uids');
        if (inpUids) uids = normalizeUidList(inpUids.value);

        // закрыть оверлей
        try { overlay.remove(); } catch (e3) {}

        var inst = window.__afAqrLcLastInst || null;

        var open = '[hide=posts=' + String(minPosts) + ']';
        if (mode === 'users') {
          open = uids ? ('[hide=users=' + uids + ']') : '[hide=posts=10]';
        }

        insertWithEditor(inst, open, '[/hide]');
      }, true); // capture=true чтобы быть раньше чужих
    } catch (e) {}
  }

  // Наблюдаем за появлением модалки (на случай, если её создал старый код)
  (function watchForOverlay() {
    try {
      var mo = new MutationObserver(function () {
        var overlay = document.querySelector('.af-aqr-lc-overlay');
        if (!overlay) return;
        patchForeignModal(overlay);
        hijackApply(overlay);

        // при переключении радиокнопок — обновим disabled
        overlay.addEventListener('change', function (e) {
          if (!e || !e.target || e.target.name !== 'af_aqr_lc_mode') return;
          patchForeignModal(overlay);
        }, true);
      });

      mo.observe(document.documentElement || document.body, { childList: true, subtree: true });
    } catch (e) {}
  })();

  // --- наш финальный хендлер: posts/users, без guests ---
  function ourHandler(inst) {
    window.__afAqrLcLastInst = inst || null;

    openModal(function (data) {
      var open = '[hide=posts=10]';

      if (data && data.mode === 'posts') {
        open = '[hide=posts=' + String(data.minPosts || 0) + ']';
      } else if (data && data.mode === 'users') {
        var uids = asText(data.uids || '').trim();
        open = uids ? ('[hide=users=' + uids + ']') : '[hide=posts=10]';
      }

      insertWithEditor(inst, open, '[/hide]');
    });
  }
  ourHandler.__afLcFinal = true;

  // --- жестко переустанавливаем хендлер, чтобы перебить любые поздние перезаписи ---
  function install() {
    try {
      window.afAqrBuiltinHandlers.lockcontent = ourHandler;
    } catch (e) {}
  }

  install();
  // “добиваем” перезаписчиков после загрузки других builtins/скриптов
  for (var i = 1; i <= 20; i++) {
    setTimeout(install, i * 250);
  }

})();

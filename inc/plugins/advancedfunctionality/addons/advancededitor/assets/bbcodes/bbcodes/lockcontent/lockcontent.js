(function () {
  'use strict';

  function asText(x) { return String(x == null ? '' : x); }

  // === РЕЕСТР ХЕНДЛЕРОВ: и AE, и AQR ===
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);
  if (!window.afAeBuiltinHandlers)  window.afAeBuiltinHandlers  = Object.create(null);

  // one-shot
  if (window.__afAeLockcontentPackLoaded) return;
  window.__afAeLockcontentPackLoaded = true;
  if (!window.AFAE || typeof window.AFAE.hasEditor !== 'function' || !window.AFAE.hasEditor()) return;

  var ID  = 'lockcontent';
  var CMD = 'af_lockcontent';

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
      try { ta.dispatchEvent(new Event('input', { bubbles: true })); } catch (e1) {}
    } catch (e) {
      try { ta.value += open + close; } catch (e2) {}
    }
  }

  function insertWithEditor(inst, open, close) {
    var ta = getTextareaFromInst(inst);
    var ed = getScEditorInstance(ta);

    if (ed && typeof ed.insertText === 'function') {
      ed.insertText(open, close);
      try { if (typeof ed.focus === 'function') ed.focus(); } catch (e0) {}
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

  function clampInt(v, min, max, fallback) {
    var n = parseInt(asText(v).trim(), 10);
    if (!isFinite(n)) n = fallback;
    if (n < min) n = min;
    if (n > max) n = max;
    return n;
  }

  // ===== dropdown UI (как floatbb/table/spoiler) =====
  function makeDropdown(editor, caller, instForInsert) {
    var wrap = document.createElement('div');
    wrap.className = 'af-lc-dd';

    wrap.innerHTML =
      '<div class="af-lc-dd-hd">' +
      '  <div class="af-lc-dd-title">Скрыть содержимое</div>' +
      '</div>' +

      '<div class="af-lc-dd-body">' +

      '  <div class="af-lc-dd-tabs" role="tablist" aria-label="Режим скрытия">' +
      '    <label class="af-lc-dd-tab">' +
      '      <input type="radio" name="af_lc_mode" value="posts" checked>' +
      '      <span>По сообщениям</span>' +
      '    </label>' +
      '    <label class="af-lc-dd-tab">' +
      '      <input type="radio" name="af_lc_mode" value="users">' +
      '      <span>По пользователям</span>' +
      '    </label>' +
      '  </div>' +

      '  <div class="af-lc-dd-panels">' +

      '    <div class="af-lc-dd-panel" data-panel="posts">' +
      '      <p class="af-lc-dd-help">Показывать содержимое тем, у кого достаточно сообщений на форуме.</p>' +
      '      <div class="af-lc-dd-posts">' +
      '        <span>Минимум:</span>' +
      '        <input class="af-lc-dd-input af-lc-dd-minposts" type="number" min="0" max="999999" step="1" value="10" inputmode="numeric">' +
      '        <span>сообщений</span>' +
      '      </div>' +
      '    </div>' +

      '    <div class="af-lc-dd-panel" data-panel="users">' +
      '      <p class="af-lc-dd-help">Показывать содержимое только выбранным пользователям (UID).</p>' +
      '      <input class="af-lc-dd-input af-lc-dd-uids" type="text" value="" placeholder="UID через запятую, например: 12,34,56">' +
      '    </div>' +

      '  </div>' +

      '  <div class="af-lc-dd-actions">' +
      '    <button type="button" class="button af-lc-dd-cancel">Отмена</button>' +
      '    <button type="button" class="button af-lc-dd-insert">Применить</button>' +
      '  </div>' +

      '</div>';

    var minPosts = wrap.querySelector('.af-lc-dd-minposts');
    var inpUids  = wrap.querySelector('.af-lc-dd-uids');

    function getMode() {
      var m = wrap.querySelector('input[name="af_lc_mode"]:checked');
      return m ? m.value : 'posts';
    }

    function syncUi() {
      var mode = getMode();
      wrap.setAttribute('data-mode', mode);

      if (minPosts) minPosts.disabled = (mode !== 'posts');
      if (inpUids)  inpUids.disabled  = (mode !== 'users');
    }

    function closeDd() {
      try { editor.closeDropDown(true); } catch (e0) {}
    }

    function buildOpenTag() {
      var mode = getMode();

      if (mode === 'users') {
        var u = normalizeUidList(inpUids ? inpUids.value : '');
        // если пусто — не ломаем: fallback к дефолту по постам
        if (!u) return '[hide=posts=10]';
        return '[hide=users=' + u + ']';
      }

      // posts
      var n = clampInt(minPosts ? minPosts.value : 10, 0, 999999, 10);
      return '[hide=posts=' + String(n) + ']';
    }

    function applyNow() {
      var open = buildOpenTag();
      insertWithEditor(instForInsert, open, '[/hide]');
      closeDd();
    }

    // events
    wrap.addEventListener('change', function (e) {
      if (!e || !e.target) return;
      if (e.target.name === 'af_lc_mode') syncUi();
    }, false);

    var btnCancel = wrap.querySelector('.af-lc-dd-cancel');
    var btnInsert = wrap.querySelector('.af-lc-dd-insert');

    if (btnCancel && !btnCancel._afBound) {
      btnCancel._afBound = true;
      btnCancel.addEventListener('click', function (ev) {
        ev.preventDefault();
        closeDd();
      }, false);
    }

    if (btnInsert && !btnInsert._afBound) {
      btnInsert._afBound = true;
      btnInsert.addEventListener('click', function (ev) {
        ev.preventDefault();
        applyNow();
      }, false);
    }

    // Enter = применить (в users поле тоже)
    function bindEnter(el) {
      if (!el || el._afEnterBound) return;
      el._afEnterBound = true;
      el.addEventListener('keydown', function (ev) {
        if (!ev) return;
        if (ev.key === 'Enter') {
          ev.preventDefault();
          applyNow();
        }
      }, false);
    }

    bindEnter(minPosts);
    bindEnter(inpUids);

    // init
    syncUi();
    try {
      // фокус на активное поле
      if (getMode() === 'users') { if (inpUids) inpUids.focus(); }
      else { if (minPosts) minPosts.focus(); }
    } catch (e2) {}

    return wrap;
  }

  function openSceditorDropdown(editor, caller, instForInsert) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;
    try { editor.closeDropDown(true); } catch (e0) {}

    var wrap = makeDropdown(editor, caller, instForInsert);
    editor.createDropDown(caller, 'sceditor-lockcontent-picker', wrap);
    return true;
  }

  // ====== AE/AQR совместимость + регистрация команды ======

  function resolveInst(ctx, caller) {
    // SCEditor обычно зовёт exec так, что `this` = instance
    if (ctx && typeof ctx.insertText === 'function') return ctx;

    // Иногда instance прилетает через caller
    if (caller && typeof caller.insertText === 'function') return caller;

    // Иногда caller = textarea
    if (caller && caller.nodeType === 1 && caller.tagName && caller.tagName.toLowerCase() === 'textarea') {
      var ed = getScEditorInstance(caller);
      return ed || { textarea: caller };
    }

    // Фоллбэк: найдём основной textarea (#message / name=message)
    var ta = getTextareaFromInst(null);
    var ed2 = getScEditorInstance(ta);
    return ed2 || { textarea: ta };
  }

  function ourHandler(inst, callerEl) {
    // если есть живой SCEditor — открываем dropdown
    if (inst && typeof inst.createDropDown === 'function') {
      if (openSceditorDropdown(inst, callerEl, inst)) return;
    }

    // fallback без dropdown (если SCEditor не поднят) — не ломаем поведение кнопки
    insertWithEditor(inst, '[hide=posts=10]', '[/hide]');
  }
  ourHandler.__afLcFinal = true;

  function registerSceditorCommand(cmdName) {
    try {
      if (!window.jQuery || !window.jQuery.sceditor || !window.jQuery.sceditor.command) return;

      window.jQuery.sceditor.command.set(cmdName, {
        tooltip: 'Скрыть содержимое',
        exec: function (caller) {
          var inst = resolveInst(this, caller);
          // caller нужен, чтобы dropdown якорился к кнопке
          ourHandler(inst, caller && caller.nodeType === 1 ? caller : null);
        },
        txtExec: function (caller) {
          var inst = resolveInst(this, caller);
          ourHandler(inst, caller && caller.nodeType === 1 ? caller : null);
        }
      });
    } catch (e) {}
  }

  // --- жестко переустанавливаем хендлер, чтобы перебить любые поздние перезаписи ---
  function install() {
    try {
      if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);
      if (!window.afAeBuiltinHandlers)  window.afAeBuiltinHandlers  = Object.create(null);

      // AQR
      window.afAqrBuiltinHandlers.lockcontent = ourHandler;
      window.afAqrBuiltinHandlers.af_lockcontent = ourHandler;

      // AE
      window.afAeBuiltinHandlers.lockcontent = ourHandler;
      window.afAeBuiltinHandlers.af_lockcontent = ourHandler;

      // SCEditor commands
      registerSceditorCommand('af_lockcontent');
      registerSceditorCommand('lockcontent');
    } catch (e) {}
  }

  install();
  for (var i = 1; i <= 20; i++) setTimeout(install, i * 250);

})();

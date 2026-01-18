(function () {
  'use strict';

  function asText(x) { return String(x == null ? '' : x); }

  // === РЕЕСТР ХЕНДЛЕРОВ: и AE, и AQR ===
  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  function isInPreOrCode(el) {
    try { return !!(el && el.closest && el.closest('pre, code')); }
    catch (e) { return false; }
  }

  // --------- Instance resolving (главный фикс) ---------
  function getInstanceFromAny(x, caller) {
    try {
      // 1) уже SCEditor instance?
      if (x && typeof x === 'object') {
        if (typeof x.insert === 'function' || typeof x.insertText === 'function') return x;
      }

      // 2) jQuery объект?
      if (window.jQuery && x && x.jquery) {
        try {
          var instJq = x.sceditor('instance');
          if (instJq) return instJq;
        } catch (e0) {}
      }

      // 3) textarea DOM?
      if (window.jQuery && x && x.nodeType === 1 && x.tagName && x.tagName.toLowerCase() === 'textarea') {
        try {
          var instTa = jQuery(x).sceditor('instance');
          if (instTa) return instTa;
        } catch (e1) {}
      }

      // 4) если дали кнопку/элемент тулбара — попробуем найти ближайший контейнер sceditor
      if (window.jQuery && caller && caller.nodeType === 1) {
        var cont = null;
        try { cont = caller.closest('.sceditor-container'); } catch (e2) { cont = null; }
        if (cont) {
          // В контейнере есть исходный textarea как sibling/prev — но проще: пройтись по textarea и найти instance с таким container
          var tas = document.querySelectorAll('textarea');
          for (var i = 0; i < tas.length; i++) {
            var ta = tas[i];
            if (!ta) continue;
            var inst = null;
            try { inst = jQuery(ta).sceditor('instance'); } catch (e3) { inst = null; }
            if (!inst) continue;

            // сравним контейнеры
            try {
              var ic = (typeof inst.getContainer === 'function') ? inst.getContainer() : null;
              var icEl = (ic && ic[0]) ? ic[0] : ic;
              if (icEl && icEl === cont) return inst;
            } catch (e4) {}
          }
        }
      }

      // 5) последний шанс: активный редактор (#message)
      if (window.jQuery) {
        var main = document.querySelector('textarea#message, textarea[name="message"]');
        if (main) {
          try {
            var instMain = jQuery(main).sceditor('instance');
            if (instMain) return instMain;
          } catch (e5) {}
        }
      }

    } catch (e) {}
    return null;
  }

  function insertIntoEditor(inst, bb) {
    bb = asText(bb);
    if (!bb) return false;

    // 1) нормальный путь
    try {
      if (inst && typeof inst.focus === 'function') inst.focus();
    } catch (e0) {}

    try {
      if (inst && typeof inst.insert === 'function') {
        inst.insert(bb, '');
        try { if (inst.updateOriginal) inst.updateOriginal(); } catch (eU0) {}
        return true;
      }
    } catch (e1) {}

    // 2) fallback API
    try {
      if (inst && typeof inst.insertText === 'function') {
        inst.insertText(bb);
        try { if (inst.updateOriginal) inst.updateOriginal(); } catch (eU1) {}
        return true;
      }
    } catch (e2) {}

    // 3) fallback на textarea, если совсем всё плохо
    try {
      var ta = null;
      if (inst && inst.opts && inst.opts.original) ta = inst.opts.original;
      if (!ta && inst && inst.textarea) ta = inst.textarea;
      if (!ta) ta = document.querySelector('textarea#message, textarea[name="message"]');
      if (ta && ta.nodeType === 1 && ta.tagName.toLowerCase() === 'textarea') {
        var start = ta.selectionStart || 0;
        var end = ta.selectionEnd || 0;
        var v = ta.value || '';
        ta.value = v.slice(0, start) + bb + v.slice(end);
        try {
          ta.selectionStart = ta.selectionEnd = start + bb.length;
        } catch (eS) {}
        ta.focus();
        return true;
      }
    } catch (e3) {}

    return false;
  }

  // --------- BBCode builder (твой, почти без изменений) ---------
  function buildBbcode(rows, cols, opts) {
    rows = Math.max(1, Math.min(50, rows | 0));
    cols = Math.max(1, Math.min(50, cols | 0));
    opts = opts || {};

    function normWidthToken(s) {
      s = asText(s).trim();
      if (!s) return '';
      var m = s.match(/^([0-9]{1,4})(px|%|em|rem|vw|vh)?$/i);
      if (!m) return '';
      return (m[1] + (m[2] ? m[2].toLowerCase() : 'px'));
    }

    function parseWidthList(raw, limit) {
      raw = asText(raw).trim();
      if (!raw) return [];
      var parts = raw.split(/[,;]+/g);
      var out = [];
      for (var i = 0; i < parts.length && out.length < limit; i++) {
        var w = normWidthToken(parts[i]);
        if (w) out.push(w);
      }
      return out;
    }

    var width = asText(opts.width).trim();
    var align = asText(opts.align).trim();
    var headers = asText(opts.headers).trim();
    var fill = !!opts.fill;

    var colWidths = Array.isArray(opts.colWidths) ? opts.colWidths : parseWidthList(opts.colWidths, cols);

    var attrs = [];
    if (width) attrs.push('width=' + width);
    if (align) attrs.push('align=' + align);
    if (headers && headers !== 'none') attrs.push('headers=' + headers);

    var open = '[table' + (attrs.length ? (' ' + attrs.join(' ')) : '') + ']';
    var close = '[/table]';

    function isHeaderCell(r, c) {
      if (headers === 'both') return (r === 1 || c === 1);
      if (headers === 'row') return (r === 1);
      if (headers === 'col') return (c === 1);
      return false;
    }

    var out = [];
    out.push(open);

    for (var r = 1; r <= rows; r++) {
      out.push('[tr]');
      for (var c = 1; c <= cols; c++) {
        var th = isHeaderCell(r, c);
        var tag = th ? 'th' : 'td';

        var txt = '…';
        if (fill && th) {
          if ((headers === 'row' || headers === 'both') && r === 1) txt = 'Header ' + c;
          if ((headers === 'col' || headers === 'both') && c === 1) txt = 'Row ' + r;
          if (headers === 'both' && r === 1 && c === 1) txt = ' ';
        }

        var cw = (colWidths && colWidths[c - 1]) ? colWidths[c - 1] : '';
        var openCell = '[' + tag + (cw ? (' width=' + cw) : '') + ']';
        out.push(openCell + txt + '[/' + tag + ']');
      }
      out.push('[/tr]');
    }

    out.push(close);
    return out.join('\n');
  }

  // --------- UI popover ---------
  function showPopover(instRaw, caller) {
    var inst = getInstanceFromAny(instRaw, caller);
    if (!inst) return;

    // ---- helpers ----
    function unwrapEl(x) {
      try { return (x && x[0]) ? x[0] : x; } catch (e) { return x; }
    }

    function getContainerEl() {
      try {
        if (inst && typeof inst.getContainer === 'function') {
          return unwrapEl(inst.getContainer());
        }
      } catch (e0) {}
      try { if (inst && inst.container) return unwrapEl(inst.container); } catch (e1) {}
      return null;
    }

    function resolveAnchorEl(containerEl) {
      // 1) если кликнули по svg внутри кнопки — поднимемся к .sceditor-button
      if (caller && caller.nodeType === 1) {
        try {
          var btn = caller.closest ? caller.closest('.sceditor-button') : null;
          if (btn) return btn;

          if (caller.closest && caller.closest('.sceditor-toolbar')) return caller;
        } catch (e0) {}
      }

      // 2) основной путь: найдём НАШУ кнопку af_table
      if (containerEl && containerEl.querySelector) {
        var b =
          containerEl.querySelector('.sceditor-toolbar .sceditor-button-af_table') ||
          containerEl.querySelector('.sceditor-toolbar .sceditor-button.sceditor-button-af_table') ||
          containerEl.querySelector('.sceditor-toolbar [data-command="af_table"]') ||
          containerEl.querySelector('.sceditor-toolbar [data-sceditor-command="af_table"]');
        if (b) return b;
      }

      // 3) fallback: якоримся к тулбару, если есть, иначе к контейнеру
      try {
        var tb = containerEl ? containerEl.querySelector('.sceditor-toolbar') : null;
        if (tb) return tb;
      } catch (e2) {}

      return containerEl;
    }

    function clamp(n, a, b) { return Math.max(a, Math.min(b, n)); }

    // ---- backdrop ----
    var backdrop = document.getElementById('af-aqr-tablepop-backdrop');
    if (!backdrop) {
      backdrop = document.createElement('div');
      backdrop.id = 'af-aqr-tablepop-backdrop';
      backdrop.style.position = 'fixed';
      backdrop.style.left = '0';
      backdrop.style.top = '0';
      backdrop.style.right = '0';
      backdrop.style.bottom = '0';
      backdrop.style.background = 'transparent';
      backdrop.style.zIndex = '99998';
      backdrop.style.display = 'none';
      backdrop.style.pointerEvents = 'auto';
      document.body.appendChild(backdrop);
    }

    // ---- pop ----
    var pop = document.getElementById('af-aqr-tablepop');
    if (!pop) {
      pop = document.createElement('div');
      pop.id = 'af-aqr-tablepop';
      document.body.appendChild(pop);
    }

    // fixed оставляем как у тебя (чтобы не ломать ничего по скроллу)
    pop.style.zIndex = '99999';
    pop.style.position = 'fixed';
    pop.style.display = 'none';
    pop.style.pointerEvents = 'auto';

    if (!pop._afTableUiV2 || !pop.querySelector('.af-aqr-t-cols') || !pop.querySelector('.af-aqr-t-rows')) {
      pop._afTableUiV2 = true;

      pop.innerHTML = ''
        + '<div class="af-aqr-tablepop-hd">'
        + '  <div class="af-aqr-tablepop-title">Таблица</div>'
        + '  <button type="button" class="af-aqr-tablepop-x" aria-label="Закрыть">×</button>'
        + '</div>'
        + '<div class="af-aqr-tablepop-opts">'
        + '  <label class="af-aqr-tablepop-row"><span>Колонок (гориз.)</span><input type="number" min="1" max="50" step="1" class="af-aqr-t-cols" value="2"></label>'
        + '  <label class="af-aqr-tablepop-row"><span>Строк (верт.)</span><input type="number" min="1" max="50" step="1" class="af-aqr-t-rows" value="2"></label>'
        + '  <div class="af-aqr-tablepop-size"><span class="af-aqr-tablepop-sizeval">2 × 2</span></div>'
        + '  <label class="af-aqr-tablepop-row"><span>Ширина таблицы</span><input type="text" class="af-aqr-t-width" placeholder="например 100% или 500px"></label>'
        + '  <label class="af-aqr-tablepop-row"><span>Ширины колонок</span><input type="text" class="af-aqr-t-colwidths" placeholder="например 100px,300px,400px"></label>'
        + '  <label class="af-aqr-tablepop-row"><span>Выравнивание</span>'
        + '    <select class="af-aqr-t-align">'
        + '      <option value="">—</option>'
        + '      <option value="left">left</option>'
        + '      <option value="center">center</option>'
        + '      <option value="right">right</option>'
        + '    </select>'
        + '  </label>'
        + '  <label class="af-aqr-tablepop-row"><span>Заголовки</span>'
        + '    <select class="af-aqr-t-headers">'
        + '      <option value="none">none</option>'
        + '      <option value="row">row</option>'
        + '      <option value="col">col</option>'
        + '      <option value="both">both</option>'
        + '    </select>'
        + '  </label>'
        + '  <label class="af-aqr-tablepop-row"><span>Заполнить</span>'
        + '    <select class="af-aqr-t-fill"><option value="0">нет</option><option value="1">да</option></select>'
        + '  </label>'
        + '  <div class="af-aqr-tablepop-actions">'
        + '    <button type="button" class="button af-aqr-t-insert">Вставить</button>'
        + '  </div>'
        + '</div>';
    }

    var sizeEl = pop.querySelector('.af-aqr-tablepop-sizeval');
    var btnX = pop.querySelector('.af-aqr-tablepop-x');
    var btnInsert = pop.querySelector('.af-aqr-t-insert');

    var inpCols = pop.querySelector('.af-aqr-t-cols');
    var inpRows = pop.querySelector('.af-aqr-t-rows');

    var inpWidth = pop.querySelector('.af-aqr-t-width');
    var inpColWidths = pop.querySelector('.af-aqr-t-colwidths');
    var selAlign = pop.querySelector('.af-aqr-t-align');
    var selHeaders = pop.querySelector('.af-aqr-t-headers');
    var selFill = pop.querySelector('.af-aqr-t-fill');

    function clampInt(v, min, max, fallback) {
      var n = parseInt(v, 10);
      if (!isFinite(n)) n = fallback;
      if (n < min) n = min;
      if (n > max) n = max;
      return n;
    }

    function repaintSize() {
      var c = clampInt(inpCols && inpCols.value, 1, 50, 2);
      var r = clampInt(inpRows && inpRows.value, 1, 50, 2);
      if (inpCols) inpCols.value = String(c);
      if (inpRows) inpRows.value = String(r);
      if (sizeEl) sizeEl.textContent = r + ' × ' + c;
    }

    function close() {
      pop.style.display = 'none';
      backdrop.style.display = 'none';
    }

    function insertNow() {
      var cols = clampInt(inpCols && inpCols.value, 1, 50, 2);
      var rows = clampInt(inpRows && inpRows.value, 1, 50, 2);

      var width = asText(inpWidth && inpWidth.value).trim();
      var colWidthsRaw = asText(inpColWidths && inpColWidths.value).trim();
      var align = asText(selAlign && selAlign.value).trim();
      var headers = asText(selHeaders && selHeaders.value).trim();
      var fill = asText(selFill && selFill.value) === '1';

      var bb = buildBbcode(rows, cols, {
        width: width,
        colWidths: colWidthsRaw,
        align: align,
        headers: headers,
        fill: fill
      });

      insertIntoEditor(inst, bb);
      close();
    }

    function bindEnter(el) {
      if (!el || el._afEnterBound) return;
      el._afEnterBound = true;
      el.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          insertNow();
        }
      }, false);
    }

    if (inpCols && !inpCols._afBound) {
      inpCols._afBound = true;
      inpCols.addEventListener('input', repaintSize, false);
      inpCols.addEventListener('change', repaintSize, false);
    }
    if (inpRows && !inpRows._afBound) {
      inpRows._afBound = true;
      inpRows.addEventListener('input', repaintSize, false);
      inpRows.addEventListener('change', repaintSize, false);
    }

    bindEnter(inpCols);
    bindEnter(inpRows);
    bindEnter(inpWidth);
    bindEnter(inpColWidths);

    if (btnInsert && !btnInsert._afBound) {
      btnInsert._afBound = true;
      btnInsert.addEventListener('click', function (ev) {
        ev.preventDefault();
        insertNow();
      }, false);
    }

    if (btnX && !btnX._afBound) {
      btnX._afBound = true;
      btnX.addEventListener('click', function (ev) {
        ev.preventDefault();
        close();
      }, false);
    }

    if (!backdrop._afBound) {
      backdrop._afBound = true;
      backdrop.addEventListener('mousedown', function (ev) { ev.preventDefault(); close(); }, false);
      backdrop.addEventListener('pointerdown', function (ev) { ev.preventDefault(); close(); }, false);
    }

    // ---- positioning (POPUP НА УРОВНЕ РЕДАКТОРА: центрируем как на скриншоте) ----
    var containerEl = getContainerEl();

    backdrop.style.display = 'block';
    pop.style.display = 'block';
    pop.style.visibility = 'hidden';

    var top = 60, left = 60;

    try {
      var pad = 12;

      // размеры попапа (после display:block)
      var pw = pop.offsetWidth || 360;
      var ph = pop.offsetHeight || 300;

      // тулбар и редакторная область
      var tb = null, tbrect = null;
      var ed = null, edrect = null;

      if (containerEl && containerEl.querySelector) {
        tb = containerEl.querySelector('.sceditor-toolbar');
        if (tb && tb.getBoundingClientRect) tbrect = tb.getBoundingClientRect();

        // "поле редактора": для WYSIWYG может быть iframe, для source — textarea
        ed =
          containerEl.querySelector('.sceditor-editor') ||
          containerEl.querySelector('iframe') ||
          containerEl.querySelector('.sceditor-wysiwyg') ||
          containerEl.querySelector('textarea');

        if (ed && ed.getBoundingClientRect) edrect = ed.getBoundingClientRect();
      }

      // если редактор не нашли — падаем на контейнер
      if (!edrect && containerEl && containerEl.getBoundingClientRect) {
        edrect = containerEl.getBoundingClientRect();
      }

      // если и так не нашли — падаем на viewport
      if (!edrect) {
        edrect = { left: 0, top: 0, right: (window.innerWidth||0), bottom: (window.innerHeight||0),
                   width: (window.innerWidth||0), height: (window.innerHeight||0) };
      } else {
        edrect.width = edrect.width || (edrect.right - edrect.left);
        edrect.height = edrect.height || (edrect.bottom - edrect.top);
      }

      // ====== КАК НА СКРИНШОТЕ ======
      // Центрируем по ширине относительно редактора
      left = edrect.left + (edrect.width - pw) / 2;

      // По высоте: центр редактора, НО не залезаем на тулбар
      var desiredTop = edrect.top + (edrect.height - ph) / 2 + 80;


      var minTop = edrect.top + pad;
      if (tbrect) {
        // чтобы попап не перекрывал кнопки тулбара
        minTop = Math.max(minTop, tbrect.bottom + pad);
      }

      var maxTop = edrect.bottom - ph - pad;

      // clamp внутри редакторной области
      if (maxTop < minTop) {
        // если редакторная область маленькая — clamp по viewport
        var vw = Math.max(320, window.innerWidth || 0);
        var vh = Math.max(240, window.innerHeight || 0);

        left = Math.max(8, Math.min(left, vw - pw - 8));
        top  = Math.max(8, Math.min(desiredTop, vh - ph - 8));
      } else {
        left = Math.max(edrect.left + pad, Math.min(left, edrect.right - pw - pad));
        top  = Math.max(minTop, Math.min(desiredTop, maxTop));
      }
    } catch (eP) {}

    pop.style.left = left + 'px';
    pop.style.top = top + 'px';
    pop.style.visibility = 'visible';

    repaintSize();

  }




  function handler(inst, caller) {
    // защита от кликов внутри pre/code
    if (caller && isInPreOrCode(caller)) return;
    showPopover(inst, caller);
  }

  // Регистрируем В ОБА реестра
  window.afAeBuiltinHandlers.table = handler;
  window.afAqrBuiltinHandlers.table = handler;

})();

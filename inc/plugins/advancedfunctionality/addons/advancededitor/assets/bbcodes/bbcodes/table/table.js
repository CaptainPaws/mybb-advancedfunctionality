(function () {
  'use strict';

  // ===== registries =====
  if (!window.afAeBuiltinHandlers)  window.afAeBuiltinHandlers  = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  // one-shot
  if (window.__afAeTablePackLoaded) return;
  window.__afAeTablePackLoaded = true;
  if (!window.AFAE || typeof window.AFAE.hasEditor !== 'function' || !window.AFAE.hasEditor()) return;

  // НЕ ТРОГАЕМ: чтобы не сломать кнопку/конструктор/manifest
  var ID  = 'table';
  var CMD = 'af_table';

  function asText(x) { return String(x == null ? '' : x); }

  function hasSceditor() {
    return !!(window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.sceditor === 'function');
  }

  // ===== instance helpers (паттерн как в floatbb.js) =====
  function getTextareaFromCtx(ctx) {
    if (ctx && ctx.textarea && ctx.textarea.nodeType === 1) return ctx.textarea;
    if (ctx && ctx.ta && ctx.ta.nodeType === 1) return ctx.ta;

    var ae = document.activeElement;
    if (ae && ae.tagName === 'TEXTAREA') return ae;

    return document.querySelector('textarea#message') ||
      document.querySelector('textarea[name="message"]') ||
      null;
  }

  function getSceditorInstanceFromCtx(ctx) {
    if (ctx && typeof ctx.insertText === 'function') return ctx;
    if (ctx && typeof ctx.createDropDown === 'function') return ctx;
    if (ctx && ctx.sceditor && typeof ctx.sceditor.insertText === 'function') return ctx.sceditor;
    if (ctx && ctx.inst && typeof ctx.inst.insertText === 'function') return ctx.inst;
    if (ctx && ctx.instance && typeof ctx.instance.insertText === 'function') return ctx.instance;

    try {
      if (window.jQuery) {
        var $ = window.jQuery;
        var $ta = $('textarea#message, textarea[name="message"]').first();
        if ($ta.length) {
          var inst = $ta.sceditor && $ta.sceditor('instance');
          if (inst && typeof inst.insertText === 'function') return inst;
        }
      }
    } catch (e) {}

    return null;
  }

  function insertAtCursor(ta, text) {
    if (!ta) return false;
    text = asText(text);

    try {
      var start = (typeof ta.selectionStart === 'number') ? ta.selectionStart : 0;
      var end   = (typeof ta.selectionEnd === 'number') ? ta.selectionEnd : start;
      var val   = String(ta.value || '');

      ta.value = val.slice(0, start) + text + val.slice(end);

      var caret = start + text.length;
      ta.focus();
      try { ta.setSelectionRange(caret, caret); } catch (e0) {}
      try { ta.dispatchEvent(new Event('input', { bubbles: true })); } catch (e1) {}
      return true;
    } catch (e) {
      return false;
    }
  }

  function insertTextToEditor(editor, bb) {
    bb = asText(bb);
    if (!bb) return false;

    try {
      if (editor && typeof editor.insertText === 'function') {
        editor.insertText(bb, '');
        if (typeof editor.focus === 'function') editor.focus();
        return true;
      }
    } catch (e0) {}

    // fallback: textarea
    var ta = getTextareaFromCtx({ sceditor: editor });
    return insertAtCursor(ta, bb);
  }

  // --------- BBCode builder (как было) ---------
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

    function normColor(s) {
      s = asText(s).trim();
      if (!s) return '';
      if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(s)) return s.toLowerCase();
      return '';
    }

    function normBorderWidth(s) {
      var n = parseInt(asText(s).trim(), 10);
      if (!isFinite(n)) return '';
      if (n < 0) n = 0;
      if (n > 20) n = 20;
      return String(n) + 'px';
    }

    var width = asText(opts.width).trim();
    var align = asText(opts.align).trim();
    var headers = asText(opts.headers).trim();
    var fill = !!opts.fill;

    var colWidths = Array.isArray(opts.colWidths) ? opts.colWidths : parseWidthList(opts.colWidths, cols);

    // styling (если пусто — НЕ пишем атрибут => наследуем от темы)
    var cellBg = normColor(opts.cellBg || opts.bgcolor || '');
    var textColor = normColor(opts.textColor || opts.textcolor || '');

    // header-specific (если пусто — не пишем)
    var headBg = normColor(opts.headBg || opts.hbgcolor || '');
    var headText = normColor(opts.headText || opts.htextcolor || '');

    var borderOn = (String(opts.borderOn) === '1' || opts.borderOn === true);
    var borderColor = normColor(opts.borderColor || opts.bordercolor || '');
    var borderWidth = normBorderWidth(opts.borderWidth || opts.borderwidth || '');

    var attrs = [];
    if (width) attrs.push('width=' + width);
    if (align) attrs.push('align=' + align);
    if (headers && headers !== 'none') attrs.push('headers=' + headers);

    if (cellBg) attrs.push('bgcolor=' + cellBg);
    if (textColor) attrs.push('textcolor=' + textColor);

    if (headBg) attrs.push('hbgcolor=' + headBg);
    if (headText) attrs.push('htextcolor=' + headText);

    attrs.push('border=' + (borderOn ? '1' : '0'));
    if (borderOn) {
      if (borderColor) attrs.push('bordercolor=' + borderColor);
      if (borderWidth) attrs.push('borderwidth=' + borderWidth);
    }

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


  // ===== dropdown UI (как floatbb.js: createDropDown) =====
  function makeDropdown(editor, caller) {
    var wrap = document.createElement('div');
    wrap.className = 'af-table-dd';

    wrap.innerHTML =
      '<div class="af-table-dd-hd">' +
      '  <div class="af-table-dd-title">Таблица</div>' +
      '</div>' +
      '<div class="af-table-dd-body">' +
      '  <div class="af-table-dd-size"><span class="af-table-dd-sizeval">2 × 2</span></div>' +
      '  <div class="af-table-dd-opts">' +

      '    <div class="af-table-dd-row is-rc">' +
      '      <label class="af-table-dd-field"><span>Колонок</span><input type="number" min="1" max="50" step="1" class="af-t-cols" value="2"></label>' +
      '      <label class="af-table-dd-field"><span>Строк</span><input type="number" min="1" max="50" step="1" class="af-t-rows" value="2"></label>' +
      '    </div>' +

      '    <label class="af-table-dd-row"><span>Ширина</span><input type="text" class="af-t-width" placeholder="например 100% или 500px"></label>' +
      '    <label class="af-table-dd-row"><span>Ширины колонок</span><input type="text" class="af-t-colwidths" placeholder="например 120px,200px,300px"></label>' +

      '    <label class="af-table-dd-row"><span>Выравнивание</span>' +
      '      <select class="af-t-align">' +
      '        <option value="">—</option>' +
      '        <option value="left">left</option>' +
      '        <option value="center">center</option>' +
      '        <option value="right">right</option>' +
      '      </select>' +
      '    </label>' +

      '    <label class="af-table-dd-row"><span>Заголовки</span>' +
      '      <select class="af-t-headers">' +
      '        <option value="none">none</option>' +
      '        <option value="row">row</option>' +
      '        <option value="col">col</option>' +
      '        <option value="both">both</option>' +
      '      </select>' +
      '    </label>' +

      '    <label class="af-table-dd-row"><span>Заполнить</span>' +
      '      <select class="af-t-fill"><option value="0">нет</option><option value="1">да</option></select>' +
      '    </label>' +

      // ===== colors (inherit by default via enable checkboxes) =====
      '    <div class="af-table-dd-row is-rc">' +
      '      <label class="af-table-dd-field">' +
      '        <span><input type="checkbox" class="af-t-cellbg-on"> Заливка</span>' +
      '        <input type="color" class="af-t-cellbg" value="#000000" disabled>' +
      '      </label>' +
      '      <label class="af-table-dd-field">' +
      '        <span><input type="checkbox" class="af-t-textcolor-on"> Цвет текста</span>' +
      '        <input type="color" class="af-t-textcolor" value="#000000" disabled>' +
      '      </label>' +
      '    </div>' +

      '    <div class="af-table-dd-row is-rc">' +
      '      <label class="af-table-dd-field">' +
      '        <span><input type="checkbox" class="af-t-headbg-on"> Заливка заголовков</span>' +
      '        <input type="color" class="af-t-headbg" value="#000000" disabled>' +
      '      </label>' +
      '      <label class="af-table-dd-field">' +
      '        <span><input type="checkbox" class="af-t-headtext-on"> Текст заголовков</span>' +
      '        <input type="color" class="af-t-headtext" value="#000000" disabled>' +
      '      </label>' +
      '    </div>' +

      // borders
      '    <div class="af-table-dd-row is-rc">' +
      '      <label class="af-table-dd-field"><span>Цвет бордера</span><input type="color" class="af-t-bordercolor" value="#ffffff"></label>' +
      '      <label class="af-table-dd-field"><span>Бордеры</span>' +
      '        <select class="af-t-borderon"><option value="0">нет</option><option value="1" selected>да</option></select>' +
      '      </label>' +
      '    </div>' +

      '    <div class="af-table-dd-row is-rc">' +
      '      <label class="af-table-dd-field"><span>Толщина</span><input type="number" min="0" max="20" step="1" class="af-t-borderwidth" value="1"></label>' +
      '      <div class="af-table-dd-field" aria-hidden="true"></div>' +
      '    </div>' +

      '    <div class="af-table-dd-actions">' +
      '      <button type="button" class="button af-t-insert">Вставить</button>' +
      '    </div>' +

      '  </div>' +
      '</div>';

    var sizeEl = wrap.querySelector('.af-table-dd-sizeval');

    var inpCols = wrap.querySelector('.af-t-cols');
    var inpRows = wrap.querySelector('.af-t-rows');
    var inpWidth = wrap.querySelector('.af-t-width');
    var inpColWidths = wrap.querySelector('.af-t-colwidths');
    var selAlign = wrap.querySelector('.af-t-align');
    var selHeaders = wrap.querySelector('.af-t-headers');
    var selFill = wrap.querySelector('.af-t-fill');

    // color toggles + inputs
    var chkCellBg = wrap.querySelector('.af-t-cellbg-on');
    var inpCellBg = wrap.querySelector('.af-t-cellbg');

    var chkText = wrap.querySelector('.af-t-textcolor-on');
    var inpText = wrap.querySelector('.af-t-textcolor');

    var chkHeadBg = wrap.querySelector('.af-t-headbg-on');
    var inpHeadBg = wrap.querySelector('.af-t-headbg');

    var chkHeadText = wrap.querySelector('.af-t-headtext-on');
    var inpHeadText = wrap.querySelector('.af-t-headtext');

    // borders
    var inpBorderColor = wrap.querySelector('.af-t-bordercolor');
    var selBorderOn = wrap.querySelector('.af-t-borderon');
    var inpBorderWidth = wrap.querySelector('.af-t-borderwidth');

    var btnInsert = wrap.querySelector('.af-t-insert');

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

    function updateBorderUi() {
      var on = (selBorderOn && selBorderOn.value === '1');
      if (inpBorderColor) inpBorderColor.disabled = !on;
      if (inpBorderWidth) inpBorderWidth.disabled = !on;
    }

    function syncColorEnable(chk, inp) {
      if (!chk || !inp) return;
      inp.disabled = !chk.checked;
    }

    function closeDd() {
      try { editor.closeDropDown(true); } catch (e0) {}
    }

    function insertNow() {
      var cols = clampInt(inpCols && inpCols.value, 1, 50, 2);
      var rows = clampInt(inpRows && inpRows.value, 1, 50, 2);

      var width = asText(inpWidth && inpWidth.value).trim();
      var colWidthsRaw = asText(inpColWidths && inpColWidths.value).trim();
      var align = asText(selAlign && selAlign.value).trim();
      var headers = asText(selHeaders && selHeaders.value).trim();
      var fill = asText(selFill && selFill.value) === '1';

      // colors: только если включено — иначе пусто => наследование
      var cellBg = (chkCellBg && chkCellBg.checked) ? asText(inpCellBg && inpCellBg.value).trim() : '';
      var textColor = (chkText && chkText.checked) ? asText(inpText && inpText.value).trim() : '';

      var headBg = (chkHeadBg && chkHeadBg.checked) ? asText(inpHeadBg && inpHeadBg.value).trim() : '';
      var headText = (chkHeadText && chkHeadText.checked) ? asText(inpHeadText && inpHeadText.value).trim() : '';

      var borderColor = asText(inpBorderColor && inpBorderColor.value).trim();
      var borderOn = (selBorderOn && selBorderOn.value === '1');
      var borderWidth = String(clampInt(inpBorderWidth && inpBorderWidth.value, 0, 20, 1));

      var bb = buildBbcode(rows, cols, {
        width: width,
        colWidths: colWidthsRaw,
        align: align,
        headers: headers,
        fill: fill,

        cellBg: cellBg,
        textColor: textColor,
        headBg: headBg,
        headText: headText,

        borderOn: borderOn,
        borderColor: borderColor,
        borderWidth: borderWidth
      });

      insertTextToEditor(editor, bb);
      closeDd();
    }

    function bindEnter(el) {
      if (!el || el._afTableEnterBound) return;
      el._afTableEnterBound = true;
      el.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          insertNow();
        }
      }, false);
    }

    // init
    repaintSize();
    updateBorderUi();
    syncColorEnable(chkCellBg, inpCellBg);
    syncColorEnable(chkText, inpText);
    syncColorEnable(chkHeadBg, inpHeadBg);
    syncColorEnable(chkHeadText, inpHeadText);

    // binds
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

    if (selBorderOn && !selBorderOn._afBound) {
      selBorderOn._afBound = true;
      selBorderOn.addEventListener('change', updateBorderUi, false);
    }

    function bindChk(chk, inp) {
      if (!chk || chk._afBound) return;
      chk._afBound = true;
      chk.addEventListener('change', function () { syncColorEnable(chk, inp); }, false);
    }
    bindChk(chkCellBg, inpCellBg);
    bindChk(chkText, inpText);
    bindChk(chkHeadBg, inpHeadBg);
    bindChk(chkHeadText, inpHeadText);

    bindEnter(inpCols);
    bindEnter(inpRows);
    bindEnter(inpWidth);
    bindEnter(inpColWidths);
    bindEnter(inpBorderWidth);

    if (btnInsert && !btnInsert._afBound) {
      btnInsert._afBound = true;
      btnInsert.addEventListener('click', function (ev) {
        ev.preventDefault();
        insertNow();
      }, false);
    }

    return wrap;
  }

  function openSceditorDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;
    try { editor.closeDropDown(true); } catch (e0) {}

    var wrap = makeDropdown(editor, caller);
    editor.createDropDown(caller, 'sceditor-table-picker', wrap);
    return true;
  }

  // ===== SCEditor command =====
  function patchSceditorTableCommand() {
    if (!hasSceditor()) return false;

    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.command) return false;

    $.sceditor.command.set(CMD, {
      exec: function (caller) {
        // НЕ вставляем автоматически, только открываем dropdown
        if (!openSceditorDropdown(this, caller)) {
          // fallback: если dropdown совсем недоступен — вставим минималку
          insertTextToEditor(this, buildBbcode(2, 2, { headers: 'none' }));
        }
      },
      txtExec: function (caller) {
        if (!openSceditorDropdown(this, caller)) {
          insertTextToEditor(this, buildBbcode(2, 2, { headers: 'none' }));
        }
      },
      tooltip: 'Таблица'
    });

    return true;
  }

  function waitAnd(fn, maxTries) {
    var tries = 0;
    (function tick() {
      tries++;
      if (fn()) return;
      if (tries > (maxTries || 150)) return;
      setTimeout(tick, 100);
    })();
  }

  waitAnd(patchSceditorTableCommand, 150);

  // ===== handlers for AQR/AE core =====
  function aqrOpen(ctx, ev) {
    var editor = getSceditorInstanceFromCtx(ctx);
    var caller =
      (ctx && (ctx.buttonEl || ctx.btn || ctx.caller)) ||
      (ev && (ev.currentTarget || ev.target)) ||
      null;

    if (editor && caller && caller.nodeType === 1) {
      if (ev && ev.preventDefault) ev.preventDefault();
      openSceditorDropdown(editor, caller);
      return;
    }

    // fallback без dropdown
    insertTextToEditor(editor, buildBbcode(2, 2, { headers: 'none' }));
  }

  var handlerObj = {
    id: ID,
    title: 'Таблица',
    onClick: aqrOpen,
    click: aqrOpen,
    action: aqrOpen,
    run: aqrOpen,
    init: function () {}
  };

  function handlerFn(inst, caller) {
    var editor = getSceditorInstanceFromCtx(inst || {});
    if (!editor) editor = getSceditorInstanceFromCtx({});
    if (!editor) return;

    if (caller && caller.nodeType === 1) openSceditorDropdown(editor, caller);
    else insertTextToEditor(editor, buildBbcode(2, 2, { headers: 'none' }));
  }

  function registerHandlers() {
    // AQR
    window.afAqrBuiltinHandlers[ID] = handlerObj;
    window.afAqrBuiltinHandlers[CMD] = handlerObj;

    // AE
    window.afAeBuiltinHandlers[ID] = handlerFn;
    window.afAeBuiltinHandlers[CMD] = handlerFn;
  }

  registerHandlers();
  for (var i = 1; i <= 20; i++) setTimeout(registerHandlers, i * 250);

  // точка входа, если AE-core зовёт window.af_ae_<handler>_exec
  window.af_ae_table_exec = function (editor, def, caller) {
    if (!openSceditorDropdown(editor, caller)) {
      insertTextToEditor(editor, buildBbcode(2, 2, { headers: 'none' }));
    }
  };

})();

(function () {
  'use strict';

  function asText(x) { return String(x == null ? '' : x); }

  // Регистр хендлеров паков (ядро должно только дергать их)
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  function escCss(s) { return String(s || '').replace(/[^a-z0-9_\-]/gi, '_'); }

  function isInPreOrCode(el) {
    try {
      return !!(el && el.closest && el.closest('pre, code'));
    } catch (e) { return false; }
  }

  // ====== TABLE UI (вставка BBCode) ======
  function findTextareaByInstance(inst) {
    try {
      var tas = document.querySelectorAll('textarea');
      for (var i = 0; i < tas.length; i++) {
        var ta = tas[i];
        if (!ta || !window.jQuery) continue;
        var got = null;
        try { got = jQuery(ta).sceditor('instance'); } catch (e0) { got = null; }
        if (got && got === inst) return ta;
      }
    } catch (e) {}
    return null;
  }

  function buildBbcode(rows, cols, opts) {
    rows = Math.max(1, Math.min(50, rows | 0));
    cols = Math.max(1, Math.min(50, cols | 0));
    opts = opts || {};

    function normWidthToken(s) {
      s = asText(s).trim();
      if (!s) return '';
      // только простые значения (чтобы не тащить инъекции в BBCode)
      // 100 -> 100px
      // 100px / 80% / 12em / 10rem / 20vw / 30vh
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
    var align = asText(opts.align).trim();      // left|center|right
    var headers = asText(opts.headers).trim();  // none|row|col|both
    var fill = !!opts.fill;

    // NEW: widths per column (comma separated)
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

  function showPopover(inst, caller) {
    var ta = findTextareaByInstance(inst);
    var form = null;

    try { form = ta ? (ta.closest('form') || null) : null; } catch (e0) { form = null; }
    if (!form) form = document.querySelector('form#quick_reply_form') || document.querySelector('form');

    var anchor = caller && caller.nodeType === 1 ? caller : null;

    // backdrop (кликаешь вне окна — закрывается)
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

    // popover
    var pop = document.getElementById('af-aqr-tablepop');
    if (!pop) {
      pop = document.createElement('div');
      pop.id = 'af-aqr-tablepop';
      document.body.appendChild(pop);
    }

    // на случай если попап уже создан старым HTML — перезальём разметку
    if (!pop._afTableUiV2 || !pop.querySelector('.af-aqr-t-cols') || !pop.querySelector('.af-aqr-t-rows')) {
      pop._afTableUiV2 = true;
      pop.style.zIndex = '99999';
      pop.style.position = 'absolute';
      pop.style.display = 'none';
      pop.style.pointerEvents = 'auto';

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

      try { inst && inst.focus && inst.focus(); } catch (e0) {}
      try { inst && inst.insert && inst.insert(bb, ''); } catch (e1) {}

      close();
    }

    // события изменения размера
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

    // enter внутри полей -> вставить
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
    bindEnter(inpCols);
    bindEnter(inpRows);
    bindEnter(inpWidth);
    bindEnter(inpColWidths);

    // кнопки
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

    // закрытие по клику вне окна — через backdrop
    if (!backdrop._afBound) {
      backdrop._afBound = true;
      backdrop.addEventListener('mousedown', function (ev) { ev.preventDefault(); close(); }, false);
      backdrop.addEventListener('pointerdown', function (ev) { ev.preventDefault(); close(); }, false);
    }

    // позиционирование
    var left = 40, top = 40;
    try {
      var a = anchor ? anchor.getBoundingClientRect() : pop.getBoundingClientRect();
      top = (a.bottom + window.scrollY + 6);
      left = (a.left + window.scrollX);
    } catch (e2) {}

    pop.style.top = top + 'px';
    pop.style.left = left + 'px';

    backdrop.style.display = 'block';
    pop.style.display = 'block';

    // обновим подпись
    repaintSize();
  }

  // handler, который дергает ядро JS
  window.afAqrBuiltinHandlers.table = function (inst, caller) {
    try { showPopover(inst, caller); } catch (e) {}
  };

  // ====== QUICK EDIT FIX: рендер таблиц в уже вставленном HTML ======
  function protectBlocks(html) {
    var prot = [];
    var out = html.replace(/<(pre|code)\b[^>]*>[\s\S]*?<\/\1>/gi, function (m) {
      var key = '%%AQR_TBL_JS_PROTECT_' + prot.length + '%%';
      prot.push({ key: key, val: m });
      return key;
    });
    return { html: out, prot: prot };
  }


  function unprotectBlocks(html, prot) {
    var out = html;
    for (var i = 0; i < prot.length; i++) {
      out = out.split(prot[i].key).join(prot[i].val);
    }
    return out;
  }


  function parseAttrs(raw) {
    raw = asText(raw).trim();
    var out = { width: '', align: '', headers: '' };

    var m = raw.match(/\bwidth\s*=\s*([0-9]{1,4})(px|%)\b/i);
    if (m) out.width = m[1] + m[2];

    var a = raw.match(/\balign\s*=\s*(left|center|right)\b/i);
    if (a) out.align = a[1].toLowerCase();

    var h = raw.match(/\bheaders\s*=\s*(none|row|col|both)\b/i);
    if (h) out.headers = (h[1].toLowerCase() === 'none') ? '' : h[1].toLowerCase();

    return out;
  }

  function renderTablesInHtml(html) {
    if (!html || html.indexOf('[table') === -1) return html;

    var p = protectBlocks(html);
    var h = p.html;

    // рендерим вложенные таблицы изнутри наружу
    var re = /\[table([^\]]*)\]((?:(?!\[table)[\s\S])*?)\[\/table\]/gi;
    var guard = 0;

    while (h.indexOf('[table') !== -1 && guard++ < 30) {
      var before = h;

      h = h.replace(re, function (full, attrRaw, body) {
        var attrs = parseAttrs(attrRaw || '');

        // чистим <br> от переносов между тегами
        body = body.replace(/\]\s*<br\s*\/?>\s*\[/gi, '][');
        body = body.replace(/<br\s*\/?>\s*\[(\/?(?:tr|td|th)(?:[^\]]*)?)\]/gi, '[$1]');
        body = body.replace(/\[(\/?(?:tr|td|th)(?:[^\]]*)?)\]\s*<br\s*\/?>/gi, '[$1]');
        body = body.replace(/^(?:\s*<br\s*\/?>\s*)+/i, '');
        body = body.replace(/(?:\s*<br\s*\/?>\s*)+$/i, '');

        var x = body;
        // tr
        x = x.replace(/\[(\/?)tr\]/gi, '<$1tr>');

        // td/th с width=...
        x = x.replace(/\[(td|th)([^\]]*)\]/gi, function (_, tag, raw) {
          tag = String(tag || '').toLowerCase();
          raw = String(raw || '');

          var style = '';
          var m = raw.match(/\bwidth\s*=\s*([0-9]{1,4})(px|%|em|rem|vw|vh)?\b/i);
          if (m) {
            var unit = m[2] ? m[2].toLowerCase() : 'px';
            style = ' style="width:' + m[1] + unit + '"';
          }
          return '<' + tag + style + '>';
        });

        x = x.replace(/\[\/td\]/gi, '</td>');
        x = x.replace(/\[\/th\]/gi, '</th>');


        var styles = [];
        if (attrs.width) styles.push('width:' + attrs.width);

        if (attrs.align) {
          if (attrs.align === 'center') { styles.push('margin-left:auto'); styles.push('margin-right:auto'); }
          if (attrs.align === 'right') { styles.push('margin-left:auto'); }
          if (attrs.align === 'left') { styles.push('margin-right:auto'); }
        }

        var styleAttr = styles.length ? ' style="' + styles.join(';') + '"' : '';
        var dataHeaders = attrs.headers ? ' data-headers="' + attrs.headers + '"' : '';

        return '<table class="af-aqr-table" data-af-aqr-table="1"' + styleAttr + dataHeaders + '>' + x + '</table>';
      });

      if (h === before) break;
    }

    h = unprotectBlocks(h, p.prot);
    return h;
  }

  function renderTablesInRoot(root) {
    if (!root || !root.querySelectorAll) return;

    // не лезем в code/pre-контейнеры
    if (root.matches && root.matches('pre, code')) return;

    // ищем типичные контейнеры постов; если не нашли — берём root
    var candidates = [];
    var qs = [
      '.post_body',
      '.post_message',
      '.postcontent',
      '.message',
      '.trow1 .message',
      '.trow2 .message'
    ].join(',');

    try {
      var found = root.querySelectorAll(qs);
      if (found && found.length) {
        for (var i = 0; i < found.length; i++) candidates.push(found[i]);
      }
    } catch (e) {}

    if (!candidates.length) candidates = [root];

    candidates.forEach(function (el) {
      if (!el || !el.innerHTML) return;
      if (isInPreOrCode(el)) return;
      if (el.innerHTML.indexOf('[table') === -1) return;

      // если уже есть html-таблица с маркером — не трогаем
      if (el.querySelector && el.querySelector('table.af-aqr-table[data-af-aqr-table="1"]')) return;

      var before = el.innerHTML;
      var after = renderTablesInHtml(before);
      if (after !== before) el.innerHTML = after;
    });
  }

  // initial
  try { renderTablesInRoot(document); } catch (e0) {}

  // MutationObserver: ловим quick edit / ajax подмены
  try {
    var mo = new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i++) {
        var m = muts[i];
        if (!m) continue;
        for (var j = 0; j < (m.addedNodes ? m.addedNodes.length : 0); j++) {
          var n = m.addedNodes[j];
          if (!n || n.nodeType !== 1) continue;
          // если прям в вставленном HTML есть [table]
          try {
            if (n.innerHTML && n.innerHTML.indexOf('[table') !== -1) renderTablesInRoot(n);
            else if (n.querySelector && n.querySelector('[data-post], .post, .post_body, .message')) renderTablesInRoot(n);
          } catch (e1) {}
        }
      }
    });

    mo.observe(document.body, { childList: true, subtree: true });
  } catch (e2) {}
})();

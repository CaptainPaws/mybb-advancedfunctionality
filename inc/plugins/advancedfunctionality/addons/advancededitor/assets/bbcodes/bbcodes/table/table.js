(function () {
  'use strict';

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);
  if (window.__afAeTablePackLoaded) return;
  window.__afAeTablePackLoaded = true;

  var ID = 'table';
  var CMD = 'af_table';
  var TABLE_ATTR_KEYS = ['width', 'align', 'headers', 'bgcolor', 'textcolor', 'hbgcolor', 'htextcolor', 'border', 'bordercolor', 'borderwidth'];

  function asText(v) { return String(v == null ? '' : v); }
  function hasSceditor() { return !!(window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.sceditor === 'function'); }
  function isSourceMode(inst) {
    try { return !!(inst && ((typeof inst.sourceMode === 'function' && inst.sourceMode()) || (typeof inst.inSourceMode === 'function' && inst.inSourceMode()))); } catch (e) { return false; }
  }

  function normColor(s) {
    s = asText(s).trim();
    return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(s) ? s.toLowerCase() : '';
  }
  function normWidthToken(s) {
    s = asText(s).trim();
    var m = s.match(/^([0-9]{1,4})(px|%|em|rem|vw|vh)?$/i);
    return m ? (m[1] + (m[2] ? m[2].toLowerCase() : 'px')) : '';
  }
  function normBorderWidth(s) {
    s = asText(s).trim();
    if (/^[0-9]{1,2}px$/i.test(s)) return s.toLowerCase();
    var n = parseInt(s, 10);
    if (!isFinite(n)) return '';
    if (n < 0) n = 0;
    if (n > 20) n = 20;
    return String(n) + 'px';
  }
  function parseWidthList(raw, limit) {
    var parts = asText(raw).split(/[,;]+/g);
    var out = [];
    for (var i = 0; i < parts.length && out.length < limit; i++) {
      var w = normWidthToken(parts[i]);
      if (w) out.push(w);
    }
    return out;
  }

  function normalizeTableAttrs(attrs) {
    attrs = attrs || {};
    var out = {
      width: normWidthToken(attrs.width),
      align: '',
      headers: '',
      bgcolor: normColor(attrs.bgcolor),
      textcolor: normColor(attrs.textcolor),
      hbgcolor: normColor(attrs.hbgcolor),
      htextcolor: normColor(attrs.htextcolor),
      border: String(attrs.border) === '0' ? '0' : '1',
      bordercolor: normColor(attrs.bordercolor),
      borderwidth: normBorderWidth(attrs.borderwidth)
    };

    var align = asText(attrs.align).trim().toLowerCase();
    if (align === 'left' || align === 'center' || align === 'right') out.align = align;

    var headers = asText(attrs.headers).trim().toLowerCase();
    if (headers === 'row' || headers === 'col' || headers === 'both') out.headers = headers;

    return out;
  }

  function getTextareaFromCtx(ctx) {
    if (ctx && ctx.textarea && ctx.textarea.nodeType === 1) return ctx.textarea;
    if (ctx && ctx.ta && ctx.ta.nodeType === 1) return ctx.ta;
    var ae = document.activeElement;
    if (ae && ae.tagName === 'TEXTAREA') return ae;
    return document.querySelector('textarea#message') || document.querySelector('textarea[name="message"]') || null;
  }

  function getSceditorInstanceFromCtx(ctx) {
    if (ctx && typeof ctx.insertText === 'function') return ctx;
    if (ctx && ctx.sceditor && typeof ctx.sceditor.insertText === 'function') return ctx.sceditor;
    if (ctx && ctx.inst && typeof ctx.inst.insertText === 'function') return ctx.inst;
    if (ctx && ctx.instance && typeof ctx.instance.insertText === 'function') return ctx.instance;
    try {
      if (window.jQuery) {
        var $ta = window.jQuery('textarea#message, textarea[name="message"]').first();
        if ($ta.length) return $ta.sceditor('instance');
      }
    } catch (e) {}
    return null;
  }

  function getInstBodySafe(inst) {
    if (!inst || typeof inst.getBody !== 'function') return null;
    try {
      var body = inst.getBody();
      return (body && body.nodeType === 1 && body.querySelectorAll) ? body : null;
    } catch (e) { return null; }
  }

  function tableAttrsToBbOpen(attrs) {
    attrs = normalizeTableAttrs(attrs);
    var parts = [];
    if (attrs.width) parts.push('width=' + attrs.width);
    if (attrs.align) parts.push('align=' + attrs.align);
    if (attrs.headers) parts.push('headers=' + attrs.headers);
    if (attrs.bgcolor) parts.push('bgcolor=' + attrs.bgcolor);
    if (attrs.textcolor) parts.push('textcolor=' + attrs.textcolor);
    if (attrs.hbgcolor) parts.push('hbgcolor=' + attrs.hbgcolor);
    if (attrs.htextcolor) parts.push('htextcolor=' + attrs.htextcolor);
    parts.push('border=' + (attrs.border === '0' ? '0' : '1'));
    if (attrs.border === '1') {
      if (attrs.bordercolor) parts.push('bordercolor=' + attrs.bordercolor);
      if (attrs.borderwidth) parts.push('borderwidth=' + attrs.borderwidth);
    }
    return '[table' + (parts.length ? ' ' + parts.join(' ') : '') + ']';
  }

  function parseAttrsFromDom(tableEl) {
    var attrs = { width:'', align:'', headers:'', bgcolor:'', textcolor:'', hbgcolor:'', htextcolor:'', border:'1', bordercolor:'', borderwidth:'' };
    if (!tableEl || tableEl.nodeType !== 1) return attrs;

    for (var i = 0; i < TABLE_ATTR_KEYS.length; i++) {
      var key = TABLE_ATTR_KEYS[i];
      var v = asText(tableEl.getAttribute('data-af-' + key)).trim();
      if (v) attrs[key] = v;
    }

    if (!attrs.headers) attrs.headers = asText(tableEl.getAttribute('data-headers')).trim().toLowerCase();
    return normalizeTableAttrs(attrs);
  }

  function getDirectChildRows(tableEl) {
    var rows = [];
    if (!tableEl || tableEl.nodeType !== 1) return rows;

    var children = tableEl.children || [];
    for (var i = 0; i < children.length; i++) {
      var tag = (children[i].tagName || '').toLowerCase();
      if (tag === 'tr') rows.push(children[i]);
      if (tag === 'thead' || tag === 'tbody' || tag === 'tfoot') {
        var gch = children[i].children || [];
        for (var j = 0; j < gch.length; j++) {
          if ((gch[j].tagName || '').toLowerCase() === 'tr') rows.push(gch[j]);
        }
      }
    }
    return rows;
  }

  function getDirectRowCells(rowEl) {
    var out = [];
    if (!rowEl || rowEl.nodeType !== 1) return out;
    var children = rowEl.children || [];
    for (var i = 0; i < children.length; i++) {
      var tag = (children[i].tagName || '').toLowerCase();
      if (tag === 'td' || tag === 'th') out.push(children[i]);
    }
    return out;
  }

  function getCanonicalColumnWidth(cellEl) {
    if (!cellEl || !cellEl.parentElement || !cellEl.parentElement.cells) return '';
    var row = cellEl.parentElement;
    var colIndex = -1;
    try { colIndex = Array.prototype.indexOf.call(row.cells, cellEl); } catch (e) { colIndex = -1; }
    if (colIndex < 0) return '';

    var table = row.closest ? row.closest('table') : null;
    if (!table || !table.rows) return '';

    for (var r = 0; r < table.rows.length; r++) {
      var c = table.rows[r].cells[colIndex];
      if (!c) continue;
      var w = normWidthToken(c.style && c.style.width);
      if (!w) w = normWidthToken(c.getAttribute('data-af-width') || c.getAttribute('width') || '');
      if (w) return w;
    }
    return '';
  }

  function genericHtmlToBb(inst, html) {
    html = asText(html);
    if (!html) return '';
    try { if (inst && typeof inst.toBBCode === 'function') return asText(inst.toBBCode(html)); } catch (e0) {}
    try {
      var plugin = (inst && typeof inst.getPlugin === 'function') ? inst.getPlugin('bbcode') : null;
      if (plugin && typeof plugin.signalToSource === 'function') return asText(plugin.signalToSource(html));
    } catch (e1) {}
    return '';
  }

  function serializeCellContentForTable(cellEl, inst) {
    if (!cellEl || cellEl.nodeType !== 1) return '';
    var box = (cellEl.ownerDocument || document).createElement('div');
    box.innerHTML = cellEl.innerHTML;
    replaceTopLevelTablesWithBb(box, inst);
    var bb = genericHtmlToBb(inst, box.innerHTML);
    if (!bb) bb = asText(box.textContent || '');
    bb = bb.replace(/^\s+|\s+$/g, '');
    if (bb === '[br]' || bb === '[br/]') bb = '';
    if (/^(?:\s|\[br\]|\[br\/\]|&nbsp;)+$/i.test(bb)) bb = '';
    return bb;
  }

  function modelToCanonicalBbcode(model) {
    model = model || { attrs: {}, rows: [] };
    var out = [tableAttrsToBbOpen(model.attrs || {})];
    var rows = Array.isArray(model.rows) ? model.rows : [];

    for (var r = 0; r < rows.length; r++) {
      var cells = Array.isArray(rows[r].cells) ? rows[r].cells : [];
      out.push('[tr]');
      for (var c = 0; c < cells.length; c++) {
        var cell = cells[c] || {};
        var tag = asText(cell.tag).toLowerCase() === 'th' ? 'th' : 'td';
        var width = normWidthToken(cell.width);
        out.push('[' + tag + (width ? ' width=' + width : '') + ']' + asText(cell.content) + '[/' + tag + ']');
      }
      out.push('[/tr]');
    }

    out.push('[/table]');
    return out.join('\n');
  }

  function serializeTableDomToCanonicalBb(tableEl, inst) {
    if (!tableEl || tableEl.nodeType !== 1) return '';
    var model = { attrs: parseAttrsFromDom(tableEl), rows: [] };
    var rows = getDirectChildRows(tableEl);

    for (var i = 0; i < rows.length; i++) {
      var rowModel = { cells: [] };
      var cells = getDirectRowCells(rows[i]);
      for (var j = 0; j < cells.length; j++) {
        var cell = cells[j];
        rowModel.cells.push({
          tag: ((cell.tagName || '').toLowerCase() === 'th') ? 'th' : 'td',
          width: getCanonicalColumnWidth(cell),
          content: serializeCellContentForTable(cell, inst)
        });
      }
      model.rows.push(rowModel);
    }

    return modelToCanonicalBbcode(model);
  }

  function isSerializableTable(table) {
    if (!table || table.nodeType !== 1 || (table.tagName || '').toLowerCase() !== 'table') return false;
    try { if (table.closest('pre,code')) return false; } catch (e0) {}
    try { if (table.hasAttribute('data-af-no-table-serialize')) return false; } catch (e1) {}
    try { return !!table.querySelector('tr td, tr th'); } catch (e2) { return false; }
  }

  function replaceTopLevelTablesWithBb(root, inst) {
    if (!root || !root.querySelectorAll) return;
    var tables = root.querySelectorAll('table');
    for (var i = 0; i < tables.length; i++) {
      var table = tables[i];
      if (!isSerializableTable(table)) continue;
      var nested = false;
      try { nested = !!(table.parentElement && table.parentElement.closest('table')); } catch (e0) { nested = false; }
      if (nested || !table.parentNode) continue;
      table.parentNode.replaceChild((root.ownerDocument || document).createTextNode('\n' + serializeTableDomToCanonicalBb(table, inst) + '\n'), table);
    }
  }

  function applyCanonicalAttrsToTableDom(tableEl, attrs) {
    if (!tableEl || tableEl.nodeType !== 1) return;
    attrs = normalizeTableAttrs(attrs || parseAttrsFromDom(tableEl));

    for (var i = 0; i < TABLE_ATTR_KEYS.length; i++) {
      var k = TABLE_ATTR_KEYS[i];
      var v = asText(attrs[k]).trim();
      if (k === 'border' && !v) v = '1';
      tableEl.setAttribute('data-af-' + k, v);
    }
    tableEl.setAttribute('data-af-table', '1');
    tableEl.setAttribute('data-headers', attrs.headers || '');
    tableEl.classList.add('af-ae-table');
  }

  function createTableModel(rows, cols, opts) {
    rows = Math.max(1, Math.min(50, rows | 0));
    cols = Math.max(1, Math.min(50, cols | 0));
    opts = opts || {};

    var attrs = normalizeTableAttrs({
      width: opts.width,
      align: opts.align,
      headers: opts.headers,
      bgcolor: opts.cellBg,
      textcolor: opts.textColor,
      hbgcolor: opts.headBg,
      htextcolor: opts.headText,
      border: opts.borderOn === false ? '0' : '1',
      bordercolor: opts.borderColor,
      borderwidth: opts.borderWidth
    });

    var colWidths = Array.isArray(opts.colWidths) ? opts.colWidths : parseWidthList(opts.colWidths, cols);
    var model = { attrs: attrs, rows: [] };

    function isHeaderCell(r, c) {
      if (attrs.headers === 'row') return r === 1;
      if (attrs.headers === 'col') return c === 1;
      if (attrs.headers === 'both') return (r === 1 || c === 1);
      return false;
    }

    var fill = !!opts.fill;
    for (var r = 1; r <= rows; r++) {
      var rowModel = { cells: [] };
      for (var c = 1; c <= cols; c++) {
        var tag = isHeaderCell(r, c) ? 'th' : 'td';
        var txt = '';
        if (fill && tag === 'th') {
          if ((attrs.headers === 'row' || attrs.headers === 'both') && r === 1) txt = 'Header ' + c;
          if ((attrs.headers === 'col' || attrs.headers === 'both') && c === 1) txt = 'Row ' + r;
          if (attrs.headers === 'both' && r === 1 && c === 1) txt = ' ';
        }
        rowModel.cells.push({ tag: tag, width: colWidths[c - 1] || '', content: txt });
      }
      model.rows.push(rowModel);
    }

    return model;
  }

  function renderCellHtml(cell) {
    var tag = asText(cell.tag).toLowerCase() === 'th' ? 'th' : 'td';
    var w = normWidthToken(cell.width);
    var content = asText(cell.content).trim() || '<br>';
    return '<' + tag + (w ? ' style="width:' + w + '" data-af-width="' + w + '"' : '') + '>' + content + '</' + tag + '>';
  }

  function modelToWysiwygHtml(model) {
    model = model || { attrs: {}, rows: [] };
    var attrs = normalizeTableAttrs(model.attrs || {});
    var html = ['<table class="af-ae-table" data-af-table="1"'];

    for (var i = 0; i < TABLE_ATTR_KEYS.length; i++) {
      var k = TABLE_ATTR_KEYS[i];
      var v = asText(attrs[k]).trim();
      if (!v && k !== 'border') continue;
      if (k === 'border' && !v) v = '1';
      html.push(' data-af-' + k + '="' + v.replace(/"/g, '&quot;') + '"');
    }
    html.push(' data-headers="' + (attrs.headers || '') + '">');

    var rows = Array.isArray(model.rows) ? model.rows : [];
    for (var r = 0; r < rows.length; r++) {
      var cells = Array.isArray(rows[r].cells) ? rows[r].cells : [];
      html.push('<tr>');
      for (var c = 0; c < cells.length; c++) html.push(renderCellHtml(cells[c] || {}));
      html.push('</tr>');
    }

    html.push('</table><p><br></p>');
    return html.join('');
  }

  function buildBbcode(rows, cols, opts) { return modelToCanonicalBbcode(createTableModel(rows, cols, opts)); }
  function buildHtmlFromBbcode(rows, cols, opts) { return modelToWysiwygHtml(createTableModel(rows, cols, opts)); }

  function getManagedTableFromNode(node) {
    if (!node || node.nodeType !== 1 || !node.closest) return null;
    try { return node.closest('table.af-ae-table,table[data-af-table="1"]'); } catch (e) { return null; }
  }

  function resetFloatingTableState(inst, hostDoc) {
    if (!inst) return;
    try { var panel = hostDoc && hostDoc.getElementById('af-ae-table-floating'); if (panel) panel.style.display = 'none'; } catch (e0) {}
    inst.__afAeActiveTable = null;
    inst.__afAeActiveTableCell = null;
    inst.__afAeTablePanelPointerDown = false;
  }

  function ensureTableCss(inst) {
    try {
      var body = getInstBodySafe(inst);
      if (!body || !body.ownerDocument) return;
      var doc = body.ownerDocument;
      if (doc.getElementById('af-ae-table-css')) return;

      var css = '' +
        'table[data-af-table="1"],table.af-ae-table{border-collapse:collapse;border-spacing:0;max-width:100%;margin:8px 0;}' +
        'table[data-af-table="1"] td,table[data-af-table="1"] th,table.af-ae-table td,table.af-ae-table th{padding:6px 8px;vertical-align:top;border:1px solid #888;}' +
        'table[data-af-table="1"][data-af-border="0"] td,table[data-af-table="1"][data-af-border="0"] th,table.af-ae-table[data-af-border="0"] td,table.af-ae-table[data-af-border="0"] th{border:0;}' +
        'table[data-af-table="1"] tr:first-child > th,table.af-ae-table tr:first-child > th{font-weight:700;text-align:left;}';

      var st = doc.createElement('style');
      st.id = 'af-ae-table-css';
      st.type = 'text/css';
      st.appendChild(doc.createTextNode(css));
      (doc.head || doc.getElementsByTagName('head')[0]).appendChild(st);
    } catch (e) {}
  }

  function bindTableToSourceNormalization(inst) {
    if (!inst || inst.__afAeTableToSourceBound || typeof inst.bind !== 'function') return;
    inst.__afAeTableToSourceBound = true;

    inst.bind('toSource', function (html) {
      html = asText(html);
      if (!html || html.indexOf('<table') === -1) return html;

      var box = document.createElement('div');
      box.innerHTML = html;
      replaceTopLevelTablesWithBb(box, inst);
      return box.innerHTML;
    });
  }

  function bindSubmitSync(inst) {
    if (!inst || inst.__afAeTableSubmitSyncBound) return;
    inst.__afAeTableSubmitSyncBound = true;

    try {
      var ta = getTextareaFromCtx({ sceditor: inst });
      var form = ta && ta.form;
      if (!form) return;
      form.addEventListener('submit', function () {
        try { if (!isSourceMode(inst) && typeof inst.updateOriginal === 'function') inst.updateOriginal(); } catch (e0) {}
      }, true);
    } catch (e) {}
  }

  function ensureInstancePatched(inst) {
    if (!inst) return false;
    ensureTableCss(inst);
    bindTableToSourceNormalization(inst);
    bindSubmitSync(inst);
    bindFloatingEditor(inst);
    return true;
  }

  function getFloatingPanelHostDoc(inst, iframeDoc) {
    try {
      if (inst && typeof inst.getContentAreaContainer === 'function') {
        var c = inst.getContentAreaContainer();
        if (c && c.ownerDocument) return c.ownerDocument;
      }
    } catch (e0) {}
    return iframeDoc || document;
  }

  function getEditorIframeElement(inst, iframeDoc) {
    try {
      if (inst && typeof inst.getContentAreaContainer === 'function') {
        var c = inst.getContentAreaContainer();
        if (c && c.querySelector) return c.querySelector('iframe');
      }
    } catch (e0) {}
    try { if (iframeDoc && iframeDoc.defaultView && iframeDoc.defaultView.frameElement) return iframeDoc.defaultView.frameElement; } catch (e1) {}
    return null;
  }

  function openFloatingEditorForTable(inst, table) {
    if (!inst || !table || isSourceMode(inst)) return;
    var body = getInstBodySafe(inst);
    if (!body || !body.contains(table)) return;

    var doc = body.ownerDocument;
    var hostDoc = getFloatingPanelHostDoc(inst, doc);
    var iframeEl = getEditorIframeElement(inst, doc);
    var panel = hostDoc.getElementById('af-ae-table-floating');

    function syncEditorValue() {
      try { if (typeof inst.updateOriginal === 'function') inst.updateOriginal(); } catch (e0) {}
      try { if (typeof inst.trigger === 'function') inst.trigger('change'); } catch (e1) {}
    }

    function ensurePanelCss() {
      if (hostDoc.getElementById('af-ae-table-floating-css')) return;
      var st = hostDoc.createElement('style');
      st.id = 'af-ae-table-floating-css';
      st.type = 'text/css';
      st.appendChild(hostDoc.createTextNode('#af-ae-table-floating{position:fixed;z-index:99999;background:#1f1f1f;border:1px solid rgba(255,255,255,.16);border-radius:10px;padding:6px;display:none;gap:6px;align-items:center;box-shadow:0 8px 24px rgba(0,0,0,.35);}#af-ae-table-floating .af-ae-tbtn{display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:28px;padding:0 6px;border-radius:8px;border:1px solid rgba(255,255,255,.14);background:#2a2a2a;color:#fff;cursor:pointer;font:600 12px/1 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;}#af-ae-table-floating .af-ae-tbtn:hover{background:#343434;}#af-ae-table-floating .af-ae-tsep{width:1px;height:20px;background:rgba(255,255,255,.12);margin:0 2px;}#af-ae-table-floating .af-ae-tcolors{display:flex;gap:6px;align-items:center;margin-left:4px;}#af-ae-table-floating .af-ae-tinputs{display:flex;gap:6px;align-items:center;}#af-ae-table-floating .af-ae-tinp{height:28px;min-width:90px;border-radius:8px;border:1px solid rgba(255,255,255,.14);background:#2a2a2a;color:#fff;padding:0 8px;font:500 12px/1 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;}#af-ae-table-floating .af-ae-tbtn.is-active{background:#4a73ff;border-color:#6f90ff;}#af-ae-table-floating input[type=color]{width:28px;height:28px;border:0;background:transparent;padding:0;cursor:pointer;}#af-ae-table-floating .af-ae-tclose{margin-left:2px;}'));
      (hostDoc.head || hostDoc.getElementsByTagName('head')[0]).appendChild(st);
    }

    ensurePanelCss();

    if (!panel) {
      panel = hostDoc.createElement('div');
      panel.id = 'af-ae-table-floating';
      panel.innerHTML = '' +
        '<button type="button" class="af-ae-tbtn" data-a="row-above" title="Добавить строку выше">↑R</button>' +
        '<button type="button" class="af-ae-tbtn" data-a="row-below" title="Добавить строку ниже">↓R</button>' +
        '<button type="button" class="af-ae-tbtn" data-a="row-del" title="Удалить строку">-R</button>' +
        '<span class="af-ae-tsep"></span>' +
        '<button type="button" class="af-ae-tbtn" data-a="col-left" title="Добавить колонку слева">←C</button>' +
        '<button type="button" class="af-ae-tbtn" data-a="col-right" title="Добавить колонку справа">→C</button>' +
        '<button type="button" class="af-ae-tbtn" data-a="col-del" title="Удалить колонку">-C</button>' +
        '<span class="af-ae-tsep"></span>' +
        '<button type="button" class="af-ae-tbtn" data-a="align-left" title="Выравнивание влево">L</button>' +
        '<button type="button" class="af-ae-tbtn" data-a="align-center" title="Выравнивание по центру">C</button>' +
        '<button type="button" class="af-ae-tbtn" data-a="align-right" title="Выравнивание вправо">R</button>' +
        '<span class="af-ae-tsep"></span>' +
        '<div class="af-ae-tinputs"><input type="text" class="af-ae-tinp" data-a="tbl-width" placeholder="100% или 500px"><button type="button" class="af-ae-tbtn" data-a="apply-width">W</button><input type="text" class="af-ae-tinp" data-a="col-width-current" placeholder="Текущая колонка"><button type="button" class="af-ae-tbtn" data-a="apply-col-width-current">C1</button><input type="text" class="af-ae-tinp" data-a="col-widths" placeholder="120px,200px,..."><button type="button" class="af-ae-tbtn" data-a="apply-col-widths">CW</button></div>' +
        '<span class="af-ae-tsep"></span>' +
        '<div class="af-ae-tcolors"><input type="color" data-a="bg" title="bgcolor"><input type="color" data-a="fg" title="textcolor"><input type="color" data-a="hbg" title="hbgcolor"><input type="color" data-a="hfg" title="htextcolor"></div>' +
        '<button type="button" class="af-ae-tbtn" data-a="border-toggle" title="Вкл/выкл border">B</button>' +
        '<input type="text" class="af-ae-tinp" data-a="border-width" placeholder="1px" style="min-width:64px">' +
        '<input type="color" data-a="border-color" title="bordercolor">' +
        '<button type="button" class="af-ae-tbtn af-ae-tclose" data-a="del-table" title="Удалить таблицу">Del</button>' +
        '<button type="button" class="af-ae-tbtn af-ae-tclose" data-a="close" title="Закрыть">×</button>';

      (hostDoc.body || hostDoc.documentElement).appendChild(panel);
      panel.addEventListener('mousedown', function (ev) { ev.stopPropagation(); }, true);

      panel.addEventListener('click', function (ev) {
        var btn = ev.target && ev.target.closest ? ev.target.closest('button[data-a]') : null;
        if (!btn || !inst.__afAeActiveTable) return;
        ev.preventDefault();
        var t = inst.__afAeActiveTable;
        var act = btn.getAttribute('data-a');

        function activeCell() {
          var c = inst.__afAeActiveTableCell;
          if (c && c.nodeType === 1 && t.contains(c) && /^(TD|TH)$/.test(c.tagName)) return c;
          return t.querySelector('td,th');
        }

        if (act === 'close') { resetFloatingTableState(inst, hostDoc); return; }
        if (act === 'del-table') { if (t.parentNode) t.parentNode.removeChild(t); resetFloatingTableState(inst, hostDoc); syncEditorValue(); return; }

        var attrs = parseAttrsFromDom(t);

        if (act === 'align-left' || act === 'align-center' || act === 'align-right') {
          attrs.align = act.replace('align-', '');
          applyCanonicalAttrsToTableDom(t, attrs);
          syncEditorValue();
          return;
        }

        if (act === 'border-toggle') {
          attrs.border = attrs.border === '0' ? '1' : '0';
          applyCanonicalAttrsToTableDom(t, attrs);
          syncEditorValue();
          return;
        }

        if (act === 'apply-width') {
          var wInput = panel.querySelector('input[data-a="tbl-width"]');
          attrs.width = normWidthToken(wInput && wInput.value);
          applyCanonicalAttrsToTableDom(t, attrs);
          if (wInput) wInput.value = attrs.width;
          syncEditorValue();
          return;
        }

        if (act === 'apply-col-widths') {
          var cwInput = panel.querySelector('input[data-a="col-widths"]');
          var first = t.rows && t.rows.length ? t.rows[0] : null;
          var cols = first ? first.cells.length : 0;
          var widths = parseWidthList(cwInput && cwInput.value, cols);
          for (var rr = 0; rr < t.rows.length; rr++) {
            for (var cc = 0; cc < cols; cc++) {
              if (!t.rows[rr].cells[cc]) continue;
              t.rows[rr].cells[cc].style.width = widths[cc] || '';
              t.rows[rr].cells[cc].setAttribute('data-af-width', widths[cc] || '');
            }
          }
          syncEditorValue();
          return;
        }

        if (act === 'apply-col-width-current') {
          var cellW = activeCell();
          if (!cellW || !cellW.parentElement) return;
          var idx = Array.prototype.indexOf.call(cellW.parentElement.cells, cellW);
          var curInput = panel.querySelector('input[data-a="col-width-current"]');
          var w = normWidthToken(curInput && curInput.value);
          for (var r0 = 0; r0 < t.rows.length; r0++) {
            if (!t.rows[r0].cells[idx]) continue;
            t.rows[r0].cells[idx].style.width = w || '';
            t.rows[r0].cells[idx].setAttribute('data-af-width', w || '');
          }
          syncEditorValue();
          return;
        }

        var cell = activeCell();
        if (!cell) return;
        var row = cell.parentElement;
        var colIndex = Array.prototype.indexOf.call(row.cells, cell);

        if (act === 'row-above' || act === 'row-below') {
          var nr = row.cloneNode(true);
          for (var i = 0; i < nr.cells.length; i++) nr.cells[i].innerHTML = '<br>';
          if (act === 'row-above') row.parentNode.insertBefore(nr, row); else row.parentNode.insertBefore(nr, row.nextSibling);
        } else if (act === 'row-del') {
          if (t.rows.length > 1) row.parentNode.removeChild(row);
        } else if (act === 'col-left' || act === 'col-right') {
          for (var r1 = 0; r1 < t.rows.length; r1++) {
            var rr2 = t.rows[r1];
            var base = rr2.cells[Math.min(colIndex, rr2.cells.length - 1)] || rr2.cells[0];
            var nc = base.cloneNode(false);
            nc.innerHTML = '<br>';
            if (act === 'col-left') rr2.insertBefore(nc, rr2.cells[colIndex] || null); else rr2.insertBefore(nc, rr2.cells[colIndex + 1] || null);
          }
        } else if (act === 'col-del') {
          for (var r2 = 0; r2 < t.rows.length; r2++) if (t.rows[r2].cells.length > 1 && t.rows[r2].cells[colIndex]) t.rows[r2].removeChild(t.rows[r2].cells[colIndex]);
        }

        applyCanonicalAttrsToTableDom(t, attrs);
        syncEditorValue();
      }, false);

      var colorHandler = function (ev) {
        var input = ev.target && ev.target.closest ? ev.target.closest('input[type=color][data-a]') : null;
        if (!input || !inst.__afAeActiveTable) return;
        var t = inst.__afAeActiveTable;
        var a = parseAttrsFromDom(t);
        var act = input.getAttribute('data-a');
        var val = normColor(input.value);
        if (act === 'bg') a.bgcolor = val;
        if (act === 'fg') a.textcolor = val;
        if (act === 'hbg') a.hbgcolor = val;
        if (act === 'hfg') a.htextcolor = val;
        if (act === 'border-color') a.bordercolor = val;
        applyCanonicalAttrsToTableDom(t, a);
        syncEditorValue();
      };

      panel.addEventListener('input', colorHandler, false);
      panel.addEventListener('change', colorHandler, false);

      panel.addEventListener('change', function (ev) {
        var bw = ev.target && ev.target.closest ? ev.target.closest('input[data-a="border-width"]') : null;
        if (!bw || !inst.__afAeActiveTable) return;
        var a = parseAttrsFromDom(inst.__afAeActiveTable);
        a.borderwidth = normBorderWidth(bw.value);
        applyCanonicalAttrsToTableDom(inst.__afAeActiveTable, a);
        bw.value = a.borderwidth || '';
        syncEditorValue();
      }, false);
    }

    var rect = table.getBoundingClientRect();
    var iframeRect = iframeEl && iframeEl.getBoundingClientRect ? iframeEl.getBoundingClientRect() : null;
    var top = iframeRect ? (iframeRect.top + rect.bottom + 8) : (rect.bottom + 8);
    var left = iframeRect ? (iframeRect.left + rect.left) : rect.left;

    panel.style.display = 'flex';
    panel.style.top = Math.max(8, top) + 'px';
    panel.style.left = Math.max(8, left) + 'px';

    inst.__afAeActiveTable = table;
    var cur = parseAttrsFromDom(table);
    var bg = panel.querySelector('input[data-a="bg"]'); if (bg && cur.bgcolor) bg.value = cur.bgcolor;
    var fg = panel.querySelector('input[data-a="fg"]'); if (fg && cur.textcolor) fg.value = cur.textcolor;
    var hbg = panel.querySelector('input[data-a="hbg"]'); if (hbg && cur.hbgcolor) hbg.value = cur.hbgcolor;
    var hfg = panel.querySelector('input[data-a="hfg"]'); if (hfg && cur.htextcolor) hfg.value = cur.htextcolor;
    var bcol = panel.querySelector('input[data-a="border-color"]'); if (bcol && cur.bordercolor) bcol.value = cur.bordercolor;
    var bwid = panel.querySelector('input[data-a="border-width"]'); if (bwid) bwid.value = cur.borderwidth || '';
    var tw = panel.querySelector('input[data-a="tbl-width"]'); if (tw) tw.value = cur.width || '';
  }

  function bindFloatingEditor(inst) {
    if (!inst || inst.__afAeTableFloatingBound) return;
    var body = getInstBodySafe(inst);
    if (!body) return;

    inst.__afAeTableFloatingBound = true;
    var doc = body.ownerDocument;
    var hostDoc = getFloatingPanelHostDoc(inst, doc);
    var iframeEl = getEditorIframeElement(inst, doc);

    body.addEventListener('mousedown', function (ev) {
      var cell = ev.target && ev.target.closest ? ev.target.closest('td,th') : null;
      var table = getManagedTableFromNode(cell);
      if (cell && table) inst.__afAeActiveTableCell = cell;
    }, true);

    body.addEventListener('click', function (ev) {
      if (isSourceMode(inst)) { resetFloatingTableState(inst, hostDoc); return; }
      var table = getManagedTableFromNode(ev.target);
      if (table && body.contains(table)) openFloatingEditorForTable(inst, table);
      else resetFloatingTableState(inst, hostDoc);
    }, false);

    hostDoc.addEventListener('mousedown', function (ev) {
      var panel = hostDoc.getElementById('af-ae-table-floating');
      if (!panel || panel.style.display === 'none') return;
      var inPanel = panel.contains(ev.target);
      var hitIframe = !!(iframeEl && (ev.target === iframeEl || (iframeEl.contains && iframeEl.contains(ev.target))));
      if (inPanel || !hitIframe) return;
      try {
        var iframeRect = iframeEl.getBoundingClientRect();
        var x = ev.clientX - iframeRect.left;
        var y = ev.clientY - iframeRect.top;
        var elAtPoint = doc.elementFromPoint(x, y);
        if (!getManagedTableFromNode(elAtPoint)) resetFloatingTableState(inst, hostDoc);
      } catch (e1) { resetFloatingTableState(inst, hostDoc); }
    }, false);
  }

  function insertTableToEditor(editor, bb, html) {
    bb = asText(bb); html = asText(html);
    try {
      if (editor && typeof editor.insertText === 'function') {
        ensureInstancePatched(editor);
        if (isSourceMode(editor)) editor.insertText(bb, '');
        else if (typeof editor.wysiwygEditorInsertHtml === 'function') editor.wysiwygEditorInsertHtml(html || bb);
        else if (typeof editor.insertHTML === 'function') editor.insertHTML(html || bb);
        else editor.insertText(bb, '');

        try { if (typeof editor.updateOriginal === 'function') editor.updateOriginal(); } catch (e0) {}
        try { if (typeof editor.focus === 'function') editor.focus(); } catch (e1) {}
        return true;
      }
    } catch (e2) {}

    var ta = getTextareaFromCtx({ sceditor: editor });
    if (!ta) return false;
    var start = typeof ta.selectionStart === 'number' ? ta.selectionStart : 0;
    var end = typeof ta.selectionEnd === 'number' ? ta.selectionEnd : start;
    var val = asText(ta.value);
    ta.value = val.slice(0, start) + bb + val.slice(end);
    return true;
  }

  function makeDropdown(editor, caller) {
    var wrap = document.createElement('div');
    wrap.className = 'af-table-dd';
    wrap.innerHTML =
      '<div class="af-table-dd-hd"><div class="af-table-dd-title">Таблица</div></div>' +
      '<div class="af-table-dd-body"><div class="af-table-dd-size"><span class="af-table-dd-sizeval">2 × 2</span></div><div class="af-table-dd-opts">' +
      '<div class="af-table-dd-row is-rc"><label class="af-table-dd-field"><span>Колонок</span><input type="number" min="1" max="50" step="1" class="af-t-cols" value="2"></label><label class="af-table-dd-field"><span>Строк</span><input type="number" min="1" max="50" step="1" class="af-t-rows" value="2"></label></div>' +
      '<label class="af-table-dd-row"><span>Ширина</span><input type="text" class="af-t-width" placeholder="например 100% или 500px"></label>' +
      '<label class="af-table-dd-row"><span>Ширины колонок</span><input type="text" class="af-t-colwidths" placeholder="например 120px,200px,300px"></label>' +
      '<label class="af-table-dd-row"><span>Выравнивание</span><select class="af-t-align"><option value="">—</option><option value="left">left</option><option value="center">center</option><option value="right">right</option></select></label>' +
      '<label class="af-table-dd-row"><span>Заголовки</span><select class="af-t-headers"><option value="none">none</option><option value="row">row</option><option value="col">col</option><option value="both">both</option></select></label>' +
      '<label class="af-table-dd-row"><span>Заполнить</span><select class="af-t-fill"><option value="0">нет</option><option value="1">да</option></select></label>' +
      '<div class="af-table-dd-row is-rc"><label class="af-table-dd-field"><span><input type="checkbox" class="af-t-cellbg-on"> Заливка</span><input type="color" class="af-t-cellbg" value="#000000" disabled></label><label class="af-table-dd-field"><span><input type="checkbox" class="af-t-textcolor-on"> Цвет текста</span><input type="color" class="af-t-textcolor" value="#000000" disabled></label></div>' +
      '<div class="af-table-dd-row is-rc"><label class="af-table-dd-field"><span><input type="checkbox" class="af-t-headbg-on"> Заливка заголовков</span><input type="color" class="af-t-headbg" value="#000000" disabled></label><label class="af-table-dd-field"><span><input type="checkbox" class="af-t-headtext-on"> Текст заголовков</span><input type="color" class="af-t-headtext" value="#000000" disabled></label></div>' +
      '<div class="af-table-dd-row is-rc"><label class="af-table-dd-field"><span>Цвет бордера</span><input type="color" class="af-t-bordercolor" value="#ffffff"></label><label class="af-table-dd-field"><span>Бордеры</span><select class="af-t-borderon"><option value="0">нет</option><option value="1" selected>да</option></select></label></div>' +
      '<div class="af-table-dd-row is-rc"><label class="af-table-dd-field"><span>Толщина</span><input type="number" min="0" max="20" step="1" class="af-t-borderwidth" value="1"></label><div class="af-table-dd-field" aria-hidden="true"></div></div>' +
      '<div class="af-table-dd-actions"><button type="button" class="button af-t-insert">Вставить</button></div></div></div>';

    var sizeEl = wrap.querySelector('.af-table-dd-sizeval');
    var inpCols = wrap.querySelector('.af-t-cols');
    var inpRows = wrap.querySelector('.af-t-rows');
    var inpWidth = wrap.querySelector('.af-t-width');
    var inpColWidths = wrap.querySelector('.af-t-colwidths');
    var selAlign = wrap.querySelector('.af-t-align');
    var selHeaders = wrap.querySelector('.af-t-headers');
    var selFill = wrap.querySelector('.af-t-fill');
    var chkCellBg = wrap.querySelector('.af-t-cellbg-on');
    var inpCellBg = wrap.querySelector('.af-t-cellbg');
    var chkText = wrap.querySelector('.af-t-textcolor-on');
    var inpText = wrap.querySelector('.af-t-textcolor');
    var chkHeadBg = wrap.querySelector('.af-t-headbg-on');
    var inpHeadBg = wrap.querySelector('.af-t-headbg');
    var chkHeadText = wrap.querySelector('.af-t-headtext-on');
    var inpHeadText = wrap.querySelector('.af-t-headtext');
    var inpBorderColor = wrap.querySelector('.af-t-bordercolor');
    var selBorderOn = wrap.querySelector('.af-t-borderon');
    var inpBorderWidth = wrap.querySelector('.af-t-borderwidth');
    var btnInsert = wrap.querySelector('.af-t-insert');

    function clampInt(v, min, max, fallback) {
      var n = parseInt(v, 10); if (!isFinite(n)) n = fallback;
      if (n < min) n = min; if (n > max) n = max;
      return n;
    }
    function repaintSize() {
      var c = clampInt(inpCols && inpCols.value, 1, 50, 2);
      var r = clampInt(inpRows && inpRows.value, 1, 50, 2);
      inpCols.value = String(c); inpRows.value = String(r);
      sizeEl.textContent = r + ' × ' + c;
    }
    function updateBorderUi() {
      var on = selBorderOn && selBorderOn.value === '1';
      inpBorderColor.disabled = !on;
      inpBorderWidth.disabled = !on;
    }
    function syncColorEnable(chk, inp) { inp.disabled = !chk.checked; }
    function closeDd() { try { editor.closeDropDown(true); } catch (e0) {} }

    function insertNow() {
      var cols = clampInt(inpCols && inpCols.value, 1, 50, 2);
      var rows = clampInt(inpRows && inpRows.value, 1, 50, 2);
      var opts = {
        width: asText(inpWidth && inpWidth.value).trim(),
        colWidths: asText(inpColWidths && inpColWidths.value).trim(),
        align: asText(selAlign && selAlign.value).trim(),
        headers: asText(selHeaders && selHeaders.value).trim(),
        fill: asText(selFill && selFill.value) === '1',
        cellBg: chkCellBg.checked ? asText(inpCellBg.value).trim() : '',
        textColor: chkText.checked ? asText(inpText.value).trim() : '',
        headBg: chkHeadBg.checked ? asText(inpHeadBg.value).trim() : '',
        headText: chkHeadText.checked ? asText(inpHeadText.value).trim() : '',
        borderOn: selBorderOn.value === '1',
        borderColor: asText(inpBorderColor.value).trim(),
        borderWidth: String(clampInt(inpBorderWidth && inpBorderWidth.value, 0, 20, 1))
      };

      var bb = buildBbcode(rows, cols, opts);
      var html = buildHtmlFromBbcode(rows, cols, opts);
      insertTableToEditor(editor, bb, html);
      closeDd();
    }

    repaintSize(); updateBorderUi(); syncColorEnable(chkCellBg, inpCellBg); syncColorEnable(chkText, inpText); syncColorEnable(chkHeadBg, inpHeadBg); syncColorEnable(chkHeadText, inpHeadText);
    [inpCols, inpRows].forEach(function (el) { el.addEventListener('input', repaintSize, false); el.addEventListener('change', repaintSize, false); });
    selBorderOn.addEventListener('change', updateBorderUi, false);
    [[chkCellBg, inpCellBg], [chkText, inpText], [chkHeadBg, inpHeadBg], [chkHeadText, inpHeadText]].forEach(function (pair) {
      pair[0].addEventListener('change', function () { syncColorEnable(pair[0], pair[1]); }, false);
    });
    [inpCols, inpRows, inpWidth, inpColWidths, inpBorderWidth].forEach(function (el) {
      el.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); insertNow(); } }, false);
    });
    btnInsert.addEventListener('click', function (ev) { ev.preventDefault(); insertNow(); }, false);

    return wrap;
  }

  function openSceditorDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;
    try { editor.closeDropDown(true); } catch (e0) {}
    ensureInstancePatched(editor);
    editor.createDropDown(caller, 'sceditor-table-picker', makeDropdown(editor, caller));
    return true;
  }

  function patchSceditorTableCommand() {
    if (window.__afAeTableCommandPatched || !hasSceditor()) return !!window.__afAeTableCommandPatched;
    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.command) return false;

    function buildCommand() {
      return {
        exec: function (caller) {
          if (!openSceditorDropdown(this, caller)) {
            var bb = buildBbcode(2, 2, { headers: 'none' });
            var html = buildHtmlFromBbcode(2, 2, { headers: 'none' });
            insertTableToEditor(this, bb, html);
          }
        },
        txtExec: function (caller) {
          if (!openSceditorDropdown(this, caller)) insertTableToEditor(this, buildBbcode(2, 2, { headers: 'none' }), '');
        },
        tooltip: 'Таблица'
      };
    }

    $.sceditor.command.set('af_table', buildCommand());
    $.sceditor.command.set('table', buildCommand());
    window.__afAeTableCommandPatched = true;
    return true;
  }

  function aqrOpen(ctx, ev) {
    var editor = getSceditorInstanceFromCtx(ctx);
    var caller = (ctx && (ctx.buttonEl || ctx.btn || ctx.caller)) || (ev && (ev.currentTarget || ev.target)) || null;
    if (editor && caller && caller.nodeType === 1) {
      if (ev && ev.preventDefault) ev.preventDefault();
      openSceditorDropdown(editor, caller);
      return;
    }
    var bb = buildBbcode(2, 2, { headers: 'none' });
    var html = buildHtmlFromBbcode(2, 2, { headers: 'none' });
    insertTableToEditor(editor, bb, html);
  }

  var handlerObj = { id: ID, title: 'Таблица', onClick: aqrOpen, click: aqrOpen, action: aqrOpen, run: aqrOpen, init: function () {} };
  function handlerFn(inst, caller) {
    var editor = getSceditorInstanceFromCtx(inst || {}) || getSceditorInstanceFromCtx({});
    if (!editor) return;
    if (caller && caller.nodeType === 1) openSceditorDropdown(editor, caller);
    else insertTableToEditor(editor, buildBbcode(2, 2, { headers: 'none' }), buildHtmlFromBbcode(2, 2, { headers: 'none' }));
  }

  window.afAqrBuiltinHandlers[ID] = handlerObj;
  window.afAqrBuiltinHandlers[CMD] = handlerObj;
  window.afAeBuiltinHandlers[ID] = handlerFn;
  window.afAeBuiltinHandlers[CMD] = handlerFn;

  window.afAeTableDebugApi = {
    normalizeTableAttrs: normalizeTableAttrs,
    parseAttrsFromDom: parseAttrsFromDom,
    tableAttrsToBbOpen: tableAttrsToBbOpen,
    modelToCanonicalBbcode: modelToCanonicalBbcode,
    modelToWysiwygHtml: modelToWysiwygHtml,
    createTableModel: createTableModel,
    serializeCellContentForTable: serializeCellContentForTable,
    serializeTableDomToCanonicalBb: serializeTableDomToCanonicalBb
  };

  window.af_ae_table_exec = function (editor, def, caller) {
    if (!openSceditorDropdown(editor, caller)) insertTableToEditor(editor, buildBbcode(2, 2, { headers: 'none' }), buildHtmlFromBbcode(2, 2, { headers: 'none' }));
  };

  try { patchSceditorTableCommand(); } catch (e0) {}
})();

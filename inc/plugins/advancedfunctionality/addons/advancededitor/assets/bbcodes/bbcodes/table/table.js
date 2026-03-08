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

  function hasSceditor() {
    return !!(window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.sceditor === 'function');
  }

  function isSourceMode(inst) {
    try { return !!(inst && typeof inst.sourceMode === 'function' && inst.sourceMode()); } catch (e) { return false; }
  }

  function normColor(v) {
    v = asText(v).trim();
    if (!v) return '';
    if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(v)) return v.toLowerCase();
    return '';
  }

  function normWidthToken(v) {
    v = asText(v).trim();
    var m = v.match(/^([0-9]{1,4})(px|%|em|rem|vw|vh)?$/i);
    return m ? (m[1] + (m[2] ? m[2].toLowerCase() : 'px')) : '';
  }

  function normBorderWidth(v) {
    v = asText(v).trim();
    if (!v) return '';
    if (/^[0-9]{1,2}px$/i.test(v)) return v.toLowerCase();
    var n = parseInt(v, 10);
    if (!isFinite(n)) return '';
    if (n < 0) n = 0;
    if (n > 20) n = 20;
    return n + 'px';
  }

  function normalizeAttrs(attrs) {
    attrs = attrs || {};
    var out = {
      width: normWidthToken(attrs.width),
      align: '',
      headers: '',
      bgcolor: normColor(attrs.bgcolor),
      textcolor: normColor(attrs.textcolor),
      hbgcolor: normColor(attrs.hbgcolor),
      htextcolor: normColor(attrs.htextcolor),
      border: asText(attrs.border) === '0' ? '0' : '1',
      bordercolor: normColor(attrs.bordercolor),
      borderwidth: normBorderWidth(attrs.borderwidth)
    };

    var align = asText(attrs.align).trim().toLowerCase();
    if (align === 'left' || align === 'center' || align === 'right') out.align = align;
    var headers = asText(attrs.headers).trim().toLowerCase();
    if (headers === 'row' || headers === 'col' || headers === 'both') out.headers = headers;

    if (out.border === '1') {
      if (!out.bordercolor) out.bordercolor = '#888888';
      if (!out.borderwidth) out.borderwidth = '1px';
    }

    return out;
  }

  function readAttrsFromData(tableEl) {
    var attrs = { border: '1' };
    if (!tableEl || tableEl.nodeType !== 1) return normalizeAttrs(attrs);
    for (var i = 0; i < TABLE_ATTR_KEYS.length; i++) {
      var k = TABLE_ATTR_KEYS[i];
      attrs[k] = asText(tableEl.getAttribute('data-af-' + k)).trim();
    }
    return normalizeAttrs(attrs);
  }

  function tableAttrsToBbOpen(attrs) {
    attrs = normalizeAttrs(attrs);
    var parts = [];
    if (attrs.width) parts.push('width=' + attrs.width);
    if (attrs.align) parts.push('align=' + attrs.align);
    if (attrs.headers) parts.push('headers=' + attrs.headers);
    if (attrs.bgcolor) parts.push('bgcolor=' + attrs.bgcolor);
    if (attrs.textcolor) parts.push('textcolor=' + attrs.textcolor);
    if (attrs.hbgcolor) parts.push('hbgcolor=' + attrs.hbgcolor);
    if (attrs.htextcolor) parts.push('htextcolor=' + attrs.htextcolor);
    parts.push('border=' + attrs.border);
    if (attrs.border === '1') {
      if (attrs.bordercolor) parts.push('bordercolor=' + attrs.bordercolor);
      if (attrs.borderwidth) parts.push('borderwidth=' + attrs.borderwidth);
    }
    return '[table' + (parts.length ? ' ' + parts.join(' ') : '') + ']';
  }

  function buildTableStyle(attrs) {
    attrs = normalizeAttrs(attrs);
    var st = ['border-collapse:collapse', 'border-spacing:0'];
    if (attrs.width) st.push('width:' + attrs.width);
    if (attrs.align === 'center') st.push('margin-left:auto', 'margin-right:auto');
    if (attrs.align === 'right') st.push('margin-left:auto');
    if (attrs.align === 'left') st.push('margin-right:auto');
    if (attrs.bgcolor) st.push('--af-tbl-bg:' + attrs.bgcolor);
    if (attrs.textcolor) st.push('--af-tbl-txt:' + attrs.textcolor);
    if (attrs.hbgcolor) st.push('--af-tbl-hbg:' + attrs.hbgcolor);
    if (attrs.htextcolor) st.push('--af-tbl-htxt:' + attrs.htextcolor);
    if (attrs.border === '1') {
      st.push('--af-tbl-bw:' + attrs.borderwidth, '--af-tbl-bc:' + attrs.bordercolor);
      st.push('border:' + attrs.borderwidth + ' solid ' + attrs.bordercolor);
    } else {
      st.push('--af-tbl-bw:0px', 'border:0');
    }
    return st.join(';');
  }

  function buildCellStyle(tag, attrs, width) {
    attrs = normalizeAttrs(attrs);
    var isH = tag === 'th';
    var st = ['padding:6px 8px', 'vertical-align:top'];
    if (width) st.push('width:' + normWidthToken(width));
    st.push('background-color:' + (isH ? (attrs.hbgcolor || attrs.bgcolor || 'transparent') : (attrs.bgcolor || 'transparent')));
    st.push('color:' + (isH ? (attrs.htextcolor || attrs.textcolor || 'inherit') : (attrs.textcolor || 'inherit')));
    st.push(attrs.border === '1' ? ('border:' + attrs.borderwidth + ' solid ' + attrs.bordercolor) : 'border:0');
    if (isH) st.push('font-weight:700', 'text-align:left');
    return st.join(';');
  }

  function attrsToDataAttrs(attrs) {
    attrs = normalizeAttrs(attrs);
    var out = ['data-af-table="1"'];
    for (var i = 0; i < TABLE_ATTR_KEYS.length; i++) {
      var k = TABLE_ATTR_KEYS[i];
      var v = asText(attrs[k]).trim();
      if (!v && k !== 'border') continue;
      out.push('data-af-' + k + '="' + v.replace(/"/g, '&quot;') + '"');
    }
    return out.join(' ');
  }

  function createTableModel(rows, cols, attrs) {
    rows = Math.max(1, Math.min(50, rows | 0));
    cols = Math.max(1, Math.min(50, cols | 0));
    attrs = normalizeAttrs(attrs || {});
    var model = { attrs: attrs, rows: [] };

    function isHeader(r, c) {
      if (attrs.headers === 'row') return r === 0;
      if (attrs.headers === 'col') return c === 0;
      if (attrs.headers === 'both') return r === 0 || c === 0;
      return false;
    }

    for (var r = 0; r < rows; r++) {
      var row = { cells: [] };
      for (var c = 0; c < cols; c++) {
        row.cells.push({ tag: isHeader(r, c) ? 'th' : 'td', width: '', content: '' });
      }
      model.rows.push(row);
    }
    return model;
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

  function modelToWysiwygHtml(model) {
    model = model || { attrs: {}, rows: [] };
    var attrs = normalizeAttrs(model.attrs || {});
    var rows = Array.isArray(model.rows) ? model.rows : [];
    var html = ['<table class="af-ae-table" ' + attrsToDataAttrs(attrs) + ' style="' + buildTableStyle(attrs) + '">'];

    for (var r = 0; r < rows.length; r++) {
      var cells = Array.isArray(rows[r].cells) ? rows[r].cells : [];
      html.push('<tr>');
      for (var c = 0; c < cells.length; c++) {
        var cell = cells[c] || {};
        var tag = asText(cell.tag).toLowerCase() === 'th' ? 'th' : 'td';
        var width = normWidthToken(cell.width);
        html.push('<' + tag + ' style="' + buildCellStyle(tag, attrs, width) + '">' + (asText(cell.content).trim() || '<br>') + '</' + tag + '>');
      }
      html.push('</tr>');
    }

    html.push('</table><p><br></p>');
    return html.join('');
  }

  function genericHtmlToBb(inst, html) {
    try {
      if (inst && typeof inst.toBBCode === 'function') return asText(inst.toBBCode(html));
    } catch (e) {}
    return asText(html).replace(/<[^>]+>/g, '');
  }

  function serializeTableDomToCanonicalBb(tableEl, inst) {
    if (!tableEl || tableEl.nodeType !== 1) return '';
    var attrs = readAttrsFromData(tableEl);
    var model = { attrs: attrs, rows: [] };

    for (var r = 0; r < tableEl.rows.length; r++) {
      var rowEl = tableEl.rows[r];
      var row = { cells: [] };
      for (var c = 0; c < rowEl.cells.length; c++) {
        var cellEl = rowEl.cells[c];
        var tag = asText(cellEl.tagName).toLowerCase() === 'th' ? 'th' : 'td';
        var width = normWidthToken((cellEl.style && cellEl.style.width) || cellEl.getAttribute('data-af-width') || '');
        var bb = genericHtmlToBb(inst, cellEl.innerHTML).trim();
        if (bb === '[br]' || bb === '[br/]') bb = '';
        row.cells.push({ tag: tag, width: width, content: bb });
      }
      model.rows.push(row);
    }

    return modelToCanonicalBbcode(model);
  }

  function applyAttrsToTable(tableEl, attrs) {
    attrs = normalizeAttrs(attrs);
    for (var i = 0; i < TABLE_ATTR_KEYS.length; i++) {
      var k = TABLE_ATTR_KEYS[i];
      tableEl.setAttribute('data-af-' + k, asText(attrs[k] || (k === 'border' ? '1' : '')));
    }
    tableEl.setAttribute('data-af-table', '1');
    tableEl.classList.add('af-ae-table');
    tableEl.setAttribute('style', buildTableStyle(attrs));

    for (var r = 0; r < tableEl.rows.length; r++) {
      for (var c = 0; c < tableEl.rows[r].cells.length; c++) {
        var cell = tableEl.rows[r].cells[c];
        var wantTh = (attrs.headers === 'row' && r === 0) || (attrs.headers === 'col' && c === 0) || (attrs.headers === 'both' && (r === 0 || c === 0));
        var tag = wantTh ? 'th' : 'td';
        if (asText(cell.tagName).toLowerCase() !== tag) {
          var n = tableEl.ownerDocument.createElement(tag);
          n.innerHTML = cell.innerHTML;
          if (cell.style && cell.style.width) n.style.width = cell.style.width;
          tableEl.rows[r].replaceChild(n, cell);
          cell = n;
        }
        var width = normWidthToken((cell.style && cell.style.width) || '');
        cell.setAttribute('style', buildCellStyle(tag, attrs, width));
      }
    }
  }

  function ensureTableCss(inst) {
    try {
      var doc = inst.getBody().ownerDocument;
      if (doc.getElementById('af-ae-table-css')) return;
      var st = doc.createElement('style');
      st.id = 'af-ae-table-css';
      st.textContent = 'table.af-ae-table{border-collapse:collapse;border-spacing:0;max-width:100%;margin:8px 0;}table.af-ae-table td,table.af-ae-table th{padding:6px 8px;vertical-align:top;}';
      (doc.head || doc.documentElement).appendChild(st);
    } catch (e) {}
  }

  function findManagedTable(node) {
    try { return node && node.closest && node.closest('table.af-ae-table,table[data-af-table="1"]'); } catch (e) { return null; }
  }

  function rebuildTableCellStyles(tableEl) {
    applyAttrsToTable(tableEl, readAttrsFromData(tableEl));
  }

  function makeFloatingPanel(inst, hostDoc) {
    var panel = hostDoc.getElementById('af-ae-table-floating');
    if (panel) return panel;

    panel = hostDoc.createElement('div');
    panel.id = 'af-ae-table-floating';
    panel.style.cssText = 'position:fixed;z-index:99999;display:none;background:#222;color:#fff;padding:8px;border-radius:6px;border:1px solid #444;font:12px/1.3 sans-serif;max-width:320px';

    panel.innerHTML = '' +
      '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px">' +
      '<label>Ширина <input data-k="width" placeholder="600px" style="width:100%"></label>' +
      '<label>Align <select data-k="align"><option value="">-</option><option>left</option><option>center</option><option>right</option></select></label>' +
      '<label>Headers <select data-k="headers"><option value="">none</option><option value="row">row</option><option value="col">col</option><option value="both">both</option></select></label>' +
      '<label>Border <select data-k="border"><option value="1">on</option><option value="0">off</option></select></label>' +
      '<label>Border color <input data-k="bordercolor" type="color"></label>' +
      '<label>Border width <input data-k="borderwidth" placeholder="1px" style="width:100%"></label>' +
      '<label>Cell bg <input data-k="bgcolor" type="color"></label>' +
      '<label>Cell text <input data-k="textcolor" type="color"></label>' +
      '<label>Head bg <input data-k="hbgcolor" type="color"></label>' +
      '<label>Head text <input data-k="htextcolor" type="color"></label>' +
      '</div>' +
      '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">' +
      '<button data-act="row-add">+row</button><button data-act="row-del">-row</button><button data-act="col-add">+col</button><button data-act="col-del">-col</button><button data-act="close">×</button>' +
      '</div>';

    hostDoc.body.appendChild(panel);

    panel.addEventListener('click', function (ev) {
      var act = ev.target && ev.target.getAttribute && ev.target.getAttribute('data-act');
      var table = inst.__afAeActiveTable;
      if (!table) return;
      if (act === 'close') { panel.style.display = 'none'; return; }
      if (act === 'row-add') {
        var cols = table.rows[0] ? table.rows[0].cells.length : 1;
        var tr = table.ownerDocument.createElement('tr');
        for (var i = 0; i < cols; i++) {
          var td = table.ownerDocument.createElement('td'); td.innerHTML = '<br>'; tr.appendChild(td);
        }
        table.appendChild(tr);
      }
      if (act === 'row-del' && table.rows.length > 1) table.deleteRow(table.rows.length - 1);
      if (act === 'col-add') {
        for (var r = 0; r < table.rows.length; r++) {
          var cell = table.ownerDocument.createElement('td'); cell.innerHTML = '<br>'; table.rows[r].appendChild(cell);
        }
      }
      if (act === 'col-del') {
        for (var rr = 0; rr < table.rows.length; rr++) if (table.rows[rr].cells.length > 1) table.rows[rr].deleteCell(table.rows[rr].cells.length - 1);
      }
      rebuildTableCellStyles(table);
    }, false);

    panel.addEventListener('input', function (ev) {
      var k = ev.target && ev.target.getAttribute && ev.target.getAttribute('data-k');
      var table = inst.__afAeActiveTable;
      if (!k || !table) return;
      var attrs = readAttrsFromData(table);
      attrs[k] = ev.target.value;
      applyAttrsToTable(table, attrs);
    }, false);

    return panel;
  }

  function bindFloatingEditor(inst) {
    if (!inst || inst.__afAeTableFloatingBound || !inst.getBody) return;
    inst.__afAeTableFloatingBound = true;
    ensureTableCss(inst);

    var body = inst.getBody();
    var hostDoc = body.ownerDocument;
    var panel = makeFloatingPanel(inst, hostDoc);

    body.addEventListener('click', function (ev) {
      if (isSourceMode(inst)) return;
      var table = findManagedTable(ev.target);
      if (!table) { panel.style.display = 'none'; inst.__afAeActiveTable = null; return; }
      inst.__afAeActiveTable = table;
      var attrs = readAttrsFromData(table);
      var controls = panel.querySelectorAll('[data-k]');
      for (var i = 0; i < controls.length; i++) {
        var k = controls[i].getAttribute('data-k');
        controls[i].value = attrs[k] || '';
      }
      panel.style.display = 'block';
      panel.style.left = Math.min(ev.clientX + 10, window.innerWidth - 340) + 'px';
      panel.style.top = Math.max(10, ev.clientY + 10) + 'px';
    }, true);
  }

  function parseIntSafe(v, d) {
    var n = parseInt(v, 10);
    return isFinite(n) && n > 0 ? n : d;
  }

  function makeDropdown(editor) {
    var wrap = document.createElement('div');
    wrap.className = 'af-ae-table-dd';
    wrap.style.cssText = 'padding:8px;min-width:300px';
    wrap.innerHTML = '' +
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">' +
      '<label>Cols <input data-k="cols" type="number" value="2" min="1" max="50"></label>' +
      '<label>Rows <input data-k="rows" type="number" value="2" min="1" max="50"></label>' +
      '<label>Width <input data-k="width" placeholder="600px"></label>' +
      '<label>Align <select data-k="align"><option value="">-</option><option>left</option><option>center</option><option>right</option></select></label>' +
      '<label>Headers <select data-k="headers"><option value="">none</option><option value="row">row</option><option value="col">col</option><option value="both">both</option></select></label>' +
      '<label>Border <select data-k="border"><option value="1">on</option><option value="0">off</option></select></label>' +
      '<label>Border color <input data-k="bordercolor" type="color" value="#888888"></label>' +
      '<label>Border width <input data-k="borderwidth" value="1px"></label>' +
      '<label>Cell bg <input data-k="bgcolor" type="color"></label>' +
      '<label>Cell text <input data-k="textcolor" type="color"></label>' +
      '<label>Head bg <input data-k="hbgcolor" type="color"></label>' +
      '<label>Head text <input data-k="htextcolor" type="color"></label>' +
      '</div><div style="margin-top:8px"><button data-act="insert">Вставить</button></div>';

    wrap.querySelector('[data-act="insert"]').addEventListener('click', function (ev) {
      ev.preventDefault();
      var rows = parseIntSafe(wrap.querySelector('[data-k="rows"]').value, 2);
      var cols = parseIntSafe(wrap.querySelector('[data-k="cols"]').value, 2);
      var attrs = {
        width: wrap.querySelector('[data-k="width"]').value,
        align: wrap.querySelector('[data-k="align"]').value,
        headers: wrap.querySelector('[data-k="headers"]').value,
        border: wrap.querySelector('[data-k="border"]').value,
        bordercolor: wrap.querySelector('[data-k="bordercolor"]').value,
        borderwidth: wrap.querySelector('[data-k="borderwidth"]').value,
        bgcolor: wrap.querySelector('[data-k="bgcolor"]').value,
        textcolor: wrap.querySelector('[data-k="textcolor"]').value,
        hbgcolor: wrap.querySelector('[data-k="hbgcolor"]').value,
        htextcolor: wrap.querySelector('[data-k="htextcolor"]').value
      };
      var model = createTableModel(rows, cols, attrs);
      var bb = modelToCanonicalBbcode(model);
      var html = modelToWysiwygHtml(model);

      if (isSourceMode(editor)) editor.insertText(bb, '');
      else if (typeof editor.wysiwygEditorInsertHtml === 'function') editor.wysiwygEditorInsertHtml(html);
      else editor.insertText(bb, '');

      bindFloatingEditor(editor);
      try { editor.closeDropDown(true); } catch (e) {}
    }, false);

    return wrap;
  }

  function patchBbcode(inst) {
    if (!hasSceditor() || !window.jQuery || !window.jQuery.sceditor) return;
    var bb = window.jQuery.sceditor.plugins.bbcode.bbcode;
    if (!bb || bb.__afAeTablePatched) return;

    bb.set('table', {
      isBlock: true,
      html: function (_t, attrs, content) {
        var a = normalizeAttrs(attrs || {});
        return '<table class="af-ae-table" ' + attrsToDataAttrs(a) + ' style="' + buildTableStyle(a) + '">' + (content || '') + '</table>';
      },
      format: function (el) { return serializeTableDomToCanonicalBb(el, inst); }
    });

    bb.set('tr', { isBlock: true, html: '<tr>{0}</tr>', format: '[tr]{0}[/tr]' });
    bb.set('td', { html: function (_t, attrs, c) { var w = normWidthToken(attrs && attrs.width); return '<td' + (w ? ' style="width:' + w + '"' : '') + '>' + (c || '') + '</td>'; }, format: '[td]{0}[/td]' });
    bb.set('th', { html: function (_t, attrs, c) { var w = normWidthToken(attrs && attrs.width); return '<th' + (w ? ' style="width:' + w + ';font-weight:700;text-align:left"' : ' style="font-weight:700;text-align:left"') + '>' + (c || '') + '</th>'; }, format: '[th]{0}[/th]' });

    bb.__afAeTablePatched = true;
  }

  function openDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;
    patchBbcode(editor);
    bindFloatingEditor(editor);
    try { editor.closeDropDown(true); } catch (e) {}
    editor.createDropDown(caller, 'sceditor-table-picker', makeDropdown(editor));
    return true;
  }

  function patchCommand() {
    if (window.__afAeTableCmdPatched || !hasSceditor()) return;
    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.command) return;
    var cmd = {
      exec: function (caller) { if (!openDropdown(this, caller)) this.insertText(modelToCanonicalBbcode(createTableModel(2, 2, {})), ''); },
      txtExec: function (caller) { if (!openDropdown(this, caller)) this.insertText(modelToCanonicalBbcode(createTableModel(2, 2, {})), ''); },
      tooltip: 'Таблица'
    };
    $.sceditor.command.set('af_table', cmd);
    $.sceditor.command.set('table', cmd);
    window.__afAeTableCmdPatched = true;
  }

  function handlerFn(ctx, caller) {
    var editor = (ctx && typeof ctx.insertText === 'function') ? ctx : null;
    if (!editor && window.jQuery) {
      var $ta = window.jQuery('textarea#message, textarea[name="message"]').first();
      if ($ta.length) editor = $ta.sceditor('instance');
    }
    if (editor) openDropdown(editor, caller);
  }

  var handlerObj = { id: ID, title: 'Таблица', onClick: handlerFn, click: handlerFn, action: handlerFn, run: handlerFn, init: function () {} };
  window.afAqrBuiltinHandlers[ID] = handlerObj;
  window.afAqrBuiltinHandlers[CMD] = handlerObj;
  window.afAeBuiltinHandlers[ID] = handlerFn;
  window.afAeBuiltinHandlers[CMD] = handlerFn;

  window.afAeTableDebugApi = {
    modelToWysiwygHtml: modelToWysiwygHtml,
    modelToCanonicalBbcode: modelToCanonicalBbcode,
    serializeTableDomToCanonicalBb: serializeTableDomToCanonicalBb,
    createTableModel: createTableModel,
    readAttrsFromData: readAttrsFromData
  };

  patchCommand();
})();

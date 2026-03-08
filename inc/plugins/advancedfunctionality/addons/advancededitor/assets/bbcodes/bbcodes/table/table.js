(function () {
  'use strict';

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);
  if (window.__afAeTablePackLoaded) return;
  window.__afAeTablePackLoaded = true;

  var ID = 'table';
  var CMD = 'af_table';
  var ATTR_KEYS = ['width', 'align', 'headers', 'bgcolor', 'textcolor', 'hbgcolor', 'htextcolor', 'border', 'bordercolor', 'borderwidth'];

  function asText(v) { return String(v == null ? '' : v); }
  function escHtml(s) { return asText(s).replace(/[&<>"']/g, function (ch) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch] || ch; }); }

  function hasSceditor() {
    return !!(window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.sceditor === 'function');
  }

  function isSourceMode(inst) {
    try {
      if (!inst) return false;
      if (typeof inst.sourceMode === 'function') return !!inst.sourceMode();
      if (typeof inst.inSourceMode === 'function') return !!inst.inSourceMode();
    } catch (e) {}
    return false;
  }

  function normColor(v) {
    v = asText(v).trim().toLowerCase();
    return /^#([0-9a-f]{3}|[0-9a-f]{6})$/.test(v) ? v : '';
  }

  function normWidth(v) {
    v = asText(v).trim().toLowerCase();
    var m = v.match(/^([0-9]{1,4})(px|%|em|rem|vw|vh)?$/);
    return m ? (m[1] + (m[2] || 'px')) : '';
  }

  function normBorderWidth(v) {
    v = asText(v).trim().toLowerCase();
    var m = v.match(/^([0-9]{1,2})px$/);
    if (!m) return '';
    var n = parseInt(m[1], 10);
    if (n < 0) n = 0;
    if (n > 20) n = 20;
    return n + 'px';
  }

  function normalizeAttrs(attrs) {
    attrs = attrs || {};
    var out = {
      width: normWidth(attrs.width),
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
    return out;
  }

  function buildTableOpenBb(attrs) {
    var n = normalizeAttrs(attrs);
    var pairs = [];
    ATTR_KEYS.forEach(function (k) {
      var v = asText(n[k]).trim();
      if (k === 'border' || v) pairs.push(k + '=' + (v || '1'));
    });
    return '[table ' + pairs.join(' ') + ']';
  }

  function readCellInnerBb(cell) {
    var html = asText(cell.innerHTML || '').replace(/<br\s*\/?>/gi, '\n');
    html = html.replace(/\u00a0/g, ' ');
    return html;
  }

  function serializeTableDomToCanonicalBb(table) {
    if (!table || table.nodeType !== 1 || String(table.tagName).toLowerCase() !== 'table') return '';

    var attrs = {};
    ATTR_KEYS.forEach(function (k) {
      attrs[k] = asText(table.getAttribute('data-af-' + k)).trim();
    });
    attrs = normalizeAttrs(attrs);

    var out = [buildTableOpenBb(attrs)];
    var rows = table.rows || [];
    for (var r = 0; r < rows.length; r++) {
      var row = rows[r];
      out.push('[tr]');
      for (var c = 0; c < row.cells.length; c++) {
        var cell = row.cells[c];
        var tag = String(cell.tagName || 'td').toLowerCase() === 'th' ? 'th' : 'td';
        var width = normWidth(asText(cell.getAttribute('data-af-width') || cell.style.width));
        var open = '[' + tag + (width ? ' width=' + width : '') + ']';
        out.push(open + readCellInnerBb(cell) + '[/' + tag + ']');
      }
      out.push('[/tr]');
    }
    out.push('[/table]');
    return out.join('\n');
  }

  function buildTableStyles(attrs) {
    var n = normalizeAttrs(attrs);
    var styles = ['border-collapse:collapse', 'border-spacing:0'];
    if (n.width) styles.push('width:' + n.width);
    if (n.align === 'center') styles.push('margin-left:auto', 'margin-right:auto');
    else if (n.align === 'right') styles.push('margin-left:auto');
    else if (n.align === 'left') styles.push('margin-right:auto');
    if (n.bgcolor) styles.push('--af-tbl-bg:' + n.bgcolor);
    if (n.textcolor) styles.push('--af-tbl-txt:' + n.textcolor);
    if (n.hbgcolor) styles.push('--af-tbl-hbg:' + n.hbgcolor);
    if (n.htextcolor) styles.push('--af-tbl-htxt:' + n.htextcolor);
    if (n.border === '0') styles.push('--af-tbl-bw:0px');
    else {
      var bw = n.borderwidth || '1px';
      var bc = n.bordercolor || '#888888';
      styles.push('--af-tbl-bw:' + bw, '--af-tbl-bc:' + bc, 'border:' + bw + ' solid ' + bc);
    }
    return styles.join(';');
  }

  function buildCellStyles(attrs, isHeader, width) {
    var n = normalizeAttrs(attrs);
    var styles = ['padding:6px 8px', 'vertical-align:top'];
    if (width) styles.push('width:' + width);
    if (isHeader) {
      styles.push('font-weight:700', 'text-align:left');
      if (n.hbgcolor || n.bgcolor) styles.push('background-color:' + (n.hbgcolor || n.bgcolor));
      if (n.htextcolor || n.textcolor) styles.push('color:' + (n.htextcolor || n.textcolor));
    } else {
      if (n.bgcolor) styles.push('background-color:' + n.bgcolor);
      if (n.textcolor) styles.push('color:' + n.textcolor);
    }
    if (n.border === '0') styles.push('border:0');
    else styles.push('border:' + (n.borderwidth || '1px') + ' solid ' + (n.bordercolor || '#888888'));
    return styles.join(';');
  }

  function buildHtmlFromModel(rows, cols, attrs) {
    rows = Math.max(1, parseInt(rows, 10) || 1);
    cols = Math.max(1, parseInt(cols, 10) || 1);
    var n = normalizeAttrs(attrs);

    var tableAttrs = ['class="af-ae-table"', 'data-af-table="1"'];
    ATTR_KEYS.forEach(function (k) {
      var v = asText(n[k]).trim();
      if (k === 'border' || v) tableAttrs.push('data-af-' + k + '="' + escHtml(v || '1') + '"');
    });

    var html = ['<table ' + tableAttrs.join(' ') + ' style="' + escHtml(buildTableStyles(n)) + '">'];
    for (var r = 0; r < rows; r++) {
      html.push('<tr>');
      for (var c = 0; c < cols; c++) {
        var isHeader = n.headers === 'both' || (n.headers === 'row' && r === 0) || (n.headers === 'col' && c === 0);
        var tag = isHeader ? 'th' : 'td';
        html.push('<' + tag + ' style="' + escHtml(buildCellStyles(n, isHeader, '')) + '">' + escHtml((isHeader ? 'Header' : 'Cell') + ' ' + (c + 1)) + '</' + tag + '>');
      }
      html.push('</tr>');
    }
    html.push('</table>');
    return html.join('');
  }

  function buildCanonicalBb(rows, cols, attrs) {
    rows = Math.max(1, parseInt(rows, 10) || 1);
    cols = Math.max(1, parseInt(cols, 10) || 1);
    var n = normalizeAttrs(attrs);

    var out = [buildTableOpenBb(n)];
    for (var r = 0; r < rows; r++) {
      out.push('[tr]');
      for (var c = 0; c < cols; c++) {
        var isHeader = n.headers === 'both' || (n.headers === 'row' && r === 0) || (n.headers === 'col' && c === 0);
        var tag = isHeader ? 'th' : 'td';
        out.push('[' + tag + ']' + (isHeader ? ('Header ' + (c + 1)) : ('Cell ' + (c + 1))) + '[/' + tag + ']');
      }
      out.push('[/tr]');
    }
    out.push('[/table]');
    return out.join('\n');
  }

  function attachToolbar(inst, table) {
    if (!inst || !table) return;
    if (inst.__afTableToolbar) {
      inst.__afTableToolbar.remove();
      inst.__afTableToolbar = null;
    }

    var doc = table.ownerDocument;
    var bar = doc.createElement('div');
    bar.className = 'af-table-toolbar';
    bar.style.cssText = 'position:absolute;z-index:9999;background:#111;color:#fff;border:1px solid rgba(255,255,255,.25);border-radius:8px;padding:6px;display:flex;gap:6px;';
    bar.innerHTML = '<button type="button" data-af-act="row-add">+row</button>' +
      '<button type="button" data-af-act="row-del">-row</button>' +
      '<button type="button" data-af-act="col-add">+col</button>' +
      '<button type="button" data-af-act="col-del">-col</button>' +
      '<button type="button" data-af-act="attrs">attrs</button>' +
      '<button type="button" data-af-act="remove">del</button>';

    function reposition() {
      var rect = table.getBoundingClientRect();
      bar.style.top = (window.scrollY + rect.top - bar.offsetHeight - 6) + 'px';
      bar.style.left = (window.scrollX + rect.left) + 'px';
    }

    function applyCellStyles() {
      var attrs = {};
      ATTR_KEYS.forEach(function (k) { attrs[k] = asText(table.getAttribute('data-af-' + k)); });
      for (var r = 0; r < table.rows.length; r++) {
        for (var c = 0; c < table.rows[r].cells.length; c++) {
          var cell = table.rows[r].cells[c];
          var tag = String(cell.tagName || 'td').toLowerCase() === 'th';
          cell.style.cssText = buildCellStyles(attrs, tag, normWidth(cell.getAttribute('data-af-width') || cell.style.width));
        }
      }
      table.style.cssText = buildTableStyles(attrs);
    }

    bar.addEventListener('click', function (ev) {
      var btn = ev.target && ev.target.closest ? ev.target.closest('button[data-af-act]') : null;
      if (!btn) return;
      ev.preventDefault();
      var act = btn.getAttribute('data-af-act');
      var rowCount = table.rows.length;
      var colCount = rowCount ? table.rows[0].cells.length : 0;

      if (act === 'row-add' && colCount) {
        var nr = table.insertRow(-1);
        for (var c = 0; c < colCount; c++) nr.insertCell(-1).innerHTML = '';
      } else if (act === 'row-del' && rowCount > 1) {
        table.deleteRow(rowCount - 1);
      } else if (act === 'col-add') {
        for (var r = 0; r < rowCount; r++) table.rows[r].insertCell(-1).innerHTML = '';
      } else if (act === 'col-del' && colCount > 1) {
        for (var r2 = 0; r2 < rowCount; r2++) table.rows[r2].deleteCell(colCount - 1);
      } else if (act === 'remove') {
        table.remove();
        bar.remove();
        return;
      } else if (act === 'attrs') {
        var next = {
          width: prompt('width (e.g. 500px or 100%)', asText(table.getAttribute('data-af-width')) || ''),
          align: prompt('align (left|center|right)', asText(table.getAttribute('data-af-align')) || ''),
          headers: prompt('headers (row|col|both|empty)', asText(table.getAttribute('data-af-headers')) || ''),
          bgcolor: prompt('bgcolor (#hex)', asText(table.getAttribute('data-af-bgcolor')) || ''),
          textcolor: prompt('textcolor (#hex)', asText(table.getAttribute('data-af-textcolor')) || ''),
          hbgcolor: prompt('hbgcolor (#hex)', asText(table.getAttribute('data-af-hbgcolor')) || ''),
          htextcolor: prompt('htextcolor (#hex)', asText(table.getAttribute('data-af-htextcolor')) || ''),
          border: prompt('border (0|1)', asText(table.getAttribute('data-af-border')) || '1'),
          bordercolor: prompt('bordercolor (#hex)', asText(table.getAttribute('data-af-bordercolor')) || ''),
          borderwidth: prompt('borderwidth (Npx)', asText(table.getAttribute('data-af-borderwidth')) || '1px')
        };
        var n = normalizeAttrs(next);
        ATTR_KEYS.forEach(function (k) { table.setAttribute('data-af-' + k, n[k] || (k === 'border' ? '1' : '')); });
      }

      applyCellStyles();
      reposition();
      try { if (typeof inst.focus === 'function') inst.focus(); } catch (e) {}
    }, false);

    doc.body.appendChild(bar);
    reposition();
    inst.__afTableToolbar = bar;
  }

  function bindEditorTableUi(inst) {
    if (!inst || inst.__afTableUiBound) return;
    inst.__afTableUiBound = true;
    var body = null;
    try { body = inst.getBody && inst.getBody(); } catch (e) { body = null; }
    if (!body) return;

    body.addEventListener('click', function (ev) {
      var table = ev.target && ev.target.closest ? ev.target.closest('table[data-af-table="1"], table.af-ae-table') : null;
      if (!table) {
        if (inst.__afTableToolbar) { inst.__afTableToolbar.remove(); inst.__afTableToolbar = null; }
        return;
      }
      attachToolbar(inst, table);
    }, false);
  }

  function replaceTablesWithBbText(inst) {
    var body = inst && inst.getBody ? inst.getBody() : null;
    if (!body) return;
    var tables = body.querySelectorAll('table[data-af-table="1"], table.af-ae-table');
    for (var i = 0; i < tables.length; i++) {
      var bb = serializeTableDomToCanonicalBb(tables[i]);
      var textNode = body.ownerDocument.createTextNode(bb);
      tables[i].parentNode.replaceChild(textNode, tables[i]);
    }
  }

  function renderCanonicalTablesInBody(inst) {
    var body = inst && inst.getBody ? inst.getBody() : null;
    if (!body) return;
    var html = asText(body.innerHTML || '');
    var guard = 0;
    while (html.indexOf('[table') !== -1 && guard++ < 20) {
      var before = html;
      html = html.replace(/\[table([^\]]*)\]([\s\S]*?)\[\/table\]/i, function (m, attrRaw, content) {
        var attrs = { border: '1' };
        attrRaw.replace(/([a-z]+)\s*=\s*("[^"]*"|'[^']*'|[^\s\]]+)/ig, function (_m, k, v) {
          attrs[String(k).toLowerCase()] = String(v).replace(/^['"]|['"]$/g, '');
          return _m;
        });

        var rows = [];
        content.replace(/\[tr\]([\s\S]*?)\[\/tr\]/ig, function (_rt, rowInner) {
          var cells = [];
          rowInner.replace(/\[(td|th)([^\]]*)\]([\s\S]*?)\[\/\1\]/ig, function (_ct, tag, cellAttrRaw, cellInner) {
            var width = '';
            var mW = String(cellAttrRaw || '').match(/\bwidth\s*=\s*([^\s\]]+)/i);
            if (mW) width = normWidth(mW[1]);
            cells.push({ tag: String(tag).toLowerCase(), width: width, html: cellInner });
            return _ct;
          });
          if (cells.length) rows.push(cells);
          return _rt;
        });

        if (!rows.length) return m;

        var tableAttrs = ['class="af-ae-table"', 'data-af-table="1"'];
        var norm = normalizeAttrs(attrs);
        ATTR_KEYS.forEach(function (k) {
          var val = asText(norm[k]).trim();
          if (k === 'border' || val) tableAttrs.push('data-af-' + k + '="' + escHtml(val || '1') + '"');
        });

        var out = ['<table ' + tableAttrs.join(' ') + ' style="' + escHtml(buildTableStyles(norm)) + '">'];
        for (var r = 0; r < rows.length; r++) {
          out.push('<tr>');
          for (var c = 0; c < rows[r].length; c++) {
            var cell = rows[r][c];
            var tag = cell.tag === 'th' ? 'th' : 'td';
            out.push('<' + tag + (cell.width ? (' data-af-width="' + escHtml(cell.width) + '"') : '') + ' style="' + escHtml(buildCellStyles(norm, tag === 'th', cell.width)) + '">' + cell.html + '</' + tag + '>');
          }
          out.push('</tr>');
        }
        out.push('</table>');
        return out.join('');
      });
      if (html === before) break;
    }
    body.innerHTML = html;
    bindEditorTableUi(inst);
  }

  function patchInstance(inst) {
    if (!inst || inst.__afTablePatched) return;
    inst.__afTablePatched = true;

    if (typeof inst.sourceMode === 'function') {
      var orig = inst.sourceMode;
      inst.sourceMode = function () {
        var to = arguments.length ? !!arguments[0] : null;
        if (to === true && !isSourceMode(this)) replaceTablesWithBbText(this);
        var out = orig.apply(this, arguments);
        if (to === false) renderCanonicalTablesInBody(this);
        return out;
      };
    }

    bindEditorTableUi(inst);
  }

  function insertTable(editor, rows, cols, attrs) {
    var bb = buildCanonicalBb(rows, cols, attrs);
    var html = buildHtmlFromModel(rows, cols, attrs);
    patchInstance(editor);

    if (isSourceMode(editor)) editor.insertText(bb, '');
    else if (typeof editor.wysiwygEditorInsertHtml === 'function') editor.wysiwygEditorInsertHtml(html);
    else editor.insertText(bb, '');

    try { if (typeof editor.focus === 'function') editor.focus(); } catch (e) {}
  }

  function openDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;
    var wrap = document.createElement('div');
    wrap.className = 'af-table-dd';
    wrap.innerHTML =
      '<div class="af-table-dd-body">' +
      '<label class="af-table-dd-row is-rc"><span>Rows</span><input type="number" min="1" max="20" value="2" data-af="rows"></label>' +
      '<label class="af-table-dd-row is-rc"><span>Cols</span><input type="number" min="1" max="20" value="2" data-af="cols"></label>' +
      '<label class="af-table-dd-row"><span>Width</span><input type="text" value="500px" data-af="width"></label>' +
      '<label class="af-table-dd-row"><span>Align</span><select data-af="align"><option value="">default</option><option value="left">left</option><option value="center" selected>center</option><option value="right">right</option></select></label>' +
      '<label class="af-table-dd-row"><span>Headers</span><select data-af="headers"><option value="">none</option><option value="row" selected>row</option><option value="col">col</option><option value="both">both</option></select></label>' +
      '<div class="af-table-dd-actions"><button type="button" class="button af-t-insert">Insert</button></div>' +
      '</div>';

    var btn = wrap.querySelector('.af-t-insert');
    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      var attrs = {
        width: wrap.querySelector('[data-af="width"]').value,
        align: wrap.querySelector('[data-af="align"]').value,
        headers: wrap.querySelector('[data-af="headers"]').value,
        border: '1',
        borderwidth: '1px'
      };
      var rows = parseInt(wrap.querySelector('[data-af="rows"]').value, 10) || 2;
      var cols = parseInt(wrap.querySelector('[data-af="cols"]').value, 10) || 2;
      insertTable(editor, rows, cols, attrs);
      try { editor.closeDropDown(true); } catch (e) {}
    }, false);

    editor.createDropDown(caller, 'sceditor-table-picker', wrap);
    return true;
  }

  function getEditorFromCtx(ctx) {
    if (ctx && typeof ctx.insertText === 'function') return ctx;
    if (ctx && ctx.sceditor && typeof ctx.sceditor.insertText === 'function') return ctx.sceditor;
    if (ctx && ctx.inst && typeof ctx.inst.insertText === 'function') return ctx.inst;
    return null;
  }

  function handler(inst, caller) {
    var editor = getEditorFromCtx(inst) || inst;
    if (!editor || typeof editor.insertText !== 'function') return;
    if (!openDropdown(editor, caller)) insertTable(editor, 2, 2, { headers: 'row', width: '500px', align: 'center', border: '1', borderwidth: '1px' });
  }

  function registerHandlers() {
    window.afAeBuiltinHandlers[ID] = handler;
    window.afAeBuiltinHandlers[CMD] = handler;
    window.afAqrBuiltinHandlers[ID] = { id: ID, title: 'Таблица', onClick: function (ctx, ev) { handler(getEditorFromCtx(ctx), ev && (ev.currentTarget || ev.target)); } };
    window.afAqrBuiltinHandlers[CMD] = window.afAqrBuiltinHandlers[ID];
  }

  function patchCommand() {
    if (!hasSceditor()) return;
    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.command) return;
    var command = {
      exec: function (caller) { patchInstance(this); handler(this, caller); },
      txtExec: function (caller) { patchInstance(this); handler(this, caller); },
      tooltip: 'Таблица'
    };
    $.sceditor.command.set('af_table', command);
    $.sceditor.command.set('table', command);
  }

  registerHandlers();
  patchCommand();
})();

(function () {
  'use strict';

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  if (window.__afAeTablePackLoaded) return;
  window.__afAeTablePackLoaded = true;

  var ID = 'table';
  var CMD = 'af_table';
  var TABLE_ATTR_KEYS = ['width', 'align', 'headers', 'bgcolor', 'textcolor', 'hbgcolor', 'htextcolor', 'border', 'bordercolor', 'borderwidth'];

  function asText(x) {
    return String(x == null ? '' : x);
  }

  function tableDebugLog() {
    try {
      if (!window.__AF_AE_DEBUG_TABLE && !window.__afAeDebug) return;
      var args = Array.prototype.slice.call(arguments);
      args.unshift('[AF-AE table]');
      if (window.console && typeof window.console.log === 'function') {
        window.console.log.apply(window.console, args);
      }
    } catch (e) {}
  }

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

  function getInstBodySafe(inst) {
    if (!inst || typeof inst.getBody !== 'function') return null;
    try {
      var body = inst.getBody();
      if (!body || body.nodeType !== 1 || !body.querySelectorAll || !body.ownerDocument) return null;
      return body;
    } catch (e) {
      return null;
    }
  }

  function hasManagedTablesInBody(inst) {
    var body = getInstBodySafe(inst);
    if (!body) return false;
    try {
      return !!body.querySelector('table.af-ae-table,table[data-af-table="1"]');
    } catch (e) {
      return false;
    }
  }

  function insertAtCursor(ta, text) {
    if (!ta) return false;
    text = asText(text);

    try {
      var start = (typeof ta.selectionStart === 'number') ? ta.selectionStart : 0;
      var end = (typeof ta.selectionEnd === 'number') ? ta.selectionEnd : start;
      var val = String(ta.value || '');

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

  function normColor(s) {
    s = asText(s).trim();
    if (!s) return '';
    if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(s)) return s.toLowerCase();
    return rgbToHex(s) || '';
  }

  function rgbToHex(s) {
    s = asText(s).trim().toLowerCase();
    if (!s) return '';
    var m = s.match(/^rgba?\(([^)]+)\)$/i);
    if (!m) return '';
    var parts = m[1].split(/\s*,\s*/);
    if (parts.length < 3) return '';
    var r = parseInt(parts[0], 10);
    var g = parseInt(parts[1], 10);
    var b = parseInt(parts[2], 10);
    if (!isFinite(r) || !isFinite(g) || !isFinite(b)) return '';
    if (r < 0 || r > 255 || g < 0 || g > 255 || b < 0 || b > 255) return '';
    var toHex = function (n) {
      var h = n.toString(16);
      return h.length === 1 ? ('0' + h) : h;
    };
    return '#' + toHex(r) + toHex(g) + toHex(b);
  }

  function readColorToken(raw) {
    raw = asText(raw).trim();
    if (!raw) return '';
    return normColor(raw) || rgbToHex(raw) || '';
  }

  function normBorderWidth(s) {
    s = asText(s).trim();
    if (!s) return '';
    if (/^[0-9]{1,2}px$/i.test(s)) return s.toLowerCase();
    var n = parseInt(s, 10);
    if (!isFinite(n)) return '';
    if (n < 0) n = 0;
    if (n > 20) n = 20;
    return String(n) + 'px';
  }

  function normWidthToken(s) {
    s = asText(s).trim();
    if (!s) return '';
    var m = s.match(/^([0-9]{1,4})(px|%|em|rem|vw|vh)?$/i);
    if (!m) return '';
    return m[1] + (m[2] ? m[2].toLowerCase() : 'px');
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

  function normalizeTableAttrs(attrs) {
    attrs = attrs || {};
    var out = {
      width: normWidthToken(attrs.width),
      align: '',
      headers: '',
      bgcolor: normColor(attrs.bgcolor || attrs.cellBg),
      textcolor: normColor(attrs.textcolor || attrs.textColor),
      hbgcolor: normColor(attrs.hbgcolor || attrs.headBg),
      htextcolor: normColor(attrs.htextcolor || attrs.headText),
      border: (String(attrs.border) === '0' || attrs.borderOn === false) ? '0' : '1',
      bordercolor: normColor(attrs.bordercolor || attrs.borderColor),
      borderwidth: normBorderWidth(attrs.borderwidth || attrs.borderWidth)
    };

    var align = asText(attrs.align).trim().toLowerCase();
    if (align === 'left' || align === 'center' || align === 'right') out.align = align;

    var headers = asText(attrs.headers).trim().toLowerCase();
    if (headers === 'row' || headers === 'col' || headers === 'both') out.headers = headers;

    return out;
  }

  function buildTableStyle(attrs) {
    var styles = [];
    if (attrs.width) styles.push('width:' + attrs.width);
    if (attrs.align === 'center') {
      styles.push('margin-left:auto');
      styles.push('margin-right:auto');
    } else if (attrs.align === 'right') {
      styles.push('margin-left:auto');
    } else if (attrs.align === 'left') {
      styles.push('margin-right:auto');
    }

    styles.push('border-collapse:collapse');
    styles.push('border-spacing:0');

    if (attrs.bgcolor) styles.push('--af-tbl-bg:' + attrs.bgcolor);
    if (attrs.textcolor) styles.push('--af-tbl-txt:' + attrs.textcolor);
    if (attrs.hbgcolor) styles.push('--af-tbl-hbg:' + attrs.hbgcolor);
    if (attrs.htextcolor) styles.push('--af-tbl-htxt:' + attrs.htextcolor);

    if (attrs.border === '0') {
      styles.push('--af-tbl-bw:0px');
    } else {
      styles.push('--af-tbl-bw:' + (attrs.borderwidth || '1px'));
      styles.push('--af-tbl-bc:' + (attrs.bordercolor || '#888888'));
    }

    if (attrs.border === '1') {
      var bw = attrs.borderwidth || '1px';
      var bc = attrs.bordercolor || '#888888';
      styles.push('border:' + bw + ' solid ' + bc);
    }

    return styles.join(';');
  }

  function attrsToDataAttrs(attrs) {
    var data = ['data-af-table="1"'];
    for (var i = 0; i < TABLE_ATTR_KEYS.length; i++) {
      var k = TABLE_ATTR_KEYS[i];
      var v = asText(attrs[k]).trim();
      if (!v && k !== 'border') continue;
      if (k === 'border' && !v) v = '1';
      data.push('data-af-' + k + '="' + v.replace(/"/g, '&quot;') + '"');
    }
    return data.join(' ');
  }

  function buildCellStyle(tag, tableAttrs, cellWidth, isHeaderByMode) {
    var styles = [];
    var isHeader = (asText(tag).toLowerCase() === 'th') || !!isHeaderByMode;
    if (cellWidth) styles.push('width:' + cellWidth);

    styles.push('background-color:' + (isHeader ? (tableAttrs.hbgcolor || tableAttrs.bgcolor || 'transparent') : (tableAttrs.bgcolor || 'transparent')));
    styles.push('color:' + (isHeader ? (tableAttrs.htextcolor || tableAttrs.textcolor || 'inherit') : (tableAttrs.textcolor || 'inherit')));

    if (tableAttrs.border === '1') {
      styles.push('border:' + (tableAttrs.borderwidth || '1px') + ' solid ' + (tableAttrs.bordercolor || '#888888'));
    } else {
      styles.push('border:0');
    }

    styles.push('padding:6px 8px');
    styles.push('vertical-align:top');

    if (isHeader) {
      styles.push('font-weight:700');
      styles.push('text-align:left');
    }

    return styles.join(';');
  }

  function getFirstTableCellByTag(tableEl, selector) {
    if (!tableEl || tableEl.nodeType !== 1) return null;
    try {
      return tableEl.querySelector(selector) || null;
    } catch (e) {
      return null;
    }
  }

  function readColorFromNode(node) {
    if (!node || node.nodeType !== 1) return '';
    try {
      return readColorToken((node.style && node.style.color) || '');
    } catch (e) {
      return '';
    }
  }

  function readBackgroundFromNode(node) {
    if (!node || node.nodeType !== 1) return '';
    try {
      return readColorToken((node.style && (node.style.backgroundColor || node.style.background)) || '');
    } catch (e) {
      return '';
    }
  }

  function readFirstDescendantColor(root) {
    if (!root || root.nodeType !== 1 || !root.querySelectorAll) return '';
    try {
      var nodes = root.querySelectorAll('*');
      for (var i = 0; i < nodes.length; i++) {
        var color = readColorFromNode(nodes[i]);
        if (color) return color;
      }
    } catch (e) {}
    return '';
  }

  function readFirstDescendantBackground(root) {
    if (!root || root.nodeType !== 1 || !root.querySelectorAll) return '';
    try {
      var nodes = root.querySelectorAll('*');
      for (var i = 0; i < nodes.length; i++) {
        var color = readBackgroundFromNode(nodes[i]);
        if (color) return color;
      }
    } catch (e) {}
    return '';
  }

  function getSingleElementChildIgnoringWhitespace(root) {
    if (!root || !root.childNodes) return null;

    var found = null;
    for (var i = 0; i < root.childNodes.length; i++) {
      var n = root.childNodes[i];

      if (n.nodeType === 3) {
        if (String(n.nodeValue || '').trim() !== '') return null;
        continue;
      }

      if (n.nodeType !== 1) return null;
      if (found) return null;
      found = n;
    }

    return found;
  }

  function isBoldWrapperNode(node) {
    if (!node || node.nodeType !== 1) return false;

    var tag = String(node.tagName || '').toLowerCase();
    if (tag === 'b' || tag === 'strong') return true;

    var cls = String(node.className || '');
    if (/\bmycode_b\b/.test(cls)) return true;

    var fw = '';
    try { fw = String((node.style && node.style.fontWeight) || '').toLowerCase(); } catch (e) { fw = ''; }
    if (fw === 'bold') return true;

    var fwNum = parseInt(fw, 10);
    if (isFinite(fwNum) && fwNum >= 600) return true;

    return false;
  }

  function isColorWrapperNode(node, expectedColor) {
    if (!node || node.nodeType !== 1 || !expectedColor) return false;

    var declared = readColorFromNode(node);
    if (declared && declared === expectedColor) return true;

    var cls = String(node.className || '');
    if (/\bmycode_color\b/.test(cls)) {
      var inline = readColorFromNode(node);
      if (inline && inline === expectedColor) return true;
    }

    return false;
  }

  function stripInheritedCellFormattingDom(cellEl, tag) {
    if (!cellEl || cellEl.nodeType !== 1) return '';

    var clone = cellEl.cloneNode(true);
    var table = null;

    try { table = cellEl.closest ? cellEl.closest('table') : null; } catch (e0) { table = null; }

    var attrs = parseAttrsFromDom(table);
    var inheritedColor = (tag === 'th')
      ? (attrs.htextcolor || attrs.textcolor || '')
      : (attrs.textcolor || '');

    var changed = true;
    while (changed) {
      changed = false;

      var only = getSingleElementChildIgnoringWhitespace(clone);
      if (!only) break;

      if (inheritedColor && isColorWrapperNode(only, inheritedColor)) {
        clone.innerHTML = only.innerHTML;
        changed = true;
        continue;
      }

      if (tag === 'th' && isBoldWrapperNode(only)) {
        clone.innerHTML = only.innerHTML;
        changed = true;
        continue;
      }
    }

    return asText(clone.innerHTML);
  }

  function parseAttrsFromDom(tableEl) {
    var attrs = {
      width: '',
      align: '',
      headers: '',
      bgcolor: '',
      textcolor: '',
      hbgcolor: '',
      htextcolor: '',
      border: '1',
      bordercolor: '',
      borderwidth: ''
    };

    if (!tableEl || tableEl.nodeType !== 1) return attrs;

    if ((tableEl.tagName || '').toLowerCase() !== 'table') {
      try {
        var nested = tableEl.querySelector && tableEl.querySelector('table[data-af-table="1"],table.af-ae-table,table');
        if (nested && nested.nodeType === 1) tableEl = nested;
      } catch (eFind) {}
    }

    function pickData(name) {
      try { return asText(tableEl.getAttribute('data-af-' + name)).trim(); } catch (e) { return ''; }
    }

    for (var i = 0; i < TABLE_ATTR_KEYS.length; i++) {
      var key = TABLE_ATTR_KEYS[i];
      var val = pickData(key);
      if (val) attrs[key] = val;
    }

    try {
      var style = tableEl.style || {};

      if (!attrs.width) attrs.width = normWidthToken(style.width || '');
      if (!attrs.align) {
        if (style.marginLeft === 'auto' && style.marginRight === 'auto') attrs.align = 'center';
        else if (style.marginLeft === 'auto') attrs.align = 'right';
        else if (style.marginRight === 'auto') attrs.align = 'left';
      }

      if (!attrs.bgcolor) attrs.bgcolor = readColorToken(style.getPropertyValue('--af-tbl-bg') || style.backgroundColor || '');
      if (!attrs.textcolor) attrs.textcolor = readColorToken(style.getPropertyValue('--af-tbl-txt') || style.color || '');
      if (!attrs.hbgcolor) attrs.hbgcolor = readColorToken(style.getPropertyValue('--af-tbl-hbg') || '');
      if (!attrs.htextcolor) attrs.htextcolor = readColorToken(style.getPropertyValue('--af-tbl-htxt') || '');
      if (!attrs.bordercolor) attrs.bordercolor = readColorToken(style.getPropertyValue('--af-tbl-bc') || style.borderColor || '');
      if (!attrs.borderwidth) attrs.borderwidth = normBorderWidth(style.getPropertyValue('--af-tbl-bw') || style.borderWidth || '');

      if (attrs.borderwidth === '0px') attrs.border = '0';
    } catch (e0) {}

    try {
      var firstHeaderCell = getFirstTableCellByTag(tableEl, 'th');
      var firstBodyCell = getFirstTableCellByTag(tableEl, 'td');
      var firstAnyCell = firstHeaderCell || firstBodyCell || getFirstTableCellByTag(tableEl, 'td,th');

      if (!attrs.borderwidth && firstAnyCell) {
        attrs.borderwidth = normBorderWidth(
          (firstAnyCell.style && firstAnyCell.style.borderWidth) || ''
        );
      }

      if (!attrs.bordercolor && firstAnyCell) {
        attrs.bordercolor = readColorToken(
          (firstAnyCell.style && firstAnyCell.style.borderColor) || ''
        );
      }

      if (!attrs.bgcolor && firstBodyCell) {
        attrs.bgcolor =
          readBackgroundFromNode(firstBodyCell) ||
          readFirstDescendantBackground(firstBodyCell) ||
          '';
      }

      if (!attrs.textcolor && firstBodyCell) {
        attrs.textcolor =
          readColorFromNode(firstBodyCell) ||
          readFirstDescendantColor(firstBodyCell) ||
          '';
      }

      if (!attrs.hbgcolor && firstHeaderCell) {
        attrs.hbgcolor =
          readBackgroundFromNode(firstHeaderCell) ||
          readFirstDescendantBackground(firstHeaderCell) ||
          '';
      }

      if (!attrs.htextcolor && firstHeaderCell) {
        attrs.htextcolor =
          readColorFromNode(firstHeaderCell) ||
          readFirstDescendantColor(firstHeaderCell) ||
          '';
      }

      if (!attrs.bgcolor && firstAnyCell) {
        attrs.bgcolor =
          readBackgroundFromNode(firstAnyCell) ||
          readFirstDescendantBackground(firstAnyCell) ||
          '';
      }

      if (!attrs.textcolor && firstAnyCell) {
        attrs.textcolor =
          readColorFromNode(firstAnyCell) ||
          readFirstDescendantColor(firstAnyCell) ||
          '';
      }

      if (!attrs.headers) {
        var hasTh = false;
        try { hasTh = !!tableEl.querySelector('th'); } catch (eHasTh) { hasTh = false; }

        if (hasTh) {
          var rowHasAllTh = false;
          var rows = tableEl.rows || [];
          if (rows.length) {
            var firstRow = rows[0];
            if (firstRow && firstRow.cells && firstRow.cells.length) {
              rowHasAllTh = true;
              for (var ri = 0; ri < firstRow.cells.length; ri++) {
                if ((firstRow.cells[ri].tagName || '').toLowerCase() !== 'th') {
                  rowHasAllTh = false;
                  break;
                }
              }
            }
          }

          attrs.headers = rowHasAllTh ? 'row' : 'both';
        }
      }
    } catch (eCell) {}

    if (!attrs.headers) {
      try { attrs.headers = asText(tableEl.getAttribute('data-headers')).trim().toLowerCase(); } catch (e1) {}
    }

    if (attrs.border === '1') {
      if (!attrs.bordercolor) attrs.bordercolor = '#888888';
      if (!attrs.borderwidth) attrs.borderwidth = '1px';
    }

    attrs = normalizeTableAttrs(attrs);

    tableDebugLog('parseAttrsFromDom', {
      className: tableEl && tableEl.className ? asText(tableEl.className) : '',
      attrs: attrs
    });

    return attrs;
  }

  function getCanonicalColumnWidth(cellEl) {
    if (!cellEl || cellEl.nodeType !== 1 || !cellEl.parentElement || !cellEl.parentElement.cells) return '';
    var row = cellEl.parentElement;
    var table = row.closest ? row.closest('table') : null;
    if (!table || !table.rows) return '';

    var colIndex = -1;
    try { colIndex = Array.prototype.indexOf.call(row.cells, cellEl); } catch (e0) { colIndex = -1; }
    if (colIndex < 0) return '';

    try {
      var col = table.querySelector && table.querySelector('colgroup col:nth-child(' + (colIndex + 1) + '), col:nth-child(' + (colIndex + 1) + ')');
      if (col) {
        var colWidth = normWidthToken((col.style && col.style.width) || col.getAttribute('width') || col.getAttribute('data-af-width') || '');
        if (colWidth) return colWidth;
      }
    } catch (eCol) {}

    for (var r = 0; r < table.rows.length; r++) {
      var rowCell = table.rows[r] && table.rows[r].cells ? table.rows[r].cells[colIndex] : null;
      if (!rowCell) continue;
      var width = '';
      try {
        width = normWidthToken((rowCell.style && rowCell.style.width) || rowCell.getAttribute('width') || rowCell.getAttribute('data-af-width') || '');
      } catch (e1) { width = ''; }
      if (width) return width;
    }

    return '';
  }

  function isSerializableTable(table) {
    if (!table || table.nodeType !== 1) return false;
    if ((table.tagName || '').toLowerCase() !== 'table') return false;

    try {
      if (table.closest && table.closest('pre,code')) return false;
    } catch (e0) {}

    try {
      if (table.hasAttribute('data-af-no-table-serialize')) return false;
    } catch (e1) {}

    try {
      return !!table.querySelector('tr td, tr th');
    } catch (e2) {
      return false;
    }
  }

  function getDirectChildRows(tableEl) {
    if (!tableEl || tableEl.nodeType !== 1) return [];

    var rows = [];
    var groups = [];
    try {
      groups = tableEl.querySelectorAll(':scope > thead,:scope > tbody,:scope > tfoot');
    } catch (eScope) {
      groups = [];
    }

    if (groups && groups.length) {
      for (var g = 0; g < groups.length; g++) {
        var groupRows = groups[g].children || [];
        for (var gr = 0; gr < groupRows.length; gr++) {
          var rowNode = groupRows[gr];
          if ((rowNode.tagName || '').toLowerCase() === 'tr') rows.push(rowNode);
        }
      }
    } else {
      var direct = tableEl.children || [];
      for (var i = 0; i < direct.length; i++) {
        var node = direct[i];
        if ((node.tagName || '').toLowerCase() === 'tr') rows.push(node);
      }
    }

    return rows;
  }

  function getDirectRowCells(rowEl) {
    if (!rowEl || rowEl.nodeType !== 1) return [];
    var out = [];
    var children = rowEl.children || [];
    for (var i = 0; i < children.length; i++) {
      var node = children[i];
      var tag = (node.tagName || '').toLowerCase();
      if (tag === 'td' || tag === 'th') out.push(node);
    }
    return out;
  }

  function getTopLevelSerializableTables(root) {
    var out = [];
    if (!root || !root.querySelectorAll) return out;

    var tables = [];
    try { tables = root.querySelectorAll('table'); } catch (eFind) { tables = []; }

    for (var i = 0; i < tables.length; i++) {
      var table = tables[i];
      if (!table || !table.parentNode) continue;
      if (!isSerializableTable(table)) continue;

      var isNested = false;
      try {
        isNested = !!(table.parentElement && table.parentElement.closest && table.parentElement.closest('table'));
      } catch (eNested) {
        isNested = false;
      }
      if (isNested) continue;

      out.push(table);
    }

    return out;
  }

  function genericHtmlToBb(inst, html) {
    html = asText(html);
    if (!html) return '';

    var bb = '';
    try {
      if (inst && typeof inst.toBBCode === 'function') {
        bb = asText(inst.toBBCode(html));
      }
    } catch (e1) { bb = ''; }

    if (!bb) {
      try {
        var plugin = (inst && typeof inst.getPlugin === 'function') ? inst.getPlugin('bbcode') : null;
        if (plugin && typeof plugin.signalToSource === 'function') {
          bb = asText(plugin.signalToSource(html));
        }
      } catch (e2) { bb = ''; }
    }

    return asText(bb);
  }

  function serializeCellContentForTable(cellEl, inst) {
    if (!cellEl || cellEl.nodeType !== 1) return '';

    var tag = ((cellEl.tagName || '').toLowerCase() === 'th') ? 'th' : 'td';
    var cleanedHtml = stripInheritedCellFormattingDom(cellEl, tag);
    if (!cleanedHtml) return '';

    var ownerDoc = cellEl.ownerDocument || document;
    var box = ownerDoc.createElement('div');
    box.innerHTML = cleanedHtml;

    replaceTopLevelTablesWithBb(box, inst);

    var bb = genericHtmlToBb(inst, box.innerHTML);
    if (!bb) {
      try { bb = asText(box.textContent || cellEl.textContent || ''); } catch (e3) { bb = ''; }
    }

    bb = bb.replace(/^\s+|\s+$/g, '');
    if (bb === '[br]' || bb === '[br/]') bb = '';
    if (/^(?:\s|\[br\]|\[br\/\]|&nbsp;)+$/i.test(bb)) bb = '';

    return bb;
  }

  function serializeTableDomToCanonicalBb(tableEl, inst) {
    if (!tableEl || tableEl.nodeType !== 1) return '';

    var model = {
      attrs: parseAttrsFromDom(tableEl),
      rows: []
    };

    var rows = getDirectChildRows(tableEl);

    for (var i = 0; i < rows.length; i++) {
      var row = rows[i];
      var rowModel = { cells: [] };

      var cells = getDirectRowCells(row);
      for (var j = 0; j < cells.length; j++) {
        var cell = cells[j];
        var tag = ((cell.tagName || '').toLowerCase() === 'th') ? 'th' : 'td';
        var width = getCanonicalColumnWidth(cell);
        var content = serializeCellContentForTable(cell, inst);

        rowModel.cells.push({
          tag: tag,
          width: width,
          content: content
        });
      }

      model.rows.push(rowModel);
    }

    var bbcode = modelToCanonicalBbcode(model);
    tableDebugLog('serializeTableDomToCanonicalBb', {
      rows: model.rows.length,
      attrs: model.attrs,
      length: bbcode.length
    });
    return bbcode;
  }

  function replaceTopLevelTablesWithBb(root, inst) {
    var tables = getTopLevelSerializableTables(root);
    for (var i = 0; i < tables.length; i++) {
      var table = tables[i];
      if (!table || !table.parentNode) continue;
      var bbTable = serializeTableDomToCanonicalBb(table, inst);
      table.parentNode.replaceChild((root.ownerDocument || document).createTextNode('\n' + bbTable + '\n'), table);
    }
  }

  function normalizeAfTablesInHtmlWithInst(html, inst) {
    html = asText(html);
    if (!html || html.indexOf('<table') === -1) return html;

    var box = document.createElement('div');
    box.innerHTML = html;
    replaceTopLevelTablesWithBb(box, inst);
    return box.innerHTML;
  }

  function editorHtmlToBbWithOwnedTables(html, inst) {
    html = asText(html);
    if (!html) return '';

    if (!/<table\b/i.test(html)) {
      return genericHtmlToBb(inst, html);
    }

    var box = document.createElement('div');
    box.innerHTML = html;
    replaceTopLevelTablesWithBb(box, inst);
    return genericHtmlToBb(inst, box.innerHTML);
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
    var open = '[table' + (parts.length ? ' ' + parts.join(' ') : '') + ']';
    tableDebugLog('tableAttrsToBbOpen', { attrs: attrs, open: open });
    return open;
  }

  function createTableModel(rows, cols, opts) {
    rows = Math.max(1, Math.min(50, rows | 0));
    cols = Math.max(1, Math.min(50, cols | 0));
    opts = opts || {};

    var attrs = normalizeTableAttrs(opts);
    attrs.width = normWidthToken(opts.width || attrs.width);

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

        rowModel.cells.push({
          tag: tag,
          width: (colWidths && colWidths[c - 1]) ? colWidths[c - 1] : '',
          content: txt
        });
      }
      model.rows.push(rowModel);
    }

    return model;
  }

  function modelToCanonicalBbcode(model) {
    model = model || { attrs: {}, rows: [] };
    var out = [tableAttrsToBbOpen(model.attrs || {})];
    var rows = Array.isArray(model.rows) ? model.rows : [];

    for (var r = 0; r < rows.length; r++) {
      var row = rows[r] || {};
      var cells = Array.isArray(row.cells) ? row.cells : [];
      out.push('[tr]');
      for (var c = 0; c < cells.length; c++) {
        var cell = cells[c] || {};
        var tag = (asText(cell.tag).toLowerCase() === 'th') ? 'th' : 'td';
        var width = normWidthToken(cell.width);
        var content = asText(cell.content);
        out.push('[' + tag + (width ? ' width=' + width : '') + ']' + content + '[/' + tag + ']');
      }
      out.push('[/tr]');
    }

    out.push('[/table]');
    return out.join('\n');
  }

  function modelToWysiwygHtml(model) {
    model = model || { attrs: {}, rows: [] };
    var attrs = normalizeTableAttrs(model.attrs || {});
    var rows = Array.isArray(model.rows) ? model.rows : [];
    var html = [];

    html.push('<table class="af-ae-table" ' + attrsToDataAttrs(attrs) + ' data-headers="' + (attrs.headers || '') + '" style="' + buildTableStyle(attrs) + '">');

    for (var r = 0; r < rows.length; r++) {
      var row = rows[r] || {};
      var cells = Array.isArray(row.cells) ? row.cells : [];
      html.push('<tr>');
      for (var c = 0; c < cells.length; c++) {
        var cell = cells[c] || {};
        var tag = (asText(cell.tag).toLowerCase() === 'th') ? 'th' : 'td';
        var cellStyle = buildCellStyle(tag, attrs, normWidthToken(cell.width), tag === 'th');
        var content = asText(cell.content).trim();
        html.push('<' + tag + (cellStyle ? ' style="' + cellStyle + '"' : '') + '>' + (content || '<br>') + '</' + tag + '>');
      }
      html.push('</tr>');
    }

    html.push('</table><p><br></p>');
    return html.join('');
  }

  function buildBbcode(rows, cols, opts) {
    return modelToCanonicalBbcode(createTableModel(rows, cols, opts));
  }

  function buildHtmlFromBbcode(rows, cols, opts) {
    return modelToWysiwygHtml(createTableModel(rows, cols, opts));
  }

  function getManagedTableFromNode(node) {
    if (!node || node.nodeType !== 1 || !node.closest) return null;
    try {
      return node.closest('table.af-ae-table,table[data-af-table="1"]');
    } catch (e) {
      return null;
    }
  }

  function isManagedTableNode(node) {
    var table = getManagedTableFromNode(node);
    return !!(table && table.nodeType === 1 && (table.tagName || '').toLowerCase() === 'table');
  }

  function resetFloatingTableState(inst, hostDoc, reason) {
    if (!inst) return;

    try {
      if (hostDoc && hostDoc.getElementById) {
        var panel = hostDoc.getElementById('af-ae-table-floating');
        if (panel) panel.style.display = 'none';
      }
    } catch (e0) {}

    inst.__afAeActiveTable = null;
    inst.__afAeActiveTableCell = null;
    inst.__afAeTablePanelPointerDown = false;

    tableDebugLog('reset floating state', { reason: reason || 'unknown' });
  }

  function applyCanonicalAttrsToTableDom(tableEl, attrs) {
    if (!tableEl || tableEl.nodeType !== 1) return false;

    var doc = tableEl.ownerDocument || document;
    attrs = normalizeTableAttrs(attrs || parseAttrsFromDom(tableEl));

    try {
      for (var i = 0; i < TABLE_ATTR_KEYS.length; i++) {
        var k = TABLE_ATTR_KEYS[i];
        var v = asText(attrs[k]).trim();
        if (k === 'border') v = v || '1';
        tableEl.setAttribute('data-af-' + k, v);
      }
      tableEl.setAttribute('data-af-table', '1');
      tableEl.setAttribute('data-headers', asText(attrs.headers).trim());
      tableEl.classList.add('af-ae-table');
      tableEl.setAttribute('style', buildTableStyle(attrs));
    } catch (e0) {}

    try {
      for (var r = 0; r < tableEl.rows.length; r++) {
        var row = tableEl.rows[r];
        for (var c = 0; c < row.cells.length; c++) {
          var cell = row.cells[c];
          var tag = (cell.tagName || '').toLowerCase();
          var isHeaderByMode = false;

          if (attrs.headers === 'row' && r === 0) isHeaderByMode = true;
          if (attrs.headers === 'col' && c === 0) isHeaderByMode = true;
          if (attrs.headers === 'both' && (r === 0 || c === 0)) isHeaderByMode = true;

          var shouldBeTh = (tag === 'th') || isHeaderByMode;
          if (shouldBeTh && tag !== 'th') {
            var th = doc.createElement('th');
            th.innerHTML = cell.innerHTML;
            try { if (cell.style && cell.style.width) th.style.width = cell.style.width; } catch (eW) {}
            row.replaceChild(th, cell);
            cell = th;
            tag = 'th';
          } else if (!shouldBeTh && tag === 'th') {
            var td = doc.createElement('td');
            td.innerHTML = cell.innerHTML;
            try { if (cell.style && cell.style.width) td.style.width = cell.style.width; } catch (eW2) {}
            row.replaceChild(td, cell);
            cell = td;
            tag = 'td';
          }

          var w = getCanonicalColumnWidth(cell) || '';
          var css = buildCellStyle(tag, attrs, w, isHeaderByMode);
          cell.setAttribute('style', css);
        }
      }
    } catch (e2) {}

    return true;
  }

  function normalizeExistingEditorTables(inst) {
    if (!inst || isSourceMode(inst)) return 0;

    var body = getInstBodySafe(inst);
    if (!body) return 0;

    var tables = [];
    try { tables = body.querySelectorAll('table.af-ae-table,table[data-af-table="1"]'); } catch (e1) { tables = []; }

    var changed = 0;
    for (var i = 0; i < tables.length; i++) {
      if (applyCanonicalAttrsToTableDom(tables[i], parseAttrsFromDom(tables[i]))) changed++;
    }

    tableDebugLog('normalizeExistingEditorTables', { count: changed });
    return changed;
  }

  function queueRehydrate(inst, reason) {
    if (!inst) return;
    if (inst.__afAeTableRehydrateQueued) return;

    inst.__afAeTableRehydrateQueued = true;
    setTimeout(function () {
      inst.__afAeTableRehydrateQueued = false;
      try { ensureTableCss(inst); } catch (e0) {}
      try { normalizeExistingEditorTables(inst); } catch (e1) {}
      tableDebugLog('rehydrate', { reason: reason || '' });
    }, 0);
  }

  function ensureTableCss(inst) {
    try {
      if (!inst || typeof inst.getBody !== 'function') return;
      var body = inst.getBody();
      if (!body || !body.ownerDocument) return;
      var doc = body.ownerDocument;
      var head = doc.head || doc.getElementsByTagName('head')[0];
      if (!head || doc.getElementById('af-ae-table-css')) return;

      var css = '' +
        'table[data-af-table="1"],table.af-ae-table{border-collapse:collapse;border-spacing:0;max-width:100%;margin:8px 0;}' +
        'table[data-af-table="1"] td,table[data-af-table="1"] th,table.af-ae-table td,table.af-ae-table th{padding:6px 8px;vertical-align:top;}' +
        'table[data-af-table="1"][data-af-border="1"] td,table[data-af-table="1"][data-af-border="1"] th,table.af-ae-table[data-af-border="1"] td,table.af-ae-table[data-af-border="1"] th{border:var(--af-tbl-bw,1px) solid var(--af-tbl-bc,#888);}' +
        'table[data-af-table="1"] td,table[data-af-table="1"] th,table.af-ae-table td,table.af-ae-table th{background:var(--af-tbl-bg,transparent);color:var(--af-tbl-txt,inherit);}' +
        'table[data-af-table="1"] th,table.af-ae-table th{font-weight:700;text-align:left;background:var(--af-tbl-hbg,var(--af-tbl-bg,rgba(255,255,255,.06)));color:var(--af-tbl-htxt,var(--af-tbl-txt,inherit));}' +
        'table[data-af-table="1"][data-af-headers="row"] tr:first-child > *,table.af-ae-table[data-af-headers="row"] tr:first-child > *,table.af-ae-table[data-headers="row"] tr:first-child > *{font-weight:700;text-align:left;background:var(--af-tbl-hbg,var(--af-tbl-bg,rgba(255,255,255,.06)));color:var(--af-tbl-htxt,var(--af-tbl-txt,inherit));}' +
        'table[data-af-table="1"][data-af-headers="col"] tr > *:first-child,table.af-ae-table[data-af-headers="col"] tr > *:first-child,table.af-ae-table[data-headers="col"] tr > *:first-child{font-weight:700;text-align:left;background:var(--af-tbl-hbg,var(--af-tbl-bg,rgba(255,255,255,.06)));color:var(--af-tbl-htxt,var(--af-tbl-txt,inherit));}' +
        'table[data-af-table="1"][data-af-headers="both"] tr:first-child > *,table[data-af-table="1"][data-af-headers="both"] tr > *:first-child,table.af-ae-table[data-af-headers="both"] tr:first-child > *,table.af-ae-table[data-af-headers="both"] tr > *:first-child,table.af-ae-table[data-headers="both"] tr:first-child > *,table.af-ae-table[data-headers="both"] tr > *:first-child{font-weight:700;text-align:left;background:var(--af-tbl-hbg,var(--af-tbl-bg,rgba(255,255,255,.06)));color:var(--af-tbl-htxt,var(--af-tbl-txt,inherit));}';

      var st = doc.createElement('style');
      st.id = 'af-ae-table-css';
      st.type = 'text/css';
      st.appendChild(doc.createTextNode(css));
      head.appendChild(st);
    } catch (e) {}
  }

  function bindTableToSourceNormalization(inst) {
    if (!inst || inst.__afAeTableToSourceBound || typeof inst.bind !== 'function') return;
    inst.__afAeTableToSourceBound = true;

    try {
      inst.bind('toSource', function (html) {
        html = asText(html);

        if (!html || html.indexOf('<table') === -1) return html;
        if (inst.__afAeTablePreSerializing) return html;

        inst.__afAeTablePreSerializing = true;
        try {
          var box = document.createElement('div');
          box.innerHTML = html;

          replaceTopLevelTablesWithBb(box, inst);
          return box.innerHTML;
        } finally {
          inst.__afAeTablePreSerializing = false;
        }
      });
    } catch (e) {}
  }

  function bindSubmitSync(inst) {
    if (!inst || inst.__afAeTableSubmitSyncBound) return;
    inst.__afAeTableSubmitSyncBound = true;

    try {
      var ta = getTextareaFromCtx({ sceditor: inst });
      var form = ta && ta.form ? ta.form : null;
      if (!form) return;

      form.addEventListener('submit', function () {
        try {
          if (!isSourceMode(inst) && hasManagedTablesInBody(inst) && typeof inst.updateOriginal === 'function') {
            inst.updateOriginal();
          }
        } catch (e0) {}
      }, true);
    } catch (e) {}
  }

  function syncTextareaFromWysiwyg(inst) {
    try {
      if (!inst || isSourceMode(inst) || !hasManagedTablesInBody(inst)) return '';
      var ta = getTextareaFromCtx({ sceditor: inst });
      if (!ta) return '';
      if (typeof inst.updateOriginal === 'function') inst.updateOriginal();
      return asText(ta.value || '');
    } catch (e) {
      return '';
    }
  }

  function bindModeHooks(inst) {
    if (!inst || inst.__afAeTableModeHooksBound) return;
    inst.__afAeTableModeHooksBound = true;

    try {
      if (typeof inst.sourceMode === 'function' && !inst.__afAeTableSourceModeWrapped) {
        var origSourceMode = inst.sourceMode;
        inst.sourceMode = function () {
          var switchingToWysiwyg = (arguments.length && arguments[0] === false);
          if (switchingToWysiwyg) {
            try { ensureInstancePatched(this); } catch (e0) {}
          }

          var out = origSourceMode.apply(this, arguments);

          if (switchingToWysiwyg) {
            queueRehydrate(this, 'sourceMode(false)');
          } else if (arguments.length && arguments[0] === true) {
            resetFloatingTableState(this, null, 'sourceMode(true)');
          }

          return out;
        };
        inst.__afAeTableSourceModeWrapped = true;
      }
    } catch (e1) {}

    try {
      if (typeof inst.toggleSourceMode === 'function' && !inst.__afAeTableToggleModeWrapped) {
        var origToggleMode = inst.toggleSourceMode;
        inst.toggleSourceMode = function () {
          var wasSource = isSourceMode(this);
          if (wasSource) {
            try { ensureInstancePatched(this); } catch (e0) {}
          }

          var out = origToggleMode.apply(this, arguments);

          if (wasSource && !isSourceMode(this)) {
            queueRehydrate(this, 'toggleSourceMode');
          } else if (!wasSource && isSourceMode(this)) {
            resetFloatingTableState(this, null, 'toggleSourceMode->source');
          }

          return out;
        };
        inst.__afAeTableToggleModeWrapped = true;
      }
    } catch (e2) {}
  }

  function afAeEnsureMybbTableBbcode(inst) {
    if (!hasSceditor()) return;

    function getBb() {
      try {
        var bb = jQuery.sceditor.plugins && jQuery.sceditor.plugins.bbcode ? jQuery.sceditor.plugins.bbcode.bbcode : null;
        if (bb && typeof bb.set === 'function') return bb;
      } catch (e0) {}

      try {
        if (inst && typeof inst.getPlugin === 'function') {
          var p = inst.getPlugin('bbcode');
          if (p && p.bbcode && typeof p.bbcode.set === 'function') return p.bbcode;
        }
      } catch (e1) {}

      return null;
    }

    var bb = getBb();
    if (!bb) return;

    function hasTag(name) {
      try {
        if (typeof bb.get === 'function') return !!bb.get(name);
      } catch (eGet) {}
      return false;
    }

    if (bb.__afAeTablePatched && hasTag('table') && hasTag('tr') && hasTag('td') && hasTag('th')) return;

    try {
      bb.set('table', {
        isBlock: true,
        html: function (_token, attrs, content) {
          var a = normalizeTableAttrs({
            width: attrs && attrs.width,
            align: attrs && attrs.align,
            headers: attrs && attrs.headers,
            bgcolor: attrs && attrs.bgcolor,
            textcolor: attrs && attrs.textcolor,
            hbgcolor: attrs && attrs.hbgcolor,
            htextcolor: attrs && attrs.htextcolor,
            border: attrs && attrs.border,
            bordercolor: attrs && attrs.bordercolor,
            borderwidth: attrs && attrs.borderwidth
          });

          var style = buildTableStyle(a);
          return '<table class="af-ae-table" ' + attrsToDataAttrs(a) + ' data-headers="' + (a.headers || '') + '"' + (style ? ' style="' + style + '"' : '') + '>' + (content || '') + '</table>';
        },
        format: function (el, _content) {
          var bbcode = serializeTableDomToCanonicalBb(el, inst);
          tableDebugLog('bb.table.format.atomic', { bbcode: bbcode });
          return bbcode;
        },
        tags: {
          table: {
            format: function (el, _content) {
              var bbcode = serializeTableDomToCanonicalBb(el, inst);
              tableDebugLog('bb.tags.table.format.atomic', { bbcode: bbcode });
              return bbcode;
            }
          }
        }
      });
    } catch (e0) {}

    try {
      bb.set('tr', {
        isBlock: true,
        html: '<tr>{0}</tr>',
        format: '[tr]{0}[/tr]'
      });
    } catch (e1) {}

    try {
      bb.set('td', {
        isInline: false,
        html: function (_token, attrs, content) {
          var w = normWidthToken(attrs && attrs.width);
          var style = '';
          if (w) style += 'width:' + w + ';';
          return '<td' + (style ? ' style="' + style + '"' : '') + '>' + (content || '') + '</td>';
        },
        format: '[td]{0}[/td]'
      });
    } catch (e2) {}

    try {
      bb.set('th', {
        isInline: false,
        html: function (_token, attrs, content) {
          var w = normWidthToken(attrs && attrs.width);
          var style = '';
          if (w) style += 'width:' + w + ';';
          style += 'font-weight:700;text-align:left;';
          return '<th' + (style ? ' style="' + style + '"' : '') + '>' + (content || '') + '</th>';
        },
        format: '[th]{0}[/th]'
      });
    } catch (e3) {}

    try {
      if (bb.tags) {
        bb.tags.table = bb.get ? bb.get('table') : bb.tags.table;
        bb.tags.tr = bb.get ? bb.get('tr') : bb.tags.tr;
        bb.tags.td = bb.get ? bb.get('td') : bb.tags.td;
        bb.tags.th = bb.get ? bb.get('th') : bb.tags.th;
      }
    } catch (eTags) {}

    bb.__afAeTablePatched = true;
  }

  function ensureInstancePatched(inst) {
    if (!inst) return false;

    try { afAeEnsureMybbTableBbcode(inst); } catch (e0) {}
    try { bindTableToSourceNormalization(inst); } catch (e1) {}
    try { bindSubmitSync(inst); } catch (e2) {}
    try { bindModeHooks(inst); } catch (e3) {}
    try { ensureTableCss(inst); } catch (e4) {}
    try { bindFloatingEditor(inst); } catch (e5) {}

    if (!isSourceMode(inst)) {
      try { normalizeExistingEditorTables(inst); } catch (e6) {}
    }

    return true;
  }

  function bindPreSerializeGuards(inst) {
    if (!inst || inst.__afAeTableSerializeGuardBound) return;
    inst.__afAeTableSerializeGuardBound = true;

    function guardPatch() {
      try { ensureInstancePatched(inst); } catch (e0) {}
    }

    try {
      if (typeof inst.bind === 'function') {
        inst.bind('toSource', function (html) {
          guardPatch();
          return html;
        });
      }
    } catch (e1) {}
  }

  function getFloatingPanelHostDoc(inst, iframeDoc) {
    var ownerDoc = document;
    try {
      if (inst && typeof inst.getContentAreaContainer === 'function') {
        var c1 = inst.getContentAreaContainer();
        if (c1 && c1.ownerDocument) return c1.ownerDocument;
      }
    } catch (e1) {}
    try {
      if (inst && typeof inst.getEditorContainer === 'function') {
        var c2 = inst.getEditorContainer();
        if (c2 && c2.ownerDocument) return c2.ownerDocument;
      }
    } catch (e2) {}
    try {
      if (inst && typeof inst.getWrapper === 'function') {
        var c3 = inst.getWrapper();
        if (c3 && c3.ownerDocument) return c3.ownerDocument;
      }
    } catch (e3) {}
    if (ownerDoc && ownerDoc.nodeType === 9) return ownerDoc;
    return iframeDoc || ownerDoc;
  }

  function getEditorIframeElement(inst, iframeDoc) {
    try {
      if (inst && typeof inst.getContentAreaContainer === 'function') {
        var c = inst.getContentAreaContainer();
        if (c && c.querySelector) {
          var ifr = c.querySelector('iframe');
          if (ifr) return ifr;
        }
      }
    } catch (e1) {}

    try {
      if (iframeDoc && iframeDoc.defaultView && iframeDoc.defaultView.frameElement) {
        return iframeDoc.defaultView.frameElement;
      }
    } catch (e2) {}

    return null;
  }

  function openFloatingEditorForTable(inst, table) {
    if (!inst || !table || table.nodeType !== 1) return;
    if (isSourceMode(inst)) return;
    if (!isManagedTableNode(table)) return;

    try {
      var body = getInstBodySafe(inst);
      if (!body || !body.ownerDocument || !body.contains(table)) return;

      var doc = body.ownerDocument;
      var hostDoc = getFloatingPanelHostDoc(inst, doc);
      var iframeEl = getEditorIframeElement(inst, doc);
      var panel = hostDoc.getElementById('af-ae-table-floating');

      function syncEditorValue() {
        try { if (typeof inst.updateOriginal === 'function') inst.updateOriginal(); } catch (e0) {}
        try { if (typeof inst.trigger === 'function') inst.trigger('change'); } catch (e1) {}
        try { if (typeof inst.trigger === 'function') inst.trigger('valuechanged'); } catch (e2) {}
      }

      function ensurePanelCss() {
        if (hostDoc.getElementById('af-ae-table-floating-css')) return;
        var st = hostDoc.createElement('style');
        st.id = 'af-ae-table-floating-css';
        st.type = 'text/css';
        st.appendChild(hostDoc.createTextNode(
          '#af-ae-table-floating{position:fixed;z-index:99999;background:#1f1f1f;border:1px solid rgba(255,255,255,.16);border-radius:10px;padding:6px;display:none;gap:6px;align-items:center;box-shadow:0 8px 24px rgba(0,0,0,.35);}' +
          '#af-ae-table-floating .af-ae-tbtn{display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:28px;padding:0 6px;border-radius:8px;border:1px solid rgba(255,255,255,.14);background:#2a2a2a;color:#fff;cursor:pointer;font:600 12px/1 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;}' +
          '#af-ae-table-floating .af-ae-tbtn:hover{background:#343434;}' +
          '#af-ae-table-floating .af-ae-tbtn svg{width:18px;height:18px;display:block;fill:currentColor;}' +
          '#af-ae-table-floating .af-ae-tsep{width:1px;height:20px;background:rgba(255,255,255,.12);margin:0 2px;}' +
          '#af-ae-table-floating .af-ae-tcolors{display:flex;gap:6px;align-items:center;margin-left:4px;}' +
          '#af-ae-table-floating .af-ae-tinputs{display:flex;gap:6px;align-items:center;}' +
          '#af-ae-table-floating .af-ae-tinp{height:28px;min-width:90px;border-radius:8px;border:1px solid rgba(255,255,255,.14);background:#2a2a2a;color:#fff;padding:0 8px;font:500 12px/1 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;}' +
          '#af-ae-table-floating .af-ae-tbtn.is-active{background:#4a73ff;border-color:#6f90ff;}' +
          '#af-ae-table-floating input[type=color]{width:28px;height:28px;border:0;background:transparent;padding:0;cursor:pointer;}' +
          '#af-ae-table-floating .af-ae-tclose{margin-left:2px;}'
        ));
        (hostDoc.head || hostDoc.getElementsByTagName('head')[0]).appendChild(st);
      }

      function icon(path) {
        return '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="' + path + '"></path></svg>';
      }

      function applyAttrsToDom(t, a) {
        try { applyCanonicalAttrsToTableDom(t, a); } catch (e0) {}
      }

      function getActiveCell(t) {
        var saved = inst.__afAeActiveTableCell;
        if (saved && saved.nodeType === 1 && t.contains(saved) && /^(TD|TH)$/.test(saved.tagName)) return saved;
        var first = t.querySelector('td,th');
        return first || null;
      }

      ensurePanelCss();

      if (!panel) {
        panel = hostDoc.createElement('div');
        panel.id = 'af-ae-table-floating';

        panel.innerHTML = '' +
          '<button type="button" class="af-ae-tbtn" data-a="row-above" title="Добавить строку выше">' + icon('M4 10h12v1H4zM10 4h1v12h-1zM4 6h12v1H4z') + '</button>' +
          '<button type="button" class="af-ae-tbtn" data-a="row-below" title="Добавить строку ниже">' + icon('M4 10h12v1H4zM4 14h12v1H4zM10 12h1v4h-1z') + '</button>' +
          '<button type="button" class="af-ae-tbtn" data-a="row-del" title="Удалить строку">' + icon('M4 10h12v1H4zM6 14h8v1H6z') + '</button>' +
          '<span class="af-ae-tsep" aria-hidden="true"></span>' +
          '<button type="button" class="af-ae-tbtn" data-a="col-left" title="Добавить колонку слева">' + icon('M10 4h1v12h-1zM4 10h4v1H4zM4 4h1v12H4z') + '</button>' +
          '<button type="button" class="af-ae-tbtn" data-a="col-right" title="Добавить колонку справа">' + icon('M10 4h1v12h-1zM12 10h4v1h-4zM15 4h1v12h-1z') + '</button>' +
          '<button type="button" class="af-ae-tbtn" data-a="col-del" title="Удалить колонку">' + icon('M10 4h1v12h-1zM14 4h1v12h-1z') + '</button>' +
          '<span class="af-ae-tsep" aria-hidden="true"></span>' +
          '<button type="button" class="af-ae-tbtn" data-a="del-table" title="Удалить таблицу">' + icon('M7 4h6l1 2h2v1H4V6h2l1-2zm-1 4h1v7H6V8zm3 0h1v7H9V8zm3 0h1v7h-1V8z') + '</button>' +
          '<span class="af-ae-tsep" aria-hidden="true"></span>' +
          '<button type="button" class="af-ae-tbtn" data-a="align-left" title="Выравнивание влево">L</button>' +
          '<button type="button" class="af-ae-tbtn" data-a="align-center" title="Выравнивание по центру">C</button>' +
          '<button type="button" class="af-ae-tbtn" data-a="align-right" title="Выравнивание вправо">R</button>' +
          '<span class="af-ae-tsep" aria-hidden="true"></span>' +
          '<div class="af-ae-tinputs" title="Ширина таблицы / колонок">' +
          '  <input type="text" class="af-ae-tinp" data-a="tbl-width" placeholder="100% или 500px">' +
          '  <button type="button" class="af-ae-tbtn" data-a="apply-width" title="Применить ширину таблицы">W</button>' +
          '  <input type="text" class="af-ae-tinp" data-a="col-width-current" placeholder="Текущая колонка">' +
          '  <button type="button" class="af-ae-tbtn" data-a="apply-col-width-current" title="Применить ширину текущей колонки">C1</button>' +
          '  <input type="text" class="af-ae-tinp" data-a="col-widths" placeholder="120px,200px,...">' +
          '  <button type="button" class="af-ae-tbtn" data-a="apply-col-widths" title="Применить ширины колонок">CW</button>' +
          '</div>' +
          '<span class="af-ae-tsep" aria-hidden="true"></span>' +
          '<div class="af-ae-tcolors" title="Цвета таблицы">' +
          '  <input type="color" data-a="bg" title="Заливка ячеек (bgcolor)">' +
          '  <input type="color" data-a="fg" title="Цвет текста (textcolor)">' +
          '  <input type="color" data-a="hbg" title="Заливка заголовков (hbgcolor)">' +
          '  <input type="color" data-a="hfg" title="Цвет текста заголовков (htextcolor)">' +
          '</div>' +
          '<button type="button" class="af-ae-tbtn af-ae-tclose" data-a="close" title="Закрыть">' + icon('M5.2 4.5L10 9.3l4.8-4.8.7.7-4.8 4.8 4.8 4.8-.7.7-4.8-4.8-4.8 4.8-.7-.7 4.8-4.8-4.8-4.8z') + '</button>';

        (hostDoc.body || hostDoc.documentElement).appendChild(panel);

        panel.addEventListener('mousedown', function (ev) {
          ev.stopPropagation();
        }, true);

        function markPanelInteractionStart() {
          inst.__afAeTablePanelPointerDown = true;
        }

        function markPanelInteractionEnd() {
          setTimeout(function () {
            inst.__afAeTablePanelPointerDown = false;
          }, 0);
        }

        panel.addEventListener('pointerdown', markPanelInteractionStart, true);
        panel.addEventListener('mousedown', markPanelInteractionStart, true);
        panel.addEventListener('pointerup', markPanelInteractionEnd, true);
        panel.addEventListener('mouseup', markPanelInteractionEnd, true);
        panel.addEventListener('click', markPanelInteractionEnd, true);

        panel.addEventListener('click', function (ev) {
          var btn = ev.target && ev.target.closest ? ev.target.closest('button[data-a]') : null;
          var act = btn ? btn.getAttribute('data-a') : '';
          if (!btn || !inst.__afAeActiveTable) return;

          ev.preventDefault();
          ev.stopPropagation();

          var t = inst.__afAeActiveTable;

          function syncPanelStateForTable(tableEl) {
            try {
              var attrs = parseAttrsFromDom(tableEl);
              var activeCell = getActiveCell(tableEl);
              var widthInput = panel.querySelector('input[data-a="tbl-width"]');
              if (widthInput) widthInput.value = attrs.width || '';

              var colCurrentInput = panel.querySelector('input[data-a="col-width-current"]');
              if (colCurrentInput && activeCell && activeCell.parentElement) {
                var cwNow = '';
                try { cwNow = normWidthToken(activeCell.style.width || '') || ''; } catch (eCWNow) { cwNow = ''; }
                colCurrentInput.value = cwNow;
              }

              var alignBtns = panel.querySelectorAll('button[data-a^="align-"]');
              for (var ai = 0; ai < alignBtns.length; ai++) {
                var b = alignBtns[ai];
                var mode = asText(b.getAttribute('data-a')).replace('align-', '');
                if (mode === attrs.align) b.classList.add('is-active');
                else b.classList.remove('is-active');
              }

              var colInput = panel.querySelector('input[data-a="col-widths"]');
              if (colInput && tableEl.rows && tableEl.rows.length) {
                var firstRow = tableEl.rows[0];
                var widths = [];
                for (var ci = 0; ci < firstRow.cells.length; ci++) {
                  var cw = '';
                  try { cw = normWidthToken(firstRow.cells[ci].style.width || ''); } catch (eW) { cw = ''; }
                  widths.push(cw || '');
                }
                colInput.value = widths.join(',');
              }
            } catch (eSync) {}
          }

          if (act === 'close') {
            resetFloatingTableState(inst, hostDoc, 'close-btn');
            return;
          }

          if (act === 'del-table') {
            try {
              t.parentNode && t.parentNode.removeChild(t);
            } catch (eD) {}
            resetFloatingTableState(inst, hostDoc, 'table-deleted');
            syncEditorValue();
            return;
          }

          if (act === 'align-left' || act === 'align-center' || act === 'align-right') {
            try {
              var aAlign = normalizeTableAttrs(parseAttrsFromDom(t));
              aAlign.align = act.replace('align-', '');
              applyAttrsToDom(t, aAlign);
              syncPanelStateForTable(t);
            } catch (eAlign) {}
            syncEditorValue();
            return;
          }

          if (act === 'apply-width') {
            try {
              var wInput = panel.querySelector('input[data-a="tbl-width"]');
              var rawWidth = wInput ? wInput.value : '';
              var aWidth = normalizeTableAttrs(parseAttrsFromDom(t));
              aWidth.width = normWidthToken(rawWidth);
              applyAttrsToDom(t, aWidth);
              if (wInput) wInput.value = aWidth.width || '';
              syncPanelStateForTable(t);
            } catch (eTblW) {}
            syncEditorValue();
            return;
          }

          if (act === 'apply-col-widths') {
            try {
              var cwInput = panel.querySelector('input[data-a="col-widths"]');
              var first = t.rows && t.rows.length ? t.rows[0] : null;
              var cols = first ? first.cells.length : 0;
              var widthsList = parseWidthList(cwInput ? cwInput.value : '', cols);
              if (cols > 0) {
                for (var rrw = 0; rrw < t.rows.length; rrw++) {
                  for (var ccw = 0; ccw < cols; ccw++) {
                    var cellW = t.rows[rrw].cells[ccw];
                    if (!cellW) continue;
                    cellW.style.width = widthsList[ccw] || '';
                  }
                }
              }
              var aCols = normalizeTableAttrs(parseAttrsFromDom(t));
              applyAttrsToDom(t, aCols);
              syncPanelStateForTable(t);
            } catch (eColList) {}
            syncEditorValue();
            return;
          }

          if (act === 'apply-col-width-current') {
            var activeForWidth = getActiveCell(t);
            if (activeForWidth && activeForWidth.parentElement) {
              var colIdx = Array.prototype.indexOf.call(activeForWidth.parentElement.cells, activeForWidth);
              var curInput = panel.querySelector('input[data-a="col-width-current"]');
              var widthVal = normWidthToken(curInput ? curInput.value : '');
              for (var rr3 = 0; rr3 < t.rows.length; rr3++) {
                var c3 = t.rows[rr3].cells[colIdx];
                if (c3) c3.style.width = widthVal || '';
              }
              try {
                var a3 = normalizeTableAttrs(parseAttrsFromDom(t));
                applyAttrsToDom(t, a3);
                syncPanelStateForTable(t);
              } catch (eCA) {}
              syncEditorValue();
            }
            return;
          }

          var cell = getActiveCell(t);
          if (!cell) return;

          var row = cell.parentElement;
          var colIndex = Array.prototype.indexOf.call(row.cells, cell);

          function cloneCell(base) {
            var n = base.cloneNode(false);
            n.innerHTML = '<br>';
            return n;
          }

          if (act === 'row-above' || act === 'row-below') {
            var nr = row.cloneNode(true);
            for (var i = 0; i < nr.cells.length; i++) nr.cells[i].innerHTML = '<br>';
            if (act === 'row-above') row.parentNode.insertBefore(nr, row);
            else row.parentNode.insertBefore(nr, row.nextSibling);
          } else if (act === 'row-del') {
            if (t.rows.length > 1) row.parentNode.removeChild(row);
          } else if (act === 'col-left' || act === 'col-right') {
            for (var r = 0; r < t.rows.length; r++) {
              var rr = t.rows[r];
              var base = rr.cells[Math.min(colIndex, rr.cells.length - 1)] || rr.cells[0];
              var nc = cloneCell(base);
              if (act === 'col-left') rr.insertBefore(nc, rr.cells[colIndex] || null);
              else rr.insertBefore(nc, rr.cells[colIndex + 1] || null);
            }
          } else if (act === 'col-del') {
            for (var r2 = 0; r2 < t.rows.length; r2++) {
              var rr2 = t.rows[r2];
              if (rr2.cells.length > 1 && rr2.cells[colIndex]) rr2.removeChild(rr2.cells[colIndex]);
            }
          }

          try {
            var a2 = normalizeTableAttrs(parseAttrsFromDom(t));
            applyAttrsToDom(t, a2);
            syncPanelStateForTable(t);
          } catch (eA) {}

          syncEditorValue();
          try { if (typeof inst.focus === 'function') inst.focus(); } catch (eF) {}
        }, false);

        var colorHandler = function (ev) {
          var input = ev.target && ev.target.closest ? ev.target.closest('input[type=color][data-a]') : null;
          if (!input || !inst.__afAeActiveTable) return;

          var t = inst.__afAeActiveTable;
          var act = input.getAttribute('data-a');
          var a = parseAttrsFromDom(t);
          var val = asText(input.value).trim();

          if (act === 'bg') a.bgcolor = normColor(val);
          if (act === 'fg') a.textcolor = normColor(val);
          if (act === 'hbg') a.hbgcolor = normColor(val);
          if (act === 'hfg') a.htextcolor = normColor(val);

          a = normalizeTableAttrs(a);
          applyAttrsToDom(t, a);
          syncEditorValue();
          try { if (typeof inst.focus === 'function') inst.focus(); } catch (eF2) {}
        };

        panel.addEventListener('input', colorHandler, false);
        panel.addEventListener('change', colorHandler, false);
      }

      var rect = table.getBoundingClientRect();
      var iframeRect = iframeEl && iframeEl.getBoundingClientRect ? iframeEl.getBoundingClientRect() : null;
      var top = rect.bottom + 8;
      var left = rect.left;

      if (iframeRect) {
        top = iframeRect.top + rect.bottom + 8;
        left = iframeRect.left + rect.left;
      }

      var vw = hostDoc.defaultView ? hostDoc.defaultView.innerWidth : 0;
      if (vw) {
        var maxLeft = Math.max(8, vw - panel.offsetWidth - 8);
        if (left > maxLeft) left = maxLeft;
      }

      panel.style.display = 'flex';
      panel.style.top = Math.max(8, top) + 'px';
      panel.style.left = Math.max(8, left) + 'px';

      inst.__afAeActiveTable = table;

      try {
        var cur = parseAttrsFromDom(table);
        var bg = panel.querySelector('input[data-a="bg"]'); if (bg && cur.bgcolor) bg.value = cur.bgcolor;
        var fg = panel.querySelector('input[data-a="fg"]'); if (fg && cur.textcolor) fg.value = cur.textcolor;
        var hbg = panel.querySelector('input[data-a="hbg"]'); if (hbg && cur.hbgcolor) hbg.value = cur.hbgcolor;
        var hfg = panel.querySelector('input[data-a="hfg"]'); if (hfg && cur.htextcolor) hfg.value = cur.htextcolor;

        var tblWidth = panel.querySelector('input[data-a="tbl-width"]');
        if (tblWidth) tblWidth.value = cur.width || '';

        var colWidths = panel.querySelector('input[data-a="col-widths"]');
        if (colWidths && table.rows && table.rows.length) {
          var firstRow = table.rows[0];
          var list = [];
          for (var ci2 = 0; ci2 < firstRow.cells.length; ci2++) {
            list.push(normWidthToken(firstRow.cells[ci2].style.width || '') || '');
          }
          colWidths.value = list.join(',');
        }

        var alignButtons = panel.querySelectorAll('button[data-a^="align-"]');
        for (var abi = 0; abi < alignButtons.length; abi++) {
          var ab = alignButtons[abi];
          var mode2 = asText(ab.getAttribute('data-a')).replace('align-', '');
          if (mode2 === cur.align) ab.classList.add('is-active');
          else ab.classList.remove('is-active');
        }
      } catch (eS) {}

    } catch (e) {}
  }

  function bindFloatingEditor(inst) {
    if (!inst || inst.__afAeTableFloatingBound) return;

    try {
      var body = getInstBodySafe(inst);
      if (!body) return;

      inst.__afAeTableFloatingBound = true;

      var doc = body.ownerDocument;
      var hostDoc = getFloatingPanelHostDoc(inst, doc);
      var iframeEl = getEditorIframeElement(inst, doc);

      function hidePanel(reason) {
        resetFloatingTableState(inst, hostDoc, reason || 'unknown');
      }

      body.addEventListener('mousedown', function (ev) {
        var cell = ev.target && ev.target.closest ? ev.target.closest('td,th') : null;
        var table = getManagedTableFromNode(cell);
        if (cell && table) inst.__afAeActiveTableCell = cell;
      }, true);

      body.addEventListener('click', function (ev) {
        var cell = ev.target && ev.target.closest ? ev.target.closest('td,th') : null;
        var table = getManagedTableFromNode(cell);
        if (cell && table) inst.__afAeActiveTableCell = cell;
      }, true);

      body.addEventListener('click', function (ev) {
        if (isSourceMode(inst)) {
          hidePanel('click-in-source-mode');
          return;
        }

        var table = getManagedTableFromNode(ev.target);
        if (table && body.contains(table)) {
          openFloatingEditorForTable(inst, table);
          return;
        }

        hidePanel('click-non-table-target');
      }, false);

      hostDoc.addEventListener('mousedown', function (ev) {
        var panel = hostDoc.getElementById('af-ae-table-floating');
        if (!panel || panel.style.display === 'none') return;

        var t = inst.__afAeActiveTable;
        var inPanel = panel.contains(ev.target);
        var hitIframe = !!(iframeEl && (ev.target === iframeEl || (iframeEl.contains && iframeEl.contains(ev.target))));
        var hitTable = false;

        if (t && hitIframe && iframeEl && iframeEl.getBoundingClientRect) {
          try {
            var iframeRect = iframeEl.getBoundingClientRect();
            var x = ev.clientX - iframeRect.left;
            var y = ev.clientY - iframeRect.top;
            if (x >= 0 && y >= 0 && x <= iframeRect.width && y <= iframeRect.height) {
              var elAtPoint = doc.elementFromPoint(x, y);
              var tableAtPoint = getManagedTableFromNode(elAtPoint);
              hitTable = !!(tableAtPoint && (tableAtPoint === t || (t.contains && t.contains(tableAtPoint))));
            }
          } catch (e1) {}
        }

        if (!inPanel && !hitTable) hidePanel('outside-click');
      }, false);

      if (typeof inst.bind === 'function') {
        inst.bind('blur', function () {
          var panel = null;
          try { panel = hostDoc.getElementById('af-ae-table-floating'); } catch (e1) {}

          if (inst.__afAeTablePanelPointerDown) return;

          try {
            if (panel && panel.contains(hostDoc.activeElement)) return;
          } catch (e2) {}

          hidePanel('blur');
        });
      }
    } catch (e) {}
  }

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
      '      <select class="af-t-align"><option value="">—</option><option value="left">left</option><option value="center">center</option><option value="right">right</option></select>' +
      '    </label>' +
      '    <label class="af-table-dd-row"><span>Заголовки</span>' +
      '      <select class="af-t-headers"><option value="none">none</option><option value="row">row</option><option value="col">col</option><option value="both">both</option></select>' +
      '    </label>' +
      '    <label class="af-table-dd-row"><span>Заполнить</span><select class="af-t-fill"><option value="0">нет</option><option value="1">да</option></select></label>' +
      '    <div class="af-table-dd-row is-rc"><label class="af-table-dd-field"><span><input type="checkbox" class="af-t-cellbg-on"> Заливка</span><input type="color" class="af-t-cellbg" value="#000000" disabled></label><label class="af-table-dd-field"><span><input type="checkbox" class="af-t-textcolor-on"> Цвет текста</span><input type="color" class="af-t-textcolor" value="#000000" disabled></label></div>' +
      '    <div class="af-table-dd-row is-rc"><label class="af-table-dd-field"><span><input type="checkbox" class="af-t-headbg-on"> Заливка заголовков</span><input type="color" class="af-t-headbg" value="#000000" disabled></label><label class="af-table-dd-field"><span><input type="checkbox" class="af-t-headtext-on"> Текст заголовков</span><input type="color" class="af-t-headtext" value="#000000" disabled></label></div>' +
      '    <div class="af-table-dd-row is-rc"><label class="af-table-dd-field"><span>Цвет бордера</span><input type="color" class="af-t-bordercolor" value="#ffffff"></label><label class="af-table-dd-field"><span>Бордеры</span><select class="af-t-borderon"><option value="0">нет</option><option value="1" selected>да</option></select></label></div>' +
      '    <div class="af-table-dd-row is-rc"><label class="af-table-dd-field"><span>Толщина</span><input type="number" min="0" max="20" step="1" class="af-t-borderwidth" value="1"></label><div class="af-table-dd-field" aria-hidden="true"></div></div>' +
      '    <div class="af-table-dd-actions"><button type="button" class="button af-t-insert">Вставить</button></div>' +
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
      var cellBg = (chkCellBg && chkCellBg.checked) ? asText(inpCellBg && inpCellBg.value).trim() : '';
      var textColor = (chkText && chkText.checked) ? asText(inpText && inpText.value).trim() : '';
      var headBg = (chkHeadBg && chkHeadBg.checked) ? asText(inpHeadBg && inpHeadBg.value).trim() : '';
      var headText = (chkHeadText && chkHeadText.checked) ? asText(inpHeadText && inpHeadText.value).trim() : '';
      var borderColor = asText(inpBorderColor && inpBorderColor.value).trim();
      var borderOn = (selBorderOn && selBorderOn.value === '1');
      var borderWidth = String(clampInt(inpBorderWidth && inpBorderWidth.value, 0, 20, 1));

      var opts = {
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
      };

      var bb = buildBbcode(rows, cols, opts);
      var html = buildHtmlFromBbcode(rows, cols, opts);
      insertTableToEditor(editor, bb, html);
      closeDd();
    }

    repaintSize();
    updateBorderUi();
    syncColorEnable(chkCellBg, inpCellBg);
    syncColorEnable(chkText, inpText);
    syncColorEnable(chkHeadBg, inpHeadBg);
    syncColorEnable(chkHeadText, inpHeadText);

    [inpCols, inpRows].forEach(function (el) {
      if (!el) return;
      el.addEventListener('input', repaintSize, false);
      el.addEventListener('change', repaintSize, false);
    });

    if (selBorderOn) selBorderOn.addEventListener('change', updateBorderUi, false);

    [[chkCellBg, inpCellBg], [chkText, inpText], [chkHeadBg, inpHeadBg], [chkHeadText, inpHeadText]].forEach(function (pair) {
      if (!pair[0]) return;
      pair[0].addEventListener('change', function () {
        syncColorEnable(pair[0], pair[1]);
      }, false);
    });

    [inpCols, inpRows, inpWidth, inpColWidths, inpBorderWidth].forEach(function (el) {
      if (!el) return;
      el.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          insertNow();
        }
      }, false);
    });

    if (btnInsert) {
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

    ensureInstancePatched(editor);
    bindPreSerializeGuards(editor);

    var wrap = makeDropdown(editor, caller);
    editor.createDropDown(caller, 'sceditor-table-picker', wrap);
    return true;
  }

  function insertTableToEditor(editor, bb, html) {
    bb = asText(bb);
    html = asText(html);

    function insertHtmlWysiwyg(inst, htmlChunk, bbFallback) {
      try {
        if (typeof inst.wysiwygEditorInsertHtml === 'function') {
          inst.wysiwygEditorInsertHtml(htmlChunk);
          return true;
        }
        if (typeof inst.insertHTML === 'function') {
          inst.insertHTML(htmlChunk);
          return true;
        }
      } catch (e0) {}

      try {
        if (typeof inst.insertText === 'function') {
          inst.insertText(bbFallback, '');
          return true;
        }
        if (typeof inst.insert === 'function') {
          inst.insert(bbFallback, '');
          return true;
        }
      } catch (e1) {}

      return false;
    }

    try {
      if (editor && typeof editor.insertText === 'function') {
        ensureInstancePatched(editor);
        bindPreSerializeGuards(editor);

        if (isSourceMode(editor)) {
          editor.insertText(bb, '');
        } else {
          insertHtmlWysiwyg(editor, html || bb, bb);
          queueRehydrate(editor, 'insert');
        }

        try {
          if (typeof editor.updateOriginal === 'function') editor.updateOriginal();
        } catch (e5) {}

        try {
          if (typeof editor.focus === 'function') editor.focus();
        } catch (e6) {}

        return true;
      }
    } catch (e0) {}

    var ta = getTextareaFromCtx({ sceditor: editor });
    return insertAtCursor(ta, bb);
  }

  function patchSceditorTableCommand() {
    if (window.__afAeTableCommandPatched) return true;
    if (!hasSceditor()) return false;

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
          if (!openSceditorDropdown(this, caller)) {
            var bb = buildBbcode(2, 2, { headers: 'none' });
            insertTableToEditor(this, bb, bb);
          }
        },
        tooltip: 'Таблица'
      };
    }

    $.sceditor.command.set('af_table', buildCommand());
    $.sceditor.command.set('table', buildCommand());

    window.__afAeTableCommandPatched = true;
    return true;
  }

  function patchAllCurrentInstances() {
    if (!hasSceditor() || !window.jQuery) return false;

    var $ = window.jQuery;
    try {
      var tas = document.querySelectorAll('textarea');
      for (var i = 0; i < tas.length; i++) {
        var inst = null;
        try { inst = $(tas[i]).sceditor('instance'); } catch (e0) { inst = null; }
        if (!inst) continue;

        ensureInstancePatched(inst);
        bindPreSerializeGuards(inst);
      }
    } catch (e) {}

    return true;
  }

  var patchAllTimer = 0;
  function schedulePatchAllInstances() {
    if (patchAllTimer) return;
    patchAllTimer = setTimeout(function () {
      patchAllTimer = 0;
      try { patchSceditorTableCommand(); } catch (e0) {}
      try { patchAllCurrentInstances(); } catch (e1) {}
    }, 0);
  }

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

    var bb = buildBbcode(2, 2, { headers: 'none' });
    var html = buildHtmlFromBbcode(2, 2, { headers: 'none' });
    insertTableToEditor(editor, bb, html);
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

    if (caller && caller.nodeType === 1) {
      openSceditorDropdown(editor, caller);
    } else {
      var bb = buildBbcode(2, 2, { headers: 'none' });
      var html = buildHtmlFromBbcode(2, 2, { headers: 'none' });
      insertTableToEditor(editor, bb, html);
    }
  }

  function registerHandlers() {
    window.afAqrBuiltinHandlers[ID] = handlerObj;
    window.afAqrBuiltinHandlers[CMD] = handlerObj;
    window.afAeBuiltinHandlers[ID] = handlerFn;
    window.afAeBuiltinHandlers[CMD] = handlerFn;
  }

  registerHandlers();

  window.afAeTableDebugApi = {
    normalizeTableAttrs: normalizeTableAttrs,
    parseAttrsFromDom: parseAttrsFromDom,
    tableAttrsToBbOpen: tableAttrsToBbOpen,
    modelToCanonicalBbcode: modelToCanonicalBbcode,
    modelToWysiwygHtml: modelToWysiwygHtml,
    createTableModel: createTableModel,
    serializeCellContentForTable: serializeCellContentForTable,
    serializeTableDomToCanonicalBb: serializeTableDomToCanonicalBb,
    normalizeAfTablesInHtmlWithInst: normalizeAfTablesInHtmlWithInst,
    editorHtmlToBbWithOwnedTables: editorHtmlToBbWithOwnedTables,
    normalizeExistingEditorTables: normalizeExistingEditorTables,
    syncTextareaFromWysiwyg: syncTextareaFromWysiwyg
  };

  window.af_ae_table_exec = function (editor, def, caller) {
    if (!openSceditorDropdown(editor, caller)) {
      var bb = buildBbcode(2, 2, { headers: 'none' });
      var html = buildHtmlFromBbcode(2, 2, { headers: 'none' });
      insertTableToEditor(editor, bb, html);
    }
  };

  try { patchSceditorTableCommand(); } catch (e0) {}
  schedulePatchAllInstances();

  window.addEventListener('load', function () {
    schedulePatchAllInstances();
  }, { passive: true });

  document.addEventListener('focusin', function (ev) {
    var t = ev.target;
    if (!t || !t.nodeType) return;

    var isTextarea = t.tagName === 'TEXTAREA';
    var inEditor = !!(t.closest && t.closest('.sceditor-container'));
    if (isTextarea || inEditor) {
      schedulePatchAllInstances();
    }
  }, true);

  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t || !t.nodeType) return;

    var inEditor = !!(t.closest && t.closest('.sceditor-container'));
    if (inEditor) {
      try { patchSceditorTableCommand(); } catch (e0) {}
      schedulePatchAllInstances();
    }
  }, true);
})();

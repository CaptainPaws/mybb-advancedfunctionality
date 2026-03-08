(function () {
  'use strict';

  if (window.__afAeTablePackLoaded) return;
  window.__afAeTablePackLoaded = true;

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  var ID = 'table';
  var CMD = 'af_table';
  var TABLE_ATTR_KEYS = ['width', 'align', 'headers', 'bgcolor', 'textcolor', 'hbgcolor', 'htextcolor', 'border', 'bordercolor', 'borderwidth'];

  function asText(v) { return String(v == null ? '' : v); }
  function hasSceditor() { return !!(window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.sceditor === 'function'); }

  function isSourceMode(inst) {
    try {
      if (!inst) return false;
      if (typeof inst.sourceMode === 'function') return !!inst.sourceMode();
      if (typeof inst.inSourceMode === 'function') return !!inst.inSourceMode();
    } catch (e) {}
    return false;
  }

  function normColor(v) {
    v = asText(v).trim();
    if (!v) return '';
    if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(v)) return v.toLowerCase();
    var m = v.toLowerCase().match(/^rgba?\(([^)]+)\)$/);
    if (!m) return '';
    var p = m[1].split(/\s*,\s*/);
    if (p.length < 3) return '';
    var r = parseInt(p[0], 10), g = parseInt(p[1], 10), b = parseInt(p[2], 10);
    if (!isFinite(r) || !isFinite(g) || !isFinite(b)) return '';
    function toHex(n) { var h = Math.max(0, Math.min(255, n)).toString(16); return h.length === 1 ? ('0' + h) : h; }
    return '#' + toHex(r) + toHex(g) + toHex(b);
  }

  function normWidth(v) {
    v = asText(v).trim();
    if (!v) return '';
    var m = v.match(/^([0-9]{1,4})(px|%|em|rem|vw|vh)?$/i);
    if (!m) return '';
    return m[1] + (m[2] ? m[2].toLowerCase() : 'px');
  }

  function normBorderWidth(v) {
    v = asText(v).trim();
    if (!v) return '';
    if (/^[0-9]{1,2}px$/i.test(v)) return v.toLowerCase();
    var n = parseInt(v, 10);
    if (!isFinite(n)) return '';
    n = Math.max(0, Math.min(20, n));
    return n + 'px';
  }

  function parseWidthList(raw, max) {
    raw = asText(raw).trim();
    if (!raw) return [];
    var out = [];
    var parts = raw.split(/[,;]+/g);
    for (var i = 0; i < parts.length && out.length < max; i++) {
      var w = normWidth(parts[i]);
      if (w) out.push(w);
    }
    return out;
  }

  function normalizeAttrs(attrs) {
    attrs = attrs || {};
    var out = {
      width: normWidth(attrs.width),
      align: '',
      headers: '',
      bgcolor: normColor(attrs.bgcolor || attrs.cellBg),
      textcolor: normColor(attrs.textcolor || attrs.textColor),
      hbgcolor: normColor(attrs.hbgcolor || attrs.headBg),
      htextcolor: normColor(attrs.htextcolor || attrs.headText),
      border: String(attrs.border) === '0' || attrs.borderOn === false ? '0' : '1',
      bordercolor: normColor(attrs.bordercolor || attrs.borderColor),
      borderwidth: normBorderWidth(attrs.borderwidth || attrs.borderWidth)
    };
    var a = asText(attrs.align).toLowerCase().trim();
    if (a === 'left' || a === 'center' || a === 'right') out.align = a;
    var h = asText(attrs.headers).toLowerCase().trim();
    if (h === 'row' || h === 'col' || h === 'both') out.headers = h;
    return out;
  }

  function tableAttrsToOpen(attrs) {
    attrs = normalizeAttrs(attrs);
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

  function modelToCanonicalBb(model) {
    var out = [tableAttrsToOpen(model.attrs || {})];
    var rows = Array.isArray(model.rows) ? model.rows : [];
    for (var r = 0; r < rows.length; r++) {
      out.push('[tr]');
      var cells = Array.isArray(rows[r].cells) ? rows[r].cells : [];
      for (var c = 0; c < cells.length; c++) {
        var cell = cells[c] || {};
        var tag = asText(cell.tag).toLowerCase() === 'th' ? 'th' : 'td';
        var width = normWidth(cell.width);
        out.push('[' + tag + (width ? ' width=' + width : '') + ']' + asText(cell.content) + '[/' + tag + ']');
      }
      out.push('[/tr]');
    }
    out.push('[/table]');
    return out.join('\n');
  }

  function buildTableStyle(attrs) {
    attrs = normalizeAttrs(attrs);
    var st = ['border-collapse:collapse'];
    if (attrs.width) st.push('width:' + attrs.width);
    if (attrs.align === 'center') st.push('margin-left:auto', 'margin-right:auto');
    else if (attrs.align === 'right') st.push('margin-left:auto');
    else if (attrs.align === 'left') st.push('margin-right:auto');
    if (attrs.border === '1') st.push('border:' + (attrs.borderwidth || '1px') + ' solid ' + (attrs.bordercolor || '#888888'));
    return st.join(';');
  }

  function attrsToData(attrs) {
    attrs = normalizeAttrs(attrs);
    var out = ['data-af-table="1"'];
    for (var i = 0; i < TABLE_ATTR_KEYS.length; i++) {
      var k = TABLE_ATTR_KEYS[i];
      var v = asText(attrs[k]).trim();
      if (!v && k !== 'border') continue;
      if (!v && k === 'border') v = '1';
      out.push('data-af-' + k + '="' + v.replace(/"/g, '&quot;') + '"');
    }
    return out.join(' ');
  }

  function cellStyle(tag, attrs, width) {
    attrs = normalizeAttrs(attrs);
    var isTh = tag === 'th';
    var st = ['padding:6px 8px', 'vertical-align:top'];
    if (width) st.push('width:' + width);
    st.push('background-color:' + (isTh ? (attrs.hbgcolor || attrs.bgcolor || 'transparent') : (attrs.bgcolor || 'transparent')));
    st.push('color:' + (isTh ? (attrs.htextcolor || attrs.textcolor || 'inherit') : (attrs.textcolor || 'inherit')));
    if (attrs.border === '1') st.push('border:' + (attrs.borderwidth || '1px') + ' solid ' + (attrs.bordercolor || '#888888'));
    else st.push('border:0');
    if (isTh) st.push('font-weight:700');
    return st.join(';');
  }

  function modelToWysiwygHtml(model) {
    var attrs = normalizeAttrs(model.attrs || {});
    var rows = Array.isArray(model.rows) ? model.rows : [];
    var out = [];
    out.push('<table class="af-ae-table" ' + attrsToData(attrs) + ' style="' + buildTableStyle(attrs) + '">');
    for (var r = 0; r < rows.length; r++) {
      out.push('<tr>');
      var cells = rows[r].cells || [];
      for (var c = 0; c < cells.length; c++) {
        var cell = cells[c] || {};
        var tag = asText(cell.tag).toLowerCase() === 'th' ? 'th' : 'td';
        var w = normWidth(cell.width);
        var cnt = asText(cell.content).trim();
        out.push('<' + tag + ' style="' + cellStyle(tag, attrs, w) + '">' + (cnt || '<br>') + '</' + tag + '>');
      }
      out.push('</tr>');
    }
    out.push('</table><p><br></p>');
    return out.join('');
  }

  function createModel(rows, cols, opts) {
    rows = Math.max(1, Math.min(50, rows | 0));
    cols = Math.max(1, Math.min(50, cols | 0));
    opts = opts || {};
    var attrs = normalizeAttrs(opts);
    var widths = Array.isArray(opts.colWidths) ? opts.colWidths : parseWidthList(opts.colWidths, cols);
    var model = { attrs: attrs, rows: [] };

    function isHeaderCell(r, c) {
      if (attrs.headers === 'row') return r === 1;
      if (attrs.headers === 'col') return c === 1;
      if (attrs.headers === 'both') return r === 1 || c === 1;
      return false;
    }

    for (var r = 1; r <= rows; r++) {
      var row = { cells: [] };
      for (var c = 1; c <= cols; c++) {
        var tag = isHeaderCell(r, c) ? 'th' : 'td';
        var txt = '';
        if (opts.fill && tag === 'th') {
          if ((attrs.headers === 'row' || attrs.headers === 'both') && r === 1) txt = 'Header ' + c;
          else if ((attrs.headers === 'col' || attrs.headers === 'both') && c === 1) txt = 'Row ' + r;
        }
        row.cells.push({ tag: tag, width: widths[c - 1] || '', content: txt });
      }
      model.rows.push(row);
    }

    return model;
  }

  function getTableAttrsFromDom(tableEl) {
    var attrs = normalizeAttrs({ border: '1' });
    if (!tableEl || tableEl.nodeType !== 1) return attrs;

    for (var i = 0; i < TABLE_ATTR_KEYS.length; i++) {
      var k = TABLE_ATTR_KEYS[i];
      var v = asText(tableEl.getAttribute('data-af-' + k)).trim();
      if (v) attrs[k] = v;
    }

    var st = tableEl.style || {};
    if (!attrs.width) attrs.width = normWidth(st.width || tableEl.getAttribute('width') || '');
    if (!attrs.align) {
      if (st.marginLeft === 'auto' && st.marginRight === 'auto') attrs.align = 'center';
      else if (st.marginLeft === 'auto') attrs.align = 'right';
      else if (st.marginRight === 'auto') attrs.align = 'left';
    }

    if (!attrs.bgcolor) attrs.bgcolor = normColor(st.backgroundColor || '');
    if (!attrs.textcolor) attrs.textcolor = normColor(st.color || '');
    if (!attrs.borderwidth) attrs.borderwidth = normBorderWidth(st.borderWidth || '');
    if (!attrs.bordercolor) attrs.bordercolor = normColor(st.borderColor || '');
    if (attrs.borderwidth === '0px') attrs.border = '0';

    return normalizeAttrs(attrs);
  }

  function getDirectRows(tableEl) {
    var rows = [];
    if (!tableEl || tableEl.nodeType !== 1) return rows;
    var kids = tableEl.children || [];
    for (var i = 0; i < kids.length; i++) {
      var tag = (kids[i].tagName || '').toLowerCase();
      if (tag === 'tr') rows.push(kids[i]);
      if (tag === 'thead' || tag === 'tbody' || tag === 'tfoot') {
        var rk = kids[i].children || [];
        for (var j = 0; j < rk.length; j++) if ((rk[j].tagName || '').toLowerCase() === 'tr') rows.push(rk[j]);
      }
    }
    return rows;
  }

  function getDirectCells(rowEl) {
    var out = [];
    var kids = rowEl && rowEl.children ? rowEl.children : [];
    for (var i = 0; i < kids.length; i++) {
      var tag = (kids[i].tagName || '').toLowerCase();
      if (tag === 'td' || tag === 'th') out.push(kids[i]);
    }
    return out;
  }

  function unwrapInheritedWrappers(root, tag, inheritedColor) {
    var changed = true;
    while (changed) {
      changed = false;
      var elem = null;
      var nodes = root.childNodes || [];
      for (var i = 0; i < nodes.length; i++) {
        if (nodes[i].nodeType === 3 && asText(nodes[i].nodeValue).trim() === '') continue;
        if (nodes[i].nodeType !== 1 || elem) return;
        elem = nodes[i];
      }
      if (!elem) return;

      var elTag = (elem.tagName || '').toLowerCase();
      var styleColor = normColor((elem.style && elem.style.color) || '');
      var isColorWrap = !!(inheritedColor && (styleColor === inheritedColor || /\bmycode_color\b/.test(asText(elem.className))));
      var fw = asText((elem.style && elem.style.fontWeight) || '').toLowerCase();
      var isBoldWrap = elTag === 'b' || elTag === 'strong' || /\bmycode_b\b/.test(asText(elem.className)) || fw === 'bold' || (parseInt(fw, 10) >= 600);

      if (isColorWrap || (tag === 'th' && isBoldWrap)) {
        root.innerHTML = elem.innerHTML;
        changed = true;
      }
    }
  }

  function cleanupInheritedBbWrappers(bb, tag, inheritedColor) {
    bb = asText(bb).trim();
    if (!bb) return '';
    var changed = true;
    while (changed) {
      changed = false;
      if (inheritedColor) {
        var esc = inheritedColor.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        var reColor = new RegExp('^\\s*\\[color=' + esc + '\\]([\\s\\S]*)\\[/color\\]\\s*$', 'i');
        var mc = bb.match(reColor);
        if (mc) { bb = mc[1]; changed = true; }
      }
      if (tag === 'th') {
        var mb = bb.match(/^\s*\[b\]([\s\S]*)\[\/b\]\s*$/i);
        if (mb) { bb = mb[1]; changed = true; }
      }
    }
    return bb;
  }

  function isSerializableTable(node) {
    if (!node || node.nodeType !== 1) return false;
    if ((node.tagName || '').toLowerCase() !== 'table') return false;
    try { if (node.closest && node.closest('pre,code')) return false; } catch (e) {}
    return true;
  }

  function serializeCellDomToBb(cellEl, tag, inheritedColor, inst) {
    var clone = cellEl.cloneNode(true);
    unwrapInheritedWrappers(clone, tag, inheritedColor);

    var nested = clone.querySelectorAll ? clone.querySelectorAll('table') : [];
    for (var i = nested.length - 1; i >= 0; i--) {
      if (!isSerializableTable(nested[i])) continue;
      var nestedBb = serializeTableDomToCanonicalBb(nested[i], inst);
      nested[i].parentNode.replaceChild(clone.ownerDocument.createTextNode('\n' + nestedBb + '\n'), nested[i]);
    }

    var html = asText(clone.innerHTML).trim();
    if (!html || html === '<br>' || html === '<br/>') return '';

    var bb = '';
    try { if (inst && typeof inst.toBBCode === 'function') bb = asText(inst.toBBCode(html)); } catch (e0) { bb = ''; }
    if (!bb) {
      try {
        var plugin = inst && typeof inst.getPlugin === 'function' ? inst.getPlugin('bbcode') : null;
        if (plugin && typeof plugin.signalToSource === 'function') bb = asText(plugin.signalToSource(html));
      } catch (e1) { bb = ''; }
    }
    if (!bb) bb = asText(clone.textContent || '');

    bb = cleanupInheritedBbWrappers(bb, tag, inheritedColor);
    bb = bb.replace(/^\s+|\s+$/g, '');
    if (bb === '[br]' || bb === '[br/]') return '';
    return bb;
  }

  // Single owner serializer for table DOM -> canonical BBCode.
  function serializeTableDomToCanonicalBb(tableEl, inst) {
    if (!isSerializableTable(tableEl)) return '';

    var attrs = getTableAttrsFromDom(tableEl);
    var rows = getDirectRows(tableEl);
    var model = { attrs: attrs, rows: [] };

    for (var r = 0; r < rows.length; r++) {
      var row = { cells: [] };
      var cells = getDirectCells(rows[r]);
      for (var c = 0; c < cells.length; c++) {
        var cell = cells[c];
        var tag = (cell.tagName || '').toLowerCase() === 'th' ? 'th' : 'td';
        var width = normWidth((cell.style && cell.style.width) || cell.getAttribute('width') || cell.getAttribute('data-af-width') || '');
        var inheritedColor = tag === 'th' ? (attrs.htextcolor || attrs.textcolor || '') : (attrs.textcolor || '');
        var content = serializeCellDomToBb(cell, tag, inheritedColor, inst);
        row.cells.push({ tag: tag, width: width, content: content });
      }
      model.rows.push(row);
    }

    return modelToCanonicalBb(model);
  }

  function bindTableToSourceNormalization(inst) {
    if (!inst || inst.__afAeTableSourceBound) return;
    inst.__afAeTableSourceBound = true;

    if (typeof inst.bind !== 'function') return;
    inst.bind('toSource', function (html) {
      html = asText(html);
      if (!html || html.indexOf('<table') === -1) return html;

      var box = document.createElement('div');
      box.innerHTML = html;
      var tables = box.querySelectorAll('table');
      for (var i = tables.length - 1; i >= 0; i--) {
        var t = tables[i];
        if (!isSerializableTable(t)) continue;
        var nested = false;
        try { nested = !!(t.parentElement && t.parentElement.closest && t.parentElement.closest('table')); } catch (e) { nested = false; }
        if (nested) continue;
        var bb = serializeTableDomToCanonicalBb(t, inst);
        t.parentNode.replaceChild(document.createTextNode('\n' + bb + '\n'), t);
      }
      return box.innerHTML;
    });
  }

  function ensureTableCss(inst) {
    try {
      var body = inst && typeof inst.getBody === 'function' ? inst.getBody() : null;
      if (!body || !body.ownerDocument) return;
      var doc = body.ownerDocument;
      if (doc.getElementById('af-ae-table-css')) return;
      var style = doc.createElement('style');
      style.id = 'af-ae-table-css';
      style.type = 'text/css';
      style.appendChild(doc.createTextNode(
        'table.af-ae-table{border-collapse:collapse;max-width:100%;margin:8px 0;}' +
        'table.af-ae-table td,table.af-ae-table th{padding:6px 8px;vertical-align:top;}'
      ));
      (doc.head || doc.getElementsByTagName('head')[0]).appendChild(style);
    } catch (e) {}
  }

  function patchBbcodeRuntime(inst) {
    var bb = window.jQuery && window.jQuery.sceditor && window.jQuery.sceditor.plugins && window.jQuery.sceditor.plugins.bbcode;
    if (!bb || bb.__afAeTablePatched) return;

    function hasTag(tag) { return !!(bb.tags && bb.tags[tag]); }
    if (!(hasTag('table') && hasTag('tr') && hasTag('td') && hasTag('th'))) return;

    bb.set('table', {
      isBlock: true,
      html: function (_token, attrs, content) {
        var a = normalizeAttrs(attrs || {});
        return '<table class="af-ae-table" ' + attrsToData(a) + ' style="' + buildTableStyle(a) + '">' + (content || '') + '</table>';
      },
      format: function (el) {
        return serializeTableDomToCanonicalBb(el, inst);
      },
      tags: {
        table: {
          format: function (el) {
            return serializeTableDomToCanonicalBb(el, inst);
          }
        }
      }
    });

    bb.set('tr', { isBlock: true, html: '<tr>{0}</tr>', format: '[tr]{0}[/tr]' });
    bb.set('td', { isInline: false, html: '<td>{0}</td>', format: function (_el, c) { return '[td]' + asText(c) + '[/td]'; } });
    bb.set('th', { isInline: false, html: '<th>{0}</th>', format: function (_el, c) { return '[th]' + asText(c) + '[/th]'; } });

    bb.__afAeTablePatched = true;
  }

  function getSceditorInstanceFromCtx(ctx) {
    if (ctx && typeof ctx.insertText === 'function') return ctx;
    if (ctx && ctx.sceditor && typeof ctx.sceditor.insertText === 'function') return ctx.sceditor;
    if (ctx && ctx.instance && typeof ctx.instance.insertText === 'function') return ctx.instance;
    try {
      if (!window.jQuery) return null;
      var ta = window.jQuery('textarea#message,textarea[name="message"]').first();
      if (ta.length) return ta.sceditor('instance');
    } catch (e) {}
    return null;
  }

  function insertTable(editor, bb, html) {
    if (!editor || typeof editor.insertText !== 'function') return false;

    patchBbcodeRuntime(editor);
    ensureTableCss(editor);
    bindTableToSourceNormalization(editor);
    bindFloatingEditor(editor);

    if (isSourceMode(editor)) editor.insertText(bb, '');
    else if (typeof editor.wysiwygEditorInsertHtml === 'function') editor.wysiwygEditorInsertHtml(html || bb);
    else if (typeof editor.insertHTML === 'function') editor.insertHTML(html || bb);
    else editor.insertText(bb, '');

    try { if (typeof editor.updateOriginal === 'function') editor.updateOriginal(); } catch (e0) {}
    try { if (typeof editor.focus === 'function') editor.focus(); } catch (e1) {}
    return true;
  }

  function clampInt(v, min, max, def) {
    var n = parseInt(v, 10);
    if (!isFinite(n)) n = def;
    return Math.max(min, Math.min(max, n));
  }

  function makeDropdown(editor) {
    var wrap = document.createElement('div');
    wrap.className = 'af-table-dd';
    wrap.style.cssText = 'padding:10px;min-width:290px;display:grid;gap:8px;';
    wrap.innerHTML = '' +
      '<label>Columns <input data-k="cols" type="number" min="1" max="50" value="2"></label>' +
      '<label>Rows <input data-k="rows" type="number" min="1" max="50" value="2"></label>' +
      '<label>Table width <input data-k="width" placeholder="500px"></label>' +
      '<label>Column widths <input data-k="colwidths" placeholder="120px,120px"></label>' +
      '<label>Align <select data-k="align"><option value="">Default</option><option>left</option><option>center</option><option>right</option></select></label>' +
      '<label>Headers <select data-k="headers"><option value="">none</option><option value="row">row</option><option value="col">col</option><option value="both">both</option></select></label>' +
      '<label>Fill <select data-k="fill"><option value="0">off</option><option value="1">on</option></select></label>' +
      '<label>Bg color <input data-k="bg" type="color" value="#ffffff"></label>' +
      '<label>Text color <input data-k="txt" type="color" value="#000000"></label>' +
      '<label>Header bg <input data-k="hbg" type="color" value="#ffffff"></label>' +
      '<label>Header text <input data-k="htxt" type="color" value="#000000"></label>' +
      '<label>Border <select data-k="border"><option value="1">on</option><option value="0">off</option></select></label>' +
      '<label>Border color <input data-k="bc" type="color" value="#888888"></label>' +
      '<label>Border width(px) <input data-k="bw" type="number" min="0" max="20" value="1"></label>' +
      '<button data-k="insert" type="button">Insert table</button>';

    function v(k) { var el = wrap.querySelector('[data-k="' + k + '"]'); return el ? el.value : ''; }
    wrap.querySelector('[data-k="insert"]').addEventListener('click', function () {
      var opts = {
        width: v('width'),
        colWidths: v('colwidths'),
        align: v('align'),
        headers: v('headers'),
        fill: v('fill') === '1',
        cellBg: v('bg'),
        textColor: v('txt'),
        headBg: v('hbg'),
        headText: v('htxt'),
        border: v('border'),
        borderColor: v('bc'),
        borderWidth: v('bw') + 'px'
      };
      var model = createModel(clampInt(v('rows'), 1, 50, 2), clampInt(v('cols'), 1, 50, 2), opts);
      insertTable(editor, modelToCanonicalBb(model), modelToWysiwygHtml(model));
      try { editor.closeDropDown(true); } catch (e) {}
    });

    return wrap;
  }

  function openDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;
    patchBbcodeRuntime(editor);
    ensureTableCss(editor);
    bindTableToSourceNormalization(editor);
    bindFloatingEditor(editor);
    try { editor.closeDropDown(true); } catch (e) {}
    editor.createDropDown(caller, 'sceditor-table-picker', makeDropdown(editor));
    return true;
  }

  function syncTableDomAttrs(tableEl) {
    var attrs = normalizeAttrs(getTableAttrsFromDom(tableEl));
    tableEl.setAttribute('class', 'af-ae-table');
    tableEl.setAttribute('style', buildTableStyle(attrs));
    for (var i = 0; i < TABLE_ATTR_KEYS.length; i++) {
      var k = TABLE_ATTR_KEYS[i];
      var v = asText(attrs[k]).trim();
      if (!v && k !== 'border') tableEl.removeAttribute('data-af-' + k);
      else tableEl.setAttribute('data-af-' + k, v || '1');
    }
  }

  function bindFloatingEditor(inst) {
    if (!inst || inst.__afAeFloatingBound) return;
    inst.__afAeFloatingBound = true;

    var body = null;
    try { body = typeof inst.getBody === 'function' ? inst.getBody() : null; } catch (e0) { body = null; }
    if (!body || !body.ownerDocument) return;
    var doc = body.ownerDocument;

    var panel = doc.getElementById('af-ae-table-floating');
    if (!panel) {
      panel = doc.createElement('div');
      panel.id = 'af-ae-table-floating';
      panel.style.cssText = 'position:fixed;z-index:99999;display:none;gap:6px;background:#1f1f1f;padding:6px;border-radius:8px;';
      panel.innerHTML = '' +
        '<button data-a="row-above">+R↑</button><button data-a="row-below">+R↓</button><button data-a="row-del">-R</button>' +
        '<button data-a="col-left">+C←</button><button data-a="col-right">+C→</button><button data-a="col-del">-C</button>' +
        '<input data-a="tbl-width" placeholder="500px" style="width:80px">' +
        '<button data-a="apply-width">W</button>' +
        '<button data-a="close">×</button>';
      doc.body.appendChild(panel);
    }

    function activeTableFromTarget(t) {
      try { return t && t.closest ? t.closest('table') : null; } catch (e) { return null; }
    }

    function showFor(table) {
      if (!table) return;
      var r = table.getBoundingClientRect();
      panel.style.left = Math.max(8, r.left) + 'px';
      panel.style.top = Math.max(8, r.top - 40) + 'px';
      panel.style.display = 'flex';
      panel.__table = table;
      var inp = panel.querySelector('[data-a="tbl-width"]');
      if (inp) inp.value = normWidth((table.style && table.style.width) || table.getAttribute('data-af-width') || '');
    }

    body.addEventListener('click', function (ev) {
      var t = activeTableFromTarget(ev.target);
      if (t) showFor(t);
    }, false);

    panel.addEventListener('click', function (ev) {
      var btn = ev.target && ev.target.closest ? ev.target.closest('[data-a]') : null;
      if (!btn) return;
      var table = panel.__table;
      if (!table) return;
      var act = btn.getAttribute('data-a');

      if (act === 'close') { panel.style.display = 'none'; panel.__table = null; return; }
      if (act === 'apply-width') {
        var val = normWidth((panel.querySelector('[data-a="tbl-width"]') || {}).value || '');
        table.style.width = val;
        table.setAttribute('data-af-width', val);
      }

      var sel = null;
      try { sel = body.querySelector('td:focus,th:focus'); } catch (e0) { sel = null; }
      if (!sel) sel = table.querySelector('td,th');
      if (!sel) return;
      var row = sel.parentElement;
      var col = Array.prototype.indexOf.call(row.cells, sel);

      if (act === 'row-above' || act === 'row-below') {
        var nr = row.cloneNode(true);
        for (var i = 0; i < nr.cells.length; i++) nr.cells[i].innerHTML = '<br>';
        row.parentNode.insertBefore(nr, act === 'row-above' ? row : row.nextSibling);
      } else if (act === 'row-del') {
        if (table.rows.length > 1) row.parentNode.removeChild(row);
      } else if (act === 'col-left' || act === 'col-right') {
        for (var r = 0; r < table.rows.length; r++) {
          var rr = table.rows[r];
          var base = rr.cells[Math.min(col, rr.cells.length - 1)] || rr.cells[0];
          var nc = base.cloneNode(false); nc.innerHTML = '<br>';
          rr.insertBefore(nc, act === 'col-left' ? rr.cells[col] : (rr.cells[col + 1] || null));
        }
      } else if (act === 'col-del') {
        for (var r2 = 0; r2 < table.rows.length; r2++) if (table.rows[r2].cells.length > 1 && table.rows[r2].cells[col]) table.rows[r2].deleteCell(col);
      }

      syncTableDomAttrs(table);
      try { if (typeof inst.updateOriginal === 'function') inst.updateOriginal(); } catch (e1) {}
    }, false);
  }

  function patchSceditorTableCommand() {
    if (!hasSceditor()) return false;
    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.command) return false;

    function cmd() {
      return {
        exec: function (caller) {
          if (!openDropdown(this, caller)) {
            var m = createModel(2, 2, { headers: '' });
            insertTable(this, modelToCanonicalBb(m), modelToWysiwygHtml(m));
          }
        },
        txtExec: function () {
          var m = createModel(2, 2, { headers: '' });
          insertTable(this, modelToCanonicalBb(m), modelToCanonicalBb(m));
        },
        tooltip: 'Таблица'
      };
    }

    $.sceditor.command.set('af_table', cmd());
    $.sceditor.command.set('table', cmd());
    return true;
  }

  function patchInstances() {
    if (!hasSceditor()) return;
    var $ = window.jQuery;
    var tas = document.querySelectorAll('textarea');
    for (var i = 0; i < tas.length; i++) {
      var inst = null;
      try { inst = $(tas[i]).sceditor('instance'); } catch (e0) { inst = null; }
      if (!inst) continue;
      patchBbcodeRuntime(inst);
      ensureTableCss(inst);
      bindTableToSourceNormalization(inst);
      bindFloatingEditor(inst);
    }
  }

  function waitAnd(fn, n) {
    var i = 0;
    (function tick() {
      i++;
      if (fn()) return;
      if (i < (n || 100)) setTimeout(tick, 100);
    })();
  }

  waitAnd(patchSceditorTableCommand, 150);
  for (var t = 0; t < 60; t++) setTimeout(patchInstances, t * 150);

  function aqrOpen(ctx, ev) {
    var editor = getSceditorInstanceFromCtx(ctx);
    var caller = (ctx && (ctx.buttonEl || ctx.btn || ctx.caller)) || (ev && (ev.currentTarget || ev.target)) || null;
    if (editor && caller && caller.nodeType === 1) return openDropdown(editor, caller);
    var m = createModel(2, 2, { headers: '' });
    insertTable(editor, modelToCanonicalBb(m), modelToWysiwygHtml(m));
  }

  var handlerObj = { id: ID, title: 'Таблица', onClick: aqrOpen, click: aqrOpen, action: aqrOpen, run: aqrOpen, init: function () {} };
  function handlerFn(inst, caller) { var ed = getSceditorInstanceFromCtx(inst || {}) || getSceditorInstanceFromCtx({}); if (ed) openDropdown(ed, caller); }

  function register() {
    window.afAqrBuiltinHandlers[ID] = handlerObj;
    window.afAqrBuiltinHandlers[CMD] = handlerObj;
    window.afAeBuiltinHandlers[ID] = handlerFn;
    window.afAeBuiltinHandlers[CMD] = handlerFn;
  }

  register();
  for (var i = 1; i <= 20; i++) setTimeout(register, i * 250);

  window.afAeTableDebugApi = {
    normalizeTableAttrs: normalizeAttrs,
    modelToCanonicalBbcode: modelToCanonicalBb,
    modelToWysiwygHtml: modelToWysiwygHtml,
    createTableModel: createModel,
    serializeTableDomToCanonicalBb: serializeTableDomToCanonicalBb
  };
})();

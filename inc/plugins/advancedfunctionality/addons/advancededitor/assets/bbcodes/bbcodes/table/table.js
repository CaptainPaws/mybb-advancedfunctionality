(function () {
  'use strict';

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  if (window.__afAeTablePackLoaded) return;
  window.__afAeTablePackLoaded = true;

  var ID = 'table';
  var CMD = 'af_table';

  function asText(x) { return String(x == null ? '' : x); }

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
    return '';
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

    if (attrs.border === '1') {
      var bw = attrs.borderwidth || '1px';
      var bc = attrs.bordercolor || '#888888';
      styles.push('border:' + bw + ' solid ' + bc);
    }

    return styles.join(';');
  }

  function attrsToDataAttrs(attrs) {
    var data = ['data-af-table="1"'];
    var keys = ['width', 'align', 'headers', 'bgcolor', 'textcolor', 'hbgcolor', 'htextcolor', 'border', 'bordercolor', 'borderwidth'];
    for (var i = 0; i < keys.length; i++) {
      var k = keys[i];
      var v = asText(attrs[k]).trim();
      if (!v && k !== 'border') continue;
      if (k === 'border' && !v) v = '1';
      data.push('data-af-' + k + '="' + v.replace(/"/g, '&quot;') + '"');
    }
    return data.join(' ');
  }

  function buildCellStyle(tag, tableAttrs, cellWidth, isHeaderByMode) {
    var styles = [];
    if (cellWidth) styles.push('width:' + cellWidth);

    if (tableAttrs.bgcolor) styles.push('background-color:' + tableAttrs.bgcolor);
    if (tableAttrs.textcolor) styles.push('color:' + tableAttrs.textcolor);

    var shouldHeaderStyle = (tag === 'th') || isHeaderByMode;
    if (shouldHeaderStyle) {
      if (tableAttrs.hbgcolor) styles.push('background-color:' + tableAttrs.hbgcolor);
      if (tableAttrs.htextcolor) styles.push('color:' + tableAttrs.htextcolor);
      styles.push('font-weight:700');
    }

    if (tableAttrs.border === '1') {
      styles.push('border:' + (tableAttrs.borderwidth || '1px') + ' solid ' + (tableAttrs.bordercolor || '#888888'));
    }
    styles.push('padding:6px 8px');

    return styles.join(';');
  }

  function parseAttrsFromDom(tableEl) {
    var attrs = {
      width: '', align: '', headers: '', bgcolor: '', textcolor: '', hbgcolor: '', htextcolor: '', border: '1', bordercolor: '', borderwidth: ''
    };

    if (!tableEl || tableEl.nodeType !== 1) return attrs;

    function pickData(name) {
      try { return asText(tableEl.getAttribute('data-af-' + name)).trim(); } catch (e) { return ''; }
    }

    var keys = ['width', 'align', 'headers', 'bgcolor', 'textcolor', 'hbgcolor', 'htextcolor', 'border', 'bordercolor', 'borderwidth'];
    for (var i = 0; i < keys.length; i++) {
      var key = keys[i];
      var val = pickData(key);
      if (val) attrs[key] = val;
    }

    try {
      var style = tableEl.style || {};
      if (!attrs.width && style.width) attrs.width = asText(style.width).trim();
      if (!attrs.align) {
        if (style.marginLeft === 'auto' && style.marginRight === 'auto') attrs.align = 'center';
        else if (style.marginLeft === 'auto') attrs.align = 'right';
        else if (style.marginRight === 'auto') attrs.align = 'left';
      }
      if (!attrs.borderwidth && style.borderWidth) attrs.borderwidth = asText(style.borderWidth).trim().toLowerCase();
      if (!attrs.bordercolor && style.borderColor) attrs.bordercolor = normColor(style.borderColor);
    } catch (e0) {}

    attrs = normalizeTableAttrs(attrs);
    return attrs;
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

  function buildBbcode(rows, cols, opts) {
    rows = Math.max(1, Math.min(50, rows | 0));
    cols = Math.max(1, Math.min(50, cols | 0));
    opts = opts || {};

    var attrs = normalizeTableAttrs(opts);
    attrs.width = normWidthToken(opts.width || attrs.width);

    var fill = !!opts.fill;
    var colWidths = Array.isArray(opts.colWidths) ? opts.colWidths : parseWidthList(opts.colWidths, cols);
    var open = tableAttrsToBbOpen(attrs);
    var close = '[/table]';

    function isHeaderCell(r, c) {
      if (attrs.headers === 'row') return r === 1;
      if (attrs.headers === 'col') return c === 1;
      if (attrs.headers === 'both') return (r === 1 || c === 1);
      return false;
    }

    var out = [open];

    for (var r = 1; r <= rows; r++) {
      out.push('[tr]');
      for (var c = 1; c <= cols; c++) {
        var th = isHeaderCell(r, c);
        var tag = th ? 'th' : 'td';
        var txt = '';

        if (fill && th) {
          if ((attrs.headers === 'row' || attrs.headers === 'both') && r === 1) txt = 'Header ' + c;
          if ((attrs.headers === 'col' || attrs.headers === 'both') && c === 1) txt = 'Row ' + r;
          if (attrs.headers === 'both' && r === 1 && c === 1) txt = ' ';
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

  function buildHtmlFromBbcode(rows, cols, opts) {
    rows = Math.max(1, Math.min(50, rows | 0));
    cols = Math.max(1, Math.min(50, cols | 0));
    opts = opts || {};

    var attrs = normalizeTableAttrs(opts);
    attrs.width = normWidthToken(opts.width || attrs.width);
    var colWidths = Array.isArray(opts.colWidths) ? opts.colWidths : parseWidthList(opts.colWidths, cols);

    function isHeaderCell(r, c) {
      if (attrs.headers === 'row') return r === 1;
      if (attrs.headers === 'col') return c === 1;
      if (attrs.headers === 'both') return (r === 1 || c === 1);
      return false;
    }

    var html = [];
    html.push('<table class="af-ae-table" ' + attrsToDataAttrs(attrs) + ' style="' + buildTableStyle(attrs) + '">');

    for (var r = 1; r <= rows; r++) {
      html.push('<tr>');
      for (var c = 1; c <= cols; c++) {
        var th = isHeaderCell(r, c);
        var tag = th ? 'th' : 'td';
        var cw = (colWidths && colWidths[c - 1]) ? colWidths[c - 1] : '';
        var cellStyle = buildCellStyle(tag, attrs, cw, isHeaderCell(r, c));
        html.push('<' + tag + (cellStyle ? ' style="' + cellStyle + '"' : '') + '><br></' + tag + '>');
      }
      html.push('</tr>');
    }

    html.push('</table><p><br></p>');
    return html.join('');
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
        'table[data-af-table="1"],table.af-ae-table{border-collapse:collapse;max-width:100%;margin:8px 0;}' +
        'table[data-af-table="1"] td,table[data-af-table="1"] th,table.af-ae-table td,table.af-ae-table th{padding:6px 8px;vertical-align:top;}' +
        'table[data-af-table="1"][data-af-border="1"] td,table[data-af-table="1"][data-af-border="1"] th,table.af-ae-table[data-af-border="1"] td,table.af-ae-table[data-af-border="1"] th{border:var(--af-bw,1px) solid var(--af-bc,#888);}' +
        'table[data-af-table="1"] th,table.af-ae-table th{font-weight:700;}';

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
        return html.replace(/<table\b([^>]*)>/gi, function (m, attrs) {
          if (!/data-af-table\s*=\s*"1"/i.test(attrs) && !/class\s*=\s*"[^"]*af-ae-table/i.test(attrs)) return m;
          var merged = attrs;
          if (!/data-af-table\s*=/.test(merged)) merged += ' data-af-table="1"';
          return '<table' + merged + '>';
        });
      });
    } catch (e) {}
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
    if (bb.__afAeTablePatched) return;
    bb.__afAeTablePatched = true;

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
          return '<table class="af-ae-table" ' + attrsToDataAttrs(a) + (style ? ' style="' + style + '"' : '') + '>' + (content || '') + '</table>';
        },
        format: function (el, content) {
          var a = parseAttrsFromDom(el);
          return tableAttrsToBbOpen(a) + (content || '') + '[/table]';
        },
        tags: {
          table: {
            format: function (el, content) {
              var a = parseAttrsFromDom(el);
              return tableAttrsToBbOpen(a) + (content || '') + '[/table]';
            }
          }
        }
      });
    } catch (e0) {}

    try {
      bb.set('tr', {
        isBlock: true,
        html: '<tr>{0}</tr>',
        format: '[tr]{0}[/tr]',
        tags: { tr: { format: function (_el, content) { return '[tr]' + (content || '') + '[/tr]'; } } }
      });
    } catch (e1) {}

    function setCell(tag) {
      try {
        bb.set(tag, {
          isInline: false,
          html: function (_token, attrs, content) {
            var w = normWidthToken(attrs && attrs.width);
            var style = '';
            if (w) style += 'width:' + w + ';';
            return '<' + tag + (style ? ' style="' + style + '"' : '') + '>' + (content || '') + '</' + tag + '>';
          },
          format: function (el, content) {
            var width = '';
            try {
              width = normWidthToken(el.getAttribute('data-af-width') || (el.style && el.style.width) || '');
            } catch (e2) { width = ''; }
            return '[' + tag + (width ? ' width=' + width : '') + ']' + (content || '') + '[/' + tag + ']';
          },
          tags: {}
        });
      } catch (e3) {}
    }

    setCell('td');
    setCell('th');
  }

  function insertTableToEditor(editor, bb, html) {
    bb = asText(bb);
    html = asText(html);

    try {
      if (editor && typeof editor.insertText === 'function') {
        afAeEnsureMybbTableBbcode(editor);
        ensureTableCss(editor);
        bindTableToSourceNormalization(editor);
        if (isSourceMode(editor)) editor.insertText(bb, '');
        else editor.insertText(html || bb, '');
        if (typeof editor.focus === 'function') editor.focus();
        return true;
      }
    } catch (e0) {}

    var ta = getTextareaFromCtx({ sceditor: editor });
    return insertAtCursor(ta, bb);
  }

  function openFloatingEditorForTable(inst, table) {
    if (!inst || !table || table.nodeType !== 1) return;
    try {
      var body = inst.getBody();
      if (!body || !body.ownerDocument) return;
      var doc = body.ownerDocument;
      var panel = doc.getElementById('af-ae-table-floating');
      if (!panel) {
        panel = doc.createElement('div');
        panel.id = 'af-ae-table-floating';
        panel.style.cssText = 'position:fixed;z-index:99999;background:#1f1f1f;border:1px solid rgba(255,255,255,.16);border-radius:8px;padding:6px;display:flex;gap:4px;';
        panel.innerHTML = '<button data-a="row-above">+R↑</button><button data-a="row-below">+R↓</button><button data-a="row-del">-R</button><button data-a="col-left">+C←</button><button data-a="col-right">+C→</button><button data-a="col-del">-C</button>';
        doc.body.appendChild(panel);

        panel.addEventListener('click', function (ev) {
          var btn = ev.target && ev.target.closest ? ev.target.closest('button[data-a]') : null;
          if (!btn || !inst.__afAeActiveTable) return;
          var t = inst.__afAeActiveTable;
          var sel = doc.getSelection ? doc.getSelection() : null;
          var node = sel && sel.anchorNode ? sel.anchorNode : null;
          var cell = node && node.nodeType === 1 ? node.closest('td,th') : (node && node.parentElement ? node.parentElement.closest('td,th') : null);
          if (!cell) cell = t.querySelector('td,th');
          if (!cell) return;
          var row = cell.parentElement;
          var rowIndex = Array.prototype.indexOf.call(t.rows, row);
          var colIndex = Array.prototype.indexOf.call(row.cells, cell);

          function cloneCell(base) {
            var n = base.cloneNode(false);
            n.innerHTML = '<br>';
            return n;
          }

          var act = btn.getAttribute('data-a');
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

          inst.__afAeActiveTable = t;
        }, false);
      }

      var rect = table.getBoundingClientRect();
      panel.style.display = 'flex';
      panel.style.top = Math.max(8, rect.top - 40) + 'px';
      panel.style.left = Math.max(8, rect.left) + 'px';
      inst.__afAeActiveTable = table;
    } catch (e) {}
  }

  function bindFloatingEditor(inst) {
    if (!inst || inst.__afAeTableFloatingBound) return;
    inst.__afAeTableFloatingBound = true;

    try {
      if (typeof inst.bind !== 'function' || typeof inst.getBody !== 'function') return;
      var body = inst.getBody();
      if (!body) return;

      body.addEventListener('click', function (ev) {
        var el = ev.target && ev.target.closest ? ev.target.closest('table[data-af-table="1"],table.af-ae-table') : null;
        if (el) openFloatingEditorForTable(inst, el);
      }, false);

      inst.bind('blur', function () {
        try {
          var doc = body.ownerDocument;
          var panel = doc && doc.getElementById('af-ae-table-floating');
          if (panel) panel.style.display = 'none';
        } catch (e0) {}
      });
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
        width: width, colWidths: colWidthsRaw, align: align, headers: headers, fill: fill,
        cellBg: cellBg, textColor: textColor, headBg: headBg, headText: headText,
        borderOn: borderOn, borderColor: borderColor, borderWidth: borderWidth
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
      pair[0].addEventListener('change', function () { syncColorEnable(pair[0], pair[1]); }, false);
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

    if (btnInsert) btnInsert.addEventListener('click', function (ev) {
      ev.preventDefault();
      insertNow();
    }, false);

    return wrap;
  }

  function openSceditorDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;
    try { editor.closeDropDown(true); } catch (e0) {}

    afAeEnsureMybbTableBbcode(editor);
    ensureTableCss(editor);
    bindTableToSourceNormalization(editor);
    bindFloatingEditor(editor);

    var wrap = makeDropdown(editor, caller);
    editor.createDropDown(caller, 'sceditor-table-picker', wrap);
    return true;
  }

  function patchSceditorTableCommand() {
    if (!hasSceditor()) return false;

    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.command) return false;

    $.sceditor.command.set(CMD, {
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

  function tryPatchInstances() {
    if (!hasSceditor() || !window.jQuery) return false;
    var $ = window.jQuery;

    try {
      var tas = document.querySelectorAll('textarea');
      for (var i = 0; i < tas.length; i++) {
        var inst = null;
        try { inst = $(tas[i]).sceditor('instance'); } catch (e0) { inst = null; }
        if (!inst) continue;

        try { afAeEnsureMybbTableBbcode(inst); } catch (e1) {}
        try { ensureTableCss(inst); } catch (e2) {}
        try { bindTableToSourceNormalization(inst); } catch (e3) {}
        try { bindFloatingEditor(inst); } catch (e4) {}
      }
    } catch (e) {}

    return true;
  }

  waitAnd(patchSceditorTableCommand, 150);
  var patchTries = 0;
  (function patchTick() {
    patchTries++;
    tryPatchInstances();
    if (patchTries < 60) setTimeout(patchTick, 150);
  })();

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

    if (caller && caller.nodeType === 1) openSceditorDropdown(editor, caller);
    else {
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
  for (var i = 1; i <= 20; i++) setTimeout(registerHandlers, i * 250);

  window.af_ae_table_exec = function (editor, def, caller) {
    if (!openSceditorDropdown(editor, caller)) {
      var bb = buildBbcode(2, 2, { headers: 'none' });
      var html = buildHtmlFromBbcode(2, 2, { headers: 'none' });
      insertTableToEditor(editor, bb, html);
    }
  };

})();

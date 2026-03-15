(function (window, document) {
  'use strict';

  if (window.__afAeTablesPackLoaded) return;
  window.__afAeTablesPackLoaded = true;

  if (!window.afAeBuiltinHandlers) {
    window.afAeBuiltinHandlers = Object.create(null);
  }

  var COMMAND_ID = 'af_tables';
  var MAX_ROWS = 20;
  var MAX_COLS = 12;
  var TOOLBAR_ID = 'af-ae-table-toolbar';
  var INSTANCE_SCAN_DELAY = 1200;

  var ATTR_ORDER = [
    'width',
    'align',
    'headers',
    'cellwidth',
    'bgcolor',
    'textcolor',
    'hbgcolor',
    'htextcolor',
    'border',
    'bordercolor',
    'borderwidth'
  ];

  var toolbarState = {
    instance: null,
    container: null,
    frame: null,
    table: null,
    cell: null,
    toolbar: null
  };
  var builderState = {
    isOpen: false,
    root: null,
    dropdown: null,
    editor: null,
    originalCloseDropDown: null,
    allowProgrammaticClose: false
  };
  var ICONS = {
    rowBefore: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 3h12v2H4V3zm0 4h12v6H4V7zm6-4h2v8h3l-4 4-4-4h3V3zM4 15h12v2H4v-2z"/></svg>',
    rowAfter: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 3h12v2H4V3zm0 5h12v6H4V8zm6 0h2v8h3l-4 4-4-4h3V8z"/></svg>',
    rowDelete: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 3h12v2H4V3zm1 5h10v4H5V8zm-1 7h12v2H4v-2zm3.4-4.6 1.4-1.4L10 10.6l1.2-1.2 1.4 1.4-1.2 1.2 1.2 1.2-1.4 1.4L10 13.4l-1.2 1.2-1.4-1.4 1.2-1.2-1.2-1.2z"/></svg>',
    colBefore: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 4h2v12H3V4zm4 0h6v12H7V4zm10 6-4 4v-3H7V9h6V6l4 4z"/></svg>',
    colAfter: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M15 4h2v12h-2V4zM7 4h6v12H7V4zM3 10l4-4v3h6v2H7v3l-4-4z"/></svg>',
    colDelete: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 4h2v12H3V4zm12 0h2v12h-2V4zM8 6.4 9.4 5 11 6.6 12.6 5 14 6.4 12.4 8 14 9.6 12.6 11 11 9.4 9.4 11 8 9.6 9.6 8 8 6.4z"/></svg>',
    moveUp: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3l4 5h-3v6H9V8H6l4-5zm-6 12h12v2H4v-2z"/></svg>',
    moveDown: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M9 6V3h2v3h3l-4 5-4-5h3zm-5 9h12v2H4v-2z"/></svg>',
    moveLeft: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7 10l5-4v3h5v2h-5v3l-5-4zM3 4h2v12H3V4z"/></svg>',
    moveRight: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M13 10l-5 4v-3H3V9h5V6l5 4zm2-6h2v12h-2V4z"/></svg>',
    trash: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7 3h6l1 2h3v2H3V5h3l1-2zm-1 5h2v7H6V8zm4 0h2v7h-2V8zm4 0h2v7h-2V8z"/></svg>',

    table: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 4h14v12H3V4zm1 1v3h4V5H4zm5 0v3h3V5H9zm4 0v3h3V5h-3zM4 9v3h4V9H4zm5 0v3h3V9H9zm4 0v3h3V9h-3zM4 13v2h4v-2H4zm5 0v2h3v-2H9zm4 0v2h3v-2h-3z"/></svg>',
    rows: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 4h14v3H3V4zm0 5h14v3H3V9zm0 5h14v2H3v-2z"/></svg>',
    cols: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 4h3v12H3V4zm5 0h4v12H8V4zm6 0h3v12h-3V4z"/></svg>',
    width: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 10l4-4v3h6V6l4 4-4 4v-3H7v3l-4-4z"/></svg>',
    cellwidth: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 4h14v12H3V4zm1 1v10h4V5H4zm5 0v10h2V5H9zm3 0v10h4V5h-4z"/></svg>',
    align: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 4h12v2H4V4zm2 4h8v2H6V8zm-2 4h12v2H4v-2zm2 4h8v2H6v-2z"/></svg>',
    headers: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 4h14v12H3V4zm1 1v3h12V5H4zm0 4v6h4V9H4zm5 0v6h7V9H9z"/></svg>',
    borderwidth: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 3h14v14H3V3zm2 2v10h10V5H5z"/></svg>',
    fill: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3l2.4 4.8L17 8.5l-3.5 3.4.8 4.8L10 14.4 5.7 16.7l.8-4.8L3 8.5l4.6-.7L10 3z"/></svg>',

    cellBg: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3c2.9 3.4 5 5.8 5 8.1A5 5 0 1 1 5 11.1C5 8.8 7.1 6.4 10 3z"/></svg>',
    cellText: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 5h12v2H11v8H9V7H4V5z"/></svg>',
    headBg: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 4h14v12H3V4zm1 1v3h12V5H4zm0 4v6h12V9H4z"/></svg>',
    headText: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 5h5l1 2 1-2h5v2h-2.2l-2.6 8h-2.4L6.2 7H4V5zm5.1 6h1.8L10 8.8 9.1 11z"/></svg>',
    borderColor: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 3h10v2l-6.8 6.8a2 2 0 1 1-2.8-2.8L12 2.5 13.5 4 7 10.5a.5.5 0 1 0 .7.7L14 5V3H5zM4 15h12v2H4v-2z"/></svg>',

    cancel: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5.4 4 10 8.6 14.6 4 16 5.4 11.4 10 16 14.6 14.6 16 10 11.4 5.4 16 4 14.6 8.6 10 4 5.4 5.4 4z"/></svg>',
    insert: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M9 3h2v6h6v2h-6v6H9v-6H3V9h6V3z"/></svg>'
  };
  var TABLE_COLOR_SWATCHES = [
    '#000000', '#434343', '#666666', '#999999', '#B7B7B7', '#CCCCCC', '#D9D9D9', '#EFEFEF', '#F3F3F3', '#FFFFFF',
    '#980000', '#FF0000', '#FF9900', '#FFFF00', '#00FF00', '#00FFFF', '#4A86E8', '#0000FF', '#9900FF', '#FF00FF',
    '#E6B8AF', '#F4CCCC', '#FCE5CD', '#FFF2CC', '#D9EAD3', '#D0E0E3', '#C9DAF8', '#CFE2F3', '#D9D2E9', '#EAD1DC',
    '#DD7E6B', '#EA9999', '#F9CB9C', '#FFE599', '#B6D7A8', '#A2C4C9', '#A4C2F4', '#9FC5E8', '#B4A7D6', '#D5A6BD',
    '#CC4125', '#E06666', '#F6B26B', '#FFD966', '#93C47D', '#76A5AF', '#6D9EEB', '#6FA8DC', '#8E7CC3', '#C27BA0',
    '#A61C00', '#CC0000', '#E69138', '#F1C232', '#6AA84F', '#45818E', '#3C78D8', '#3D85C6', '#674EA7', '#A64D79',
    '#85200C', '#990000', '#B45F06', '#BF9000', '#38761D', '#134F5C', '#1155CC', '#0B5394', '#351C75', '#741B47',
    '#5B0F00', '#660000', '#783F04', '#7F6000', '#274E13', '#0C343D', '#1C4587', '#073763', '#20124D', '#4C1130'
  ];
  function getSceditorRoot() {
    if (window.sceditor) return window.sceditor;
    if (window.jQuery && window.jQuery.sceditor) return window.jQuery.sceditor;
    return null;
  }

  function trim(value) {
    return String(value == null ? '' : value).trim();
  }

  function escHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function clampInt(value, min, max, fallback) {
    var n = parseInt(value, 10);
    if (isNaN(n)) return fallback;
    if (n < min) return min;
    if (n > max) return max;
    return n;
  }

  function normalizeAlign(value) {
    value = trim(value).toLowerCase();
    if (value === 'left' || value === 'right' || value === 'center') {
      return value;
    }
    return 'center';
  }

  function normalizeOptionalAlign(value) {
    value = trim(value).toLowerCase();
    if (!value) return '';
    return normalizeAlign(value);
  }

  function normalizeHeaders(value) {
    value = trim(value).toLowerCase();
    if (value === 'column') value = 'col';
    if (value === 'both') return 'both';
    if (value === 'col') return 'col';
    if (value === 'row') return 'row';
    return 'none';
  }

  function normalizeSize(value, fallback, allowBlank) {
    value = trim(value);

    if (!value) {
      return allowBlank ? '' : fallback;
    }

    if (/^\d+$/.test(value)) {
      return value + 'px';
    }

    if (/^\d+(?:\.\d+)?(?:px|%|em|rem|vw|vh)$/i.test(value)) {
      return value;
    }

    return fallback;
  }

  function normalizeWidth(value, fallback) {
    value = trim(value);

    if (!value) {
      return fallback;
    }

    if (/^\d+$/.test(value)) {
      return value + '%';
    }

    if (/^\d+(?:\.\d+)?(?:%|px|em|rem|vw)$/i.test(value)) {
      return value;
    }

    return fallback;
  }

  function clampColorChannel(value) {
    value = parseInt(value, 10);
    if (isNaN(value)) value = 0;
    if (value < 0) value = 0;
    if (value > 255) value = 255;
    return value;
  }

  function clampColorAlpha(value) {
    value = parseFloat(value);
    if (isNaN(value)) value = 1;
    if (value < 0) value = 0;
    if (value > 1) value = 1;
    return value;
  }

  function toColorHex(value) {
    var hex = clampColorChannel(value).toString(16).toUpperCase();
    return hex.length < 2 ? '0' + hex : hex;
  }

  function alphaToColorHex(value) {
    var hex = Math.round(clampColorAlpha(value) * 255).toString(16).toUpperCase();
    return hex.length < 2 ? '0' + hex : hex;
  }

  function normalizeColor(value) {
    var m;
    var s;
    var r;
    var g;
    var b;
    var a;
    var hex;

    value = trim(value);
    if (!value) return '';

    m = value.match(/^#([0-9a-f]{3})$/i);
    if (m) {
      s = m[1].toUpperCase();
      return '#' + s.charAt(0) + s.charAt(0) + s.charAt(1) + s.charAt(1) + s.charAt(2) + s.charAt(2);
    }

    m = value.match(/^#([0-9a-f]{4})$/i);
    if (m) {
      s = m[1].toUpperCase();
      hex = '#' +
        s.charAt(0) + s.charAt(0) +
        s.charAt(1) + s.charAt(1) +
        s.charAt(2) + s.charAt(2) +
        s.charAt(3) + s.charAt(3);

      if (hex.substr(7, 2) === 'FF') {
        return hex.substr(0, 7);
      }

      return hex;
    }

    m = value.match(/^#([0-9a-f]{6})$/i);
    if (m) {
      return '#' + m[1].toUpperCase();
    }

    m = value.match(/^#([0-9a-f]{8})$/i);
    if (m) {
      hex = '#' + m[1].toUpperCase();

      if (hex.substr(7, 2) === 'FF') {
        return hex.substr(0, 7);
      }

      return hex;
    }

    m = value.match(/^rgb\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*\)$/i);
    if (m) {
      r = clampColorChannel(m[1]);
      g = clampColorChannel(m[2]);
      b = clampColorChannel(m[3]);
      return '#' + toColorHex(r) + toColorHex(g) + toColorHex(b);
    }

    m = value.match(/^rgba\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]*\.?[0-9]+)\s*\)$/i);
    if (m) {
      r = clampColorChannel(m[1]);
      g = clampColorChannel(m[2]);
      b = clampColorChannel(m[3]);
      a = clampColorAlpha(m[4]);

      hex = '#' + toColorHex(r) + toColorHex(g) + toColorHex(b);
      if (alphaToColorHex(a) === 'FF') {
        return hex;
      }

      return hex + alphaToColorHex(a);
    }

    return '';
  }

  function isBorderEnabled(value) {
    value = trim(value).toLowerCase();
    return !(value === '' || value === '0' || value === 'false' || value === 'no');
  }

  function quoteAttr(value) {
    value = trim(value);
    if (!value) return '';
    if (/[\s=\]]/.test(value)) {
      return '"' + value.replace(/"/g, '&quot;') + '"';
    }
    return value;
  }

  function normalizeTableAttrs(raw) {
    raw = raw || {};

    var borderwidth = normalizeSize(raw.borderwidth, '1px', false);
    var border = trim(raw.border || '1');

    if (borderwidth === '0px' || borderwidth === '0') {
      border = '0';
    }

    return {
      width: normalizeWidth(raw.width, '100%'),
      align: normalizeAlign(raw.align),
      headers: normalizeHeaders(raw.headers || raw.header || raw.heads),
      cellwidth: normalizeSize(raw.cellwidth || raw.rowwidth, '', true),
      bgcolor: normalizeColor(raw.bgcolor),
      textcolor: normalizeColor(raw.textcolor),
      hbgcolor: normalizeColor(raw.hbgcolor),
      htextcolor: normalizeColor(raw.htextcolor),
      border: border,
      bordercolor: normalizeColor(raw.bordercolor || '#5f6670'),
      borderwidth: borderwidth
    };
  }

  function stringifyAttrs(attrs) {
    var parts = [];
    var key, value;

    for (var i = 0; i < ATTR_ORDER.length; i += 1) {
      key = ATTR_ORDER[i];
      value = trim(attrs[key]);

      if (!value) continue;

      if (key === 'width' && value === '100%') continue;
      if (key === 'headers' && value === 'none') continue;
      if (key === 'border' && value === '1') continue;
      if (key === 'bordercolor' && value === '#5f6670') continue;
      if (key === 'borderwidth' && value === '1px') continue;

      parts.push(key + '=' + quoteAttr(value));
    }

    return parts.length ? ' ' + parts.join(' ') : '';
  }

  function tableMarginStyles(align) {
    if (align === 'left') {
      return ['margin-left:0', 'margin-right:auto'];
    }

    if (align === 'right') {
      return ['margin-left:auto', 'margin-right:0'];
    }

    return ['margin-left:auto', 'margin-right:auto'];
  }

  function buildTableStyle(attrs) {
    var styles = [
      'border-collapse:collapse',
      'table-layout:fixed',
      'max-width:100%'
    ];

    if (attrs.width) {
      styles.push('width:' + attrs.width);
    }

    styles = styles.concat(tableMarginStyles(attrs.align));
    return styles.join(';');
  }

  function readCellSourceAttrs(cell) {
    return {
      width: trim(cell.getAttribute('data-af-source-width')),
      align: trim(cell.getAttribute('data-af-source-align')),
      bgcolor: trim(cell.getAttribute('data-af-source-bgcolor')),
      textcolor: trim(cell.getAttribute('data-af-source-textcolor'))
    };
  }

  function buildCellStyle(tagName, cellAttrs, tableAttrs) {
    var styles = [
      'padding:8px 10px',
      'vertical-align:top'
    ];

    var width = trim(cellAttrs.width || '');
    var align = normalizeOptionalAlign(cellAttrs.align || '');
    var bgcolor = trim(cellAttrs.bgcolor || '');
    var textcolor = trim(cellAttrs.textcolor || '');

    if (!width && tableAttrs.cellwidth) {
      width = tableAttrs.cellwidth;
    }

    if (width) {
      styles.push('width:' + width);
    }

    if (align) {
      styles.push('text-align:' + align);
    }

    if (!bgcolor) {
      bgcolor = tagName === 'th' ? tableAttrs.hbgcolor : tableAttrs.bgcolor;
    }

    if (!textcolor) {
      textcolor = tagName === 'th' ? tableAttrs.htextcolor : tableAttrs.textcolor;
    }

    if (bgcolor) {
      styles.push('background:' + bgcolor);
    }

    if (textcolor) {
      styles.push('color:' + textcolor);
    }

    if (isBorderEnabled(tableAttrs.border)) {
      styles.push('border:' + tableAttrs.borderwidth + ' solid ' + tableAttrs.bordercolor);
    } else {
      styles.push('border:none');
    }

    return styles.join(';');
  }

  function mergeStyles(existing, incoming) {
    existing = trim(existing).replace(/;+\s*$/, '');
    incoming = trim(incoming).replace(/;+\s*$/, '');

    if (!existing) return incoming;
    if (!incoming) return existing;

    return existing + ';' + incoming;
  }

  function isNodeMeaningful(node) {
    if (!node) return false;

    if (node.nodeType === 3) {
      return trim(String(node.nodeValue || '').replace(/\u00a0/g, '')) !== '';
    }

    if (node.nodeType !== 1) {
      return false;
    }

    var tag = node.tagName.toLowerCase();

    if (tag === 'br') return false;
    if (node.hasAttribute && node.hasAttribute('data-af-cell-filler')) return false;

    if (/^(img|video|audio|iframe|object|embed|table|ul|ol|blockquote|hr)$/i.test(tag)) {
      return true;
    }

    for (var i = 0; i < node.childNodes.length; i += 1) {
      if (isNodeMeaningful(node.childNodes[i])) {
        return true;
      }
    }

    return false;
  }

  function getCellFillerHtml() {
    return '<span data-af-cell-filler="1"><br></span>';
  }

  function ensureEditableCellContent(cell) {
    if (!cell) return;
    if (isNodeMeaningful(cell)) return;
    cell.innerHTML = getCellFillerHtml();
  }

    function isEditorSpacerNode(node) {
    if (!node) return false;

    if (node.nodeType === 3) {
        return trim(String(node.nodeValue || '').replace(/\u00a0/g, '').replace(/\u200b/g, '')) === '';
    }

    if (node.nodeType !== 1) {
        return false;
    }

    var tag = node.tagName.toLowerCase();

    if (tag === 'table') return false;
    if (tag === 'br') return true;
    if (/^(td|th|tr|tbody|thead|tfoot)$/i.test(tag)) return false;

    if (node.hasAttribute && node.hasAttribute('data-af-cell-filler')) {
        return true;
    }

    return !isNodeMeaningful(node);
    }

    function removeSpacerSiblingsAroundTable(table) {
    if (!table || !table.parentNode) return false;

    var changed = false;
    var prev = table.previousSibling;
    var next;
    var removable;

    while (prev && isEditorSpacerNode(prev)) {
        removable = prev;
        prev = prev.previousSibling;
        if (removable.parentNode) {
        removable.parentNode.removeChild(removable);
        changed = true;
        }
    }

    next = table.nextSibling;

    while (next && isEditorSpacerNode(next)) {
        removable = next;
        next = next.nextSibling;
        if (removable.parentNode) {
        removable.parentNode.removeChild(removable);
        changed = true;
        }
    }

    return changed;
    }

    function cleanupEditorBodyTableSpacers(instance) {
    if (!instance || typeof instance.getBody !== 'function') return false;

    var body = instance.getBody();
    if (!body) return false;

    var tables = body.querySelectorAll('table[data-af-bb-table="1"]');
    if (!tables.length) return false;

    var changed = false;

    for (var i = 0; i < tables.length; i += 1) {
        if (removeSpacerSiblingsAroundTable(tables[i])) {
        changed = true;
        }
    }

    return changed;
    }

  function normalizeCellHtmlForBbcode(content) {
    var wrapper = document.createElement('div');
    wrapper.innerHTML = String(content == null ? '' : content);

    var fillers = wrapper.querySelectorAll('[data-af-cell-filler]');
    for (var i = 0; i < fillers.length; i += 1) {
      fillers[i].parentNode.removeChild(fillers[i]);
    }

    if (!isNodeMeaningful(wrapper)) {
      return '';
    }

    return wrapper.innerHTML
      .replace(/^\s+/, '')
      .replace(/\s+$/, '');
  }

  function normalizeBlockBbcodeContent(content) {
    return String(content == null ? '' : content)
      .replace(/\r\n?/g, '\n')
      .replace(/\n{3,}/g, '\n\n')
      .replace(/^\s+/, '')
      .replace(/\s+$/, '');
  }

  function decorateTableContent(content, tableAttrs) {
    var tbody = document.createElement('tbody');
    tbody.innerHTML = content || '';

    var cells = tbody.querySelectorAll('td, th');
    var cell, sourceAttrs, tagName, mergedStyle;

    for (var i = 0; i < cells.length; i += 1) {
      cell = cells[i];
      tagName = cell.tagName.toLowerCase();
      sourceAttrs = readCellSourceAttrs(cell);

      cell.classList.add('af-bb-cell');
      cell.classList.add('af-bb-cell--' + tagName);

      mergedStyle = mergeStyles(
        cell.getAttribute('style'),
        buildCellStyle(tagName, sourceAttrs, tableAttrs)
      );

      if (mergedStyle) {
        cell.setAttribute('style', mergedStyle);
      }

      ensureEditableCellContent(cell);
    }

    return tbody.innerHTML;
  }

  function buildTableHtml(attrs, content) {
    var normalized = normalizeTableAttrs(attrs);
    var explicit = attrs || {};
    var pieces = [
      'class="af-bb-table"',
      'data-af-bb-table="1"',
      'data-af-width="' + escHtml(normalized.width) + '"',
      'data-af-align="' + escHtml(normalized.align) + '"',
      'data-af-headers="' + escHtml(normalized.headers) + '"',
      'style="' + escHtml(buildTableStyle(normalized)) + '"'
    ];

    if (normalized.cellwidth) {
      pieces.push('data-af-cellwidth="' + escHtml(normalized.cellwidth) + '"');
    }

    if (trim(explicit.bgcolor)) {
      pieces.push('data-af-source-bgcolor="' + escHtml(explicit.bgcolor) + '"');
    }

    if (trim(explicit.textcolor)) {
      pieces.push('data-af-source-textcolor="' + escHtml(explicit.textcolor) + '"');
    }

    if (trim(explicit.hbgcolor)) {
      pieces.push('data-af-source-hbgcolor="' + escHtml(explicit.hbgcolor) + '"');
    }

    if (trim(explicit.htextcolor)) {
      pieces.push('data-af-source-htextcolor="' + escHtml(explicit.htextcolor) + '"');
    }

    if (trim(explicit.border)) {
      pieces.push('data-af-source-border="' + escHtml(explicit.border) + '"');
    }

    if (trim(explicit.bordercolor)) {
      pieces.push('data-af-source-bordercolor="' + escHtml(explicit.bordercolor) + '"');
    }

    if (trim(explicit.borderwidth)) {
      pieces.push('data-af-source-borderwidth="' + escHtml(explicit.borderwidth) + '"');
    }

    return '<table ' + pieces.join(' ') + '><tbody>' + decorateTableContent(content, normalized) + '</tbody></table>';
  }

  function buildCellHtml(tagName, attrs, content) {
    attrs = attrs || {};

    var pieces = ['data-af-bb-cell="1"'];
    var width = normalizeSize(attrs.width, '', true);
    var align = normalizeOptionalAlign(attrs.align || '');
    var colspan = clampInt(attrs.colspan, 1, 99, 1);
    var rowspan = clampInt(attrs.rowspan, 1, 99, 1);
    var inner = trim(content) ? content : getCellFillerHtml();

    if (width) {
      pieces.push('data-af-source-width="' + escHtml(width) + '"');
    }

    if (align) {
      pieces.push('data-af-source-align="' + escHtml(align) + '"');
    }

    if (trim(attrs.bgcolor)) {
      pieces.push('data-af-source-bgcolor="' + escHtml(attrs.bgcolor) + '"');
    }

    if (trim(attrs.textcolor)) {
      pieces.push('data-af-source-textcolor="' + escHtml(attrs.textcolor) + '"');
    }

    if (trim(attrs.colspan)) {
      pieces.push('colspan="' + colspan + '"');
    }

    if (trim(attrs.rowspan)) {
      pieces.push('rowspan="' + rowspan + '"');
    }

    return '<' + tagName + ' ' + pieces.join(' ') + '>' + inner + '</' + tagName + '>';
  }

  function inferAlignFromElement(table) {
    var explicit = trim(table.getAttribute('data-af-align'));
    if (explicit) return normalizeAlign(explicit);

    var style = table.style || {};
    var left = trim(style.marginLeft);
    var right = trim(style.marginRight);

    if (left === 'auto' && right === 'auto') return 'center';
    if (left === 'auto') return 'right';
    return 'left';
  }

  function readTableAttrsFromElement(table) {
    var attrs = {
      width: trim(table.getAttribute('data-af-width') || table.style.width || '100%'),
      align: inferAlignFromElement(table),
      headers: trim(table.getAttribute('data-af-headers') || 'none'),
      cellwidth: trim(table.getAttribute('data-af-cellwidth') || '')
    };

    [
      'bgcolor',
      'textcolor',
      'hbgcolor',
      'htextcolor',
      'border',
      'bordercolor',
      'borderwidth'
    ].forEach(function (key) {
      var value = trim(table.getAttribute('data-af-source-' + key));
      if (value) {
        attrs[key] = value;
      }
    });

    return normalizeTableAttrs(attrs);
  }

  function formatTableElement(element, content) {
    var attrs = readTableAttrsFromElement(element);
    var inner = normalizeBlockBbcodeContent(content);

    if (!inner) {
      return '[table' + stringifyAttrs(attrs) + '][/table]';
    }

    return '[table' + stringifyAttrs(attrs) + ']\n' + inner + '\n[/table]';
  }

  function formatRowElement(element, content) {
    return '[tr]' + normalizeBlockBbcodeContent(content) + '[/tr]';
  }

  function formatCellElement(tagName, element, content) {
    var attrs = {};
    var width = trim(element.getAttribute('data-af-source-width') || element.getAttribute('width') || element.style.width);
    var align = trim(element.getAttribute('data-af-source-align') || element.getAttribute('align') || element.style.textAlign);
    var colspan = trim(element.getAttribute('colspan'));
    var rowspan = trim(element.getAttribute('rowspan'));
    var bgcolor = trim(element.getAttribute('data-af-source-bgcolor'));
    var textcolor = trim(element.getAttribute('data-af-source-textcolor'));
    var normalizedContent = normalizeCellHtmlForBbcode(content);

    if (width) attrs.width = normalizeSize(width, '', true);
    if (align) attrs.align = normalizeOptionalAlign(align);
    if (colspan) attrs.colspan = colspan;
    if (rowspan) attrs.rowspan = rowspan;
    if (bgcolor) attrs.bgcolor = bgcolor;
    if (textcolor) attrs.textcolor = textcolor;

    return '[' + tagName + stringifyAttrs(attrs) + ']' + normalizedContent + '[/' + tagName + ']';
  }

  function registerBbcodeFormats() {
    var sc = getSceditorRoot();

    if (!sc || !sc.formats || !sc.formats.bbcode || typeof sc.formats.bbcode.set !== 'function') {
      return;
    }

    sc.formats.bbcode.set('table', {
      tags: { table: null },
      isInline: false,
      allowedChildren: ['tr'],
      allowsEmpty: true,
      breakBefore: true,
      breakAfter: true,
      skipLastLineBreak: true,
      format: function (element, content) {
        return formatTableElement(element, content);
      },
      html: function (token, attrs, content) {
        return buildTableHtml(attrs, content);
      }
    });

    sc.formats.bbcode.set('tr', {
      tags: { tr: null },
      isInline: false,
      allowedChildren: ['td', 'th'],
      allowsEmpty: true,
      breakBefore: true,
      breakAfter: true,
      skipLastLineBreak: true,
      format: function (element, content) {
        return formatRowElement(element, content);
      },
      html: function (token, attrs, content) {
        return '<tr>' + content + '</tr>';
      }
    });

    sc.formats.bbcode.set('td', {
      tags: { td: null },
      isInline: false,
      allowsEmpty: true,
      skipLastLineBreak: true,
      format: function (element, content) {
        return formatCellElement('td', element, content);
      },
      html: function (token, attrs, content) {
        return buildCellHtml('td', attrs, content);
      }
    });

    sc.formats.bbcode.set('th', {
      tags: { th: null },
      isInline: false,
      allowsEmpty: true,
      skipLastLineBreak: true,
      format: function (element, content) {
        return formatCellElement('th', element, content);
      },
      html: function (token, attrs, content) {
        return buildCellHtml('th', attrs, content);
      }
    });
  }

  function resolveCellTag(model, rowIndex, colIndex) {
    var isFirstRow = rowIndex === 1;
    var isFirstCol = colIndex === 1;

    if (model.headers === 'both' && (isFirstRow || isFirstCol)) return 'th';
    if (model.headers === 'col' && isFirstRow) return 'th';
    if (model.headers === 'row' && isFirstCol) return 'th';

    return 'td';
  }

  function buildPlaceholderText(model, rowIndex, colIndex, tagName) {
    if (!model.fill) return '';

    if (tagName === 'th') {
      if (rowIndex === 1) return 'Заголовок ' + colIndex;
      if (colIndex === 1) return 'Строка ' + rowIndex;
      return 'Заголовок ' + rowIndex + '.' + colIndex;
    }

    return 'Ячейка ' + rowIndex + '.' + colIndex;
  }

  function buildModelFromForm(root) {
    var borderwidth = normalizeSize(root.querySelector('[name="borderwidth"]').value, '1px', false);

    return {
      rows: clampInt(root.querySelector('[name="rows"]').value, 1, MAX_ROWS, 2),
      cols: clampInt(root.querySelector('[name="cols"]').value, 1, MAX_COLS, 2),
      width: normalizeWidth(root.querySelector('[name="width"]').value, '100%'),
      cellwidth: normalizeSize(root.querySelector('[name="cellwidth"]').value, '', true),
      align: normalizeAlign(root.querySelector('[name="align"]').value),
      headers: normalizeHeaders(root.querySelector('[name="headers"]').value),
      fill: !!root.querySelector('[name="fill"]').checked,
      bgcolor: normalizeColor(root.querySelector('[name="bgcolor"]').value),
      textcolor: normalizeColor(root.querySelector('[name="textcolor"]').value),
      hbgcolor: normalizeColor(root.querySelector('[name="hbgcolor"]').value),
      htextcolor: normalizeColor(root.querySelector('[name="htextcolor"]').value),
      bordercolor: normalizeColor(root.querySelector('[name="bordercolor"]').value || '#5f6670'),
      borderwidth: borderwidth,
      border: borderwidth === '0px' ? '0' : '1'
    };
  }

  function buildTableBbcode(model) {
    var tableAttrs = {
      width: model.width,
      align: model.align,
      headers: model.headers,
      bgcolor: model.bgcolor,
      textcolor: model.textcolor,
      hbgcolor: model.hbgcolor,
      htextcolor: model.htextcolor,
      border: model.border,
      bordercolor: model.bordercolor,
      borderwidth: model.borderwidth
    };

    if (model.cellwidth) {
      tableAttrs.cellwidth = model.cellwidth;
    }

    var out = ['[table' + stringifyAttrs(tableAttrs) + ']'];

    for (var r = 1; r <= model.rows; r += 1) {
      out.push('[tr]');

      for (var c = 1; c <= model.cols; c += 1) {
        var tagName = resolveCellTag(model, r, c);
        var text = buildPlaceholderText(model, r, c, tagName);
        out.push('[' + tagName + ']' + text + '[/' + tagName + ']');
      }

      out.push('[/tr]');
    }

    out.push('[/table]');

    return out.join('\n');
  }

  function insertIntoTextarea(textarea, value) {
    if (!textarea) return false;

    var start = textarea.selectionStart || 0;
    var end = textarea.selectionEnd || 0;
    var before = textarea.value.slice(0, start);
    var after = textarea.value.slice(end);

    textarea.value = before + value + after;
    textarea.selectionStart = textarea.selectionEnd = start + value.length;

    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    textarea.dispatchEvent(new Event('change', { bubbles: true }));
    textarea.focus();

    return true;
  }

    function insertIntoEditor(target, value) {
    if (!target) return false;

    if (typeof target.insert === 'function') {
        target.insert(value);

        cleanupEditorBodyTableSpacers(target);

        if (typeof target.updateOriginal === 'function') {
        target.updateOriginal();
        }

        if (typeof target.focus === 'function') {
        target.focus();
        }

        return true;
    }

    if (target.nodeName && target.nodeName.toLowerCase() === 'textarea') {
        return insertIntoTextarea(target, value);
    }

    return false;
    }

  function createIconLabel(iconSvg, label) {
    return [
      '<span class="af-ae-ui-label" aria-hidden="true" title="' + escHtml(label) + '">',
      iconSvg,
      '</span>',
      '<span class="af-ae-sr-only">' + escHtml(label) + '</span>'
    ].join('');
  }

  function createCompactField(iconSvg, label, controlHtml, extraClass) {
    return [
      '<label class="af-ae-tables-dropdown__field' + (extraClass ? ' ' + extraClass : '') + '" title="' + escHtml(label) + '">',
      '  <span class="af-ae-tables-dropdown__fieldhead">' + createIconLabel(iconSvg, label) + '</span>',
      controlHtml,
      '</label>'
    ].join('');
  }

  function createToolbarMetaField(iconSvg, label, controlHtml) {
    return [
      '<label class="af-ae-table-toolbar__meta-field" title="' + escHtml(label) + '">',
      '  <span class="af-ae-table-toolbar__meta-label">' + createIconLabel(iconSvg, label) + '</span>',
      controlHtml,
      '</label>'
    ].join('');
  }

  function createToolbarColorField(name, label, placeholder, iconKey) {
    return [
      '<label class="af-ae-color-chip af-ae-color-chip--toolbar" title="' + escHtml(label) + '">',
      '  <span class="af-ae-color-chip__label">' + createIconLabel(ICONS[iconKey], label) + '</span>',
      '  <div class="af-ae-color af-ae-color--chip">',
      '    <input class="af-ae-color__value" type="text" name="' + name + '" value="" placeholder="' + placeholder + '">',
      '    <button type="button" class="af-ae-color__pick" data-af-color-for="' + name + '" aria-label="' + escHtml(label) + '"></button>',
      '    <button type="button" class="af-ae-color__clear af-ae-color__clear--mini" data-af-clear-color="' + name + '" aria-label="Сбросить цвет">×</button>',
      '  </div>',
      '</label>'
    ].join('');
  }

  function createActionButton(action, label, iconKey, extraClass) {
    return [
      '<button type="button" class="button' + (extraClass ? ' ' + extraClass : '') + '" data-af-action="' + action + '" title="' + escHtml(label) + '">',
      '  <span class="af-ae-btn__icon" aria-hidden="true">' + ICONS[iconKey] + '</span>',
      '  <span>' + escHtml(label) + '</span>',
      '</button>'
    ].join('');
  }

  function createColorField(name, label, placeholder, iconKey) {
    return [
      '<label class="af-ae-color-chip" title="' + escHtml(label) + '">',
      '  <span class="af-ae-color-chip__label">' + createIconLabel(ICONS[iconKey], label) + '</span>',
      '  <div class="af-ae-color af-ae-color--chip">',
      '    <input class="af-ae-color__value" type="text" name="' + name + '" value="" placeholder="' + placeholder + '">',
      '    <button type="button" class="af-ae-color__pick" data-af-color-for="' + name + '" aria-label="' + escHtml(label) + '"></button>',
      '    <button type="button" class="af-ae-color__clear af-ae-color__clear--mini" data-af-clear-color="' + name + '" aria-label="Сбросить цвет">×</button>',
      '  </div>',
      '</label>'
    ].join('');
  }

  function getJscolorCtor() {
    if (window.JSColor && typeof window.JSColor === 'function') return window.JSColor;
    if (window.jscolor && typeof window.jscolor === 'function') return window.jscolor;
    return null;
  }
  function isJscolorEventTarget(node) {
  if (!node) return false;

  if (node.nodeType !== 1) {
    node = node.parentNode;
  }

  return !!(node && node.closest && node.closest('.jscolor-picker, .jscolor-wrap, #jscolor-palette'));
}

  function hasVisibleJscolorPopup() {
    var nodes = document.querySelectorAll('.jscolor-picker, .jscolor-wrap, #jscolor-palette');

    for (var i = 0; i < nodes.length; i += 1) {
      var node = nodes[i];
      if ((node.offsetWidth > 0 || node.offsetHeight > 0 || node.getClientRects().length > 0) &&
          window.getComputedStyle(node).display !== 'none' &&
          window.getComputedStyle(node).visibility !== 'hidden') {
        return true;
      }
    }

    return false;
  }

  function isBuilderEventTarget(node) {
    if (!node) return false;

    if (node.nodeType !== 1) {
      node = node.parentNode;
    }

    if (!node || !node.closest) return false;

    if (builderState.root && builderState.root.contains(node)) {
      return true;
    }

    if (builderState.dropdown && builderState.dropdown.contains(node)) {
      return true;
    }

    return false;
  }

  function openBuilderState(root, dropdown, editor) {
    builderState.isOpen = true;
    builderState.root = root || null;
    builderState.dropdown = dropdown || null;
    builderState.editor = editor || null;
    builderState.allowProgrammaticClose = false;

    if (
      editor &&
      typeof editor.closeDropDown === 'function' &&
      !builderState.originalCloseDropDown
    ) {
      builderState.originalCloseDropDown = editor.closeDropDown;

      editor.closeDropDown = function () {
        if (builderState.isOpen && !builderState.allowProgrammaticClose) {
          return false;
        }

        return builderState.originalCloseDropDown.apply(editor, arguments);
      };
    }
  }

  function restoreBuilderCloseDropDown() {
    if (builderState.editor && builderState.originalCloseDropDown) {
      builderState.editor.closeDropDown = builderState.originalCloseDropDown;
    }

    builderState.originalCloseDropDown = null;
    builderState.editor = null;
    builderState.allowProgrammaticClose = false;
  }

  function closeBuilderState() {
    restoreBuilderCloseDropDown();
    builderState.isOpen = false;
    builderState.root = null;
    builderState.dropdown = null;
  }

  function requestBuilderClose() {
    var editor = builderState.editor;
    var closeFn = editor && typeof editor.closeDropDown === 'function'
      ? editor.closeDropDown
      : null;

    if (!editor || !closeFn) {
      closeBuilderState();
      return;
    }

    builderState.allowProgrammaticClose = true;

    try {
      closeFn.call(editor, true);
    } catch (e) {
      closeBuilderState();
      throw e;
    }

    closeBuilderState();
  }

  function bindJscolorPopupGuards() {
    var nodes = document.querySelectorAll('.jscolor-picker, .jscolor-wrap, #jscolor-palette');

    for (var i = 0; i < nodes.length; i += 1) {
      var node = nodes[i];

      if (node.__afTableJscolorPopupGuardBound) {
        continue;
      }

      node.__afTableJscolorPopupGuardBound = true;
      node.style.pointerEvents = 'auto';

      if (!node.getAttribute('data-af-jscolor-popup')) {
        node.setAttribute('data-af-jscolor-popup', '1');
      }
    }
  }

  function getTableColorDropdown(root) {
    var node = root;

    while (node && node.nodeType === 1) {
      if (node.classList && node.classList.contains('sceditor-dropdown')) {
        return node;
      }
      node = node.parentNode;
    }

    return null;
  }

  function ensureTableColorViewport(root) {
    var dropdown = getTableColorDropdown(root);

    if (dropdown) {
      dropdown.style.overflow = 'visible';
      dropdown.style.maxWidth = 'none';
      if (dropdown.offsetWidth < 460) {
        dropdown.style.width = '460px';
      }
    }

    if (root && root.classList) {
      if (root.classList.contains('af-ae-tables-dropdown')) {
        root.style.minWidth = '430px';
        root.style.maxWidth = '460px';
      }

      if (root.classList.contains('af-ae-table-toolbar')) {
        root.style.width = '460px';
        root.style.maxWidth = 'calc(100% - 12px)';
      }
    }
  }

  function createTableJscolorInstance(root, input, pickerBtn) {
    var Ctor = getJscolorCtor();
    var picker;
    var initialValue;
    var hadValue;

    if (!root || !input || !pickerBtn || !Ctor) return null;
    if (input.jscolor) return input.jscolor;

    hadValue = !!normalizeColor(input.value);
    initialValue = normalizeColor(input.value) || '#FFFFFF';

    ensureTableColorViewport(root);

    try {
      picker = new Ctor(input, {
        value: initialValue,
        format: 'hexa',
        alphaChannel: true,
        hash: true,
        valueElement: input,
        previewElement: pickerBtn,
        container: document.body,
        showOnClick: false,
        hideOnLeave: false,
        closeButton: true,
        position: 'top',
        smartPosition: true,
        palette: TABLE_COLOR_SWATCHES,
        paletteCols: 10,
        paletteHeight: 12,
        paletteSpacing: 4,
        width: 160,
        height: 86,
        zIndex: 100000,
        onInput: function () {
          var value = '';

          if (typeof this.toHEXAString === 'function') {
            try { value = this.toHEXAString(); } catch (e1) {}
          }

          if (!value && typeof this.toRGBAString === 'function') {
            try { value = this.toRGBAString(); } catch (e2) {}
          }

          value = normalizeColor(value || input.value);
          if (value) {
            input.value = value;
          }

          syncColorControls(root);
        },
        onChange: function () {
          var value = '';

          if (typeof this.toHEXAString === 'function') {
            try { value = this.toHEXAString(); } catch (e3) {}
          }

          if (!value && typeof this.toRGBAString === 'function') {
            try { value = this.toRGBAString(); } catch (e4) {}
          }

          value = normalizeColor(value || input.value);
          if (value) {
            input.value = value;
          }

          syncColorControls(root);
        }
      });

      input.jscolor = picker;

      if (!hadValue) {
        input.value = '';
        pickerBtn.style.background = '#FFFFFF';
      } else if (typeof picker.fromString === 'function') {
        picker.fromString(initialValue);
      }

      if (picker && typeof picker.hide === 'function') {
        try { picker.hide(); } catch (e5) {}
      }

      bindJscolorPopupGuards();
      return picker;
    } catch (e) {
      return null;
    }
  }

  function ensureJscolorForInput(root, input) {
    var pickerBtn;
    var picker;

    if (!root || !input) return;
    if (input.__afTableJscolorBound) return;

    pickerBtn = root.querySelector('.af-ae-color__pick[data-af-color-for="' + input.name + '"]');
    if (!pickerBtn) return;

    input.__afTableJscolorBound = true;

    pickerBtn.addEventListener('pointerdown', function (event) {
      event.preventDefault();
      event.stopPropagation();
    });

    pickerBtn.addEventListener('mousedown', function (event) {
      event.preventDefault();
      event.stopPropagation();
    });

    pickerBtn.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();

      ensureTableColorViewport(root);

      picker = createTableJscolorInstance(root, input, pickerBtn);
      if (picker && typeof picker.show === 'function') {
        try { picker.show(); } catch (e1) {}
        window.setTimeout(bindJscolorPopupGuards, 0);
      }
    });

    input.addEventListener('input', function () {
      var norm = normalizeColor(input.value);

      if (norm) {
        input.value = norm;
      }

      syncColorControls(root);
    });

    input.addEventListener('change', function () {
      var norm = normalizeColor(input.value);

      if (!norm) {
        syncColorControls(root);
        return;
      }

      input.value = norm;

      if (input.jscolor && typeof input.jscolor.fromString === 'function') {
        try { input.jscolor.fromString(norm); } catch (e2) {}
      }

      syncColorControls(root);
    });
  }
  function initTableJscolor(root) {
    var textInputs = root.querySelectorAll('.af-ae-color input[type="text"]');
    for (var i = 0; i < textInputs.length; i += 1) {
      ensureJscolorForInput(root, textInputs[i]);
    }
  }
  function bindJscolorInteractionGuards(root) {
    if (!root || root.__afTableJscolorGuardsBound) return;
    root.__afTableJscolorGuardsBound = true;

    function stopIfJscolor(event) {
      var target = event.target;
      if (!target || !target.closest) return;

      if (target.closest('.jscolor-picker, .jscolor-wrap, #jscolor-palette, .af-ae-color, .af-ae-color__pick, .af-ae-color__value')) {
        event.stopPropagation();
      }
    }

    root.addEventListener('mousedown', stopIfJscolor);
    root.addEventListener('click', stopIfJscolor);
  }
  function syncColorControls(root) {
    var textInputs = root.querySelectorAll('.af-ae-color input[type="text"]');

    for (var i = 0; i < textInputs.length; i += 1) {
      var input = textInputs[i];
      var color = root.querySelector('[data-af-color-for="' + input.name + '"]');
      var value = normalizeColor(input.value);

      if (value && input.value !== value) {
        input.value = value;
      }

      if (!color) continue;

      if (value) {
        color.style.background = value;
      } else {
        color.style.background = '#FFFFFF';
      }
    }
  }

  function attachColorUi(root) {
    root.addEventListener('input', function (event) {
      var target = event.target;

      if (target.matches('.af-ae-color input[type="text"]')) {
        syncColorControls(root);
      }
    });

    root.addEventListener('click', function (event) {
      var clearNode = event.target && event.target.closest ? event.target.closest('[data-af-clear-color]') : null;
      var name = clearNode ? clearNode.getAttribute('data-af-clear-color') : '';

      if (!name) return;

      event.preventDefault();
      event.stopPropagation();

      var textInput = root.querySelector('.af-ae-color input[type="text"][name="' + name + '"]');
      var colorInput = root.querySelector('[data-af-color-for="' + name + '"]');

      if (textInput) {
        textInput.value = '';

        if (textInput.jscolor && typeof textInput.jscolor.hide === 'function') {
          try { textInput.jscolor.hide(); } catch (e) {}
        }
      }

      if (colorInput) {
        colorInput.style.background = '#FFFFFF';
      }
    });
  }

  function createBuilderNode(target, closeFn) {
    var root = document.createElement('div');
    root.className = 'af-ae-tables-dropdown';
    root.innerHTML = [
      '<div class="af-ae-tables-dropdown__title">',
      '  ' + createIconLabel(ICONS.table, 'Конструктор таблицы'),
      '  <span>Таблица</span>',
      '</div>',

      '<div class="af-ae-tables-dropdown__grid af-ae-tables-dropdown__grid--compact">',
      createCompactField(ICONS.cols, 'Количество колонок',
        '<input type="number" name="cols" min="1" max="' + MAX_COLS + '" value="2">'),
      createCompactField(ICONS.rows, 'Количество строк',
        '<input type="number" name="rows" min="1" max="' + MAX_ROWS + '" value="2">'),
      createCompactField(ICONS.width, 'Ширина таблицы',
        '<input type="text" name="width" value="100%" placeholder="100%">'),
      createCompactField(ICONS.cellwidth, 'Ширина колонок',
        '<input type="text" name="cellwidth" value="" placeholder="160px">'),
      createCompactField(ICONS.align, 'Выравнивание таблицы',
        '<select name="align"><option value="left">L</option><option value="center" selected>C</option><option value="right">R</option></select>'),
      createCompactField(ICONS.headers, 'Заголовки',
        '<select name="headers"><option value="none" selected>Нет</option><option value="col">Строка</option><option value="row">Столбец</option><option value="both">Оба</option></select>'),
      createCompactField(ICONS.borderwidth, 'Ширина границы',
        '<input type="text" name="borderwidth" value="1px" placeholder="1px">'),
      [
        '<label class="af-ae-tables-dropdown__checkbox af-ae-tables-dropdown__checkbox--compact" title="Заполнить таблицу плейсхолдерами">',
        '  ' + createIconLabel(ICONS.fill, 'Заполнить таблицу плейсхолдерами'),
        '  <input type="checkbox" name="fill" checked>',
        '  <span>Плейсхолдеры</span>',
        '</label>'
      ].join(''),
      '</div>',

      '<div class="af-ae-tables-dropdown__colors">',
      createColorField('bgcolor', 'Фон ячеек', '#1f2329', 'cellBg'),
      createColorField('textcolor', 'Текст ячеек', '#e6e9ef', 'cellText'),
      createColorField('hbgcolor', 'Фон заголовков', '#2f3c55', 'headBg'),
      createColorField('htextcolor', 'Текст заголовков', '#ffffff', 'headText'),
      createColorField('bordercolor', 'Цвет границы', '#5f6670', 'borderColor'),
      '</div>',

      '<div class="af-ae-tables-dropdown__summary"></div>',

      '<div class="af-ae-tables-dropdown__actions">',
      createActionButton('cancel', 'Отмена', 'cancel', ''),
      createActionButton('insert', 'Вставить', 'insert', 'button--primary'),
      '</div>'
    ].join('');

    attachColorUi(root);
    bindJscolorInteractionGuards(root);
    initTableJscolor(root);
    syncColorControls(root);

    var summary = root.querySelector('.af-ae-tables-dropdown__summary');

    function renderSummary() {
      var model = buildModelFromForm(root);
      var headersMap = {
        none: 'без head',
        col: 'head: строка',
        row: 'head: столбец',
        both: 'head: оба'
      };

      summary.textContent =
        model.rows + '×' + model.cols +
        ' · ' + model.width +
        (model.cellwidth ? ' · col ' + model.cellwidth : '') +
        ' · ' + headersMap[model.headers] +
        ' · border ' + model.borderwidth;
    }

    root.addEventListener('input', renderSummary);
    root.addEventListener('change', renderSummary);

    root.addEventListener('click', function (event) {
      var action = event.target && event.target.getAttribute('data-af-action');
      if (!action && event.target && event.target.closest) {
        var actionNode = event.target.closest('[data-af-action]');
        action = actionNode ? actionNode.getAttribute('data-af-action') : '';
      }
      if (!action) return;

      event.preventDefault();

      if (action === 'cancel') {
        if (typeof closeFn === 'function') closeFn();
        return;
      }

      if (action === 'insert') {
        var model = buildModelFromForm(root);
        var bbcode = buildTableBbcode(model);
        insertIntoEditor(target, bbcode);

        if (typeof closeFn === 'function') {
          closeFn();
        }
      }
    });

    renderSummary();
    return root;
  }

  function openBuilder(target, caller) {
    var node;
    var dropdown;
    var drops;
    var i;

    if (!target || typeof target.createDropDown !== 'function') {
      return false;
    }

    if (builderState.isOpen) {
      requestBuilderClose();
    }

    node = createBuilderNode(target, function () {
      requestBuilderClose();
    });

    target.createDropDown(caller || null, 'af-ae-tables-builder', node);

    dropdown = node.parentNode;
    if (!dropdown || !dropdown.classList || !dropdown.classList.contains('sceditor-dropdown')) {
      drops = document.querySelectorAll('.sceditor-dropdown');
      for (i = drops.length - 1; i >= 0; i -= 1) {
        if (drops[i].contains(node)) {
          dropdown = drops[i];
          break;
        }
      }
    }

    if (dropdown) {
      dropdown.style.overflow = 'visible';
      dropdown.style.maxWidth = 'none';
      if (dropdown.offsetWidth < 460) {
        dropdown.style.width = '460px';
      }
    }

    openBuilderState(node, dropdown || null, target);
    return true;
  }

  function resolveEditor(ctx) {
    if (!ctx) return null;
    if (typeof ctx.insert === 'function') return ctx;
    if (ctx.editor && typeof ctx.editor.insert === 'function') return ctx.editor;
    if (ctx.instance && typeof ctx.instance.insert === 'function') return ctx.instance;
    if (ctx.sceditor && typeof ctx.sceditor.insert === 'function') return ctx.sceditor;
    if (ctx.target && typeof ctx.target.insert === 'function') return ctx.target;
    if (ctx.textarea && ctx.textarea.nodeName && ctx.textarea.nodeName.toLowerCase() === 'textarea') {
      return ctx.textarea;
    }
    return null;
  }

  function resolveCaller(ctx, fallback) {
    if (ctx && ctx.caller) return ctx.caller;
    if (ctx && ctx.button) return ctx.button;
    if (ctx && ctx.el) return ctx.el;
    return fallback || null;
  }

  function getEditorInstanceFromTextarea(textarea) {
    var sc = getSceditorRoot();
    if (!sc || typeof sc.instance !== 'function') return null;

    try {
      return sc.instance(textarea);
    } catch (error) {
      return null;
    }
  }

  function getEditorFrame(instance) {
    if (!instance || typeof instance.getBody !== 'function') return null;
    var body = instance.getBody();
    if (!body || !body.ownerDocument || !body.ownerDocument.defaultView) return null;
    return body.ownerDocument.defaultView.frameElement || null;
  }

  function getEditorContainer(instance) {
    var frame = getEditorFrame(instance);
    return frame && typeof frame.closest === 'function' ? frame.closest('.sceditor-container') : null;
  }

  function getClosestTableCell(node) {
    while (node && node.nodeType === 1) {
      if (node.matches && node.matches('td, th')) return node;
      node = node.parentNode;
    }
    return null;
  }

  function getClosestTable(node) {
    while (node && node.nodeType === 1) {
      if (node.matches && node.matches('table[data-af-bb-table="1"]')) return node;
      node = node.parentNode;
    }
    return null;
  }

  function refreshTablePresentation(table) {
    if (!table) return;

    var tableAttrs = readTableAttrsFromElement(table);
    var cells = table.querySelectorAll('td, th');

    table.setAttribute('style', buildTableStyle(tableAttrs));

    if (tableAttrs.cellwidth) {
      table.setAttribute('data-af-cellwidth', tableAttrs.cellwidth);
    } else {
      table.removeAttribute('data-af-cellwidth');
    }

    for (var i = 0; i < cells.length; i += 1) {
      var cell = cells[i];
      var tagName = cell.tagName.toLowerCase();
      var sourceAttrs = readCellSourceAttrs(cell);

      cell.classList.add('af-bb-cell');
      cell.classList.add('af-bb-cell--' + tagName);
      cell.setAttribute('style', buildCellStyle(tagName, sourceAttrs, tableAttrs));
      ensureEditableCellContent(cell);
    }
  }

  function setTableAttr(table, key, value) {
    if (!table) return;
    value = trim(value);

    if (key === 'width') {
      value = normalizeWidth(value || '100%', '100%');
      table.setAttribute('data-af-width', value);
    } else if (key === 'align') {
      value = normalizeAlign(value || 'center');
      table.setAttribute('data-af-align', value);
    } else if (key === 'headers') {
      value = normalizeHeaders(value || 'none');
      table.setAttribute('data-af-headers', value);
    } else if (key === 'cellwidth') {
      value = normalizeSize(value, '', true);
      if (value) table.setAttribute('data-af-cellwidth', value);
      else table.removeAttribute('data-af-cellwidth');
    } else {
      if (value) table.setAttribute('data-af-source-' + key, value);
      else table.removeAttribute('data-af-source-' + key);
    }

    refreshTablePresentation(table);
  }

  function setCellAttr(cell, key, value) {
    if (!cell) return;
    value = trim(value);

    if (key === 'width') {
      value = normalizeSize(value, '', true);
      if (value) cell.setAttribute('data-af-source-width', value);
      else cell.removeAttribute('data-af-source-width');
    } else if (key === 'align') {
      value = normalizeOptionalAlign(value);
      if (value) cell.setAttribute('data-af-source-align', value);
      else cell.removeAttribute('data-af-source-align');
    } else if (key === 'bgcolor' || key === 'textcolor') {
      if (value) cell.setAttribute('data-af-source-' + key, value);
      else cell.removeAttribute('data-af-source-' + key);
    }

    refreshTablePresentation(getClosestTable(cell));
  }

  function getColumnIndex(cell) {
    if (!cell || !cell.parentNode) return -1;
    return Array.prototype.indexOf.call(cell.parentNode.children, cell);
  }

  function applyWidthToColumn(table, cell, value) {
    if (!table || !cell) return;
    var colIndex = getColumnIndex(cell);
    if (colIndex < 0) return;

    value = normalizeSize(value, '', true);
    var rows = table.querySelectorAll('tr');

    for (var i = 0; i < rows.length; i += 1) {
      var current = rows[i].children[colIndex];
      if (!current) continue;

      if (value) {
        current.setAttribute('data-af-source-width', value);
      } else {
        current.removeAttribute('data-af-source-width');
      }
    }

    refreshTablePresentation(table);
  }

  function replaceCellTag(cell, tagName) {
    var replacement = cell.ownerDocument.createElement(tagName);
    var attrs = Array.prototype.slice.call(cell.attributes);

    for (var i = 0; i < attrs.length; i += 1) {
      replacement.setAttribute(attrs[i].name, attrs[i].value);
    }

    replacement.innerHTML = cell.innerHTML;
    cell.parentNode.replaceChild(replacement, cell);
    return replacement;
  }

  function normalizeHeaderLayout(table) {
    if (!table) return;

    var headers = normalizeHeaders(table.getAttribute('data-af-headers') || 'none');
    var rows = table.querySelectorAll('tr');

    for (var rowIndex = 0; rowIndex < rows.length; rowIndex += 1) {
      var row = rows[rowIndex];
      var cells = Array.prototype.slice.call(row.children);

      for (var colIndex = 0; colIndex < cells.length; colIndex += 1) {
        var cell = cells[colIndex];
        var shouldHeader = false;

        if (headers === 'col' && rowIndex === 0) shouldHeader = true;
        if (headers === 'row' && colIndex === 0) shouldHeader = true;
        if (headers === 'both' && (rowIndex === 0 || colIndex === 0)) shouldHeader = true;

        if (headers === 'none') shouldHeader = false;

        if (shouldHeader && cell.tagName.toLowerCase() !== 'th') {
          replaceCellTag(cell, 'th');
        } else if (!shouldHeader && cell.tagName.toLowerCase() !== 'td') {
          replaceCellTag(cell, 'td');
        }
      }
    }
  }

  function createNewCell(tagName, tableAttrs, placeholder) {
    var cell = document.createElement(tagName);
    cell.setAttribute('data-af-bb-cell', '1');
    cell.innerHTML = placeholder ? placeholder : getCellFillerHtml();
    cell.setAttribute('style', buildCellStyle(tagName, {}, tableAttrs));
    return cell;
  }

  function buildPlaceholderForInsertedCell(table, rowIndex, colIndex, tagName) {
    if (tagName === 'th') {
      if (rowIndex === 0) return 'Заголовок ' + (colIndex + 1);
      if (colIndex === 0) return 'Строка ' + (rowIndex + 1);
      return 'Заголовок';
    }
    return '';
  }

  function addRow(table, cell, mode) {
    if (!table || !cell) return null;

    var refRow = cell.parentNode;
    var rows = table.querySelectorAll('tr');
    var rowIndex = Array.prototype.indexOf.call(rows, refRow);
    var insertIndex = mode === 'before' ? rowIndex : rowIndex + 1;
    var tableAttrs = readTableAttrsFromElement(table);
    var headers = normalizeHeaders(table.getAttribute('data-af-headers') || 'none');
    var newRow = document.createElement('tr');
    var count = refRow.children.length;

    for (var colIndex = 0; colIndex < count; colIndex += 1) {
      var tagName = 'td';

      if (headers === 'row' && colIndex === 0) tagName = 'th';
      if (headers === 'both' && colIndex === 0) tagName = 'th';
      if (headers === 'col' && insertIndex === 0) tagName = 'th';
      if (headers === 'both' && insertIndex === 0) tagName = 'th';

      newRow.appendChild(
        createNewCell(tagName, tableAttrs, buildPlaceholderForInsertedCell(table, insertIndex, colIndex, tagName))
      );
    }

    if (mode === 'before') {
      refRow.parentNode.insertBefore(newRow, refRow);
    } else {
      if (refRow.nextSibling) refRow.parentNode.insertBefore(newRow, refRow.nextSibling);
      else refRow.parentNode.appendChild(newRow);
    }

    normalizeHeaderLayout(table);
    refreshTablePresentation(table);

    return newRow.children[Math.max(0, Math.min(getColumnIndex(cell), newRow.children.length - 1))];
  }

  function addColumn(table, cell, mode) {
    if (!table || !cell) return null;

    var colIndex = getColumnIndex(cell);
    if (colIndex < 0) return null;

    var insertIndex = mode === 'before' ? colIndex : colIndex + 1;
    var rows = table.querySelectorAll('tr');
    var headers = normalizeHeaders(table.getAttribute('data-af-headers') || 'none');
    var tableAttrs = readTableAttrsFromElement(table);
    var firstInserted = null;

    for (var rowIndex = 0; rowIndex < rows.length; rowIndex += 1) {
      var row = rows[rowIndex];
      var ref = row.children[colIndex];
      var tagName = 'td';

      if (headers === 'col' && rowIndex === 0) tagName = 'th';
      if (headers === 'row' && insertIndex === 0) tagName = 'th';
      if (headers === 'both' && (rowIndex === 0 || insertIndex === 0)) tagName = 'th';

      var newCell = createNewCell(
        tagName,
        tableAttrs,
        buildPlaceholderForInsertedCell(table, rowIndex, insertIndex, tagName)
      );

      if (mode === 'before') {
        row.insertBefore(newCell, ref);
      } else {
        if (ref && ref.nextSibling) row.insertBefore(newCell, ref.nextSibling);
        else row.appendChild(newCell);
      }

      if (!firstInserted) firstInserted = newCell;
    }

    normalizeHeaderLayout(table);
    refreshTablePresentation(table);

    return firstInserted;
  }

  function deleteRow(table, cell) {
    if (!table || !cell) return null;

    var row = cell.parentNode;
    var rows = table.querySelectorAll('tr');

    if (rows.length <= 1) {
      table.parentNode.removeChild(table);
      hideTableToolbar();
      return null;
    }

    var colIndex = getColumnIndex(cell);
    var nextRow = row.nextElementSibling || row.previousElementSibling;

    row.parentNode.removeChild(row);
    normalizeHeaderLayout(table);
    refreshTablePresentation(table);

    return nextRow ? nextRow.children[Math.max(0, Math.min(colIndex, nextRow.children.length - 1))] : null;
  }

  function deleteColumn(table, cell) {
    if (!table || !cell) return null;

    var colIndex = getColumnIndex(cell);
    var rows = table.querySelectorAll('tr');

    if (!rows.length || rows[0].children.length <= 1) {
      table.parentNode.removeChild(table);
      hideTableToolbar();
      return null;
    }

    for (var i = 0; i < rows.length; i += 1) {
      if (rows[i].children[colIndex]) {
        rows[i].removeChild(rows[i].children[colIndex]);
      }
    }

    normalizeHeaderLayout(table);
    refreshTablePresentation(table);

    var targetRow = rows[0];
    return targetRow ? (targetRow.children[colIndex] || targetRow.children[colIndex - 1] || null) : null;
  }

  function moveRow(table, cell, dir) {
    if (!table || !cell) return cell;

    var row = cell.parentNode;
    var sibling = dir < 0 ? row.previousElementSibling : row.nextElementSibling;
    if (!sibling) return cell;

    if (dir < 0) {
      row.parentNode.insertBefore(row, sibling);
    } else {
      if (sibling.nextSibling) row.parentNode.insertBefore(row, sibling.nextSibling);
      else row.parentNode.appendChild(row);
    }

    normalizeHeaderLayout(table);
    refreshTablePresentation(table);

    return row.children[Math.max(0, Math.min(getColumnIndex(cell), row.children.length - 1))];
  }

  function moveColumn(table, cell, dir) {
    if (!table || !cell) return cell;

    var colIndex = getColumnIndex(cell);
    var rows = table.querySelectorAll('tr');
    if (!rows.length) return cell;

    var targetIndex = colIndex + dir;
    if (targetIndex < 0 || targetIndex >= rows[0].children.length) return cell;

    for (var i = 0; i < rows.length; i += 1) {
      var row = rows[i];
      var current = row.children[colIndex];
      var target = row.children[targetIndex];

      if (!current || !target) continue;

      if (dir < 0) {
        row.insertBefore(current, target);
      } else {
        if (target.nextSibling) row.insertBefore(current, target.nextSibling);
        else row.appendChild(current);
      }
    }

    normalizeHeaderLayout(table);
    refreshTablePresentation(table);

    var rowIndex = Array.prototype.indexOf.call(cell.parentNode.parentNode.children, cell.parentNode);
    rowIndex = Math.max(0, rowIndex);
    return rows[rowIndex] ? rows[rowIndex].children[targetIndex] : null;
  }

    function touchEditorInstance(instance) {
    if (!instance) return;

    cleanupEditorBodyTableSpacers(instance);

    if (typeof instance.updateOriginal === 'function') {
        instance.updateOriginal();
    }

    if (typeof instance.focus === 'function') {
        instance.focus();
    }
    }

  function toolbarTemplate() {
    return [
      '<div class="af-ae-table-toolbar__panel">',

      '  <div class="af-ae-table-toolbar__actions">',
      '    <button type="button" class="af-ae-table-toolbar__btn" data-action="row-before" title="Добавить строку выше">' + ICONS.rowBefore + '</button>',
      '    <button type="button" class="af-ae-table-toolbar__btn" data-action="row-after" title="Добавить строку ниже">' + ICONS.rowAfter + '</button>',
      '    <button type="button" class="af-ae-table-toolbar__btn" data-action="move-row-up" title="Переместить строку выше">' + ICONS.moveUp + '</button>',
      '    <button type="button" class="af-ae-table-toolbar__btn" data-action="move-row-down" title="Переместить строку ниже">' + ICONS.moveDown + '</button>',
      '    <button type="button" class="af-ae-table-toolbar__btn af-ae-table-toolbar__btn--danger" data-action="row-delete" title="Удалить строку">' + ICONS.rowDelete + '</button>',

      '    <span class="af-ae-table-toolbar__sep" aria-hidden="true"></span>',

      '    <button type="button" class="af-ae-table-toolbar__btn" data-action="col-before" title="Добавить колонку слева">' + ICONS.colBefore + '</button>',
      '    <button type="button" class="af-ae-table-toolbar__btn" data-action="col-after" title="Добавить колонку справа">' + ICONS.colAfter + '</button>',
      '    <button type="button" class="af-ae-table-toolbar__btn" data-action="move-col-left" title="Переместить колонку влево">' + ICONS.moveLeft + '</button>',
      '    <button type="button" class="af-ae-table-toolbar__btn" data-action="move-col-right" title="Переместить колонку вправо">' + ICONS.moveRight + '</button>',
      '    <button type="button" class="af-ae-table-toolbar__btn af-ae-table-toolbar__btn--danger" data-action="col-delete" title="Удалить колонку">' + ICONS.colDelete + '</button>',

      '    <span class="af-ae-table-toolbar__sep" aria-hidden="true"></span>',

      '    <button type="button" class="af-ae-table-toolbar__btn af-ae-table-toolbar__btn--danger" data-action="delete-table" title="Удалить таблицу">' + ICONS.trash + '</button>',
      '  </div>',

      '  <div class="af-ae-table-toolbar__meta">',
      createToolbarMetaField(ICONS.width, 'Ширина таблицы',
        '<input type="text" name="toolbar-width" placeholder="100%">'),
      createToolbarMetaField(ICONS.cellwidth, 'Ширина текущей колонки',
        '<input type="text" name="toolbar-colwidth" placeholder="160px">'),
      createToolbarMetaField(ICONS.align, 'Выравнивание таблицы',
        '<select name="toolbar-align"><option value="left">L</option><option value="center">C</option><option value="right">R</option></select>'),
      createToolbarMetaField(ICONS.borderwidth, 'Ширина границы',
        '<input type="text" name="toolbar-borderwidth" placeholder="1px">'),
      '  </div>',

      '  <div class="af-ae-table-toolbar__colors">',
      createToolbarColorField('toolbar-cell-bg', 'Ячейка · фон', '#ffffff', 'cellBg'),
      createToolbarColorField('toolbar-cell-text', 'Ячейка · текст', '#000000', 'cellText'),
      createToolbarColorField('toolbar-head-bg', 'Заголовок · фон', '#2f3c55', 'headBg'),
      createToolbarColorField('toolbar-head-text', 'Заголовок · текст', '#ffffff', 'headText'),
      createToolbarColorField('toolbar-bordercolor', 'Граница · цвет', '#5f6670', 'borderColor'),
      '  </div>',

      '</div>'
    ].join('');
  }

  function ensureTableToolbar() {
    if (toolbarState.toolbar) return toolbarState.toolbar;

    var toolbar = document.createElement('div');
    toolbar.id = TOOLBAR_ID;
    toolbar.className = 'af-ae-table-toolbar';
    toolbar.innerHTML = toolbarTemplate();

    attachColorUi(toolbar);
    bindJscolorInteractionGuards(toolbar);
    initTableJscolor(toolbar);

    toolbar.addEventListener('click', function (event) {
      var button = event.target.closest('[data-action]');
      if (!button) return;
      event.preventDefault();
      handleToolbarAction(button.getAttribute('data-action'));
    });

    toolbar.addEventListener('input', function (event) {
      if (!toolbarState.table) return;

      var target = event.target;
      var table = toolbarState.table;
      var cell = toolbarState.cell;

      if (target.matches('input[name="toolbar-width"]')) {
        setTableAttr(table, 'width', target.value || '100%');
      } else if (target.matches('input[name="toolbar-colwidth"]')) {
        applyWidthToColumn(table, cell, target.value);
      } else if (target.matches('select[name="toolbar-align"]')) {
        setTableAttr(table, 'align', target.value);
      } else if (target.matches('input[name="toolbar-cell-bg"]')) {
        setCellAttr(cell, 'bgcolor', target.value);
      } else if (target.matches('input[name="toolbar-cell-text"]')) {
        setCellAttr(cell, 'textcolor', target.value);
      } else if (target.matches('input[name="toolbar-head-bg"]')) {
        setTableAttr(table, 'hbgcolor', target.value);
      } else if (target.matches('input[name="toolbar-head-text"]')) {
        setTableAttr(table, 'htextcolor', target.value);
      } else if (target.matches('input[name="toolbar-borderwidth"]')) {
        var bw = normalizeSize(target.value, '1px', false);
        setTableAttr(table, 'borderwidth', bw);
        setTableAttr(table, 'border', bw === '0px' ? '0' : '1');
      } else if (target.matches('input[name="toolbar-bordercolor"]')) {
        setTableAttr(table, 'bordercolor', target.value || '#5f6670');
      }

      touchEditorInstance(toolbarState.instance);
    });

    toolbarState.toolbar = toolbar;
    return toolbar;
  }

  function populateToolbar(table, cell) {
    var toolbar = ensureTableToolbar();
    var tableAttrs = readTableAttrsFromElement(table);
    var cellAttrs = cell ? readCellSourceAttrs(cell) : { width: '', bgcolor: '', textcolor: '' };
    var isHeader = !!(cell && cell.tagName && cell.tagName.toLowerCase() === 'th');

    var effectiveCellBg = cellAttrs.bgcolor || (isHeader ? tableAttrs.hbgcolor : tableAttrs.bgcolor) || '';
    var effectiveCellText = cellAttrs.textcolor || (isHeader ? tableAttrs.htextcolor : tableAttrs.textcolor) || '';

    toolbar.querySelector('input[name="toolbar-width"]').value = tableAttrs.width || '';
    toolbar.querySelector('input[name="toolbar-colwidth"]').value = cellAttrs.width || tableAttrs.cellwidth || '';
    toolbar.querySelector('select[name="toolbar-align"]').value = tableAttrs.align || 'center';
    toolbar.querySelector('input[name="toolbar-cell-bg"]').value = effectiveCellBg;
    toolbar.querySelector('input[name="toolbar-cell-text"]').value = effectiveCellText;
    toolbar.querySelector('input[name="toolbar-head-bg"]').value = tableAttrs.hbgcolor || '';
    toolbar.querySelector('input[name="toolbar-head-text"]').value = tableAttrs.htextcolor || '';
    toolbar.querySelector('input[name="toolbar-borderwidth"]').value = tableAttrs.borderwidth || '1px';
    toolbar.querySelector('input[name="toolbar-bordercolor"]').value = tableAttrs.bordercolor || '#5f6670';

    syncColorControls(toolbar);
  }
  function positionToolbar(container, frame, anchor) {
    var toolbar = ensureTableToolbar();

    if (toolbar.parentNode !== container) {
      container.appendChild(toolbar);
    }

    container.classList.add('af-ae-has-table-toolbar');

    var containerRect = container.getBoundingClientRect();
    var frameRect = frame.getBoundingClientRect();
    var anchorRect = anchor.getBoundingClientRect();

    var left = frameRect.left - containerRect.left + anchorRect.left;
    var top = frameRect.top - containerRect.top + anchorRect.bottom + 8;

    var maxLeft = frameRect.left - containerRect.left + frameRect.width - toolbar.offsetWidth - 8;
    if (left > maxLeft) left = maxLeft;
    if (left < 8) left = 8;

    var maxTop = frameRect.top - containerRect.top + frameRect.height - toolbar.offsetHeight - 8;
    if (top > maxTop) {
      top = frameRect.top - containerRect.top + anchorRect.top - toolbar.offsetHeight - 8;
    }
    if (top < 8) top = 8;

    toolbar.style.left = left + 'px';
    toolbar.style.top = top + 'px';
    toolbar.classList.add('is-visible');
  }

  function showTableToolbar(instance, table, cell) {
    if (!instance || !table) return;

    var container = getEditorContainer(instance);
    var frame = getEditorFrame(instance);
    var anchor = cell || table;

    if (!container || !frame || !anchor) return;

    toolbarState.instance = instance;
    toolbarState.container = container;
    toolbarState.frame = frame;
    toolbarState.table = table;
    toolbarState.cell = cell || table.querySelector('td, th');

    populateToolbar(table, toolbarState.cell);
    positionToolbar(container, frame, anchor);
  }

  function hideTableToolbar() {
    var toolbar = toolbarState.toolbar;
    if (toolbar) {
      toolbar.classList.remove('is-visible');
    }

    if (toolbarState.container) {
      toolbarState.container.classList.remove('af-ae-has-table-toolbar');
    }

    toolbarState.instance = null;
    toolbarState.container = null;
    toolbarState.frame = null;
    toolbarState.table = null;
    toolbarState.cell = null;
  }

  function focusCell(cell) {
    if (!cell) return;
    ensureEditableCellContent(cell);

    var doc = cell.ownerDocument;
    var win = doc.defaultView;
    var range = doc.createRange();
    var selection = win.getSelection();

    range.selectNodeContents(cell);
    range.collapse(false);

    selection.removeAllRanges();
    selection.addRange(range);
  }

  function handleToolbarAction(action) {
    var table = toolbarState.table;
    var cell = toolbarState.cell;
    var instance = toolbarState.instance;
    var targetCell = cell;

    if (!table || !cell || !instance) return;

    if (action === 'row-before') {
      targetCell = addRow(table, cell, 'before') || cell;
    } else if (action === 'row-after') {
      targetCell = addRow(table, cell, 'after') || cell;
    } else if (action === 'row-delete') {
      targetCell = deleteRow(table, cell);
    } else if (action === 'col-before') {
      targetCell = addColumn(table, cell, 'before') || cell;
    } else if (action === 'col-after') {
      targetCell = addColumn(table, cell, 'after') || cell;
    } else if (action === 'col-delete') {
      targetCell = deleteColumn(table, cell);
    } else if (action === 'move-row-up') {
      targetCell = moveRow(table, cell, -1);
    } else if (action === 'move-row-down') {
      targetCell = moveRow(table, cell, 1);
    } else if (action === 'move-col-left') {
      targetCell = moveColumn(table, cell, -1);
    } else if (action === 'move-col-right') {
      targetCell = moveColumn(table, cell, 1);
    } else if (action === 'delete-table') {
      table.parentNode.removeChild(table);
      touchEditorInstance(instance);
      hideTableToolbar();
      return;
    }

    if (!targetCell || !targetCell.isConnected) {
      targetCell = table.querySelector('td, th');
    }

    if (!targetCell) {
      touchEditorInstance(instance);
      hideTableToolbar();
      return;
    }

    focusCell(targetCell);
    showTableToolbar(instance, getClosestTable(targetCell), targetCell);
    touchEditorInstance(instance);
  }

  function handleBodyClick(instance, event) {
    var target = event.target;
    var table = getClosestTable(target);
    var cell = getClosestTableCell(target);

    if (!table || !cell) {
      hideTableToolbar();
      return;
    }

    showTableToolbar(instance, table, cell);
  }

  function handleSelectionState(instance) {
    if (!instance) return;

    if (typeof instance.inSourceMode === 'function' && instance.inSourceMode()) {
      hideTableToolbar();
      return;
    }

    if (typeof instance.currentNode !== 'function') return;

    var node = instance.currentNode();
    var table = getClosestTable(node);
    var cell = getClosestTableCell(node);

    if (!table || !cell) {
      hideTableToolbar();
      return;
    }

    showTableToolbar(instance, table, cell);
  }

    function enhanceEditorInstance(instance) {
    if (!instance || instance.__afTablesEnhanced) return;

    var body = typeof instance.getBody === 'function' ? instance.getBody() : null;
    if (!body) return;

    instance.__afTablesEnhanced = true;

    cleanupEditorBodyTableSpacers(instance);

    body.addEventListener('click', function (event) {
        handleBodyClick(instance, event);
    });

    body.addEventListener('mouseup', function () {
        handleSelectionState(instance);
    });

    body.addEventListener('keyup', function () {
        handleSelectionState(instance);
    });

    instance.bind('selectionchanged nodechanged valuechanged blur', function (event) {
          if (event && event.type === 'blur') {
            window.setTimeout(function () {
                var toolbar = toolbarState.toolbar;
                var active = document.activeElement;

                if (toolbar && toolbar.contains(active)) return;
                if (isJscolorEventTarget(active) || hasVisibleJscolorPopup()) return;

                hideTableToolbar();
            }, 0);
            return;
          }

        cleanupEditorBodyTableSpacers(instance);
        handleSelectionState(instance);
    });
    }

  function enhanceAllEditors() {
    var textareas = document.querySelectorAll('textarea');

    for (var i = 0; i < textareas.length; i += 1) {
      var instance = getEditorInstanceFromTextarea(textareas[i]);
      if (instance) {
        enhanceEditorInstance(instance);
      }
    }
  }

  function registerBuiltinHandler() {
    window.afAeBuiltinHandlers.tables = function (ctx, maybeCaller) {
      var editor = resolveEditor(ctx);
      var caller = resolveCaller(ctx, maybeCaller);

      if (!editor) return false;
      return openBuilder(editor, caller);
    };
  }

  function registerCommand() {
    var sc = getSceditorRoot();

    if (!sc || !sc.command || typeof sc.command.set !== 'function') {
      return;
    }

    sc.command.set(COMMAND_ID, {
      exec: function (caller) {
        openBuilder(this, caller);
      },
      txtExec: function (caller) {
        openBuilder(this, caller);
      },
      tooltip: 'Таблица'
    });
  }

  document.addEventListener('mousedown', function (event) {
    var toolbar = toolbarState.toolbar;
    var frame = toolbarState.frame;

    if (builderState.isOpen) return;
    if (isJscolorEventTarget(event.target)) return;
    if (toolbar && toolbar.contains(event.target)) return;
    if (frame && event.target === frame) return;

    if (toolbar && toolbar.classList.contains('is-visible')) {
      hideTableToolbar();
    }
  });

  window.setInterval(enhanceAllEditors, INSTANCE_SCAN_DELAY);

  enhanceAllEditors();
  registerBbcodeFormats();
  registerBuiltinHandler();
  registerCommand();
})(window, document);

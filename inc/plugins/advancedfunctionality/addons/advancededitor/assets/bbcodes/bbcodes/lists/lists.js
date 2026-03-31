(function () {
  'use strict';

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);
  if (window.__afAeListsInitialized) return;
  window.__afAeListsInitialized = true;

  var LIST_BTNS = [
    { cmd: 'af_ul_disc',        style: 'disc',        tooltip: 'Список: точки (•)' },
    { cmd: 'af_ul_circle',      style: 'circle',      tooltip: 'Список: круги (◦)' },
    { cmd: 'af_ul_square',      style: 'square',      tooltip: 'Список: квадраты (■)' },
    { cmd: 'af_ul_decimal',     style: 'decimal',     tooltip: 'Список: нумерация (1,2,3)' },
    { cmd: 'af_ul_lower_roman', style: 'lower-roman', tooltip: 'Список: римские (i, ii, iii)' },
    { cmd: 'af_ul_upper_roman', style: 'upper-roman', tooltip: 'Список: римские (I, II, III)' },
    { cmd: 'af_ul_upper_alpha', style: 'upper-alpha', tooltip: 'Список: буквы (A, B, C)' },
    { cmd: 'af_ul_lower_alpha', style: 'lower-alpha', tooltip: 'Список: буквы (a, b, c)' }
  ];

  var UL_STYLES = { disc: 1, circle: 1, square: 1 };
  var OL_STYLES = { decimal: 1, 'upper-roman': 1, 'lower-roman': 1, 'upper-alpha': 1, 'lower-alpha': 1 };

  function hasSceditor() {
    return !!(window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.sceditor === 'function');
  }

  function asText(x) {
    return String(x == null ? '' : x);
  }

  function normalizeStyle(raw, fallback) {
    var s = asText(raw).trim().toLowerCase();
    if (s === 'i') s = 'decimal';
    if (!s) s = asText(fallback).trim().toLowerCase();
    if (!s) s = 'disc';
    if (UL_STYLES[s] || OL_STYLES[s]) return s;
    return fallback || 'disc';
  }

  function styleToType(style) {
    style = normalizeStyle(style, 'disc');
    return OL_STYLES[style] ? 'ol' : 'ul';
  }

  function canonicalListOpen(type, style) {
    style = normalizeStyle(style, type === 'ol' ? 'decimal' : 'disc');
    if (type === 'ol') {
      if (style === 'decimal') return '[ol]';
      return '[ol=' + style + ']';
    }
    if (style === 'disc') return '[ul]';
    return '[ul=' + style + ']';
  }

  function canonicalListClose(type) {
    return type === 'ol' ? '[/ol]' : '[/ul]';
  }

  function buildCanonicalChunk(style) {
    var type = styleToType(style);
    var normalized = normalizeStyle(style, type === 'ol' ? 'decimal' : 'disc');

    return canonicalListOpen(type, normalized) + '\n' +
      '[li][/li]\n' +
      canonicalListClose(type);
  }

  function listTypeAttr(type, style) {
    if (type === 'ul') {
      if (style === 'disc' || style === 'circle' || style === 'square') return style;
      return '';
    }

    if (style === 'decimal') return '1';
    if (style === 'upper-alpha') return 'A';
    if (style === 'lower-alpha') return 'a';
    if (style === 'upper-roman') return 'I';
    if (style === 'lower-roman') return 'i';

    return '';
  }

  function setListClassStyle(el, style) {
    if (!el || !el.classList) return;

    var toDelete = [];
    for (var i = 0; i < el.classList.length; i++) {
      if (/^af-ae-list--/i.test(el.classList[i])) toDelete.push(el.classList[i]);
    }
    for (var j = 0; j < toDelete.length; j++) {
      el.classList.remove(toDelete[j]);
    }

    el.classList.add('af-ae-list');
    el.classList.add('af-ae-list--' + style);
  }

  function readListTypeFromLegacy(tagName, rawAttr) {
    tagName = asText(tagName).toLowerCase();
    var attr = asText(rawAttr).trim();

    if (tagName === 'ul') {
      var ulAttr = attr.toLowerCase();
      if (UL_STYLES[ulAttr]) return ulAttr;
      if (ulAttr === 'disc' || ulAttr === '') return 'disc';
    }

    if (tagName === 'ol') {
      if (attr === '1') return 'decimal';
      if (attr === 'A') return 'upper-alpha';
      if (attr === 'a') return 'lower-alpha';
      if (attr === 'I') return 'upper-roman';
      if (attr === 'i') return 'lower-roman';

      var olAttr = attr.toLowerCase();
      if (OL_STYLES[olAttr]) return olAttr;
    }

    return '';
  }

  function parseInlineStyle(styleText) {
    var m = asText(styleText).match(/(?:^|;)\s*list-style-type\s*:\s*([^;]+)/i);
    return m && m[1] ? normalizeStyle(m[1], '') : '';
  }

  function detectListStyle(el, fallback) {
    if (!el || el.nodeType !== 1) return normalizeStyle(fallback, 'disc');

    var style = normalizeStyle(el.getAttribute('data-af-list-style'), '');
    if (style && (UL_STYLES[style] || OL_STYLES[style])) return style;

    style = normalizeStyle(el.getAttribute('data-list'), '');
    if (style && (UL_STYLES[style] || OL_STYLES[style])) return style;

    style = readListTypeFromLegacy(el.tagName, el.getAttribute('type'));
    if (style) return style;

    style = parseInlineStyle(el.getAttribute('style'));
    if (style) return style;

    if (el.className) {
      var m = String(el.className).match(/\baf-ae-list--([a-z-]+)\b/i);
      if (m && m[1]) {
        style = normalizeStyle(m[1], '');
        if (style) return style;
      }
    }

    return normalizeStyle(fallback, String(el.tagName).toLowerCase() === 'ol' ? 'decimal' : 'disc');
  }

  function applyListMeta(el, style, forcedType) {
    if (!el || el.nodeType !== 1) return;

    var type = forcedType || styleToType(style);
    style = normalizeStyle(style, type === 'ol' ? 'decimal' : 'disc');

    if (String(el.tagName).toLowerCase() !== type) return;

    el.setAttribute('data-af-list-style', style);
    el.setAttribute('data-af-list-type', type);
    el.setAttribute('data-list', style);
    setListClassStyle(el, style);

    var tAttr = listTypeAttr(type, style);
    if (tAttr) el.setAttribute('type', tAttr);
    else el.removeAttribute('type');

    el.style.listStyleType = style;
    el.style.paddingLeft = type === 'ol' ? '1.6em' : '1.4em';

    if (!el.children) return;

    for (var i = 0; i < el.children.length; i++) {
      var child = el.children[i];
      if (!child || child.nodeType !== 1 || String(child.tagName).toLowerCase() !== 'li') continue;

      child.classList.add('af-ae-list__item');
      child.setAttribute('data-af-list-style', style);
      child.style.listStyleType = style;
    }
  }

  function normalizeListsUnder(root) {
    if (!root || !root.querySelectorAll) return;

    var lists = root.querySelectorAll('ul, ol');
    for (var i = 0; i < lists.length; i++) {
      var list = lists[i];
      var fallback = String(list.tagName).toLowerCase() === 'ol' ? 'decimal' : 'disc';
      var style = detectListStyle(list, fallback);
      var type = styleToType(style);
      var tag = String(list.tagName).toLowerCase();

      if (tag !== type) {
        style = normalizeStyle(style, tag === 'ol' ? 'decimal' : 'disc');
        type = tag;
      }

      applyListMeta(list, style, type);
    }
  }

  function buildHtmlList(type, style, content) {
    type = asText(type).toLowerCase() === 'ol' ? 'ol' : 'ul';
    style = normalizeStyle(style, type === 'ol' ? 'decimal' : 'disc');
    var tAttr = listTypeAttr(type, style);

    return '<' + type +
      ' class="af-ae-list af-ae-list--' + style + '"' +
      ' data-af-list-type="' + type + '"' +
      ' data-af-list-style="' + style + '"' +
      ' data-list="' + style + '"' +
      (tAttr ? ' type="' + tAttr + '"' : '') +
      ' style="list-style-type:' + style + '; padding-left:' + (type === 'ol' ? '1.6em' : '1.4em') + ';">' +
      asText(content) +
      '</' + type + '>';
  }

  function buildHtmlListChunk(style) {
    var type = styleToType(style);
    var normalized = normalizeStyle(style, type === 'ol' ? 'decimal' : 'disc');
    return buildHtmlList(type, normalized, '<li class="af-ae-list__item" data-af-list-style="' + normalized + '" style="list-style-type:' + normalized + ';"><br></li>');
  }

  function findEditorInstanceFromCtx(ctx, caller) {
    if (ctx && typeof ctx.insertText === 'function') return ctx;
    if (ctx && ctx.sceditor && typeof ctx.sceditor.insertText === 'function') return ctx.sceditor;
    if (ctx && ctx.inst && typeof ctx.inst.insertText === 'function') return ctx.inst;

    if (!window.jQuery) return null;
    var $ = window.jQuery;

    try {
      if (caller && caller.nodeType === 1) {
        var c = caller.closest ? caller.closest('.sceditor-container') : null;
        if (c && c.previousElementSibling && c.previousElementSibling.tagName === 'TEXTAREA') {
          var i0 = $(c.previousElementSibling).sceditor('instance');
          if (i0) return i0;
        }
      }
    } catch (e0) {}

    try {
      var active = document.activeElement;
      if (active && active.tagName === 'IFRAME') {
        var cont = active.closest ? active.closest('.sceditor-container') : null;
        if (cont && cont.previousElementSibling && cont.previousElementSibling.tagName === 'TEXTAREA') {
          var i1 = $(cont.previousElementSibling).sceditor('instance');
          if (i1) return i1;
        }
      }
    } catch (e1) {}

    try {
      var ta = document.querySelector('textarea#message, textarea[name="message"]');
      if (ta) return $(ta).sceditor('instance');
    } catch (e2) {}

    return null;
  }

  function isSourceMode(inst) {
    try {
      if (inst && typeof inst.sourceMode === 'function') return !!inst.sourceMode();
    } catch (e) {}
    return false;
  }

  function getSourceTextarea(inst) {
    try {
      if (inst && inst.sourceEditor && inst.sourceEditor.nodeType === 1) return inst.sourceEditor;
    } catch (e0) {}

    try {
      var cont = inst && typeof inst.getContainer === 'function' ? inst.getContainer() : null;
      if (cont && cont.querySelector) {
        return cont.querySelector('textarea.sceditor-textarea') || cont.querySelector('textarea');
      }
    } catch (e1) {}

    return null;
  }

  function insertIntoTextarea(ta, chunk) {
    if (!ta) return false;

    var start = ta.selectionStart || 0;
    var end = ta.selectionEnd || 0;
    var val = String(ta.value || '');

    ta.value = val.slice(0, start) + chunk + val.slice(start, end) + val.slice(end);

    var pos = start + chunk.length;
    ta.focus();
    ta.setSelectionRange(pos, pos);
    ta.dispatchEvent(new Event('input', { bubbles: true }));
    return true;
  }

  function insertList(instOrCtx, style, meta) {
    meta = meta || {};

    var inst = findEditorInstanceFromCtx(instOrCtx, meta.caller);
    var chunk = buildCanonicalChunk(style);

    if (!inst) {
      var plain = document.querySelector('textarea#message, textarea[name="message"]');
      if (!plain) return false;
      return insertIntoTextarea(plain, chunk);
    }

    try { ensureListCss(inst); } catch (e0) {}
    try { ensureBbcodeBridge(inst); } catch (e1) {}
    try { bindToSource(inst); } catch (e2) {}

    if (isSourceMode(inst)) {
      var srcTa = getSourceTextarea(inst);
      if (srcTa) {
        insertIntoTextarea(srcTa, chunk);
      } else if (typeof inst.insertText === 'function') {
        inst.insertText(chunk, '');
      }
      try { if (typeof inst.updateOriginal === 'function') inst.updateOriginal(); } catch (e3) {}
      return true;
    }

    var html = buildHtmlListChunk(style);

    try {
      if (typeof inst.wysiwygEditorInsertHtml === 'function') {
        inst.wysiwygEditorInsertHtml(html);
      } else if (typeof inst.insertHTML === 'function') {
        inst.insertHTML(html);
      } else {
        inst.insert(html, '');
      }

      if (typeof inst.getBody === 'function') normalizeListsUnder(inst.getBody());
      if (typeof inst.updateOriginal === 'function') inst.updateOriginal();
      return true;
    } catch (e4) {
      return false;
    }
  }

  function rewriteHtmlForSourceFromInstance(inst) {
    if (!inst || typeof inst.getBody !== 'function') return '';
    var body = inst.getBody();
    if (!body) return '';

    var cloneWrap = document.createElement('div');
    cloneWrap.innerHTML = body.innerHTML;
    normalizeListsUnder(cloneWrap);

    return rewriteHtmlForSource(cloneWrap.innerHTML);
  }

  function rewriteHtmlForSource(html) {
    html = asText(html);
    if (!html) return '';

    var wrap = document.createElement('div');
    wrap.innerHTML = html;

    function convert(node, doc) {
      if (!node) return null;
      if (node.nodeType === 3) return doc.createTextNode(node.nodeValue || '');
      if (node.nodeType !== 1) return null;

      var tag = String(node.tagName).toLowerCase();
      if (tag === 'ul' || tag === 'ol') {
        var fallback = tag === 'ol' ? 'decimal' : 'disc';
        var style = detectListStyle(node, fallback);
        var type = styleToType(style);

        if (tag !== type) {
          style = normalizeStyle(style, fallback);
          type = tag;
        }

        var out = doc.createElement(type === 'ol' ? 'afol' : 'aful');
        out.setAttribute('data-af-list-style', style);

        for (var i = 0; i < node.childNodes.length; i++) {
          var converted = convert(node.childNodes[i], doc);
          if (converted) out.appendChild(converted);
        }
        return out;
      }

      if (tag === 'li') {
        var li = doc.createElement('afli');
        for (var j = 0; j < node.childNodes.length; j++) {
          var nested = convert(node.childNodes[j], doc);
          if (nested) li.appendChild(nested);
        }
        return li;
      }

      var cloned = node.cloneNode(false);
      for (var k = 0; k < node.childNodes.length; k++) {
        var child = convert(node.childNodes[k], doc);
        if (child) cloned.appendChild(child);
      }
      return cloned;
    }

    var out = document.createElement('div');
    while (wrap.firstChild) {
      var n = convert(wrap.firstChild, document);
      wrap.removeChild(wrap.firstChild);
      if (n) out.appendChild(n);
    }

    return out.innerHTML;
  }

  function registerBridgeTags(bb) {
    if (!bb || bb.__afAeListsBridgeTagsInstalled) return;
    bb.__afAeListsBridgeTagsInstalled = true;

    bb.set('afli', {
      isInline: false,
      format: function (_el, content) { return '[li]' + asText(content) + '[/li]'; },
      tags: {
        afli: {
          format: function (_el, content) { return '[li]' + asText(content) + '[/li]'; }
        }
      }
    });

    bb.set('aful', {
      isBlock: true,
      format: function (el, content) {
        var style = normalizeStyle(el && el.getAttribute ? el.getAttribute('data-af-list-style') : '', 'disc');
        return canonicalListOpen('ul', style) + asText(content) + canonicalListClose('ul');
      },
      tags: {
        aful: {
          format: function (el, content) {
            var style = normalizeStyle(el && el.getAttribute ? el.getAttribute('data-af-list-style') : '', 'disc');
            return canonicalListOpen('ul', style) + asText(content) + canonicalListClose('ul');
          }
        }
      }
    });

    bb.set('afol', {
      isBlock: true,
      format: function (el, content) {
        var style = normalizeStyle(el && el.getAttribute ? el.getAttribute('data-af-list-style') : '', 'decimal');
        return canonicalListOpen('ol', style) + asText(content) + canonicalListClose('ol');
      },
      tags: {
        afol: {
          format: function (el, content) {
            var style = normalizeStyle(el && el.getAttribute ? el.getAttribute('data-af-list-style') : '', 'decimal');
            return canonicalListOpen('ol', style) + asText(content) + canonicalListClose('ol');
          }
        }
      }
    });
  }

  function ensureBbcodeBridge(inst) {
    if (!hasSceditor() || !window.jQuery) return;

    function getBb() {
      try {
        var bb = jQuery.sceditor.plugins && jQuery.sceditor.plugins.bbcode ? jQuery.sceditor.plugins.bbcode.bbcode : null;
        if (bb && typeof bb.set === 'function') return bb;
      } catch (e0) {}

      try {
        var p = inst && typeof inst.getPlugin === 'function' ? inst.getPlugin('bbcode') : null;
        if (p && p.bbcode && typeof p.bbcode.set === 'function') return p.bbcode;
      } catch (e1) {}

      return null;
    }

    var bb = getBb();
    if (!bb) return;
    if (bb.__afAeListsPatched) return;
    bb.__afAeListsPatched = true;

    registerBridgeTags(bb);

    bb.set('li', {
      isInline: false,
      html: '<li>{0}</li>',
      format: '[li]{0}[/li]'
    });

    bb.set('ul', {
      isBlock: true,
      html: function (_token, attrs, content) {
        var style = normalizeStyle(attrs && attrs.defaultattr != null ? attrs.defaultattr : '', 'disc');
        if (OL_STYLES[style]) {
          return buildHtmlList('ol', style, asText(content || ''));
        }
        return buildHtmlList('ul', style, asText(content || ''));
      },
      format: function (el, content) {
        var style = detectListStyle(el, 'disc');
        var type = styleToType(style);
        if (type === 'ol') return canonicalListOpen('ol', style) + asText(content) + canonicalListClose('ol');
        return canonicalListOpen('ul', style) + asText(content) + canonicalListClose('ul');
      }
    });

    bb.set('ol', {
      isBlock: true,
      html: function (_token, attrs, content) {
        var style = normalizeStyle(attrs && attrs.defaultattr != null ? attrs.defaultattr : '', 'decimal');
        return buildHtmlList('ol', style, asText(content || ''));
      },
      format: function (el, content) {
        var style = detectListStyle(el, 'decimal');
        return canonicalListOpen('ol', style) + asText(content) + canonicalListClose('ol');
      }
    });

    bb.set('bulletlist', {
      isInline: false,
      html: function (_t, _a, c) { return buildHtmlList('ul', 'disc', asText(c || '')); },
      format: function (_el, c) { return '[ul]' + asText(c) + '[/ul]'; }
    });

    bb.set('orderedlist', {
      isInline: false,
      html: function (_t, _a, c) { return buildHtmlList('ol', 'decimal', asText(c || '')); },
      format: function (_el, c) { return '[ol]' + asText(c) + '[/ol]'; }
    });
  }

  function bindToSource(inst) {
    if (!inst || inst.__afAeListsToSourceBound || typeof inst.bind !== 'function') return;
    inst.__afAeListsToSourceBound = true;

    inst.bind('toSource', function (html) {
      var preferred = rewriteHtmlForSourceFromInstance(inst);
      if (preferred) return preferred;
      return rewriteHtmlForSource(html);
    });
  }

  function ensureListCss(inst) {
    if (!inst || typeof inst.getBody !== 'function') return;

    var body = inst.getBody();
    if (!body || !body.ownerDocument) return;
    var doc = body.ownerDocument;
    if (doc.getElementById('af-ae-lists-css')) return;

    var css =
      'ol.af-ae-list{padding-left:1.6em !important;}' +
      'ul.af-ae-list{padding-left:1.4em !important;}' +
      '.af-ae-list > li,.af-ae-list__item{display:list-item !important;}' +
      '.af-ae-list--disc > li,.af-ae-list__item[data-af-list-style="disc"]{list-style-type:disc !important;}' +
      '.af-ae-list--circle > li,.af-ae-list__item[data-af-list-style="circle"]{list-style-type:circle !important;}' +
      '.af-ae-list--square > li,.af-ae-list__item[data-af-list-style="square"]{list-style-type:square !important;}' +
      '.af-ae-list--decimal > li,.af-ae-list__item[data-af-list-style="decimal"]{list-style-type:decimal !important;}' +
      '.af-ae-list--upper-roman > li,.af-ae-list__item[data-af-list-style="upper-roman"]{list-style-type:upper-roman !important;}' +
      '.af-ae-list--lower-roman > li,.af-ae-list__item[data-af-list-style="lower-roman"]{list-style-type:lower-roman !important;}' +
      '.af-ae-list--upper-alpha > li,.af-ae-list__item[data-af-list-style="upper-alpha"]{list-style-type:upper-alpha !important;}' +
      '.af-ae-list--lower-alpha > li,.af-ae-list__item[data-af-list-style="lower-alpha"]{list-style-type:lower-alpha !important;}';

    var st = doc.createElement('style');
    st.id = 'af-ae-lists-css';
    st.type = 'text/css';
    st.appendChild(doc.createTextNode(css));
    (doc.head || doc.documentElement).appendChild(st);
  }

  function detectCmd(def, caller) {
    var fromDef = asText(def && def.cmd ? def.cmd : '').trim();
    if (fromDef) return fromDef;

    if (!caller || caller.nodeType !== 1) return '';

    var a = caller.closest ? caller.closest('a') : caller;
    if (!a) a = caller;

    for (var i = 0; i < LIST_BTNS.length; i++) {
      if (a.classList && a.classList.contains('sceditor-button-' + LIST_BTNS[i].cmd)) return LIST_BTNS[i].cmd;
    }

    if (a.className) {
      var m = String(a.className).match(/sceditor-button-([a-z0-9_\-]+)/i);
      if (m && m[1]) return String(m[1]);
    }

    return '';
  }

  function cmdToStyle(cmd) {
    cmd = asText(cmd).trim();
    for (var i = 0; i < LIST_BTNS.length; i++) {
      if (LIST_BTNS[i].cmd === cmd) return LIST_BTNS[i].style;
    }
    return 'disc';
  }

  function aeListsHandler(editor, def, caller) {
    var cmd = detectCmd(def, caller);
    var style = cmdToStyle(cmd);
    insertList(editor || {}, style, { caller: caller, cmd: cmd, handler: 'lists' });
  }

  function registerHandlers() {
    window.afAeBuiltinHandlers.lists = aeListsHandler;
    window.afAqrBuiltinHandlers.lists = { onClick: aeListsHandler };

    for (var i = 0; i < LIST_BTNS.length; i++) {
      window.afAeBuiltinHandlers[LIST_BTNS[i].cmd] = aeListsHandler;
      window.afAqrBuiltinHandlers[LIST_BTNS[i].cmd] = { onClick: aeListsHandler };
    }
  }

  window.af_ae_lists_exec = function (editor, def, caller) {
    aeListsHandler(editor, def, caller);
  };

  function registerSceditorCommands() {
    if (!hasSceditor() || !window.jQuery) return false;
    var $ = window.jQuery;

    function setCmd(name, def) {
      try {
        if ($.sceditor.command && typeof $.sceditor.command.set === 'function') {
          $.sceditor.command.set(name, def);
          return true;
        }
      } catch (e0) {}

      try {
        if (!$.sceditor.commands) $.sceditor.commands = {};
        $.sceditor.commands[name] = def;
        return true;
      } catch (e1) {}
      return false;
    }

    for (var i = 0; i < LIST_BTNS.length; i++) {
      (function (btn) {
        setCmd(btn.cmd, {
          tooltip: btn.tooltip || btn.cmd,
          exec: function () {
            ensureBbcodeBridge(this);
            ensureListCss(this);
            bindToSource(this);
            insertList(this, btn.style, { cmd: btn.cmd, handler: 'sceditor' });
          },
          txtExec: function () {
            insertList(this, btn.style, { cmd: btn.cmd, handler: 'sceditor-txt' });
          }
        });
      })(LIST_BTNS[i]);
    }

    setCmd('bulletlist', {
      tooltip: 'Список: точки (•)',
      exec: function () {
        ensureBbcodeBridge(this);
        ensureListCss(this);
        bindToSource(this);
        insertList(this, 'disc', { cmd: 'bulletlist', handler: 'sceditor' });
      },
      txtExec: function () {
        insertList(this, 'disc', { cmd: 'bulletlist', handler: 'sceditor-txt' });
      }
    });

    setCmd('orderedlist', {
      tooltip: 'Список: нумерация (1,2,3)',
      exec: function () {
        ensureBbcodeBridge(this);
        ensureListCss(this);
        bindToSource(this);
        insertList(this, 'decimal', { cmd: 'orderedlist', handler: 'sceditor' });
      },
      txtExec: function () {
        insertList(this, 'decimal', { cmd: 'orderedlist', handler: 'sceditor-txt' });
      }
    });

    return true;
  }

  function patchInstances() {
    if (!hasSceditor() || !window.jQuery) return false;

    var $ = window.jQuery;
    var tas = document.querySelectorAll('textarea');
    for (var i = 0; i < tas.length; i++) {
      var inst = null;
      try { inst = $(tas[i]).sceditor('instance'); } catch (e0) { inst = null; }
      if (!inst) continue;

      try { ensureBbcodeBridge(inst); } catch (e1) {}
      try { ensureListCss(inst); } catch (e2) {}
      try { bindToSource(inst); } catch (e3) {}
      try { if (typeof inst.getBody === 'function') normalizeListsUnder(inst.getBody()); } catch (e4) {}
    }

    return true;
  }

  function waitAnd(fn, maxTries) {
    var tries = 0;
    (function tick() {
      tries++;
      if (fn()) return;
      if (tries > (maxTries || 120)) return;
      setTimeout(tick, 100);
    })();
  }

  registerHandlers();
  for (var r = 1; r <= 10; r++) setTimeout(registerHandlers, r * 200);
  waitAnd(registerSceditorCommands, 120);

  var patchTry = 0;
  (function patchTick() {
    patchTry++;
    patchInstances();
    if (patchTry < 60) setTimeout(patchTick, 150);
  })();
})();

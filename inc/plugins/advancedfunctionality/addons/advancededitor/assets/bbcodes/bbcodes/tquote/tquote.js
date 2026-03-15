(function (window, document) {
  'use strict';

  if (window.__afAeTquoteLoaded) return;
  window.__afAeTquoteLoaded = true;

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  var ID = 'tquote';
  var CMD = 'af_tquote';
  var FILLER_ATTR = 'data-af-tquote-filler';
  var TOOLBAR_ID = 'af-ae-tquote-toolbar';
  var INSTANCE_SCAN_DELAY = 1200;

  var DEFAULT_TQUOTE_OPTS = {
    side: 'left',
    accent: '#ffffff',
    bg: '#111111',
    text: '#ffffff'
  };

  var TQUOTE_COLOR_SWATCHES = [
    '#000000', '#434343', '#666666', '#999999', '#B7B7B7', '#CCCCCC', '#D9D9D9', '#EFEFEF', '#F3F3F3', '#FFFFFF',
    '#980000', '#FF0000', '#FF9900', '#FFFF00', '#00FF00', '#00FFFF', '#4A86E8', '#0000FF', '#9900FF', '#FF00FF',
    '#E6B8AF', '#F4CCCC', '#FCE5CD', '#FFF2CC', '#D9EAD3', '#D0E0E3', '#C9DAF8', '#CFE2F3', '#D9D2E9', '#EAD1DC',
    '#DD7E6B', '#EA9999', '#F9CB9C', '#FFE599', '#B6D7A8', '#A2C4C9', '#A4C2F4', '#9FC5E8', '#B4A7D6', '#D5A6BD',
    '#CC4125', '#E06666', '#F6B26B', '#FFD966', '#93C47D', '#76A5AF', '#6D9EEB', '#6FA8DC', '#8E7CC3', '#C27BA0',
    '#A61C00', '#CC0000', '#E69138', '#F1C232', '#6AA84F', '#45818E', '#3C78D8', '#3D85C6', '#674EA7', '#A64D79',
    '#85200C', '#990000', '#B45F06', '#BF9000', '#38761D', '#134F5C', '#1155CC', '#0B5394', '#351C75', '#741B47',
    '#5B0F00', '#660000', '#783F04', '#7F6000', '#274E13', '#0C343D', '#1C4587', '#073763', '#20124D', '#4C1130'
  ];

  var ICONS = {
    sideLeft: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 3h2v14H4V3zm4 2h8v10H8V5zm2 2v6h4V7h-4z"/></svg>',
    sideRight: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M14 3h2v14h-2V3zM4 5h8v10H4V5zm2 2v6h4V7H6z"/></svg>',
    accent: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 4h12v3H4V4zm0 5h4v7H4V9zm6 0h6v7h-6V9z"/></svg>',
    bg: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 4h12v12H4V4z"/></svg>',
    text: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 5h12v2h-5v8H9V7H4V5z"/></svg>',
    trash: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7 3h6l1 2h3v2H3V5h3l1-2zm-1 5h2v7H6V8zm4 0h2v7h-2V8zm4 0h2v7h-2V8z"/></svg>'
  };

  var dropdownState = {
    isOpen: false,
    root: null,
    dropdown: null,
    editor: null,
    originalCloseDropDown: null,
    allowProgrammaticClose: false
  };

  var toolbarState = {
    instance: null,
    container: null,
    frame: null,
    block: null,
    toolbar: null
  };

  function getSceditorRoot() {
    if (window.sceditor) return window.sceditor;
    if (window.jQuery && window.jQuery.sceditor) return window.jQuery.sceditor;
    return null;
  }

  function getJscolorCtor() {
    if (window.JSColor && typeof window.JSColor === 'function') return window.JSColor;
    if (window.jscolor && typeof window.jscolor === 'function') return window.jscolor;
    return null;
  }

  function asText(x) {
    return String(x == null ? '' : x);
  }

  function trim(x) {
    return asText(x).trim();
  }

  function escHtml(x) {
    return asText(x)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function normSide(x) {
    x = trim(x).toLowerCase();
    return (x === 'right' || x === 'r' || x === '2') ? 'right' : 'left';
  }

  function normHex(x) {
    var hex;

    x = trim(x);
    if (!x) return '';

    if (!/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(x)) {
      return '';
    }

    hex = x.slice(1).toLowerCase();

    if (hex.length === 3 || hex.length === 4) {
      hex = hex.split('').map(function (ch) {
        return ch + ch;
      }).join('');
    }

    return '#' + hex;
  }

  function getDefaultColor(name) {
    if (name === 'accent') return DEFAULT_TQUOTE_OPTS.accent;
    if (name === 'bg') return DEFAULT_TQUOTE_OPTS.bg;
    return DEFAULT_TQUOTE_OPTS.text;
  }

  function createColorControl(name, label, iconKey, value) {
    return '' +
      '<div class="af-tquote-ui-color" title="' + escHtml(label) + '">' +
        '<button type="button" class="af-tquote-ui-swatch" data-af-color-for="' + name + '" aria-label="' + escHtml(label) + '">' +
          '<span class="af-tquote-ui-swatch-icon">' + ICONS[iconKey] + '</span>' +
        '</button>' +
        '<input type="text" class="af-tquote-ui-value" data-af-color-input="' + name + '" value="' + escHtml(normHex(value) || getDefaultColor(name)) + '" maxlength="9">' +
      '</div>';
  }

  function buildTag(side, accent, bg, text) {
    side = normSide(side);
    accent = normHex(accent);
    bg = normHex(bg);
    text = normHex(text);

    var open = '[tquote side=' + side;
    if (accent) open += ' accent=' + accent;
    if (bg) open += ' bg=' + bg;
    if (text) open += ' text=' + text;
    open += ']';

    return { open: open, close: '[/tquote]' };
  }

  function parseTquoteAttrs(attrs) {
    attrs = attrs || {};

    return {
      side: normSide(attrs.side || attrs.defaultattr || DEFAULT_TQUOTE_OPTS.side),
      accent: normHex(attrs.accent || attrs.color || '') || DEFAULT_TQUOTE_OPTS.accent,
      bg: normHex(attrs.bg || attrs.background || '') || DEFAULT_TQUOTE_OPTS.bg,
      text: normHex(attrs.text || attrs.textcolor || attrs.fg || '') || DEFAULT_TQUOTE_OPTS.text
    };
  }

  function readBlockOptions(block) {
    if (!block || block.nodeType !== 1) {
      return {
        side: DEFAULT_TQUOTE_OPTS.side,
        accent: DEFAULT_TQUOTE_OPTS.accent,
        bg: DEFAULT_TQUOTE_OPTS.bg,
        text: DEFAULT_TQUOTE_OPTS.text
      };
    }

    var style = block.style || {};

    return {
      side: normSide(block.getAttribute('data-side') || DEFAULT_TQUOTE_OPTS.side),
      accent: normHex(
        block.getAttribute('data-accent') ||
        block.getAttribute('data-af-tquote-accent') ||
        style.getPropertyValue('--af-tq-accent') ||
        ''
      ) || DEFAULT_TQUOTE_OPTS.accent,
      bg: normHex(
        block.getAttribute('data-bg') ||
        block.getAttribute('data-af-tquote-bg') ||
        style.getPropertyValue('--af-tq-bg') ||
        ''
      ) || DEFAULT_TQUOTE_OPTS.bg,
      text: normHex(
        block.getAttribute('data-text') ||
        block.getAttribute('data-af-tquote-text') ||
        style.getPropertyValue('--af-tq-text') ||
        ''
      ) || DEFAULT_TQUOTE_OPTS.text
    };
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

  function isSourceMode(editor) {
    try {
      if (!editor) return false;
      if (typeof editor.inSourceMode === 'function') return !!editor.inSourceMode();
      if (typeof editor.sourceMode === 'function') return !!editor.sourceMode();
    } catch (e) {}
    return false;
  }

  function insertIntoTextarea(textarea, open, close) {
    if (!textarea) return false;

    var start = textarea.selectionStart || 0;
    var end = textarea.selectionEnd || 0;
    var val = String(textarea.value || '');
    var before = val.slice(0, start);
    var sel = val.slice(start, end);
    var after = val.slice(end);

    textarea.value = before + open + sel + close + after;

    var caret = sel.length
      ? before.length + open.length + sel.length + close.length
      : before.length + open.length;

    textarea.focus();
    textarea.setSelectionRange(caret, caret);

    try { textarea.dispatchEvent(new Event('input', { bubbles: true })); } catch (e0) {}
    try { textarea.dispatchEvent(new Event('change', { bubbles: true })); } catch (e1) {}

    return true;
  }

  function insertWrap(open, close, ctx) {
    var inst = getSceditorInstanceFromCtx(ctx);

    if (inst && typeof inst.insertText === 'function' && isSourceMode(inst)) {
      inst.insertText(open, close);
      if (typeof inst.updateOriginal === 'function') inst.updateOriginal();
      if (typeof inst.focus === 'function') inst.focus();
      return true;
    }

    var ta = getTextareaFromCtx(ctx);
    if (ta && (!inst || isSourceMode(inst))) {
      return insertIntoTextarea(ta, open, close);
    }

    return false;
  }

  function getEditorDom(editor) {
    if (!editor || typeof editor.getBody !== 'function') return null;

    var body = editor.getBody();
    if (!body || !body.ownerDocument) return null;

    var doc = body.ownerDocument;
    var win = doc.defaultView || window;
    var sel = win.getSelection ? win.getSelection() : null;

    if (!sel) return null;

    return {
      body: body,
      doc: doc,
      win: win,
      sel: sel
    };
  }

  function getCurrentRange(editor) {
    var dom = getEditorDom(editor);
    if (!dom || !dom.sel || dom.sel.rangeCount < 1) return null;

    try {
      return dom.sel.getRangeAt(0);
    } catch (e) {
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

  function closestTquoteBlock(node, stopNode) {
    while (node && node !== stopNode) {
      if (
        node.nodeType === 1 &&
        node.tagName &&
        node.tagName.toLowerCase() === 'blockquote' &&
        node.hasAttribute('data-af-tquote')
      ) {
        return node;
      }
      node = node.parentNode;
    }
    return null;
  }

  function removeZeroWidthTextNodes(root) {
    if (!root || !root.ownerDocument || !root.ownerDocument.createTreeWalker) return;

    var walker = root.ownerDocument.createTreeWalker(root, 4, null, false);
    var toRemove = [];
    var current;

    while ((current = walker.nextNode())) {
      if (asText(current.nodeValue).replace(/\u200B/g, '') === '') {
        toRemove.push(current);
      }
    }

    for (var i = 0; i < toRemove.length; i += 1) {
      if (toRemove[i].parentNode) {
        toRemove[i].parentNode.removeChild(toRemove[i]);
      }
    }
  }

  function hasMeaningfulContent(node) {
    if (!node) return false;

    if (node.nodeType === 3) {
      return trim(asText(node.nodeValue).replace(/\u200B/g, '').replace(/\u00a0/g, '')) !== '';
    }

    if (node.nodeType !== 1) {
      return false;
    }

    var tag = node.tagName.toLowerCase();

    if (tag === 'br') {
      return !(node.hasAttribute && node.hasAttribute(FILLER_ATTR));
    }

    if (/^(img|video|audio|iframe|object|embed|hr|table)$/i.test(tag)) {
      return true;
    }

    for (var i = 0; i < node.childNodes.length; i += 1) {
      if (hasMeaningfulContent(node.childNodes[i])) {
        return true;
      }
    }

    return false;
  }

  function cleanupTquoteBlock(block) {
    if (!block || block.nodeType !== 1) return;

    removeZeroWidthTextNodes(block);

    var fillers = block.querySelectorAll('br[' + FILLER_ATTR + ']');
    var meaningful = hasMeaningfulContent(block);

    if (meaningful) {
      for (var i = 0; i < fillers.length; i += 1) {
        if (fillers[i].parentNode) fillers[i].parentNode.removeChild(fillers[i]);
      }
    } else {
      if (!fillers.length) {
        var br = block.ownerDocument.createElement('br');
        br.setAttribute(FILLER_ATTR, '1');
        block.appendChild(br);
      } else {
        for (var j = 1; j < fillers.length; j += 1) {
          if (fillers[j].parentNode) fillers[j].parentNode.removeChild(fillers[j]);
        }
      }
    }
  }

  function unwrapNode(node) {
    if (!node || !node.parentNode) return;

    while (node.firstChild) {
      node.parentNode.insertBefore(node.firstChild, node);
    }

    node.parentNode.removeChild(node);
  }
  function removeTquoteBlock(block, editor) {
    if (!block || !block.parentNode) return false;

    var parent = block.parentNode;
    var doc = block.ownerDocument;
    var marker = doc.createElement('span');
    var child;
    var caretIndex;

    marker.setAttribute('data-af-tquote-caret', '1');
    marker.style.cssText = 'display:inline-block;width:0;height:0;overflow:hidden;line-height:0;font-size:0;';
    parent.insertBefore(marker, block);

    while (block.firstChild) {
      child = block.firstChild;

      if (
        child.nodeType === 1 &&
        child.tagName &&
        child.tagName.toLowerCase() === 'br' &&
        child.hasAttribute(FILLER_ATTR)
      ) {
        block.removeChild(child);
        continue;
      }

      parent.insertBefore(child, marker);
    }

    caretIndex = Array.prototype.indexOf.call(parent.childNodes, marker);

    parent.removeChild(block);

    if (marker.parentNode) {
      marker.parentNode.removeChild(marker);
    }

    if (editor) {
      syncEditor(editor);

      try {
        var dom = getEditorDom(editor);
        if (dom && dom.sel && parent && parent.isConnected) {
          var range = doc.createRange();
          var safeIndex = Math.max(0, Math.min(caretIndex, parent.childNodes.length));
          range.setStart(parent, safeIndex);
          range.collapse(true);
          dom.sel.removeAllRanges();
          dom.sel.addRange(range);
        }
      } catch (e) {}

      if (typeof editor.focus === 'function') {
        editor.focus();
      }
    }

    return true;
  }

  function removeCurrentTquoteFromToolbar() {
    if (!toolbarState.block || !toolbarState.instance) return;

    var block = toolbarState.block;
    var instance = toolbarState.instance;

    hideTquoteToolbar();
    removeTquoteBlock(block, instance);
  }
  function sameOptions(a, b) {
    return normSide(a.side) === normSide(b.side)
      && normHex(a.accent) === normHex(b.accent)
      && normHex(a.bg) === normHex(b.bg)
      && normHex(a.text) === normHex(b.text);
  }

  function normalizeNestedTquotes(root) {
    if (!root || !root.querySelectorAll) return;

    var nodes = root.querySelectorAll('blockquote[data-af-tquote]');

    for (var i = nodes.length - 1; i >= 0; i -= 1) {
      var node = nodes[i];
      var parent = node.parentNode;

      if (
        parent &&
        parent.nodeType === 1 &&
        parent.tagName &&
        parent.tagName.toLowerCase() === 'blockquote' &&
        parent.hasAttribute('data-af-tquote') &&
        sameOptions(readBlockOptions(parent), readBlockOptions(node))
      ) {
        unwrapNode(node);
      }
    }
  }

  function applyBlockOptions(block, opts) {
    if (!block) return;

    opts = opts || {};
    var side = normSide(opts.side || DEFAULT_TQUOTE_OPTS.side);
    var accent = normHex(opts.accent || '') || DEFAULT_TQUOTE_OPTS.accent;
    var bg = normHex(opts.bg || '') || DEFAULT_TQUOTE_OPTS.bg;
    var text = normHex(opts.text || '') || DEFAULT_TQUOTE_OPTS.text;

    block.setAttribute('data-af-tquote', '1');
    block.setAttribute('data-side', side);
    block.setAttribute('data-accent', accent);
    block.setAttribute('data-bg', bg);
    block.setAttribute('data-text', text);

    block.classList.remove('mycode_quote');
    block.classList.add('af-aqr-tquote');

    block.style.setProperty('--af-tq-accent', accent);
    block.style.setProperty('--af-tq-bg', bg);
    block.style.setProperty('--af-tq-text', text);
    block.style.color = text;
  }

  function syncEditor(editor) {
    if (!editor) return;

    try {
      var dom = getEditorDom(editor);
      if (dom && dom.body) {
        var blocks = dom.body.querySelectorAll('blockquote[data-af-tquote]');
        for (var i = 0; i < blocks.length; i += 1) {
          blocks[i].classList.remove('mycode_quote');
          cleanupTquoteBlock(blocks[i]);
        }
        normalizeNestedTquotes(dom.body);
      }
    } catch (e0) {}

    if (typeof editor.updateOriginal === 'function') {
      editor.updateOriginal();
    }

    if (typeof editor.focus === 'function') {
      editor.focus();
    }
  }

  function insertCollapsedTquote(editor, opts) {
    var dom = getEditorDom(editor);
    var range = getCurrentRange(editor);

    if (!dom || !range) return false;

    var block = dom.doc.createElement('blockquote');
    applyBlockOptions(block, opts);

    var br = dom.doc.createElement('br');
    br.setAttribute(FILLER_ATTR, '1');
    block.appendChild(br);

    range.deleteContents();
    range.insertNode(block);

    var newRange = dom.doc.createRange();
    newRange.setStart(block, 0);
    newRange.collapse(true);

    dom.sel.removeAllRanges();
    dom.sel.addRange(newRange);

    syncEditor(editor);
    return true;
  }

  function wrapSelectedRangeWithTquote(editor, opts) {
    var dom = getEditorDom(editor);
    var range = getCurrentRange(editor);

    if (!dom || !range || range.collapsed) return false;

    var fragment = range.extractContents();
    var block = dom.doc.createElement('blockquote');
    applyBlockOptions(block, opts);
    block.appendChild(fragment);

    range.insertNode(block);

    cleanupTquoteBlock(block);
    normalizeNestedTquotes(block);

    var newRange = dom.doc.createRange();
    newRange.selectNodeContents(block);

    dom.sel.removeAllRanges();
    dom.sel.addRange(newRange);

    syncEditor(editor);
    return true;
  }

  function applyTquoteWysiwyg(editor, opts) {
    if (!editor || isSourceMode(editor)) return false;

    var dom = getEditorDom(editor);
    if (!dom) return false;

    var range = getCurrentRange(editor);
    if (!range) {
      if (typeof editor.focus === 'function') editor.focus();
      range = getCurrentRange(editor);
    }
    if (!range) return false;

    var startBlock = closestTquoteBlock(range.startContainer, dom.body);
    var endBlock = closestTquoteBlock(range.endContainer, dom.body);

    if (range.collapsed) {
      if (startBlock) {
        applyBlockOptions(startBlock, opts);
        syncEditor(editor);
        return true;
      }

      return insertCollapsedTquote(editor, opts);
    }

    if (startBlock && endBlock && startBlock === endBlock) {
      applyBlockOptions(startBlock, opts);
      syncEditor(editor);
      return true;
    }

    return wrapSelectedRangeWithTquote(editor, opts);
  }

  function applyTquote(editorOrTextarea, opts) {
    opts = opts || {};

    if (
      editorOrTextarea &&
      typeof editorOrTextarea.getBody === 'function' &&
      !isSourceMode(editorOrTextarea)
    ) {
      ensureTquoteIframeCss(editorOrTextarea);
      return applyTquoteWysiwyg(editorOrTextarea, opts);
    }

    var tag = buildTag(opts.side, opts.accent, opts.bg, opts.text);
    return insertWrap(tag.open, tag.close, { sceditor: editorOrTextarea });
  }

  function getCurrentTquoteOptions(editor) {
    var dom = getEditorDom(editor);
    var range = getCurrentRange(editor);

    if (!dom || !range) {
      return {
        side: DEFAULT_TQUOTE_OPTS.side,
        accent: DEFAULT_TQUOTE_OPTS.accent,
        bg: DEFAULT_TQUOTE_OPTS.bg,
        text: DEFAULT_TQUOTE_OPTS.text
      };
    }

    var block = closestTquoteBlock(range.startContainer, dom.body);
    return block ? readBlockOptions(block) : {
      side: DEFAULT_TQUOTE_OPTS.side,
      accent: DEFAULT_TQUOTE_OPTS.accent,
      bg: DEFAULT_TQUOTE_OPTS.bg,
      text: DEFAULT_TQUOTE_OPTS.text
    };
  }

  function normalizeFormatContent(content) {
    content = asText(content)
      .replace(new RegExp('<br\\b[^>]*' + FILLER_ATTR + '[^>]*>', 'gi'), '')
      .replace(/\u200B/g, '');

    return content;
  }

  function stripOuterBbcodeTag(content, tagName, predicate) {
    var text = trim(content);
    var re = new RegExp(
      '^\\[' + tagName + '(?:=([^\\]]+)|\\s+([^\\]]+))?\\]([\\s\\S]*)\\[\\/' + tagName + '\\]$',
      'i'
    );
    var match = text.match(re);
    var attrRaw;
    var inner;

    if (!match) {
      return {
        changed: false,
        content: content
      };
    }

    attrRaw = trim(match[1] || match[2] || '');
    inner = trim(match[3] || '');

    if (typeof predicate === 'function' && !predicate(attrRaw, inner)) {
      return {
        changed: false,
        content: content
      };
    }

    return {
      changed: true,
      content: inner
    };
  }

  function normalizeTquoteSerializedContent(content, opts) {
    var result = trim(normalizeFormatContent(content));
    var prev = null;
    var guard = 0;
    var textColor = normHex((opts && opts.text) || '') || DEFAULT_TQUOTE_OPTS.text;

    while (result !== prev && guard < 12) {
      prev = result;
      guard += 1;

      result = stripOuterBbcodeTag(result, 'quote', function () {
        return true;
      }).content;

      result = stripOuterBbcodeTag(result, 'align', function (attrRaw) {
        var attr = trim(attrRaw).replace(/^["']|["']$/g, '').toLowerCase();
        return attr === 'justify';
      }).content;

      result = stripOuterBbcodeTag(result, 'color', function (attrRaw) {
        var attr = normHex(trim(attrRaw).replace(/^["']|["']$/g, ''));
        return !!attr && attr === textColor;
      }).content;
    }

    return trim(result);
  }

  function registerTquoteBbcode() {
    var sc = getSceditorRoot();

    if (!sc || !sc.formats || !sc.formats.bbcode || typeof sc.formats.bbcode.set !== 'function') {
      return false;
    }

    sc.formats.bbcode.set('tquote', {
      tags: {
        blockquote: {
          'data-af-tquote': null
        }
      },
      isInline: false,
      allowsEmpty: true,
      breakBefore: true,
      breakAfter: true,
      skipLastLineBreak: true,
      format: function (element, content) {
        var opts = readBlockOptions(element);
        var inner = normalizeTquoteSerializedContent(content, opts);

        if (!trim(inner)) {
          return '';
        }

        var tag = buildTag(opts.side, opts.accent, opts.bg, opts.text);
        return tag.open + inner + tag.close;
      },
      html: function (token, attrs, content) {
        var opts = parseTquoteAttrs(attrs);

        return '' +
          '<blockquote class="af-aqr-tquote" ' +
            'data-af-tquote="1" ' +
            'data-side="' + escHtml(opts.side) + '" ' +
            'data-accent="' + escHtml(opts.accent) + '" ' +
            'data-bg="' + escHtml(opts.bg) + '" ' +
            'data-text="' + escHtml(opts.text) + '" ' +
            'style="--af-tq-accent:' + escHtml(opts.accent) + ';--af-tq-bg:' + escHtml(opts.bg) + ';--af-tq-text:' + escHtml(opts.text) + ';color:' + escHtml(opts.text) + ';">' +
            asText(content) +
          '</blockquote>';
      }
    });

    return true;
  }

  function getIframeCss() {
    return '' +
      'blockquote.af-aqr-tquote[data-af-tquote="1"]{' +
        'position:relative;' +
        'display:block;' +
        'margin:10px 0;' +
        'padding:28px 34px;' +
        'border-radius:10px;' +
        'background:var(--af-tq-bg, rgba(255,255,255,.06));' +
        'color:var(--af-tq-text, #f5f7fa);' +
        'overflow:hidden;' +
        'text-align:justify;' +
        'border:none;' +
        'box-shadow:none;' +
      '}' +
      'blockquote.af-aqr-tquote[data-af-tquote="1"] *{' +
        'color:inherit;' +
      '}' +
      'blockquote.af-aqr-tquote[data-af-tquote="1"][data-side="left"]{' +
        'border-right:4px solid var(--af-tq-accent, rgba(255,255,255,.35));' +
      '}' +
      'blockquote.af-aqr-tquote[data-af-tquote="1"][data-side="right"]{' +
        'border-left:4px solid var(--af-tq-accent, rgba(255,255,255,.35));' +
      '}' +
      'blockquote.af-aqr-tquote[data-af-tquote="1"]::before{' +
        'content:"";' +
        'position:absolute;' +
        'top:-5px;' +
        'width:56px;' +
        'height:56px;' +
        'opacity:.16;' +
        'pointer-events:none;' +
        'background-color:var(--af-tq-accent, rgba(255,255,255,.35));' +
        '-webkit-mask-repeat:no-repeat;' +
        '-webkit-mask-position:center;' +
        '-webkit-mask-size:contain;' +
        'mask-repeat:no-repeat;' +
        'mask-position:center;' +
        'mask-size:contain;' +
        '-webkit-mask-image:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 64 64\'%3E%3Cpath fill=\'black\' d=\'M10 28c0-10 6-18 18-18v10c-6 0-8 3-8 8h8v20H10V28zm26 0c0-10 6-18 18-18v10c-6 0-8 3-8 8h8v20H36V28z\'/%3E%3C/svg%3E");' +
        'mask-image:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 64 64\'%3E%3Cpath fill=\'black\' d=\'M10 28c0-10 6-18 18-18v10c-6 0-8 3-8 8h8v20H10V28zm26 0c0-10 6-18 18-18v10c-6 0-8 3-8 8h8v20H36V28z\'/%3E%3C/svg%3E");' +
      '}' +
      'blockquote.af-aqr-tquote[data-af-tquote="1"][data-side="left"]::before{' +
        'left:2px;' +
        'transform:rotate(-190deg) scaleX(-1);' +
      '}' +
      'blockquote.af-aqr-tquote[data-af-tquote="1"][data-side="right"]::before{' +
        'right:2px;' +
        'transform:rotate(190deg);' +
      '}' +
      'blockquote.af-aqr-tquote[data-af-tquote="1"]::after{' +
        'content:"";' +
        'position:absolute;' +
        'inset:-40% -30%;' +
        'background:radial-gradient(closest-side, rgba(255,255,255,.10), transparent 60%);' +
        'opacity:.35;' +
        'pointer-events:none;' +
      '}';
  }

  function ensureTquoteIframeCss(editor) {
    var dom = getEditorDom(editor);
    if (!dom || !dom.doc || !dom.doc.head) return;

    if (dom.doc.getElementById('af-ae-tquote-style')) return;

    var style = dom.doc.createElement('style');
    style.id = 'af-ae-tquote-style';
    style.type = 'text/css';
    style.appendChild(dom.doc.createTextNode(getIframeCss()));
    dom.doc.head.appendChild(style);
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

  function isJscolorEventTarget(node) {
    if (!node) return false;

    if (node.nodeType !== 1) {
      node = node.parentNode;
    }

    if (!node || !node.closest) return false;

    return !!node.closest(
      '[data-af-tquote-color-popup="1"], .jscolor-picker, .jscolor-wrap, #jscolor-palette, [class*="jscolor"]'
    );
  }

  function bindJscolorPopupGuards() {
    var nodes = document.querySelectorAll(
      '.jscolor-picker, .jscolor-wrap, #jscolor-palette, [class*="jscolor"]'
    );

    for (var i = 0; i < nodes.length; i += 1) {
      var node = nodes[i];
      if (!node || node.nodeType !== 1) continue;

      node.__afTquoteJscolorPopupGuardBound = true;
      node.style.pointerEvents = 'auto';
      node.setAttribute('data-af-tquote-color-popup', '1');
    }
  }

  function isDropdownEventTarget(node) {
    if (!node) return false;

    if (node.nodeType !== 1) {
      node = node.parentNode;
    }

    if (!node || !node.closest) return false;

    if (dropdownState.root && dropdownState.root.contains(node)) return true;
    if (dropdownState.dropdown && dropdownState.dropdown.contains(node)) return true;

    return false;
  }

  function openDropdownState(root, dropdown, editor) {
    dropdownState.isOpen = true;
    dropdownState.root = root || null;
    dropdownState.dropdown = dropdown || null;
    dropdownState.editor = editor || null;
    dropdownState.allowProgrammaticClose = false;

    if (editor && typeof editor.closeDropDown === 'function' && !dropdownState.originalCloseDropDown) {
      dropdownState.originalCloseDropDown = editor.closeDropDown;

      editor.closeDropDown = function () {
        if (dropdownState.isOpen && !dropdownState.allowProgrammaticClose) {
          return false;
        }

        return dropdownState.originalCloseDropDown.apply(editor, arguments);
      };
    }
  }

  function restoreDropdownCloseDropDown() {
    if (dropdownState.editor && dropdownState.originalCloseDropDown) {
      dropdownState.editor.closeDropDown = dropdownState.originalCloseDropDown;
    }

    dropdownState.originalCloseDropDown = null;
    dropdownState.editor = null;
    dropdownState.allowProgrammaticClose = false;
  }

  function closeDropdownState() {
    restoreDropdownCloseDropDown();
    dropdownState.isOpen = false;
    dropdownState.root = null;
    dropdownState.dropdown = null;
  }

  function requestDropdownClose() {
    var editor = dropdownState.editor;

    if (!editor || !dropdownState.originalCloseDropDown) {
      closeDropdownState();
      return;
    }

    dropdownState.allowProgrammaticClose = true;

    try {
      dropdownState.originalCloseDropDown.call(editor, true);
    } catch (e) {}

    closeDropdownState();
  }

  function getColorInput(root, name) {
    return root.querySelector('[data-af-color-input="' + name + '"]');
  }

  function getColorSwatch(root, name) {
    return root.querySelector('[data-af-color-for="' + name + '"]');
  }

  function syncColorControls(root) {
    var names = ['accent', 'bg', 'text'];

    for (var i = 0; i < names.length; i += 1) {
      var name = names[i];
      var input = getColorInput(root, name);
      var swatch = getColorSwatch(root, name);
      var value = input ? (normHex(input.value) || getDefaultColor(name)) : getDefaultColor(name);

      if (input) input.value = value;
      if (swatch) swatch.style.background = value;
    }
  }

  function createJscolorInstance(root, input, swatch, onChange) {
    var Ctor = getJscolorCtor();
    var picker;
    var initialValue = normHex(input.value) || getDefaultColor(input.getAttribute('data-af-color-input'));

    function syncFromPicker(ctx) {
      var value = '';

      if (ctx && typeof ctx.toHEXAString === 'function') {
        try { value = ctx.toHEXAString(); } catch (e0) {}
      }

      value = normHex(value || input.value) || initialValue;
      input.value = value;
      syncColorControls(root);

      if (typeof onChange === 'function') {
        onChange();
      }
    }

    if (!root || !input || !swatch || !Ctor) return null;
    if (input.jscolor) return input.jscolor;

    try {
      picker = new Ctor(input, {
        value: initialValue,
        format: 'hexa',
        alphaChannel: true,
        hash: true,
        valueElement: input,
        previewElement: swatch,
        container: document.body,
        showOnClick: false,
        hideOnLeave: false,
        closeButton: true,
        position: 'top',
        smartPosition: true,
        palette: TQUOTE_COLOR_SWATCHES,
        paletteCols: 10,
        paletteHeight: 12,
        paletteSpacing: 4,
        width: 160,
        height: 86,
        zIndex: 100000,
        onInput: function () {
          syncFromPicker(this);
        },
        onChange: function () {
          syncFromPicker(this);
        }
      });

      input.jscolor = picker;

      if (typeof picker.fromString === 'function') {
        picker.fromString(initialValue);
      }

      if (typeof picker.hide === 'function') {
        try { picker.hide(); } catch (e1) {}
      }

      bindJscolorPopupGuards();
      return picker;
    } catch (e) {
      return null;
    }
  }

  function ensureJscolorForField(root, name, onChange) {
    var input = getColorInput(root, name);
    var swatch = getColorSwatch(root, name);
    var picker;

    if (!input || !swatch) return;
    if (input.__afTquoteJscolorBound) return;

    input.__afTquoteJscolorBound = true;
    input.value = normHex(input.value) || getDefaultColor(name);

    swatch.addEventListener('pointerdown', function (event) {
      event.preventDefault();
      event.stopPropagation();
    });

    swatch.addEventListener('mousedown', function (event) {
      event.preventDefault();
      event.stopPropagation();
    });

    swatch.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();

      picker = createJscolorInstance(root, input, swatch, onChange);
      if (picker && typeof picker.show === 'function') {
        try { picker.show(); } catch (e0) {}
        window.setTimeout(bindJscolorPopupGuards, 0);
        window.setTimeout(bindJscolorPopupGuards, 30);
        window.setTimeout(bindJscolorPopupGuards, 100);
      }
    });

    input.addEventListener('input', function () {
      var value = normHex(input.value) || getDefaultColor(name);
      input.value = value;
      if (input.jscolor && typeof input.jscolor.fromString === 'function') {
        try { input.jscolor.fromString(value); } catch (e1) {}
      }
      syncColorControls(root);
      if (typeof onChange === 'function') onChange();
    });

    input.addEventListener('change', function () {
      var value = normHex(input.value) || getDefaultColor(name);
      input.value = value;
      if (input.jscolor && typeof input.jscolor.fromString === 'function') {
        try { input.jscolor.fromString(value); } catch (e2) {}
      }
      syncColorControls(root);
      if (typeof onChange === 'function') onChange();
    });
  }

  function initColorPickers(root, onChange) {
    ensureJscolorForField(root, 'accent', onChange);
    ensureJscolorForField(root, 'bg', onChange);
    ensureJscolorForField(root, 'text', onChange);
    syncColorControls(root);
  }

  function setSideButtonsState(root, side) {
    var buttons = root.querySelectorAll('[data-af-tquote-side]');
    side = normSide(side);

    root.setAttribute('data-side', side);

    for (var i = 0; i < buttons.length; i += 1) {
      buttons[i].classList.toggle('is-active', buttons[i].getAttribute('data-af-tquote-side') === side);
    }
  }

  function findDropdownForNode(node) {
    var dropdown = node ? node.parentNode : null;
    var all;
    var i;

    if (dropdown && dropdown.classList && dropdown.classList.contains('sceditor-dropdown')) {
      return dropdown;
    }

    all = document.querySelectorAll('.sceditor-dropdown');
    for (i = all.length - 1; i >= 0; i -= 1) {
      if (all[i].contains(node)) {
        return all[i];
      }
    }

    return null;
  }

  function createDropdownNode(editor, caller, initialOpts) {
    initialOpts = initialOpts || {
      side: DEFAULT_TQUOTE_OPTS.side,
      accent: DEFAULT_TQUOTE_OPTS.accent,
      bg: DEFAULT_TQUOTE_OPTS.bg,
      text: DEFAULT_TQUOTE_OPTS.text
    };

    var wrap = document.createElement('div');
    var preview;
    var btnApply;
    var btnCancel;

    wrap.className = 'af-tquote-dd';
    wrap.innerHTML =
      '<div class="af-tquote-dd-head">' +
        '<div class="af-tquote-dd-title">Типографическая цитата</div>' +
      '</div>' +
      '<div class="af-tquote-dd-body">' +
        '<div class="af-tquote-dd-top">' +
          '<div class="af-tquote-dd-seg" role="group" aria-label="Сторона акцента">' +
            '<button type="button" class="af-tquote-dd-segbtn" data-af-tquote-side="left" title="Акцент справа">' + ICONS.sideLeft + '</button>' +
            '<button type="button" class="af-tquote-dd-segbtn" data-af-tquote-side="right" title="Акцент слева">' + ICONS.sideRight + '</button>' +
          '</div>' +
          '<div class="af-tquote-dd-colors">' +
            createColorControl('accent', 'Цвет акцента', 'accent', initialOpts.accent) +
            createColorControl('bg', 'Цвет фона', 'bg', initialOpts.bg) +
            createColorControl('text', 'Цвет текста', 'text', initialOpts.text) +
          '</div>' +
        '</div>' +
        '<div class="af-tquote-dd-preview" aria-hidden="true">' +
          '<div class="af-tquote-dd-previewbox">' +
            '<span class="af-tquote-dd-previewtext">Предпросмотр блока</span>' +
          '</div>' +
        '</div>' +
        '<div class="af-tquote-dd-actions">' +
          '<button type="button" class="button af-tquote-cancel">Отмена</button>' +
          '<button type="button" class="button af-tquote-apply">Применить</button>' +
        '</div>' +
      '</div>';

    preview = wrap.querySelector('.af-tquote-dd-previewbox');
    btnApply = wrap.querySelector('.af-tquote-apply');
    btnCancel = wrap.querySelector('.af-tquote-cancel');

    function currentOpts() {
      return {
        side: wrap.getAttribute('data-side') || DEFAULT_TQUOTE_OPTS.side,
        accent: normHex(getColorInput(wrap, 'accent').value) || DEFAULT_TQUOTE_OPTS.accent,
        bg: normHex(getColorInput(wrap, 'bg').value) || DEFAULT_TQUOTE_OPTS.bg,
        text: normHex(getColorInput(wrap, 'text').value) || DEFAULT_TQUOTE_OPTS.text
      };
    }

    function applyPreview() {
      var opts = currentOpts();

      preview.setAttribute('data-side', opts.side);
      preview.style.setProperty('--af-tq-accent', opts.accent);
      preview.style.setProperty('--af-tq-bg', opts.bg);
      preview.style.setProperty('--af-tq-text', opts.text);
      preview.style.color = opts.text;
    }

    function applyNow() {
      applyTquote(editor, currentOpts());
      requestDropdownClose();
    }

    wrap.addEventListener('click', function (event) {
      var sideBtn = event.target && event.target.closest ? event.target.closest('[data-af-tquote-side]') : null;
      if (!sideBtn) return;

      event.preventDefault();
      event.stopPropagation();

      setSideButtonsState(wrap, sideBtn.getAttribute('data-af-tquote-side'));
      applyPreview();
    });

    btnApply.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      applyNow();
    });

    btnCancel.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      requestDropdownClose();
    });

    wrap.addEventListener('keydown', function (event) {
      if (!event) return;
      if (event.key === 'Enter') {
        event.preventDefault();
        applyNow();
      }
    });

    setSideButtonsState(wrap, initialOpts.side || DEFAULT_TQUOTE_OPTS.side);
    initColorPickers(wrap, applyPreview);
    applyPreview();

    editor.createDropDown(caller, 'sceditor-tquote-picker', wrap);
    return wrap;
  }

  function openSceditorDropdown(editor, caller) {
    var wrap;
    var dropdown;

    if (!editor || typeof editor.createDropDown !== 'function') return false;

    hideTquoteToolbar();
    ensureTquoteIframeCss(editor);

    if (dropdownState.isOpen) {
      requestDropdownClose();
    }

    wrap = createDropdownNode(editor, caller, getCurrentTquoteOptions(editor));
    dropdown = findDropdownForNode(wrap);

    if (dropdown) {
      dropdown.style.overflow = 'visible';
      dropdown.style.maxWidth = 'none';
    }

    openDropdownState(wrap, dropdown || null, editor);
    return true;
  }

  function createToolbarNode() {
    var wrap = document.createElement('div');

    wrap.id = TOOLBAR_ID;
    wrap.className = 'af-tquote-toolbar';
    wrap.innerHTML =
      '<div class="af-tquote-toolbar__panel">' +
        '<div class="af-tquote-toolbar__seg" role="group" aria-label="Сторона акцента">' +
          '<button type="button" class="af-tquote-toolbar__segbtn" data-af-tquote-side="left" title="Акцент справа">' + ICONS.sideLeft + '</button>' +
          '<button type="button" class="af-tquote-toolbar__segbtn" data-af-tquote-side="right" title="Акцент слева">' + ICONS.sideRight + '</button>' +
        '</div>' +
        '<div class="af-tquote-toolbar__colors">' +
          createColorControl('accent', 'Цвет акцента', 'accent', DEFAULT_TQUOTE_OPTS.accent) +
          createColorControl('bg', 'Цвет фона', 'bg', DEFAULT_TQUOTE_OPTS.bg) +
          createColorControl('text', 'Цвет текста', 'text', DEFAULT_TQUOTE_OPTS.text) +
        '</div>' +
        '<button type="button" class="af-tquote-toolbar__segbtn af-tquote-toolbar__delete" data-af-tquote-action="remove" title="Убрать tquote">' +
          ICONS.trash +
        '</button>' +
      '</div>';

    wrap.addEventListener('click', function (event) {
      var deleteBtn = event.target && event.target.closest ? event.target.closest('[data-af-tquote-action="remove"]') : null;
      var sideBtn = event.target && event.target.closest ? event.target.closest('[data-af-tquote-side]') : null;

      if (deleteBtn) {
        event.preventDefault();
        event.stopPropagation();
        removeCurrentTquoteFromToolbar();
        return;
      }

      if (!sideBtn) return;

      event.preventDefault();
      event.stopPropagation();

      setSideButtonsState(wrap, sideBtn.getAttribute('data-af-tquote-side'));
      applyToolbarToBlock();
    });

    initColorPickers(wrap, function () {
      applyToolbarToBlock();
    });

    return wrap;
  }

  function ensureTquoteToolbar() {
    if (toolbarState.toolbar) return toolbarState.toolbar;
    toolbarState.toolbar = createToolbarNode();
    return toolbarState.toolbar;
  }

  function populateTquoteToolbar(block) {
    var toolbar = ensureTquoteToolbar();
    var opts = readBlockOptions(block);

    setSideButtonsState(toolbar, opts.side);
    getColorInput(toolbar, 'accent').value = opts.accent;
    getColorInput(toolbar, 'bg').value = opts.bg;
    getColorInput(toolbar, 'text').value = opts.text;
    syncColorControls(toolbar);
  }

  function positionTquoteToolbar(container, frame, block) {
    var toolbar = ensureTquoteToolbar();

    if (toolbar.parentNode !== container) {
      container.appendChild(toolbar);
    }

    container.classList.add('af-ae-has-tquote-toolbar');
    toolbar.classList.add('is-visible');

    var containerRect = container.getBoundingClientRect();
    var frameRect = frame.getBoundingClientRect();
    var blockRect = block.getBoundingClientRect();

    var left = frameRect.left - containerRect.left + blockRect.left;
    var top = frameRect.top - containerRect.top + blockRect.bottom + 8;

    var maxLeft = frameRect.left - containerRect.left + frameRect.width - toolbar.offsetWidth - 8;
    if (left > maxLeft) left = maxLeft;
    if (left < 8) left = 8;

    var maxTop = frameRect.top - containerRect.top + frameRect.height - toolbar.offsetHeight - 8;
    if (top > maxTop) {
      top = frameRect.top - containerRect.top + blockRect.top - toolbar.offsetHeight - 8;
    }
    if (top < 8) top = 8;

    toolbar.style.left = left + 'px';
    toolbar.style.top = top + 'px';
  }

  function showTquoteToolbar(instance, block) {
    if (!instance || !block) return;

    var container = getEditorContainer(instance);
    var frame = getEditorFrame(instance);

    if (!container || !frame) return;

    toolbarState.instance = instance;
    toolbarState.container = container;
    toolbarState.frame = frame;
    toolbarState.block = block;

    populateTquoteToolbar(block);
    positionTquoteToolbar(container, frame, block);
  }

  function hideTquoteToolbar() {
    var toolbar = toolbarState.toolbar;

    if (toolbar) {
      toolbar.classList.remove('is-visible');
    }

    if (toolbarState.container) {
      toolbarState.container.classList.remove('af-ae-has-tquote-toolbar');
    }

    toolbarState.instance = null;
    toolbarState.container = null;
    toolbarState.frame = null;
    toolbarState.block = null;
  }

  function applyToolbarToBlock() {
    if (!toolbarState.toolbar || !toolbarState.block || !toolbarState.instance) return;

    var toolbar = toolbarState.toolbar;
    var block = toolbarState.block;

    applyBlockOptions(block, {
      side: toolbar.getAttribute('data-side') || DEFAULT_TQUOTE_OPTS.side,
      accent: normHex(getColorInput(toolbar, 'accent').value) || DEFAULT_TQUOTE_OPTS.accent,
      bg: normHex(getColorInput(toolbar, 'bg').value) || DEFAULT_TQUOTE_OPTS.bg,
      text: normHex(getColorInput(toolbar, 'text').value) || DEFAULT_TQUOTE_OPTS.text
    });

    syncColorControls(toolbar);
    syncEditor(toolbarState.instance);

    if (block.isConnected && toolbarState.container && toolbarState.frame) {
      populateTquoteToolbar(block);
      positionTquoteToolbar(toolbarState.container, toolbarState.frame, block);
    }
  }

  function handleBodyClick(instance, event) {
    var block = closestTquoteBlock(event.target, null);
    if (!block) {
      hideTquoteToolbar();
      return;
    }

    showTquoteToolbar(instance, block);
  }

  function handleSelectionState(instance) {
    if (!instance) return;

    if (typeof instance.inSourceMode === 'function' && instance.inSourceMode()) {
      hideTquoteToolbar();
      return;
    }

    if (typeof instance.currentNode !== 'function') return;

    var node = instance.currentNode();
    var block = closestTquoteBlock(node, null);

    if (!block) {
      hideTquoteToolbar();
      return;
    }

    showTquoteToolbar(instance, block);
  }

  function patchSceditorTquoteCommand() {
    if (!window.jQuery) return false;
    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.command) return false;

    function fallbackApply(ed) {
      applyTquote(ed, {
        side: DEFAULT_TQUOTE_OPTS.side,
        accent: DEFAULT_TQUOTE_OPTS.accent,
        bg: DEFAULT_TQUOTE_OPTS.bg,
        text: DEFAULT_TQUOTE_OPTS.text
      });
    }

    $.sceditor.command.set(CMD, {
      exec: function (caller) {
        if (!openSceditorDropdown(this, caller)) fallbackApply(this);
      },
      txtExec: function (caller) {
        if (!openSceditorDropdown(this, caller)) fallbackApply(this);
      },
      tooltip: 'Типографическая цитата'
    });

    $.sceditor.command.set('tquote', {
      exec: function (caller) {
        if (!openSceditorDropdown(this, caller)) fallbackApply(this);
      },
      txtExec: function (caller) {
        if (!openSceditorDropdown(this, caller)) fallbackApply(this);
      },
      tooltip: 'Типографическая цитата'
    });

    return true;
  }

  function enhanceEditorInstance(instance) {
    if (!instance || instance.__afTquoteEnhanced) return;

    var body = typeof instance.getBody === 'function' ? instance.getBody() : null;
    if (!body) return;

    instance.__afTquoteEnhanced = true;
    ensureTquoteIframeCss(instance);

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
          var active = document.activeElement;
          var toolbar = toolbarState.toolbar;

          if (dropdownState.isOpen) return;
          if (toolbar && toolbar.contains(active)) return;
          if (isJscolorEventTarget(active) || hasVisibleJscolorPopup()) return;

          hideTquoteToolbar();
        }, 0);
        return;
      }

      handleSelectionState(instance);
    });
  }

  function enhanceAllEditors() {
    var sc = getSceditorRoot();
    if (!sc || typeof sc.instance !== 'function') return;

    var textareas = document.querySelectorAll('textarea');
    for (var i = 0; i < textareas.length; i += 1) {
      try {
        var editor = sc.instance(textareas[i]);
        if (editor) {
          enhanceEditorInstance(editor);
        }
      } catch (e) {}
    }
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

    applyTquote(editor || getTextareaFromCtx(ctx), {
      side: DEFAULT_TQUOTE_OPTS.side,
      accent: DEFAULT_TQUOTE_OPTS.accent,
      bg: DEFAULT_TQUOTE_OPTS.bg,
      text: DEFAULT_TQUOTE_OPTS.text
    });
  }

  var handlerObj = {
    id: ID,
    title: 'Типографическая цитата',
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
      return;
    }

    applyTquote(editor, {
      side: DEFAULT_TQUOTE_OPTS.side,
      accent: DEFAULT_TQUOTE_OPTS.accent,
      bg: DEFAULT_TQUOTE_OPTS.bg,
      text: DEFAULT_TQUOTE_OPTS.text
    });
  }

  function registerHandlers() {
    window.afAqrBuiltinHandlers[ID] = handlerObj;
    window.afAqrBuiltinHandlers[CMD] = handlerObj;

    window.afAeBuiltinHandlers[ID] = handlerFn;
    window.afAeBuiltinHandlers[CMD] = handlerFn;
  }

  document.addEventListener('mousedown', function (event) {
    if (!dropdownState.isOpen) return;

    if (isDropdownEventTarget(event.target)) return;
    if (isJscolorEventTarget(event.target)) return;
    if (hasVisibleJscolorPopup()) return;

    event.preventDefault();
    event.stopPropagation();
  }, true);

  document.addEventListener('click', function (event) {
    if (!dropdownState.isOpen) return;

    if (isDropdownEventTarget(event.target)) return;
    if (isJscolorEventTarget(event.target)) return;
    if (hasVisibleJscolorPopup()) return;

    event.preventDefault();
    event.stopPropagation();
  }, true);

  document.addEventListener('mousedown', function (event) {
    var toolbar = toolbarState.toolbar;
    var frame = toolbarState.frame;

    if (hasVisibleJscolorPopup()) return;
    if (isJscolorEventTarget(event.target)) return;
    if (toolbar && toolbar.contains(event.target)) return;
    if (frame && event.target === frame) return;

    if (toolbar && toolbar.classList.contains('is-visible')) {
      hideTquoteToolbar();
    }
  });

  registerHandlers();
  waitAnd(registerTquoteBbcode, 150);
  waitAnd(patchSceditorTquoteCommand, 150);
  waitAnd(function () {
    enhanceAllEditors();
    return false;
  }, 20);

  window.setInterval(enhanceAllEditors, INSTANCE_SCAN_DELAY);

  for (var i = 1; i <= 20; i += 1) {
    setTimeout(registerHandlers, i * 250);
    setTimeout(enhanceAllEditors, i * 300);
  }
})(window, document);

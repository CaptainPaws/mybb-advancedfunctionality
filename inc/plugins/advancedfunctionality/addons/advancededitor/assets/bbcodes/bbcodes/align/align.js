(function (window, document) {
  'use strict';

  if (window.__afAeAlignPackLoaded) return;
  window.__afAeAlignPackLoaded = true;

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);
  if (!Array.isArray(window.afAeBbcodeNormalizers)) window.afAeBbcodeNormalizers = [];

  var PACK_ID = 'align';
  var FILLER_ATTR = 'data-af-align-filler';
  var BLOCK_SELECTOR = 'div,p,li,blockquote,pre,td,th,h1,h2,h3,h4,h5,h6';

  function getSceditorRoot() {
    if (window.sceditor) return window.sceditor;
    if (window.jQuery && window.jQuery.sceditor) return window.jQuery.sceditor;
    return null;
  }

  function asText(value) {
    return String(value == null ? '' : value);
  }

  function trim(value) {
    return asText(value).trim();
  }

  function escHtml(value) {
    return asText(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function stripInvisible(value) {
    return asText(value).replace(/\u200B/g, '');
  }

  function normalizeAlign(value) {
    value = trim(value).toLowerCase();

    if (value === 'start') return 'left';
    if (value === 'end') return 'right';

    if (value === 'left' || value === 'center' || value === 'right' || value === 'justify') {
      return value;
    }

    return '';
  }

  function normalizeLegacyAlignBbcode(value) {
    value = asText(value);

    value = value.replace(/\[left\]([\s\S]*?)\[\/left\]/gi, '[align=left]$1[/align]');
    value = value.replace(/\[center\]([\s\S]*?)\[\/center\]/gi, '[align=center]$1[/align]');
    value = value.replace(/\[right\]([\s\S]*?)\[\/right\]/gi, '[align=right]$1[/align]');
    value = value.replace(/\[justify\]([\s\S]*?)\[\/justify\]/gi, '[align=justify]$1[/align]');

    value = value.replace(/\[(\/?)left\]/gi, '[$1align=left]').replace(/\[\/align=left\]/gi, '[/align]');
    value = value.replace(/\[(\/?)center\]/gi, '[$1align=center]').replace(/\[\/align=center\]/gi, '[/align]');
    value = value.replace(/\[(\/?)right\]/gi, '[$1align=right]').replace(/\[\/align=right\]/gi, '[/align]');
    value = value.replace(/\[(\/?)justify\]/gi, '[$1align=justify]').replace(/\[\/align=justify\]/gi, '[/align]');

    return value;
  }

  function collapseSameAlignBbcode(value) {
    value = normalizeLegacyAlignBbcode(asText(value));

    var guard = 0;
    var changed = true;

    while (changed && guard < 30) {
      guard += 1;
      changed = false;

      value = value.replace(
        /\[align=([^\]]+)\]\s*\[align=([^\]]+)\]([\s\S]*?)\[\/align\]\s*\[\/align\]/gi,
        function (full, a1, a2, inner) {
          var outer = normalizeAlign(a1);
          var innerAlign = normalizeAlign(a2);

          if (outer && outer === innerAlign) {
            changed = true;
            return '[align=' + outer + ']' + inner + '[/align]';
          }

          return full;
        }
      );
    }

    return value;
  }

  function normalizeAllAlignBbcode(value) {
    return collapseSameAlignBbcode(normalizeLegacyAlignBbcode(value));
  }

  function registerNormalizer() {
    if (window.__afAeAlignNormalizerRegistered) return;
    window.__afAeAlignNormalizerRegistered = true;
    window.afAeBbcodeNormalizers.push(normalizeAllAlignBbcode);
  }

  function unwrapSameOuterAlignBbcode(content, expectedAlign) {
    content = normalizeAllAlignBbcode(content);
    expectedAlign = normalizeAlign(expectedAlign);

    if (!content || !expectedAlign) {
      return content;
    }

    var guard = 0;
    var pattern = /^\s*\[align\s*=\s*([^\]]+)\]([\s\S]*?)\[\/align\]\s*$/i;

    while (guard < 20) {
      guard += 1;

      var match = content.match(pattern);
      if (!match) break;

      var foundAlign = normalizeAlign(match[1]);
      if (foundAlign !== expectedAlign) break;

      content = asText(match[2]);
    }

    return content;
  }

  function resolveEditor(ctx) {
    if (!ctx) return null;
    if (typeof ctx.insert === 'function' || typeof ctx.insertText === 'function') return ctx;
    if (ctx.editor && (typeof ctx.editor.insert === 'function' || typeof ctx.editor.insertText === 'function')) return ctx.editor;
    if (ctx.instance && (typeof ctx.instance.insert === 'function' || typeof ctx.instance.insertText === 'function')) return ctx.instance;
    if (ctx.sceditor && (typeof ctx.sceditor.insert === 'function' || typeof ctx.sceditor.insertText === 'function')) return ctx.sceditor;
    if (ctx.target && (typeof ctx.target.insert === 'function' || typeof ctx.target.insertText === 'function')) return ctx.target;
    if (ctx.textarea && ctx.textarea.nodeName && ctx.textarea.nodeName.toLowerCase() === 'textarea') return ctx.textarea;
    return null;
  }

  function getTextareaFromCtx(ctx) {
    if (ctx && ctx.textarea && ctx.textarea.nodeType === 1) return ctx.textarea;
    if (ctx && ctx.ta && ctx.ta.nodeType === 1) return ctx.ta;

    var active = document.activeElement;
    if (active && active.tagName === 'TEXTAREA') return active;

    return document.querySelector('textarea#message') ||
      document.querySelector('textarea[name="message"]') ||
      null;
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
    var value = textarea.value || '';
    var before = value.slice(0, start);
    var selected = value.slice(start, end);
    var after = value.slice(end);

    textarea.value = before + open + selected + close + after;

    var caret;
    if (selected.length) {
      caret = before.length + open.length + selected.length + close.length;
    } else {
      caret = before.length + open.length;
    }

    textarea.focus();
    textarea.selectionStart = textarea.selectionEnd = caret;

    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    textarea.dispatchEvent(new Event('change', { bubbles: true }));

    return true;
  }

  function insertAlignSource(editorOrTextarea, align) {
    align = normalizeAlign(align);
    if (!align) return false;

    var open = '[align=' + align + ']';
    var close = '[/align]';

    if (!editorOrTextarea) return false;

    if (typeof editorOrTextarea.insertText === 'function') {
      editorOrTextarea.insertText(open, close);

      if (typeof editorOrTextarea.updateOriginal === 'function') {
        editorOrTextarea.updateOriginal();
      }

      if (typeof editorOrTextarea.focus === 'function') {
        editorOrTextarea.focus();
      }

      return true;
    }

    if (editorOrTextarea.nodeName && editorOrTextarea.nodeName.toLowerCase() === 'textarea') {
      return insertIntoTextarea(editorOrTextarea, open, close);
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

  function isBlockElement(node) {
    if (!node || node.nodeType !== 1 || !node.tagName) return false;

    var tag = node.tagName.toLowerCase();
    return /^(div|p|li|blockquote|pre|td|th|h1|h2|h3|h4|h5|h6)$/i.test(tag);
  }

  function closestEditableBlock(node, stopNode) {
    while (node && node !== stopNode) {
      if (isBlockElement(node)) {
        return node;
      }
      node = node.parentNode;
    }
    return null;
  }

  function closestAlignBlock(node, stopNode) {
    while (node && node !== stopNode) {
      if (
        node.nodeType === 1 &&
        node.hasAttribute &&
        node.hasAttribute('data-af-align') &&
        isBlockElement(node)
      ) {
        return node;
      }
      node = node.parentNode;
    }
    return null;
  }

  function applyBlockAlign(block, align) {
    if (!block || block.nodeType !== 1) return;

    align = normalizeAlign(align);
    if (!align) return;

    block.classList.add('af-bb-align');
    block.setAttribute('data-af-align', align);
    block.style.textAlign = align;
  }

  function unwrapNode(node) {
    if (!node || !node.parentNode) return;

    while (node.firstChild) {
      node.parentNode.insertBefore(node.firstChild, node);
    }

    node.parentNode.removeChild(node);
  }

  function removeZeroWidthTextNodes(root) {
    if (!root || !root.ownerDocument || !root.ownerDocument.createTreeWalker) return;

    var walker = root.ownerDocument.createTreeWalker(root, 4, null, false);
    var toRemove = [];
    var current;

    while ((current = walker.nextNode())) {
      if (stripInvisible(current.nodeValue) === '') {
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
      return trim(stripInvisible(node.nodeValue).replace(/\u00a0/g, '')) !== '';
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

  function cleanupAlignBlock(block) {
    if (!block || block.nodeType !== 1) return;

    removeZeroWidthTextNodes(block);

    var fillers = block.querySelectorAll('br[' + FILLER_ATTR + ']');
    var meaningful = hasMeaningfulContent(block);

    if (meaningful) {
      for (var i = 0; i < fillers.length; i += 1) {
        if (fillers[i].parentNode) {
          fillers[i].parentNode.removeChild(fillers[i]);
        }
      }
    } else {
      if (!fillers.length) {
        var br = block.ownerDocument.createElement('br');
        br.setAttribute(FILLER_ATTR, '1');
        block.appendChild(br);
      } else {
        for (var j = 1; j < fillers.length; j += 1) {
          if (fillers[j].parentNode) {
            fillers[j].parentNode.removeChild(fillers[j]);
          }
        }
      }
    }
  }

  function normalizeNestedAlignBlocks(root) {
    if (!root || !root.querySelectorAll) return;

    var nodes = root.querySelectorAll('[data-af-align]');

    for (var i = nodes.length - 1; i >= 0; i -= 1) {
      var node = nodes[i];
      var parent = node.parentNode;

      if (
        parent &&
        parent.nodeType === 1 &&
        parent.hasAttribute &&
        parent.hasAttribute('data-af-align') &&
        normalizeAlign(parent.getAttribute('data-af-align')) === normalizeAlign(node.getAttribute('data-af-align'))
      ) {
        unwrapNode(node);
      }
    }
  }

  function cleanupEditorAlignArtifacts(editor) {
    var dom = getEditorDom(editor);
    if (!dom || !dom.body) return;

    var blocks = dom.body.querySelectorAll('[data-af-align]');
    for (var i = 0; i < blocks.length; i += 1) {
      cleanupAlignBlock(blocks[i]);
    }

    normalizeNestedAlignBlocks(dom.body);
  }

  function syncEditor(editor) {
    if (!editor) return;

    cleanupEditorAlignArtifacts(editor);

    if (typeof editor.updateOriginal === 'function') {
      editor.updateOriginal();
    }

    if (typeof editor.focus === 'function') {
      editor.focus();
    }
  }

  function getSelectedBlocks(editor, range, dom) {
    var out = [];
    var seen = [];

    function push(node) {
      if (!node || node.nodeType !== 1) return;
      if (!isBlockElement(node)) return;
      if (seen.indexOf(node) !== -1) return;
      seen.push(node);
      out.push(node);
    }

    var startBlock = closestEditableBlock(range.startContainer, dom.body);
    var endBlock = closestEditableBlock(range.endContainer, dom.body);

    push(startBlock);
    push(endBlock);

    try {
      var all = dom.body.querySelectorAll(BLOCK_SELECTOR);
      for (var i = 0; i < all.length; i += 1) {
        var block = all[i];

        try {
          if (range.intersectsNode(block)) {
            push(block);
          }
        } catch (e) {}
      }
    } catch (e2) {}

    return out;
  }

  function insertEmptyAlignBlock(editor, align) {
    var dom = getEditorDom(editor);
    var range = getCurrentRange(editor);

    if (!dom || !range) return false;

    var block = dom.doc.createElement('div');
    applyBlockAlign(block, align);

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

  function wrapSelectedRangeWithAlign(editor, align) {
    var dom = getEditorDom(editor);
    var range = getCurrentRange(editor);

    if (!dom || !range || range.collapsed) return false;

    var fragment = range.extractContents();
    var block = dom.doc.createElement('div');
    applyBlockAlign(block, align);
    block.appendChild(fragment);

    range.insertNode(block);

    cleanupAlignBlock(block);
    normalizeNestedAlignBlocks(block);

    var newRange = dom.doc.createRange();
    newRange.selectNodeContents(block);

    dom.sel.removeAllRanges();
    dom.sel.addRange(newRange);

    syncEditor(editor);
    return true;
  }

  function applyAlignWysiwyg(editor, align) {
    align = normalizeAlign(align);
    if (!align) return false;

    if (!editor || isSourceMode(editor)) {
      return false;
    }

    var dom = getEditorDom(editor);
    if (!dom) return false;

    var range = getCurrentRange(editor);
    if (!range) {
      if (typeof editor.focus === 'function') editor.focus();
      range = getCurrentRange(editor);
    }
    if (!range) return false;

    if (range.collapsed) {
      var block = closestEditableBlock(range.startContainer, dom.body) ||
        closestAlignBlock(range.startContainer, dom.body);

      if (block) {
        applyBlockAlign(block, align);
        syncEditor(editor);
        return true;
      }

      return insertEmptyAlignBlock(editor, align);
    }

    var blocks = getSelectedBlocks(editor, range, dom);

    if (blocks.length) {
      for (var i = 0; i < blocks.length; i += 1) {
        applyBlockAlign(blocks[i], align);
      }

      syncEditor(editor);
      return true;
    }

    return wrapSelectedRangeWithAlign(editor, align);
  }

  function applyAlign(editorOrTextarea, align) {
    align = normalizeAlign(align);
    if (!align) return false;

    if (
      editorOrTextarea &&
      typeof editorOrTextarea.getBody === 'function' &&
      !isSourceMode(editorOrTextarea)
    ) {
      return applyAlignWysiwyg(editorOrTextarea, align);
    }

    return insertAlignSource(editorOrTextarea, align);
  }

  function normalizeFormatContent(content, align) {
    var cleaned = stripInvisible(content);
    cleaned = normalizeAllAlignBbcode(cleaned);
    cleaned = unwrapSameOuterAlignBbcode(cleaned, align);

    if (/^(?:\s|(?:\[br\]|\[br\/\]|<br\s*\/?>|&nbsp;))+$/i.test(cleaned)) {
      return '';
    }

    return cleaned;
  }

  function makeAlignFormat(defaultAlign) {
    return {
      isInline: false,
      allowsEmpty: true,
      breakBefore: true,
      breakAfter: true,
      skipLastLineBreak: true,
      format: function (element, content) {
        var raw = defaultAlign || trim(
          (element && element.getAttribute ? element.getAttribute('data-af-align') : '') ||
          (element && element.style ? element.style.textAlign : '') ||
          ''
        );

        var align = normalizeAlign(raw);
        var cleanedContent = normalizeFormatContent(content, align);

        if (!align) {
          return cleanedContent;
        }

        if (!trim(cleanedContent)) {
          return '';
        }

        return '[align=' + align + ']' + cleanedContent + '[/align]';
      },
      html: function (token, attrs, content) {
        var align = normalizeAlign(defaultAlign || (attrs && (attrs.defaultattr || attrs.align)) || '');
        if (!align) {
          return content;
        }

        return '<div class="af-bb-align" data-af-align="' + escHtml(align) + '" style="text-align:' + escHtml(align) + ';">' + asText(content) + '</div>';
      }
    };
  }

  function registerBbcodeFormat() {
    var sc = getSceditorRoot();

    if (!sc || !sc.formats || !sc.formats.bbcode || typeof sc.formats.bbcode.set !== 'function') {
      return false;
    }

    sc.formats.bbcode.set('align', {
      tags: {
        div: { 'data-af-align': null },
        p: { 'data-af-align': null },
        li: { 'data-af-align': null },
        blockquote: { 'data-af-align': null },
        pre: { 'data-af-align': null },
        td: { 'data-af-align': null },
        th: { 'data-af-align': null },
        h1: { 'data-af-align': null },
        h2: { 'data-af-align': null },
        h3: { 'data-af-align': null },
        h4: { 'data-af-align': null },
        h5: { 'data-af-align': null },
        h6: { 'data-af-align': null }
      },
      styles: {
        'text-align': 'defaultattr'
      },
      isInline: false,
      allowsEmpty: true,
      breakBefore: true,
      breakAfter: true,
      skipLastLineBreak: true,
      format: function (element, content) {
        var raw = trim(
          (element && element.getAttribute ? element.getAttribute('data-af-align') : '') ||
          (element && element.style ? element.style.textAlign : '') ||
          ''
        );

        var align = normalizeAlign(raw);
        var cleanedContent = normalizeFormatContent(content, align);

        if (!align) {
          return cleanedContent;
        }

        if (!trim(cleanedContent)) {
          return '';
        }

        return '[align=' + align + ']' + cleanedContent + '[/align]';
      },
      html: function (token, attrs, content) {
        attrs = attrs || {};

        var align = normalizeAlign(attrs.defaultattr || attrs.align || '');
        if (!align) {
          return content;
        }

        return '<div class="af-bb-align" data-af-align="' + escHtml(align) + '" style="text-align:' + escHtml(align) + ';">' + asText(content) + '</div>';
      }
    });

    sc.formats.bbcode.set('left', Object.assign(makeAlignFormat('left'), {
      styles: { 'text-align': ['left'] }
    }));

    sc.formats.bbcode.set('center', Object.assign(makeAlignFormat('center'), {
      styles: { 'text-align': ['center'] }
    }));

    sc.formats.bbcode.set('right', Object.assign(makeAlignFormat('right'), {
      styles: { 'text-align': ['right'] }
    }));

    sc.formats.bbcode.set('justify', Object.assign(makeAlignFormat('justify'), {
      styles: { 'text-align': ['justify'] }
    }));

    return true;
  }

  function makeCommand(align, tooltip) {
    return {
      exec: function () {
        applyAlign(this, align);
      },
      txtExec: function () {
        applyAlign(this, align);
      },
      tooltip: tooltip
    };
  }

  function registerCommands() {
    var sc = getSceditorRoot();

    if (!sc || !sc.command || typeof sc.command.set !== 'function') {
      return false;
    }

    sc.command.set('left', makeCommand('left', 'По левому краю'));
    sc.command.set('center', makeCommand('center', 'По центру'));
    sc.command.set('right', makeCommand('right', 'По правому краю'));
    sc.command.set('justify', makeCommand('justify', 'По ширине'));

    return true;
  }

  function enhanceEditorInstance(editor) {
    if (!editor || editor.__afAeAlignEnhanced) return;

    var dom = getEditorDom(editor);
    if (!dom || !dom.body) return;

    editor.__afAeAlignEnhanced = true;

    dom.body.addEventListener('input', function () {
      cleanupEditorAlignArtifacts(editor);
    });

    dom.body.addEventListener('keyup', function () {
      cleanupEditorAlignArtifacts(editor);
    });

    dom.body.addEventListener('mouseup', function () {
      cleanupEditorAlignArtifacts(editor);
    });

    if (typeof editor.bind === 'function') {
      editor.bind('valuechanged nodechanged selectionchanged', function () {
        cleanupEditorAlignArtifacts(editor);
      });
    }
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

  function registerBuiltinHandlers() {
    function makeHandler(align) {
      return function (ctx) {
        var editor = resolveEditor(ctx) || resolveEditor({ textarea: getTextareaFromCtx(ctx) });
        if (!editor) return false;
        return applyAlign(editor, align);
      };
    }

    function makeAqrHandler(id, title, align) {
      var fn = makeHandler(align);

      return {
        id: id,
        title: title,
        onClick: function (ctx) { return fn(ctx); },
        click: function (ctx) { return fn(ctx); },
        action: function (ctx) { return fn(ctx); },
        run: function (ctx) { return fn(ctx); },
        init: function () {}
      };
    }

    window.afAeBuiltinHandlers.left = makeHandler('left');
    window.afAeBuiltinHandlers.center = makeHandler('center');
    window.afAeBuiltinHandlers.right = makeHandler('right');
    window.afAeBuiltinHandlers.justify = makeHandler('justify');

    window.afAqrBuiltinHandlers.left = makeAqrHandler('left', 'По левому краю', 'left');
    window.afAqrBuiltinHandlers.center = makeAqrHandler('center', 'По центру', 'center');
    window.afAqrBuiltinHandlers.right = makeAqrHandler('right', 'По правому краю', 'right');
    window.afAqrBuiltinHandlers.justify = makeAqrHandler('justify', 'По ширине', 'justify');
  }

  function boot() {
    registerNormalizer();
    registerBuiltinHandlers();

    var tries = 0;

    (function waitForSceditor() {
      var okFormat = registerBbcodeFormat();
      var okCmd = registerCommands();

      enhanceAllEditors();

      if (okFormat && okCmd) {
        return;
      }

      tries += 1;
      if (tries > 150) {
        return;
      }

      window.setTimeout(waitForSceditor, 100);
    })();
  }

  boot();
})(window, document);
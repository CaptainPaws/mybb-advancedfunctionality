(function (window, document) {
  'use strict';

  if (window.__afAeIndentPackLoaded) return;
  window.__afAeIndentPackLoaded = true;

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);
  if (!Array.isArray(window.afAeBbcodeNormalizers)) window.afAeBbcodeNormalizers = [];

  var ID = 'indent';
  var CMD = 'af_indent';
  var FILLER_ATTR = 'data-af-indent-filler';

  function asText(value) {
    return String(value == null ? '' : value);
  }

  function trim(value) {
    return asText(value).trim();
  }

  function safeLevel(value) {
    var n = parseInt(value, 10);
    if (!isFinite(n)) return 1;
    if (n < 1) return 1;
    if (n > 3) return 3;
    return n;
  }

  function getSceditorRoot() {
    if (window.sceditor) return window.sceditor;
    if (window.jQuery && window.jQuery.sceditor) return window.jQuery.sceditor;
    return null;
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

    try { textarea.dispatchEvent(new Event('input', { bubbles: true })); } catch (e0) {}
    try { textarea.dispatchEvent(new Event('change', { bubbles: true })); } catch (e1) {}

    return true;
  }

  function insertIndentSource(editorOrTextarea, level) {
    level = safeLevel(level);

    var open = '[indent=' + level + ']';
    var close = '[/indent]';

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

  function normalizeLegacyIndentBbcode(value) {
    value = asText(value);

    if (value.indexOf('[indent=') === -1) {
      return value;
    }

    return value.replace(
      /\[indent=(1|2|3)\]([\s\S]*?)(?=(\r?\n|$))/gi,
      function (all, level, inner) {
        if (/\[\/indent\]/i.test(inner)) {
          return all;
        }

        return '[indent=' + safeLevel(level) + ']' + inner + '[/indent]';
      }
    );
  }

  function registerNormalizer() {
    if (window.__afAeIndentNormalizerRegistered) return;
    window.__afAeIndentNormalizerRegistered = true;
    window.afAeBbcodeNormalizers.push(normalizeLegacyIndentBbcode);
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

  function closestIndentBlock(node, stopNode) {
    while (node && node !== stopNode) {
      if (
        node.nodeType === 1 &&
        node.hasAttribute &&
        node.hasAttribute('data-af-indent')
      ) {
        return node;
      }
      node = node.parentNode;
    }
    return null;
  }

  function isIndentableHostBlock(node, stopNode) {
    if (!node || node === stopNode || node.nodeType !== 1 || !node.tagName) {
      return false;
    }

    var tag = node.tagName.toLowerCase();
    return tag === 'div' || tag === 'p' || tag === 'blockquote' || tag === 'pre';
  }

  function closestIndentableHostBlock(node, stopNode) {
    while (node && node !== stopNode) {
      if (isIndentableHostBlock(node, stopNode)) {
        return node;
      }
      node = node.parentNode;
    }

    return null;
  }

  function getChildNodeAt(container, index) {
    if (!container || container.nodeType !== 1) return null;
    if (index < 0 || index >= container.childNodes.length) return null;
    return container.childNodes[index] || null;
  }

  function resolveCollapsedIndentTarget(range, body) {
    if (!range || !body) return null;

    var startNode = range.startContainer;
    var target = closestIndentableHostBlock(startNode, body);

    if (target) {
      return target;
    }

    if (startNode && startNode.nodeType === 1) {
      var childAfter = getChildNodeAt(startNode, range.startOffset);
      if (childAfter) {
        if (isIndentableHostBlock(childAfter, body)) {
          return childAfter;
        }

        target = closestIndentableHostBlock(childAfter, body);
        if (target) {
          return target;
        }
      }

      var childBefore = getChildNodeAt(startNode, range.startOffset - 1);
      if (childBefore) {
        if (isIndentableHostBlock(childBefore, body)) {
          return childBefore;
        }

        target = closestIndentableHostBlock(childBefore, body);
        if (target) {
          return target;
        }
      }
    }

    return null;
  } 

  function applyBlockLevel(block, level) {
    if (!block || block.nodeType !== 1) return;

    level = safeLevel(level);

    block.setAttribute('data-af-indent', String(level));
    block.classList.add('af-indent');
    block.classList.remove('af-indent-1', 'af-indent-2', 'af-indent-3');
    block.classList.add('af-indent-' + level);
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

  function cleanupIndentBlock(block) {
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

  function normalizeNestedIndentBlocks(root) {
    if (!root || !root.querySelectorAll) return;

    var nodes = root.querySelectorAll('[data-af-indent]');

    for (var i = nodes.length - 1; i >= 0; i -= 1) {
      var node = nodes[i];
      var parent = node.parentNode;

      if (
        parent &&
        parent.nodeType === 1 &&
        parent.hasAttribute &&
        parent.hasAttribute('data-af-indent') &&
        safeLevel(parent.getAttribute('data-af-indent')) === safeLevel(node.getAttribute('data-af-indent'))
      ) {
        unwrapNode(node);
      }
    }
  }

  function syncEditor(editor) {
    if (!editor) return;

    try {
      var dom = getEditorDom(editor);
      if (dom && dom.body) {
        var blocks = dom.body.querySelectorAll('[data-af-indent]');
        for (var i = 0; i < blocks.length; i += 1) {
          cleanupIndentBlock(blocks[i]);
        }
        normalizeNestedIndentBlocks(dom.body);
      }
    } catch (e0) {}

    if (typeof editor.updateOriginal === 'function') {
      editor.updateOriginal();
    }

    if (typeof editor.focus === 'function') {
      editor.focus();
    }
  }

  function insertCollapsedIndentBlock(editor, level) {
    var dom = getEditorDom(editor);
    var range = getCurrentRange(editor);

    if (!dom || !range) return false;

    var block = dom.doc.createElement('div');
    applyBlockLevel(block, level);

    var br = dom.doc.createElement('br');
    br.setAttribute(FILLER_ATTR, '1');
    block.appendChild(br);

    range.deleteContents();
    range.insertNode(block);

    var newRange = dom.doc.createRange();
    newRange.selectNodeContents(block);
    newRange.collapse(true);

    dom.sel.removeAllRanges();
    dom.sel.addRange(newRange);

    syncEditor(editor);
    return true;
  }

  function wrapSelectedRangeWithIndent(editor, level) {
    var dom = getEditorDom(editor);
    var range = getCurrentRange(editor);

    if (!dom || !range || range.collapsed) return false;

    var fragment = range.extractContents();
    var block = dom.doc.createElement('div');
    applyBlockLevel(block, level);
    block.appendChild(fragment);

    range.insertNode(block);

    cleanupIndentBlock(block);
    normalizeNestedIndentBlocks(block);

    var newRange = dom.doc.createRange();
    newRange.selectNodeContents(block);

    dom.sel.removeAllRanges();
    dom.sel.addRange(newRange);

    syncEditor(editor);
    return true;
  }

  function getCurrentIndentLevel(editor) {
    var dom = getEditorDom(editor);
    var range = getCurrentRange(editor);

    if (!dom || !range) return 1;

    var block = closestIndentBlock(range.startContainer, dom.body);
    return block ? safeLevel(block.getAttribute('data-af-indent')) : 1;
  }

  function applyIndentWysiwyg(editor, level) {
    level = safeLevel(level);

    if (!editor || isSourceMode(editor)) {
      return false;
    }

    ensureIndentIframeCss(editor);

    var dom = getEditorDom(editor);
    if (!dom) return false;

    var range = getCurrentRange(editor);
    if (!range) {
      if (typeof editor.focus === 'function') editor.focus();
      range = getCurrentRange(editor);
    }
    if (!range) return false;

    var startIndentBlock = closestIndentBlock(range.startContainer, dom.body);
    var endIndentBlock = closestIndentBlock(range.endContainer, dom.body);

    if (range.collapsed) {
      if (startIndentBlock) {
        applyBlockLevel(startIndentBlock, level);
        syncEditor(editor);
        return true;
      }

      var hostBlock = resolveCollapsedIndentTarget(range, dom.body);
      if (hostBlock) {
        applyBlockLevel(hostBlock, level);
        syncEditor(editor);
        return true;
      }

      return insertCollapsedIndentBlock(editor, level);
    }

    if (startIndentBlock && endIndentBlock && startIndentBlock === endIndentBlock) {
      applyBlockLevel(startIndentBlock, level);
      syncEditor(editor);
      return true;
    }

    return wrapSelectedRangeWithIndent(editor, level);
  }

  function applyIndent(editorOrTextarea, level) {
    level = safeLevel(level);

    if (
      editorOrTextarea &&
      typeof editorOrTextarea.getBody === 'function' &&
      !isSourceMode(editorOrTextarea)
    ) {
      return applyIndentWysiwyg(editorOrTextarea, level);
    }

    return insertIndentSource(editorOrTextarea, level);
  }

  function normalizeFormatContent(content) {
    content = asText(content)
      .replace(new RegExp('<br\\b[^>]*' + FILLER_ATTR + '[^>]*>', 'gi'), '')
      .replace(/\u200B/g, '');

    return content;
  }

  function registerIndentBbcode() {
    var sc = getSceditorRoot();

    if (!sc || !sc.formats || !sc.formats.bbcode || typeof sc.formats.bbcode.set !== 'function') {
      return false;
    }

    sc.formats.bbcode.set('indent', {
      tags: {
        div: { 'data-af-indent': null },
        p: { 'data-af-indent': null },
        blockquote: { 'data-af-indent': null },
        pre: { 'data-af-indent': null }
      },
      isInline: false,
      allowsEmpty: true,
      breakBefore: true,
      breakAfter: true,
      skipLastLineBreak: true,
      format: function (element, content) {
        var classMatch = (element.className || '').match(/af-indent-(\d)/);
        var level = safeLevel(
          element.getAttribute('data-af-indent') ||
          (classMatch ? classMatch[1] : 1)
        );

        var inner = normalizeFormatContent(content);

        if (!trim(inner)) {
          return '';
        }

        return '[indent=' + level + ']' + inner + '[/indent]';
      },
      html: function (token, attrs, content) {
        attrs = attrs || {};

        var level = safeLevel(attrs.defaultattr || attrs.indent || attrs.level || 1);

        return '<div class="af-indent af-indent-' + level + '" data-af-indent="' + level + '">' + asText(content) + '</div>';
      }
    });

    return true;
  }

  function getIframeCss() {
    return '' +
      '.af-indent{display:block;}' +
      '.af-indent-1,.af-indent[data-af-indent="1"]{text-indent:1em;}' +
      '.af-indent-2,.af-indent[data-af-indent="2"]{text-indent:2em;}' +
      '.af-indent-3,.af-indent[data-af-indent="3"]{text-indent:3em;}' +
      '.af-indent > :first-child{margin-top:0;}' +
      '.af-indent > :last-child{margin-bottom:0;}';
  }

  function ensureIndentIframeCss(editor) {
    var dom = getEditorDom(editor);
    if (!dom || !dom.doc || !dom.doc.head) return;

    if (dom.doc.getElementById('af-ae-indent-style')) return;

    var style = dom.doc.createElement('style');
    style.id = 'af-ae-indent-style';
    style.type = 'text/css';
    style.appendChild(dom.doc.createTextNode(getIframeCss()));
    dom.doc.head.appendChild(style);
  }

  function makeDropdown(editor, caller) {
    ensureIndentIframeCss(editor);

    var currentLevel = getCurrentIndentLevel(editor);

    var wrap = document.createElement('div');
    wrap.className = 'af-indent-dd';

    function addItem(level, title, sample) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'af-indent-item';
      btn.setAttribute('data-level', String(level));

      var nm = document.createElement('div');
      nm.className = 'af-indent-name';
      nm.textContent = title;

      var sm = document.createElement('div');
      sm.className = 'af-indent-sample';
      sm.textContent = sample;

      btn.appendChild(nm);
      btn.appendChild(sm);

      if (safeLevel(level) === safeLevel(currentLevel)) {
        btn.classList.add('is-active');
      }

      btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        applyIndent(editor, level);
        try { editor.closeDropDown(true); } catch (e0) {}
      });

      wrap.appendChild(btn);
    }

    addItem(1, 'Отступ 1em', 'Отступ первой строки: 1em');
    addItem(2, 'Отступ 2em', 'Отступ первой строки: 2em');
    addItem(3, 'Отступ 3em', 'Отступ первой строки: 3em');

    editor.createDropDown(caller, 'sceditor-indent-picker', wrap);
  }

  function openSceditorDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;
    try { editor.closeDropDown(true); } catch (e0) {}
    makeDropdown(editor, caller);
    return true;
  }

  function patchSceditorIndentCommand() {
    if (!window.jQuery) return false;
    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.command) return false;

    function fallbackApply(ed) {
      applyIndent(ed, 1);
    }

    var commandImpl = {
      exec: function (caller) {
        if (!openSceditorDropdown(this, caller)) fallbackApply(this);
      },
      txtExec: function (caller) {
        if (!openSceditorDropdown(this, caller)) fallbackApply(this);
      },
      tooltip: 'Отступ первой строки (1–3em)'
    };

    $.sceditor.command.set(CMD, commandImpl);
    $.sceditor.command.set('indent', commandImpl);

    return true;
  }

  function enhanceAllEditors() {
    var sc = getSceditorRoot();
    if (!sc || typeof sc.instance !== 'function') return;

    var textareas = document.querySelectorAll('textarea');
    for (var i = 0; i < textareas.length; i += 1) {
      try {
        var editor = sc.instance(textareas[i]);
        if (editor) {
          ensureIndentIframeCss(editor);
        }
      } catch (e) {}
    }
  }

  function waitAnd(fn, maxTries) {
    var tries = 0;
    (function tick() {
      tries += 1;
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

    applyIndent(editor || getTextareaFromCtx(ctx), 1);
  }

  var handlerObj = {
    id: ID,
    title: 'Отступ первой строки (1–3em)',
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

    applyIndent(editor, 1);
  }

  function registerHandlers() {
    window.afAqrBuiltinHandlers[ID] = handlerObj;
    window.afAqrBuiltinHandlers[CMD] = handlerObj;

    window.afAeBuiltinHandlers[ID] = handlerFn;
    window.afAeBuiltinHandlers[CMD] = handlerFn;
  }

  registerNormalizer();
  registerHandlers();
  waitAnd(registerIndentBbcode, 150);
  waitAnd(patchSceditorIndentCommand, 150);
  waitAnd(function () {
    enhanceAllEditors();
    return false;
  }, 20);

  for (var i = 1; i <= 20; i++) {
    setTimeout(registerHandlers, i * 250);
    setTimeout(enhanceAllEditors, i * 300);
  }
})(window, document);

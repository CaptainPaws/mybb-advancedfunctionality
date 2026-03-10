(function (window, document) {
  'use strict';

  if (window.__afAeFloatbbPackLoaded) return;
  window.__afAeFloatbbPackLoaded = true;

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);
  if (!Array.isArray(window.afAeBbcodeNormalizers)) window.afAeBbcodeNormalizers = [];

  var ID = 'floatbb';
  var CMD = 'af_floatbb';
  var FILLER_ATTR = 'data-af-floatbb-filler';

  function asText(value) {
    return String(value == null ? '' : value);
  }

  function trim(value) {
    return asText(value).trim();
  }

  function stripZeroWidth(value) {
    return asText(value).replace(/\u200B/g, '');
  }

  function normDir(value) {
    value = trim(value).toLowerCase();

    if (value === 'right' || value === 'r' || value === '2') {
      return 'right';
    }

    return 'left';
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

  function insertFloatSource(editorOrTextarea, dir) {
    dir = normDir(dir);

    var open = '[float=' + dir + ']';
    var close = '[/float]';

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

  function normalizeLegacyFloatBbcode(value) {
    value = asText(value);

    value = value.replace(/\[floatbb(?:=([^\]]+))?\]/gi, function (all, dir) {
      return '[float=' + normDir(dir || 'left') + ']';
    });

    value = value.replace(/\[\/floatbb\]/gi, '[/float]');

    return value;
  }

  function registerNormalizer() {
    if (window.__afAeFloatbbNormalizerRegistered) return;
    window.__afAeFloatbbNormalizerRegistered = true;
    window.afAeBbcodeNormalizers.push(normalizeLegacyFloatBbcode);
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

  function closestFloatBlock(node, stopNode) {
    while (node && node !== stopNode) {
      if (
        node.nodeType === 1 &&
        node.tagName &&
        node.tagName.toLowerCase() === 'div' &&
        (
          node.getAttribute('data-af-bb') === 'float' ||
          node.hasAttribute('data-af-dir') ||
          /\baf-floatbb\b/.test(node.className || '')
        )
      ) {
        return node;
      }
      node = node.parentNode;
    }

    return null;
  }

  function applyBlockDir(block, dir) {
    if (!block || block.nodeType !== 1) return;

    dir = normDir(dir);

    block.setAttribute('data-af-bb', 'float');
    block.setAttribute('data-af-dir', dir);

    block.classList.add('af-floatbb');
    block.classList.remove('af-floatbb-left', 'af-floatbb-right');
    block.classList.add('af-floatbb-' + dir);
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

  function cleanupFloatBlock(block) {
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

  function normalizeNestedFloatBlocks(root) {
    if (!root || !root.querySelectorAll) return;

    var nodes = root.querySelectorAll('div[data-af-dir], div[data-af-bb="float"], div.af-floatbb');

    for (var i = nodes.length - 1; i >= 0; i -= 1) {
      var node = nodes[i];
      var parent = node.parentNode;

      if (
        parent &&
        parent.nodeType === 1 &&
        parent.tagName &&
        parent.tagName.toLowerCase() === 'div' &&
        (
          parent.getAttribute('data-af-bb') === 'float' ||
          parent.hasAttribute('data-af-dir') ||
          /\baf-floatbb\b/.test(parent.className || '')
        ) &&
        normDir(parent.getAttribute('data-af-dir') || '') === normDir(node.getAttribute('data-af-dir') || '')
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
        var blocks = dom.body.querySelectorAll('div[data-af-dir], div[data-af-bb="float"], div.af-floatbb');
        for (var i = 0; i < blocks.length; i += 1) {
          cleanupFloatBlock(blocks[i]);
        }
        normalizeNestedFloatBlocks(dom.body);
      }
    } catch (e0) {}

    if (typeof editor.updateOriginal === 'function') {
      editor.updateOriginal();
    }

    if (typeof editor.focus === 'function') {
      editor.focus();
    }
  }

  function insertCollapsedFloatBlock(editor, dir) {
    var dom = getEditorDom(editor);
    var range = getCurrentRange(editor);

    if (!dom || !range) return false;

    var block = dom.doc.createElement('div');
    applyBlockDir(block, dir);

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

  function wrapSelectedRangeWithFloat(editor, dir) {
    var dom = getEditorDom(editor);
    var range = getCurrentRange(editor);

    if (!dom || !range || range.collapsed) return false;

    var fragment = range.extractContents();
    var block = dom.doc.createElement('div');
    applyBlockDir(block, dir);
    block.appendChild(fragment);

    range.insertNode(block);

    cleanupFloatBlock(block);
    normalizeNestedFloatBlocks(block);

    var newRange = dom.doc.createRange();
    newRange.selectNodeContents(block);

    dom.sel.removeAllRanges();
    dom.sel.addRange(newRange);

    syncEditor(editor);
    return true;
  }

  function getCurrentFloatDir(editor) {
    var dom = getEditorDom(editor);
    var range = getCurrentRange(editor);

    if (!dom || !range) return 'left';

    var block = closestFloatBlock(range.startContainer, dom.body);
    if (!block) return 'left';

    return normDir(block.getAttribute('data-af-dir') || 'left');
  }

  function applyFloatWysiwyg(editor, dir) {
    dir = normDir(dir);

    if (!editor || isSourceMode(editor)) {
      return false;
    }

    ensureFloatIframeCss(editor);

    var dom = getEditorDom(editor);
    if (!dom) return false;

    var range = getCurrentRange(editor);
    if (!range) {
      if (typeof editor.focus === 'function') editor.focus();
      range = getCurrentRange(editor);
    }
    if (!range) return false;

    var startBlock = closestFloatBlock(range.startContainer, dom.body);
    var endBlock = closestFloatBlock(range.endContainer, dom.body);

    if (range.collapsed) {
      if (startBlock) {
        applyBlockDir(startBlock, dir);
        syncEditor(editor);
        return true;
      }

      return insertCollapsedFloatBlock(editor, dir);
    }

    if (startBlock && endBlock && startBlock === endBlock) {
      applyBlockDir(startBlock, dir);
      syncEditor(editor);
      return true;
    }

    return wrapSelectedRangeWithFloat(editor, dir);
  }

  function applyFloat(editorOrTextarea, dir) {
    dir = normDir(dir);

    if (
      editorOrTextarea &&
      typeof editorOrTextarea.getBody === 'function' &&
      !isSourceMode(editorOrTextarea)
    ) {
      return applyFloatWysiwyg(editorOrTextarea, dir);
    }

    return insertFloatSource(editorOrTextarea, dir);
  }

  function normalizeFormatContent(content) {
    content = asText(content)
      .replace(new RegExp('<br\\b[^>]*' + FILLER_ATTR + '[^>]*>', 'gi'), '')
      .replace(/\u200B/g, '');

    return content;
  }

  function unwrapSameOuterFloatBbcode(content, expectedDir) {
    content = asText(content);
    expectedDir = normDir(expectedDir);

    if (!content) return content;

    var guard = 0;
    var pattern = /^\s*\[float(?:bb)?=([^\]]+)\]([\s\S]*?)\[\/float(?:bb)?\]\s*$/i;

    while (guard < 20) {
      guard += 1;

      var match = content.match(pattern);
      if (!match) break;

      var foundDir = normDir(match[1]);
      if (foundDir !== expectedDir) break;

      content = asText(match[2]);
    }

    return content;
  }

  function makeFloatFormatDefinition() {
    return {
      tags: {
        div: {
          'data-af-dir': null
        }
      },
      isInline: false,
      allowsEmpty: true,

      // ВАЖНО:
      // float не должен насильно добавлять переносы до/после себя,
      // иначе в source и после публикации появляется "пустая строка".
      breakBefore: false,
      breakAfter: false,
      skipLastLineBreak: true,

      format: function (element, content) {
        var dir = 'left';

        if (element && element.getAttribute) {
          dir = normDir(element.getAttribute('data-af-dir') || dir);
        }

        if ((!dir || dir === 'left') && element && element.className) {
          var cls = String(element.className || '');
          if (cls.indexOf('af-floatbb-right') !== -1) dir = 'right';
          else if (cls.indexOf('af-floatbb-left') !== -1) dir = 'left';
        }

        var inner = normalizeFormatContent(content);
        inner = unwrapSameOuterFloatBbcode(inner, dir);

        if (!trim(inner)) {
          return '';
        }

        return '[float=' + dir + ']' + inner + '[/float]';
      },

      html: function (token, attrs, content) {
        attrs = attrs || {};

        var dir = normDir(attrs.defaultattr || attrs.dir || attrs.float || 'left');

        return '<div class="af-floatbb af-floatbb-' + dir + '" data-af-bb="float" data-af-dir="' + dir + '">' + asText(content) + '</div>';
      }
    };
  }

  function registerFloatBbcode() {
    var sc = getSceditorRoot();

    if (!sc || !sc.formats || !sc.formats.bbcode || typeof sc.formats.bbcode.set !== 'function') {
      return false;
    }

    var def = makeFloatFormatDefinition();

    sc.formats.bbcode.set('float', def);
    sc.formats.bbcode.set('floatbb', makeFloatFormatDefinition());

    return true;
  }

  function getIframeCss() {
    return '' +
      '.af-floatbb{display:block;max-width:50%;margin:.2em 1em .8em 0;box-sizing:border-box;}' +
      '.af-floatbb-left{float:left;margin-right:1em;margin-left:0;}' +
      '.af-floatbb-right{float:right;margin-left:1em;margin-right:0;}' +
      '.af-floatbb img{max-width:100%;height:auto;display:block;}' +
      '.af-floatbb::after{content:"";display:block;clear:both;}' +
      '.af-floatbb[' + FILLER_ATTR + '],.af-floatbb br[' + FILLER_ATTR + ']{display:block;}';
  }

  function ensureFloatIframeCss(editor) {
    var dom = getEditorDom(editor);
    if (!dom || !dom.doc || !dom.doc.head) return;

    if (dom.doc.getElementById('af-ae-floatbb-style')) return;

    var style = dom.doc.createElement('style');
    style.id = 'af-ae-floatbb-style';
    style.type = 'text/css';
    style.appendChild(dom.doc.createTextNode(getIframeCss()));
    dom.doc.head.appendChild(style);
  }

  function makeDropdown(editor, caller) {
    ensureFloatIframeCss(editor);

    var currentDir = getCurrentFloatDir(editor);
    var wrap = document.createElement('div');
    wrap.className = 'af-floatbb-dd';

    function addItem(dir, title, sample) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'af-floatbb-item';
      btn.setAttribute('data-dir', dir);

      var nm = document.createElement('div');
      nm.className = 'af-floatbb-name';
      nm.textContent = title;

      var sm = document.createElement('div');
      sm.className = 'af-floatbb-sample';
      sm.textContent = sample;

      btn.appendChild(nm);
      btn.appendChild(sm);

      if (normDir(dir) === normDir(currentDir)) {
        btn.classList.add('is-active');
      }

      btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        applyFloat(editor, dir);
        try { editor.closeDropDown(true); } catch (e0) {}
      });

      wrap.appendChild(btn);
    }

    addItem('left', 'Обтекание слева', 'Блок прижимается влево');
    addItem('right', 'Обтекание справа', 'Блок прижимается вправо');

    editor.createDropDown(caller || null, 'sceditor-floatbb-picker', wrap);
  }

  function openSceditorDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;

    try { editor.closeDropDown(true); } catch (e0) {}
    makeDropdown(editor, caller);
    return true;
  }

  function registerCommands() {
    var sc = getSceditorRoot();

    if (!sc || !sc.command || typeof sc.command.set !== 'function') {
      return false;
    }

    var commandImpl = {
      exec: function (caller) {
        if (!openSceditorDropdown(this, caller)) {
          applyFloat(this, 'left');
        }
      },
      txtExec: function (caller) {
        if (!openSceditorDropdown(this, caller)) {
          applyFloat(this, 'left');
        }
      },
      tooltip: 'Обтекание (слева/справа)'
    };

    sc.command.set(CMD, commandImpl);
    sc.command.set('floatbb', commandImpl);

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
          ensureFloatIframeCss(editor);
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

    applyFloat(editor || getTextareaFromCtx(ctx), 'left');
  }

  var handlerObj = {
    id: ID,
    title: 'Обтекание (слева/справа)',
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

    applyFloat(editor, 'left');
  }

  function registerHandlers() {
    window.afAqrBuiltinHandlers[ID] = handlerObj;
    window.afAqrBuiltinHandlers[CMD] = handlerObj;

    window.afAeBuiltinHandlers[ID] = handlerFn;
    window.afAeBuiltinHandlers[CMD] = handlerFn;
  }

  registerNormalizer();
  registerHandlers();
  waitAnd(registerFloatBbcode, 150);
  waitAnd(registerCommands, 150);
  waitAnd(function () {
    enhanceAllEditors();
    return false;
  }, 20);

  for (var i = 1; i <= 20; i += 1) {
    setTimeout(registerHandlers, i * 250);
    setTimeout(enhanceAllEditors, i * 300);
  }
})(window, document);

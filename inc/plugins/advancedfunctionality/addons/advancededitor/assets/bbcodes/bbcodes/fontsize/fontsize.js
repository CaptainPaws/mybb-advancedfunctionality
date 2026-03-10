(function (window, document) {
  'use strict';

  if (window.__afAeFontsizePackLoaded) return;
  window.__afAeFontsizePackLoaded = true;

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  var PACK_ID = 'fontsize';
  var CMD = 'af_fontsize';
  var MIN_PX = 8;
  var MAX_PX = 36;
  var DROPDOWN_ID = 'af-ae-fontsize-picker';
  var DROPDOWN_SIZES = [8, 10, 12, 14, 16, 18, 20, 22, 24, 26, 28, 30, 32, 36];
  var ZWSP = '\u200B';

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

  function normalizeSizeValue(raw) {
    var value = trim(raw).toLowerCase();

    if (!value) return '';

    var legacyMap = {
      1: '8px',
      2: '10px',
      3: '12px',
      4: '14px',
      5: '18px',
      6: '24px',
      7: '32px'
    };

    if (/^\d+$/.test(value)) {
      var n = parseInt(value, 10);

      if (legacyMap[n]) {
        return legacyMap[n];
      }

      return clampInt(n, MIN_PX, MAX_PX, 12) + 'px';
    }

    var match = value.match(/^(\d+(?:\.\d+)?)(px|em|rem|%|pt)$/i);
    if (!match) return '';

    var num = match[1];
    var unit = match[2].toLowerCase();

    if (unit === 'px') {
      return clampInt(parseInt(num, 10), MIN_PX, MAX_PX, 12) + 'px';
    }

    return num + unit;
  }

  function stripZeroWidth(text) {
    return String(text == null ? '' : text).replace(/\u200B/g, '');
  }

  function trimQuoted(value) {
    value = trim(value);
    if (!value) return '';

    var first = value.charAt(0);
    var last = value.charAt(value.length - 1);

    if ((first === '"' && last === '"') || (first === '\'' && last === '\'')) {
      value = value.slice(1, -1);
    }

    return trim(value);
  }

  function unwrapSameOuterSizeBbcode(content, expectedSize) {
    content = String(content == null ? '' : content);
    expectedSize = normalizeSizeValue(expectedSize);

    if (!expectedSize || !content) {
      return content;
    }

    var guard = 0;
    var pattern = /^\s*\[size\s*=\s*([^\]]+)\]([\s\S]*?)\[\/size\]\s*$/i;

    while (guard < 20) {
      guard += 1;

      var match = content.match(pattern);
      if (!match) {
        break;
      }

      var foundSize = normalizeSizeValue(trimQuoted(match[1]));
      if (foundSize !== expectedSize) {
        break;
      }

      content = String(match[2] == null ? '' : match[2]);
    }

    return content;
  }

  function isSourceMode(editor) {
    try {
      if (!editor) return false;
      if (typeof editor.inSourceMode === 'function') return !!editor.inSourceMode();
      if (typeof editor.sourceMode === 'function') return !!editor.sourceMode();
    } catch (e) {}
    return false;
  }

  function getEditorBodyFontSizePx(editor) {
    try {
      if (editor && typeof editor.getBody === 'function') {
        var body = editor.getBody();
        if (body && body.ownerDocument && body.ownerDocument.defaultView) {
          var cs = body.ownerDocument.defaultView.getComputedStyle(body);
          var px = parseInt(cs.fontSize, 10);
          if (!isNaN(px)) return px;
        }
      }
    } catch (e) {}

    return 12;
  }

  function getDefaultPx(editor) {
    return clampInt(getEditorBodyFontSizePx(editor), MIN_PX, MAX_PX, 12);
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

  function resolveCaller(ctx, fallback) {
    if (ctx && ctx.caller) return ctx.caller;
    if (ctx && ctx.button) return ctx.button;
    if (ctx && ctx.el) return ctx.el;
    return fallback || null;
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

  function insertSizeSource(editorOrTextarea, size) {
    var open = '[size=' + size + ']';
    var close = '[/size]';

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

  function closestFontsizeSpan(node, stopNode) {
    while (node && node !== stopNode) {
      if (
        node.nodeType === 1 &&
        node.tagName &&
        node.tagName.toLowerCase() === 'span' &&
        node.hasAttribute('data-af-fontsize')
      ) {
        return node;
      }
      node = node.parentNode;
    }
    return null;
  }

  function applySpanSize(span, size) {
    if (!span || span.nodeType !== 1) return;

    span.classList.add('af-bb-fontsize');
    span.setAttribute('data-af-fontsize', size);
    span.style.fontSize = size;
  }

  function unwrapNode(node) {
    if (!node || !node.parentNode) return;

    while (node.firstChild) {
      node.parentNode.insertBefore(node.firstChild, node);
    }

    node.parentNode.removeChild(node);
  }

  function normalizeNestedFontsizeSpans(root) {
    if (!root || !root.querySelectorAll) return;

    var spans = root.querySelectorAll('span[data-af-fontsize]');

    for (var i = spans.length - 1; i >= 0; i -= 1) {
      var span = spans[i];
      var parent = span.parentNode;

      if (
        parent &&
        parent.nodeType === 1 &&
        parent.tagName &&
        parent.tagName.toLowerCase() === 'span' &&
        parent.hasAttribute('data-af-fontsize') &&
        trim(parent.getAttribute('data-af-fontsize')) === trim(span.getAttribute('data-af-fontsize'))
      ) {
        unwrapNode(span);
      }
    }
  }

  function syncEditor(editor) {
    if (!editor) return;

    if (typeof editor.updateOriginal === 'function') {
      editor.updateOriginal();
    }

    if (typeof editor.focus === 'function') {
      editor.focus();
    }
  }

  function insertCollapsedSizeSpan(editor, size) {
    var dom = getEditorDom(editor);
    var range = getCurrentRange(editor);

    if (!dom || !range) return false;

    var span = dom.doc.createElement('span');
    applySpanSize(span, size);

    var marker = dom.doc.createTextNode(ZWSP);
    span.appendChild(marker);

    range.deleteContents();
    range.insertNode(span);

    var newRange = dom.doc.createRange();
    newRange.setStart(marker, 0);
    newRange.setEnd(marker, marker.nodeValue.length);

    dom.sel.removeAllRanges();
    dom.sel.addRange(newRange);

    syncEditor(editor);
    return true;
  }

  function wrapSelectedRangeWithSize(editor, size) {
    var dom = getEditorDom(editor);
    var range = getCurrentRange(editor);

    if (!dom || !range || range.collapsed) return false;

    var fragment = range.extractContents();
    var span = dom.doc.createElement('span');
    applySpanSize(span, size);
    span.appendChild(fragment);

    range.insertNode(span);
    normalizeNestedFontsizeSpans(span);

    var newRange = dom.doc.createRange();
    newRange.selectNodeContents(span);

    dom.sel.removeAllRanges();
    dom.sel.addRange(newRange);

    syncEditor(editor);
    return true;
  }

  function applySizeWysiwyg(editor, rawSize) {
    var size = normalizeSizeValue(rawSize);
    if (!size) {
      size = getDefaultPx(editor) + 'px';
    }

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
      var currentSpan = closestFontsizeSpan(range.startContainer, dom.body);
      if (currentSpan) {
        applySpanSize(currentSpan, size);
        syncEditor(editor);
        return true;
      }

      return insertCollapsedSizeSpan(editor, size);
    }

    return wrapSelectedRangeWithSize(editor, size);
  }

  function applySize(editorOrTextarea, rawSize) {
    var size = normalizeSizeValue(rawSize);
    if (!size) {
      size = getDefaultPx(editorOrTextarea) + 'px';
    }

    if (
      editorOrTextarea &&
      (typeof editorOrTextarea.getBody === 'function') &&
      !isSourceMode(editorOrTextarea)
    ) {
      return applySizeWysiwyg(editorOrTextarea, size);
    }

    return insertSizeSource(editorOrTextarea, size);
  }

  function createDropdownNode(editor, closeFn) {
    var root = document.createElement('div');
    root.className = 'sceditor-font-picker af-ae-fontsize-picker';

    var defaultPx = getDefaultPx(editor);

    root.innerHTML = [
      '<div class="af-ae-fontsize-picker__list">',
      '  <a href="#" class="sceditor-font-option af-ae-fontsize-picker__option af-ae-fontsize-picker__option--default" data-size="' + defaultPx + 'px" title="Размер по умолчанию">',
      '    <span><strong>По умолчанию</strong> (' + defaultPx + 'px)</span>',
      '  </a>',
      DROPDOWN_SIZES.map(function (px) {
        return '' +
          '<a href="#" class="sceditor-font-option af-ae-fontsize-picker__option" data-size="' + px + 'px" title="' + px + 'px">' +
            '<span style="font-size:' + px + 'px;line-height:1.15;">' + px + 'px</span>' +
          '</a>';
      }).join(''),
      '</div>'
    ].join('');

    root.addEventListener('click', function (event) {
      var option = event.target && event.target.closest('[data-size]');
      if (!option) return;

      event.preventDefault();
      event.stopPropagation();

      applySize(editor, option.getAttribute('data-size'));

      if (typeof closeFn === 'function') {
        closeFn();
      }
    });

    return root;
  }

  function openDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') {
      return false;
    }

    var node = createDropdownNode(editor, function () {
      if (typeof editor.closeDropDown === 'function') {
        editor.closeDropDown(true);
      }
    });

    try {
      if (typeof editor.closeDropDown === 'function') {
        editor.closeDropDown(true);
      }
    } catch (e) {}

    editor.createDropDown(caller || null, DROPDOWN_ID, node);
    return true;
  }

  function registerBbcodeFormat() {
    var sc = getSceditorRoot();

    if (!sc || !sc.formats || !sc.formats.bbcode || typeof sc.formats.bbcode.set !== 'function') {
      return false;
    }

    sc.formats.bbcode.set('size', {
      tags: {
        span: {
          'data-af-fontsize': null
        }
      },
      format: function (element, content) {
        var raw = trim(
          element.getAttribute('data-af-fontsize') ||
          element.style.fontSize ||
          ''
        );

        var size = normalizeSizeValue(raw);
        var cleanedContent = stripZeroWidth(content);

        if (!size) {
          return cleanedContent;
        }

        // КЛЮЧЕВОЙ ФИКС:
        // если внутренний контент уже сериализовался как [size=этот_же_размер]...[/size],
        // снимаем одинаковую внешнюю обёртку и возвращаем ровно ОДИН size.
        cleanedContent = unwrapSameOuterSizeBbcode(cleanedContent, size);

        if (!trim(cleanedContent)) {
          return '';
        }

        return '[size=' + size + ']' + cleanedContent + '[/size]';
      },
      html: function (token, attrs, content) {
        attrs = attrs || {};

        var size = normalizeSizeValue(attrs.defaultattr || attrs.size || '');
        if (!size) {
          return content;
        }

        return '<span class="af-bb-fontsize" data-af-fontsize="' + escHtml(size) + '" style="font-size:' + escHtml(size) + ';">' + String(content == null ? '' : content) + '</span>';
      }
    });

    return true;
  }

  function registerCommands() {
    var sc = getSceditorRoot();

    if (!sc || !sc.command || typeof sc.command.set !== 'function') {
      return false;
    }

    var commandImpl = {
      exec: function (caller) {
        if (!openDropdown(this, caller)) {
          applySize(this, getDefaultPx(this) + 'px');
        }
      },
      txtExec: function (caller) {
        if (!openDropdown(this, caller)) {
          applySize(this, getDefaultPx(this) + 'px');
        }
      },
      tooltip: 'Размер шрифта (px)'
    };

    sc.command.set('size', commandImpl);
    sc.command.set(CMD, commandImpl);

    return true;
  }

  function registerBuiltinHandlers() {
    var handlerFn = function (ctx, maybeCaller) {
      var editor = resolveEditor(ctx) || resolveEditor({ textarea: getTextareaFromCtx(ctx) });
      var caller = resolveCaller(ctx, maybeCaller);

      if (!editor) return false;

      if (typeof editor.createDropDown === 'function') {
        return openDropdown(editor, caller);
      }

      return applySize(editor, '12px');
    };

    var handlerObj = {
      id: PACK_ID,
      title: 'Размер шрифта (px)',
      onClick: function (ctx, ev) {
        return handlerFn(ctx, ev && (ev.currentTarget || ev.target));
      },
      click: function (ctx, ev) {
        return handlerFn(ctx, ev && (ev.currentTarget || ev.target));
      },
      action: function (ctx, ev) {
        return handlerFn(ctx, ev && (ev.currentTarget || ev.target));
      },
      run: function (ctx, ev) {
        return handlerFn(ctx, ev && (ev.currentTarget || ev.target));
      },
      init: function () {}
    };

    window.afAeBuiltinHandlers.fontsize = handlerFn;
    window.afAeBuiltinHandlers[CMD] = handlerFn;

    window.afAqrBuiltinHandlers.fontsize = handlerObj;
    window.afAqrBuiltinHandlers[CMD] = handlerObj;
  }

  function boot() {
    registerBuiltinHandlers();

    var tries = 0;

    (function waitForSceditor() {
      var okFormat = registerBbcodeFormat();
      var okCmd = registerCommands();

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

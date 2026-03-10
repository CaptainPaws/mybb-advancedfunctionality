(function (window, document) {
  'use strict';

  if (window.__afAeFontFamilyPackLoaded) return;
  window.__afAeFontFamilyPackLoaded = true;

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  var PACK_ID = 'fontfamily';
  var PACK_ID_ALIAS = 'font';
  var CMD = 'af_fontfamily';
  var CMD_ALIAS = 'af_font';
  var DROPDOWN_ID = 'af-ae-fontfamily-picker';
  var ZWSP = '\u200B';
  var INSTANCE_SCAN_DELAY = 1200;

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

  function stripZeroWidth(value) {
    return asText(value).replace(/\u200B/g, '');
  }

  function safeCssString(value) {
    value = asText(value).replace(/[\u0000-\u001f\u007f]/g, '');
    return value.replace(/["'\\]/g, '');
  }

  function safeBbValue(value) {
    value = asText(value);
    value = value.replace(/[\[\]]/g, '');
    value = value.replace(/[\r\n\t]/g, ' ');
    value = value.replace(/\s+/g, ' ');
    return value.trim();
  }

  function getPayload() {
    if (window.afAePayload && typeof window.afAePayload === 'object') return window.afAePayload;
    if (window.afAqrPayload && typeof window.afAqrPayload === 'object') return window.afAqrPayload;
    return {};
  }

  function getCfg() {
    var payload = getPayload();
    return (payload.cfg && typeof payload.cfg === 'object') ? payload.cfg : {};
  }

  function getFamilies() {
    var cfg = getCfg();
    var list = cfg.fontFamilies;

    if (Array.isArray(list) && list.length) {
      return list;
    }

    return [
      { id: 'arial', name: 'Arial', system: 1 },
      { id: 'helvetica', name: 'Helvetica', system: 1 },
      { id: 'verdana', name: 'Verdana', system: 1 },
      { id: 'tahoma', name: 'Tahoma', system: 1 },
      { id: 'trebuchet_ms', name: 'Trebuchet MS', system: 1 },
      { id: 'georgia', name: 'Georgia', system: 1 },
      { id: 'times_new_roman', name: 'Times New Roman', system: 1 },
      { id: 'garamond', name: 'Garamond', system: 1 },
      { id: 'courier_new', name: 'Courier New', system: 1 }
    ];
  }

  function normalizeFontFamily(raw) {
    var value = stripZeroWidth(raw);
    value = trimQuoted(value);
    value = safeBbValue(value);

    if (!value) return '';

    // Если браузер вернул CSS-список семейств — оставляем первое.
    if (value.indexOf(',') !== -1) {
      value = trimQuoted(value.split(',')[0]);
      value = safeBbValue(value);
    }

    if (!value) return '';

    var families = getFamilies();
    var lower = value.toLowerCase();

    for (var i = 0; i < families.length; i += 1) {
      var name = trim(families[i] && families[i].name);
      if (name && name.toLowerCase() === lower) {
        return name;
      }
    }

    return value;
  }

  function cssFontFamilyValue(family) {
    family = normalizeFontFamily(family);
    family = safeCssString(family);

    if (!family) return '';

    if (/\s/.test(family)) {
      return '\'' + family + '\'';
    }

    return family;
  }

  function unwrapSameOuterFontBbcode(content, expectedFamily) {
    content = asText(content);
    expectedFamily = normalizeFontFamily(expectedFamily);

    if (!content || !expectedFamily) {
      return content;
    }

    var guard = 0;
    var pattern = /^\s*\[font\s*=\s*([^\]]+)\]([\s\S]*?)\[\/font\]\s*$/i;

    while (guard < 20) {
      guard += 1;

      var match = content.match(pattern);
      if (!match) {
        break;
      }

      var foundFamily = normalizeFontFamily(trimQuoted(match[1]));
      if (foundFamily !== expectedFamily) {
        break;
      }

      content = asText(match[2]);
    }

    return content;
  }

  function buildFontFaceCss(families) {
    var css = '';

    for (var i = 0; i < families.length; i += 1) {
      var family = families[i];
      if (!family || !family.name || !family.files || typeof family.files !== 'object') {
        continue;
      }

      var name = safeCssString(family.name);
      if (!name) continue;

      var src = [];

      function pushFile(ext, format) {
        var file = family.files[ext];
        if (!file) return;

        file = trim(file);
        if (!file) return;

        var payload = getPayload();
        var assetsBase = trim(payload.assetsBase || '');
        if (!assetsBase) return;

        var url = assetsBase.replace(/\/+$/, '') + '/fonts/' + encodeURIComponent(file).replace(/%2F/gi, '/');
        src.push('url("' + url.replace(/"/g, '\\"') + '") format("' + format + '")');
      }

      pushFile('woff2', 'woff2');
      pushFile('woff', 'woff');
      pushFile('ttf', 'truetype');
      pushFile('otf', 'opentype');

      if (!src.length) continue;

      css += '@font-face{'
        + 'font-family:"' + name + '";'
        + 'src:' + src.join(',') + ';'
        + 'font-style:normal;'
        + 'font-weight:400;'
        + 'font-display:swap;'
        + '}\n';
    }

    return css;
  }

  function ensureFontFacesInDocument(doc) {
    if (!doc || !doc.head) return;

    var styleId = 'af-ae-fontfamily-fontfaces';
    if (doc.getElementById(styleId)) return;

    var css = buildFontFaceCss(getFamilies());
    if (!css) return;

    var style = doc.createElement('style');
    style.id = styleId;
    style.type = 'text/css';
    style.appendChild(doc.createTextNode(css));
    doc.head.appendChild(style);
  }

  function getEditorInstanceFromTextarea(textarea) {
    var sc = getSceditorRoot();
    if (!sc || typeof sc.instance !== 'function') return null;

    try {
      return sc.instance(textarea);
    } catch (e) {
      return null;
    }
  }

  function enhanceAllEditors() {
    ensureFontFacesInDocument(document);

    var textareas = document.querySelectorAll('textarea');
    for (var i = 0; i < textareas.length; i += 1) {
      var instance = getEditorInstanceFromTextarea(textareas[i]);
      if (!instance || typeof instance.getBody !== 'function') continue;

      try {
        var body = instance.getBody();
        if (body && body.ownerDocument) {
          ensureFontFacesInDocument(body.ownerDocument);
        }
      } catch (e) {}
    }
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

  function insertFontSource(editorOrTextarea, family) {
    family = normalizeFontFamily(family);
    if (!family) return false;

    var open = '[font=' + family + ']';
    var close = '[/font]';

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

  function isSourceMode(editor) {
    try {
      if (!editor) return false;
      if (typeof editor.inSourceMode === 'function') return !!editor.inSourceMode();
      if (typeof editor.sourceMode === 'function') return !!editor.sourceMode();
    } catch (e) {}
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

  function closestFontSpan(node, stopNode) {
    while (node && node !== stopNode) {
      if (
        node.nodeType === 1 &&
        node.tagName &&
        node.tagName.toLowerCase() === 'span' &&
        node.hasAttribute('data-af-fontfamily')
      ) {
        return node;
      }
      node = node.parentNode;
    }
    return null;
  }

  function applySpanFamily(span, family) {
    if (!span || span.nodeType !== 1) return;

    family = normalizeFontFamily(family);
    if (!family) return;

    span.classList.add('af-bb-fontfamily');
    span.setAttribute('data-af-fontfamily', family);
    span.style.fontFamily = family;
  }

  function unwrapNode(node) {
    if (!node || !node.parentNode) return;

    while (node.firstChild) {
      node.parentNode.insertBefore(node.firstChild, node);
    }

    node.parentNode.removeChild(node);
  }

  function normalizeNestedFontSpans(root) {
    if (!root || !root.querySelectorAll) return;

    var spans = root.querySelectorAll('span[data-af-fontfamily]');

    for (var i = spans.length - 1; i >= 0; i -= 1) {
      var span = spans[i];
      var parent = span.parentNode;

      if (
        parent &&
        parent.nodeType === 1 &&
        parent.tagName &&
        parent.tagName.toLowerCase() === 'span' &&
        parent.hasAttribute('data-af-fontfamily') &&
        normalizeFontFamily(parent.getAttribute('data-af-fontfamily')) === normalizeFontFamily(span.getAttribute('data-af-fontfamily'))
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

  function insertCollapsedFontSpan(editor, family) {
    var dom = getEditorDom(editor);
    var range = getCurrentRange(editor);

    if (!dom || !range) return false;

    var span = dom.doc.createElement('span');
    applySpanFamily(span, family);

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

  function wrapSelectedRangeWithFont(editor, family) {
    var dom = getEditorDom(editor);
    var range = getCurrentRange(editor);

    if (!dom || !range || range.collapsed) return false;

    var fragment = range.extractContents();
    var span = dom.doc.createElement('span');
    applySpanFamily(span, family);
    span.appendChild(fragment);

    range.insertNode(span);
    normalizeNestedFontSpans(span);

    var newRange = dom.doc.createRange();
    newRange.selectNodeContents(span);

    dom.sel.removeAllRanges();
    dom.sel.addRange(newRange);

    syncEditor(editor);
    return true;
  }

  function applyFontWysiwyg(editor, family) {
    family = normalizeFontFamily(family);
    if (!family) return false;

    if (!editor || isSourceMode(editor)) {
      return false;
    }

    var dom = getEditorDom(editor);
    if (!dom) return false;

    ensureFontFacesInDocument(dom.doc);

    var range = getCurrentRange(editor);
    if (!range) {
      if (typeof editor.focus === 'function') editor.focus();
      range = getCurrentRange(editor);
    }
    if (!range) return false;

    if (range.collapsed) {
      var currentSpan = closestFontSpan(range.startContainer, dom.body);
      if (currentSpan) {
        applySpanFamily(currentSpan, family);
        syncEditor(editor);
        return true;
      }

      return insertCollapsedFontSpan(editor, family);
    }

    return wrapSelectedRangeWithFont(editor, family);
  }

  function applyFont(editorOrTextarea, family) {
    family = normalizeFontFamily(family);
    if (!family) return false;

    if (
      editorOrTextarea &&
      typeof editorOrTextarea.getBody === 'function' &&
      !isSourceMode(editorOrTextarea)
    ) {
      return applyFontWysiwyg(editorOrTextarea, family);
    }

    return insertFontSource(editorOrTextarea, family);
  }

  function createDropdownNode(editor, closeFn) {
    enhanceAllEditors();

    var families = getFamilies().slice();
    var custom = [];
    var system = [];

    for (var i = 0; i < families.length; i += 1) {
      var item = families[i];
      if (!item || !item.name) continue;

      if (item.files && typeof item.files === 'object' && (item.files.woff2 || item.files.woff || item.files.ttf || item.files.otf)) {
        custom.push(item);
      } else {
        system.push(item);
      }
    }

    system.sort(function (a, b) {
      return asText(a.name).toLowerCase().localeCompare(asText(b.name).toLowerCase());
    });
    custom.sort(function (a, b) {
      return asText(a.name).toLowerCase().localeCompare(asText(b.name).toLowerCase());
    });

    var root = document.createElement('div');
    root.className = 'af-ff-dd';

    var search = document.createElement('input');
    search.className = 'af-ff-search';
    search.type = 'text';
    search.placeholder = 'Поиск шрифта...';
    root.appendChild(search);

    var list = document.createElement('div');
    list.className = 'af-ff-list';
    root.appendChild(list);

    function addGroupTitle(text) {
      var title = document.createElement('div');
      title.className = 'af-ff-group';
      title.textContent = text;
      list.appendChild(title);
    }

    function addItem(fontItem) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'af-ff-item';

      var name = trim(fontItem.name);
      var sampleFamily = cssFontFamilyValue(name);

      var nameNode = document.createElement('div');
      nameNode.className = 'af-ff-name';
      nameNode.textContent = name;

      var sampleNode = document.createElement('div');
      sampleNode.className = 'af-ff-sample';
      sampleNode.textContent = 'The quick brown fox — 1234567890';

      if (sampleFamily) {
        button.style.fontFamily = sampleFamily;
      }

      button.setAttribute('data-af-name', name.toLowerCase());
      button.appendChild(nameNode);
      button.appendChild(sampleNode);

      button.addEventListener('click', function (event) {
        event.preventDefault();
        applyFont(editor, name);

        if (typeof closeFn === 'function') {
          closeFn();
        }
      });

      list.appendChild(button);
    }

    var hasAny = false;

    if (system.length) {
      addGroupTitle('Системные');
      for (var s = 0; s < system.length; s += 1) {
        hasAny = true;
        addItem(system[s]);
      }
    }

    if (custom.length) {
      addGroupTitle('Загруженные');
      for (var c = 0; c < custom.length; c += 1) {
        hasAny = true;
        addItem(custom[c]);
      }
    }

    if (!hasAny) {
      var empty = document.createElement('div');
      empty.className = 'af-ff-empty';
      empty.textContent = 'Шрифтов нет.';
      list.appendChild(empty);
    }

    function filterNow() {
      var query = trim(search.value).toLowerCase();
      var items = list.querySelectorAll('.af-ff-item');
      var any = false;

      for (var i = 0; i < items.length; i += 1) {
        var item = items[i];
        var hit = !query || (item.getAttribute('data-af-name') || '').indexOf(query) !== -1;
        item.style.display = hit ? '' : 'none';
        if (hit) any = true;
      }

      var emptyMsg = list.querySelector('.af-ff-empty');
      if (!any) {
        if (!emptyMsg) {
          emptyMsg = document.createElement('div');
          emptyMsg.className = 'af-ff-empty';
          emptyMsg.textContent = 'Ничего не найдено.';
          list.appendChild(emptyMsg);
        } else {
          emptyMsg.textContent = 'Ничего не найдено.';
        }
      } else if (emptyMsg && emptyMsg.textContent === 'Ничего не найдено.') {
        emptyMsg.parentNode.removeChild(emptyMsg);
      }
    }

    search.addEventListener('input', filterNow);

    window.setTimeout(function () {
      try {
        search.focus();
      } catch (e) {}
    }, 1);

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

    sc.formats.bbcode.set('font', {
      tags: {
        span: {
          'data-af-fontfamily': null
        }
      },
      isInline: true,
      format: function (element, content) {
        var raw = trim(
          element.getAttribute('data-af-fontfamily') ||
          element.style.fontFamily ||
          ''
        );

        var family = normalizeFontFamily(raw);
        var cleanedContent = stripZeroWidth(content);

        if (!family) {
          return cleanedContent;
        }

        cleanedContent = unwrapSameOuterFontBbcode(cleanedContent, family);

        if (!trim(cleanedContent)) {
          return '';
        }

        return '[font=' + family + ']' + cleanedContent + '[/font]';
      },
      html: function (token, attrs, content) {
        attrs = attrs || {};

        var family = normalizeFontFamily(attrs.defaultattr || attrs.font || '');
        if (!family) {
          return content;
        }

        var cssFamily = cssFontFamilyValue(family);

        return '<span class="af-bb-fontfamily" data-af-fontfamily="' + escHtml(family) + '" style="font-family:' + escHtml(cssFamily) + ';">' + asText(content) + '</span>';
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
          applyFont(this, 'Arial');
        }
      },
      txtExec: function (caller) {
        if (!openDropdown(this, caller)) {
          applyFont(this, 'Arial');
        }
      },
      tooltip: 'Шрифт (семейства)'
    };

    sc.command.set('font', commandImpl);
    sc.command.set(CMD, commandImpl);
    sc.command.set(CMD_ALIAS, commandImpl);

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

      return applyFont(editor, 'Arial');
    };

    var handlerObj = {
      id: PACK_ID,
      title: 'Шрифт (семейства)',
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

    window.afAeBuiltinHandlers[PACK_ID] = handlerFn;
    window.afAeBuiltinHandlers[PACK_ID_ALIAS] = handlerFn;
    window.afAeBuiltinHandlers[CMD] = handlerFn;
    window.afAeBuiltinHandlers[CMD_ALIAS] = handlerFn;

    window.afAqrBuiltinHandlers[PACK_ID] = handlerObj;
    window.afAqrBuiltinHandlers[PACK_ID_ALIAS] = handlerObj;
    window.afAqrBuiltinHandlers[CMD] = handlerObj;
    window.afAqrBuiltinHandlers[CMD_ALIAS] = handlerObj;
  }

  function boot() {
    registerBuiltinHandlers();
    enhanceAllEditors();
    window.setInterval(enhanceAllEditors, INSTANCE_SCAN_DELAY);

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

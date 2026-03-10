(function (window, document) {
  'use strict';

  if (window.__afAeTquoteLoaded) return;
  window.__afAeTquoteLoaded = true;

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  var ID = 'tquote';
  var CMD = 'af_tquote';
  var FILLER_ATTR = 'data-af-tquote-filler';

  function getSceditorRoot() {
    if (window.sceditor) return window.sceditor;
    if (window.jQuery && window.jQuery.sceditor) return window.jQuery.sceditor;
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
    x = trim(x);
    if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(x)) return x.toLowerCase();
    return '';
  }

  function buildTag(side, accent, bg) {
    side = normSide(side);
    accent = normHex(accent);
    bg = normHex(bg);

    var open = '[tquote side=' + side;
    if (accent) open += ' accent=' + accent;
    if (bg) open += ' bg=' + bg;
    open += ']';

    return { open: open, close: '[/tquote]' };
  }

  function parseTquoteAttrs(attrs) {
    attrs = attrs || {};

    var side = normSide(attrs.side || attrs.defaultattr || 'left');
    var accent = normHex(attrs.accent || attrs.color || '');
    var bg = normHex(attrs.bg || attrs.background || '');

    return {
      side: side,
      accent: accent,
      bg: bg
    };
  }

  function readBlockOptions(block) {
    if (!block || block.nodeType !== 1) {
      return {
        side: 'left',
        accent: '#ffffff',
        bg: '#111111'
      };
    }

    var style = block.style || {};

    var accent = normHex(
      block.getAttribute('data-accent') ||
      block.getAttribute('data-af-tquote-accent') ||
      style.getPropertyValue('--af-tq-accent') ||
      ''
    );

    var bg = normHex(
      block.getAttribute('data-bg') ||
      block.getAttribute('data-af-tquote-bg') ||
      style.getPropertyValue('--af-tq-bg') ||
      ''
    );

    return {
      side: normSide(block.getAttribute('data-side') || 'left'),
      accent: accent || '#ffffff',
      bg: bg || '#111111'
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

  function sameOptions(a, b) {
    return normSide(a.side) === normSide(b.side)
      && normHex(a.accent) === normHex(b.accent)
      && normHex(a.bg) === normHex(b.bg);
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
    opts = opts || {};
    var side = normSide(opts.side || 'left');
    var accent = normHex(opts.accent || '') || '#ffffff';
    var bg = normHex(opts.bg || '') || '#111111';

    block.setAttribute('data-af-tquote', '1');
    block.setAttribute('data-side', side);
    block.setAttribute('data-accent', accent);
    block.setAttribute('data-bg', bg);
    block.classList.add('mycode_quote');
    block.classList.add('af-aqr-tquote');

    block.style.setProperty('--af-tq-accent', accent);
    block.style.setProperty('--af-tq-bg', bg);
  }

  function syncEditor(editor) {
    if (!editor) return;

    try {
      var dom = getEditorDom(editor);
      if (dom && dom.body) {
        var blocks = dom.body.querySelectorAll('blockquote[data-af-tquote]');
        for (var i = 0; i < blocks.length; i += 1) {
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

    var tag = buildTag(opts.side, opts.accent, opts.bg);
    return insertWrap(tag.open, tag.close, { sceditor: editorOrTextarea });
  }

  function getCurrentTquoteOptions(editor) {
    var dom = getEditorDom(editor);
    var range = getCurrentRange(editor);

    if (!dom || !range) {
      return {
        side: 'left',
        accent: '#ffffff',
        bg: '#111111'
      };
    }

    var block = closestTquoteBlock(range.startContainer, dom.body);
    return block ? readBlockOptions(block) : {
      side: 'left',
      accent: '#ffffff',
      bg: '#111111'
    };
  }

  function normalizeFormatContent(content) {
    content = asText(content)
      .replace(new RegExp('<br\\b[^>]*' + FILLER_ATTR + '[^>]*>', 'gi'), '')
      .replace(/\u200B/g, '');

    return content;
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
        var inner = normalizeFormatContent(content);

        if (!trim(inner)) {
          return '';
        }

        var tag = buildTag(opts.side, opts.accent, opts.bg);
        return tag.open + inner + tag.close;
      },
      html: function (token, attrs, content) {
        var opts = parseTquoteAttrs(attrs);
        var side = normSide(opts.side || 'left');
        var accent = normHex(opts.accent || '') || '#ffffff';
        var bg = normHex(opts.bg || '') || '#111111';

        return '' +
          '<blockquote class="mycode_quote af-aqr-tquote" ' +
            'data-af-tquote="1" ' +
            'data-side="' + escHtml(side) + '" ' +
            'data-accent="' + escHtml(accent) + '" ' +
            'data-bg="' + escHtml(bg) + '" ' +
            'style="--af-tq-accent:' + escHtml(accent) + ';--af-tq-bg:' + escHtml(bg) + ';">' +
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
        'padding:55px;' +
        'border-radius:6px;' +
        'background:var(--af-tq-bg, rgba(255,255,255,.06));' +
        'overflow:hidden;' +
        'text-align:justify;' +
        'border:none;' +
        'margin:10px 0;' +
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
        'width:60px;' +
        'height:60px;' +
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
        'left:1px;' +
        'transform:rotate(-190deg) scaleX(-1);' +
      '}' +
      'blockquote.af-aqr-tquote[data-af-tquote="1"][data-side="right"]::before{' +
        'right:1px;' +
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

  function makeDropdown(editor, caller, initialOpts) {
    initialOpts = initialOpts || {
      side: 'left',
      accent: '#ffffff',
      bg: '#111111'
    };

    ensureTquoteIframeCss(editor);

    var wrap = document.createElement('div');
    wrap.className = 'af-tquote-dd';
    wrap.setAttribute('data-side', normSide(initialOpts.side));

    wrap.innerHTML =
      '<div class="af-tquote-dd-hd">' +
      '  <div class="af-tquote-dd-title">Типографическая цитата</div>' +
      '</div>' +
      '<div class="af-tquote-dd-body">' +

      '  <div class="af-tquote-dd-row">' +
      '    <div class="af-tquote-dd-label">Сторона акцента</div>' +
      '    <div class="af-tquote-dd-seg" role="group" aria-label="Сторона">' +
      '      <button type="button" class="af-tquote-dd-segbtn" data-side="left">Слева</button>' +
      '      <button type="button" class="af-tquote-dd-segbtn" data-side="right">Справа</button>' +
      '    </div>' +
      '  </div>' +

      '  <div class="af-tquote-dd-grid">' +

      '    <label class="af-tquote-dd-field">' +
      '      <span>Цвет акцента</span>' +
      '      <div class="af-tquote-dd-color">' +
      '        <input class="af-tquote-accent" type="color" value="' + escHtml(normHex(initialOpts.accent) || '#ffffff') + '">' +
      '        <input class="af-tquote-accent-hex" type="text" value="' + escHtml(normHex(initialOpts.accent) || '#ffffff') + '" maxlength="7" placeholder="#aabbcc">' +
      '      </div>' +
      '    </label>' +

      '    <label class="af-tquote-dd-field">' +
      '      <span>Цвет блока</span>' +
      '      <div class="af-tquote-dd-color">' +
      '        <input class="af-tquote-bg" type="color" value="' + escHtml(normHex(initialOpts.bg) || '#111111') + '">' +
      '        <input class="af-tquote-bg-hex" type="text" value="' + escHtml(normHex(initialOpts.bg) || '#111111') + '" maxlength="7" placeholder="#112233">' +
      '      </div>' +
      '    </label>' +

      '  </div>' +

      '  <div class="af-tquote-dd-preview" aria-hidden="true">' +
      '    <div class="af-tquote-dd-previewbox" data-side="' + escHtml(normSide(initialOpts.side)) + '">' +
      '      <span class="af-tquote-dd-previewtext">Предпросмотр блока</span>' +
      '    </div>' +
      '  </div>' +

      '  <div class="af-tquote-dd-actions">' +
      '    <button type="button" class="button af-tquote-insert">Применить</button>' +
      '  </div>' +

      '</div>';

    function closeDd() {
      try { editor.closeDropDown(true); } catch (e0) {}
    }

    var btns = wrap.querySelectorAll('.af-tquote-dd-segbtn');
    var btnInsert = wrap.querySelector('.af-tquote-insert');

    var inpAccent = wrap.querySelector('.af-tquote-accent');
    var inpAccentHex = wrap.querySelector('.af-tquote-accent-hex');
    var inpBg = wrap.querySelector('.af-tquote-bg');
    var inpBgHex = wrap.querySelector('.af-tquote-bg-hex');
    var preview = wrap.querySelector('.af-tquote-dd-previewbox');

    function currentOpts() {
      return {
        side: wrap.getAttribute('data-side') || 'left',
        accent: normHex(inpAccentHex ? inpAccentHex.value : '') || '#ffffff',
        bg: normHex(inpBgHex ? inpBgHex.value : '') || '#111111'
      };
    }

    function setSide(side) {
      side = normSide(side);
      wrap.setAttribute('data-side', side);

      for (var i = 0; i < btns.length; i++) {
        btns[i].classList.toggle('is-active', btns[i].getAttribute('data-side') === side);
      }

      applyPreview();
    }

    function syncHexFromColor(inpColor, inpHex) {
      try { inpHex.value = asText(inpColor.value).toLowerCase(); } catch (e0) {}
    }

    function syncColorFromHex(inpHex, inpColor) {
      var v = normHex(inpHex.value);
      if (v) {
        try { inpColor.value = v; } catch (e0) {}
        inpHex.value = v;
      }
    }

    function applyPreview() {
      if (!preview) return;

      var opts = currentOpts();

      preview.style.setProperty('--af-tq-accent', opts.accent);
      preview.style.setProperty('--af-tq-bg', opts.bg);
      preview.setAttribute('data-side', opts.side);
    }

    setSide(normSide(initialOpts.side || 'left'));
    applyPreview();

    wrap.addEventListener('click', function (e) {
      var b = e.target && e.target.closest ? e.target.closest('button[data-side]') : null;
      if (!b) return;
      e.preventDefault();
      setSide(b.getAttribute('data-side'));
    }, false);

    if (inpAccent) inpAccent.addEventListener('input', function () {
      if (inpAccentHex) syncHexFromColor(inpAccent, inpAccentHex);
      applyPreview();
    });

    if (inpBg) inpBg.addEventListener('input', function () {
      if (inpBgHex) syncHexFromColor(inpBg, inpBgHex);
      applyPreview();
    });

    if (inpAccentHex) inpAccentHex.addEventListener('change', function () {
      if (inpAccent) syncColorFromHex(inpAccentHex, inpAccent);
      applyPreview();
    });

    if (inpBgHex) inpBgHex.addEventListener('change', function () {
      if (inpBg) syncColorFromHex(inpBgHex, inpBg);
      applyPreview();
    });

    function applyNow() {
      applyTquote(editor, currentOpts());
      closeDd();
    }

    if (btnInsert) {
      btnInsert.addEventListener('click', function (ev) {
        ev.preventDefault();
        applyNow();
      });
    }

    function onEnter(ev) {
      if (!ev) return;
      if (ev.key === 'Enter') {
        ev.preventDefault();
        applyNow();
      }
    }

    if (inpAccentHex) inpAccentHex.addEventListener('keydown', onEnter);
    if (inpBgHex) inpBgHex.addEventListener('keydown', onEnter);

    editor.createDropDown(caller, 'sceditor-tquote-picker', wrap);
  }

  function openSceditorDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;

    ensureTquoteIframeCss(editor);

    var opts = getCurrentTquoteOptions(editor);

    try { editor.closeDropDown(true); } catch (e0) {}
    makeDropdown(editor, caller, opts);
    return true;
  }

  function patchSceditorTquoteCommand() {
    if (!window.jQuery) return false;
    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.command) return false;

    function fallbackApply(ed) {
      applyTquote(ed, {
        side: 'left',
        accent: '#ffffff',
        bg: '#111111'
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

  function enhanceAllEditors() {
    var sc = getSceditorRoot();
    if (!sc || typeof sc.instance !== 'function') return;

    var textareas = document.querySelectorAll('textarea');
    for (var i = 0; i < textareas.length; i += 1) {
      try {
        var editor = sc.instance(textareas[i]);
        if (editor) {
          ensureTquoteIframeCss(editor);
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
      side: 'left',
      accent: '#ffffff',
      bg: '#111111'
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
      side: 'left',
      accent: '#ffffff',
      bg: '#111111'
    });
  }

  function registerHandlers() {
    window.afAqrBuiltinHandlers[ID] = handlerObj;
    window.afAqrBuiltinHandlers[CMD] = handlerObj;

    window.afAeBuiltinHandlers[ID] = handlerFn;
    window.afAeBuiltinHandlers[CMD] = handlerFn;
  }

  registerHandlers();
  waitAnd(registerTquoteBbcode, 150);
  waitAnd(patchSceditorTquoteCommand, 150);
  waitAnd(function () {
    enhanceAllEditors();
    return false;
  }, 20);

  for (var i = 1; i <= 20; i++) {
    setTimeout(registerHandlers, i * 250);
    setTimeout(enhanceAllEditors, i * 300);
  }
})(window, document);

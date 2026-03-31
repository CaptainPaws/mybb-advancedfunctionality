(function () {
  'use strict';

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  if (window.__afAeListsInitialized) return;
  window.__afAeListsInitialized = true;

  function asText(x) { return String(x == null ? '' : x); }

  var LIST_BTNS = [
    { cmd: 'af_ul_disc',        attr: '',            tooltip: 'Список: точки (•)' },
    { cmd: 'af_ul_circle',      attr: 'circle',      tooltip: 'Список: круги (◦)' },
    { cmd: 'af_ul_square',      attr: 'square',      tooltip: 'Список: квадраты (■)' },
    { cmd: 'af_ul_decimal',     attr: 'decimal',     tooltip: 'Список: нумерация (1,2,3)' },
    { cmd: 'af_ul_lower_roman', attr: 'lower-roman', tooltip: 'Список: римские (i, ii, iii)' },
    { cmd: 'af_ul_upper_roman', attr: 'upper-roman', tooltip: 'Список: римские (I, II, III)' },
    { cmd: 'af_ul_upper_alpha', attr: 'upper-alpha', tooltip: 'Список: буквы (A, B, C)' },
    { cmd: 'af_ul_lower_alpha', attr: 'lower-alpha', tooltip: 'Список: буквы (a, b, c)' }
  ];

  function debugLog() {
    if (!window.__afAeDebug && !window.AE_DEBUG) return;
    try { console.log.apply(console, arguments); } catch (e) {}
  }

  function cmdToAttr(cmd) {
    cmd = asText(cmd).trim();
    for (var i = 0; i < LIST_BTNS.length; i++) {
      if (LIST_BTNS[i].cmd === cmd) return LIST_BTNS[i].attr;
    }
    return '';
  }

  function hasSceditor() {
    return !!(window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.sceditor === 'function');
  }

  function isSourceMode(inst) {
    try {
      if (!inst) return false;
      if (typeof inst.sourceMode === 'function') return !!inst.sourceMode();
      if (typeof inst.isSourceMode === 'function') return !!inst.isSourceMode();
    } catch (e) {}
    return false;
  }

  function getEditorFormat(inst) {
    try {
      if (inst && inst.opts && typeof inst.opts.format === 'string') {
        return String(inst.opts.format).toLowerCase();
      }
    } catch (e) {}
    return '';
  }

  function isBbcodeEditorMode(inst) {
    var fmt = getEditorFormat(inst);
    if (!fmt) return true;
    return fmt === 'bbcode';
  }

  function getSceditorInstanceFromCaller(caller) {
    if (!window.jQuery || !caller || !caller.nodeType || caller.nodeType !== 1) return null;
    var $ = window.jQuery;
    try {
      var container = caller.closest ? caller.closest('.sceditor-container') : null;
      if (!container) return null;
      var sibling = container.previousElementSibling;
      while (sibling) {
        if (sibling.tagName === 'TEXTAREA') {
          var i1 = $(sibling).sceditor && $(sibling).sceditor('instance');
          if (i1 && typeof i1.insertText === 'function') return i1;
        }
        sibling = sibling.previousElementSibling;
      }
      var taInContainer = container.querySelector('textarea');
      if (taInContainer) {
        var i2 = $(taInContainer).sceditor && $(taInContainer).sceditor('instance');
        if (i2 && typeof i2.insertText === 'function') return i2;
      }
    } catch (e) {}
    return null;
  }

  function getActiveSceditorInstance() {
    if (!window.jQuery) return null;
    var $ = window.jQuery;
    try {
      var ae = document.activeElement;
      if (ae && ae.classList && ae.classList.contains('sceditor-container')) {
        var prev = ae.previousElementSibling;
        if (prev && prev.tagName === 'TEXTAREA') {
          var inst1 = $(prev).sceditor && $(prev).sceditor('instance');
          if (inst1 && typeof inst1.insertText === 'function') return inst1;
        }
      }

      var activeFrame = document.activeElement && document.activeElement.tagName === 'IFRAME' ? document.activeElement : null;
      if (activeFrame) {
        var c = activeFrame.closest ? activeFrame.closest('.sceditor-container') : null;
        if (c && c.previousElementSibling && c.previousElementSibling.tagName === 'TEXTAREA') {
          var inst2 = $(c.previousElementSibling).sceditor && $(c.previousElementSibling).sceditor('instance');
          if (inst2 && typeof inst2.insertText === 'function') return inst2;
        }
      }
    } catch (e) {}
    return null;
  }

  function getSceditorInstanceFromCtx(ctx, caller) {
    if (ctx && typeof ctx.insertText === 'function') return ctx;
    if (ctx && ctx.sceditor && typeof ctx.sceditor.insertText === 'function') return ctx.sceditor;
    if (ctx && ctx.inst && typeof ctx.inst.insertText === 'function') return ctx.inst;
    if (ctx && ctx.instance && typeof ctx.instance.insertText === 'function') return ctx.instance;

    var fromCaller = getSceditorInstanceFromCaller(caller);
    if (fromCaller) return fromCaller;

    var activeInst = getActiveSceditorInstance();
    if (activeInst) return activeInst;

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

  function getTextareaFromCtx(ctx) {
    if (ctx && ctx.textarea && ctx.textarea.nodeType === 1) return ctx.textarea;
    if (ctx && ctx.ta && ctx.ta.nodeType === 1) return ctx.ta;

    var ae = document.activeElement;
    if (ae && ae.tagName === 'TEXTAREA') return ae;

    return document.querySelector('textarea#message') ||
      document.querySelector('textarea[name="message"]') ||
      null;
  }

  function insertIntoTextarea(chunk, ctx) {
    var ta = getTextareaFromCtx(ctx);
    if (!ta) return false;

    try {
      var start = ta.selectionStart || 0;
      var end = ta.selectionEnd || 0;
      var val = String(ta.value || '');
      var before = val.slice(0, start);
      var sel = val.slice(start, end);
      var after = val.slice(end);

      ta.value = before + chunk + sel + after;

      var caret = before.length + chunk.length;
      ta.focus();
      ta.setSelectionRange(caret, caret);
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      return true;
    } catch (e) {
      return false;
    }
  }

  function getSourceTextareaFromInstance(inst) {
    try {
      if (inst && inst.sourceEditor && inst.sourceEditor.nodeType === 1) {
        return inst.sourceEditor;
      }
    } catch (e0) {}

    try {
      var cont = inst && typeof inst.getContainer === 'function' ? inst.getContainer() : null;
      if (!cont && inst && inst.container && inst.container.nodeType === 1) cont = inst.container;
      if (cont && cont.querySelector) {
        var ta = cont.querySelector('textarea.sceditor-textarea');
        if (ta) return ta;
        ta = cont.querySelector('textarea');
        if (ta) return ta;
      }
    } catch (e1) {}

    return null;
  }

  function insertIntoSourceInstance(inst, chunk, ctx) {
    var ta = getSourceTextareaFromInstance(inst);
    if (ta) {
      return insertIntoTextarea(chunk, { textarea: ta });
    }

    try {
      if (inst && typeof inst.insertText === 'function') {
        inst.insertText(chunk, '');
        return true;
      }
    } catch (e0) {}

    try {
      if (inst && typeof inst.insert === 'function') {
        inst.insert(chunk, '');
        return true;
      }
    } catch (e1) {}

    return insertIntoTextarea(chunk, ctx || {});
  }

  function stripListBoundaryBreaks(html) {
    html = String(html == null ? '' : html);

    html = html.replace(/^(?:\s|&nbsp;|<br\s*\/?>)+/gi, '');
    html = html.replace(/(?:\s|&nbsp;|<br\s*\/?>)+$/gi, '');
    html = html.replace(/(?:<br\s*\/?>\s*)+(?=<li\b)/gi, '');
    html = html.replace(/<\/li>(?:\s|&nbsp;|<br\s*\/?>)+(?=<li\b)/gi, '</li>');

    return html;
  }

  function isListSpacerNode(node) {
    if (!node) return false;

    if (node.nodeType === 3) {
      return String(node.nodeValue || '').replace(/\u00a0/g, ' ').trim() === '';
    }

    if (node.nodeType !== 1) return false;

    var tag = String(node.nodeName || '').toLowerCase();
    if (tag === 'br') return true;

    if (tag === 'p' || tag === 'div') {
      var html = String(node.innerHTML || '')
        .replace(/&nbsp;/gi, '')
        .replace(/\s+/g, '')
        .toLowerCase();

      return html === '' || html === '<br>' || html === '<br/>' || html === '<br />';
    }

    return false;
  }

  function cleanupListBoundaryBreaks(inst) {
    try {
      if (!inst || typeof inst.getBody !== 'function') return;

      var body = inst.getBody();
      if (!body) return;

      var lists = body.querySelectorAll('ul, ol');
      for (var i = 0; i < lists.length; i++) {
        var list = lists[i];

        while (list.firstChild && isListSpacerNode(list.firstChild)) {
          list.removeChild(list.firstChild);
        }

        while (list.lastChild && isListSpacerNode(list.lastChild)) {
          list.removeChild(list.lastChild);
        }

        var child = list.firstChild;
        while (child) {
          var next = child.nextSibling;
          if (isListSpacerNode(child)) {
            list.removeChild(child);
          }
          child = next;
        }

        while (list.previousSibling && isListSpacerNode(list.previousSibling)) {
          list.parentNode.removeChild(list.previousSibling);
        }

        while (list.nextSibling && isListSpacerNode(list.nextSibling)) {
          list.parentNode.removeChild(list.nextSibling);
        }
      }
    } catch (e) {}
  }

  function buildHtmlListTag(tag, style, padding, content) {
    style = String(style || '').trim().toLowerCase();
    padding = String(padding || '').trim();
    content = stripListBoundaryBreaks(content);

    return '<' + tag +
      ' class="af-ae-list af-ae-list--' + style + '"' +
      ' data-list="' + style + '"' +
      ' data-af-list-style="' + style + '"' +
      ' style="list-style-type:' + style + '; padding-left:' + padding + ';">' +
        content +
      '</' + tag + '>';
  }  

  // ===============================
  // Canonical BBCode chunk (твой канон)
  // ===============================
  function buildCanonicalChunk(attr) {
    attr = asText(attr).trim();
    var open = '[ul]';
    if (isOrderedAttr(attr)) open = '[ol=' + attr + ']';
    else if (attr) open = '[ul=' + attr + ']';
    var close = isOrderedAttr(attr) ? '[/ol]' : '[/ul]';
    return open + '\n' +
      '[li][/li]\n' +
      close;
  }

  // ===============================
  // WYSIWYG HTML chunk (для вставки в iframe)
  // ===============================
  function normAttr(a) {
    a = (a == null) ? '' : String(a);
    return a.trim();
  }

  // backward compatibility: [ul=i] => decimal
  function olStyleForAttr(a) {
    a = normAttr(a);

    var low = a.toLowerCase();
    if (low === 'i') return 'decimal';
    if (low === 'lower-roman') return 'lower-roman';
    if (low === 'upper-roman') return 'upper-roman';
    if (low === 'upper-alpha') return 'upper-alpha';
    if (low === 'lower-alpha') return 'lower-alpha';

    return 'decimal';
  }

  function extractListStyle(el, fallback) {
    var style = '';

    try {
      if (el && typeof el.getAttribute === 'function') {
        var dataList = el.getAttribute('data-list');
        if (dataList) style = String(dataList);
      }
    } catch (e0) {}

    if (!style) {
      try { style = String((el && el.style && el.style.listStyleType) ? el.style.listStyleType : ''); } catch (e1) { style = ''; }
    }

    if (!style && el && el.ownerDocument && el.ownerDocument.defaultView && el.ownerDocument.defaultView.getComputedStyle) {
      try {
        var cs = el.ownerDocument.defaultView.getComputedStyle(el);
        style = cs && cs.listStyleType ? String(cs.listStyleType) : '';
      } catch (e2) { style = ''; }
    }

    style = String(style || '').trim().toLowerCase();
    return style || String(fallback || '').toLowerCase();
  }

  function isOrderedAttr(attr) {
    var low = String(attr || '').trim().toLowerCase();
    return low === 'i' || low === 'decimal' || low === 'lower-roman' || low === 'upper-roman' || low === 'upper-alpha' || low === 'lower-alpha';
  }

  function isUnorderedAttr(attr) {
    var low = String(attr || '').trim().toLowerCase();
    return low === '' || low === 'disc' || low === 'circle' || low === 'square';
  }

  function normalizeListAttr(style, fallback) {
    var low = String(style || '').trim().toLowerCase();
    if (!low) low = String(fallback || '').trim().toLowerCase();
    if (low === 'i') low = 'decimal';

    if (isUnorderedAttr(low)) return { type: 'ul', attr: (low === 'disc' ? '' : low) };
    if (isOrderedAttr(low)) return { type: 'ol', attr: (low === 'decimal' ? 'decimal' : low) };

    return { type: 'ul', attr: '' };
  }

  function buildHtmlListChunk(attr) {
    attr = normAttr(attr);

    if (!attr) {
      return buildHtmlListTag(
        'ul',
        'disc',
        '1.4em',
        '<li style="list-style-type:disc;"><br></li>'
      );
    }

    if (attr === 'square' || attr === 'circle') {
      return buildHtmlListTag(
        'ul',
        attr,
        '1.4em',
        '<li style="list-style-type:' + attr + ';"><br></li>'
      );
    }

    var lst = olStyleForAttr(attr);
    return buildHtmlListTag(
      'ol',
      lst,
      '1.6em',
      '<li style="list-style-type:' + lst + ';"><br></li>'
    );
  }

  // ===============================
  // WYSIWYG CSS (чтобы SCEditor не “съедал” типы)
  // ===============================
  function ensureListCss(inst) {
    try {
      if (!inst || typeof inst.getBody !== 'function') return;

      var body = inst.getBody();
      if (!body || !body.ownerDocument) return;

      var doc = body.ownerDocument;
      var head = doc.head || doc.getElementsByTagName('head')[0];
      if (!head) return;

      if (doc.getElementById('af-ae-lists-css')) return;

      var css =
        'ol{padding-left:1.6em !important;}' +
        'ul{padding-left:1.4em !important;}' +
        'li{display:list-item !important;}';

      var st = doc.createElement('style');
      st.id = 'af-ae-lists-css';
      st.type = 'text/css';
      st.appendChild(doc.createTextNode(css));
      head.appendChild(st);
    } catch (e) {}
  }

  function bindToSourceListNormalization(inst) {
    if (!inst || inst.__afAeListToSourceBound) return;
    inst.__afAeListToSourceBound = true;

    try {
      if (typeof inst.bind !== 'function') return;
      inst.bind('toSource', function (html) {
        html = String(html == null ? '' : html);

        html = html.replace(/<ul[^>]*style="[^"]*list-style-type:([^;"]+)[^"]*"[^>]*>/gi, function (_m, type) {
          return '<ul data-list="' + String(type || '').trim().toLowerCase() + '">';
        });

        html = html.replace(/<ol[^>]*style="[^"]*list-style-type:([^;"]+)[^"]*"[^>]*>/gi, function (_m, type) {
          return '<ol data-list="' + String(type || '').trim().toLowerCase() + '">';
        });

        return html;
      });
    } catch (e) {}
  }

  // ===============================
  // BBCode plugin patch
  // ===============================
  function afAeEnsureMybbListsBbcode(inst) {
    if (!hasSceditor()) return;

    function getBb() {
      try {
        var bb = jQuery.sceditor.plugins && jQuery.sceditor.plugins.bbcode
          ? jQuery.sceditor.plugins.bbcode.bbcode
          : null;
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
    if (!bb) {
      var t = 0;
      (function retry() {
        t++;
        var b2 = getBb();
        if (b2) {
          try { afAeEnsureMybbListsBbcode(inst); } catch (e2) {}
          return;
        }
        if (t < 25) return setTimeout(retry, 120);
      })();
      return;
    }

    if (bb.__afAeMybbListsPatched) return;
    bb.__afAeMybbListsPatched = true;

    try {
      bb.set('li', {
        isInline: false,
        html: '<li>{0}</li>',
        format: '[li]{0}[/li]',
        tags: {
          li: {
            format: function (el, content) {
              content = (content || '');
              if (!String(content).trim()) content = '';
              return '[li]' + content + '[/li]';
            }
          }
        }
      });
    } catch (eLI) {}

    try {
      if (jQuery.sceditor && jQuery.sceditor.plugins && jQuery.sceditor.plugins.bbcode && jQuery.sceditor.plugins.bbcode.bbcode) {
        delete jQuery.sceditor.plugins.bbcode.bbcode.list;
        delete jQuery.sceditor.plugins.bbcode.bbcode.ul;
        delete jQuery.sceditor.plugins.bbcode.bbcode.ol;
      }
    } catch (eDelete) {}

    try {
      bb.set('ul', {
        isBlock: true,

        html: function (_token, attrs, content) {
          var type = (attrs && attrs.defaultattr != null) ? String(attrs.defaultattr).trim().toLowerCase() : 'disc';
          if (type === 'i') type = 'decimal';

          var map = {
            disc: ['ul', 'disc', '1.4em'],
            circle: ['ul', 'circle', '1.4em'],
            square: ['ul', 'square', '1.4em'],
            decimal: ['ol', 'decimal', '1.6em'],
            'lower-roman': ['ol', 'lower-roman', '1.6em'],
            'upper-roman': ['ol', 'upper-roman', '1.6em'],
            'upper-alpha': ['ol', 'upper-alpha', '1.6em'],
            'lower-alpha': ['ol', 'lower-alpha', '1.6em']
          };

          var conf = map[type] || map.disc;
          return buildHtmlListTag(conf[0], conf[1], conf[2], content || '');
        },

        format: function (el, content) {
          var norm = normalizeListAttr(extractListStyle(el, 'disc'), 'disc');

          if (norm.type === 'ol') {
            return '[ol=' + norm.attr + ']' + (content || '') + '[/ol]';
          }

          if (norm.attr) return '[ul=' + norm.attr + ']' + (content || '') + '[/ul]';
          return '[ul]' + (content || '') + '[/ul]';
        },

        tags: {
          ul: {
            format: function (el, content) {
              var norm = normalizeListAttr(extractListStyle(el, 'disc'), 'disc');

              if (norm.type === 'ol') {
                return '[ol=' + norm.attr + ']' + (content || '') + '[/ol]';
              }

              if (norm.attr) return '[ul=' + norm.attr + ']' + (content || '') + '[/ul]';
              return '[ul]' + (content || '') + '[/ul]';
            }
          }
        }
      });
    } catch (eUL) {}

    try {
      bb.set('ol', {
        isBlock: true,

        html: function (_token, attrs, content) {
          var type = (attrs && attrs.defaultattr != null) ? String(attrs.defaultattr).trim().toLowerCase() : 'decimal';
          if (type === 'i') type = 'decimal';

          var map = {
            decimal: 'decimal',
            'lower-roman': 'lower-roman',
            'upper-roman': 'upper-roman',
            'upper-alpha': 'upper-alpha',
            'lower-alpha': 'lower-alpha'
          };

          type = map[type] || 'decimal';
          return buildHtmlListTag('ol', type, '1.6em', content || '');
        },

        format: function (el, content) {
          var norm = normalizeListAttr(extractListStyle(el, 'decimal'), 'decimal');
          if (norm.type !== 'ol') {
            return '[ul' + (norm.attr ? '=' + norm.attr : '') + ']' + (content || '') + '[/ul]';
          }
          return '[ol=' + norm.attr + ']' + (content || '') + '[/ol]';
        },

        tags: {
          ol: {
            format: function (el, content) {
              var norm = normalizeListAttr(extractListStyle(el, 'decimal'), 'decimal');
              if (norm.type !== 'ol') {
                return '[ul' + (norm.attr ? '=' + norm.attr : '') + ']' + (content || '') + '[/ul]';
              }
              return '[ol=' + norm.attr + ']' + (content || '') + '[/ol]';
            }
          }
        }
      });
    } catch (eOL2) {}

    try {
      bb.set('bulletlist', {
        isInline: false,
        html: function (_t, _a, c) {
          return buildHtmlListTag('ul', 'disc', '1.4em', c || '');
        },
        format: function (_el, c) {
          return '[ul]' + (c || '') + '[/ul]';
        }
      });
    } catch (eBL) {}

    try {
      bb.set('orderedlist', {
        isInline: false,
        html: function (_t, _a, c) {
          return buildHtmlListTag('ol', 'decimal', '1.6em', c || '');
        },
        format: function (_el, c) {
          return '[ol=decimal]' + (c || '') + '[/ol]';
        }
      });
    } catch (eOL) {}
  }
  function bindListEnterBehavior(inst) {
    if (!inst || inst.__afAeListEnterBound) return;
    inst.__afAeListEnterBound = true;

    function closestLi(node) {
      while (node && node.nodeType === 1) {
        if (String(node.nodeName).toLowerCase() === 'li') return node;
        node = node.parentNode;
      }
      return null;
    }

    function isLiEmpty(li) {
      if (!li) return true;
      var text = String(li.textContent || '').replace(/\u00a0/g, ' ').trim();
      if (text.length) return false;
      var media = li.querySelector && li.querySelector('img,video,audio,iframe,table,blockquote,pre');
      return !media;
    }

    function placeCaretAtStart(node, doc) {
      if (!node || !doc) return;
      var sel = doc.getSelection && doc.getSelection();
      if (!sel) return;
      var range = doc.createRange();
      range.selectNodeContents(node);
      range.collapse(true);
      sel.removeAllRanges();
      sel.addRange(range);
    }

    function onEnter(e) {
      if (!e || e.key !== 'Enter' || e.shiftKey || e.ctrlKey || e.metaKey || e.altKey) return;

      var node = null;
      try { node = (typeof inst.currentNode === 'function') ? inst.currentNode() : null; } catch (e0) { node = null; }
      if (!node) return;

      var li = closestLi(node.nodeType === 1 ? node : node.parentNode);
      if (!li) return;

      e.preventDefault();

      var doc = li.ownerDocument;
      var list = li.parentNode;
      if (!doc || !list) return false;

      if (isLiEmpty(li)) {
        var listParent = list.parentNode;
        var marker = doc.createElement('p');
        marker.appendChild(doc.createElement('br'));

        li.parentNode.removeChild(li);

        if (!list.querySelector('li')) {
          if (listParent) {
            if (list.nextSibling) listParent.insertBefore(marker, list.nextSibling);
            else listParent.appendChild(marker);
            listParent.removeChild(list);
            placeCaretAtStart(marker, doc);
          }
        } else {
          if (list.nextSibling) listParent.insertBefore(marker, list.nextSibling);
          else listParent.appendChild(marker);
          placeCaretAtStart(marker, doc);
        }
        return false;
      }

      var newLi = doc.createElement('li');
      newLi.appendChild(doc.createElement('br'));
      if (li.nextSibling) list.insertBefore(newLi, li.nextSibling);
      else list.appendChild(newLi);
      placeCaretAtStart(newLi, doc);
      return false;
    }

    try {
      if (typeof inst.keyDown === 'function') {
        inst.keyDown(onEnter);
        return;
      }
    } catch (e1) {}

    try {
      if (typeof inst.getBody === 'function') {
        var body = inst.getBody();
        if (body && !body.__afAeListEnterBound) {
          body.__afAeListEnterBound = true;
          body.addEventListener('keydown', onEnter, false);
        }
      }
    } catch (e2) {}
  }

  // ===============================
  // Insert list (единственная точка)
  // ===============================
  function hasUsableIframeBody(inst) {
    try {
      if (!inst || typeof inst.getBody !== 'function') return false;
      var body = inst.getBody();
      if (!body || body.nodeType !== 1 || !body.ownerDocument) return false;
      var frame = body.ownerDocument.defaultView && body.ownerDocument.defaultView.frameElement;
      if (frame && frame.ownerDocument && frame.ownerDocument.documentElement) {
        return frame.ownerDocument.documentElement.contains(frame);
      }
      return !!body.isConnected;
    } catch (e) {}
    return false;
  }

  function describeInstance(inst) {
    if (!inst) return { exists: false };
    var out = { exists: true, textareaId: '', textareaName: '', containerClass: '' };
    try {
      var ta = inst.getOriginal && inst.getOriginal();
      if (ta) {
        out.textareaId = ta.id || '';
        out.textareaName = ta.name || '';
      }
    } catch (e0) {}
    try {
      var c = inst.getContainer && inst.getContainer();
      if (c && c.className) out.containerClass = String(c.className);
    } catch (e1) {}
    return out;
  }

  function insertHtmlViaDom(inst, html) {
    try {
      var body = inst && inst.getBody && inst.getBody();
      var doc = body && body.ownerDocument;
      if (!doc) return false;
      var sel = doc.getSelection && doc.getSelection();
      if (!sel || !sel.rangeCount) return false;
      var range = sel.getRangeAt(0);
      range.deleteContents();
      var wrap = doc.createElement('div');
      wrap.innerHTML = html;
      var frag = doc.createDocumentFragment();
      var node = null;
      var last = null;
      while ((node = wrap.firstChild)) {
        last = frag.appendChild(node);
      }
      range.insertNode(frag);
      if (last) {
        range.setStartAfter(last);
        range.collapse(true);
        sel.removeAllRanges();
        sel.addRange(range);
      }
      return true;
    } catch (e) {}
    return false;
  }

  function insertCanonicalList(editorOrCtx, attr, meta) {
    meta = meta || {};

    var inst = getSceditorInstanceFromCtx(editorOrCtx, meta.caller);
    var sourceFlag = isSourceMode(inst);
    var hasBody = hasUsableIframeBody(inst);
    var mode = sourceFlag ? 'source' : (hasBody ? 'wysiwyg' : 'source');

    if (!inst) {
      var textareaInserted = insertIntoTextarea(buildCanonicalChunk(attr), editorOrCtx);
      debugLog('[AE-LISTS] insert', {
        handler: meta.handler || 'unknown',
        cmd: meta.cmd || '',
        mode: 'textarea',
        isSourceMode: false,
        hasIframeBody: false,
        insertedVia: textareaInserted ? 'textarea-insert' : 'none',
        instance: describeInstance(inst)
      });
      return textareaInserted;
    }

    try { afAeEnsureMybbListsBbcode(inst); } catch (e0) {}
    try { ensureListCss(inst); } catch (e1) {}
    try { bindToSourceListNormalization(inst); } catch (e2) {}
    try { bindListEnterBehavior(inst); } catch (e3) {}

    if (mode === 'source') {
      var chunk = buildCanonicalChunk(attr);
      var insertedSourceOk = insertIntoSourceInstance(inst, chunk, editorOrCtx);

      debugLog('[AE-LISTS] insert', {
        handler: meta.handler || 'unknown',
        cmd: meta.cmd || '',
        mode: 'source',
        isSourceMode: sourceFlag,
        hasIframeBody: hasBody,
        requestedAttr: attr,
        format: getEditorFormat(inst) || '(unknown)',
        insertedVia: insertedSourceOk ? 'source-insert' : 'none',
        bbcodeChunk: chunk,
        instance: describeInstance(inst)
      });

      try { if (typeof inst.updateOriginal === 'function') inst.updateOriginal(); } catch (e4) {}
      return insertedSourceOk;
    }

    var html = buildHtmlListChunk(attr);
    var insertedVia = '';
    var insertedOk = false;

    try {
      if (typeof inst.wysiwygEditorInsertHtml === 'function') {
        inst.wysiwygEditorInsertHtml(html);
        insertedVia = 'wysiwygEditorInsertHtml';
        insertedOk = true;
      } else if (typeof inst.insertHTML === 'function') {
        inst.insertHTML(html);
        insertedVia = 'insertHTML';
        insertedOk = true;
      } else {
        insertedOk = insertHtmlViaDom(inst, html);
        insertedVia = insertedOk ? 'domRangeInsert' : 'no-html-api';
      }
    } catch (e5) {}

    if (!insertedOk && hasBody) {
      for (var retry = 1; retry <= 3 && !insertedOk; retry++) {
        try {
          if (typeof inst.focus === 'function') inst.focus();
          insertedOk = insertHtmlViaDom(inst, html);
          if (insertedOk) insertedVia = 'domRangeInsert-retry-' + retry;
        } catch (eRetry) {}
      }
    }

    try { cleanupListBoundaryBreaks(inst); } catch (e6) {}
    try { if (typeof inst.updateOriginal === 'function') inst.updateOriginal(); } catch (e7) {}
    try { if (typeof inst.focus === 'function') inst.focus(); } catch (e8) {}

    debugLog('[AE-LISTS] insert', {
      mode: 'wysiwyg',
      handler: meta.handler || 'unknown',
      cmd: meta.cmd || '',
      isSourceMode: sourceFlag,
      hasIframeBody: hasBody,
      requestedAttr: attr,
      insertedVia: insertedVia,
      instance: describeInstance(inst)
    });

    return insertedOk;
  }

  // ===============================
  // cmd detection
  // ===============================
  function detectCmd(def, caller) {
    try {
      var c = asText(def && def.cmd ? def.cmd : '').trim();
      if (c) return c;
    } catch (e0) {}

    try {
      var el = caller && caller.nodeType === 1 ? caller : null;
      if (!el) return '';

      var a = el.closest ? el.closest('a') : el;
      if (!a) a = el;

      for (var i = 0; i < LIST_BTNS.length; i++) {
        var cmd = LIST_BTNS[i].cmd;
        if (a.classList && a.classList.contains('sceditor-button-' + cmd)) return cmd;
      }

      if (a.className) {
        var m = String(a.className).match(/sceditor-button-([a-z0-9_\-]+)/i);
        if (m && m[1]) return String(m[1]);
      }
    } catch (e1) {}
    return '';
  }

  // ===============================
  // AE handler (для pack handler: af_ae_lists_exec)
  // ===============================
  function aeListsHandler(editor, def, caller) {
    var cmd = detectCmd(def, caller);
    var attr = cmdToAttr(cmd);
    debugLog('[AE-LISTS] handler', { handler: 'afAeBuiltinHandlers.*', cmd: cmd, via: 'aeListsHandler' });
    insertCanonicalList(editor || {}, attr, { caller: caller, cmd: cmd, handler: 'afAeBuiltinHandlers.*' });
  }

  function registerHandlers() {
    window.afAeBuiltinHandlers.lists = aeListsHandler;
    window.afAqrBuiltinHandlers.lists = { onClick: aeListsHandler };

    for (var i = 0; i < LIST_BTNS.length; i++) {
      var c = LIST_BTNS[i].cmd;
      window.afAeBuiltinHandlers[c] = aeListsHandler;
      window.afAqrBuiltinHandlers[c] = { onClick: aeListsHandler };
    }
  }

  // if AE core calls window.af_ae_lists_exec(...)
  window.af_ae_lists_exec = function (editor, def, caller) {
    aeListsHandler(editor, def, caller);
  };

  // ===============================
  // SCEditor commands (важно: не [bulletlist])
  // ===============================
  function registerSceditorCommands() {
    if (!hasSceditor()) return false;
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

    // наши кастомные кнопки
    LIST_BTNS.forEach(function (it) {
      setCmd(it.cmd, {
        tooltip: it.tooltip || it.cmd,
        exec: function () {
          debugLog('[AE-LISTS] handler', { handler: 'SCEditor command exec', cmd: it.cmd });
          try { afAeEnsureMybbListsBbcode(this); } catch (e0) {}
          try { ensureListCss(this); } catch (e1) {}
          insertCanonicalList(this, it.attr, { cmd: it.cmd, handler: 'SCEditor command exec' });
        },
        txtExec: function () {
          debugLog('[AE-LISTS] handler', { handler: 'SCEditor command txtExec', cmd: it.cmd });
          insertCanonicalList(this, it.attr, { cmd: it.cmd, handler: 'SCEditor command txtExec' });
        }
      });
    });

    // перехват стандартных SCEditor list-команд:
    // чтобы не получалось [bulletlist][/bulletlist] и подобная хтонь
    setCmd('bulletlist', {
      tooltip: 'Список: точки (•)',
      exec: function () {
        debugLog('[AE-LISTS] handler', { handler: 'SCEditor command exec', cmd: 'bulletlist' });
        try { afAeEnsureMybbListsBbcode(this); } catch (e0) {}
        try { ensureListCss(this); } catch (e1) {}
        try { bindToSourceListNormalization(this); } catch (e2) {}
        insertCanonicalList(this, '', { cmd: 'bulletlist', handler: 'SCEditor command exec' });
      },
      txtExec: function () {
        debugLog('[AE-LISTS] handler', { handler: 'SCEditor command txtExec', cmd: 'bulletlist' });
        insertCanonicalList(this, '', { cmd: 'bulletlist', handler: 'SCEditor command txtExec' });
      }
    });

    setCmd('orderedlist', {
      tooltip: 'Список: нумерация (1,2,3)',
      exec: function () {
        debugLog('[AE-LISTS] handler', { handler: 'SCEditor command exec', cmd: 'orderedlist' });
        try { afAeEnsureMybbListsBbcode(this); } catch (e0) {}
        try { ensureListCss(this); } catch (e1) {}
        try { bindToSourceListNormalization(this); } catch (e2) {}
        insertCanonicalList(this, 'decimal', { cmd: 'orderedlist', handler: 'SCEditor command exec' });
      },
      txtExec: function () {
        debugLog('[AE-LISTS] handler', { handler: 'SCEditor command txtExec', cmd: 'orderedlist' });
        insertCanonicalList(this, 'decimal', { cmd: 'orderedlist', handler: 'SCEditor command txtExec' });
      }
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

  // ===============================
  // Light patch of existing instances (без мигания)
  // ===============================
  function tryPatchInstances() {
    if (!hasSceditor() || !window.jQuery) return false;
    var $ = window.jQuery;

    try {
      var tas = document.querySelectorAll('textarea');
      for (var i = 0; i < tas.length; i++) {
        var ta = tas[i];
        if (!ta || ta.nodeType !== 1) continue;

        var inst = null;
        try { inst = $(ta).sceditor('instance'); } catch (e0) { inst = null; }
        if (!inst) continue;

        try { afAeEnsureMybbListsBbcode(inst); } catch (e1) {}
        try { ensureListCss(inst); } catch (e2) {}
        try { bindToSourceListNormalization(inst); } catch (e3) {}
        try { bindListEnterBehavior(inst); } catch (e4) {}
      }
    } catch (e3) {}

    return true;
  }

  // ===============================
  // boot
  // ===============================
  registerHandlers();
  for (var k = 1; k <= 10; k++) setTimeout(registerHandlers, k * 250);

  waitAnd(registerSceditorCommands, 150);

  var tries2 = 0;
  (function tick2() {
    tries2++;
    tryPatchInstances();
    if (tries2 < 60) setTimeout(tick2, 150);
  })();

})();

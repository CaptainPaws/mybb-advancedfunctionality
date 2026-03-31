(function (window, document) {
  'use strict';

  if (window.__afAeMarkPackLoaded) return;
  window.__afAeMarkPackLoaded = true;

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAeWysiwygCustomTags) window.afAeWysiwygCustomTags = Object.create(null);

  var CMD = 'af_mark';
  var INSTANCE_SCAN_DELAY = 1200;
  var DEFAULT_BG = '#FFF2A8';
  var DEFAULT_TEXT = '#202020';

  window.afAeWysiwygCustomTags.mark = true;

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

  function normHex(x) {
    var hex;

    x = trim(x);
    if (!x) return '';

    if (!/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(x)) {
      return '';
    }

    hex = x.slice(1).toUpperCase();

    if (hex.length === 3) {
      hex = hex.split('').map(function (ch) {
        return ch + ch;
      }).join('');
    }

    return '#' + hex;
  }

  function normalizeColor(v, fallback) {
    return normHex(v) || fallback || '';
  }

  function escHtml(x) {
    return asText(x)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function getEditorInstanceFromTextarea(textarea) {
    if (!textarea || !window.jQuery || !jQuery.fn || typeof jQuery.fn.sceditor !== 'function') {
      return null;
    }

    try {
      return jQuery(textarea).sceditor('instance');
    } catch (e) {
      return null;
    }
  }

  function getEditorBody(instance) {
    try {
      if (instance && typeof instance.getBody === 'function') {
        return instance.getBody();
      }
    } catch (e) {}

    return null;
  }

  function isSourceMode(instance) {
    try {
      if (instance && typeof instance.inSourceMode === 'function') {
        return !!instance.inSourceMode();
      }
      if (instance && typeof instance.sourceMode === 'function') {
        return !!instance.sourceMode();
      }
    } catch (e) {}

    return false;
  }

  function getSelectionAnchorNode(instance) {
    try {
      if (instance && typeof instance.currentNode === 'function') {
        var current = instance.currentNode();
        if (current) return current;
      }
    } catch (e0) {}

    try {
      if (instance && instance.getRangeHelper && typeof instance.getRangeHelper === 'function') {
        var helper = instance.getRangeHelper();
        if (helper && typeof helper.selectedRange === 'function') {
          var range = helper.selectedRange();
          if (range) {
            return range.commonAncestorContainer || range.startContainer || null;
          }
        }
      }
    } catch (e1) {}

    return null;
  }

  function toElement(node) {
    if (!node) return null;
    if (node.nodeType === 1) return node;
    if (node.nodeType === 3) return node.parentNode || null;
    return null;
  }

  function closestMarkNode(node, stopNode) {
    node = toElement(node);

    while (node) {
      if (
        node.nodeType === 1 &&
        node.getAttribute &&
        node.getAttribute('data-af-bb') === 'mark'
      ) {
        if (!stopNode || stopNode.contains(node)) {
          return node;
        }
        return null;
      }

      if (node === stopNode) break;
      node = node.parentNode;
    }

    return null;
  }

  function readColorsFromNode(node) {
    var bg = '';
    var text = '';

    if (!node || node.nodeType !== 1) {
      return {
        bg: DEFAULT_BG,
        text: DEFAULT_TEXT
      };
    }

    bg = normalizeColor(node.getAttribute('data-bgcolor') || '', '');
    text = normalizeColor(node.getAttribute('data-textcolor') || '', '');

    if ((!bg || !text) && node.getAttribute('data-af-bb-attrs')) {
      try {
        var attrs = JSON.parse(String(node.getAttribute('data-af-bb-attrs') || '{}')) || {};
        if (!bg) bg = normalizeColor(attrs.bgcolor || '', '');
        if (!text) text = normalizeColor(attrs.textcolor || '', '');
      } catch (e) {}
    }

    if (!bg && node.style) {
      bg = normalizeColor(node.style.backgroundColor || '', '');
    }

    if (!text && node.style) {
      text = normalizeColor(node.style.color || '', '');
    }

    return {
      bg: bg || DEFAULT_BG,
      text: text || DEFAULT_TEXT
    };
  }

  function applyMarkStyles(node, bg, text) {
    if (!node || node.nodeType !== 1) return;

    bg = normalizeColor(bg, DEFAULT_BG);
    text = normalizeColor(text, DEFAULT_TEXT);

    node.classList.add('af-ae-mark');
    node.classList.add('af-ae-bb-mark');

    node.setAttribute('data-af-bb', 'mark');
    node.setAttribute('data-bgcolor', bg);
    node.setAttribute('data-textcolor', text);

    node.style.backgroundColor = bg;
    node.style.color = text;
  }

  function normalizeRenderedMarkNode(node) {
    if (!node || node.nodeType !== 1) return null;

    var colors = readColorsFromNode(node);
    applyMarkStyles(node, colors.bg, colors.text);

    return node;
  }

  function normalizeAllRenderedMarks(root) {
    if (!root || !root.querySelectorAll) return;

    var nodes = root.querySelectorAll(
      '[data-af-bb="mark"], .af-ae-mark, .af-ae-bb-mark, .af-ae-mark-render'
    );

    for (var i = 0; i < nodes.length; i += 1) {
      normalizeRenderedMarkNode(nodes[i]);
    }
  }

  function normalizeFormatContent(content) {
    return asText(content).replace(/\u200B/g, '').trim();
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

  function parseMarkAttrString(attrRaw) {
    var attrs = {};
    var m;

    if (!attrRaw) return attrs;

    var re = /([a-z_][a-z0-9_-]*)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s\]]+))/ig;
    while ((m = re.exec(attrRaw))) {
      attrs[String(m[1]).toLowerCase()] = String(m[2] || m[3] || m[4] || '');
    }

    return attrs;
  }

  function normalizeMarkSerializedContent(content, opts) {
    var result = trim(normalizeFormatContent(content));
    var prev = null;
    var guard = 0;
    var textColor = normHex((opts && opts.text) || '') || DEFAULT_TEXT;

    while (result !== prev && guard < 12) {
      prev = result;
      guard += 1;

      result = stripOuterBbcodeTag(result, 'color', function (attrRaw) {
        var attr = normHex(trim(attrRaw).replace(/^["']|["']$/g, ''));
        return !!attr && attr === textColor;
      }).content;

      result = stripOuterBbcodeTag(result, 'mark', function () {
        return true;
      }).content;
    }

    return trim(result);
  }

  function buildOpenTag(bg, text) {
    bg = normalizeColor(bg, DEFAULT_BG);
    text = normalizeColor(text, DEFAULT_TEXT);

    if (bg === DEFAULT_BG && text === DEFAULT_TEXT) {
      return '[mark]';
    }

    return '[mark bgcolor="' + bg + '" textcolor="' + text + '"]';
  }

  function touchEditor(instance) {
    try {
      if (instance && typeof instance.triggerValueChanged === 'function') {
        instance.triggerValueChanged();
      }
    } catch (e0) {}

    try {
      if (instance && typeof instance.updateOriginal === 'function') {
        instance.updateOriginal();
      }
    } catch (e1) {}
  }

  function rerenderInstance(instance) {
    if (!instance || isSourceMode(instance)) return;

    try {
      if (typeof instance.val === 'function') {
        var current = instance.val();
        if (typeof current === 'string' && current.indexOf('[mark') !== -1) {
          instance.val(current);
        }
      }
    } catch (e0) {}

    try {
      if (typeof instance.focus === 'function') {
        instance.focus();
      }
    } catch (e1) {}

    touchEditor(instance);

    var body = getEditorBody(instance);
    if (body) {
      normalizeAllRenderedMarks(body);
    }
  }

  function buildMarkDef() {
    return {
      isInline: true,
      allowsEmpty: true,
      tags: {
        span: {
          'data-af-bb': 'mark'
        }
      },

      html: function (token, attrs, content) {
        attrs = attrs || {};

        var bg = normalizeColor(attrs.bgcolor, DEFAULT_BG);
        var text = normalizeColor(attrs.textcolor, DEFAULT_TEXT);

        return '' +
          '<span' +
          ' class="af-ae-mark af-ae-bb-mark"' +
          ' data-af-bb="mark"' +
          ' data-bgcolor="' + escHtml(bg) + '"' +
          ' data-textcolor="' + escHtml(text) + '"' +
          ' style="background-color:' + escHtml(bg) + ';color:' + escHtml(text) + ';">' +
          asText(content || '') +
          '</span>';
      },

      format: function (el, content) {
        var colors = readColorsFromNode(el);
        var inner = normalizeMarkSerializedContent(content, {
          bg: colors.bg,
          text: colors.text
        });

        if (!trim(inner)) {
          return '';
        }

        if (colors.bg === DEFAULT_BG && colors.text === DEFAULT_TEXT) {
          return '[mark]' + inner + '[/mark]';
        }

        return '[mark bgcolor="' + colors.bg + '" textcolor="' + colors.text + '"]' + inner + '[/mark]';
      }
    };
  }

  function getFormatTargets(instance) {
    var out = [];
    var seen = [];

    function pushUnique(target) {
      if (!target || typeof target.set !== 'function') return;
      if (seen.indexOf(target) !== -1) return;
      seen.push(target);
      out.push(target);
    }

    try {
      var sc = getSceditorRoot();
      if (sc && sc.formats && sc.formats.bbcode && typeof sc.formats.bbcode.set === 'function') {
        pushUnique(sc.formats.bbcode);
      }
    } catch (e0) {}

    try {
      if (window.jQuery && jQuery.sceditor && jQuery.sceditor.formats && jQuery.sceditor.formats.bbcode && typeof jQuery.sceditor.formats.bbcode.set === 'function') {
        pushUnique(jQuery.sceditor.formats.bbcode);
      }
    } catch (e1) {}

    try {
      if (instance && typeof instance.getPlugin === 'function') {
        var plugin = instance.getPlugin('bbcode');
        if (plugin && plugin.bbcode && typeof plugin.bbcode.set === 'function') {
          pushUnique(plugin.bbcode);
        }
      }
    } catch (e2) {}

    try {
      var sc2 = getSceditorRoot();
      if (sc2 && sc2.plugins && sc2.plugins.bbcode && sc2.plugins.bbcode.bbcode && typeof sc2.plugins.bbcode.bbcode.set === 'function') {
        pushUnique(sc2.plugins.bbcode.bbcode);
      }
    } catch (e3) {}

    try {
      if (window.jQuery && jQuery.sceditor && jQuery.sceditor.plugins && jQuery.sceditor.plugins.bbcode && jQuery.sceditor.plugins.bbcode.bbcode && typeof jQuery.sceditor.plugins.bbcode.bbcode.set === 'function') {
        pushUnique(jQuery.sceditor.plugins.bbcode.bbcode);
      }
    } catch (e4) {}

    return out;
  }

  function ensureBbcodeDef(instance) {
    var targets = getFormatTargets(instance);
    if (!targets.length) return false;

    var def = buildMarkDef();

    for (var i = 0; i < targets.length; i += 1) {
      try {
        targets[i].set('mark', def);
      } catch (e) {}
    }

    return true;
  }

  function getCurrentMarkNode(instance) {
    var body = getEditorBody(instance);
    var anchor = getSelectionAnchorNode(instance);

    if (!body || !anchor) return null;

    return closestMarkNode(anchor, body);
  }

  function insertOrApply(instance, bg, text) {
    bg = normalizeColor(bg, DEFAULT_BG);
    text = normalizeColor(text, DEFAULT_TEXT);

    if (!instance) return false;

    if (getCurrentMarkNode(instance)) {
      return true;
    }

    ensureBbcodeDef(instance);

    var open = buildOpenTag(bg, text);

    try {
      if (typeof instance.insert === 'function') {
        instance.insert(open, '[/mark]');

        window.setTimeout(function () {
          ensureBbcodeDef(instance);
          rerenderInstance(instance);
        }, 0);

        return true;
      }
    } catch (e0) {}

    try {
      if (typeof instance.insertText === 'function') {
        instance.insertText(open, '[/mark]');
        return true;
      }
    } catch (e1) {}

    return false;
  }

  function patchSceditorMarkCommand() {
    if (!window.jQuery) return false;
    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.command) return false;

    $.sceditor.command.set(CMD, {
      exec: function () {
        insertOrApply(this, DEFAULT_BG, DEFAULT_TEXT);
      },
      txtExec: function () {
        insertOrApply(this, DEFAULT_BG, DEFAULT_TEXT);
      },
      tooltip: 'Маркер'
    });

    $.sceditor.command.set('mark', {
      exec: function () {
        insertOrApply(this, DEFAULT_BG, DEFAULT_TEXT);
      },
      txtExec: function () {
        insertOrApply(this, DEFAULT_BG, DEFAULT_TEXT);
      },
      tooltip: 'Маркер'
    });

    return true;
  }

  function enhanceEditorInstance(instance) {
    if (!instance || instance.__afAeMarkEnhanced) return;

    var body = getEditorBody(instance);
    if (!body) return;

    instance.__afAeMarkEnhanced = true;

    ensureBbcodeDef(instance);
    normalizeAllRenderedMarks(body);

    window.setTimeout(function () {
      ensureBbcodeDef(instance);
      rerenderInstance(instance);
    }, 0);

    window.setTimeout(function () {
      ensureBbcodeDef(instance);
      rerenderInstance(instance);
    }, 80);

    body.addEventListener('click', function () {
      normalizeAllRenderedMarks(body);
    });

    body.addEventListener('mouseup', function () {
      normalizeAllRenderedMarks(body);
    });

    body.addEventListener('keyup', function () {
      normalizeAllRenderedMarks(body);
    });

    if (typeof instance.bind === 'function') {
      instance.bind('selectionchanged nodechanged valuechanged blur', function () {
        if (isSourceMode(instance)) return;
        normalizeAllRenderedMarks(body);
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

  function waitAnd(fn, maxTries) {
    var tries = 0;

    (function tick() {
      tries += 1;
      if (fn()) return;
      if (tries > (maxTries || 150)) return;
      setTimeout(tick, 100);
    })();
  }

  window.af_ae_mark_exec = function (editor) {
    ensureBbcodeDef(editor);
    if (!editor) return;

    if (isSourceMode(editor)) {
      try {
        if (typeof editor.insertText === 'function') {
          editor.insertText('[mark]', '[/mark]');
          return;
        }
      } catch (e0) {}
    }

    insertOrApply(editor, DEFAULT_BG, DEFAULT_TEXT);
  };

  window.afAeBuiltinHandlers.mark = function (editor) {
    window.af_ae_mark_exec(editor);
  };

  window.afAeBuiltinHandlers[CMD] = function (editor) {
    window.af_ae_mark_exec(editor);
  };

  waitAnd(function () {
    return ensureBbcodeDef(null);
  }, 150);

  waitAnd(patchSceditorMarkCommand, 150);

  waitAnd(function () {
    enhanceAllEditors();
    return false;
  }, 20);

  window.setInterval(enhanceAllEditors, INSTANCE_SCAN_DELAY);
})(window, document);

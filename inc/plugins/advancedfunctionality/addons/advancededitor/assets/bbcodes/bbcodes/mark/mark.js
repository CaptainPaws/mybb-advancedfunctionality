(function (window, document) {
  'use strict';

  if (window.__afAeMarkPackLoaded) return;
  window.__afAeMarkPackLoaded = true;

  var CMD = 'af_mark';
  var DEFAULT_BG = '#FFF2A8';
  var DEFAULT_TEXT = '#202020';
  var POP_ID = 'af-ae-mark-pop';
  var state = { instance: null, node: null, pop: null };

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAeWysiwygCustomTags) window.afAeWysiwygCustomTags = Object.create(null);
  window.afAeWysiwygCustomTags.mark = true;

  function asText(x) { return String(x == null ? '' : x); }

  function normalizeColor(v, fallback) {
    v = asText(v).trim();
    if (!v) return fallback || '';
    var m = v.match(/^#([0-9a-f]{3})$/i);
    if (m) {
      var s = m[1].toUpperCase();
      return '#' + s[0] + s[0] + s[1] + s[1] + s[2] + s[2];
    }
    m = v.match(/^#([0-9a-f]{6})$/i);
    if (m) return '#' + m[1].toUpperCase();
    m = v.match(/^rgb\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*\)$/i);
    if (m) {
      var r = Math.max(0, Math.min(255, parseInt(m[1], 10) || 0)).toString(16).toUpperCase().padStart(2, '0');
      var g = Math.max(0, Math.min(255, parseInt(m[2], 10) || 0)).toString(16).toUpperCase().padStart(2, '0');
      var b = Math.max(0, Math.min(255, parseInt(m[3], 10) || 0)).toString(16).toUpperCase().padStart(2, '0');
      return '#' + r + g + b;
    }
    return fallback || '';
  }

  function applyMarkStyles(el, bg, text) {
    if (!el || el.nodeType !== 1) return;
    bg = normalizeColor(bg, DEFAULT_BG);
    text = normalizeColor(text, DEFAULT_TEXT);

    el.classList.add('af-ae-mark');
    el.setAttribute('data-af-bb', 'mark');
    el.setAttribute('data-bgcolor', bg);
    el.setAttribute('data-textcolor', text);
    el.style.backgroundColor = bg;
    el.style.color = text;
  }

  function buildOpenTag(bg, text) {
    bg = normalizeColor(bg, DEFAULT_BG);
    text = normalizeColor(text, DEFAULT_TEXT);

    if (bg === DEFAULT_BG && text === DEFAULT_TEXT) {
      return '[mark]';
    }

    return '[mark bgcolor="' + bg + '" textcolor="' + text + '"]';
  }

  function getCurrentMarkNode(instance) {
    try {
      var range = instance && instance.getRangeHelper && instance.getRangeHelper().selectedRange();
      var node = range ? (range.commonAncestorContainer || range.startContainer) : null;
      while (node) {
        if (node.nodeType === 1 && node.getAttribute('data-af-bb') === 'mark') return node;
        node = node.parentNode;
      }
    } catch (e) {}
    return null;
  }

  function touchEditor(instance) {
    try {
      if (instance && typeof instance.triggerValueChanged === 'function') instance.triggerValueChanged();
      if (instance && typeof instance.updateOriginal === 'function') instance.updateOriginal();
    } catch (e) {}
  }

  function insertOrApply(instance, bg, text) {
    bg = normalizeColor(bg, DEFAULT_BG);
    text = normalizeColor(text, DEFAULT_TEXT);

    var existing = getCurrentMarkNode(instance);
    if (existing) {
      applyMarkStyles(existing, bg, text);
      touchEditor(instance);
      return;
    }

    var open = buildOpenTag(bg, text);
    try {
      if (instance && typeof instance.insert === 'function') {
        instance.insert(open, '[/mark]');
        return;
      }
    } catch (e0) {}

    try {
      if (instance && typeof instance.insertText === 'function') {
        instance.insertText(open + '[/mark]', '');
      }
    } catch (e1) {}
  }

  function ensureBbcodeDef() {
    if (!(window.jQuery && jQuery.sceditor && jQuery.sceditor.plugins && jQuery.sceditor.plugins.bbcode)) return;

    var bb = null;
    try { bb = jQuery.sceditor.plugins.bbcode.bbcode; } catch (e) { bb = null; }
    if (!bb || typeof bb.set !== 'function') return;

    bb.set('mark', {
      isInline: true,
      html: function (token, attrs, content) {
        attrs = attrs || {};
        var bg = normalizeColor(attrs.bgcolor, DEFAULT_BG);
        var text = normalizeColor(attrs.textcolor, DEFAULT_TEXT);
        return '<span class="af-ae-mark" data-af-bb="mark" data-bgcolor="' + bg + '" data-textcolor="' + text + '" style="background-color:' + bg + ';color:' + text + ';">' + (content || '') + '</span>';
      },
      format: function (el, content) {
        var bg = normalizeColor(el && el.getAttribute ? el.getAttribute('data-bgcolor') : '', DEFAULT_BG);
        var text = normalizeColor(el && el.getAttribute ? el.getAttribute('data-textcolor') : '', DEFAULT_TEXT);

        if ((!bg || bg === DEFAULT_BG) && (!text || text === DEFAULT_TEXT)) {
          return '[mark]' + (content || '') + '[/mark]';
        }

        return '[mark bgcolor="' + bg + '" textcolor="' + text + '"]' + (content || '') + '[/mark]';
      }
    });
  }

  function ensurePopup() {
    if (state.pop && state.pop.nodeType === 1) return state.pop;

    var pop = document.createElement('div');
    pop.id = POP_ID;
    pop.className = 'af-ae-mark-pop';
    pop.innerHTML =
      '<label title="Цвет фона"><input type="color" data-role="bg" value="#fff2a8"></label>' +
      '<label title="Цвет текста"><input type="color" data-role="text" value="#202020"></label>';

    pop.addEventListener('input', function (event) {
      if (!state.node) return;
      var t = event.target;
      if (!t || t.nodeType !== 1) return;
      var bgInput = pop.querySelector('input[data-role="bg"]');
      var textInput = pop.querySelector('input[data-role="text"]');
      applyMarkStyles(state.node, bgInput.value, textInput.value);
      touchEditor(state.instance);
    });

    document.body.appendChild(pop);
    state.pop = pop;
    return pop;
  }

  function hidePopup() {
    var pop = ensurePopup();
    pop.classList.remove('is-open');
    state.instance = null;
    state.node = null;
  }

  function showPopup(instance, node, frameRect) {
    var pop = ensurePopup();
    var bgInput = pop.querySelector('input[data-role="bg"]');
    var textInput = pop.querySelector('input[data-role="text"]');

    var bg = normalizeColor(node.getAttribute('data-bgcolor') || node.style.backgroundColor, DEFAULT_BG);
    var text = normalizeColor(node.getAttribute('data-textcolor') || node.style.color, DEFAULT_TEXT);

    bgInput.value = bg;
    textInput.value = text;

    state.instance = instance;
    state.node = node;

    var r = node.getBoundingClientRect();
    var top = frameRect.top + r.top - 42;
    var left = frameRect.left + r.left;

    pop.style.top = Math.max(8, top) + 'px';
    pop.style.left = Math.max(8, left) + 'px';
    pop.classList.add('is-open');
  }

  function resolveEditorFrame(instance) {
    try {
      if (!instance) return null;

      if (typeof instance.getContentAreaContainer === 'function') {
        var container = instance.getContentAreaContainer();
        if (container) {
          if (container.tagName === 'IFRAME') return container;
          if (container.querySelector) {
            var nested = container.querySelector('iframe');
            if (nested) return nested;
          }
        }
      }

      if (typeof instance.getBody === 'function') {
        var body = instance.getBody();
        if (body && body.ownerDocument && body.ownerDocument.defaultView && body.ownerDocument.defaultView.frameElement) {
          return body.ownerDocument.defaultView.frameElement;
        }
      }
    } catch (e) {}

    return null;
  }

  function bindInstance(instance) {
    if (!instance || instance.__afAeMarkBound) return;
    instance.__afAeMarkBound = true;

    ensureBbcodeDef();

    try {
      var body = instance.getBody && instance.getBody();
      var frame = resolveEditorFrame(instance);
      if (!body || !frame) return;

      body.addEventListener('click', function (event) {
        var node = event.target;
        while (node && node.nodeType === 1 && node !== body) {
          if (node.getAttribute('data-af-bb') === 'mark') {
            showPopup(instance, node, frame.getBoundingClientRect());
            return;
          }
          node = node.parentNode;
        }
        hidePopup();
      });
    } catch (e) {}
  }

  function scanInstances() {
    if (!window.jQuery || !jQuery.fn || typeof jQuery.fn.sceditor !== 'function') return;
    var arr = document.querySelectorAll('textarea');
    for (var i = 0; i < arr.length; i += 1) {
      try {
        var inst = jQuery(arr[i]).sceditor('instance');
        if (inst) bindInstance(inst);
      } catch (e) {}
    }
  }

  window.af_ae_mark_exec = function (editor) {
    ensureBbcodeDef();
    if (!editor) return;

    try {
      if (typeof editor.sourceMode === 'function' && editor.sourceMode()) {
        editor.insertText('[mark][/mark]', '');
        return;
      }
    } catch (e0) {}

    insertOrApply(editor, DEFAULT_BG, DEFAULT_TEXT);
  };

  document.addEventListener('click', function (event) {
    var pop = state.pop;
    if (!pop || !pop.classList.contains('is-open')) return;
    if (pop.contains(event.target)) return;
    hidePopup();
  }, true);

  if (window.jQuery && jQuery.sceditor && jQuery.sceditor.command) {
    jQuery.sceditor.command.set(CMD, {
      tooltip: 'Маркер',
      exec: function () { window.af_ae_mark_exec(this); },
      txtExec: function () { window.af_ae_mark_exec(this); }
    });
  }

  ensureBbcodeDef();
  setInterval(scanInstances, 1200);
  scanInstances();
})(window, document);

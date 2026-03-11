(function (window, document) {
  'use strict';

  if (window.__afAeJscolorPackLoaded) return;
  window.__afAeJscolorPackLoaded = true;

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  var CMD = 'color';
  var HANDLER_ID = 'jscolorpiker';
  var DEFAULT_COLOR = '#000000';
  var popupEl = null;
  var popupCaller = null;
  var popupEditor = null;
  var outsideBound = false;
  var repositionTimer = 0;
  var lastKnownEditor = null;

  var SWATCHES = [
    '#000000', '#434343', '#666666', '#999999', '#B7B7B7', '#CCCCCC', '#D9D9D9', '#EFEFEF', '#F3F3F3', '#FFFFFF',
    '#980000', '#FF0000', '#FF9900', '#FFFF00', '#00FF00', '#00FFFF', '#4A86E8', '#0000FF', '#9900FF', '#FF00FF',
    '#E6B8AF', '#F4CCCC', '#FCE5CD', '#FFF2CC', '#D9EAD3', '#D0E0E3', '#C9DAF8', '#CFE2F3', '#D9D2E9', '#EAD1DC',
    '#DD7E6B', '#EA9999', '#F9CB9C', '#FFE599', '#B6D7A8', '#A2C4C9', '#A4C2F4', '#9FC5E8', '#B4A7D6', '#D5A6BD',
    '#CC4125', '#E06666', '#F6B26B', '#FFD966', '#93C47D', '#76A5AF', '#6D9EEB', '#6FA8DC', '#8E7CC3', '#C27BA0',
    '#A61C00', '#CC0000', '#E69138', '#F1C232', '#6AA84F', '#45818E', '#3C78D8', '#3D85C6', '#674EA7', '#A64D79',
    '#85200C', '#990000', '#B45F06', '#BF9000', '#38761D', '#134F5C', '#1155CC', '#0B5394', '#351C75', '#741B47',
    '#5B0F00', '#660000', '#783F04', '#7F6000', '#274E13', '#0C343D', '#1C4587', '#073763', '#20124D', '#4C1130'
  ];

  function asText(value) {
    return String(value == null ? '' : value);
  }

  function hasEditorApi(obj) {
    return !!obj && (
      typeof obj.insert === 'function' ||
      typeof obj.insertText === 'function' ||
      typeof obj.execCommand === 'function' ||
      typeof obj.createDropDown === 'function'
    );
  }

  function clampInt(value, min, max) {
    value = parseInt(value, 10);
    if (isNaN(value)) value = min;
    if (value < min) value = min;
    if (value > max) value = max;
    return value;
  }

  function clampAlpha(value) {
    value = parseFloat(value);
    if (isNaN(value)) value = 1;
    if (value < 0) value = 0;
    if (value > 1) value = 1;
    return value;
  }

  function toHexPart(num) {
    var s = Number(num).toString(16).toUpperCase();
    return s.length < 2 ? '0' + s : s;
  }

  function alphaToHex(alpha) {
    return toHexPart(Math.round(clampAlpha(alpha) * 255));
  }

  function normalizeColor(value) {
    var m;
    var r;
    var g;
    var b;
    var a;
    var base;
    var alphaHex;
    var hex8;

    value = asText(value).trim();
    if (!value) return '';

    m = value.match(/^#([0-9a-f]{3})$/i);
    if (m) {
      var s = m[1].toUpperCase();
      return '#' + s.charAt(0) + s.charAt(0) + s.charAt(1) + s.charAt(1) + s.charAt(2) + s.charAt(2);
    }

    m = value.match(/^#([0-9a-f]{6})$/i);
    if (m) {
      return '#' + m[1].toUpperCase();
    }

    m = value.match(/^#([0-9a-f]{8})$/i);
    if (m) {
      hex8 = m[1].toUpperCase();
      if (hex8.substr(6, 2) === 'FF') {
        return '#' + hex8.substr(0, 6);
      }
      return '#' + hex8;
    }

    m = value.match(/^rgb\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*\)$/i);
    if (m) {
      r = clampInt(m[1], 0, 255);
      g = clampInt(m[2], 0, 255);
      b = clampInt(m[3], 0, 255);
      return '#' + toHexPart(r) + toHexPart(g) + toHexPart(b);
    }

    m = value.match(/^rgba\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]*\.?[0-9]+)\s*\)$/i);
    if (m) {
      r = clampInt(m[1], 0, 255);
      g = clampInt(m[2], 0, 255);
      b = clampInt(m[3], 0, 255);
      a = clampAlpha(m[4]);

      base = '#' + toHexPart(r) + toHexPart(g) + toHexPart(b);
      alphaHex = alphaToHex(a);

      if (alphaHex === 'FF') {
        return base;
      }

      return base + alphaHex;
    }

    return '';
  }

  function getNode(element) {
    if (!element) return null;
    if (element.jquery) return element[0] || null;
    return element.nodeType === 1 ? element : null;
  }

  function getClosest(element, selector) {
    element = getNode(element);
    if (!element) return null;

    if (typeof element.closest === 'function') {
      return element.closest(selector);
    }

    while (element) {
      if (element.matches && element.matches(selector)) return element;
      element = element.parentNode;
    }

    return null;
  }

  function getEditorContainer(editor) {
    var c = null;

    if (!editor) return null;

    c = editor.container || editor.$container || editor._container || null;
    if (c && c.jquery) c = c[0];

    return c && c.nodeType === 1 ? c : null;
  }

  function rememberEditor(editor) {
    if (!hasEditorApi(editor)) return;
    lastKnownEditor = editor;
    window.__afAeJscolorLastEditor = editor;

    var container = getEditorContainer(editor);
    if (container) {
      container.__afAeJscolorEditor = editor;
    }
  }

  function getSceditorRoot() {
    return window.sceditor || (window.jQuery && window.jQuery.sceditor) || null;
  }

  function tryInstanceFromTextarea(textarea) {
    var sc;
    var inst;

    if (!textarea) return null;

    sc = getSceditorRoot();

    if (sc && typeof sc.instance === 'function') {
      try {
        inst = sc.instance(textarea);
        if (hasEditorApi(inst)) return inst;
      } catch (e1) {}
    }

    if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.sceditor === 'function') {
      try {
        inst = window.jQuery(textarea).sceditor('instance');
        if (hasEditorApi(inst)) return inst;
      } catch (e2) {}
    }

    if (textarea.sceditor && hasEditorApi(textarea.sceditor)) {
      return textarea.sceditor;
    }

    return null;
  }

  function findEditorByContainer(container) {
    var textareas;
    var i;
    var inst;
    var instContainer;

    if (!container) return null;

    if (container.__afAeJscolorEditor && hasEditorApi(container.__afAeJscolorEditor)) {
      return container.__afAeJscolorEditor;
    }

    textareas = document.querySelectorAll('textarea');

    for (i = 0; i < textareas.length; i += 1) {
      inst = tryInstanceFromTextarea(textareas[i]);
      if (!hasEditorApi(inst)) continue;

      instContainer = getEditorContainer(inst);
      if (!instContainer) continue;

      if (instContainer === container || instContainer.contains(container) || container.contains(instContainer)) {
        rememberEditor(inst);
        return inst;
      }
    }

    return null;
  }

  function resolveEditor(ctx, caller) {
    var candidates = [];
    var i;
    var container;
    var active;

    if (ctx) {
      candidates.push(ctx);
      if (ctx.editor) candidates.push(ctx.editor);
      if (ctx.instance) candidates.push(ctx.instance);
      if (ctx.sceditor) candidates.push(ctx.sceditor);
      if (ctx.api) candidates.push(ctx.api);
    }

    for (i = 0; i < candidates.length; i += 1) {
      if (hasEditorApi(candidates[i])) {
        rememberEditor(candidates[i]);
        return candidates[i];
      }
    }

    caller = getNode(caller);
    container = getClosest(caller, '.sceditor-container');
    if (container) {
      active = findEditorByContainer(container);
      if (active) return active;
    }

    active = document.activeElement;
    container = getClosest(active, '.sceditor-container');
    if (container) {
      active = findEditorByContainer(container);
      if (active) return active;
    }

    if (hasEditorApi(window.__afAeJscolorLastEditor)) {
      return window.__afAeJscolorLastEditor;
    }

    if (hasEditorApi(lastKnownEditor)) {
      return lastKnownEditor;
    }

    return null;
  }

  function isSourceMode(editor) {
    if (!editor) return false;

    try {
      if (typeof editor.inSourceMode === 'function') {
        return !!editor.inSourceMode();
      }
    } catch (e1) {}

    try {
      if (typeof editor.sourceMode === 'function') {
        return !!editor.sourceMode();
      }
    } catch (e2) {}

    if (typeof editor.sourceMode !== 'undefined') {
      return !!editor.sourceMode;
    }

    return false;
  }

  function syncPreview(input, preview, raw) {
    var norm = normalizeColor(raw);
    if (!norm) return '';

    input.value = norm;

    if (preview) {
      preview.style.background = norm;
      preview.setAttribute('title', norm);
    }

    return norm;
  }

  function getJscolorCtor() {
    if (window.JSColor && typeof window.JSColor === 'function') return window.JSColor;
    if (window.jscolor && typeof window.jscolor === 'function') return window.jscolor;
    return null;
  }
  function escapeHtmlAttr(value) {
    return asText(value)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }
  function syncFromPicker(input, preview, picker) {
    var raw = '';

    if (picker && typeof picker.toHEXAString === 'function') {
      try {
        raw = picker.toHEXAString();
      } catch (e1) {}
    }

    if (!raw && picker && typeof picker.toRGBAString === 'function') {
      try {
        raw = picker.toRGBAString();
      } catch (e2) {}
    }

    if (!raw) {
      raw = input.value;
    }

    return syncPreview(input, preview, raw);
  }

  function ensureJscolor(host, input, preview) {
    var Ctor;
    var picker = null;
    var options;

    if (!host || !input) return null;

    options = {
      value: input.value || DEFAULT_COLOR,
      format: 'hexa',
      alphaChannel: true,
      palette: SWATCHES,
      paletteCols: 10,
      paletteHeight: 18,
      paletteSpacing: 4,
      valueElement: input,
      previewElement: preview,
      container: host,
      position: 'bottom',
      smartPosition: false,
      showOnClick: false,
      hideOnLeave: false,
      closeButton: false,
      width: 181,
      height: 101,
      zIndex: 100000,
      onInput: function () {
        syncFromPicker(input, preview, this);
      },
      onChange: function () {
        syncFromPicker(input, preview, this);
      }
    };

    Ctor = getJscolorCtor();
    if (!Ctor) return null;

    try {
      input.jscolor = new Ctor(input, options);
    } catch (e1) {
      return null;
    }

    picker = input.jscolor || null;

    if (!input.__afJscolorBound) {
      input.__afJscolorBound = true;

      input.addEventListener('input', function () {
        var norm = normalizeColor(input.value);
        if (norm) {
          input.value = norm;
          preview.style.background = norm;
        }
      });

      input.addEventListener('change', function () {
        var norm = normalizeColor(input.value);
        if (!norm) return;

        input.value = norm;
        preview.style.background = norm;

        if (input.jscolor && typeof input.jscolor.fromString === 'function') {
          try { input.jscolor.fromString(norm); } catch (e2) {}
        }
      });
    }

    window.setTimeout(function () {
      syncPreview(input, preview, input.value || DEFAULT_COLOR);

      if (input.jscolor && typeof input.jscolor.show === 'function') {
        try { input.jscolor.show(); } catch (e3) {}
      }
    }, 0);

    return picker;
  }

  function applyColor(editor, colorValue) {
    var htmlOpen;
    var htmlClose = '</span>';

    colorValue = normalizeColor(colorValue);
    if (!editor || !colorValue) return false;

    rememberEditor(editor);

    if (isSourceMode(editor)) {
      try {
        if (typeof editor.insert === 'function') {
          editor.insert('[color=' + colorValue + ']', '[/color]');
          return true;
        }
      } catch (e1) {}

      try {
        if (typeof editor.insertText === 'function') {
          editor.insertText('[color=' + colorValue + ']', '[/color]');
          return true;
        }
      } catch (e2) {}

      return false;
    }

    htmlOpen = '<span style="color: ' + escapeHtmlAttr(colorValue) + ';">';

    try {
      if (typeof editor.wysiwygEditorInsertHtml === 'function') {
        editor.wysiwygEditorInsertHtml(htmlOpen, htmlClose);
        return true;
      }
    } catch (e3) {}

    try {
      if (typeof editor.execCommand === 'function') {
        editor.execCommand('forecolor', colorValue);
        return true;
      }
    } catch (e4) {}

    try {
      if (typeof editor.execCommand === 'function') {
        editor.execCommand('ForeColor', colorValue);
        return true;
      }
    } catch (e5) {}

    try {
      if (typeof editor.insert === 'function') {
        editor.insert(htmlOpen, htmlClose);
        return true;
      }
    } catch (e6) {}

    try {
      if (typeof editor.insertText === 'function') {
        editor.insertText(htmlOpen, htmlClose);
        return true;
      }
    } catch (e7) {}

    return false;
  }

  function closePopup() {
    if (popupEl && popupEl.parentNode) {
      popupEl.parentNode.removeChild(popupEl);
    }

    if (popupCaller) {
      try { popupCaller.classList.remove('af-ae-jscolor-open'); } catch (e1) {}
    }

    popupEl = null;
    popupCaller = null;
    popupEditor = null;
  }

  function positionPopup() {
    var rect;
    var top;
    var left;
    var maxLeft;
    var width;

    if (!popupEl || !popupCaller) return;

    rect = popupCaller.getBoundingClientRect();

    popupEl.style.visibility = 'hidden';
    popupEl.style.display = 'block';

    width = popupEl.offsetWidth || 320;
    top = rect.bottom + window.pageYOffset + 6;
    left = rect.left + window.pageXOffset;

    maxLeft = window.pageXOffset + document.documentElement.clientWidth - width - 8;
    if (left > maxLeft) left = maxLeft;
    if (left < window.pageXOffset + 8) left = window.pageXOffset + 8;

    popupEl.style.left = left + 'px';
    popupEl.style.top = top + 'px';
    popupEl.style.visibility = 'visible';
  }

  function bindOutsideCloser() {
    if (outsideBound) return;
    outsideBound = true;

    document.addEventListener('mousedown', function (ev) {
      if (!popupEl) return;

      if (popupEl.contains(ev.target)) return;
      if (popupCaller && popupCaller.contains(ev.target)) return;

      closePopup();
    }, true);

    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && popupEl) {
        closePopup();
      }
    }, true);

    window.addEventListener('resize', function () {
      if (!popupEl) return;
      positionPopup();
    });

    window.addEventListener('scroll', function () {
      if (!popupEl) return;

      window.clearTimeout(repositionTimer);
      repositionTimer = window.setTimeout(positionPopup, 10);
    }, true);
  }

  function createPopup(editor, caller) {
    var popup = document.createElement('div');
    var input;
    var preview;
    var host;
    var applyBtn;

    popup.className = 'af-ae-jscolor-popup';
    popup.innerHTML = '' +
      '<div class="af-ae-jscolor-wrap">' +
        '<div class="af-ae-jscolor-top">' +
          '<input type="text" class="af-ae-jscolor-input" value="' + DEFAULT_COLOR + '">' +
          '<span class="af-ae-jscolor-preview" style="background:' + DEFAULT_COLOR + ';"></span>' +
        '</div>' +
        '<div class="af-ae-jscolor-host"></div>' +
        '<div class="af-ae-jscolor-actions">' +
          '<button type="button" class="button af-ae-jscolor-apply">Применить</button>' +
        '</div>' +
      '</div>';

    document.body.appendChild(popup);

    input = popup.querySelector('.af-ae-jscolor-input');
    preview = popup.querySelector('.af-ae-jscolor-preview');
    host = popup.querySelector('.af-ae-jscolor-host');
    applyBtn = popup.querySelector('.af-ae-jscolor-apply');

    ensureJscolor(host, input, preview);

    applyBtn.addEventListener('click', function (ev) {
      var resolvedEditor;
      var norm;

      ev.preventDefault();
      ev.stopPropagation();

      norm = normalizeColor(input.value);
      if (!norm) return;

      resolvedEditor = resolveEditor(editor, caller) || popupEditor || lastKnownEditor;
      if (!resolvedEditor) return;

      if (applyColor(resolvedEditor, norm)) {
        closePopup();
      }
    });

    popupEl = popup;
    popupCaller = caller;
    popupEditor = editor || null;

    if (popupCaller) {
      try { popupCaller.classList.add('af-ae-jscolor-open'); } catch (e1) {}
    }

    positionPopup();

    window.setTimeout(function () {
      if (input) {
        try { input.focus(); } catch (e2) {}
      }
    }, 0);

    return true;
  }

  function buildDropdown(editor, caller) {
    caller = getNode(caller);

    if (!caller) {
      return false;
    }

    editor = resolveEditor(editor, caller);
    if (editor) {
      rememberEditor(editor);
    }

    if (popupEl && popupCaller === caller) {
      closePopup();
      return true;
    }

    closePopup();
    return createPopup(editor, caller);
  }

  function registerCommands() {
    var sc = getSceditorRoot();

    if (!sc || !sc.command || typeof sc.command.set !== 'function') {
      return false;
    }

    sc.command.set(CMD, {
      exec: function (caller) {
        return buildDropdown(this, caller);
      },
      txtExec: function (caller) {
        return buildDropdown(this, caller);
      },
      tooltip: 'Цвет (расширенный)'
    });

    return true;
  }

  function registerHandlers() {
    var handlerFn = function (ctx, caller) {
      var editor = resolveEditor(ctx, caller);
      return buildDropdown(editor, caller);
    };

    window.afAeBuiltinHandlers[HANDLER_ID] = handlerFn;
    window.afAeBuiltinHandlers[CMD] = handlerFn;

    window.afAqrBuiltinHandlers[HANDLER_ID] = {
      id: HANDLER_ID,
      title: 'Цвет (расширенный)',
      onClick: function (ctx, ev) {
        var caller = ev && (ev.currentTarget || ev.target);
        return handlerFn(ctx, caller);
      }
    };

    window.afAqrBuiltinHandlers[CMD] = window.afAqrBuiltinHandlers[HANDLER_ID];
  }

  function bindEditorTracker() {
    document.addEventListener('focusin', function (ev) {
      var container = getClosest(ev.target, '.sceditor-container');
      var editor;

      if (!container) return;

      editor = findEditorByContainer(container);
      if (editor) {
        rememberEditor(editor);
      }
    }, true);
  }

  registerHandlers();
  bindEditorTracker();
  bindOutsideCloser();

  var tries = 0;
  (function waitSceditor() {
    if (registerCommands()) return;

    tries += 1;
    if (tries < 120) {
      window.setTimeout(waitSceditor, 100);
    }
  })();

})(window, document);

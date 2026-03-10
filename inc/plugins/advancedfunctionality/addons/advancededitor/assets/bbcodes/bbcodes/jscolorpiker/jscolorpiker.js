(function (window, document) {
  'use strict';

  if (window.__afAeJscolorPackLoaded) return;
  window.__afAeJscolorPackLoaded = true;

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  var CMD = 'color';
  var SWATCHES = [
    '#000000','#434343','#666666','#999999','#CCCCCC','#EFEFEF','#F3F3F3','#FFFFFF','#980000','#FF0000',
    '#FF9900','#FFFF00','#00FF00','#00FFFF','#4A86E8','#0000FF','#9900FF','#FF00FF','#E6B8AF','#F4CCCC',
    '#FCE5CD','#FFF2CC','#D9EAD3','#D0E0E3','#C9DAF8','#CFE2F3','#D9D2E9','#EAD1DC','#DD7E6B','#EA9999'
  ];

  function normalizeColor(value) {
    value = String(value || '').trim();
    var m;
    if (!value) return '';
    m = value.match(/^#([0-9a-f]{3})$/i);
    if (m) {
      var s = m[1].toUpperCase();
      return '#' + s[0] + s[0] + s[1] + s[1] + s[2] + s[2];
    }
    m = value.match(/^#([0-9a-f]{6})$/i);
    if (m) return '#' + m[1].toUpperCase();
    m = value.match(/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/i);
    if (m) {
      var r = Math.max(0, Math.min(255, parseInt(m[1], 10)));
      var g = Math.max(0, Math.min(255, parseInt(m[2], 10)));
      var b = Math.max(0, Math.min(255, parseInt(m[3], 10)));
      return '#' + [r, g, b].map(function (n) { return n.toString(16).padStart(2, '0'); }).join('').toUpperCase();
    }
    return '';
  }

  function applyColor(editor, hex) {
    hex = normalizeColor(hex);
    if (!editor || !hex) return false;

    try {
      if (typeof editor.insertText === 'function') {
        editor.insertText('[color=' + hex + ']', '[/color]');
        return true;
      }
      if (typeof editor.insert === 'function') {
        editor.insert('[color=' + hex + ']', '[/color]');
        return true;
      }
    } catch (e) {}
    return false;
  }

  function ensureJscolor(input, onChange) {
    if (!input || input.__afJscolorBound) return;
    input.__afJscolorBound = true;

    if (window.jscolor && typeof window.jscolor.install === 'function') {
      try { window.jscolor.install(input.parentNode || input); } catch (e) {}
    }

    if (window.JSColor && !input.jscolor) {
      try {
        input.jscolor = new window.JSColor(input, {
          format: 'hex',
          hash: true,
          previewPosition: 'right',
          closeButton: true,
          palette: SWATCHES,
          paletteCols: 10,
          paletteHeight: 60
        });
      } catch (e2) {}
    }

    input.addEventListener('input', function () { onChange(input.value); });
    input.addEventListener('change', function () { onChange(input.value); });
  }

  function buildDropdown(editor, caller) {
    var current = '#000000';
    var html = '' +
      '<div class="af-ae-jscolor-wrap">' +
      ' <div class="af-ae-jscolor-top">' +
      '  <input type="text" class="af-ae-jscolor-input" value="' + current + '" data-jscolor="{format:\'hex\',hash:true}" />' +
      '  <span class="af-ae-jscolor-preview" style="background:' + current + ';"></span>' +
      ' </div>' +
      ' <div class="af-ae-jscolor-swatches"></div>' +
      ' <div class="af-ae-jscolor-actions"><button type="button" class="button af-ae-jscolor-apply">Применить</button></div>' +
      '</div>';

    editor.createDropDown(caller || null, CMD, html);

    var drop = document.querySelector('.sceditor-dropdown:last-of-type');
    if (!drop) return false;
    drop.classList.add('af-ae-jscolor-dropdown');

    var input = drop.querySelector('.af-ae-jscolor-input');
    var preview = drop.querySelector('.af-ae-jscolor-preview');
    var applyBtn = drop.querySelector('.af-ae-jscolor-apply');
    var swWrap = drop.querySelector('.af-ae-jscolor-swatches');

    function sync(raw) {
      var norm = normalizeColor(raw);
      if (!norm) return;
      input.value = norm;
      preview.style.background = norm;
    }

    ensureJscolor(input, sync);

    SWATCHES.forEach(function (hex) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'af-ae-jscolor-swatch';
      b.style.background = hex;
      b.title = hex;
      b.addEventListener('click', function () { sync(hex); });
      swWrap.appendChild(b);
    });

    applyBtn.addEventListener('click', function () {
      var norm = normalizeColor(input.value);
      if (!norm) return;
      applyColor(editor, norm);
      try { editor.closeDropDown(true); } catch (e) {}
    });

    return true;
  }

  function registerCommands() {
    var sc = window.sceditor || (window.jQuery && window.jQuery.sceditor);
    if (!sc || !sc.command || typeof sc.command.set !== 'function') return false;

    var impl = {
      exec: function (caller) { return buildDropdown(this, caller); },
      txtExec: function (caller) { return buildDropdown(this, caller); },
      tooltip: 'Цвет (расширенный)'
    };

    sc.command.set('color', impl);
    return true;
  }

  function registerHandlers() {
    var handlerFn = function (ctx, caller) {
      var editor = ctx && (ctx.editor || ctx.instance || ctx.sceditor || ctx);
      if (!editor || typeof editor.createDropDown !== 'function') return false;
      return buildDropdown(editor, caller);
    };

    window.afAeBuiltinHandlers.jscolorpiker = handlerFn;
    window.afAeBuiltinHandlers.color = handlerFn;

    var handlerObj = {
      id: 'jscolorpiker',
      title: 'Цвет (расширенный)',
      onClick: function (ctx, ev) { return handlerFn(ctx, ev && (ev.currentTarget || ev.target)); }
    };
    window.afAqrBuiltinHandlers.jscolorpiker = handlerObj;
    window.afAqrBuiltinHandlers.color = handlerObj;
  }

  registerHandlers();
  var tries = 0;
  (function waitSc() {
    if (registerCommands()) return;
    tries += 1;
    if (tries < 120) window.setTimeout(waitSc, 100);
  })();
})(window, document);

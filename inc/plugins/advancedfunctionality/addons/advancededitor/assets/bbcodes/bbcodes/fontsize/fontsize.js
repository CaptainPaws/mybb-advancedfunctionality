(function () {
  'use strict';

  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  var ID = 'fontsize';
  var CMD = 'af_fontsize';
  if (!window.AFAE || typeof window.AFAE.onEditorReady !== 'function') return;
  window.AFAE.onEditorReady(function () {

  // Диапазон для конвертации/нормализации (не для списка!)
  var MIN_PX = 8;
  var MAX_PX = 36;

  // Компактный список, который показываем в дропдауне
  function getDropdownSizes() {
    var out = [];
    for (var i = 8; i <= 32; i += 2) out.push(i);
    return out;
  }

  function buildFontSizesCsvFromList(list) {
    var out = [];
    for (var i = 0; i < list.length; i++) out.push(list[i] + 'px');
    return out.join(',');
  }

  function asInt(x, def) {
    var n = parseInt(String(x), 10);
    return Number.isFinite(n) ? n : def;
  }

  function clamp(n, a, b) {
    n = asInt(n, a);
    if (n < a) return a;
    if (n > b) return b;
    return n;
  }

  function getEditorBodyFontSizePx() {
    try {
      if (!window.jQuery) return null;
      var $ = window.jQuery;
      var iframe = $('.sceditor-container iframe').first();
      if (!iframe.length) return null;
      var body = $('body', iframe.contents());
      if (!body.length) return null;
      var fs = body.css('font-size');
      var px = parseInt(fs, 10);
      return Number.isFinite(px) ? px : null;
    } catch (e) {
      return null;
    }
  }

  // ====== дефолтный размер (как "сброс") ======
  function getDefaultPx() {
    var px = getEditorBodyFontSizePx();
    if (!px) px = 12; // фоллбэк
    return clamp(px, MIN_PX, MAX_PX);
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

  function insertWrap(open, close, ctx) {
    var inst = getSceditorInstanceFromCtx(ctx);
    if (inst && typeof inst.insertText === 'function') {
      inst.insertText(open, close);
      if (typeof inst.focus === 'function') inst.focus();
      return true;
    }

    var ta = getTextareaFromCtx(ctx);
    if (!ta) return false;

    try {
      var start = ta.selectionStart || 0;
      var end = ta.selectionEnd || 0;
      var val = String(ta.value || '');
      var before = val.slice(0, start);
      var sel = val.slice(start, end);
      var after = val.slice(end);

      ta.value = before + open + sel + close + after;

      var caret = (sel.length
        ? (before.length + open.length + sel.length + close.length)
        : (before.length + open.length)
      );

      ta.focus();
      ta.setSelectionRange(caret, caret);
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      return true;
    } catch (e) {
      return false;
    }
  }

  // ====== 1) Перебиваем формат size на [size=NN] где NN = px ======
  function patchMybbSizeToPixels() {
    if (!window.jQuery) return false;

    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.formats || !$.sceditor.command) return false;
    if (!$.sceditor.formats.bbcode) return false;

    // Подсовываем компактный список как "доступные fontSizes" (на всякий)
    try {
      var dropSizes = getDropdownSizes();
      $.sceditor.defaultOptions = $.sceditor.defaultOptions || {};
      $.sceditor.defaultOptions.fontSizes = buildFontSizesCsvFromList(dropSizes);
    } catch (e0) {}

    $.sceditor.formats.bbcode.set('size', {
      format: function (element, content) {
        var px = null;

        try {
          var css = $(element).css('font-size');
          var parsed = parseInt(css, 10);
          if (Number.isFinite(parsed)) px = parsed;
        } catch (e1) {}

        if (!px) return content;

        var basePx = getEditorBodyFontSizePx();
        if (basePx && px === basePx) return content;

        px = clamp(px, MIN_PX, MAX_PX);
        return '[size=' + px + ']' + content + '[/size]';
      },

      html: function (token, attrs, content) {
        var n = asInt(attrs.defaultattr, 0);
        if (!n) return content;

        n = clamp(n, MIN_PX, MAX_PX);

        return '<span data-scefontsize="' +
          $.sceditor.escapeEntities(String(n)) +
          '" style="font-size: ' + n + 'px;">' +
          content +
          '</span>';
      }
    });

    // Базовый size — делаем компактным + добавляем "по умолчанию"
    $.sceditor.command.set('size', {
      _dropDown: function (editor, caller, callback) {
        var content = $('<div />');
        var sizes = getDropdownSizes();

        content.css({
          maxHeight: '250px',
          overflowY: 'auto',
          overflowX: 'hidden'
        });

        var clickFunc = function (e) {
          e.preventDefault();
          e.stopPropagation();
          callback($(this).data('px'));
          editor.closeDropDown(true);
        };

        // ====== "По умолчанию" (ВАЖНО: класс без слова fontsize!) ======
        var defPx = getDefaultPx();
        content.append(
          $('<a class="sceditor-fontsize-option sceditor-font-default" data-px="' + defPx + '" href="#">' +
            '<span style="line-height:1.1;"><strong>По умолчанию</strong> (' + defPx + 'px)</span>' +
            '</a>').on('click', clickFunc)
        );

        content.append($('<div style="height:6px;"></div>'));

        for (var i = 0; i < sizes.length; i++) {
          var px = sizes[i];
          content.append(
            $('<a class="sceditor-fontsize-option" data-px="' + px + '" href="#">' +
              '<span style="font-size:' + px + 'px; line-height: 1.1;">' + px + 'px</span>' +
              '</a>').on('click', clickFunc)
          );
        }

        editor.createDropDown(caller, 'sceditor-font-picker', content.get(0));
      },

      exec: function (caller) {
        var editor = this;
        $.sceditor.command.get('size')._dropDown(editor, caller, function (px) {
          px = clamp(px, MIN_PX, MAX_PX);
          editor.insertText('[size=' + px + ']', '[/size]');
        });
      },

      txtExec: function (caller) {
        var editor = this;
        $.sceditor.command.get('size')._dropDown(editor, caller, function (px) {
          px = clamp(px, MIN_PX, MAX_PX);
          editor.insertText('[size=' + px + ']', '[/size]');
        });
      }
    });

    return true;
  }

  (function waitAndPatch(tries) {
    tries = tries || 0;
    if (patchMybbSizeToPixels()) return;
    if (tries > 150) return;
    setTimeout(function () { waitAndPatch(tries + 1); }, 100);
  })();

  // ====== 2) Наш dropdown как у SCEditor font picker ======
  function openSceditorDropdown(editor, caller) {
    if (!editor || typeof editor.createDropDown !== 'function') return false;

    try { editor.closeDropDown(true); } catch (e0) {}

    var sizes = getDropdownSizes();
    var defPx = getDefaultPx();

    var wrap = document.createElement('div');
    wrap.className = 'sceditor-font-picker';

    wrap.style.maxHeight = '250px';
    wrap.style.overflowY = 'auto';
    wrap.style.overflowX = 'hidden';

    // ====== "По умолчанию" (класс без fontsize) ======
    (function addDefaultItem() {
      var a = document.createElement('a');
      a.href = '#';
      a.className = 'sceditor-font-option sceditor-font-default';
      a.setAttribute('data-px', String(defPx));

      var span = document.createElement('span');
      span.style.lineHeight = '1.1';
      span.innerHTML = '<strong>По умолчанию</strong> (' + defPx + 'px)';

      a.appendChild(span);
      wrap.appendChild(a);

      var sep = document.createElement('div');
      sep.style.height = '6px';
      wrap.appendChild(sep);
    })();

    for (var i = 0; i < sizes.length; i++) {
      var px = sizes[i];

      var a2 = document.createElement('a');
      a2.href = '#';
      a2.className = 'sceditor-font-option';
      a2.setAttribute('data-px', String(px));

      var span2 = document.createElement('span');
      span2.style.fontSize = px + 'px';
      span2.style.lineHeight = '1.1';
      span2.textContent = px + 'px';

      a2.appendChild(span2);
      wrap.appendChild(a2);
    }

    wrap.addEventListener('click', function (e) {
      var t = e.target;
      var opt = t && t.closest ? t.closest('a[data-px]') : null;
      if (!opt) return;

      e.preventDefault();
      e.stopPropagation();

      var px = clamp(opt.getAttribute('data-px'), MIN_PX, MAX_PX);
      editor.insertText('[size=' + px + ']', '[/size]');
      try { editor.closeDropDown(true); } catch (e2) {}
    });

    editor.createDropDown(caller, 'sceditor-font-picker', wrap);
    return true;
  }

  // ====== 3) SCEditor command для кнопки CMD ======
  function registerSceditorCmd() {
    if (!window.jQuery) return false;
    var $ = window.jQuery;
    if (!$.sceditor || !$.sceditor.command) return false;

    $.sceditor.command.set(CMD, {
      exec: function (caller) {
        if (!openSceditorDropdown(this, caller)) {
          var defPx = getDefaultPx();
          insertWrap('[size=' + defPx + ']', '[/size]', { sceditor: this });
        }
      },
      txtExec: function (caller) {
        if (!openSceditorDropdown(this, caller)) {
          var defPx = getDefaultPx();
          insertWrap('[size=' + defPx + ']', '[/size]', { sceditor: this });
        }
      },
      tooltip: 'Размер шрифта (px)'
    });

    return true;
  }

  (function waitCmd(tries) {
    tries = tries || 0;
    if (registerSceditorCmd()) return;
    if (tries > 150) return;
    setTimeout(function () { waitCmd(tries + 1); }, 100);
  })();

  // ====== 4) AQR handler ======
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

    var defPx = getDefaultPx();
    insertWrap('[size=' + defPx + ']', '[/size]', ctx || {});
  }

  var handler = {
    id: ID,
    title: 'Размер шрифта (px)',
    onClick: aqrOpen,
    click: aqrOpen,
    action: aqrOpen,
    run: aqrOpen,
    init: function () {}
  };

  window.afAqrBuiltinHandlers[ID] = handler;
  window.afAqrBuiltinHandlers[CMD] = handler;

  // ====== 5) СТРАХОВКА capture ======
  function isInsideDropdown(target) {
    try {
      if (!target || !target.closest) return false;
      return !!target.closest('.sceditor-dropdown, .sceditor-font-picker');
    } catch (e) {
      return false;
    }
  }

  function findButtonAtPoint(e) {
    try {
      var x = e.clientX, y = e.clientY;
      var el = document.elementFromPoint(x, y);
      if (!el) return null;

      // если кликнули внутри самого дропдауна — НЕ трогаем
      if (isInsideDropdown(el)) return null;

      var btn = el.closest ? el.closest('a,button') : el;
      if (!btn || btn.nodeType !== 1) return null;

      var cls = String(btn.className || '');

      // исключаем элементы опций дропдауна на всякий
      if (cls.indexOf('sceditor-font-option') !== -1) return null;

      var data =
        (btn.getAttribute('data-cmd') || btn.getAttribute('data-command') || btn.getAttribute('data-id') || '') +
        ' ' +
        (btn.getAttribute('aria-label') || '') +
        ' ' +
        (btn.getAttribute('title') || '');

      var s = (cls + ' ' + data).toLowerCase();

      if (s.indexOf(CMD) !== -1) return btn;
      if (s.indexOf(ID) !== -1) return btn;
      if (cls.indexOf('sceditor-button-' + CMD) !== -1) return btn;
      if (cls.indexOf('sceditor-button-' + ID) !== -1) return btn;

      return null;
    } catch (e0) {
      return null;
    }
  }

  function nearestEditorFromButton(btn) {
    try {
      if (!window.jQuery || !btn) return getSceditorInstanceFromCtx({});
      var $ = window.jQuery;

      var $container = $(btn).closest('.sceditor-container');
      if ($container.length) {
        var $ta = $container.prevAll('textarea').first();
        if ($ta.length) {
          var inst = $ta.sceditor && $ta.sceditor('instance');
          if (inst && typeof inst.insertText === 'function') return inst;
        }
      }
    } catch (e1) {}

    return getSceditorInstanceFromCtx({});
  }

  function captureClick(e) {
    // если клик в дропдауне — выходим, не мешаем выбору
    if (isInsideDropdown(e && e.target)) return;

    var btn = findButtonAtPoint(e);
    if (!btn) return;

    try { e.preventDefault(); } catch (e2) {}
    try { e.stopPropagation(); } catch (e3) {}
    try { e.stopImmediatePropagation(); } catch (e4) {}

    var inst = nearestEditorFromButton(btn);
    if (inst) openSceditorDropdown(inst, btn);
  }

  (function bindCapture() {
    if (window.__af_fontsize_capture_bound) return;
    window.__af_fontsize_capture_bound = true;

    document.addEventListener('pointerdown', captureClick, true);
    document.addEventListener('mousedown', captureClick, true);
    document.addEventListener('click', captureClick, true);
  })();

  });
})();
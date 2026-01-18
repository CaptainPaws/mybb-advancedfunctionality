(function () {
  'use strict';

  function asText(x) { return String(x == null ? '' : x); }

  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  // --- editor helpers ---
  function getTextareaFromInst(inst) {
    if (inst && inst.textarea && inst.textarea.nodeType === 1) return inst.textarea;
    if (inst && inst.ta && inst.ta.nodeType === 1) return inst.ta;
    return document.querySelector('textarea#message') || document.querySelector('textarea[name="message"]');
  }

  function getScEditorInstance(ta) {
    try {
      if (!ta) return null;
      if (window.jQuery && window.jQuery.fn && window.jQuery.fn.sceditor) {
        var $ = window.jQuery;
        return $(ta).sceditor('instance') || null;
      }
    } catch (e) {}
    return null;
  }

  function wrapTextareaSelection(ta, open, close) {
    try {
      ta.focus();
      var start = ta.selectionStart || 0;
      var end = ta.selectionEnd || 0;
      var val = asText(ta.value);
      var sel = val.substring(start, end);
      ta.value = val.substring(0, start) + open + sel + close + val.substring(end);

      var pos = start + open.length;
      var posEnd = pos + sel.length;
      ta.setSelectionRange(pos, posEnd);
    } catch (e) {
      try { ta.value += open + close; } catch (e2) {}
    }
  }

  function insertWithEditor(inst, open, close) {
    var ta = getTextareaFromInst(inst);
    var ed = getScEditorInstance(ta);
    if (ed && typeof ed.insertText === 'function') {
      ed.insertText(open, close);
      return;
    }
    if (ta) wrapTextareaSelection(ta, open, close);
  }

  // --- tiny title editor helpers (in modal) ---
  function wrapInInput(el, open, close) {
    try {
      el.focus();
      var s = el.selectionStart || 0;
      var e = el.selectionEnd || 0;
      var v = asText(el.value);
      var sel = v.substring(s, e);
      el.value = v.substring(0, s) + open + sel + close + v.substring(e);
      var p = s + open.length;
      el.setSelectionRange(p, p + sel.length);
    } catch (e) {}
  }

  function normalizeTitle(raw) {
    raw = asText(raw).replace(/\r?\n/g, ' ').trim();
    if (!raw) raw = 'Спойлер';

    // Чтобы не ломать [spoiler="..."] если юзер вставил кавычки в заголовок.
    // Меняем на типографские, визуально почти то же, но тег не рвёт.
    raw = raw.replace(/"/g, '“').replace(/'/g, '’');

    return raw;
  }

  function buildSpoilerOpenTag(title) {
    title = normalizeTitle(title);
    // Всегда в двойных кавычках — это то, что тебе нужно для BBCode внутри.
    return '[spoiler="' + title + '"]';
  }

  function openTitleModal(onOk) {
    var overlay = document.createElement('div');
    overlay.className = 'af-aqr-sp-overlay';

    overlay.innerHTML =
      '<div class="af-aqr-sp-modal" role="dialog" aria-modal="true">' +
        '<div class="af-aqr-sp-head">' +
          '<div class="af-aqr-sp-title">Заголовок спойлера</div>' +
          '<button type="button" class="af-aqr-sp-close" aria-label="Закрыть">×</button>' +
        '</div>' +

        '<div class="af-aqr-sp-body">' +
          '<div class="af-aqr-sp-tools" role="toolbar" aria-label="Форматирование заголовка">' +
            '<span class="af-aqr-sp-sep"></span>' +
          '</div>' +

          '<textarea class="af-aqr-sp-input" rows="4" placeholder="Можно BBCode: [b]жирно[/b], [size=16]...[/size], [align=center]...[/align], [img]URL[/img]"></textarea>' +
          '<div class="af-aqr-sp-hint">Заголовок вставится как <code>[spoiler=&quot;...&quot;]</code>, поэтому BBCode внутри заголовка теперь безопасен. Кавычки в заголовке будут заменены на типографские.</div>' +
        '</div>' +

        '<div class="af-aqr-sp-foot">' +
          '<button type="button" class="af-aqr-sp-btn">Отмена</button>' +
          '<button type="button" class="af-aqr-sp-btn primary">Применить</button>' +
        '</div>' +
      '</div>';

    function close() {
      try { overlay.remove(); } catch (e) {}
      document.removeEventListener('keydown', onKeyDown, true);
    }
    function onKeyDown(e) {
      if (e && e.key === 'Escape') close();
    }

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) close();
    });

    var input = overlay.querySelector('.af-aqr-sp-input');

    function doAct(act) {
      if (!input) return;
      if (act === 'b') return wrapInInput(input, '[b]', '[/b]');
      if (act === 'i') return wrapInInput(input, '[i]', '[/i]');
      if (act === 'u') return wrapInInput(input, '[u]', '[/u]');
      if (act === 's') return wrapInInput(input, '[s]', '[/s]');
      if (act === 'al') return wrapInInput(input, '[align=left]', '[/align]');
      if (act === 'ac') return wrapInInput(input, '[align=center]', '[/align]');
      if (act === 'ar') return wrapInInput(input, '[align=right]', '[/align]');
      if (act === 'img') {
        var url = prompt('URL картинки для заголовка (будет [img]URL[/img])');
        url = asText(url).trim();
        if (!url) return;
        wrapInInput(input, '[img]' + url + '[/img]', '');
        return;
      }
    }

    overlay.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('button[data-act]') : null;
      if (!btn) return;
      e.preventDefault();
      doAct(btn.getAttribute('data-act'));
    });

    overlay.addEventListener('change', function (e) {
      var sel = e.target;
      if (!sel || !sel.getAttribute) return;

      var act = sel.getAttribute('data-act');
      if (act === 'size') {
        var v = asText(sel.value).trim();
        if (v) wrapInInput(input, '[size=' + v + ']', '[/size]');
        sel.value = '';
      }
      if (act === 'font') {
        var f = asText(sel.value).trim();
        if (f) wrapInInput(input, '[font=' + f + ']', '[/font]');
        sel.value = '';
      }
    });

    var btnClose = overlay.querySelector('.af-aqr-sp-close');
    var btnCancel = overlay.querySelector('.af-aqr-sp-btn');
    var btnOk = overlay.querySelector('.af-aqr-sp-btn.primary');

    btnClose.addEventListener('click', close);
    btnCancel.addEventListener('click', close);

    btnOk.addEventListener('click', function () {
      var title = normalizeTitle(input ? input.value : '');
      close();
      onOk({ title: title });
    });

    document.body.appendChild(overlay);
    document.addEventListener('keydown', onKeyDown, true);
    if (input) input.focus();
  }

  // --- lazy load activation ---
  function activateMedia(root) {
    try {
      if (!root) return;

      // IMG
      var imgs = root.querySelectorAll('img[data-src], img[data-srcset]');
      for (var i = 0; i < imgs.length; i++) {
        var img = imgs[i];
        var ds = img.getAttribute('data-src');
        if (ds) {
          img.setAttribute('src', ds);
          img.removeAttribute('data-src');
        }
        var dss = img.getAttribute('data-srcset');
        if (dss) {
          img.setAttribute('srcset', dss);
          img.removeAttribute('data-srcset');
        }
      }

      // IFRAME
      var ifr = root.querySelectorAll('iframe[data-src]');
      for (var j = 0; j < ifr.length; j++) {
        var fr = ifr[j];
        var s = fr.getAttribute('data-src');
        if (s) {
          fr.setAttribute('src', s);
          fr.removeAttribute('data-src');
        }
      }

      // VIDEO/SOURCE
      var vids = root.querySelectorAll('video[data-src]');
      for (var k = 0; k < vids.length; k++) {
        var v = vids[k];
        var vs = v.getAttribute('data-src');
        if (vs) {
          v.setAttribute('src', vs);
          v.removeAttribute('data-src');
        }
        try { v.load(); } catch (e) {}
      }

      var srcs = root.querySelectorAll('source[data-src]');
      for (var n = 0; n < srcs.length; n++) {
        var so = srcs[n];
        var ss = so.getAttribute('data-src');
        if (ss) {
          so.setAttribute('src', ss);
          so.removeAttribute('data-src');
        }
      }

      var v2 = root.querySelectorAll('video');
      for (var m = 0; m < v2.length; m++) {
        try { v2[m].load(); } catch (e2) {}
      }
    } catch (e3) {}
  }

  function setOpen(sp, open) {
    if (!sp) return;

    var head = sp.querySelector('.af-aqr-spoiler-head');
    var body = sp.querySelector('.af-aqr-spoiler-body');
    var foot = sp.querySelector('.af-aqr-spoiler-foot');

    sp.setAttribute('data-open', open ? '1' : '0');

    if (head) head.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (body) body.hidden = !open;
    if (foot) foot.hidden = !open;

    if (open && body && !sp.__afSpoilerActivated) {
      sp.__afSpoilerActivated = true;
      activateMedia(body);
    }
  }

  function toggleSpoiler(sp) {
    var isOpen = sp.getAttribute('data-open') === '1';
    setOpen(sp, !isOpen);
  }

  function bindSpoilers(root) {
    root = root || document;

    var list = root.querySelectorAll('blockquote.af-aqr-spoiler');
    for (var i = 0; i < list.length; i++) {
      var sp = list[i];
      if (sp.__afSpoilerBound) continue;
      sp.__afSpoilerBound = true;

      var head = sp.querySelector('.af-aqr-spoiler-head');
      var collapse = sp.querySelector('.af-aqr-spoiler-collapse');

      if (head) {
        head.addEventListener('click', function (e) {
          e.preventDefault();
          toggleSpoiler(this.closest('blockquote.af-aqr-spoiler'));
        });

        head.addEventListener('keydown', function (e) {
          if (!e) return;
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            toggleSpoiler(this.closest('blockquote.af-aqr-spoiler'));
          }
        });
      }

      if (collapse) {
        collapse.addEventListener('click', function (e) {
          e.preventDefault();
          var sp2 = this.closest('blockquote.af-aqr-spoiler');
          setOpen(sp2, false);
          try {
            var h = sp2 && sp2.querySelector ? sp2.querySelector('.af-aqr-spoiler-head') : null;
            if (h) h.focus();
          } catch (e2) {}
        });
      }

      setOpen(sp, false);
    }
  }

  // --- toolbar handler ---
  function ourHandler(inst) {
    openTitleModal(function (data) {
      var title = data && data.title ? data.title : 'Спойлер';
      var open = buildSpoilerOpenTag(title);
      insertWithEditor(inst, open, '[/spoiler]');
    });
  }

function resolveInst(ctx, caller) {
  // SCEditor обычно вызывает exec так, что `this` = editor instance
  if (ctx && typeof ctx.insertText === 'function') return ctx;

  // Иногда редактор приходит как caller
  if (caller && typeof caller.insertText === 'function') return caller;

  // Иногда caller = textarea
  if (caller && caller.nodeType === 1 && caller.tagName === 'TEXTAREA') {
    var ed = getScEditorInstance(caller);
    return ed || { textarea: caller };
  }

  // Фоллбэк: найдём message textarea и инстанс
  var ta = getTextareaFromInst(null);
  var ed2 = getScEditorInstance(ta);
  return ed2 || { textarea: ta };
}

function registerSceditorCommand(cmdName) {
  try {
    if (!window.jQuery || !window.jQuery.sceditor || !window.jQuery.sceditor.command) return;

    window.jQuery.sceditor.command.set(cmdName, {
      tooltip: 'Спойлер',
      exec: function (caller) {
        ourHandler(resolveInst(this, caller));
      },
      txtExec: function (caller) {
        ourHandler(resolveInst(this, caller));
      }
    });
  } catch (e) {}
}

  function install() {
    try {
      // Регистрируем в оба реестра (AE и AQR), чтобы точно подхватилось
      if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);
      if (!window.afAeBuiltinHandlers)  window.afAeBuiltinHandlers  = Object.create(null);

      // Дадим подсказку мапперу команд (если он это читает)
      try { ourHandler.cmd = 'af_spoiler'; } catch (e0) {}

      // Вешаем на оба ключа — иногда в layout может быть spoiler, иногда af_spoiler
      window.afAqrBuiltinHandlers.spoiler = ourHandler;
      window.afAqrBuiltinHandlers.af_spoiler = ourHandler;

      window.afAeBuiltinHandlers.spoiler = ourHandler;
      window.afAeBuiltinHandlers.af_spoiler = ourHandler;

      // И ГЛАВНОЕ: регаем/перерегаем SCEditor-команду, даже если раньше была заглушка
      registerSceditorCommand('af_spoiler');
      registerSceditorCommand('spoiler');
    } catch (e) {}
  }

  install();
  for (var t = 1; t <= 20; t++) setTimeout(install, t * 250);


  function boot() {
    bindSpoilers(document);
    try {
      var mo = new MutationObserver(function () { bindSpoilers(document); });
      mo.observe(document.documentElement || document.body, { childList: true, subtree: true });
    } catch (e) {}
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

})();

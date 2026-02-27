(function () {
  'use strict';

  if (window.__afAeCharCountAndPrewLoaded) return;
  window.__afAeCharCountAndPrewLoaded = true;

  function asText(x) { return String(x == null ? '' : x); }

  // ====== CONFIG from PHP payload (ACP settings) ======
  function getCfg() {
    try {
      var p = window.afAePayload || window.afAdvancedEditorPayload || window.afAdvancedEditor || null;
      var cfg = null;
      if (p && p.cfg) cfg = Object.assign({}, p.cfg);
      if (p && p.payload && p.payload.cfg) cfg = Object.assign({}, p.payload.cfg);
      if (p && typeof p.countBbcode !== 'undefined') {
        if (!cfg) cfg = {};
        cfg.countBbcode = p.countBbcode;
      }
      if (p && p.payload && typeof p.payload.countBbcode !== 'undefined') {
        if (!cfg) cfg = {};
        cfg.countBbcode = p.payload.countBbcode;
      }
      if (cfg) return cfg;
    } catch (e) {}
    return {};
  }

  function parseCsvIds(csv) {
    csv = asText(csv).trim();
    if (!csv) return []; // пусто = везде
    var out = [];
    var seen = Object.create(null);
    csv.split(',').forEach(function (part) {
      var n = parseInt(asText(part).trim(), 10);
      if (n > 0 && !seen[n]) { seen[n] = 1; out.push(n); }
    });
    return out;
  }

  function listToSet(list) {
    var set = Object.create(null);
    if (Array.isArray(list)) {
      for (var i = 0; i < list.length; i++) {
        var n = parseInt(list[i], 10);
        if (n > 0) set[n] = 1;
      }
    }
    return set;
  }

  var CFG = getCfg();
  var ALLOWED_POSTCOUNT_FORUM_IDS = parseCsvIds(CFG.postcountForumIds || '');
  var ALLOWED_FORM_FEATURE_FORUM_IDS = parseCsvIds(CFG.formFeatureForumIds || '');

  var POSTCOUNT_SET = listToSet(ALLOWED_POSTCOUNT_FORUM_IDS);
  var FORMFEATURE_SET = listToSet(ALLOWED_FORM_FEATURE_FORUM_IDS);

  function debounce(fn, ms) {
    var t = null;
    return function () {
      var ctx = this, args = arguments;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(ctx, args); }, ms || 150);
    };
  }

  // ====== helpers: forum id ======
  function getUrlParam(name) {
    try {
      var u = new URL(window.location.href);
      return u.searchParams.get(name);
    } catch (e) { return null; }
  }

    function getForumIdFromDom() {
    // 0) fid из hidden в формах (newreply/newthread/editpost/quickreply)
    var el =
        document.querySelector('input[name="fid"]') ||
        document.querySelector('form#quick_reply_form input[name="fid"]') ||
        document.querySelector('form#post input[name="fid"]');
    if (el && el.value && String(el.value).match(/^\d+$/)) return parseInt(el.value, 10);

    // 0.1) иногда fid может лежать в hidden "forum" или "f"
    var el2 = document.querySelector('input[name="f"]') || document.querySelector('input[name="forum"]');
    if (el2 && el2.value && String(el2.value).match(/^\d+$/)) return parseInt(el2.value, 10);

    // 1) showthread: иногда есть MyBBSettings.fid
    try {
        if (window.MyBBSettings && String(window.MyBBSettings.fid || '').match(/^\d+$/)) {
        return parseInt(window.MyBBSettings.fid, 10);
        }
    } catch (e) {}

    // 2) глобальная переменная fid (иногда в шаблонах)
    try {
        if (typeof fid !== 'undefined' && String(fid).match(/^\d+$/)) return parseInt(fid, 10);
    } catch (e) {}

    // 3) из URL (forumdisplay.php?fid=...)
    var fidFromUrl = getUrlParam('fid');
    if (fidFromUrl && String(fidFromUrl).match(/^\d+$/)) return parseInt(fidFromUrl, 10);

    // 4) showthread.php?tid=... — fid не в урле
    // РАНЬШЕ: брали ПЕРВЫЙ a[href*="forumdisplay.php?fid="] -> часто это категория.
    // ТЕПЕРЬ: берём ПОСЛЕДНИЙ (обычно это реальный форум темы).
    try {
        var links = document.querySelectorAll('a[href*="forumdisplay.php?fid="]');
        if (links && links.length) {
        for (var i = links.length - 1; i >= 0; i--) {
            var href = links[i].getAttribute('href') || '';
            var m = href.match(/[?&]fid=(\d+)/);
            if (m) return parseInt(m[1], 10);
        }
        }
    } catch (e) {}

    return null;
    }


  function inAllowedForum(list, setObj) {
    // пусто => везде
    if ((!Array.isArray(list) || !list.length) && (!setObj || typeof setObj !== 'object')) return true;
    if (Array.isArray(list) && !list.length) return true;

    var fid2 = getForumIdFromDom();
    if (fid2 === null) return false;

    // быстрый путь: set
    if (setObj && setObj[fid2]) return true;

    // фоллбек: массив
    if (Array.isArray(list)) return list.indexOf(fid2) !== -1;

    return false;
  }

  // ====== BBCode -> plain text (для счётчика) ======
  function stripBbForCount(s) {
    s = asText(s);

    // вырезаем тяжёлые штуки
    s = s.replace(/\[mask\b[\s\S]*?\[\/mask\]/gi, '');
    s = s.replace(/\[img\b[^\]]*\][\s\S]*?\[\/img\]/gi, '');

    // теги
    s = s.replace(/\[(\/?)[^\]\s=]+(?:=[^\]]+)?\]/g, '');

    return s;
  }

  function countGraphemes(s) {
    return Array.from(asText(s)).length;
  }

  // ====== SCEditor helpers ======
  function findTextarea() {
    return document.querySelector('textarea#message') ||
      document.querySelector('textarea[name="message"]') ||
      document.querySelector('textarea');
  }

  function getSceditorInstance(ta) {
    try {
      if (!ta || !window.jQuery) return null;
      var $ = window.jQuery;
      if (!$.fn || !$.fn.sceditor) return null;
      var inst = $(ta).sceditor('instance');
      if (inst && typeof inst.val === 'function') return inst;
      return null;
    } catch (e) {
      return null;
    }
  }

  function getEditorRawText(ta, inst) {
    try {
      if (inst && typeof inst.val === 'function') return asText(inst.val());
    } catch (e) {}
    return asText(ta ? ta.value : '');
  }

  // ====== FORM UI: bar + preview box ======
    function buildUiAboveEditor(ta) {
    var sc = null;
    try { sc = ta.closest('.sceditor-container'); } catch (e) { sc = null; }

    var anchor = sc || ta;
    if (!anchor || !anchor.parentNode) return null;

    if (document.querySelector('.af-ccp-wrap')) return null;

    var wrap = document.createElement('div');
    wrap.className = 'af-ccp-wrap';

    var bar = document.createElement('div');
    bar.className = 'af-ccp-bar';

    var value = document.createElement('span');
    value.className = 'af-ccp-value';
    value.textContent = '0';

    var hint = document.createElement('span');
    hint.className = 'af-ccp-muted';
    hint.innerHTML = '<sup>SYM</sup>';

    var label = document.createElement('span');
    label.className = 'af-ccp-label';
    label.textContent = '';

    bar.appendChild(label);
    bar.appendChild(value);
    bar.appendChild(hint);

    var previewBox = document.createElement('div');
    previewBox.className = 'af-ccp-preview';
    previewBox.hidden = true;

    var pt = document.createElement('div');
    pt.className = 'af-ccp-preview-title';

    var ptText = document.createElement('span');
    ptText.className = 'af-ccp-preview-title-text';
    ptText.textContent = 'Предпросмотр';

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'af-ccp-preview-close';
    closeBtn.textContent = 'Закрыть превью';

    pt.appendChild(ptText);
    pt.appendChild(closeBtn);

    var pb = document.createElement('div');
    pb.className = 'af-ccp-preview-body';

    previewBox.appendChild(pt);
    previewBox.appendChild(pb);

    wrap.appendChild(bar);
    wrap.appendChild(previewBox);

    anchor.parentNode.insertBefore(wrap, anchor);

    // хук на кнопку закрытия (без привязки к previewBtn)
    closeBtn.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        pb.innerHTML = '';
        previewBox.hidden = true;
        wrap.classList.remove('af-ccp-preview-open');
        return false;
    }, true);

    return { wrap: wrap, valueEl: value, previewBox: previewBox, previewBody: pb };
    }


  function updateCounter(ui, ta, inst) {
    if (!ui) return;
    var raw = getEditorRawText(ta, inst);
    var textForCount = raw;
    if (!CFG.countBbcode) {
      textForCount = stripBbForCount(raw);
    }
    ui.valueEl.textContent = String(countGraphemes(textForCount));
  }

  function findPreviewButton(form) {
    if (!form) return null;
    return form.querySelector('input[name="previewpost"]');
  }

  function isAtfHiddenEditorMode() {
    var meta = document.querySelector('meta[name="af-atf-hide-editor"]');
    return !!(meta && asText(meta.getAttribute('content')) === '1');
  }

  // ====== Preview extract ======
  function extractPreviewHtmlFromResponse(htmlText) {
    var doc = null;
    try { doc = new DOMParser().parseFromString(asText(htmlText), 'text/html'); } catch (e) { return { html: '', why: 'DOMParser failed' }; }
    if (!doc) return { html: '', why: 'No doc' };

    var candidates = [
      '#preview_post', '#previewpost', '#preview', '.previewpost',
      '.post.preview', '.postbit.preview', '.postbit_prev',
      '#preview_post_container', '#posts .post.preview',
      '#posts .post', '#posts .postbit', '.postbit', '.post'
    ];

    for (var i = 0; i < candidates.length; i++) {
      var el = doc.querySelector(candidates[i]);
      if (!el) continue;

      var body =
        el.querySelector('.post_body') ||
        el.querySelector('.post-content') ||
        el.querySelector('.post_content') ||
        el.querySelector('.postbody') ||
        el.querySelector('.content') ||
        el;

      var out = asText(body.innerHTML || '').trim();
      if (out) return { html: out, why: 'matched ' + candidates[i] };
    }

    return { html: '', why: 'nothing matched' };
  }

  function unescapeWeirdString(s) {
    s = asText(s);
    var looksEsc = (s.indexOf('\\u0') !== -1) || (s.indexOf('\\n') !== -1) || (s.indexOf('\\"') !== -1);
    if (!looksEsc) return s;

    s = s.replace(/\\u([0-9a-fA-F]{4})/g, function (_m, hex) {
      try { return String.fromCharCode(parseInt(hex, 16)); } catch (e) { return _m; }
    });

    s = s
      .replace(/\\n/g, '\n')
      .replace(/\\t/g, '\t')
      .replace(/\\r/g, '\r')
      .replace(/\\"/g, '"')
      .replace(/\\'/g, "'")
      .replace(/\\\//g, '/');

    return s;
  }

  function ajaxPreview(ui, form, ta, inst) {
    if (!ui || !form) return;

    var raw = getEditorRawText(ta, inst);
    if (!raw.trim()) {
      ui.previewBody.innerHTML = '';
      ui.previewBox.hidden = true;
      return;
    }

    ui.previewBox.hidden = false;
    ui.previewBody.innerHTML = '<div class="af-ccp-muted">Готовлю предпросмотр…</div>';

    var fd = new FormData(form);
    fd.set('previewpost', '1');

    try {
      if (ta && ta.name) fd.set(ta.name, raw);
      if (ta && ta.id === 'message') fd.set('message', raw);
    } catch (e) {}

    fetch(form.getAttribute('action') || window.location.href, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    })
      .then(function (r) { return r.text(); })
      .then(function (txt) {
        var res = extractPreviewHtmlFromResponse(txt);
        var html = res && res.html ? res.html : '';
        html = unescapeWeirdString(html);

        if (!html) {
          ui.previewBody.innerHTML = '<div class="af-ccp-muted">Не нашла блок превью в ответе (' + asText(res && res.why ? res.why : 'no reason') + ').</div>';
          ui.previewBox.hidden = false;
          return;
        }

        ui.previewBody.innerHTML = html;
        ui.previewBox.hidden = false;
      })
      .catch(function () {
        ui.previewBody.innerHTML = '<div class="af-ccp-muted">Ошибка превью (fetch).</div>';
        ui.previewBox.hidden = false;
      });
  }

  function closePreview(ui) {
    if (!ui) return;
    ui.previewBody.innerHTML = '';
    ui.previewBox.hidden = true;
    if (ui.wrap) ui.wrap.classList.remove('af-ccp-preview-open');
  }

    function togglePreview(ui, form, ta, inst) {
    if (!ui) return;

    // Toggle: повторный клик закрывает
    var open = !ui.previewBox.hidden;
    if (open) {
        closePreview(ui);
        return;
    }

    if (ui.wrap) ui.wrap.classList.add('af-ccp-preview-open');
    ajaxPreview(ui, form, ta, inst);
    }


  function initFormCounterAndPreview() {
    if (!inAllowedForum(ALLOWED_FORM_FEATURE_FORUM_IDS, FORMFEATURE_SET)) return;

    var ta = findTextarea();
    if (!ta) return;

    var inst = getSceditorInstance(ta);
    var form = ta.closest('form') || document.querySelector('form#post') || ta.closest('form');
    if (!form) return;

    var ui = buildUiAboveEditor(ta);
    if (!ui) ui = (function () {
      var wrap = document.querySelector('.af-ccp-wrap');
      if (!wrap) return null;
      return {
        wrap: wrap,
        valueEl: wrap.querySelector('.af-ccp-value'),
        previewBox: wrap.querySelector('.af-ccp-preview'),
        previewBody: wrap.querySelector('.af-ccp-preview-body')
      };
    })();
    if (!ui) return;

    var upd = debounce(function () { updateCounter(ui, ta, inst); }, 120);

    ta.addEventListener('input', upd, { passive: true });
    ta.addEventListener('keyup', upd, { passive: true });
    ta.addEventListener('paste', function () { setTimeout(upd, 0); }, { passive: true });
    ta.addEventListener('cut', function () { setTimeout(upd, 0); }, { passive: true });

    var last = '';
    setInterval(function () {
      var now = getEditorRawText(ta, inst);
      if (now !== last) {
        last = now;
        updateCounter(ui, ta, inst);
      }
    }, 600);

    updateCounter(ui, ta, inst);

    var previewBtn = findPreviewButton(form);
    var useNativePreview = isAtfHiddenEditorMode();

    if (previewBtn && !previewBtn.__afCcpBound && !useNativePreview) {
      previewBtn.__afCcpBound = true;

      previewBtn.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        togglePreview(ui, form, ta, inst);
        return false;
      }, true);
    }

    if (form && !form.__afCcpSubmitBound && !useNativePreview) {
      form.__afCcpSubmitBound = true;

      form.addEventListener('submit', function (ev) {
        try {
          var sub = ev.submitter;
          if (sub && sub.name === 'previewpost') {
            ev.preventDefault();
            ev.stopPropagation();
            togglePreview(ui, form, ta, inst);
            return false;
          }
        } catch (e) {}
      }, true);
    }

    // закрываем превью при клике вне блока (аккуратно, без агрессии)
    if (!document.__afCcpDocClickBound) {
      document.__afCcpDocClickBound = true;
      document.addEventListener('click', function (ev) {
        var wrap = document.querySelector('.af-ccp-wrap');
        if (!wrap) return;
        var pb = wrap.querySelector('.af-ccp-preview');
        if (!pb || pb.hidden) return;

        var t = ev.target;
        if (!t) return;
        if (wrap.contains(t)) return;

        // закрыть
        pb.hidden = true;
        var body = wrap.querySelector('.af-ccp-preview-body');
        if (body) body.innerHTML = '';
      }, true);
    }
  }

  // ====== POSTS: counter in published posts ======
  function findPostBodies(postEl) {
    return postEl.querySelector('.post_body') ||
      postEl.querySelector('.post-content') ||
      postEl.querySelector('.post_content') ||
      postEl.querySelector('.postbody') ||
      null;
  }

  function extractVisiblePostText(bodyEl) {
    if (!bodyEl) return '';
    var clone = bodyEl.cloneNode(true);

    var kill = [
      '.signature', '.post_sig', '.post-signature',
      '.postmask', '.post-mask', '.mask', '.pl-mask',
      '[data-mask]'
    ];
    kill.forEach(function (sel) {
      var nodes = clone.querySelectorAll(sel);
      for (var i = 0; i < nodes.length; i++) nodes[i].remove();
    });

    var text = asText(clone.textContent || '');
    text = text.replace(/\s+/g, ' ').trim();
    return text;
  }

  function applyPostCountersOnce() {
    if (!inAllowedForum(ALLOWED_POSTCOUNT_FORUM_IDS, POSTCOUNT_SET)) return;

    var posts = document.querySelectorAll('.post');
    if (!posts || !posts.length) return;

    for (var i = 0; i < posts.length; i++) {
      var post = posts[i];
      if (post.querySelector('.af-ccp-postcount')) continue;

      var body = findPostBodies(post);
      if (!body) continue;

      var text = extractVisiblePostText(body);
      if (!text) continue;

      var n = countGraphemes(text);

      var box = document.createElement('div');
      box.className = 'af-ccp-postcount';
      box.textContent = 'Символов в посте: ' + n;

      body.insertAdjacentElement('afterend', box);
    }
  }

  function initPostCounters() {
    applyPostCountersOnce();

    try {
      var mo = new MutationObserver(function (ml) {
        for (var i = 0; i < ml.length; i++) {
          if (ml[i].addedNodes && ml[i].addedNodes.length) {
            applyPostCountersOnce();
            break;
          }
        }
      });
      mo.observe(document.body, { childList: true, subtree: true });
    } catch (e) {}
  }

  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  onReady(function () {
    initFormCounterAndPreview();
    initPostCounters();
    setTimeout(initFormCounterAndPreview, 300);
    setTimeout(initFormCounterAndPreview, 900);
  });

})();

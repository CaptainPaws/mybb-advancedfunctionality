(function () {
  'use strict';

  if (window.__afAeDraftsBooted) return;
  window.__afAeDraftsBooted = true;

  // ====== CONFIG ======
  var SUBMIT_SS_PREFIX = 'af_ae_drafts_just_submitted::';
  var SUBMIT_TTL_MS = 2 * 60 * 1000; // 2 минуты

  // Как часто реально пишем в localStorage при наборе
  var INPUT_THROTTLE_MS = 800;

  function asText(x) { return String(x == null ? '' : x); }

  // ====== DOM helpers ======
  function findMessageTextarea(root) {
    try {
      if (!root) root = document;
      return (
        root.querySelector('textarea#message') ||
        root.querySelector('textarea[name="message"]') ||
        null
      );
    } catch (e) { return null; }
  }

  function findFormsWithMessageTextarea() {
    var out = [];
    try {
      var forms = document.querySelectorAll('form');
      for (var i = 0; i < forms.length; i++) {
        var f = forms[i];
        if (!f || f.nodeType !== 1) continue;
        var ta = findMessageTextarea(f);
        if (!ta) continue;
        out.push(f);
      }
    } catch (e) {}

    if (!out.length) {
      try {
        var ta2 = findMessageTextarea(document);
        if (ta2) {
          var f2 = ta2.form || (ta2.closest ? ta2.closest('form') : null);
          if (f2) out.push(f2);
        }
      } catch (e2) {}
    }

    var uniq = [];
    for (var j = 0; j < out.length; j++) {
      if (uniq.indexOf(out[j]) === -1) uniq.push(out[j]);
    }
    return uniq;
  }

  function getIntField(form, name) {
    try {
      if (!form) return 0;
      var el = form.querySelector('input[name="' + name + '"]');
      if (!el) return 0;
      var v = parseInt(el.value, 10);
      return isFinite(v) && v > 0 ? v : 0;
    } catch (e) { return 0; }
  }

  function getStorageKey(form) {
    var tid = getIntField(form, 'tid');
    if (tid) return 'af_ae_drafts_tid_' + tid;

    var fid = getIntField(form, 'fid');
    if (fid) return 'af_ae_drafts_fid_' + fid;

    return 'af_ae_drafts_url_' + location.pathname + location.search;
  }

  // ====== SCEditor helpers ======
  function getSceditorInstance(textarea) {
    try {
      if (!textarea) return null;
      if (!window.jQuery) return null;

      var $ = window.jQuery;
      var $ta = $(textarea);
      var inst = $ta.sceditor && $ta.sceditor('instance');
      if (inst && typeof inst.val === 'function') return inst;
    } catch (e) {}
    return null;
  }

  function getEditorBBCode(textarea) {
    try {
      var inst = getSceditorInstance(textarea);
      if (inst && typeof inst.val === 'function') return asText(inst.val());
    } catch (e) {}
    return textarea ? asText(textarea.value) : '';
  }

  function setEditorBBCode(textarea, value) {
    value = asText(value);
    try {
      var inst = getSceditorInstance(textarea);
      if (inst && typeof inst.val === 'function') {
        inst.val(value);
        try { if (typeof inst.updateOriginal === 'function') inst.updateOriginal(); } catch (e0) {}
        return;
      }
    } catch (e) {}
    if (textarea) textarea.value = value;
  }

  function hardClearEditor(form, textarea) {
    try { if (textarea) textarea.value = ''; } catch (e0) {}

    try {
      var inst = getSceditorInstance(textarea);
      if (inst) {
        try { inst.val(''); } catch (e1) {}
        try {
          if (typeof inst.getBody === 'function') {
            var b = inst.getBody();
            if (b) b.innerHTML = '';
          }
        } catch (e2) {}
        try { if (typeof inst.updateOriginal === 'function') inst.updateOriginal(); } catch (e3) {}
      }
    } catch (e4) {}

    try {
      var container = null;
      if (textarea && textarea.closest) container = textarea.closest('.sceditor-container');
      if (!container && form) container = form.querySelector('.sceditor-container');

      if (container) {
        var iframe = container.querySelector('iframe');
        if (iframe && iframe.contentDocument && iframe.contentDocument.body) {
          iframe.contentDocument.body.innerHTML = '';
        }
        var wysiwygDiv = container.querySelector('.sceditor-wysiwyg');
        if (wysiwygDiv) wysiwygDiv.innerHTML = '';
      }
    } catch (e5) {}
  }

  // ====== submit flag ======
  function markJustSubmitted(key) {
    try { sessionStorage.setItem(SUBMIT_SS_PREFIX + key, String(Date.now())); } catch (e) {}
  }

  function consumeJustSubmitted(key) {
    try {
      var k = SUBMIT_SS_PREFIX + key;
      var v = sessionStorage.getItem(k);
      if (!v) return false;

      var t = parseInt(v, 10);
      sessionStorage.removeItem(k);

      if (!isFinite(t)) return true;
      return (Date.now() - t) <= SUBMIT_TTL_MS;
    } catch (e) {
      return false;
    }
  }

  // ====== small helpers ======
  function throttle(fn, ms) {
    var last = 0;
    var timer = 0;
    return function () {
      var now = Date.now();
      var args = arguments;
      if (now - last >= ms) {
        last = now;
        try { fn.apply(null, args); } catch (e) {}
        return;
      }
      if (timer) return;
      timer = window.setTimeout(function () {
        timer = 0;
        last = Date.now();
        try { fn.apply(null, args); } catch (e2) {}
      }, ms - (now - last));
    };
  }

  // ====== core installer ======
  function installOnForm(form) {
    if (!form || form.nodeType !== 1) return;
    if (form._afAeDraftsInstalled) return;
    form._afAeDraftsInstalled = true;

    var ta = findMessageTextarea(form);
    if (!ta) return;

    var key = getStorageKey(form);
    form._afAeDraftsKey = key;
    form._afAeDraftsLast = '';
    form._afAeDraftsLocked = false;

    function saveNow(force) {
      if (form._afAeDraftsLocked) return;
      var text = getEditorBBCode(ta);
      if (!force && text === form._afAeDraftsLast) return;

      try { localStorage.setItem(key, text); } catch (e1) {}
      form._afAeDraftsLast = text;
    }

    function deleteNow() {
      try { localStorage.removeItem(key); } catch (e2) {}
      form._afAeDraftsLast = '';
    }

    // 1) Если пришли после отправки — это сигнал очистки
    if (consumeJustSubmitted(key)) {
      form._afAeDraftsLocked = true;
      deleteNow();
      setEditorBBCode(ta, '');
      hardClearEditor(form, ta);
    } else {
      // 2) Восстановить черновик только если поле пустое
      try {
        var existing = localStorage.getItem(key);
        var current = asText(getEditorBBCode(ta)).trim();
        if (existing && !current) {
          setEditorBBCode(ta, existing);
          form._afAeDraftsLast = asText(existing);
        } else {
          form._afAeDraftsLast = asText(getEditorBBCode(ta));
        }
      } catch (e3) {}
    }

    // 3) Автосохранение раз в минуту (как бэкап)
    form._afAeDraftsTimer = window.setInterval(function () {
      saveNow(false);
    }, 60 * 1000);

    // 3.1) Сохранение во время набора (аккуратно, с троттлингом)
    var saveSoon = throttle(function () { saveNow(false); }, INPUT_THROTTLE_MS);

    // textarea input/keyup
    try {
      ta.addEventListener('input', saveSoon, true);
      ta.addEventListener('keyup', saveSoon, true);
      ta.addEventListener('change', function () { saveNow(true); }, true);
    } catch (eIn) {}

    // SCEditor: чтобы ловить ввод в iframe, подписываемся на body
    function bindSceditorBody() {
      try {
        var inst = getSceditorInstance(ta);
        if (!inst || typeof inst.getBody !== 'function') return false;

        var body = inst.getBody();
        if (!body || body._afAeDraftsBodyBound) return true;
        body._afAeDraftsBodyBound = true;

        body.addEventListener('input', saveSoon, true);
        body.addEventListener('keyup', saveSoon, true);
        return true;
      } catch (eB) { return false; }
    }

    // Пытаемся привязаться сразу и чуть позже (редактор иногда поднимается после DOMContentLoaded)
    bindSceditorBody();
    window.setTimeout(bindSceditorBody, 250);
    window.setTimeout(bindSceditorBody, 800);

    // 3.2) Перед уходом/скрытием вкладки — сохранить принудительно
    try {
      document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') saveNow(true);
      }, true);
    } catch (eV) {}

    try {
      window.addEventListener('pagehide', function () { saveNow(true); }, true);
      window.addEventListener('beforeunload', function () { saveNow(true); }, true);
    } catch (eU) {}

    // 4) Submit: удаляем черновик + ставим флаг для очистки после перезагрузки
    form.addEventListener('submit', function () {
      form._afAeDraftsLocked = true;

      try {
        if (form._afAeDraftsTimer) {
          window.clearInterval(form._afAeDraftsTimer);
          form._afAeDraftsTimer = 0;
        }
      } catch (e4) {}

      deleteNow();
      markJustSubmitted(key);

      try {
        window.requestAnimationFrame(function () {
          try { hardClearEditor(form, ta); } catch (e5) {}
        });
      } catch (e6) {}

      window.setTimeout(function () {
        try { hardClearEditor(form, ta); } catch (e7) {}
      }, 50);

      window.setTimeout(function () {
        try { hardClearEditor(form, ta); } catch (e8) {}
      }, 250);
    }, true);

    // 5) BFCache: если вернулись назад — и флаг ещё жив, добить очистку
    try {
      window.addEventListener('pageshow', function () {
        if (!form || !form._afAeDraftsKey) return;
        if (consumeJustSubmitted(form._afAeDraftsKey)) {
          form._afAeDraftsLocked = true;
          try { localStorage.removeItem(form._afAeDraftsKey); } catch (e9) {}
          try { hardClearEditor(form, ta); } catch (e10) {}
        }
      });
    } catch (e11) {}
  }

  function installAll() {
    var forms = findFormsWithMessageTextarea();
    for (var i = 0; i < forms.length; i++) {
      try { installOnForm(forms[i]); } catch (e0) {}
    }
  }

  function boot() {
    try { installAll(); } catch (e0) {}
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      window.setTimeout(boot, 0);
      window.setTimeout(boot, 250);
      window.setTimeout(boot, 800);
    });
  } else {
    window.setTimeout(boot, 0);
    window.setTimeout(boot, 250);
  }

  // если textarea/редактор появляется позже
  document.addEventListener('focusin', function (e) {
    var t = e && e.target;
    if (!t || t.nodeType !== 1) return;

    if (t.tagName === 'TEXTAREA' && (t.id === 'message' || t.name === 'message')) {
      try {
        var f = t.form || (t.closest ? t.closest('form') : null);
        if (f) installOnForm(f);
      } catch (e1) {}
      return;
    }

    try {
      var f2 = t.closest ? t.closest('form') : null;
      if (f2 && findMessageTextarea(f2)) installOnForm(f2);
    } catch (e2) {}
  }, true);

})();

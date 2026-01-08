(function () {
  'use strict';

  // ====== CONFIG ======
  var SUBMIT_SS_PREFIX = 'af_aqr_drafts_just_submitted::';
  var SUBMIT_TTL_MS = 2 * 60 * 1000; // 2 минуты

  function asText(x) { return String(x == null ? '' : x); }

  // ====== DOM helpers ======
  function getForm() {
    return document.getElementById('quick_reply_form') || document.querySelector('form#quick_reply_form') || null;
  }

  function getTextarea(form) {
    if (form) {
      return form.querySelector('textarea#message') || form.querySelector('textarea[name="message"]') || null;
    }
    return document.querySelector('textarea#message') || document.querySelector('textarea[name="message"]') || null;
  }

  function getTid(form) {
    try {
      var el = form ? form.querySelector('input[name="tid"]') : null;
      var tid = el ? parseInt(el.value, 10) : 0;
      return isFinite(tid) && tid > 0 ? tid : 0;
    } catch (e) {
      return 0;
    }
  }

  function getStorageKey(form) {
    var tid = getTid(form);
    if (tid) return 'af_aqr_drafts_last_tid_' + tid;
    return 'af_aqr_drafts_last_' + location.pathname + location.search;
  }

  // ====== SCEditor helpers ======
  function getSceditorInstance(textarea) {
    try {
      if (!textarea) return null;

      // jQuery way
      if (window.jQuery) {
        var $ = window.jQuery;
        var $ta = $(textarea);
        var inst = $ta.sceditor && $ta.sceditor('instance');
        if (inst && typeof inst.val === 'function') return inst;
      }
    } catch (e) {}

    return null;
  }

  function getEditorBBCode(textarea, inst) {
    try {
      if (inst && typeof inst.val === 'function') return asText(inst.val());
    } catch (e) {}
    return textarea ? asText(textarea.value) : '';
  }

  function setEditorBBCode(textarea, inst, value) {
    value = asText(value);
    try {
      if (inst && typeof inst.val === 'function') { inst.val(value); return; }
    } catch (e) {}
    if (textarea) textarea.value = value;
  }

  function hardClearEditor(form, textarea, inst) {
    // textarea
    try { if (textarea) textarea.value = ''; } catch (e0) {}

    // SCEditor API
    if (!inst) {
      try { inst = getSceditorInstance(textarea); } catch (e1) {}
    }
    if (inst) {
      try { inst.val(''); } catch (e2) {}
      try {
        if (typeof inst.getBody === 'function') {
          var b = inst.getBody();
          if (b) b.innerHTML = '';
        }
      } catch (e3) {}
      try { if (typeof inst.updateOriginal === 'function') inst.updateOriginal(); } catch (e4) {}
    }

    // DOM fallback
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

  // ====== core ======
  function installOnce() {
    var form = getForm();
    var ta = getTextarea(form);
    if (!form || !ta) return null;

    if (form._afDraftsInstalled) return form;
    form._afDraftsInstalled = true;

    var key = getStorageKey(form);
    form._afDraftsKey = key;
    form._afDraftsLast = '';
    form._afDraftsLocked = false;

    var inst = null;
    try { inst = getSceditorInstance(ta); } catch (e0) {}

    function saveNow() {
      if (form._afDraftsLocked) return;
      var text = getEditorBBCode(ta, inst);
      try { localStorage.setItem(key, text); } catch (e1) {}
      form._afDraftsLast = text;
    }

    function deleteNow() {
      try { localStorage.removeItem(key); } catch (e2) {}
      form._afDraftsLast = '';
    }

    // 1) Если пришли после отправки — это сигнал очистки
    if (consumeJustSubmitted(key)) {
      form._afDraftsLocked = true;
      deleteNow();
      setEditorBBCode(ta, inst, '');
      hardClearEditor(form, ta, inst);
    } else {
      // 2) Восстановить черновик только если поле пустое
      try {
        var existing = localStorage.getItem(key);
        if (existing && !asText(getEditorBBCode(ta, inst)).trim()) {
          setEditorBBCode(ta, inst, existing);
          form._afDraftsLast = asText(existing);
        }
      } catch (e3) {}
    }

    // 3) Автосохранение раз в минуту
    form._afDraftsTimer = window.setInterval(function () {
      if (form._afDraftsLocked) return;
      var text = getEditorBBCode(ta, inst);
      if (text === form._afDraftsLast) return;
      saveNow();
    }, 60 * 1000);

    // 4) Submit: удаляем черновик + ставим флаг для очистки после перезагрузки
    form.addEventListener('submit', function () {
      form._afDraftsLocked = true;

      try {
        if (form._afDraftsTimer) {
          window.clearInterval(form._afDraftsTimer);
          form._afDraftsTimer = 0;
        }
      } catch (e4) {}

      // удалить черновик и отметить submit
      deleteNow();
      markJustSubmitted(key);

      // визуально почистить редактор (на случай “зависло”/BFCache)
      try {
        window.requestAnimationFrame(function () {
          try { hardClearEditor(form, ta, inst); } catch (e5) {}
        });
      } catch (e6) {}

      window.setTimeout(function () {
        try { hardClearEditor(form, ta, inst); } catch (e7) {}
      }, 50);

      window.setTimeout(function () {
        try { hardClearEditor(form, ta, inst); } catch (e8) {}
      }, 250);
    }, true);

    // 5) BFCache: если “вернулись назад” — и всё ещё есть флаг, добить очистку
    try {
      window.addEventListener('pageshow', function () {
        if (!form || !form._afDraftsKey) return;
        if (consumeJustSubmitted(form._afDraftsKey)) {
          form._afDraftsLocked = true;
          try { localStorage.removeItem(form._afDraftsKey); } catch (e9) {}
          try { hardClearEditor(form, ta, inst); } catch (e10) {}
        }
      });
    } catch (e11) {}

    return form;
  }

  // ====== boot ======
  function boot() {
    try { installOnce(); } catch (e0) {}
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
      try { installOnce(); } catch (e1) {}
    }
  }, true);

})();

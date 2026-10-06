(function () {
  'use strict';

  // The lightweight shell owns automatic persistence. Avoid competing legacy\n  // listeners, deletion rules and storage keys when its manager is present.\n  if (window.afAdvancedEditorShell && window.afAdvancedEditorShell.draftsManaged) {\n    window.af_ae_drafts_exec = function (editor) { if (editor && editor.focus) editor.focus(); };\n    return;\n  }\n  if (window.__afAeDraftsBooted) return;
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

  function scheduleHardClear(form, textarea) {
    try {
      window.requestAnimationFrame(function () {
        try { hardClearEditor(form, textarea); } catch (e0) {}
      });
    } catch (e1) {}

    var delays = [0, 50, 250, 800];
    for (var i = 0; i < delays.length; i++) {
      (function (d) {
        window.setTimeout(function () {
          try { hardClearEditor(form, textarea); } catch (e2) {}
        }, d);
      })(delays[i]);
    }
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
  function installOnForm(form, preferredTextarea) {
    if (!form || form.nodeType !== 1) return;
    if (form._afAeDraftsInstalled) return;
    form._afAeDraftsInstalled = true;

    var ta = preferredTextarea || findMessageTextarea(form);
    if (!ta) return;
    var listeners = [];
    function listen(target, type, callback, options) { target.addEventListener(type, callback, options); listeners.push([target, type, callback, options]); }
    form._afAeDraftsDispose = function () {
      form._afAeDraftsLocked = true; clearInterval(form._afAeDraftsTimer);
      listeners.forEach(function(l) { l[0].removeEventListener(l[1], l[2], l[3]); }); listeners = [];
      form._afAeDraftsInstalled = false;
    };

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

    // Retain drafts through refresh, navigation and validation errors. A pending
    // submit is confirmed only on a fresh, empty editor after navigation to the
    // thread; an error response with the submitted textarea retains its draft.
    var submitted = consumeJustSubmitted(key);
    try {
      var existing = localStorage.getItem(key);
      var current = asText(getEditorBBCode(ta)).trim();
      var landedOnThread = /(?:^|\\/)showthread\\.php$/.test(location.pathname);
      var validationError = !!document.querySelector('.error, .error_message, #error, .alert--error');
      if (submitted && landedOnThread && !current && !validationError) {
        deleteNow();
        setEditorBBCode(ta, '');
      } else if (existing && !current) {
        setEditorBBCode(ta, existing);
        form._afAeDraftsLast = asText(existing);
      } else {
        form._afAeDraftsLast = asText(getEditorBBCode(ta));
      }
    } catch (e3) {}

    // 3) Автосохранение раз в минуту (как бэкап)
    form._afAeDraftsTimer = window.setInterval(function () {
      saveNow(false);
    }, 60 * 1000);

    // 3.1) Сохранение во время набора (аккуратно, с троттлингом)
    var saveSoon = throttle(function () { saveNow(false); }, INPUT_THROTTLE_MS);

    // textarea input/keyup
    try {
      listen(ta, 'input', saveSoon, true);
      listen(ta, 'keyup', saveSoon, true);
      listen(ta, 'change', function () { saveNow(true); }, true);
    } catch (eIn) {}

    // SCEditor: чтобы ловить ввод в iframe, подписываемся на body
    function bindSceditorBody() {
      try {
        var inst = getSceditorInstance(ta);
        if (!inst || typeof inst.getBody !== 'function') return false;

        var body = inst.getBody();
        if (!body || body._afAeDraftsBodyBound) return true;
        body._afAeDraftsBodyBound = true;

        listen(body, 'input', saveSoon, true);
        listen(body, 'keyup', saveSoon, true);
        return true;
      } catch (eB) { return false; }
    }

    // Пытаемся привязаться сразу и чуть позже (редактор иногда поднимается после DOMContentLoaded)
    bindSceditorBody();
    window.setTimeout(bindSceditorBody, 250);
    window.setTimeout(bindSceditorBody, 800);

    // 3.2) Перед уходом/скрытием вкладки — сохранить принудительно
    try {
      listen(document, 'visibilitychange', function () {
        if (document.visibilityState === 'hidden') saveNow(true);
      }, true);
    } catch (eV) {}

    try {
      listen(window, 'pagehide', function () { saveNow(true); }, true);
      listen(window, 'beforeunload', function () { saveNow(true); }, true);
    } catch (eU) {}

    // Submission can fail due to validation, network, moderation or AJAX.
    // Do NOT clear the textarea or storage on submit: preserve until success.
    listen(form, 'submit', function () {
      saveNow(true);
      markJustSubmitted(key);
    }, true);

    // Native AJAX reply success commonly resets the message field without
    // navigating. Only that actual reset clears an in-flight submission draft.
    listen(form, 'reset', function () {
      if (!consumeJustSubmitted(key)) return;
      window.setTimeout(function () {
        if (asText(getEditorBBCode(ta)).trim() === '') deleteNow();
      }, 0);
    });
  }

  // Drafts are a baseline safety feature, not an opt-in toolbar dialog.
  function bootDrafts() {
    findFormsWithMessageTextarea().forEach(function (form) {
      installOnForm(form, findMessageTextarea(form));
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootDrafts);
  else bootDrafts();
  document.addEventListener('af:editor-ready', function (event) {
    var ta = event.detail && event.detail.textarea;
    if (ta && ta.name === 'message' && ta.form) installOnForm(ta.form, ta);
  });

  window.af_ae_drafts_exec = function (editor, definition, caller) {
    var owner = caller && caller.closest('[data-af-editor-shell]');
    var ta = owner && owner.__afAeTextarea || editor && editor.textarea;
    if (ta && ta.jquery) ta = ta[0];
    if ((!ta || !ta.form) && editor && editor.getContainer) {
      var container = editor.getContainer(); if (container && container.jquery) container = container[0];
      var shell = container && container.closest('[data-af-editor-shell]');
      ta = (shell || container) && (shell || container).querySelector('textarea:not(.sceditor-textarea)');
    }
    if (!ta || !ta.form) return;
    installOnForm(ta.form, ta);
    editor.focus();
  };
  document.addEventListener('af:editor-destroyed', function (event) {
    var ta = event.detail.textarea;
    var form = ta && (ta.form || ta.__afAeForm);
    if (form && form._afAeDraftsDispose) form._afAeDraftsDispose();
  });
})();

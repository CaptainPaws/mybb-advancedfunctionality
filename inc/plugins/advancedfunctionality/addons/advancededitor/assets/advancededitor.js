(function () {
  'use strict';

  if (window.__afAdvancedEditorLoaded) return;
  window.__afAdvancedEditorLoaded = true;

  var P = window.afAePayload || window.afAdvancedEditorPayload || {};
  var CFG = (P && P.cfg) ? P.cfg : {};

  // Глобальные гарды от мутаций/тогглов (с твоего прошлого канона)
  if (typeof window.__afAeGlobalToggling === 'undefined') window.__afAeGlobalToggling = 0;
  if (typeof window.__afAeIgnoreMutationsUntil === 'undefined') window.__afAeIgnoreMutationsUntil = 0;

  function now() { return Date.now ? Date.now() : +new Date(); }
  function asText(x) { return String(x == null ? '' : x); }

  function log() {
    // включай руками если надо: window.__afAeDebug = true
    if (!window.__afAeDebug) return;
    try { console.log.apply(console, arguments); } catch (e) {}
  }

  function hasJq() { return !!(window.jQuery && window.jQuery.fn); }
  function hasSceditor() { return hasJq() && typeof window.jQuery.fn.sceditor === 'function'; }

  function isHidden(el) {
    if (!el || el.nodeType !== 1) return true;
    if (el.disabled) return true;
    if (el.type === 'hidden') return true;
    if (el.offsetParent === null && el.getClientRects().length === 0) return true;
    return false;
  }

  function isEligibleTextarea(ta) {
    if (!ta || ta.nodeType !== 1 || ta.tagName !== 'TEXTAREA') return false;

    // пропускаем служебные скедитор-поля (важно!)
    var cls = ta.className || '';
    if (/\bsceditor-source\b/i.test(cls)) return false;
    if (/\bsceditor-textarea\b/i.test(cls)) return false;

    // если явно помечено "не трогать"
    if (ta.getAttribute('data-af-ae-skip') === '1') return false;

    // типовые MyBB textarea, которые нам НЕ нужны — можешь расширять
    var name = (ta.getAttribute('name') || '').toLowerCase();
    if (name === 'subject') return false;

    return true;
  }

  function getToolbarString() {
    // 1) если ты уже генеришь строку в ACP и отдаёшь как P.toolbar — берём её
    if (P && P.toolbar) return asText(P.toolbar);

    // 2) fallback: если layout есть, но строки нет — делаем простую сборку
    // Ожидаем layout как массив строк/групп; если другой формат — это просто запасной парашют.
    var layout = P && P.layout;
    if (!layout) return '';

    try {
      if (Array.isArray(layout)) {
        // поддержка: ["bold,italic,underline","|","link,unlink"]
        return layout.map(asText).join('');
      }
      if (typeof layout === 'string') return layout;
    } catch (e) {}

    return '';
  }

  function safeGetInstance($ta) {
    try { return $ta.sceditor('instance'); } catch (e) { return null; }
  }

  function updateOriginal(inst) {
    if (!inst) return;
    try { inst.updateOriginal(); } catch (e) {}
    try {
      // иногда оригинал — это textarea, иногда hidden input, но на MyBB обычно textarea
      if (inst.original && inst.original.nodeType === 1) {
        // ничего
      }
    } catch (e2) {}
  }

  function bindSubmitSync(form, inst, ta) {
    if (!form || !inst || !ta) return;
    if (form.__afAeSubmitBound) return;
    form.__afAeSubmitBound = true;

    form.addEventListener('submit', function () {
      try { updateOriginal(inst); } catch (e) {}
      try {
        // железная подстраховка: если textarea пустая, но в редакторе есть контент — кладём вручную
        if (ta && typeof ta.value === 'string') {
          if (ta.value === '' || ta.value == null) {
            try {
              var val = '';
              // для bbcode форматтера
              try { val = inst.val ? inst.val() : ''; } catch (e2) { val = ''; }
              if (val && typeof val === 'string') ta.value = val;
            } catch (e3) {}
          }
        }
      } catch (e4) {}
    }, true);
  }

  function patchEditorInstanceForSafeToggle(inst) {
    // Мягкая защита: во время переключения режима гасим мутации/реинициализацию
    if (!inst || inst.__afAePatchedToggle) return;
    inst.__afAePatchedToggle = true;

    var origToggle = inst.toggleSourceMode;
    if (typeof origToggle === 'function') {
      inst.toggleSourceMode = function () {
        window.__afAeGlobalToggling++;
        window.__afAeIgnoreMutationsUntil = now() + 1200; // 1.2s
        try { return origToggle.apply(inst, arguments); }
        finally {
          setTimeout(function () {
            window.__afAeIgnoreMutationsUntil = now() + 250;
            window.__afAeGlobalToggling = Math.max(0, (window.__afAeGlobalToggling | 0) - 1);
          }, 0);
        }
      };
    }
  }

  function initOneTextarea(ta) {
    if (!isEligibleTextarea(ta)) return false;
    if (isHidden(ta)) return false;

    // анти-реинициализация
    if (ta.__afAeInited) return true;
    if (now() < (window.__afAeIgnoreMutationsUntil || 0)) return false;
    if ((window.__afAeGlobalToggling || 0) > 0) return false;

    if (!hasSceditor()) return false;

    var $ = window.jQuery;
    var $ta = $(ta);

    // если MyBB/кто-то уже поднял инстанс — не трогаем, но помечаем
    var existing = safeGetInstance($ta);
    if (existing) {
      ta.__afAeInited = true;
      existing.__afAeOwned = true;
      try { patchEditorInstanceForSafeToggle(existing); } catch (e0) {}
      try { bindSubmitSync(ta.form, existing, ta); } catch (e1) {}
      return true;
    }

    var toolbar = getToolbarString();

    try {
      // создаём наш инстанс
      $ta.sceditor({
        format: 'bbcode',
        toolbar: toolbar,
        style: P.sceditorCss || '',
        height: 180,
        width: '100%',
        resizeEnabled: true,
        autoExpand: false
      });

      var inst = safeGetInstance($ta);
      if (inst) {
        ta.__afAeInited = true;
        inst.__afAeOwned = true;
        inst.__afAeToolbarSig = asText(toolbar);

        try { patchEditorInstanceForSafeToggle(inst); } catch (e2) {}
        try { bindSubmitSync(ta.form, inst, ta); } catch (e3) {}

        // если нужно — форс старт в source mode (BBCode)
        if (P && P.forceSourceMode) {
          try {
            if (!inst.sourceMode) inst.toggleSourceMode();
          } catch (e4) {}
        }
        return true;
      }
    } catch (e) {
      log('[AE] init error', e);
    }

    return false;
  }

  function scanAndInit(root) {
    root = root || document;
    if (!root.querySelectorAll) return;

    var list = root.querySelectorAll('textarea');
    for (var i = 0; i < list.length; i++) {
      initOneTextarea(list[i]);
    }
  }

  function observeDynamicEditors() {
    if (!window.MutationObserver) return;

    if (window.__afAeObserverAttached) return;
    window.__afAeObserverAttached = true;

    var obs = new MutationObserver(function (muts) {
      if (now() < (window.__afAeIgnoreMutationsUntil || 0)) return;
      if ((window.__afAeGlobalToggling || 0) > 0) return;

      for (var i = 0; i < muts.length; i++) {
        var m = muts[i];
        if (!m.addedNodes || !m.addedNodes.length) continue;

        for (var j = 0; j < m.addedNodes.length; j++) {
          var n = m.addedNodes[j];
          if (!n || n.nodeType !== 1) continue;

          // если добавили textarea или контейнер с textarea — инициализируем
          if (n.tagName === 'TEXTAREA') {
            initOneTextarea(n);
          } else if (n.querySelectorAll) {
            var tas = n.querySelectorAll('textarea');
            if (tas && tas.length) {
              for (var k = 0; k < tas.length; k++) initOneTextarea(tas[k]);
            }
          }
        }
      }
    });

    obs.observe(document.documentElement || document.body, {
      childList: true,
      subtree: true
    });
  }

  function boot() {
    // ждём jquery+sceditor
    var tries = 0;
    (function wait() {
      tries++;
      if (hasSceditor()) {
        scanAndInit(document);
        observeDynamicEditors();
        return;
      }
      if (tries < 80) return setTimeout(wait, 50);
    })();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

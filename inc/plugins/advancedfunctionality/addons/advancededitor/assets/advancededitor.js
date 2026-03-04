(function () {
  'use strict';

  if (window.__afAdvancedEditorLoaded) return;
  window.__afAdvancedEditorLoaded = true;

  var $ = window.jQuery;
  var state = {
    booted: false,
    bootAttempts: 0,
    submitBoundForms: new WeakMap(),
    loadedAssets: Object.create(null)
  };

  function toText(value) {
    return String(value == null ? '' : value);
  }

  function debugEnabled() {
    return !!(window.AE_DEBUG || window.__afAeDebug || window.__afAeEditpostDebug);
  }

  function log() {
    if (!debugEnabled()) return;
    var args = Array.prototype.slice.call(arguments);
    args.unshift('AE:');
    try { console.log.apply(console, args); } catch (err) {}
  }

  function getPayload() {
    return window.afAdvancedEditorPayload || window.afAePayload || {};
  }

  function hasRuntime() {
    return !!($ && $.fn && typeof $.fn.sceditor === 'function' && $.sceditor && $.sceditor.command);
  }

  function hasBbcodePlugin() {
    return !!($.sceditor && $.sceditor.plugins && $.sceditor.plugins.bbcode && $.sceditor.plugins.bbcode.bbcode);
  }

  function mapByCmd(list) {
    var out = Object.create(null);
    (Array.isArray(list) ? list : []).forEach(function (item) {
      var cmd = toText(item && item.cmd).trim();
      if (!cmd || cmd === '|') return;
      out[cmd] = item;
    });
    return out;
  }

  function ensureAsset(url, type) {
    var normalized = toText(url).trim();
    if (!normalized) return;

    var key = type + ':' + normalized;
    if (state.loadedAssets[key]) return;
    state.loadedAssets[key] = true;

    if (type === 'css') {
      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = normalized;
      link.setAttribute('data-af-ae-pack', '1');
      document.head.appendChild(link);
      return;
    }

    if (type === 'js') {
      var script = document.createElement('script');
      script.src = normalized;
      script.defer = true;
      script.setAttribute('data-af-ae-pack', '1');
      document.head.appendChild(script);
    }
  }

  function ensurePackAssets(payload) {
    var packs = payload && payload.packs;
    if (!packs || typeof packs !== 'object') return;

    var css = Array.isArray(packs.css) ? packs.css : [];
    var js = Array.isArray(packs.js) ? packs.js : [];

    css.forEach(function (url) { ensureAsset(url, 'css'); });
    js.forEach(function (url) { ensureAsset(url, 'js'); });
  }

  function registerSourceCommand() {
    if (!$.sceditor.command.get('source')) {
      $.sceditor.command.set('source', {
        tooltip: 'Source',
        exec: function () { if (typeof this.toggleSourceMode === 'function') this.toggleSourceMode(); },
        txtExec: function () { if (typeof this.toggleSourceMode === 'function') this.toggleSourceMode(); }
      });
    }
  }

  function ensureCommand(cmd, def) {
    if (!cmd || !$.sceditor || !$.sceditor.command || typeof $.sceditor.command.set !== 'function') return;

    var open = toText(def && def.opentag).trim();
    var close = toText(def && def.closetag).trim();
    var handler = toText(def && def.handler).trim();
    var title = toText((def && (def.title || def.hint)) || cmd);

    $.sceditor.command.set(cmd, {
      tooltip: title,
      exec: function () {
        if (handler && window.afAeBuiltinHandlers && typeof window.afAeBuiltinHandlers[handler] === 'function') {
          return window.afAeBuiltinHandlers[handler].call(this, { cmd: cmd, def: def });
        }
        this.insertText(open, close);
      },
      txtExec: function () {
        this.insertText(open, close);
      }
    });
  }

  function ensureCommandIcon(cmd, iconUrl) {
    iconUrl = toText(iconUrl).trim();
    if (!cmd || !iconUrl || !document || !document.head) return;

    var styleId = 'af-ae-icon-' + cmd.replace(/[^a-z0-9_-]/ig, '_');
    if (document.getElementById(styleId)) return;

    var css = '.sceditor-button-' + cmd + ' div{background-image:none!important;}' +
      '.sceditor-button-' + cmd + '::before{content:"";display:block;width:16px;height:16px;margin:0 auto;background-repeat:no-repeat;background-position:center;background-size:contain;background-image:url("' + iconUrl.replace(/"/g, '\\"') + '");}';

    var style = document.createElement('style');
    style.id = styleId;
    style.appendChild(document.createTextNode(css));
    document.head.appendChild(style);
  }

  function registerBbcodes(commandDefs) {
    if (!hasBbcodePlugin()) return;
    var api = $.sceditor.plugins.bbcode.bbcode;

    Object.keys(commandDefs).forEach(function (cmd) {
      var def = commandDefs[cmd] || {};
      var open = toText(def.opentag).trim();
      var close = toText(def.closetag).trim();
      var match = open.match(/^\[([a-z0-9_]+)(?:=[^\]]*)?\]/i);
      if (!match) return;

      var tag = toText(match[1]).toLowerCase();
      if (!tag) return;

      api.set(tag, {
        format: function (element, content) {
          return open + (content || '') + close;
        },
        html: function (token, attrs, content) {
          var defaultAttr = attrs && attrs.defaultattr ? toText(attrs.defaultattr) : '';
          var safeAttr = defaultAttr ? ' data-attr="' + defaultAttr.replace(/"/g, '&quot;') + '"' : '';
          return '<span class="af-ae-bb af-ae-bb-' + tag + '" data-tag="' + tag + '"' + safeAttr + '>' + (content || '') + '</span>';
        }
      });
    });
  }

  function normalizeLayout(layout) {
    if (!layout || typeof layout !== 'object') layout = {};
    if (!Array.isArray(layout.sections)) layout.sections = [];

    if (!layout.sections.length) {
      layout.sections = [{ items: ['bold', 'italic', 'underline', '|', 'quote', 'code', '|', 'link', 'image', '|', 'source'] }];
    }

    return layout;
  }

  function buildToolbar(layout) {
    var normalized = normalizeLayout(layout);
    var sectionStrings = [];

    normalized.sections.forEach(function (section) {
      if (!section || !Array.isArray(section.items)) return;

      var sectionItems = [];
      section.items.forEach(function (item) {
        var cmd = toText(item).trim();
        if (!cmd) return;

        if (cmd === '|') {
          if (sectionItems.length && sectionItems[sectionItems.length - 1] !== '|') {
            sectionItems.push('|');
          }
          return;
        }

        sectionItems.push(cmd);
      });

      while (sectionItems.length && sectionItems[sectionItems.length - 1] === '|') sectionItems.pop();
      if (!sectionItems.length) return;

      sectionStrings.push(sectionItems.join(','));
    });

    var toolbar = sectionStrings.join('|');
    log('toolbar built', toolbar);
    return toolbar;
  }

  function getEditor(textarea) {
    if (!textarea) return null;

    try {
      var $textarea = $(textarea);
      return $textarea.data('sceditor') || $textarea.sceditor('instance') || null;
    } catch (err) {
      return null;
    }
  }

  function bindSubmitSync(textarea, editor) {
    if (!textarea || !textarea.form || !editor) return;

    var form = textarea.form;
    if (state.submitBoundForms.get(form)) return;

    state.submitBoundForms.set(form, true);
    form.addEventListener('submit', function () {
      var textareas = form.querySelectorAll('textarea');
      for (var i = 0; i < textareas.length; i += 1) {
        var ta = textareas[i];
        var inst = getEditor(ta);
        if (!inst) continue;
        try { inst.updateOriginal(); } catch (err) {}
      }
    });
  }

  function bindSyncEvents(textarea, editor) {
    if (!textarea || !editor || textarea.__afAeSyncBound) return;
    textarea.__afAeSyncBound = true;

    var sync = function () {
      try { editor.updateOriginal(); } catch (err) {}
    };

    try {
      editor.bind('valuechanged keyup blur paste input change', sync);
      editor.bind('sourceMode', function () {
        sync();
      });
    } catch (err) {}

    bindSubmitSync(textarea, editor);
  }

  function isEligibleTextarea(textarea, payload) {
    if (!textarea || textarea.tagName !== 'TEXTAREA' || textarea.disabled) return false;
    if (textarea.classList.contains('sceditor-ignore')) return false;

    var forced = toText(payload && payload.cfg && payload.cfg.editorSelector).trim();
    if (forced) {
      try { return textarea.matches(forced); } catch (err) { return false; }
    }

    var name = toText(textarea.name).toLowerCase();
    return name !== 'subject' && name !== 'captcha';
  }

  function buildOptions(payload, toolbar) {
    return {
      plugins: 'bbcode',
      style: toText(payload.sceditorCss || payload.sceditorContentCss || ''),
      toolbar: toolbar,
      format: 'bbcode',
      startInSourceMode: true,
      width: '100%',
      height: 180,
      resizeEnabled: true,
      autoExpand: false
    };
  }

  function initTextarea(textarea, payload, toolbar) {
    if (!isEligibleTextarea(textarea, payload)) return;

    var existing = getEditor(textarea);
    if (existing) {
      bindSyncEvents(textarea, existing);
      return;
    }

    var originalContent = textarea.value;
    log('init', textarea.name || textarea.id || '(textarea)');

    try {
      $(textarea).sceditor(buildOptions(payload, toolbar));
    } catch (err) {
      log('init failed', err && err.message ? err.message : err);
      return;
    }

    var editor = getEditor(textarea);
    if (!editor) {
      log('editor missing after init', textarea.name || textarea.id || '(textarea)');
      return;
    }

    log('editor created', textarea.name || textarea.id || '(textarea)');

    if (originalContent) {
      try { editor.val(originalContent); } catch (err) {}
      try { editor.updateOriginal(); } catch (err2) {}
      log('content restored', textarea.name || textarea.id || '(textarea)');
    }

    try {
      if (!editor.val() && textarea.value) {
        editor.val(textarea.value);
        editor.updateOriginal();
        log('content restored', 'fallback', textarea.name || textarea.id || '(textarea)');
      }
    } catch (err3) {}

    bindSyncEvents(textarea, editor);
  }

  function initAllTextareas(payload, toolbar) {
    var textareas = document.querySelectorAll('textarea:not(.sceditor-ignore)');
    for (var i = 0; i < textareas.length; i += 1) {
      initTextarea(textareas[i], payload, toolbar);
    }
  }

  function registerCommands(payload) {
    var available = mapByCmd(payload.available);
    var customDefs = mapByCmd(payload.customDefs);
    var packButtons = mapByCmd(payload.packs && payload.packs.buttons);
    var commandDefs = Object.assign({}, available, customDefs, packButtons);

    registerSourceCommand();

    Object.keys(commandDefs).forEach(function (cmd) {
      ensureCommand(cmd, commandDefs[cmd]);
      ensureCommandIcon(cmd, commandDefs[cmd] && commandDefs[cmd].icon);
    });

    registerBbcodes(commandDefs);
  }

  function boot() {
    if (!hasRuntime() || !hasBbcodePlugin()) {
      state.bootAttempts += 1;
      if (state.bootAttempts <= 40) {
        window.setTimeout(boot, 100);
      } else {
        log('runtime unavailable');
      }
      return;
    }

    if (state.booted) return;
    state.booted = true;

    var payload = getPayload();
    ensurePackAssets(payload);
    registerCommands(payload);

    var toolbar = buildToolbar(payload.layout);
    initAllTextareas(payload, toolbar);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();

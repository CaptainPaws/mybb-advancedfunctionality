(function () {
  'use strict';

  if (window.__afAdvancedEditorLoaded) return;
  window.__afAdvancedEditorLoaded = true;

  var $ = window.jQuery;
  var PAYLOAD = window.afAePayload || window.afAdvancedEditorPayload || {};
  var CFG = PAYLOAD.cfg || {};

  var state = {
    booted: false,
    observer: null,
    editors: new WeakMap(),
    bootAttempts: 0
  };

  function toText(v) { return String(v == null ? '' : v); }

  function log() {
    if (!window.__afAeDebug) return;
    var args = Array.prototype.slice.call(arguments);
    args.unshift('[AF-AE]');
    try { console.log.apply(console, args); } catch (e) {}
  }

  function hasScEditorRuntime() {
    return !!($ && $.fn && typeof $.fn.sceditor === 'function' && $.sceditor && $.sceditor.command);
  }

  function hasBbcodePlugin() {
    return !!($.sceditor && $.sceditor.plugins && $.sceditor.plugins.bbcode && $.sceditor.plugins.bbcode.bbcode);
  }

  function getPayload() {
    var p = window.afAePayload || window.afAdvancedEditorPayload || PAYLOAD || {};
    PAYLOAD = p;
    CFG = p.cfg || CFG || {};
    return p;
  }

  function getEditor(textarea) {
    if (!textarea) return null;
    if (state.editors.has(textarea)) return state.editors.get(textarea);

    var instance = null;
    try {
      if ($ && textarea) {
        var $ta = $(textarea);
        instance = $ta.data('sceditor') || $ta.sceditor('instance');
      }
    } catch (e) { instance = null; }

    if (!instance && $.sceditor && typeof $.sceditor.instance === 'function') {
      try { instance = $.sceditor.instance(textarea); } catch (e2) { instance = null; }
    }

    if (instance) state.editors.set(textarea, instance);
    return instance;
  }

  function setEditorBinding(textarea, editor) {
    if (!textarea || !editor) return;
    state.editors.set(textarea, editor);
    textarea.__afAeReady = true;
    if ($) {
      try { $(textarea).data('sceditor', editor); } catch (e) {}
    }
  }

  function normalizeLayout(layout) {
    if (!layout || typeof layout !== 'object') layout = {};
    if (!Array.isArray(layout.sections)) layout.sections = [];
    if (!layout.sections.length) {
      layout.sections = [{
        id: 'default',
        type: 'group',
        title: 'Main',
        items: ['bold', 'italic', 'underline', '|', 'quote', 'code', '|', 'link', 'image', '|', 'source']
      }];
    }
    layout.v = layout.v || 1;
    return layout;
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
        if (open || close) this.insertText(open, close);
      },
      txtExec: function () {
        if (open || close) this.insertText(open, close);
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
      var m = open.match(/^\[([a-z0-9_]+)(?:=[^\]]*)?\]/i);
      if (!m) return;

      var tag = toText(m[1]).toLowerCase();
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

  function buildToolbar(layout) {
    layout = normalizeLayout(layout);

    var toolbar = [];
    var dropdowns = [];
    var n = 0;

    layout.sections.forEach(function (section) {
      if (!section) return;
      var type = toText(section.type || 'group').toLowerCase();
      var title = toText(section.title || 'Menu');
      var items = Array.isArray(section.items) ? section.items.map(function (v) { return toText(v).trim(); }).filter(Boolean) : [];
      if (!items.length) return;

      if (type === 'dropdown') {
        n += 1;
        var dropdownCmd = 'af_dropdown_' + n;
        dropdowns.push({ cmd: dropdownCmd, title: title, items: items });
        toolbar.push(dropdownCmd);
        toolbar.push('|');
        return;
      }

      items.forEach(function (item) { toolbar.push(item); });
      toolbar.push('|');
    });

    while (toolbar.length && toolbar[toolbar.length - 1] === '|') toolbar.pop();

    return {
      toolbar: toolbar.join(','),
      dropdowns: dropdowns,
      layout: layout
    };
  }

  function registerDropdownCommands(dropdowns, commandDefs) {
    if (!Array.isArray(dropdowns)) return;

    dropdowns.forEach(function (dropdown) {
      if (!dropdown || !dropdown.cmd || !Array.isArray(dropdown.items) || !dropdown.items.length) return;

      var dropDownItems = {};
      dropdown.items.forEach(function (cmd) {
        var def = commandDefs[cmd] || {};
        dropDownItems[cmd] = toText(def.title || def.hint || cmd);
      });

      $.sceditor.command.set(dropdown.cmd, {
        tooltip: toText(dropdown.title || dropdown.cmd),
        dropDown: dropDownItems,
        exec: function (itemCmd) {
          var command = $.sceditor.command.get(itemCmd);
          if (command && typeof command.exec === 'function') command.exec.call(this);
        },
        txtExec: function (itemCmd) {
          var command = $.sceditor.command.get(itemCmd);
          if (!command) return;
          if (typeof command.txtExec === 'function') command.txtExec.call(this);
          else if (typeof command.exec === 'function') command.exec.call(this);
        }
      });
    });
  }

  function injectFontsCss() {
    if (!document || !document.head || document.getElementById('af-ae-fonts-css')) return;
    var p = getPayload();
    var families = (CFG && Array.isArray(CFG.fontFamilies)) ? CFG.fontFamilies : [];
    var base = toText(p.assetsBase || '').replace(/\/$/, '');
    if (!families.length || !base) return;

    var css = '';
    families.forEach(function (font) {
      if (!font || !font.name || !font.files) return;
      var src = [];
      [['woff2', 'woff2'], ['woff', 'woff'], ['ttf', 'truetype'], ['otf', 'opentype']].forEach(function (pair) {
        var ext = pair[0];
        if (!font.files[ext]) return;
        src.push('url("' + base + '/fonts/' + toText(font.files[ext]).replace(/^\/+/, '') + '") format("' + pair[1] + '")');
      });
      if (!src.length) return;
      css += '@font-face{font-family:"' + toText(font.name).replace(/"/g, '\\"') + '";src:' + src.join(',') + ';font-display:swap;}';
    });

    if (!css) return;
    var style = document.createElement('style');
    style.id = 'af-ae-fonts-css';
    style.appendChild(document.createTextNode(css));
    document.head.appendChild(style);
  }

  function injectFontsIntoIframe(editor) {
    if (!editor || typeof editor.getBody !== 'function') return;
    var body, doc;
    try { body = editor.getBody(); doc = body && body.ownerDocument; } catch (e) { doc = null; }
    if (!doc || !doc.head || doc.getElementById('af-ae-fonts-frame')) return;

    var source = document.getElementById('af-ae-fonts-css');
    if (!source) return;

    var style = doc.createElement('style');
    style.id = 'af-ae-fonts-frame';
    style.appendChild(doc.createTextNode(source.textContent || ''));
    doc.head.appendChild(style);
  }

  function isEligibleTextarea(textarea) {
    if (!textarea || textarea.tagName !== 'TEXTAREA' || textarea.disabled) return false;
    if (textarea.closest && textarea.closest('.sceditor-container')) return false;

    var n = toText(textarea.name).toLowerCase();
    if (n === 'subject' || n === 'captcha') return false;

    var forced = toText(CFG.editorSelector || '').trim();
    if (forced) {
      try { return textarea.matches(forced); } catch (e) { return false; }
    }

    return true;
  }

  function syncEditorToTextarea(textarea, editor) {
    if (!textarea || !editor) return;

    try { editor.updateOriginal(); } catch (e) {}
    try { textarea.value = editor.val(); } catch (e2) {}
  }

  function bindSyncEvents(textarea, editor) {
    if (!textarea || !editor || textarea.__afAeSyncBound) return;
    textarea.__afAeSyncBound = true;

    var sync = function () { syncEditorToTextarea(textarea, editor); };

    try {
      editor.bind('valuechanged keyup blur paste input change sourceMode', sync);
    } catch (e) {}

    if (textarea.form && !textarea.form.__afAeSubmitBound) {
      textarea.form.__afAeSubmitBound = true;
      textarea.form.addEventListener('submit', function () {
        var textareas = this.querySelectorAll('textarea');
        for (var i = 0; i < textareas.length; i += 1) {
          var ta = textareas[i];
          var inst = getEditor(ta);
          if (inst) syncEditorToTextarea(ta, inst);
        }
      });
    }
  }

  function buildOptions(toolbarString) {
    var p = getPayload();
    return {
      format: 'bbcode',
      toolbar: toolbarString,
      style: toText(p.sceditorContentCss || p.sceditorCss || ''),
      resizeEnabled: true,
      autoExpand: false,
      width: '100%',
      height: 180,
      startInSourceMode: true
    };
  }

  function initTextarea(textarea, toolbarString) {
    if (!hasScEditorRuntime() || !isEligibleTextarea(textarea)) return;

    var existing = getEditor(textarea);
    if (existing) {
      setEditorBinding(textarea, existing);
      bindSyncEvents(textarea, existing);
      injectFontsIntoIframe(existing);
      return;
    }

    var options = buildOptions(toolbarString);
    try {
      $(textarea).sceditor(options);
    } catch (e) {
      log('init failed', e && e.message ? e.message : e);
      return;
    }

    var editor = getEditor(textarea);
    if (!editor) {
      log('instance not found', textarea.name || textarea.id || '(anonymous)');
      return;
    }

    setEditorBinding(textarea, editor);
    bindSyncEvents(textarea, editor);
    injectFontsIntoIframe(editor);
    syncEditorToTextarea(textarea, editor);
  }

  function initExistingTextareas(toolbarString) {
    var areas = document.querySelectorAll('textarea');
    for (var i = 0; i < areas.length; i += 1) {
      initTextarea(areas[i], toolbarString);
    }
  }

  function registerObserver(toolbarString) {
    if (!window.MutationObserver || state.observer) return;

    state.observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (m) {
        var nodes = m.addedNodes || [];
        for (var i = 0; i < nodes.length; i += 1) {
          var node = nodes[i];
          if (!node || node.nodeType !== 1) continue;
          if (node.matches && (node.matches('.sceditor-container') || node.matches('iframe'))) continue;

          if (node.tagName === 'TEXTAREA') {
            initTextarea(node, toolbarString);
            continue;
          }

          if (node.querySelectorAll) {
            var textareas = node.querySelectorAll('textarea');
            for (var j = 0; j < textareas.length; j += 1) {
              initTextarea(textareas[j], toolbarString);
            }
          }
        }
      });
    });

    state.observer.observe(document.documentElement || document.body, {
      childList: true,
      subtree: true
    });
  }

  function registerSourceCommand() {
    if (!$.sceditor.command.get('source')) {
      $.sceditor.command.set('source', {
        exec: function () { if (typeof this.toggleSourceMode === 'function') this.toggleSourceMode(); },
        txtExec: function () { if (typeof this.toggleSourceMode === 'function') this.toggleSourceMode(); },
        tooltip: 'Source'
      });
    }
  }

  function runBoot() {
    var p = getPayload();

    if (!hasScEditorRuntime() || !hasBbcodePlugin()) {
      state.bootAttempts += 1;
      if (state.bootAttempts <= 20) {
        window.setTimeout(runBoot, 100);
      } else {
        log('SCEditor runtime unavailable after retries');
      }
      return;
    }

    if (state.booted) return;
    state.booted = true;

    injectFontsCss();

    var available = mapByCmd(p.available);
    var customDefs = mapByCmd(p.customDefs);
    var commandDefs = Object.assign({}, available, customDefs);

    registerSourceCommand();

    Object.keys(commandDefs).forEach(function (cmd) {
      ensureCommand(cmd, commandDefs[cmd]);
      ensureCommandIcon(cmd, commandDefs[cmd] && commandDefs[cmd].icon);
    });

    var toolbarData = buildToolbar(p.layout);
    registerDropdownCommands(toolbarData.dropdowns, commandDefs);
    registerBbcodes(commandDefs);

    initExistingTextareas(toolbarData.toolbar);
    registerObserver(toolbarData.toolbar);

    log('boot complete', toolbarData.toolbar);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', runBoot, { once: true });
  } else {
    runBoot();
  }
})();

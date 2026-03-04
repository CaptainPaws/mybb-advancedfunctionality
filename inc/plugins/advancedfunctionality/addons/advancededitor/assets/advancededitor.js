(function () {
  'use strict';

  if (window.__afAdvancedEditorLoaded) return;
  window.__afAdvancedEditorLoaded = true;

  var $ = window.jQuery;
  var P = window.afAePayload || window.afAdvancedEditorPayload || {};
  var CFG = (P && P.cfg) ? P.cfg : {};

  function log() {
    if (!window.__afAeDebug) return;
    var args = Array.prototype.slice.call(arguments);
    args.unshift('[AF-AE]');
    try { console.log.apply(console, args); } catch (e) {}
  }

  function toText(v) { return String(v == null ? '' : v); }

  function hasScEditor() {
    return !!($ && $.fn && typeof $.fn.sceditor === 'function' && $.sceditor);
  }

  var ToolbarUtils = (function createToolbarUtils() {
    function normalizeLayout(layout) {
      if (!layout || typeof layout !== 'object' || !Array.isArray(layout.sections)) {
        return {
          v: 1,
          sections: [
            {
              id: 'main',
              type: 'group',
              title: 'Main',
              items: ['bold', 'italic', 'underline', '|', 'quote', 'code', '|', 'link', 'image', '|', 'source', 'maximize']
            }
          ]
        };
      }

      layout.v = layout.v || 1;
      layout.sections = layout.sections || [];
      return layout;
    }

    function buildToolbar(layout) {
      layout = normalizeLayout(layout);
      var parts = [];
      var dropdowns = [];
      var dropdownN = 0;

      (layout.sections || []).forEach(function (section, idx) {
        if (!section) return;

        var type = toText(section.type || 'group').toLowerCase();
        var title = toText(section.title || 'Menu');
        var items = Array.isArray(section.items) ? section.items.map(function (x) { return toText(x).trim(); }).filter(Boolean) : [];
        if (!items.length) return;

        if (type === 'dropdown') {
          dropdownN += 1;
          var cmd = 'af_menu_dropdown' + dropdownN;
          parts.push(cmd);
          parts.push('|');
          dropdowns.push({
            cmd: cmd,
            title: title,
            items: items
          });
          return;
        }

        items.forEach(function (item) {
          parts.push(item);
        });
        parts.push('|');
      });

      while (parts.length && parts[parts.length - 1] === '|') parts.pop();

      return {
        toolbar: parts.join(','),
        dropdowns: dropdowns,
        layout: layout
      };
    }

    return {
      normalizeLayout: normalizeLayout,
      buildToolbar: buildToolbar
    };
  })();

  window.afAeToolbarUtils = ToolbarUtils;

  function mapByCmd(list) {
    var out = Object.create(null);
    (Array.isArray(list) ? list : []).forEach(function (row) {
      if (!row || !row.cmd) return;
      out[toText(row.cmd)] = row;
    });
    return out;
  }

  function setToolbarButtonIcon(cmd, iconUrl) {
    if (!cmd || !iconUrl || !document || !document.head) return;

    var id = 'af-ae-icon-' + cmd.replace(/[^a-z0-9_-]/ig, '_');
    if (document.getElementById(id)) return;

    var css = '' +
      '.sceditor-button-' + cmd + ' div{background-image:none!important;}' +
      '.sceditor-button-' + cmd + '::before{' +
      'content:"";display:block;width:16px;height:16px;margin:0 auto;' +
      'background-repeat:no-repeat;background-position:center;background-size:contain;' +
      'background-image:url("' + iconUrl.replace(/"/g, '\\"') + '");' +
      '}';

    var style = document.createElement('style');
    style.id = id;
    style.appendChild(document.createTextNode(css));
    document.head.appendChild(style);
  }

  function registerCommand(cmd, def) {
    if (!hasScEditor() || !cmd) return;
    var commandApi = $.sceditor.command;
    if (!commandApi || typeof commandApi.set !== 'function') return;

    var open = toText(def && def.opentag).trim();
    var close = toText(def && def.closetag).trim();
    var title = toText((def && (def.hint || def.title)) || cmd);

    var userHandler = toText(def && def.handler).trim();

    commandApi.set(cmd, {
      tooltip: title,
      exec: function () {
        if (userHandler && window.afAeBuiltinHandlers && typeof window.afAeBuiltinHandlers[userHandler] === 'function') {
          return window.afAeBuiltinHandlers[userHandler].call(this, { cmd: cmd, def: def });
        }
        if (open || close) {
          this.insertText(open, close);
        }
      },
      txtExec: function () {
        if (open || close) {
          this.insertText(open, close);
        }
      }
    });

    setToolbarButtonIcon(cmd, toText(def && def.icon).trim());
  }

  function registerDropdownCommands(dropdowns, commandDefs) {
    if (!hasScEditor() || !Array.isArray(dropdowns)) return;

    dropdowns.forEach(function (dropdown) {
      var cmd = dropdown.cmd;
      var title = toText(dropdown.title || cmd);
      var items = Array.isArray(dropdown.items) ? dropdown.items : [];
      if (!cmd || !items.length) return;

      var dropDown = {};
      items.forEach(function (itemCmd) {
        var d = commandDefs[itemCmd] || {};
        dropDown[itemCmd] = toText(d.title || d.hint || itemCmd);
      });

      $.sceditor.command.set(cmd, {
        tooltip: title,
        dropDown: dropDown,
        exec: function (itemCmd) {
          if (!itemCmd) return;
          var target = $.sceditor.command.get(itemCmd);
          if (!target || typeof target.exec !== 'function') return;
          target.exec.call(this);
        },
        txtExec: function (itemCmd) {
          if (!itemCmd) return;
          var target = $.sceditor.command.get(itemCmd);
          if (!target) return;
          if (typeof target.txtExec === 'function') target.txtExec.call(this);
          else if (typeof target.exec === 'function') target.exec.call(this);
        }
      });
    });
  }

  function parseTag(opentag, closetag) {
    var open = toText(opentag).trim();
    var close = toText(closetag).trim();
    var m = open.match(/^\[([a-z0-9_]+)(?:=[^\]]*)?\]/i);
    if (!m) return null;
    var tag = toText(m[1]).toLowerCase();
    if (!tag) return null;

    return {
      tag: tag,
      format: function (element, content) {
        return open + (content || '') + close;
      },
      html: function (token, attrs, content) {
        var dataAttr = attrs && attrs.defaultattr ? ' data-attr="' + toText(attrs.defaultattr).replace(/"/g, '&quot;') + '"' : '';
        return '<span class="af-ae-bb af-ae-bb-' + tag + '" data-tag="' + tag + '"' + dataAttr + '>' + (content || '') + '</span>';
      }
    };
  }

  function registerBbcodes(commandDefs) {
    if (!hasScEditor()) return;

    var bb = $.sceditor.plugins && $.sceditor.plugins.bbcode && $.sceditor.plugins.bbcode.bbcode;
    if (!bb || typeof bb.set !== 'function') return;

    Object.keys(commandDefs).forEach(function (cmd) {
      var def = commandDefs[cmd];
      var parsed = parseTag(def && def.opentag, def && def.closetag);
      if (!parsed) return;
      bb.set(parsed.tag, {
        format: parsed.format,
        html: parsed.html
      });
    });

    log('bbcode register complete');
  }

  function getScEditorContentCss() {
    return toText(P.sceditorContentCss || P.sceditorCss || '').trim();
  }

  function injectFontsCssOnce() {
    if (!document || !document.head) return;
    if (document.getElementById('af-ae-fonts-css')) return;

    var families = (CFG && Array.isArray(CFG.fontFamilies)) ? CFG.fontFamilies : [];
    var assetsBase = toText(P.assetsBase || '').replace(/\/$/, '');
    if (!families.length || !assetsBase) return;

    var css = '';
    families.forEach(function (font) {
      if (!font || !font.files || !font.name) return;

      var src = [];
      ['woff2', 'woff', 'ttf', 'otf'].forEach(function (ext) {
        if (!font.files[ext]) return;
        src.push('url("' + assetsBase + '/fonts/' + toText(font.files[ext]).replace(/^\/+/, '') + '") format("' + ext + '")');
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

  function injectFontsIntoEditorFrame(editor) {
    if (!editor || typeof editor.getBody !== 'function') return;
    var doc;
    try {
      var body = editor.getBody();
      doc = body && body.ownerDocument;
    } catch (e) {
      doc = null;
    }
    if (!doc || !doc.head || doc.getElementById('af-ae-fonts-css-frame')) return;

    var src = document.getElementById('af-ae-fonts-css');
    if (!src) return;

    var style = doc.createElement('style');
    style.id = 'af-ae-fonts-css-frame';
    style.appendChild(doc.createTextNode(src.textContent || ''));
    doc.head.appendChild(style);
  }

  var TARGET_NAMES = {
    message: 1,
    message_new: 1,
    post: 1,
    content: 1
  };

  function isEligibleTextarea(textarea) {
    if (!textarea || textarea.tagName !== 'TEXTAREA') return false;
    if (textarea.disabled) return false;

    var type = toText(textarea.type).toLowerCase();
    if (type === 'hidden') return false;

    var name = toText(textarea.name).toLowerCase();
    if (name === 'subject' || name === 'captcha') return false;

    if (textarea.closest && textarea.closest('.sceditor-container')) return false;
    if ((textarea.className || '').indexOf('sceditor-textarea') !== -1) return false;

    var forcedSelector = toText(CFG.editorSelector || '').trim();
    if (forcedSelector) {
      try {
        return textarea.matches(forcedSelector);
      } catch (e) {}
    }

    if (TARGET_NAMES[name]) return true;

    return true;
  }

  function syncBeforeSubmit(textarea, editor) {
    if (!textarea || !editor) return;

    var form = textarea.form;
    if (!form || form.__afAeSyncBound) return;

    form.__afAeSyncBound = true;
    form.addEventListener('submit', function () {
      try { editor.updateOriginal(); } catch (e) {}
    });
  }

  function initEditorOnTextarea(textarea, toolbarString) {
    if (!hasScEditor() || !isEligibleTextarea(textarea)) return;
    if (textarea.dataset.aeInitialized === '1') return;

    var $textarea = $(textarea);
    var existing = $textarea.data('sceditor') || ($textarea.sceditor && $textarea.sceditor('instance'));
    if (existing) {
      textarea.dataset.aeInitialized = '1';
      syncBeforeSubmit(textarea, existing);
      return;
    }

    $textarea.sceditor({
      format: 'bbcode',
      toolbar: toolbarString,
      style: getScEditorContentCss(),
      resizeEnabled: true,
      autoExpand: false,
      width: '100%',
      height: 180,
      startInSourceMode: true
    });

    var editor = $textarea.sceditor('instance');
    if (!editor) return;

    textarea.dataset.aeInitialized = '1';
    syncBeforeSubmit(textarea, editor);

    try {
      editor.bind('valuechanged keyup blur', function () {
        editor.updateOriginal();
      });
    } catch (e) {}

    try {
      editor.bind('sourceMode', function () {
        editor.updateOriginal();
      });
    } catch (e2) {}

    injectFontsIntoEditorFrame(editor);
    log('editor ready', textarea.name || textarea.id || '(anonymous)');
  }

  function initOnPage(toolbarString) {
    var list = document.querySelectorAll('textarea');
    for (var i = 0; i < list.length; i += 1) {
      initEditorOnTextarea(list[i], toolbarString);
    }
  }

  function shouldIgnoreMutationNode(node) {
    if (!node || node.nodeType !== 1) return true;
    if (node.matches && (node.matches('iframe') || node.matches('.sceditor-container') || node.matches('.sceditor-toolbar'))) {
      return true;
    }
    return false;
  }

  function setupObserver(toolbarString) {
    if (!window.MutationObserver) return;

    var observer = new MutationObserver(function (mutations) {
      var shouldRescan = false;

      mutations.forEach(function (mutation) {
        var nodes = mutation.addedNodes || [];
        for (var i = 0; i < nodes.length; i += 1) {
          var node = nodes[i];
          if (shouldIgnoreMutationNode(node)) continue;

          if (node.tagName === 'TEXTAREA') {
            shouldRescan = true;
            break;
          }

          if (node.querySelector && node.querySelector('textarea')) {
            shouldRescan = true;
            break;
          }
        }
      });

      if (shouldRescan) initOnPage(toolbarString);
    });

    observer.observe(document.documentElement || document.body, {
      childList: true,
      subtree: true
    });
  }

  function registerToggleModeCommand() {
    if (!hasScEditor()) return;
    if ($.sceditor.command.get('af_togglemode')) return;

    $.sceditor.command.set('af_togglemode', {
      tooltip: 'Toggle source/WYSIWYG',
      exec: function () {
        if (typeof this.toggleSourceMode === 'function') this.toggleSourceMode();
      },
      txtExec: function () {
        if (typeof this.toggleSourceMode === 'function') this.toggleSourceMode();
      }
    });
  }

  function boot() {
    if (!hasScEditor()) {
      log('SCEditor is not available');
      return;
    }

    log('init');

    injectFontsCssOnce();

    var availableDefs = mapByCmd(P.available);
    var customDefs = mapByCmd(P.customDefs);
    var commandDefs = Object.assign({}, availableDefs, customDefs);

    registerToggleModeCommand();

    Object.keys(commandDefs).forEach(function (cmd) {
      registerCommand(cmd, commandDefs[cmd]);
    });

    var toolbarData = ToolbarUtils.buildToolbar(P.layout);
    log('toolbar build', toolbarData.toolbar);

    registerDropdownCommands(toolbarData.dropdowns, commandDefs);
    registerBbcodes(commandDefs);

    initOnPage(toolbarData.toolbar);
    setupObserver(toolbarData.toolbar);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();

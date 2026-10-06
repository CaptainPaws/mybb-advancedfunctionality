(function (window, document) {
  'use strict';

  if (window.__afAeAnchorsPackLoaded) return;
  window.__afAeAnchorsPackLoaded = true;

  var CMD_ANCHOR = 'af_anchor';
  var CMD_ANCHORLINK = 'af_anchorlink';

  if (!window.afAeBuiltinHandlers) window.afAeBuiltinHandlers = Object.create(null);
  if (!window.afAqrBuiltinHandlers) window.afAqrBuiltinHandlers = Object.create(null);

  function asText(v) { return String(v == null ? '' : v); }

  function escHtml(s) {
    return asText(s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function sanitizeKey(v) {
    v = asText(v).trim().toLowerCase();
    if (!v) return '';

    v = v.replace(/[\x00-\x1F\x7F]+/g, '');
    v = v.replace(/\s+/g, '_');
    v = v.replace(/[^a-z0-9_-]+/g, '_');
    v = v.replace(/_+/g, '_').replace(/^[_-]+|[_-]+$/g, '');

    if (!v) return '';
    if (!/^[a-z]/.test(v)) v = 'a_' + v;

    return v.slice(0, 96);
  }

  function escBbAttr(v) {
    return asText(v)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/\]/g, '&#93;')
      .trim();
  }

  function buildAnchorTag(key) {
    return '[anchor id="' + escBbAttr(key) + '"][/anchor]';
  }

  function buildAnchorLinkTags(key) {
    var target = escBbAttr(key);
    return {
      open: '[anchorlink target="' + target + '"]',
      close: '[/anchorlink]'
    };
  }

  function promptAnchorKey(defaultValue, title) {
    var input = window.prompt(title || 'ID якоря', defaultValue || 'section_1');
    if (input == null) return '';
    return sanitizeKey(input);
  }

  function hasSelection(editor) {
    if (!editor) return false;

    try {
      if (typeof editor.sourceMode === 'function' && editor.sourceMode()) {
        var c = editor.getContainer && editor.getContainer();
        var ta = c && c.querySelector ? (c.querySelector('textarea.sceditor-textarea') || c.querySelector('textarea')) : null;
        if (!ta) return false;
        return (ta.selectionEnd - ta.selectionStart) > 0;
      }
    } catch (e0) {}

    try {
      var helper = editor.getRangeHelper && editor.getRangeHelper();
      var range = helper && helper.selectedRange ? helper.selectedRange() : null;
      return !!(range && asText(range.toString()).length);
    } catch (e1) {}

    return false;
  }

  function ensureBbcodeDefs() {
    var bb = null;
    try {
      bb = window.jQuery && jQuery.sceditor && jQuery.sceditor.plugins && jQuery.sceditor.plugins.bbcode
        ? jQuery.sceditor.plugins.bbcode.bbcode
        : null;
    } catch (e0) {
      bb = null;
    }

    if (!bb || typeof bb.set !== 'function') return;

    try {
      bb.set('anchor', {
        isInline: false,
        html: function (token, attrs) {
          var key = sanitizeKey(attrs && attrs.id);
          if (!key) key = sanitizeKey(attrs && attrs.defaultattr);
          if (!key) key = 'section_1';
          return '<span class="af-ae-anchor-placeholder" data-af-bb="anchor" data-af-anchor-key="' + escHtml(key) + '" contenteditable="false">⚓ anchor: ' + escHtml(key) + '</span>';
        },
        format: function (el) {
          var key = '';
          try { key = sanitizeKey(el && el.getAttribute && el.getAttribute('data-af-anchor-key')); } catch (e1) { key = ''; }
          if (!key) key = 'section_1';
          return buildAnchorTag(key);
        }
      });
    } catch (e2) {}

    try {
      bb.set('anchorlink', {
        isInline: true,
        html: function (token, attrs, content) {
          var key = sanitizeKey(attrs && attrs.target);
          if (!key) key = sanitizeKey(attrs && attrs.defaultattr);
          if (!key) key = 'section_1';
          return '<a class="af-ae-anchorlink-placeholder" data-af-bb="anchorlink" data-af-anchor-target="' + escHtml(key) + '" href="#">' + (content || 'anchor link') + '</a>';
        },
        format: function (el, content) {
          var key = '';
          try { key = sanitizeKey(el && el.getAttribute && el.getAttribute('data-af-anchor-target')); } catch (e3) { key = ''; }
          if (!key) key = 'section_1';
          return '[anchorlink target="' + escBbAttr(key) + '"]' + (content || '') + '[/anchorlink]';
        }
      });
    } catch (e4) {}
  }

  function insertAnchor(editor) {
    ensureBbcodeDefs();

    var key = promptAnchorKey('section_1', 'Введите ID якоря (например: section_1)');
    if (!key) return;

    var bb = buildAnchorTag(key);

    try {
      if (editor && typeof editor.insertText === 'function') {
        editor.insertText(bb, '');
        return;
      }
    } catch (e0) {}

    try {
      if (editor && typeof editor.insert === 'function') {
        editor.insert(bb, '');
      }
    } catch (e1) {}
  }

  function insertAnchorLink(editor) {
    ensureBbcodeDefs();

    var key = promptAnchorKey('section_1', 'Введите target якоря (например: section_1)');
    if (!key) return;

    var tags = buildAnchorLinkTags(key);

    if (hasSelection(editor)) {
      try {
        if (editor && typeof editor.insertText === 'function') {
          editor.insertText(tags.open, tags.close);
          return;
        }
      } catch (e0) {}

      try {
        if (editor && typeof editor.insert === 'function') {
          editor.insert(tags.open, tags.close);
          return;
        }
      } catch (e1) {}
    }

    var text = asText(window.prompt('Текст ссылки', 'Перейти к разделу')).trim();
    if (!text) text = 'Перейти к разделу';

    var bb = tags.open + text + tags.close;

    try {
      if (editor && typeof editor.insertText === 'function') {
        editor.insertText(bb, '');
        return;
      }
    } catch (e2) {}

    try {
      if (editor && typeof editor.insert === 'function') {
        editor.insert(bb, '');
      }
    } catch (e3) {}
  }

  function registerCommands() {
    try {
      if (!window.jQuery || !jQuery.sceditor || !jQuery.sceditor.command) return;

      jQuery.sceditor.command.set(CMD_ANCHOR, {
        tooltip: 'Якорь (точка)',
        exec: function () { insertAnchor(this); },
        txtExec: function () { insertAnchor(this); }
      });

      jQuery.sceditor.command.set(CMD_ANCHORLINK, {
        tooltip: 'Ссылка на якорь',
        exec: function () { insertAnchorLink(this); },
        txtExec: function () { insertAnchorLink(this); }
      });
    } catch (e0) {}
  }

  function cssEscape(v) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(v);
    }
    return asText(v).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
  }

  function getScopeNode(node) {
    if (!node || !node.closest) return document;
    return node.closest('[id^="post_"]') || node.closest('.post') || document;
  }

  function getScopeId(scope) {
    if (!scope || scope === document) return 'doc';
    var id = asText(scope.getAttribute && scope.getAttribute('id')).trim();
    id = sanitizeKey(id);
    return id || 'scope';
  }

  function ensureAnchorDomIds() {
    var used = Object.create(null);
    var list = document.querySelectorAll('.af-bb-anchor-target[data-af-anchor-key]');

    for (var i = 0; i < list.length; i++) {
      var el = list[i];
      var key = sanitizeKey(el.getAttribute('data-af-anchor-key'));
      if (!key) continue;

      var scope = getScopeNode(el);
      var scopeId = getScopeId(scope);
      var base = 'af-ae-anchor-' + scopeId + '-' + key;
      var domId = base;
      var seq = 2;

      while (used[domId] || document.getElementById(domId)) {
        if (document.getElementById(domId) === el) break;
        domId = base + '-' + seq;
        seq += 1;
      }

      used[domId] = true;
      el.id = domId;
      el.setAttribute('data-af-anchor-key', key);
      el.setAttribute('data-af-anchor-domid', domId);
    }
  }

  function resolveAnchorTarget(link) {
    if (!link) return null;

    var key = sanitizeKey(link.getAttribute('data-af-anchor-target'));
    if (!key) return null;

    var scope = getScopeNode(link);
    var inScope = scope && scope.querySelector
      ? scope.querySelector('.af-bb-anchor-target[data-af-anchor-key="' + cssEscape(key) + '"]')
      : null;

    if (inScope) return inScope;

    return document.querySelector('.af-bb-anchor-target[data-af-anchor-key="' + cssEscape(key) + '"]');
  }

  function smoothScrollToTarget(target) {
    if (!target || typeof target.scrollIntoView !== 'function') return false;

    try {
      target.scrollIntoView({ behavior: 'smooth', block: 'start', inline: 'nearest' });

      var id = asText(target.getAttribute('id')).trim();
      if (id) {
        try {
          if (window.history && typeof window.history.replaceState === 'function') {
            window.history.replaceState(null, '', '#' + id);
          }
        } catch (e0) {}
      }
      return true;
    } catch (e1) {
      return false;
    }
  }

  function bindAnchorLinks() {
    document.addEventListener('click', function (event) {
      var node = event.target;
      var link = node && node.closest ? node.closest('a.af-bb-anchor-link[data-af-anchor-target]') : null;
      if (!link) return;

      var target = resolveAnchorTarget(link);
      if (!target) return;

      event.preventDefault();
      ensureAnchorDomIds();
      smoothScrollToTarget(target);
    });
  }

  window.af_ae_anchor_exec = function (editor) { insertAnchor(editor); };
  window.af_ae_anchorlink_exec = function (editor) { insertAnchorLink(editor); };

  window.afAeBuiltinHandlers.anchor = insertAnchor;
  window.afAeBuiltinHandlers.af_anchor = insertAnchor;
  window.afAeBuiltinHandlers.anchorlink = insertAnchorLink;
  window.afAeBuiltinHandlers.af_anchorlink = insertAnchorLink;

  window.afAqrBuiltinHandlers.anchor = insertAnchor;
  window.afAqrBuiltinHandlers.af_anchor = insertAnchor;
  window.afAqrBuiltinHandlers.anchorlink = insertAnchorLink;
  window.afAqrBuiltinHandlers.af_anchorlink = insertAnchorLink;

  ensureBbcodeDefs();
  registerCommands();
  ensureAnchorDomIds();
  bindAnchorLinks();

  setTimeout(function () {
    ensureBbcodeDefs();
    registerCommands();
    ensureAnchorDomIds();
  }, 300);
})(window, document);

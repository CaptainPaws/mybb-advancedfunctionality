(function(){
  'use strict';
  if (window.__afAeWysiwygBbcodesLoaded) return;
  window.__afAeWysiwygBbcodesLoaded = true;

  var P = window.afAePayload || window.afAdvancedEditorPayload || {};
  var EXCLUDED_CACHE = null;

  function hasSceditor(){ return !!(window.jQuery && jQuery.sceditor && jQuery.sceditor.plugins && jQuery.sceditor.plugins.bbcode); }
  function getBb(inst){
    try {
      var bb = jQuery.sceditor.plugins.bbcode.bbcode;
      if (bb && typeof bb.set === 'function') return bb;
    } catch(e) {}
    try {
      var p = inst && typeof inst.getPlugin === 'function' ? inst.getPlugin('bbcode') : null;
      if (p && p.bbcode && typeof p.bbcode.set === 'function') return p.bbcode;
    } catch(e2) {}
    return null;
  }

  function escHtml(s){
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function parseExcluded(){
    if (EXCLUDED_CACHE) return EXCLUDED_CACHE;
    var raw = '';
    try { raw = String(P && P.cfg && P.cfg.wysiwygExclude ? P.cfg.wysiwygExclude : ''); } catch(e) {}
    var m = Object.create(null);
    raw.split(/[\n,;\s]+/).forEach(function(x){
      x = String(x || '').toLowerCase().trim();
      if (x) m[x] = true;
    });
    if (m.lockcontent) m.hide = true;
    EXCLUDED_CACHE = m;
    return EXCLUDED_CACHE;
  }

  function isExcluded(tag){ return !!parseExcluded()[String(tag || '').toLowerCase()]; }

  function parseTagFromOpenClose(openTag, closeTag){
    openTag = String(openTag || '').trim();
    closeTag = String(closeTag || '').trim();
    if (!openTag) return null;

    var m = openTag.match(/^\[([a-z0-9_\-*]+)(?:=([^\]]*))?\]/i);
    if (!m) return null;

    var tag = String(m[1] || '').toLowerCase();
    var hasClose = !!closeTag;
    if (closeTag) {
      var cm = closeTag.match(/^\[\/([a-z0-9_\-*]+)\]/i);
      if (cm && String(cm[1] || '').toLowerCase() !== tag) hasClose = false;
    }

    return {
      tag: tag,
      hasClose: hasClose,
      openRaw: openTag,
      closeRaw: closeTag
    };
  }

  function collectTagDefs(){
    var map = Object.create(null);

    function addTag(tag, hasClose){
      tag = String(tag || '').toLowerCase().trim();
      if (!tag) return;
      if (!map[tag]) {
        map[tag] = { tag: tag, hasClose: hasClose !== false };
      } else if (hasClose === false) {
        map[tag].hasClose = false;
      }
    }

    try {
      var defs = Array.isArray(P.customDefs) ? P.customDefs : [];
      defs.forEach(function(def){
        if (!def) return;
        var parsed = parseTagFromOpenClose(def.opentag, def.closetag);
        if (parsed && parsed.tag) addTag(parsed.tag, parsed.hasClose);
      });
    } catch(e1) {}

    try {
      var packs = (P && P.packs && P.packs.packs) ? P.packs.packs : null;
      if (packs && typeof packs === 'object') {
        Object.keys(packs).forEach(function(k){
          var p = packs[k];
          if (!p || !Array.isArray(p.tags)) return;
          p.tags.forEach(function(tag){ addTag(tag, true); });
        });
      }
    } catch(e2) {}

    try {
      var available = Array.isArray(P.available) ? P.available : [];
      available.forEach(function(item){
        if (!item || !item.cmd) return;
        var cmd = String(item.cmd || '').toLowerCase().trim();
        if (!cmd || cmd === '|' || /^af_/.test(cmd) || /^af_menu_dropdown\d+$/i.test(cmd)) return;
        if (/^[a-z][a-z0-9_\-*]*$/.test(cmd)) addTag(cmd, true);
      });
    } catch(e3) {}

    return map;
  }

  function collectAttrs(attrs){
    var out = {};
    if (!attrs || typeof attrs !== 'object') return out;
    Object.keys(attrs).forEach(function(k){
      if (!Object.prototype.hasOwnProperty.call(attrs, k)) return;
      var v = attrs[k];
      if (v == null || typeof v === 'undefined') return;
      out[String(k)] = String(v);
    });
    return out;
  }

  function buildAttrString(attrs){
    if (!attrs || typeof attrs !== 'object') return '';
    var parts = [];
    Object.keys(attrs).forEach(function(k){
      var v = String(attrs[k] == null ? '' : attrs[k]);
      if (k === 'defaultattr') {
        if (v !== '') parts.push('=' + v);
      } else if (v !== '') {
        parts.push(' ' + k + '="' + v.replace(/"/g, '&quot;') + '"');
      } else {
        parts.push(' ' + k);
      }
    });
    return parts.join('');
  }

  function createUniversalDef(tag, hasClose){
    var isInline = /^(font|size|color|url|email|img|sup|sub|b|i|u|s|strong|em|span)$/i.test(tag);

    return {
      isInline: !!isInline,
      html: function(token, attrs, content){
        var a = collectAttrs(attrs);
        var jsonAttrs = '{}';
        try { jsonAttrs = JSON.stringify(a); } catch(e1) {}

        var styles = '';
        var cls = 'af-ae-bb-node af-ae-bb-' + escHtml(tag);
        if (tag === 'align') {
          var align = String((a && a.defaultattr) || '').toLowerCase().trim();
          if (align === 'start') align = 'left';
          if (align === 'end') align = 'right';
          if (align === 'left' || align === 'right' || align === 'center' || align === 'justify') {
            styles = ' style="text-align:' + escHtml(align) + ';"';
          }
        }

        var attr = '';
        if (a.defaultattr != null && String(a.defaultattr) !== '') {
          attr = ' data-af-bb-attr="' + escHtml(a.defaultattr) + '"';
        }

        var tagName = isInline ? 'span' : 'div';
        return '<' + tagName + ' class="' + cls + '" data-af-bb="' + escHtml(tag) + '"' + attr +
          ' data-af-bb-attrs="' + escHtml(jsonAttrs) + '"' + styles + '>' + (content || '') + '</' + tagName + '>';
      },
      format: function(el, content){
        var attrs = {};
        try {
          var raw = String(el && el.getAttribute ? (el.getAttribute('data-af-bb-attrs') || '') : '');
          if (raw) attrs = JSON.parse(raw) || {};
        } catch(e1) { attrs = {}; }

        if (!attrs || typeof attrs !== 'object') attrs = {};

        if (typeof attrs.defaultattr === 'undefined') {
          try {
            var fallbackAttr = String(el && el.getAttribute ? (el.getAttribute('data-af-bb-attr') || '') : '');
            if (fallbackAttr !== '') attrs.defaultattr = fallbackAttr;
          } catch(e2) {}
        }

        var open = '[' + tag + buildAttrString(attrs) + ']';
        if (hasClose === false) return open;
        return open + (content || '') + '[/' + tag + ']';
      }
    };
  }

  function createPassthroughDef(tag, hasClose){
    return {
      isInline: true,
      html: function(token, attrs, content){
        var a = collectAttrs(attrs);
        var open = '[' + tag + buildAttrString(a) + ']';
        var close = hasClose === false ? '' : ('[/' + tag + ']');
        return escHtml(open + (content || '') + close);
      },
      format: function(el, content){
        var txt = '';
        try { txt = String(el && el.textContent ? el.textContent : ''); } catch(e) {}
        if (txt) return txt;
        return '[' + tag + ']' + (content || '') + (hasClose === false ? '' : ('[/' + tag + ']'));
      }
    };
  }

  function register(inst){
    if (!hasSceditor()) return;
    var bb = getBb(inst);
    if (!bb || bb.__afAeUniversalWysiwygPatched) return;
    bb.__afAeUniversalWysiwygPatched = true;

    var tags = collectTagDefs();
    Object.keys(tags).forEach(function(tag){
      var def = tags[tag];
      try {
        bb.set(tag, isExcluded(tag) ? createPassthroughDef(tag, def.hasClose) : createUniversalDef(tag, def.hasClose));
      } catch(e) {}
    });
  }

  function forceRender(inst){
    try {
      if (!inst || typeof inst.val !== 'function') return;
      var v = inst.val();
      inst.val(String(v == null ? '' : v));
    } catch(e) {}
  }

  function injectCss(inst){
    try {
      var body = inst && typeof inst.getBody === 'function' ? inst.getBody() : null;
      var doc = body ? body.ownerDocument : null;
      if (!doc || doc.getElementById('af-ae-pack-css')) return;

      var style = doc.createElement('style');
      style.id = 'af-ae-pack-css';
      style.appendChild(doc.createTextNode('.af-ae-bb-node[data-af-bb]{display:block}.af-ae-bb-node[data-af-bb].af-ae-bb-font,.af-ae-bb-node[data-af-bb].af-ae-bb-size,.af-ae-bb-node[data-af-bb].af-ae-bb-color{display:inline}'));
      (doc.head || doc.documentElement).appendChild(style);

      var urls = [];
      try { urls = (P && P.packs && Array.isArray(P.packs.css)) ? P.packs.css.slice() : []; } catch(e2) {}
      if (!urls.length || typeof window.fetch !== 'function' || doc.getElementById('af-ae-pack-css-inline')) return;

      Promise.all(urls.map(function(u){
        return fetch(String(u), { credentials: 'same-origin' })
          .then(function(r){ return r.ok ? r.text() : ''; })
          .catch(function(){ return ''; });
      })).then(function(parts){
        if (!parts || !parts.length) return;
        var cssText = parts.join('\n').trim();
        if (!cssText) return;
        var st2 = doc.createElement('style');
        st2.id = 'af-ae-pack-css-inline';
        st2.appendChild(doc.createTextNode(cssText));
        (doc.head || doc.documentElement).appendChild(st2);
      });
    } catch(e) {}
  }

  window.afAeWysiwygBbcodes = {
    init: function(inst){ register(inst || null); if (inst) { injectCss(inst); forceRender(inst); } },
    applyInstance: function(inst){ register(inst || null); injectCss(inst); forceRender(inst); }
  };
})();

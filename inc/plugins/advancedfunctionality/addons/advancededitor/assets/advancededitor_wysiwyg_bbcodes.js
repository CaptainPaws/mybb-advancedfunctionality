(function(){
  'use strict';
  if (window.__afAeWysiwygBbcodesLoaded) return;
  window.__afAeWysiwygBbcodesLoaded = true;

  var P = window.afAePayload || window.afAdvancedEditorPayload || {};

  function hasSceditor(){ return !!(window.jQuery && jQuery.sceditor && jQuery.sceditor.plugins && jQuery.sceditor.plugins.bbcode); }
  function getBb(inst){
    try {
      var bb = jQuery.sceditor.plugins.bbcode.bbcode;
      if (bb && typeof bb.set === 'function') return bb;
    } catch(e){}
    try {
      var p = inst && typeof inst.getPlugin === 'function' ? inst.getPlugin('bbcode') : null;
      if (p && p.bbcode && typeof p.bbcode.set === 'function') return p.bbcode;
    } catch(e2){}
    return null;
  }
  function esc(s){ return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function getAttr(attrs, k){
    try { if (attrs && attrs[k] != null) return String(attrs[k]); } catch(e){}
    return '';
  }
  function parseExcluded(){
    var raw = '';
    try { raw = String(P && P.cfg && P.cfg.wysiwygExclude ? P.cfg.wysiwygExclude : ''); } catch(e){}
    var m = Object.create(null);
    raw.split(/[\n,;\s]+/).forEach(function(x){ x = String(x||'').toLowerCase().trim(); if (x) m[x] = true; });
    return m;
  }
  function isExcluded(tag){
    var ex = parseExcluded();
    if (ex[tag]) return true;
    if (tag === 'hide' && ex.lockcontent) return true;
    return false;
  }

  function blockWrap(tag, content, attr){
    var at = attr ? ' data-af-bb-attr="' + esc(attr) + '"' : '';
    return '<div class="af-ae-bb-block af-ae-bb-'+esc(tag)+'" data-af-bb="'+esc(tag)+'"'+at+'>' + (content || '') + '</div>';
  }

  function makeSimple(tag, inline){
    return {
      isInline: !!inline,
      html: function(token, attrs, content){
        var a = getAttr(attrs, 'defaultattr');
        return inline
          ? '<span class="af-ae-bb-inline af-ae-bb-'+esc(tag)+'" data-af-bb="'+esc(tag)+'"'+(a?' data-af-bb-attr="'+esc(a)+'"':'')+'>' + (content||'') + '</span>'
          : blockWrap(tag, content, a);
      },
      format: function(el, content){
        var a = '';
        try { a = String(el.getAttribute('data-af-bb-attr') || ''); } catch(e){}
        return '[' + tag + (a ? '=' + a : '') + ']' + (content || '') + '[/' + tag + ']';
      }
    };
  }

  function register(inst){
    if (!hasSceditor()) return;
    var bb = getBb(inst);
    if (!bb || bb.__afAeUniversalWysiwygPatched) return;
    bb.__afAeUniversalWysiwygPatched = true;

    if (!isExcluded('table')) bb.set('table', makeSimple('table', false));
    if (!isExcluded('tr')) bb.set('tr', makeSimple('tr', false));
    if (!isExcluded('td')) bb.set('td', makeSimple('td', false));
    if (!isExcluded('th')) bb.set('th', makeSimple('th', false));

    if (!isExcluded('float')) {
      bb.set('float', {
        isInline: false,
        html: function(token, attrs, content){
          var side = getAttr(attrs, 'defaultattr').toLowerCase();
          if (side !== 'left' && side !== 'right') side = 'left';
          return '<div data-af-bb="float" data-af-bb-attr="'+esc(side)+'" class="af-ae-bb-float" style="float:'+esc(side)+';max-width:50%;">'+(content||'')+'</div><div style="clear:both"></div>';
        },
        format: function(el, content){
          var a = 'left';
          try { a = String(el.getAttribute('data-af-bb-attr') || 'left'); } catch(e){}
          return '[float=' + a + ']' + (content || '') + '[/float]';
        }
      });
    }

    if (!isExcluded('spoiler')) {
      bb.set('spoiler', {
        isInline: false,
        html: function(token, attrs, content){
          var title = getAttr(attrs, 'defaultattr') || 'Спойлер';
          return '<div data-af-bb="spoiler" data-af-bb-attr="'+esc(title)+'" class="af-ae-bb-spoiler"><div class="af-ae-bb-spoiler-title">'+esc(title)+'</div><div class="af-ae-bb-spoiler-body">'+(content||'')+'</div></div>';
        },
        format: function(el, content){
          var a = '';
          try { a = String(el.getAttribute('data-af-bb-attr') || ''); } catch(e){}
          return '[spoiler' + (a ? '="' + a.replace(/"/g, '&quot;') + '"' : '') + ']' + (content || '') + '[/spoiler]';
        }
      });
    }

    if (!isExcluded('hide')) bb.set('hide', makeSimple('hide', false));
    if (!isExcluded('tquote')) bb.set('tquote', makeSimple('tquote', false));
    if (!isExcluded('ul')) bb.set('ul', makeSimple('ul', false));
    if (!isExcluded('li')) bb.set('li', makeSimple('li', false));
    if (!isExcluded('font')) bb.set('font', makeSimple('font', true));
    if (!isExcluded('size')) bb.set('size', makeSimple('size', true));
  }

  function injectCss(inst){
    try {
      var body = inst && typeof inst.getBody === 'function' ? inst.getBody() : null;
      var doc = body ? body.ownerDocument : null;
      if (!doc || doc.getElementById('af-ae-pack-css')) return;
      var style = doc.createElement('style');
      style.id = 'af-ae-pack-css';
      style.appendChild(doc.createTextNode('.af-ae-bb-block[data-af-bb]{display:block;border:1px dashed #999;padding:6px;margin:4px 0}.af-ae-bb-spoiler-title{font-weight:700;margin-bottom:4px}.af-ae-bb-float{border:1px dashed #777;padding:4px;margin:2px 8px 4px 0;}'));
      (doc.head || doc.documentElement).appendChild(style);

      var urls = [];
      try { urls = (P && P.packs && Array.isArray(P.packs.css)) ? P.packs.css.slice() : []; } catch(e2) {}
      if (!urls.length || typeof window.fetch !== 'function') return;

      Promise.all(urls.map(function(u){ return fetch(String(u), { credentials: 'same-origin' }).then(function(r){ return r.ok ? r.text() : ''; }).catch(function(){ return ''; }); }))
        .then(function(parts){
          if (!parts || !parts.length) return;
          var st2 = doc.createElement('style');
          st2.id = 'af-ae-pack-css-inline';
          st2.appendChild(doc.createTextNode(parts.join('\n')));
          (doc.head || doc.documentElement).appendChild(st2);
        });
    } catch(e){}
  }

  window.afAeWysiwygBbcodes = {
    init: function(inst){ register(inst || null); if (inst) injectCss(inst); },
    applyInstance: function(inst){ injectCss(inst); }
  };
})();

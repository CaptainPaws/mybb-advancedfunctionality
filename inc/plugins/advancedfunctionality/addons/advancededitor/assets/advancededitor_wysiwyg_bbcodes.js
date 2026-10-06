(function () {
'use strict';

if (window.__afAeWysiwygBbcodesLoaded) return;
window.__afAeWysiwygBbcodesLoaded = true;

var P = window.afAePayload || window.afAdvancedEditorPayload || {};

var EXCLUDED_CACHE = null;
var PARTIAL_CACHE = null;

/* ------------------------------------------------ */
function hasSceditor(){
  return !!(getBb(null));
}

function getBb(inst){
  var sc = window.sceditor || (window.jQuery && jQuery.sceditor);
  if (sc && sc.formats && sc.formats.bbcode) return sc.formats.bbcode;
  try{
    var p = inst && typeof inst.getPlugin === 'function' ? inst.getPlugin('bbcode') : null;
    if (p && p.bbcode && typeof p.bbcode.set === 'function') return p.bbcode;
  }catch(e){}
  try {
    var bb = jQuery.sceditor.plugins.bbcode.bbcode;
    if (bb && typeof bb.set === 'function') return bb;
  } catch(e){}
  return null;
}

/* ------------------------------------------------ */

function escHtml(s){
  return String(s == null ? '' : s)
    .replace(/&/g,'&amp;')
    .replace(/"/g,'&quot;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;');
}

function escBbAttr(s){
  return String(s == null ? '' : s)
    .replace(/&/g,'&amp;')
    .replace(/"/g,'&quot;')
    .replace(/\]/g,'&#93;')
    .trim();
}

/* ------------------------------------------------ */
/* PARTIAL MODE */

function getWysiwygMode(){
  try{
    var mode = String(P && P.cfg && P.cfg.wysiwygMode ? P.cfg.wysiwygMode : '').toLowerCase().trim();
    if(mode === 'full' || mode === 'partial') return mode;
  }catch(e){}
  return 'partial';
}

function isPartialMode(){
  return getWysiwygMode() === 'partial';
}

function whitelist(){
  if(PARTIAL_CACHE) return PARTIAL_CACHE;

  var raw='';
  try{ raw = String(P && P.cfg && P.cfg.wysiwygWhitelist ? P.cfg.wysiwygWhitelist : ''); }catch(e){}

  var m=Object.create(null);

  raw.split(/[\n,;\s]+/).forEach(function(x){
    x = String(x||'').toLowerCase().trim();
    if(x) m[x]=1;
  });

  if(!Object.keys(m).length){
    m = {
      b:1,i:1,u:1,s:1,
      font:1,size:1,color:1,
      url:1,email:1,
      align:1,
      mark:1,
      ul:1,ol:1,li:1
    };
  }

  m.ul = 1;
  m.ol = 1;
  m.li = 1;

  PARTIAL_CACHE = m;
  return PARTIAL_CACHE;
}

function isWhitelist(tag){
  return !!whitelist()[String(tag||'').toLowerCase()];
}

/* ------------------------------------------------ */
/* EXCLUDED */

function parseExcluded(){
  if(EXCLUDED_CACHE) return EXCLUDED_CACHE;

  var raw='';
  try{ raw = String(P && P.cfg && P.cfg.wysiwygExclude ? P.cfg.wysiwygExclude : ''); }catch(e){}

  var m=Object.create(null);

  raw.split(/[\n,;\s]+/).forEach(function(x){
    x = String(x||'').toLowerCase().trim();
    if(x) m[x]=true;
  });

  if(m.lockcontent) m.hide=true;

  EXCLUDED_CACHE = m;
  return EXCLUDED_CACHE;
}

function isExcluded(tag){
  return !!parseExcluded()[String(tag||'').toLowerCase()];
}

/* ------------------------------------------------ */
/* TAG COLLECTION */

function parseTagFromOpenClose(openTag, closeTag){

  openTag = String(openTag||'').trim();
  closeTag = String(closeTag||'').trim();

  if(!openTag) return null;

  var m = openTag.match(/^\[([a-z0-9_\-*]+)(?:=([^\]]*))?\]/i);
  if(!m) return null;

  var tag = String(m[1]||'').toLowerCase();

  var hasClose = !!closeTag;

  if(closeTag){
    var cm = closeTag.match(/^\[\/([a-z0-9_\-*]+)\]/i);
    if(cm && String(cm[1]||'').toLowerCase() !== tag) hasClose=false;
  }

  return {
    tag:tag,
    hasClose:hasClose
  };
}

function collectTagDefs(){

  var map = Object.create(null);

  function addTag(tag, hasClose){

    tag = String(tag||'').toLowerCase().trim();

    if(!tag) return;

    if(!map[tag]){
      map[tag]={tag:tag,hasClose:hasClose!==false};
    }else if(hasClose===false){
      map[tag].hasClose=false;
    }

  }

  try{
    var defs = Array.isArray(P.customDefs) ? P.customDefs : [];
    defs.forEach(function(def){

      if(!def) return;

      var parsed = parseTagFromOpenClose(def.opentag,def.closetag);

      if(parsed && parsed.tag) addTag(parsed.tag,parsed.hasClose);

    });
  }catch(e){}

  try{

    var packs = (P && P.packs && P.packs.packs)?P.packs.packs:null;

    if(packs && typeof packs==='object'){
      Object.keys(packs).forEach(function(k){

        var p = packs[k];

        if(!p || !Array.isArray(p.tags)) return;

        p.tags.forEach(function(tag){ addTag(tag,true); });

      });
    }

  }catch(e){}

  try{

    var available = Array.isArray(P.available)?P.available:[];

    available.forEach(function(item){

      if(!item || !item.cmd) return;

      var cmd = String(item.cmd||'').toLowerCase().trim();

      if(!cmd) return;

      if(cmd==='|') return;
      if(/^af_/.test(cmd)) return;

      if(/^[a-z][a-z0-9_\-*]*$/.test(cmd)) addTag(cmd,true);

    });

  }catch(e){}

  return map;

}

/* ------------------------------------------------ */

function collectAttrs(attrs){

  var out={};

  if(!attrs || typeof attrs!=='object') return out;

  Object.keys(attrs).forEach(function(k){

    if(!Object.prototype.hasOwnProperty.call(attrs,k)) return;

    var v = attrs[k];

    if(v==null) return;

    out[String(k)] = String(v);

  });

  return out;

}

function buildAttrString(attrs){

  if(!attrs || typeof attrs!=='object') return '';

  var parts=[];

  Object.keys(attrs).forEach(function(k){

    var v = String(attrs[k]==null?'':attrs[k]);

    if(k==='defaultattr'){

      parts.unshift('="'+v.replace(/\\/g,'\\\\').replace(/"/g,'\\"')+'"');

    }else{

      if(v!=='') parts.push(' '+k+'="'+v.replace(/"/g,'&quot;')+'"');

    }

  });

  return parts.join('');

}

/* ------------------------------------------------ */
/* UNIVERSAL RENDER */

function createUniversalDef(tag, hasClose){

  var inline=/^(font|size|color|url|email|img|sup|sub|b|i|u|s|strong|em|span)$/i.test(tag);

  return{

    tags: (function () { var tags = {}; tags[inline ? 'span' : 'div'] = { 'data-af-bb': [tag] }; return tags; })(),
    isInline:inline,

    html:function(token,attrs,content){

      var a=collectAttrs(attrs);

      var json='{}';
      try{ json=JSON.stringify(a); }catch(e){}

      var cls='af-ae-bb-node af-ae-bb-'+escHtml(tag);

      var attr='';
      if(a.defaultattr){
        attr=' data-af-bb-attr="'+escHtml(a.defaultattr)+'"';
      }

      var tagName = inline?'span':'div';

      return '<'+tagName+
        ' class="'+cls+'"'+
        ' data-af-bb="'+escHtml(tag)+'"'+
        attr+
        ' data-af-bb-attrs="'+escHtml(json)+'">'+
        (content||'')+
        '</'+tagName+'>';

    },

    format:function(el,content){

      var attrs={};

      try{

        var raw=String(el.getAttribute('data-af-bb-attrs')||'');

        if(raw) attrs=JSON.parse(raw)||{};

      }catch(e){}

      if(typeof attrs.defaultattr==='undefined'){

        var f=String(el.getAttribute('data-af-bb-attr')||'');

        if(f!=='') attrs.defaultattr=f;

      }

      var open='['+tag+buildAttrString(attrs)+']';

      if(hasClose===false) return open;

      return open+(content||'')+'[/'+tag+']';

    }

  };

}

/* ------------------------------------------------ */

function createPassthroughDef(tag,hasClose){

  return{

    tags: { span: { 'data-af-bb': [tag] } },
    isInline:true,

    html:function(token,attrs,content){

      var a=collectAttrs(attrs);

      var open='['+tag+buildAttrString(a)+']';

      var close=hasClose?'[/'+tag+']':'';

      return '<span data-af-bb="'+escHtml(tag)+'" data-af-bb-attrs="'+escHtml(JSON.stringify(a))+'">'+escHtml(open)+(content||'')+escHtml(close)+'</span>';

    },

    format:function(el,content){

      var txt='';

      try{ txt=String(el.textContent||''); }catch(e){}

      if(txt) return txt;

      return '['+tag+']'+(content||'')+(hasClose?'[/'+tag+']':'');

    }

  };

}

function createAbbrDef(){
  return {
    isInline:true,

    html:function(token,attrs,content){
      var a=collectAttrs(attrs);
      var tip=String(a.defaultattr == null ? '' : a.defaultattr);

      return '<span class="af-ae-abbr" data-af-bb="abbr" data-af-abbr-title="'+escHtml(tip)+'" title="'+escHtml(tip)+'">'+(content||'')+'</span>';
    },

    format:function(el,content){
      var tip='';
      try{ tip=String(el.getAttribute('data-af-abbr-title')||''); }catch(e0){}
      if(!tip){
        try{ tip=String(el.getAttribute('title')||''); }catch(e1){}
      }

      return '[abbr="'+escBbAttr(tip)+'"]'+(content||'')+'[/abbr]';
    }
  };
}

function isStructuralTableTag(tag){
  tag = String(tag||'').toLowerCase().trim();
  return tag==='table' || tag==='tr' || tag==='td' || tag==='th' ||
    tag==='af_table' || tag==='af_tr' || tag==='af_td' || tag==='af_th';
}

function hasCustomWysiwygRenderer(tag){
  tag = String(tag||'').toLowerCase().trim();
  if (!tag) return false;

  try {
    var map = window.afAeWysiwygCustomTags;
    if (map && typeof map === 'object' && map[tag]) return true;
  } catch (e) {}

  return false;
}

/* ------------------------------------------------ */
/* REGISTER */
// SCEditor 3 uses formats.bbcode; older releases expose plugins.bbcode.
function installMybbQuote(bb) {
  bb.set('quote', {
    tags: { blockquote: null }, isInline: false, quoteType: 1,
    html: function (token, attrs, content) {
      var a = collectAttrs(attrs);
      // Empty defaultattr is legal for a MyBB post quote. Undefined is not.
      if (attrs && Object.prototype.hasOwnProperty.call(attrs, 'defaultattr') && attrs.defaultattr == null) a.defaultattr = '';
      var author = a.defaultattr || '';
      var post = /^\d+$/.test(a.pid || '') && document.getElementById('post_' + a.pid);
      var link = post && post.querySelector('.atf-post__author a[href*="uid="], .atf-post__username a, .author_information a[href*="uid="], .post_author a[href*="uid="], a[href*="member.php"][href*="uid="]');
      if (!author && link) author = link.textContent.trim();
      // The displayed name is separate from the stored BBCode attributes.
      return '<blockquote class="mycode_quote" data-af-quote-attrs="' + escHtml(JSON.stringify(a)) + '"' +
        (a.pid ? ' data-pid="' + escHtml(a.pid) + '"' : '') + ' data-author="' + escHtml(author) + '">' +
        '<cite class="sceditor-ignore" contenteditable="false">' + escHtml(author || 'Цитата') + '</cite>' + (content || '') + '</blockquote>';
    },
    format: function (element, content) {
      var attrs = {}, raw = element.getAttribute('data-af-quote-attrs');
      if (raw) { try { attrs = collectAttrs(JSON.parse(raw)); } catch (e) {} }
      else {
        var author = element.getAttribute('data-author');
        var cite = element.querySelector(':scope > cite');
        if (author || cite) attrs.defaultattr = author || cite.textContent;
        var pid = element.getAttribute('data-pid');
        if (pid) attrs.pid = pid;
      }
      // Native quotes inserted by execCommand may have an editable cite.
      // Exclude the header structurally, without re-running alignment on the
      // entire blockquote (the native formatter did exactly that).
      var header = element.querySelector(':scope > cite:not(.sceditor-ignore)');
      if (header && this.elementToBbcode) {
        header.classList.add('sceditor-ignore');
        var clone = element.cloneNode(true);
        clone.querySelector(':scope > cite').remove();
        content = this.elementToBbcode(clone);
      }
      return '[quote' + buildAttrString(attrs) + ']' + (content || '') + '[/quote]';
    }
  });
}

// Patch the token boundary inside the addon, never MyBB's library. In 3.2.1
// parseAttrs uses `unquote(empty) || fallback`, producing undefined for ="".
// Its serializer then treats that defaultattr as a named attribute. Serialize
// the parsed structure with presence checks, retaining legitimate empty author.
function installTokenSerializer() {
  var sc = window.sceditor || (window.jQuery && jQuery.sceditor);
  if (!sc || !sc.BBCodeParser || sc.BBCodeParser.__afQuoteSafe) return;
  var NativeParser = sc.BBCodeParser;
  function Parser(options) {
    var parser = new NativeParser(options), tokenize = parser.tokenize;
    parser.tokenize = function (value) {
      var tokens = tokenize.call(this, value);
      tokens.forEach(function (token) {
        var attrs = token.attrs;
        if (!attrs) return;
        Object.keys(attrs).forEach(function (key) {
          if (attrs[key] == null) {
            if (key === 'defaultattr' && token.name === 'quote' && /^\[quote\s*=\s*(["'])\1(?:\s|\])/i.test(token.val)) attrs[key] = '';
            else delete attrs[key];
          }
        });
      });
      return tokens;
    };
    parser.toBBCode = function (value, preserveNewlines) {
      var opts = parser.opts;
      function attr(value, rule, name) {
        value = String(value);
        if (typeof rule === 'function') return rule(value, name);
        if (rule === 2 || (rule === 3 && !/\s|=/.test(value))) return value;
        return '"' + value.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"';
      }
      function render(tokens) {
        return tokens.map(function (token) {
          var def = sc.formats.bbcode.get(token.name);
          if (token.type !== 'open') return token.val;
          if (!def) return token.val + render(token.children || []) + (token.closing ? token.closing.val : '');
          var block = def.isInline === false, self = def.isSelfClosing;
          var rule = def.quoteType || opts.quoteType || 3;
          var out = ((block && opts.breakBeforeBlock && def.breakBefore !== false) || def.breakBefore) ? '\n' : '';
          out += '[' + token.name;
          var attrs = collectAttrs(token.attrs);
          if (Object.prototype.hasOwnProperty.call(attrs, 'defaultattr')) {
            out += '=' + attr(attrs.defaultattr, rule, 'defaultattr');
            delete attrs.defaultattr;
          }
          Object.keys(attrs).forEach(function (key) { out += ' ' + key + '=' + attr(attrs[key], rule, key); });
          out += ']';
          if ((block && !self && opts.breakStartBlock && def.breakStart !== false) || def.breakStart) out += '\n';
          out += render(token.children || []);
          if (!self && !def.excludeClosing) {
            if ((block && opts.breakEndBlock && def.breakEnd !== false) || def.breakEnd) out += '\n';
            out += '[/' + token.name + ']';
          }
          if ((block && opts.breakAfterBlock && def.breakAfter !== false) || def.breakAfter) out += '\n';
          if (self && token.closing) out += token.closing.val;
          return out;
        }).join('');
      }
      return render(parser.parse(value, preserveNewlines));
    };
    return parser;
  }
  Object.keys(NativeParser).forEach(function (key) { Parser[key] = NativeParser[key]; });
  Parser.prototype = NativeParser.prototype;
  Parser.__afQuoteSafe = true;
  sc.BBCodeParser = Parser;
  // The native format closes over NativeParser. Install its DOM converter
  // with the safe token serializer rather than cleaning its serialized text.
  var NativeFormat = sc.formats.bbcode;
  function Format() {
    var plugin = new NativeFormat(), init = plugin.init;
    plugin.init = function () {
      init.apply(this, arguments);
      function toSource(fragment, html, doc, parent) {
        doc = doc || document;
        var outer = doc.createElement('div'), root = doc.createElement('div');
        root.innerHTML = html;
        outer.style.visibility = 'hidden'; outer.appendChild(root); doc.body.appendChild(outer);
        if (fragment) { outer.prepend(doc.createTextNode('#')); outer.appendChild(doc.createTextNode('#')); }
        if (parent) root.style.whiteSpace = sc.dom.css(parent, 'whiteSpace');
        root.querySelectorAll('.sceditor-ignore').forEach(function (node) { node.remove(); });
        sc.dom.removeWhiteSpace(outer);
        var raw = plugin.elementToBbcode(root, !!(parent && sc.dom.closest(parent, 'code')));
        outer.remove();
        var value = new sc.BBCodeParser(plugin.opts.parserOptions).toBBCode(raw, true);
        return plugin.opts.bbcodeTrim ? value.trim() : value;
      }
      plugin.toSource = toSource.bind(null, false);
      plugin.fragmentToSource = toSource.bind(null, true);
      var editor = this;
      editor.toBBCode = plugin.toSource;
      editor.__afAeRefreshBbcodes = function () {
        var fresh = new NativeFormat();
        fresh.init.call(editor);
        plugin.opts = fresh.opts;
        plugin.elementToBbcode = fresh.elementToBbcode;
        plugin.toHtml = fresh.toHtml;
        plugin.fragmentToHtml = fresh.fragmentToHtml;
        editor.toBBCode = plugin.toSource;
      };
    };
    return plugin;
  }
  Object.keys(NativeFormat).forEach(function (key) { Format[key] = NativeFormat[key]; });
  Format.prototype = NativeFormat.prototype;
  sc.formats.bbcode = Format;
  document.addEventListener('af:capability-ready', function () {
    // Native SCEditor caches DOM/BBCode mappings during format.init. Rebuild
    // that cache when a lazy pack registers converters, keeping the instance.
    document.querySelectorAll('textarea').forEach(function (ta) {
      var editor = ta._sceditor;
      if (editor && editor.__afAeRefreshBbcodes) editor.__afAeRefreshBbcodes();
    });
  });
}


function register(inst){

  if(!hasSceditor()) return;

  var bb=getBb(inst);

  if(!bb || bb.__afAeUniversalWysiwygPatched) return;

  bb.__afAeUniversalWysiwygPatched=true;

  installTokenSerializer();
  installMybbQuote(bb);
  var tags=collectTagDefs();

  var partial=isPartialMode();

  Object.keys(tags).forEach(function(tag){
    if(isStructuralTableTag(tag)) return;
    // Never overwrite native semantic formats or dedicated pack converters.
    if(bb.get && bb.get(tag)) return;
    if(hasCustomWysiwygRenderer(tag)) return;

    var def=tags[tag];

    try{

      if(partial){

        if(tag === 'abbr'){
          bb.set(tag,createAbbrDef());
        }else if(isWhitelist(tag)){
          bb.set(tag,createUniversalDef(tag,def.hasClose));
        }else{
          bb.set(tag,createPassthroughDef(tag,def.hasClose));
        }

      }else{

        if(tag === 'abbr'){
          bb.set(tag,createAbbrDef());
        }else{
          bb.set(tag,
            isExcluded(tag)
            ? createPassthroughDef(tag,def.hasClose)
            : createUniversalDef(tag,def.hasClose)
          );
        }

      }

    }catch(e){}

  });

}

/* ------------------------------------------------ */
/* FORCE RENDER */

/* ------------------------------------------------ */
/* CSS */

function injectCss(inst){

  try{

    var body=inst.getBody();

    if(!body) return;

    var doc=body.ownerDocument;

    if(doc.getElementById('af-ae-pack-css')) return;

    var style=doc.createElement('style');

    style.id='af-ae-pack-css';

    style.appendChild(doc.createTextNode(
      '.af-ae-bb-node[data-af-bb]{display:block}'+
      '.af-ae-bb-node.af-ae-bb-font,.af-ae-bb-node.af-ae-bb-size,.af-ae-bb-node.af-ae-bb-color{display:inline}'+
      '.af-ae-abbr{cursor:help;text-decoration:underline dotted;text-underline-offset:2px;background:rgba(75,116,255,.08);border-radius:3px;padding:0 2px}'
    ));

    (doc.head||doc.documentElement).appendChild(style);

  }catch(e){}

}

/* ------------------------------------------------ */

window.afAeWysiwygBbcodes = {

  init:function(inst){

    register(inst);

    if(inst){
      injectCss(inst);
      // Definitions are registered before creation; a mode switch is not init.
    }

  },

  applyInstance:function(inst){

    register(inst);

    injectCss(inst);

    // No destroy/create or synthetic mode transitions.

  }

};

})();

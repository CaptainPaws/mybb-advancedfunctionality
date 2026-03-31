(function () {
'use strict';

if (window.__afAeWysiwygBbcodesLoaded) return;
window.__afAeWysiwygBbcodesLoaded = true;

var P = window.afAePayload || window.afAdvancedEditorPayload || {};

var EXCLUDED_CACHE = null;
var PARTIAL_CACHE = null;

/* ------------------------------------------------ */
function hasSceditor(){
  return !!(window.jQuery && jQuery.sceditor && jQuery.sceditor.plugins && jQuery.sceditor.plugins.bbcode);
}

function getBb(inst){
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

      if(v!=='') parts.push('='+v);

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

    isInline:true,

    html:function(token,attrs,content){

      var a=collectAttrs(attrs);

      var open='['+tag+buildAttrString(a)+']';

      var close=hasClose?'[/'+tag+']':'';

      return escHtml(open+(content||'')+close);

    },

    format:function(el,content){

      var txt='';

      try{ txt=String(el.textContent||''); }catch(e){}

      if(txt) return txt;

      return '['+tag+']'+(content||'')+(hasClose?'[/'+tag+']':'');

    }

  };

}

function isStructuralTableTag(tag){
  tag = String(tag||'').toLowerCase().trim();
  return tag==='table' || tag==='tr' || tag==='td' || tag==='th' ||
    tag==='af_table' || tag==='af_tr' || tag==='af_td' || tag==='af_th';
}

/* ------------------------------------------------ */
/* REGISTER */

function register(inst){

  if(!hasSceditor()) return;

  var bb=getBb(inst);

  if(!bb || bb.__afAeUniversalWysiwygPatched) return;

  bb.__afAeUniversalWysiwygPatched=true;

  var tags=collectTagDefs();

  var partial=isPartialMode();

  Object.keys(tags).forEach(function(tag){
    if(isStructuralTableTag(tag)) return;
    if(tag === 'align') return;

    var def=tags[tag];

    try{

      if(partial){

        if(isWhitelist(tag)){
          bb.set(tag,createUniversalDef(tag,def.hasClose));
        }else{
          bb.set(tag,createPassthroughDef(tag,def.hasClose));
        }

      }else{

        bb.set(tag,
          isExcluded(tag)
          ? createPassthroughDef(tag,def.hasClose)
          : createUniversalDef(tag,def.hasClose)
        );

      }

    }catch(e){}

  });

}

/* ------------------------------------------------ */
/* FORCE RENDER */

function forceRender(inst){

  try{

    if(!inst) return;

    if(typeof inst.sourceMode!=='function') return;

    var src=inst.sourceMode();

    inst.sourceMode(!src);
    inst.sourceMode(src);

  }catch(e){}

}

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
      '.af-ae-bb-node.af-ae-bb-font,.af-ae-bb-node.af-ae-bb-size,.af-ae-bb-node.af-ae-bb-color{display:inline}'
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
      forceRender(inst);
    }

  },

  applyInstance:function(inst){

    register(inst);

    injectCss(inst);

    forceRender(inst);

  }

};

})();

'use strict';
// Short API contract test against the shipped Gallery runtime; no browser/site simulation.
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
class Node {
  constructor() { this.attrs = {}; this.children = []; this.events = {}; this.style = {}; this.classes = new Set(); this.classList = {add:x=>this.classes.add(x),remove:x=>this.classes.delete(x),contains:x=>this.classes.has(x),toggle:()=>{}}; }
  appendChild(node) { this.children.push(node); }
  setAttribute(k,v) { this.attrs[k]=v; }
  getAttribute(k) { return this.attrs[k] || ''; }
  querySelector() { return null; }
  querySelectorAll() { return []; }
  addEventListener(type, fn) { this.events[type]=fn; }
  focus() { this.focused=true; }
  remove() { this.removed=true; }
}
const document = new Node(), body = new Node(), root = new Node();
let modal, urls=[], errors=[], fail=false;
document.body=body;document.documentElement=root;
document.createElement=()=>{ const node = new Node(); node.querySelector=selector=>selector==='#af-gallery-picker'?modal:null;return node; };
const window={AF_GalleryPickerConfig:{pickerUrl:'gallery.php?action=picker',dataUrl:'gallery.php?action=picker_data'}};
const context=vm.createContext({window,document,URL,location:{href:'https://example.org/usercp.php?action=editsig'},setInterval:()=>0,clearInterval:()=>{},setTimeout,clearTimeout,FormData,alert:()=>{throw Error('Picker ignored inline onError');},fetch:async url=>{urls.push(url);if(fail)throw Error('network failure');return {ok:true,text:async()=>'<div id="af-gallery-picker"></div>',json:async()=>[]};}});
vm.runInContext(fs.readFileSync('inc/plugins/advancedfunctionality/addons/advancedgallery/assets/advancedgallery.js','utf8'),context);
(async()=>{
  for (const purpose of ['signature_images','signature_background']) {
    modal=new Node();modal.attrs['data-initial-album']='-2';
    await window.AF_GalleryPicker.open(()=>{}, {purpose,onError:e=>errors.push(e.message)});
    assert.ok(body.children.includes(modal),'Modal was not appended');
    assert.ok(modal.classList.contains('is-open'),'Modal did not actually open');
    assert.equal(modal.attrs['aria-hidden'],'false');assert.ok(modal.focused);
    assert.ok(urls.some(url=>url.includes('action=picker&purpose='+purpose)),'Wrong endpoint/purpose');
    assert.ok(urls.some(url=>url.includes('action=picker_data&purpose='+purpose)),'Media request lost purpose');
  }
  fail=true;await window.AF_GalleryPicker.open(()=>{}, {purpose:'signature_images',onError:e=>errors.push(e.message)});
  assert.ok(errors.some(message=>message.includes('network failure')),'Open failure did not reach constructor');
  console.log('Gallery runtime passed: both signature purposes fetch and open the actual modal API, focus/ARIA and inline failure callback.');
})().catch(error=>{console.error(error);process.exitCode=1;});

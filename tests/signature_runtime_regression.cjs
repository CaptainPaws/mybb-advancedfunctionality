'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const base = 'inc/plugins/advancedfunctionality/addons/';
// Native disclosure enhancement: one listener, dynamic nodes, ARIA and focus.
let toggles = 0, onToggle, focused = false;
const attributes = {};
const summary = { setAttribute: (k,v) => { attributes[k] = v; }, focus: () => { focused = true; } };
const content = { contains: node => node === 'inside' };
const details = { open: false, matches: () => true, querySelector: selector => selector.includes('toggle') ? summary : content };
const document = { activeElement: null, querySelectorAll: () => [details], addEventListener: (type,fn) => { toggles++; onToggle = fn; } };
const context = vm.createContext({window:{}, document});
const source = fs.readFileSync(base+'adaptivethemeframework/assets/adaptivethemeframework.signature.js','utf8');
vm.runInContext(source,context); vm.runInContext(source,context);
assert.equal(toggles,1); assert.equal(attributes['aria-expanded'],'false');
details.open = true; onToggle({target:details}); assert.equal(attributes['aria-expanded'],'true');
details.open = false; document.activeElement = 'inside'; onToggle({target:details}); assert.ok(focused);
const appended = {...details,open:true}; onToggle({target:appended}); assert.equal(attributes['aria-expanded'],'true');
// APStudio keeps one browsing context and does not request an editor before opening.
let disclosureHandler, loads = 0, loadHandler, sourceUrl, observed, submit;
const textarea = {value:'draft',form:{addEventListener: (name,fn) => { submit = fn; }}};
const main = {querySelector: selector => selector.startsWith('textarea') ? textarea : null, getBoundingClientRect: () => ({height:400})};
const frame = {dataset:{afSignatureSrc:'/usercp.php?action=editsig&af_apui_signature_embed=1'},style:{},contentDocument:{querySelector: () => main},
  hasAttribute: () => loads > 0, addEventListener: (name,fn) => { loadHandler = fn; }};
Object.defineProperty(frame,'src',{set(value){ loads++; sourceUrl=value; }});
const block = {open:false,querySelector: () => frame,addEventListener: (name,fn) => { disclosureHandler=fn; }};
const studio = vm.createContext({window:{ResizeObserver:true},ResizeObserver:class {observe(node){observed=node;} disconnect(){}},
  document:{querySelectorAll: () => [block]}});
const studioSource = fs.readFileSync(base+'advancedprofileui/assets/signature-editor.js','utf8');
vm.runInContext(studioSource,studio); vm.runInContext(studioSource,studio);
assert.equal(loads,0);
block.open=true; disclosureHandler(); assert.equal(loads,1); assert.equal(sourceUrl,frame.dataset.afSignatureSrc);
block.open=false; disclosureHandler(); block.open=true; disclosureHandler(); assert.equal(loads,1);
loadHandler(); assert.equal(frame.style.height,'420px'); assert.equal(observed,main); assert.equal(typeof submit,'function');
console.log('Signature runtime passed: delegated disclosure, ARIA/focus, dynamic posts, lazy iframe and one editor context across reopen.');

// Focused DOM test, not browser E2E. Run with jsdom available in NODE_PATH.
const {JSDOM,VirtualConsole}=require('jsdom');const fs=require('fs');const assert=require('assert');const path=require('path');
function editor(type,rules) {
  const vc=new VirtualConsole();vc.sendTo(console);
  const dom=new JSDOM('<form><div id="af-kb-rules-ui"></div><textarea id="af-kb-rules-json-raw"></textarea><textarea id="af-kb-meta-json">{}</textarea></form>',{url:'https://test.invalid/',runScripts:'outside-only',virtualConsole:vc});
  const w=dom.window,d=w.document,root=d.getElementById('af-kb-rules-ui');root.dataset.type=type;root.dataset.mechanic='arpg';root.dataset.typeSchema=JSON.stringify({root_defaults:{rules:{type_profile:type.replace('arpg_','')}}});
  d.getElementById('af-kb-rules-json-raw').value=JSON.stringify({mechanic:'arpg',rules});
  w.eval(fs.readFileSync(path.join(__dirname,'../inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase.js'),'utf8'));d.dispatchEvent(new w.Event('DOMContentLoaded'));
  return {dom,w,d,data:()=>JSON.parse(d.getElementById('af-kb-rules-json-raw').value).rules};
}
const e=editor('arpg_ability',{type_profile:'ability',damage_type:'physical'});
const titles={resources:'Ресурсы',effects:'Эффекты',modifiers:'Модификаторы',triggers:'Срабатывания',conditions:'Условия',stacking:'Накопление эффектов',upgrade_requirements:'Требования улучшения',grants:'Предоставляемые возможности'};
for(const [key,title] of Object.entries(titles)) {
 const section=[...e.d.querySelectorAll('.af-kb-kvlist')].find(n=>n.querySelector('h4')?.textContent===title);assert(section, title);
 const add=section.querySelector('button.af-kb-add');add.click();const card=section.querySelector('.af-kb-block-item');assert(card && card.querySelector('input,select'),key+' card missing');
 const input=card.querySelector('input');if(input){input.value=input.type==='number'?'7':'Проверка';input.dispatchEvent(new e.w.Event('input',{bubbles:true}));}
 add.click();assert.strictEqual(section.querySelector('.af-kb-block-item'),card,'Add replaced existing fields');
 section.querySelectorAll('.af-kb-block-item')[1].querySelector('button.af-kb-remove').click();assert.equal(e.data()[key].length,1,key+' delete/serialization');
}
const saved=e.data();e.dom.window.close();
const reopened=editor('arpg_ability',saved);for(const key of Object.keys(titles))assert.deepEqual(reopened.data()[key],saved[key],key+' lost on reopening');reopened.dom.window.close();
const nested=editor('arpg_bestiary',{type_profile:'bestiary',ability_keys:[{ability_name:'Inline',effects:[],resources:[],modifiers:[],triggers:[],conditions:[],stacking:[],upgrade_requirements:[],grants:[]}]});
const card=nested.d.querySelector('#af-kb-arpg-rules .af-kb-block-item h4')?.parentElement;
const nestedResource=[...nested.d.querySelectorAll('.af-kb-rule-card')].find(n=>n.querySelector('strong')?.textContent==='Ресурсы');assert(nestedResource,'Nested resources missing');nestedResource.querySelector('button.af-kb-add').click();assert.equal(nested.data().ability_keys[0].resources.length,1,'Nested add failed');nestedResource.querySelector('button.af-kb-remove').click();assert.equal(nested.data().ability_keys[0].resources.length,0,'Nested remove failed');assert.equal(nested.data().ability_keys.length,1,'Nested remove deleted its parent');nested.dom.window.close();
if(process.argv[2])fs.writeFileSync(process.argv[2],JSON.stringify(saved));console.log('Eight repeaters, stable DOM, remove/reopen and nested isolation: OK');

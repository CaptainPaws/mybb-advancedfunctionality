// Local DOM only. Requires jsdom in NODE_PATH, no browser or production access.
const {JSDOM,VirtualConsole}=require('jsdom');const fs=require('fs');const path=require('path');const assert=require('assert');
const keys='fire water air earth ice lightning nature light shadow death life chaos mind abyss gravity astral space'.split(' ');
function editor(rules){
 const errors=[];const vc=new VirtualConsole();vc.on('error',(...args)=>errors.push(args.join(' ')));vc.on('jsdomError',error=>errors.push(error.message));
 const dom=new JSDOM('<html lang="ru"><body><form><div id="af-kb-rules-ui" data-type="arpg_bestiary" data-mechanic="arpg"></div><textarea id="af-kb-rules-json-raw"></textarea><textarea id="af-kb-meta-json">{}</textarea></form>',{url:'https://test.invalid/',runScripts:'outside-only',virtualConsole:vc});
 const w=dom.window,d=w.document;
 d.getElementById('af-kb-rules-ui').dataset.typeSchema=JSON.stringify({primary_element_options:keys.map(value=>({value,label:value})),editor_choices:{arpg_ability:[{value:'blood_bolt',label:'Кровавый шип',type:'active',description:'Явный стихийный урон'}],magic_direction:[{value:'blood',label:'Кровь · Жизнь'}],formula_def:[{value:'test_damage',label:'Формула урона'}]},root_defaults:{rules:{type_profile:'bestiary'}}});
 d.getElementById('af-kb-rules-json-raw').value=JSON.stringify({mechanic:'arpg',rules});
 w.eval(fs.readFileSync(process.env.KB_EDITOR_SCRIPT || path.join(__dirname,'../inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase.js'),'utf8'));d.dispatchEvent(new w.Event('DOMContentLoaded'));
 assert.deepEqual(errors,[],'Editor initialization or event handler failed');
 return {dom,w,d,errors,data:()=>JSON.parse(d.getElementById('af-kb-rules-json-raw').value).rules};
}
const e=editor({type_profile:'bestiary',family:'fixture',archetype:'fixture',faction:'fixture',combat_stats:{accuracy:25,status_resist:0},resists:{wind:{scope:'elemental',unit:'RES',value:0,custom:'keep'}},ability_keys:[]});
// Regression for the actual screenshots: check the ordinary rules panel, not the optional technical tab.
const rulesPanel=e.d.querySelector('#af-kb-arpg-rules');
assert(rulesPanel,'Bestiary rules panel missing');
assert.equal(rulesPanel.querySelectorAll('textarea').length,0,'Bestiary still renders JSON blocks instead of visual controls');
for(const title of ['Количество целей','Условия применения','Призыв','Ограничения применения','Кулдаун и восстановление','Затраты','Формулы']) {
 assert([...rulesPanel.querySelectorAll('h4')].some(node=>node.textContent===title),'Missing managed section: '+title);
}
const ordinaryLabels=[...rulesPanel.querySelectorAll('label')].map(node=>node.textContent);
assert(!ordinaryLabels.some(label=>/JSON|magic_direction_ref|fingerprint|definition_version/.test(label)),'Technical fields leaked into ordinary bestiary controls');
const inputs=[...e.d.querySelectorAll('[data-res-element]')];assert.equal(inputs.length,17);assert.equal(inputs.find(n=>n.dataset.resElement==='air').value,'0');assert.equal(inputs.find(n=>n.dataset.resElement==='fire').value,'');
for(const input of inputs){input.value=input.dataset.resElement==='fire'?'7':'0';input.dispatchEvent(new e.w.Event('input',{bubbles:true}));}
assert(!('air' in e.data().resists));assert.equal(e.data().resists.wind.custom,'keep');
const abilities=[...e.d.querySelectorAll('.af-kb-kvlist')].find(n=>n.querySelector('h4')?.textContent==='Способности монстра');abilities.querySelector('.af-kb-add').click();
const picker=abilities.querySelector('[data-kb-field="ability_kb_key"] select');assert(picker);assert.equal(picker.options[1].textContent,'Кровавый шип');picker.value='blood_bolt';picker.dispatchEvent(new e.w.Event('change',{bubbles:true}));
assert.equal(e.data().ability_keys[0]._editor_ability_key,'blood_bolt');assert(!e.data().ability_keys[0].ability_fingerprint,'Client fabricated fingerprint');
const direction=e.d.querySelector('[data-kb-field="magic_direction_ref"] select');direction.value='blood';direction.dispatchEvent(new e.w.Event('change',{bubbles:true}));assert(!('damage_element' in e.data()),'Direction inferred damage');
const formulas=[...e.d.querySelectorAll('.af-kb-kvlist')].find(n=>n.querySelector('h4')?.textContent==='Формулы');formulas.querySelector('button').click();const formula=formulas.querySelector('select');formula.value='test_damage';formula.dispatchEvent(new e.w.Event('change',{bubbles:true}));
const fireInput=inputs.find(n=>n.dataset.resElement==='fire');fireInput.value='-1';fireInput.dispatchEvent(new e.w.Event('input',{bubbles:true}));assert(!fireInput.checkValidity(),'Negative resistance accepted');assert.equal(e.data().resists.fire.value,7,'Invalid input changed stored value');fireInput.value='7';fireInput.dispatchEvent(new e.w.Event('input',{bubbles:true}));
const saved=e.data();if(process.argv[2])fs.writeFileSync(process.argv[2],JSON.stringify(saved));e.dom.window.close();
const old=editor({type_profile:'bestiary',ability_keys:[{ability_kb_key:'blood_bolt',ability_fingerprint:'old-pin',ability_definition_version:{revision:1},extra:'keep'}],resists:[]});
assert(!('_editor_ability_key' in old.data().ability_keys[0]));assert.equal(old.data().ability_keys[0].ability_fingerprint,'old-pin');assert.equal(old.data().ability_keys[0].extra,'keep');
const refresh=[...old.d.querySelectorAll('button')].find(n=>n.textContent==='Обновить закреплённую версию');refresh.click();assert.equal(old.data().ability_keys[0]._editor_ability_key,'blood_bolt');old.dom.window.close();
console.log('Bestiary named choices, 17 sparse RES/wind, explicit refresh and unchanged pins: OK');

// A saved inline ability takes a different render path from a linked KB ability.
const inline=editor({type_profile:'bestiary',ability_keys:[{ability_name:'Старая способность',type:'active',damage_type:'physical',targeting:'single_enemy',effects:[],resources:[],extension:{keep:true}}],resists:[]});
const inlinePanel=inline.d.querySelector('#af-kb-arpg-rules');
const technicalNames=['slot_index','ability_name','icon_url','icon_class','type','subtype','slot','damage_type','targeting','range','cast_time','duration','max_charges','level_cap','ability_kb_key','sortorder'];
for(const label of inlinePanel.querySelectorAll('label'))assert(!technicalNames.includes(label.textContent.trim()),'Untranslated bestiary label: '+label.textContent);
for(const [key,value,caption] of [['type','active','Активная'],['damage_type','physical','Физический'],['targeting','single_enemy','Один противник']]) {
 const select=inlinePanel.querySelector('[data-kb-field="'+key+'"] select');assert(select,'Missing inline control: '+key);
 assert.equal([...select.options].find(option=>option.value===value)?.textContent,caption,'Untranslated bestiary option: '+value);
}
assert.equal(inlinePanel.querySelectorAll('textarea').length,0,'Inline ability still requires JSON');
assert.deepEqual(inline.data().ability_keys[0].extension,{keep:true},'Inline extension lost');
assert.deepEqual(inline.errors,[]);inline.dom.window.close();
console.log('Screenshot regression: managed bestiary sections, Russian inline labels/options, no ordinary JSON fields OK');

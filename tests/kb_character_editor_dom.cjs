const {JSDOM,VirtualConsole}=require('jsdom');const fs=require('fs');const path=require('path');const assert=require('assert');
(async()=>{
const vc=new VirtualConsole();vc.sendTo(console);const dom=new JSDOM('<form><div id="af-kb-rules-ui" data-type="character" data-mechanic="arpg"></div><textarea id="af-kb-rules-json-raw"></textarea><textarea id="af-kb-meta-json">{}</textarea></form>',{url:'https://test.invalid/',runScripts:'outside-only',virtualConsole:vc});
const w=dom.window,d=w.document;d.getElementById('af-kb-rules-ui').dataset.typeSchema=JSON.stringify({ui_profile:'character',primary_element_options:[{value:'life',label:'Жизнь'}],editor_choices:{magic_direction:[{value:'blood',label:'Кровь · Жизнь'}],formula_def:[{value:'hit',label:'Урон'}]}});
d.getElementById('af-kb-rules-json-raw').value=JSON.stringify({schema:'af_kb.character.v1',type_profile:'character',character_profile:{character_name:'Fixture'},character_meta:{mechanic:'arpg'},character_abilities:[{title:'Способность',damage_type:'elemental',damage_element:'life',effects:[{effect_type:'damage',damage_type:'elemental',damage_element:'life',value:1}],extension:{keep:1}}]});
w.eval(fs.readFileSync(path.join(__dirname,'../inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase.js'),'utf8'));d.dispatchEvent(new w.Event('DOMContentLoaded'));
const direction=d.querySelector('[data-kb-field="magic_direction_ref"] select');assert(direction,'Dedicated public character editor did not receive shared controls');direction.value='blood';direction.dispatchEvent(new w.Event('change',{bubbles:true}));
assert([...d.querySelectorAll('label')].some(n=>n.textContent.includes('Стихия урона')),'Public character lacks explicit damage input');
await new Promise(resolve=>setTimeout(resolve,350));const saved=JSON.parse(d.getElementById('af-kb-rules-json-raw').value);
assert.equal(saved.character_abilities[0]._editor_magic_direction_key,'blood');assert.equal(saved.character_abilities[0].damage_element,'life');assert.deepEqual(saved.character_abilities[0].extension,{keep:1});
console.log('Dedicated public character editor: explicit damage, shared direction picker and extension preservation OK');dom.window.close();
})().catch(error=>{console.error(error);process.exitCode=1});

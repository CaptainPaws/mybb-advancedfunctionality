'use strict';
const assert=require('node:assert/strict'),{chromium}=require('playwright');
(async()=>{
 const origin=process.argv[2]||'http://127.0.0.1:8100';
 const browser=await chromium.launch({executablePath:process.env.AA_TEST_CHROMIUM||'/usr/bin/chromium',args:['--no-sandbox']});
 const page=await browser.newPage({viewport:{width:1440,height:1050}});page.setDefaultTimeout(10000);
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const selectors={profile:'.atf-profile-hero',sheet:'.af-cs-summary',theme:'.atf-forum-category'};
 for(const target of ['profile','sheet','theme']){
  await page.setViewportSize({width:1440,height:1050});
  await page.goto(origin+'/apstudio.php?target='+target);
  await page.getByRole('button',{name:'Редактировать',exact:true}).first().click();
  await page.locator('#aa-component').selectOption(target==='profile'?'banner':target==='sheet'?'hero':'cards');
  await page.locator('[data-setting="text_color"]').evaluate(n=>{n.value='#99bbdd';n.dispatchEvent(new Event('input',{bubbles:true}));});
  const selector='#aa-studio-preview '+selectors[target];
  await page.waitForFunction(s=>getComputedStyle(document.querySelector(s)).color==='rgb(153, 187, 221)',selector);
  await page.locator('#aa-field-groups summary').filter({hasText:'Типографика'}).click();
  await page.locator('[data-setting="font_family"]').selectOption('mono');
  await page.waitForFunction(s=>getComputedStyle(document.querySelector(s)).fontFamily.includes('monospace'),selector);
  await page.locator('#aa-component').selectOption('page');
  await page.evaluate(()=>{window.AF_GalleryPicker={open:(callback,options)=>{window.surfacePurpose=options.purpose;callback({media_id:1});}};});
  await page.getByRole('button',{name:'Выбрать фон в Gallery',exact:true}).click();
  assert.equal(await page.evaluate(()=>window.surfacePurpose),'appearance_'+target+'_bg');
  await page.locator('#aa-controls button[type=submit]').click();
  await page.waitForFunction(()=>document.getElementById('aa-status').textContent.includes('Пресет сохранён'));
  await page.locator('#aa-back').click();await page.locator('#aa-cards').waitFor({state:'visible'});
  await page.getByRole('button',{name:'Применить',exact:true}).last().click();
  await page.waitForFunction(()=>document.getElementById('aa-status').textContent.includes('Пресет применён'));
  await page.getByRole('button',{name:'Редактировать',exact:true}).first().click();
  await page.setViewportSize({width:390,height:844});await page.locator('[data-aa-view=preview]').click();
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Mobile overflow: '+target);
  assert.equal(await page.locator('#aa-avatar-fields').isVisible(),false);
  if(target==='theme')assert.equal(await page.locator('#aa-canvas-fieldset').isVisible(),false);
  else {
   assert.equal(await page.locator('script[src*="element-canvas-engine.js"]').count(),1);
   await page.evaluate(()=>window.afElementEffects?.setPreferences({effects_enabled:false}));
   assert.equal(await page.locator('#aa-studio-preview [data-af-element-effect-active]').count(),0);
  }
  if(target!=='theme'){
   await page.goto(origin+'/member.php?fixture_action=runtime&target='+target);
   const own=target==='profile'?'.atf-profile.af-aa-profile-user-7 '+selectors[target]:'.af-cs-page[data-af-apui-owner-uid="7"] '+selectors[target];
   const other=target==='profile'?'.atf-profile.af-aa-profile-user-8 '+selectors[target]:'.af-cs-page[data-af-apui-owner-uid="8"] '+selectors[target];
   assert.equal(await page.locator(own).evaluate(n=>getComputedStyle(n).color),'rgb(153, 187, 221)');
   assert.notEqual(await page.locator(other).evaluate(n=>getComputedStyle(n).color),'rgb(153, 187, 221)','CSS leaked: '+target);
  }
 }
 assert.deepEqual(errors,[]);await browser.close();
 console.log('Surface browser checks passed: real templates, live fonts/colors, Gallery purposes, save/apply, responsive, Canvas preference and owner isolation.');
})().catch(e=>{console.error(e);process.exit(1);});

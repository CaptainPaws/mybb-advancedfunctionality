'use strict';
const assert=require('node:assert/strict'),{chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.AA_TEST_CHROMIUM||'/usr/bin/chromium',args:['--no-sandbox']});
 try {
 const page=await browser.newPage({viewport:{width:1450,height:1100}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 const origin=process.argv[2]||'http://127.0.0.1:8100';
 await page.context().route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.fulfill({status:204}));
 await page.goto(origin+'/apstudio.php');await page.getByRole('button',{name:'Редактировать',exact:true}).first().click();
 const area=zone=>page.locator('#aa-studio-preview '+(zone==='topbar'?'.atf-post__topbar':'.atf-post__sidebar-inner'));
 const paint=zone=>area(zone).evaluate(el=>getComputedStyle(el).backgroundImage);
 async function select(zone){await page.selectOption('#aa-component',zone);await page.locator('#aa-field-groups details').filter({has:page.locator('summary',{hasText:'Затенение'})}).locator('summary').click();}
 async function range(key,value){await page.locator('[data-setting="'+key+'"]').evaluate((el,value)=>{el.value=value;el.dispatchEvent(new Event('input',{bubbles:true}));},String(value));}
 const initialSide=await paint('sidebar');
 await select('topbar');
 for(const [key,value] of Object.entries({overlay_intensity:'.5',overlay_start:'1',overlay_end:'1',overlay_angle:'180'}))assert.equal(Number(await page.locator('[data-setting="'+key+'"]').inputValue()),Number(value),'Wrong initial '+key);
 await range('overlay_intensity',.8);
 assert.equal(await page.locator('[data-setting="overlay_mode"]').inputValue(),'darken');
 await page.waitForFunction(()=>getComputedStyle(document.querySelector('#aa-studio-preview .atf-post__topbar')).backgroundImage.includes('0.8'));
 assert.equal(await paint('sidebar'),initialSide,'Topbar changed sidebar');const topPaint=await paint('topbar');
 await select('sidebar');await page.locator('[data-setting="overlay_mode"]').selectOption('lighten');await range('overlay_intensity',.6);
 await range('overlay_start',0);await range('overlay_end',1);
 await page.waitForFunction(()=>getComputedStyle(document.querySelector('#aa-studio-preview .atf-post__sidebar-inner')).backgroundImage.includes('0.6'));
 assert.equal(await paint('topbar'),topPaint,'Sidebar changed topbar');
 assert.match(await paint('sidebar'),/1 1 1/,'Lighten did not use white');
 await page.waitForFunction(()=>document.querySelector('#aa-draft-status').textContent==='Черновик сохранён');
 const response=await page.request.post(origin+'/apstudio.php?api=personal',{data:{operation:'list',target:'postbit',my_post_key:'appearance-fixture-key'}});
 const list=(await response.json()).data,row=list.presets[0],settings=JSON.parse(row.draft_json);
 assert.equal(settings.components.topbar.overlay_mode,'darken');assert.equal(settings.components.topbar.overlay_intensity,.8);
 assert.equal(settings.components.sidebar.overlay_mode,'lighten');assert.equal(settings.components.sidebar.overlay_start,0);
 // Persist/activate through the existing API, then inspect the ordinary postbit CSS path.
 for(const [operation,values] of [['save',{id:row.id,title:row.title,settings_json:row.draft_json}],['activate',{id:row.id}]]){
  const r=await page.request.post(origin+'/apstudio.php?api=personal',{data:{operation,target:'postbit',my_post_key:'appearance-fixture-key',...values}});assert.ok((await r.json()).ok);
 }
 const live=await browser.newPage();await live.goto(origin+'/apstudio.php?fixture_action=studio_render',{waitUntil:'domcontentloaded'});
 const read=selector=>live.locator('#post_107 '+selector).evaluate(el=>({background:getComputedStyle(el).backgroundImage,opacity:getComputedStyle(el).opacity,filter:getComputedStyle(el).filter}));
 assert.equal((await read('.atf-post__topbar')).background,topPaint);
 assert.ok((await read('.atf-post__sidebar-inner')).background.includes('0.6'));
 for(const selector of ['.atf-post__topbar','.atf-post__sidebar-inner']){const data=await read(selector);assert.equal(data.opacity,'1');assert.equal(data.filter,'none');}
 await live.locator('#post_107 .atf-post__profile-action').evaluate(el=>{window.overlayClicks=0;el.addEventListener('click',event=>{event.preventDefault();window.overlayClicks++;});});
 await live.locator('#post_107 .atf-post__profile-action').click();assert.equal(await live.evaluate(()=>window.overlayClicks),1);
 assert.equal(await live.locator('#post_107 .atf-post__primary-avatar-media img').count(),1);
 assert.equal(await live.locator('#post_107 .atf-post__secondary-avatar > .atf-gothic-frame').count(),1);
 await select('sidebar');await page.locator('[data-setting="overlay_mode"]').selectOption('none');
 assert.ok(await page.locator('[data-setting="overlay_intensity"]').isDisabled());
 await page.waitForFunction(()=>document.querySelector('#aa-studio-preview .atf-post__sidebar-inner')&&getComputedStyle(document.querySelector('#aa-studio-preview .atf-post__sidebar-inner')).getPropertyValue('--aa-sidebar-mask').trim()==='none');
 assert.deepEqual(errors,[]);
 console.log('Overlay browser passed: defaults, sparse slider activation, darken/lighten/gradient/none, isolated zones, persisted runtime paint, clickable profile and intact avatars/frame.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

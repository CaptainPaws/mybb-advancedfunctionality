'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {execFileSync}=require('node:child_process'); const {chromium,firefox}=require('playwright');
const root=path.resolve(__dirname,'..'), assets=path.join(root,'inc/plugins/advancedfunctionality/addons/advancedelementtheme/assets');
const keys=['effects_enabled','effects_profile','effects_sheet','effects_application','effects_postbit'];
const defaults=Object.fromEntries(keys.map(k=>[k,true]));
(async()=>{
 const ff=process.env.AF_TEST_BROWSER==='firefox';
 const browser=await(ff?firefox:chromium).launch({headless:true,...(!ff?{executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',args:['--no-sandbox']}: {})});
 try{
  const fixture=JSON.parse(execFileSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'element_theme_effects_regression.php'),'--browser-fixture'],{encoding:'utf8'}));
  const widget=JSON.parse(execFileSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'element_preferences_regression.php'),'--browser-fixture'],{encoding:'utf8'})).widget;
  let server={...defaults,effects_enabled:false,effects_application:false},fail=false,saves=0;
  async function context(){const c=await browser.newContext({viewport:{width:1200,height:1000}}); await c.route('https://forum.test/**',async route=>{
   if(new URL(route.request().url()).pathname==='/misc.php'){
    const data=new URLSearchParams(route.request().postData()); saves++;
    assert.equal(data.get('my_post_key'),'csrf-token');assert.equal(data.has('uid'),false);
    if(fail){fail=false;await route.fulfill({status:500,json:{error:'Test storage failure'}});return;}
    server=Object.fromEntries(keys.map(k=>[k,data.get(k)==='1'])); await route.fulfill({json:{preferences:server}});return;
   }
   if(['/preferences-fixture','/guest'].includes(new URL(route.request().url()).pathname)){await route.fulfill({body:'<html><head><meta charset="utf-8"></head><body></body></html>',contentType:'text/html; charset=utf-8'});return;}
   const file=path.basename(new URL(route.request().url()).pathname);
   if(fs.existsSync(path.join(assets,file))) await route.fulfill({body:fs.readFileSync(path.join(assets,file)),contentType:file.endsWith('.js')?'application/javascript; charset=utf-8':'text/css; charset=utf-8'});else await route.abort();
  });return c;}
  async function load(page,uid,prefs){
   await page.goto("https://forum.test/preferences-fixture");
   let html=fixture.html.replace(/<script type="application\/json" data-af-element-preferences>[\s\S]*?<\/script>/,`<script type="application/json" data-af-element-preferences>${JSON.stringify({uid,preferences:prefs})}</script>`);
   const controls=widget.replace(/<script type="application\/json" data-af-element-preferences>[\s\S]*?<\/script>/,'');
   html=html.replace('</head>',`<script>window.everCreated=0;new MutationObserver(rs=>rs.forEach(r=>r.addedNodes.forEach(n=>{if(n.nodeType===1&&n.matches('.af-element-canvas'))everCreated++;}))).observe(document,{childList:true,subtree:true});</script></head>`).replace('</body>',controls+'</body>');
   await page.setContent(html);await page.addStyleTag({content:'body{position:relative;isolation:isolate}#sheet-host,#application{min-height:200px;position:relative;isolation:isolate}.atf-post__topbar{height:70px;position:relative}.atf-post__sidebar-inner{height:180px;position:relative;isolation:isolate;background-image:linear-gradient(#123,#456);--af-apui-postbit-author-bg-image:url("own-image");--af-apui-postbit-author-overlay:rgba(0,0,0,.4)}'});
   await page.waitForFunction(()=>window.afElementEffects&&window.afElementPreferenceControlsReady);
  }
  const c=await context(),page=await c.newPage(),errors=[];page.on('pageerror',e=>errors.push(String(e)));
  await load(page,42,server);await page.waitForTimeout(200);
  assert.equal(await page.locator('.af-element-canvas').count(),0);assert.equal(await page.evaluate(()=>everCreated),0,'Disabled seed briefly started Canvas');
  assert.equal(await page.evaluate(()=>afElementCanvasEngine.diagnostics().schedulerLoops),0);
  async function toggle(name){await page.locator(`[data-af-element-preferences-form] input[type="checkbox"][name="${name}"]`).click();await page.waitForFunction(()=>document.querySelector('[data-af-element-preferences-form]').getAttribute('aria-busy')==='false');}
  await toggle('effects_enabled');assert.equal(server.effects_application,false);assert.equal(await page.locator('.af-element-canvas').count(),4);
  await toggle('effects_application');await page.waitForFunction(()=>afElementCanvasEngine.diagnostics().instances===5);
  const styles=await page.locator('.atf-post__sidebar-inner').evaluate(e=>({bg:getComputedStyle(e).backgroundImage,custom:e.style.getPropertyValue('--af-apui-postbit-author-bg-image')}));
  const palette=await page.locator('body').evaluate(e=>getComputedStyle(e).getPropertyValue('--af-element-accent'));
  for(const surface of ['profile','sheet','application','postbit']){
   const key='effects_'+surface;await toggle(key);
   assert.ok(await page.evaluate(s=>afElementCanvasEngine.diagnostics().hosts.every(h=>h.surface!==s),surface));
   assert.equal(await page.locator('.af-element-canvas').count(),surface==='postbit'?3:4);
   await toggle(key);assert.equal(await page.locator('.af-element-canvas').count(),5);
  }
  fail=true;await toggle('effects_profile');assert.equal(await page.locator('[name="effects_profile"][type="checkbox"]').isChecked(),true);
  assert.match(await page.locator('[data-af-element-preferences-form] [role="status"]').innerText(),/Test storage failure.*отменены/);
  assert.equal(await page.locator('.af-element-canvas').count(),5);
  await toggle('effects_sheet');
  await page.evaluate(()=>document.body.insertAdjacentHTML('beforeend','<div id="lazy-sheet" class="af-aa-context--sheet af-apui-surface-body" data-element="fire" data-element-surface="sheet" style="height:150px;position:relative"></div>'));
  await page.waitForTimeout(120);assert.equal(await page.locator('#lazy-sheet canvas').count(),0);
  await toggle('effects_sheet');assert.equal(await page.locator('#lazy-sheet canvas').count(),1);
  await page.evaluate(()=>{const host=document.getElementById('lazy-sheet');host.hidden=true;});await page.waitForTimeout(100);
  await page.evaluate(()=>{document.getElementById('lazy-sheet').hidden=false;afElementEffects.refresh();});assert.equal(await page.locator('#lazy-sheet canvas').count(),1);
  await page.evaluate(()=>document.getElementById('lazy-sheet').remove());await page.waitForTimeout(100);assert.equal(await page.locator('.af-element-canvas').count(),5);
  const denied=await page.locator('#disabled-host canvas,#neutral-host canvas').count();assert.equal(denied,0,'Preferences bypassed ACP/eligibility');
  await page.emulateMedia({reducedMotion:'reduce'});await page.waitForFunction(()=>afElementCanvasEngine.diagnostics().schedulerLoops===0);
  await toggle('effects_enabled');await toggle('effects_enabled');await page.waitForFunction(()=>afElementCanvasEngine.diagnostics().schedulerLoops===0);
  const reducedFrames=await page.evaluate(()=>afElementCanvasEngine.diagnostics().frames);await page.waitForTimeout(120);assert.equal(await page.evaluate(()=>afElementCanvasEngine.diagnostics().frames),reducedFrames);
  await page.emulateMedia({reducedMotion:'no-preference'});await page.waitForFunction(()=>afElementCanvasEngine.diagnostics().schedulerLoops===1);
  await toggle('effects_enabled');assert.equal(await page.locator('canvas.af-element-canvas').count(),0);
  assert.equal(await page.locator('body').evaluate(e=>getComputedStyle(e).getPropertyValue('--af-element-accent')),palette);
  assert.deepEqual(await page.locator('.atf-post__sidebar-inner').evaluate(e=>({bg:getComputedStyle(e).backgroundImage,custom:e.style.getPropertyValue('--af-apui-postbit-author-bg-image')})),styles);
  const c2=await context(),other=await c2.newPage();await load(other,42,server);assert.equal(await other.locator('.af-element-canvas').count(),0);assert.deepEqual(await other.evaluate(()=>afElementEffects.getPreferences()),server);
  // Separate browser context is a server-seeded device fixture, not a live account login.
  await page.goto('https://forum.test/guest');await page.evaluate(()=>localStorage.setItem('af-element-effects-guest',JSON.stringify({effects_enabled:false})));
  await load(page,0,defaults);assert.equal(await page.locator('.af-element-canvas').count(),0);
  const beforeGuest=saves;await toggle('effects_enabled');assert.equal(saves,beforeGuest);assert.equal(await page.locator('.af-element-canvas').count(),5);
  assert.equal(await page.evaluate(()=>JSON.parse(localStorage.getItem('af-element-effects-guest:v1')).effects_enabled),true,'Legacy choices were not saved to versioned storage');
  await load(page,0,defaults);assert.equal(await page.locator('.af-element-canvas').count(),5,'Guest choice did not survive reload');
  await page.evaluate(()=>{Storage.prototype.setItem=function(){throw new Error('blocked storage');};});
  await toggle('effects_enabled');assert.equal(await page.locator('.af-element-canvas').count(),5,'Storage failure did not restore saved permissions');
  assert.match(await page.locator('[data-af-element-preferences-form] [role="status"]').textContent(),/отменены/);
  await page.goto('https://forum.test/guest');await page.evaluate(()=>localStorage.setItem('af-element-effects-guest:v1',JSON.stringify({effects_enabled:false})));
  await load(page,42,defaults);assert.equal(await page.locator('.af-element-canvas').count(),5,'Guest storage overrode account seed');
  assert.deepEqual(errors,[]);await c2.close();await c.close();
  console.log(`Element preferences ${ff?'Firefox':'Chromium'}: server seed without startup flash, all surface/master toggles, AJAX exclusion/resume, no duplicates, optimistic save/rollback, second-device seed, guest isolation, ACP/neutral/reduced-motion gates and static style preservation passed.`);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});

'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium,firefox}=require('playwright');const root=path.resolve(__dirname,'..'),addons=path.join(root,'inc/plugins/advancedfunctionality/addons');
const fixture=JSON.parse(execFileSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'profile_quick_modal_regression.php'),'--browser-fixture'],{encoding:'utf8'}));
(async()=>{const ff=process.env.AF_TEST_BROWSER==='firefox',browser=await(ff?firefox:chromium).launch({headless:true,...(!ff?{executablePath:'/usr/bin/chromium',args:['--no-sandbox']}:{})});try{
 const page=await browser.newPage({viewport:{width:1100,height:800}}),errors=[];let sections=[],assets=0,frameLoads=0,deny=false,slow=false;
 page.on('pageerror',e=>errors.push(String(e)));
 await page.route('https://forum.test/**',async route=>{const url=new URL(route.request().url());
  if(url.pathname==='/profile')return route.fulfill({body:'<html><body></body></html>',contentType:'text/html'});
  if(url.pathname==='/member.php'){
   const key=url.searchParams.get('af_profile_quick')||url.searchParams.get('af_profile_tab');sections.push(key);
   if(slow){slow=false;await new Promise(r=>setTimeout(r,150));}
   if(deny){deny=false;return route.fulfill({status:403,json:{error:'Содержимое недоступно.'}}).catch(()=>{});}
   const config='<script type="application/json" data-af-element-effects-config>'+JSON.stringify({fire:{enabled:true,preset:'stardust',speed:50,density:50,intensity:50,opacity:50,surfaces:['application']}})+'</script>';
   const html=key==='application'?config+'<div class="af-atf-display" data-element="fire" data-element-surface="application" style="position:relative;min-height:160px">Анкета: только содержимое</div>':`<div data-section="${key}">${key} content <button>Action</button></div>`;
   const list=[{type:'css',url:'https://forum.test/lazy.css'},{type:'js',url:'https://forum.test/lazy.js'}];
   if(key==='application')for(const file of ['element-effects.css','element-canvas-engine.js','element-effects.js'])list.push({type:file.endsWith('.css')?'css':'js',url:'https://forum.test/inc/plugins/advancedfunctionality/addons/advancedelementtheme/assets/'+file});
   return route.fulfill({json:{html,assets:list}}).catch(()=>{});
  }
  if(url.pathname==='/charactersheets.php'){frameLoads++;return route.fulfill({body:'<html><body><button data-afcs-tab="main" class="is-active">Основная информация</button><p>Sheet fixture</p></body></html>',contentType:'text/html'});}
  if(url.pathname==='/lazy.css'){assets++;return route.fulfill({body:'[data-section]{padding:10px}',contentType:'text/css'});}
  if(url.pathname==='/lazy.js'){assets++;return route.fulfill({body:'window.runtimeLoads=(window.runtimeLoads||0)+1;window.AFAdvancedInventoryInit=function(root){root.dataset.inventoryInitialized="1";};',contentType:'application/javascript'});}
  const file=url.pathname.split('/addons/')[1];if(file&&fs.existsSync(path.join(addons,file)))return route.fulfill({body:fs.readFileSync(path.join(addons,file)),contentType:file.endsWith('.js')?'application/javascript; charset=utf-8':'text/css; charset=utf-8'});
  return route.abort();
 });
 await page.goto('https://forum.test/profile');
 const panels=['info','rewards','timeline','activity','extra'].map(k=>`<section id="af-apui-tab-${k}" data-panel="${k}" ${k==='info'?'':'hidden'} ${['rewards','timeline','activity'].includes(k)?'data-lazy-url="member.php?action=profile&uid=9&af_profile_tab='+k+'"':''}>${k==='info'?fixture.quick:k==='extra'?fixture.extra:''}</section>`).join('');
 await page.setContent(`<style>${fs.readFileSync(path.join(addons,'advancedprofileui/assets/advancedprofileui.css'),'utf8')}body{background:#152030;color:#eee;--af-element-main:#e77;--af-element-accent:#8af;--af-element-soft:rgba(100,160,240,.2);--af-element-border:#567}</style><body><main class="af-apui-profile" data-af-apui-tabs>${fixture.nav}${panels}</main></body>`);
 await page.addScriptTag({content:fs.readFileSync(path.join(addons,'advancedprofileui/assets/advancedprofileui.js'),'utf8')});
 assert.equal(sections.length,0);assert.equal(assets,0);assert.equal(frameLoads,0);
 assert.equal(await page.locator('[data-tab="sheet"],[data-panel="sheet"]').count(),0);
 await page.locator('[data-tab="extra"]').click();assert.equal(await page.locator('#af-apui-tab-extra').isVisible(),true);assert.equal(await page.locator('#af-apui-tab-info').isVisible(),false);assert.equal(await page.locator('#mail').count(),1);
 await page.locator('[data-tab="info"]').click();
 const modal=page.locator('[data-af-apui-modal]');
 for(const key of ['application','arsenal','talents','resources','pets']){
  await page.locator(`[data-af-apui-quick-link="${key}"]`).click();await page.waitForFunction(()=>{const m=document.querySelector('[data-af-apui-modal-content]');return m&&m.querySelector('[data-section],.af-atf-display')&&m.dataset.inventoryInitialized==='1';});
  assert.equal(await modal.count(),1);assert.equal(await modal.evaluate(e=>e.contains(document.activeElement)),true,'Modal did not receive focus');
  if(key==='application')assert.equal(await modal.locator('iframe').count(),0,'Application unnecessarily created an iframe');assert.equal(await modal.locator('.af-cs-modal__backdrop').count(),1);assert.equal(await modal.locator('.atf-post,.atf-post__sidebar,#quick_reply,#footer').count(),0);
  if(key==='application'){await page.waitForFunction(()=>document.querySelectorAll('.af-element-canvas').length===1);assert.equal(await page.evaluate(()=>afElementCanvasEngine.diagnostics().instances),1);}
  await page.keyboard.press('Escape');assert.equal(await page.locator(`[data-af-apui-quick-link="${key}"]`).evaluate(e=>e===document.activeElement),true);
  if(key==='application'){await page.waitForFunction(()=>afElementCanvasEngine.diagnostics().instances===0);}
 }
 assert.equal(await page.evaluate(()=>runtimeLoads),1,'Heavy runtime duplicated');assert.equal(assets,2);
 await page.locator('[data-af-apui-quick-link="application"]').click();await page.waitForFunction(()=>document.querySelectorAll('.af-element-canvas').length===1);await page.locator('[data-af-apui-modal-close]').last().click();await page.waitForFunction(()=>afElementCanvasEngine.diagnostics().instances===0);
 deny=true;await page.locator('[data-af-apui-quick-link="pets"]').click();await page.locator('[role="alert"]').waitFor();assert.match(await page.locator('[role="alert"]').textContent(),/недоступно/);await page.keyboard.press('Escape');
 slow=true;await page.locator('[data-af-apui-quick-link="arsenal"]').click();await page.keyboard.press('Escape');await page.waitForTimeout(180);assert.equal(await modal.locator('[data-section]').count(),0,'Closed modal received stale content');
 await page.locator('[data-af-apui-quick-link="sheet"]').click();await page.waitForFunction(()=>document.querySelector('[data-af-apui-modal-frame]').classList.contains('is-loaded'));assert.equal(frameLoads,1);assert.match(await modal.locator('iframe').getAttribute('src'),/embed=1/);await page.frameLocator('[data-af-apui-modal-frame]').locator('button').focus();await page.keyboard.press('Tab');assert.equal(await modal.locator('button[data-af-apui-modal-close]').evaluate(e=>e===document.activeElement),true);await page.frameLocator('[data-af-apui-modal-frame]').locator('button').focus();await page.keyboard.press('Escape');assert.equal(await page.locator('[data-af-apui-quick-link="sheet"]').evaluate(e=>e===document.activeElement),true);assert.equal(await modal.locator('iframe').getAttribute('src'),'about:blank');
 for(const key of ['rewards','timeline','activity']){await page.locator(`[data-tab="${key}"]`).click();await page.waitForFunction(k=>document.querySelector('[data-panel="'+k+'"]').dataset.lazyState==='loaded',key);}
 assert.equal(await page.evaluate(()=>runtimeLoads),1);assert.deepEqual(errors,[]);
 await page.setViewportSize({width:390,height:844});await page.locator('[data-tab="info"]').click();await page.locator('[data-af-apui-quick-link="resources"]').click();await page.waitForFunction(()=>document.querySelector('[data-af-apui-modal-content] [data-section]'));assert.ok(await modal.locator('.af-cs-modal__dialog').evaluate(e=>e.getBoundingClientRect().width<=innerWidth));await page.keyboard.press('Escape');
 console.log(`Profile modals ${ff?'Firefox':'Chromium'}: five tabs, extra native actions, no initial heavy requests, section fetches, single modal/assets/runtime, real Canvas cleanup/reopen, iframe-only sheet, errors/abort, focus return and mobile passed.`);
 }finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1);});

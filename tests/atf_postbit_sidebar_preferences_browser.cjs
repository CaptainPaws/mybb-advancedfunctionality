'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium,firefox}=require('playwright');
const root=path.resolve(__dirname,'..'),addons=path.join(root,'inc/plugins/advancedfunctionality/addons');
const fixture=JSON.parse(execFileSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'atf_postbit_sidebar_preferences_regression.php'),'--browser-fixture'],{encoding:'utf8'}));
const css=execFileSync(process.env.PHP_BINARY||'php',['-r',`require '${path.join(__dirname,'fixtures/atf_css.php')}'; echo atf_test_css('${path.join(addons,'adaptivethemeframework')}', 'showthread.php');`],{encoding:'utf8'});
const storageKey='af-presentation-preferences:v1:postbit_sidebar_hidden';
function post(id){return `<article class="atf-post" id="post-${id}" data-element="fire" data-element-surface="postbit"><header class="atf-post__topbar" data-af-element-effect-host="postbit-topbar"><a href="member.php?action=profile&uid=7">Author</a><span class="atf-post__meta-line">Time · status · #${id}</span><button class="edit">Quick edit</button></header><div class="atf-post__layout"><aside class="atf-post__sidebar" data-af-element-effect-host="postbit-sidebar"><div class="atf-post__sidebar-inner" style="background-image:linear-gradient(#123,#456);--af-apui-postbit-author-bg-image:url(own-image);--af-apui-postbit-author-overlay:rgba(0,0,0,.4);min-height:180px">Author sidebar</div></aside><div class="atf-post__content"><div class="post_body scaleimages atf-post__message"><p>${'Message '.repeat(40)}</p><blockquote>Quote</blockquote><pre>${'Code '.repeat(60)}</pre><img width="1400" height="40" alt="attachment" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='1400' height='40'%3E%3C/svg%3E"></div><div class="atf-post__signature">Signature</div><footer class="atf-post__footer"><a href="#post-${id}">Post link</a><button>Reply</button></footer></div></div></article>`;}
(async()=>{
 const ff=process.env.AF_TEST_BROWSER==='firefox';const browser=await(ff?firefox:chromium).launch({headless:true,...(!ff?{executablePath:'/usr/bin/chromium',args:['--no-sandbox']}:{})});
 try{
  const page=await browser.newPage({viewport:{width:1280,height:900}}),errors=[];let saves=0,fail=false,serverHidden=true,serverLayout='grid',held=null,release;
  page.on('pageerror',e=>errors.push(String(e)));
  await page.route('https://forum.test/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname==='/misc.php'){
    const body=new URLSearchParams(route.request().postData());assert.equal(body.get('my_post_key'),'csrf-token');const key=body.get('preference_key');assert.ok(['forum_layout','postbit_sidebar_hidden'].includes(key));assert.equal(body.has('uid'),false);assert.equal(body.has(key==='forum_layout'?'postbit_sidebar_hidden':'forum_layout'),false);saves++;
    if(held) await held;
    if(fail){fail=false;return route.fulfill({status:500,json:{error:'Storage failed'}});}
    if(key==='forum_layout'){serverLayout=body.get('forum_layout');return route.fulfill({json:{forum_layout:serverLayout}});}
    serverHidden=body.get('postbit_sidebar_hidden')==='1';return route.fulfill({json:{postbit_sidebar_hidden:serverHidden}});
   }
   if(url.pathname==='/fixture')return route.fulfill({body:'<html><body></body></html>',contentType:'text/html'});
   const relative=url.pathname.split('/addons/')[1],file=relative&&path.join(addons,relative);
   return file&&fs.existsSync(file)?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.js')?'application/javascript; charset=utf-8':'text/css; charset=utf-8'}):route.abort();
  });
  const asset=(name)=>`https://forum.test/inc/plugins/advancedfunctionality/addons/${name}`;
  async function load(member){
   await page.goto('https://forum.test/fixture');
   let {widget,bootstrap}=fixture[member?'member':'guest'];
   if(member){widget=widget.replace(/name="postbit_sidebar_visible" value="1"(?: checked)?/, 'name="postbit_sidebar_visible" value="1"'+(serverHidden?'':' checked'));bootstrap=bootstrap.replace('data-atf-preferences-layout="grid"','data-atf-preferences-layout="'+serverLayout+'"');bootstrap=bootstrap.replace('data-atf-preferences-sidebar="hidden"','data-atf-preferences-sidebar="'+(serverHidden?'hidden':'visible')+'"');}
   const html=`<html><head><style>${css}${fs.readFileSync(path.join(addons,'advancedmenu/assets/advancedmenu.css'),'utf8')}${fs.readFileSync(path.join(addons,'advancedelementtheme/assets/element-effects.css'),'utf8')}body{margin:0;padding:12px;--af-element-main:#ee7799;--af-element-accent:#88ccff;--af-element-soft:rgba(200,100,160,.3);--af-element-border:#aaccee;--atf-post-accent:#aaccee;--atf-post-accent-soft:rgba(100,160,200,.25);--atf-color-page-subtle:#152030}.atf-post__content{min-height:300px}.atf-post__topbar{min-height:60px}.atf-theme-preferences{max-width:340px}.af-am-drawer{position:relative;inset:auto;width:min(340px,100%);max-height:none}</style>${bootstrap}<script>window.earlySidebar=document.documentElement.dataset.atfPostbitSidebar;window.createdSidebarCanvases=0;new MutationObserver(rs=>rs.forEach(r=>r.addedNodes.forEach(n=>{if(n.nodeType===1&&n.matches('.af-element-canvas')&&n.closest('.atf-post__sidebar'))createdSidebarCanvases++;}))).observe(document,{childList:true,subtree:true});</script><script type="application/json" data-af-element-preferences>${JSON.stringify({uid:member?42:0,preferences:{}})}</script><script type="application/json" data-af-element-effects-config>${JSON.stringify({fire:{enabled:true,preset:'stardust',density:70,speed:80,intensity:80,opacity:70,surfaces:['postbit']}})}</script><script src="${asset('advancedelementtheme/assets/element-canvas-engine.js')}" defer></script><script src="${asset('advancedelementtheme/assets/element-effects.js')}" defer></script></head><body class="atf-active"><div class="af-am-drawer">${widget}</div><main>${post(1)}${post(2)}${post(3)}</main><script src="${asset('adaptivethemeframework/assets/adaptivethemeframework.postbit-sticky.js')}" defer></script></body></html>`;
   await page.route('https://forum.test/render',route=>route.fulfill({body:html,contentType:'text/html; charset=utf-8'}));
   await page.goto('https://forum.test/render');await page.waitForFunction(()=>window.afElementEffects&&document.body.dataset.atfPostbitSidebar);
  }
  const input=page.locator('input[name="postbit_sidebar_visible"]');
  async function toggle(){await input.click();await page.waitForFunction(()=>!document.querySelector('[name="postbit_sidebar_visible"]').disabled);}
  async function noSidebarTail() {
   const geometry=await page.locator('.atf-post__sidebar:visible').evaluateAll(nodes=>nodes.map(outer=>{
    const inner=outer.querySelector('.atf-post__sidebar-inner'), a=outer.getBoundingClientRect(), b=inner.getBoundingClientRect(), style=getComputedStyle(outer);
    return {tail:a.bottom-b.bottom,border:parseFloat(style.borderBottomWidth)||0,background:style.backgroundImage,align:style.alignSelf,overflow:getComputedStyle(inner).overflowY};
   }));
   assert.ok(geometry.every(g=>g.tail<=g.border+.5), 'Outer sidebar exposes a bottom background strip: '+JSON.stringify(geometry));
   assert.ok(geometry.every(g=>g.background!=='none' && g.align==='start' && g.overflow==='visible'), 'Fix removed the background, stretched the grid item or introduced inner scrolling');
  }
  async function hidden(expected,count=3){
   assert.equal(await page.locator('.atf-post__sidebar').first().isVisible(),!expected,JSON.stringify(await page.evaluate(()=>({root:document.documentElement.dataset.atfPostbitSidebar,body:document.body.dataset.atfPostbitSidebar,checked:document.querySelector('[name="postbit_sidebar_visible"]').checked,stored:localStorage.getItem('af-presentation-preferences:v1:postbit_sidebar_hidden'),status:document.querySelector('[role="status"]').textContent,uid:document.querySelector('[data-atf-preferences-uid]').dataset.atfPreferencesUid,aside:document.querySelector('.atf-post__sidebar').getBoundingClientRect().toJSON(),display:getComputedStyle(document.querySelector('.atf-post__sidebar')).display,columns:getComputedStyle(document.querySelector('.atf-post__layout')).gridTemplateColumns,areas:getComputedStyle(document.querySelector('.atf-post__layout')).gridTemplateAreas}))));
   assert.equal(await page.locator('.atf-post__topbar:visible').count(),count);
   assert.equal(await page.locator('.atf-post__sidebar canvas').count(),expected?0:count);
   assert.equal(await page.locator('.atf-post__topbar canvas').count(),count);
   assert.equal(await page.evaluate(()=>afElementCanvasEngine.diagnostics().instances),count*(expected?1:2));
   if(!expected && await page.evaluate(()=>innerWidth<=768)) assert.equal(await page.locator('.atf-post__layout').first().evaluate(e=>getComputedStyle(e).gridTemplateAreas),'"sidebar" "content"');
   if(expected){assert.equal(await page.locator('.atf-post__layout').first().evaluate(e=>getComputedStyle(e).gridTemplateColumns.split(' ').length),1);assert.ok(await page.locator('#post-1').evaluate(e=>Math.abs(e.querySelector('.atf-post__content').getBoundingClientRect().width-e.querySelector('.atf-post__layout').getBoundingClientRect().width)<2));}
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Horizontal overflow');
   if(!expected) await noSidebarTail();
  }
  await load(true);assert.equal(await page.evaluate(()=>earlySidebar),'hidden');assert.equal(await page.evaluate(()=>createdSidebarCanvases),0);await hidden(true);
  assert.equal(await page.locator('.atf-theme-preferences button[type="submit"]').count(),0);
  const beforeLayoutSave=saves;held=new Promise(resolve=>release=resolve);
  await page.locator('input[value="full"]').click();
  await page.waitForFunction(()=>document.querySelector('[data-atf-layout-preferences]').getAttribute('aria-busy')==='true');
  assert.equal(await page.evaluate(()=>document.body.classList.contains('atf-forum-layout--full')),true,'Account layout did not apply immediately');
  assert.ok(await page.locator('.atf-theme-preferences input[type="radio"],.atf-theme-preferences input[type="checkbox"]').evaluateAll(inputs=>inputs.every(i=>i.disabled)));
  await page.evaluate(()=>{const i=document.querySelector('input[value="grid"]');i.checked=true;i.dispatchEvent(new Event('change',{bubbles:true}));});
  await page.waitForTimeout(30);assert.equal(saves,beforeLayoutSave+1,'Concurrent or duplicated autosave');
  held=null;release();await page.waitForFunction(()=>document.querySelector('[data-atf-layout-preferences]').getAttribute('aria-busy')==='false');
  assert.equal(serverLayout,'full');assert.equal(serverHidden,true,'Forum save overwrote sidebar');
  fail=true;await page.locator('input[value="grid"]').click();await page.waitForFunction(()=>document.querySelector('[data-atf-layout-preferences]').getAttribute('aria-busy')==='false');
  assert.equal(await page.evaluate(()=>document.body.classList.contains('atf-forum-layout--full')),true,'Failed account layout save did not roll back');
  assert.match(await page.locator('.atf-theme-preferences [role="status"]').textContent(),/отменено/);
  await load(true);assert.equal(await page.evaluate(()=>document.body.classList.contains('atf-forum-layout--full')),true,'Saved account layout did not survive reload');
  await page.evaluate(()=>window.scrollTo(0,document.getElementById('post-1').getBoundingClientRect().top+scrollY+30));
  await page.waitForFunction(()=>getComputedStyle(document.querySelector('#post-1 .atf-post__topbar')).transform!=='none');
  assert.ok(Math.abs((await page.locator('#post-1 .atf-post__topbar').boundingBox()).y)<2,'Hidden sidebar broke sticky topbar');
  await page.evaluate(()=>window.scrollTo(0,0));
  const palette=await page.evaluate(()=>getComputedStyle(document.body).getPropertyValue('--af-element-accent'));
  await toggle();await hidden(false);assert.equal(serverHidden,false);
  const bg=await page.locator('.atf-post__sidebar-inner').first().evaluate(e=>({image:e.style.backgroundImage,overlay:e.style.getPropertyValue('--af-apui-postbit-author-overlay')}));
  // Geometry belongs to ATF, regardless of Canvas or an APUI image.
  const original = await page.locator('#post-1 .atf-post__content').evaluate(e=>({html:e.innerHTML,style:e.getAttribute('style')}));
  const sidebarStyle = await page.locator('#post-1 .atf-post__sidebar-inner').getAttribute('style');
  await page.locator('#post-1 .atf-post__content').evaluate(e=>{e.innerHTML='Short message';e.style.minHeight='60px';});
  await noSidebarTail();
  await page.evaluate(()=>afElementEffects.setPreferences({effects_enabled:false}));
  assert.equal(await page.locator('.af-element-canvas').count(),0);await noSidebarTail();
  await page.locator('#post-1 .atf-post__sidebar-inner').evaluate(e=>{e.style.removeProperty('background-image');e.style.setProperty('--af-apui-postbit-author-bg-image','none');});
  await noSidebarTail();
  await page.evaluate(()=>afElementEffects.setPreferences({effects_enabled:true}));await noSidebarTail();
  await page.evaluate(()=>afElementEffects.setPreferences({effects_enabled:false}));
  await page.locator('#post-1 .atf-post__sidebar-inner').evaluate((e,style)=>e.setAttribute('style',style),sidebarStyle);
  await page.locator('#post-1 .atf-post__content').evaluate((e,o)=>{e.innerHTML=o.html;e.setAttribute('style',o.style||'');e.style.minHeight='1200px';},original);
  await page.evaluate(()=>afElementEffects.setPreferences({effects_enabled:true}));await hidden(false);
  await page.evaluate(()=>window.scrollTo(0,document.getElementById('post-1').getBoundingClientRect().top+scrollY+120));
  await page.waitForFunction(()=>getComputedStyle(document.querySelector('#post-1 .atf-post__sidebar-inner')).transform!=='none');
  await noSidebarTail();
  await page.evaluate(()=>window.scrollTo(0,0));
  await page.locator('#post-1 .atf-post__content').evaluate((e,o)=>{e.innerHTML=o.html;if(o.style===null)e.removeAttribute('style');else e.setAttribute('style',o.style);},original);
  await toggle();await hidden(true);const frames=await page.evaluate(()=>afElementCanvasEngine.diagnostics().hosts.filter(h=>h.role==='postbit-topbar').reduce((n,h)=>n+h.frames,0));await page.waitForTimeout(180);assert.ok(await page.evaluate(()=>afElementCanvasEngine.diagnostics().hosts.filter(h=>h.role==='postbit-topbar').reduce((n,h)=>n+h.frames,0))>frames,'Topbar stopped with sidebar');
  await load(true);await hidden(true);fail=true;await toggle();await hidden(true);assert.match(await page.locator('.atf-theme-preferences [role="status"]').textContent(),/отменено/);
  await page.locator('#post-1 .edit').click();await page.locator('#post-1 .atf-post__message').evaluate(e=>e.innerHTML='<form><textarea style="width:100%;box-sizing:border-box">Quick edit fixture</textarea></form>');await hidden(true);
  await page.locator('main').evaluate((e,html)=>e.insertAdjacentHTML('beforeend',html),post(4));await page.waitForFunction(()=>document.querySelectorAll('.atf-post__topbar canvas').length===4);await hidden(true,4);
  await toggle();await hidden(false,4);assert.deepEqual(await page.locator('.atf-post__sidebar-inner').first().evaluate(e=>({image:e.style.backgroundImage,overlay:e.style.getPropertyValue('--af-apui-postbit-author-overlay')})),bg);assert.equal(await page.evaluate(()=>getComputedStyle(document.body).getPropertyValue('--af-element-accent')),palette);
  await page.setViewportSize({width:390,height:844});await toggle();await hidden(true,4);await toggle();await hidden(false,4);
  assert.equal(await page.evaluate(()=>afElementCanvasEngine.diagnostics().schedulerLoops),1);
  await page.goto('https://forum.test/fixture');await page.evaluate(k=>localStorage.setItem(k,'1'),storageKey);await load(false);await hidden(true);assert.equal(await page.evaluate(()=>earlySidebar),'hidden');assert.equal(await page.evaluate(()=>createdSidebarCanvases),0);
  const guestSaves=saves;await toggle();await hidden(false);assert.equal(saves,guestSaves);assert.equal(await page.evaluate(k=>localStorage.getItem(k),storageKey),'0');await load(false);await hidden(false);
  await page.evaluate(()=>{Storage.prototype.setItem=function(){throw new Error('blocked');};});await toggle();await hidden(false);assert.match(await page.locator('.atf-theme-preferences [role="status"]').textContent(),/отменено/);
  serverHidden=false;await page.goto('https://forum.test/fixture');await page.evaluate(k=>localStorage.setItem(k,'1'),storageKey);await load(true);await hidden(false);assert.equal(await page.evaluate(()=>earlySidebar),'visible','Guest storage overrode account state');
  await page.emulateMedia({reducedMotion:'reduce'});await page.waitForFunction(()=>afElementCanvasEngine.diagnostics().schedulerLoops===0);await toggle();await hidden(true);assert.equal(await page.evaluate(()=>afElementCanvasEngine.diagnostics().schedulerLoops),0);
  assert.deepEqual(errors,[]);
  console.log(`ATF postbit sidebar ${ff?'Firefox':'Chromium'}: early account/guest bootstrap, full-width grid, multiple posts, AJAX insert/edit fixtures, desktop/mobile/no overflow, independent topbar Canvas, cleanup/resume/no duplicates, save/rollback/reload, guest storage failure/isolation and reduced motion passed.`);
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});

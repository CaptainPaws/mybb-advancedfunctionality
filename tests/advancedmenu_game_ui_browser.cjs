'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium, firefox } = require('playwright');
const root = path.resolve(__dirname, '..'), assets = path.join(root, 'inc/plugins/advancedfunctionality/addons/advancedmenu/assets');
(async () => {
 const ff = process.env.AF_TEST_BROWSER === 'firefox';
 const browser = await (ff ? firefox : chromium).launch({ headless:true, ...(!ff ? {executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium',args:['--no-sandbox']} : {}) });
 try {
  const page=await browser.newPage({viewport:{width:1100,height:800}}), errors=[];
  page.on('pageerror',e=>errors.push(String(e)));
  await page.route('https://forum.test/**',route=>{
   const url=new URL(route.request().url()), name=path.basename(url.pathname);
   if (url.pathname === '/menu-fixture') return route.fulfill({body:'<html><body></body></html>',contentType:'text/html'});
   const dir=url.pathname.includes('/font-awesome-6/') ? path.join(root,'inc/plugins/advancedfunctionality/addons/advancedfontawesome/assets/font-awesome-6',name.endsWith('.woff2')?'webfonts':'css') : path.join(root,'inc/plugins/advancedfunctionality/addons',url.pathname.includes('adaptivethemeframework')?'adaptivethemeframework':'advancedelementtheme','assets');
   return fs.existsSync(path.join(dir,name)) ? route.fulfill({body:fs.readFileSync(path.join(dir,name)),contentType:name.endsWith('.js')?'application/javascript; charset=utf-8':name.endsWith('.woff2')?'font/woff2':'text/css; charset=utf-8'}) : route.abort();
  });
  const fixture=JSON.parse(execFileSync(process.env.PHP_BINARY || 'php',[path.join(__dirname,'advancedmenu_game_ui_regression.php'),'--browser-fixture'],{encoding:'utf8'}));
  async function load(markup, member) {
   await page.goto('https://forum.test/menu-fixture');
   await page.setContent(`<link rel="stylesheet" href="https://forum.test/font-awesome-6/css/all.min.css"><style>${fs.readFileSync(path.join(assets,'advancedmenu.css'),'utf8')}${fs.readFileSync(path.join(root,'inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/surfaces/forum.css'),'utf8')}body{margin:0;background:#0e1520;color:white}#forum{height:1600px;max-width:100%;box-sizing:border-box;padding:20px}</style><body class="af-advancedmenu-layout ${member?'af-am-member':'af-am-guest'} atf-active atf-forum-layout--full">${markup}<main id="forum"><nav class="navigation atf-breadcrumbs">Breadcrumbs</nav><div class="atf-forumdisplay__subforum-cards"><div class="atf-forum-card">Subforum A</div><div class="atf-forum-card">Subforum B</div></div><div class="atf-forum-category__forums"><div class="atf-forum-card">Forum A</div><div class="atf-forum-card">Forum B</div></div>Forum content <button id="outside">Outside</button></main></body>`);
   await page.addScriptTag({content:fs.readFileSync(path.join(assets,'advancedmenu.js'),'utf8')});
  }
  await load(fixture.member,true);
  const drawer=page.locator('#af-am-user-drawer'), shell=page.locator('[data-af-am-drawer-shell]');
  for(const section of ['profile','links','settings','theme']) {
   const control=page.locator(`[data-af-am-category="${section}"]`);
   await control.click();
   assert.equal(await control.getAttribute('aria-expanded'),'true');
   assert.equal(await drawer.locator(`[data-af-am-panel="${section}"]`).isVisible(),true);
   assert.ok(await page.evaluate(()=>document.getElementById('af-am-user-drawer').contains(document.activeElement)));
  }
  assert.ok((await drawer.boundingBox()).height<740,'Drawer fills viewport');
  if(process.env.AF_MENU_SCREENSHOT) await page.screenshot({path:process.env.AF_MENU_SCREENSHOT});
  await page.locator('[data-af-am-category="theme"]').click(); assert.equal(await shell.isVisible(),false);
  await page.locator('[data-af-am-category="settings"]').click();
  assert.equal(await page.locator('#af-am-panel-settings').getByText('ACP custom action').count(),1);
  const tabbables=await drawer.locator('button,a,input,select').evaluateAll(nodes=>nodes.filter(n=>!n.closest('[hidden]')&&n.getClientRects().length).map(n=>n.id||n.tagName));
  assert.ok(tabbables.length>1);
  await page.locator('.af-am-drawer-close').focus(); await page.keyboard.press('Shift+Tab');
  assert.ok(await page.evaluate(()=>document.getElementById('af-am-user-drawer').contains(document.activeElement)));
  await page.keyboard.press('Tab'); assert.equal(await page.locator('.af-am-drawer-close').evaluate(e=>e===document.activeElement),true);
  const tab=page.locator('[data-af-am-tab="settings"]'); await tab.focus(); await page.keyboard.press('ArrowRight');
  assert.equal(await page.locator('#af-am-panel-theme').isVisible(),true);
  await page.keyboard.press('Escape'); assert.equal(await shell.isVisible(),false);
  assert.equal(await page.locator('[data-af-am-category="settings"]').evaluate(e=>e===document.activeElement),true);
  await page.locator('[data-af-am-category="profile"]').click(); await page.locator('.af-am-drawer-overlay').click({position:{x:900,y:400}}); assert.equal(await shell.isVisible(),false);
  const avatar=page.locator('.af-am-avatar-control > a'); assert.match(await avatar.getAttribute('href'),/uid=42/);
  await avatar.hover(); assert.equal(await page.locator('#af-am-account-tooltip').isVisible(),true);
  await page.mouse.move(900,700); await avatar.focus(); assert.equal(await page.locator('#af-am-account-tooltip').isVisible(),true);
  assert.match(await page.locator('#af-am-account-tooltip').innerText(),/Player.*recently/s);
  const geometry=await page.evaluate(()=>({rail:document.querySelector('.af-am-rail').getBoundingClientRect().right,content:document.getElementById('forum').getBoundingClientRect().left,width:document.documentElement.scrollWidth,viewport:innerWidth,height:document.querySelector('.af-am-rail').getBoundingClientRect().height,offset:document.querySelector('.af-am-navigation').getBoundingClientRect().height}));
  assert.ok(geometry.rail===geometry.content && geometry.width<=geometry.viewport,'Rail overlays forum');
  assert.equal(geometry.height,800); assert.equal(geometry.offset,0,'ATF would use full rail as top offset');
  const main=page.locator('.af-am-main .af-am-link').first();
  const url=await main.getAttribute('href'); assert.match(url,/index.php/);
  await main.hover(); assert.match(await page.locator('#af-am-rail-tooltip').innerText(),/Main registry/);
  assert.ok((await page.locator('#af-am-rail-tooltip').boundingBox()).x>=64,'Tooltip clipped inside rail');
  await page.mouse.move(900,700); await main.focus(); assert.equal(await page.locator('#af-am-rail-tooltip').isVisible(),true);
  await page.keyboard.press('Escape'); assert.equal(await page.locator('#af-am-rail-tooltip').isVisible(),false);
  const contentWidth=(await page.locator('#forum').boundingBox()).width;
  await page.locator('[data-af-am-category="profile"]').click(); assert.equal(await page.locator('#af-am-rail-tooltip').isVisible(),false);
  assert.equal((await page.locator('#forum').boundingBox()).width,contentWidth,'Drawer caused layout shift');
  assert.ok((await drawer.boundingBox()).height<300,'Short drawer is not content-sized'); await page.keyboard.press('Escape');
  await page.evaluate(()=>{document.body.style.setProperty('--atf-color-surface','#eef2f7');document.body.style.setProperty('--atf-color-text','#172233');document.body.style.setProperty('--atf-color-page-subtle','#e2e8f0');});
  assert.equal(await page.locator('.af-am-rail').evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(226, 232, 240)');
  assert.equal(await drawer.evaluate(e=>getComputedStyle(e).color),'rgb(23, 34, 51)');
  await page.evaluate(()=>document.body.removeAttribute('style'));
  await page.addScriptTag({content:fs.readFileSync(path.join(assets,'advancedmenu.js'),'utf8')});
  await page.locator('[data-af-am-category="profile"]').click(); assert.equal(await shell.isVisible(),true,'Duplicate handler toggled drawer closed'); await page.keyboard.press('Escape');
  await page.evaluate(()=>{
   document.body.insertAdjacentHTML('beforeend','<div id="fixture-modal" hidden style="position:fixed;inset:0;z-index:10000;background:#192535"><button id="modal-close">Close modal</button></div>');
   document.getElementById('fixture-modal-trigger').addEventListener('click',e=>{e.preventDefault();document.getElementById('fixture-modal').hidden=false;});
  });
  await page.locator('#fixture-modal-trigger').click();
  assert.equal(await page.evaluate(()=>document.elementFromPoint(20,20).closest('#fixture-modal')!==null),true,'Modal is below navigation');
  await page.evaluate(()=>document.getElementById('fixture-modal').remove());
  await page.setViewportSize({width:1100,height:300});
  await page.locator('[data-af-am-category="theme"]').click();
  assert.ok((await drawer.boundingBox()).height<=268);
  assert.ok(await page.locator('.af-am-drawer-body').evaluate(e=>e.scrollHeight>e.clientHeight),'Long category does not scroll internally');
  await page.keyboard.press('Escape');
  assert.ok(await page.locator('.af-am-rail-scroll').evaluate(e=>e.scrollHeight>e.clientHeight),'Short-height rail has no inner scrolling');
  assert.equal(await page.evaluate(()=>document.documentElement.scrollTop),0);
  await page.locator('[data-af-am-category="theme"]').focus();
  assert.ok(await page.locator('.af-am-rail-scroll').evaluate(e=>e.scrollTop>0),'Keyboard focus did not reveal overflowing items');
  await page.setViewportSize({width:1100,height:800});
  await page.setViewportSize({width:375,height:740});
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Mobile horizontal overflow');
  for(const section of ['profile','links','settings','theme']) {await page.locator(`[data-af-am-category="${section}"]`).click(); await page.keyboard.press('Escape');}
  await page.locator('[data-af-am-category="profile"]').click();
  await page.locator('[data-af-am-category="links"]').click(); assert.equal(await page.locator('#af-am-panel-links').isVisible(),true);
  await page.locator('[data-af-am-category="links"]').click(); assert.equal(await shell.isVisible(),false);
  await page.locator('.af-am-account-info').click(); assert.equal(await page.locator('.af-am-account-info').getAttribute('aria-expanded'),'true');
  await page.locator('.af-am-account-info').click(); assert.equal(await page.locator('.af-am-account-info').getAttribute('aria-expanded'),'false');
  assert.equal(await page.locator('.af-am-rail').evaluate(e=>e.getBoundingClientRect().width),375);
  assert.equal(await page.locator('#forum').evaluate(e=>e.getBoundingClientRect().left),0);
  await page.setViewportSize({width:1100,height:800});
  await load(fixture.guest,false); assert.equal(await page.locator('[data-af-am-category]').count(),1); await page.locator('[data-af-am-category="theme"]').click(); assert.equal(await page.locator('#af-am-user-drawer').isVisible(),true);
  await page.locator('.atf-forum-layout-option').filter({has:page.locator('input[value="grid"]')}).click();
  assert.equal(await page.evaluate(()=>document.body.classList.contains('atf-forum-layout--grid')),true);
  assert.equal(await page.evaluate(()=>localStorage.getItem('af-presentation-preferences:v1:forum_layout')),'grid');
  assert.equal(await page.locator('.atf-forum-category__forums').evaluate(e=>getComputedStyle(e).gridTemplateColumns.split(' ').length),2,'ATF renderer did not change columns');
  assert.equal(await page.locator('.atf-forumdisplay__subforum-cards').evaluate(e=>getComputedStyle(e).gridTemplateColumns.split(' ').length),2,'Forumdisplay subforum renderer did not change columns');
  await load(fixture.guest,false);
  assert.equal(await page.evaluate(()=>document.body.classList.contains('atf-forum-layout--grid')),true);
  await page.keyboard.press('Escape');
  await page.setViewportSize({width:1100,height:800});
  await page.locator('.af-am-guest-avatar').focus();
  assert.equal(await page.locator('#af-am-account-tooltip').textContent(),'Добро пожаловать, гость!');
  assert.equal(await page.locator('#af-am-account-tooltip').isVisible(),true);
  assert.equal(await page.locator('.af-am-rail-scroll').evaluate(e=>getComputedStyle(e).scrollbarWidth),'none');
  await page.evaluate(()=>{Storage.prototype.setItem=function(){throw new Error('blocked storage');};});
  await page.locator('[data-af-am-category="theme"]').click();
  await page.locator('.atf-forum-layout-option').filter({has:page.locator('input[value="full"]')}).click();
  assert.equal(await page.evaluate(()=>document.body.classList.contains('atf-forum-layout--full')),true);
  assert.match(await page.locator('.atf-theme-preferences [role="status"]').textContent(),/до перезагрузки/);
  await load(fixture.member,true);
  assert.equal(await page.evaluate(()=>document.body.classList.contains('atf-forum-layout--grid')),false);
  // Shared route/layout fixtures, not production MyBB pages.
  for(const route of ['index.php','forumdisplay.php','showthread.php','member.php','usercp.php','kb.php','charactersheets.php','shop.php','inventory.php']) {
   await load(fixture.member,true);
   await page.locator('#forum').evaluate((e,r)=>e.dataset.route=r,route);
   await page.addStyleTag({content:fs.readFileSync(path.join(root,'inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/surfaces/navigation.css'),'utf8')});
   await page.addStyleTag({content:'body{--atf-page-max-width:1200px;--atf-page-gap:16px;--atf-space-3:12px}'});
   await page.evaluate(()=>document.body.classList.add('atf-active'));
   assert.equal(await page.locator('#forum').evaluate(e=>e.getBoundingClientRect().left),64,route);
   assert.ok(await page.locator('.atf-breadcrumbs').evaluate(e=>e.getBoundingClientRect().left>=64),route+' breadcrumbs');
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),route+' overflow');
  }
  // Actual unmodified ATF sticky runtime, using a controlled post geometry.
  await page.locator('#forum').evaluate(e=>e.innerHTML='<div id="posts"><article class="atf-post" style="height:1800px"><div class="atf-post__topbar" style="height:60px">Topbar</div><div class="atf-post__sidebar-inner" style="height:260px">Author</div><div class="atf-post__meta-line" style="height:80px">Meta</div></article></div>');
  await page.addScriptTag({content:fs.readFileSync(path.join(root,'inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/adaptivethemeframework.postbit-sticky.js'),'utf8')});
  await page.evaluate(()=>scrollTo(0,400)); await page.waitForTimeout(120);
  assert.ok(Math.abs((await page.locator('.atf-post__topbar').boundingBox()).y)<2,'Rail height broke ATF sticky topbar');
  assert.ok(Math.abs((await page.locator('.atf-post__sidebar-inner').boundingBox()).y-60)<2,'Rail height broke sticky sidebar');
  assert.equal((await page.locator('.af-am-rail').boundingBox()).y,0);
  await page.emulateMedia({reducedMotion:'reduce'}); await page.locator('[data-af-am-category="profile"]').click();
  assert.equal(await drawer.evaluate(e=>getComputedStyle(e).animationName),'none');
  assert.deepEqual(errors,[]);
  console.log(`AdvancedMenu ${ff?'Firefox':'Chromium'}: categories, toggle/switch, Escape/overlay, focus trap/return, tabs, tooltip/avatar, registry custom action, single rail, compact/scrolling drawer, shared tooltips, desktop/mobile, nine layout fixtures, modal stacking, real ATF sticky runtime, reduced motion and guest passed.`);
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});

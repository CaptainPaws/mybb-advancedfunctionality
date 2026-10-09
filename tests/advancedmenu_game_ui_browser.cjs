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
   const dir=url.pathname.includes('/font-awesome-6/') ? path.join(root,'inc/plugins/advancedfunctionality/addons/advancedfontawesome/assets/font-awesome-6',name.endsWith('.woff2')?'webfonts':'css') : path.join(root,'inc/plugins/advancedfunctionality/addons/advancedelementtheme/assets');
   return fs.existsSync(path.join(dir,name)) ? route.fulfill({body:fs.readFileSync(path.join(dir,name)),contentType:name.endsWith('.js')?'application/javascript; charset=utf-8':name.endsWith('.woff2')?'font/woff2':'text/css; charset=utf-8'}) : route.abort();
  });
  const fixture=JSON.parse(execFileSync(process.env.PHP_BINARY || 'php',[path.join(__dirname,'advancedmenu_game_ui_regression.php'),'--browser-fixture'],{encoding:'utf8'}));
  async function load(markup, member) {
   await page.setContent(`<link rel="stylesheet" href="https://forum.test/font-awesome-6/css/all.min.css"><style>${fs.readFileSync(path.join(assets,'advancedmenu.css'),'utf8')}body{margin:0;background:#0e1520;color:white}#forum{height:1600px;max-width:100%;box-sizing:border-box;padding:20px}</style><body class="af-advancedmenu-layout ${member?'af-am-member':'af-am-guest'}">${markup}<main id="forum">Forum content <button id="outside">Outside</button></main></body>`);
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
  const geometry=await page.evaluate(()=>({rail:document.querySelector('.af-am-user-controls').getBoundingClientRect().right,content:document.getElementById('forum').getBoundingClientRect().left,width:document.documentElement.scrollWidth,viewport:innerWidth}));
  assert.ok(geometry.rail<=geometry.content && geometry.width<=geometry.viewport,'Rail overlays forum');
  await page.setViewportSize({width:375,height:740});
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Mobile horizontal overflow');
  for(const section of ['profile','links','settings','theme']) {await page.locator(`[data-af-am-category="${section}"]`).click(); await page.keyboard.press('Escape');}
  await page.locator('[data-af-am-category="profile"]').click();
  await page.locator('[data-af-am-category="links"]').click(); assert.equal(await page.locator('#af-am-panel-links').isVisible(),true);
  await page.locator('[data-af-am-category="links"]').click(); assert.equal(await shell.isVisible(),false);
  await page.locator('.af-am-account-info').click(); assert.equal(await page.locator('.af-am-account-info').getAttribute('aria-expanded'),'true');
  await page.locator('.af-am-account-info').click(); assert.equal(await page.locator('.af-am-account-info').getAttribute('aria-expanded'),'false');
  await load(fixture.guest,false); assert.equal(await page.locator('[data-af-am-category]').count(),0); await page.locator('.af-am-burger').click(); assert.equal(await page.locator('#af-am-user-drawer').isVisible(),true);
  assert.deepEqual(errors,[]);
  console.log(`AdvancedMenu ${ff?'Firefox':'Chromium'}: categories, toggle/switch, Escape/overlay, focus trap/return, tabs, tooltip/avatar, registry custom action, desktop/mobile geometry and guest passed.`);
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});

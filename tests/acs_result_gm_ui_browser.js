'use strict';
const assert=require('node:assert/strict');const fs=require('node:fs');const path=require('node:path');const {chromium}=require('playwright');
const fixture=JSON.parse(fs.readFileSync(process.env.ACS_PRESENTATION_FIXTURE||'/tmp/acs-stage6-presentation.json','utf8'));
const css=fs.readFileSync(path.join(__dirname,'../inc/plugins/advancedfunctionality/addons/advancedcombatsystem/assets/presentation.css'),'utf8');
(async()=>{const browser=await chromium.launch({headless:true,...(fs.existsSync('/usr/bin/chromium')?{executablePath:'/usr/bin/chromium'}:{}),args:['--no-sandbox']});try{
 const page=await browser.newPage({viewport:{width:390,height:844}});let posts=[];const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://fixture.local/**',async route=>{if(route.request().method()==='POST'){posts.push(new URLSearchParams(route.request().postData()));await route.fulfill({contentType:'text/html; charset=UTF-8',body:'<p>Saved fixture response</p>'});}else await route.fulfill({contentType:'text/html; charset=UTF-8',body:'<!doctype html><html><head><meta charset="utf-8"><style>'+css+'</style></head><body>'+fixture.review+'</body></html>'});});
 await page.goto('https://fixture.local/misc.php?action=acs_recovery');
 assert.equal(await page.locator('form').count(),1);assert.equal(await page.locator('textarea').count(),1);assert.equal(await page.locator('textarea').getAttribute('data-af-ae-skip'),'1');assert.equal(await page.locator('input[name=pid]').count(),0);
 await page.getByRole('button',{name:'Записать решение'}).click();assert.equal(posts.length,0,'Missing reason submitted');
 await page.locator('textarea[name=reason]').fill('Known evidence checked');await page.getByRole('button',{name:'Записать решение'}).click();await page.waitForFunction(()=>document.body.textContent==='Saved fixture response');
 assert.equal(posts.length,1);assert.equal(posts[0].get('my_post_key'),'token');assert.ok(posts[0].get('expected_state_version'));assert.equal(posts[0].get('strategy'),'hold');assert.equal(posts[0].get('reason'),'Known evidence checked');assert.equal(posts[0].has('pid'),false);
 for(const type of ['confirmed','limited','pending']){
  await page.setContent('<!doctype html><html><head><meta charset="utf-8"><style>'+css+'</style></head><body>'+fixture[type]+'</body></html>');
  assert.equal(await page.locator('script').count(),0);assert.equal(await page.locator('textarea,iframe').count(),0);
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'Card overflow on narrow viewport');
  if(type==='confirmed'){const details=page.locator('details').first();await details.locator('summary').focus();await page.keyboard.press('Enter');assert.equal(await details.getAttribute('open'),'');assert.ok((await page.locator('aside').innerText()).includes('<script>leak()</script>'),'Escaped name not plain text');}
  else {assert.equal(await page.locator('details').count(),0);assert.ok(!(await page.locator('aside').innerText()).includes('Урон:'));}
 }
 assert.deepEqual(errors,[]);console.log('ACS presentation browser: responsive stored card, keyboard details, escaped text, no hidden numbers/editor, required GM reason and native CSRF/CAS form passed');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});

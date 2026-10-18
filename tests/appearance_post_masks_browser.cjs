'use strict';
const assert=require('node:assert/strict'),{chromium}=require('playwright');
(async()=>{
 const origin=process.argv[2]||'http://127.0.0.1:8100';
 const browser=await chromium.launch({executablePath:process.env.AA_TEST_CHROMIUM||'/usr/bin/chromium',args:['--no-sandbox']});const page=await browser.newPage({viewport:{width:1280,height:1000}});page.setDefaultTimeout(12000);
 const errors=[],sent=[];page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>{if(r.method()==='POST'&&/\/xmlhttp\.php|\/newreply\.php/.test(r.url()))sent.push({url:r.url(),data:r.postData()});});
 const api=async(operation,pid,extra={})=>{const r=await page.request.post(origin+'/apstudio.php?api=mask',{data:{operation,pid,fid:1,tid:10,my_post_key:'appearance-fixture-key',...extra}});return {status:r.status(),body:await r.json()};};
 const color=pid=>page.locator('#post_'+pid+' .atf-post__message-body').evaluate(n=>getComputedStyle(n).color);
 await page.goto(origin+'/showthread.php?tid=10');
 assert.equal(await color(101),'rgb(255, 119, 170)');assert.equal(await color(102),'rgb(187, 136, 238)');assert.equal(await color(103),'rgb(221, 238, 255)');
 assert.match(await page.locator('#post_101 .atf-post__primary-avatar-media img').getAttribute('src'),/mine.png/);assert.match(await page.locator('#post_102 .atf-post__primary-avatar-media img').getAttribute('src'),/four.png/);
 assert.equal(await page.locator('[data-af-aa-mask-css]').count(),2);assert.ok(await page.locator('script[src*="element-canvas-engine.js"]').count()<=1);assert.equal(await page.locator('[data-af-command="af_post_mask"]').count(),1);
 assert.equal(await page.locator('script[src*="advancedgallery.js"]').count(),1,'Mask dialog needs a real Gallery script, not only a lazy URL in editor config');
 const shape=await page.locator('#post_101 .atf-post__layout').evaluate(n=>getComputedStyle(n).gridTemplateColumns);
 await page.evaluate(()=>window.originalMaskFrame=document.querySelector('#post_101 .atf-post__secondary-avatar > .atf-gothic-frame'));
 const checkFrame=async()=>{
  assert.ok(await page.evaluate(()=>window.originalMaskFrame&&window.originalMaskFrame===document.querySelector('#post_101 .atf-post__secondary-avatar > .atf-gothic-frame')),'Sidebar frame was replaced or removed');
  assert.equal(await page.locator('#post_101 .atf-post__secondary-avatar .atf-gothic-corner').count(),4);
 };
 await checkFrame();
 const initialSource=(await api('load',101)).body.data.mask.source_preset_id;
 const presetList=(await api('load',0)).body.data.presets,purple=presetList.find(p=>p.title==='Purple').id;
 assert.equal((await api('load',104)).status,403);assert.equal((await api('preview',101,{my_post_key:'bad',mask_json:'{"mode":"clear"}'})).status,403);
 // Real MyBB button -> jEditable -> Editor lifecycle, not a synthetic parallel editor.
 await page.locator('#quick_edit_post_101').click();await page.locator('#quickedit_101').waitFor();
 await page.locator('#post_101 [data-af-command="af_post_mask"]').click();await page.locator('.af-aa-mask-modal').waitFor();assert.equal(await page.locator('.af-aa-mask-modal select').inputValue(),'keep');
 await page.getByRole('button',{name:'Сбросить маску',exact:true}).click();await page.getByRole('button',{name:'Отмена правки',exact:true}).click();await page.locator('#quickedit_101').waitFor({state:'detached'});assert.equal(await color(101),'rgb(255, 119, 170)');assert.equal((await api('load',101)).body.data.mask.source_preset_id,initialSource);
 // Choose a different snapshot, independent avatar IDs through the shipped Gallery picker.
 const purposes=[];let picked=4;
 await page.route('**/gallery.php?**',async route=>{const url=new URL(route.request().url()),action=url.searchParams.get('action');if(action==='picker'){purposes.push(url.searchParams.get('purpose'));await route.fulfill({contentType:'text/html',body:'<div id="af-gallery-picker" class="ag-picker" data-purpose="appearance_avatars" data-initial-album="1"><div class="ag-picker-dialog"><form><input name="my_post_key" value="appearance-fixture-key"></form><div class="ag-picker-grid" data-ag-picker-grid="1"></div></div></div>'});}else if(action==='picker_select')await route.fulfill({json:{ok:true,media_id:picked,url_full:origin+'/uploads/'+(picked===4?'four':'mine')+'.png'}});else await route.fulfill({json:{items:[{id:picked,media_id:picked,type:'local',title:'Mine',url_full:origin+'/uploads/'+(picked===4?'four':'mine')+'.png',thumb:origin+'/uploads/mine.png'}]}});});
 await page.locator('#quick_edit_post_101').click();await page.locator('#post_101 [data-af-command="af_post_mask"]').click();await page.locator('.af-aa-mask-modal select').selectOption(String(purple));
 await page.locator('.af-aa-mask-avatar').nth(0).getByRole('button',{name:'Выбрать или загрузить в Gallery'}).click();await page.getByRole('button',{name:'Выбрать изображение'}).click();picked=1;
 await page.locator('.af-aa-mask-avatar').nth(1).getByRole('button',{name:'Выбрать или загрузить в Gallery'}).click();await page.getByRole('button',{name:'Выбрать изображение'}).click();
 await page.waitForFunction(()=>getComputedStyle(document.querySelector('.af-aa-mask-preview .atf-post__message-body')).color==='rgb(187, 136, 238)');assert.equal(await color(102),'rgb(187, 136, 238)');
 await page.getByRole('button',{name:'Применить к сообщению',exact:true}).click();await page.locator('.af-aa-mask-modal').waitFor({state:'detached'});assert.deepEqual(purposes,['appearance_avatars']);
 await page.locator('#quickedit_101').fill('Edited with mask');await page.getByRole('button',{name:'Сохранить изменения',exact:true}).click();
 await page.waitForFunction(()=>document.querySelector('#post_101 .atf-post__message-body')?.textContent==='Edited with mask'&&getComputedStyle(document.querySelector('#post_101 .atf-post__message-body')).color==='rgb(187, 136, 238)');
 await checkFrame();
 const quick=sent.find(x=>x.url.includes('update_post'));assert.ok(quick&&new URLSearchParams(quick.data).get('af_aa_mask'));assert.equal(new URLSearchParams(quick.data).get('value'),'Edited with mask');assert.equal(await color(103),'rgb(221, 238, 255)');
 assert.match(await page.locator('#post_101 .atf-post__primary-avatar-media img').getAttribute('src'),/four.png/);assert.match(await page.locator('#post_101 .atf-post__secondary-avatar img').getAttribute('src'),/mine.png/);assert.equal(await page.locator('#post_101 [data-af-aa-mask-css]').count(),1);assert.equal(await page.locator('#post_101 .atf-post__layout').evaluate(n=>getComputedStyle(n).gridTemplateColumns),shape);
 await page.locator('#quick_edit_post_101').click();await page.locator('#post_101 [data-af-command="af_post_mask"]').click();await page.getByRole('button',{name:'Сбросить маску',exact:true}).click();await page.locator('#quickedit_101').fill('Reset mask');await page.getByRole('button',{name:'Сохранить изменения',exact:true}).click();await page.waitForFunction(()=>document.querySelector('#post_101').classList.contains('af-aa-postbit-user-7'));
 await checkFrame();
 assert.equal(await color(101),'rgb(221, 238, 255)');assert.equal((await api('load',101)).body.data.mask,null);
 // Inherited APF avatar must survive a fresh runtime request after clearing the mask.
 const inherited=await api('runtime',101);
 assert.match(inherited.body.data.sidebar_html,/uploads\/avatars\/secondary_7_/);
 await page.waitForFunction(()=>document.querySelector('#post_101 .atf-post__secondary-avatar img')?.src.includes('/uploads/avatars/secondary_7_'));
 await page.evaluate(()=>{
  window.maskNodes={};
  for(const selector of ['.atf-post__sidebar-inner','.atf-post__primary-avatar-media img','.atf-post__secondary-avatar img','.atf-post__character-name']){
   const el=document.querySelector('#post_101 '+selector);if(el)window.maskNodes[selector]=el;
  }
  window.maskSidebarClicks=0;document.querySelector('#post_101 .atf-post__sidebar-inner').addEventListener('click',()=>window.maskSidebarClicks++);
 });
 const refreshed=page.waitForResponse(async r=>r.url().includes('api=mask')&&r.request().postDataJSON()?.operation==='runtime');
 await page.locator('#quick_edit_post_101').click();await page.locator('#quickedit_101').fill('Text-only edit');await page.getByRole('button',{name:'Сохранить изменения',exact:true}).click();
 await refreshed;
 await page.waitForFunction(()=>document.querySelector('#post_101 .atf-post__message-body')?.textContent==='Text-only edit');
 await checkFrame();
 assert.equal(await page.evaluate(()=>Object.entries(window.maskNodes).every(([selector,node])=>document.querySelector('#post_101 '+selector)===node)),true,'Unchanged sidebar/avatar nodes were replaced');
 await page.locator('#post_101 .atf-post__secondary-avatar img').click();
 assert.equal(await page.evaluate(()=>window.maskSidebarClicks),1,'Sidebar handler lost');
 // A native failed Quick Edit restores old text; its uncommitted mask remains available on retry.
 await page.locator('#quick_edit_post_103').click();await page.locator('#post_103 [data-af-command="af_post_mask"]').click();await page.locator('.af-aa-mask-modal select').selectOption(String(purple));await page.getByRole('button',{name:'Применить к сообщению',exact:true}).click();await page.locator('.af-aa-mask-modal').waitFor({state:'detached'});await page.locator('#quickedit_103').fill('INVALID');await page.getByRole('button',{name:'Сохранить изменения',exact:true}).click();await page.locator('#quickedit_103').waitFor({state:'detached'});await page.waitForTimeout(100);
 await page.locator('#quick_edit_post_103').click();assert.equal(JSON.parse(await page.locator('#post_103 [name="af_aa_mask"]').inputValue()).preset_id,purple);await page.getByRole('button',{name:'Отмена правки',exact:true}).click();assert.equal((await api('load',103)).body.data.mask,null);
 // Source/WYSIWYG preserve the same hidden transport field; Quick Reply serializes it natively.
 await page.locator('#quick_reply_form [data-af-command="af_post_mask"]').click();await page.locator('.af-aa-mask-modal select').selectOption(String(purple));await page.getByRole('button',{name:'Применить к сообщению',exact:true}).click();await page.locator('.af-aa-mask-modal').waitFor({state:'detached'});
 const value=await page.locator('#quick_reply_form [name="af_aa_mask"]').inputValue();await page.locator('#quick_reply_form [data-af-command="af_togglemode"]').click();await page.waitForFunction(()=>document.getElementById('message').__afAeWysiwyg||document.querySelector('#quick_reply_form iframe'));assert.equal(await page.locator('#quick_reply_form [name="af_aa_mask"]').inputValue(),value);await page.locator('#quick_reply_form [data-af-command="af_togglemode"]').click();
 await page.locator('#quick_reply_form textarea.sceditor-source').fill('Quick reply masked');await page.locator('#quick_reply_submit').click();await page.waitForFunction(()=>Array.from(document.querySelectorAll('.atf-post__message-body')).some(n=>n.textContent==='Quick reply masked'));
 const posted=await page.locator('.atf-post').filter({hasText:'Quick reply masked'}).first().getAttribute('id'),pid=Number(posted.replace('post_',''));assert.equal(await color(pid),'rgb(187, 136, 238)');assert.equal((await api('load',pid)).body.data.mask.source_preset_id,purple);
 assert.ok(await page.locator('script[src*="element-canvas-engine.js"]').count()<=1);assert.ok(await page.locator('#post_'+pid+' canvas').count()<=2);
 // Posting forms preserve a mask after native text validation fails, including refresh/local draft reserve.
 for(const path of ['/newthread.php?fid=1','/newreply.php?tid=10','/editpost.php?pid=103']) {
  await page.goto(origin+path);assert.equal(await page.locator('[data-af-command="af_post_mask"]').count(),1);await page.locator('[data-af-command="af_post_mask"]').click();await page.locator('.af-aa-mask-modal select').selectOption(String(purple));await page.getByRole('button',{name:'Применить к сообщению',exact:true}).click();await page.locator('.af-aa-mask-modal').waitFor({state:'detached'});const selected=await page.locator('[name="af_aa_mask"]').inputValue();
  await page.reload();assert.equal(await page.locator('[name="af_aa_mask"]').inputValue(),selected);
  const response=await page.request.post(origin+path,{form:{message:'INVALID',pid:path.includes('editpost')?'103':'0',my_post_key:'appearance-fixture-key',af_aa_mask:selected}});assert.ok((await response.json()).errors);assert.equal((await api('load',103)).body.data.mask,null);
  await page.locator('#message').fill('Form masked');const savedResponse=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes(path.split('?')[0]));await page.getByRole('button',{name:'Отправить',exact:true}).click();const saved=await (await savedResponse).json();assert.ok(saved.pid||path.includes('editpost'));const savedPid=saved.pid||(path.includes('editpost')?103:0);assert.equal((await api('load',savedPid)).body.data.mask.source_preset_id,purple);
 }
 await page.goto(origin+'/showthread.php?tid=10');await page.locator('#quick_reply_form [data-af-command="af_post_mask"]').click();await page.setViewportSize({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+2),true);await page.getByRole('button',{name:'Отмена',exact:true}).click();
 assert.deepEqual(errors,[]);await browser.close();console.log('Post masks browser passed: independent PIDs/avatars, real MyBB Quick Edit load/cancel/save/reset, Gallery IDs, SOURCE/WYSIWYG and Quick Reply transport, ordinary posting/edit forms, retained failed submissions/drafts, scoped CSS, inherited APF avatar after reset/text edits, preserved sidebar nodes/listeners and mobile dialog.');
})().catch(e=>{console.error(e);process.exit(1)});

'use strict';
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
 try{
  const page=await browser.newPage();page.setDefaultTimeout(10000);const errors=[],requests=[];
  page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>requests.push(r.url()));
  await page.route('**/*',async route=>{
   const url=new URL(route.request().url());let file;
   if(url.pathname.startsWith('/inc/plugins/advancedfunctionality/'))file=path.join(process.cwd(),url.pathname);
   if(url.pathname.startsWith('/jscripts/'))file=path.join(process.cwd(),'work/mybb-media',url.pathname);
   if(url.pathname.startsWith('/cache/'))file=path.join(process.cwd(),url.pathname);
   if(file && fs.existsSync(file))return route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.js')?'application/javascript':file.endsWith('.css')?'text/css':'image/svg+xml'});
   if(route.request().resourceType()==='image')return route.fulfill({status:204});
   return route.continue();
  });
  const origin='http://127.0.0.1:8101';
  await page.goto(origin+'/showthread.php?action=editor_page');
  await page.waitForFunction(()=>!!window.afAdvancedEditorShell);
  const command=cmd=>page.locator('#quick_reply_form .af-ae-shell > .sceditor-toolbar [data-af-command="'+cmd+'"]');
  assert.equal(await command('af_gallery').count(),1);
  assert.match(await command('af_gallery').locator('.af-ae-shell-icon').getAttribute('style'),/data:image\/svg/);
  assert.equal(requests.filter(u=>/advancedgallery\.js/.test(u)).length,0,'Gallery eagerly loaded');
  await page.locator('#message').evaluate(ta=>{ta.focus();ta.setSelectionRange(1,1);});
  await command('image').click();
  const modal=page.locator('#af-gallery-picker');await modal.locator('[data-ag-media-tab=url][aria-selected=true]').waitFor();
  await modal.locator('[data-ag-media-url] input').fill('https://images.example.org/one.gif');
  await modal.getByRole('button',{name:'Вставить изображение',exact:true}).click();
  assert.equal(await page.locator('#message').inputValue(),'А[img]https://images.example.org/one.gif[/img]😀Б');
  await command('af_gallery').click();await modal.locator('[data-ag-media-tab=mine][aria-selected=true]').waitFor();
  await modal.locator('[data-ag-picker-close]').last().click();
  assert.equal(await page.evaluate(()=>document.activeElement.id),'message');
  await command('image').click();await modal.locator('[data-ag-media-tab=external]').click();
  assert.equal(await modal.locator('[name=storage_provider]').inputValue(),'imgbb');
  assert.equal(await modal.locator('option[value=postimages]').count(),0);
  const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6l8AAAAASUVORK5CYII=','base64');
  await modal.locator('[name=ag_file]').setInputFiles({name:'media.png',mimeType:'image/png',buffer:png});
  await modal.getByRole('button',{name:'Загрузить',exact:true}).click();
  await page.waitForFunction(()=>document.querySelector('#message').value.includes('https://i.ibb.co/fixture/original.png'));
  // Select an existing Gallery upload using the same entry point.
  await command('af_gallery').click();await modal.locator('.ag-picker-card').first().waitFor();
  await modal.locator('.ag-picker-card-actions button').first().click();
  assert.equal(requests.filter(u=>/advancedgallery\.js/.test(u)).length,1);
  await command('af_togglemode').click();
  await page.waitForFunction(()=>!!(jQuery.fn.sceditor && jQuery('#message').sceditor('instance')));
  await page.evaluate(()=>{const ed=jQuery('#message').sceditor('instance');ed.val('AB');ed.focus();const body=ed.getBody(),text=body.firstChild.nodeType===3?body.firstChild:body.firstChild.firstChild,range=body.ownerDocument.createRange();range.setStart(text,1);range.collapse(true);ed.getRangeHelper().selectRange(range);});
  await command('image').click();await modal.locator('[data-ag-media-url] input').fill('https://images.example.org/two.png');
  await modal.getByRole('button',{name:'Вставить изображение',exact:true}).click();
  const rich=await page.evaluate(()=>jQuery('#message').sceditor('instance').val());
  assert.ok(rich.includes('A[img]https://images.example.org/two.png[/img]B'),rich);
  assert.equal(await page.locator('.af-ae-shell > .sceditor-toolbar').count(),1);
  assert.equal(await command('af_gallery').count(),1);
  await command('af_togglemode').click();
  await command('image').click();await modal.locator('[data-ag-media-tab=local]').click();
  assert.equal(await modal.locator('[name=storage_provider]').inputValue(),'local');
  await modal.locator('[name=ag_file]').setInputFiles({name:'local.png',mimeType:'image/png',buffer:png});
  await modal.getByRole('button',{name:'Загрузить',exact:true}).click();
  await page.waitForFunction(()=>!document.querySelector('#af-gallery-picker').classList.contains('is-open'));
  await page.goto(origin+'/showthread.php?action=editor_page');await page.waitForFunction(()=>!!window.afAdvancedEditorShell);
  await page.evaluate(()=>{var post=document.createElement('div');post.className='post';post.innerHTML='<div id="pid_699"><form><textarea id="quickedit_699" name="value">XY</textarea></form></div>';document.body.appendChild(post);});
  const quick=page.locator('#pid_699 .af-ae-shell');await quick.locator('[data-af-command=af_gallery]').waitFor();
  await page.locator('#quickedit_699').evaluate(ta=>{ta.focus();ta.setSelectionRange(1,1);});
  await quick.locator('[data-af-command=image]').click();await modal.locator('[data-ag-media-url] input').fill('https://images.example.org/dynamic.gif');
  await modal.getByRole('button',{name:'Вставить изображение',exact:true}).click();
  assert.equal(await page.locator('#quickedit_699').inputValue(),'X[img]https://images.example.org/dynamic.gif[/img]Y');
  // Remove a live Quick Edit while its picker is open: the callback must not target Quick Reply.
  await quick.locator('[data-af-command=image]').click();await modal.locator('[data-ag-media-url] input').fill('https://images.example.org/stale.gif');
  await page.locator('#pid_699').evaluate(node=>node.parentElement.remove());
  await page.waitForFunction(()=>!document.querySelector('#af-gallery-picker').classList.contains('is-open'));
  assert.equal(await page.locator('#message').inputValue(),'А😀Б');
  for(const script of ['newthread.php','newreply.php','editpost.php']) {
   await page.goto(origin+'/'+script+'?action=editor_page');await page.waitForFunction(()=>!!window.afAdvancedEditorShell);
   assert.equal(await command('af_gallery').count(),1);assert.equal(await page.locator('.af-ae-shell > .sceditor-toolbar').count(),1);
  }
  await command('image').click();await modal.locator('[data-ag-media-tab=external]').click();
  await modal.locator('[name=ag_file]').setInputFiles({name:'invalid.png',mimeType:'image/png',buffer:Buffer.from('<svg/>')});
  await modal.getByRole('button',{name:'Загрузить',exact:true}).click();await modal.locator('[data-ag-media-status]').filter({hasText:/изображени|формат/i}).waitFor();
  await modal.locator('[data-ag-picker-close]').last().click();
  await page.setViewportSize({width:390,height:844});
  await command('af_gallery').click();assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  await modal.locator('[data-ag-picker-close]').last().click();
  await page.goto(origin+'/showthread.php?action=editor_page&disabled=1');await page.waitForFunction(()=>!!window.afAdvancedEditorShell);
  assert.equal(await command('af_gallery').count(),0);await command('image').click();await page.locator('.ae-media-fallback').waitFor();
  assert.deepEqual(errors,[]);
  console.log('Editor media passed: one toolbar, lazy shared picker, source cursor, URL/Gallery/ImgBB/local, real WYSIWYG cursor and disabled Gallery fallback.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});

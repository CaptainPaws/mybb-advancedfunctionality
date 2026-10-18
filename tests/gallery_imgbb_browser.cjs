'use strict';
const {chromium}=require('playwright');const assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.AA_CHROMIUM||'/usr/bin/chromium',args:['--no-sandbox']});
 try {
  const page=await browser.newPage();page.setDefaultTimeout(7000);const errors=[],requests=[];
  const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6l8AAAAASUVORK5CYII=','base64');
  page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>requests.push(r.url()));
  await page.route('https://i.ibb.co/**',r=>r.fulfill({contentType:'image/png',body:png}));
  await page.goto(process.env.AG_IMGBB_ORIGIN||'http://127.0.0.1:8101/gallery.php?action=page');
  const provider=page.locator('[data-ag-storage-provider]');assert.equal(await provider.inputValue(),'imgbb');
  assert.equal(await page.locator('[data-ag-external-options]').isVisible(),true);
  await page.locator('[name=ag_file]').setInputFiles({name:'new.png',mimeType:'image/png',buffer:png});
  await page.locator('.ag-upload-form').first().getByRole('button',{name:'Загрузить',exact:true}).click();
  await page.locator('[data-ag-upload-result] article').waitFor();
  assert.match(await page.locator('[data-ag-upload-result] .ag-copy-bbcode').getAttribute('data-bbcode'),/^\[img\]https:\/\/i\.ibb\.co\//);
  assert.match(await page.locator('[data-ag-storage-used]').innerText(),/0.00 МБ из 1.00 МБ/);
  await page.locator('[data-ag-external-source]').selectOption('url');
  assert.equal(await page.locator('[name=ag_file]').isDisabled(),true);
  await page.locator('[name=external_url]').fill('http://127.0.0.1/private');
  await page.locator('.ag-upload-form').first().getByRole('button',{name:'Загрузить',exact:true}).click();
  await page.locator('[data-ag-storage-result]').filter({hasText:'внутренние'}).waitFor();
  assert.equal(await page.locator('[data-ag-upload-result] article').count(),1);
  await provider.selectOption('local');assert.equal(await page.locator('[name=ag_file]').isDisabled(),false);
  assert.equal(await page.locator('[data-ag-external-options]').isVisible(),false);
  assert.equal(await page.locator('select[name=album_id]').first().isDisabled(),false);
  await page.evaluate(()=>AF_GalleryPicker.open({purpose:'account_avatar',onSelect:item=>window.imgbbSelected=item}));
  await page.locator('#af-gallery-picker .ag-picker-card-actions button').first().click();
  await page.waitForFunction(()=>!!window.imgbbSelected);
  const chosen=await page.evaluate(()=>window.imgbbSelected);
  assert.ok(chosen.url_full.includes('action=avatar_original'));
  assert.equal(await page.evaluate(async url=>{const r=await fetch(url);return r.ok;},chosen.url_full),true);
  const content=await page.content();assert.ok(!content.includes('fixture_only_not_a_real_imgbb_key'));assert.ok(!content.includes('PRIVATE_DELETE_TOKEN'));
  assert.ok(requests.every(url=>!url.includes('api.imgbb.com')&&!url.includes('fixture_only_not_a_real_imgbb_key')));
  assert.deepEqual(errors,[]);
  console.log('ImgBB browser passed: actual upload form/default provider, multipart upload/card/BBCode, URL SSRF rejection, local switch, avatar Picker/proxy and no client keys.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

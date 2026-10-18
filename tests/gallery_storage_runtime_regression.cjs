'use strict';
const {chromium}=require('playwright');
const fs=require('node:fs'),assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.AA_CHROMIUM||'/usr/bin/chromium',args:['--no-sandbox']});
 try {
  const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const usage=(used,limit)=>({used_bytes:used,limit_bytes:limit,available_bytes:limit?Math.max(0,limit-used):null,percent:limit?Math.min(100,used/limit*100):null,full:limit>0&&used>=limit});
  const mb=1048576;let uploads=0,deleted=0,navigations=0;
  const html=fs.readFileSync('work/storage-widget.html','utf8')+'<form action="gallery.php?action=upload_do" method="post"><input name="my_post_key" value="key"><input type="file" name="ag_file"><button>Загрузить</button></form><div class="ag-view"><form action="gallery.php?action=delete&id=1" method="post"><input name="my_post_key" value="key"><button>Удалить</button></form></div>';
  await page.route('https://forum.test/**',async route=>{
   const r=route.request(),url=new URL(r.url());
   if(r.method()==='GET'){navigations++;return route.fulfill({contentType:'text/html; charset=utf-8',body:html});}
   assert.ok(r.postData().includes('name="ajax"'));assert.equal(r.headers()['x-requested-with'],'XMLHttpRequest');
   if(url.searchParams.get('action')==='upload_do'){
    uploads++;
    if(uploads===2)return route.fulfill({status:422,json:{ok:false,error:'Недостаточно места в Gallery.',storage:usage(mb,mb)}});
    return route.fulfill({json:{ok:true,storage:uploads===1?usage(mb,mb):usage(mb*2,0)}});
   }
   deleted++;return route.fulfill({json:{ok:true,storage:usage(mb/4,mb)}});
  });
  await page.goto('https://forum.test/gallery.php?action=upload');
  await page.addScriptTag({content:fs.readFileSync('inc/plugins/advancedfunctionality/addons/advancedgallery/assets/advancedgallery.js','utf8')});
  await page.addStyleTag({content:fs.readFileSync('inc/plugins/advancedfunctionality/addons/advancedgallery/assets/advancedgallery.css','utf8')});
  assert.match(await page.locator('[data-ag-storage-used]').innerText(),/0.50 МБ из 1.00 МБ/);
  const upload=page.locator('form').first();
  await upload.locator('button').click();await page.getByText('Изображение загружено.',{exact:true}).waitFor();
  assert.equal(await page.locator('progress').evaluate(e=>e.value),100);assert.equal(await page.locator('[data-ag-storage-warning]').isVisible(),true);
  await upload.locator('button').click();await page.getByText('Недостаточно места в Gallery.',{exact:true}).waitFor();
  await page.locator('.ag-view button').click();await page.getByText('Удаление завершено.',{exact:true}).waitFor();
  assert.equal(deleted,1);assert.equal(await page.locator('.ag-view').count(),0);
  assert.equal(await page.locator('progress').evaluate(e=>e.value),25);assert.match(await page.locator('[data-ag-storage-available]').innerText(),/0.75 МБ/);
  assert.equal(await page.locator('[data-ag-storage-warning]').isVisible(),false);
  await upload.locator('button').click();await page.getByText('Изображение загружено.',{exact:true}).waitFor();
  assert.match(await page.locator('[data-ag-storage-used]').innerText(),/2.00 МБ · Без ограничений/);
  assert.equal(await page.locator('progress').isVisible(),false);assert.equal(await page.locator('[data-ag-storage-available]').isVisible(),false);
  assert.equal(navigations,1);assert.deepEqual(errors,[]);
  await page.setViewportSize({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  console.log('Storage browser passed: real PHP widget, AJAX upload/full/error/delete/unlimited states, no reload, mobile width.');
 }finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

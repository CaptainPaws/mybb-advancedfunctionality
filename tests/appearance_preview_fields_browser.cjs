'use strict';
const assert=require('node:assert/strict'),{chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.AA_TEST_CHROMIUM||'/usr/bin/chromium',args:['--no-sandbox']});
 try {
 const page=await browser.newPage({viewport:{width:1450,height:1000}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 const origin=process.argv[2]||'http://127.0.0.1:8100';
 await page.goto(origin+'/apstudio.php');
 await page.getByRole('button',{name:'Редактировать',exact:true}).first().click();
 const root=page.locator('#aa-studio-preview');
 assert.match(await root.locator('.af-apf-postbit-field--fid1').innerText(),/Личное звание персонажа/);
 assert.match(await root.locator('.af-apf-postbit-field--fid2').innerText(),/не заполнено/);
 assert.equal(await root.locator('.af-apf-postbit-field--fid3').count(),0);
 assert.equal(await root.locator('.atf-post__rank').innerText(),'');
 const icon=root.locator('.atf-post__profile-actions .atf-post__profile-action .fa-user');
 assert.equal(await icon.count(),1);
 assert.equal(await icon.locator('..').getAttribute('title'),'Профиль');
 assert.ok(await page.evaluate(async()=>{const fonts=await document.fonts.load('900 14px "Font Awesome 6 Free"');return fonts.length>0&&document.fonts.check('900 14px "Font Awesome 6 Free"');}),'Real Font Awesome font failed to load');
 await root.locator('.af-apf-postbit-field--fid1 .af-apf-value').click();
 assert.equal(await page.locator('#aa-component').inputValue(),'profile_field_1');
 await page.locator('#aa-field-groups details').filter({has:page.locator('summary',{hasText:'Цвета'})}).locator('summary').click();
 await page.locator('[data-setting="text_color"]').fill('#123456');
 await page.waitForFunction(()=>getComputedStyle(document.querySelector('#aa-studio-preview .af-apf-postbit-field--fid1')).color==='rgb(18, 52, 86)');
 await root.locator('.af-apf-postbit-field--fid2').click();
 assert.equal(await page.locator('#aa-component').inputValue(),'profile_field_2');
 await page.selectOption('#aa-component','profile_field_1');
 assert.equal(await root.locator('.af-apf-postbit-field--fid1').getAttribute('data-aa-selected'),'');
 await page.waitForFunction(()=>document.querySelector('#aa-draft-status').textContent==='Черновик сохранён');
 assert.deepEqual(errors,[]);
 console.log('Preview fields passed: real stored/empty fields, per-field click/dropdown/style/draft, no rank and loaded Font Awesome profile icon.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

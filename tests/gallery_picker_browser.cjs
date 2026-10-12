/* Run against the isolated gallery_media_api_integration.php fixture server. */
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
(async () => {
  const origin = process.argv[2] || 'http://127.0.0.1:8099';
  const browser = await chromium.launch({headless:true, executablePath:process.env.AG_TEST_CHROMIUM || '/usr/bin/chromium', args:['--no-sandbox']});
  const page = await browser.newPage();
  const errors=[];page.on('pageerror', error=>errors.push(error.message));
  await page.goto(origin+'/gallery.php?action=fixture_page');
  await page.evaluate(() => {window.selected=null;AF_GalleryPicker.open({purpose:'appearance_profile_bg',onSelect:item=>window.selected=item});});
  await page.locator('.ag-picker-card').first().waitFor();
  const expected=Number(await page.locator('.ag-picker-card').first().getAttribute('data-media-id'));
  await page.getByRole('button',{name:'Выбрать изображение'}).first().click();
  await page.waitForFunction(()=>window.selected!==null);
  assert.equal((await page.evaluate(()=>window.selected)).media_id,expected);
  assert.equal(await page.locator('#af-gallery-picker.is-open').count(),0);
  // Switching purpose must replace cached modal, not leak rows/callbacks across contexts.
  await page.evaluate(() => AF_GalleryPicker.open(()=>{}, {purpose:'appearance_avatars'}));
  await page.waitForFunction(()=>document.querySelector('#af-gallery-picker.is-open')?.dataset.purpose==='appearance_avatars');
  await page.waitForFunction(()=>!document.querySelector('.ag-picker-grid')?.classList.contains('is-loading'));
  assert.equal(await page.locator('.ag-picker-card').count(),0);
  await page.getByRole('button',{name:'Все мои изображения'}).click();
  await page.locator('.ag-picker-card').first().waitFor();
  assert.ok(await page.locator('.ag-picker-card').count()>0);
  await page.keyboard.press('Escape');
  // Keep existing BBCode insertion mode working when returning from the ID picker.
  await page.evaluate(() => {window.inserted='';AF_GalleryPicker.open(text=>window.inserted=text);});
  await page.waitForFunction(()=>document.querySelector('#af-gallery-picker.is-open')?.dataset.purpose==='');
  await page.locator('.ag-picker-card').first().waitFor();
  await page.locator('.ag-picker-card .button').first().click();
  await page.waitForFunction(()=>window.inserted.startsWith('[img]'));
  // Opening missing purpose: no default/system album gets created. Upload then reopen uses real ID.
  await page.context().addCookies([{name:'fixture_uid',value:'31',url:origin}]);
  await page.evaluate(() => {
    AF_GalleryPickerConfig={pickerUrl:'gallery.php?action=picker&fixture_uid=31',dataUrl:'gallery.php?action=picker_data&fixture_uid=31'};
    AF_GalleryPicker.open(()=>{}, {purpose:'appearance_theme_bg'});
  });
  await page.waitForFunction(()=>document.querySelector('#af-gallery-picker.is-open')?.dataset.initialAlbum==='-2');
  // Minimal PNG fixture accepted by the original MIME/getimagesize pipeline.
  const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6l8AAAAASUVORK5CYII=','base64');
  await page.locator('input[type=file]').setInputFiles({name:'picker.png',mimeType:'image/png',buffer:png});
  await page.locator('form[data-ag-ajax="1"]').first().evaluate(form=>form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
  await page.waitForFunction(()=>Number(document.querySelector('#af-gallery-picker')?.dataset.initialAlbum)>0);
  const initial=await page.locator('#af-gallery-picker').getAttribute('data-initial-album');
  await page.keyboard.press('Escape');
  await page.evaluate(()=>AF_GalleryPicker.open(()=>{}, {purpose:'appearance_theme_bg'}));
  await page.waitForFunction(()=>document.querySelector('#af-gallery-picker')?.classList.contains('is-open'));
  assert.equal(await page.locator('#af-gallery-picker').getAttribute('data-initial-album'),initial);
  await page.locator('.ag-picker-card').first().waitFor();
  assert.equal(await page.locator('#af-gallery-picker').count(),1);
  assert.deepEqual(errors,[]);
  await browser.close();
  console.log('Gallery picker browser passed: media ID selection, purpose isolation/all-owned, legacy BBCode, lazy upload/reopen, no duplicate modal/errors.');
})().catch(error=>{console.error(error);process.exit(1);});

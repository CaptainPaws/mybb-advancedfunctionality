'use strict';
// Short browser contract, no live forum or long UI suite.
const {chromium}=require('playwright');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
  const browser=await chromium.launch({headless:true,executablePath:process.env.AA_CHROMIUM||(fs.existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined),args:['--no-sandbox']});
  try {
    const page=await browser.newPage();
    const gif=Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7','base64');
    await page.route('https://forum.test/**',route=>{
      if(route.request().url().endsWith('/original.gif'))return route.fulfill({contentType:'image/gif',body:gif});
      return route.fulfill({contentType:'text/html',body:`<form action="/usercp.php" method="post" enctype="multipart/form-data">
        <input type="file" name="avatarupload"><button name="native" type="submit">Native</button>
        <section data-ag-avatar-picker><button type="button" data-ag-avatar-open>Open</button><img hidden data-ag-avatar-preview>
        <input name="ag_avatar_media_id"><button disabled name="ag_avatar_confirm" type="submit" value="1">Confirm</button>
        <button disabled type="button" data-ag-avatar-cancel>Cancel</button><p data-ag-avatar-status></p></section></form>`});
    });
    await page.goto('https://forum.test/usercp.php?action=avatar');
    await page.addScriptTag({content:fs.readFileSync('inc/plugins/advancedfunctionality/addons/advancedgallery/assets/advancedgallery.js','utf8')});
    await page.evaluate(()=>{
      window.AF_GalleryPicker.open=(callback,options)=>{window.purpose=options.purpose;callback({media_id:42,url_full:'https://forum.test/original.gif',mime:'image/gif',filename:'gallery-avatar.gif'});};
      document.querySelector('form').addEventListener('submit',event=>{if(!event.defaultPrevented){event.preventDefault();window.savedSubmit=event.submitter.name;}});
    });
    await page.locator('[data-ag-avatar-open]').click();
    assert.equal(await page.evaluate(()=>window.purpose),'account_avatar');
    assert.equal(await page.locator('[name=ag_avatar_media_id]').inputValue(),'42');
    await page.locator('[data-ag-avatar-cancel]').click();
    assert.equal(await page.locator('[name=ag_avatar_media_id]').inputValue(),'');
    assert.equal(await page.locator('[name=ag_avatar_confirm]').isDisabled(),true);
    await page.locator('[data-ag-avatar-open]').click();
    await page.locator('[name=ag_avatar_confirm]').click();
    await page.waitForFunction(()=>window.savedSubmit==='ag_avatar_confirm');
    const result=await page.evaluate(async()=>{
      const file=document.querySelector('[name=avatarupload]').files[0];
      return {name:file.name,type:file.type,bytes:Array.from(new Uint8Array(await file.arrayBuffer()))};
    });
    assert.equal(result.name,'gallery-avatar.gif');assert.equal(result.type,'image/gif');assert.deepEqual(Buffer.from(result.bytes),gif);
    await page.locator('[name=native]').click();assert.equal(await page.evaluate(()=>window.savedSubmit),'native');
    console.log('Avatar runtime passed: Gallery purpose, selected preview, cancellation, original GIF bytes in native multipart input, native submit unaffected.');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});

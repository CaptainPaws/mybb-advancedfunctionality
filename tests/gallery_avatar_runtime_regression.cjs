'use strict';
// Short local contract using the real PHP-rendered component and both shipped runtimes.
const {chromium}=require('playwright');
const fs=require('node:fs'), assert=require('node:assert/strict');
(async()=>{
  const browser=await chromium.launch({headless:true,executablePath:process.env.AA_CHROMIUM||(fs.existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined),args:['--no-sandbox']});
  try{
    const page=await browser.newPage({viewport:{width:1180,height:1000}});
    page.setDefaultTimeout(5000);
    const html=fs.readFileSync(process.env.ATF_AVATAR_HTML||'work/avatar-editor.html','utf8');
    const gif=Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7','base64');
    const posts=[],purposes=[],dataRequests=[];let failNext=false;let legacyNext=false;let ambiguousNext=false;let stateFailure=false;let failRecovery=false;const invalidRequests=[];let stateReads=0;const versions={primary:0,secondary:0};
    const state=role=>({uid:7,role,url:'https://forum.test/current.gif?v='+versions[role],version:role+'-'+versions[role],has_avatar:true});
    const field=(raw,name)=>raw.match(new RegExp('name="'+name+'"\\r\\n\\r\\n([^\\r]+)'))?.[1]||'';
    await page.route('**/*',async route=>{
      const request=route.request(),url=new URL(request.url());
      if(url.pathname.endsWith('.gif'))return route.fulfill({contentType:'image/gif',body:gif});
      if(!['/usercp.php','/gallery.php','/original.gif','/current.gif'].includes(url.pathname)){invalidRequests.push(request.url());return route.fulfill({status:404,body:'Not found'});}
      if(url.searchParams.get('action')==='avatar' && url.searchParams.get('af_avatar_state')==='1'){stateReads++;assert.equal(request.headers().accept,'application/json');assert.equal(request.method(),'GET');assert.equal(url.pathname,'/usercp.php');assert.ok(['primary','secondary'].includes(url.searchParams.get('role')));assert.equal(url.searchParams.get('my_post_key'),'valid');if(stateFailure)return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,avatar:state(url.searchParams.get('role'))})+'<!--af_assets_begin--><link rel=stylesheet href=theme.css><!--af_core_preoutput_done-->'});return route.fulfill({json:{ok:true,avatar:state(url.searchParams.get('role'))}});}
      if(url.pathname.endsWith('gallery.php')){
        const action=url.searchParams.get('action'),purpose=url.searchParams.get('purpose');
        if(action==='picker'){
          purposes.push(purpose);
          return route.fulfill({contentType:'text/html; charset=utf-8',body:'<div id="af-gallery-picker" class="ag-picker" tabindex="-1" aria-hidden="true" data-initial-album="-3"><div class="ag-picker-dialog" role="document" tabindex="-1"><div class="ag-picker-albums"><button class="ag-picker-album" data-album-id="-3">Аватары аккаунта и оформления</button><button class="ag-picker-album" data-album-id="12">Другой альбом</button><button class="ag-picker-album" data-album-id="-1">Все изображения</button></div><form data-ag-ajax="1" action="https://forum.test/gallery.php?action=upload_do" method="post"><input name="my_post_key" value="valid"><button type="submit" data-test-upload>Загрузить</button></form><div class="ag-picker-grid" data-ag-picker-grid="1"></div><button data-ag-picker-close="1">Закрыть</button></div></div>'});
        }
        if(action==='picker_data'){dataRequests.push({purpose,album:url.searchParams.get('album_id')});return route.fulfill({json:[{id:42,media_id:42,title:'Original GIF',type:'local',thumb:'https://forum.test/original.gif',url_full:'https://forum.test/original.gif'}]});}
        if(action==='upload_do')return route.fulfill({json:{ok:true,album_id:85}});
        if(action==='picker_select')return route.fulfill({json:{ok:true,media_id:42,mime:'image/gif',filename:'gallery-avatar.gif',url_full:'https://forum.test/original.gif'}});
      }
      if(request.method()==='POST'){
        assert.equal(url.pathname,'/usercp.php');
        const raw=request.postData()||'';assert.equal(field(raw,'my_post_key'),'valid');posts.push({action:field(raw,'action'),raw,bytes:request.postDataBuffer()});
        const role=field(raw,'action')==='do_avatar'?'primary':'secondary';
        if(failNext){failNext=false;return route.fulfill({json:{errors:['Проверочная ошибка']}});}
        if(ambiguousNext){ambiguousNext=false;return route.fulfill({contentType:'text/html; charset=utf-8',body:'<p>Неоднозначный ответ без сохранения</p>'});}
        versions[role]++;
        if(legacyNext){legacyNext=false;if(failRecovery){stateFailure=true;failRecovery=false;}return route.fulfill({contentType:'text/html; charset=utf-8',body:'<html><p>Сохранено</p><a href="usercp.php?action=avatar">Продолжить</a></html>'});}
        return route.fulfill({json:{ok:true,avatar:state(role)}});
      }
      return route.fulfill({contentType:'text/html; charset=utf-8',body:'<html><body style="margin:24px;background:#060e17">'+html+'</body></html>'});
    });
    await page.goto('https://forum.test/usercp.php?action=avatar');
    await page.addStyleTag({content:fs.readFileSync('inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/surfaces/usercp.css','utf8')});
    await page.addStyleTag({content:fs.readFileSync('inc/plugins/advancedfunctionality/addons/advancedgallery/assets/advancedgallery.css','utf8')});
    await page.addScriptTag({content:fs.readFileSync('inc/plugins/advancedfunctionality/addons/advancedgallery/assets/advancedgallery.js','utf8')});
    await page.addScriptTag({content:fs.readFileSync('inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/adaptivethemeframework.avatar-editor.js','utf8')});
    assert.equal(await page.locator('form[data-avatar-card]').first().evaluate(f=>String(f.action)),'[object HTMLInputElement]','Fixture must reproduce named action shadowing');
    await page.waitForFunction(()=>document.querySelectorAll('[data-avatar-recent] button').length===2);
    assert.equal(await page.locator('form[data-avatar-card]').count(),2);
    assert.deepEqual(dataRequests.map(r=>r.album),[null,null],'Recent must defer album selection to server');
    await page.screenshot({path:'work/avatar-editor-desktop.png',fullPage:true});
    for(const role of ['primary','secondary']){
      const card=page.locator(`[data-avatar-card=${role}]`);
      await card.locator('.atf-avatar-editor__gallery-open').click();
      await page.waitForFunction(()=>document.querySelector('#af-gallery-picker .ag-picker-card-actions button'));
      assert.equal(dataRequests.at(-1).album,'-3','Modal must start on combined avatar albums');
      for(const album of ['12','-1','-3']){
        const count=dataRequests.length;
        const response=page.waitForResponse(r=>new URL(r.url()).searchParams.get('action')==='picker_data' && new URL(r.url()).searchParams.get('album_id')===album);
        await page.locator('#af-gallery-picker [data-album-id=\"'+album+'\"]').click();
        await response;
        await page.waitForFunction(()=>document.querySelector('#af-gallery-picker .ag-picker-card-actions button'));
        assert.equal(dataRequests.length,count+1);assert.equal(dataRequests.at(-1).album,album,'Switch must retain explicitly selected album');
      }
      const uploadResponse=page.waitForResponse(r=>new URL(r.url()).searchParams.get('action')==='picker_data');
      await page.locator('[data-test-upload]').click();await uploadResponse;
      await page.waitForFunction(()=>document.querySelector('#af-gallery-picker .ag-picker-card-actions button'));
      assert.equal(dataRequests.at(-1).album,'-3','Upload must preserve combined album filter');
      assert.equal(await page.locator('#af-gallery-picker').getAttribute('data-initial-album'),'-3','Upload must preserve initial group');
      await page.locator('#af-gallery-picker .ag-picker-card-actions button').click();
      await card.locator('[data-avatar-selection]').waitFor({state:'visible'});
      assert.equal(posts.length,role==='primary'?0:1,'Selection saved automatically');
      if(role==='primary'){
        await card.locator('[data-avatar-cancel]').click();assert.equal(await card.locator('[data-avatar-selection]').isVisible(),false);
        await card.locator('[data-avatar-recent] button').click();await card.locator('[data-avatar-selection]').waitFor({state:'visible'});
      }
      await card.locator('[data-avatar-save]').click();await card.locator('[data-avatar-status]').filter({hasText:'Аватар успешно обновлён.'}).waitFor();
      const post=posts.at(-1);assert.equal(post.action,role==='primary'?'do_avatar':'do_apf_secondary_avatar');
      assert.ok(post.raw.includes(`name="${role==='primary'?'avatarupload':'secondary_avatar_upload'}"`));
      assert.ok(!post.raw.includes(`name="${role==='primary'?'secondary_avatar_upload':'avatarupload'}"`));
      assert.ok(post.bytes.includes(gif),'Original GIF bytes lost');assert.equal(field(post.raw,'ag_avatar_confirm'),'1');
    }
    assert.deepEqual(purposes,['account_avatar','character_avatar']);
    assert.equal(stateReads,0,'Verified save receipts must not require a preflight state GET');
    legacyNext=true;
    const primary=page.locator('[data-avatar-card=primary]');
    await primary.locator('[data-avatar-tab=upload]').click();
    const beforeUpload=posts.length;
    await primary.locator('input[type=file]').setInputFiles({name:'local.gif',mimeType:'image/gif',buffer:gif});
    assert.equal(posts.length,beforeUpload,'File saved without confirmation');
    await primary.locator('[data-avatar-save]').click();await primary.locator('[data-avatar-status]').filter({hasText:'Аватар успешно обновлён.'}).waitFor();
    assert.ok(posts.at(-1).raw.includes('name="avatarupload"') && !posts.at(-1).raw.includes('name="ag_avatar_confirm"'));
    await primary.locator('[data-avatar-tab=url]').click();await primary.locator('[data-avatar-url]').fill('https://forum.test/original.gif');
    await primary.locator('[data-avatar-save]').click();await primary.locator('[data-avatar-status]').filter({hasText:'Аватар успешно обновлён.'}).waitFor();
    assert.equal(field(posts.at(-1).raw,'avatarurl'),'https://forum.test/original.gif');assert.ok(!posts.at(-1).raw.includes('filename='),'URL submitted stale upload');

    await primary.locator('[data-avatar-recent] button').click();await primary.locator('[data-avatar-selection]').waitFor({state:'visible'});
    failNext=true;await primary.locator('[data-avatar-save]').click();await primary.locator('[data-avatar-status]').filter({hasText:'Проверочная ошибка'}).waitFor();
    assert.equal(await primary.locator('[data-avatar-selection]').isVisible(),true,'Error discarded selection');
    const beforeAmbiguous=posts.length;ambiguousNext=true;await primary.locator('[data-avatar-save]').click();
    await primary.locator('[data-avatar-status]').filter({hasText:'состояние аватара не изменилось'}).waitFor();
    assert.equal(posts.length,beforeAmbiguous+1,'Ambiguous response retried POST');assert.equal(await primary.locator('[data-avatar-selection]').isVisible(),true);
    stateFailure=true;const beforeStateFailure=posts.length;
    await primary.locator('[data-avatar-save]').click();
    await primary.locator('[data-avatar-status]').filter({hasText:'Аватар успешно обновлён.'}).waitFor();
    assert.equal(posts.length,beforeStateFailure+1,'Unavailable state GET must not block a valid POST receipt');
    assert.equal(await primary.locator('[data-avatar-selection]').isVisible(),false);
    await primary.locator('[data-avatar-recent] button').click();await primary.locator('[data-avatar-selection]').waitFor({state:'visible'});
    stateFailure=false;legacyNext=true;failRecovery=true;
    await primary.locator('[data-avatar-save]').click();
    await primary.locator('[data-avatar-status]').filter({hasText:'Это не означает отказ сохранения'}).waitFor();
    assert.equal(posts.length,beforeStateFailure+2,'Unavailable recovery must not repeat POST');
    assert.equal(await primary.locator('[data-avatar-selection]').isVisible(),true);
    stateFailure=false;
    assert.deepEqual(invalidRequests,[],'Requests must reach the actual UserCP path');
    page.once('dialog',dialog=>dialog.accept());await primary.locator('[data-avatar-remove]').evaluate(button=>button.disabled=false);await primary.locator('[data-avatar-remove]').click();
    await primary.locator('[data-avatar-status]').filter({hasText:'Аватар удалён.'}).waitFor();assert.equal(field(posts.at(-1).raw,'remove'),'1');
    assert.ok(!posts.at(-1).raw.includes('filename='),'Remove submitted another avatar image');
    const secondary=page.locator('[data-avatar-card=secondary]');
    page.once('dialog',dialog=>dialog.accept());await secondary.locator('[data-avatar-remove]').click();
    await secondary.locator('[data-avatar-status]').filter({hasText:'Аватар удалён.'}).waitFor();
    assert.equal(posts.at(-1).action,'do_apf_secondary_avatar');assert.equal(field(posts.at(-1).raw,'remove_secondary_avatar'),'1');
    assert.ok(!posts.at(-1).raw.includes('filename='));assert.deepEqual(invalidRequests,[]);
    for(const role of ['primary','secondary'])await page.locator(`[data-avatar-card=${role}] [data-avatar-tab=upload]`).click();
    await page.setViewportSize({width:390,height:844});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'Mobile overflow');
    await page.screenshot({path:'work/avatar-editor-mobile.png',fullPage:true});
    console.log('Two-avatar runtime + truthful receipts/legacy state recovery passed: real Gallery modal for both contexts, recent selection/cancel, isolated multipart routes with original GIFs, save/remove, retained error state, mobile width.');
  }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});

'use strict';
const assert=require('node:assert/strict');
const {chromium}=require('playwright');
const fs=require('node:fs');
const path=require('node:path');
const script=fs.readFileSync(path.join(__dirname,'../inc/plugins/advancedfunctionality/addons/advancedcombatsystem/assets/publication.js'),'utf8');
const {PublicationModel}=require('../inc/plugins/advancedfunctionality/addons/advancedcombatsystem/assets/publication.js');
const m=new PublicationModel();const draft={session_id:'1',encounter_id:'2',turn_id:'3',draft_id:'4',draft_version:'5',actions:[],author_uid:'7',confirmation_hash:'a'.repeat(64),confirmed:true,prose:'saved',summary:{actions:[{sealed_action_id:'1',outcome:'hit',costs:[{amount:1,kind:'ap'}],damage:5,rolls:[.5]}]},operation:null};
const req=m.saveRequest(draft,'first','same-key');assert.deepEqual(m.saveRequest(draft,'changed','another-key'),req);m.prepared({submission_id:'a'.repeat(32),status:'POSTING'});assert.equal(m.canSend(),false);assert.throws(()=>m.prepared({submission_id:'b'.repeat(32),status:'REGISTERED'}));
(async()=>{
 const browser=await chromium.launch({headless:true,...(fs.existsSync('/usr/bin/chromium')?{executablePath:'/usr/bin/chromium'}:{}),args:['--no-sandbox']});
 try {
  for(const quick of [false,true]) {
   const page=await browser.newPage();let sends=0,saves=0,prepares=0,losePrepare=true,losePost=true,lastPrepare='',savedText='';let operation=null;
   const errors=[];page.on('pageerror',e=>errors.push(e.message));
   const formId=quick?'quick_reply_form':'newreply';
   const html=`<!doctype html><html><head><meta charset="utf-8"></head><body><form id="${formId}" action="newreply.php" method="post"><input name="tid" value="51"><textarea name="message" id="message"></textarea><input type="file" name="attachments"><button type="submit" name="submit" id="${quick?'quick_reply_submit':'reply_submit'}">Send</button><button type="submit" name="previewpost">Preview</button><button type="submit" name="savedraft">Draft</button></form><script type="application/json" data-acs-publication-config>${JSON.stringify({author_uid:'7',draft_id:'4',tid:'51',post_key:'token',endpoint:'newreply.php',draft_endpoint:'xmlhttp.php?op=draft_save'})}</script><script>
   window.nativeSends=0;window.activeValue='[b]WYSIWYG prose[/b]';window.adapterCreated=1;
   const ta=document.querySelector('textarea');ta.__afAeAdapter={val:()=> 'stale source text'};
   const active={val(v){if(arguments.length)window.activeValue=v;return window.activeValue;},updateOriginal(){ta.value=window.activeValue;},readOnly(){},bind(){}};
   window.jQuery=()=>({sceditor:op=>{if(op!=='instance')throw Error('Second editor creation');return active;}});window.jQuery.fn={sceditor:true};
   document.querySelector('form').addEventListener('submit',e=>{window.nativeSends++;e.preventDefault();});
   </script><script>${script}</script></body></html>`;
   await page.route('https://fixture.local/**',async route=>{
    const r=route.request();if(r.method()==='GET'&&!r.url().includes('acs_operation='))return route.fulfill({contentType:'text/html',body:html});
    const params=r.method()==='GET'?new URL(r.url()).searchParams:new URLSearchParams(r.postData());const op=params.get('acs_operation')||params.get('op')||new URL(r.url()).searchParams.get('op');
    let data;
    if(op==='compose')data={...draft,operation};
    else if(op==='draft_save'){saves++;savedText=params.get('prose');data={draft_id:'4',draft_version:'6'};}
    else if(op==='prepare'){
     prepares++;assert.equal(params.get('message'),savedText);if(lastPrepare)assert.equal(r.postData(),lastPrepare);lastPrepare=r.postData();operation={submission_id:'a'.repeat(32),status:'REGISTERED',pid:null,mechanics_confirmed:false};
     if(losePrepare){losePrepare=false;return route.abort('failed');}data={operation,summary:draft.summary};
    }else if(op==='publish'){
     sends++;assert.equal(params.get('action'),'acs_publication');assert.equal(params.get('message'),'[b]WYSIWYG prose[/b]');assert.equal(params.get('acs_submission_id'),'a'.repeat(32));assert.equal(params.get('method'),quick?'quickreply':null);
     operation={submission_id:'a'.repeat(32),status:'COMMITTED',pid:'501',mechanics_confirmed:true};
     if(losePost){losePost=false;return route.abort('failed');}data=operation;
    }else if(op==='status')data=operation;else throw Error('Unexpected request '+op);
    await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data})});
   });
   await page.goto('https://fixture.local/'+(quick?'showthread.php':'newreply.php')+'?tid=51&acs_draft=4');
   const prepare=page.getByRole('button',{name:'Сохранить текст и подготовить отправку'});await prepare.waitFor();await page.waitForFunction(()=>!document.querySelector('.af-acs-publication button:nth-of-type(2)').disabled);
   await page.locator('select[name=acs_mode]').selectOption('narrative');await page.getByRole('button',{name:'Send',exact:true}).click();assert.equal(await page.evaluate(()=>window.nativeSends),1);assert.equal(sends,0);await page.locator('select[name=acs_mode]').selectOption('combat');
   await page.locator('input[type=file]').setInputFiles({name:'zero.txt',mimeType:'text/plain',buffer:Buffer.alloc(0)});await prepare.click();await page.getByRole('status').filter({hasText:'Вложения'}).waitFor();assert.equal(saves,0);await page.locator('input[type=file]').setInputFiles([]);
   await prepare.click();await page.getByRole('status').filter({hasText:/fetch|Failed|Network/i}).waitFor();assert.equal(prepares,1);assert.equal(savedText,'[b]WYSIWYG prose[/b]');
   await prepare.click();await page.getByRole('status').filter({hasText:'Отправка подготовлена'}).waitFor();assert.equal(saves,1);assert.equal(prepares,2);
   await page.getByRole('checkbox').check();await page.getByRole('button',{name:'Preview',exact:true}).click();assert.equal(sends,0);await page.getByRole('button',{name:'Draft',exact:true}).click();assert.equal(sends,0);
   await page.getByRole('button',{name:'Send',exact:true}).dblclick();await page.getByRole('status').filter({hasText:'Исход не подтверждён'}).waitFor();assert.equal(sends,1);assert.equal(await page.evaluate(()=>window.nativeSends),1);
   await page.getByRole('button',{name:'Проверить статус отправки'}).click();await page.getByRole('status').filter({hasText:'Результат подтверждён'}).waitFor();assert.equal(sends,1);
   await page.reload();await page.getByRole('status').filter({hasText:'Результат подтверждён'}).waitFor();await page.getByRole('button',{name:'Send',exact:true}).click();assert.equal(sends,1);
   assert.equal(await page.locator('textarea[name=message]').count(),1);assert.equal(await page.locator('form').count(),1);assert.equal(await page.evaluate(()=>window.adapterCreated),1);assert.deepEqual(errors,[]);await page.close();
  }
  const page=await browser.newPage();await page.route('https://fixture.local/**',r=>r.fulfill({contentType:'text/html',body:'<form action="newreply.php"><textarea name="message"></textarea></form><script>'+script+'</script>'}));await page.goto('https://fixture.local/editpost.php');assert.equal(await page.locator('.af-acs-publication').count(),0);await page.close();
  console.log('ACS editor browser: full/quick forms, existing WYSIWYG adapter, save/prepare loss, one submission, double click, lost publish response/status/reload, preview/draft/upload refusal and no edit wiring passed');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

/* Chromium + actual MyBB 1.8.40 jscripts. The forum boundary is a local fixture. */
'use strict';
const {chromium}=require('playwright'), fs=require('fs'), path=require('path'), assert=require('node:assert/strict');
const args=Object.fromEntries(process.argv.slice(2).reduce((a,v,i,all)=>v.startsWith('--')?a.concat([[v.slice(2),all[i+1]]]):a,[]));
if (!args.html || !args['mybb-assets']) throw Error('--html and --mybb-assets required');
const root=path.resolve(args.root||path.resolve(__dirname,'..')), addon='/inc/plugins/advancedfunctionality/addons/';
const checks=[], errors=[], requests=[], failedAssets=[];
let browser;
const spoiler='<blockquote class="mycode_quote af-aqr-spoiler" data-open="0"><button class="af-aqr-spoiler-head" aria-expanded="false">Spoiler</button><div class="af-aqr-spoiler-body" hidden>Secret</div><div class="af-aqr-spoiler-foot" hidden><button class="af-aqr-spoiler-collapse">Close</button></div></blockquote>';
(async()=>{
 browser=await chromium.launch({executablePath:args.chromium||'/usr/bin/chromium',args:['--no-sandbox']});
 const page=await browser.newPage({viewport:{width:1100,height:850}});
 page.on('pageerror',e=>errors.push(e.message));
 page.on('response',r=>{if(r.status()>=400 && r.url().includes('/assets/') && /\.(js|css|svg)(?:\?|$)/.test(r.url()))failedAssets.push(r.url());});
 await page.route('**/*',async route=>{
  const url=new URL(route.request().url());requests.push(url.pathname);
  if(url.pathname==='/showthread.php'&&route.request().method()==='POST') {
   return route.fulfill({contentType:'text/html',body:'<div id="preview_post"><div class="post_body"><blockquote class="mycode_quote"><cite><a href="showthread.php?pid=703#pid703">↗</a> Wrote:</cite>Lorem ipsum</blockquote></div></div>'});
  }
  let file=url.pathname==='/work/theme.css'?args['theme-css']:url.pathname==='/showthread.php'?args.html:url.pathname.startsWith('/jscripts/')?path.join(args['mybb-assets'],url.pathname):path.join(root,url.pathname);
  try {
   let body=fs.readFileSync(file);
   if(url.pathname==='/showthread.php') {
    let html=body.toString().replace(/window.afAdvancedEditorPayload=(.*?);<\/script>/,(_,json)=>{
     let payload=JSON.parse(json);payload.cfg.editorSelector='textarea';
     return 'window.afAdvancedEditorPayload='+JSON.stringify(payload)+';</script>';
    });
    if(args['theme-css']) html=html.replace('<head>','<head><link rel="stylesheet" href="/work/theme.css">').replace('<body>','<body class="atf-active"><div class="atf-page">').replace('</body>','</div></body>');
    html=html.replace('</head>','<style>.mycode_quote{text-align:left}</style></head>')
      .replace('<textarea name="message" id="message">','<textarea class="sceditor-textarea" name="message" id="message">');
    html=html.replace('</body>','<div id="posts"><article class="post" id="post_703"><div class="atf-post__name"><a href="member.php?action=profile&uid=42">Тестовый Автор</a></div><div class="atf-post__avatar"><img src="/uploads/avatars/avatar_42.png" width="48" height="48"></div><div class="post_body" id="pid_703">Lorem ipsum</div></article></div></body>');
    body=Buffer.from(html);
   }
   await route.fulfill({body,contentType:/\.js$/.test(file)?'application/javascript':/\.css$/.test(file)?'text/css':/\.svg$/.test(file)?'image/svg+xml':'text/html; charset=utf-8'});
  }catch(e){await route.fulfill({status:404,body:'missing'});}
 });
 await page.goto('http://127.0.0.1:8765/showthread.php');
 const cmd=name=>page.locator('#quick_reply_form [data-af-command="'+name+'"]');
 const shell=page.locator('#quick_reply_form > .af-ae-shell');
 const dimensions=()=>shell.evaluate(e=>({height:e.getBoundingClientRect().height,fields:e.querySelectorAll('textarea').length,native:e.querySelectorAll('.sceditor-container').length,toolbar:e.querySelectorAll('.sceditor-toolbar').length,kb:e.querySelectorAll('.sceditor-button-af_kb_insert').length}));
 assert.equal(await page.evaluate(()=>!!jQuery.fn.sceditor),false);
 assert.equal(await shell.count(),1);assert.equal(await cmd('af_kb_insert').count(),1);
 assert.equal(await shell.locator('.af-ccp-bar [data-af-command=af_formathelp]').count(),1);
 assert.equal(await shell.locator('.sceditor-toolbar [data-af-command=af_formathelp]').count(),0);
 const icon=page.locator('#quick_reply_form [data-af-command=af_menu_dropdown1] > div');
 assert.equal((await icon.textContent()).trim(),'');
 assert.equal(await icon.locator('.af-ae-shell-icon, img, svg, i.fa-solid, i.fa-regular, i.fa-brands').count(),1);
 assert.equal(await cmd('af_kb_insert').locator('.af-ae-shell-icon, img, svg, i.fa-solid, i.fa-regular, i.fa-brands').count(),1);
 const helpTrigger=shell.locator('.af-ae-format-help-trigger');
 assert.equal((await helpTrigger.textContent()).trim(),'');
 assert.equal(await helpTrigger.locator('i.fa-circle-question').count(),1);
 const palette=()=>shell.evaluate(e=>({color:getComputedStyle(e).color,background:getComputedStyle(e).backgroundColor,source:getComputedStyle(e.querySelector('textarea:not(.af-ae-original-textarea)')||e.querySelector('textarea')).color}));
 const initialPalette=await palette();
 assert.notEqual(initialPalette.color,initialPalette.background);
 assert.equal(initialPalette.source,initialPalette.color);
 await page.setViewportSize({width:375,height:850});
 assert.equal(await shell.evaluate(e=>e.scrollWidth<=e.clientWidth+1),true,'Mobile toolbar must wrap');
 await page.setViewportSize({width:1100,height:850});
 const initial=await dimensions();
 await page.locator('#message').fill('Текст 😀');
 async function popup(command,selector) {
  await cmd(command).click();const popup=page.locator('.af-ae-popup');await popup.waitFor({state:'visible'});
  assert.equal(await popup.count(),1);assert.equal(await popup.locator(selector).count()>0,true);
  const box=await popup.boundingBox();assert(box.width<=1084&&box.x>=0&&box.y>=0&&box.y+box.height<=851);
  assert.equal(await popup.evaluate(e=>e.parentElement===document.body),true);
  await page.keyboard.press('Escape');assert.equal(await popup.count(),0);
 }
 await popup('af_formathelp','.af-ae-format-help-body');
 await popup('af_font','.af-ff-dd');await popup('af_fontsize','.af-ae-fontsize-picker');
 await cmd('af_menu_dropdown1').click();await page.locator('.af-ae-popup .af-ae-shell-menu').waitFor({state:'visible'});
 assert.equal(await page.locator('.af-ae-popup').getAttribute('class').then(v=>v.includes('sceditor-dropdown')),false,'AdvancedEditor extra menu must not use native SCEditor dropdown class');
 assert.equal(await page.locator('.af-ae-popup .af-ae-shell-menu .sceditor-button').evaluateAll(nodes=>nodes.every(n=>!!n.querySelector('i.fa-solid, i.fa-regular, i.fa-brands'))),true,'Every extra-menu command must have a Font Awesome icon');
 assert.equal(await page.locator('.af-ae-popup .af-ae-shell-menu .sceditor-button > div').evaluateAll(nodes=>nodes.every(n=>getComputedStyle(n).backgroundImage==='none' && getComputedStyle(n).textIndent==='0px')),true,'Source extra-menu icons must not inherit SCEditor sprites');
 window.__afExtraMenuIconsBefore=await page.locator('.af-ae-popup .af-ae-shell-menu .sceditor-button i').evaluateAll(nodes=>nodes.map(n=>n.className).join('|'));
 window.__afExtraMenuStyleBefore=await page.locator('.af-ae-popup').evaluate(e=>{const c=getComputedStyle(e);return [c.padding,c.borderRadius,c.backgroundColor,c.minWidth].join('|');});
 await page.keyboard.press('Escape');
 await cmd('af_menu_dropdown1').click();await page.locator('.af-ae-popup [data-af-command=af_indent]').click();
 await page.waitForFunction(()=>afAdvancedEditorShell.states.indent.state==='loaded');
 await page.locator('.af-ae-popup').waitFor({state:'visible'});await page.keyboard.press('Escape');
 checks.push('Source popups, bottom Formatting Help and extra menu');
 await cmd('af_togglemode').click();
 await page.waitForFunction(()=>!!document.querySelector('#message')._sceditor);
 await page.waitForTimeout(50);
 const ed=()=>page.evaluate(()=>jQuery('#message').sceditor('instance').val());
 assert.equal((await dimensions()).native,1);assert.equal((await dimensions()).toolbar,1);
 assert.equal(await cmd('af_kb_insert').count(),1,'KB must remain single after WYSIWYG activation');
 assert.equal(await shell.locator(':scope > .sceditor-toolbar .sceditor-button').evaluateAll(nodes=>nodes.filter(n=>{
   const cmd=(n.getAttribute('data-af-command')||n.getAttribute('data-sceditor-command')||'').toLowerCase();
   const title=(n.getAttribute('title')||n.getAttribute('aria-label')||'').trim().toLowerCase();
   return ['kb','kb_insert','af_kb','af_kb_insert','knowledgebase','knowledgebase_insert'].includes(cmd) || title==='kb' || title==='insert kb' || title==='вставить kb';
 }).length),1,'Only one semantic KB toolbar control may exist');
 await cmd('af_menu_dropdown1').click();await page.locator('.af-ae-popup .af-ae-shell-menu').waitFor({state:'visible'});
 assert.equal(await page.locator('.af-ae-popup').getAttribute('class').then(v=>v.includes('sceditor-dropdown')),false,'WYSIWYG extra menu must stay outside native SCEditor dropdown styling');
 assert.equal(await page.locator('.af-ae-popup .af-ae-shell-menu .sceditor-button').evaluateAll(nodes=>nodes.every(n=>!!n.querySelector('i.fa-solid, i.fa-regular, i.fa-brands'))),true,'WYSIWYG must not remove Font Awesome nodes from extra menu');
 assert.equal(await page.locator('.af-ae-popup .af-ae-shell-menu .sceditor-button > div').evaluateAll(nodes=>nodes.every(n=>getComputedStyle(n).backgroundImage==='none' && getComputedStyle(n).textIndent==='0px')),true,'WYSIWYG extra-menu icons must keep Font Awesome visuals after SCEditor CSS loads');
 assert.equal(await page.locator('.af-ae-popup .af-ae-shell-menu .sceditor-button i').evaluateAll(nodes=>nodes.map(n=>n.className).join('|')),window.__afExtraMenuIconsBefore,'WYSIWYG must preserve exact extra-menu icon DOM');
 assert.equal(await page.locator('.af-ae-popup').evaluate(e=>{const c=getComputedStyle(e);return [c.padding,c.borderRadius,c.backgroundColor,c.minWidth].join('|');}),window.__afExtraMenuStyleBefore,'WYSIWYG must preserve extra-menu visual contract');
 await page.keyboard.press('Escape');
 await cmd('af_togglemode').click();await page.waitForTimeout(20);
 await cmd('af_menu_dropdown1').click();await page.locator('.af-ae-popup .af-ae-shell-menu').waitFor({state:'visible'});
 assert.equal(await page.locator('.af-ae-popup .af-ae-shell-menu .sceditor-button i').evaluateAll(nodes=>nodes.map(n=>n.className).join('|')),window.__afExtraMenuIconsBefore,'Returning to Source must preserve extra-menu icons');
 await page.keyboard.press('Escape');
 assert.equal((await dimensions()).fields,2); // Original data field + ONE native source view.
 const activated=await dimensions();assert.equal(activated.height,initial.height);
 assert.equal(await page.evaluate(()=>getComputedStyle(jQuery('#message').sceditor('instance').getBody()).color),initialPalette.color);
 await page.evaluate(()=>jQuery('#message').sceditor('instance').sourceMode(true));
 assert.equal(await shell.locator('.sceditor-source').evaluate(e=>getComputedStyle(e).color),initialPalette.color);
 await page.evaluate(()=>jQuery('#message').sceditor('instance').sourceMode(false));
 await page.evaluate(()=>jQuery('#message').sceditor('instance').val('[color=#ff0000]Red[/color]'));
 assert.equal(await page.evaluate(()=>getComputedStyle(jQuery('#message').sceditor('instance').getBody().querySelector('[style*=color], font[color]')).color),'rgb(255, 0, 0)');
 await page.evaluate(()=>jQuery('#message').sceditor('instance').val('Текст 😀'));
 await page.evaluate(()=>{window.__testedInstance=jQuery('#message').sceditor('instance');window.__testedFrame=__testedInstance.getContentAreaContainer();});
 for(let i=0;i<10;i++) {
  await cmd('af_togglemode').click();await page.waitForTimeout(20);
  assert.deepEqual(await dimensions(),activated);
  assert.equal(await page.evaluate(()=>jQuery('#message').sceditor('instance')===__testedInstance),true);
  assert.equal((await ed()).trim(),'Текст 😀');
  if(i%3===0) {await popup('af_font','.af-ff-dd');await popup('af_fontsize','.af-ae-fontsize-picker');await popup('af_formathelp','.af-ae-format-help-body');}
 }
 checks.push('A/B/C: 10 toggles, same instance, stable height, 2 total textareas, 1 native widget/toolbar/KB');
 await page.evaluate(()=>__testedInstance.resizeTo('100%',300));const resized=await dimensions();
 await cmd('af_togglemode').click();assert.equal((await dimensions()).height,resized.height);
 await cmd('af_togglemode').click();assert.equal((await dimensions()).height,resized.height);
 await page.evaluate(()=>__testedInstance.resizeTo('100%',document.querySelector('#message').__afAeSurfaceMetrics.height));
 assert.equal((await dimensions()).height,initial.height);
 async function roundtrip(source) {
  return page.evaluate(source=>{
   const e=__testedInstance;e.sourceMode(true);e.val(source);let results=[];
   for(let i=0;i<3;i++){e.sourceMode(false);results.push({html:e.getBody().innerHTML,bb:e.val()});e.sourceMode(true);results.push({bb:e.val()});}
   return results;
  },source);
 }
 const aligned='[left]left[/left]\n[center]center[/center]\n[right]right[/right]\n[justify]justify[/justify]';
 let result=await roundtrip(aligned);
 for(const step of result) {for(const tag of ['left','center','right','justify']) assert.equal((step.bb.match(new RegExp('\\['+tag+'\\]','g'))||[]).length,1,step.bb);assert(!/\[align/.test(step.bb),step.bb);}
 assert.match(result[0].html,/text-align:left/);assert.match(result[0].html,/text-align:center/);
 await page.evaluate(()=>{__testedInstance.sourceMode(false);__testedInstance.val('Action alignment');const body=__testedInstance.getBody(),range=body.ownerDocument.createRange();range.selectNodeContents(body);const selection=body.ownerDocument.defaultView.getSelection();selection.removeAllRanges();selection.addRange(range);});
 await cmd('center').click();
 assert.equal(await page.evaluate(()=>!!__testedInstance.getBody().querySelector('[data-af-align="center"]')),true);
 assert.match(await ed(),/\[align=center\]/);
 for(const quote of ['[quote="" pid=\'703\']Lorem ipsum[/quote]','[quote="Автор 😀" pid="703" dateline="12345"]Lorem ipsum[/quote]','[quote="" pid=\'703\'][center]Lorem ipsum[/center][/quote]','[center][quote="" pid=\'703\']Lorem ipsum[/quote][/center]','[quote="Outer" pid="700"][quote="Inner" pid="703"]Nested[/quote][/quote]']) {
  result=await roundtrip(quote);
  for(const step of result) {
   assert.match(step.bb,/pid="703"/);assert(!/undefined|\[left\]|defaultattr/.test(step.bb),step.bb);
   assert.equal((step.bb.match(/\[quote(?:=|\])/g)||[]).length,(quote.match(/\[quote(?:=|\])/g)||[]).length,step.bb);
   assert.equal((step.bb.match(/\[center\]/g)||[]).length,(quote.match(/\[center\]/g)||[]).length,step.bb);
   if(quote.includes('Автор'))assert.match(step.bb,/Автор 😀/);
  }
  assert.equal(result[1].bb,result[5].bb,'Serializer must be idempotent');
  if(quote.includes('pid=\'703\''))assert.match(result[0].html,/data-author="Тестовый Автор"/);
 }
 // Real edits, not just the pristine-source preservation path.
 await page.evaluate(()=>{__testedInstance.sourceMode(false);__testedInstance.getBody().querySelector('blockquote').append(' Edited');});
 assert.match(await ed(),/Edited/);assert.match(await ed(),/pid="703"/);
 await page.evaluate(()=>document.querySelector('#quick_reply_form').addEventListener('submit',e=>{e.preventDefault();window.__quoteSubmitted=document.querySelector('#message').value;}));
 await page.locator('#quick_reply_form button[type=submit]:not([name])').click();
 assert.match(await page.evaluate(()=>__quoteSubmitted),/pid="703"/);
 assert(!/undefined|\[left\]/.test(await page.evaluate(()=>__quoteSubmitted)));
 checks.push('D/E/F: visual alignment, named/empty/nested quote, pid/dateline, quote inside/outside alignment, edited round trip');
 for(const source of [true,false]) {
  await page.evaluate(source=>__testedInstance.sourceMode(source),source);
  const before=await ed();await cmd('maximize').click();
  const box=await shell.boundingBox();assert.equal(box.x,0);assert.equal(box.y,0);assert.equal(box.height,850);
  assert.equal(await shell.locator('.af-ccp-bar').isVisible(),true);
  assert.equal(await page.evaluate(()=>__testedInstance.getContentAreaContainer()===__testedFrame),true);
  assert.equal(await ed(),before);
  await popup('af_formathelp','.af-ae-format-help-body');await popup('af_fontsize','.af-ae-fontsize-picker');
  await cmd('af_menu_dropdown1').click();await page.locator('.af-ae-popup .af-ae-shell-menu').waitFor({state:'visible'});await page.keyboard.press('Escape');
  await cmd('af_togglemode').click();await cmd('af_togglemode').click();assert.equal(await ed(),before);
  await cmd('maximize').click();assert.equal((await dimensions()).height,initial.height);assert.equal(await ed(),before);
 }
 checks.push('H/J: fullscreen in both modes, mode switches, popup/menu and exact return geometry');
 // First published spoiler arrives by AJAX; editor spoiler pack remains unloaded.
 await page.evaluate(html=>document.querySelector('#pid_703').innerHTML='<blockquote class="mycode_quote">'+html+html+'</blockquote>',spoiler);
 await page.waitForFunction(()=>!!window.__afSpoilerViewBound);
 const heads=page.locator('#pid_703 .af-aqr-spoiler-head');
 await heads.nth(0).click();assert.equal(await page.locator('#pid_703 .af-aqr-spoiler-body').nth(0).isVisible(),true);
 assert.equal(await page.locator('#pid_703 .af-aqr-spoiler-body').nth(1).isVisible(),false);
 await heads.nth(0).click();assert.equal(await page.locator('#pid_703 .af-aqr-spoiler-body').nth(0).isVisible(),false);
 await heads.nth(1).focus();await page.keyboard.press('Enter');assert.equal(await page.locator('#pid_703 .af-aqr-spoiler-body').nth(1).isVisible(),true);
 assert(!requests.some(url=>url.endsWith('/spoiler.js')));
 checks.push('I: first AJAX published spoiler, several spoilers inside quote, click/keyboard toggle, no editor pack request');
 await page.addScriptTag({url:addon+'advancedjsbandle/assets/quote-avatars.js'});
 await page.locator('[name=previewpost]').click();
 await page.locator('.af-ccp-preview-body blockquote').waitFor();
 assert.match(await page.locator('.af-ccp-preview-body cite').textContent(),/Тестовый Автор/);
 assert.equal(await page.locator('.af-ccp-preview-body .af-qa-avatar').count(),1);
 checks.push('G: AJAX preview pid author and mini-avatar from real post metadata (HTTP response fixture)');
 // Lazy converters added AFTER instance creation must refresh the native map.
 await page.evaluate(()=>{__testedInstance.sourceMode(false);__testedInstance.val('Formatting');});
 await cmd('af_tables').click();await page.locator('.af-ae-popup .af-ae-tables-dropdown').waitFor({state:'visible'});
 await page.locator('.af-ae-popup [data-af-action=insert]').click();
 assert.match(await ed(),/\[table\b/);
 const tableValue=await ed();const tableTrip=await roundtrip(tableValue);
 assert.match(tableTrip[5].bb,/\[table\b/);assert.equal(tableTrip[1].bb,tableTrip[5].bb);
 checks.push('Lazy table converter after WYSIWYG activation; same instance and stable round trip');
 // Full Reply uses a new original node; unrelated SCEditor-owned fields stay ignored.
 await page.evaluate(()=>{const form=document.createElement('form');form.id='full_reply_fixture';form.innerHTML='<textarea name="message">Full reply</textarea>';document.body.appendChild(form);});
 await page.locator('#full_reply_fixture .af-ae-shell').waitFor();
 await page.locator('#full_reply_fixture [data-af-command=af_togglemode]').click();
 await page.waitForFunction(()=>!!document.querySelector('#full_reply_fixture textarea[name=message]')._sceditor);
 assert.equal(await page.locator('.af-ae-shell').count(),2);
 await page.evaluate(()=>document.querySelector('#full_reply_fixture').remove());
 await page.waitForFunction(()=>document.querySelectorAll('.af-ae-shell').length===1);
 await page.evaluate(()=>{document.querySelector('#pid_699').innerHTML='<form><textarea id="quickedit_699" name="value">Quick Edit 😀</textarea></form>';});
 await page.locator('#pid_699 [data-af-command=af_togglemode]').click();
 await page.waitForFunction(()=>!!document.querySelector('#quickedit_699')._sceditor);
 await page.evaluate(()=>window.__removedQuick=document.querySelector('#quickedit_699'));
 await page.locator('#pid_699 [data-af-command=maximize]').click();
 await page.locator('#pid_699 [data-af-command=af_formathelp]').click();await page.locator('.af-ae-popup').waitFor({state:'visible'});
 await page.evaluate(()=>document.querySelector('#pid_699').innerHTML='Saved');
 await page.waitForFunction(()=>__removedQuick.__afAeLifecycle==='destroyed');
 assert.equal(await page.locator('.af-ae-popup').count(),0);
 assert.equal(await page.evaluate(()=>document.documentElement.classList.contains('af-ae-shell-fullscreen-active')),false);
 assert.equal(await page.evaluate(()=>!!__removedQuick._sceditor),false);
 checks.push('Quick Edit WYSIWYG/fullscreen/popup destruction, original instance cleanup, no orphan popup/body lock');
 // Inline moderation: native first option is merge; action remains none until explicit click.
 await page.evaluate(()=>{
  const div=document.createElement('div');div.innerHTML='<input type="checkbox" name="inlinemod_703"><input type="checkbox" name="inlinemod_704"><form id="inlinemoderation_options"><input name="modtype" value="inlinepost"><select name="action"><option value="multimergeposts">Merge</option><option value="multideleteposts">Delete</option><option value="multiapproveposts">Approve</option><option value="multiunapproveposts">Unapprove</option><option value="multimoveposts">Move</option><option value="multisplitposts">Split</option></select><button name="go" type="submit">Go</button></form>';document.body.appendChild(div);
  window.__modSubmissions=[];document.querySelector('#inlinemoderation_options').addEventListener('submit',e=>{e.preventDefault();__modSubmissions.push(e.target.querySelector('select').value);});
 });
 await page.addScriptTag({url:addon+'advancedjsbandle/assets/fimp.js'});await page.waitForFunction(()=>!!window.__afFimpInit);
 const action=()=>page.locator('#inlinemoderation_options select').inputValue();
 assert.equal(await action(),'');await page.locator('[name=inlinemod_703]').check();assert.equal(await action(),'');
 assert.equal(await page.locator('#fimp [data-action=multimergeposts]').isDisabled(),true);
 await page.evaluate(()=>document.querySelector('#fimp [data-action=multimergeposts]').click());assert.equal(await action(),'');
 await page.locator('#fimp [data-action=multideleteposts]').click();assert.deepEqual(await page.evaluate(()=>__modSubmissions),['multideleteposts']);
 await page.locator('[name=inlinemod_704]').check();assert.equal(await action(),'');
 await page.locator('#fimp [data-action=multimergeposts]').click();assert.deepEqual(await page.evaluate(()=>__modSubmissions),['multideleteposts','multimergeposts']);
 for(const value of ['multiapproveposts','multiunapproveposts','multimoveposts','multisplitposts'])await page.locator('#fimp [data-action='+value+']').click();
 await page.locator('[name=inlinemod_703]').uncheck();await page.locator('[name=inlinemod_704]').uncheck();assert.equal(await action(),'');
 assert.equal(await page.locator('#fimp').getAttribute('data-current-action'),'');
 checks.push('K: action none, disabled merge ignored, delete/merge/approve/unapprove/move/split exact actions and reset');
 if(args.screenshot)await shell.screenshot({path:args.screenshot});
 assert.deepEqual(failedAssets,[],'Editor assets must return successful responses');
 assert.deepEqual(errors,[]);
 if(args.report)fs.writeFileSync(args.report,JSON.stringify({initial,activated,checks,errors,requests},null,2));
 console.log(checks.join('\n'));
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(async()=>{if(browser)await browser.close();});

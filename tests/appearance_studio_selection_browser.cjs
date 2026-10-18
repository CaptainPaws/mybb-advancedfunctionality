'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),{chromium}=require('playwright');
const base='inc/plugins/advancedfunctionality/addons/advancedappearance/';
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.AA_TEST_CHROMIUM||'/usr/bin/chromium',args:['--no-sandbox']});
 try {
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 // Use the shipped registry, including its original container-last ordering.
 const components={};
 for(const match of fs.readFileSync(base+'postbit_studio.php','utf8').matchAll(/'([^']+)'=>\$entry\('([^']*)','([^']*)'/g))components[match[1]]={label:match[2],selector:match[3],fields:['text_color']};
 assert.ok(components.signature_title&&components.signature);
 const seed={target:'postbit',surface:{components},list:{active_selection:{kind:'system'},presets:[],slots:{available:0,capacity:0,maximum:0},fonts:{}},fields:{text_color:['color']},fonts:{},uid:7};
 const ids=['aa-status','aa-cards','aa-editor','aa-field-groups','aa-avatar-id','aa-preview-style','aa-draft-status','aa-avatar-fields'];
 const buttons=['aa-save-draft','aa-avatar','aa-avatar-clear','aa-copy','aa-reset','aa-back'];
 await page.setContent(`<div class="aa-workbench">${ids.map(id=>`<div id="${id}"></div>`).join('')}<form id="aa-controls"></form><input id="aa-title"><input id="aa-description"><select id="aa-component">${Object.keys(components).map(k=>`<option>${k}</option>`).join('')}</select>${buttons.map(id=>`<button id="${id}"></button>`).join('')}<div class="aa-preview" id="aa-studio-preview"><article class="atf-post"><div class="atf-post__layout"><div class="atf-post__message-body">Message</div><div class="atf-post__signature" style="padding:30px"><div class="af-asb-signature af-asb-signature--quote"><div class="af-asb-signature__copy"><h2 class="af-asb-signature__title"><span>Title</span></h2><p class="af-asb-signature__subtitle">Subtitle</p><blockquote class="af-asb-signature__text"><em>Quote</em></blockquote></div><a class="af-asb-signature__button" href="https://example.com"><span>Link one</span></a><a class="af-asb-signature__button" href="https://example.com">Link two</a></div></div><summary class="atf-post__signature-toggle">Toggle</summary></div></article></div></div><script id="aa-studio-seed" type="application/json">${JSON.stringify(seed)}</script>`);
 await page.addStyleTag({content:fs.readFileSync(base+'assets/studio.css','utf8')});
 await page.addScriptTag({content:fs.readFileSync(base+'assets/studio.js','utf8')});
 const check=async(selector,key,count=1)=>{
   await page.locator(selector).first().click();
   assert.equal(await page.locator('#aa-component').inputValue(),key);
   assert.equal(await page.locator('[data-aa-selected]').count(),count);
   assert.equal(await page.locator('#aa-field-groups [data-setting="text_color"]').count(),1);
   assert.equal(await page.locator('[data-aa-selected]').first().getAttribute('aria-label'),components[key].label);
 };
 await check('.af-asb-signature__title span','signature_title');
 await check('.af-asb-signature__subtitle','signature_subtitle');
 await check('.af-asb-signature__text em','signature_quote');
 await check('.af-asb-signature__button span','signature_buttons');
 await page.locator('.af-asb-signature__button').nth(1).click();
 assert.equal(await page.locator('.af-asb-signature__button').nth(1).getAttribute('data-aa-selected'),'');
 assert.equal(page.url(),'about:blank','Preview link navigated');
 await page.locator('.atf-post__signature').click({position:{x:5,y:5}});
 assert.equal(await page.locator('#aa-component').inputValue(),'signature');
 await page.selectOption('#aa-component','signature_subtitle');
 assert.equal(await page.locator('.af-asb-signature__subtitle').getAttribute('data-aa-selected'),'');
 // Delegation must survive the server replacing preview markup.
 await page.locator('.af-asb-signature__title').evaluate(el=>el.outerHTML='<h2 class="af-asb-signature__title"><strong>New title</strong></h2>');
 await check('.af-asb-signature__title strong','signature_title');
 await page.locator('.atf-post__signature-toggle').click();
 assert.equal(await page.locator('#aa-component').inputValue(),'signature_title');
 await check('.atf-post__message-body','message');
 assert.deepEqual(errors,[]);
 console.log('APStudio selection passed: nested zones, links, blank container, dropdown, refreshed markup and disclosure controls.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

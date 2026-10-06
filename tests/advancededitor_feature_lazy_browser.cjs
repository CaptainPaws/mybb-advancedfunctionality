/* Run against PHP-generated fixtures and a MyBB jscripts directory; see docs/advancededitor-feature-lazy.md. */
'use strict';
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path'), http = require('http'), zlib = require('zlib'), assert = require('node:assert/strict');
const args = Object.fromEntries(process.argv.slice(2).reduce((pairs, value, i, all) => value.startsWith('--') ? pairs.concat([[value.slice(2), all[i + 1]]]) : pairs, []));
const root = path.resolve(__dirname, '..');
if (!args.html || !args['mybb-assets']) throw new Error('--html and --mybb-assets are required');
let mode = 'after', failTables = false;
const requests = [];
const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  requests.push({ mode, path: url.pathname, method: req.method });
  if (url.pathname === '/test/shared.js') { setTimeout(() => { res.setHeader('content-type', 'application/javascript'); res.end('window.__sharedLoads=(window.__sharedLoads||0)+1;'); }, 80); return; }
  if (url.pathname === '/test/a.js' || url.pathname === '/test/b.js') { res.setHeader('content-type', 'application/javascript'); res.end('window.__capTest=true;'); return; }
  if (failTables && url.pathname.endsWith('/tables.js')) { failTables = false; res.statusCode = 503; res.end('retry'); return; }
  if ((url.pathname === '/showthread.php' && req.method === 'POST')) { res.setHeader('content-type', 'text/html'); res.end('<div id="preview_post"><div class="post_body">Preview result</div></div>'); return; }
  if (url.pathname === '/misc.php') {
    res.setHeader('content-type', 'application/json');
    const action = url.searchParams.get('action');
    res.end(JSON.stringify(action === 'kb_types' ? { items: [{type:'rules',title:'Rules'}] } : action === 'kb_list' ? {items:[{type:'rules',key:'one',title:'Rule one'}]} : {success:true,data:{categories:[],recent:[],user_stickers:[],sizes:{post:80}},items:[]})); return;
  }
  let file;
  if (url.pathname === '/showthread.php') file = mode === 'before' ? args['baseline-html'] : args.html;
  else {
    const base = url.pathname.startsWith('/jscripts/') ? args['mybb-assets'] : mode === 'before' ? args.baseline : root;
    file = path.join(base, url.pathname);
  }
  try {
    let data = fs.readFileSync(file);
    res.setHeader('content-type', /\.js$/.test(file) ? 'application/javascript' : /\.css$/.test(file) ? 'text/css' : /\.svg$/.test(file) ? 'image/svg+xml' : /\.png$/.test(file) ? 'image/png' : 'text/html; charset=utf-8');
    if (/gzip/.test(req.headers['accept-encoding'] || '')) { res.setHeader('content-encoding', 'gzip'); data = zlib.gzipSync(data); }
    res.end(data);
  } catch (_) { res.statusCode = 404; res.end('missing'); }
});
let browser;
(async function () {
  await new Promise(resolve => server.listen(8765, '127.0.0.1', resolve));
  browser = await chromium.launch({ executablePath: args.chromium || '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] });
  const performanceReport = {};
  for (const sampleMode of args.baseline && args['baseline-html'] ? ['before','after'] : ['after']) {
    mode = sampleMode;
    const context = await browser.newContext(); const page = await context.newPage();
    const cdp = await context.newCDPSession(page); await cdp.send('Performance.enable');
    await page.goto('http://127.0.0.1:8765/showthread.php', { waitUntil: 'load' });
    await page.waitForTimeout(500);
    performanceReport[sampleMode] = await page.evaluate(() => {
      const entries = performance.getEntriesByType('resource').filter(x => x.initiatorType === 'script');
      const n = performance.getEntriesByType('navigation')[0];
      return { requests:entries.length, transferred:entries.reduce((sum,x)=>sum+x.transferSize,0), decoded:entries.reduce((sum,x)=>sum+x.decodedBodySize,0), domContentLoaded:n.domContentLoadedEventEnd, load:n.loadEventEnd, urls:entries.map(x=>x.name) };
    });
    performanceReport[sampleMode].scripting = (await cdp.send('Performance.getMetrics')).metrics.find(x=>x.name==='ScriptDuration').value * 1000;
    await context.close();
  }
  mode = 'after';
  const page = await browser.newPage(); const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.addInitScript(() => { window.__readyEvents=[]; document.addEventListener('af:editor-ready',e=>window.__readyEvents.push({id:e.detail.textarea.id,quick:e.detail.quickEdit,source:!!e.detail.instance.__afAeSourceAdapter})); });
  await page.goto('http://127.0.0.1:8765/showthread.php');
  await page.waitForFunction(() => !!window.afAdvancedEditorShell);
  const command = cmd => page.locator('#quick_reply_form .af-ae-shell > .sceditor-toolbar [data-af-command="'+cmd+'"]');
  const toolbar = page.locator('#quick_reply_form .af-ae-shell > .sceditor-toolbar');
  const initialHeight = (await toolbar.boundingBox()).height;
  for (const cmd of ['bold','italic','underline','color','af_tables','af_stikers','af_kb_insert','af_togglemode']) assert(await command(cmd).isVisible(), 'Initial button '+cmd);
  await page.locator('#message').focus(); await page.waitForTimeout(100);
  assert.equal(await page.evaluate(()=>!!jQuery.fn.sceditor), false);
  assert.equal(await page.evaluate(()=>!!window.__afAeStickerRuntimeObserver), false);
  assert.equal(await page.evaluate(()=>window.__afAeDraftsBooted), undefined);
  for (const [cmd,open,close] of [['bold','[b]','[/b]'],['italic','[i]','[/i]'],['underline','[u]','[/u]'],['quote','[quote]','[/quote]']]) {
    await page.evaluate(()=>{const ta=document.querySelector('#message');ta.value='А😀Б';ta.setSelectionRange(1,3);});
    await command(cmd).click();
    assert.equal(await page.locator('#message').inputValue(), 'А'+open+'😀'+close+'Б');
    assert.deepEqual(await page.evaluate(()=>{let ta=document.querySelector('#message');return [ta.selectionStart,ta.selectionEnd,document.activeElement===ta];}),[1+open.length,3+open.length,true]);
  }
  await page.evaluate(()=>{const ta=document.querySelector('#message');ta.value='ёж';ta.setSelectionRange(1,1);});
  await command('bold').click(); assert.equal(await page.locator('#message').inputValue(),'ё[b][/b]ж');
  assert.equal(await page.evaluate(()=>!!jQuery.fn.sceditor), false);
  failTables = true; await command('af_tables').click();
  await page.waitForFunction(()=>afAdvancedEditorShell.states.tables.state==='failed');
  assert(await page.locator('.af-ae-shell-error').isVisible());
  await command('italic').click();
  await command('af_tables').click();
  await page.locator('.af-ae-tables-dropdown').waitFor({state:'visible'});
  assert.equal(await page.evaluate(()=>afAdvancedEditorShell.states.tables.state),'loaded');
  assert.equal((await toolbar.boundingBox()).height,initialHeight);
  await page.locator('.af-ae-tables-dropdown [data-af-action="insert"]').click();
  assert.match(await page.locator('#message').inputValue(),/\[table\b/);
  for (const [cmd,selector] of [['color','.af-ae-jscolor-popup'],['af_font','.af-ff-dd'],['af_fontsize','.af-ae-fontsize-picker'],['af_spoiler','.af-spoiler-dd'],['af_tabs','.af-ae-tabs-picker']]) {
    await command(cmd).click(); await page.locator(selector).last().waitFor({state:'visible'});
    assert.equal((await toolbar.boundingBox()).height,initialHeight); await page.keyboard.press('Escape');
  }
  assert.equal(requests.filter(x=>x.mode==='after'&&x.path.endsWith('/jscolor.js')).length,1,'Shared jscolor dependency must be deduplicated');
  await command('af_stikers').click(); await page.locator('#af-ae-stikers-modal').waitFor({state:'visible'});
  assert.equal(await page.evaluate(()=>!!window.__afAeStickerRuntimeObserver),true);
  await page.locator('.af-ae-stikers-close').click();
  await command('af_kb_insert').click(); await page.locator('.af-kb-insert.is-active').waitFor({state:'visible'});
  await page.locator('.af-kb-insert-select').selectOption('rules'); await page.locator('.af-kb-insert-item button').click();
  assert.match(await page.locator('#message').inputValue(),/\[kb=rules:one\]/);
  await command('af_kb_insert').click(); await page.locator('.af-kb-modal-close').click();
  assert.equal(requests.filter(x=>x.mode==='after'&&x.path.endsWith('/knowledgebase_insert.js')).length,1);
  await command('af_accordion').click();
  assert.equal(requests.filter(x=>x.mode==='after'&&x.path.endsWith('/accordion.js')).length,0,'Simple pack should make no JS request');
  assert.equal(await page.evaluate(()=>!!jQuery.fn.sceditor),false);
  const promises = await page.evaluate(async()=>{
    const api=afAdvancedEditorShell;
    api.registry.shared={js:['/test/shared.js'],css:[],requires:[]};
    api.registry.a={js:['/test/a.js'],css:[],requires:['shared']};api.registry.b={js:['/test/b.js'],css:[],requires:['shared']};
    const a=api.loadCapability('a'),same=api.loadCapability('a')===a;await Promise.all([a,api.loadCapability('b')]);
    api.registry.cycle1={requires:['cycle2']};api.registry.cycle2={requires:['cycle1']};let cycle=false;try{await api.loadCapability('cycle1');}catch(e){cycle=api.states.cycle1.state==='failed';}
    api.registry.parallelCycle1={requires:['parallelCycle2']};api.registry.parallelCycle2={requires:['parallelCycle1']};
    const results=await Promise.allSettled([api.loadCapability('parallelCycle1'),api.loadCapability('parallelCycle2')]);
    const parallelCycle=results.every(result=>result.status==='rejected');
    return {same,shared:window.__sharedLoads,cycle,parallelCycle};
  });
  assert.deepEqual(promises,{same:true,shared:1,cycle:true,parallelCycle:true});
  // Native MyBB Quick Edit DOM contract, including delayed id assignment and removal.
  for (const action of ['cancel','save','cancel']) {
    await page.evaluate(()=>{
      const host=document.querySelector('#pid_699');host.innerHTML='<form><textarea name="value">Edit 😀</textarea><button type="submit">Save</button><button name="previewpost" type="submit">Preview</button><button type="button" class="cancel">Cancel</button></form>';
      const form=host.querySelector('form'),ta=form.querySelector('textarea');
      form.addEventListener('submit',e=>{e.preventDefault();window.__savedQuickText=ta.value;host.innerHTML='<div class="atf-post__message-body">Saved</div>';});
      form.querySelector('.cancel').addEventListener('click',()=>host.innerHTML='<div class="atf-post__message-body">Cancelled</div>');
      queueMicrotask(()=>ta.id='quickedit_699');
    });
    await page.locator('#pid_699 [data-af-command="bold"]').waitFor({state:'visible'});
    const loadedBefore=await page.evaluate(()=>Object.keys(afAdvancedEditorShell.states).filter(id=>afAdvancedEditorShell.states[id].state==='loaded'));
    await page.locator('#quickedit_699').evaluate(ta=>ta.setSelectionRange(5,7));
    await page.locator('#pid_699 [data-af-command="bold"]').click();
    assert.equal(await page.locator('#quickedit_699').inputValue(),'Edit [b]😀[/b]');
    assert.deepEqual(await page.evaluate(()=>Object.keys(afAdvancedEditorShell.states).filter(id=>afAdvancedEditorShell.states[id].state==='loaded')),loadedBefore);
    if(action==='cancel' && !(await page.evaluate(()=>afAdvancedEditorShell.states.charcountandprew.state==='loaded'))) {
      await page.locator('#pid_699 [name=previewpost]').click();
      await page.locator('#pid_699 .af-ccp-preview-body').filter({hasText:'Preview result'}).waitFor({state:'visible'});
      assert.equal(await page.locator('#quickedit_699').inputValue(),'Edit [b]😀[/b]');
      assert.equal(await page.evaluate(()=>!!jQuery.fn.sceditor),false);
    }
    if(action==='save') await page.locator('#pid_699 button[type=submit]:not([name])').click(); else await page.locator('#pid_699 .cancel').click();
    await page.waitForFunction(()=>!document.querySelector('#quickedit_699'));
    await page.waitForFunction(()=>!document.querySelector('.af-ccp-postcount').hidden);
    assert.equal(await page.locator('.atf-post__meta-line').textContent(),'Meta');
    assert.equal(await page.locator('#pid_699 .af-ccp-postcount').count(),0);
  }
  assert.equal(await page.evaluate(()=>!!jQuery.fn.sceditor),false);
  assert.equal(await page.evaluate(()=>window.__readyEvents.filter(e=>e.quick&&e.source).length),3);
  await page.evaluate(()=>document.querySelector('#quick_reply_form').addEventListener('submit',e=>{e.preventDefault();window.__submittedReply=document.querySelector('#message').value;}));
  await page.locator('#quick_reply_form button[type=submit]:not([name])').click();
  assert.equal(await page.evaluate(()=>window.__submittedReply),await page.locator('#message').inputValue());
  const beforeWys=await page.locator('#message').inputValue();
  await command('af_togglemode').click();
  await page.waitForFunction(()=>!!jQuery.fn.sceditor&&!!jQuery('#message').sceditor('instance'));
  assert.equal(await page.locator('#message').inputValue(),beforeWys);
  assert.equal(await page.evaluate(()=>jQuery('#message').sceditor('instance').inSourceMode()),false);
  assert.equal((await toolbar.boundingBox()).height,initialHeight);
  await page.locator('#quick_reply_form button[type=submit]:not([name])').click();
  assert.equal(await page.evaluate(()=>window.__submittedReply),beforeWys);
  await command('af_togglemode').click();
  assert.equal(await page.evaluate(()=>jQuery('#message').sceditor('instance').inSourceMode()),true);
  assert.equal(await page.evaluate(()=>jQuery('#message').sceditor('instance').val()),beforeWys);
  await page.locator('[name=previewpost]').click();
  await page.locator('.af-ccp-preview-body').filter({hasText:'Preview result'}).waitFor({state:'visible'});
  await page.evaluate(()=>jQuery('#message').sceditor('instance').val('Изменено 😀'));
  await page.locator('#quick_reply_form button[type=submit]:not([name])').click();
  assert.equal(await page.evaluate(()=>window.__submittedReply),'Изменено 😀','Actual edits must replace preserved initial text');
  const dependent = await page.evaluate(async()=>{
    const ta=document.createElement('textarea');ta.name='message';ta.value='Dependent 😀';document.querySelector('#quick_reply_form').appendChild(ta);
    afAdvancedEditorShell.init(ta);
    afAdvancedEditorShell.registry.requiresEditor={requires:['wysiwyg']};
    window.af_ae_customDependent_exec=editor=>window.__dependentSource=!!editor.__afAeSourceAdapter;
    const caller=document.createElement('button');
    await afAdvancedEditorShell.activate(ta,{cmd:'customDependent',handler:'customDependent',capability:'requiresEditor'},caller);
    const ok=!!jQuery(ta).sceditor('instance') && window.__dependentSource===false && ta.value==='Dependent 😀';
    ta.__afAeShell.remove();return ok;
  });
  assert.equal(dependent,true,'SCEditor-dependent capability initializes requested textarea');
  await command('af_drafts').click();
  await page.waitForFunction(()=>window.__afAeDraftsBooted&&document.querySelector('#message').form._afAeDraftsInstalled);
  assert.equal(errors.length,0,errors.join('\n'));
  assert.equal(await page.locator('.af-ae-shell-error').count(),0);
  const initial=performanceReport.after.urls.join('\n');
  for(const forbidden of ['sceditor','tables.js','jscolor','stikers.js','drafts.js','knowledgebase_insert.js','wysiwyg_bbcodes']) assert(!initial.includes(forbidden),'Initial request '+forbidden);
  if(args.report) fs.writeFileSync(args.report,JSON.stringify({performance:performanceReport,requests,checks:'passed'},null,2));
  console.log('Feature-lazy browser checks passed.'); console.log(JSON.stringify(performanceReport,null,2));
})().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{if(browser) await browser.close();server.close();});

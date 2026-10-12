const assert=require('node:assert/strict');
const {chromium}=require('playwright');
(async()=>{
  const origin=process.argv[2]||'http://127.0.0.1:8100';
  const browser=await chromium.launch({headless:true,executablePath:process.env.AA_TEST_CHROMIUM||'/usr/bin/chromium',args:['--no-sandbox']});
  const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const api=async(operation,data={})=>{
    const response=await page.request.post(origin+'/apstudio.php?api=personal',{data:{operation,my_post_key:'appearance-fixture-key',...data}});
    assert.equal(response.status(),200);return (await response.json()).data;
  };
  const list=await (await page.request.get(origin+'/apstudio.php?api=personal')).json();const id=Number(list.data.presets[0].id);
  const render=async()=>{await page.goto(origin+'/apstudio.php?fixture_action=render&owner=7');return await page.evaluate(()=>{
    const root=document.querySelector('#post_101'),text=root.querySelector('.atf-post__message-body'),layout=root.querySelector('.atf-post__layout');
    return {color:getComputedStyle(text).color,font:getComputedStyle(text).fontFamily,accent:getComputedStyle(root).getPropertyValue('--atf-post-accent').trim(),
      element:root.dataset.element,grid:getComputedStyle(layout).gridTemplateColumns,display:getComputedStyle(layout).display,
      shape:root.outerHTML.replace(/<style[\s\S]*?<\/style>/g,'')};
  });};
  const active=await render();assert.equal(active.color,'rgb(17, 51, 85)');assert.match(active.font,/Georgia/);assert.equal(active.accent,'#446688');assert.equal(active.element,'fire');
  await api('draft',{id,settings_json:'{"text_color":"#abcdef"}'});const draft=await render();assert.equal(draft.color,active.color);
  await api('reset');const base=await render();assert.notEqual(base.color,active.color);assert.notEqual(base.accent,active.accent);
  assert.equal(active.grid,base.grid);assert.equal(active.display,base.display);assert.equal(active.shape,base.shape);
  await api('activate',{id});const again=await render();assert.equal(again.color,active.color);assert.equal(again.grid,base.grid);
  // The same author's second post inherits the ordinary active preset, without any PID mask.
  assert.equal(await page.locator('#post_102 .atf-post__message-body').evaluate(n=>getComputedStyle(n).color),active.color);
  await api('save',{id,settings_json:'{"text_color":"#113355","font_family":"serif","accent_color":"#446688"}'});
  assert.deepEqual(errors,[]);await browser.close();
  console.log('Appearance browser passed: real ATF cascade, color/font/accent, isolated drafts, reset/reactivation, same author posts, unchanged grid/markup/canonical element.');
})().catch(e=>{console.error(e);process.exit(1);});

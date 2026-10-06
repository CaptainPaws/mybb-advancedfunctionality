'use strict';
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path'), http = require('http'), assert = require('node:assert/strict');
const { execFileSync } = require('child_process');
const root = path.resolve(__dirname, '..'), assets = '/inc/plugins/advancedfunctionality/addons/';
const php = process.env.PHP_BIN || 'php';
const form = process.env.FORM_HTML ? fs.readFileSync(process.env.FORM_HTML,'utf8') : execFileSync(php, [path.join(__dirname, 'fixtures/stage5_form.php')], {encoding:'utf8'});
const requests = [];
const server = http.createServer((req,res) => {
  requests.push(req.url);
  res.setHeader('content-type', /\.js$/.test(req.url) ? 'application/javascript' : /\.css$/.test(req.url) ? 'text/css' : 'text/html; charset=utf-8');
  if (req.url.startsWith('/slow')) return setTimeout(() => res.end('<h1>Loaded</h1>'), 350);
  if (req.url.startsWith('/never')) return; // timeout state uses the production timeout, accelerated in the test.
  if (req.url.startsWith(assets)) return res.end(fs.readFileSync(path.join(root, req.url)));
  if (req.url === '/form') return res.end(`<html><head><link rel="stylesheet" href="${assets}advancedthreadfields/assets/advancedthreadfields.css"></head><body style="margin:16px;background:#181b24;color:#eee">${form}<script src="${assets}advancedthreadfields/assets/advancedthreadfields-form.js"></script></body></html>`);
  res.end(`<html><head><link rel="stylesheet" href="${assets}charactersheets/assets/charactersheets-trigger.css"></head><body><a data-afcs-open="1" data-afcs-sheet="/slow?sheet=1" href="/slow?sheet=1">Sheet</a><a data-afcs-application="/slow?application=1" href="/slow?application=1">Application</a><a data-afcs-sheet="/never">Timeout</a><script src="${assets}charactersheets/assets/charactersheets-trigger.js"></script></body></html>`);
});
(async () => {
  await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
  const base = `http://127.0.0.1:${server.address().port}`;
  const browser = await chromium.launch({executablePath:process.env.CHROMIUM || '/usr/bin/chromium',headless:true,args:['--no-sandbox']});
  try {
    const page = await browser.newPage();
    const errors=[]; page.on('pageerror',e=>errors.push(e.message));
    await page.goto(base);
    assert.equal(await page.locator('iframe').count(),0);
    assert.equal(requests.some(x=>x.startsWith('/slow')),false);
    assert.equal(requests.some(x=>/\/charactersheets\.js/.test(x)),false);
    for (const [selector, close] of [['[data-afcs-open]','x'],['[data-afcs-open]','esc'],['[data-afcs-application]','overlay']]) {
      await page.locator(selector).click();
      await page.locator('.af-cs-modal__loader').waitFor({state:'visible'});
      assert.equal(await page.locator('.af-cs-modal__body').getAttribute('aria-busy'),'true');
      assert.match(await page.locator('iframe').getAttribute('src'), /embed=1/);
      assert.equal(await page.locator('[data-afcs-modal]').count(),1);
      await page.locator('.af-cs-modal__loader').waitFor({state:'hidden'});
      assert.equal(await page.locator('.af-cs-modal__body').getAttribute('aria-busy'),'false');
      if (close==='x') await page.locator('button[data-afcs-close]').click();
      else if (close==='esc') await page.keyboard.press('Escape');
      else await page.locator('.af-cs-modal__backdrop').click({position:{x:2,y:2}});
      assert.equal(await page.locator('iframe').count(),0);
    }
    // A close before load must not hide the next modal's loader.
    await page.locator('[data-afcs-open]').click(); await page.keyboard.press('Escape');
    await page.locator('[data-afcs-open]').click();
    await page.locator('.af-cs-modal__loader').waitFor({state:'visible'});
    await page.keyboard.press('Escape');
    await page.evaluate(() => { const real=window.setTimeout;window.setTimeout=(fn,ms,...args)=>real(fn,ms===30000?40:ms,...args); });
    await page.locator('[data-afcs-sheet="/never"]').click();
    await page.locator('.af-cs-modal__loader.is-error a').waitFor();
    assert.equal(await page.locator('.af-cs-modal__body').getAttribute('aria-busy'),'false');
    await page.keyboard.press('Escape');
    await page.locator('[data-afcs-open]').click();
    await page.locator('iframe').dispatchEvent('error');
    await page.locator('.af-cs-modal__loader.is-error a').waitFor();
    await page.keyboard.press('Escape');
    const geometry=[];
    for (const [width, columns] of [[1440,4],[1000,2],[600,1],[375,1]]) {
      await page.setViewportSize({width,height:1000}); await page.goto(base+'/form');
      await page.locator('.af-atf-ability-item').nth(1).waitFor();
      const result = await page.evaluate(() => {
        const grid=document.querySelector('.af-atf-character-top-grid');
        const boxes=[...document.querySelectorAll('.af-atf-form-field,.af-atf-ability-field')];
        return {columns:getComputedStyle(grid).gridTemplateColumns.split(' ').length,overflow:document.documentElement.scrollWidth>innerWidth,
          bad:boxes.filter(b=>{const l=b.querySelector('.af-atf-field-label,.af-atf-ability-label'),c=b.querySelector('input:not([type="hidden"]),select,textarea');return l&&c&&c.getBoundingClientRect().top-l.getBoundingClientRect().bottom<7;}).length,
          heights:[...document.querySelectorAll('input.af-atf-input:not([type="hidden"]),select.af-atf-input')].map(x=>x.getBoundingClientRect().height),
          sections:document.querySelectorAll('.af-atf-form-section').length};
      });
      assert.equal(result.columns,columns);assert.equal(result.overflow,false);assert.equal(result.bad,0);assert(result.heights.every(x=>x===42));assert.equal(result.sections,3);
      geometry.push({width,...result});
      if (process.env.SCREENSHOT_DIR) {fs.mkdirSync(process.env.SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.SCREENSHOT_DIR,`stage5-form-${width}.png`),fullPage:true});}
    }
    assert.deepEqual(errors,[]);
    console.log(JSON.stringify({passed:true,geometry,initialCharacterSheetsJsRequests:1,initialAtfJsRequests:0},null,2));
  } finally {await browser.close();server.closeAllConnections();server.close();}
})().catch(e=>{console.error(e);server.closeAllConnections();server.close();process.exitCode=1;});

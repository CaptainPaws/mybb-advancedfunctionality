// Real trigger runtime + real PHP sheet renderer behind an HTTP fixture.
const { chromium } = require('playwright');
const http = require('http');
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const assert = require('assert/strict');
const root = path.resolve(__dirname, '..');
const php = process.env.AF_TEST_PHP || 'php';
const requests = [];
const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  requests.push(url.pathname + url.search);
  if (url.pathname === '/charactersheets.php') {
    const child = spawnSync(php, [path.join(__dirname, 'fixtures/charactersheets_runtime.php'), root, url.searchParams.get('embed') === '1' ? 'embed' : 'full'], { env: { ...process.env, AF_TEST_HTML: '1' }, encoding: 'utf8' });
    res.writeHead(child.status === 0 ? 200 : 500, { 'content-type': 'text/html; charset=utf-8' });
    res.end(child.status === 0 ? child.stdout : child.stderr);
  } else if (url.pathname === '/showthread.php') {
    res.setHeader('content-type', 'text/html; charset=utf-8');
    res.end(`<link rel="stylesheet" href="/trigger.css"><button class="af-apui-postbit-action af-apui-postbit-action--sheet af-cs-plaque__btn" data-afcs-open="1" data-afcs-sheet="charactersheets.php?slug=thread-52">Sheet</button><script src="/trigger.js"></script>`);
  } else if (url.pathname === '/trigger.css') {
    res.setHeader('content-type', 'text/css');
    res.end(fs.readFileSync(path.join(root, 'inc/plugins/advancedfunctionality/addons/charactersheets/assets/charactersheets-trigger.css')));
  } else if (url.pathname === '/trigger.js') {
    res.setHeader('content-type', 'application/javascript');
    res.end(fs.readFileSync(path.join(root, 'inc/plugins/advancedfunctionality/addons/charactersheets/assets/charactersheets-trigger.js')));
  } else { res.writeHead(404); res.end(); }
});
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const base = `http://127.0.0.1:${server.address().port}`;
  let browser;
  try {
    browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] });
    const page = await browser.newPage();
    page.setDefaultTimeout(8000);
    await page.addInitScript(() => {
      window.__activeKeys = 0;
      window.__emptyFrames = [];
      const add = document.addEventListener.bind(document);
      const remove = document.removeEventListener.bind(document);
      document.addEventListener = (name, fn, ...rest) => { if (name === 'keydown') window.__activeKeys++; return add(name, fn, ...rest); };
      document.removeEventListener = (name, fn, ...rest) => { if (name === 'keydown') window.__activeKeys--; return remove(name, fn, ...rest); };
      const append = Element.prototype.appendChild;
      Element.prototype.appendChild = function (node) {
        if (node.matches && node.matches('[data-afcs-modal]')) window.__emptyFrames.push(node.querySelector('iframe').getAttribute('src'));
        return append.call(this, node);
      };
    });
    for (const suffix of ['', '&embed=1']) {
      const response = await page.goto(base + '/charactersheets.php?slug=thread-52' + suffix);
      assert.equal(response.status(), 200, 'Direct route returned HTTP 500');
      assert.equal(await page.locator('[data-afcs-sheet-id="1"]').count(), 1);
    }
    requests.length = 0;
    await page.goto(base + '/showthread.php');
    await page.waitForFunction(() => window.__afCharacterSheetsTrigger === true);
    assert.equal(await page.locator('iframe').count(), 0);
    assert.equal(requests.filter(x => x.startsWith('/charactersheets.php')).length, 0);
    assert.equal(requests.some(x => /charactersheets\.(?:js|css)/.test(x)), false);
    const open = async () => {
      await page.locator('.af-cs-plaque__btn').click();
      const frame = page.frameLocator('[data-afcs-frame]');
      await frame.locator('[data-afcs-sheet-id="1"]').waitFor();
      assert.equal(await page.locator('[data-afcs-modal]').count(), 1);
      assert.equal(await page.locator('body.af-cs-modal-open').count(), 1);
      assert.match(await page.locator('iframe').getAttribute('src'), /slug=thread-52&embed=1/);
      await page.evaluate(() => {
        const frame = document.querySelector('iframe');
        window.__lastFrame = frame;
        const remove = frame.removeAttribute.bind(frame);
        frame.removeAttribute = name => { if (name === 'src') window.__srcRemoved = (window.__srcRemoved || 0) + 1; return remove(name); };
      });
    };
    const closed = async () => {
      assert.equal(await page.locator('iframe, [data-afcs-modal]').count(), 0);
      assert.equal(await page.locator('body.af-cs-modal-open').count(), 0);
      assert.equal(await page.evaluate(() => window.__lastFrame.hasAttribute('src')), false);
      assert.equal(await page.evaluate(() => window.__activeKeys), 0);
    };
    await open(); await page.locator('.af-cs-modal__close').click(); await closed();
    await open(); await page.locator('.af-cs-modal__backdrop').click({ position: {x: 5, y: 5} }); await closed();
    await open(); await page.keyboard.press('Escape'); await closed();
    assert.equal(await page.evaluate(() => window.__srcRemoved), 3);
    // Opening again replaces the old frame and removes its src first.
    await open();
    const old = await page.locator('iframe').elementHandle();
    await page.locator('.af-cs-plaque__btn').evaluate(button => button.click());
    assert.equal(await old.getAttribute('src'), null);
    await page.keyboard.press('Escape'); await closed();
    assert.deepEqual(await page.evaluate(() => window.__emptyFrames), [null, null, null, null, null]);
    console.log('Direct/embed HTTP 200; lazy click; X/overlay/ESC; replacement and cleanup passed.');
  } finally {
    if (browser) await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
})().catch(error => { console.error(error); process.exitCode = 1; });

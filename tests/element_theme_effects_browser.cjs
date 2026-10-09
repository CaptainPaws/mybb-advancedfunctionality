'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium, firefox } = require('playwright');
const root = path.resolve(__dirname, '..');
const addons = path.join(root, 'inc/plugins/advancedfunctionality/addons');
const assets = path.join(addons, 'advancedelementtheme/assets');
const componentCSS = ['adaptivethemeframework/assets/surfaces/profile.css', 'adaptivethemeframework/assets/surfaces/postbit.css', 'charactersheets/assets/charactersheets.css', 'advancedthreadfields/assets/advancedthreadfields.css'];
(async () => {
  const fixture = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'element_theme_effects_regression.php'), '--browser-fixture'], { encoding: 'utf8' }));
  const isFirefox = process.env.AF_TEST_BROWSER === 'firefox';
  const browser = await (isFirefox ? firefox : chromium).launch({ headless: true, ...(!isFirefox ? { executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', args: ['--no-sandbox'] } : {}) });
  try {
    const context = await browser.newContext({ viewport: { width: 1100, height: 900 } });
    await context.route('https://forum.test/**', route => {
      const file = path.basename(new URL(route.request().url()).pathname);
      return fs.existsSync(path.join(assets, file)) ? route.fulfill({ body: fs.readFileSync(path.join(assets, file)), contentType: file.endsWith('.js') ? 'application/javascript' : 'text/css' }) : route.abort();
    });
    const page = await context.newPage();
    await page.setContent(fixture.html);
    for (const file of componentCSS) await page.addStyleTag({ content: fs.readFileSync(path.join(addons, file), 'utf8') });
    const layer = page.locator('#profile [data-af-element-effect]');
    await page.waitForFunction(() => document.querySelector('#profile [data-af-element-effect]').hasAttribute('data-af-effect-running'));
    assert.equal(await page.locator('[data-af-effect-ready]').count(), 4);
    assert.equal(await page.locator('#disabled-host [data-af-element-effect]').count(), 0);
    assert.equal(await page.locator('#neutral-host [data-af-element-effect]').count(), 0);
    assert.equal(await layer.evaluate(e => getComputedStyle(e).pointerEvents), 'none');
    assert.equal(await layer.evaluate(e => getComputedStyle(e).zIndex), '-1');
    assert.equal(await layer.evaluate(e => getComputedStyle(e).getPropertyValue('--af-effect-color').trim()), '#37c4ff');
    assert.equal(await layer.evaluate(e => getComputedStyle(e, '::before').animationName), 'af-effect-embers');
    assert.equal(await page.locator('#postbit [data-af-element-effect]').evaluate(e => getComputedStyle(e, '::before').animationName), 'af-effect-stardust');
    assert.equal(await page.locator('#postbit [data-af-element-effect]').evaluate(e => (getComputedStyle(e, '::before').backgroundImage.match(/radial-gradient/g) || []).length), 3);
    await page.locator('#profile-button').evaluate(e => e.addEventListener('click', () => e.dataset.clicked = 'yes'));
    await page.locator('#profile-button').click(); assert.equal(await page.locator('#profile-button').getAttribute('data-clicked'), 'yes');
    const initialBox = await page.locator('#profile').boundingBox();
    await page.locator('#profile').evaluate(e => { e.style.display = 'none'; });
    await page.waitForFunction(() => !document.querySelector('#profile [data-af-element-effect]').hasAttribute('data-af-effect-running'));
    await page.locator('#profile').evaluate(e => { e.style.display = ''; });
    await page.waitForFunction(() => document.querySelector('#profile [data-af-element-effect]').hasAttribute('data-af-effect-running'));
    assert.deepEqual(await page.locator('#profile').boundingBox(), initialBox, 'Animation lifecycle shifted layout');
    // Off-screen components pause, and document inactivity pauses every tracked layer.
    await page.locator('#profile').evaluate(e => { e.style.marginTop = '2000px'; });
    await page.waitForFunction(() => !document.querySelector('#profile [data-af-element-effect]').hasAttribute('data-af-effect-running'));
    await page.locator('#profile').evaluate(e => { e.style.marginTop = ''; });
    await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, value: true }); document.dispatchEvent(new Event('visibilitychange')); });
    assert.equal(await page.locator('[data-af-effect-running]').count(), 0);
    await page.evaluate(() => { delete document.hidden; document.dispatchEvent(new Event('visibilitychange')); });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    assert.equal(await layer.evaluate(e => getComputedStyle(e, '::before').animationName), 'none');
    await page.setViewportSize({ width: 390, height: 844 });
    assert.equal(await layer.evaluate(e => (getComputedStyle(e, '::before').backgroundImage.match(/radial-gradient/g) || []).length), 3);
    await page.evaluate(html => document.body.insertAdjacentHTML('beforeend', html), fixture.sheet);
    await page.waitForFunction(() => document.querySelector('#modal-sheet [data-af-element-effect]').hasAttribute('data-af-effect-ready'));
    await page.locator('#modal-sheet').evaluate(e => e.remove());
    // Old installed templates receive just a decorative node, with no template restore.
    await page.locator('#profile [data-af-element-effect]').evaluate(e => e.remove());
    await page.locator('#profile').evaluate(e => e.insertAdjacentHTML('beforeend', '<small>updated hero</small>'));
    await page.waitForFunction(() => document.querySelectorAll('#profile [data-af-element-effect]').length === 1);
    await page.setViewportSize({ width: 1100, height: 900 });
    // ACP live preview uses the same compiler textures/asset keyframes for all six presets.
    await page.setContent(fixture.editor);
    await page.waitForFunction(() => document.querySelector('[data-af-et-effect-preview] [data-af-effect-ready]'));
    for (const preset of ['stardust', 'embers', 'mist', 'aura', 'electric', 'frost']) {
      await page.selectOption('[name="effect_preset"]', preset);
      await page.emulateMedia({ reducedMotion: 'no-preference' });
      assert.equal(await page.locator('[data-af-et-effect-preview] [data-af-element-effect]').evaluate(e => getComputedStyle(e, '::before').animationName), 'af-effect-' + preset);
    }
    await page.selectOption('[name="effect_preset"]', 'aura');
    await page.screenshot({ path: process.env.AF_EFFECT_SCREENSHOT || '/tmp/af-element-effect-preview.png', fullPage: true });
    await page.selectOption('[data-af-et-effect-preview-surface]', 'postbit');
    assert.equal(await page.locator('[data-af-et-effect-preview] [data-af-element-effect]').evaluate(e => getComputedStyle(e, '::before').animationName), 'af-effect-stardust');
    await page.uncheck('[name="effect_enabled"]');
    assert.equal(await page.locator('[data-af-et-effect-preview] [data-af-element-effect]').evaluate(e => getComputedStyle(e).display), 'none');
    // JS-off fallback: existing palette and content remain intact, effects stay hidden.
    const noJS = await browser.newContext({ javaScriptEnabled: false });
    await noJS.route('https://forum.test/**', route => {
      const file = path.basename(new URL(route.request().url()).pathname);
      return route.fulfill({ body: fs.readFileSync(path.join(assets, file)), contentType: file.endsWith('.js') ? 'application/javascript' : 'text/css' });
    });
    const fallback = await noJS.newPage(); await fallback.setContent(fixture.html);
    assert.equal(await fallback.locator('#profile [data-af-element-effect]').evaluate(e => getComputedStyle(e).display), 'none');
    assert.equal(await fallback.locator('#profile-button').isVisible(), true);
    await noJS.close();
    console.log(`ElementTheme effects ${isFirefox ? 'Firefox' : 'Chromium'}: six previews, four surfaces, palette inheritance, bounded postbit/mobile, visibility/inactivity, reduced motion, AJAX, no-JS fallback and click-through passed.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });

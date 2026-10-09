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
    await page.addStyleTag({ content: 'body { --atf-space-4: 16px; --atf-color-page-subtle: #10151c; --af-apui-postbit-author-bg-image: linear-gradient(#202530,#11151a); }' });
    const layer = page.locator('body > [data-af-element-effect]');
    await page.waitForFunction(() => document.querySelector('body > [data-af-element-effect]').hasAttribute('data-af-effect-running'));
    assert.equal(await page.locator('[data-af-effect-ready]').count(), 5);
    assert.equal(await page.locator('#disabled-host [data-af-element-effect]').count(), 0);
    assert.equal(await page.locator('#neutral-host [data-af-element-effect]').count(), 0);
    assert.equal(await layer.evaluate(e => getComputedStyle(e).pointerEvents), 'none');
    assert.equal(await layer.evaluate(e => getComputedStyle(e).zIndex), '-1');
    assert.equal(await layer.evaluate(e => getComputedStyle(e).getPropertyValue('--af-effect-color').trim()), '#37c4ff');
    assert.equal(await layer.evaluate(e => getComputedStyle(e, '::before').animationName), 'af-effect-embers');
    assert.equal(await page.locator('#postbit .atf-post__topbar > [data-af-element-effect]').evaluate(e => getComputedStyle(e, '::before').animationName), 'af-effect-stardust');
    assert.equal(await page.locator('#postbit .atf-post__sidebar > [data-af-element-effect]').count(), 0);
    const rail = page.locator('#postbit .atf-post__sidebar-inner');
    const railStyle = await rail.evaluate(e => {
      const style = getComputedStyle(e), background = style.backgroundImage;
      e.removeAttribute('data-af-element-effect-active');
      const disabledBackground = getComputedStyle(e).backgroundImage;
      e.setAttribute('data-af-element-effect-active', '');
      return { background, disabledBackground, maxHeight: style.maxHeight, overflow: style.overflowY,
        layerZ: getComputedStyle(e.querySelector(':scope > [data-af-element-effect]')).zIndex,
        firstMargin: getComputedStyle(e.querySelector(':scope > :not([data-af-element-effect])')).marginTop };
    });
    assert.equal(railStyle.background, railStyle.disabledBackground, 'Effect replaced the APUI background');
    assert.ok(railStyle.background.includes('gradient'));
    assert.equal(railStyle.maxHeight, 'none');
    assert.equal(railStyle.overflow, 'visible');
    assert.equal(railStyle.layerZ, '0');
    assert.equal(railStyle.firstMargin, '0px', 'Decoration counted as the first content block');
    assert.equal(await page.locator('#postbit .atf-post__topbar > [data-af-element-effect]').evaluate(e => (getComputedStyle(e, '::before').backgroundImage.match(/radial-gradient/g) || []).length), 3);
    await page.locator('#profile-button').evaluate(e => e.addEventListener('click', () => e.dataset.clicked = 'yes'));
    await page.locator('#profile-button').click(); assert.equal(await page.locator('#profile-button').getAttribute('data-clicked'), 'yes');
    assert.equal(await page.locator('#profile .atf-profile__panel h2').evaluate(e => getComputedStyle(e).color), 'rgb(55, 196, 255)');
    assert.equal(await page.locator('#profile-button').evaluate(e => getComputedStyle(e).color), 'rgb(55, 196, 255)');
    // Root-sized layers and invariant layout, including the real ATF topbar > * rule.
    for (const selector of ['body', '#sheet-host', '#application', '#postbit .atf-post__topbar', '#postbit .atf-post__sidebar-inner']) {
      const result = await page.locator(selector).evaluate(host => {
        const layer = Array.from(host.children).find(e => e.hasAttribute('data-af-element-effect'));
        const a = host.getBoundingClientRect(), b = layer.getBoundingClientRect();
        const before = [a.x, a.y, a.width, a.height, host.matches('.atf-post__topbar') ? getComputedStyle(host).position : host.querySelector('.atf-post__sidebar-inner') ? getComputedStyle(host.querySelector('.atf-post__sidebar-inner')).position : null];
        const palette = getComputedStyle(host).getPropertyValue('--af-element-accent');
        layer.removeAttribute('data-af-effect-ready'); host.removeAttribute('data-af-element-effect-active'); layer.remove();
        const off = host.getBoundingClientRect();
        const offBox = [off.x, off.y, off.width, off.height, host.matches('.atf-post__topbar') ? getComputedStyle(host).position : host.querySelector('.atf-post__sidebar-inner') ? getComputedStyle(host.querySelector('.atf-post__sidebar-inner')).position : null];
        host.prepend(layer); host.setAttribute('data-af-element-effect-active', ''); layer.setAttribute('data-af-effect-ready', '');
        return { before, offBox, isolation: getComputedStyle(host).isolation, bounds: [host === document.body ? innerWidth : host.clientWidth, host === document.body ? innerHeight : host.clientHeight, Math.round(b.width), Math.round(b.height)], palette, afterPalette: getComputedStyle(host).getPropertyValue('--af-element-accent') };
      });
      assert.equal(result.isolation, 'isolate', selector);
      assert.ok(Math.abs(result.bounds[0] - result.bounds[2]) <= 1 && Math.abs(result.bounds[1] - result.bounds[3]) <= 1, selector + ' host coverage');
      assert.deepEqual(result.before, result.offBox, selector + ' geometry/sticky changed');
      assert.equal(result.palette, result.afterPalette);
    }
    assert.equal(await page.locator('#sheet > [data-af-element-effect], #profile > [data-af-element-effect]').count(), 0);
    // Real paint on the external host: decoration survives an opaque background,
    // while the profile body never acquires authored :scope layout properties.
    for (const selector of ['body', '#sheet-host', '#postbit .atf-post__sidebar-inner']) {
      const host = page.locator(selector);
      const decoration = host.locator(':scope > [data-af-element-effect]');
      await page.emulateMedia({ reducedMotion: 'reduce' });
      await host.evaluate(e => e.style.background = 'rgb(2,3,4)');
      await decoration.evaluate(e => { e.style.setProperty('--af-effect-field', 'linear-gradient(lime,lime)'); e.style.setProperty('--af-effect-light-points', 'linear-gradient(lime,lime)'); e.style.setProperty('--af-effect-opacity', '1'); e.style.setProperty('--af-effect-strength', '1'); e.removeAttribute('data-af-effect-ready'); });
      const off = await host.screenshot();
      await decoration.evaluate(e => e.setAttribute('data-af-effect-ready', ''));
      assert.notDeepEqual(await host.screenshot(), off, selector + ' background hid effect');
      await decoration.evaluate(e => e.removeAttribute('style'));
      await host.evaluate(e => e.style.removeProperty('background'));
    }
    // Verify paint order in the engine, not only computed z-index: decoration
    // must remain visible above an opaque root background but below its content.
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const rootStyle = await page.locator('#application').getAttribute('style');
    const appLayer = page.locator('#application > [data-af-element-effect]');
    await page.locator('#application').evaluate(e => { e.style.padding = '24px'; e.style.background = 'rgb(2,3,4)'; });
    await appLayer.evaluate(e => { e.style.setProperty('--af-effect-field', 'linear-gradient(lime,lime)'); e.style.setProperty('--af-effect-light-points', 'linear-gradient(lime,lime)'); e.style.setProperty('--af-effect-opacity', '1'); e.style.setProperty('--af-effect-strength', '1'); e.removeAttribute('data-af-effect-ready'); });
    const paintedOff = await page.locator('#application').screenshot();
    await appLayer.evaluate(e => e.setAttribute('data-af-effect-ready', ''));
    const paintedOn = await page.locator('#application').screenshot();
    assert.notDeepEqual(paintedOn, paintedOff, 'Root background hid the decorative layer');
    await appLayer.evaluate(e => e.removeAttribute('style'));
    await page.locator('#application').evaluate((e, style) => style === null ? e.removeAttribute('style') : e.setAttribute('style', style), rootStyle);
    await page.locator('#profile').scrollIntoViewIfNeeded();
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    const initialBox = await page.locator('#sheet-host').boundingBox();
    await page.locator('#sheet-host').evaluate(e => { e.style.display = 'none'; });
    await page.waitForFunction(() => !document.querySelector('#sheet-host > [data-af-element-effect]').hasAttribute('data-af-effect-running'));
    await page.locator('#sheet-host').evaluate(e => { e.style.display = ''; });
    await page.waitForFunction(() => document.querySelector('#sheet-host > [data-af-element-effect]').hasAttribute('data-af-effect-running'));
    const restoredBox = await page.locator('#sheet-host').boundingBox();
    assert.deepEqual([restoredBox.width, restoredBox.height], [initialBox.width, initialBox.height], 'Animation lifecycle shifted layout');
    // Off-screen components pause, and document inactivity pauses every tracked layer.
    await page.locator('#sheet-host').evaluate(e => { e.style.marginTop = '2000px'; });
    await page.waitForFunction(() => !document.querySelector('#sheet-host > [data-af-element-effect]').hasAttribute('data-af-effect-running'));
    await page.locator('#sheet-host').evaluate(e => { e.style.marginTop = ''; });
    await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, value: true }); document.dispatchEvent(new Event('visibilitychange')); });
    assert.equal(await page.locator('[data-af-effect-running]').count(), 0);
    await page.evaluate(() => { delete document.hidden; document.dispatchEvent(new Event('visibilitychange')); });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    assert.equal(await layer.evaluate(e => getComputedStyle(e, '::before').animationName), 'none');
    await page.setViewportSize({ width: 390, height: 844 });
    assert.equal(await layer.evaluate(e => (getComputedStyle(e, '::before').backgroundImage.match(/radial-gradient/g) || []).length), 3);
    await page.evaluate(html => document.body.insertAdjacentHTML('beforeend', html), fixture.sheet);
    await page.waitForFunction(() => document.querySelector('#modal-sheet-host > [data-af-element-effect]').hasAttribute('data-af-effect-ready'));
    await page.locator('#modal-sheet-host').evaluate(e => e.remove());
    // Old installed templates receive just a decorative node, with no template restore.
    await page.locator('body > [data-af-element-effect]').evaluate(e => e.remove());
    await page.locator('#profile').evaluate(e => e.insertAdjacentHTML('beforeend', '<small>updated hero</small>'));
    await page.waitForFunction(() => document.querySelectorAll('body > [data-af-element-effect]').length === 1);
    await page.setViewportSize({ width: 1100, height: 900 });
    // Browser replay of actual approved-provider -> PHP pre_output DOM, including
    // an empty ATF global and a conflicting fire global for an unapproved owner.
    const profileDOM = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'element_theme_profile_flow_regression.php'), '--browser-fixture'], { encoding: 'utf8' }));
    const deliveredHead = fixture.html.match(/<head>([\s\S]*?)<\/head>/)[1];
    for (const uid of ['1', '2']) {
      const rendered = profileDOM[uid].replace('</head>', deliveredHead + '</head>');
      const checked = await context.newPage();
      await checked.setContent(rendered);
      await checked.addStyleTag({ content: fs.readFileSync(path.join(addons, 'adaptivethemeframework/assets/surfaces/profile.css'), 'utf8') });
      const expected = uid === '1' ? 'fire' : '';
      assert.equal(await checked.locator('body').getAttribute('data-element'), expected);
      assert.equal(await checked.locator('main.atf-profile').getAttribute('data-element'), expected);
      if (uid === '1') {
        await checked.waitForFunction(() => document.querySelector('body > [data-af-element-effect]')?.hasAttribute('data-af-effect-ready'));
        assert.equal(await checked.locator('main').evaluate(e => getComputedStyle(e).getPropertyValue('--af-element-accent').trim()), '#37c4ff');
        for (const selector of ['.atf-profile-hero__name', '.atf-profile-nav__item', '.atf-profile__panel h2']) assert.equal(await checked.locator(selector).evaluate(e => getComputedStyle(e).color), 'rgb(55, 196, 255)');
        assert.notEqual(await checked.locator('.atf-profile-hero').evaluate(e => getComputedStyle(e).borderTopColor), 'rgba(0, 0, 0, 0)');
      } else assert.equal(await checked.locator('[data-af-effect-ready]').count(), 0);
      await checked.close();
    }
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
    assert.equal(await fallback.locator('body > [data-af-element-effect]').evaluate(e => getComputedStyle(e).display), 'none');
    assert.equal(await fallback.locator('#profile-button').isVisible(), true);
    await noJS.close();
    console.log(`ElementTheme effects ${isFirefox ? 'Firefox' : 'Chromium'}: six previews, four surfaces, palette inheritance, bounded postbit/mobile, visibility/inactivity, reduced motion, AJAX, no-JS fallback and click-through passed.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });

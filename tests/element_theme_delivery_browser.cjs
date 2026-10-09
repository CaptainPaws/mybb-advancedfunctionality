'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium, firefox } = require('playwright');
const root = path.resolve(__dirname, '..');
const addons = path.join(root, 'inc/plugins/advancedfunctionality/addons');
const read = file => fs.readFileSync(path.join(addons, file), 'utf8');
(async () => {
  const fixture = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'element_theme_delivery_regression.php'), '--browser-fixture'], { encoding: 'utf8' }));
  const isFirefox = process.env.AF_TEST_BROWSER === 'firefox';
  const browser = await (isFirefox ? firefox : chromium).launch({ headless: true, ...(!isFirefox && process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}), ...(!isFirefox ? { args: ['--no-sandbox'] } : {}) });
  try {
    const page = await browser.newPage();
    // Use the actual owner's base link and compiled inline stylesheet, not a parallel CSS fixture.
    await page.route('https://forum.test/**/element-theme.css*', route => route.fulfill({ contentType: 'text/css', body: read('advancedelementtheme/assets/element-theme.css') }));
    await page.setContent(fixture.html);
    assert.equal(await page.locator('link[data-af-element-theme]').count(), 1);
    assert.equal(await page.locator('style[data-af-element-theme-overrides]').count(), 1);
    // Late real component styles emulate the AF asset pipeline. Late high-specificity
    // legacy variables emulate stale theme bundles; ACP tokens must still win.
    for (const file of ['advancedthreadfields/assets/advancedthreadfields.css', 'charactersheets/assets/charactersheets.css', 'advancedprofileui/assets/advancedprofileui.css', 'adaptivethemeframework/assets/surfaces/postbit.css', 'adaptivethemeframework/assets/surfaces/profile.css']) await page.addStyleTag({ content: read(file) });
    await page.addStyleTag({ content: 'body.atf-active [data-element="fire"][data-element-surface] { --af-element-accent:#ff0000; --af-element-main:#ff0000; } body.atf-active.atf-profile-page .atf-profile .atf-profile-hero__name { color:rgb(255,0,0) !important; letter-spacing:0; } body.atf-active .atf-profile {outline:1px solid red !important;}' });
    assert.equal(await page.evaluate(() => CSS.supports('color', 'color-mix(in srgb, red, blue)')), true);
    for (const id of ['application-accent', 'sheet-accent', 'postbit-accent', 'profile-accent']) {
      assert.equal(await page.locator('#' + id).evaluate(e => getComputedStyle(e).color), 'rgb(18, 171, 52)', `${id} did not apply saved Global Accent`);
    }
    for (const id of ['application', 'sheet', 'postbit']) assert.equal(await page.locator('#' + id).evaluate(e => getComputedStyle(e).getPropertyValue('--af-element-main').trim()), '#234567');
    assert.equal(await page.locator('#profile').evaluate(e => getComputedStyle(e).getPropertyValue('--af-element-main').trim()), '#b012cd');
    assert.equal(await page.locator('#profile-custom').evaluate(e => getComputedStyle(e).color), 'rgb(21, 32, 43)', 'Surface Custom CSS lost to global/ATF rules');
    assert.equal(await page.locator('#profile-custom').evaluate(e => getComputedStyle(e).letterSpacing), '3px', 'Global Custom CSS missing');
    assert.equal(await page.locator('#profile').evaluate(e => getComputedStyle(e).outlineWidth), '5px', 'Scoped root or surface precedence failed');
    assert.notEqual(await page.locator('#water-custom').evaluate(e => getComputedStyle(e).color), 'rgb(21, 32, 43)', 'Custom CSS escaped element');
    assert.equal(await page.locator('#neutral-accent').evaluate(e => getComputedStyle(e).color), 'rgb(123, 129, 140)', 'Neutral approved-character gate lost');
    assert.equal(await page.locator('#shadow').getAttribute('data-element'), 'shadow');
    assert.equal(await page.locator('#shadow').evaluate(e => getComputedStyle(e).getPropertyValue('--af-element-main').trim()), '#76528f');
    await page.evaluate(html => document.body.insertAdjacentHTML('beforeend', html), fixture.sheet);
    assert.equal(await page.locator('#modal-accent').evaluate(e => getComputedStyle(e).color), 'rgb(18, 171, 52)', 'Dynamically inserted sheet lost palette');
    console.log(`ElementTheme ${isFirefox ? 'Firefox' : 'Chromium'}: delivered CSS applied to application, sheet/modal, postbit, ATF profile; Global/Surface/custom cascade and neutral/shadow passed.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });

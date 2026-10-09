'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '..');
const assets = path.join(root, 'inc/plugins/advancedfunctionality/addons/advancedelementtheme/assets');

(async function () {
  const fixture = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'element_theme_editor_regression.php'), '--browser-fixture'], { encoding: 'utf8' }));
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}), args: ['--no-sandbox'] });
  try {
    const page = await browser.newPage();
    await page.setContent(fixture.editor.replace(/<link\b[^>]*>|<script\b[^>]*>[\s\S]*?<\/script>/gi, ''));
    await page.addStyleTag({ content: fs.readFileSync(path.join(assets, 'advancedelementtheme-admin.css'), 'utf8') });
    await page.addScriptTag({ content: fs.readFileSync(path.join(assets, 'advancedelementtheme-admin.js'), 'utf8') });
    assert.equal(await page.locator('.af-et-tabs a').count(), 6);
    assert.equal(await page.locator('.af-et-tab.is-active').innerText(), 'Profile');
    assert.match(await page.locator('form').getAttribute('action'), /action=edit.*element_key=fire.*surface=profile/);
    const rows = page.locator('[data-af-et-extra-rows] tr');
    const count = await rows.count();
    await page.locator('[data-af-et-add-variable]').click();
    assert.equal(await rows.count(), count + 1);
    await rows.last().locator('[name="extra_names[]"]').fill('--af-element-test');
    await rows.last().locator('[data-af-et-remove-variable]').click();
    assert.equal(await rows.count(), count);
    const main = page.locator('#af-et-main');
    const picker = page.locator('.af-et-color-row').first().locator('[data-af-et-color-picker]');
    await main.fill('rgba(1,2,3,.5)');
    assert.equal(await picker.isDisabled(), true);
    await main.fill('#abc');
    assert.equal(await picker.isDisabled(), false);
    assert.equal(await picker.inputValue(), '#aabbcc');
    await picker.evaluate(e => { e.value = '#556677'; e.dispatchEvent(new Event('input', { bubbles: true })); });
    assert.equal(await main.inputValue(), '#556677');
    assert.equal(await page.locator('textarea[name="custom_css"]').inputValue(), '.hero, :scope + [data-element="water"] .hero { color: rgb(10, 20, 200); } :scope { outline: 2px solid rgb(1, 2, 3); }');
    // Actual engine check: selectors, root styling, surface isolation, sibling
    // selectors and nested differently bound contexts cannot escape the scope.
    await page.setContent('<style>.hero {color:rgb(0,0,0);background-color:rgb(255,255,255)}</style>' +
      '<section data-element="fire" data-element-surface="profile" id="fp"><div class="hero" id="fp-hero">profile</div><div data-element="water"><div class="hero" id="nested-water">water</div></div></section>' +
      '<section data-element="water" data-element-surface="profile" id="water"><div class="hero" id="water-hero">water sibling</div></section>' +
      '<section data-element="fire" data-element-surface="postbit" id="postbit"><div class="hero" id="postbit-hero">postbit</div></section>');
    await page.addStyleTag({ content: fs.readFileSync(path.join(assets, 'element-theme.css'), 'utf8') });
    await page.addStyleTag({ content: fixture.css });
    const styles = await page.evaluate(() => {
      const get = id => { const s = getComputedStyle(document.getElementById(id)); return { color: s.color, background: s.backgroundColor, border: s.borderLeftWidth, outline: s.outlineWidth, outlineStyle: s.outlineStyle, main: s.getPropertyValue('--af-element-main').trim() }; };
      return Object.fromEntries(['fp', 'fp-hero', 'nested-water', 'water-hero', 'postbit', 'postbit-hero'].map(id => [id, get(id)]));
    });
    assert.equal(styles['fp-hero'].color, 'rgb(10, 20, 200)');
    assert.equal(styles['fp-hero'].background, 'rgb(200, 10, 20)');
    assert.equal(styles['postbit-hero'].background, 'rgb(200, 10, 20)');
    assert.equal(styles['postbit-hero'].color, 'rgb(0, 0, 0)');
    for (const id of ['nested-water', 'water-hero']) { assert.equal(styles[id].color, 'rgb(0, 0, 0)'); assert.equal(styles[id].background, 'rgb(255, 255, 255)'); }
    assert.equal(styles.fp.border, '3px');
    assert.equal(styles.fp.outline, '2px');
    assert.equal(styles.postbit.outlineStyle, 'none');
    assert.equal(styles.fp.main, '#123456');
    console.log('ElementTheme browser: tabs, variable rows, color picker and element/surface/root scope passed.');
  } finally { await browser.close(); }
}()).catch(error => { console.error(error); process.exit(1); });

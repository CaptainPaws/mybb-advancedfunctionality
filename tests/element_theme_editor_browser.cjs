'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium, firefox } = require('playwright');
const root = path.resolve(__dirname, '..');
const assets = path.join(root, 'inc/plugins/advancedfunctionality/addons/advancedelementtheme/assets');

(async function () {
  const fixture = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'element_theme_editor_regression.php'), '--browser-fixture'], { encoding: 'utf8' }));
  const isFirefox = process.env.AF_TEST_BROWSER === 'firefox';
  const browser = await (isFirefox ? firefox : chromium).launch({ headless: true, ...(!isFirefox ? { executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', args: ['--no-sandbox'] } : {}) });
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
    assert.equal(await picker.isDisabled(), false);
    assert.equal(await picker.inputValue(), '#010203');
    await picker.evaluate(e => { e.value = '#556677'; e.dispatchEvent(new Event('input', { bubbles: true })); });
    assert.equal(await main.inputValue(), 'rgba(85, 102, 119, 0.5)');
    await main.fill('#abc');
    assert.equal(await picker.isDisabled(), false);
    assert.equal(await picker.inputValue(), '#aabbcc');
    await picker.evaluate(e => { e.value = '#556677'; e.dispatchEvent(new Event('input', { bubbles: true })); });
    assert.equal(await main.inputValue(), '#556677');
    for (const token of ['main', 'accent', 'soft', 'border', 'contrast']) {
      const row = page.locator(`[data-af-et-token="--af-element-${token}"]`);
      const text = row.locator('[data-af-et-color-text]'), color = row.locator('[data-af-et-color-picker]');
      assert.equal(await color.isEnabled(), true, token + ' disabled');
      await text.fill(token === 'soft' ? 'rgba(10,20,30,.123456)' : token === 'border' ? 'hsla(120,100%,50%,.25)' : '#abcdef');
      const source = await text.inputValue();
      await color.click(); await page.keyboard.press('Escape');
      assert.equal(await text.inputValue(), source, 'Opening picker changed CSS');
      await color.evaluate(e => { e.value = '#112233'; e.dispatchEvent(new Event('input', { bubbles: true })); });
      assert.equal(await text.inputValue(), token === 'soft' ? 'rgba(17, 34, 51, 0.123456)' : token === 'border' ? 'rgba(17, 34, 51, 0.25)' : '#112233');
    }
    const soft = page.locator('[data-af-et-token="--af-element-soft"]');
    await main.fill('#ff0000');
    const expression = 'color-mix(in srgb, var(--af-element-main) 20%, transparent)';
    await soft.locator('[data-af-et-color-text]').fill(expression);
    assert.equal(await soft.locator('[data-af-et-color-text]').inputValue(), expression);
    assert.equal(await soft.locator('[data-af-et-color-picker]').inputValue(), '#ff0000');
    assert.ok(Math.abs(Number(await soft.locator('[data-af-et-color-alpha]').inputValue()) - .2) < .001);
    await soft.locator('[data-af-et-color-picker]').click(); await page.keyboard.press('Escape');
    assert.equal(await soft.locator('[data-af-et-color-text]').inputValue(), expression);
    assert.match(await soft.locator('[data-af-et-color-note]').innerText(), /заменит/);
    await soft.locator('[data-af-et-color-picker]').evaluate(e => { e.value = '#445566'; e.dispatchEvent(new Event('input', { bubbles: true })); });
    assert.match(await soft.locator('[data-af-et-color-text]').inputValue(), /^rgba\(68, 85, 102, 0.2/);
    await soft.locator('[data-af-et-color-alpha]').fill('0.37');
    assert.equal(await soft.locator('[data-af-et-color-text]').inputValue(), 'rgba(68, 85, 102, 0.37)');
    await soft.locator('[data-af-et-color-text]').fill('var(--af-element-main)');
    assert.equal(await soft.locator('[data-af-et-color-picker]').inputValue(), '#ff0000');
    assert.equal(await soft.locator('[data-af-et-color-text]').inputValue(), 'var(--af-element-main)');
    await soft.locator('[data-af-et-color-text]').fill('not-a-color');
    assert.equal(await soft.locator('[data-af-et-color-picker]').isEnabled(), true);
    assert.equal(await soft.locator('[data-af-et-color-text]').inputValue(), 'not-a-color');
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
    console.log(`ElementTheme ${isFirefox ? 'Firefox' : 'Chromium'} browser: tabs, variable rows, five pickers, alpha, expressions and element/surface/root scope passed.`);
  } finally { await browser.close(); }
}()).catch(error => { console.error(error); process.exit(1); });

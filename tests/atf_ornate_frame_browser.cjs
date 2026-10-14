'use strict';
// Real template roots and production stylesheets; provider data is inert fixture HTML.
// Run: node tests/atf_ornate_frame_browser.cjs (Playwright + Chromium).
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { PNG } = require('pngjs');
const root = path.resolve(__dirname, '..');
const addon = path.join(root, 'inc/plugins/advancedfunctionality/addons/adaptivethemeframework');
const read = file => fs.readFileSync(file, 'utf8');
const core = read(path.join(addon, 'assets/adaptivethemeframework.css'));
const baseline = process.env.ATF_BASELINE_CSS ? read(process.env.ATF_BASELINE_CSS) : null;
const surface = name => read(path.join(addon, 'assets/surfaces', name + '.css'));
const kb = read(path.join(root, 'inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase.css'));
const ac = read(path.join(root, 'inc/plugins/advancedfunctionality/addons/advancedcharacters/assets/advancedcharacters.css'));
const strip = html => html.replace(/\{\$[^}]*\}/g, '').replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
const template = name => strip(read(path.join(addon, 'templates', name + '.html')));
const content = '<h2>Содержимое страницы</h2><p>' + 'ОченьДлинноеНепрерывноеСодержимое'.repeat(50) + '</p><button id="control">Управление</button>';
const fixtures = fs.readdirSync(path.join(addon, 'templates')).filter(name => read(path.join(addon, 'templates', name)).includes('atf-page-shell')).map(name => {
  const html = template(name.replace(/\.html$/, ''));
  const classes = html.match(/<body\b[^>]*class="([^"]*)"/)?.[1] || 'atf-active';
  const main = html.match(/<main\b[\s\S]*?<\/main>/)?.[0];
  assert.ok(main, 'Missing actual page root: ' + name);
  const routeSurface = name.startsWith('usercp') ? 'usercp' : name.startsWith('private') ? 'private' : name.startsWith('modcp') ? 'modcp' : name.startsWith('moderation') ? 'moderation' : name.startsWith('memberlist') ? 'memberlist' : name === 'member_profile.html' ? 'profile' : name === 'showthread.html' ? 'showthread' : ['index.html', 'forumdisplay.html'].includes(name) ? 'forum' : 'compose';
  return { name, classes, main, css: surface(routeSurface) + (['usercp', 'modcp', 'private', 'moderation', 'profile', 'memberlist', 'showthread'].includes(routeSurface) ? surface('compose') : '') };
});
const kbTemplate = strip(read(path.join(root, 'inc/plugins/advancedfunctionality/addons/knowledgebase/templates/knowledgebase_page.html')));
assert.ok(kbTemplate.includes('class="af-kb-page"'));
for (const name of ['kb-catalog', 'kb-category', 'kb-article', 'characters-catalog', 'characters-public']) fixtures.push({ name, classes: 'atf-active', main: '<div class="af-kb-page">' + content + '</div>', css: kb + surface('knowledgebase') + (name.startsWith('characters') ? ac : '') });
for (const name of ['wanted-catalog', 'wanted-detail']) fixtures.push({ name, classes: 'atf-active', main: '<div class="pun atf-layout-shell"><main class="af-wanted' + (name === 'wanted-detail' ? ' af-wanted--detail-page' : '') + '">' + (name === 'wanted-detail' ? '<article class="af-wanted-detail af-wanted-detail--dossier">' + content + '</article>' : '<div class="af-wanted-catalog">' + content + '</div>') + '</main></div>', css: read(path.join(root, 'inc/plugins/advancedfunctionality/addons/advancedwanted/assets/advancedwanted.css')) + read(path.join(root, 'inc/plugins/advancedfunctionality/addons/advancedwanted/assets/advancedwanted_decoration.css')) });
fixtures.push({ name: 'explicit-component', classes: 'atf-active', main: '<main class="atf-ornate-frame" style="margin:24px;padding:20px">' + content + '</main>', css: '' });
const setup = 'html,body{margin:0;background:#030609}body{padding:20px 0}.pun{background:#241d35;border:2px solid #765;border-radius:20px;box-shadow:0 0 10px #765}p{margin:8px 0}';
const document = (f, css = core) => '<!doctype html><html><head><style>' + setup + css + f.css + '</style></head><body class="' + f.classes + ' af-rwd-enabled">' + f.main + '</body></html>';
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || '/usr/bin/chromium', args: ['--no-sandbox'] });
  try {
    const page = await browser.newPage();
    const results = [];
    for (const width of [1440, 768, 375, 320]) {
      await page.setViewportSize({ width, height: 900 });
      for (const f of fixtures) {
        await page.setContent(document(f));
        const frame = page.locator('.atf-page-shell, .af-kb-page, .atf-ornate-frame, .atf-layout-shell > .af-wanted').first();
        await frame.evaluate((el, html) => { el.insertAdjacentHTML('beforeend', '<section id="long-content">' + html + '</section>'); }, content);
        if (f.name === 'wanted-detail') {
          assert.equal(await page.locator('.af-wanted-detail--dossier').evaluate(el => getComputedStyle(el, '::before').content), 'none', 'Dossier has a second frame');
          assert.equal(await page.locator('.af-wanted-detail--dossier').evaluate(el => getComputedStyle(el).backgroundColor), 'rgba(0, 0, 0, 0)');
        }
        const state = await frame.evaluate(el => {
          const host = getComputedStyle(el), outer = getComputedStyle(el, '::before'), inner = getComputedStyle(el, '::after');
          return { cut: host.getPropertyValue('--atf-frame-cut').trim(), radius: host.borderRadius, background: host.backgroundColor, shadow: host.boxShadow, border: host.borderTopWidth, overflow: host.overflow, clip: host.clipPath, outerClip: outer.clipPath, innerClip: inner.clipPath, innerBackground: inner.backgroundImage, ornamentOrigin: inner.backgroundOrigin, events: [outer.pointerEvents, inner.pointerEvents], z: [outer.zIndex, inner.zIndex], innerShadow: inner.boxShadow, padding: host.paddingLeft, width: el.getBoundingClientRect().width, scroll: document.documentElement.scrollWidth > innerWidth };
        });
        assert.equal(state.cut, '6px', f.name);
        assert.equal(state.radius, '0px', f.name);
        assert.equal(state.background, 'rgba(0, 0, 0, 0)', f.name);
        assert.equal(state.shadow, 'none', f.name);
        assert.equal(state.border, '0px', f.name);
        assert.equal(state.clip, 'none', f.name);
        assert.equal(state.overflow, 'visible', f.name);
        assert.equal(state.scroll, false, f.name + ': horizontal overflow at ' + width);
        assert.ok(state.outerClip.startsWith('polygon(6px 0px, calc(100% - 6px) 0px, 100% 6px'), f.name);
        assert.ok(state.innerClip.startsWith('polygon(5.414px 0px'), f.name);
        assert.equal((state.innerBackground.match(/linear-gradient/g) || []).length, 9, f.name);
        assert.equal((state.ornamentOrigin.match(/content-box/g) || []).length, 8, f.name);
        assert.equal((state.innerShadow.match(/inset/g) || []).length, 5, f.name);
        assert.deepEqual(state.events, ['none', 'none'], f.name);
        assert.deepEqual(state.z, ['-2', '-1'], f.name);
        assert.ok(f.name === 'wanted-detail' || parseFloat(state.padding) >= 11, f.name + ': decoration overlaps content');
        results.push({ name: f.name, width });
      }
    }
    const f = fixtures.find(f => f.name === 'showthread.html');
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.setContent(document(f));
    const frame = page.locator('.atf-page-shell');
    await frame.evaluate(el => {
      el.insertAdjacentHTML('beforeend', '<button id="edge-button" style="position:absolute;left:0;top:0">Edge</button><div class="af-kb-page" id="nested"><p>Nested addon</p></div><div id="popover" style="position:absolute;left:20px;top:-18px;z-index:10;width:120px;height:20px;background:#fff">Popover</div><button id="fixed-modal" style="position:fixed;left:10px;top:10px;z-index:100">Modal</button>');
      document.querySelector('#edge-button').onclick = () => window.edgeClicked = true;
    });
    await page.locator('#edge-button').click();
    assert.equal(await page.evaluate(() => window.edgeClicked), true);
    assert.equal(await page.locator('#nested').evaluate(el => getComputedStyle(el, '::before').content), 'none', 'Nested page has another frame');
    assert.equal(await page.locator('#popover').evaluate(el => { const r = el.getBoundingClientRect(); return document.elementFromPoint(r.x + 80, r.y + 10) === el; }), true, 'Popover clipped');
    await page.evaluate(() => scrollTo(0, 200));
    assert.equal(await page.locator('#fixed-modal').evaluate(el => el.getBoundingClientRect().top), 10, 'Fixed modal containing block changed');
    // Element presets cannot recolour the frame; public variables still work.
    await frame.evaluate(el => el.style.setProperty('--af-element-accent', '#ff2200'));
    assert.ok((await frame.evaluate(el => getComputedStyle(el, '::before').backgroundImage)).includes('rgb(168, 160, 250)'));
    await frame.evaluate(el => el.style.setProperty('--atf-frame-cut', '8px'));
    assert.ok((await frame.evaluate(el => getComputedStyle(el, '::after').clipPath)).startsWith('polygon(7.414px 0px'));
    // Compare computed postbit/rail/meta-line styles with the pre-change core.
    if (baseline) {
      const post = template('postbit_classic');
      const thread = { ...f, main: '<main class="pun atf-page-shell atf-page atf-thread">' + post + '</main>', css: surface('showthread') + surface('postbit') };
      const snapshot = () => page.evaluate(() => [...document.querySelectorAll('.atf-post, .atf-post *')].map(el => { const s = getComputedStyle(el); return Object.fromEntries(['border', 'border-radius', 'background', 'box-shadow', 'position', 'z-index', 'transform', 'color', 'font', 'clip-path', 'overflow'].map(p => [p, s.getPropertyValue(p)])); }));
      await page.setContent(document(thread, baseline)); const before = await snapshot();
      await page.setContent(document(thread)); assert.deepEqual(await snapshot(), before, 'Postbit presentation changed');
    }
    // Check the painted silhouette too: all four cutouts expose the page,
    // while the first solid pixel on each outer edge remains at 6px.
    const paint = { classes: 'atf-active', main: '<main class="atf-ornate-frame" style="width:280px;height:180px;margin:20px"></main>', css: '' };
    await page.setContent(document(paint));
    const png = PNG.sync.read(await page.locator('main').screenshot());
    const pixel = (x, y) => [...png.data.subarray((y * png.width + x) * 4, (y * png.width + x) * 4 + 3)];
    for (const [x, y] of [[1, 1], [278, 1], [1, 178], [278, 178]]) assert.deepEqual(pixel(x, y), [3, 6, 9], 'Paint behind a cut corner');
    for (const [x, y] of [[7, 0], [272, 0], [7, 179], [272, 179]]) assert.notDeepEqual(pixel(x, y), [3, 6, 9], 'Missing external edge');
    assert.notDeepEqual(pixel(10, 10), pixel(80, 10), 'Missing inner corner ornament');
    // ATF off leaves the addon fallback intact, even if the stylesheet is cached.
    const off = fixtures.find(f => f.name === 'kb-article');
    await page.setContent(document({ ...off, classes: '' }));
    assert.equal(await page.locator('.af-kb-page').evaluate(el => getComputedStyle(el, '::before').content), 'none');
    assert.equal(await page.locator('.af-kb-page').evaluate(el => getComputedStyle(el).padding), '20px 16px');
    const wantedOff = fixtures.find(f => f.name === 'wanted-detail');
    await page.setContent(document({ ...wantedOff, classes: '' }));
    assert.equal(await page.locator('.af-wanted-detail--dossier').evaluate(el => getComputedStyle(el).borderRadius), '9px');
    assert.equal(await page.locator('.af-wanted-detail--dossier').evaluate(el => getComputedStyle(el, '::before').content), '""');
    if (process.env.ATF_FRAME_SCREENSHOT) {
      await page.setContent(document(fixtures.find(f => f.name === 'kb-catalog')));
      await page.locator('.af-kb-page').screenshot({ path: process.env.ATF_FRAME_SCREENSHOT });
    }
    console.log(JSON.stringify({ passed: true, templateRoots: fixtures.length, viewportChecks: results.length, widths: [1440, 768, 375, 320], postbitBaselineCompared: !!baseline, overflowPopoverModalNestedFallback: true }));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });

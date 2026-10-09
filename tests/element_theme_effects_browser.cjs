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
const presets = ['stardust', 'embers', 'mist', 'aura', 'electric', 'frost'];
const results = { environment: 'local synthetic fixtures, not production', scenarios: [] };
async function metrics(page, name, seconds = 2) {
  const started = Date.now();
  const before = await page.evaluate(() => afElementCanvasEngine.diagnostics());
  const samples = [];
  for (let i = 0; i < seconds * 10; i++) {
    await page.waitForTimeout(100);
    samples.push(await page.evaluate(() => afElementCanvasEngine.diagnostics()));
  }
  const after = samples.at(-1), elapsed = (Date.now() - started) / 1000;
  const sorted = after.frameTimes.slice(-Math.min(after.ticks - before.ticks, after.frameTimes.length)).sort((a, b) => a - b);
  const result = { name, instances: after.instances, active: after.active, loops: after.schedulerLoops, quality: after.quality,
    frameMsP50: sorted[Math.floor(sorted.length * .5)], frameMsP95: sorted[Math.floor(sorted.length * .95)],
    textures: after.textures, hosts: after.hosts.map((h, i) => ({ ...h, observedFPS: (h.frames - (before.hosts[i]?.frames || 0)) / elapsed })) };
  results.scenarios.push(result); return result;
}
(async () => {
  const fixture = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'element_theme_effects_regression.php'), '--browser-fixture'], { encoding: 'utf8' }));
  const isFirefox = process.env.AF_TEST_BROWSER === 'firefox';
  const browser = await (isFirefox ? firefox : chromium).launch({ headless: true, ...(!isFirefox ? { executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', args: ['--no-sandbox'] } : {}) });
  try {
    const context = await browser.newContext({ viewport: { width: 1100, height: 900 } });
    const serve = route => {
      const file = path.basename(new URL(route.request().url()).pathname);
      return fs.existsSync(path.join(assets, file)) ? route.fulfill({ body: fs.readFileSync(path.join(assets, file)), contentType: file.endsWith('.js') ? 'application/javascript' : 'text/css' }) : route.abort();
    };
    await context.route('https://forum.test/**', serve);
    const page = await context.newPage(), errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    await page.setContent(fixture.html);
    for (const file of componentCSS) await page.addStyleTag({ content: fs.readFileSync(path.join(addons, file), 'utf8') });
    await page.addStyleTag({ content: 'body { --atf-space-4:16px; --atf-color-page-subtle:#101725; } #sheet-host { min-height:400px; }' });
    await page.waitForFunction(() => window.afElementCanvasEngine?.diagnostics().active > 0);
    assert.equal(await page.locator('.af-element-canvas').count(), 5);
    assert.equal(await page.locator('#disabled-host .af-element-canvas, #neutral-host .af-element-canvas').count(), 0);
    assert.equal(await page.locator('#sheet > [data-af-element-effect], #profile > [data-af-element-effect]').count(), 0);
    assert.equal(await page.evaluate(() => afElementCanvasEngine.diagnostics().schedulerLoops), 1);
    const layer = page.locator('body > [data-af-element-effect]');
    assert.equal(await layer.evaluate(e => getComputedStyle(e).pointerEvents), 'none');
    assert.equal(await layer.evaluate(e => getComputedStyle(e).zIndex), '-1');
    assert.equal(await layer.evaluate(e => getComputedStyle(e, '::before').animationName), 'none');
    assert.deepEqual(await page.evaluate(() => afElementCanvasEngine.diagnostics().hosts.find(h => h.surface === 'profile').palette[1]), 'rgb(55, 196, 255)');
    await page.locator('#profile-button').evaluate(e => e.addEventListener('click', () => e.dataset.clicked = 'yes'));
    await page.locator('#profile-button').click();
    assert.equal(await page.locator('#profile-button').getAttribute('data-clicked'), 'yes');
    // Real owner CSS: Canvas must preserve host geometry and APUI background contracts.
    for (const selector of ['body', '#sheet-host', '#application', '#postbit .atf-post__topbar', '#postbit .atf-post__sidebar-inner']) {
      const result = await page.locator(selector).evaluate(host => {
        const layer = Array.from(host.children).find(e => e.hasAttribute('data-af-element-effect'));
        const box = () => { const r = host.getBoundingClientRect(); return [r.width, r.height, getComputedStyle(host).position, host.querySelector('.atf-post__sidebar-inner') && getComputedStyle(host.querySelector('.atf-post__sidebar-inner')).position]; };
        const before = box(); layer.style.display = 'none'; const after = box(); layer.style.removeProperty('display');
        return { before, after, isolation: getComputedStyle(host).isolation };
      });
      assert.deepEqual(result.before, result.after, selector + ' geometry/sticky changed');
      assert.equal(result.isolation, 'isolate');
    }
    const sidebar = page.locator('#postbit .atf-post__sidebar');
    await sidebar.evaluate(e => {
      e.style.setProperty('--af-apui-postbit-author-bg-image', 'linear-gradient(rgb(31, 50, 71),rgb(31, 50, 71))');
      e.style.setProperty('--af-apui-postbit-author-overlay', 'linear-gradient(rgba(0,0,0,.2),rgba(0,0,0,.2))');
    });
    assert.equal(await sidebar.locator(':scope > [data-af-element-effect]').count(), 0);
    const railStyle = await sidebar.locator('.atf-post__sidebar-inner').evaluate(e => ({ maxHeight: getComputedStyle(e).maxHeight, overflow: getComputedStyle(e).overflowY, z: getComputedStyle(e.querySelector('[data-af-element-effect]')).zIndex, firstMargin: getComputedStyle(e.querySelector(':scope > :not([data-af-element-effect])')).marginTop }));
    assert.deepEqual(railStyle, { maxHeight: 'none', overflow: 'visible', z: '0', firstMargin: '0px' });
    const sidebarBackground = await sidebar.locator('.atf-post__sidebar-inner').evaluate(e => getComputedStyle(e).backgroundImage);
    assert.ok(sidebarBackground.includes('31, 50, 71'));
    // Freeze to test pixels, including above an opaque base and below buttons.
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.waitForFunction(() => afElementCanvasEngine.diagnostics().schedulerLoops === 0);
    await page.evaluate(() => afElementCanvasEngine.refresh());
    await page.waitForTimeout(100);
    const pixels = await layer.locator('canvas').evaluate(c => Array.from(c.getContext('2d').getImageData(0, 0, c.width, c.height).data).some((n, i) => i % 4 === 3 && n > 0));
    assert.ok(pixels, 'Canvas must actually paint');
    const off = await page.screenshot();
    await layer.evaluate(e => e.style.visibility = 'hidden');
    assert.notDeepEqual(await page.screenshot(), off, 'Profile background hid canvas');
    await layer.evaluate(e => e.style.removeProperty('visibility'));
    // Preserve the current explicit moving-rail host above APUI's background.
    // An opaque APUI author image must not hide paint, while foreground boxes
    // remain pixel-identical with the atmospheric layer toggled.
    await sidebar.evaluate(e => {
      e.style.width = '280px'; e.style.height = '440px';
      const inner = e.querySelector('.atf-post__sidebar-inner');
      inner.style.minHeight = '400px'; inner.style.transform = 'translateY(20px)';
      inner.innerHTML = '<button id="sidebar-probe" style="display:block;width:180px;height:60px;background:white;color:black">Character action</button>';
    });
    await sidebar.scrollIntoViewIfNeeded();
    await page.evaluate(() => afElementCanvasEngine.refresh()); await page.waitForTimeout(150);
    async function foregroundSnapshot() {
      const probe = page.locator('#sidebar-probe');
      await probe.scrollIntoViewIfNeeded();
      const box = await probe.boundingBox();
      // Fractional coordinates can add a transparent background row to the
      // locator screenshot. Compare the opaque content, excluding its border.
      return page.screenshot({ clip: { x: Math.ceil(box.x) + 2, y: Math.ceil(box.y) + 2,
        width: Math.floor(box.width) - 4, height: Math.floor(box.height) - 4 } });
    }
    const probeOn = await foregroundSnapshot();
    const railOn = await sidebar.screenshot();
    await sidebar.locator('.atf-post__sidebar-inner > [data-af-element-effect]').evaluate(e => e.style.display = 'none');
    assert.ok((await foregroundSnapshot()).equals(probeOn), 'Canvas painted over foreground');
    assert.notDeepEqual(await sidebar.screenshot(), railOn, 'APUI image/overlay hid the sidebar canvas');
    await sidebar.locator('.atf-post__sidebar-inner > [data-af-element-effect]').evaluate(e => e.style.removeProperty('display'));
    await page.locator('#profile').scrollIntoViewIfNeeded();
    await page.evaluate(() => afElementCanvasEngine.refresh()); await page.waitForTimeout(100);
    const frames = await page.evaluate(() => afElementCanvasEngine.diagnostics().hosts.map(h => h.frames));
    await page.waitForTimeout(300);
    assert.deepEqual(await page.evaluate(() => afElementCanvasEngine.diagnostics().hosts.map(h => h.frames)), frames, 'Reduced motion must not keep drawing');
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await page.locator('#sheet-host').evaluate(e => e.style.display = 'none');
    await page.waitForFunction(() => !document.querySelector('#sheet-host > [data-af-element-effect]').hasAttribute('data-af-effect-running'));
    await page.locator('#sheet-host').evaluate(e => e.style.display = '');
    await page.locator('#sheet-host').scrollIntoViewIfNeeded();
    await page.waitForFunction(() => document.querySelector('#sheet-host > [data-af-element-effect]').hasAttribute('data-af-effect-running'));
    await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, value: true }); document.dispatchEvent(new Event('visibilitychange')); });
    assert.equal(await page.locator('[data-af-effect-running]').count(), 0);
    assert.equal(await page.evaluate(() => afElementCanvasEngine.diagnostics().schedulerLoops), 0);
    await page.evaluate(() => { delete document.hidden; document.dispatchEvent(new Event('visibilitychange')); });
    await page.evaluate(html => {
      document.body.insertAdjacentHTML('beforeend', '<div id="sheet-modal" style="position:fixed;left:100px;top:100px;width:600px;height:240px;overflow:auto;z-index:1000">' + html + '<div style="height:600px"></div></div>');
      document.getElementById('modal-sheet-host').style.minHeight = '600px';
    }, fixture.sheet);
    await page.waitForFunction(() => document.querySelector('#modal-sheet-host .af-element-canvas'));
    await page.evaluate(() => { afElementEffects.refresh(); afElementEffects.refresh(); });
    assert.equal(await page.locator('#modal-sheet-host > [data-af-element-effect] > canvas').count(), 1);
    await page.waitForFunction(() => document.querySelector('#modal-sheet-host canvas').height > 1);
    assert.ok(await page.locator('#modal-sheet-host canvas').evaluate(c => c.getBoundingClientRect().height <= 240), 'Bitmap exceeds modal scrollport');
    await page.locator('#sheet-modal').evaluate(e => e.hidden = true);
    await page.waitForFunction(() => !document.querySelector('#modal-sheet-host [data-af-effect-running]'));
    await page.locator('#sheet-modal').evaluate(e => e.hidden = false);
    await page.waitForFunction(() => document.querySelector('#modal-sheet-host [data-af-effect-running]'));
    assert.equal(await page.locator('#modal-sheet-host canvas').count(), 1);
    await page.locator('#sheet-modal').evaluate(e => e.scrollTop = 700);
    await page.waitForFunction(() => !document.querySelector('#modal-sheet-host [data-af-effect-running]'));
    await page.locator('#sheet-modal').evaluate(e => e.remove());
    await page.waitForFunction(() => afElementCanvasEngine.diagnostics().instances === 5);
    await page.locator('#postbit').evaluate(e => e.dataset.element = '');
    await page.waitForFunction(() => document.querySelectorAll('#postbit canvas').length === 0);
    assert.equal(await sidebar.locator('.atf-post__sidebar-inner').evaluate(e => getComputedStyle(e).backgroundImage), sidebarBackground);
    await page.locator('#postbit').evaluate(e => e.dataset.element = 'fire');
    await page.waitForFunction(() => document.querySelectorAll('#postbit canvas').length === 2);
    await page.locator('#postbit').evaluate(e => e.removeAttribute('data-element'));
    await page.waitForFunction(() => document.querySelectorAll('#postbit canvas').length === 0);
    await page.locator('#postbit').evaluate(e => e.dataset.element = 'fire');
    await page.waitForFunction(() => document.querySelectorAll('#postbit canvas').length === 2);
    // Preserve the upstream approved-provider browser regression.
    const profileDOM = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'element_theme_profile_flow_regression.php'), '--browser-fixture'], { encoding: 'utf8' }));
    const deliveredHead = fixture.html.match(/<head>([\s\S]*?)<\/head>/)[1];
    for (const uid of ['1', '2']) {
      const checked = await context.newPage();
      await checked.setContent(profileDOM[uid].replace('</head>', deliveredHead + '</head>'));
      await checked.addStyleTag({ content: fs.readFileSync(path.join(addons, 'adaptivethemeframework/assets/surfaces/profile.css'), 'utf8') });
      const expected = uid === '1' ? 'fire' : '';
      assert.equal(await checked.locator('body').getAttribute('data-element'), expected);
      assert.equal(await checked.locator('main.atf-profile').getAttribute('data-element'), expected);
      if (uid === '1') {
        await checked.waitForFunction(() => document.querySelector('body .af-element-canvas'));
        assert.equal(await checked.locator('main').evaluate(e => getComputedStyle(e).getPropertyValue('--af-element-accent').trim()), '#37c4ff');
        for (const selector of ['.atf-profile-hero__name', '.atf-profile-nav__item', '.atf-profile__panel h2']) assert.equal(await checked.locator(selector).evaluate(e => getComputedStyle(e).color), 'rgb(55, 196, 255)');
        assert.notEqual(await checked.locator('.atf-profile-hero').evaluate(e => getComputedStyle(e).borderTopColor), 'rgba(0, 0, 0, 0)');
      } else assert.equal(await checked.locator('.af-element-canvas').count(), 0);
      await checked.close();
    }
    // One full profile; one full sheet, isolated for observed FPS/frame budgets.
    await page.evaluate(() => {
      for (const id of ['sheet-host', 'application', 'postbit']) document.getElementById(id).remove();
    });
    await page.waitForFunction(() => afElementCanvasEngine.diagnostics().instances === 1);
    const profile = await metrics(page, 'profile');
    assert.equal(profile.loops, 1); assert.ok(profile.hosts[0].particles >= 40 && profile.hosts[0].particles <= 70);
    await page.evaluate(html => {
      document.body.dataset.element = ''; document.body.insertAdjacentHTML('beforeend', html);
    }, fixture.sheet);
    await page.locator('#modal-sheet-host').scrollIntoViewIfNeeded();
    await page.waitForFunction(() => afElementCanvasEngine.diagnostics().instances === 1 && afElementCanvasEngine.diagnostics().active === 1);
    const sheet = await metrics(page, 'sheet');
    assert.ok(sheet.hosts[0].particles >= 40 && sheet.hosts[0].particles <= 70);
    // Live ACP uses this engine, not a second renderer, for every preset/unsaved color.
    await page.setContent(fixture.editor);
    await page.waitForFunction(() => document.querySelector('[data-af-et-effect-preview] canvas'));
    for (const preset of presets) {
      await page.selectOption('[name="effect_preset"]', preset);
      await page.waitForFunction(p => afElementCanvasEngine.diagnostics().hosts[0]?.preset === p, preset);
      await page.locator('[data-af-et-effect-preview]').scrollIntoViewIfNeeded();
      await page.waitForTimeout(150);
      assert.ok(await page.locator('[data-af-et-effect-preview] canvas').evaluate(c => Array.from(c.getContext('2d').getImageData(0, 0, c.width, c.height).data).some((n, i) => i % 4 === 3 && n > 0)), preset + ' empty renderer');
      if (process.env.AF_EFFECT_SCREENSHOTS) await page.locator('[data-af-et-effect-preview]').screenshot({ path: path.join(process.env.AF_EFFECT_SCREENSHOTS, preset + '.png') });
    }
    await page.fill('[name="effect_color"]', '#ff37b9');
    await page.waitForFunction(() => afElementCanvasEngine.diagnostics().hosts[0].palette[1] === 'rgb(255, 55, 185)');
    await page.selectOption('[data-af-et-effect-preview-surface]', 'postbit');
    await page.waitForFunction(() => afElementCanvasEngine.diagnostics().hosts[0].surface === 'postbit');
    await page.waitForFunction(() => afElementCanvasEngine.diagnostics().hosts[0].particles <= 16);
    await page.uncheck('[name="effect_enabled"]');
    await page.waitForFunction(() => afElementCanvasEngine.diagnostics().instances === 0);
    // Long thread: 30 posts, 60 hosts, six movement presets, arbitrary element keys.
    const config = Object.fromEntries(presets.map((preset, i) => ['custom_' + i, { enabled: true, preset, density: 100, speed: 50, intensity: 65, opacity: 50, color: '', surfaces: ['postbit'] }]));
    const posts = Array.from({ length: 30 }, (_, i) => `<article data-element="custom_${i % 6}" data-element-surface="postbit" style="--af-element-main:hsl(${i * 23} 80% 65%);--af-element-accent:hsl(${i * 23} 80% 80%);--af-element-soft:hsl(${i * 23} 80% 55% / .2);--af-element-border:hsl(${i * 23} 80% 75% / .5)"><header class="atf-post__topbar" data-af-element-effect-host="postbit-topbar">Post ${i}</header><aside class="atf-post__sidebar" data-af-element-effect-host="postbit-sidebar"><div class="atf-post__sidebar-inner">Character</div></aside><section>Message</section></article>`).join('');
    const engineSource = fs.readFileSync(path.join(assets, 'element-canvas-engine.js'), 'utf8');
    await page.goto('about:blank');
    await page.setContent(`<style>${fs.readFileSync(path.join(assets, 'element-effects.css'), 'utf8')} body{margin:0;background:#101725;color:white}article{height:380px;position:relative;display:grid;grid-template-columns:250px 1fr}header{height:64px;grid-column:1/3}aside{height:316px}.atf-post__sidebar-inner{height:100%;position:relative;isolation:isolate}.atf-post__sidebar-inner>:not([data-af-element-effect]){position:relative;z-index:1}header,aside{position:relative;isolation:isolate}</style><script type="application/json" data-af-element-effects-config>${JSON.stringify(config)}</script>${posts}`);
    // Instrument the real browser RAF API: pending loops cannot exceed one.
    await page.evaluate(() => {
      const request = window.requestAnimationFrame.bind(window), cancel = window.cancelAnimationFrame.bind(window), pending = new Set();
      window.rafAudit = { maxPending: 0, callbacks: 0 };
      window.requestAnimationFrame = fn => {
        if (fn !== window.afElementCanvasEngine?.tick) return request(fn);
        let id = request(t => { pending.delete(id); rafAudit.callbacks++; fn(t); });
        pending.add(id); rafAudit.maxPending = Math.max(rafAudit.maxPending, pending.size); return id;
      };
      window.cancelAnimationFrame = id => { pending.delete(id); cancel(id); };
    });
    await page.addScriptTag({ content: engineSource });
    await page.addScriptTag({ content: fs.readFileSync(path.join(assets, 'element-effects.js'), 'utf8') });
    await page.waitForFunction(() => afElementCanvasEngine.diagnostics().instances === 60 && afElementCanvasEngine.diagnostics().active > 0);
    const thread = await metrics(page, '30 postbits / 60 canvases');
    assert.ok(thread.active < 10);
    assert.equal(await page.evaluate(() => rafAudit.maxPending), 1);
    assert.ok(thread.hosts.filter(h => !h.visible).every(h => h.observedFPS === 0), 'Offscreen areas drew frames');
    const scrollSamples = [];
    for (let i = 0; i < 8; i++) {
      await page.evaluate(y => scrollTo(0, y), i * 1300); await page.waitForTimeout(90); scrollSamples.push(await page.evaluate(() => afElementCanvasEngine.diagnostics().frameMs));
    }
    assert.equal(await page.evaluate(() => rafAudit.maxPending), 1);
    results.scroll = { samples: scrollSamples, maxFrameMs: Math.max(...scrollSamples) };
    // Force more than eight visible hosts to verify adaptive tier.
    await page.addStyleTag({ content: 'article{height:100px}header{height:30px}aside{height:70px}' });
    await page.evaluate(() => scrollTo(0, 0));
    await page.waitForFunction(() => afElementCanvasEngine.diagnostics().quality === 2);
    const crowded = await metrics(page, 'crowded viewport');
    assert.ok(crowded.hosts.filter(h => h.visible).every(h => h.particles <= 7));
    // Cache bound under palette churn, removal/reopening, explicit area hidden.
    const live = page.locator('article').first();
    await live.evaluate(e => e.style.setProperty('--af-element-accent', '#ff37b9'));
    await page.waitForFunction(() => afElementCanvasEngine.diagnostics().hosts[0].palette[1] === 'rgb(255, 55, 185)');
    await live.evaluate(e => e.setAttribute('aria-hidden', 'true'));
    await page.waitForFunction(() => !document.querySelector('article').querySelector('[data-af-effect-running]'));
    await live.evaluate(e => e.removeAttribute('aria-hidden'));
    await page.waitForFunction(() => document.querySelector('article').querySelector('[data-af-effect-running]'));
    const sessions = !isFirefox ? await context.newCDPSession(page) : null;
    const heap = async () => { if (!sessions) return null; await sessions.send('HeapProfiler.collectGarbage'); return (await sessions.send('Runtime.getHeapUsage')).usedSize; };
    results.memory = { before: await heap() };
    for (let i = 0; i < 30; i++) {
      await live.evaluate((e, n) => e.style.setProperty('--af-element-accent', `hsl(${n * 11} 80% 70%)`), i);
      await page.waitForTimeout(35);
    }
    assert.ok(await page.evaluate(() => afElementCanvasEngine.diagnostics().textures <= 96));
    await page.evaluate(() => document.querySelectorAll('article').forEach(e => e.remove()));
    await page.waitForFunction(() => afElementCanvasEngine.diagnostics().instances === 0);
    assert.equal(await page.evaluate(() => afElementCanvasEngine.diagnostics().schedulerLoops), 0);
    assert.equal(await page.evaluate(() => afElementCanvasEngine.diagnostics().textures), 0);
    results.memory.cleanupCycles = [];
    const replay = posts.slice(0, posts.indexOf('</article>') + '</article>'.length);
    for (let i = 0; i < 12; i++) {
      await page.evaluate(html => document.body.insertAdjacentHTML('beforeend', html), replay);
      await page.waitForFunction(() => afElementCanvasEngine.diagnostics().instances === 2, null, { polling: 100 });
      await page.evaluate(() => document.querySelector('article').remove());
      await page.waitForFunction(() => afElementCanvasEngine.diagnostics().instances === 0);
      if (i % 3 === 2) results.memory.cleanupCycles.push(await heap());
    }
    assert.equal(await page.locator('canvas.af-element-canvas').count(), 0);
    results.memory.afterCleanup = await heap();
    results.memory.note = 'GC heap observation over a short run; not a production soak test';
    // Missing Canvas and no-JS preserve static palette/content without throwing.
    const noCanvas = await context.newPage();
    await noCanvas.addInitScript(() => { HTMLCanvasElement.prototype.getContext = () => null; });
    await noCanvas.goto('about:blank'); await noCanvas.setContent(fixture.html);
    await noCanvas.waitForTimeout(200);
    assert.equal(await noCanvas.locator('canvas.af-element-canvas').count(), 0);
    assert.equal(await noCanvas.locator('#profile-button').isVisible(), true);
    await noCanvas.close();
    const noJS = await browser.newContext({ javaScriptEnabled: false });
    await noJS.route('https://forum.test/**', serve);
    const fallback = await noJS.newPage(); await fallback.setContent(fixture.html);
    assert.equal(await fallback.locator('body > [data-af-element-effect]').evaluate(e => getComputedStyle(e).display), 'none');
    assert.equal(await fallback.locator('#profile-button').isVisible(), true);
    await noJS.close();
    assert.deepEqual(errors, [], 'Browser JS errors');
    if (process.env.AF_EFFECT_BENCHMARK) fs.writeFileSync(process.env.AF_EFFECT_BENCHMARK, JSON.stringify(results, null, 2));
    console.log(JSON.stringify(results.scenarios.map(({ name, instances, active, loops, quality, frameMsP50, frameMsP95 }) => ({ name, instances, active, loops, quality, frameMsP50, frameMsP95 })), null, 2));
    console.log(`ElementTheme Canvas ${isFirefox ? 'Firefox' : 'Chromium'}: six presets, profile/sheet/application/postbit, ACP, palette, single RAF, offscreen, reduced motion, AJAX cleanup, 60-host benchmark, cache bound and fallbacks passed.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });

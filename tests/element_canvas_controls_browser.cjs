'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium, firefox } = require('playwright');
const assets = path.resolve(__dirname, '../inc/plugins/advancedfunctionality/addons/advancedelementtheme/assets');
const presets = ['stardust', 'embers', 'mist', 'aura', 'electric', 'frost'];
(async () => {
  const isFirefox = process.env.AF_TEST_BROWSER === 'firefox';
  const browser = await (isFirefox ? firefox : chromium).launch({ headless: true, ...(!isFirefox ? { executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', args: ['--no-sandbox'] } : {}) });
  try {
    const context = await browser.newContext({ viewport: { width: 1100, height: 900 } });
    const page = await context.newPage(), errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    await context.route('https://forum.test/**', route => {
      const name = path.basename(new URL(route.request().url()).pathname);
      return route.fulfill({ body: fs.readFileSync(path.join(assets, name)), contentType: name.endsWith('.js') ? 'application/javascript' : 'text/css' });
    });
    await page.setContent(`<style>${fs.readFileSync(path.join(assets, 'element-effects.css'), 'utf8')}
      body{margin:0;background:#0c1525}#viewport{height:600px;width:800px;overflow:auto;margin:40px}
      #host{height:12000px;width:800px;position:relative;isolation:isolate;--af-element-main:#70aaff;--af-element-accent:#a0e0ff;--af-element-soft:rgba(80,160,255,.2);--af-element-border:rgba(180,225,255,.5)}</style>
      <div id="viewport"><div id="host" class="af-aa-context--sheet af-apui-surface-body" data-af-element-effect-host="sheet"><span data-af-element-effect></span></div></div>`);
    await page.addScriptTag({ content: fs.readFileSync(path.join(assets, 'element-canvas-engine.js'), 'utf8') });
    await page.evaluate(() => {
      window.host = document.getElementById('host'); window.layer = host.querySelector('[data-af-element-effect]');
      window.config = { preset: 'stardust', density: 100, speed: 100, intensity: 100, opacity: 100 };
      window.instance = afElementCanvasEngine.register(host, layer, config, 'sheet');
      window.coverage = () => {
        const bins = [0, 0, 0, 0];
        instance.particles.forEach(p => { const y = ((p.y - instance.offsetY / instance.fieldHeight) % 1 + 1) % 1; bins[Math.min(3, Math.floor(y * 4))]++; });
        const c = instance.canvas, data = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
        const pixels = [0, 0, 0, 0];
        for (let y = 0; y < c.height; y++) for (let x = 0; x < c.width; x++) pixels[Math.min(3, Math.floor(y / c.height * 4))] += data[(y * c.width + x) * 4 + 3];
        return { bins, pixels };
      };
    });
    await page.waitForFunction(() => instance.particles.length === 70 && instance.frames > 1);
    const first = await page.evaluate(() => coverage());
    assert.ok(first.bins.every(n => n >= 10) && first.pixels.every(n => n > 0), 'Initial scene is sparse');
    await page.evaluate(() => { window.savedPool = instance.particles.slice(); window.savedTime = instance.time; document.getElementById('viewport').scrollTop = 8200; });
    await page.waitForTimeout(120);
    const bottom = await page.evaluate(() => ({ ...coverage(), same: instance.particles.every((p, i) => p === savedPool[i]), time: instance.time, field: instance.fieldHeight, host: instance.hostHeight }));
    assert.ok(bottom.same && bottom.time >= 0 && bottom.bins.every(n => n >= 8) && bottom.pixels.every(n => n > 0), 'Scroll reset/empty lower region');
    assert.equal(bottom.field, 600); assert.equal(bottom.host, 12000);
    await page.evaluate(() => document.getElementById('viewport').style.height = '450px');
    await page.waitForFunction(() => instance.fieldHeight === 450);
    assert.ok(await page.evaluate(() => instance.particles.every((p, i) => p === savedPool[i])), 'Resize reset pool');
    // Controlled clock calibration invokes the actual browser renderer. It is
    // distinct from the real-time scheduler/benchmark below and in effects_browser.
    const calibration = await page.evaluate(() => {
      afElementCanvasEngine.cancel();
      const p = instance.particles[0], identity = p;
      function configure(overrides) { config = { ...config, ...overrides }; instance.configure(config, 'sheet'); instance.refresh(); instance.quality(0); }
      function displacement(speed) {
        configure({ speed }); p.x = .5; p.y = .5; p.age = 2; p.life = 14;
        instance.last = 100000; instance.draw(100100, false);
        return Math.abs(p.y - .5) * instance.fieldHeight;
      }
      const slow = displacement(20), fast = displacement(100);
      function brightness(intensity) {
        configure({ intensity }); instance.particles.forEach(p => p.age = p.life * .5);
        instance.draw(100100, true);
        const c = instance.canvas, data = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
        let sum = 0; for (let i = 3; i < data.length; i += 4) sum += data[i]; return sum;
      }
      const dim = brightness(20), bright = brightness(100);
      configure({ density: 20 }); instance.draw(100100, true); const sparse = instance.particles.length;
      configure({ density: 100 }); instance.draw(100100, true); const dense = instance.particles.length;
      const same = instance.particles[0] === identity;
      configure({ opacity: 20 }); const opacity20 = getComputedStyle(layer).opacity;
      configure({ opacity: 100 }); const opacity100 = getComputedStyle(layer).opacity;
      afElementCanvasEngine.wake();
      return { slow, fast, dim, bright, sparse, dense, same, opacity20, opacity100 };
    });
    assert.ok(calibration.fast > calibration.slow * 5 && calibration.slow > 0);
    assert.ok(calibration.bright > calibration.dim * 3, 'Intensity did not brighten pixels');
    assert.ok(calibration.dense > calibration.sparse * 2 && calibration.same, 'Density/reset regression');
    assert.equal(calibration.opacity20, '0.2'); assert.equal(calibration.opacity100, '1');
    // Observe recurring sparkles and actual lifetime renewal in real time.
    await page.waitForTimeout(1500);
    const pulses = await page.evaluate(() => instance.sparklePulses);
    const generations = await page.evaluate(() => { window.beforeLifetime = instance.particles.slice(); return instance.particles.length; });
    await page.waitForTimeout(14500);
    const sustained = await page.evaluate(() => ({ pulses: instance.sparklePulses, replacements: instance.particles.filter((p, i) => p !== beforeLifetime[i]).length, ...coverage() }));
    assert.ok(sustained.pulses > pulses + 8 && sustained.replacements === generations, 'Sparkles/lifetimes stopped after first cycle');
    assert.ok(sustained.bins.every(n => n >= 5) && sustained.pixels.every(n => n > 0), 'Renewal emptied a band');
    await page.evaluate(() => { window.heldPool = instance.particles.slice(); document.getElementById('viewport').hidden = true; });
    await page.waitForFunction(() => afElementCanvasEngine.diagnostics().schedulerLoops === 0);
    await page.evaluate(() => document.getElementById('viewport').hidden = false);
    await page.waitForFunction(() => afElementCanvasEngine.diagnostics().active === 1);
    assert.ok(await page.evaluate(() => instance.particles.every((p, i) => p === heldPool[i])));
    await page.evaluate(() => { afElementCanvasEngine.register(host, layer, config, 'sheet'); afElementCanvasEngine.register(host, layer, config, 'sheet'); });
    assert.equal(await page.locator('.af-element-canvas').count(), 1);
    for (const preset of presets) {
      await page.evaluate(preset => { config = { ...config, preset }; afElementCanvasEngine.register(host, layer, config, 'sheet'); }, preset);
      await page.waitForTimeout(100);
      assert.ok((await page.evaluate(() => coverage())).pixels.every(n => n > 0), preset + ' lower band is empty');
      assert.ok(await page.evaluate(() => afElementCanvasEngine.diagnostics().hosts[0].sparkles > 0));
    }
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.waitForFunction(() => afElementCanvasEngine.diagnostics().schedulerLoops === 0);
    const paused = await page.evaluate(() => instance.frames);
    await page.waitForTimeout(150); assert.equal(await page.evaluate(() => instance.frames), paused);
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    // Unsaved preview controls update the same instance, with preserved pool.
    const fixture = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'element_theme_effects_regression.php'), '--browser-fixture'], { encoding: 'utf8' }));
    await page.goto('about:blank'); await page.setContent(fixture.editor);
    await page.locator('[data-af-et-effect-preview]').scrollIntoViewIfNeeded();
    await page.waitForFunction(() => afElementCanvasEngine?.diagnostics().hosts[0]?.particles > 0);
    await page.evaluate(() => { window.previewInstance = Array.from(afElementCanvasEngine.instances.values())[0]; window.previewParticle = previewInstance.particles[0]; });
    async function slider(name, value) { await page.locator(`[name="effect_${name}"]`).evaluate((e, v) => { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); }, String(value)); await page.waitForTimeout(70); }
    await slider('speed', 20); const speed20 = await page.evaluate(() => previewInstance.config.speed);
    await slider('speed', 100); const speed100 = await page.evaluate(() => previewInstance.config.speed);
    assert.ok(speed100 > speed20 * 5);
    await slider('intensity', 20); await slider('intensity', 100);
    await slider('opacity', 20); assert.equal(await page.locator('[data-af-et-effect-preview] [data-af-element-effect]').evaluate(e => getComputedStyle(e).opacity), '0.2');
    await slider('opacity', 100);
    await slider('density', 20); const sparse = await page.evaluate(() => previewInstance.particles.length);
    await slider('density', 100); assert.ok(await page.evaluate(n => previewInstance.particles.length > n * 2, sparse));
    assert.ok(await page.evaluate(() => Array.from(afElementCanvasEngine.instances.values())[0] === previewInstance && previewInstance.particles[0] === previewParticle));
    assert.equal(await page.locator('[data-af-et-effect-preview] canvas').count(), 1);
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ browser: isFirefox ? 'Firefox' : 'Chromium', controlledClockCalibration: calibration, realTime: { pulsesBefore: pulses, pulsesAfter: sustained.pulses, renewedParticles: sustained.replacements }, initialBands: first.bins, lowerBands: bottom.bins }));
    console.log('Canvas controls: long host, scroll/resize continuity, repeated lifetimes/sparkles, speed/density/intensity/opacity, preview and reduced motion passed.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });

'use strict';
(function () {
  if (window.afElementCanvasEngine) return;
  const presets = {
    stardust: { drift: 3, fall: -2, sprite: 'glow', haze: 2, sparkles: .13 },
    embers: { drift: 6, fall: -23, sprite: 'ember', haze: 2, sparkles: .08 },
    mist: { drift: 2, fall: -1, sprite: 'glow', haze: 5, sparkles: .02, particles: .25 },
    aura: { drift: 3, fall: -1, sprite: 'glow', haze: 4, sparkles: .04, particles: .4 },
    electric: { drift: 5, fall: -3, sprite: 'glow', haze: 2, sparkles: .09 },
    frost: { drift: 5, fall: 7, sprite: 'crystal', haze: 2, sparkles: .12 }
  };
  // Internal budgets, deliberately independent of ACP metadata/schema.
  const budgets = { moderate: 4, crowded: 9, cache: 96, maxPixels: 2400000, maxSide: 4096 };
  const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
  const random = (min, max) => min + Math.random() * (max - min);
  const percent = (value, fallback) => clamp(Number.isFinite(Number(value)) ? Number(value) : fallback, 0, 100) / 100;

  class TextureCache {
    constructor() { this.items = new Map(); }
    get(kind, color, size) {
      const key = kind + ':' + color + ':' + size;
      if (this.items.has(key)) {
        const texture = this.items.get(key);
        this.items.delete(key); this.items.set(key, texture);
        return texture;
      }
      let texture, ctx;
      try {
        if ('OffscreenCanvas' in window) { texture = new OffscreenCanvas(size, size); ctx = texture.getContext('2d'); }
      } catch (_) { /* ordinary canvas fallback */ }
      if (!ctx) { texture = document.createElement('canvas'); texture.width = texture.height = size; ctx = texture.getContext('2d'); }
      if (!ctx) return null;
      const c = size / 2;
      const gradient = ctx.createRadialGradient(c, c, 0, c, c, c);
      // Gradient opacity is spatial; the original color's alpha is preserved.
      gradient.addColorStop(0, color); gradient.addColorStop(.12, color);
      gradient.addColorStop(1, 'transparent');
      ctx.fillStyle = gradient; ctx.fillRect(0, 0, size, size);
      if (kind === 'haze') {
        // Cached low-frequency turbulence gives the cloud a soft, irregular
        // density instead of a rotating gradient or a rectangular image field.
        const pixels = ctx.getImageData(0, 0, size, size);
        for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) {
          const a = Math.sin(x * .055 + Math.sin(y * .047) * 2.2);
          const b = Math.sin(y * .091 + Math.cos(x * .083) * 1.6);
          pixels.data[(y * size + x) * 4 + 3] *= .55 + (a + b + 2) * .1125;
        }
        ctx.putImageData(pixels, 0, 0);
      }
      if (kind === 'star' || kind === 'crystal') {
        ctx.strokeStyle = color; ctx.lineWidth = kind === 'star' ? 1.2 : 1;
        ctx.beginPath();
        const axes = kind === 'star' ? 2 : 3;
        for (let i = 0; i < axes; i++) {
          const a = i * Math.PI / axes;
          const radius = c * (i ? .48 : .72);
          ctx.moveTo(c - Math.cos(a) * radius, c - Math.sin(a) * radius);
          ctx.lineTo(c + Math.cos(a) * radius, c + Math.sin(a) * radius);
        }
        ctx.stroke();
      } else if (kind === 'ember') {
        ctx.fillStyle = color; ctx.beginPath(); ctx.ellipse(c, c, size * .045, size * .12, .25, 0, Math.PI * 2); ctx.fill();
      } else if (kind === 'arc') {
        ctx.strokeStyle = color; ctx.lineWidth = 1.2; ctx.beginPath();
        ctx.moveTo(size * .18, c); ctx.lineTo(size * .38, size * .4);
        ctx.lineTo(size * .46, size * .58); ctx.lineTo(size * .66, size * .38); ctx.lineTo(size * .82, c); ctx.stroke();
      }
      this.items.set(key, texture);
      while (this.items.size > budgets.cache) this.items.delete(this.items.keys().next().value);
      return texture;
    }
  }

  class Instance {
    constructor(engine, host, layer) {
      this.engine = engine; this.host = host; this.layer = layer;
      this.canvas = document.createElement('canvas'); this.canvas.className = 'af-element-canvas';
      this.canvas.setAttribute('aria-hidden', 'true'); this.layer.appendChild(this.canvas);
      try { this.ctx = this.canvas.getContext('2d', { alpha: true }); } catch (_) { this.ctx = null; }
      this.visible = false; this.allowed = false; this.dirty = true; this.paletteDirty = true;
      this.particles = []; this.haze = []; this.sprites = null;
      this.time = 0; this.last = null; this.frames = 0; this.drawMs = 0; this.nextFlash = random(3, 10);
      this.flash = null; this.width = 0; this.height = 0;
    }
    configure(config, surface) {
      this.surface = surface; this.light = surface === 'postbit';
      const next = {
        preset: presets[config.preset] ? config.preset : 'stardust',
        density: percent(config.density, 50), speed: .2 + percent(config.speed, 50) * 1.8,
        glow: percent(config.intensity, 50), opacity: percent(config.opacity, 35), color: config.color || ''
      };
      if (JSON.stringify(this.config) !== JSON.stringify(next)) {
        this.config = next; this.particles = []; this.haze = []; this.flash = null; this.paletteDirty = true;
      }
      this.layer.style.setProperty('--af-effect-opacity', String(next.opacity));
      // Override the retired CSS renderer's postbit opacity cap; ACP stays authoritative.
      this.layer.style.opacity = String(next.opacity);
      this.layer.setAttribute('data-af-effect-ready', ''); this.layer.hidden = false;
      this.host.setAttribute('data-af-element-effect-active', '');
      this.dirty = true; this.last = null;
    }
    refresh() {
      this.dirty = false;
      if (!this.host.isConnected || !this.layer.isConnected || !this.canvas.isConnected) { this.engine.unregister(this.host); return; }
      this.allowed = this.ctx !== null && !this.host.closest('[hidden], [aria-hidden="true"], [inert]');
      if (this.allowed && this.layer.checkVisibility) this.allowed = this.layer.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true });
      const rect = this.layer.getBoundingClientRect();
      let left = Math.max(0, rect.left), top = Math.max(0, rect.top);
      let right = Math.min(window.innerWidth, rect.right), bottom = Math.min(window.innerHeight, rect.bottom);
      if (!this.clippers || this.paletteDirty) {
        this.clippers = [];
        for (let node = this.host; node; node = node.parentElement) {
          const style = getComputedStyle(node);
          const x = /^(auto|scroll|hidden|clip)$/.test(style.overflowX);
          const y = /^(auto|scroll|hidden|clip)$/.test(style.overflowY);
          if (x || y) this.clippers.push({ node, x, y });
        }
      }
      // The visible rectangle also respects modal/tab scrolling containers.
      // Cached overflow contracts avoid computed-style reads on animation frames.
      this.clippers.forEach(clip => {
        const box = clip.node.getBoundingClientRect();
        if (clip.x) { left = Math.max(left, box.left + clip.node.clientLeft); right = Math.min(right, box.left + clip.node.clientLeft + clip.node.clientWidth); }
        if (clip.y) { top = Math.max(top, box.top + clip.node.clientTop); bottom = Math.min(bottom, box.top + clip.node.clientTop + clip.node.clientHeight); }
      });
      this.width = Math.max(0, right - left); this.height = Math.max(0, bottom - top);
      this.offsetX = left - rect.left; this.offsetY = top - rect.top;
      this.hostWidth = rect.width; this.hostHeight = rect.height;
      this.allowed = this.allowed && this.width > 0 && this.height > 0;
      if (!this.engine.intersection) this.visible = this.allowed;
      if (this.paletteDirty && this.allowed) {
        const style = getComputedStyle(this.layer);
        const colors = ['main', 'accent', 'soft', 'border'].map(name => style.getPropertyValue('--af-element-' + name).trim());
        // Resolve CSS variables/color-mix to browser colors once per palette update.
        // The canvas is decorative and its color cannot affect host content.
        this.palette = colors.map(color => {
          this.canvas.style.color = color && CSS.supports('color', color) ? color : 'transparent';
          return getComputedStyle(this.canvas).color;
        });
        if (this.config.color && CSS.supports('color', this.config.color)) {
          this.canvas.style.color = this.config.color;
          this.palette[1] = getComputedStyle(this.canvas).color;
        }
        this.canvas.style.removeProperty('color');
        this.paletteDirty = false; this.sprites = null;
      }
      if (!this.allowed) this.last = null;
    }
    quality(level) {
      this.factor = level === 2 ? .4 : level === 1 ? .65 : 1;
      this.fps = (this.light ? 24 : 30) * (level === 2 ? .5 : level === 1 ? .75 : 1);
      const base = this.light ? 8 + this.config.density * 8 : 40 + this.config.density * 30;
      this.count = this.config.density === 0 ? 0 : Math.round(base * this.factor * (presets[this.config.preset].particles || 1));
      const dpr = Math.min(window.devicePixelRatio || 1, this.light ? 1.5 : 2) * (level === 2 ? .7 : level === 1 ? .85 : 1);
      this.dpr = Math.min(dpr, Math.sqrt(budgets.maxPixels / Math.max(1, this.width * this.height)), budgets.maxSide / Math.max(1, this.width, this.height));
    }
    textures() {
      if (this.sprites) return;
      const cache = this.engine.textures, palette = this.palette;
      this.sprites = {
        particle: cache.get(presets[this.config.preset].sprite, palette[1], 32),
        secondary: cache.get('glow', palette[0], 32),
        star: cache.get('star', palette[1], 48),
        haze: cache.get('haze', palette[2], 128),
        ambient: cache.get('haze', palette[0], 128),
        radiance: cache.get('haze', palette[1], 128),
        reflection: cache.get(this.config.preset === 'electric' ? 'arc' : 'glow', palette[3], 64)
      };
    }
    particle(initial) {
      const preset = presets[this.config.preset], life = random(6, 18);
      return { x: random(0, 1), y: initial ? random(0, 1) : (preset.fall > 0 ? -.02 : 1.02),
        vx: random(-preset.drift, preset.drift), vy: preset.fall * random(.5, 1.5),
        size: random(this.light ? 2 : 3, this.config.preset === 'frost' ? 12 : 9),
        age: initial ? random(0, life) : 0, life, phase: random(0, Math.PI * 2), brightness: random(.45, 1),
        sparkle: Math.random() < preset.sparkles, secondary: Math.random() < .3 };
    }
    draw(timestamp, staticFrame) {
      const started = performance.now();
      const delta = staticFrame || this.last === null ? 0 : Math.min(.1, (timestamp - this.last) / 1000);
      this.last = staticFrame ? null : timestamp;
      this.time += delta * this.config.speed;
      const ctx = this.ctx, w = this.width, h = this.height;
      const pixelW = Math.max(1, Math.ceil(w * this.dpr)), pixelH = Math.max(1, Math.ceil(h * this.dpr));
      if (this.canvas.width !== pixelW || this.canvas.height !== pixelH) { this.canvas.width = pixelW; this.canvas.height = pixelH; }
      const style = this.canvas.style;
      for (const [name, value] of Object.entries({ left: this.offsetX, top: this.offsetY, width: w, height: h })) {
        const px = value + 'px'; if (style[name] !== px) style[name] = px;
      }
      ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0); ctx.clearRect(0, 0, w, h);
      ctx.save();
      this.textures();
      while (this.particles.length < this.count) this.particles.push(this.particle(true));
      if (this.particles.length > this.count) this.particles.length = this.count;
      const hazeCount = this.config.density === 0 ? 0 : (this.light ? 1 : Math.ceil(presets[this.config.preset].haze * this.factor));
      while (this.haze.length < hazeCount) this.haze.push({ x: random(.1, .9), y: random(.1, .9), phase: random(0, Math.PI * 2), scale: random(.5, 1) });
      if (this.haze.length > hazeCount) this.haze.length = hazeCount;
      const sprite = (texture, x, y, size, alpha, stretch = 1) => {
        if (!texture || alpha <= 0 || x + size < 0 || y + size < 0 || x - size > w || y - size > h) return;
        ctx.globalAlpha = clamp(alpha * this.config.glow, 0, 1);
        ctx.drawImage(texture, x - size / 2, y - size * stretch / 2, size, size * stretch);
      };
      // Local feathered clouds; all textures end at zero alpha, no rectangular edges.
      ctx.globalCompositeOperation = 'source-over';
      this.haze.forEach((cloud, i) => {
        const phase = cloud.phase + (staticFrame ? 0 : this.time * .09);
        const x = (cloud.x + Math.sin(phase) * .035) * this.hostWidth - this.offsetX;
        const y = (cloud.y + Math.cos(phase * .73) * .025) * this.hostHeight - this.offsetY;
        const size = Math.min(this.hostWidth, this.light ? 240 : 680) * cloud.scale;
        const pulse = staticFrame ? .5 : .5 + Math.sin(phase) * .2;
        sprite(i % 2 ? this.sprites.ambient : this.sprites.haze, x, y, size, pulse * (this.light ? .22 : .6), 1.25);
        const radiance = this.config.preset === 'mist' ? .45 : this.config.preset === 'aura' ? .38 : .2;
        sprite(this.sprites.radiance, x, y, size * .9, pulse * (this.light ? .12 : radiance), 1.25);
      });
      ctx.globalCompositeOperation = 'lighter';
      this.particles.forEach((p, i) => {
        if (!staticFrame) {
          p.age += delta * this.config.speed;
          p.x += p.vx * delta * this.config.speed / Math.max(1, this.hostWidth);
          p.y += p.vy * delta * this.config.speed / Math.max(1, this.hostHeight);
          if (p.age >= p.life || p.y < -.05 || p.y > 1.05 || p.x < -.05 || p.x > 1.05) this.particles[i] = p = this.particle(false);
        }
        const envelope = Math.sin(Math.PI * clamp(p.age / p.life, 0, 1));
        const flicker = staticFrame ? .65 : .7 + Math.sin(this.time * (p.sparkle ? 1.6 : .65) + p.phase) * .3;
        const alpha = envelope * flicker * p.brightness;
        const x = p.x * this.hostWidth - this.offsetX, y = p.y * this.hostHeight - this.offsetY;
        sprite(p.secondary ? this.sprites.secondary : this.sprites.particle, x, y, p.size, alpha);
        if (p.sparkle && !staticFrame && this.factor > .4) {
          const peak = Math.pow(Math.max(0, Math.sin(this.time * .7 + p.phase)), 18);
          sprite(this.sprites.star, x, y, p.size * 2.2, peak * envelope * .65);
        }
      });
      if (!staticFrame && this.config.density > 0 && this.time > this.nextFlash) {
        this.nextFlash = this.time + random(this.light ? 8 : 4, 15);
        this.flash = { x: random(.1, .9), y: random(.1, .9), start: this.time, life: this.config.preset === 'electric' ? .65 : 1.4 };
      }
      if (this.flash && !staticFrame) {
        const age = (this.time - this.flash.start) / this.flash.life;
        if (age >= 1) this.flash = null;
        else {
          const x = this.flash.x * this.hostWidth - this.offsetX, y = this.flash.y * this.hostHeight - this.offsetY;
          const alpha = Math.sin(age * Math.PI) * .55;
          sprite(this.sprites.star, x, y, this.light ? 16 : 28, alpha);
          if (this.config.preset === 'electric') sprite(this.sprites.reflection, x + 8, y + 3, this.light ? 32 : 60, alpha);
        }
      }
      ctx.restore(); ctx.globalAlpha = 1; ctx.globalCompositeOperation = 'source-over';
      this.frames++; this.drawMs = performance.now() - started;
    }
    dispose() {
      this.canvas.remove(); this.particles = []; this.haze = []; this.sprites = null; this.clippers = [];
      this.canvas.width = this.canvas.height = 1;
      this.layer.removeAttribute('data-af-effect-running'); this.layer.removeAttribute('data-af-effect-ready');
      this.layer.hidden = true; this.host.removeAttribute('data-af-element-effect-active');
    }
  }

  class ElementCanvasEngine {
    constructor() {
      this.instances = new Map(); this.textures = new TextureCache(); this.raf = null;
      this.clock = 0; this.frames = 0; this.frameMs = 0; this.timings = new Float32Array(180); this.active = 0; this.level = 0;
      this.motion = matchMedia('(prefers-reduced-motion: reduce)');
      this.tick = this.tick.bind(this);
      this.intersection = 'IntersectionObserver' in window ? new IntersectionObserver(entries => {
        entries.forEach(entry => {
          const instance = Array.from(this.instances.values()).find(item => item.layer === entry.target);
          if (instance) { instance.visible = entry.isIntersecting && entry.intersectionRect.width > 0 && entry.intersectionRect.height > 0; instance.dirty = true; instance.last = null; }
        });
        this.wake();
      }, { threshold: [0, .01] }) : null;
      this.resize = 'ResizeObserver' in window ? new ResizeObserver(entries => {
        entries.forEach(entry => this.instances.forEach(instance => { if (instance.layer === entry.target) instance.dirty = true; })); this.wake();
      }) : null;
      this.changes = new MutationObserver(records => {
        let wake = false;
        this.instances.forEach(instance => {
          if (!instance.host.isConnected || !instance.canvas.isConnected || !instance.layer.isConnected) { this.unregister(instance.host); return; }
          const relevant = records.filter(record => {
            if (record.type === 'attributes') return !record.target.matches('[data-af-element-effect], .af-element-canvas')
              && (record.target.contains(instance.host) || instance.host.contains(record.target));
            if ((record.type === 'childList' || record.type === 'characterData') && instance.host.contains(record.target) && !(record.target instanceof Element && record.target.closest('[data-af-element-effect]'))) return true;
            return record.target.nodeName === 'STYLE' || record.target.parentNode?.nodeName === 'STYLE'
              || Array.from(record.addedNodes || []).some(node => node.nodeType === 1 && node.matches('style, link[rel="stylesheet"]'));
          });
          if (relevant.length) {
            instance.dirty = true;
            if (relevant.some(record => record.target.nodeName === 'STYLE' || record.target.parentNode?.nodeName === 'STYLE' || Array.from(record.addedNodes || []).some(node => node.nodeType === 1 && node.matches('style, link[rel="stylesheet"]')) || (record.type === 'attributes' && record.target.contains(instance.host)))) instance.paletteDirty = true;
            wake = true;
          }
        });
        if (wake) this.wake();
      });
      this.changes.observe(document, { childList: true, characterData: true, subtree: true, attributes: true, attributeFilter: ['class', 'style', 'hidden', 'aria-hidden', 'inert', 'open'] });
      this.onGeometry = () => { this.instances.forEach(instance => { instance.dirty = true; }); this.wake(); };
      this.onVisibility = () => { this.instances.forEach(instance => { instance.last = null; instance.dirty = true; }); this.wake(); };
      document.addEventListener('transitionend', this.onGeometry, true);
      document.addEventListener('transitioncancel', this.onGeometry, true);
      window.addEventListener('scroll', this.onGeometry, { passive: true, capture: true });
      window.addEventListener('resize', this.onGeometry, { passive: true });
      document.addEventListener('visibilitychange', this.onVisibility);
      this.motion.addEventListener('change', this.onVisibility);
      this.onRefresh = () => this.refresh();
      document.addEventListener('af-element-effects-refresh', this.onRefresh);
    }
    register(host, layer, config, surface) {
      let instance = this.instances.get(host);
      if (instance && (instance.layer !== layer || !instance.canvas.isConnected)) { this.unregister(host); instance = null; }
      if (!instance) {
        instance = new Instance(this, host, layer);
        if (!instance.ctx) {
          instance.configure(config, surface); instance.canvas.remove();
          // Static CSS fallback remains useful without a 2D context.
          return null;
        }
        this.instances.set(host, instance); this.intersection?.observe(layer); this.resize?.observe(layer);
      }
      instance.configure(config, surface); this.wake(); return instance;
    }
    unregister(host) {
      const instance = this.instances.get(host); if (!instance) return;
      this.intersection?.unobserve(instance.layer); this.resize?.unobserve(instance.layer);
      instance.dispose(); this.instances.delete(host);
      if (!this.instances.size) { this.cancel(); this.textures.items.clear(); this.active = 0; }
    }
    refresh(host) {
      this.instances.forEach(instance => { if (!host || host === instance.host || host.contains(instance.host)) { instance.dirty = true; instance.paletteDirty = true; } }); this.wake();
    }
    cancel() { if (this.raf !== null) cancelAnimationFrame(this.raf); this.raf = null; }
    wake() {
      if (document.hidden || document.visibilityState === 'hidden') {
        this.cancel(); this.active = 0;
        this.instances.forEach(instance => { instance.layer.removeAttribute('data-af-effect-running'); instance.last = null; }); return;
      }
      if (this.raf === null && this.instances.size) this.raf = requestAnimationFrame(this.tick);
    }
    tick(timestamp) {
      this.raf = null; this.clock = timestamp;
      const started = performance.now(), active = [];
      this.instances.forEach(instance => {
        if (!instance.host.isConnected || !instance.canvas.isConnected) { this.unregister(instance.host); return; }
        const dirty = instance.dirty;
        if (dirty) instance.refresh();
        if (!this.instances.has(instance.host)) return;
        const visible = instance.allowed && instance.visible && !document.hidden && document.visibilityState !== 'hidden';
        const moving = visible && !this.motion.matches && instance.config.opacity > 0 && instance.config.glow > 0 && instance.config.density > 0;
        instance.layer.toggleAttribute('data-af-effect-running', moving);
        if (moving) active.push(instance);
        else {
          instance.last = null;
          if (!visible) {
            if (instance.canvas.width !== 1 || instance.canvas.height !== 1) instance.canvas.width = instance.canvas.height = 1;
            instance.sprites = null;
          }
          if (visible && dirty) { instance.quality(0); instance.draw(timestamp, true); }
        }
      });
      this.active = active.length;
      this.level = active.length >= budgets.crowded ? 2 : active.length >= budgets.moderate ? 1 : 0;
      active.forEach(instance => {
        instance.quality(this.level);
        if (instance.last === null || timestamp - instance.last >= 1000 / instance.fps - .5) instance.draw(timestamp, false);
      });
      this.frameMs = performance.now() - started;
      this.timings[this.frames % this.timings.length] = this.frameMs; this.frames++;
      if (active.length) this.raf = requestAnimationFrame(this.tick);
    }
    diagnostics() {
      return { instances: this.instances.size, active: this.active, schedulerLoops: this.raf === null ? 0 : 1,
        clock: this.clock, ticks: this.frames, frameMs: this.frameMs,
        frameTimes: Array.from({ length: Math.min(this.frames, this.timings.length) }, (_, i) => this.timings[(Math.max(0, this.frames - this.timings.length) + i) % this.timings.length]), quality: this.level, textures: this.textures.items.size,
        hosts: Array.from(this.instances.values(), item => ({ role: item.host.getAttribute('data-af-element-effect-host'),
          surface: item.surface, preset: item.config.preset, palette: item.palette, visible: item.visible && item.allowed,
          frames: item.frames, drawMs: item.drawMs, fps: item.fps || 0, particles: item.particles.length,
          pixels: item.canvas.width * item.canvas.height })) };
    }
    destroy() {
      Array.from(this.instances.keys()).forEach(host => this.unregister(host));
      this.cancel(); this.intersection?.disconnect(); this.resize?.disconnect(); this.changes.disconnect();
      document.removeEventListener('transitionend', this.onGeometry, true);
      document.removeEventListener('transitioncancel', this.onGeometry, true);
      window.removeEventListener('scroll', this.onGeometry, true); window.removeEventListener('resize', this.onGeometry);
      document.removeEventListener('af-element-effects-refresh', this.onRefresh);
      document.removeEventListener('visibilitychange', this.onVisibility); this.motion.removeEventListener('change', this.onVisibility);
    }
  }
  window.afElementCanvasEngine = new ElementCanvasEngine();
}());

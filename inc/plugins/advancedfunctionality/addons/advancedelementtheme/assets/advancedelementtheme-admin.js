'use strict';
(function () {
  const clamp = (n, low, high) => Math.max(low, Math.min(high, n));
  const unit = value => clamp(parseFloat(value) / (String(value).includes('%') ? 100 : 1), 0, 1);
  const hex = rgb => '#' + rgb.map(n => Math.round(clamp(n, 0, 255)).toString(16).padStart(2, '0')).join('');
  function literalAlpha(value) {
    const short = value.match(/^#[\da-f]{3}([\da-f])$/i), long = value.match(/^#[\da-f]{6}([\da-f]{2})$/i);
    if (short || long) return { alpha: parseInt(long ? long[1] : short[1] + short[1], 16) / 255, explicit: true };
    const fn = value.match(/^(?:rgba?|hsla?)\((.*)\)$/i);
    if (fn) {
      const alpha = fn[1].includes('/') ? fn[1].split('/').at(-1).trim() : fn[1].split(',')[3];
      if (alpha !== undefined && Number.isFinite(parseFloat(alpha))) return { alpha: unit(alpha), explicit: true };
    }
    return null;
  }
  function colorResolver(form, initial = {}) {
    const probe = document.createElement('span'); probe.hidden = true; probe.setAttribute('aria-hidden', 'true'); form.appendChild(probe);
    const canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
    const ctx = canvas.getContext('2d');
    let palette = initial;
    function setPalette(next = palette) {
      palette = next; probe.removeAttribute('style');
      Object.entries(palette).forEach(([name, value]) => probe.style.setProperty(name, value));
      form.querySelectorAll('[data-af-et-token]').forEach(row => {
        const text = row.querySelector('[data-af-et-color-text]');
        probe.style.setProperty(row.dataset.afEtToken, text.value.trim() || text.placeholder);
      });
      form.querySelectorAll('[data-af-et-extra-rows] tr').forEach(row => {
        const name = row.querySelector('[name="extra_names[]"]').value.trim(), text = row.querySelector('[name="extra_values[]"]');
        if (/^--af-element-[\w-]+$/.test(name)) probe.style.setProperty(name, text.value.trim() || text.placeholder);
      });
    }
    function resolve(source) {
      probe.style.removeProperty('color');
      if (!source || !CSS.supports('color', source)) return null;
      probe.style.color = source;
      const resolved = getComputedStyle(probe).color;
      const rgb = resolved.match(/^rgba?\((.*)\)$/i);
      let alpha, channels;
      if (rgb) {
        const parts = rgb[1].trim().split(/[\s,\/]+/);
        channels = parts.slice(0, 3).map(v => parseFloat(v) * (v.includes('%') ? 2.55 : 1));
        alpha = parts[3] === undefined ? 1 : unit(parts[3]);
      } else if (ctx) {
        // Standard Canvas pixel conversion supports resolved color-mix()/color()
        // without relying on experimental CSS Typed Color APIs.
        const slash = resolved.match(/\/\s*([\d.]+%?)\s*\)$/);
        ctx.clearRect(0, 0, 1, 1); ctx.fillStyle = '#000000';
        ctx.fillStyle = slash ? resolved.replace(/\/\s*[\d.]+%?\s*\)$/, '/ 1)') : resolved;
        ctx.fillRect(0, 0, 1, 1);
        const pixel = ctx.getImageData(0, 0, 1, 1).data;
        channels = Array.from(pixel).slice(0, 3); alpha = slash ? unit(slash[1]) : pixel[3] / 255;
      } else return null;
      const literal = literalAlpha(source);
      return { hex: hex(channels), alpha: literal ? literal.alpha : alpha, explicit: !!literal || alpha < 1 };
    }
    setPalette(); return { resolve, setPalette };
  }
  function selectedColor(value, state) {
    if (!state.explicit && state.alpha === 1) return value;
    const rgb = [1, 3, 5].map(i => parseInt(value.slice(i, i + 2), 16));
    return `rgba(${rgb.join(', ')}, ${state.alpha})`;
  }
  const editors = new Map();
  document.querySelectorAll('form.af-et-editor:not([data-af-et-effects-editor])').forEach(form => {
    const data = form.querySelector('[data-af-et-palette-data]');
    const resolver = colorResolver(form, data ? JSON.parse(data.textContent) : {});
    function sync() {
      resolver.setPalette();
      form.querySelectorAll('.af-et-color-row').forEach(row => {
        const text = row.querySelector('[data-af-et-color-text]'), picker = row.querySelector('[data-af-et-color-picker]');
        const source = text.value.trim() || text.placeholder;
        const state = resolver.resolve(source) || { hex: '#000000', alpha: literalAlpha(source)?.alpha ?? 1, explicit: !!literalAlpha(source) };
        row.afColor = state; picker.disabled = false; picker.value = state.hex;
        row.querySelector('[data-af-et-color-alpha]').value = String(state.alpha);
        row.querySelector('[data-af-et-color-note]').textContent = /^(?:var|color-mix|color|lab|lch|oklab|oklch)\(/i.test(source)
          ? 'CSS-выражение сохранено. Выбор RGB или alpha заменит его буквальным цветом.'
          : 'Picker выбирает RGB; alpha сохраняется. Исходный CSS можно редактировать в текстовом поле.';
      });
    }
    editors.set(form, sync); sync();
  });
  document.addEventListener('click', function (event) {
    const add = event.target.closest('[data-af-et-add-variable]');
    if (add) {
      const form = add.closest('form'), template = form.querySelector('[data-af-et-variable-template]');
      form.querySelector('[data-af-et-extra-rows]').appendChild(template.content.cloneNode(true)); return;
    }
    const remove = event.target.closest('[data-af-et-remove-variable]');
    if (remove) { const form = remove.closest('form'); remove.closest('tr').remove(); editors.get(form)?.(); }
  });
  document.addEventListener('input', function (event) {
    const target = event.target, form = target.closest('form');
    if (!editors.has(form)) return;
    const row = target.closest('.af-et-color-row');
    if (row) {
      const text = row.querySelector('[data-af-et-color-text]'), picker = row.querySelector('[data-af-et-color-picker]');
      const alpha = row.querySelector('[data-af-et-color-alpha]');
      if (target === picker) text.value = selectedColor(picker.value, row.afColor);
      if (target === alpha) {
        if (!alpha.value || !alpha.checkValidity()) return;
        text.value = selectedColor(picker.value, { alpha: Number(alpha.value), explicit: true });
      }
    }
    editors.get(form)();
  });

  const form = document.querySelector('[data-af-et-effects-editor]');
  if (!form) return;
  const data = JSON.parse(form.querySelector('[data-af-et-effect-preview-data]').textContent);
  const preview = form.querySelector('[data-af-et-effect-preview]');
  const host = preview.querySelector('[data-af-element-effect-host]'), layer = host.querySelector('[data-af-element-effect]');
  const engine = window.afElementCanvasEngine;
  if (!engine) return;
  const resolver = colorResolver(form);
  function field(name) { return form.elements.namedItem('effect_' + name); }
  function update() {
    const surface = form.querySelector('[data-af-et-effect-preview-surface]').value;
    preview.dataset.elementSurface = surface;
    preview.removeAttribute('style');
    Object.entries(data.palettes[surface]).forEach(([name, value]) => preview.style.setProperty(name, value));
    resolver.setPalette(data.palettes[surface]);
    const color = resolver.resolve(field('color').value.trim() || 'var(--af-element-accent)');
    if (color) form.querySelector('[data-af-et-effect-picker]').value = color.hex;
    form.querySelectorAll('.af-et-effect-range').forEach(row => { row.querySelector('output').value = row.querySelector('input').value + '%'; });
    const enabled = field('enabled').checked && Array.from(form.querySelectorAll('[name="effect_surfaces[]"]:checked')).some(input => input.value === surface);
    if (enabled) {
      const config = { preset: field('preset').value, color: field('color').value.trim() };
      for (const name of ['density', 'speed', 'intensity', 'opacity']) config[name] = Number(field(name).value);
      engine.register(host, layer, config, surface);
    } else {
      engine.unregister(host); layer.hidden = true; layer.removeAttribute('data-af-effect-ready'); host.removeAttribute('data-af-element-effect-active');
    }
  }
  const picker = form.querySelector('[data-af-et-effect-picker]');
  picker.addEventListener('input', () => {
    const state = resolver.resolve(field('color').value.trim()) || { alpha: 1, explicit: false };
    field('color').value = selectedColor(picker.value, state);
  });
  form.addEventListener('input', update); form.addEventListener('change', update);
  update();
}());

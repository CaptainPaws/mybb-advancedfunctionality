'use strict';
(function () {
  function hexColor(value) {
    if (/^#[0-9a-f]{6}$/i.test(value)) return value;
    if (/^#[0-9a-f]{3}$/i.test(value)) return '#' + value.slice(1).split('').map(function (c) { return c + c; }).join('');
    return '';
  }
  document.addEventListener('click', function (event) {
    const add = event.target.closest('[data-af-et-add-variable]');
    if (add) {
      const form = add.closest('form');
      const template = form.querySelector('[data-af-et-variable-template]');
      form.querySelector('[data-af-et-extra-rows]').appendChild(template.content.cloneNode(true));
      return;
    }
    const remove = event.target.closest('[data-af-et-remove-variable]');
    if (remove) remove.closest('tr').remove();
  });
  document.addEventListener('input', function (event) {
    const target = event.target;
    const row = target.closest('.af-et-color-row');
    if (!row) return;
    const text = row.querySelector('[data-af-et-color-text]');
    const picker = row.querySelector('[data-af-et-color-picker]');
    if (target === picker) text.value = picker.value;
    if (target === text) {
      const hex = hexColor(text.value.trim() || text.placeholder);
      picker.disabled = !hex;
      if (hex) picker.value = hex;
    }
  });
}());

(function () {
  const form = document.querySelector('[data-af-et-effects-editor]');
  if (!form) return;
  const data = JSON.parse(form.querySelector('[data-af-et-effect-preview-data]').textContent);
  const preview = form.querySelector('[data-af-et-effect-preview]');
  const host = preview.querySelector('[data-af-element-effect-host]');
  const layer = host.querySelector('[data-af-element-effect]');
  const engine = window.afElementCanvasEngine;
  if (!engine) return;
  function field(name) { return form.elements.namedItem('effect_' + name); }
  function update() {
    const surface = form.querySelector('[data-af-et-effect-preview-surface]').value;
    preview.dataset.elementSurface = surface;
    // Clear only this preview's variables, then use its selected surface's palette.
    preview.removeAttribute('style');
    Object.entries(data.palettes[surface]).forEach(([name, value]) => preview.style.setProperty(name, value));
    form.querySelectorAll('.af-et-effect-range').forEach(row => { row.querySelector('output').value = row.querySelector('input').value + '%'; });
    const enabled = field('enabled').checked && Array.from(form.querySelectorAll('[name="effect_surfaces[]"]:checked')).some(input => input.value === surface);
    if (enabled) {
      const config = { preset: field('preset').value, color: field('color').value.trim() };
      for (const name of ['density', 'speed', 'intensity', 'opacity']) config[name] = Number(field(name).value);
      engine.register(host, layer, config, surface);
      engine.refresh(host);
    } else {
      engine.unregister(host); layer.hidden = true; layer.removeAttribute('data-af-effect-ready'); host.removeAttribute('data-af-element-effect-active');
    }
  }
  const picker = form.querySelector('[data-af-et-effect-picker]');
  picker.addEventListener('input', () => { field('color').value = picker.value; });
  form.addEventListener('input', update); form.addEventListener('change', update);
  update();
}());

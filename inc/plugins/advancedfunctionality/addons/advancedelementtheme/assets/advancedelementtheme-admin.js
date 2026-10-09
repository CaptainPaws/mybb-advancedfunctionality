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
  const sheet = form.querySelector('[data-af-et-effect-preview-style]');
  const motion = matchMedia('(prefers-reduced-motion: reduce)');
  let visible = true;
  function field(name) { return form.elements.namedItem('effect_' + name); }
  function play() { layer.toggleAttribute('data-af-effect-running', visible && !document.hidden && !motion.matches && field('enabled').checked); }
  function points(density, light) {
    return data.points.slice(0, Math.ceil(density * (light ? 3 : 12) / 100)).map((p, i) => `radial-gradient(circle at ${p[0]}% ${p[1]}%,var(--af-effect-color) 0 ${i % 3 === 0 ? '1.6px' : '1px'},transparent 3px)`).join(',') || 'none';
  }
  function update() {
    const surface = form.querySelector('[data-af-et-effect-preview-surface]').value;
    preview.dataset.elementSurface = surface;
    sheet.textContent = data.textures[field('preset').value].replaceAll('[data-element-surface="profile"]', `[data-element-surface="${surface}"]`);
    // Clear only this preview's variables, then use its selected surface's palette.
    preview.removeAttribute('style');
    Object.entries(data.palettes[surface]).forEach(([name, value]) => preview.style.setProperty(name, value));
    const color = field('color').value.trim();
    layer.style.setProperty('--af-effect-color', color && CSS.supports('color', color) ? color : 'var(--af-element-accent,var(--af-element-main,transparent))');
    for (const name of ['intensity', 'opacity']) layer.style.setProperty(name === 'intensity' ? '--af-effect-strength' : '--af-effect-opacity', field(name).value / 100);
    layer.style.setProperty('--af-effect-duration', (32 - field('speed').value * .24) + 's');
    layer.style.setProperty('--af-effect-points', points(field('density').value, surface === 'postbit'));
    layer.style.setProperty('--af-effect-light-points', points(field('density').value, true));
    form.querySelectorAll('.af-et-effect-range').forEach(row => { row.querySelector('output').value = row.querySelector('input').value + '%'; });
    const enabled = field('enabled').checked && Array.from(form.querySelectorAll('[name="effect_surfaces[]"]:checked')).some(input => input.value === surface);
    layer.toggleAttribute('data-af-effect-ready', enabled); host.toggleAttribute('data-af-element-effect-active', enabled);
    play();
  }
  const picker = form.querySelector('[data-af-et-effect-picker]');
  picker.addEventListener('input', () => { field('color').value = picker.value; });
  form.addEventListener('input', update); form.addEventListener('change', update);
  if ('IntersectionObserver' in window) new IntersectionObserver(entries => { visible = entries[0].isIntersecting; play(); }).observe(preview);
  document.addEventListener('visibilitychange', play); motion.addEventListener('change', play);
  update();
}());

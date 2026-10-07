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

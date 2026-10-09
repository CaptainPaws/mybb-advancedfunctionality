'use strict';
(function () {
  if (window.afElementPreferenceControlsReady) return;
  window.afElementPreferenceControlsReady = true;
  const keys = ['effects_enabled', 'effects_profile', 'effects_sheet', 'effects_application', 'effects_postbit'];
  const defaults = Object.fromEntries(keys.map(key => [key, true]));
  const seed = document.querySelector('[data-af-element-preferences]');
  let payload;
  try { payload = JSON.parse(seed?.textContent || '{}'); } catch (_) { payload = {}; }
  const uid = Number(payload.uid) || 0, storageKey = 'af-element-effects-guest';
  function normalize(value = {}) { return Object.fromEntries(keys.map(key => [key, value[key] === undefined ? true : value[key] === true || value[key] === 1 || value[key] === '1'])); }
  let saved = normalize(payload.preferences || defaults), busy = false;
  if (!uid) { try { saved = normalize(JSON.parse(localStorage.getItem(storageKey)) || saved); } catch (_) { /* unavailable storage uses defaults */ } }
  if (window.afElementEffectsPreferences) saved = normalize(window.afElementEffectsPreferences);
  function apply(value) {
    window.afElementEffectsPreferences = { ...value };
    if (window.afElementEffects) window.afElementEffects.setPreferences(value);
    else document.dispatchEvent(new CustomEvent('af-element-effects-refresh', { detail: { preferences: value } }));
  }
  function forms() { return Array.from(document.querySelectorAll('[data-af-element-preferences-form]')); }
  function render(value, message = '') {
    forms().forEach(form => {
      keys.forEach(key => { const input = form.querySelector(`input[type="checkbox"][name="${key}"]`); input.checked = value[key]; input.disabled = busy; });
      form.querySelector('button[type="submit"]').disabled = busy;
      form.setAttribute('aria-busy', String(busy));
      form.querySelector('[role="status"]').textContent = message;
    });
  }
  async function save(form) {
    if (busy) return;
    const next = Object.fromEntries(keys.map(key => [key, form.querySelector(`input[type="checkbox"][name="${key}"]`).checked]));
    apply(next); busy = true; render(next, uid ? 'Сохранение…' : '');
    try {
      if (uid) {
        const body = new URLSearchParams({ my_post_key: form.elements.namedItem('my_post_key').value });
        keys.forEach(key => body.set(key, next[key] ? '1' : '0'));
        const response = await fetch(form.action, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body });
        const result = await response.json();
        if (!response.ok || !result.preferences || !keys.every(key => typeof result.preferences[key] === 'boolean')) throw new Error(result.error || 'Не удалось сохранить настройки.');
        saved = normalize(result.preferences);
      } else { localStorage.setItem(storageKey, JSON.stringify(next)); saved = next; }
      busy = false; apply(saved); render(saved, 'Сохранено');
    } catch (error) {
      busy = false; apply(saved); render(saved, (error.message || 'Не удалось сохранить настройки.') + ' Изменения отменены; повторите попытку.');
    }
  }
  apply(saved); render(saved);
  document.addEventListener('change', event => {
    const form = event.target.closest('[data-af-element-preferences-form]');
    if (form && event.target.matches('input[type="checkbox"]')) save(form);
  });
  document.addEventListener('submit', event => {
    const form = event.target.closest('[data-af-element-preferences-form]');
    if (form) { event.preventDefault(); save(form); }
  });
}());

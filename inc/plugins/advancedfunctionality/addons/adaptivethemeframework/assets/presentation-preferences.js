'use strict';
(function () {
  if (window.atfPresentationPreferencesReady) return;
  const seed = document.currentScript?.hasAttribute('data-atf-preferences-uid') ? document.currentScript : document.querySelector('[data-atf-preferences-uid]');
  if (!seed) return;
  window.atfPresentationPreferencesReady = true;
  const guest = Number(seed.dataset.atfPreferencesUid) === 0;
  const storage = {forum_layout:'af-presentation-preferences:v1:forum_layout', postbit_sidebar_hidden:'af-presentation-preferences:v1:postbit_sidebar_hidden'};
  const initial = {forum_layout:seed.dataset.atfPreferencesLayout || 'full', postbit_sidebar_hidden:seed.dataset.atfPreferencesSidebar === 'hidden'};
  if (guest) {
    try {
      const layout = localStorage.getItem(storage.forum_layout), sidebar = localStorage.getItem(storage.postbit_sidebar_hidden);
      if (layout === 'full' || layout === 'grid') initial.forum_layout = layout;
      if (sidebar === '0' || sidebar === '1') initial.postbit_sidebar_hidden = sidebar === '1';
    } catch (_) { /* Defaults remain usable if storage cannot be read. */ }
  }
  document.documentElement.dataset.atfPostbitSidebar = initial.postbit_sidebar_hidden ? 'hidden' : 'visible';
  function initialize() {
    const forms = Array.from(document.querySelectorAll('[data-atf-layout-preferences]'));
    let saved = {...initial}, current = {...initial}, busy = false;
    function render() {
      document.body.classList.toggle('atf-forum-layout--grid', current.forum_layout === 'grid');
      document.body.classList.toggle('atf-forum-layout--full', current.forum_layout === 'full');
      const state = current.postbit_sidebar_hidden ? 'hidden' : 'visible';
      const changed = document.body.dataset.atfPostbitSidebar !== state;
      document.documentElement.dataset.atfPostbitSidebar = state;
      document.body.dataset.atfPostbitSidebar = state;
      forms.forEach(form => {
        form.querySelectorAll('[name="forum_layout"]').forEach(input => { input.checked = input.value === current.forum_layout; });
        const input = form.elements.postbit_sidebar_visible;
        if (input) input.checked = !current.postbit_sidebar_hidden;
        form.querySelectorAll('input[type="radio"], input[type="checkbox"]').forEach(input => { input.disabled = busy; });
        form.setAttribute('aria-busy', String(busy));
      });
      if (changed) document.dispatchEvent(new CustomEvent('af-element-effects-refresh'));
    }
    async function save(form, key, value) {
      if (busy) { render(); return; }
      current[key] = value; busy = !guest; render();
      const status = form.querySelector('[role="status"]');
      status.textContent = guest ? '' : 'Сохранение…';
      try {
        if (guest) {
          localStorage.setItem(storage[key], key === 'forum_layout' ? value : value ? '1' : '0');
          saved[key] = value;
        } else {
          const body = new URLSearchParams({preference_key:key, my_post_key:form.elements.my_post_key.value});
          body.set(key, key === 'forum_layout' ? value : value ? '1' : '0');
          const response = await fetch(form.action, {method:'POST', credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}, body});
          const result = await response.json();
          const valid = key === 'forum_layout' ? ['full','grid'].includes(result[key]) : typeof result[key] === 'boolean';
          if (!response.ok || !valid) throw new Error(result.error || 'Не удалось сохранить настройку.');
          saved[key] = result[key];
        }
        current[key] = saved[key]; busy = false; render(); status.textContent = 'Сохранено';
      } catch (error) {
        current[key] = saved[key]; busy = false; render();
        status.textContent = (guest ? 'Хранилище браузера недоступно.' : error.message || 'Не удалось сохранить настройку.') + ' Изменение отменено; повторите попытку.';
      }
    }
    render();
    forms.forEach(form => {
      form.addEventListener('change', event => {
        if (event.target.name === 'postbit_sidebar_visible') save(form, 'postbit_sidebar_hidden', !event.target.checked);
        else if (event.target.name === 'forum_layout' && ['full','grid'].includes(event.target.value)) save(form, 'forum_layout', event.target.value);
      });
      form.addEventListener('submit', event => event.preventDefault());
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, {once:true});
  else initialize();
}());

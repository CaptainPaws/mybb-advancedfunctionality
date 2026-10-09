'use strict';
(function () {
  if (window.atfPresentationPreferencesReady) return;
  const seed = document.currentScript?.hasAttribute('data-atf-preferences-uid') ? document.currentScript : document.querySelector('[data-atf-preferences-uid]');
  if (!seed) return;
  window.atfPresentationPreferencesReady = true;
  const guest = Number(seed.dataset.atfPreferencesUid) === 0;
  const key = 'af-presentation-preferences:v1:forum_layout';
  const sidebarKey = 'af-presentation-preferences:v1:postbit_sidebar_hidden';
  let sidebar = seed.dataset.atfPreferencesSidebar === 'hidden';
  if (guest) { try { const stored = localStorage.getItem(sidebarKey); if (stored === '0' || stored === '1') sidebar = stored === '1'; } catch (_) { /* Session-only choices remain usable. */ } }
  // The parser-blocking head delivery sets this before the first postbit exists.
  document.documentElement.dataset.atfPostbitSidebar = sidebar ? 'hidden' : 'visible';
  function initialize() {
    const forms = Array.from(document.querySelectorAll('[data-atf-layout-preferences]'));
    let layout = forms[0]?.elements.forum_layout.value || seed.dataset.atfPreferencesLayout || 'full';
    let savedSidebar = sidebar, busy = false;
    if (guest) { try { const stored = localStorage.getItem(key); if (stored === 'grid' || stored === 'full') layout = stored; } catch (_) { /* Session-only choices remain usable. */ } }
    function apply(value) {
      layout = value;
      document.body.classList.toggle('atf-forum-layout--grid', value === 'grid');
      document.body.classList.toggle('atf-forum-layout--full', value === 'full');
      forms.forEach(form => form.querySelectorAll('[name="forum_layout"]').forEach(input => { input.checked = input.value === value; }));
    }
    function applySidebar(value) {
      sidebar = value;
      const state = value ? 'hidden' : 'visible';
      document.documentElement.dataset.atfPostbitSidebar = state;
      document.body.dataset.atfPostbitSidebar = state;
      forms.forEach(form => {
        const input = form.elements.postbit_sidebar_hidden;
        if (input) { input.checked = value; input.disabled = busy; }
        form.querySelector('button[type="submit"]').disabled = busy;
      });
      // Existing public lifecycle contract retires only hidden sidebar hosts.
      document.dispatchEvent(new CustomEvent('af-element-effects-refresh'));
    }
    function persistGuest(form) {
      let message = 'Сохранено в этом браузере';
      try { localStorage.setItem(key, layout); } catch (_) { message = 'Применено до перезагрузки: хранилище браузера недоступно.'; }
      form.querySelector('[role="status"]').textContent = message;
    }
    async function saveSidebar(form, value) {
      if (busy) return;
      applySidebar(value);
      const status = form.querySelector('[role="status"]');
      if (guest) {
        savedSidebar = value;
        try { localStorage.setItem(sidebarKey, value ? '1' : '0'); status.textContent = 'Сохранено в этом браузере'; }
        catch (_) { status.textContent = 'Применено до перезагрузки: хранилище браузера недоступно.'; }
        return;
      }
      busy = true; applySidebar(value); status.textContent = 'Сохранение…';
      try {
        const body = new URLSearchParams({ preference_key:'postbit_sidebar_hidden', postbit_sidebar_hidden:value ? '1' : '0', my_post_key:form.elements.my_post_key.value });
        const response = await fetch(form.action, {method:'POST', credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}, body});
        const result = await response.json();
        if (!response.ok || typeof result.postbit_sidebar_hidden !== 'boolean') throw new Error(result.error || 'Не удалось сохранить настройку.');
        savedSidebar = result.postbit_sidebar_hidden;
        busy = false; applySidebar(savedSidebar); status.textContent = 'Сохранено';
      } catch (error) {
        busy = false; applySidebar(savedSidebar); status.textContent = (error.message || 'Не удалось сохранить настройку.') + ' Изменение отменено; повторите попытку.';
      }
    }
    apply(layout); applySidebar(sidebar);
    forms.forEach(form => {
      form.addEventListener('change', event => {
        if (event.target.name === 'postbit_sidebar_hidden') { saveSidebar(form, event.target.checked); return; }
        if (event.target.name !== 'forum_layout' || !['full', 'grid'].includes(event.target.value)) return;
        apply(event.target.value);
        if (guest) persistGuest(form);
      });
      if (guest) form.addEventListener('submit', event => { event.preventDefault(); persistGuest(form); saveSidebar(form, sidebar); });
      // Members retain MyBB's CSRF-protected forum-layout save flow.
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, {once:true});
  else initialize();
}());

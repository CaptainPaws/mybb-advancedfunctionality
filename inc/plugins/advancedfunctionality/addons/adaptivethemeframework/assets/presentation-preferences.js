'use strict';
(function () {
  if (window.atfPresentationPreferencesReady) return;
  window.atfPresentationPreferencesReady = true;
  const seed = document.querySelector('[data-atf-preferences-uid]');
  if (!seed) return;
  const guest = Number(seed.dataset.atfPreferencesUid) === 0;
  const key = 'af-presentation-preferences:v1:forum_layout';
  const forms = Array.from(document.querySelectorAll('[data-atf-layout-preferences]'));
  let layout = forms[0]?.elements.forum_layout.value || seed.dataset.atfPreferencesLayout || 'full';
  if (guest) { try { const stored = localStorage.getItem(key); if (stored === 'grid' || stored === 'full') layout = stored; } catch (_) { /* Session-only choices remain usable. */ } }
  function apply(value) {
    layout = value;
    // Reuse ATF's existing renderer classes, not a second forum layout implementation.
    document.body.classList.toggle('atf-forum-layout--grid', value === 'grid');
    document.body.classList.toggle('atf-forum-layout--full', value === 'full');
    forms.forEach(form => form.querySelectorAll('[name="forum_layout"]').forEach(input => { input.checked = input.value === value; }));
  }
  function persistGuest(form) {
    let message = 'Сохранено в этом браузере';
    try { localStorage.setItem(key, layout); } catch (_) { message = 'Применено до перезагрузки: хранилище браузера недоступно.'; }
    form.querySelector('[role="status"]').textContent = message;
  }
  apply(layout);
  forms.forEach(form => {
    form.addEventListener('change', event => {
      if (event.target.name !== 'forum_layout' || !['full', 'grid'].includes(event.target.value)) return;
      apply(event.target.value);
      if (guest) persistGuest(form);
    });
    if (guest) form.addEventListener('submit', event => { event.preventDefault(); persistGuest(form); });
    // Members retain MyBB's CSRF-protected server save flow and account ownership.
  });
}());

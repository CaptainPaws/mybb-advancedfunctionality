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

/* Shared native disclosure enhancement, used by posts and standalone profile signatures. */
(() => {
  window.afAtfInitializeSignatures = function () {
    if (window.afAtfSignatureReady) return;
    window.afAtfSignatureReady = true;
    function sync(details) {
      const summary = details.querySelector(':scope > .atf-post__signature-toggle');
      if (!summary) return;
      summary.setAttribute('aria-expanded', String(details.open));
      const content = details.querySelector(':scope > .atf-post__signature-content');
      if (!details.open && content?.contains(document.activeElement)) summary.focus();
    }
    // One delegated listener covers appended/replaced posts without observers.
    document.addEventListener('toggle', event => {
      if (event.target.matches?.('details[data-atf-signature]')) sync(event.target);
    }, true);
    const initialize = () => document.querySelectorAll('details[data-atf-signature]').forEach(sync);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, {once:true});
    else initialize();
  };
  window.afAtfInitializeSignatures();
})();

/* Explicitly called by lazy feature dialogs; no DOM observer or editor runtime. */
window.afAtfDecorateSurface = function (surface) {
  if (!surface || !document.body.classList.contains('atf-active')) return;
  surface.classList.add('atf-ornate');
  if (surface.querySelector(':scope > .atf-gothic-frame')) return;
  var frame = document.createElement('span');
  frame.className = 'atf-gothic-frame'; frame.setAttribute('aria-hidden', 'true');
  ['tl', 'tr', 'bl', 'br'].forEach(function (corner) {
    var node = document.createElement('i');
    node.className = 'atf-gothic-corner atf-gothic-corner--' + corner;
    frame.appendChild(node);
  });
  surface.appendChild(frame);
};
document.addEventListener('toggle', function (event) {
  if (!event.target.matches?.('details[data-atf-quick-settings]')) return;
  event.target.querySelector('summary').setAttribute('aria-expanded', String(event.target.open));
}, true);

/* Enhance only native, actually rendered moderator forms. Forum-jump-only
   panels retain their existing markup and presentation. */
(() => {
  function initializeModeration() {
    if (!document.body.classList.contains('atf-active')) return;
    document.querySelectorAll('.atf-thread__moderation').forEach(panel => {
      const forms = Array.from(panel.children).filter(node => node.tagName === 'FORM');
      if (!forms.some(form => form.id === 'inlinemoderation_options' || form.id === 'moderator_options')) return;
      panel.classList.add('atf-thread__moderation--managed');
      forms.forEach(form => {
        if (!form.matches('#inlinemoderation_options, #moderator_options') && !form.querySelector('select.forumjump')) return;
        form.querySelectorAll('input[type="submit"], button[type="submit"]').forEach(control => {
          control.classList.add('atf-button', 'atf-thread-mod-submit');
        });
        if (form.querySelector('.atf-thread-mod-actions')) return;
        const actions = document.createElement('div');
        actions.className = 'atf-thread-mod-actions';
        form.querySelectorAll('input[type="submit"], button[type="submit"], input[type="button"][onclick*="inlineModeration.clearChecked"]').forEach(control => {
          if (control.matches('input[type="button"]')) {
            control.title = 'Снять выделение сообщений';
            control.setAttribute('aria-label', control.title);
            const reset = document.createElement('span');
            reset.className = 'atf-thread-mod-clear';
            reset.appendChild(control);
            reset.insertAdjacentHTML('beforeend', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4v6h6M4 10a8 8 0 1 1 1 8"/></svg>');
            actions.prepend(reset);
          } else actions.appendChild(control);
        });
        if (actions.children.length) form.appendChild(actions);
        // Native inline moderation separates its inputs with &nbsp;. Without
        // controls these whitespace nodes would become anonymous grid items.
        Array.from(form.childNodes).forEach(node => {
          if (node.nodeType === Node.TEXT_NODE && !node.textContent.trim()) node.remove();
        });
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeModeration, {once:true});
  else initializeModeration();
})();

/* Enhance native MyBB page-jump popups without replacing their form or plugin. */
(() => {
  let active = null;
  const owners = new WeakMap();
  function close(popup, restoreFocus) {
    if (popup.hidePopover && popup.matches(':popover-open')) popup.hidePopover();
    popup.style.display = 'none';
    const owner = owners.get(popup);
    if (owner) { owner.setAttribute('aria-expanded', 'false'); if (restoreFocus) owner.focus(); }
    if (active === popup) active = null;
  }
  function position() {
    if (!active) return;
    const popup = active, owner = owners.get(popup);
    if (!owner?.isConnected) { close(popup, false); return; }
    const rect = owner.getBoundingClientRect(), view = window.visualViewport;
    const left = view?.offsetLeft || 0, top = view?.offsetTop || 0;
    const width = view?.width || document.documentElement.clientWidth, height = view?.height || innerHeight;
    popup.style.position = 'fixed';
    popup.style.left = Math.max(left + 8, Math.min(rect.left, left + width - popup.offsetWidth - 8)) + 'px';
    const y = rect.bottom + popup.offsetHeight + 6 > top + height ? rect.top - popup.offsetHeight - 6 : rect.bottom + 6;
    popup.style.top = Math.max(top + 8, Math.min(y, top + height - popup.offsetHeight - 8)) + 'px';
  }
  document.addEventListener('click', event => {
    const owner = event.target.closest('.go_page');
    if (!owner || !document.body.classList.contains('atf-active')) return;
    const popup = document.getElementById(owner.id + '_popup');
    if (!popup?.classList.contains('drop_go_page')) return;
    // The native jQuery handler has already decided whether to open or close.
    if (popup.style.display === 'none') { close(popup, false); return; }
    if (active && active !== popup) close(active, false);
    if (!owners.has(popup)) {
      owners.set(popup, owner);
      const button = document.createElement('button');
      button.type = 'button'; button.className = 'atf-page-jump-close';
      button.textContent = '×'; button.setAttribute('aria-label', document.querySelector('[data-atf-close-label]')?.dataset.atfCloseLabel || 'Закрыть');
      button.addEventListener('click', () => close(popup, true));
      popup.appendChild(button);
      new MutationObserver(() => {
        if (popup.style.display === 'none' && active === popup) close(popup, false);
      }).observe(popup, {attributes:true, attributeFilter:['style']});
    }
    active = popup;
    owner.setAttribute('aria-expanded', 'true'); owner.setAttribute('aria-controls', popup.id);
    if (popup.showPopover) { popup.setAttribute('popover', 'manual'); if (!popup.matches(':popover-open')) popup.showPopover(); }
    position();
    popup.querySelector('input[name="page"]')?.focus();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && active) { event.preventDefault(); close(active, true); }
  });
  window.addEventListener('resize', position);
  window.addEventListener('scroll', position, true);
  window.visualViewport?.addEventListener('resize', position);
  window.visualViewport?.addEventListener('scroll', position);
})();

'use strict';
(function () {
  if (window.afElementEffects) return;
  const blocks = document.querySelectorAll('[data-af-element-effects-config]');
  const settings = {};
  blocks.forEach(block => { try { Object.assign(settings, JSON.parse(block.textContent)); } catch (_) { /* malformed metadata stays inert */ } });
  const preferenceKeys = ['effects_enabled', 'effects_profile', 'effects_sheet', 'effects_application', 'effects_postbit'];
  function normalizePreferences(value = {}) { return Object.fromEntries(preferenceKeys.map(key => [key, value[key] === undefined ? true : value[key] === true || value[key] === 1 || value[key] === '1'])); }
  let payload;
  try { payload = JSON.parse(document.querySelector('[data-af-element-preferences]')?.textContent || '{}'); } catch (_) { payload = {}; }
  let preferences = normalizePreferences(window.afElementEffectsPreferences || payload.preferences);
  // Authenticated pages always use the server seed, never guest localStorage.
  if (!Number(payload.uid) && !window.afElementEffectsPreferences) {
    try { preferences = normalizePreferences(JSON.parse((localStorage.getItem('af-element-effects-guest:v1') || localStorage.getItem('af-element-effects-guest'))) || preferences); } catch (_) { /* storage is optional */ }
  }
  window.afElementEffectsPreferences = { ...preferences };
  function permitted(surface) { return preferences.effects_enabled && preferences['effects_' + surface] === true; }
  const roots = '[data-element][data-element-surface]';
  const bindings = new WeakMap();
  const engine = window.afElementCanvasEngine;
  if (!engine) return;
  function start(host, layer, config, surface) { engine.register(host, layer, config, surface); }
  function remember(root, hosts) {
    for (const host of bindings.get(root) || []) if (!hosts.includes(host)) stop(host);
    bindings.set(root, hosts);
  }
  function mountApplication(root) {
    const config = settings[root.dataset.element];
    const surface = root.dataset.elementSurface;
    // Full surfaces own the layer on their canonical root. Only postbit uses
    // an inner host; explicit template metadata wins over the legacy class.
    const host = root;
    remember(root, [host]);
    // Retire old hero/header layers without touching nested element surfaces.
    root.querySelectorAll('[data-af-element-effect-host]').forEach(old => {
      if (old === host || old.closest(roots) !== root) return;
      Array.from(old.children).filter(child => child.hasAttribute('data-af-element-effect')).forEach(layer => { engine.unregister(old); layer.remove(); });
      old.removeAttribute('data-af-element-effect-active'); old.removeAttribute('data-af-element-effect-host');
    });
    if (!host || host.closest(roots) !== root) return;
    let layer = Array.from(host.children).find(child => child.hasAttribute('data-af-element-effect'));
    if (!config || !config.enabled || !config.surfaces.includes(surface) || !permitted(surface)) {
      engine.unregister(host); if (layer) { layer.hidden = true; layer.removeAttribute('data-af-effect-ready'); }
      host.removeAttribute('data-af-element-effect-active'); return;
    }
    // Explicit owned template containers are preferred. Old installed templates
    // receive an empty decorative node only, never a restored/replaced template.
    if (!layer) { layer = document.createElement('span'); layer.hidden = true; layer.setAttribute('data-af-element-effect', ''); layer.setAttribute('aria-hidden', 'true'); host.prepend(layer); }
    host.setAttribute('data-af-element-effect-host', '');
    host.setAttribute('data-af-element-effect-active', '');
    layer.setAttribute('data-af-effect-ready', '');
    start(host, layer, config, surface);
  }
  function stop(host, remove = false) {
    Array.from(host.children).filter(child => child.hasAttribute('data-af-element-effect')).forEach(layer => {
      engine.unregister(host);
      layer.hidden = true; layer.removeAttribute('data-af-effect-ready'); layer.removeAttribute('data-af-effect-running');
      if (remove) layer.remove();
    });
    host.removeAttribute('data-af-element-effect-active');
  }
  function mount(root) {
    const surface = root.dataset.elementSurface;
    // Application integration is deliberately unchanged in this task.
    if (surface === 'application') return mountApplication(root);
    if (!root.matches(roots)) { remember(root, []); return; }
    // Explicit body hosts also need to stop when their canonical key is cleared.
    const key = root.dataset.element;
    const config = settings[key];
    let hosts = [];
    if (surface === 'profile') {
      const host = root.closest('[data-af-element-effect-host="profile-page"]')
        || root.closest('body.af-apui-member-profile-page');
      if (host) hosts = [host];
    } else if (surface === 'sheet') {
      const host = root.closest('[data-af-element-effect-host="sheet"]')
        || root.closest('.af-aa-context--sheet.af-apui-surface-body');
      if (host) hosts = [host];
    } else if (surface === 'postbit') {
      hosts = Array.from(root.querySelectorAll('[data-af-element-effect-host="postbit-topbar"], [data-af-element-effect-host="postbit-sidebar"], .atf-post__topbar, .atf-post__sidebar-inner'))
        .map(host => host.matches('.atf-post__sidebar') ? host.querySelector(':scope > .atf-post__sidebar-inner') : host)
        .filter((host, index, all) => host && host.closest(roots) === root && all.indexOf(host) === index);
    } else { remember(root, []); return; }
    // Only the canonical outer identity may own a full-surface effect.
    hosts = hosts.filter(host => surface === 'postbit' || !host.hasAttribute('data-element') || host.dataset.element === key);
    remember(root, hosts);
    // Remove only obsolete decoration belonging to this instance, not nested sheets.
    [root, ...root.querySelectorAll('[data-af-element-effect-host]')].forEach(old => {
      if (hosts.includes(old) || old.closest(roots) !== root) return;
      if (old.hasAttribute('data-af-element-effect-host')) { stop(old, true); old.removeAttribute('data-af-element-effect-host'); }
    });
    hosts.forEach(host => {
      // PHP owns new context attributes. Old installed templates may be bridged
      // only from the declared source within the same surface ancestry, never uid.
      if (surface !== 'postbit') {
        if (host.hasAttribute('data-element') && host.dataset.element !== key) return;
        if (!host.hasAttribute('data-element')) {
          host.dataset.element = key; host.dataset.elementSurface = surface;
          if (surface === 'profile') host.setAttribute('data-af-element-effect-only', '');
        }
      }
      if (surface === 'postbit' && host.closest('.atf-post__sidebar') && document.documentElement.dataset.atfPostbitSidebar === 'hidden') { stop(host); return; }
      if (!config || !config.enabled || !config.surfaces.includes(surface) || !permitted(surface)) { stop(host); return; }
      let layer = Array.from(host.children).find(child => child.hasAttribute('data-af-element-effect'));
      if (!layer) { layer = document.createElement('span'); layer.hidden = true; layer.setAttribute('data-af-element-effect', ''); layer.setAttribute('aria-hidden', 'true'); host.prepend(layer); }
      const role = surface === 'profile' ? 'profile-page' : (surface === 'sheet' ? 'sheet' : (host.matches('.atf-post__sidebar-inner') ? 'postbit-sidebar' : 'postbit-topbar'));
      if (host.getAttribute('data-af-element-effect-host') !== role) host.setAttribute('data-af-element-effect-host', role);
      host.setAttribute('data-af-element-effect-active', ''); layer.setAttribute('data-af-effect-ready', '');
      start(host, layer, config, surface);
    });
  }
  function scan(node) {
    if (!(node instanceof Element)) return;
    if (node.matches(roots)) mount(node);
    node.querySelectorAll(roots).forEach(mount);
    const root = node.closest(roots); if (root) mount(root);
  }
  scan(document.documentElement);
  // Bind only identity attributes already delivered by PHP. No UID/KB inference.
  const changes = new MutationObserver(records => {
    records.forEach(record => {
      if (record.type === 'attributes') { if (!record.target.matches(roots)) remember(record.target, []); scan(record.target); }
      else record.addedNodes.forEach(node => {
        if (node instanceof Element && !node.matches('[data-af-element-effect], .af-element-canvas')) scan(node);
      });
    });
  });
  changes.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-element', 'data-element-surface'] });
  function refresh() { scan(document.documentElement); engine.refresh(); }
  function setPreferences(value) {
    preferences = normalizePreferences(value);
    window.afElementEffectsPreferences = { ...preferences };
    refresh();
  }
  // Stable public contract: settings affect decoration only, never canonical
  // identity, ACP restrictions, CSS palettes or engine internals.
  window.afElementEffects = { refresh, setPreferences, getPreferences: () => ({ ...preferences }) };
  document.addEventListener('af-element-effects-refresh', event => {
    if (event.detail?.preferences) setPreferences(event.detail.preferences);
    else refresh();
  });
}());

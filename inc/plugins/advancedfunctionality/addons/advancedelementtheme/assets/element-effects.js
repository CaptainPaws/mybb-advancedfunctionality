'use strict';
(function () {
  if (window.afElementEffects) return;
  const blocks = document.querySelectorAll('[data-af-element-effects-config]');
  const settings = {};
  blocks.forEach(block => { try { Object.assign(settings, JSON.parse(block.textContent)); } catch (_) { /* malformed metadata stays inert */ } });
  const roots = '[data-element][data-element-surface]';
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const tracked = new Map();
  function play(layer, visible) {
    tracked.set(layer, visible);
    layer.toggleAttribute('data-af-effect-running', visible && !document.hidden && !motion.matches);
  }
  const visibility = 'IntersectionObserver' in window ? new IntersectionObserver(entries => {
    entries.forEach(entry => play(entry.target, entry.isIntersecting));
  }, { threshold: 0 }) : null;
  function mountApplication(root) {
    const config = settings[root.dataset.element];
    const surface = root.dataset.elementSurface;
    // Full surfaces own the layer on their canonical root. Only postbit uses
    // an inner host; explicit template metadata wins over the legacy class.
    const host = surface === 'postbit'
      ? Array.from(root.querySelectorAll('[data-af-element-effect-host]')).find(node => node.closest(roots) === root)
        || root.querySelector('.atf-post__topbar')
      : root;
    // Retire old hero/header layers without touching nested element surfaces.
    root.querySelectorAll('[data-af-element-effect-host]').forEach(old => {
      if (old === host || old.closest(roots) !== root) return;
      Array.from(old.children).filter(child => child.hasAttribute('data-af-element-effect')).forEach(layer => { visibility?.unobserve(layer); tracked.delete(layer); layer.remove(); });
      old.removeAttribute('data-af-element-effect-active'); old.removeAttribute('data-af-element-effect-host');
    });
    if (!host || host.closest(roots) !== root) return;
    let layer = Array.from(host.children).find(child => child.hasAttribute('data-af-element-effect'));
    if (!config || !config.enabled || !config.surfaces.includes(surface)) {
      if (layer) { visibility?.unobserve(layer); tracked.delete(layer); layer.removeAttribute('data-af-effect-ready'); layer.removeAttribute('data-af-effect-running'); }
      host.removeAttribute('data-af-element-effect-active'); return;
    }
    // Explicit owned template containers are preferred. Old installed templates
    // receive an empty decorative node only, never a restored/replaced template.
    if (!layer) { layer = document.createElement('span'); layer.hidden = true; layer.setAttribute('data-af-element-effect', ''); layer.setAttribute('aria-hidden', 'true'); host.prepend(layer); }
    host.setAttribute('data-af-element-effect-host', '');
    host.setAttribute('data-af-element-effect-active', '');
    layer.setAttribute('data-af-effect-ready', '');
    if (!tracked.has(layer)) { play(layer, !visibility); visibility?.observe(layer); }
  }
  function stop(host, remove = false) {
    Array.from(host.children).filter(child => child.hasAttribute('data-af-element-effect')).forEach(layer => {
      visibility?.unobserve(layer); tracked.delete(layer);
      layer.removeAttribute('data-af-effect-ready'); layer.removeAttribute('data-af-effect-running');
      if (remove) layer.remove();
    });
    host.removeAttribute('data-af-element-effect-active');
  }
  function mount(root) {
    const surface = root.dataset.elementSurface;
    // Application integration is deliberately unchanged in this task.
    if (surface === 'application') return mountApplication(root);
    if (root.hasAttribute('data-af-element-effect-only')) return;
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
    } else return;
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
      if (!config || !config.enabled || !config.surfaces.includes(surface)) { stop(host); return; }
      let layer = Array.from(host.children).find(child => child.hasAttribute('data-af-element-effect'));
      if (!layer) { layer = document.createElement('span'); layer.hidden = true; layer.setAttribute('data-af-element-effect', ''); layer.setAttribute('aria-hidden', 'true'); host.prepend(layer); }
      const role = surface === 'profile' ? 'profile-page' : (surface === 'sheet' ? 'sheet' : (host.matches('.atf-post__sidebar-inner') ? 'postbit-sidebar' : 'postbit-topbar'));
      if (host.getAttribute('data-af-element-effect-host') !== role) host.setAttribute('data-af-element-effect-host', role);
      host.setAttribute('data-af-element-effect-active', ''); layer.setAttribute('data-af-effect-ready', '');
      if (!tracked.has(layer)) { play(layer, !visibility); visibility?.observe(layer); }
    });
  }
  function scan(node) {
    if (!(node instanceof Element)) return;
    if (node.matches(roots)) mount(node);
    node.querySelectorAll(roots).forEach(mount);
    const root = node.closest(roots); if (root) mount(root);
  }
  scan(document.documentElement);
  // One observer for all surfaces, no continuous RAF/timers, fetches, KB lookups or per-post loops.
  const changes = new MutationObserver(records => {
    records.forEach(record => { if (record.type === 'attributes') mount(record.target); else record.addedNodes.forEach(scan); });
    for (const layer of tracked.keys()) if (!layer.isConnected) { visibility?.unobserve(layer); tracked.delete(layer); }
  });
  changes.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-element', 'data-element-surface'] });
  function refresh() { tracked.forEach((visible, layer) => play(layer, visible)); }
  document.addEventListener('visibilitychange', refresh); motion.addEventListener('change', refresh);
  window.afElementEffects = { refresh };
}());

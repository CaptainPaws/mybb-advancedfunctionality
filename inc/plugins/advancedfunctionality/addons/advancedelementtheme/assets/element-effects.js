'use strict';
(function () {
  if (window.afElementEffects) return;
  const blocks = document.querySelectorAll('[data-af-element-effects-config]');
  const settings = {};
  blocks.forEach(block => { try { Object.assign(settings, JSON.parse(block.textContent)); } catch (_) { /* malformed metadata stays inert */ } });
  const roots = '[data-element][data-element-surface]';
  const hosts = {
    profile: '.atf-profile-hero, .af-apui-profile-hero',
    sheet: '.af-cs-arpg-top, .af-cs-hero',
    application: '.af-atf-wiki__header',
    postbit: '.atf-post__topbar'
  };
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const tracked = new Map();
  function play(layer, visible) {
    tracked.set(layer, visible);
    layer.toggleAttribute('data-af-effect-running', visible && !document.hidden && !motion.matches);
  }
  const visibility = 'IntersectionObserver' in window ? new IntersectionObserver(entries => {
    entries.forEach(entry => play(entry.target, entry.isIntersecting));
  }, { threshold: 0 }) : null;
  function mount(root) {
    const config = settings[root.dataset.element];
    const surface = root.dataset.elementSurface;
    const host = root.querySelector(hosts[surface] || '[data-af-element-effect-host]');
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
  function scan(node) {
    if (!(node instanceof Element)) return;
    if (node.matches(roots)) mount(node);
    node.querySelectorAll(roots).forEach(mount);
    const root = node.closest(roots); if (root) mount(root);
  }
  scan(document.documentElement);
  // One observer for all surfaces, no RAF/timers, fetches, KB lookups or per-post loops.
  const changes = new MutationObserver(records => {
    records.forEach(record => { if (record.type === 'attributes') mount(record.target); else record.addedNodes.forEach(scan); });
    for (const layer of tracked.keys()) if (!layer.isConnected) { visibility?.unobserve(layer); tracked.delete(layer); }
  });
  changes.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-element', 'data-element-surface'] });
  function refresh() { tracked.forEach((visible, layer) => play(layer, visible)); }
  document.addEventListener('visibilitychange', refresh); motion.addEventListener('change', refresh);
  window.afElementEffects = { refresh };
}());

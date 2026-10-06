/* Published-content capabilities remain separate from editor tools. */
(function (window, document) {
  'use strict';
  if (window.__afAeContentLoader) return;
  window.__afAeContentLoader = true;
  var packs = window.afAeContentCapabilities || [], loaded = Object.create(null);
  function asset(url, type) {
    var key = type + ':' + new URL(url, document.baseURI).href;
    if (loaded[key]) return;
    if (Array.prototype.some.call(document.querySelectorAll(type === 'js' ? 'script[src]' : 'link[href]'), function (el) {
      return (type === 'js' ? el.src : el.href) === new URL(url, document.baseURI).href;
    })) { loaded[key] = true; return; }
    var el = document.createElement(type === 'js' ? 'script' : 'link');
    loaded[key] = true;
    if (type === 'js') el.src = url;
    else { el.rel = 'stylesheet'; el.href = url; }
    el.onerror = function () { delete loaded[key]; el.remove(); };
    el.onload = function () { document.dispatchEvent(new CustomEvent('af:content-ready')); };
    document.head.appendChild(el);
  }
  function scan(root) {
    if (!root || !root.querySelectorAll) return;
    // Scan only rendered content, never an editor's popup or textarea.
    var content = [];
    if (root.matches && root.matches('.post_body, .atf-post__message, .af-ccp-preview-body')) content.push(root);
    if (root.closest) { var owner = root.closest('.post_body, .atf-post__message, .af-ccp-preview-body'); if (owner) content.push(owner); }
    content = content.concat(Array.from(root.querySelectorAll('.post_body, .atf-post__message, .af-ccp-preview-body')));
    packs.forEach(function (pack) {
      if (!content.some(function (node) { return pack.markers.some(function (marker) { return node.outerHTML.indexOf(marker) !== -1; }); })) return;
      (pack.css || []).forEach(function (url) { asset(url, 'css'); });
      (pack.js || []).forEach(function (url) { asset(url, 'js'); });
    });
  }
  function boot() {
    scan(document);
    new MutationObserver(function (records) {
      records.forEach(function (record) { record.addedNodes.forEach(function (node) { if (node.nodeType === 1) scan(node); }); });
    }).observe(document.body, { subtree: true, childList: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})(window, document);

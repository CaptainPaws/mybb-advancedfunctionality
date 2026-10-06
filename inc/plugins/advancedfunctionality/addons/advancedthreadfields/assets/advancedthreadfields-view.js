(function (window, document) {
  'use strict';
  if (window.__afAtfViewRuntime) return;
  window.__afAtfViewRuntime = true;

  function decorate(root) {
    (root || document).querySelectorAll('[data-af-atf-kb-catalog-cta]').forEach(function (opener) {
      var href = String(opener.getAttribute('href') || '').trim();
      if (!href || href === '#' || /^javascript:/i.test(href)) return;
      opener.setAttribute('target', '_blank');
      opener.setAttribute('rel', 'noopener noreferrer');
    });
  }

  decorate(document);
  document.addEventListener('click', function (event) {
    var opener = event.target && event.target.closest
      ? event.target.closest('[data-af-atf-kb-catalog-cta]')
      : null;
    if (opener) decorate(opener.parentNode || document);
  }, true);
})(window, document);

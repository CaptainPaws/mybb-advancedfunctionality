/* Native details owns activation, keyboard handling and the collapsed layout. */
(() => {
  'use strict';
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
  document.querySelectorAll('details[data-atf-signature]').forEach(sync);
})();

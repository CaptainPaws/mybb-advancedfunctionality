// Historical custom-theme entry point. Runtime ownership stays in one file.
(function () {
  'use strict';
  var source = document.currentScript && document.currentScript.src;
  function boot() {
    if (!source || window.AFPostbitSticky || window.AFPostbitStickyLoading
        || !document.querySelector('.atf-post')) return;
    window.AFPostbitStickyLoading = true;
    var script = document.createElement('script');
    script.src = source.replace('adaptivethemeframework.postbit.js', 'adaptivethemeframework.postbit-sticky.js');
    script.onerror = function () { window.AFPostbitStickyLoading = false; };
    document.head.appendChild(script);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
}());

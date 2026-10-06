(function(window, document) {
  "use strict";
  function activateMedia(root) {
    try {
      if (!root) return;

      var imgs = root.querySelectorAll('img[data-src], img[data-srcset]');
      for (var i = 0; i < imgs.length; i++) {
        var img = imgs[i];
        var ds = img.getAttribute('data-src');
        if (ds) {
          img.setAttribute('src', ds);
          img.removeAttribute('data-src');
        }
        var dss = img.getAttribute('data-srcset');
        if (dss) {
          img.setAttribute('srcset', dss);
          img.removeAttribute('data-srcset');
        }
      }

      var ifr = root.querySelectorAll('iframe[data-src]');
      for (var j = 0; j < ifr.length; j++) {
        var fr = ifr[j];
        var s = fr.getAttribute('data-src');
        if (s) {
          fr.setAttribute('src', s);
          fr.removeAttribute('data-src');
        }
      }

      var vids = root.querySelectorAll('video[data-src]');
      for (var k = 0; k < vids.length; k++) {
        var v = vids[k];
        var vs = v.getAttribute('data-src');
        if (vs) {
          v.setAttribute('src', vs);
          v.removeAttribute('data-src');
        }
        try { v.load(); } catch (e) {}
      }

      var srcs = root.querySelectorAll('source[data-src]');
      for (var n = 0; n < srcs.length; n++) {
        var so = srcs[n];
        var ss = so.getAttribute('data-src');
        if (ss) {
          so.setAttribute('src', ss);
          so.removeAttribute('data-src');
        }
      }

      var v2 = root.querySelectorAll('video');
      for (var m = 0; m < v2.length; m++) {
        try { v2[m].load(); } catch (e2) {}
      }
    } catch (e3) {}
  }

  function setOpen(sp, open) {
    if (!sp) return;

    var head = sp.querySelector(':scope > .af-aqr-spoiler-head');
    var body = sp.querySelector(':scope > .af-aqr-spoiler-body');
    var foot = sp.querySelector(':scope > .af-aqr-spoiler-foot');

    sp.setAttribute('data-open', open ? '1' : '0');

    if (head) head.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (body) body.hidden = !open;
    if (foot) foot.hidden = !open;

    if (open && body && !sp.__afSpoilerActivated) {
      sp.__afSpoilerActivated = true;
      activateMedia(body);
    }
  }

  function toggleSpoiler(sp) {
    var isOpen = sp.getAttribute('data-open') === '1';
    setOpen(sp, !isOpen);
  }

  if (window.__afSpoilerViewBound) return;
  window.__afSpoilerViewBound = true;
  // Delegation includes AJAX posts, nested spoilers and preview. The parser
  // emits hidden bodies, so no per-node initializer is required.
  document.addEventListener('click', function (e) {
    var control = e.target.closest('.af-aqr-spoiler-head, .af-aqr-spoiler-collapse');
    if (!control) return;
    var sp = control.closest('blockquote.af-aqr-spoiler');
    if (!sp) return;
    e.preventDefault();
    if (control.classList.contains('af-aqr-spoiler-collapse')) setOpen(sp, false);
    else toggleSpoiler(sp);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var head = e.target.closest('.af-aqr-spoiler-head');
    if (!head) return;
    e.preventDefault(); toggleSpoiler(head.closest('blockquote.af-aqr-spoiler'));
  });
})(window, document);

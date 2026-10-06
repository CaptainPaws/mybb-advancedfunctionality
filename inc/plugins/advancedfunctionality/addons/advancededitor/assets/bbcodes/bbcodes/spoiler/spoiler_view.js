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

    var head = sp.querySelector('.af-aqr-spoiler-head');
    var body = sp.querySelector('.af-aqr-spoiler-body');
    var foot = sp.querySelector('.af-aqr-spoiler-foot');

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

  function bindSpoilers(root) {
    root = root || document;

    var list = root.querySelectorAll('blockquote.af-aqr-spoiler');
    for (var i = 0; i < list.length; i++) {
      var sp = list[i];
      if (sp.__afSpoilerBound) continue;
      sp.__afSpoilerBound = true;

      var head = sp.querySelector('.af-aqr-spoiler-head');
      var collapse = sp.querySelector('.af-aqr-spoiler-collapse');

      if (head) {
        head.addEventListener('click', function (e) {
          e.preventDefault();
          toggleSpoiler(this.closest('blockquote.af-aqr-spoiler'));
        });

        head.addEventListener('keydown', function (e) {
          if (!e) return;
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            toggleSpoiler(this.closest('blockquote.af-aqr-spoiler'));
          }
        });
      }

      if (collapse) {
        collapse.addEventListener('click', function (e) {
          e.preventDefault();
          var sp2 = this.closest('blockquote.af-aqr-spoiler');
          setOpen(sp2, false);
          try {
            var h = sp2 && sp2.querySelector ? sp2.querySelector('.af-aqr-spoiler-head') : null;
            if (h) h.focus();
          } catch (e2) {}
        });
      }

      setOpen(sp, false);
    }
  }

  function boot() { bindSpoilers(document); }
  document.addEventListener("af:preview-updated", function(e) { bindSpoilers(e.detail && e.detail.root || document); });
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot); else boot();

})(window, document);

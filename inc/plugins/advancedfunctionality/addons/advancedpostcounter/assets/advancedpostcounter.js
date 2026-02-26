(function () {
  'use strict';

  function onReady(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  function setActive(tabId) {
    document.querySelectorAll('.af-apc-tab').forEach(function (a) {
      var active = a.getAttribute('data-apc-tab') === tabId;
      a.classList.toggle('is-active', active);
      a.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    document.querySelectorAll('.af-apc-tabpanel').forEach(function (p) {
      var active = p.getAttribute('data-apc-panel') === tabId;
      p.classList.toggle('is-active', active);
    });
  }

  function toInt(v) {
    var n = parseInt(String(v == null ? '' : v), 10);
    return Number.isFinite(n) ? n : 0;
  }

  function parseAjaxSettings(settings) {
    var out = Object.create(null);
    if (!settings) return out;

    var url = String(settings.url || '');
    var qPos = url.indexOf('?');
    if (qPos >= 0 && qPos + 1 < url.length) {
      var usp = new URLSearchParams(url.slice(qPos + 1));
      usp.forEach(function (v, k) {
        if (!(k in out)) out[k] = v;
      });
    }

    var data = settings.data;
    if (typeof data === 'string' && data) {
      new URLSearchParams(data).forEach(function (v, k) {
        if (!(k in out)) out[k] = v;
      });
    } else if (data && typeof data === 'object') {
      Object.keys(data).forEach(function (k) {
        if (!(k in out)) out[k] = data[k];
      });
    }

    return out;
  }

  function extractUidFromPost(postNode) {
    if (!postNode) return 0;

    var fromData = toInt(postNode.getAttribute('data-uid'));
    if (fromData) return fromData;

    var uidCarrier = postNode.querySelector('[data-uid]');
    if (uidCarrier) {
      var nestedUid = toInt(uidCarrier.getAttribute('data-uid'));
      if (nestedUid) return nestedUid;
    }

    var profileLink = postNode.querySelector('a[href*="member.php?action=profile"]');
    if (!profileLink) return 0;

    try {
      var href = String(profileLink.getAttribute('href') || '');
      var absolute = new URL(href, window.location.href);
      var uidFromHref = toInt(absolute.searchParams.get('uid'));
      if (uidFromHref) return uidFromHref;
    } catch (e) {}

    var m = String(profileLink.getAttribute('href') || '').match(/(?:\?|&)uid=(\d+)/i);
    return m ? toInt(m[1]) : 0;
  }

  function upsertApc(slot, snapshot) {
    if (!slot || !snapshot || !snapshot.success) return;

    var apc = slot.querySelector('.af-apc');
    if (!apc && snapshot.html) {
      slot.innerHTML = String(snapshot.html);
      apc = slot.querySelector('.af-apc');
    }

    if (!apc) return;

    var tooltip = String(snapshot.tooltip || '');
    if (tooltip) {
      apc.setAttribute('title', tooltip);
      apc.setAttribute('data-af-title', tooltip);
    }

    var num = apc.querySelector('.af-apc-num');
    if (num) {
      var formatted = (snapshot.total != null) ? Number(snapshot.total).toLocaleString('ru-RU') : '';
      num.textContent = formatted || String(snapshot.total || '0');
    }

    var label = apc.querySelector('.af-apc-label');
    if (label && snapshot.label_html) {
      label.innerHTML = String(snapshot.label_html);
    }
  }

  function updateApcByUid(uid, snapshot) {
    if (!uid || !snapshot || !snapshot.success) return;

    var slots = document.querySelectorAll('[data-af-apc-slot="1"][data-uid="' + uid + '"]');
    if (!slots.length) {
      return;
    }

    slots.forEach(function (slot) {
      upsertApc(slot, snapshot);
    });
  }

  var inFlight = Object.create(null);

  function fetchSnapshot(uid) {
    uid = toInt(uid);
    if (!uid) return;
    if (inFlight[uid]) return;
    inFlight[uid] = 1;

    var url = 'xmlhttp.php?action=af_apc_snapshot&uid=' + encodeURIComponent(String(uid)) + '&_ts=' + Date.now();
    fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (data) {
        inFlight[uid] = 0;
        if (!data || !data.success) return;
        updateApcByUid(uid, data);
      })
      .catch(function () {
        inFlight[uid] = 0;
      });
  }

  function hookQuickReply() {
    if (!window.jQuery || window.__afApcQuickReplyHooked) return;
    window.__afApcQuickReplyHooked = true;

    var $ = window.jQuery;

    $(document).ajaxComplete(function (event, xhr, settings) {
      try {
        if (!settings || !settings.url) return;

        var url = String(settings.url || '').toLowerCase();
        var p = parseAjaxSettings(settings);

        var looksLikeQuickReply =
          url.indexOf('newreply.php') !== -1 ||
          String(p.action || '').toLowerCase() === 'do_newreply' ||
          p.replyto != null ||
          p.tid != null;

        if (!looksLikeQuickReply) return;

        var responseText = (xhr && typeof xhr.responseText === 'string') ? xhr.responseText : '';
        if (!responseText) return;

        var latestPost = document.querySelector('.post:last-of-type, [id^="post_"]:last-of-type');
        if (!latestPost) {
          var posts = document.querySelectorAll('[id^="post_"]');
          latestPost = posts.length ? posts[posts.length - 1] : null;
        }

        var uid = extractUidFromPost(latestPost);
        if (!uid) return;

        fetchSnapshot(uid);
      } catch (e) {}
    });
  }

  onReady(function () {
    var tabs = document.querySelector('.af-apc-tabs');
    if (tabs) {
      tabs.addEventListener('click', function (e) {
        var a = e.target.closest('.af-apc-tab');
        if (!a) return;
        e.preventDefault();

        var tabId = a.getAttribute('data-apc-tab');
        if (!tabId) return;

        setActive(tabId);

        try {
          history.replaceState(null, '', '#' + tabId);
        } catch (err) {
          location.hash = tabId;
        }
      });

      var hash = (location.hash || '').replace('#', '');
      if (hash === 'apc-months' || hash === 'apc-users') {
        setActive(hash);
      } else {
        setActive('apc-users');
      }
    }

    hookQuickReply();
  });
})();

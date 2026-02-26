(function () {
  'use strict';

  console.log('[APC] loaded');

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

    if (snapshot.html) {
      slot.innerHTML = String(snapshot.html);
    }

    var apc = slot.querySelector('.af-apc');

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

  function updateSlot(slot) {
    if (!slot) return;
    var uid = toInt(slot.getAttribute('data-uid'));
    if (!uid) {
      var postNode = slot.closest('[id^="post_"], .post');
      uid = extractUidFromPost(postNode);
      if (uid) {
        slot.setAttribute('data-uid', String(uid));
      }
    }

    if (!uid) return;

    var isEmpty = !slot.querySelector('.af-apc');
    if (isEmpty || !inFlight[uid]) {
      fetchSnapshot(uid);
    }
  }

  function processNodeForApc(node) {
    if (!node || node.nodeType !== 1) return;

    var handled = false;

    if (node.matches && node.matches('[data-af-apc-slot="1"]')) {
      updateSlot(node);
      handled = true;
    }

    if (node.querySelectorAll) {
      node.querySelectorAll('[data-af-apc-slot="1"]').forEach(function (slot) {
        updateSlot(slot);
        handled = true;
      });
    }

    var candidatePosts = [];
    if (node.matches && node.matches('[id^="post_"], .post')) {
      candidatePosts.push(node);
    }
    if (node.querySelectorAll) {
      node.querySelectorAll('[id^="post_"], .post').forEach(function (postNode) {
        candidatePosts.push(postNode);
      });
    }

    candidatePosts.forEach(function (postNode) {
      var slot = postNode.querySelector('[data-af-apc-slot="1"]');
      if (slot) {
        updateSlot(slot);
        handled = true;
        return;
      }

      var plaque = postNode.querySelector('[data-af-balance-plaque="1"]') || postNode.querySelector('.af-cs-postbit-stats');
      if (!plaque) return;

      var uid = toInt(plaque.getAttribute('data-uid')) || extractUidFromPost(postNode);
      if (!uid) return;

      var postsCell = plaque.querySelector('[data-af-balance-posts="1"]');
      if (!postsCell) {
        var grid = plaque.querySelector('.af-cs-postbit-stats__grid') || plaque;
        postsCell = document.createElement('div');
        postsCell.className = 'af-cs-postbit-stat af-cs-postbit-stat--posts';
        postsCell.setAttribute('data-af-balance-posts', '1');
        grid.appendChild(postsCell);
      }

      slot = document.createElement('div');
      slot.className = 'af-apc-slot';
      slot.setAttribute('data-af-apc-slot', '1');
      slot.setAttribute('data-uid', String(uid));
      postsCell.appendChild(slot);

      updateSlot(slot);
      handled = true;
    });

    if (!handled && node.matches && node.matches('[id^="post_"], .post')) {
      var uid = extractUidFromPost(node);
      if (uid) {
        fetchSnapshot(uid);
      }
    }
  }

  function findLatestPostNode() {
    var posts = Array.prototype.slice.call(document.querySelectorAll('[id^="post_"], .post'));
    if (!posts.length) return null;

    posts.sort(function (a, b) {
      var aid = toInt(String(a.id || '').replace(/^post_/, ''));
      var bid = toInt(String(b.id || '').replace(/^post_/, ''));
      if (aid && bid) return aid - bid;
      return (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) ? -1 : 1;
    });

    return posts[posts.length - 1] || null;
  }

  function findPostNodeByPid(pid) {
    pid = toInt(pid);
    if (!pid) return null;

    return document.getElementById('post_' + pid)
      || document.querySelector('[id="post_' + pid + '"]')
      || document.querySelector('[data-pid="' + pid + '"]');
  }

  function extractPidFromAjaxResponse(xhr) {
    if (!xhr) return 0;

    var text = String(xhr.responseText || '');
    if (!text) return 0;

    var m = text.match(/id\s*=\s*["']post_(\d+)["']/i)
      || text.match(/(?:^|[?&])pid=(\d+)/i);
    return m ? toInt(m[1]) : 0;
  }

  function findQuickReplyPostNode(xhr, params) {
    var pid = toInt(params.pid || params.newpid || params.lastpid || 0);
    if (!pid) {
      pid = extractPidFromAjaxResponse(xhr);
    }

    return findPostNodeByPid(pid) || findLatestPostNode();
  }

  function observeRealtimePosts() {
    if (window.__afApcObserverStarted || typeof MutationObserver === 'undefined') return;

    var roots = [
      document.getElementById('posts'),
      document.getElementById('content'),
      document.querySelector('.thread'),
      document.body
    ].filter(Boolean);

    if (!roots.length) return;

    var observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        if (!mutation.addedNodes || !mutation.addedNodes.length) return;
        mutation.addedNodes.forEach(function (node) {
          processNodeForApc(node);
        });
      });
    });

    roots.forEach(function (root) {
      observer.observe(root, { childList: true, subtree: true });
    });

    window.__afApcObserverStarted = true;
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

        console.log('[APC] new post detected');

        var postNode = findQuickReplyPostNode(xhr, p);
        if (postNode) {
          processNodeForApc(postNode);
        }
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

    document.querySelectorAll('[data-af-apc-slot="1"]').forEach(function (slot) {
      updateSlot(slot);
    });

    observeRealtimePosts();
    hookQuickReply();
  });
})();

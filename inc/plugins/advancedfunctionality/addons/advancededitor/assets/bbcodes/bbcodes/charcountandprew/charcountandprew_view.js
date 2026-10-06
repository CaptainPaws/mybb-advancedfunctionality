(function () {
  'use strict';

  if (window.__afAePostCountViewLoaded) return;
  window.__afAePostCountViewLoaded = true;

  function asText(x) { return String(x == null ? '' : x); }

  // ====== CONFIG from PHP payload (ACP settings) ======
  function getCfg() {
    try {
      var p = window.afAePayload || window.afAdvancedEditorPayload || window.afAdvancedEditor || null;
      var cfg = null;
      if (p && p.cfg) cfg = Object.assign({}, p.cfg);
      if (p && p.payload && p.payload.cfg) cfg = Object.assign({}, p.payload.cfg);
      if (p && typeof p.countBbcode !== 'undefined') {
        if (!cfg) cfg = {};
        cfg.countBbcode = p.countBbcode;
      }
      if (p && p.payload && typeof p.payload.countBbcode !== 'undefined') {
        if (!cfg) cfg = {};
        cfg.countBbcode = p.payload.countBbcode;
      }
      if (cfg) return cfg;
    } catch (e) {}
    return {};
  }

  function parseCsvIds(csv) {
    csv = asText(csv).trim();
    if (!csv) return []; // пусто = везде
    var out = [];
    var seen = Object.create(null);
    csv.split(',').forEach(function (part) {
      var n = parseInt(asText(part).trim(), 10);
      if (n > 0 && !seen[n]) { seen[n] = 1; out.push(n); }
    });
    return out;
  }

  function listToSet(list) {
    var set = Object.create(null);
    if (Array.isArray(list)) {
      for (var i = 0; i < list.length; i++) {
        var n = parseInt(list[i], 10);
        if (n > 0) set[n] = 1;
      }
    }
    return set;
  }

  var CFG = getCfg();
  var ALLOWED_POSTCOUNT_FORUM_IDS = parseCsvIds(CFG.postcountForumIds || '');
  var ALLOWED_FORM_FEATURE_FORUM_IDS = parseCsvIds(CFG.formFeatureForumIds || '');

  var POSTCOUNT_SET = listToSet(ALLOWED_POSTCOUNT_FORUM_IDS);
  var FORMFEATURE_SET = listToSet(ALLOWED_FORM_FEATURE_FORUM_IDS);

  function debounce(fn, ms) {
    var t = null;
    return function () {
      var ctx = this, args = arguments;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(ctx, args); }, ms || 150);
    };
  }

  // ====== helpers: forum id ======
  function getUrlParam(name) {
    try {
      var u = new URL(window.location.href);
      return u.searchParams.get(name);
    } catch (e) { return null; }
  }

    function getForumIdFromDom() {
    // 0) fid из hidden в формах (newreply/newthread/editpost/quickreply)
    var el =
        document.querySelector('input[name="fid"]') ||
        document.querySelector('form#quick_reply_form input[name="fid"]') ||
        document.querySelector('form#post input[name="fid"]');
    if (el && el.value && String(el.value).match(/^\d+$/)) return parseInt(el.value, 10);

    // 0.1) иногда fid может лежать в hidden "forum" или "f"
    var el2 = document.querySelector('input[name="f"]') || document.querySelector('input[name="forum"]');
    if (el2 && el2.value && String(el2.value).match(/^\d+$/)) return parseInt(el2.value, 10);

    // 1) showthread: иногда есть MyBBSettings.fid
    try {
        if (window.MyBBSettings && String(window.MyBBSettings.fid || '').match(/^\d+$/)) {
        return parseInt(window.MyBBSettings.fid, 10);
        }
    } catch (e) {}

    // 2) глобальная переменная fid (иногда в шаблонах)
    try {
        if (typeof fid !== 'undefined' && String(fid).match(/^\d+$/)) return parseInt(fid, 10);
    } catch (e) {}

    // 3) из URL (forumdisplay.php?fid=...)
    var fidFromUrl = getUrlParam('fid');
    if (fidFromUrl && String(fidFromUrl).match(/^\d+$/)) return parseInt(fidFromUrl, 10);

    // 4) showthread.php?tid=... — fid не в урле
    // РАНЬШЕ: брали ПЕРВЫЙ a[href*="forumdisplay.php?fid="] -> часто это категория.
    // ТЕПЕРЬ: берём ПОСЛЕДНИЙ (обычно это реальный форум темы).
    try {
        var links = document.querySelectorAll('a[href*="forumdisplay.php?fid="]');
        if (links && links.length) {
        for (var i = links.length - 1; i >= 0; i--) {
            var href = links[i].getAttribute('href') || '';
            var m = href.match(/[?&]fid=(\d+)/);
            if (m) return parseInt(m[1], 10);
        }
        }
    } catch (e) {}

    return null;
    }


  function inAllowedForum(list, setObj) {
    // пусто => везде
    if ((!Array.isArray(list) || !list.length) && (!setObj || typeof setObj !== 'object')) return true;
    if (Array.isArray(list) && !list.length) return true;

    var fid2 = getForumIdFromDom();
    if (fid2 === null) return false;

    // быстрый путь: set
    if (setObj && setObj[fid2]) return true;

    // фоллбек: массив
    if (Array.isArray(list)) return list.indexOf(fid2) !== -1;

    return false;
  }

  // ====== BBCode -> plain text (для счётчика) ======
  function stripBbForCount(s) {
    s = asText(s);

    // вырезаем тяжёлые штуки
    s = s.replace(/\[mask\b[\s\S]*?\[\/mask\]/gi, '');
    s = s.replace(/\[img\b[^\]]*\][\s\S]*?\[\/img\]/gi, '');

    // теги
    s = s.replace(/\[(\/?)[^\]\s=]+(?:=[^\]]+)?\]/g, '');

    return s;
  }

  function countGraphemes(s) {
    return Array.from(asText(s)).length;
  }

  function findPostBodies(postEl) {
    return postEl.querySelector('.post_body') ||
      postEl.querySelector('.post-content') ||
      postEl.querySelector('.post_content') ||
      postEl.querySelector('.postbody') ||
      null;
  }

  function extractVisiblePostText(bodyEl) {
    if (!bodyEl) return '';
    var clone = bodyEl.cloneNode(true);

    var kill = [
      '.signature', '.post_sig', '.post-signature',
      '.postmask', '.post-mask', '.mask', '.pl-mask',
      '[data-mask]'
    ];
    kill.forEach(function (sel) {
      var nodes = clone.querySelectorAll(sel);
      for (var i = 0; i < nodes.length; i++) nodes[i].remove();
    });

    var text = asText(clone.textContent || '');
    text = text.replace(/\s+/g, ' ').trim();
    return text;
  }

  function applyPostCountersOnce(root) {
    if (!inAllowedForum(ALLOWED_POSTCOUNT_FORUM_IDS, POSTCOUNT_SET)) return;

    root = root || document;
    var posts = [];
    if (root.nodeType === 1 && root.matches && root.matches('.post')) posts.push(root);
    if (root.querySelectorAll) {
      var descendants = root.querySelectorAll('.post');
      for (var p = 0; p < descendants.length; p++) posts.push(descendants[p]);
    }
    if (!posts || !posts.length) return;

    for (var i = 0; i < posts.length; i++) {
      var post = posts[i];
      // ATF renders the canonical server-side count in its content metadata.
      // Identify ATF by its stable post structure rather than by the meta-line:
      // MyBB temporarily replaces the pid host during AJAX Quick Edit.
      if (post.querySelector('.atf-post__content .atf-post__message')) continue;
      if (post.querySelector('.af-ccp-postcount')) continue;

      var body = findPostBodies(post);
      if (!body) continue;

      var text = extractVisiblePostText(body);
      if (!text) continue;

      var n = countGraphemes(text);

      var box = document.createElement('div');
      box.className = 'af-ccp-postcount';
      box.textContent = 'Символов в посте: ' + n;

      body.insertAdjacentElement('afterend', box);
    }
  }

  function initPostCounters() {
    applyPostCountersOnce();

    try {
      var mo = new MutationObserver(function (ml) {
        for (var i = 0; i < ml.length; i++) {
          if (ml[i].addedNodes && ml[i].addedNodes.length) {
            for (var j = 0; j < ml[i].addedNodes.length; j++) {
              var added = ml[i].addedNodes[j];
              if (added && added.nodeType === 1) applyPostCountersOnce(added);
            }
          }
        }
      });
      mo.observe(document.body, { childList: true, subtree: true });
    } catch (e) {}
  }

  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initPostCounters); else initPostCounters();
})();

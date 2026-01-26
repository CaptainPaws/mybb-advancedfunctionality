/**
 * AF QuickQuote for MyBB 1.8.x
 * - Click postbit quote/reply button => insert quote into Quick Reply editor (no redirect)
 * - If text selected => quote selected text
 * - Floating quote button on selection (mouse-coordinates fallback)
 */

(function ($) {
  'use strict';

  if (window.__afQuickQuoteInit) return;
  window.__afQuickQuoteInit = true;

  var FLOAT_ID = 'af-quickquote-float';
  var CSS_ID   = 'af-quickquote-css';

  // ---------- load css via JS ----------
  function getThisScriptBase() {
    // Return base url to current script folder
    try {
      var cs = document.currentScript;
      if (cs && cs.src) return cs.src.replace(/[^\/?#]+(\?.*)?$/, '');
    } catch (e) {}

    // fallback: last script tag containing af_quickquote
    try {
      var scripts = document.getElementsByTagName('script');
      for (var i = scripts.length - 1; i >= 0; i--) {
        var s = scripts[i];
        if (s && s.src && /af_quickquote(\.min)?\.js(\?.*)?$/i.test(s.src)) {
          return s.src.replace(/[^\/?#]+(\?.*)?$/, '');
        }
      }
    } catch (e2) {}

    // last resort: relative
    return '';
  }

  function ensureCssLinkOnce() {
    if (document.getElementById(CSS_ID)) return;

    var base = getThisScriptBase(); // usually .../assets/
    var href = base + 'af_quickquote.css';

    var link = document.createElement('link');
    link.id = CSS_ID;
    link.rel = 'stylesheet';
    link.type = 'text/css';
    link.href = href;

    document.head.appendChild(link);
  }

  // ---------- editor helpers ----------
  function findMessageTextarea() {
    var el = document.getElementById('message');
    if (el && el.tagName === 'TEXTAREA') return el;

    var t = document.querySelector('textarea[name="message"]');
    if (t) return t;

    var list = document.querySelectorAll('textarea');
    for (var i = 0; i < list.length; i++) {
      if (list[i].offsetParent !== null) return list[i];
    }
    return null;
  }

  function insertAtCursor(textarea, text) {
    textarea.focus();
    var start = textarea.selectionStart || 0;
    var end = textarea.selectionEnd || 0;
    var val = textarea.value || '';

    textarea.value = val.slice(0, start) + text + val.slice(end);
    var pos = start + text.length;
    textarea.selectionStart = textarea.selectionEnd = pos;

    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function insertIntoEditor(bbcode) {
    var ta = findMessageTextarea();
    if (!ta) return false;

    try {
      if (window.sceditor && typeof window.sceditor.instance === 'function') {
        var inst = window.sceditor.instance(ta);
        if (inst && typeof inst.insertText === 'function') {
          inst.insertText(bbcode);
          inst.focus();
          return true;
        }
      }
    } catch (e) {}

    insertAtCursor(ta, bbcode);
    return true;
  }

  function ensureQuickReplyVisible() {
    var ta = findMessageTextarea();
    if (!ta) return;
    try { ta.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
    try { ta.focus(); } catch (e2) {}
  }

  // ---------- post/meta extraction ----------
  function parseTidPidFromHref(href) {
    var out = { tid: 0, pid: 0 };
    if (!href) return out;

    var m1 = href.match(/[?&]tid=(\d+)/i);
    if (m1) out.tid = parseInt(m1[1], 10) || 0;

    var m2 = href.match(/[?&](?:replyto|pid)=(\d+)/i);
    if (m2) out.pid = parseInt(m2[1], 10) || 0;

    return out;
  }

  function findPostContainer(pid, clickedEl) {
    if (pid) {
      var byId = document.getElementById('post_' + pid) || document.getElementById('pid_' + pid);
      if (byId) return byId;
    }
    var $p = $(clickedEl).closest('.post, .post.classic, [id^="post_"]');
    return $p.length ? $p.get(0) : null;
  }

  function extractUsername(postEl) {
    if (!postEl) return '';
    var cand =
      postEl.querySelector('.author_information .largetext a') ||
      postEl.querySelector('.post_author a') ||
      postEl.querySelector('.author_information a') ||
      postEl.querySelector('.username') ||
      postEl.querySelector('.post_author');

    return (cand ? (cand.textContent || '') : '').trim();
  }

  function extractDateline(postEl) {
    if (!postEl) return '';

    var d = postEl.getAttribute('data-dateline');
    if (d && /^\d+$/.test(d)) return d;

    var any = postEl.querySelector('[data-dateline]');
    if (any) {
      var dd = any.getAttribute('data-dateline');
      if (dd && /^\d+$/.test(dd)) return dd;
    }

    var t = postEl.querySelector('time[data-timestamp], time[data-time], time[data-unixtime]');
    if (t) {
      var v = t.getAttribute('data-timestamp') || t.getAttribute('data-time') || t.getAttribute('data-unixtime');
      if (v && /^\d+$/.test(v)) return v;
    }
    return '';
  }

  function extractPostText(postEl) {
    if (!postEl) return '';
    var body =
      postEl.querySelector('.post_body') ||
      postEl.querySelector('.post_content') ||
      postEl.querySelector('.post_message');
    if (!body) return '';
    return ((body.innerText || body.textContent || '') + '').trim();
  }

  function escapeQuoteAttr(s) {
    return String(s || '').replace(/"/g, '\\"');
  }

  function buildQuote(username, pid, dateline, text) {
    username = escapeQuoteAttr(username || '');
    text = String(text || '').trim();

    var head = '[quote="' + username + '" pid=\'' + String(pid || '') + '\'';
    if (dateline) head += " dateline='" + String(dateline) + "'";
    head += ']\n';

    return head + text + '\n[/quote]\n';
  }

  // ---------- selection helpers ----------
  function getSelectionText() {
    try {
      var sel = window.getSelection();
      if (!sel || sel.isCollapsed) return '';
      return (sel.toString() || '').trim();
    } catch (e) { return ''; }
  }

  function selectionInsideElement(el) {
    try {
      if (!el) return false;
      var sel = window.getSelection();
      if (!sel || sel.rangeCount === 0) return false;
      var r = sel.getRangeAt(0);
      return el.contains(r.commonAncestorContainer);
    } catch (e) { return false; }
  }

  function isSelectionInEditor() {
    var ta = findMessageTextarea();
    if (!ta) return false;
    return selectionInsideElement(ta) || selectionInsideElement(ta.parentNode);
  }

  function getSelectionPostEl() {
    try {
      var sel = window.getSelection();
      if (!sel || sel.rangeCount === 0) return null;
      var node = sel.anchorNode;
      var host = node && node.nodeType === 3 ? node.parentElement : (node && node.nodeType === 1 ? node : null);
      if (!host || !host.closest) return null;
      return host.closest('.post, .post.classic, [id^="post_"]');
    } catch (e) { return null; }
  }

  // ---------- core: quote insertion for a post ----------
  function quoteFromPost(postEl, pid, preferSelection) {
    if (!postEl) return false;

    var username = extractUsername(postEl) || '';
    var dateline = extractDateline(postEl) || '';
    var text = '';

    if (preferSelection) {
      var selText = getSelectionText();
      if (selText && selectionInsideElement(postEl) && !isSelectionInEditor()) {
        text = selText;
      }
    }

    if (!text) text = extractPostText(postEl);
    if (!text) return false;

    ensureQuickReplyVisible();
    return insertIntoEditor(buildQuote(username, pid, dateline, text));
  }

  // ---------- intercept postbit quote/reply button ----------
  function bindPostbitButtons() {
    $(document).on('click', 'a.postbit_quote[href*="newreply.php"][href*="replyto="]', function (e) {
      e.preventDefault();
      e.stopPropagation();

      var href = this.getAttribute('href') || '';
      var ids = parseTidPidFromHref(href);
      var pid = ids.pid || 0;

      var postEl = findPostContainer(pid, this);
      if (!postEl) return;

      quoteFromPost(postEl, pid, true);
    });
  }

  // ---------- floating quote button (mouse-coordinates fallback) ----------
  function ensureFloatButton() {
    if (document.getElementById(FLOAT_ID)) return;
    var b = document.createElement('div');
    b.id = FLOAT_ID;
    b.setAttribute('role', 'button');
    b.setAttribute('tabindex', '0');
    b.setAttribute('data-af-title', 'Цитировать выделенное');
    b.setAttribute('title', 'Цитировать выделенное');
    document.body.appendChild(b);
  }

  function hideFloat() {
    var el = document.getElementById(FLOAT_ID);
    if (el) el.style.display = 'none';
  }

  var lastMouse = { x: 0, y: 0, t: 0 };

  function showFloatAt(x, y) {
    var el = document.getElementById(FLOAT_ID);
    if (!el) return;

    var left = Math.max(8, x + 10);
    var top  = Math.max(8, y - 42);

    var maxLeft = (document.documentElement.clientWidth) - 34 - 8;
    var maxTop  = (document.documentElement.clientHeight) - 34 - 8;
    if (left > maxLeft) left = maxLeft;
    if (top > maxTop) top = maxTop;

    el.style.left = left + 'px';
    el.style.top = top + 'px';
    el.style.display = 'flex';
  }

  function maybeShowFloatFromSelection() {
    if (isSelectionInEditor()) { hideFloat(); return; }

    var text = getSelectionText();
    if (!text) { hideFloat(); return; }

    var postEl = getSelectionPostEl();
    if (!postEl) { hideFloat(); return; }

    // Главное: показываем около последнего mouseup (самый стабильный метод)
    // Если выделили клавиатурой — попробуем показать в центре экрана как fallback
    var now = Date.now();
    if (lastMouse.t && (now - lastMouse.t) < 2000 && lastMouse.x && lastMouse.y) {
      showFloatAt(lastMouse.x, lastMouse.y);
      return;
    }

    showFloatAt(Math.round(window.innerWidth / 2), Math.round(window.innerHeight / 2));
  }

  function bindFloatButton() {
    // запоминаем позицию мыши на mouseup (именно когда выделение обычно заканчивается)
    document.addEventListener('mouseup', function (e) {
      lastMouse.x = e.clientX;
      lastMouse.y = e.clientY;
      lastMouse.t = Date.now();
      setTimeout(maybeShowFloatFromSelection, 0);
    }, { passive: true });

    // выделение клавиатурой/двойной клик/прочее
    document.addEventListener('selectionchange', function () {
      // чуть дебаунса, чтобы не дёргать постоянно
      clearTimeout(window.__afQQSelTimer);
      window.__afQQSelTimer = setTimeout(maybeShowFloatFromSelection, 50);
    }, { passive: true });

    $(window).on('scroll resize', function () { hideFloat(); });

    // клик по кнопке = цитировать выделенное
    $(document).on('mousedown', '#' + FLOAT_ID, function (e) {
      e.preventDefault();
      e.stopPropagation();

      var selText = getSelectionText();
      if (!selText) return;

      var postEl = getSelectionPostEl();
      if (!postEl) return;

      var pid = 0;
      var idm = (postEl.getAttribute('id') || '').match(/post_(\d+)/i);
      if (idm) pid = parseInt(idm[1], 10) || 0;

      quoteFromPost(postEl, pid, true);
      hideFloat();

      try {
        var sel = window.getSelection();
        if (sel) sel.removeAllRanges();
      } catch (e2) {}
    });

    // клик в другое место прячет
    $(document).on('mousedown', function (e) {
      if (e.target && e.target.id === FLOAT_ID) return;
      hideFloat();
    });
  }

  // ---------- init ----------
  $(function () {
    ensureCssLinkOnce();
    ensureFloatButton();
    bindPostbitButtons();
    bindFloatButton();
  });

})(jQuery);

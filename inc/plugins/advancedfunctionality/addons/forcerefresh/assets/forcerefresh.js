(function () {
  'use strict';

  if (window.__afForceRefreshInit) return;
  window.__afForceRefreshInit = true;

  var cfg = window.afForceRefreshCfg || {};
  var delayMs = Number(cfg.delayMs || 0);
  if (!isFinite(delayMs) || delayMs < 0) delayMs = 0;

  // pending только для потока editpost -> showthread
  var pendingKey = 'af_fr_editpost_pending';

  // маркер в URL, чтобы не уйти в цикл replace
  var markerKey = 'af_fr';
  var markerVal = '1';

  function toInt(v) {
    var n = parseInt(String(v == null ? '' : v), 10);
    return isFinite(n) ? n : 0;
  }

  function parseUrlValue(url, key) {
    try {
      var q = String(url || '').split('?')[1] || '';
      var hashPos = q.indexOf('#');
      if (hashPos >= 0) q = q.slice(0, hashPos);
      var parts = q.split('&');
      for (var i = 0; i < parts.length; i++) {
        var chunk = parts[i].split('=');
        if (decodeURIComponent(chunk[0] || '') !== key) continue;
        return decodeURIComponent((chunk[1] || '').replace(/\+/g, ' '));
      }
    } catch (e) {}
    return '';
  }

  function isScript(name) {
    var p = String(location.pathname || '');
    var h = String(location.href || '');
    var re = new RegExp(name.replace('.', '\\.') + '$', 'i');
    return re.test(p) || re.test(h);
  }

  var isShowthread = isScript('showthread.php');
  var isEditpost = isScript('editpost.php');

  function hasMarker() {
    return String(parseUrlValue(location.href, markerKey) || '') === markerVal;
  }

  function addMarkerToCurrentUrl() {
    // добавляем af_fr=1 в текущий URL, сохраняя hash
    var href = String(location.href || '');
    if (!href) return href;

    var hash = '';
    var hashPos = href.indexOf('#');
    if (hashPos >= 0) {
      hash = href.slice(hashPos);
      href = href.slice(0, hashPos);
    }

    // уже есть?
    if (new RegExp('(?:\\?|&)' + markerKey + '=' + markerVal + '(?:&|$)').test(href)) {
      return href + hash;
    }

    if (href.indexOf('?') === -1) {
      href += '?' + markerKey + '=' + markerVal;
    } else {
      href += '&' + markerKey + '=' + markerVal;
    }

    return href + hash;
  }

  // -----------------------------
  // editpost.php: ставим pending на submit (ОЧЕНЬ лёгкая логика)
  // -----------------------------
  function hookEditpostPending() {
    if (!isEditpost) return;
    if (!window.sessionStorage) return;

    var form =
      document.querySelector('form[action*="editpost.php"], form#editpost, form[name="editpost"]') ||
      document.querySelector('form');

    if (!form) return;

    form.addEventListener('submit', function () {
      var tid = toInt((form.elements.tid && form.elements.tid.value) || parseUrlValue(location.href, 'tid') || 0);
      var pid = toInt((form.elements.pid && form.elements.pid.value) || parseUrlValue(location.href, 'pid') || 0);

      try {
        window.sessionStorage.setItem(
          pendingKey,
          JSON.stringify({ ts: Date.now(), tid: tid, pid: pid })
        );
      } catch (e) {}
    }, true);
  }

  // -----------------------------
  // showthread.php: если пришли после editpost — делаем ОДИН replace на URL+af_fr=1
  // -----------------------------
  function consumePendingAndForceReplaceOnShowthread() {
    if (!isShowthread) return;
    if (!window.sessionStorage) return;

    var raw = '';
    try { raw = window.sessionStorage.getItem(pendingKey) || ''; } catch (e) { raw = ''; }
    if (!raw) return;

    var data = null;
    try { data = JSON.parse(raw); } catch (e2) { data = null; }
    if (!data || typeof data !== 'object') {
      try { window.sessionStorage.removeItem(pendingKey); } catch (e3) {}
      return;
    }

    var ts = toInt(data.ts || 0);
    if (!ts || (Date.now() - ts) > 60000) {
      try { window.sessionStorage.removeItem(pendingKey); } catch (e4) {}
      return;
    }

    // страховка от "не та тема"
    var curTid = toInt(parseUrlValue(location.href, 'tid') || 0);
    var pendTid = toInt(data.tid || 0);
    if (pendTid > 0 && curTid > 0 && pendTid !== curTid) {
      try { window.sessionStorage.removeItem(pendingKey); } catch (e5) {}
      return;
    }

    // если маркера нет — делаем replace (pending НЕ удаляем, чтобы пережил навигацию)
    if (!hasMarker()) {
      var target = addMarkerToCurrentUrl();
      setTimeout(function () {
        try { window.location.replace(target); } catch (e6) { try { window.location.href = target; } catch (e7) {} }
      }, delayMs);
      return;
    }

    // если маркер уже есть — значит replace случился, чистим pending
    try { window.sessionStorage.removeItem(pendingKey); } catch (e8) {}
  }

  // -----------------------------
  // bfcache страховка: если showthread восстановили из cache, но pending ещё есть — replace снова на URL+af_fr=1
  // -----------------------------
  function hookBfcacheFallback() {
    if (!isShowthread) return;

    window.addEventListener('pageshow', function (event) {
      if (!event || !event.persisted) return;
      if (!window.sessionStorage) return;

      var raw = '';
      try { raw = window.sessionStorage.getItem(pendingKey) || ''; } catch (e) { raw = ''; }
      if (!raw) return;

      var data = null;
      try { data = JSON.parse(raw); } catch (e2) { data = null; }
      if (!data || typeof data !== 'object') return;

      var ts = toInt(data.ts || 0);
      if (!ts || (Date.now() - ts) > 60000) return;

      var target = addMarkerToCurrentUrl();
      try { window.location.replace(target); } catch (e3) { try { window.location.href = target; } catch (e4) {} }
    });
  }

  // --- ТВОЙ СТАРЫЙ Quick Reply reload (НЕ ТРОГАЮ) ---
  function looksLikeQuickReplyRequest(settingsOrUrl, maybeData) {
    var url = '';
    var data = '';

    if (typeof settingsOrUrl === 'string') {
      url = settingsOrUrl;
      data = String(maybeData || '');
    } else if (settingsOrUrl && typeof settingsOrUrl === 'object') {
      url = String(settingsOrUrl.url || '');
      data = settingsOrUrl.data;
      if (typeof data !== 'string') data = '';
    }

    var u = url.toLowerCase();

    if (u.indexOf('xmlhttp.php') !== -1 && u.indexOf('action=do_newreply') !== -1) return true;
    if (u.indexOf('newreply.php') !== -1) return true;

    var d = String(data || '').toLowerCase();
    if (d.indexOf('action=do_newreply') !== -1) return true;

    return false;
  }

  function responseLooksSuccessful(xhr) {
    try {
      if (!xhr) return false;
      if (xhr.readyState && xhr.readyState !== 4) return false;
      if (xhr.status !== 200) return false;
      return responseTextLooksSuccessful(String(xhr.responseText || ''));
    } catch (e) {
      return false;
    }
  }

  function responseTextLooksSuccessful(text) {
    var t = String(text || '');
    if (!t) return false;

    if (/error|no\s+permission|csrf|my_post_key|invalid_post|thread_closed|flood_check/i.test(t) && !/post_\d+/i.test(t)) {
      return false;
    }

    return true;
  }

  function findQuickReplyTextarea() {
    var form =
      document.querySelector('form#quick_reply_form') ||
      document.querySelector('form[action*="newreply.php"][id*="quick"]') ||
      document.querySelector('form[action*="xmlhttp.php"][id*="quick"]');
    if (!form) return null;
    return form.querySelector('textarea[name="message"], textarea#message');
  }

  function clearQuickReplyEditor() {
    var ta = findQuickReplyTextarea();
    if (!ta) return;

    try { ta.value = ''; } catch (e0) {}

    var inst = null;
    try {
      if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.sceditor === 'function') {
        inst = window.jQuery(ta).sceditor('instance');
      }
    } catch (e1) {
      inst = null;
    }

    if (inst) {
      try { if (typeof inst.val === 'function') inst.val(''); } catch (e2) {}
      try {
        if (typeof inst.getBody === 'function') {
          var body = inst.getBody();
          if (body && typeof body.innerHTML === 'string') body.innerHTML = '';
        }
      } catch (e3) {}
      try {
        var sourceTa = (inst.getContentAreaContainer && inst.getContentAreaContainer().querySelector)
          ? inst.getContentAreaContainer().querySelector('textarea.sceditor-source')
          : null;
        if (!sourceTa && inst.textarea) sourceTa = inst.textarea;
        if (sourceTa) sourceTa.value = '';
      } catch (e4) {}
      try { if (typeof inst.updateOriginal === 'function') inst.updateOriginal(); } catch (e5) {}
    }

    try { ta.dispatchEvent(new Event('input', { bubbles: true })); } catch (e6) {}
    try { ta.dispatchEvent(new Event('change', { bubbles: true })); } catch (e7) {}
  }

  function reloadSoon() {
    if (window.__afForceRefreshReloading) return;
    window.__afForceRefreshReloading = true;

    setTimeout(function () {
      try { location.reload(); } catch (e) {}
    }, delayMs);
  }

  // порядок:
  // 1) showthread: если пришли после editpost — сделать replace на URL+af_fr=1 (один раз)
  // 2) editpost: поставить pending
  consumePendingAndForceReplaceOnShowthread();
  hookEditpostPending();
  hookBfcacheFallback();

  // quick reply logic — только на showthread
  if (!isShowthread) return;

  if (window.jQuery) {
    var $ = window.jQuery;
    $(document).ajaxComplete(function (event, xhr, settings) {
      try {
        if (!looksLikeQuickReplyRequest(settings)) return;
        if (!responseLooksSuccessful(xhr)) return;
        clearQuickReplyEditor();
        reloadSoon();
      } catch (e) {}
    });
    return;
  }

  if (window.fetch) {
    var _fetch = window.fetch;
    window.fetch = function () {
      var args = arguments;
      var input = args[0];
      var init = args[1] || {};
      var url = (typeof input === 'string') ? input : (input && input.url ? input.url : '');
      var method = String((init && init.method) || 'GET').toUpperCase();

      var isPost = method === 'POST';
      if (!isPost || !looksLikeQuickReplyRequest(String(url))) {
        return _fetch.apply(this, args);
      }

      return _fetch.apply(this, args).then(function (resp) {
        try {
          if (!resp || !resp.ok) return resp;
          var copy = null;
          try { copy = resp.clone(); } catch (eClone) { copy = null; }

          if (!copy || typeof copy.text !== 'function') {
            return resp;
          }

          return copy.text().then(function (txt) {
            if (!responseTextLooksSuccessful(txt)) return resp;
            clearQuickReplyEditor();
            reloadSoon();
            return resp;
          }).catch(function () {
            return resp;
          });
        } catch (e) {}
        return resp;
      });
    };
  }
})();

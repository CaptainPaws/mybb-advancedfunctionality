(function () {
  'use strict';

  if (window.__afForceRefreshInit) return;
  window.__afForceRefreshInit = true;

  var cfg = window.afForceRefreshCfg || {};
  var delayMs = Number(cfg.delayMs || 0);
  var debugEnabled = String(cfg.debug || '') === '1';
  var pendingKey = 'af_forcerefresh_pending';

  if (!isFinite(delayMs) || delayMs < 0) delayMs = 0;

  function debugLog(message, payload) {
    if (!debugEnabled || !window.console || typeof window.console.info !== 'function') return;
    if (typeof payload === 'undefined') {
      window.console.info('[ForceRefresh] ' + message);
      return;
    }
    window.console.info('[ForceRefresh] ' + message, payload);
  }

  function scriptName() {
    try {
      var fromCfg = String(cfg.script || '').toLowerCase();
      if (fromCfg) return fromCfg;
      var p = String((window.location && window.location.pathname) || '').toLowerCase();
      var parts = p.split('/');
      return String(parts[parts.length - 1] || '');
    } catch (e) {}
    return '';
  }

  var currentScript = scriptName();
  var isShowthread = currentScript === 'showthread.php';
  var isEditpost = currentScript === 'editpost.php';
  if (!isShowthread && !isEditpost) return;

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

  function toInt(v) {
    var n = parseInt(String(v == null ? '' : v), 10);
    return isFinite(n) ? n : 0;
  }

  function parseBodyString(data) {
    var out = {};
    var src = String(data || '');
    if (!src) return out;
    var parts = src.split('&');
    for (var i = 0; i < parts.length; i++) {
      var pair = parts[i];
      if (!pair) continue;
      var eq = pair.indexOf('=');
      var k = eq >= 0 ? pair.slice(0, eq) : pair;
      var v = eq >= 0 ? pair.slice(eq + 1) : '';
      try {
        k = decodeURIComponent(String(k || '').replace(/\+/g, ' '));
        v = decodeURIComponent(String(v || '').replace(/\+/g, ' '));
      } catch (e) {}
      if (k) out[k] = v;
    }
    return out;
  }

  function canonicalTid() {
    var fromCfg = toInt(cfg.tid || 0);
    if (fromCfg > 0) return fromCfg;
    var fromUrl = toInt(parseUrlValue(location.href, 'tid') || 0);
    if (fromUrl > 0) return fromUrl;
    var el = document.querySelector('[data-tid], input[name="tid"]');
    if (!el) return 0;
    return toInt((el.getAttribute('data-tid') || el.value || 0));
  }

  function buildCanonicalShowthreadUrl(tid, pid) {
    var url = 'showthread.php';
    var q = [];

    tid = toInt(tid);
    pid = toInt(pid);

    if (tid > 0) q.push('tid=' + encodeURIComponent(String(tid)));
    if (pid > 0) q.push('pid=' + encodeURIComponent(String(pid)));

    if (q.length) url += '?' + q.join('&');
    if (pid > 0) url += '#pid' + pid;

    return url;
  }

  function setPending(reason, tid, pid) {
    if (!window.sessionStorage) return;
    var payload = {
      ts: Date.now(),
      reason: String(reason || ''),
      tid: toInt(tid || 0),
      pid: toInt(pid || 0)
    };

    try {
      window.sessionStorage.setItem(pendingKey, JSON.stringify(payload));
      debugLog('pending set', payload);
    } catch (e) {}
  }

  function consumePendingOnShowthread() {
    if (!isShowthread || !window.sessionStorage) return;

    var raw = '';
    try {
      raw = window.sessionStorage.getItem(pendingKey) || '';
    } catch (e0) {
      raw = '';
    }
    if (!raw) return;

    var data = null;
    try {
      data = JSON.parse(raw);
    } catch (e1) {
      data = null;
    }

    if (!data || typeof data !== 'object') {
      try { window.sessionStorage.removeItem(pendingKey); } catch (e2) {}
      return;
    }

    var ts = toInt(data.ts || 0);
    if (!ts || (Date.now() - ts) > 60000) {
      try { window.sessionStorage.removeItem(pendingKey); } catch (e3) {}
      return;
    }

    debugLog('landed after refresh', data);
    try { window.sessionStorage.removeItem(pendingKey); } catch (e4) {}
  }

  function redirectToCanonical(tid, pid, reason, replaceMode) {
    if (window.__afForceRefreshRedirecting) return;
    window.__afForceRefreshRedirecting = true;

    var finalTid = toInt(tid || canonicalTid() || 0);
    var finalPid = toInt(pid || parseUrlValue(location.href, 'pid') || 0);
    var target = buildCanonicalShowthreadUrl(finalTid, finalPid);

    setPending(reason, finalTid, finalPid);

    setTimeout(function () {
      try {
        if (replaceMode) {
          window.location.replace(target);
        } else {
          window.location.assign(target);
        }
      } catch (e1) {
        try { window.location.href = target; } catch (e2) {}
      }
    }, delayMs);
  }

  function looksLikeQuickEditRequest(url, bodyData) {
    var rawUrl = String(url || '').toLowerCase();
    if (rawUrl.indexOf('xmlhttp.php') === -1) return false;

    var action = String(bodyData.action || parseUrlValue(rawUrl, 'action') || '').toLowerCase();
    var doValue = String(bodyData.do || parseUrlValue(rawUrl, 'do') || '').toLowerCase();

    if (action !== 'edit_post') return false;
    return doValue === 'update_post' || doValue === 'update';
  }

  function extractPid(url, bodyData) {
    return toInt(bodyData.pid || bodyData.post_id || parseUrlValue(url, 'pid') || 0);
  }

  function responseIsQuickEditSuccess(text, pid) {
    var body = String(text || '');
    if (!body) return false;
    if (/\b(error|no\s+permission|invalid\s+post|my_post_key|security\s+token|csrf)\b/i.test(body)) {
      return false;
    }

    pid = toInt(pid);
    if (pid > 0) {
      var marker = new RegExp('id\\s*=\\s*["\\\']pid_' + pid + '["\\\']', 'i');
      if (marker.test(body)) return true;
    }

    if (/\{[\s\S]*"success"\s*:\s*true[\s\S]*\}/i.test(body)) return true;
    return false;
  }

  function hookEditpostSubmitFlow() {
    if (!isEditpost) return;

    var form = document.querySelector('form[action*="editpost.php"], form#editpost, form[name="editpost"]') || document.querySelector('form');
    if (!form) return;

    form.addEventListener('submit', function () {
      var tid = toInt((form.elements.tid && form.elements.tid.value) || canonicalTid() || 0);
      var pid = toInt((form.elements.pid && form.elements.pid.value) || parseUrlValue(location.href, 'pid') || 0);
      setPending('editpost_submit', tid, pid);
    }, true);
  }

  function hookShowthreadHistoryFallback() {
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

      var tid = toInt(data.tid || canonicalTid() || 0);
      var pid = toInt(data.pid || parseUrlValue(location.href, 'pid') || 0);
      debugLog('pageshow persisted; forcing canonical replace', { tid: tid, pid: pid, reason: data.reason || '' });
      window.location.replace(buildCanonicalShowthreadUrl(tid, pid));
    });
  }

  function hookQuickEditByAjaxSuccess() {
    if (!isShowthread || !window.jQuery || window.__afForceRefreshAjaxHooked) return;
    window.__afForceRefreshAjaxHooked = true;

    var $ = window.jQuery;
    var originalAjax = $.ajax;

    if (typeof originalAjax !== 'function') return;

    $.ajax = function patchedAjax(url, options) {
      var opts;

      if (typeof url === 'object') {
        opts = url || {};
      } else {
        opts = options || {};
        opts.url = url;
      }

      var requestUrl = String(opts.url || '');
      var bodyData = parseBodyString(opts.data || '');
      var isQuickEdit = looksLikeQuickEditRequest(requestUrl, bodyData);
      var pid = isQuickEdit ? extractPid(requestUrl, bodyData) : 0;
      var tid = canonicalTid();

      if (isQuickEdit) {
        var originalSuccess = opts.success;
        opts.success = function (responseText, statusText, xhrObj) {
          if (typeof originalSuccess === 'function') {
            originalSuccess.apply(this, arguments);
          }

          if (window.__afForceRefreshQuickEditDone) return;

          var xhr = xhrObj || this;
          var status = xhr && typeof xhr.status !== 'undefined' ? Number(xhr.status) : 200;
          if (status !== 200) return;

          var text = typeof responseText === 'string'
            ? responseText
            : ((xhr && typeof xhr.responseText === 'string') ? xhr.responseText : '');

          if (!responseIsQuickEditSuccess(text, pid)) return;

          window.__afForceRefreshQuickEditDone = true;
          redirectToCanonical(tid, pid, 'quickedit_success', false);
        };
      }

      return originalAjax.call(this, opts);
    };
  }

  consumePendingOnShowthread();
  hookEditpostSubmitFlow();
  hookShowthreadHistoryFallback();
  hookQuickEditByAjaxSuccess();
})();

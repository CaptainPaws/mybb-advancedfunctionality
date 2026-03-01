(function () {
  'use strict';

  if (window.__afForceRefreshInit) return;
  window.__afForceRefreshInit = true;

  var cfg = window.afForceRefreshCfg || {};
  var delayMs = Number(cfg.delayMs || 0);
  if (!isFinite(delayMs) || delayMs < 0) delayMs = 0;

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

  function buildCanonicalShowthreadUrl(tid, pid) {
    var url = 'showthread.php';
    if (tid > 0 || pid > 0) {
      var q = [];
      if (tid > 0) q.push('tid=' + encodeURIComponent(String(tid)));
      if (pid > 0) q.push('pid=' + encodeURIComponent(String(pid)));
      url += '?' + q.join('&');
    }
    if (pid > 0) url += '#pid' + pid;
    return url;
  }

  function reloadToCanonical(tid, pid) {
    if (window.__afForceRefreshReloading) return;
    window.__afForceRefreshReloading = true;

    setTimeout(function () {
      var target = buildCanonicalShowthreadUrl(tid, pid);
      try {
        if (isShowthread) {
          window.location.reload();
          return;
        }
      } catch (e0) {}
      try {
        window.location.href = target;
      } catch (e1) {
        try { window.location.assign(target); } catch (e2) {}
      }
    }, delayMs);
  }

  function rememberPendingEditFromForm(form) {
    if (!form || !window.sessionStorage) return;

    var tid = toInt((form.elements.tid && form.elements.tid.value) || parseUrlValue(location.href, 'tid'));
    var pid = toInt((form.elements.pid && form.elements.pid.value) || parseUrlValue(location.href, 'pid'));

    var action = String(form.getAttribute('action') || '');
    var doValue = '';
    try {
      if (form.elements.do && form.elements.do.value) doValue = String(form.elements.do.value);
    } catch (e0) {}
    if (!doValue) doValue = parseUrlValue(action, 'do') || parseUrlValue(location.href, 'do');

    if (String(doValue).toLowerCase() !== 'updatepost') return;

    var payload = {
      ts: Date.now(),
      tid: tid,
      pid: pid
    };

    try {
      window.sessionStorage.setItem('af_force_refresh_pending_edit', JSON.stringify(payload));
    } catch (e) {}
  }

  function hookEditpostSubmit() {
    if (!isEditpost) return;
    var form = document.querySelector('form[action*="editpost.php"], form#editpost, form[name="editpost"]') || document.querySelector('form');
    if (!form) return;
    form.addEventListener('submit', function () {
      rememberPendingEditFromForm(form);
    }, true);
  }

  function consumePendingEditReloadOnShowthread() {
    if (!isShowthread || !window.sessionStorage) return;

    var raw = '';
    try { raw = window.sessionStorage.getItem('af_force_refresh_pending_edit') || ''; } catch (e0) { raw = ''; }
    if (!raw) return;

    var data = null;
    try { data = JSON.parse(raw); } catch (e1) { data = null; }
    if (!data || typeof data !== 'object') {
      try { window.sessionStorage.removeItem('af_force_refresh_pending_edit'); } catch (e2) {}
      return;
    }

    var ts = toInt(data.ts || 0);
    if (!ts || (Date.now() - ts) > 180000) {
      try { window.sessionStorage.removeItem('af_force_refresh_pending_edit'); } catch (e3) {}
      return;
    }

    var pid = toInt(data.pid || parseUrlValue(location.href, 'pid') || 0);
    var tid = toInt(data.tid || parseUrlValue(location.href, 'tid') || 0);

    try { window.sessionStorage.removeItem('af_force_refresh_pending_edit'); } catch (e4) {}
    reloadToCanonical(tid, pid);
  }

  function looksLikeEditPostQuickEdit(settings) {
    if (!settings) return false;
    var url = String(settings.url || '').toLowerCase();
    if (url.indexOf('xmlhttp.php') === -1) return false;

    var dataObj = parseBodyString(settings.data || '');

    var action = String(dataObj.action || parseUrlValue(url, 'action') || '').toLowerCase();
    var doValue = String(dataObj.do || parseUrlValue(url, 'do') || '').toLowerCase();

    if (action !== 'edit_post') return false;
    return doValue === 'update_post' || doValue === 'update';
  }

  function pidFromQuickEdit(settings) {
    var url = String((settings && settings.url) || '');
    var dataObj = parseBodyString(settings && settings.data ? settings.data : '');
    return toInt(dataObj.pid || dataObj.post_id || parseUrlValue(url, 'pid') || 0);
  }

  function responseLooksSuccessful(xhr) {
    try {
      if (!xhr) return false;
      if (xhr.status && xhr.status !== 200) return false;
      var t = String(xhr.responseText || '');
      if (/error|permission|no\s+permission|csrf|my_post_key|invalid\s+post/i.test(t) && !/post_\d+/i.test(t)) {
        return false;
      }
      return true;
    } catch (e) {
      return true;
    }
  }

  function hookQuickEditSuccess() {
    if (!isShowthread || !window.jQuery) return;
    var $ = window.jQuery;

    $(document).ajaxComplete(function (event, xhr, settings) {
      try {
        if (!looksLikeEditPostQuickEdit(settings)) return;
        if (!responseLooksSuccessful(xhr)) return;

        var pid = pidFromQuickEdit(settings);
        var tid = toInt(parseUrlValue(location.href, 'tid') || 0);
        reloadToCanonical(tid, pid);
      } catch (e) {}
    });
  }

  hookEditpostSubmit();
  consumePendingEditReloadOnShowthread();
  hookQuickEditSuccess();
})();

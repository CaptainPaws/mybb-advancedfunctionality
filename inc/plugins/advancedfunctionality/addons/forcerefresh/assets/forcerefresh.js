(function () {
  'use strict';

  if (window.__afForceRefreshInit) return;
  window.__afForceRefreshInit = true;

  var cfg = window.afForceRefreshCfg || {};
  var delayMs = Number(cfg.delayMs || 0);
  if (!isFinite(delayMs) || delayMs < 0) delayMs = 0;

  // только showthread
  if (!/showthread\.php/i.test(String(location.pathname || '')) && !/showthread\.php/i.test(String(location.href || ''))) {
    return;
  }

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

    // типичные варианты MyBB quick reply:
    // 1) xmlhttp.php?action=do_newreply
    // 2) newreply.php (иногда ajax=1)
    if (u.indexOf('xmlhttp.php') !== -1 && u.indexOf('action=do_newreply') !== -1) return true;
    if (u.indexOf('newreply.php') !== -1) return true;

    // fallback по параметрам
    var d = String(data || '').toLowerCase();
    if (d.indexOf('action=do_newreply') !== -1) return true;

    return false;
  }

  function responseLooksSuccessful(xhr) {
    try {
      if (!xhr) return false;
      if (xhr.status && xhr.status !== 200) return false;
      var t = String(xhr.responseText || '');

      // если это “классическая” ошибка — не перезагружаем
      if (/error|permission|no\s+permission|csrf|my_post_key/i.test(t) && !/post_/i.test(t)) {
        return false;
      }

      // На успех обычно приходит HTML с новым постом/кнопками, либо куски с pid/post_
      if (/post_\d+/i.test(t) || /pid\d+/i.test(t) || /<\/textarea>/i.test(t) || /<!-- start: postbit/i.test(t)) {
        return true;
      }

      // если не распознали — но запрос был quick reply и 200 — лучше всё равно обновить
      return true;
    } catch (e) {
      return true;
    }
  }

  function reloadSoon() {
    // защита от двойного вызова
    if (window.__afForceRefreshReloading) return;
    window.__afForceRefreshReloading = true;

    setTimeout(function () {
      try { location.reload(); } catch (e) {}
    }, delayMs);
  }

  // 1) jQuery путь (в MyBB почти всегда есть)
  if (window.jQuery) {
    var $ = window.jQuery;

    $(document).ajaxComplete(function (event, xhr, settings) {
      try {
        if (!looksLikeQuickReplyRequest(settings)) return;
        if (!responseLooksSuccessful(xhr)) return;
        reloadSoon();
      } catch (e) {}
    });

    return;
  }

  // 2) Fallback без jQuery: перехват fetch (если кто-то заменил ajax на fetch)
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
          // успешный quick reply -> reload
          reloadSoon();
        } catch (e) {}
        return resp;
      });
    };
  }
})();
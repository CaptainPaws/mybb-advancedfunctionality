(function () {
  'use strict';

  // Глобальный гард
  if (window.__afBalanceJsInit) return;
  window.__afBalanceJsInit = true;

  function toInt(v) {
    var n = parseInt(String(v || ''), 10);
    return isFinite(n) ? n : 0;
  }

  function toStr(v) {
    return (v == null) ? '' : String(v);
  }

  function parseQuery(qs) {
    var out = {};
    qs = String(qs || '');
    if (!qs) return out;
    if (qs.charAt(0) === '?') qs = qs.slice(1);

    qs.split('&').forEach(function (part) {
      if (!part) return;
      var i = part.indexOf('=');
      var k = i === -1 ? part : part.slice(0, i);
      var v = i === -1 ? '' : part.slice(i + 1);
      try {
        k = decodeURIComponent(k.replace(/\+/g, ' '));
        v = decodeURIComponent(v.replace(/\+/g, ' '));
      } catch (e) {}
      if (k) out[k] = v;
    });

    return out;
  }

  function mergeParams(a, b) {
    var out = {};
    Object.keys(a || {}).forEach(function (k) { out[k] = a[k]; });
    Object.keys(b || {}).forEach(function (k) { out[k] = b[k]; });
    return out;
  }

  function parseAjaxSettings(settings) {
    var url = (settings && settings.url) ? String(settings.url) : '';
    var q = {};
    var qm = url.indexOf('?');
    if (qm !== -1) q = parseQuery(url.slice(qm + 1));

    var d = {};
    var data = settings ? settings.data : null;
    if (typeof data === 'string') {
      d = parseQuery(data);
    } else if (data && typeof data === 'object') {
      Object.keys(data).forEach(function (k) {
        d[k] = String(data[k]);
      });
    }

    return mergeParams(q, d);
  }

  function findPostContainer(pid) {
    pid = toInt(pid);
    if (!pid) return null;

    return (
      document.getElementById('post_' + pid) ||
      document.getElementById('pid_' + pid) ||
      document.querySelector('[data-pid="' + pid + '"]') ||
      null
    );
  }

  function updateCsPlaqueInPost(pid, snapshot) {
    pid = toInt(pid);
    if (!pid || !snapshot || !snapshot.success) return;

    var postEl = findPostContainer(pid);
    if (!postEl) return;

    // New selectors (data markers) + fallback на legacy классы
    var plaque = postEl.querySelector('[data-af-balance-plaque="1"]') || postEl.querySelector('.af-cs-postbit-stats') || null;
    if (!plaque) return;

    var creditsDiv = plaque.querySelector('[data-af-balance-credits="1"]') || postEl.querySelector('.af-cs-postbit-stats__credits');
    var levelDiv   = plaque.querySelector('[data-af-balance-level="1"]') || postEl.querySelector('.af-cs-postbit-stats__level');
    var barDiv     = plaque.querySelector('[data-af-balance-expbar="1"]')
      || postEl.querySelector('.af-expbar.af-cs-postbit-stats__bar')
      || postEl.querySelector('.af-cs-postbit-stats__bar.af-expbar');

    // credits
    if (creditsDiv) {
      var icon = creditsDiv.querySelector('i');
      var iconHtml = icon ? icon.outerHTML : '';
      var cr = toStr(snapshot.credits_display);
      var sym = toStr(snapshot.currency_symbol);
      // Сохраняем иконку, перерисовываем текст
      creditsDiv.innerHTML = (iconHtml ? iconHtml + ': ' : '') + cr + (sym ? ' ' + sym : '');
    }

    // level
    if (levelDiv && snapshot.level != null) {
      levelDiv.textContent = 'Level ' + toInt(snapshot.level);
    }

    // exp bar
    if (barDiv) {
      var percent = (snapshot.progress_percent != null) ? toInt(snapshot.progress_percent) : null;

      if (percent != null) {
        if (percent < 0) percent = 0;
        if (percent > 100) percent = 100;

        barDiv.setAttribute('aria-valuenow', String(percent));

        var fill = barDiv.querySelector('.af-expbar__fill');
        if (fill) {
          fill.style.width = percent + '%';
        }
      }

      var text = barDiv.querySelector('[data-af-balance-exptext="1"]') || barDiv.querySelector('.af-expbar__text');
      if (text && snapshot.exp_display != null) {
        // У тебя формат "X / Y". Меняем левую часть, правую оставляем как было.
        var old = toStr(text.textContent).trim();
        var expNow = toStr(snapshot.exp_display).trim();

        if (old.indexOf('/') !== -1) {
          var parts = old.split('/');
          var right = parts.slice(1).join('/').trim();
          text.textContent = expNow + ' / ' + right;
        } else {
          text.textContent = expNow;
        }
      }
    }
  }

  function applySnapshot(snapshot) {
    if (!snapshot || !snapshot.success) return;
    var pid = toInt(snapshot.pid);

    // Главное: обновляем внутри отредактированного поста (т.к. разметка без uid-атрибутов)
    if (pid) {
      updateCsPlaqueInPost(pid, snapshot);
    }
  }

  var inFlight = Object.create(null);

  function fetchSnapshot(pid) {
    pid = toInt(pid);
    if (!pid) return;

    if (inFlight[pid]) return;
    inFlight[pid] = 1;

    var url = 'xmlhttp.php?action=af_balance_snapshot&pid=' + encodeURIComponent(String(pid)) + '&_ts=' + Date.now();

    fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (data) {
        inFlight[pid] = 0;
        applySnapshot(data);
      })
      .catch(function () {
        inFlight[pid] = 0;
      });
  }

  function hookQuickEdit() {
    if (!window.jQuery) return;
    if (window.__afBalanceQuickEditHooked) return;
    window.__afBalanceQuickEditHooked = true;

    var $ = window.jQuery;

    $(document).ajaxComplete(function (event, xhr, settings) {
      try {
        if (!settings || !settings.url) return;

        var url = String(settings.url || '');
        if (url.indexOf('xmlhttp.php') === -1) return;

        var p = parseAjaxSettings(settings);
        if (String(p.action || '') !== 'edit_post') return;

        var d = String(p.do || '');
        if (d !== 'update_post' && d !== 'update') return;

        var pid = toInt(p.pid || p.post_id || 0);
        if (!pid) return;

        // После quick edit: подтягиваем свежий баланс и обновляем постбит
        fetchSnapshot(pid);
      } catch (e) {}
    });
  }

  hookQuickEdit();

  // =========================
  // Ниже — твоя manage-модалка
  // =========================

  if (!window.__afBalanceManageInit) {
    window.__afBalanceManageInit = true;

    var modal = document.querySelector('[data-af-balance-modal]');
    if (!modal) return;

    var elError = modal.querySelector('[data-af-balance-error]');
    var elUid = modal.querySelector('[data-af-balance-uid]');
    var elAmount = modal.querySelector('[data-af-balance-amount]');
    var elReason = modal.querySelector('[data-af-balance-reason]');
    var elApplyBtn = modal.querySelector('[data-af-balance-apply]');

    if (typeof window.__afBalanceManageBusy === 'undefined') {
      window.__afBalanceManageBusy = false;
    }

    function setBusy(on) {
      window.__afBalanceManageBusy = !!on;
      if (elApplyBtn) elApplyBtn.disabled = window.__afBalanceManageBusy;
    }

    function err(msg) {
      if (!elError) return;
      elError.textContent = msg || '';
      elError.style.display = (msg && msg !== '') ? 'block' : '';
    }

    function open(uid) {
      modal.hidden = false;
      if (elUid) elUid.value = String(uid || '');
      if (elAmount) elAmount.value = '';
      if (elReason) elReason.value = '';

      var add = modal.querySelector('input[name="af-balance-op"][value="add"]');
      if (add) add.checked = true;

      setBusy(false);
      err('');
    }

    function close() {
      modal.hidden = true;
      setBusy(false);
      err('');
    }

    function getOp() {
      var checked = modal.querySelector('input[name="af-balance-op"]:checked');
      var v = checked ? String(checked.value || '') : 'add';
      return (v === 'sub') ? 'sub' : 'add';
    }

    function parseJsonFromPossiblyDirtyResponse(text) {
      if (!text) return null;
      try { return JSON.parse(text); } catch (e) {}

      var i = text.indexOf('{');
      if (i === -1) return null;

      var slice = text.slice(i).trim();
      var last = slice.lastIndexOf('}');
      if (last !== -1) slice = slice.slice(0, last + 1);

      try { return JSON.parse(slice); } catch (e2) { return null; }
    }

    function snippet(text, maxLen) {
      var t = String(text || '');
      t = t.replace(/\s+/g, ' ').trim();
      return t.length > maxLen ? t.slice(0, maxLen) + '…' : t;
    }

    function makeReqId() {
      return String(Date.now()) + '_' + Math.random().toString(16).slice(2);
    }

    function applyAdjust() {
      if (window.__afBalanceManageBusy) return;

      var uid = elUid ? String(elUid.value || '').trim() : '';
      var amountRaw = elAmount ? String(elAmount.value || '').trim() : '';
      var reason = elReason ? String(elReason.value || '').trim() : '';
      var op = getOp();

      if (!uid || !/^\d+$/.test(uid)) {
        err('Некорректный UID');
        return;
      }

      var amount = parseFloat(amountRaw.replace(',', '.'));
      if (!isFinite(amount) || amount <= 0) {
        err('Укажи сумму больше 0');
        return;
      }

      var cfg = window.afBalanceConfig || {};
      var kind = (cfg.kind === 'credits') ? 'credits' : 'exp';
      var postKey = String(cfg.postKey || '');

      var fd = new FormData();
      fd.append('my_post_key', postKey);
      fd.append('uid', uid);
      fd.append('amount', String(amount));
      fd.append('reason', reason);
      fd.append('op', op);
      fd.append('kind', kind);

      var reqId = makeReqId();
      fd.append('req_id', reqId);

      err('');
      setBusy(true);

      fetch('misc.php?action=balance_manage&do=adjust&ajax=1', {
        method: 'POST',
        credentials: 'same-origin',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (r) {
          return r.text().then(function (t) {
            var data = parseJsonFromPossiblyDirtyResponse(t);
            if (!data) {
              err('Не JSON ответ (' + r.status + '). ' + snippet(t, 260));
              return null;
            }
            if (!r.ok && data && data.error) {
              err(String(data.error));
              return null;
            }
            return data;
          });
        })
        .then(function (data) {
          setBusy(false);
          if (!data) return;

          if (!data.success) {
            err(data.error || 'Ошибка');
            return;
          }

          var row = document.querySelector('[data-af-balance-row="' + data.uid + '"]');
          if (row) {
            var expEl = row.querySelector('[data-af-balance-exp]');
            if (expEl && typeof data.exp_display !== 'undefined') expEl.textContent = data.exp_display;

            var crEl = row.querySelector('[data-af-balance-credits]');
            if (crEl && typeof data.credits_display !== 'undefined') crEl.textContent = data.credits_display;

            var lvlEl = row.querySelector('[data-af-balance-level]');
            if (lvlEl && typeof data.level !== 'undefined') lvlEl.textContent = String(data.level);
          }

          close();
        })
        .catch(function (ex) {
          setBusy(false);
          err('Ошибка запроса: ' + (ex && ex.message ? ex.message : 'unknown'));
        });
    }

    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-af-balance-adjust]');
      if (btn) {
        e.preventDefault();
        e.stopPropagation();
        var uid2 = btn.getAttribute('data-uid') || '';
        open(uid2);
        return;
      }

      if (e.target.closest('[data-af-balance-close]')) {
        e.preventDefault();
        e.stopPropagation();
        close();
        return;
      }

      if (e.target.closest('[data-af-balance-apply]')) {
        e.preventDefault();
        e.stopPropagation();
        applyAdjust();
        return;
      }
    }, true);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal && !modal.hidden) {
        close();
      }
    });
  }
})();

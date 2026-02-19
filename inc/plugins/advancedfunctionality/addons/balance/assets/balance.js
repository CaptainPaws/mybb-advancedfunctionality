(function () {
  'use strict';

  // ====== ГЛОБАЛЬНЫЙ ГАРД: если скрипт подключили дважды — второй раз не запускаемся
  if (window.__afBalanceManageInit) return;
  window.__afBalanceManageInit = true;

  var modal = document.querySelector('[data-af-balance-modal]');
  if (!modal) return;

  var elError = modal.querySelector('[data-af-balance-error]');
  var elUid = modal.querySelector('[data-af-balance-uid]');
  var elAmount = modal.querySelector('[data-af-balance-amount]');
  var elReason = modal.querySelector('[data-af-balance-reason]');
  var elApplyBtn = modal.querySelector('[data-af-balance-apply]');

  // Глобальный busy — чтобы даже при нескольких обработчиках/скриптах не улетали дубли
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
    // достаточно уникально для защиты от дубля
    return String(Date.now()) + '_' + Math.random().toString(16).slice(2);
  }

  function applyAdjust() {
    // ГЛОБАЛЬНЫЙ стоп от дубля
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

    // req_id для идемпотентности на сервере
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
      var uid = btn.getAttribute('data-uid') || '';
      open(uid);
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
  }, true); // capture=true уменьшает шанс двойного срабатывания из-за вложенных обработчиков

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal && !modal.hidden) {
      close();
    }
  });
})();

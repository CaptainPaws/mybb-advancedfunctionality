(function () {
  'use strict';

  function onReady(fn) {
    if (document.readyState !== 'loading') {
      fn();
    } else {
      document.addEventListener('DOMContentLoaded', fn);
    }
  }

  function postForm(url, data) {
    var body = new URLSearchParams();

    Object.keys(data || {}).forEach(function (key) {
      body.append(key, data[key] == null ? '' : String(data[key]));
    });

    return fetch(String(url), {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: body.toString()
    }).then(function (res) {
      return res.text().then(function (text) {
        var parsed = null;
        try {
          parsed = JSON.parse(text);
        } catch (e) {}

        if (!res.ok) {
          var err = parsed && (parsed.error || parsed.message)
            ? (parsed.error || parsed.message)
            : ('HTTP ' + res.status);
          throw new Error(err);
        }

        if (!parsed) {
          throw new Error('Invalid JSON response');
        }

        return parsed;
      });
    });
  }

  function appearanceActionUrl(action) {
    return 'shop.php?action=' + encodeURIComponent(action);
  }

  function endpointScript() {
    var path = (window.location && window.location.pathname)
      ? String(window.location.pathname).toLowerCase()
      : '';
    return /\/shop\.php$/.test(path) ? 'shop.php' : 'misc.php';
  }

  function buildActionUrl(action, query) {
    var endpoint = endpointScript();
    if (endpoint === 'shop.php') {
      var params = [];
      if (action) {
        params.push('action=' + encodeURIComponent(action));
      }
      if (query) {
        params.push(query);
      }
      return 'shop.php' + (params.length ? ('?' + params.join('&')) : '');
    }

    return 'misc.php?action=' + encodeURIComponent(action || 'shop') + (query ? ('&' + query) : '');
  }

  function normalizeActionUrl(url) {
    if (typeof url !== 'string') {
      return url;
    }
    if (url.indexOf('misc.php?action=') !== 0) {
      return url;
    }

    var raw = url.slice('misc.php?action='.length);
    var ampIndex = raw.indexOf('&');
    var action = ampIndex === -1 ? raw : raw.slice(0, ampIndex);
    var query = ampIndex === -1 ? '' : raw.slice(ampIndex + 1);

    return buildActionUrl(decodeURIComponent(action || 'shop'), query);
  }

  function getPostKey(root) {
    var doc = root && root.ownerDocument ? root.ownerDocument : document;
    var node = doc.querySelector('input[name="my_post_key"]');
    if (node && node.value) {
      return node.value || '';
    }
    if (typeof window.my_post_key === 'string' && window.my_post_key) {
      return window.my_post_key;
    }
    if (typeof window.mybb_post_code === 'string' && window.mybb_post_code) {
      return window.mybb_post_code;
    }
    return '';
  }

  function apiActionUrl(base, action) {
    if (!base) {
      base = 'inventory.php';
    }
    if (base.indexOf('?') === -1) {
      return base + '?action=' + encodeURIComponent(action);
    }
    return base + '&action=' + encodeURIComponent(action);
  }

  function clearMessage(page) {
    if (!page) {
      return;
    }

    var state = page.__afInvFlashState || null;
    if (state && state.timerId) {
      window.clearTimeout(state.timerId);
      state.timerId = 0;
    }

    page.querySelectorAll('.af-inv-flash').forEach(function (node) {
      node.remove();
    });

    page.__afInvFlashState = {
      timerId: 0,
      box: null
    };
  }

  function showMessage(page, text, isError, options) {
    if (!page) {
      return;
    }

    var opts = options || {};
    var state = page.__afInvFlashState || { timerId: 0, box: null };
    if (state.timerId) {
      window.clearTimeout(state.timerId);
      state.timerId = 0;
    }

    var host = page.querySelector('.af-inv-tab-content') || page;
    var box = host ? host.parentNode.querySelector(':scope > .af-inv-flash') : null;
    if (!box) {
      page.querySelectorAll('.af-inv-flash').forEach(function (node) {
        node.remove();
      });
      box = document.createElement('div');
      box.className = 'af-inv-flash';
      if (host && host.parentNode) {
        host.parentNode.insertBefore(box, host);
      } else {
        page.insertBefore(box, page.firstChild || null);
      }
    }

    box.className = 'af-inv-flash ' + (isError ? 'is-error' : 'is-success');
    box.textContent = text;
    box.removeAttribute('hidden');
    box.classList.remove('is-hiding');

    state.box = box;
    if (opts.autohide) {
      state.timerId = window.setTimeout(function () {
        if (!state.box || !state.box.parentNode) {
          return;
        }
        state.box.classList.add('is-hiding');
        window.setTimeout(function () {
          if (state.box && state.box.parentNode) {
            state.box.remove();
          }
          if (page.__afInvFlashState === state) {
            page.__afInvFlashState = { timerId: 0, box: null };
          }
        }, 220);
        state.timerId = 0;
      }, typeof opts.delay === 'number' ? opts.delay : 3000);
    }

    page.__afInvFlashState = state;
  }

  function withLoading(btn, promise) {
    if (btn) {
      btn.disabled = true;
      btn.dataset.loading = '1';
    }
    return promise.finally(function () {
      if (btn) {
        btn.disabled = false;
        btn.removeAttribute('data-loading');
      }
    });
  }

  function setPanelBusy(panel, isBusy) {
    if (!panel) {
      return;
    }
    panel.classList.toggle('is-loading', !!isBusy);
  }

  function activateSlot(panel, itemId) {
    if (!panel) {
      return;
    }

    var targetId = String(itemId || '');
    var firstSlot = panel.querySelector('[data-item-select]');
    if (!targetId && firstSlot) {
      targetId = firstSlot.getAttribute('data-item-select') || '';
    }

    panel.querySelectorAll('[data-item-select]').forEach(function (slot) {
      var active = (slot.getAttribute('data-item-select') || '') === targetId;
      slot.classList.toggle('is-selected', active);
      slot.setAttribute('aria-pressed', active ? 'true' : 'false');
    });

    panel.querySelectorAll('[data-preview-item]').forEach(function (card) {
      var active = (card.getAttribute('data-preview-item') || '') === targetId;
      card.classList.toggle('is-active', active);
      card.hidden = !active;
    });
  }

  function updateWallet(page, wallet) {
    if (!page || !wallet) {
      return;
    }
    var balanceNode = page.querySelector('[data-af-wallet-balance]');
    var symbolNode = page.querySelector('[data-af-wallet-symbol]');
    if (balanceNode && wallet.balance_major != null) {
      balanceNode.textContent = wallet.balance_major;
    }
    if (symbolNode && wallet.currency_symbol != null) {
      symbolNode.textContent = wallet.currency_symbol;
    }
  }

  function inventoryContext(page) {
    var panel = page.querySelector('#af-inv-panel');
    var api = panel ? panel.querySelector('.af-inv-api') : null;
    return {
      page: page,
      panel: panel,
      api: api,
      uid: api ? (api.getAttribute('data-owner') || page.getAttribute('data-owner') || '0') : (page.getAttribute('data-owner') || '0'),
      apiBase: api ? (api.getAttribute('data-api-base') || 'inventory.php') : 'inventory.php',
      currentKey: page.dataset.currentKey || '',
      currentUrl: page.dataset.currentUrl || ''
    };
  }

  function normalizeKeyFromUrl(url) {
    try {
      var parsed = new URL(url, window.location.origin);
      var entity = parsed.searchParams.get('entity') || parsed.searchParams.get('tab') || 'equipment';
      var sub = parsed.searchParams.get('sub') || 'all';
      return entity + ':' + sub;
    } catch (e) {
      return '';
    }
  }

  function bindPanelInteractions(page, panel) {
    if (!panel || panel.dataset.afInvBound === '1') {
      activateSlot(panel, '');
      return;
    }
    panel.dataset.afInvBound = '1';

    panel.addEventListener('submit', function (e) {
      var appearanceUnapplyForm = e.target.closest('[data-af-appearance-unapply-form]');
      if (!appearanceUnapplyForm || !panel.contains(appearanceUnapplyForm)) {
        return;
      }

      e.preventDefault();

      var appearanceUnapplyBtn = appearanceUnapplyForm.querySelector('[data-af-appearance-unapply-btn]');
      var ctxUn = inventoryContext(page);
      withLoading(appearanceUnapplyBtn || appearanceUnapplyForm, postForm(appearanceActionUrl('inventory_appearance_unapply'), {
        uid: ctxUn.uid,
        target_key: (appearanceUnapplyForm.querySelector('input[name=\"target_key\"]') || {}).value || '',
        my_post_key: getPostKey(page)
      })).then(function () {
        showMessage(page, 'Пресет снят.', false, { autohide: true });
        page.__afInvReloadCurrent();
      }).catch(function (err) {
        showMessage(page, err.message || 'Не удалось снять пресет.', true, { autohide: true });
      });
    });

    panel.addEventListener('click', function (e) {
      var slot = e.target.closest('[data-item-select]');
      if (slot && panel.contains(slot)) {
        e.preventDefault();
        activateSlot(panel, slot.getAttribute('data-item-select') || '');
        return;
      }

      var filterLink = e.target.closest('.af-inv-subfilter');
      if (filterLink && panel.contains(filterLink)) {
        e.preventDefault();
        page.__afInvLoadUrl(filterLink.getAttribute('href') || '', normalizeKeyFromUrl(filterLink.getAttribute('href') || ''));
        return;
      }

      var appearanceApplyBtn = e.target.closest('[data-af-appearance-apply-btn][data-item-id]');
      if (appearanceApplyBtn && panel.contains(appearanceApplyBtn)) {
        e.preventDefault();
        var ctxApply = inventoryContext(page);
        withLoading(appearanceApplyBtn, postForm(appearanceActionUrl('inventory_appearance_apply'), {
          uid: ctxApply.uid,
          item_id: appearanceApplyBtn.getAttribute('data-item-id') || '0',
          inv_id: appearanceApplyBtn.getAttribute('data-item-id') || '0',
          my_post_key: getPostKey(page)
        })).then(function () {
          showMessage(page, 'Пресет применён.', false, { autohide: true });
          page.__afInvReloadCurrent();
        }).catch(function (err) {
          showMessage(page, err.message || 'Не удалось применить пресет.', true, { autohide: true });
        });
        return;
      }

      var actionBtn = e.target.closest('.af-inv-action[data-action][data-item-id]');
      if (!actionBtn || !panel.contains(actionBtn)) {
        return;
      }

      var action = actionBtn.getAttribute('data-action') || '';
      if (['update', 'delete', 'equip', 'unequip', 'sell', 'bind_support_slot', 'unbind_support_slot'].indexOf(action) === -1) {
        return;
      }

      e.preventDefault();

      var ctx = inventoryContext(page);
      if (action === 'bind_support_slot') {
        openSupportSlotPicker(page, actionBtn, ctx);
        return;
      }
      var payload = {
        uid: ctx.uid,
        item_id: actionBtn.getAttribute('data-item-id') || '0',
        my_post_key: getPostKey(page)
      };

      var card = actionBtn.closest('[data-preview-item]');
      var qtyInput = card ? card.querySelector('.af-inv-qty') : null;
      var qtyValue = qtyInput ? parseInt(qtyInput.value || '1', 10) : 1;
      var qtyMax = qtyInput ? parseInt(qtyInput.getAttribute('data-max-qty') || qtyInput.getAttribute('max') || '0', 10) : 0;
      if (!qtyValue || qtyValue < 1) {
        qtyValue = 1;
      }
      if (qtyMax > 0 && qtyValue > qtyMax) {
        qtyValue = qtyMax;
      }
      if (qtyInput) {
        qtyInput.value = String(qtyValue);
      }

      if (action === 'update' || action === 'sell') {
        payload.qty = String(qtyValue);
      }

      if (action === 'equip' || action === 'unequip') {
        payload.equip_slot = actionBtn.getAttribute('data-equip-slot') || '';
      }
      if (action === 'unbind_support_slot') {
        payload.slot_code = actionBtn.getAttribute('data-slot-code') || '';
      }

      withLoading(actionBtn, postForm(apiActionUrl(ctx.apiBase, 'api_' + action), payload))
        .then(function (res) {
          if (action === 'sell') {
            updateWallet(page, res.wallet || null);
            showMessage(page, 'Продано: ' + (res.sold_qty || payload.qty || '1') + ' шт. Баланс пополнен на ' + (res.sold_major || '0') + ' ' + (res.currency_symbol || '') + '.', false, { autohide: true });
          } else if (action === 'delete') {
            showMessage(page, 'Предмет удалён.', false, { autohide: true });
          } else if (action === 'update') {
            showMessage(page, 'Изменения сохранены.', false, { autohide: true });
          } else if (action === 'equip') {
            showMessage(page, 'Предмет надет.', false, { autohide: true });
          } else if (action === 'unequip') {
            showMessage(page, 'Предмет снят.', false, { autohide: true });
          } else if (action === 'unbind_support_slot') {
            showMessage(page, 'Предмет убран из быстрого слота.', false, { autohide: true });
          }
          page.__afInvReloadCurrent();
        })
        .catch(function (err) {
          showMessage(page, err.message || 'Не удалось выполнить действие.', true, { autohide: true });
        });
    });

    activateSlot(panel, '');
  }


  function supportSlotModal(page) {
    if (page.__afInvSupportModal) {
      return page.__afInvSupportModal;
    }

    var overlay = document.createElement('div');
    overlay.className = 'af-inv-support-modal';
    overlay.setAttribute('hidden', 'hidden');
    overlay.innerHTML = ''
      + '<div class="af-inv-support-modal__backdrop" data-support-close="1"></div>'
      + '<div class="af-inv-support-modal__dialog" role="dialog" aria-modal="true" aria-label="Выбор быстрого слота">'
      + '<div class="af-inv-support-modal__header"><strong>Выберите быстрый слот</strong><button type="button" class="af-inv-action" data-support-close="1">Закрыть</button></div>'
      + '<div class="af-inv-support-modal__body"></div>'
      + '</div>';
    page.appendChild(overlay);
    overlay.addEventListener('click', function (e) {
      if (e.target && e.target.getAttribute('data-support-close') === '1') {
        overlay.setAttribute('hidden', 'hidden');
      }
      var bindBtn = e.target.closest('[data-support-bind-slot]');
      if (!bindBtn) return;
      var pending = overlay.__pendingBind || null;
      if (!pending) return;
      var payload = {
        uid: pending.uid,
        item_id: pending.itemId,
        slot_code: bindBtn.getAttribute('data-support-bind-slot') || '',
        my_post_key: pending.postKey
      };
      withLoading(bindBtn, postForm(apiActionUrl(pending.apiBase, 'api_bind_support_slot'), payload))
        .then(function () {
          overlay.setAttribute('hidden', 'hidden');
          showMessage(page, 'Предмет добавлен в быстрый слот.', false, { autohide: true });
          if (typeof page.__afInvReloadCurrent === 'function') {
            page.__afInvReloadCurrent();
          }
        })
        .catch(function (err) {
          showMessage(page, err.message || 'Не удалось назначить быстрый слот.', true, { autohide: true });
        });
    });
    page.__afInvSupportModal = overlay;
    return overlay;
  }

  function openSupportSlotPicker(page, actionBtn, ctx) {
    var modal = supportSlotModal(page);
    var body = modal.querySelector('.af-inv-support-modal__body');
    body.innerHTML = '<div class="af-inv-muted">Загрузка слотов…</div>';
    modal.__pendingBind = {
      uid: ctx.uid,
      itemId: actionBtn.getAttribute('data-item-id') || '0',
      postKey: getPostKey(page),
      apiBase: ctx.apiBase
    };
    modal.removeAttribute('hidden');

    fetch(apiActionUrl(ctx.apiBase, 'api_support_slots_state') + '&uid=' + encodeURIComponent(ctx.uid), { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (res) {
        if (!res || res.ok === false) {
          throw new Error((res && res.error) || 'Не удалось загрузить слоты.');
        }
        var preferred = actionBtn.getAttribute('data-slot-code') || '';
        var html = '';
        (res.slots || []).forEach(function (slot) {
          var isCurrent = String(slot.item_id || '0') === String(modal.__pendingBind.itemId || '');
          var isPreferred = preferred && preferred === String(slot.slot_code || '');
          html += '<button type="button" class="af-inv-action" data-support-bind-slot="' + escapeHtml(String(slot.slot_code || '')) + '">'
            + escapeHtml(String(slot.title || slot.slot_code || 'Слот'))
            + (slot.legacy_slot ? ' <small>(' + escapeHtml(String(slot.legacy_slot)) + ')</small>' : '')
            + (isCurrent ? ' — уже выбран' : '')
            + (slot.item_title ? ' — ' + escapeHtml(String(slot.item_title)) + ' x' + escapeHtml(String(slot.qty_bound || 0)) : ' — пусто')
            + (isPreferred ? ' — рекомендуется' : '')
            + '</button>';
        });
        body.innerHTML = html || '<div class="af-inv-muted">Нет доступных support slots.</div>';
      })
      .catch(function (err) {
        body.innerHTML = '<div class="af-inv-muted">' + escapeHtml(err.message || 'Ошибка загрузки.') + '</div>';
      });
  }

  onReady(function () {
    if (!document.getElementById('af-inv-support-modal-style')) {
      var style = document.createElement('style');
      style.id = 'af-inv-support-modal-style';
      style.textContent = '.af-inv-support-modal{position:fixed;inset:0;z-index:9999}.af-inv-support-modal[hidden]{display:none}.af-inv-support-modal__backdrop{position:absolute;inset:0;background:rgba(0,0,0,.45)}.af-inv-support-modal__dialog{position:relative;max-width:560px;margin:8vh auto;background:#111827;color:#fff;border:1px solid rgba(255,255,255,.15);border-radius:16px;padding:16px;display:flex;flex-direction:column;gap:12px}.af-inv-support-modal__header{display:flex;justify-content:space-between;align-items:center;gap:12px}.af-inv-support-modal__body{display:flex;flex-direction:column;gap:8px}.af-inv-muted{opacity:.8}';
      document.head.appendChild(style);
    }
    var page = document.querySelector('.af-inv-page');
    if (!page) {
      return;
    }

    var panel = document.getElementById('af-inv-panel');
    if (!panel) {
      return;
    }

    var uid = page.getAttribute('data-owner') || '0';
    var cache = Object.create(null);

    function loadPanelHtml(url, cacheKey, force) {
      if (!url) {
        return Promise.resolve('');
      }
      if (!force && cacheKey && cache[cacheKey]) {
        return Promise.resolve(cache[cacheKey]);
      }
      setPanelBusy(panel, true);
      return fetch(url, { credentials: 'same-origin' })
        .then(function (res) {
          if (!res.ok) {
            throw new Error('HTTP ' + res.status);
          }
          return res.text();
        })
        .then(function (html) {
          if (cacheKey) {
            cache[cacheKey] = html;
          }
          return html;
        })
        .finally(function () {
          setPanelBusy(panel, false);
        });
    }

    function mountPanelHtml(html, cacheKey, url) {
      panel.innerHTML = html;
      page.dataset.currentKey = cacheKey || '';
      page.dataset.currentUrl = url || '';
      bindPanelInteractions(page, panel);
    }

    page.__afInvLoadUrl = function (url, cacheKey, force) {
      var finalKey = cacheKey || normalizeKeyFromUrl(url);
      return loadPanelHtml(url, finalKey, !!force).then(function (html) {
        mountPanelHtml(html, finalKey, url);
      }).catch(function (err) {
        showMessage(page, err.message || 'Не удалось загрузить раздел инвентаря.', true, { autohide: true });
      });
    };

    page.__afInvReloadCurrent = function () {
      var url = page.dataset.currentUrl || '';
      var key = page.dataset.currentKey || '';
      if (!url) {
        var activeTab = page.querySelector('.af-inv-tab.is-active');
        var entity = activeTab ? (activeTab.getAttribute('data-entity') || 'equipment') : 'equipment';
        var reloadBase = page.getAttribute('data-entity-base') || 'inventory.php?action=entity&uid=' + encodeURIComponent(uid);
        var reloadSep = reloadBase.indexOf('?') === -1 ? '?' : '&';
        url = reloadBase + reloadSep + 'entity=' + encodeURIComponent(entity) + '&sub=all&ajax=1';
        key = entity + ':all';
      }
      if (key) {
        delete cache[key];
      }
      return page.__afInvLoadUrl(url, key, true);
    };

    page.querySelectorAll('.af-inv-tab').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        clearMessage(page);
        var entity = btn.getAttribute('data-entity') || btn.getAttribute('data-tab') || 'equipment';
        page.querySelectorAll('.af-inv-tab').forEach(function (el) {
          el.classList.remove('is-active');
        });
        btn.classList.add('is-active');
        var key = entity + ':all';
        var baseUrl = page.getAttribute('data-entity-base') || 'inventory.php?action=entity&uid=' + encodeURIComponent(uid);
        var sep = baseUrl.indexOf('?') === -1 ? '?' : '&';
        var url = baseUrl + sep + 'entity=' + encodeURIComponent(entity) + '&sub=all&ajax=1';
        page.__afInvLoadUrl(url, key, false);
      });
    });

    var defaultEntity = page.getAttribute('data-default-tab') || 'equipment';
    var initialKey = defaultEntity + ':all';
    cache[initialKey] = panel.innerHTML;
    page.dataset.currentKey = initialKey;
    page.dataset.currentUrl = page.getAttribute('data-first-url') || '';
    if (!page.dataset.currentUrl) {
      var initialBase = page.getAttribute('data-entity-base') || 'inventory.php?action=entity&uid=' + encodeURIComponent(uid);
      var initialSep = initialBase.indexOf('?') === -1 ? '?' : '&';
      page.dataset.currentUrl = initialBase + initialSep + 'entity=' + encodeURIComponent(defaultEntity) + '&sub=all&ajax=1';
    }
    bindPanelInteractions(page, panel);
  });
})();

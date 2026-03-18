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

    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
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

  function showMessage(page, text, isError) {
    var host = page.querySelector('.af-inv-tab-content') || page;
    var box = page.querySelector('.af-inv-flash');
    if (!box) {
      box = document.createElement('div');
      box.className = 'af-inv-flash';
      host.parentNode.insertBefore(box, host);
    }
    box.className = 'af-inv-flash ' + (isError ? 'is-error' : 'is-success');
    box.textContent = text;
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
        withLoading(appearanceApplyBtn, postForm('misc.php?action=inventory_appearance_apply', {
          uid: ctxApply.uid,
          item_id: appearanceApplyBtn.getAttribute('data-item-id') || '0',
          inv_id: appearanceApplyBtn.getAttribute('data-item-id') || '0',
          my_post_key: getPostKey(page)
        })).then(function () {
          showMessage(page, 'Пресет активирован.', false);
          page.__afInvReloadCurrent();
        }).catch(function (err) {
          showMessage(page, err.message || 'Не удалось активировать пресет.', true);
        });
        return;
      }

      var appearanceUnapplyBtn = e.target.closest('[data-af-appearance-unapply-btn]');
      if (appearanceUnapplyBtn && panel.contains(appearanceUnapplyBtn)) {
        e.preventDefault();
        var ctxUn = inventoryContext(page);
        withLoading(appearanceUnapplyBtn, postForm('misc.php?action=inventory_appearance_unapply', {
          uid: ctxUn.uid,
          target_key: appearanceUnapplyBtn.getAttribute('data-target-key') || '',
          my_post_key: getPostKey(page)
        })).then(function () {
          showMessage(page, 'Пресет снят.', false);
          page.__afInvReloadCurrent();
        }).catch(function (err) {
          showMessage(page, err.message || 'Не удалось снять пресет.', true);
        });
        return;
      }

      var actionBtn = e.target.closest('.af-inv-action[data-action][data-item-id]');
      if (!actionBtn || !panel.contains(actionBtn)) {
        return;
      }

      var action = actionBtn.getAttribute('data-action') || '';
      if (['update', 'delete', 'equip', 'unequip', 'sell'].indexOf(action) === -1) {
        return;
      }

      e.preventDefault();

      var ctx = inventoryContext(page);
      var payload = {
        uid: ctx.uid,
        item_id: actionBtn.getAttribute('data-item-id') || '0',
        my_post_key: getPostKey(page)
      };

      if (action === 'update') {
        var card = actionBtn.closest('[data-preview-item]');
        var qtyInput = card ? card.querySelector('.af-inv-qty') : null;
        payload.qty = qtyInput ? (qtyInput.value || '1') : '1';
      }

      if (action === 'equip' || action === 'unequip') {
        payload.equip_slot = actionBtn.getAttribute('data-equip-slot') || '';
      }

      withLoading(actionBtn, postForm(apiActionUrl(ctx.apiBase, 'api_' + action), payload))
        .then(function (res) {
          if (action === 'sell') {
            updateWallet(page, res.wallet || null);
            showMessage(page, (res.message || 'Предмет продан.') + ' +' + (res.sold_major || '0') + ' ' + (res.currency_symbol || ''), false);
          } else if (action === 'delete') {
            showMessage(page, 'Предмет удалён.', false);
          } else if (action === 'update') {
            showMessage(page, 'Изменения сохранены.', false);
          } else if (action === 'equip') {
            showMessage(page, 'Предмет надет.', false);
          } else if (action === 'unequip') {
            showMessage(page, 'Предмет снят.', false);
          }
          page.__afInvReloadCurrent();
        })
        .catch(function (err) {
          showMessage(page, err.message || 'Не удалось выполнить действие.', true);
        });
    });

    activateSlot(panel, '');
  }

  onReady(function () {
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
        showMessage(page, err.message || 'Не удалось загрузить раздел инвентаря.', true);
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

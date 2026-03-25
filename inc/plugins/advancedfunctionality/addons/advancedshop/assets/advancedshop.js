(function(){
  'use strict';

  if (window.__afShopBound) { return; }
  window.__afShopBound = true;
  window.AFSHOP = window.AFSHOP || {};
  window.AFSHOP.loaded = true;

  var CHECKOUT_SUCCESS_STORAGE_KEY = 'af_shop_checkout_success';

  function afShopDebugEnabled() {
    if (window.AFSHOP_DEBUG === true) { return true; }
    try {
      return (new URLSearchParams(window.location.search)).get('af_debug') === '1';
    } catch (err) {
      return false;
    }
  }

  function afShopDebug() {
    if (!afShopDebugEnabled() || !window.console || typeof window.console.log !== 'function') { return; }
    var args = Array.prototype.slice.call(arguments);
    args.unshift('[AFSHOP]');
    window.console.log.apply(window.console, args);
  }

  function afShopWarn() {
    if (!window.console || typeof window.console.warn !== 'function') { return; }
    var args = Array.prototype.slice.call(arguments);
    args.unshift('[AFSHOP]');
    window.console.warn.apply(window.console, args);
  }

  function afShopEndpointScript() {
    if (window.AFSHOP && window.AFSHOP.endpointScript) {
      return window.AFSHOP.endpointScript;
    }
    var path = (window.location && window.location.pathname) ? String(window.location.pathname).toLowerCase() : '';
    if (/\/shop\.php$/.test(path)) {
      return 'shop.php';
    }
    return 'misc.php';
  }

  function buildActionUrl(action, query) {
    var endpoint = afShopEndpointScript();
    if (endpoint === 'shop.php') {
      var params = [];
      if (action && action !== 'shop' && action !== 'view') {
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
    if (typeof url !== 'string') { return url; }
    if (url.indexOf('misc.php?action=') !== 0) { return url; }

    var raw = url.slice('misc.php?action='.length);
    var amp = raw.indexOf('&');
    var action = amp === -1 ? raw : raw.slice(0, amp);
    var query = amp === -1 ? '' : raw.slice(amp + 1);
    return buildActionUrl(decodeURIComponent(action || 'shop'), query);
  }

  function parseJSON(text) {
    try {
      return JSON.parse(text);
    } catch (e) {
      afShopWarn('Invalid JSON response', text);
      return { ok: false, error: 'Invalid JSON response' };
    }
  }

  function key() {
    if (window.AFSHOP && window.AFSHOP.postKey) { return window.AFSHOP.postKey; }
    var cfg = document.getElementById('af_shop_post_key');
    if (cfg && cfg.value) { return cfg.value; }
    return window.my_post_key || '';
  }

  function post(url, data) {
    url = normalizeActionUrl(url);
    data = data || {};
    if (!data.my_post_key) {
      data.my_post_key = key();
    }

    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: new URLSearchParams(data)
    })
      .then(function(r){ return r.text(); })
      .then(parseJSON);
  }

  function getJSON(url) {
    url = normalizeActionUrl(url);
    return fetch(url, { credentials: 'same-origin' })
      .then(function(r){ return r.text(); })
      .then(parseJSON);
  }

  function escapeHtml(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function(ch){
      return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[ch];
    });
  }

  function setStatus(node, message, ok) {
    if (!node) { return; }
    node.textContent = String(message || '');
    node.classList.remove('af-status-ok', 'af-status-error');
    if (!message) { return; }
    node.classList.add(ok ? 'af-status-ok' : 'af-status-error');
  }

  function treeStorageKey(shop, catId) {
    return 'af_shop_tree_' + String(shop || 'game') + '_' + String(catId || 0);
  }

  function applyCatState(toggle, collapsed) {
    var catId = toggle.getAttribute('data-cat');
    var root = toggle.closest('.af-shop-cat-node');
    var children = root ? root.querySelector(':scope > .af-cat-children[data-parent="' + catId + '"]') : null;
    if (!root || !children) { return; }

    root.classList.toggle('is-collapsed', !!collapsed);
    root.classList.toggle('is-expanded', !collapsed);
    children.hidden = !!collapsed;
    toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

    var icon = toggle.querySelector('.af-cat-toggle__icon');
    if (icon) {
      icon.textContent = collapsed ? '▸' : '▾';
    }
  }

  function initCategoryTree() {
    var shopRoot = document.querySelector('.af-shop-wrap[data-shop]');
    if (!shopRoot) { return; }

    var shop = shopRoot.getAttribute('data-shop') || 'game';
    shopRoot.querySelectorAll('.af-cat-toggle[data-cat]').forEach(function(toggle) {
      var catId = toggle.getAttribute('data-cat');
      var st = '';
      try {
        st = localStorage.getItem(treeStorageKey(shop, catId)) || '';
      } catch (err) {
        st = '';
      }
      applyCatState(toggle, st === 'collapsed');
    });
  }

  window.afShopToast = function(msg, type) {
    var root = document.querySelector('.af-shop-toasts');
    if (!root) { return; }

    var item = document.createElement('div');
    item.className = 'af-shop-toast af-shop-toast--' + (type === 'error' ? 'error' : 'success');
    item.textContent = msg || '';
    root.appendChild(item);

    setTimeout(function(){ item.classList.add('is-hide'); }, 2200);
    setTimeout(function(){ if (item.parentNode) { item.parentNode.removeChild(item); } }, 2800);
  };

  function afShopModalRoot() {
    var modal = document.querySelector('[data-af-shop-modal]');
    if (modal) {
      return modal;
    }

    modal = document.createElement('div');
    modal.className = 'af-shop-modal';
    modal.hidden = true;
    modal.setAttribute('data-af-shop-modal', '1');
    modal.innerHTML = ''
      + '<div class="af-shop-modal__overlay"></div>'
      + '<div class="af-shop-modal__dialog" role="dialog" aria-modal="true" aria-label="Покупка завершена">'
      + '  <button type="button" class="af-shop-modal__close" aria-label="Закрыть">×</button>'
      + '  <div data-af-shop-modal-content></div>'
      + '</div>';

    var overlay = modal.querySelector('.af-shop-modal__overlay');
    var closeBtn = modal.querySelector('.af-shop-modal__close');

    if (overlay) {
      overlay.addEventListener('click', function() {
        afShopHideCheckoutSuccessModal();
      });
    }

    if (closeBtn) {
      closeBtn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        afShopHideCheckoutSuccessModal();
      });
    }

    document.body.appendChild(modal);
    return modal;
  }

  function showInventoryModal(html) {
    var modal = afShopModalRoot();
    if (!modal) { return; }

    var content = modal.querySelector('[data-af-shop-modal-content]');
    if (content) {
      content.innerHTML = html;
    }

    modal.hidden = false;
    document.body.classList.add('af-shop-modal-open');
  }

  function afShopHideCheckoutSuccessModal() {
    var modal = document.querySelector('[data-af-shop-modal]');
    if (!modal) { return; }

    modal.hidden = true;
    document.body.classList.remove('af-shop-modal-open');

    var content = modal.querySelector('[data-af-shop-modal-content]');
    if (content) {
      content.innerHTML = '';
    }
  }

  function normalizeCheckoutPayload(payload) {
    var data = payload || {};
    var checkout = data.checkout || {};
    var links = data.links || {};
    var items = [];

    if (Array.isArray(data.items)) {
      items = data.items;
    } else if (Array.isArray(checkout.items)) {
      items = checkout.items;
    }

    return {
      checkout: checkout,
      links: links,
      items: items
    };
  }

  function checkoutItemIconUrl(item) {
    if (!item || typeof item !== 'object') { return ''; }

    var meta = item.meta && typeof item.meta === 'object' ? item.meta : {};
    var ui = meta.ui && typeof meta.ui === 'object' ? meta.ui : {};

    return String(
      item.icon_url ||
      item.preview_image ||
      item.image ||
      item.image_url ||
      item.icon ||
      item.thumb ||
      item.thumbnail ||
      item.thumb_url ||
      item.img ||
      item.picture ||
      item.cover ||
      item.cover_url ||
      meta.icon_url ||
      meta.preview_image ||
      ui.icon_url ||
      ''
    );
  }

  function normalizeTitleForMatch(value) {
    return String(value == null ? '' : value)
      .replace(/\s+/g, ' ')
      .trim()
      .toLowerCase();
  }

  function collectCheckoutIconsFromDom() {
    var items = [];
    var seen = Object.create(null);

    function pushItem(title, iconUrl) {
      var normalizedTitle = normalizeTitleForMatch(title);
      var normalizedIcon = String(iconUrl || '').trim();

      if (!normalizedTitle || !normalizedIcon) { return; }

      var key = normalizedTitle + '|' + normalizedIcon;
      if (seen[key]) { return; }
      seen[key] = true;

      items.push({
        title: String(title || '').trim(),
        title_key: normalizedTitle,
        icon_url: normalizedIcon
      });
    }

    document.querySelectorAll('.af-cart-item').forEach(function(node) {
      var img = node.querySelector('img');
      if (!img || !img.getAttribute('src')) { return; }

      var titleNode =
        node.querySelector('.af-cart-item__title') ||
        node.querySelector('.af-cart-item__name') ||
        node.querySelector('.af-cart-item__meta strong') ||
        node.querySelector('strong') ||
        node.querySelector('[data-item-title]');

      var title = '';
      if (titleNode) {
        title = titleNode.getAttribute && titleNode.getAttribute('data-item-title')
          ? titleNode.getAttribute('data-item-title')
          : (titleNode.textContent || '');
      }

      pushItem(title, img.getAttribute('src'));
    });

    document.querySelectorAll('.af-shop-card').forEach(function(node) {
      var img = node.querySelector('.af-shop-card__icon img');
      if (!img || !img.getAttribute('src')) { return; }

      var titleNode =
        node.querySelector('.af-shop-card__title') ||
        node.querySelector('h3') ||
        node.querySelector('strong') ||
        node.querySelector('[data-item-title]');

      var title = '';
      if (titleNode) {
        title = titleNode.getAttribute && titleNode.getAttribute('data-item-title')
          ? titleNode.getAttribute('data-item-title')
          : (titleNode.textContent || '');
      }

      pushItem(title, img.getAttribute('src'));
    });

    return items;
  }

  function enrichCheckoutItemsWithDomIcons(payload) {
    var data = normalizeCheckoutPayload(payload);
    var domItems = collectCheckoutIconsFromDom();

    if (!Array.isArray(data.items) || !data.items.length || !domItems.length) {
      return data;
    }

    var byTitle = Object.create(null);
    domItems.forEach(function(entry) {
      if (!entry || !entry.title_key || !entry.icon_url) { return; }
      if (!byTitle[entry.title_key]) {
        byTitle[entry.title_key] = entry.icon_url;
      }
    });

    data.items = data.items.map(function(item, index) {
      var next = Object.assign({}, item || {});
      var existingIcon = checkoutItemIconUrl(next);
      if (existingIcon) {
        next.icon_url = existingIcon;
        return next;
      }

      var titleKey = normalizeTitleForMatch(next.title || '');
      if (titleKey && byTitle[titleKey]) {
        next.icon_url = byTitle[titleKey];
        return next;
      }

      if (domItems[index] && domItems[index].icon_url) {
        next.icon_url = domItems[index].icon_url;
      }

      return next;
    });

    return data;
  }

  function persistCheckoutSuccess(payload) {
    try {
      var enriched = enrichCheckoutItemsWithDomIcons(payload);
      sessionStorage.setItem(CHECKOUT_SUCCESS_STORAGE_KEY, JSON.stringify(enriched));
    } catch (err) {
      afShopWarn('Не удалось сохранить checkout success payload', err);
    }
  }

  function clearCheckoutSuccess() {
    try {
      sessionStorage.removeItem(CHECKOUT_SUCCESS_STORAGE_KEY);
    } catch (err) {}
  }

  function buildCheckoutSuccessHtml(payload) {
    var data = normalizeCheckoutPayload(payload);
    var checkout = data.checkout || {};
    var links = data.links || {};
    var items = Array.isArray(data.items) ? data.items : [];

    var currencySymbol = checkout.currency_symbol ? (' ' + escapeHtml(checkout.currency_symbol)) : '';
    var totalMajor = checkout.total_major ? escapeHtml(checkout.total_major) : '0.00';
    var balanceMajor = checkout.balance_major ? escapeHtml(checkout.balance_major) : '0.00';

    var itemsHtml = '';
    if (items.length) {
      itemsHtml = items.map(function(item) {
        var title = item && item.title ? String(item.title) : ('Предмет #' + String(item && item.slot_id ? item.slot_id : '0'));
        var qty = item && item.qty ? String(item.qty) : '1';
        var iconUrl = checkoutItemIconUrl(item);

        var iconHtml = iconUrl
          ? '<div class="af-shop-success__item-icon"><img src="' + escapeHtml(iconUrl) + '" alt="' + escapeHtml(title) + '"></div>'
          : '<div class="af-shop-success__item-icon af-shop-success__item-icon--empty">—</div>';

        return ''
          + '<div class="af-shop-success__item">'
          +   iconHtml
          +   '<div class="af-shop-success__item-main">'
          +     '<div class="af-shop-success__item-title">'
          +       '<span>' + escapeHtml(title) + '</span>'
          +       '<span class="af-shop-success__qty">×' + escapeHtml(qty) + '</span>'
          +     '</div>'
          +   '</div>'
          + '</div>';
      }).join('');
    } else {
      itemsHtml = '<div class="af-shop-success__empty">Товаров в ответе не найдено.</div>';
    }

    var actions = '';
    if (links.shop) {
      actions += '<button type="button" class="af-shop-btn af-shop-btn--primary" data-af-shop-nav="' + escapeHtml(normalizeActionUrl(String(links.shop))) + '">В магазин</button>';
    }
    if (links.inventory) {
      actions += '<button type="button" class="af-shop-btn af-shop-btn--secondary" data-af-shop-nav="' + escapeHtml(String(links.inventory)) + '">В инвентарь</button>';
    }

    return ''
      + '<div class="af-shop-success">'
      +   '<h3 class="af-shop-modal__title">Покупка завершена</h3>'

      +   '<div class="af-shop-success__section">'
      +     '<div class="af-shop-success__label">Куплено:</div>'
      +     '<div class="af-shop-success__items">'
      +       itemsHtml
      +     '</div>'
      +   '</div>'

      +   '<div class="af-shop-success__divider"></div>'

      +   '<div class="af-shop-success__totals">'
      +     '<div class="af-shop-success__row"><span>Списано:</span><strong>' + totalMajor + currencySymbol + '</strong></div>'
      +     '<div class="af-shop-success__row"><span>Баланс:</span><strong>' + balanceMajor + currencySymbol + '</strong></div>'
      +   '</div>'

      +   '<div class="af-shop-success__divider"></div>'

      +   '<div class="af-shop-modal__actions af-shop-success__actions">'
      +     actions
      +   '</div>'
      + '</div>';
  }

  function restoreCheckoutSuccessModal() {
    var raw = '';
    try {
      raw = sessionStorage.getItem(CHECKOUT_SUCCESS_STORAGE_KEY) || '';
    } catch (err) {
      raw = '';
    }
    if (!raw) { return; }

    clearCheckoutSuccess();

    var payload = null;
    try {
      payload = JSON.parse(raw);
    } catch (err) {
      afShopWarn('Некорректный checkout success payload', err);
      payload = null;
    }

    if (!payload) { return; }
    showInventoryModal(buildCheckoutSuccessHtml(payload));
  }

  function withLoading(btn, promise) {
    if (!btn || btn.dataset.loading === '1') {
      return Promise.resolve({ ok: false, error: 'busy' });
    }
    btn.disabled = true;
    btn.dataset.loading = '1';
    return promise.finally(function() {
      btn.disabled = false;
      delete btn.dataset.loading;
    });
  }

  function isPanelOpen(panel) {
    if (!panel) { return false; }
    return !panel.hidden && panel.getAttribute('hidden') === null && panel.style.display !== 'none';
  }

  function setDisclosureState(button, panel, isOpen, options) {
    if (!panel) { return; }

    var openText = options && options.openText ? options.openText : 'Скрыть';
    var closedText = options && options.closedText ? options.closedText : 'Открыть';

    if (isOpen) {
      panel.hidden = false;
      panel.removeAttribute('hidden');
      panel.style.display = '';
      panel.setAttribute('aria-hidden', 'false');
    } else {
      panel.hidden = true;
      panel.setAttribute('hidden', 'hidden');
      panel.style.display = 'none';
      panel.setAttribute('aria-hidden', 'true');
    }

    if (button) {
      button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      button.textContent = isOpen ? openText : closedText;
    }
  }  

  var AF_EQUIP_META = {
    head:{label:'Голова'}, body:{label:'Тело'}, hands:{label:'Руки'}, legs:{label:'Ноги'}, feet:{label:'Ступни'}, back:{label:'Спина'}, belt:{label:'Пояс'},
    mainhand:{label:'Основная рука'}, offhand:{label:'Вторая рука'}, twohand:{label:'Двуручное'}, ranged:{label:'Дистанционное'}, melee:{label:'Ближний бой'}, accessory:{label:'Аксессуар'}, unique:{label:'Уникалка'}, artifact:{label:'Артефакт'}
  };

  function updateEquipmentSlot(node, data) {
    if (!node) { return; }
    var slotCode = node.getAttribute('data-slot-code') || '';
    var meta = AF_EQUIP_META[slotCode] || { label: slotCode };
    var entry = data || null;
    var rarity = (entry && entry.rarity) ? entry.rarity : 'common';
    var title = (entry && entry.title) ? entry.title : 'Пусто';
    var iconUrl = (entry && entry.icon_url) ? entry.icon_url : '';
    node.className = 'af-eq-slot af-equip-slot rarity-' + rarity + (entry ? '' : ' is-empty');
    node.setAttribute('data-inv-id', entry && entry.inv_id ? String(entry.inv_id) : '0');
    node.setAttribute('data-kb-id', entry && entry.kb_id ? String(entry.kb_id) : '0');
    node.setAttribute('data-item-title', title);
    node.setAttribute('data-item-icon', iconUrl);
    node.setAttribute('data-item-rarity', rarity);
    node.setAttribute('data-slot-label', meta.label || slotCode);
    var iconNode = node.querySelector('.af-equip-slot__item-icon');
    if (iconNode) {
      iconNode.innerHTML = iconUrl ? ('<img src="' + escapeHtml(iconUrl) + '" alt="' + escapeHtml(title) + '">') : '<span class="af-equip-slot__placeholder">Пусто</span>';
    }
  }

  document.addEventListener('click', function(e) {
    var catToggle = e.target.closest('.af-cat-toggle[data-cat]');
    if (catToggle) {
      e.preventDefault();
      e.stopPropagation();
      var wrap = catToggle.closest('.af-shop-wrap[data-shop]');
      var shop = wrap ? (wrap.getAttribute('data-shop') || 'game') : 'game';
      var collapsed = catToggle.getAttribute('aria-expanded') === 'true';
      applyCatState(catToggle, collapsed);
      try {
        localStorage.setItem(treeStorageKey(shop, catToggle.getAttribute('data-cat')), collapsed ? 'collapsed' : 'expanded');
      } catch (err) {}
      return;
    }
    var popupNav = e.target.closest('[data-af-shop-nav]');
    if (popupNav) {
      e.preventDefault();
      e.stopPropagation();

      var href = popupNav.getAttribute('data-af-shop-nav') || '';
      if (href) {
        afShopHideCheckoutSuccessModal();
        window.location.href = normalizeActionUrl(href);
      }
      return;
    }
    var add = e.target.closest('.af-add-cart');
    if (add) {
      e.preventDefault();
      e.stopPropagation();
      var wrap1 = add.closest('[data-shop]');
      var shop1 = wrap1 ? wrap1.getAttribute('data-shop') : 'game';
      withLoading(add, post(buildActionUrl('shop_add_to_cart', 'shop=' + encodeURIComponent(shop1)), {
        slot: add.getAttribute('data-slot'),
        qty: 1
      })).then(function(res) {
        if (res.ok) {
          afShopToast('Added to cart', 'success');
        } else if (res.error !== 'busy') {
          afShopToast(res.error || 'Error', 'error');
        }
      });
      return;
    }

    var buy = e.target.closest('.af-buy-now');
    if (buy) {
      e.preventDefault();
      e.stopPropagation();
      var wrap2 = buy.closest('[data-shop]');
      var shop2 = wrap2 ? wrap2.getAttribute('data-shop') : 'game';
      withLoading(buy, post(buildActionUrl('shop_add_to_cart', 'shop=' + encodeURIComponent(shop2)), {
        slot: buy.getAttribute('data-slot'),
        qty: 1
      })).then(function(res) {
        if (res.ok) {
          window.location = buildActionUrl('shop_cart', 'shop=' + encodeURIComponent(shop2));
        } else if (res.error !== 'busy') {
          afShopToast(res.error || 'Error', 'error');
        }
      });
      return;
    }

    var checkout = e.target.closest('.af-checkout');
    if (checkout) {
      e.preventDefault();
      e.stopPropagation();
      var shop3 = (checkout.closest('[data-shop]') || document.body).getAttribute('data-shop') || 'game';
      withLoading(checkout, post(buildActionUrl('shop_checkout', 'shop=' + encodeURIComponent(shop3)), {})).then(function(res) {
        if (res.ok) {
          persistCheckoutSuccess(res);
          window.location.reload();
        } else if (res.error !== 'busy') {
          afShopToast(res.error || 'Error', 'error');
        }
      });
      return;
    }

    var qtyBtn = e.target.closest('.af-cart-qty');
    if (qtyBtn) {
      e.preventDefault();
      e.stopPropagation();
      var item = qtyBtn.closest('.af-cart-item');
      var id = item.getAttribute('data-item-id');
      var curr = parseInt(((item.querySelector('.af-cart-item__qty span') || {}).textContent || '1'), 10);
      var next = curr + parseInt(qtyBtn.getAttribute('data-delta'), 10);
      post(buildActionUrl('shop_update_cart', ''), { item_id: id, qty: next }).then(function() { window.location.reload(); });
      return;
    }

    var remove = e.target.closest('.af-cart-remove');
    if (remove) {
      e.preventDefault();
      e.stopPropagation();
      var item2 = remove.closest('.af-cart-item');
      post(buildActionUrl('shop_update_cart', ''), { item_id: item2.getAttribute('data-item-id'), qty: 0 }).then(function() { window.location.reload(); });
      return;
    }

    var catSave = e.target.closest('.af-cat-save');
    if (catSave) {
      e.preventDefault();
      var row = catSave.closest('tr[data-cat-id]');
      var wrap3 = document.querySelector('.af-manage[data-shop]');
      if (!row || !wrap3) { return; }
      var shop4 = wrap3.getAttribute('data-shop') || 'game';
      var status1 = document.getElementById('af-manage-category-status');
      post(buildActionUrl('shop_manage_category_update', 'shop=' + encodeURIComponent(shop4)), {
        cat_id: row.getAttribute('data-cat-id'),
        title: (row.querySelector('.af-cat-title-input') || {}).value || '',
        description: (row.querySelector('.af-cat-description-input') || {}).value || '',
        parent_id: (row.querySelector('.af-cat-parent-input') || {}).value || 0,
        enabled: (row.querySelector('.af-cat-enabled-input') || {}).checked ? 1 : 0,
        sortorder: (row.querySelector('.af-cat-sortorder-input') || {}).value || 0
      }).then(function(r) {
        if (r.ok) {
          setStatus(status1, 'Category updated', true);
          window.location.reload();
        } else {
          setStatus(status1, r.error || 'Update failed', false);
        }
      });
      return;
    }

    var catDelete = e.target.closest('.af-cat-delete');
    if (catDelete) {
      e.preventDefault();
      var row2 = catDelete.closest('tr[data-cat-id]');
      var wrap4 = document.querySelector('.af-manage[data-shop]');
      if (!row2 || !wrap4 || !window.confirm('Delete category?')) { return; }
      var shop5 = wrap4.getAttribute('data-shop') || 'game';
      var status2 = document.getElementById('af-manage-category-status');
      post(buildActionUrl('shop_manage_category_delete', 'shop=' + encodeURIComponent(shop5)), {
        cat_id: row2.getAttribute('data-cat-id')
      }).then(function(r) {
        if (r.ok) {
          setStatus(status2, 'Category deleted', true);
          window.location.reload();
        } else {
          setStatus(status2, r.error || 'Delete failed', false);
        }
      });
      return;
    }

    var pick = e.target.closest('#af-add-slot-btn');
    if (pick) {
      e.preventDefault();
      var panel = document.getElementById('af-slot-create-panel');
      if (panel) {
        setDisclosureState(
          pick,
          panel,
          !isPanelOpen(panel),
          { openText: 'Скрыть форму', closedText: 'Показать форму' }
        );
      }
      return;
    }

    var editToggle = e.target.closest('.af-slot-edit-toggle');
    if (editToggle) {
      e.preventDefault();
      var targetId = editToggle.getAttribute('aria-controls') || '';
      var editor = targetId ? byId(targetId) : null;
      if (editor) {
        setDisclosureState(
          editToggle,
          editor,
          !isPanelOpen(editor),
          { openText: 'Скрыть редактор', closedText: 'Редактировать' }
        );
      }
      return;
    }

    var kbItem = e.target.closest('.af-kb-pick-item');
    if (kbItem) {
      e.preventDefault();
      var prefixKb = kbItem.getAttribute('data-editor-prefix') || 'create';
      setKbSelection(prefixKb, {
        kb_id: kbItem.getAttribute('data-kb-id') || '0',
        kb_type: kbItem.getAttribute('data-kb-type') || 'item',
        kb_key: kbItem.getAttribute('data-kb-key') || '',
        title: kbItem.getAttribute('data-kb-title') || '',
        short: kbItem.getAttribute('data-kb-short') || '',
        rarity: kbItem.getAttribute('data-kb-rarity') || ''
      });
      return;
    }

    var apItem = e.target.closest('.af-appearance-pick-item');
    if (apItem) {
      e.preventDefault();
      var prefixAp = apItem.getAttribute('data-editor-prefix') || 'create';
      setAppearanceSelection(prefixAp, {
        preset_id: apItem.getAttribute('data-preset-id') || '0',
        slug: apItem.getAttribute('data-preset-slug') || '',
        title: apItem.getAttribute('data-preset-title') || '',
        target_key: apItem.getAttribute('data-target-key') || '',
        preview_image: apItem.getAttribute('data-preview-image') || '',
        enabled: apItem.getAttribute('data-enabled') || '0',
        target_label: apItem.getAttribute('data-target-label') || '',
        group: apItem.getAttribute('data-group') || '',
        group_label: apItem.getAttribute('data-group-label') || '',
        description: apItem.getAttribute('data-description') || ''
      });
      return;
    }

    var createSubmit = e.target.closest('#af-slot-create-submit');
    if (createSubmit) {
      e.preventDefault();
      var rootCreate = document.querySelector('.af-manage-slots[data-shop]');
      if (!rootCreate) { return; }
      var shopCreate = rootCreate.getAttribute('data-shop') || 'game';
      var catCreate = rootCreate.getAttribute('data-cat-id') || '0';
      var statusCreate = document.getElementById('af-manage-slot-status');
      var payloadCreate = buildSlotPayload('create');
      payloadCreate.cat_id = catCreate;
      post(buildActionUrl('shop_manage_slot_create', 'shop=' + encodeURIComponent(shopCreate)), payloadCreate).then(function(r) {
        if (r.ok) {
          setStatus(statusCreate, 'Slot created', true);
          loadSlots(shopCreate, catCreate);
        } else {
          setStatus(statusCreate, r.error || 'Failed to create slot', false);
        }
      });
      return;
    }

    var saveSlot = e.target.closest('.af-slot-save');
    if (saveSlot) {
      e.preventDefault();
      var card = saveSlot.closest('.af-slot-card');
      var root5 = document.querySelector('.af-manage-slots[data-shop]');
      if (!card || !root5) { return; }
      var shop6 = root5.getAttribute('data-shop') || 'game';
      var status4 = document.getElementById('af-manage-slot-status');
      var payloadUpdate = buildSlotPayload('slot-' + card.getAttribute('data-slot-id'));
      payloadUpdate.slot_id = card.getAttribute('data-slot-id');
      post(buildActionUrl('shop_manage_slot_update', 'shop=' + encodeURIComponent(shop6)), payloadUpdate).then(function(r) {
        if (r.ok) {
          setStatus(status4, 'Slot saved', true);
          loadSlots(shop6, root5.getAttribute('data-cat-id') || '0');
        } else {
          setStatus(status4, r.error || 'Save failed', false);
        }
      });
      return;
    }

    var delSlot = e.target.closest('.af-slot-delete');
    if (delSlot) {
      e.preventDefault();
      var card2 = delSlot.closest('.af-slot-card');
      var root6 = document.querySelector('.af-manage-slots[data-shop]');
      if (!card2 || !root6 || !window.confirm('Delete slot?')) { return; }
      var shop7 = root6.getAttribute('data-shop') || 'game';
      var status5 = document.getElementById('af-manage-slot-status');
      post(buildActionUrl('shop_manage_slot_delete', 'shop=' + encodeURIComponent(shop7)), {
        slot_id: card2.getAttribute('data-slot-id')
      }).then(function(r) {
        if (r.ok) {
          setStatus(status5, 'Slot deleted', true);
          loadSlots(shop7, root6.getAttribute('data-cat-id') || '0');
        } else {
          setStatus(status5, r.error || 'Delete failed', false);
        }
      });
      return;
    }
  });

  var categoryForm = document.getElementById('af-manage-category-form');
  if (categoryForm) {
    var statusNode = document.getElementById('af-manage-category-status');
    categoryForm.addEventListener('submit', function(e) {
      e.preventDefault();
      var fd = new FormData(categoryForm);
      var shop = categoryForm.getAttribute('data-shop') || 'game';
      var payload = Object.fromEntries(fd.entries());
      payload.my_post_key = key();
      post(buildActionUrl('shop_manage_category_create', 'shop=' + encodeURIComponent(shop)), payload).then(function(r) {
        if (r.ok) {
          setStatus(statusNode, 'Category created', true);
          window.location.reload();
        } else {
          setStatus(statusNode, r.error || 'Failed to create category', false);
        }
      });
    });
  }

  var rebuild = document.getElementById('af-rebuild-sortorder');
  if (rebuild) {
    rebuild.addEventListener('click', function() {
      var wrap = document.querySelector('.af-manage[data-shop]');
      if (!wrap) { return; }
      var shop = wrap.getAttribute('data-shop') || 'game';
      post(buildActionUrl('shop_manage_sortorder_rebuild', 'shop=' + encodeURIComponent(shop)), {}).then(function(r) {
        var status = document.getElementById('af-manage-category-status');
        setStatus(status, r.ok ? 'Sortorder rebuilt' : (r.error || 'Failed'), !!r.ok);
        if (r.ok) {
          setTimeout(function(){ window.location.reload(); }, 300);
        }
      });
    });
  }

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      afShopHideCheckoutSuccessModal();
    }
  });

  initCategoryTree();
  try {
    restoreCheckoutSuccessModal();
  } catch (err) {
    afShopWarn('restoreCheckoutSuccessModal failed', err);
  }
  runHealthCheck();

  function sourcePrefix(id) { return 'af-' + id; }
  function byId(id) { return document.getElementById(id); }
  function asBool(value) { return String(value || '') === '1'; }

  function setSourceMode(prefix, sourceType) {
    var normalized = sourceType === 'appearance' ? 'appearance' : 'kb';
    var sourceNode = byId(sourcePrefix(prefix + '-source-type'));
    if (sourceNode) { sourceNode.value = normalized; }
    var kbWrap = byId(sourcePrefix(prefix + '-kb-fields'));
    var apWrap = byId(sourcePrefix(prefix + '-appearance-fields'));
    if (kbWrap) { kbWrap.hidden = normalized !== 'kb'; }
    if (apWrap) { apWrap.hidden = normalized !== 'appearance'; }
  }

  function renderSelectedSource(prefix, sourceType, html) {
    var node = byId(sourcePrefix(prefix + '-selected-summary'));
    if (!node) { return; }
    if (!html) {
      node.innerHTML = 'Источник ещё не выбран.';
      return;
    }
    node.innerHTML = '<div class="af-slot-selected-card__type">' + escapeHtml(sourceType === 'appearance' ? 'Appearance source' : 'KB source') + '</div>' + html;
  }

  function setKbSelection(prefix, item) {
    var idNode = byId(sourcePrefix(prefix + '-kb-id'));
    var typeNode = byId(sourcePrefix(prefix + '-kb-type'));
    var keyNode = byId(sourcePrefix(prefix + '-kb-key'));
    var sourceRefNode = byId(sourcePrefix(prefix + '-source-ref-id'));
    var kbId = item && item.kb_id ? String(item.kb_id) : '0';
    if (idNode) { idNode.value = kbId; }
    if (typeNode) { typeNode.value = item && item.kb_type ? item.kb_type : 'item'; }
    if (keyNode) { keyNode.value = item && item.kb_key ? item.kb_key : ''; }
    if (sourceRefNode) { sourceRefNode.value = kbId; }

    var summaryNode = byId(sourcePrefix(prefix + '-kb-summary'));
    var title = item && item.title ? item.title : '';
    var shortText = item && item.short ? item.short : '';
    var rarity = item && item.rarity ? item.rarity : '';
    var summaryHtml = kbId !== '0'
      ? '<strong>' + escapeHtml(title || ('KB #' + kbId)) + '</strong>'
        + '<div class="af-slot-badges"><span class="af-slot-badge">KB</span><span class="af-slot-badge">ID ' + escapeHtml(kbId) + '</span><span class="af-slot-badge">' + escapeHtml(item && item.kb_type ? item.kb_type : 'item') + '</span>' + (rarity ? '<span class="af-slot-badge">' + escapeHtml(rarity) + '</span>' : '') + '</div>'
        + '<div><small>key: ' + escapeHtml(item && item.kb_key ? item.kb_key : '') + '</small></div>'
        + (shortText ? ('<div><small>' + escapeHtml(shortText) + '</small></div>') : '')
      : 'KB-источник ещё не выбран.';

    if (summaryNode) { summaryNode.innerHTML = summaryHtml; }
    renderSelectedSource(prefix, 'kb', kbId !== '0' ? summaryHtml : '');
  }

  function renderAppearanceSummary(prefix, item) {
    var node = byId(sourcePrefix(prefix + '-appearance-summary'));
    if (!node) { return ''; }
    if (!item || (!item.preset_id && !item.slug)) {
      node.innerHTML = 'Appearance preset ещё не выбран.';
      renderSelectedSource(prefix, 'appearance', '');
      return '';
    }

    var preview = item.preview_image ? ('<img src="' + escapeHtml(item.preview_image) + '" alt="" class="af-slot-selected-card__preview">') : '';
    var enabledLabel = asBool(item.enabled) ? 'enabled' : 'disabled';
    var html = preview + '<div class="af-slot-selected-card__body"><strong>' + escapeHtml(item.title || item.slug || ('#' + String(item.preset_id || '0'))) + '</strong>'
      + '<div class="af-slot-badges"><span class="af-slot-badge">Appearance</span><span class="af-slot-badge">ID ' + escapeHtml(String(item.preset_id || '0')) + '</span><span class="af-slot-badge">' + escapeHtml(item.group_label || item.group || 'group?') + '</span><span class="af-slot-badge">' + escapeHtml(item.target_label || item.target_key || 'target?') + '</span><span class="af-slot-badge">' + escapeHtml(enabledLabel) + '</span></div>'
      + '<div><small>slug: ' + escapeHtml(item.slug || '') + '</small></div>'
      + (item.description ? ('<div><small>' + escapeHtml(item.description) + '</small></div>') : '')
      + '</div>';
    node.innerHTML = html;
    renderSelectedSource(prefix, 'appearance', html);
    return html;
  }

  function setAppearanceSelection(prefix, item) {
    var idNode = byId(sourcePrefix(prefix + '-preset-id'));
    var slugNode = byId(sourcePrefix(prefix + '-preset-slug'));
    var sourceRefNode = byId(sourcePrefix(prefix + '-source-ref-id'));
    var presetId = item && item.preset_id ? String(item.preset_id) : '0';
    if (idNode) { idNode.value = presetId; }
    if (slugNode) { slugNode.value = item && item.slug ? item.slug : ''; }
    if (sourceRefNode) { sourceRefNode.value = presetId; }
    renderAppearanceSummary(prefix, item || {});
  }

  function buildSlotPayload(prefix) {
    var rawCurrency = ((byId(sourcePrefix(prefix + '-currency')) || {}).value || 'credits').toLowerCase().trim();
    var normalizedCurrency = rawCurrency === 'ability_tokens' ? 'ability_tokens' : 'credits';
    var sourceTypeNode = byId(sourcePrefix(prefix + '-source-type'));
    var sourceType = sourceTypeNode ? (sourceTypeNode.value || 'kb') : 'kb';
    return {
      source_type: sourceType,
      source_ref_id: sourceType === 'appearance'
        ? ((byId(sourcePrefix(prefix + '-preset-id')) || {}).value || (byId(sourcePrefix(prefix + '-source-ref-id')) || {}).value || '0')
        : ((byId(sourcePrefix(prefix + '-kb-id')) || {}).value || (byId(sourcePrefix(prefix + '-source-ref-id')) || {}).value || '0'),
      preset_id: (byId(sourcePrefix(prefix + '-preset-id')) || {}).value || '0',
      preset_slug: (byId(sourcePrefix(prefix + '-preset-slug')) || {}).value || '',
      kb_id: (byId(sourcePrefix(prefix + '-kb-id')) || {}).value || '0',
      kb_type: (byId(sourcePrefix(prefix + '-kb-type')) || {}).value || 'item',
      kb_key: (byId(sourcePrefix(prefix + '-kb-key')) || {}).value || '',
      price: (byId(sourcePrefix(prefix + '-price')) || {}).value || 0,
      currency: normalizedCurrency,
      stock: (byId(sourcePrefix(prefix + '-stock')) || {}).value || -1,
      limit_per_user: (byId(sourcePrefix(prefix + '-limit')) || {}).value || 0,
      enabled: (byId(sourcePrefix(prefix + '-enabled')) || {}).checked ? 1 : 0,
      sortorder: (byId(sourcePrefix(prefix + '-sortorder')) || {}).value || 0
    };
  }

  var slotsRoot = document.querySelector('.af-manage-slots[data-shop]');
  if (slotsRoot) {
    var shopCode = slotsRoot.getAttribute('data-shop') || 'game';
    var catId = slotsRoot.getAttribute('data-cat-id') || '0';
    var searchTimer = null;
    var loadSlots = function(){};

    function renderKbResults(r, prefix) {
      var resNode = byId(sourcePrefix(prefix + '-kb-results')) || byId('af-kb-picker-results');
      if (!resNode) { return; }
      if (!r.ok) {
        resNode.textContent = r.error || 'Search failed';
        return;
      }
      var items = Array.isArray(r.items) ? r.items : [];
      if (!items.length) {
        resNode.innerHTML = '<div class="af-slot-empty-state">По KB ничего не найдено. Измените тип, ключ или редкость.</div>';
        return;
      }
      resNode.innerHTML = items.map(function(item) {
        var desc = item.short ? ('<small>' + escapeHtml(item.short) + '</small>') : '<small>Без краткого описания.</small>';
        return '<div class="af-search-result-card af-search-result-card--kb">'
          + '<div class="af-search-result-card__main"><strong>#' + escapeHtml(String(item.kb_id || 0)) + ' ' + escapeHtml(item.title || '') + '</strong>'
          + '<div class="af-slot-badges"><span class="af-slot-badge">KB</span><span class="af-slot-badge">' + escapeHtml(item.kb_type || 'item') + '</span><span class="af-slot-badge">' + escapeHtml(item.rarity || 'common') + '</span></div>'
          + '<div><small>key: ' + escapeHtml(item.kb_key || '') + '</small></div>'
          + desc + '</div>'
          + '<button class="af-kb-pick-item" data-editor-prefix="' + escapeHtml(prefix) + '" data-kb-id="' + escapeHtml(String(item.kb_id || 0)) + '" data-kb-type="' + escapeHtml(item.kb_type || 'item') + '" data-kb-key="' + escapeHtml(item.kb_key || '') + '" data-kb-title="' + escapeHtml(item.title || '') + '" data-kb-short="' + escapeHtml(item.short || '') + '" data-kb-rarity="' + escapeHtml(item.rarity || '') + '" type="button">Выбрать KB</button></div>';
      }).join('');
    }

    function runKbSearch(prefix) {
      var qNode = byId(sourcePrefix(prefix + '-kb-q')) || byId('af-kb-picker-q');
      var typeNode = byId(sourcePrefix(prefix + '-kb-type-filter')) || byId('af-kb-picker-type');
      var rarityNode = byId(sourcePrefix(prefix + '-kb-rarity')) || byId('af-kb-picker-rarity');
      var itemTypeNode = byId(sourcePrefix(prefix + '-kb-item-type')) || byId('af-kb-picker-item-type');
      var spellLevelNode = byId(sourcePrefix(prefix + '-kb-spell-level')) || byId('af-kb-picker-spell-level');
      var spellSchoolNode = byId(sourcePrefix(prefix + '-kb-spell-school')) || byId('af-kb-picker-spell-school');

      var url = buildActionUrl('shop_kb_search',
        'shop=' + encodeURIComponent(shopCode)
        + '&q=' + encodeURIComponent(qNode ? (qNode.value || '') : '')
        + '&kb_type=' + encodeURIComponent(typeNode ? (typeNode.value || 'all') : 'all')
        + '&rarity=' + encodeURIComponent(rarityNode ? (rarityNode.value || '') : '')
        + '&item_type=' + encodeURIComponent(itemTypeNode ? (itemTypeNode.value || '') : '')
        + '&spell_level=' + encodeURIComponent(spellLevelNode ? (spellLevelNode.value || '') : '')
        + '&spell_school=' + encodeURIComponent(spellSchoolNode ? (spellSchoolNode.value || '') : '')
      );

      afShopDebug('runKbSearch', prefix, url);
      getJSON(url).then(function(r) { renderKbResults(r, prefix); });
    }

    function appearanceSearchEmptyMessage(groupLabel, query) {
      var parts = [];
      if (groupLabel) { parts.push('группа: ' + groupLabel); }
      if (query) { parts.push('поиск: ' + query); }
      return 'Совпадений по appearance не найдено.' + (parts.length ? (' Фильтрация — ' + parts.join(', ') + '.') : '');
    }

    function renderAppearanceResults(r, prefix) {
      var node = byId(sourcePrefix(prefix + '-appearance-results')) || byId('af-appearance-picker-results');
      if (!node) { return; }
      if (!r.ok) {
        node.textContent = r.error || 'Search failed';
        return;
      }

      var items = Array.isArray(r.items) ? r.items : [];
      if (!items.length) {
        var qNode = byId(sourcePrefix(prefix + '-appearance-q')) || byId('af-appearance-picker-q');
        node.innerHTML = '<div class="af-slot-empty-state">' + escapeHtml(appearanceSearchEmptyMessage(r.group_label || '', qNode ? (qNode.value || '') : '')) + '</div>';
        return;
      }

      node.innerHTML = items.map(function(item) {
        var prev = item.preview_image
          ? ('<img src="' + escapeHtml(item.preview_image) + '" alt="" class="af-search-result-card__preview">')
          : '<div class="af-search-result-card__preview af-search-result-card__preview--empty">No preview</div>';

        return '<div class="af-search-result-card af-search-result-card--appearance">'
          + prev
          + '<div class="af-search-result-card__main"><strong>#' + escapeHtml(String(item.preset_id || 0)) + ' ' + escapeHtml(item.title || '') + '</strong><div><code>' + escapeHtml(item.slug || '') + '</code></div>'
          + '<div class="af-slot-badges"><span class="af-slot-badge">Appearance</span><span class="af-slot-badge">' + escapeHtml(item.group_label || item.group || '') + '</span><span class="af-slot-badge">' + escapeHtml(item.target_label || item.target_key || '') + '</span><span class="af-slot-badge">' + escapeHtml(item.enabled ? 'enabled' : 'disabled') + '</span></div>'
          + (item.description ? ('<div><small>' + escapeHtml(item.description) + '</small></div>') : '<div><small>Без описания.</small></div>') + '</div>'
          + '<button class="af-appearance-pick-item" data-editor-prefix="' + escapeHtml(prefix) + '" data-preset-id="' + escapeHtml(String(item.preset_id || 0)) + '" data-preset-slug="' + escapeHtml(item.slug || '') + '" data-preset-title="' + escapeHtml(item.title || '') + '" data-target-key="' + escapeHtml(item.target_key || '') + '" data-target-label="' + escapeHtml(item.target_label || '') + '" data-group="' + escapeHtml(item.group || '') + '" data-group-label="' + escapeHtml(item.group_label || '') + '" data-description="' + escapeHtml(item.description || '') + '" data-preview-image="' + escapeHtml(item.preview_image || '') + '" data-enabled="' + escapeHtml(String(item.enabled ? 1 : 0)) + '" type="button">Выбрать appearance</button></div>';
      }).join('');
    }

    function runAppearanceSearch(prefix) {
      var qNode = byId(sourcePrefix(prefix + '-appearance-q')) || byId('af-appearance-picker-q');
      var groupNode = byId(sourcePrefix(prefix + '-appearance-group')) || byId('af-appearance-picker-group');

      var url = buildActionUrl('shop_appearance_search',
        'shop=' + encodeURIComponent(shopCode)
        + '&q=' + encodeURIComponent(qNode ? (qNode.value || '') : '')
        + '&group=' + encodeURIComponent(groupNode ? (groupNode.value || 'all') : 'all')
      );

      afShopDebug('runAppearanceSearch', prefix, url);
      getJSON(url).then(function(r) { renderAppearanceResults(r, prefix); });
    }

    function bindEditor(prefix, row) {
      if (row) {
        return;
      }

      var sourceNode = byId(sourcePrefix(prefix + '-source-type'));
      if (sourceNode) {
        sourceNode.addEventListener('change', function() {
          var st = sourceNode.value || 'kb';
          setSourceMode(prefix, st);
          renderSelectedSource(prefix, st, '');
          if (st === 'appearance') {
            runAppearanceSearch(prefix);
          } else {
            runKbSearch(prefix);
          }
        });
      }

      var apQ = byId(sourcePrefix(prefix + '-appearance-q'));
      if (apQ) {
        apQ.addEventListener('input', function() {
          clearTimeout(searchTimer);
          searchTimer = setTimeout(function() { runAppearanceSearch(prefix); }, 150);
        });
      }

      var apGroup = byId(sourcePrefix(prefix + '-appearance-group')) || byId('af-appearance-picker-group');
      if (apGroup) {
        apGroup.addEventListener('change', function() { runAppearanceSearch(prefix); });
      }

      ['kb-q','kb-type-filter','kb-rarity','kb-item-type','kb-spell-level','kb-spell-school'].forEach(function(suffix) {
        var node = byId(sourcePrefix(prefix + '-' + suffix)) || byId('af-' + suffix.replace(/^kb-/, 'kb-picker-'));
        if (!node) { return; }
        node.addEventListener('input', function() {
          clearTimeout(searchTimer);
          searchTimer = setTimeout(function() { runKbSearch(prefix); }, 150);
        });
        node.addEventListener('change', function() { runKbSearch(prefix); });
      });

      setSourceMode(prefix, sourceNode ? (sourceNode.value || 'kb') : 'kb');
    }

    function sourceSummaryLine(row) {
      if ((row.source_type || 'kb') === 'appearance') {
        return '<div class="af-slot-badges"><span class="af-slot-badge">appearance</span><span class="af-slot-badge">ref #' + escapeHtml(String(row.source_ref_id || 0)) + '</span><span class="af-slot-badge">' + escapeHtml(row.appearance_group_label || row.appearance_group || 'group?') + '</span><span class="af-slot-badge">' + escapeHtml(row.appearance_target_label || row.appearance_target || 'target?') + '</span></div>';
      }
      return '<div class="af-slot-badges"><span class="af-slot-badge">kb</span><span class="af-slot-badge">ref #' + escapeHtml(String(row.source_ref_id || row.kb_id || 0)) + '</span><span class="af-slot-badge">' + escapeHtml(row.kb_type || 'item') + '</span><span class="af-slot-badge">' + escapeHtml(row.kb_key || 'no-key') + '</span></div>';
    }

    function slotEditorHtml(prefix, row) {
      var sourceType = row.source_type || 'kb';
      var editorId = sourcePrefix(prefix + '-editor');
      var icon = row.icon_url
        ? '<img src="' + escapeHtml(row.icon_url) + '" alt="" class="af-slot-existing__preview">'
        : '<div class="af-slot-existing__preview af-slot-existing__preview--empty">No preview</div>';

      var sourceRefId = String(row.source_ref_id || row.kb_id || 0);
      var presetId = sourceType === 'appearance' ? String(row.appearance_preset_id || row.source_ref_id || 0) : '0';
      var presetSlug = sourceType === 'appearance' ? (row.appearance_preset_slug || '') : '';
      var kbId = sourceType === 'kb' ? String(row.kb_id || row.source_ref_id || 0) : '0';
      var kbType = sourceType === 'kb' ? (row.kb_type || 'item') : 'item';
      var kbKey = sourceType === 'kb' ? (row.kb_key || '') : '';

      var currency = (row.currency || 'credits') === 'ability_tokens' ? 'ability_tokens' : 'credits';
      var currencyOptions = '<option value="credits"' + (currency === 'credits' ? ' selected' : '') + '>Credits</option>'
        + '<option value="ability_tokens"' + (currency === 'ability_tokens' ? ' selected' : '') + '>Ability Tokens</option>';

      return '<article class="af-slot-card ' + escapeHtml(row.rarity_class || 'af-rarity-common') + '" data-slot-id="' + escapeHtml(String(row.slot_id || 0)) + '">'
        + '<div class="af-slot-existing">'
        + '<div class="af-slot-existing__media">' + icon + '</div>'
        + '<div class="af-slot-existing__main">'
        + '<div class="af-slot-existing__header"><div><h3>#' + escapeHtml(String(row.slot_id || 0)) + ' — ' + escapeHtml(row.title || '') + '</h3>' + sourceSummaryLine(row) + '</div><button type="button" class="af-shop-btn af-slot-edit-toggle" aria-expanded="false" aria-controls="' + editorId + '">Редактировать</button></div>'
        + '<dl class="af-slot-existing__meta">'
        + '<div><dt>title</dt><dd>' + escapeHtml(row.title || '') + '</dd></div>'
        + '<div><dt>source type</dt><dd>' + escapeHtml(sourceType) + '</dd></div>'
        + '<div><dt>source ref</dt><dd>#' + escapeHtml(sourceRefId) + '</dd></div>'
        + '<div><dt>target/group</dt><dd>' + escapeHtml(sourceType === 'appearance' ? ((row.appearance_group_label || row.appearance_group || '—') + ' / ' + (row.appearance_target_label || row.appearance_target || '—')) : '—') + '</dd></div>'
        + '<div><dt>price</dt><dd>' + escapeHtml(row.price_major || '0.00') + '</dd></div>'
        + '<div><dt>currency</dt><dd>' + escapeHtml(currency) + '</dd></div>'
        + '<div><dt>stock</dt><dd>' + escapeHtml(String(row.stock == null ? -1 : row.stock)) + '</dd></div>'
        + '<div><dt>enabled</dt><dd>' + (row.enabled ? 'yes' : 'no') + '</dd></div>'
        + '<div><dt>sortorder</dt><dd>' + escapeHtml(String(row.sortorder || 0)) + '</dd></div>'
        + '</dl>'
        + '</div></div>'

        + '<div id="' + editorId + '" class="af-slot-inline-editor" hidden aria-hidden="true" style="display:none;">'

        + '<input type="hidden" id="' + sourcePrefix(prefix + '-source-type') + '" value="' + escapeHtml(sourceType) + '">'
        + '<input type="hidden" id="' + sourcePrefix(prefix + '-source-ref-id') + '" value="' + escapeHtml(sourceRefId) + '">'
        + '<input type="hidden" id="' + sourcePrefix(prefix + '-preset-id') + '" value="' + escapeHtml(presetId) + '">'
        + '<input type="hidden" id="' + sourcePrefix(prefix + '-preset-slug') + '" value="' + escapeHtml(presetSlug) + '">'
        + '<input type="hidden" id="' + sourcePrefix(prefix + '-kb-id') + '" value="' + escapeHtml(kbId) + '">'
        + '<input type="hidden" id="' + sourcePrefix(prefix + '-kb-type') + '" value="' + escapeHtml(kbType) + '">'
        + '<input type="hidden" id="' + sourcePrefix(prefix + '-kb-key') + '" value="' + escapeHtml(kbKey) + '">'

        + '<section class="af-slot-step">'
        + '<div class="af-slot-step__label">⚙</div>'
        + '<div class="af-slot-step__body">'
        + '<h4>Коммерческие настройки слота</h4>'
        + '<div class="af-kb-picker-filters">'
        + '<label>Price <input type="number" id="' + sourcePrefix(prefix + '-price') + '" value="' + escapeHtml(row.price_major || '0.00') + '" min="0" step="0.01"></label>'
        + '<label>Currency <select id="' + sourcePrefix(prefix + '-currency') + '">' + currencyOptions + '</select></label>'
        + '<label>Stock <input type="number" id="' + sourcePrefix(prefix + '-stock') + '" value="' + escapeHtml(String(row.stock == null ? -1 : row.stock)) + '"></label>'
        + '<label>Limit/user <input type="number" id="' + sourcePrefix(prefix + '-limit') + '" value="' + escapeHtml(String(row.limit_per_user || 0)) + '" min="0"></label>'
        + '<label>Sort <input type="number" id="' + sourcePrefix(prefix + '-sortorder') + '" value="' + escapeHtml(String(row.sortorder || 0)) + '"></label>'
        + '<label><input type="checkbox" id="' + sourcePrefix(prefix + '-enabled') + '" ' + (row.enabled ? 'checked' : '') + '> Enabled</label>'
        + '</div>'
        + '<div class="af-slot-inline-editor__actions"><button type="button" class="af-shop-btn af-slot-save">Save</button> <button type="button" class="af-shop-btn af-slot-delete">Delete</button></div>'
        + '</div>'
        + '</section>'

        + '</div>'
        + '</article>';
    }

    loadSlots = function(shop, catValue) {
      var body = document.getElementById('af-manage-slots-body');
      if (!body) { return; }

      var url = buildActionUrl('shop_manage_slots', 'shop=' + encodeURIComponent(shop) + '&cat_id=' + encodeURIComponent(catValue) + '&do=list');
      afShopDebug('loadSlots', url);

      getJSON(url).then(function(r) {
        if (!body) { return; }

        if (!r.ok) {
          body.innerHTML = '<div class="af-slot-empty-state">' + escapeHtml(r.error || 'Failed to load slots') + '</div>';
          return;
        }

        var rows = Array.isArray(r.rows) ? r.rows : [];
        if (!rows.length) {
          body.innerHTML = '<div class="af-slot-empty-state">В этой категории ещё нет слотов. Используйте форму справа, чтобы добавить первый товар.</div>';
          return;
        }

        body.innerHTML = rows.map(function(row) {
          return slotEditorHtml('slot-' + row.slot_id, row);
        }).join('');

        body.querySelectorAll('.af-slot-inline-editor').forEach(function(panel) {
          setDisclosureState(null, panel, false, null);
        });

        body.querySelectorAll('.af-slot-edit-toggle').forEach(function(button) {
          button.setAttribute('aria-expanded', 'false');
          button.textContent = 'Редактировать';
        });

        rows.forEach(function(row) {
          bindEditor('slot-' + row.slot_id, row);
        });
      });
    };

    bindEditor('create');
    setKbSelection('create', { kb_id: '0', kb_type: 'item', kb_key: '' });
    setAppearanceSelection('create', {});
    runKbSearch('create');
    runAppearanceSearch('create');
    loadSlots(shopCode, catId);
  }

  function runHealthCheck() {
    var root = document.getElementById('af-shop-health');
    if (!root) { return; }
    var jsNode = root.querySelector('[data-health-js]');
    var keyNode = root.querySelector('[data-health-postkey]');
    var apiNode = root.querySelector('[data-health-api]');
    if (jsNode) { jsNode.textContent = 'JS loaded: ' + (window.AFSHOP && window.AFSHOP.loaded ? 'yes' : 'no'); }
    var hasPostKey = !!key();
    if (keyNode) { keyNode.textContent = 'postKey present: ' + (hasPostKey ? 'yes' : 'no'); }
    fetch(buildActionUrl('shop_health', ''), { credentials:'same-origin' })
      .then(function(r){ return r.text(); })
      .then(parseJSON)
      .then(function(res) { if (apiNode) { apiNode.textContent = 'API ping: ' + (res && res.ok ? 'ok' : 'no'); } })
      .catch(function() { if (apiNode) { apiNode.textContent = 'API ping: no'; } });
  }
})();

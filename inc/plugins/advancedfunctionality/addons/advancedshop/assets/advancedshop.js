(function(){
  if(window.__afShopBound){ return; }
  window.__afShopBound = true;
  window.AFSHOP = window.AFSHOP || {};
  window.AFSHOP.loaded = true;

  function afShopDebugEnabled(){
    if(window.AFSHOP_DEBUG === true){ return true; }
    try {
      return (new URLSearchParams(window.location.search)).get('af_debug') === '1';
    } catch(err) {
      return false;
    }
  }

  function afShopDebug(){
    if(!afShopDebugEnabled() || !window.console || typeof window.console.log !== 'function'){ return; }
    var args = Array.prototype.slice.call(arguments);
    args.unshift('[AFSHOP]');
    window.console.log.apply(window.console, args);
  }

  function afShopWarn(){
    if(!window.console || typeof window.console.warn !== 'function'){ return; }
    var args = Array.prototype.slice.call(arguments);
    args.unshift('[AFSHOP]');
    window.console.warn.apply(window.console, args);
  }

  function safeMountInventory(scope){
    if(typeof window.AFSHOP.mountInventory === 'function'){
      return window.AFSHOP.mountInventory(scope);
    }
    if(typeof mountInventory === 'function'){
      afShopWarn('window.AFSHOP.mountInventory отсутствует, используется локальный fallback.');
      return mountInventory(scope);
    }
    afShopWarn('mountInventory недоступен, пропускаем монтирование инвентаря.');
    return Promise.resolve();
  }



  function afShopEndpointScript(){
    if(window.AFSHOP && window.AFSHOP.endpointScript){ return window.AFSHOP.endpointScript; }
    var path = (window.location && window.location.pathname) ? window.location.pathname.toLowerCase() : '';
    return /\/shop\.php$/.test(path) ? 'shop.php' : 'misc.php';
  }

  function buildActionUrl(action, query){
    var endpoint = afShopEndpointScript();
    if(endpoint === 'shop.php'){
      var params = [];
      if(action && action !== 'shop' && action !== 'view'){
        params.push('action=' + encodeURIComponent(action));
      }
      if(query){ params.push(query); }
      return 'shop.php' + (params.length ? ('?' + params.join('&')) : '');
    }
    return 'misc.php?action=' + encodeURIComponent(action || 'shop') + (query ? ('&' + query) : '');
  }

  function normalizeActionUrl(url){
    if(typeof url !== 'string'){ return url; }
    if(url.indexOf('misc.php?action=') !== 0){ return url; }
    var raw = url.slice('misc.php?action='.length);
    var amp = raw.indexOf('&');
    var action = amp === -1 ? raw : raw.slice(0, amp);
    var query = amp === -1 ? '' : raw.slice(amp + 1);
    return buildActionUrl(decodeURIComponent(action || 'shop'), query);
  }

  function post(url, data){
    url = normalizeActionUrl(url);
    data = data || {};
    if(!data.my_post_key){ data.my_post_key = key(); }
    return fetch(url, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:new URLSearchParams(data)})
      .then(function(r){ return r.text(); })
      .then(parseJSON);
  }

  function getJSON(url){
    url = normalizeActionUrl(url);
    return fetch(url, {credentials:'same-origin'}).then(function(r){ return r.text(); }).then(parseJSON);
  }

  function parseJSON(text){
    try { return JSON.parse(text); } catch (e) { return {ok:false, error:'Invalid JSON response'}; }
  }

  function key(){
    if(window.AFSHOP && window.AFSHOP.postKey){ return window.AFSHOP.postKey; }
    var cfg = document.getElementById('af_shop_post_key');
    if(cfg && cfg.value){ return cfg.value; }
    return window.my_post_key || '';
  }

  function escapeHtml(v){
    return String(v == null ? '' : v).replace(/[&<>"']/g, function(ch){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[ch]; });
  }

  function setStatus(node, text, ok){ if(!node){ return; } node.textContent = text; node.className = ok ? 'af-status-ok' : 'af-status-error'; }

  function afShopModalRoot(){
    return document.querySelector('[data-af-shop-modal]');
  }

  function afShopHideCheckoutSuccessModal(){
    var modal = afShopModalRoot();
    if(!modal){ return; }
    modal.hidden = true;
    document.body.classList.remove('af-shop-modal-open');
  }


  function checkoutStorageKey(){
    return 'af_shop_checkout_success';
  }

  function persistCheckoutSuccess(res){
    try {
      var payload = {checkout:(res && res.checkout) || {}, links:(res && res.links) || {}};
      sessionStorage.setItem(checkoutStorageKey(), JSON.stringify(payload));
    } catch(err) {}
  }

  function restoreCheckoutSuccessModal(){
    var cartRoot = document.querySelector('.af-cart[data-shop]');
    if(!cartRoot){ return; }
    var raw = '';
    try { raw = sessionStorage.getItem(checkoutStorageKey()) || ''; } catch(err) { raw = ''; }
    if(!raw){ return; }
    try { sessionStorage.removeItem(checkoutStorageKey()); } catch(err) {}
    var data = parseJSON(raw);
    if(!data || typeof data !== 'object'){ return; }
    afShopShowCheckoutSuccessModal(data.checkout || {}, data.links || {});
  }

  window.afShopShowCheckoutSuccessModal = function(checkout, links){
    var modal = afShopModalRoot();
    if(!modal){
      if(links && links.shop){ window.location = links.shop; }
      return;
    }
    checkout = checkout || {};
    links = links || {};
    var content = modal.querySelector('[data-af-shop-modal-content]');
    if(content){
      var rows = '<p class="af-shop-modal__title" id="af-shop-modal-title">Покупка успешна</p>'
        + '<p>Списано: <strong>' + escapeHtml(checkout.total_major || '0.00') + ' ' + escapeHtml(checkout.currency_symbol || '') + '</strong></p>';
      if(checkout.balance_major != null){
        rows += '<p>Текущий баланс: <strong>' + escapeHtml(checkout.balance_major) + ' ' + escapeHtml(checkout.currency_symbol || '') + '</strong></p>';
      }
      rows += '<div class="af-shop-modal__actions">'
        + '<button type="button" class="af-shop-btn" data-af-modal-shop>Вернуться в магазин</button>'
        + '<button type="button" class="af-shop-btn" data-af-modal-inventory>Перейти в инвентарь</button>'
        + '</div>';
      content.innerHTML = rows;
    }

    var shopBtn = modal.querySelector('[data-af-modal-shop]');
    if(shopBtn){
      shopBtn.onclick = function(){
        afShopHideCheckoutSuccessModal();
        window.location = normalizeActionUrl(links.shop || 'misc.php?action=shop&shop=game');
      };
    }
    var invBtn = modal.querySelector('[data-af-modal-inventory]');
    if(invBtn){
      invBtn.onclick = function(){ window.location = normalizeActionUrl(links.inventory || 'misc.php?action=inventory'); };
    }

    modal.hidden = false;
    document.body.classList.add('af-shop-modal-open');
  };

  function treeStorageKey(shop, catId){
    return 'af_shop_tree_' + String(shop || 'game') + '_' + String(catId || 0);
  }

  function applyCatState(toggle, collapsed){
    var catId = toggle.getAttribute('data-cat');
    var root = toggle.closest('.af-shop-cat-node');
    var children = root ? root.querySelector(':scope > .af-cat-children[data-parent="' + catId + '"]') : null;
    if(!root || !children){ return; }
    root.classList.toggle('is-collapsed', !!collapsed);
    root.classList.toggle('is-expanded', !collapsed);
    children.hidden = !!collapsed;
    toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    var icon = toggle.querySelector('.af-cat-toggle__icon');
    if(icon){ icon.textContent = collapsed ? '▸' : '▾'; }
  }

  function initCategoryTree(){
    var shopRoot = document.querySelector('.af-shop-wrap[data-shop]');
    if(!shopRoot){ return; }
    var shop = shopRoot.getAttribute('data-shop') || 'game';
    shopRoot.querySelectorAll('.af-cat-toggle[data-cat]').forEach(function(toggle){
      var catId = toggle.getAttribute('data-cat');
      var st = '';
      try { st = localStorage.getItem(treeStorageKey(shop, catId)) || ''; } catch(err) { st = ''; }
      applyCatState(toggle, st === 'collapsed');
    });
  }

  window.afShopToast = function(msg, type){
    var root = document.querySelector('.af-shop-toasts');
    if(!root){ return; }
    var item = document.createElement('div');
    item.className = 'af-shop-toast af-shop-toast--' + (type === 'error' ? 'error' : 'success');
    item.textContent = msg || '';
    root.appendChild(item);
    setTimeout(function(){ item.classList.add('is-hide'); }, 2200);
    setTimeout(function(){ if(item.parentNode){ item.parentNode.removeChild(item); } }, 2800);
  };

  function withLoading(btn, promise){
    if(!btn || btn.dataset.loading === '1'){ return Promise.resolve({ok:false, error:'busy'}); }
    btn.disabled = true;
    btn.dataset.loading = '1';
    return promise.finally(function(){
      btn.disabled = false;
      delete btn.dataset.loading;
    });
  }

  var AF_EQUIP_META = {
    head:{label:'Голова'}, body:{label:'Тело'}, hands:{label:'Руки'}, legs:{label:'Ноги'}, feet:{label:'Ступни'}, back:{label:'Спина'}, belt:{label:'Пояс'},
    mainhand:{label:'Основная рука'}, offhand:{label:'Вторая рука'}, twohand:{label:'Двуручное'}, ranged:{label:'Дистанционное'}, melee:{label:'Ближний бой'}, accessory:{label:'Аксессуар'}, unique:{label:'Уникалка'}, artifact:{label:'Артефакт'}
  };

  function updateEquipmentSlot(node, data){
    if(!node){ return; }
    var slotCode = node.getAttribute('data-slot-code') || '';
    var meta = AF_EQUIP_META[slotCode] || {label:slotCode};
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
    if(iconNode){
      iconNode.innerHTML = iconUrl ? ('<img src="' + escapeHtml(iconUrl) + '" alt="' + escapeHtml(title) + '">') : '<span class="af-equip-slot__placeholder">Пусто</span>';
    }
  }

  function showInventoryModal(html){
    var modal = afShopModalRoot();
    if(!modal){ return; }
    var content = modal.querySelector('[data-af-shop-modal-content]');
    if(content){ content.innerHTML = html; }
    modal.hidden = false;
    document.body.classList.add('af-shop-modal-open');
  }

  function renderEquipmentState(equipped, scope){
    var root = scope && scope.querySelector ? scope : document;
    root.querySelectorAll('[data-af-equip-slot][data-slot-code]').forEach(function(node){
      var slotCode = node.getAttribute('data-slot-code') || '';
      updateEquipmentSlot(node, equipped && equipped[slotCode] ? equipped[slotCode] : null);
    });
  }


  function inventoryContext(sourceNode){
    var root = null;
    if(sourceNode && sourceNode.closest){
      root = sourceNode.closest('.af-inventory[data-uid]');
    }
    if(!root && sourceNode && sourceNode.querySelector){
      root = sourceNode.querySelector('.af-inventory[data-uid]');
    }
    if(!root){
      root = document.querySelector('.af-inventory[data-uid]');
    }
    var uid = root ? (root.getAttribute('data-uid') || '0') : '0';
    var canEdit = !!(root && root.getAttribute('data-can-edit') === '1');
    afShopDebug('inventory uid, canEdit', uid, canEdit);
    return {uid: uid, canEdit: canEdit, root: root};
  }

  function loadEquipped(scope){
    var panel = scope && scope.querySelector ? scope.querySelector('[data-af-equipment-panel][data-uid]') : document.querySelector('[data-af-equipment-panel][data-uid]');
    if(!panel){ return Promise.resolve(); }
    var ctx = inventoryContext(panel);
    var uid = panel.getAttribute('data-uid') || ctx.uid || '0';
    return getJSON('misc.php?action=inventory_equipped_get&uid=' + encodeURIComponent(uid)).then(function(r){
      if(!r || !r.ok){ afShopToast((r && r.error) || 'Не удалось загрузить экипировку', 'error'); return; }
      renderEquipmentState(r.equipped || {}, panel.closest('.af-inventory') || scope || document);
    });
  }


  function hideItemPopover(){
    var pop = document.querySelector('[data-af-item-popover]');
    if(pop){ pop.hidden = true; pop.innerHTML = ''; }
  }

  function handleInventorySlotClick(slotNode){
    if(!slotNode){ return; }
    var invId = parseInt(slotNode.getAttribute('data-inv-id') || '0', 10);
    if(invId <= 0){ return; }
    var title = slotNode.getAttribute('data-title') || 'Предмет';
    var rarity = slotNode.getAttribute('data-rarity') || 'common';
    var qty = (slotNode.querySelector('.af-inventory-qty') || {}).textContent || '1';
    var equipSlot = slotNode.getAttribute('data-equip-slot') || '';
    var canEquip = slotNode.getAttribute('data-is-equippable') === '1';
    var sourceType = slotNode.getAttribute('data-source-type') || 'kb';
    var isVisualItem = sourceType === 'appearance' || slotNode.getAttribute('data-is-visual-item') === '1';
    var activeApplied = slotNode.getAttribute('data-appearance-active') === '1';
    var pop = document.querySelector('[data-af-item-popover]');
    if(!pop){ return; }
    var actions = '';
    if(isVisualItem){
      actions += '<button type="button" class="af-shop-btn" data-af-appearance-apply-btn data-inv-id="' + escapeHtml(String(invId)) + '">' + (activeApplied ? 'Активен' : 'Активировать') + '</button>';
      actions += '<button type="button" class="af-shop-btn" data-af-appearance-unapply-btn data-target-key="' + escapeHtml(slotNode.getAttribute('data-appearance-target') || '') + '"' + (activeApplied ? '' : ' disabled') + '>Снять</button>';
    } else {
      actions += '<button type="button" class="af-shop-btn" data-af-equip-btn data-can-equip="' + (canEquip ? '1' : '0') + '" data-inv-id="' + escapeHtml(String(invId)) + '" data-slot-code="' + escapeHtml(equipSlot) + '">Надеть</button>';
      actions += '<button type="button" class="af-shop-btn" data-af-sell-btn>Продать</button>';
    }
    pop.innerHTML = '<div class="af-item-popover__title">' + escapeHtml(title) + '</div>'
      + '<div class="af-item-popover__meta">' + (isVisualItem ? 'Визуальный пресет' : ('Редкость: ' + escapeHtml(rarity))) + ' · Кол-во: ' + escapeHtml(qty) + '</div>'
      + '<div class="af-item-popover__actions">' + actions + '</div>';
    var rect = slotNode.getBoundingClientRect();
    pop.style.left = (window.scrollX + rect.left) + 'px';
    pop.style.top = (window.scrollY + rect.bottom + 8) + 'px';
    pop.hidden = false;
  }

  function handleEquipmentSlotClick(slotNode){
    if(!slotNode){ return; }
    var ctx = inventoryContext(slotNode);
    if(!ctx.canEdit){ return; }
    var invId = parseInt(slotNode.getAttribute('data-inv-id') || '0', 10);
    var slotCode = slotNode.getAttribute('data-slot-code') || '';
    var slotLabel = slotNode.getAttribute('data-slot-label') || slotCode;
    var title = slotNode.getAttribute('data-item-title') || 'Пусто';
    var icon = slotNode.getAttribute('data-item-icon') || '';
    var rarity = slotNode.getAttribute('data-item-rarity') || 'common';
    if(invId > 0){
      showInventoryModal(
        '<p class="af-shop-modal__title" id="af-shop-modal-title">' + escapeHtml(title) + '</p>'
        + '<p><strong>Слот:</strong> ' + escapeHtml(slotLabel) + '</p>'
        + '<p><strong>Редкость:</strong> ' + escapeHtml(rarity) + '</p>'
        + (icon ? '<p><img src="' + escapeHtml(icon) + '" alt="' + escapeHtml(title) + '" class="af-equip-modal-icon"></p>' : '')
        + '<div class="af-shop-modal__actions"><button type="button" class="af-shop-btn" data-af-unequip-btn data-slot-code="' + escapeHtml(slotCode) + '">Снять</button></div>'
      );
      return;
    }
    showInventoryModal(
      '<p class="af-shop-modal__title" id="af-shop-modal-title">' + escapeHtml(slotLabel) + '</p>'
      + '<p>Подбираем предметы для слота…</p>'
    );

    getJSON('misc.php?action=inventory_equippable_list&uid=' + encodeURIComponent(ctx.uid || '0') + '&slot_code=' + encodeURIComponent(slotCode)).then(function(r){
      if(!r || !r.ok){
        showInventoryModal(
          '<p class="af-shop-modal__title" id="af-shop-modal-title">' + escapeHtml(slotLabel) + '</p>'
          + '<p class="af-status-error">' + escapeHtml((r && r.error) || 'Не удалось загрузить список предметов') + '</p>'
        );
        return;
      }

      var items = Array.isArray(r.items) ? r.items : [];
      if(!items.length){
        showInventoryModal(
          '<p class="af-shop-modal__title" id="af-shop-modal-title">' + escapeHtml(slotLabel) + '</p>'
          + '<p><em>Нет подходящих предметов.</em></p>'
        );
        return;
      }

      var html = '<p class="af-shop-modal__title" id="af-shop-modal-title">' + escapeHtml(slotLabel) + '</p>'
        + '<div class="af-equip-picker-grid">'
        + items.map(function(item){
            var title = item && item.title ? item.title : ('#' + String(item && item.kb_id ? item.kb_id : '0'));
            var rarity = item && item.rarity ? item.rarity : 'common';
            var icon = item && item.icon_url ? item.icon_url : '';
            var invIdItem = item && item.inv_id ? String(item.inv_id) : '0';
            return '<div class="af-equip-picker-item rarity-' + escapeHtml(rarity) + '">'
              + (icon ? '<img src="' + escapeHtml(icon) + '" alt="' + escapeHtml(title) + '" class="af-equip-picker-item__icon">' : '<div class="af-equip-picker-item__icon af-equip-picker-item__icon--empty">?</div>')
              + '<div class="af-equip-picker-item__meta"><div class="af-equip-picker-item__title">' + escapeHtml(title) + '</div><div class="af-equip-picker-item__rarity">' + escapeHtml(rarity) + '</div></div>'
              + '<button type="button" class="af-shop-btn" data-af-slot-equip-btn data-inv-id="' + escapeHtml(invIdItem) + '" data-slot-code="' + escapeHtml(slotCode) + '">Надеть</button>'
              + '</div>';
          }).join('')
        + '</div>';
      showInventoryModal(html);
    });
  }

  document.addEventListener('click', function(e){


    var modalClose = e.target.closest('[data-af-shop-modal-close]');
    if(modalClose){
      e.preventDefault();
      afShopHideCheckoutSuccessModal();
      return;
    }

    var invSlot = e.target.closest('.af-inv-slot[data-inv-id]');
    if(invSlot){
      e.preventDefault();
      handleInventorySlotClick(invSlot);
      return;
    }

    var equipSlot = e.target.closest('[data-af-equip-slot][data-slot-code]');
    if(equipSlot){
      e.preventDefault();
      handleEquipmentSlotClick(equipSlot);
      return;
    }

    var equipBtn = e.target.closest('[data-af-equip-btn][data-inv-id]');
    if(equipBtn){
      e.preventDefault();
      var invIdEquip = parseInt(equipBtn.getAttribute('data-inv-id') || '0', 10);
      if(invIdEquip <= 0){ return; }
      if(equipBtn.getAttribute('data-can-equip') === '0'){
        afShopToast('Этот предмет нельзя экипировать.', 'error');
        return;
      }
      var ctxEquip = inventoryContext(equipBtn);
      withLoading(equipBtn, post('misc.php?action=inventory_equip', {uid:ctxEquip.uid || '0', inv_id:invIdEquip, slot_code:equipBtn.getAttribute('data-slot-code') || ''})).then(function(r){
        if(r && r.ok){
          hideItemPopover();
          if(r.state && r.state.equipment){ renderEquipmentState(r.state.equipment, ctxEquip.root || document); }
          else if(r.equipped){ renderEquipmentState(r.equipped, ctxEquip.root || document); }
          else { loadEquipped(ctxEquip.root || document); }
          afShopToast('Предмет экипирован', 'success');
        } else if(r && r.error !== 'busy'){
          afShopToast(r.error || 'Не удалось надеть предмет', 'error');
        }
      });
      return;
    }

    var applyAppearanceBtn = e.target.closest('[data-af-appearance-apply-btn][data-inv-id]');
    if(applyAppearanceBtn){
      e.preventDefault();
      var invIdApply = parseInt(applyAppearanceBtn.getAttribute('data-inv-id') || '0', 10);
      if(invIdApply <= 0){ return; }
      var ctxApply = inventoryContext(applyAppearanceBtn);
      withLoading(applyAppearanceBtn, post('misc.php?action=inventory_appearance_apply', {uid:ctxApply.uid || '0', inv_id:invIdApply})).then(function(r){
        if(r && r.ok){
          hideItemPopover();
          safeMountInventory(ctxApply.root || document);
          loadEquipped(ctxApply.root || document);
          afShopToast('Пресет применён', 'success');
        } else if(r && r.error !== 'busy'){
          afShopToast(r.error || 'Не удалось применить пресет', 'error');
        }
      });
      return;
    }

    var unapplyAppearanceBtn = e.target.closest('[data-af-appearance-unapply-btn]');
    if(unapplyAppearanceBtn){
      e.preventDefault();
      var ctxUn = inventoryContext(unapplyAppearanceBtn);
      withLoading(unapplyAppearanceBtn, post('misc.php?action=inventory_appearance_unapply', {uid:ctxUn.uid || '0', target_key: unapplyAppearanceBtn.getAttribute('data-target-key') || ''})).then(function(r){
        if(r && r.ok){
          hideItemPopover();
          safeMountInventory(ctxUn.root || document);
          loadEquipped(ctxUn.root || document);
          afShopToast('Пресет снят', 'success');
        } else if(r && r.error !== 'busy'){
          afShopToast(r.error || 'Не удалось снять пресет', 'error');
        }
      });
      return;
    }

    var sellBtn = e.target.closest('[data-af-sell-btn]');
    if(sellBtn){
      e.preventDefault();
      afShopToast('Скоро', 'success');
      return;
    }

    var slotEquipBtn = e.target.closest('[data-af-slot-equip-btn][data-inv-id][data-slot-code]');
    if(slotEquipBtn){
      e.preventDefault();
      var invIdSlotEquip = parseInt(slotEquipBtn.getAttribute('data-inv-id') || '0', 10);
      var slotCodeEquip = slotEquipBtn.getAttribute('data-slot-code') || '';
      if(invIdSlotEquip <= 0 || !slotCodeEquip){ return; }
      var ctxSlotEquip = inventoryContext(slotEquipBtn);
      withLoading(slotEquipBtn, post('misc.php?action=inventory_equip', {uid:ctxSlotEquip.uid || '0', inv_id:invIdSlotEquip, slot_code:slotCodeEquip})).then(function(r){
        if(r && r.ok){
          afShopHideCheckoutSuccessModal();
          if(r.equipped){ renderEquipmentState(r.equipped, ctxSlotEquip.root || document); }
          else { loadEquipped(ctxSlotEquip.root || document); }
          afShopToast('Предмет экипирован', 'success');
        } else if(r && r.error !== 'busy'){
          afShopToast(r.error || 'Не удалось надеть предмет', 'error');
        }
      });
      return;
    }

    var unequipBtn = e.target.closest('[data-af-unequip-btn][data-slot-code]');
    if(unequipBtn){
      e.preventDefault();
      var slotCode = unequipBtn.getAttribute('data-slot-code') || '';
      var ctxUnequip = inventoryContext(unequipBtn);
      withLoading(unequipBtn, post('misc.php?action=inventory_unequip', {uid:ctxUnequip.uid || '0', slot_code:slotCode})).then(function(r){
        if(r && r.ok){
          afShopHideCheckoutSuccessModal();
          hideItemPopover();
          if(r.state && r.state.equipment){ renderEquipmentState(r.state.equipment, ctxUnequip.root || document); }
          else { loadEquipped(ctxUnequip.root || document); }
          afShopToast('Слот освобожден', 'success');
        } else if(r && r.error !== 'busy'){
          afShopToast(r.error || 'Не удалось снять предмет', 'error');
        }
      });
      return;
    }

    var catToggle = e.target.closest('.af-cat-toggle[data-cat]');
    if(catToggle){
      e.preventDefault();
      e.stopPropagation();
      var wrap = catToggle.closest('.af-shop-wrap[data-shop]');
      var shop = wrap ? (wrap.getAttribute('data-shop') || 'game') : 'game';
      var collapsed = catToggle.getAttribute('aria-expanded') === 'true';
      applyCatState(catToggle, collapsed);
      try { localStorage.setItem(treeStorageKey(shop, catToggle.getAttribute('data-cat')), collapsed ? 'collapsed' : 'expanded'); } catch(err) {}
      return;
    }

    var add = e.target.closest('.af-add-cart');
    if(add){
      e.preventDefault(); e.stopPropagation();
      var wrap = add.closest('[data-shop]');
      var shop = wrap ? wrap.getAttribute('data-shop') : 'game';
      withLoading(add, post('misc.php?action=shop_add_to_cart&shop=' + encodeURIComponent(shop), {slot:add.getAttribute('data-slot'), qty:1}))
        .then(function(res){ if(res.ok){ afShopToast('Added to cart', 'success'); } else if(res.error !== 'busy'){ afShopToast(res.error || 'Error', 'error'); } });
      return;
    }

    var buy = e.target.closest('.af-buy-now');
    if(buy){
      e.preventDefault(); e.stopPropagation();
      var wrap2 = buy.closest('[data-shop]');
      var shop2 = wrap2 ? wrap2.getAttribute('data-shop') : 'game';
      withLoading(buy, post('misc.php?action=shop_add_to_cart&shop=' + encodeURIComponent(shop2), {slot:buy.getAttribute('data-slot'), qty:1}))
        .then(function(res){ if(res.ok){ window.location=normalizeActionUrl('misc.php?action=shop_cart&shop='+encodeURIComponent(shop2)); } else if(res.error !== 'busy'){ afShopToast(res.error || 'Error', 'error'); } });
      return;
    }

    var checkout = e.target.closest('.af-checkout');
    if(checkout){
      e.preventDefault(); e.stopPropagation();
      var shop3 = (checkout.closest('[data-shop]') || document.body).getAttribute('data-shop') || 'game';
      withLoading(checkout, post('misc.php?action=shop_checkout&shop=' + encodeURIComponent(shop3), {}))
        .then(function(res){
          if(res.ok){
            persistCheckoutSuccess(res);
            window.location.reload();
          } else if(res.error !== 'busy'){
            afShopToast(res.error || 'Error', 'error');
          }
        });
      return;
    }

    var qtyBtn = e.target.closest('.af-cart-qty');
    if(qtyBtn){
      e.preventDefault(); e.stopPropagation();
      var item = qtyBtn.closest('.af-cart-item');
      var id = item.getAttribute('data-item-id');
      var curr = parseInt((item.querySelector('.af-cart-item__qty span')||{}).textContent||'1',10);
      var next = curr + parseInt(qtyBtn.getAttribute('data-delta'),10);
      post('misc.php?action=shop_update_cart', {item_id:id, qty:next}).then(function(){ location.reload(); });
      return;
    }

    var remove = e.target.closest('.af-cart-remove');
    if(remove){
      e.preventDefault(); e.stopPropagation();
      var item2 = remove.closest('.af-cart-item');
      post('misc.php?action=shop_update_cart', {item_id:item2.getAttribute('data-item-id'), qty:0}).then(function(){ location.reload(); });
      return;
    }

    var catSave = e.target.closest('.af-cat-save');
    if(catSave){
      e.preventDefault();
      var row = catSave.closest('tr[data-cat-id]');
      var wrap3 = document.querySelector('.af-manage[data-shop]');
      if(!row || !wrap3){ return; }
      var shop4 = wrap3.getAttribute('data-shop') || 'game';
      var status = document.getElementById('af-manage-category-status');
      post('misc.php?action=shop_manage_category_update&shop=' + encodeURIComponent(shop4), {
        cat_id: row.getAttribute('data-cat-id'),
        title: (row.querySelector('.af-cat-title-input') || {}).value || '',
        description: (row.querySelector('.af-cat-description-input') || {}).value || '',
        parent_id: (row.querySelector('.af-cat-parent-input') || {}).value || 0,
        enabled: (row.querySelector('.af-cat-enabled-input') || {}).checked ? 1 : 0,
        sortorder: (row.querySelector('.af-cat-sortorder-input') || {}).value || 0
      }).then(function(r){
        if(r.ok){
          setStatus(status, 'Category updated', true);
          window.location.reload();
        } else {
          setStatus(status, r.error || 'Update failed', false);
        }
      });
      return;
    }

    var catDelete = e.target.closest('.af-cat-delete');
    if(catDelete){
      e.preventDefault();
      var row2 = catDelete.closest('tr[data-cat-id]');
      var wrap4 = document.querySelector('.af-manage[data-shop]');
      if(!row2 || !wrap4 || !confirm('Delete category?')){ return; }
      var shop5 = wrap4.getAttribute('data-shop') || 'game';
      var status2 = document.getElementById('af-manage-category-status');
      post('misc.php?action=shop_manage_category_delete&shop=' + encodeURIComponent(shop5), {cat_id: row2.getAttribute('data-cat-id')}).then(function(r){
        if(r.ok){
          setStatus(status2, 'Category deleted', true);
          window.location.reload();
        } else {
          setStatus(status2, r.error || 'Delete failed', false);
        }
      });
      return;
    }

    var pick = e.target.closest('#af-add-slot-btn');
    if(pick){
      e.preventDefault();
      var panel = document.getElementById('af-slot-create-panel');
      if(panel){ panel.hidden = !panel.hidden; }
      return;
    }

    var kbItem = e.target.closest('.af-kb-pick-item');
    if(kbItem){
      e.preventDefault();
      var prefix = kbItem.getAttribute('data-editor-prefix') || 'create';
      setKbSelection(prefix, {
        kb_id: kbItem.getAttribute('data-kb-id') || '0',
        kb_type: kbItem.getAttribute('data-kb-type') || 'item',
        kb_key: kbItem.getAttribute('data-kb-key') || '',
        title: kbItem.getAttribute('data-kb-title') || ''
      });
      return;
    }

    var apItem = e.target.closest('.af-appearance-pick-item');
    if(apItem){
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
    if(createSubmit){
      e.preventDefault();
      var rootCreate = document.querySelector('.af-manage-slots[data-shop]');
      if(!rootCreate){ return; }
      var shopCreate = rootCreate.getAttribute('data-shop') || 'game';
      var catCreate = rootCreate.getAttribute('data-cat-id') || '0';
      var statusCreate = document.getElementById('af-manage-slot-status');
      var payloadCreate = buildSlotPayload('create');
      payloadCreate.cat_id = catCreate;
      post('misc.php?action=shop_manage_slot_create&shop=' + encodeURIComponent(shopCreate), payloadCreate).then(function(r){
        if(r.ok){
          setStatus(statusCreate, 'Slot created', true);
          loadSlots(shopCreate, catCreate);
        } else {
          setStatus(statusCreate, r.error || 'Failed to create slot', false);
        }
      });
      return;
    }

    var saveSlot = e.target.closest('.af-slot-save');
    if(saveSlot){
      e.preventDefault();
      var card = saveSlot.closest('.af-slot-card');
      var root2 = document.querySelector('.af-manage-slots[data-shop]');
      if(!card || !root2){ return; }
      var shop7 = root2.getAttribute('data-shop') || 'game';
      var status4 = document.getElementById('af-manage-slot-status');
      var payloadUpdate = buildSlotPayload('slot-' + card.getAttribute('data-slot-id'));
      payloadUpdate.slot_id = card.getAttribute('data-slot-id');
      post('misc.php?action=shop_manage_slot_update&shop=' + encodeURIComponent(shop7), payloadUpdate).then(function(r){
        if(r.ok){
          setStatus(status4, 'Slot saved', true);
          loadSlots(shop7, root2.getAttribute('data-cat-id') || '0');
        } else {
          setStatus(status4, r.error || 'Save failed', false);
        }
      });
      return;
    }

    var delSlot = e.target.closest('.af-slot-delete');
    if(delSlot){
      e.preventDefault();
      var card2 = delSlot.closest('.af-slot-card');
      var root3 = document.querySelector('.af-manage-slots[data-shop]');
      if(!card2 || !root3 || !confirm('Delete slot?')){ return; }
      var shop8 = root3.getAttribute('data-shop') || 'game';
      var status5 = document.getElementById('af-manage-slot-status');
      post('misc.php?action=shop_manage_slot_delete&shop=' + encodeURIComponent(shop8), {slot_id: card2.getAttribute('data-slot-id')}).then(function(r){
        if(r.ok){ card2.remove(); setStatus(status5, 'Slot deleted', true); }
        else { setStatus(status5, r.error || 'Delete failed', false); }
      });
      return;
    }
  });


  var categoryForm = document.getElementById('af-manage-category-form');
  if(categoryForm){
    var statusNode = document.getElementById('af-manage-category-status');
    categoryForm.addEventListener('submit', function(e){
      e.preventDefault();
      var fd = new FormData(categoryForm);
      var shop = categoryForm.getAttribute('data-shop') || 'game';
      var payload = Object.fromEntries(fd.entries());
      payload.my_post_key = key();
      post('misc.php?action=shop_manage_category_create&shop=' + encodeURIComponent(shop), payload).then(function(r){
        if(r.ok){ setStatus(statusNode, 'Category created', true); location.reload(); }
        else { setStatus(statusNode, r.error || 'Failed to create category', false); }
      });
    });
  }

  var rebuild = document.getElementById('af-rebuild-sortorder');
  if(rebuild){
    rebuild.addEventListener('click', function(){
      var wrap = document.querySelector('.af-manage[data-shop]');
      if(!wrap){ return; }
      var shop = wrap.getAttribute('data-shop') || 'game';
      post('misc.php?action=shop_manage_sortorder_rebuild&shop=' + encodeURIComponent(shop), {}).then(function(r){
        var status = document.getElementById('af-manage-category-status');
        setStatus(status, r.ok ? 'Sortorder rebuilt' : (r.error || 'Failed'), !!r.ok);
        if(r.ok){ setTimeout(function(){ location.reload(); }, 300); }
      });
    });
  }

  document.addEventListener('keydown', function(e){ if(e.key === 'Escape'){ afShopHideCheckoutSuccessModal(); } });

  initCategoryTree();
  restoreCheckoutSuccessModal();
  safeMountInventory(document);
  runHealthCheck();

  function sourcePrefix(id){ return 'af-' + id; }
  function byId(id){ return document.getElementById(id); }
  function asBool(value){ return String(value || '') === '1'; }

  function setSourceMode(prefix, sourceType){
    var normalized = sourceType === 'appearance' ? 'appearance' : 'kb';
    var sourceNode = byId(sourcePrefix(prefix + '-source-type'));
    if(sourceNode){ sourceNode.value = normalized; }
    var kbWrap = byId(sourcePrefix(prefix + '-kb-fields'));
    var apWrap = byId(sourcePrefix(prefix + '-appearance-fields'));
    if(kbWrap){ kbWrap.hidden = normalized !== 'kb'; }
    if(apWrap){ apWrap.hidden = normalized !== 'appearance'; }
  }

  function setKbSelection(prefix, item){
    var idNode = byId(sourcePrefix(prefix + '-kb-id'));
    var typeNode = byId(sourcePrefix(prefix + '-kb-type'));
    var keyNode = byId(sourcePrefix(prefix + '-kb-key'));
    var sourceRefNode = byId(sourcePrefix(prefix + '-source-ref-id'));
    var kbId = item && item.kb_id ? item.kb_id : '0';
    if(idNode){ idNode.value = kbId; }
    if(typeNode){ typeNode.value = item && item.kb_type ? item.kb_type : 'item'; }
    if(keyNode){ keyNode.value = item && item.kb_key ? item.kb_key : ''; }
    if(sourceRefNode){ sourceRefNode.value = kbId; }
  }

  function appearanceSearchEndpoint(){
    return window.location.pathname && /shop_manage\.php$/i.test(window.location.pathname) ? 'shop_manage.php' : 'misc.php';
  }

  function appearanceSearchEmptyMessage(groupLabel, query){
    var parts = [];
    if(groupLabel){ parts.push('группа: ' + groupLabel); }
    if(query){ parts.push('поиск: ' + query); }
    return 'Nothing found. ' + (parts.length ? ('Фильтрация — ' + parts.join(', ') + '.') : '');
  }

  function renderAppearanceSummary(prefix, item){
    var node = byId(sourcePrefix(prefix + '-appearance-summary'));
    if(!node){ return; }
    if(!item || (!item.preset_id && !item.slug)){
      node.innerHTML = 'Preset not selected.';
      return;
    }
    var preview = item.preview_image ? ('<img src="' + escapeHtml(item.preview_image) + '" alt="" style="width:48px;height:48px;object-fit:cover;vertical-align:middle;margin-right:8px;">') : '';
    var enabledLabel = asBool(item.enabled) ? 'enabled' : 'disabled';
    node.innerHTML = preview + '<strong>' + escapeHtml(item.title || item.slug || ('#' + String(item.preset_id || '0'))) + '</strong>'
      + '<div><small>ID: ' + escapeHtml(String(item.preset_id || '0')) + ' / slug: ' + escapeHtml(item.slug || '') + '</small></div>'
      + '<div><small>group: ' + escapeHtml(item.group_label || item.group || '') + ' / target: ' + escapeHtml(item.target_label || item.target_key || '') + ' / ' + enabledLabel + '</small></div>'
      + (item.description ? ('<div><small>' + escapeHtml(item.description) + '</small></div>') : '');
  }

  function setAppearanceSelection(prefix, item){
    var idNode = byId(sourcePrefix(prefix + '-preset-id'));
    var slugNode = byId(sourcePrefix(prefix + '-preset-slug'));
    var sourceRefNode = byId(sourcePrefix(prefix + '-source-ref-id'));
    var presetId = item && item.preset_id ? item.preset_id : '0';
    if(idNode){ idNode.value = presetId; }
    if(slugNode && item && item.slug){ slugNode.value = item.slug; }
    if(sourceRefNode){ sourceRefNode.value = presetId; }
    renderAppearanceSummary(prefix, item || {});
  }

  function buildSlotPayload(prefix){
    var sourceTypeNode = byId(sourcePrefix(prefix + '-source-type'));
    var sourceType = sourceTypeNode ? (sourceTypeNode.value || 'kb') : 'kb';
    return {
      source_type: sourceType,
      source_ref_id: sourceType === 'appearance' ? ((byId(sourcePrefix(prefix + '-preset-id')) || {}).value || (byId(sourcePrefix(prefix + '-source-ref-id')) || {}).value || '0') : ((byId(sourcePrefix(prefix + '-kb-id')) || {}).value || (byId(sourcePrefix(prefix + '-source-ref-id')) || {}).value || '0'),
      preset_id: (byId(sourcePrefix(prefix + '-preset-id')) || {}).value || '0',
      preset_slug: (byId(sourcePrefix(prefix + '-preset-slug')) || {}).value || '',
      kb_id: (byId(sourcePrefix(prefix + '-kb-id')) || {}).value || '0',
      kb_type: (byId(sourcePrefix(prefix + '-kb-type')) || {}).value || 'item',
      kb_key: (byId(sourcePrefix(prefix + '-kb-key')) || {}).value || '',
      price: (byId(sourcePrefix(prefix + '-price')) || {}).value || 0,
      currency: (byId(sourcePrefix(prefix + '-currency')) || {}).value || 'credits',
      stock: (byId(sourcePrefix(prefix + '-stock')) || {}).value || -1,
      limit_per_user: (byId(sourcePrefix(prefix + '-limit')) || {}).value || 0,
      enabled: (byId(sourcePrefix(prefix + '-enabled')) || {}).checked ? 1 : 0,
      sortorder: (byId(sourcePrefix(prefix + '-sortorder')) || {}).value || 0
    };
  }

  var slotsRoot = document.querySelector('.af-manage-slots[data-shop]');
  if(slotsRoot){
    var shopCode = slotsRoot.getAttribute('data-shop') || 'game';
    var catId = slotsRoot.getAttribute('data-cat-id') || '0';
    var createPanel = byId('af-slot-create-panel');
    var searchTimer = null;

    function renderKbResults(r, prefix){
      var resNode = byId(sourcePrefix(prefix + '-kb-results')) || byId('af-kb-picker-results');
      if(!resNode){ return; }
      if(!r.ok){ resNode.textContent = r.error || 'Search failed'; return; }
      var items = r.items || [];
      if(!items.length){
        resNode.innerHTML = '<div class="af-kb-item">Nothing found.</div>';
        return;
      }
      resNode.innerHTML = items.map(function(item){
        var desc = item.short ? ('<small>' + escapeHtml(item.short) + '</small>') : '';
        return '<div class="af-kb-item">'
          + '<span>#'+item.kb_id+' '+escapeHtml(item.title || '')+' ['+escapeHtml(item.kb_type || 'item')+'] ['+escapeHtml(item.rarity || 'common')+']</span>'
          + desc
          + ' <button class="af-kb-pick-item" data-editor-prefix="'+escapeHtml(prefix)+'" data-kb-id="'+item.kb_id+'" data-kb-type="'+escapeHtml(item.kb_type || 'item')+'" data-kb-key="'+escapeHtml(item.kb_key || '')+'" data-kb-title="'+escapeHtml(item.title || '')+'" type="button">Выбрать</button></div>';
      }).join('');
    }

    function runKbSearch(prefix){
      var qNode = byId(sourcePrefix(prefix + '-kb-q')) || byId('af-kb-picker-q');
      var typeNode = byId(sourcePrefix(prefix + '-kb-type-filter')) || byId('af-kb-picker-type');
      var rarityNode = byId(sourcePrefix(prefix + '-kb-rarity')) || byId('af-kb-picker-rarity');
      var itemTypeNode = byId(sourcePrefix(prefix + '-kb-item-type')) || byId('af-kb-picker-item-type');
      var spellLevelNode = byId(sourcePrefix(prefix + '-kb-spell-level')) || byId('af-kb-picker-spell-level');
      var spellSchoolNode = byId(sourcePrefix(prefix + '-kb-spell-school')) || byId('af-kb-picker-spell-school');
      var query = 'misc.php?action=shop_kb_search&shop=' + encodeURIComponent(shopCode)
        + '&q=' + encodeURIComponent(qNode ? (qNode.value || '') : '')
        + '&kb_type=' + encodeURIComponent(typeNode ? (typeNode.value || 'all') : 'all')
        + '&rarity=' + encodeURIComponent(rarityNode ? (rarityNode.value || '') : '')
        + '&item_type=' + encodeURIComponent(itemTypeNode ? (itemTypeNode.value || '') : '')
        + '&spell_level=' + encodeURIComponent(spellLevelNode ? (spellLevelNode.value || '') : '')
        + '&spell_school=' + encodeURIComponent(spellSchoolNode ? (spellSchoolNode.value || '') : '');
      getJSON(query).then(function(r){ renderKbResults(r, prefix); });
    }

    function renderAppearanceResults(r, prefix){
      var node = byId(sourcePrefix(prefix + '-appearance-results')) || byId('af-appearance-picker-results');
      if(!node){ return; }
      if(!r.ok){ node.textContent = r.error || 'Search failed'; return; }
      var items = r.items || [];
      if(!items.length){ node.innerHTML = '<div class="af-kb-item">' + escapeHtml(appearanceSearchEmptyMessage(r.group_label || '', ((byId(sourcePrefix(prefix + '-appearance-q')) || byId('af-appearance-picker-q') || {}).value || ''))) + '</div>'; return; }
      node.innerHTML = items.map(function(item){
        var prev = item.preview_image ? ('<img src="'+escapeHtml(item.preview_image)+'" alt="" style="width:32px;height:32px;object-fit:cover;"> ') : '';
        return '<div class="af-kb-item">'
          + prev
          + '<div><strong>#'+escapeHtml(String(item.preset_id))+' '+escapeHtml(item.title || '')+'</strong> <code>'+escapeHtml(item.slug || '')+'</code></div>'
          + '<div><small>' + escapeHtml(item.group_label || item.group || '') + ' / ' + escapeHtml(item.target_label || item.target_key || '') + ' / ' + (item.enabled ? 'enabled' : 'disabled') + '</small></div>'
          + (item.description ? ('<div><small>' + escapeHtml(item.description) + '</small></div>') : '')
          + ' <button class="af-appearance-pick-item" data-editor-prefix="'+escapeHtml(prefix)+'" data-preset-id="'+escapeHtml(String(item.preset_id))+'" data-preset-slug="'+escapeHtml(item.slug || '')+'" data-preset-title="'+escapeHtml(item.title || '')+'" data-target-key="'+escapeHtml(item.target_key || '')+'" data-target-label="'+escapeHtml(item.target_label || '')+'" data-group="'+escapeHtml(item.group || '')+'" data-group-label="'+escapeHtml(item.group_label || '')+'" data-description="'+escapeHtml(item.description || '')+'" data-preview-image="'+escapeHtml(item.preview_image || '')+'" data-enabled="'+escapeHtml(String(item.enabled ? 1 : 0))+'" type="button">Выбрать</button></div>';
      }).join('');
    }

    function runAppearanceSearch(prefix){
      var qNode = byId(sourcePrefix(prefix + '-appearance-q')) || byId('af-appearance-picker-q');
      var groupNode = byId(sourcePrefix(prefix + '-appearance-group')) || byId('af-appearance-picker-group');
      var endpoint = appearanceSearchEndpoint();
      var query = endpoint + '?action=shop_appearance_search&shop=' + encodeURIComponent(shopCode) + '&q=' + encodeURIComponent(qNode ? (qNode.value || '') : '') + '&group=' + encodeURIComponent(groupNode ? (groupNode.value || 'all') : 'all');
      getJSON(query).then(function(r){ renderAppearanceResults(r, prefix); });
    }

    function bindEditor(prefix){
      var sourceNode = byId(sourcePrefix(prefix + '-source-type'));
      if(sourceNode){
        sourceNode.addEventListener('change', function(){
          var st = sourceNode.value || 'kb';
          setSourceMode(prefix, st);
          if(st === 'appearance'){ runAppearanceSearch(prefix); }
          else { runKbSearch(prefix); }
        });
      }
      var apQ = byId(sourcePrefix(prefix + '-appearance-q'));
      if(apQ){
        apQ.addEventListener('input', function(){ clearTimeout(searchTimer); searchTimer = setTimeout(function(){ runAppearanceSearch(prefix); }, 150); });
      }
      var apGroup = byId(sourcePrefix(prefix + '-appearance-group'));
      if(apGroup){ apGroup.addEventListener('change', function(){ runAppearanceSearch(prefix); }); }
      ['kb-q','kb-type-filter','kb-rarity','kb-item-type','kb-spell-level','kb-spell-school'].forEach(function(suffix){
        var node = byId(sourcePrefix(prefix + '-' + suffix));
        if(!node){ return; }
        node.addEventListener('input', function(){ clearTimeout(searchTimer); searchTimer = setTimeout(function(){ runKbSearch(prefix); }, 150); });
        node.addEventListener('change', function(){ runKbSearch(prefix); });
      });
      setSourceMode(prefix, sourceNode ? (sourceNode.value || 'kb') : 'kb');
    }

    bindEditor('create');
    setKbSelection('create', {kb_id:'0',kb_type:'item',kb_key:''});
    setAppearanceSelection('create', {});
    runKbSearch('create');
    runAppearanceSearch('create');
    loadSlots(shopCode, catId);
  }

  function slotEditorHtml(prefix, row){
    var sourceType = row.source_type || 'kb';
    var appearanceSummary = row.source_type === 'appearance'
      ? ((row.appearance_preview_image ? '<img src="'+escapeHtml(row.appearance_preview_image)+'" alt="" style="width:48px;height:48px;object-fit:cover;vertical-align:middle;margin-right:8px;">' : '')
        + '<strong>' + escapeHtml(row.appearance_preset_title || row.title || '') + '</strong>'
        + ' <small>slug: ' + escapeHtml(row.appearance_preset_slug || '') + ' / group: ' + escapeHtml(row.appearance_group_label || row.appearance_group || '') + ' / target: ' + escapeHtml(row.appearance_target_label || row.appearance_target || '') + ' / ' + (row.appearance_enabled ? 'enabled' : 'disabled') + '</small>')
      : 'Preset not selected.';
    return '<div class="af-slot-card '+escapeHtml(row.rarity_class || 'af-rarity-common')+'" data-slot-id="'+row.slot_id+'">'
      + '<div><strong>#'+row.slot_id+'</strong> — '+escapeHtml(row.title || '')+'</div>'
      + '<div><label>Source <select id="'+sourcePrefix(prefix+'-source-type')+'"><option value="kb"'+(sourceType === 'kb' ? ' selected' : '')+'>KB</option><option value="appearance"'+(sourceType === 'appearance' ? ' selected' : '')+'>Appearance</option></select><input type="hidden" id="'+sourcePrefix(prefix+'-source-ref-id')+'" value="'+escapeHtml(String(row.source_ref_id || row.kb_id || 0))+'"></label></div>'
      + '<div><small>' + (sourceType === 'appearance'
          ? ('Appearance preset #' + escapeHtml(String(row.source_ref_id || 0)) + ' / slug ' + escapeHtml(row.appearance_preset_slug || '') + ' / group ' + escapeHtml(row.appearance_group_label || row.appearance_group || '') + ' / target ' + escapeHtml(row.appearance_target_label || row.appearance_target || ''))
          : ('KB #' + escapeHtml(String(row.kb_id || 0)) + ' / ' + escapeHtml(row.kb_type || 'item') + ' / ' + escapeHtml(row.kb_key || '')))
      + '</small></div>'
      + '<div class="af-kb-picker-filters">'
      + '<label>Price <input type="number" id="'+sourcePrefix(prefix+'-price')+'" value="'+escapeHtml(row.price_major || '0.00')+'" min="0" step="0.01"></label>'
      + '<label>Currency <input type="text" id="'+sourcePrefix(prefix+'-currency')+'" value="'+escapeHtml(row.currency || 'credits')+'"></label>'
      + '<label>Stock <input type="number" id="'+sourcePrefix(prefix+'-stock')+'" value="'+escapeHtml(String(row.stock == null ? -1 : row.stock))+'"></label>'
      + '<label>Limit/user <input type="number" id="'+sourcePrefix(prefix+'-limit')+'" value="'+escapeHtml(String(row.limit_per_user || 0))+'" min="0"></label>'
      + '<label>Sort <input type="number" id="'+sourcePrefix(prefix+'-sortorder')+'" value="'+escapeHtml(String(row.sortorder || 0))+'"></label>'
      + '<label><input type="checkbox" id="'+sourcePrefix(prefix+'-enabled')+'" '+(row.enabled ? 'checked' : '')+'> Enabled</label>'
      + '</div>'
      + '<div id="'+sourcePrefix(prefix+'-kb-fields')+'"'+(sourceType === 'kb' ? '' : ' hidden')+'>'
      + '<div class="af-kb-picker-filters">'
      + '<label>KB ID <input type="number" id="'+sourcePrefix(prefix+'-kb-id')+'" value="'+escapeHtml(String(row.kb_id || 0))+'"></label>'
      + '<label>KB type <input type="text" id="'+sourcePrefix(prefix+'-kb-type')+'" value="'+escapeHtml(row.kb_type || 'item')+'"></label>'
      + '<label>KB key <input type="text" id="'+sourcePrefix(prefix+'-kb-key')+'" value="'+escapeHtml(row.kb_key || '')+'"></label>'
      + '</div>'
      + '<div class="af-kb-picker-filters">'
      + '<label>Type <select id="'+sourcePrefix(prefix+'-kb-type-filter')+'"><option value="all">All</option><option value="item">Item</option><option value="spell">Spell / Ritual</option></select></label>'
      + '<label>Search <input type="text" id="'+sourcePrefix(prefix+'-kb-q')+'" placeholder="Search by title or key/slug"></label>'
      + '<label>Rarity <input type="text" id="'+sourcePrefix(prefix+'-kb-rarity')+'" placeholder="rare, uncommon..."></label>'
      + '<label>Item type <input type="text" id="'+sourcePrefix(prefix+'-kb-item-type')+'" placeholder="weapon, armor..."></label>'
      + '<label>Spell level <input type="text" id="'+sourcePrefix(prefix+'-kb-spell-level')+'" placeholder="1,2,3..."></label>'
      + '<label>Spell school <input type="text" id="'+sourcePrefix(prefix+'-kb-spell-school')+'" placeholder="evocation..."></label>'
      + '</div><div id="'+sourcePrefix(prefix+'-kb-results')+'"></div></div>'
      + '<div id="'+sourcePrefix(prefix+'-appearance-fields')+'"'+(sourceType === 'appearance' ? '' : ' hidden')+'>'
      + '<div class="af-kb-picker-filters">'
      + '<label>Preset ID <input type="number" id="'+sourcePrefix(prefix+'-preset-id')+'" value="'+escapeHtml(String(row.appearance_preset_id || row.source_ref_id || 0))+'"></label>'
      + '<label>Preset slug <input type="text" id="'+sourcePrefix(prefix+'-preset-slug')+'" value="'+escapeHtml(row.appearance_preset_slug || '')+'" placeholder="preset-slug"></label>'
      + '</div>'
      + '<div id="'+sourcePrefix(prefix+'-appearance-summary')+'" class="af-slot-source-summary">'+appearanceSummary+'</div>'
      + '<div class="af-kb-picker-filters"><label>Group <select id="'+sourcePrefix(prefix+'-appearance-group')+'"><option value="all">Все группы</option><option value="theme_pack">Общие пак-темы</option><option value="profile_pack">Профили</option><option value="postbit_pack">Постбиты</option><option value="fragment_pack">Разное</option></select></label><label>Search <input type="text" id="'+sourcePrefix(prefix+'-appearance-q')+'" placeholder="Search by title, description or slug"></label></div>'
      + '<div id="'+sourcePrefix(prefix+'-appearance-results')+'"></div></div>'
      + '<div><button type="button" class="af-shop-btn af-slot-save">Save</button> <button type="button" class="af-shop-btn af-slot-delete">Delete</button></div>'
      + '</div>';
  }

  function loadSlots(shop, catId){
    getJSON('shop_manage.php?shop=' + encodeURIComponent(shop) + '&cat_id=' + encodeURIComponent(catId) + '&view=slots&do=list').then(function(r){
      var body = document.getElementById('af-manage-slots-body');
      if(!body){ return; }
      if(!r.ok){ body.innerHTML = '<div>'+escapeHtml(r.error || 'Failed to load slots')+'</div>'; return; }
      var rows = r.rows || [];
      if(!rows.length){ body.innerHTML = '<div>No slots yet</div>'; return; }
      body.innerHTML = rows.map(function(row){ return slotEditorHtml('slot-' + row.slot_id, row); }).join('');
      rows.forEach(function(row){
        var prefix = 'slot-' + row.slot_id;
        var sourceNode = byId(sourcePrefix(prefix + '-source-type'));
        if(sourceNode){
          sourceNode.addEventListener('change', function(){
            var current = sourceNode.value || 'kb';
            setSourceMode(prefix, current);
            if(current === 'appearance'){ runAppearanceSearch(prefix); }
            else { runKbSearch(prefix); }
          });
        }
        ['kb-q','kb-type-filter','kb-rarity','kb-item-type','kb-spell-level','kb-spell-school'].forEach(function(suffix){
          var node = byId(sourcePrefix(prefix + '-' + suffix));
          if(!node){ return; }
          node.addEventListener('input', function(){ runKbSearch(prefix); });
          node.addEventListener('change', function(){ runKbSearch(prefix); });
        });
        var apNode = byId(sourcePrefix(prefix + '-appearance-q'));
        if(apNode){ apNode.addEventListener('input', function(){ runAppearanceSearch(prefix); }); }
        var apGroupNode = byId(sourcePrefix(prefix + '-appearance-group'));
        if(apGroupNode){ apGroupNode.addEventListener('change', function(){ runAppearanceSearch(prefix); }); }
        if((row.source_type || 'kb') === 'appearance'){ runAppearanceSearch(prefix); }
        else { runKbSearch(prefix); }
      });
    });
  }



  function renderInventorySlot(item){
    var invId = String(item && item.inv_id ? item.inv_id : 0);
    var kbId = String(item && item.kb_id ? item.kb_id : 0);
    var rarity = item && item.rarity ? item.rarity : 'common';
    var kind = item && item.item_kind ? item.item_kind : 'misc';
    var equipSlot = item && item.equip_slot ? item.equip_slot : '';
    var canEquip = item && item.is_equippable ? '1' : '0';
    var title = item && item.title ? item.title : '';
    var icon = item && item.icon_url ? item.icon_url : '';
    var qty = item && item.qty ? String(item.qty) : '1';
    var tooltip = item && item.tooltip_text ? item.tooltip_text : '';
    var sourceType = item && item.source_type ? String(item.source_type) : 'kb';
    var isVisual = item && item.is_visual_item ? '1' : '0';
    var isActiveAppearance = item && item.appearance_is_active ? '1' : '0';
    var iconHtml = icon ? ('<img src="' + escapeHtml(icon) + '" alt="' + escapeHtml(title) + '">') : '<span class="af-equip-slot__placeholder">?</span>';
    return '<div class="af-inventory-slot af-inv-slot rarity-' + escapeHtml(rarity) + '" draggable="true" data-inv-id="' + escapeHtml(invId) + '" data-kb-id="' + escapeHtml(kbId) + '" data-rarity="' + escapeHtml(rarity) + '" data-kind="' + escapeHtml(kind) + '" data-source-type="' + escapeHtml(sourceType) + '" data-is-visual-item="' + escapeHtml(isVisual) + '" data-appearance-target="' + escapeHtml(item && item.appearance_target ? item.appearance_target : '') + '" data-appearance-active="' + escapeHtml(isActiveAppearance) + '" data-equip-slot="' + escapeHtml(equipSlot) + '" data-is-equippable="' + escapeHtml(canEquip) + '" data-title="' + escapeHtml(title) + '" data-tooltip="' + escapeHtml(tooltip) + '">' + iconHtml + '<span class="af-inventory-qty">' + escapeHtml(qty) + '</span></div>';
  }

  function renderInventoryGrid(root, inventoryItems){
    if(!root){ return; }
    var gridRoot = root.querySelector('[data-af-inv-grid]');
    var tabsNav = root.querySelector('[data-af-inventory-tabs-nav]');
    var panelsWrap = gridRoot ? gridRoot.querySelector('.af-inventory-tabs__panels') : null;
    if(!tabsNav || !panelsWrap){
      afShopDebug('renderInventoryGrid: required nodes missing', !!tabsNav, !!panelsWrap);
      return;
    }

    var labels = {
      all: 'Всё',
      weapon: 'Оружие',
      armor: 'Броня',
      consumable: 'Расходники',
      gear: 'Снаряжение',
      misc: 'Другое'
    };
    var order = ['all', 'weapon', 'armor', 'consumable', 'gear', 'misc'];
    var grouped = {all: []};
    (Array.isArray(inventoryItems) ? inventoryItems : []).forEach(function(item){
      var kind = item && item.item_kind ? String(item.item_kind) : 'misc';
      if(!grouped[kind]){
        grouped[kind] = [];
        if(order.indexOf(kind) === -1){ order.push(kind); }
      }
      grouped.all.push(item);
      grouped[kind].push(item);
    });

    tabsNav.innerHTML = '';
    panelsWrap.innerHTML = '';
    order.forEach(function(kind){
      var tab = document.createElement('button');
      tab.type = 'button';
      tab.className = 'af-inventory-tab';
      tab.setAttribute('data-kind', kind);
      tab.textContent = labels[kind] || kind;
      tabsNav.appendChild(tab);

      var panel = document.createElement('section');
      panel.className = 'af-inventory-panel';
      panel.setAttribute('data-kind', kind);
      var html = (grouped[kind] || []).map(renderInventorySlot).join('');
      panel.innerHTML = '<div class="af-inventory-grid" data-af-grid-panel>' + (html || '<div class="af-inventory-empty">Нет предметов</div>') + '</div><div class="af-inventory-empty" data-af-filter-empty hidden>Ничего не найдено</div>';
      panelsWrap.appendChild(panel);
    });
    afShopDebug('items count', Array.isArray(inventoryItems) ? inventoryItems.length : 0);
  }

  function initInventoryTabs(scope){
    var root = scope && scope.querySelector ? scope.querySelector('[data-af-inventory-tabs]') : document.querySelector('[data-af-inventory-tabs]');
    if(!root){ return; }
    var inventoryRoot = root.closest('.af-inventory') || scope || document;
    if(root.getAttribute && root.getAttribute('data-af-tabs-init') === '1'){
      applyFilters();
      return;
    }
    if(root.setAttribute){ root.setAttribute('data-af-tabs-init', '1'); }

    var search = inventoryRoot.querySelector('[data-af-inventory-search]');
    var rarity = inventoryRoot.querySelector('[data-af-inventory-rarity]');

    function collectTabs(){
      return Array.prototype.slice.call(inventoryRoot.querySelectorAll('.af-inventory-tab[data-kind]'));
    }

    function collectPanels(){
      return Array.prototype.slice.call(root.querySelectorAll('.af-inventory-panel[data-kind]'));
    }

    function activate(kind){
      var tabs = collectTabs();
      var panels = collectPanels();
      tabs.forEach(function(tab){ tab.classList.toggle('is-active', tab.getAttribute('data-kind') === kind); });
      panels.forEach(function(panel){ panel.classList.toggle('is-active', panel.getAttribute('data-kind') === kind); });
      try { localStorage.setItem('af_inv_tab', kind); } catch(err) {}
    }

    function applyFilters(){
      var q = (search && search.value ? search.value : '').toLowerCase();
      var rr = rarity && rarity.value ? rarity.value : '';
      collectPanels().forEach(function(panel){
        var visible = 0;
        panel.querySelectorAll('.af-inv-slot').forEach(function(slot){
          var txt = ((slot.getAttribute('data-title') || '') + ' ' + (slot.getAttribute('data-tooltip') || '')).toLowerCase();
          var matchQ = !q || txt.indexOf(q) !== -1;
          var matchR = !rr || (slot.getAttribute('data-rarity') || '') === rr;
          var show = matchQ && matchR;
          slot.style.display = show ? '' : 'none';
          if(show){ visible += 1; }
        });
        var filterEmpty = panel.querySelector('[data-af-filter-empty]');
        if(filterEmpty){
          filterEmpty.hidden = visible !== 0;
        }
      });
    }

    inventoryRoot.addEventListener('click', function(e){
      var tab = e.target.closest('.af-inventory-tab[data-kind]');
      if(!tab || !inventoryRoot.contains(tab)){ return; }
      activate(tab.getAttribute('data-kind') || 'all');
    });

    var preferred = '';
    try { preferred = localStorage.getItem('af_inv_tab') || ''; } catch(err) { preferred = ''; }
    if(!collectTabs().some(function(tab){ return tab.getAttribute('data-kind') === preferred; })){
      preferred = 'all';
    }
    if(!preferred){
      preferred = 'all';
    }
    activate(preferred);
    if(search){ search.addEventListener('input', applyFilters); }
    if(rarity){ rarity.addEventListener('change', applyFilters); }
    applyFilters();

    document.addEventListener('click', function(e){
      if(!e.target.closest('.af-inv-slot') && !e.target.closest('[data-af-item-popover]')){
        hideItemPopover();
      }
    });

    inventoryRoot.querySelectorAll('.af-inv-slot[data-inv-id]').forEach(function(slot){
      if(slot.getAttribute('data-af-dnd-init') === '1'){ return; }
      slot.setAttribute('data-af-dnd-init', '1');
      slot.addEventListener('dragstart', function(e){
        var payload = {
          inv_id: slot.getAttribute('data-inv-id') || '0',
          kb_id: slot.getAttribute('data-kb-id') || '0',
          equip_slot: slot.getAttribute('data-equip-slot') || ''
        };
        e.dataTransfer.setData('text/plain', JSON.stringify(payload));
      });
    });

    inventoryRoot.querySelectorAll('[data-af-equip-slot][data-slot-code]').forEach(function(slot){
      if(slot.getAttribute('data-af-dnd-init') === '1'){ return; }
      slot.setAttribute('data-af-dnd-init', '1');
      slot.addEventListener('dragover', function(e){
        e.preventDefault();
        var data = {};
        try { data = JSON.parse(e.dataTransfer.getData('text/plain') || '{}'); } catch(err) { data = {}; }
        var valid = !!data.equip_slot && data.equip_slot === (slot.getAttribute('data-slot-code') || '');
        slot.classList.toggle('is-hover-valid', valid);
        slot.classList.toggle('is-hover-invalid', !valid);
      });
      slot.addEventListener('dragleave', function(){ slot.classList.remove('is-hover-valid', 'is-hover-invalid'); });
      slot.addEventListener('drop', function(e){
        e.preventDefault();
        slot.classList.remove('is-hover-valid', 'is-hover-invalid');
        var data = {};
        try { data = JSON.parse(e.dataTransfer.getData('text/plain') || '{}'); } catch(err) { data = {}; }
        var invId = parseInt(data.inv_id || '0', 10);
        var targetSlot = slot.getAttribute('data-slot-code') || '';
        if(invId <= 0 || !data.equip_slot || data.equip_slot !== targetSlot){
          afShopToast('Этот предмет нельзя надеть в этот слот.', 'error');
          return;
        }
        var ctx = inventoryContext(slot);
        post('misc.php?action=inventory_equip', {uid:ctx.uid || '0', inv_id:invId, slot_code:targetSlot}).then(function(r){
          if(r && r.ok){
            if(r.state && r.state.equipment){ renderEquipmentState(r.state.equipment, ctx.root || inventoryRoot); }
            else if(r.equipped){ renderEquipmentState(r.equipped, ctx.root || inventoryRoot); }
            afShopToast('Предмет экипирован', 'success');
          } else {
            afShopToast((r && r.error) || 'Этот предмет нельзя надеть в этот слот.', 'error');
          }
        });
      });
    });
  }

  function loadInventoryState(scope){
    var root = scope && scope.closest ? scope.closest('.af-inventory') : null;
    if(!root){ root = scope && scope.querySelector ? scope.querySelector('.af-inventory') : null; }
    if(!root){ root = document.querySelector('.af-inventory[data-uid]'); }
    afShopDebug('mountInventory: root found?', !!root);
    if(!root){
      var fallbackScope = scope && scope.querySelector ? scope : document;
      var near = fallbackScope.querySelectorAll ? fallbackScope.querySelectorAll('.af-inventory, [data-afcs-inventory-embed-content], [data-af-inv-grid]') : [];
      var htmlDump = [];
      near.forEach(function(node){ htmlDump.push(node.outerHTML ? node.outerHTML.slice(0, 220) : '[node]'); });
      afShopDebug('mountInventory: nearest blocks', htmlDump);
      return Promise.resolve();
    }
    var uid = root.getAttribute('data-uid') || '0';
    var url = normalizeActionUrl('misc.php?action=inventory_state&uid=' + encodeURIComponent(uid));
    if(afShopDebugEnabled()){
      url += '&af_debug=1';
    }
    afShopDebug('fetch inventory_state url', url);
    return fetch(url, {credentials:'same-origin'}).then(function(resp){
      return resp.text().then(function(text){
        afShopDebug('response status + preview', resp.status, text.slice(0, 200));
        var parsed = parseJSON(text);
        afShopDebug('parsed JSON ok? keys', !!(parsed && parsed.ok), Object.keys(parsed || {}));
        return parsed;
      });
    }).then(function(r){
      if(!r || !r.ok){
        afShopToast((r && r.error) || 'Не удалось загрузить инвентарь', 'error');
        return;
      }
      var items = Array.isArray(r.items) ? r.items : (Array.isArray(r.inventory) ? r.inventory : []);
      var equipment = (r && typeof r.equipment === 'object' && r.equipment) ? r.equipment : {};
      afShopDebug('items count', items.length, 'equipment keys', Object.keys(equipment));
      renderInventoryGrid(root, items);
      renderEquipmentState(equipment, root.querySelector('[data-af-equip]') || root);
      initInventoryTabs(root);
      if(afShopDebugEnabled()){
        var existing = root.querySelector('[data-af-debug-items]');
        if(!existing){
          existing = document.createElement('div');
          existing.setAttribute('data-af-debug-items', '1');
          existing.className = 'af-cs-muted';
          var first = root.firstChild || null;
          root.insertBefore(existing, first);
        }
        existing.textContent = 'items count: ' + items.length;
      }
    });
  }

  window.AFSHOP.renderInventoryGrid = renderInventoryGrid;
  window.AFSHOP.renderEquipment = renderEquipmentState;
  function mountInventory(scope){
    var root = scope && scope.classList && scope.classList.contains('af-inventory') ? scope : (scope && scope.querySelector ? scope.querySelector('.af-inventory') : null);
    afShopDebug('mountInventory: root found?', !!root);
    if(!root){ return Promise.resolve(); }
    return loadInventoryState(root);
  }

  window.AFSHOP.mountInventory = mountInventory;

  function runHealthCheck(){
    var root = document.getElementById('af-shop-health');
    if(!root){ return; }
    var jsNode = root.querySelector('[data-health-js]');
    var keyNode = root.querySelector('[data-health-postkey]');
    var apiNode = root.querySelector('[data-health-api]');
    if(jsNode){ jsNode.textContent = 'JS loaded: ' + (window.AFSHOP && window.AFSHOP.loaded ? 'yes' : 'no'); }
    var hasPostKey = !!key();
    if(keyNode){ keyNode.textContent = 'postKey present: ' + (hasPostKey ? 'yes' : 'no'); }
    fetch(normalizeActionUrl('misc.php?action=shop_health'), {credentials:'same-origin'})
      .then(function(r){ return r.text(); })
      .then(parseJSON)
      .then(function(res){ if(apiNode){ apiNode.textContent = 'API ping: ' + (res && res.ok ? 'ok' : 'no'); } })
      .catch(function(){ if(apiNode){ apiNode.textContent = 'API ping: no'; } });
  }

})();

(function(){
  if(window.__afShopBound){ return; }
  window.__afShopBound = true;
  window.AFSHOP = window.AFSHOP || {};
  window.AFSHOP.loaded = true;

  function post(url, data){
    data = data || {};
    if(!data.my_post_key){ data.my_post_key = key(); }
    return fetch(url, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:new URLSearchParams(data)})
      .then(function(r){ return r.text(); })
      .then(parseJSON);
  }

  function getJSON(url){
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
        window.location = links.shop || 'misc.php?action=shop&shop=game';
      };
    }
    var invBtn = modal.querySelector('[data-af-modal-inventory]');
    if(invBtn){
      invBtn.onclick = function(){ window.location = links.inventory || 'misc.php?action=inventory'; };
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
    weapon_main:{label:'Main Hand', icon:'⚔️'}, weapon_off:{label:'Off Hand', icon:'🛡️'},
    head:{label:'Head', icon:'⛑️'}, body:{label:'Body', icon:'🦺'}, hands:{label:'Hands', icon:'🧤'},
    legs:{label:'Legs', icon:'👖'}, feet:{label:'Feet', icon:'🥾'}, back:{label:'Back', icon:'🎒'}, belt:{label:'Belt', icon:'🧷'}
  };

  function updateEquipmentSlot(node, data){
    if(!node){ return; }
    var slotCode = node.getAttribute('data-slot-code') || '';
    var meta = AF_EQUIP_META[slotCode] || {label:slotCode, icon:'⬜'};
    var entry = data || null;
    var rarity = (entry && entry.rarity) ? entry.rarity : 'common';
    var title = (entry && entry.title) ? entry.title : 'Пусто';
    var iconUrl = (entry && entry.icon_url) ? entry.icon_url : '';
    node.className = 'af-equip-slot rarity-' + rarity;
    node.setAttribute('data-inv-id', entry && entry.inv_id ? String(entry.inv_id) : '0');
    node.setAttribute('data-kb-id', entry && entry.kb_id ? String(entry.kb_id) : '0');
    node.setAttribute('data-item-title', title);
    node.setAttribute('data-item-icon', iconUrl);
    node.setAttribute('data-item-rarity', rarity);
    node.setAttribute('data-slot-label', meta.label || slotCode);
    node.setAttribute('data-slot-icon', meta.icon || '⬜');
    var iconNode = node.querySelector('.af-equip-slot__item-icon');
    if(iconNode){
      iconNode.innerHTML = iconUrl ? ('<img src="' + escapeHtml(iconUrl) + '" alt="' + escapeHtml(title) + '">') : '<span class="af-equip-slot__placeholder">Пусто</span>';
    }
    var titleNode = node.querySelector('.af-equip-slot__item-title');
    if(titleNode){ titleNode.textContent = title; }
  }

  function showInventoryModal(html){
    var modal = afShopModalRoot();
    if(!modal){ return; }
    var content = modal.querySelector('[data-af-shop-modal-content]');
    if(content){ content.innerHTML = html; }
    modal.hidden = false;
    document.body.classList.add('af-shop-modal-open');
  }

  function renderEquipmentState(equipped){
    document.querySelectorAll('[data-af-equip-slot][data-slot-code]').forEach(function(node){
      var slotCode = node.getAttribute('data-slot-code') || '';
      updateEquipmentSlot(node, equipped && equipped[slotCode] ? equipped[slotCode] : null);
    });
  }

  function loadEquipped(){
    var panel = document.querySelector('[data-af-equipment-panel][data-uid]');
    if(!panel){ return Promise.resolve(); }
    var uid = panel.getAttribute('data-uid') || '0';
    return getJSON('misc.php?action=inventory_equipped_get&uid=' + encodeURIComponent(uid)).then(function(r){
      if(!r || !r.ok){ afShopToast((r && r.error) || 'Не удалось загрузить экипировку', 'error'); return; }
      renderEquipmentState(r.equipped || {});
    });
  }


  function renderInventoryItemModal(item){
    var title = (item && item.title) ? item.title : 'Предмет';
    var rarity = (item && item.rarity) ? item.rarity : 'common';
    var icon = (item && item.icon_url) ? item.icon_url : '';
    var desc = (item && item.description_html) ? item.description_html : '<em>Описание отсутствует</em>';
    var canEquip = !!(item && item.is_equippable);
    var invId = item && item.inv_id ? String(item.inv_id) : '0';
    var html = '<p class="af-shop-modal__title" id="af-shop-modal-title">' + escapeHtml(title) + '</p>'
      + '<p><strong>Редкость:</strong> ' + escapeHtml(rarity) + '</p>'
      + (icon ? '<p><img src="' + escapeHtml(icon) + '" alt="' + escapeHtml(title) + '" class="af-equip-modal-icon"></p>' : '')
      + '<div class="af-shop-modal__desc">' + desc + '</div>';
    if (canEquip) {
      html += '<p><strong>Можно экипировать</strong></p>'
        + '<div class="af-shop-modal__actions"><button type="button" class="af-shop-btn" data-af-equip-btn data-inv-id="' + escapeHtml(invId) + '">Надеть</button></div>';
    }
    showInventoryModal(html);
  }

  function handleInventorySlotClick(slotNode){
    if(!slotNode){ return; }
    var invId = parseInt(slotNode.getAttribute('data-inv-id') || '0', 10);
    if(invId <= 0){ return; }
    getJSON('misc.php?action=inventory_item_info&inv_id=' + encodeURIComponent(invId)).then(function(r){
      if(!r || !r.ok || !r.item){
        afShopToast((r && r.error) || 'Не удалось загрузить предмет', 'error');
        return;
      }
      renderInventoryItemModal(r.item || {});
    });
  }

  function handleEquipmentSlotClick(slotNode){
    if(!slotNode){ return; }
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

    getJSON('misc.php?action=inventory_equippable_list&slot_code=' + encodeURIComponent(slotCode)).then(function(r){
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
      withLoading(equipBtn, post('misc.php?action=inventory_equip', {inv_id:invIdEquip})).then(function(r){
        if(r && r.ok){
          afShopHideCheckoutSuccessModal();
          if(r.equipped){ renderEquipmentState(r.equipped); }
          else { loadEquipped(); }
          afShopToast('Предмет экипирован', 'success');
        } else if(r && r.error !== 'busy'){
          afShopToast(r.error || 'Не удалось надеть предмет', 'error');
        }
      });
      return;
    }

    var slotEquipBtn = e.target.closest('[data-af-slot-equip-btn][data-inv-id][data-slot-code]');
    if(slotEquipBtn){
      e.preventDefault();
      var invIdSlotEquip = parseInt(slotEquipBtn.getAttribute('data-inv-id') || '0', 10);
      var slotCodeEquip = slotEquipBtn.getAttribute('data-slot-code') || '';
      if(invIdSlotEquip <= 0 || !slotCodeEquip){ return; }
      withLoading(slotEquipBtn, post('misc.php?action=inventory_equip', {inv_id:invIdSlotEquip, slot_code:slotCodeEquip})).then(function(r){
        if(r && r.ok){
          afShopHideCheckoutSuccessModal();
          if(r.equipped){ renderEquipmentState(r.equipped); }
          else { loadEquipped(); }
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
      withLoading(unequipBtn, post('misc.php?action=inventory_unequip', {slot_code:slotCode})).then(function(r){
        if(r && r.ok){
          afShopHideCheckoutSuccessModal();
          loadEquipped();
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
        .then(function(res){ if(res.ok){ window.location='misc.php?action=shop_cart&shop='+encodeURIComponent(shop2); } else if(res.error !== 'busy'){ afShopToast(res.error || 'Error', 'error'); } });
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
      }).then(function(r){ setStatus(status, r.ok ? 'Category updated' : (r.error || 'Update failed'), !!r.ok); });
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
        if(r.ok){ row2.remove(); setStatus(status2, 'Category deleted', true); }
        else { setStatus(status2, r.error || 'Delete failed', false); }
      });
      return;
    }

    var pick = e.target.closest('#af-add-slot-btn');
    if(pick){
      e.preventDefault();
      var picker = document.getElementById('af-kb-picker');
      if(picker){ picker.hidden = !picker.hidden; }
      return;
    }

    var kbItem = e.target.closest('.af-kb-pick-item');
    if(kbItem){
      e.preventDefault();
      var root = document.querySelector('.af-manage-slots[data-shop]');
      if(!root){ return; }
      var shop6 = root.getAttribute('data-shop') || 'game';
      var catId = root.getAttribute('data-cat-id') || '0';
      var status3 = document.getElementById('af-manage-slot-status');
      post('misc.php?action=shop_manage_slot_create&shop=' + encodeURIComponent(shop6), {
        cat_id: catId,
        kb_id: kbItem.getAttribute('data-kb-id'),
        kb_type: kbItem.getAttribute('data-kb-type') || 'item',
        kb_key: kbItem.getAttribute('data-kb-key') || '',
        price: 0,
        currency: 'credits',
        stock: -1,
        limit_per_user: 0,
        enabled: 1,
        sortorder: 0
      }).then(function(r){
        if(r.ok){ setStatus(status3, 'Slot created', true); loadSlots(shop6, catId); }
        else { setStatus(status3, r.error || 'Failed to create slot', false); }
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
      post('misc.php?action=shop_manage_slot_update&shop=' + encodeURIComponent(shop7), {
        slot_id: card.getAttribute('data-slot-id'),
        price: (card.querySelector('.af-slot-price') || {}).value || 0,
        currency: (card.querySelector('.af-slot-currency') || {}).value || 'credits',
        stock: (card.querySelector('.af-slot-stock') || {}).value || -1,
        limit_per_user: (card.querySelector('.af-slot-limit') || {}).value || 0,
        enabled: (card.querySelector('.af-slot-enabled') || {}).checked ? 1 : 0,
        sortorder: (card.querySelector('.af-slot-sortorder') || {}).value || 0
      }).then(function(r){ setStatus(status4, r.ok ? 'Slot saved' : (r.error || 'Save failed'), !!r.ok); });
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
  initInventoryTabs();
  loadEquipped();
  runHealthCheck();

  var slotsRoot = document.querySelector('.af-manage-slots[data-shop]');
  if(slotsRoot){
    var shopCode = slotsRoot.getAttribute('data-shop') || 'game';
    var catId = slotsRoot.getAttribute('data-cat-id') || '0';
    loadSlots(shopCode, catId);

    var kbQ = document.getElementById('af-kb-picker-q');
    if(kbQ){
      kbQ.addEventListener('input', function(){
        var q = kbQ.value || '';
        getJSON('misc.php?action=shop_kb_search&shop=' + encodeURIComponent(shopCode) + '&q=' + encodeURIComponent(q)).then(function(r){
          var resNode = document.getElementById('af-kb-picker-results');
          if(!resNode){ return; }
          if(!r.ok){ resNode.textContent = r.error || 'Search failed'; return; }
          resNode.innerHTML = (r.items || []).map(function(item){
            return '<div class="af-kb-item"><span>#'+item.kb_id+' '+escapeHtml(item.title || '')+' ['+escapeHtml(item.rarity || 'common')+']</span> <button class="af-kb-pick-item" data-kb-id="'+item.kb_id+'" data-kb-type="'+escapeHtml(item.kb_type || 'item')+'" data-kb-key="'+escapeHtml(item.kb_key || '')+'" type="button">Select</button></div>';
          }).join('');
        });
      });
      kbQ.dispatchEvent(new Event('input'));
    }
  }

  function loadSlots(shop, catId){
    getJSON('misc.php?action=shop_manage_slots&shop=' + encodeURIComponent(shop) + '&cat=' + encodeURIComponent(catId) + '&do=list').then(function(r){
      var body = document.getElementById('af-manage-slots-body');
      if(!body){ return; }
      if(!r.ok){ body.innerHTML = '<div>'+escapeHtml(r.error || 'Failed to load slots')+'</div>'; return; }
      var rows = r.rows || [];
      if(!rows.length){ body.innerHTML = '<div>No slots yet</div>'; return; }
      body.innerHTML = rows.map(function(row){
        var icon = row.icon_url ? '<img src="'+escapeHtml(row.icon_url)+'" alt="" style="width:32px;height:32px;">' : '';
        var rarity = row.rarity || 'common';
        var rarityClass = row.rarity_class || ('af-rarity-' + rarity);
        var rarityLabel = row.rarity_label || rarity;
        var debugInfo = 'debug: rarity_raw = ' + escapeHtml(row.debug_rarity_raw || '')
          + ', rarity_final = ' + escapeHtml(row.debug_rarity_final || rarity)
          + ', data_json_present: ' + escapeHtml(row.debug_data_json_present || 'no');
        return '<div class="af-slot-card '+escapeHtml(rarityClass)+'" data-slot-id="'+row.slot_id+'">'
          + '<div><strong>#'+row.slot_id+'</strong> KB#'+row.kb_id+' '+icon+'</div>'
          + '<div>'+escapeHtml(row.title || '')+'</div>'
          + '<div><strong>Rarity:</strong> <span class="'+escapeHtml(rarityClass)+'">'+escapeHtml(rarityLabel)+'</span></div>'
          + '<div><small>'+debugInfo+'</small></div>'
          + '<label>Price <input type="number" class="af-slot-price" value="'+escapeHtml(row.price_major || '0.00')+'" min="0" step="0.01"></label>'
          + '<label>Currency <input type="text" class="af-slot-currency" value="'+escapeHtml(row.currency || 'credits')+'"></label>'
          + '<label>Stock <input type="number" class="af-slot-stock" value="'+(row.stock==null?-1:row.stock)+'"></label>'
          + '<label>Limit/user <input type="number" class="af-slot-limit" value="'+(row.limit_per_user||0)+'" min="0"></label>'
          + '<label>Sort <input type="number" class="af-slot-sortorder" value="'+(row.sortorder||0)+'"></label>'
          + '<label><input type="checkbox" class="af-slot-enabled" '+(row.enabled ? 'checked' : '')+'> Enabled</label>'
          + '<div><button type="button" class="af-shop-btn af-slot-save">Save</button> <button type="button" class="af-shop-btn af-slot-delete">Delete</button></div>'
          + '</div>';
      }).join('');
    });
  }



  function initInventoryTabs(){
    var root = document.querySelector('[data-af-inventory-tabs]');
    if(!root){ return; }
    var tabs = Array.prototype.slice.call(root.querySelectorAll('.af-inventory-tab[data-kind]'));
    var panels = Array.prototype.slice.call(root.querySelectorAll('.af-inventory-panel[data-kind]'));
    if(!tabs.length || !panels.length){ return; }
    function activate(kind){
      tabs.forEach(function(tab){ tab.classList.toggle('is-active', tab.getAttribute('data-kind') === kind); });
      panels.forEach(function(panel){ panel.classList.toggle('is-active', panel.getAttribute('data-kind') === kind); });
      try { localStorage.setItem('af_inv_tab', kind); } catch(err) {}
    }
    var preferred = '';
    try { preferred = localStorage.getItem('af_inv_tab') || ''; } catch(err) { preferred = ''; }
    if(!tabs.some(function(tab){ return tab.getAttribute('data-kind') === preferred; })){
      preferred = tabs[0].getAttribute('data-kind');
    }
    activate(preferred);
    tabs.forEach(function(tab){
      tab.addEventListener('click', function(){ activate(tab.getAttribute('data-kind')); });
    });
  }

  function runHealthCheck(){
    var root = document.getElementById('af-shop-health');
    if(!root){ return; }
    var jsNode = root.querySelector('[data-health-js]');
    var keyNode = root.querySelector('[data-health-postkey]');
    var apiNode = root.querySelector('[data-health-api]');
    if(jsNode){ jsNode.textContent = 'JS loaded: ' + (window.AFSHOP && window.AFSHOP.loaded ? 'yes' : 'no'); }
    var hasPostKey = !!key();
    if(keyNode){ keyNode.textContent = 'postKey present: ' + (hasPostKey ? 'yes' : 'no'); }
    fetch('misc.php?action=shop_health', {credentials:'same-origin'})
      .then(function(r){ return r.text(); })
      .then(parseJSON)
      .then(function(res){ if(apiNode){ apiNode.textContent = 'API ping: ' + (res && res.ok ? 'ok' : 'no'); } })
      .catch(function(){ if(apiNode){ apiNode.textContent = 'API ping: no'; } });
  }

})();

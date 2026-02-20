(function(){
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

  document.addEventListener('click', function(e){
    var add = e.target.closest('.af-add-cart');
    if(add){
      e.preventDefault();
      var wrap = add.closest('[data-shop]');
      var shop = wrap ? wrap.getAttribute('data-shop') : 'game';
      post('misc.php?action=shop_add_to_cart&shop=' + encodeURIComponent(shop), {slot:add.getAttribute('data-slot'), qty:1}).then(function(res){ alert(res.ok ? 'Added' : (res.error || 'Error')); });
      return;
    }

    var buy = e.target.closest('.af-buy-now');
    if(buy){
      e.preventDefault();
      var wrap2 = buy.closest('[data-shop]');
      var shop2 = wrap2 ? wrap2.getAttribute('data-shop') : 'game';
      post('misc.php?action=shop_add_to_cart&shop=' + encodeURIComponent(shop2), {slot:buy.getAttribute('data-slot'), qty:1}).then(function(res){ if(res.ok){ window.location='misc.php?action=shop_cart&shop='+encodeURIComponent(shop2); } else { alert(res.error || 'Error'); } });
      return;
    }

    var checkout = e.target.closest('.af-checkout');
    if(checkout){
      e.preventDefault();
      var shop3 = (checkout.closest('[data-shop]') || document.body).getAttribute('data-shop') || 'game';
      post('misc.php?action=shop_checkout&shop=' + encodeURIComponent(shop3), {}).then(function(res){ if(res.ok){ window.location = res.redirect || location.href; } else { alert(res.error || 'Error'); } });
      return;
    }

    var qtyBtn = e.target.closest('.af-cart-qty');
    if(qtyBtn){
      e.preventDefault();
      var item = qtyBtn.closest('.af-cart-item');
      var id = item.getAttribute('data-item-id');
      var curr = parseInt((item.querySelector('.af-cart-item__qty span')||{}).textContent||'1',10);
      var next = curr + parseInt(qtyBtn.getAttribute('data-delta'),10);
      post('misc.php?action=shop_update_cart', {item_id:id, qty:next}).then(function(){ location.reload(); });
      return;
    }

    var remove = e.target.closest('.af-cart-remove');
    if(remove){
      e.preventDefault();
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
            return '<div class="af-kb-item"><span>#'+item.kb_id+' '+escapeHtml(item.title || '')+' ['+escapeHtml(item.rarity || 'common')+']</span> <button class="af-kb-pick-item" data-kb-id="'+item.kb_id+'" type="button">Select</button></div>';
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
        return '<div class="af-slot-card" data-slot-id="'+row.slot_id+'">'
          + '<div><strong>#'+row.slot_id+'</strong> KB#'+row.kb_id+' '+icon+'</div>'
          + '<div>'+escapeHtml(row.title || '')+' <em>['+escapeHtml(row.rarity || 'common')+']</em></div>'
          + '<label>Price <input type="number" class="af-slot-price" value="'+(row.price||0)+'" min="0"></label>'
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
})();

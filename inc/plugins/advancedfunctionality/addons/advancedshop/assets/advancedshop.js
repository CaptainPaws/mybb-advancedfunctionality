(function(){
  function post(url, data){
    data = data || {};
    if(!data.my_post_key){ data.my_post_key = key(); }
    return fetch(url, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:new URLSearchParams(data)})
      .then(function(r){
        return r.text().then(function(text){
          try { return JSON.parse(text); }
          catch(err){ return {ok:false, error:'Invalid JSON response'}; }
        });
      });
  }

  function getJSON(url){
    return fetch(url, {credentials:'same-origin'})
      .then(function(r){ return r.text(); })
      .then(function(text){
        try { return JSON.parse(text); }
        catch(err){ return {ok:false, error:'Invalid JSON response'}; }
      });
  }

  function key(){
    if(window.AFSHOP && window.AFSHOP.postKey){ return window.AFSHOP.postKey; }
    var cfg = document.getElementById('af_shop_post_key');
    if(cfg && cfg.value){ return cfg.value; }
    var el = document.querySelector('input[name="my_post_key"]');
    return el ? el.value : (window.my_post_key || '');
  }

  function renderCategoryRow(cat, shop){
    return '<tr data-cat-id="'+cat.cat_id+'">'
      + '<td>'+cat.cat_id+'</td>'
      + '<td class="af-cat-title">'+escapeHtml(cat.title || '')+'</td>'
      + '<td>'+(cat.parent_id || 0)+'</td>'
      + '<td>'+(cat.sortorder || 0)+'</td>'
      + '<td><a class="button" href="misc.php?action=shop_manage_slots&shop='+encodeURIComponent(shop)+'&cat='+cat.cat_id+'">Slots</a></td>'
      + '</tr>';
  }

  function escapeHtml(v){
    return String(v).replace(/[&<>"']/g, function(ch){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[ch]; });
  }

  document.addEventListener('click', function(e){
    var add = e.target.closest('.af-add-cart');
    if(add){
      e.preventDefault();
      var wrap = add.closest('[data-shop]');
      var shop = wrap ? wrap.getAttribute('data-shop') : 'game';
      post('misc.php?action=shop_add_to_cart&shop=' + encodeURIComponent(shop), {slot:add.getAttribute('data-slot'), qty:1}).then(function(res){ if(res.ok){ alert('Added'); } else { alert(res.error || 'Error'); } });
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

    var pick = e.target.closest('#af-pick-kb-btn');
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
      var shop = root.getAttribute('data-shop') || 'game';
      var catId = root.getAttribute('data-cat-id') || '0';
      var status = document.getElementById('af-manage-slot-status');
      post('misc.php?action=shop_manage_slots&shop=' + encodeURIComponent(shop), {
        do:'create', cat_id:catId, kb_id:kbItem.getAttribute('data-kb-id'), price:0, stock:-1, limit_per_user:0, enabled:1, sortorder:0
      }).then(function(r){
        if(r.ok){
          if(status){ status.textContent = 'Slot created'; status.className = 'af-status-ok'; }
          loadSlots(root, shop, catId);
          return;
        }
        if(status){ status.textContent = r.error || 'Failed to create slot'; status.className = 'af-status-error'; }
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
        if(r.ok){
          if(statusNode){ statusNode.textContent = 'Category created'; statusNode.className = 'af-status-ok'; }
          var body = document.getElementById('af-manage-categories-body');
          if(body && r.cat){ body.insertAdjacentHTML('beforeend', renderCategoryRow(r.cat, shop)); }
          categoryForm.reset();
          return;
        }
        if(statusNode){ statusNode.textContent = r.error || 'Failed to create category'; statusNode.className = 'af-status-error'; }
      });
    });
  }

  var slotsRoot = document.querySelector('.af-manage-slots[data-shop]');
  if(slotsRoot){
    var shopCode = slotsRoot.getAttribute('data-shop') || 'game';
    var catId = slotsRoot.getAttribute('data-cat-id') || '0';
    loadSlots(slotsRoot, shopCode, catId);

    var addBtn = document.getElementById('af-add-slot-btn');
    var slotStatus = document.getElementById('af-manage-slot-status');
    if(addBtn){
      addBtn.addEventListener('click', function(){
        post('misc.php?action=shop_manage_slots&shop=' + encodeURIComponent(shopCode), {do:'create', cat_id:catId, kb_id:0, price:0, stock:-1, limit_per_user:0, enabled:1, sortorder:0, meta_json:'{}'})
          .then(function(r){
            if(r.ok){
              if(slotStatus){ slotStatus.textContent = 'Slot created'; slotStatus.className = 'af-status-ok'; }
              loadSlots(slotsRoot, shopCode, catId);
            } else if(slotStatus){
              slotStatus.textContent = r.error || 'Failed to create slot'; slotStatus.className = 'af-status-error';
            }
          });
      });
    }

    var kbQ = document.getElementById('af-kb-picker-q');
    if(kbQ){
      kbQ.addEventListener('input', function(){
        var q = kbQ.value || '';
        getJSON('misc.php?action=shop_kb_search&shop=' + encodeURIComponent(shopCode) + '&q=' + encodeURIComponent(q)).then(function(r){
          var resNode = document.getElementById('af-kb-picker-results');
          if(!resNode){ return; }
          if(!r.ok){ resNode.textContent = r.error || 'Search failed'; return; }
          resNode.innerHTML = (r.items || []).map(function(item){
            return '<button class="af-kb-pick-item" data-kb-id="'+item.kb_id+'" type="button">#'+item.kb_id+' '+escapeHtml(item.title || '')+'</button>';
          }).join('');
        });
      });
    }
  }

  function loadSlots(root, shop, catId){
    getJSON('misc.php?action=shop_manage_slots&shop=' + encodeURIComponent(shop) + '&cat=' + encodeURIComponent(catId) + '&do=list').then(function(r){
      var body = document.getElementById('af-manage-slots-body');
      if(!body){ return; }
      if(!r.ok){
        body.innerHTML = '<tr><td colspan="4">'+escapeHtml(r.error || 'Failed to load slots')+'</td></tr>';
        return;
      }
      var rows = r.rows || [];
      if(!rows.length){
        body.innerHTML = '<tr><td colspan="4">No slots yet</td></tr>';
        return;
      }
      body.innerHTML = rows.map(function(row){
        return '<tr><td>'+row.slot_id+'</td><td>'+row.kb_id+'</td><td>'+escapeHtml(row.title || '')+'</td><td>'+row.price+'</td></tr>';
      }).join('');
    });
  }
})();

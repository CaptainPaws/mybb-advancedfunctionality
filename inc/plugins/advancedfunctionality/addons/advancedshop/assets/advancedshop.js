(function(){
  function post(url, data){
    return fetch(url, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:new URLSearchParams(data)}).then(function(r){return r.json();});
  }
  function key(){
    var el = document.querySelector('input[name="my_post_key"]');
    return el ? el.value : (window.my_post_key || '');
  }

  document.addEventListener('click', function(e){
    var add = e.target.closest('.af-add-cart');
    if(add){
      e.preventDefault();
      var wrap = add.closest('[data-shop]');
      var shop = wrap ? wrap.getAttribute('data-shop') : 'game';
      post('misc.php?action=shop_add_to_cart&shop=' + encodeURIComponent(shop), {slot:add.getAttribute('data-slot'), qty:1, my_post_key:key()}).then(function(res){ if(res.ok){ alert('Added'); } });
      return;
    }

    var buy = e.target.closest('.af-buy-now');
    if(buy){
      e.preventDefault();
      var wrap2 = buy.closest('[data-shop]');
      var shop2 = wrap2 ? wrap2.getAttribute('data-shop') : 'game';
      post('misc.php?action=shop_add_to_cart&shop=' + encodeURIComponent(shop2), {slot:buy.getAttribute('data-slot'), qty:1, my_post_key:key()}).then(function(res){ if(res.ok){ window.location='misc.php?action=shop_cart&shop='+encodeURIComponent(shop2); } });
      return;
    }

    var checkout = e.target.closest('.af-checkout');
    if(checkout){
      e.preventDefault();
      var shop3 = (checkout.closest('[data-shop]') || document.body).getAttribute('data-shop') || 'game';
      post('misc.php?action=shop_checkout&shop=' + encodeURIComponent(shop3), {my_post_key:key()}).then(function(res){ if(res.ok){ window.location = res.redirect || location.href; } else { alert(res.error || 'Error'); } });
      return;
    }

    var qtyBtn = e.target.closest('.af-cart-qty');
    if(qtyBtn){
      e.preventDefault();
      var item = qtyBtn.closest('.af-cart-item');
      var id = item.getAttribute('data-item-id');
      var curr = parseInt((item.querySelector('.af-cart-item__qty span')||{}).textContent||'1',10);
      var next = curr + parseInt(qtyBtn.getAttribute('data-delta'),10);
      post('misc.php?action=shop_update_cart', {item_id:id, qty:next, my_post_key:key()}).then(function(){ location.reload(); });
      return;
    }

    var remove = e.target.closest('.af-cart-remove');
    if(remove){
      e.preventDefault();
      var item2 = remove.closest('.af-cart-item');
      post('misc.php?action=shop_update_cart', {item_id:item2.getAttribute('data-item-id'), qty:0, my_post_key:key()}).then(function(){ location.reload(); });
      return;
    }
  });

  var categoryForm = document.getElementById('af-manage-category-form');
  if(categoryForm){
    categoryForm.addEventListener('submit', function(e){
      e.preventDefault();
      var fd = new FormData(categoryForm);
      fd.append('do', 'create');
      post('misc.php?action=shop_manage_categories&shop=game', Object.fromEntries(fd.entries())).then(function(r){ if(r.ok){ location.reload(); } });
    });
  }
})();

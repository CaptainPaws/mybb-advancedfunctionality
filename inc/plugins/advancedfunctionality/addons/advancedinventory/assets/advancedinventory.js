(function () {
  function onReady(fn){ if(document.readyState!=='loading'){fn();} else {document.addEventListener('DOMContentLoaded',fn);} }
  function postForm(url, data){
    var body = new URLSearchParams();
    Object.keys(data || {}).forEach(function(key){
      body.append(key, data[key] == null ? '' : String(data[key]));
    });
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body.toString()
    }).then(function(res){
      return res.text().then(function(text){
        var parsed = null;
        try { parsed = JSON.parse(text); } catch (e) {}
        if(!res.ok){
          var err = parsed && (parsed.error || parsed.message) ? (parsed.error || parsed.message) : ('HTTP ' + res.status);
          throw new Error(err);
        }
        if(!parsed){ throw new Error('Invalid JSON response'); }
        return parsed;
      });
    });
  }
  function getPostKey(doc){
    var node = doc.querySelector('input[name="my_post_key"]');
    if(node && node.value){ return node.value || ''; }
    if(typeof window.my_post_key === 'string' && window.my_post_key){ return window.my_post_key; }
    if(typeof window.mybb_post_code === 'string' && window.mybb_post_code){ return window.mybb_post_code; }
    return '';
  }
  function showMessage(doc, text, isError){
    var root = doc.querySelector('.af-inv-api');
    if(!root){ return; }
    var box = doc.querySelector('.af-inv-flash');
    if(!box){
      box = doc.createElement('div');
      box.className = 'af-inv-flash';
      root.parentNode.insertBefore(box, root);
    }
    box.className = 'af-inv-flash ' + (isError ? 'is-error' : 'is-success');
    box.textContent = text;
  }
  function withLoading(btn, promise){
    if(btn){ btn.disabled = true; btn.dataset.loading = '1'; }
    return promise.finally(function(){
      if(btn){ btn.disabled = false; btn.removeAttribute('data-loading'); }
    });
  }
  function reloadFrame(frame){
    if(frame && frame.contentWindow){
      frame.contentWindow.location.reload();
    }
  }
  function bindFrame(frame){
    var doc;
    try { doc = frame.contentDocument || frame.contentWindow.document; } catch (e) { return; }
    if(!doc || !doc.body){ return; }
    if(doc.body.dataset.afInvBound === '1'){ return; }
    doc.body.dataset.afInvBound = '1';

    doc.addEventListener('click', function(e){
      var filterLink = e.target.closest('.af-inv-subfilter');
      if(filterLink){
        e.preventDefault();
        frame.src = filterLink.getAttribute('href') || frame.src;
        return;
      }

      var appearanceApplyBtn = e.target.closest('[data-af-appearance-apply-btn][data-item-id]');
      if(appearanceApplyBtn){
        e.preventDefault();
        var api = doc.querySelector('.af-inv-api');
        var ownerUid = api ? (api.getAttribute('data-owner') || '0') : '0';
        withLoading(appearanceApplyBtn, postForm('misc.php?action=inventory_appearance_apply', {
          uid: ownerUid,
          inv_id: appearanceApplyBtn.getAttribute('data-item-id') || '0',
          my_post_key: getPostKey(document)
        })).then(function(){
          showMessage(doc, 'Внешний вид активирован.', false);
          reloadFrame(frame);
        }).catch(function(err){
          showMessage(doc, err.message || 'Не удалось активировать предмет.', true);
        });
        return;
      }

      var appearanceUnapplyBtn = e.target.closest('[data-af-appearance-unapply-btn]');
      if(appearanceUnapplyBtn){
        e.preventDefault();
        var apiNode = doc.querySelector('.af-inv-api');
        var owner = apiNode ? (apiNode.getAttribute('data-owner') || '0') : '0';
        withLoading(appearanceUnapplyBtn, postForm('misc.php?action=inventory_appearance_unapply', {
          uid: owner,
          target_key: appearanceUnapplyBtn.getAttribute('data-target-key') || '',
          my_post_key: getPostKey(document)
        })).then(function(){
          showMessage(doc, 'Внешний вид снят.', false);
          reloadFrame(frame);
        }).catch(function(err){
          showMessage(doc, err.message || 'Не удалось снять предмет.', true);
        });
        return;
      }

      var actionBtn = e.target.closest('.af-inv-action[data-action][data-item-id]');
      if(!actionBtn){ return; }
      var action = actionBtn.getAttribute('data-action') || '';
      if(action !== 'update' && action !== 'delete'){ return; }
      e.preventDefault();
      var apiBaseNode = doc.querySelector('.af-inv-api');
      var apiBase = apiBaseNode ? (apiBaseNode.getAttribute('data-api-base') || 'inventory.php') : 'inventory.php';
      var ownerUid2 = apiBaseNode ? (apiBaseNode.getAttribute('data-owner') || '0') : '0';
      var payload = {
        uid: ownerUid2,
        item_id: actionBtn.getAttribute('data-item-id') || '0',
        my_post_key: getPostKey(document)
      };
      if(action === 'update'){
        var card = actionBtn.closest('.af-inv-card');
        var qtyInput = card ? card.querySelector('.af-inv-qty') : null;
        payload.qty = qtyInput ? (qtyInput.value || '1') : '1';
      }
      withLoading(actionBtn, postForm(apiBase + '?action=api_' + action, payload)).then(function(){
        showMessage(doc, action === 'delete' ? 'Предмет удалён.' : 'Изменения сохранены.', false);
        reloadFrame(frame);
      }).catch(function(err){
        showMessage(doc, err.message || 'Не удалось выполнить действие.', true);
      });
    });
  }

  onReady(function(){
    var page=document.querySelector('.af-inv-page');
    if(!page){return;}
    var frame=document.getElementById('af-inv-frame');
    if(!frame){return;}
    var uid=page.getAttribute('data-owner') || '0';
    var cache={};
    frame.addEventListener('load', function(){ bindFrame(frame); });
    document.addEventListener('click',function(e){
      var btn=e.target.closest('.af-inv-tab');
      if(!btn){return;}
      e.preventDefault();
      var entity=btn.getAttribute('data-entity') || btn.getAttribute('data-tab') || 'equipment';
      document.querySelectorAll('.af-inv-tab').forEach(function(el){el.classList.remove('is-active');});
      btn.classList.add('is-active');
      var key=entity + ':all';
      if(cache[key]){ frame.src=cache[key]; return; }
      var url='inventory.php?action=entity&uid='+encodeURIComponent(uid)+'&entity='+encodeURIComponent(entity)+'&sub=all&ajax=1';
      cache[key]=url;
      frame.src=url;
    });
  });
})();

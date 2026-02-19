(function(){
  var modal=document.querySelector('[data-af-balance-modal]');
  if(!modal)return;
  function err(msg){var e=modal.querySelector('[data-af-balance-error]');e.textContent=msg||'';}
  function open(uid){modal.hidden=false;modal.querySelector('[data-af-balance-uid]').value=uid;err('');}
  function close(){modal.hidden=true;}
  document.addEventListener('click',function(e){
    var btn=e.target.closest('[data-af-balance-adjust]');
    if(btn){open(btn.getAttribute('data-uid'));return;}
    if(e.target.closest('[data-af-balance-close]')){close();return;}
    if(e.target.closest('[data-af-balance-apply]')){
      var uid=modal.querySelector('[data-af-balance-uid]').value;
      var amount=modal.querySelector('[data-af-balance-amount]').value;
      var reason=modal.querySelector('[data-af-balance-reason]').value;
      var op=(modal.querySelector('input[name="af-balance-op"]:checked')||{}).value||'add';
      var fd=new FormData();
      fd.append('my_post_key', (window.afBalanceConfig||{}).postKey || '');
      fd.append('uid',uid);fd.append('amount',amount);fd.append('reason',reason);fd.append('op',op);
      fd.append('kind',(window.afBalanceConfig||{}).kind||'exp');
      fetch('misc.php?action=balance_manage&do=adjust',{method:'POST',credentials:'same-origin',body:fd})
        .then(function(r){return r.json();})
        .then(function(data){
          if(!data.success){err(data.error||'Ошибка');return;}
          var row=document.querySelector('[data-af-balance-row="'+data.uid+'"]');
          if(row){
            var e=row.querySelector('[data-af-balance-exp]');if(e)e.textContent=data.exp_display;
            var c=row.querySelector('[data-af-balance-credits]');if(c)c.textContent=data.credits_display;
            var l=row.querySelector('[data-af-balance-level]');if(l)l.textContent=data.level;
          }
          close();
        }).catch(function(){err('Ошибка сети');});
    }
  });
})();

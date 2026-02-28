(function () {
  function onReady(fn){ if(document.readyState!=='loading'){fn();} else {document.addEventListener('DOMContentLoaded',fn);} }
  onReady(function(){
    var page=document.querySelector('.af-inv-page');
    if(!page){return;}
    var frame=document.getElementById('af-inv-frame');
    if(!frame){return;}
    var uid=page.getAttribute('data-owner') || '0';
    var cache={};
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

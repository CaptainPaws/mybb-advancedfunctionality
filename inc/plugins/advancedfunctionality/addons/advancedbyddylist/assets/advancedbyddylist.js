(function(){
  'use strict';
  if(window.__afABDLLoaded)return; window.__afABDLLoaded=true;
  var content=document.getElementById('af-abdl-content'); if(!content)return;
  function replaceFrom(html){var doc=new DOMParser().parseFromString(html,'text/html');var next=doc.getElementById('af-abdl-content');if(next)content.replaceChildren.apply(content,Array.prototype.slice.call(next.childNodes));}
  document.addEventListener('submit',function(event){
    var form=event.target;if(!form.classList.contains('af-abdl-action'))return;
    event.preventDefault();var card=form.closest('.af-abdl-card');if(card)card.classList.add('af-abdl-busy');
    var data=new FormData(form);data.append('ajax','1');
    fetch(form.action,{method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json();}).then(function(result){if(!result.ok)throw new Error(result.message);return fetch(location.href,{credentials:'same-origin'});}).then(function(r){return r.text();}).then(replaceFrom).catch(function(error){alert(error.message||'Не удалось выполнить действие.');if(card)card.classList.remove('af-abdl-busy');});
  });
  var timer;
  document.addEventListener('input',function(event){
    if(!event.target.matches('.af-abdl-search input[name="q"]'))return;
    clearTimeout(timer);var input=event.target;if(input.value.trim().length<2)return;
    timer=setTimeout(function(){var url='buddy.php?tab=search&q='+encodeURIComponent(input.value.trim());fetch(url,{credentials:'same-origin'}).then(function(r){return r.text();}).then(replaceFrom);},300);
  });
})();

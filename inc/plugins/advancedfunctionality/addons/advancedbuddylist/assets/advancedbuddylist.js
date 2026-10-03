(function(){
  'use strict';
  if(window.__afABDLLoaded)return; window.__afABDLLoaded=true;
  var content=document.getElementById('af-abdl-content'); if(!content)return;
  function replaceFrom(html){var doc=new DOMParser().parseFromString(html,'text/html');var next=doc.getElementById('af-abdl-content');if(next)content.replaceChildren.apply(content,Array.prototype.slice.call(next.childNodes));}
  document.addEventListener('submit',function(event){
    var form=event.target;if(!form.classList.contains('af-abdl-action'))return;
    event.preventDefault();var card=form.closest('.af-abdl-card');if(card)card.classList.add('af-abdl-busy');
    var data=new FormData(form);data.set('ajax','1');
    fetch(form.action,{method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){if(!r.ok)throw new Error('HTTP '+r.status+' — '+r.url);return r.text();})
      .then(function(){return fetch(location.href,{credentials:'same-origin'});})
      .then(function(r){if(!r.ok)throw new Error('HTTP '+r.status+' — '+r.url);return r.text();})
      .then(replaceFrom)
      .catch(function(error){alert(error.message||'Не удалось выполнить действие.');if(card)card.classList.remove('af-abdl-busy');});
  });
  var timer;
  document.addEventListener('input',function(event){
    if(!event.target.matches('.af-abdl-search input[name="q"]'))return;
    clearTimeout(timer);var input=event.target;var value=input.value.trim();if(value.length===1)return;
    timer=setTimeout(function(){var url='buddy.php?tab=search'+(value.length>=2?'&q='+encodeURIComponent(value):'');fetch(url,{credentials:'same-origin'}).then(function(r){return r.text();}).then(replaceFrom);},300);
  });
})();

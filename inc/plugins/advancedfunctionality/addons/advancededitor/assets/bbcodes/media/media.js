(function(){
 'use strict';
 var loading=null, generation=0, activeOwner=null;
 document.addEventListener('af:editor-destroyed',function(event){if(activeOwner && event.detail && event.detail.textarea.__afAeShell===activeOwner){activeOwner=null;generation++;if(window.AF_GalleryPicker && window.AF_GalleryPicker.close && document.querySelector('#af-gallery-picker.is-open .ag-media-dialog'))window.AF_GalleryPicker.close();var fallback=document.querySelector('.ae-media-fallback');if(fallback)fallback.remove();}});
 function element(value){return value && value.jquery?value[0]:value;}
 function source(ed){return !!ed.__afAeSourceAdapter || (typeof ed.sourceMode==='function' && ed.sourceMode());}
 function bookmark(ed){
  var ta=element(ed.textarea || (ed.getSourceEditor && ed.getSourceEditor())), text=null, range=null;
  if(source(ed)) {var input=element(ed.getSourceEditor && ed.getSourceEditor()) || ta;if(input) text={input:input,start:input.selectionStart,end:input.selectionEnd,value:input.value};}
  else {var body=ed.getBody && ed.getBody(), sel=body && body.ownerDocument.getSelection();if(sel && sel.rangeCount) range=sel.getRangeAt(0).cloneRange();}
  function restore(){
   if(ta && !ta.isConnected) return false;
   ed.focus();
   if(text && text.input.isConnected && text.input.value===text.value) text.input.setSelectionRange(text.start,text.end);
   if(range && range.startContainer.isConnected) {var helper=ed.getRangeHelper && ed.getRangeHelper();if(helper && helper.selectRange)helper.selectRange(range);else{var sel=range.startContainer.ownerDocument.getSelection();sel.removeAllRanges();sel.addRange(range);}}
   return true;
  }
  function insert(code){
   if(!restore())return;
   if(ed.__afAeSourceAdapter && text){text.input.setRangeText(code,text.input.selectionStart,text.input.selectionEnd,'end');text.input.dispatchEvent(new Event('input',{bubbles:true}));}
   else if(source(ed)) ed.insertText(code);else ed.insert(code);
  }
  insert.restore=restore;return insert;
 }
 function asset(url,css){return new Promise(function(resolve,reject){
  var existing=document.querySelector((css?'link[href=':'script[src=')+JSON.stringify(url)+']');if(existing && !existing.dataset.mediaLoading){resolve();return;}
  var el=document.createElement(css?'link':'script');if(css){el.rel='stylesheet';el.href=url;}else el.src=url;
  el.dataset.mediaLoading='1';var timer=setTimeout(function(){fail();},15000);
  function fail(){clearTimeout(timer);el.remove();reject(new Error('Не удалось загрузить медиапикер. Повторите попытку.'));}
  el.onload=function(){clearTimeout(timer);delete el.dataset.mediaLoading;resolve();};el.onerror=fail;document.head.appendChild(el);
 });}
 function ensure(config){
  if(window.AF_GalleryPicker) return Promise.resolve();
  if(!loading){window.AF_GalleryPickerConfig={pickerUrl:config.pickerUrl,dataUrl:config.dataUrl};
   loading=asset(config.css,true).then(function(){return asset(config.js,false);}).catch(function(error){loading=null;throw error;});}
  return loading;
 }
 function fallback(ed,insert,token){
  var previous=document.querySelector('.ae-media-fallback');if(previous) previous.remove();
  var root=document.createElement('div');root.className='ae-media-fallback';root.setAttribute('role','dialog');root.setAttribute('aria-modal','true');root.setAttribute('aria-label','Вставить изображение');
  root.innerHTML='<div class="ae-media-dialog"><button type="button" data-close aria-label="Закрыть">×</button><h3>Изображение</h3><p>Галерея недоступна. Можно вставить изображение по URL.</p><form><label>URL изображения <input type="url" required placeholder="https://…"></label><button type="submit">Вставить</button><p role="status"></p></form></div>';
  function close(){root.remove();if(token===generation){activeOwner=null;generation++;insert.restore();}}root.querySelector('[data-close]').onclick=close;
  root.addEventListener('keydown',function(event){if(event.key==='Escape')close();});
  root.querySelector('form').onsubmit=function(event){event.preventDefault();var value=root.querySelector('input').value.trim();if(!/^https?:\/\/[^\s\[\]<>]+$/i.test(value)){root.querySelector('[role=status]').textContent='Укажите прямую HTTP/HTTPS-ссылку.';return;}close();insert('[img]'+value+'[/img]');};
  document.body.appendChild(root);if(window.afAeDecorateModal)window.afAeDecorateModal(root.firstElementChild);root.querySelector('input').focus();
 }
 window.af_ae_media_exec=function(ed,button,caller){
  var config=(window.afAePayload || window.afAdvancedEditorPayload || {}).mediaGallery || {enabled:false}, insert=bookmark(ed), token=++generation;
  activeOwner=caller.closest('.af-ae-shell');
  if(!config.enabled){fallback(ed,insert,token);return;}
  caller.setAttribute('aria-busy','true');
  var previous=document.querySelector('.ae-media-fallback');if(previous)previous.remove();
  var shell=document.createElement('div');shell.className='ae-media-fallback';shell.setAttribute('role','dialog');shell.setAttribute('aria-modal','true');shell.setAttribute('aria-label','Изображение');
  shell.innerHTML='<section class="ae-media-dialog"><header><h3>Изображение</h3><button type="button" aria-label="Закрыть">×</button></header><p role="status" aria-live="polite">Загрузка медиатеки…</p></section>';
  function cancel(){if(token===generation){generation++;activeOwner=null;insert.restore();}shell.remove();}
  shell.querySelector('button').onclick=cancel;
  shell.addEventListener('keydown',function(event){if(event.key==='Escape')cancel();});
  document.body.appendChild(shell);if(window.afAeDecorateModal)window.afAeDecorateModal(shell.firstElementChild);shell.querySelector('button').focus();
  return ensure(config).then(function(){if(token!==generation)return;return window.AF_GalleryPicker.open({mediaTool:true,tab:button.cmd==='af_gallery'?'mine':'url',onSelect:insert,onClose:function(){if(token===generation){activeOwner=null;insert.restore();}},onError:function(error){if(!document.querySelector('#af-gallery-picker.is-open'))throw error;}});})
   .then(function(){shell.remove();}).catch(function(error){if(token!==generation)return;shell.querySelector('[role=status]').textContent=error.message || 'Не удалось открыть медиатеку. Повторите попытку.';}).finally(function(){caller.removeAttribute('aria-busy');});
 };
})();

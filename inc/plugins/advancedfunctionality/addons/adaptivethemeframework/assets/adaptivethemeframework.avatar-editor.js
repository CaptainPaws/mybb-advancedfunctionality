'use strict';
(() => {
  const root=document.querySelector('[data-atf-avatar-editor]');
  if(!root || root.dataset.bound) return;
  root.dataset.bound='1';
  const one=(host,selector)=>host.querySelector(selector);
  for(const form of root.querySelectorAll('[data-avatar-card]')) {
    const limits=JSON.parse(form.dataset.fileLimits), fileInput=one(form,'input[type=file]');
    const urlInput=one(form,'[data-avatar-url]'), status=one(form,'[data-avatar-status]');
    const selection=one(form,'[data-avatar-selection]'), selectedImage=one(form,'[data-avatar-selected-image]');
    const mediaInput=one(form,'[name=ag_avatar_media_id]'), confirmInput=one(form,'[name=ag_avatar_confirm]');
    const save=one(form,'[data-avatar-save]'), recent=one(form,'[data-avatar-recent]');
    const saveAllowed=!save.disabled;
    const purpose=form.dataset.galleryPurpose, field=form.dataset.fileField;
    let mode='upload', file=null, gallery=null, objectUrl='', revision=0, busy=false;
    const report=message=>{status.textContent=message;};
    const picker=()=>{
      const api=window.AF_GalleryPicker;
      if(!api || typeof api.open!=='function') throw new Error('Gallery Picker не загрузился. Обновите страницу или используйте загрузку файла.');
      return api;
    };
    function releaseUrl(){if(objectUrl){URL.revokeObjectURL(objectUrl);objectUrl='';}}
    function showSelection(src,label){selectedImage.src=src;one(form,'[data-avatar-selected-label]').textContent=label;selection.hidden=false;}
    function reset(){
      revision++;releaseUrl();file=null;gallery=null;fileInput.value='';mediaInput.value='';confirmInput.value='';
      selection.hidden=true;selectedImage.removeAttribute('src');
      for(const button of recent.querySelectorAll('button'))button.setAttribute('aria-pressed','false');
      report('');
    }
    function setMode(next){
      const tab=one(form,`[data-avatar-tab="${next}"]`);
      if(!tab || tab.disabled || busy)return;
      mode=next;
      for(const button of form.querySelectorAll('[data-avatar-tab]')){
        const active=button===tab;button.setAttribute('aria-selected',String(active));button.tabIndex=active?0:-1;
      }
      for(const pane of form.querySelectorAll('[data-avatar-pane]'))pane.hidden=pane.dataset.avatarPane!==next;
      if(next==='upload' && file){showSelection(objectUrl,file.name);}
      else if(next==='gallery' && gallery){showSelection(gallery.url_full,'Изображение из вашей галереи');}
      else {selection.hidden=true;}
      report('');
    }
    for(const tab of form.querySelectorAll('[data-avatar-tab]')){
      tab.addEventListener('click',()=>setMode(tab.dataset.avatarTab));
      tab.addEventListener('keydown',event=>{
        if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
        event.preventDefault();const tabs=[...form.querySelectorAll('[data-avatar-tab]:not(:disabled)')];
        let index=tabs.indexOf(tab);
        index=event.key==='Home'?0:event.key==='End'?tabs.length-1:(index+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;
        setMode(tabs[index].dataset.avatarTab);tabs[index].focus();
      });
    }
    function validateFile(candidate){
      if(!candidate || !limits.mimes.includes(candidate.type))throw new Error('Этот формат изображения не разрешён для данного аватара.');
      const max=limits.maxBytes, mimeMax=limits.mimeLimits[candidate.type]||0;
      if(!candidate.size || (max && candidate.size>max) || (mimeMax && candidate.size>mimeMax))throw new Error('Файл превышает разрешённый размер. Ограничения указаны в заголовке карточки.');
    }
    function chooseFile(candidate){
      if(busy || fileInput.disabled)return;
      try{validateFile(candidate);}catch(error){report(error.message);return;}
      revision++;releaseUrl();file=candidate;gallery=null;mediaInput.value='';confirmInput.value='';
      objectUrl=URL.createObjectURL(candidate);setMode('upload');showSelection(objectUrl,candidate.name);
      report('Предпросмотр готов. Нажмите «Сохранить».');
    }
    fileInput.addEventListener('change',()=>chooseFile(fileInput.files[0]));
    const drop=one(form,'[data-avatar-drop]');
    for(const type of ['dragenter','dragover'])drop.addEventListener(type,event=>{event.preventDefault();if(!fileInput.disabled)drop.classList.add('is-dragging');});
    for(const type of ['dragleave','drop'])drop.addEventListener(type,event=>{event.preventDefault();drop.classList.remove('is-dragging');});
    drop.addEventListener('drop',event=>chooseFile(event.dataTransfer.files[0]));
    function chooseGallery(item){
      if(busy)return;
      revision++;gallery=item;mediaInput.value=String(item.media_id);confirmInput.value='1';
      setMode('gallery');showSelection(item.url_full,'Изображение из вашей галереи');
      for(const button of recent.querySelectorAll('button'))button.setAttribute('aria-pressed',String(button.dataset.mediaId===String(item.media_id)));
      report('Изображение выбрано. Подтвердите сохранение.');
    }
    function openGallery(){
      if(busy)return;
      try{picker().open(chooseGallery,{purpose,onError:error=>report(error.message)});}catch(error){report(error.message);}
    }
    for(const button of form.querySelectorAll('[data-avatar-open-gallery]'))button.addEventListener('click',openGallery);
    one(form,'[data-avatar-cancel]').addEventListener('click',()=>{if(!busy)reset();});
    const urlPreview=one(form,'[data-avatar-url-preview]');
    urlPreview.addEventListener('click',()=>{
      try{
        const value=urlInput.value.trim();
        const url=new URL(value);
        if(!['http:','https:'].includes(url.protocol) || url.username || url.password)throw new Error();
        showSelection(url.href,'Предпросмотр URL');report('Изображение будет проверено MyBB при сохранении.');
      }catch{report('Укажите полный HTTP(S) URL изображения. Для Gravatar введите email и сохраните без предпросмотра.');}
    });
    selectedImage.addEventListener('error',()=>report('Не удалось показать изображение. Выбор сохранён; проверьте адрес или выберите другой файл.'));
    async function loadRecent(){
      if(one(form,'[data-avatar-tab=gallery]').disabled)return;
      try{
        const api=picker();if(typeof api.recent!=='function' || typeof api.select!=='function'){recent.textContent='Используйте кнопку «Открыть галерею».';return;}
        const items=await api.recent({purpose});recent.replaceChildren();
        if(!items.length){recent.textContent='В галерее пока нет подходящих изображений.';return;}
        for(const item of items){
          const button=document.createElement('button');button.type='button';button.dataset.mediaId=String(item.media_id||item.id);
          button.setAttribute('aria-label',item.title||'Выбрать изображение');button.setAttribute('aria-pressed','false');
          const image=document.createElement('img');image.src=item.thumb;image.alt='';image.loading='lazy';button.append(image);
          button.addEventListener('click',async()=>{
            if(busy)return;
            const token=++revision;button.disabled=true;
            try{const selected=await api.select(button.dataset.mediaId,{purpose,myPostKey:one(form,'[name=my_post_key]').value});if(token===revision)chooseGallery(selected);}
            catch(error){if(token===revision)report(error.message);}finally{button.disabled=false;}
          });recent.append(button);
        }
      }catch(error){recent.textContent=error.message;}
    }
    loadRecent();
    async function parseResult(response){
      let doc=new DOMParser().parseFromString(await response.text(),'text/html');
      let card=doc.querySelector(`[data-avatar-card="${form.dataset.avatarCard}"]`);
      if(!card){
        // MyBB may return its intermediate redirect template rather than HTTP Location.
        const refresh=doc.querySelector('meta[http-equiv="refresh" i]');
        const match=refresh?.content.match(/url\s*=\s*["']?([^"']+)/i);
        if(match){
          const target=new URL(match[1].trim(),location.href);
          const expected=new URL(form.action,location.href);
          if(target.origin===location.origin && target.pathname===expected.pathname && target.searchParams.get('action')==='avatar'){
            const result=await fetch(target.href,{credentials:'same-origin',cache:'no-store'});
            doc=new DOMParser().parseFromString(await result.text(),'text/html');card=doc.querySelector(`[data-avatar-card="${form.dataset.avatarCard}"]`);
          }
        }
      }
      if(!response.ok || !card)throw new Error(doc.querySelector('.error,.error_page,[role=alert]')?.textContent.trim().slice(0,400)||'Сервер не подтвердил сохранение. Выбранное изображение осталось в карточке.');
      if(card.dataset.saveError==='1')throw new Error(card.querySelector('[data-avatar-server-error]')?.textContent.trim()||'Не удалось сохранить аватар.');
      return card;
    }
    async function submitAvatar(submitter){
      if(busy)return;
      const removing=submitter?.hasAttribute('data-avatar-remove');
      if(removing && !window.confirm('Удалить этот аватар?'))return;
      const data=new FormData(form);data.delete('avatarurl');data.delete(field);data.delete('ag_avatar_confirm');data.delete('ag_avatar_media_id');
      if(removing){data.set(submitter.name,'1');}
      busy=true;save.disabled=true;form.setAttribute('aria-busy','true');
      try{
        if(!removing){
          if(mode==='upload'){if(!file)throw new Error('Выберите файл для загрузки.');validateFile(file);data.set(field,file,file.name);}
          else if(mode==='url'){if(urlInput.disabled || !urlInput.value.trim())throw new Error('Укажите URL или email для Gravatar.');data.set('avatarurl',urlInput.value.trim());}
          else {
            if(!gallery)throw new Error('Выберите изображение из галереи.');
            const url=new URL(gallery.url_full,location.href);if(url.origin!==location.origin)throw new Error('Оригинал изображения должен находиться на этом форуме.');
            const source=await fetch(url.href,{credentials:'same-origin',cache:'no-store',redirect:'error'});
            if(!source.ok)throw new Error('Исходный файл галереи недоступен.');
            const blob=await source.blob();const candidate=new File([blob],gallery.filename,{type:gallery.mime});validateFile(candidate);
            data.set(field,candidate,candidate.name);data.set('ag_avatar_confirm','1');data.set('ag_avatar_media_id',String(gallery.media_id));
          }
        }
        report(removing?'Удаление…':'Сохранение…');
        const response=await fetch(form.action,{method:'POST',body:data,credentials:'same-origin',cache:'no-store'});
        if(new URL(response.url).origin!==location.origin)throw new Error('Неверный адрес ответа сервера.');
        const result=await parseResult(response);
        one(form,'[data-avatar-current]').replaceChildren(...[...result.querySelector('[data-avatar-current]').childNodes].map(node=>document.importNode(node,true)));
        one(form,'[data-avatar-remove]').disabled=result.querySelector('[data-avatar-remove]').disabled;
        const error=one(form,'[data-avatar-server-error]');if(error)error.remove();form.dataset.saveError='0';
        reset();report(removing?'Аватар удалён.':'Аватар сохранён.');
      }catch(error){report(error.message);}
      finally{busy=false;save.disabled=!saveAllowed;form.removeAttribute('aria-busy');}
    }
    form.addEventListener('submit',event=>{event.preventDefault();submitAvatar(event.submitter);});
    one(form,'[data-avatar-remove]').addEventListener('click',event=>submitAvatar(event.currentTarget));
    window.addEventListener('pagehide',releaseUrl,{once:true});
  }
})();

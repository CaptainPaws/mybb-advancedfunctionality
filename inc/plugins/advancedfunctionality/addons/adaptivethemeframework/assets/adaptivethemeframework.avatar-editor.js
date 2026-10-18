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
      if(typeof api.supportsPurpose!=='function' || !api.supportsPurpose(purpose))throw new Error(`Не удалось открыть галерею. Gallery Picker не поддерживает ${purpose}: обновите файлы Gallery и кэш статических ресурсов.`);
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
      if(!candidate || !limits.mimes.includes(candidate.type))throw new Error('Этот формат изображения не поддерживается для выбранного аватара.');
      const max=limits.maxBytes, mimeMax=limits.mimeLimits[candidate.type]||0;
      if(!candidate.size || (max && candidate.size>max) || (mimeMax && candidate.size>mimeMax))throw new Error(`Допустимый размер файла: до ${Math.round(Math.min(...[max,mimeMax].filter(value=>value>0))/1024)} КБ.`);
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
    let beforeVersion=form.dataset.avatarVersion;
    function checkedState(state){
      if(!state || state.role!==form.dataset.avatarCard || String(state.uid)!==form.dataset.avatarOwner || typeof state.version!=='string')throw new Error('Ответ состояния аватара не соответствует текущему пользователю.');
      return state;
    }
    async function readState(){
      const url=new URL(form.action,location.href);url.search='';url.searchParams.set('action','af_avatar_state');
      url.searchParams.set('role',form.dataset.avatarCard);url.searchParams.set('my_post_key',one(form,'[name=my_post_key]').value);
      const response=await fetch(url.href,{credentials:'same-origin',cache:'no-store'});
      let payload;try{payload=await response.json();}catch{throw new Error(`Состояние аватара недоступно (HTTP ${response.status}). Проверьте авторизацию и установку обновлённых файлов AF.`);}
      if(!response.ok || !payload.ok)throw new Error(payload.error||'Не удалось проверить текущее состояние аватара.');
      return checkedState(payload.avatar);
    }
    const safeText=html=>new DOMParser().parseFromString(String(html),'text/html').body.textContent.trim().slice(0,500);
    async function parseResult(response){
      const text=await response.text();let payload=null;
      try{payload=JSON.parse(text);}catch{}
      if(payload?.errors || payload?.error || payload?.ok===false)throw new Error(safeText(payload.error||(payload.errors||[]).join(' '))||'Сервер отклонил сохранение аватара.');
      if(response.ok && payload?.ok===true && payload.avatar)return {state:checkedState(payload.avatar)};
      let doc=new DOMParser().parseFromString(text,'text/html');
      let card=doc.querySelector(`[data-avatar-card="${form.dataset.avatarCard}"]`);
      if(card?.dataset.saveError==='1')throw new Error(card.querySelector('[data-avatar-server-error]')?.textContent.trim()||'Не удалось сохранить аватар.');
      const serverError=doc.querySelector('.error,.error_page,#error,[role=alert]');
      if(!response.ok || serverError)throw new Error(safeText(serverError?.textContent||`Сервер вернул HTTP ${response.status}.`));
      // A redirect body (HTML, JavaScript or MyBB AJAX data) is not a save receipt.
      // Verify durable user state with one GET. Never repeat the POST or execute scripts.
      const state=await readState();
      if(state.version!==beforeVersion)return {state};
      throw new Error('Ответ обработчика неоднозначен, а состояние аватара не изменилось. Обновите страницу перед повторной попыткой. Выбранное изображение сохранено в карточке.');
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
        data.set('atf_avatar_async','1');data.delete('ajax');
        beforeVersion=(await readState()).version;
        report(removing?'Удаление…':'Сохранение…');
        const response=await fetch(form.action,{method:'POST',body:data,credentials:'same-origin',cache:'no-store'});
        if(new URL(response.url).origin!==location.origin)throw new Error('Неверный адрес ответа сервера.');
        const result=await parseResult(response);
        const current=one(form,'[data-avatar-current]'), state=result.state;
        current.replaceChildren();
        if(state.url || state.display_url){
          const url=new URL(state.display_url||state.url,location.href);
          if(!['http:','https:'].includes(url.protocol))throw new Error('Обработчик вернул недопустимый адрес аватара.');
          const image=document.createElement('img');image.src=url.href;image.alt='Текущий аватар';image.decoding='async';current.append(image);
        }else{const empty=document.createElement('span');empty.className='atf-avatar-editor__empty';empty.textContent='Аватар не установлен';current.append(empty);}
        one(form,'[data-avatar-remove]').disabled=!state.has_avatar;
        form.dataset.avatarVersion=state.version;
        const error=one(form,'[data-avatar-server-error]');if(error)error.remove();form.dataset.saveError='0';
        reset();report(removing?'Аватар удалён.':'Аватар успешно обновлён.');
      }catch(error){report(error.message);}
      finally{busy=false;save.disabled=!saveAllowed;form.removeAttribute('aria-busy');}
    }
    form.addEventListener('submit',event=>{event.preventDefault();submitAvatar(event.submitter);});
    one(form,'[data-avatar-remove]').addEventListener('click',event=>submitAvatar(event.currentTarget));
    window.addEventListener('pagehide',releaseUrl,{once:true});
  }
})();

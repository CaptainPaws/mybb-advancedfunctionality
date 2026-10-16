(() => {
  'use strict';
  const form=document.querySelector('[data-af-signature-builder]');
  if (!form || form.dataset.initialized) return;
  form.dataset.initialized='1';
  const seed=JSON.parse(form.querySelector('[data-af-sig-seed]').textContent), model=structuredClone(seed.defaults);
  // Pick only known typed content fields. Never turn a model string into HTML.
  for(const key of Object.keys(model)) {
    const value=seed.model[key];
    if(typeof value===typeof model[key] && !Array.isArray(value) && value!==null && typeof value!=='object')model[key]=value;
  }
  if(!Object.hasOwn(seed.templates,model.template))model.template='minimal';
  function imageState(value){const d=structuredClone(seed.defaults.background);for(const k of ['enabled','url','media_id'])if(value&&typeof value[k]===typeof d[k])d[k]=value[k];if(Number.isInteger(value?.media_id)&&value.media_id>0)d.media_id=value.media_id;return d;}
  model.background=imageState(seed.model.background);
  model.images=[0,1,2].map(i=>imageState(seed.model.images?.[i]));
  model.buttons=Array.isArray(seed.model.buttons)?seed.model.buttons.slice(0,6).map(b=>({label:typeof b?.label==='string'?b.label:'',url:typeof b?.url==='string'?b.url:''})):[];
  const controls=form.querySelector('[data-af-sig-controls]'), status=form.querySelector('[data-af-sig-status]'), previewHost=form.querySelector('[data-af-sig-preview]');
  const thumbUrls=seed.media_urls||[], templateInputs=[], imageThumbs=[];
  let timer, sequence=0, dirty=false;
  function el(tag,text,cls){const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;}
  function button(text,fn){const n=el('button',text);n.type='button';n.addEventListener('click',fn);return n;}
  function panel(title){const n=el('section',undefined,'af-sig-builder__panel');n.append(el('h2',title));return n;}
  function serialize(){form.querySelector('[data-af-sig-model]').value=JSON.stringify(model);}
  function changed(){dirty=true;serialize();clearTimeout(timer);timer=setTimeout(()=>request('preview').catch(showError),300);}
  function showError(e){status.textContent=e.message||'Не удалось выполнить действие.';}
  async function request(operation){
    const seq=++sequence, submitted=JSON.stringify(model), data=new FormData();
    data.set('my_post_key',seed.csrf);data.set('af_signature_model',submitted);
    const response=await fetch('usercp.php?action=editsig&af_sig_api='+operation,{method:'POST',body:data,credentials:'same-origin'});
    const result=await response.json();if(seq!==sequence&&operation==='preview')return;
    if(!response.ok||!result.ok)throw new Error(result.error||'Ошибка сервера.');
    form.querySelector('[data-af-sig-preview-style]').textContent=result.data.css;
    previewHost.innerHTML=result.data.html;
    if(operation==='draft'&&JSON.stringify(model)===submitted)dirty=false;
    status.textContent=result.data.message;
  }
  const templates=panel('1 · Выбор шаблона'), cards=el('div',undefined,'af-sig-builder__templates');
  for(const [key,[name,description]]of Object.entries(seed.templates)){
    const label=el('label',undefined,'af-sig-builder__template'),input=el('input');input.type='radio';input.name='af_sig_template';input.value=key;input.checked=model.template===key;
    const thumbnail=el('span',undefined,'af-sig-builder__mini af-sig-builder__mini--'+key);thumbnail.setAttribute('aria-hidden','true');
    thumbnail.append(el('span'),el('span'),el('span'));label.append(input,thumbnail,el('strong',name),el('small',description));
    input.addEventListener('change',()=>{model.template=key;syncCards();changed();});templateInputs.push(input);cards.append(label);
  }
  function syncCards(){templateInputs.forEach(input=>input.closest('label').classList.toggle('is-active',input.value===model.template));}
  templates.append(el('p','Выберите композицию. Оформление берётся из APStudio.'),cards);controls.append(templates);syncCards();
  const basics=panel('2 · Основные поля');
  function textField(parent,key,title,multi=false,toggle=false){
    const row=el('div',undefined,'af-sig-builder__field'),label=el('label',title),input=el(multi?'textarea':'input'),count=el('output');
    input.value=model[key];if(!multi)input.type='text';else input.rows=4;
    input.maxLength=seed.limits[key];label.append(input,count);
    const updateCount=()=>{count.textContent=Array.from(input.value).length+'/'+seed.limits[key];};updateCount();
    input.addEventListener('input',()=>{model[key]=input.value;updateCount();changed();});row.append(label);
    if(toggle){const switchLabel=el('label',undefined,'af-sig-builder__switch'),check=el('input');check.type='checkbox';check.checked=model['show_'+key];check.addEventListener('change',()=>{model['show_'+key]=check.checked;changed();});switchLabel.append(check,el('span','Показывать'));row.append(switchLabel);}
    parent.append(row);
  }
  textField(basics,'title','Заголовок',false,true);textField(basics,'subtitle','Подзаголовок',false,true);textField(basics,'text','Основной текст / цитата',true);controls.append(basics);
  const extras=panel('3 · Дополнительные поля'), imageGrid=el('div',undefined,'af-sig-builder__images');
  function imageControl(image,title,index){
    const box=el('fieldset',undefined,'af-sig-builder__image'),legend=el('legend',title),enabledLabel=el('label'),enabled=el('input'),url=el('input'),thumbnail=el('img');
    enabled.type='checkbox';enabled.checked=image.enabled;enabledLabel.append(enabled,el('span','Показывать'));
    url.type='url';url.placeholder='https://…/image.gif';url.maxLength=2048;url.value=image.url;url.setAttribute('aria-label',title+' — URL');thumbnail.alt='Предпросмотр: '+title;
    function thumbnailUrl(){const source=image.media_id?thumbUrls[index]:image.url;let valid='';try{const parsed=new URL(source);if(['http:','https:'].includes(parsed.protocol)&&/\.(png|jpe?g|webp|gif)$/i.test(parsed.pathname))valid=source;}catch(_){}thumbnail.hidden=!valid;if(valid)thumbnail.src=valid;else thumbnail.removeAttribute('src');}
    enabled.addEventListener('change',()=>{image.enabled=enabled.checked;changed();});
    url.addEventListener('input',()=>{image.url=url.value;image.media_id=null;thumbnailUrl();changed();});
    const choose=button('Загрузить / выбрать',()=>{
      if(!window.AF_GalleryPicker){showError(new Error('Gallery недоступна. Можно указать разрешённый URL изображения.'));return;}
      window.AF_GalleryPicker.open(media=>{image.media_id=Number(media.media_id);image.url='';thumbUrls[index]=media.url_full;url.value='';thumbnailUrl();changed();},{purpose:'appearance_postbit_bg'});
    });
    const clear=button('Удалить',()=>{image.url='';image.media_id=null;url.value='';thumbnailUrl();changed();});
    if(!seed.image_allowed){choose.disabled=true;url.disabled=true;enabled.disabled=true;}
    box.append(legend,enabledLabel,thumbnail,url,choose,clear);thumbnailUrl();imageThumbs.push(thumbnail);return box;
  }
  extras.append(imageControl(model.background,'Фоновое изображение',0));
  model.images.forEach((image,i)=>imageGrid.append(imageControl(image,'Изображение '+(i+1),i+1)));
  extras.append(el('p','Карточка использует первое изображение, галерея — до трёх. Скрытые в композиции данные сохраняются. Лимиты файлов и разрешения задаёт Gallery; итоговые ограничения подписи проверяет MyBB.'),imageGrid);
  const linkSection=el('section'),links=el('div'),add=button('+ Добавить кнопку',()=>{if(model.buttons.length>=6)return;model.buttons.push({label:'',url:''});renderButtons();changed();});
  function renderButtons(){links.replaceChildren();model.buttons.forEach((value,index)=>{
    const row=el('div',undefined,'af-sig-builder__link'),name=el('input'),url=el('input');name.type='text';name.maxLength=60;name.placeholder='Название';name.value=value.label;name.setAttribute('aria-label','Название ссылки '+(index+1));url.type='url';url.maxLength=2048;url.placeholder='https://';url.value=value.url;url.setAttribute('aria-label','Адрес ссылки '+(index+1));
    name.addEventListener('input',()=>{value.label=name.value;changed();});url.addEventListener('input',()=>{value.url=url.value;changed();});
    const up=button('↑',()=>{[model.buttons[index-1],model.buttons[index]]=[model.buttons[index],model.buttons[index-1]];renderButtons();changed();}),down=button('↓',()=>{[model.buttons[index+1],model.buttons[index]]=[model.buttons[index],model.buttons[index+1]];renderButtons();changed();});up.disabled=index===0;down.disabled=index===model.buttons.length-1;
    up.setAttribute('aria-label','Переместить ссылку вверх');down.setAttribute('aria-label','Переместить ссылку вниз');row.append(name,url,up,down,button('Удалить',()=>{model.buttons.splice(index,1);renderButtons();changed();}));links.append(row);
  });add.disabled=model.buttons.length>=6;}
  linkSection.append(el('h3','Кнопки-ссылки'),links,add,el('small','До шести ссылок. Оформление наследуется из APStudio.'));renderButtons();extras.append(linkSection);textField(extras,'status','Подпись автора / статус',false,true);controls.append(extras);
  form.querySelector('[data-af-sig-draft]').addEventListener('click',()=>{clearTimeout(timer);request('draft').catch(showError);});
  form.querySelector('[data-af-sig-preview-button]').addEventListener('click',()=>{clearTimeout(timer);request('preview').catch(showError);});
  previewHost.addEventListener('click',event=>{if(event.target.closest('a,summary'))event.preventDefault();});
  form.addEventListener('submit',()=>{serialize();dirty=false;});
  window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
  window.addEventListener('focus',()=>request('preview').catch(showError));
  serialize();form.querySelector('button[type="submit"]').disabled=false;
})();

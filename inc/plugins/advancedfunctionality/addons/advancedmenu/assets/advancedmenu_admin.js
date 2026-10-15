/* AdvancedMenu — ACP Icon Picker (Font Awesome) */
(function () {
  'use strict';

  if (window.__afAdvancedMenuAdminLoaded) return;
  window.__afAdvancedMenuAdminLoaded = true;

  function asText(x) { return String(x == null ? '' : x); }

  // Конфиг можно прокинуть из admin.php:
  // window.afAdvancedMenuIconPickerConfig = { cssUrl: 'https://.../all.min.css' }
  function getCfg() {
    var cfg = window.afAdvancedMenuIconPickerConfig;
    return (cfg && typeof cfg === 'object') ? cfg : {};
  }

  var iconListPromise = null;

  function parseIconsFromCss(cssText) {
    // ловим ".fa-house:before" из FA CSS
    var re = /\.fa-([a-z0-9-]+):before\b/g;
    var seen = Object.create(null);
    var out = [];
    var m;
    while ((m = re.exec(cssText))) {
      var name = m[1];
      if (!name || seen[name]) continue;
      seen[name] = true;
      out.push('fa-' + name);
    }
    out.sort();
    return out;
  }

  function guessFontAwesomeCssUrl() {
    var links = document.getElementsByTagName('link');
    for (var i = 0; i < links.length; i++) {
      var href = asText(links[i].getAttribute('href')).toLowerCase();
      if (href.indexOf('fontawesome') !== -1 || href.indexOf('font-awesome') !== -1) {
        return links[i].href;
      }
    }
    return '';
  }

  function loadIconList() {
    if (window.__afAmAdminIconList) return Promise.resolve(window.__afAmAdminIconList);
    if (iconListPromise) return iconListPromise;

    var cfg = getCfg();
    var cssUrl = asText(cfg.cssUrl || '').trim();
    if (!cssUrl) cssUrl = guessFontAwesomeCssUrl();

    if (!cssUrl) {
      window.__afAmAdminIconList = [];
      return Promise.resolve([]);
    }

    iconListPromise = fetch(cssUrl, { credentials: 'omit' })
      .then(function (r) { return r.text(); })
      .then(function (txt) {
        var list = parseIconsFromCss(txt);
        window.__afAmAdminIconList = list;
        return list;
      })
      .catch(function () {
        window.__afAmAdminIconList = [];
        return [];
      });

    return iconListPromise;
  }

  function normalizeToPreviewHtml(raw) {
    raw = asText(raw).trim();
    if (!raw) return '';

    // если админ руками вставил HTML — в превью не рендерим (безопасность/предсказуемость)
    if (raw.indexOf('<') !== -1 || raw.indexOf('>') !== -1) return '';

    // картинка по URL
    if (/^(https?:)?\/\//i.test(raw) || raw.charAt(0) === '/' || /\.(png|jpe?g|gif|svg|webp)$/i.test(raw)) {
      var safe = raw.replace(/'/g, "\\'");
      return '<span class="af-am-ico af-am-ico-img" style="--af-am-icon-url:url(\'' + safe + '\');" aria-hidden="true"></span>';
    }

    // FontAwesome классы
    if (/\bfa-[a-z0-9-]+\b/i.test(raw) || /\bfa(s|r|b)?\b/i.test(raw)) {
      if (!/\bfa-(solid|regular|brands|light|thin|duotone)\b/i.test(raw)) {
        raw = 'fa-solid ' + raw;
      }
      raw = raw.replace(/[^a-z0-9_\-\s]/gi, '').trim();
      return '<i class="' + raw + '" aria-hidden="true"></i>';
    }

    // текст/эмодзи
    var t = raw.replace(/[<>&]/g, '');
    return '<span class="af-am-icp-text" aria-hidden="true">' + t + '</span>';
  }

  function setPreview(previewEl, value) {
    if (!previewEl) return;
    previewEl.innerHTML = normalizeToPreviewHtml(value);
  }

  function openPicker(input) {
    if (!input) return;

    var overlay = document.createElement('div');
    overlay.className = 'af-am-icp-overlay';

    var modal = document.createElement('div');
    modal.className = 'af-am-icp-modal';

    var head = document.createElement('div');
    head.className = 'af-am-icp-head';

    var title = document.createElement('div');
    title.className = 'af-am-icp-title';
    title.textContent = 'Выбор иконки Font Awesome';
    head.appendChild(title);

    var search = document.createElement('input');
    search.type = 'text';
    search.className = 'af-am-icp-search';
    search.placeholder = 'Поиск… (house, user, discord, bell)';
    head.appendChild(search);

    var styleWrap = document.createElement('div');
    styleWrap.className = 'af-am-icp-style';

    var styles = [
      { key: 'fa-solid', label: 'Solid' },
      { key: 'fa-regular', label: 'Regular' },
      { key: 'fa-brands', label: 'Brands' }
    ];

    var activeStyle = 'fa-solid';

    function mkStyleBtn(s) {
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = s.label;
      b.setAttribute('data-style', s.key);
      if (s.key === activeStyle) b.classList.add('is-active');

      b.addEventListener('click', function () {
        activeStyle = s.key;
        var all = styleWrap.querySelectorAll('button[data-style]');
        for (var i = 0; i < all.length; i++) all[i].classList.remove('is-active');
        b.classList.add('is-active');
        renderGrid(window.__afAmAdminIconList || [], search.value);
      });

      return b;
    }

    styles.forEach(function (s) { styleWrap.appendChild(mkStyleBtn(s)); });
    head.appendChild(styleWrap);

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'af-am-icp-close';
    close.textContent = 'Закрыть';
    head.appendChild(close);

    modal.appendChild(head);

    var status = document.createElement('div');
    status.className = 'af-am-icp-status';
    status.textContent = 'Загрузка списка иконок…';
    modal.appendChild(status);

    var grid = document.createElement('div');
    grid.className = 'af-am-icp-grid';
    modal.appendChild(grid);

    function teardown() {
      try { document.body.removeChild(overlay); } catch (e) {}
      try { document.body.removeChild(modal); } catch (e2) {}
      document.removeEventListener('keydown', onKeydown);
    }

    function onKeydown(e) {
      if (e && e.key === 'Escape') teardown();
    }

    function onPick(iconName) {
      var cls = activeStyle + ' ' + iconName; // "fa-solid fa-house"
      input.value = cls;
      try { input.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
      teardown();
    }

    function renderGrid(list, filter) {
      var term = asText(filter || '').trim().toLowerCase();
      grid.innerHTML = '';

      var shown = 0;
      for (var i = 0; i < list.length; i++) {
        var icon = list[i];
        if (term && icon.indexOf(term) === -1) continue;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'af-am-icp-btn';
        btn.innerHTML = '<i class="' + activeStyle + ' ' + icon + '" aria-hidden="true"></i>';
        btn.addEventListener('click', (function (ic) {
          return function () { onPick(ic); };
        })(icon));

        grid.appendChild(btn);
        shown++;
      }

      status.textContent = shown ? ('Найдено: ' + shown) : 'Ничего не найдено.';
    }

    close.addEventListener('click', teardown);
    overlay.addEventListener('click', teardown);
    document.addEventListener('keydown', onKeydown);

    document.body.appendChild(overlay);
    document.body.appendChild(modal);

    loadIconList().then(function (list) {
      status.textContent = list.length
        ? ('Иконок в базе: ' + list.length)
        : 'Список пустой (не удалось загрузить CSS FontAwesome).';

      renderGrid(list, '');
      try { search.focus(); } catch (e) {}

      search.addEventListener('input', function () {
        renderGrid(list, search.value);
      });
    });
  }

  function findElements() {
    // input: либо явно помеченный, либо name=icon
    var input =
      document.querySelector('[data-af-am-icon-input="1"]') ||
      document.querySelector('input[name="icon"]');

    var pickBtn = document.querySelector('[data-af-am-iconpick="1"]');
    var clearBtn = document.querySelector('[data-af-am-iconclear="1"]');
    var preview = document.querySelector('[data-af-am-iconpreview="1"]');

    return { input: input, pickBtn: pickBtn, clearBtn: clearBtn, preview: preview };
  }

  function boot() {
    var els = findElements();
    if (!els.input || !els.pickBtn || !els.preview) return;

    setPreview(els.preview, els.input.value);

    els.input.addEventListener('input', function () {
      setPreview(els.preview, els.input.value);
    });

    els.pickBtn.addEventListener('click', function () {
      openPicker(els.input);
    });

    if (els.clearBtn) {
      els.clearBtn.addEventListener('click', function () {
        els.input.value = '';
        setPreview(els.preview, '');
        try { els.input.focus(); } catch (e) {}
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

/* Visual navigation editor. The server remains authoritative for identities and placement. */
(function () {
  'use strict';
  function bootTree() {
    var root = document.getElementById('af-am-editor');
    var seed = document.getElementById('af-am-editor-state');
    if (!root || !seed || root.dataset.bound) return;
    root.dataset.bound = '1';
    var config = JSON.parse(seed.textContent);
    var clone = function (value) { return JSON.parse(JSON.stringify(value)); };
    var state = clone(config), baseline = clone(config), dirty = false, saving = false, term = '';
    var treeHost = root.querySelector('[data-am-tree]'), status = root.querySelector('[data-am-status]');
    var openKey = 'af-am-tree-open:' + location.pathname, opened = {};
    try { opened = JSON.parse(localStorage.getItem(openKey) || '{}'); } catch (_) { opened = {}; }
    if (!opened || typeof opened !== 'object') opened = {};
    var drag = null, insertion = null, menuSerial = 0;
    var marker = document.createElement('li'); marker.className = 'af-am-drop-marker'; marker.setAttribute('aria-hidden', 'true');
    function el(tag, text, cls) {
      var node = document.createElement(tag);
      if (text !== undefined && text !== null) node.textContent = text;
      if (cls) node.className = cls;
      return node;
    }
    function announce(message, error) { status.textContent = message; status.classList.toggle('is-error', !!error); }
    function changed() { dirty = true; announce('Есть несохранённые изменения'); }
    function button(text, action, cls) {
      var b = el('button', text, cls); b.type = 'button';
      b.addEventListener('click', function (event) { event.preventDefault(); event.stopPropagation(); if (!saving) action(); });
      return b;
    }
    function remember(details, key, initial) {
      details.open = term ? true : (Object.prototype.hasOwnProperty.call(opened, key) ? opened[key] : initial);
      details.addEventListener('toggle', function () {
        if (!term) { opened[key] = details.open; try { localStorage.setItem(openKey, JSON.stringify(opened)); } catch (_) {} }
      });
    }
    function listFor(key) {
      if (key.indexOf('root:') === 0) return state.tree[key.slice(5)];
      var found = locate('section', key.slice(8));
      return found ? found.node.children : null;
    }
    function locate(kind, id) {
      var found = null;
      Object.keys(state.tree).some(function (container) {
        return state.tree[container].some(function (node, index) {
          if (node.kind === kind && node.id === id) { found = { list: state.tree[container], index: index, container: container, node: node }; return true; }
          if (node.kind === 'section' && kind === 'item') {
            var childIndex = node.children.indexOf(id);
            if (childIndex !== -1) { found = { list: node.children, index: childIndex, container: container, node: {kind:'item', id:id} }; return true; }
          }
          return false;
        });
      });
      return found;
    }
    function targetContainer(key) {
      if (key.indexOf('root:') === 0) return key.slice(5);
      var found = locate('section', key.slice(8)); return found && found.container;
    }
    function allowed(kind, id, target) {
      if (term || saving || (kind === 'section' && target.indexOf('root:') !== 0)) return false;
      var container = targetContainer(target), source = locate(kind, id);
      if (!container || !source || !listFor(target)) return false;
      var ids = kind === 'section' ? source.node.children : [id];
      return ids.every(function (child) { return state.items[child].allowed.indexOf(container) !== -1; });
    }
    function move(kind, id, target, index) {
      if (!allowed(kind, id, target)) { announce('Перенос запрещён: проверьте ограничения провайдера или очистите поиск.', true); return; }
      var from = locate(kind, id), to = listFor(target), value;
      if (from.list === to && from.index < index) index--;
      from.list.splice(from.index, 1);
      value = target.indexOf('section:') === 0 ? id : from.node;
      to.splice(Math.max(0, Math.min(index, to.length)), 0, value);
      opened[target] = true;
      changed(); render();
      var handle = treeHost.querySelector('[data-node-id="' + CSS.escape(id) + '"] [data-drag-handle]');
      if (handle) handle.focus();
    }
    function destinations(kind, id) {
      var result = [];
      Object.keys(config.containers).forEach(function (container) {
        var rootKey = 'root:' + container;
        if (allowed(kind, id, rootKey)) result.push({key:rootKey, label:config.containers[container]});
        if (kind !== 'item') return;
        state.tree[container].forEach(function (node) {
          var key = 'section:' + node.id;
          if (node.kind === 'section' && allowed(kind,id,key)) result.push({key:key, label:config.containers[container] + ' → ' + state.sections[node.id].label});
        });
      });
      return result;
    }
    function dialog(title, build) {
      var modal = el('dialog', null, 'af-am-edit-dialog'), form = el('form'); form.method = 'dialog';
      form.appendChild(el('h2', title)); modal.appendChild(form); document.body.appendChild(modal);
      var last = document.activeElement;
      function close() { modal.close(); modal.remove(); if (last && last.isConnected) last.focus(); }
      build(form, close);
      modal.addEventListener('cancel', function (event) { event.preventDefault(); close(); });
      modal.showModal();
      var first = form.querySelector('input,select,button'); if (first) first.focus();
    }
    function field(form, label, value, options) {
      options = options || {};
      var wrap = el('label', label, 'af-am-edit-field'), input = el(options.multiline ? 'textarea' : 'input');
      if (options.checkbox) { input.type = 'checkbox'; input.checked = !!value; }
      else { input.value = value == null ? '' : value; if (!options.multiline) input.type = 'text'; }
      input.readOnly = !!options.readonly;
      if (options.required) input.required = true;
      if (options.max) input.maxLength = options.max;
      wrap.appendChild(input); form.appendChild(wrap); return input;
    }
    function edit(kind, id, fresh) {
      var original = fresh || (kind === 'section' ? state.sections[id] : state.items[id]);
      dialog(fresh ? (kind === 'section' ? 'Добавить секцию' : 'Добавить пункт') : 'Редактирование', function (form, close) {
        var provider = kind === 'item' && original.kind === 'provider';
        var name = field(form,'Название',original.label,{required:true,max:255});
        var icon = field(form,'Иконка (Font Awesome / URL / текст)',original.icon,{max:255});
        var enabled = field(form,'Включён',original.enabled,{checkbox:true});
        var key, url, hint, access;
        if (kind === 'section') field(form,'Key',id,{readonly:true});
        if (kind === 'item') {
          key = field(form,'Key',original.key,{required:true,readonly:provider,max:64});
          field(form,'Источник',original.source || 'AdvancedMenu',{readonly:true});
          field(form,'Тип',original.type || 'link',{readonly:true});
          url = field(form,'URL / действие',original.url,{required:!provider,readonly:provider,max:500});
          if (provider) field(form,'Дополнительные параметры действия',JSON.stringify(original.action || {},null,2),{readonly:true,multiline:true});
          else hint = field(form,'Подсказка',original.hint || '',{max:255});
        }
        access = field(form,provider ? 'Доступ (задаётся провайдером)' : 'Доступ: all, guests, users, groups:1,4',original.visibility || '',{readonly:provider,max:255});
        if (provider) form.appendChild(el('p','URL, тип, права и обработчик принадлежат провайдеру. Их редактирование недоступно.'));
        var controls = el('div',null,'af-am-dialog-actions'), apply = el('button','Применить'); apply.type = 'submit';
        controls.appendChild(apply); controls.appendChild(button('Отмена',close)); form.appendChild(controls);
        form.addEventListener('submit',function(event) {
          event.preventDefault();
          var copy = clone(original); copy.label = name.value.trim(); copy.icon = icon.value.trim(); copy.enabled = enabled.checked ? 1 : 0;
          if (kind === 'item' && !provider) { copy.key = key.value.trim(); copy.url = url.value.trim(); copy.hint = hint.value; copy.visibility = access.value.trim(); }
          if (kind === 'section') copy.visibility = access.value.trim();
          copy.reset = false;
          if (kind === 'section') state.sections[id] = copy; else state.items[id] = copy;
          if (fresh) state.tree.main.push(kind === 'section' ? {kind:'section',id:id,children:[]} : {kind:'item',id:id});
          close(); changed(); render();
          var handle = treeHost.querySelector('[data-node-id="' + CSS.escape(id) + '"] [data-drag-handle]');
          if (handle) handle.focus();
        });
      });
    }
    function moveDialog(kind,id) {
      if (term) { announce('Очистите поиск перед перемещением.',true); return; }
      dialog('Переместить',function(form,close) {
        var label = el('label','Куда'), select = el('select');
        destinations(kind,id).forEach(function(target) { var option = el('option',target.label); option.value = target.key; select.appendChild(option); });
        label.appendChild(select); form.appendChild(label);
        form.appendChild(el('p','Элемент будет добавлен в конец выбранной группы. Порядок можно изменить кнопками «Выше» / «Ниже».'));
        var submit = el('button','Переместить'); submit.type='submit'; form.appendChild(submit); form.appendChild(button('Отмена',close));
        form.addEventListener('submit',function(event) { event.preventDefault(); var key = select.value; close(); if (key) move(kind,id,key,listFor(key).length); });
      });
    }
    function resetProvider(id) {
      if (term) { announce('Очистите поиск перед восстановлением размещения.',true); return; }
      var item = state.items[id], defaults = item.defaults;
      item.label = defaults.label; item.icon = defaults.icon; item.enabled = 1; item.reset = true;
      var parent = locate('section',defaults.section);
      var target = parent && parent.container === defaults.default_container ? 'section:' + defaults.section : 'root:' + defaults.default_container;
      move('item',id,target,listFor(target).length);
    }
    function reorder(kind,id,direction) {
      var found = locate(kind,id), target = 'root:' + found.container;
      if (typeof found.list[found.index] === 'string') {
        state.tree[found.container].forEach(function(node) { if (node.kind === 'section' && node.children === found.list) target='section:'+node.id; });
      }
      var index = direction < 0 ? found.index - 1 : found.index + 2;
      if (direction < 0 && found.index === 0 || direction > 0 && found.index === found.list.length-1) return;
      move(kind,id,target,index);
    }
    function card(kind,id,summary) {
      var data = kind === 'section' ? state.sections[id] : state.items[id];
      var row = el(summary ? 'summary' : 'div',null,'af-am-node-card');
      var handle = button('⋮⋮',function(){ announce('Используйте меню действий → Переместить или Выше / Ниже.'); },'af-am-drag-handle');
      handle.dataset.dragHandle = '1'; handle.dataset.kind = kind; handle.dataset.id = id; handle.draggable = !term;
      handle.setAttribute('aria-label','Перетащить: '+data.label); row.appendChild(handle);
      if (summary) row.appendChild(el('span','▸','af-am-section-arrow'));
      var icon = el('span',null,'af-am-node-icon');
      // Only class tokens are rendered as markup; legacy image/HTML icons stay plain text here.
      if (/^[a-z0-9 _-]+$/i.test(data.icon || '') && /\bfa-/.test(data.icon)) { var i = el('i'); i.className = data.icon; i.setAttribute('aria-hidden','true'); icon.appendChild(i); }
      else icon.textContent = data.icon && data.icon.indexOf('<') === -1 && data.icon.length < 8 ? data.icon : '◇';
      row.appendChild(icon); row.appendChild(el('strong',data.label,'af-am-node-label'));
      if (kind === 'section') row.appendChild(el('span',String(locate(kind,id).node.children.length),'af-am-count'));
      else row.appendChild(el('small',data.kind === 'provider' ? data.source : 'Свой пункт','af-am-source'));
      row.appendChild(el('span',data.enabled ? 'Включён' : 'Выключен','af-am-node-status' + (data.enabled ? '' : ' is-disabled')));
      row.appendChild(button('Редактировать',function(){ edit(kind,id); }));
      var menu = el('span',null,'af-am-node-menu'), actions = el('span',null,'af-am-node-actions');
      actions.hidden = true; actions.id = 'af-am-actions-' + (++menuSerial);
      var toggle = button('⋯',function(){actions.hidden=!actions.hidden;toggle.setAttribute('aria-expanded',String(!actions.hidden));});
      toggle.setAttribute('aria-label','Действия: '+data.label); toggle.setAttribute('aria-controls',actions.id); toggle.setAttribute('aria-expanded','false');
      menu.appendChild(toggle);
      actions.appendChild(button('Переместить',function(){actions.hidden=true;toggle.setAttribute('aria-expanded','false');moveDialog(kind,id);}));
      actions.appendChild(button('Выше',function(){reorder(kind,id,-1);}));
      actions.appendChild(button('Ниже',function(){reorder(kind,id,1);}));
      actions.appendChild(button(data.enabled ? 'Выключить' : 'Включить',function(){data.enabled=data.enabled?0:1;changed();render();}));
      if (kind === 'item' && data.kind === 'provider') actions.appendChild(button('Вернуть defaults',function(){resetProvider(id);}));
      if (kind === 'item' && data.kind === 'custom') actions.appendChild(button('Удалить',function(){
        dialog('Удалить «'+data.label+'»?',function(form,close){
          form.appendChild(el('p','Удаление будет выполнено только после сохранения меню.'));
          form.appendChild(button('Удалить',function(){ var found=locate(kind,id); found.list.splice(found.index,1); if (id.indexOf('c:')===0) state.deleted.push(id); delete state.items[id]; close(); changed(); render(); }));
          form.appendChild(button('Отмена',close));
        });
      }));
      menu.appendChild(actions); row.appendChild(menu); return row;
    }
    function matches(item) { return !term || (item.label+' '+(item.key||'')).toLocaleLowerCase().indexOf(term)!==-1; }
    function makeList(key) { var list = el('ul',null,'af-am-tree-list'); list.dataset.list=key; list.setAttribute('aria-label',key.indexOf('root:')===0 ? config.containers[key.slice(5)] : state.sections[key.slice(8)].label); return list; }
    function itemNode(id) {
      var li=el('li',null,'af-am-tree-node'); li.dataset.nodeId=id; li.dataset.kind='item'; li.hidden=!matches(state.items[id]); li.appendChild(card('item',id,false)); return li;
    }
    function render() {
      treeHost.replaceChildren();
      Object.keys(config.containers).forEach(function(container){
        var details=el('details',null,'af-am-container'), key='root:'+container;
        var heading=el('summary',config.containers[container],'af-am-container-title'); details.appendChild(heading);
        var list=makeList(key);
        state.tree[container].forEach(function(node){
          if(node.kind==='item') {list.appendChild(itemNode(node.id));return;}
          var li=el('li',null,'af-am-tree-node af-am-section-node'); li.dataset.nodeId=node.id; li.dataset.kind='section';
          var section=el('details',null,'af-am-section'); section.appendChild(card('section',node.id,true));
          var children=makeList('section:'+node.id), showGroup=matches({label:state.sections[node.id].label,key:node.id});
          node.children.forEach(function(id){var child=itemNode(id); if(term && showGroup)child.hidden=false; children.appendChild(child);});
          li.hidden=!!term&&!showGroup&&!node.children.some(function(id){return matches(state.items[id]);});
          section.appendChild(children); remember(section,'section:'+node.id,true); li.appendChild(section); list.appendChild(li);
        });
        details.appendChild(list); remember(details,key,container==='main'); treeHost.appendChild(details);
      });
      root.classList.toggle('is-filtered',!!term);
    }
    function clearDrop(){marker.remove();insertion=null;root.querySelectorAll('.is-drop-allowed').forEach(function(n){n.classList.remove('is-drop-allowed');});}
    treeHost.addEventListener('dragstart',function(event){
      var handle=event.target.closest('[data-drag-handle]');
      if(!handle||term||saving){event.preventDefault();return;}
      drag={kind:handle.dataset.kind,id:handle.dataset.id};
      event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',drag.id);
      root.classList.add('is-dragging');
    });
    treeHost.addEventListener('dragover',function(event){
      if(!drag)return;
      var list=event.target.closest('[data-list]');
      var heading=event.target.closest('.af-am-container-title');
      var sectionHeading=event.target.closest('.af-am-section > summary');
      if(heading){heading.parentElement.open=true;list=heading.parentElement.querySelector(':scope > [data-list]');}
      if(sectionHeading && drag.kind==='item'){sectionHeading.parentElement.open=true;list=sectionHeading.parentElement.querySelector(':scope > [data-list]');}
      if(!list||!allowed(drag.kind,drag.id,list.dataset.list)){clearDrop();event.dataTransfer.dropEffect='none';return;}
      event.preventDefault();event.dataTransfer.dropEffect='move';
      var children=Array.from(list.children).filter(function(n){return n!==marker;});
      var before=children.find(function(n){var box=n.getBoundingClientRect();return event.clientY < box.top+box.height/2;});
      clearDrop(); list.classList.add('is-drop-allowed');
      var index=before?children.indexOf(before):children.length;
      list.insertBefore(marker,before||null);insertion={target:list.dataset.list,index:index};
    });
    treeHost.addEventListener('drop',function(event){
      if(!drag||!insertion)return;event.preventDefault();
      var target=insertion,source=drag;clearDrop();drag=null;root.classList.remove('is-dragging');move(source.kind,source.id,target.target,target.index);
    });
    treeHost.addEventListener('dragend',function(){drag=null;clearDrop();root.classList.remove('is-dragging');});
    root.querySelector('[data-am-search]').addEventListener('input',function(event){term=event.target.value.trim().toLocaleLowerCase();clearDrop();render();announce(term?'Поиск активен. Перемещение отключено.':dirty?'Есть несохранённые изменения':'Нет несохранённых изменений');});
    async function save(){
      if(saving)return;saving=true;root.classList.add('is-saving');root.setAttribute('aria-busy','true');announce('Сохранение…');
      var body=new URLSearchParams();body.set('my_post_key',config.postKey);body.set('structure',JSON.stringify({revision:state.revision,tree:state.tree,items:state.items,sections:state.sections,deleted:state.deleted}));
      try{
        var response=await fetch(config.saveUrl,{method:'POST',credentials:'same-origin',body:body});
        var doc=new DOMParser().parseFromString(await response.text(),'text/html'), resultNode=doc.getElementById('af-am-save-result');
        if(!response.ok||!resultNode)throw new Error('Не удалось сохранить: проверьте соединение и сессию ACP. Изменения остались в редакторе.');
        var result=JSON.parse(resultNode.textContent);if(!result.ok)throw new Error(result.message);
        state=result.state;baseline=clone(state);dirty=false;render();announce('Изменения сохранены');
      }catch(error){announce(error.message||'Ошибка сохранения. Изменения остались в редакторе.',true);}
      finally{saving=false;root.classList.remove('is-saving');root.removeAttribute('aria-busy');}
    }
    root.querySelectorAll('[data-am-command]').forEach(function(control){control.addEventListener('click',function(){
      if(saving)return;
      var command=control.dataset.amCommand;
      if(command==='save'){save();return;}
      if(command==='cancel'){
        if(!dirty)return;
        dialog('Отменить изменения?',function(form,close){form.appendChild(button('Отменить изменения',function(){state=clone(baseline);dirty=false;close();render();announce('Изменения отменены');}));form.appendChild(button('Продолжить редактирование',close));});return;
      }
      if(command==='expand'||command==='collapse'){treeHost.querySelectorAll('details:not(.af-am-node-menu)').forEach(function(details){details.open=command==='expand';});return;}
      var token=Date.now().toString(36)+Math.random().toString(36).slice(2,7);
      if(command==='add-section')edit('section','s_'+token,{label:'Новая секция',icon:'fa-solid fa-folder',enabled:1,visibility:''});
      if(command==='add-item')edit('item','n:'+token,{kind:'custom',label:'Новый пункт',icon:'',enabled:1,key:'link_'+token,url:'',hint:'',visibility:'',allowed:Object.keys(config.containers)});
    });});
    root.addEventListener('keydown',function(event){if(event.key==='Escape'){var menu=event.target.closest('.af-am-node-menu');if(menu){menu.querySelector('.af-am-node-actions').hidden=true;menu.querySelector('button').setAttribute('aria-expanded','false');menu.querySelector('button').focus();event.stopPropagation();}}});
    document.addEventListener('click',function(event){root.querySelectorAll('.af-am-node-menu').forEach(function(menu){if(!menu.contains(event.target)){menu.querySelector('.af-am-node-actions').hidden=true;menu.querySelector('button').setAttribute('aria-expanded','false');}});});
    window.addEventListener('beforeunload',function(event){if(dirty){event.preventDefault();event.returnValue='';}});
    render();
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',bootTree);else bootTree();
})();

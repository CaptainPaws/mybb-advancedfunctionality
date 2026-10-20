(function () {
  'use strict';

  function init() {
    if (window.afAasInitialized) return;
    window.afAasInitialized = true;

  function qs(sel, root) { return (root || document).querySelector(sel); }

  function getBburl() {
    if (window.afAasConfig && typeof window.afAasConfig.bburl === 'string' && window.afAasConfig.bburl) {
      return window.afAasConfig.bburl.replace(/\/+$/, '');
    }
    return '';
  }

  function buildUrl(path) {
    var bburl = getBburl();
    if (bburl) return bburl + '/' + String(path).replace(/^\/+/, '');
    return String(path).replace(/^\/+/, '');
  }

  // Header modal (👥)
  (function initModal() {
    var modal = qs('#af_aas_modal');
    var closeBtn = modal ? modal.querySelector('.af-aas-modal-close') : null;
    var backdrop = modal ? modal.querySelector('.af-aas-modal-backdrop') : null;

    if (!modal || document.afAasModalOwner) return;
    document.afAasModalOwner = true;

    // The legacy header template renders its trigger and shell together.  The
    // trigger may be relocated by AdvancedMenu, but the dialog must live in
    // the page modal layer rather than inherit a transformed/clipped header.
    function mountModal() {
      if (window.AFModalHost && typeof window.AFModalHost.mount === 'function') window.AFModalHost.mount(modal);
      else {
        var host = document.getElementById('atf-global-modal-host') || document.body;
        if (modal.parentNode !== host) host.appendChild(modal);
      }
    }
    mountModal();

    function triggers() { return document.querySelectorAll('#af_aas_trigger, [data-af-aas-open]'); }
    function setExpanded(value) {
      Array.prototype.forEach.call(triggers(), function (node) { node.setAttribute('aria-expanded', value); });
    }

    var opener = null, inertNodes = [];
    function openModal() {
      if (modal.classList.contains('af-aas-modal-open')) return;
      opener = document.activeElement;
      mountModal();
      inertNodes = [];
      // ATF owns the modal's portal. Never inert an ancestor (including the
      // shared host), only sibling branches along the path to body.
      for (var branch = modal; branch && branch !== document.body; branch = branch.parentElement) {
        if (!branch.parentElement) break;
        Array.from(branch.parentElement.children).forEach(function(node) {
          if (node !== branch && !node.inert) { inertNodes.push(node); node.inert = true; }
        });
      }
      document.body.classList.add('af-aas-lock-scroll');
      modal.classList.add('af-aas-modal-open');
      modal.style.display = 'flex';
      modal.setAttribute('aria-hidden', 'false');
      setExpanded('true');
      if (closeBtn) closeBtn.focus();
    }

    function closeModal() {
      if (!modal.classList.contains('af-aas-modal-open')) return;
      closeMenus();
      inertNodes.forEach(function(node) { node.inert = false; }); inertNodes = [];
      document.body.classList.remove('af-aas-lock-scroll');
      modal.classList.remove('af-aas-modal-open');
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');
      setExpanded('false');
      if (opener && opener.isConnected) opener.focus();
    }

    document.addEventListener('click', function (e) {
      var trigger = e.target.closest('#af_aas_trigger, [data-af-aas-open]');
      if (!trigger) return;
      if (e.button === 0 && !e.ctrlKey && !e.metaKey && !e.shiftKey && !e.altKey) {
        e.preventDefault();
        // A trigger click only opens. The previous toggle made a duplicate
        // delegated/legacy delivery execute closeModal() on the same click.
        // Closing remains owned by the X, backdrop, outside click and Escape.
        openModal();
      }
    });

    if (closeBtn) closeBtn.addEventListener('click', function (e) { e.preventDefault(); closeModal(); });
    if (backdrop) backdrop.addEventListener('click', function (e) { e.preventDefault(); closeModal(); });

    document.addEventListener('click', function (e) {
      if (modal.style.display === 'none') return;
      if (e.target.closest('#af_aas_modal') || e.target.closest('#af_aas_trigger, [data-af-aas-open]')) return;
      closeModal();
    });

    document.addEventListener('keydown', function (e) {
      if (!modal.classList.contains('af-aas-modal-open')) return;
      if (e.key === 'Escape') {
        var opened = modal.querySelector('.af-aas-account-menu[open]');
        if (opened) { closeMenus(); opened.querySelector('summary').focus(); } else closeModal();
        e.preventDefault(); return;
      }
      if (e.key === 'Tab') {
        var nodes = Array.from(modal.querySelectorAll('a[href],button:not([disabled]),input:not([type="hidden"]),summary')).filter(function(node) { return node.tabIndex >= 0 && node.getClientRects().length && !node.closest('[hidden],[inert]'); });
        if (!nodes.length) return;
        var first = nodes[0], last = nodes[nodes.length-1];
        if (e.shiftKey && (document.activeElement === first || !modal.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && (document.activeElement === last || !modal.contains(document.activeElement))) { e.preventDefault(); first.focus(); }
      }
    });

    modal.addEventListener('click', function(event) {
      if (event.target.closest('[data-aas-add-close]')) { closeModal(); return; }
      var add = event.target.closest('[data-aas-add]');
      if (add) { event.preventDefault(); showAdd(); return; }
      if (event.target.closest('[data-aas-add-back]')) { showPicker(); modal.querySelector('[data-aas-add]')?.focus(); return; }
      var tab = event.target.closest('[data-aas-mode]');
      if (tab) selectMode(tab.dataset.aasMode);
    });
    var addView = modal.querySelector('[data-aas-add-view]');
    var addContent = modal.querySelector('[data-aas-add-content]');
    var addMessage = modal.querySelector('[data-aas-add-message]');
    var addLoading = false, addSubmitting = false;
    function showPicker() {
      addView.hidden = true;
      modal.querySelectorAll('[data-aas-picker]').forEach(function(node) { node.hidden = false; });
      addView.querySelectorAll('input[name*="password"]').forEach(function(input) { input.value=''; input.type='password'; });
    }
    function selectMode(mode) {
      var forms = Array.from(addContent.querySelectorAll('[data-aas-form-mode]'));
      if (!forms.some(function(form) { return form.dataset.aasFormMode === mode; })) return;
      forms.forEach(function(form) {
        var active = form.dataset.aasFormMode === mode;
        form.hidden = !active; form.inert = !active;
      });
      addContent.querySelectorAll('[data-aas-mode]').forEach(function(tab) {
        var active = tab.dataset.aasMode === mode;
        tab.setAttribute('aria-selected',String(active)); tab.tabIndex = active ? 0 : -1;
      });
    }
    function showAdd() {
      modal.querySelectorAll('[data-aas-picker]').forEach(function(node) { node.hidden=true; });
      addView.hidden = false;
      if (addContent.querySelector('form')) { addContent.querySelector('form:not([hidden]) input:not([type="hidden"])')?.focus(); return; }
      if (addLoading) return;
      addLoading = true; addMessage.textContent='Загрузка…';
      var config = modal.querySelector('.af-aas-walk');
      var body = new URLSearchParams({aas_ajax:'1',aas_view:'1',my_post_key:config.dataset.postKey});
      fetch(buildUrl('usercp.php?action=af_aas'),{method:'POST',credentials:'same-origin',body:body}).then(uiResponse).then(function(data) {
        addContent.innerHTML=data.forms;
        var tabs = document.createElement('nav'); tabs.className='af-aas-mode-tabs';
        tabs.setAttribute('role','tablist'); tabs.setAttribute('aria-label','Добавление аккаунта');
        tabs.addEventListener('keydown',function(event) {
          var items = Array.from(tabs.querySelectorAll('[role="tab"]'));
          var index = items.indexOf(document.activeElement), next = index;
          if (index < 0) return;
          if (event.key === 'ArrowRight') next = (index+1)%items.length;
          else if (event.key === 'ArrowLeft') next = (index+items.length-1)%items.length;
          else if (event.key === 'Home') next = 0;
          else if (event.key === 'End') next = items.length-1;
          else return;
          event.preventDefault(); selectMode(items[next].dataset.aasMode); items[next].focus();
        });
        addContent.querySelectorAll('[data-aas-form-mode]').forEach(function(form) {
          var tab=document.createElement('button'); tab.type='button'; tab.className='af-aas-ui-button'; tab.dataset.aasMode=form.dataset.aasFormMode;
          form.id='af-aas-add-'+form.dataset.aasFormMode;
          tab.id=form.id+'-tab'; tab.setAttribute('role','tab'); tab.setAttribute('aria-controls',form.id);
          form.setAttribute('role','tabpanel'); form.setAttribute('aria-labelledby',tab.id);
          tab.textContent=form.dataset.aasFormMode==='create' ? 'Создать новый' : 'Привязать существующий'; tabs.appendChild(tab);
        });
        addContent.prepend(tabs);
        var first=addContent.querySelector('form[data-aas-form-mode="link_existing"]') || addContent.querySelector('form');
        if (first) { selectMode(first.dataset.aasFormMode); initUcpSuggest(addContent); }
        addMessage.textContent=first ? '' : 'Добавление аккаунтов недоступно.';
        if (!addView.hidden && modal.classList.contains('af-aas-modal-open')) addContent.querySelector('form:not([hidden]) input:not([type="hidden"])')?.focus();
      }).catch(function(error) { addMessage.textContent=error.message; }).finally(function(){addLoading=false;});
    }
    modal.addEventListener('submit',function(event) {
      var form=event.target.closest('[data-aas-form-mode]');
      if (!form) return;
      event.preventDefault();
      if (form.dataset.pending || addSubmitting) return;
      addSubmitting=true;
      form.dataset.pending='1'; var button=form.querySelector('[type="submit"]'); button.disabled=true;
      var body=new FormData(form); body.set('aas_ajax','1');
      addMessage.textContent='Выполняется…';
      fetch(form.action,{method:'POST',credentials:'same-origin',body:body}).then(uiResponse).then(function(data){
        document.querySelectorAll('[data-aas-cards]').forEach(function(list){list.innerHTML=data.cards;});
        form.reset(); showPicker(); modal.querySelector('.af-aas-walk-result').textContent=data.message || 'Аккаунт добавлен.';
        modal.querySelector('[data-aas-add]')?.focus();
      }).catch(function(error){addMessage.textContent=error.message;}).finally(function(){
        form.querySelectorAll('input[name*="password"]').forEach(function(input){input.value='';input.type='password';});
        form.querySelectorAll('[data-aas-password]').forEach(function(button){button.setAttribute('aria-pressed','false');button.setAttribute('aria-label','Показать пароль');});
        delete form.dataset.pending; addSubmitting=false; button.disabled=false;
      });
    });
    var originalClose = closeModal;
    closeModal = function() { showPicker(); originalClose(); };
  })();

  function uiResponse(response) {
    if (!(response.headers.get('content-type')||'').includes('application/json')) throw new Error('Не удалось получить ответ. Обновите страницу и проверьте вход в аккаунт.');
    return response.json().then(function(data){if (!response.ok || !data.ok) throw new Error(data.message || 'Операция не выполнена.'); return data;});
  }
  function closeMenus(except) {
    document.querySelectorAll('.af-aas-surface .af-aas-account-menu[open]').forEach(function(menu){if(menu!==except)menu.open=false;});
  }
  // One delegated action owner for picker and User CP, including refreshed cards.
  var walkPending=false;
  document.addEventListener('toggle',function(event){
    var details=event.target;
    if (!details.matches('.af-aas-surface .af-aas-account-menu')) return;
    var menu=details.querySelector('.af-aas-dropdown');
    if (!details.open) {if(menu.hidePopover && menu.matches(':popover-open'))menu.hidePopover();return;}
    closeMenus(details); if(menu.showPopover && !menu.matches(':popover-open'))menu.showPopover();
    var rect=details.querySelector('summary').getBoundingClientRect();
    menu.style.left=Math.max(8,Math.min(rect.right-menu.offsetWidth,innerWidth-menu.offsetWidth-8))+'px';
    menu.style.top=Math.max(8,Math.min(rect.bottom+4,innerHeight-menu.offsetHeight-8))+'px';
  },true);
  document.addEventListener('click',function(event){
    var surface=event.target.closest('.af-aas-surface');
    if (!event.target.closest('.af-aas-account-menu'))closeMenus();
    if (!surface) return;
    var reveal=event.target.closest('[data-aas-password]');
    if (reveal) {var input=reveal.parentElement.querySelector('input'); var visible=input.type==='password'; input.type=visible?'text':'password';reveal.setAttribute('aria-pressed',String(visible));reveal.setAttribute('aria-label',visible?'Скрыть пароль':'Показать пароль');return;}
    var switchLink=event.target.closest('.af-aas-switch');
    if (switchLink && event.button===0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) {
      if(switchLink.getAttribute('aria-disabled')==='true'){event.preventDefault();return;}
      switchLink.setAttribute('aria-disabled','true');switchLink.setAttribute('aria-busy','true');switchLink.querySelector('span').textContent='Переключение…';return;
    }
    var personal=event.target.closest('[data-aas-walk-uid]'), bulk=event.target.closest('.af-aas-walk-button');
    if(!personal && !bulk)return;
    var button=personal||bulk, uid=personal?Number(personal.dataset.aasWalkUid):0;
    var autopost=personal ? personal.closest('.af-aas-dropdown').querySelector('[data-aas-personal-autopost]').checked : !!surface.querySelector('.af-aas-walk-autopost')?.checked;
    closeMenus(); if(walkPending)return; walkPending=true;
    var config=surface.querySelector('.af-aas-walk')||surface, result=surface.querySelector('.af-aas-walk-result');
    var bytes=new Uint8Array(16);crypto.getRandomValues(bytes);
    var token=Array.from(bytes,function(n){return n.toString(16).padStart(2,'0');}).join('');
    var body=new URLSearchParams({my_post_key:config.dataset.postKey,operation_id:token,autopost:autopost?'1':'0'});
    if(uid)body.set('uid',String(uid));
    var initial=button.innerHTML;button.disabled=true;button.textContent='Выполняется…';result.textContent='';
    fetch(config.dataset.url||config.dataset.walkUrl,{method:'POST',credentials:'same-origin',body:body}).then(uiResponse).then(function(data){
      result.textContent=[data.message||'Выгул завершён.','Обработано: '+(data.processed||0), 'Опубликовано: '+(data.posted||0)].concat(data.errors||[]).join('\n');
    }).catch(function(error){result.textContent=error.message;}).finally(function(){walkPending=false;button.disabled=false;button.innerHTML=initial;});
  });
  document.addEventListener('submit',function(event){if(event.target.matches('.af-aas-surface [data-aas-unlink]') && !confirm('Отвязать выбранный аккаунт? Сам аккаунт MyBB не будет удалён.'))event.preventDefault();});
  document.addEventListener('keydown',function(event){if(event.key==='Escape'){var menu=document.querySelector('.af-aas-surface .af-aas-account-menu[open]');if(menu){closeMenus();menu.querySelector('summary').focus();event.preventDefault();}}});
  document.addEventListener('scroll',function(event){if(event.target.matches?.('.af-aas-account-list'))closeMenus();},true);
  window.addEventListener('resize',function(){closeMenus();});

  // UCP suggest
  function initUcpSuggest(root) {
    var input = qs('[data-aas-suggest-input], #afAasLinkUsername', root);
    var hiddenUid = qs('[data-aas-suggest-uid], #afAasLinkUid', root);
    var suggestBox = qs('[data-aas-suggest-box], #afAasSuggest', root);

    if (!input || !hiddenUid || !suggestBox || input.dataset.aasSuggestBound) return;
    input.dataset.aasSuggestBound="1";

    input.setAttribute('autocomplete', 'off');
    input.setAttribute('autocorrect', 'off');
    input.setAttribute('autocapitalize', 'off');
    input.setAttribute('spellcheck', 'false');

    var host = input.parentElement || input.closest('div') || input.closest('td') || document.body;
    if (host && host !== document.body) {
      var cs = window.getComputedStyle(host);
      if (cs && cs.position === 'static') {
        host.style.position = 'relative';
      }
    }

    suggestBox.style.position = 'absolute';
    suggestBox.style.left = '0';
    suggestBox.style.right = '0';
    suggestBox.style.bottom = '100%';
    suggestBox.style.top = 'auto';
    suggestBox.style.marginBottom = '6px';
    suggestBox.style.zIndex = '9999';

    var timer = null;
    var lastQuery = '';

    function clearSuggest() {
      suggestBox.innerHTML = '';
      suggestBox.setAttribute('hidden', 'hidden');
    }

    function escapeHtml(s) {
      return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    function render(items) {
      if (!items || !items.length) { clearSuggest(); return; }
      suggestBox.innerHTML = items.map(function (it) {
        return '<div class="af-aas-suggest-item" role="button" tabindex="0" ' +
          'data-uid="' + String(it.uid) + '" data-username="' + escapeHtml(it.username) + '">' +
          escapeHtml(it.username) + ' <span class="smalltext">(#' + String(it.uid) + ')</span></div>';
      }).join('');
      suggestBox.removeAttribute('hidden');
    }

    function load(q) {
      var query = q.trim();
      hiddenUid.value = '0';

      if (query.length < 2) { clearSuggest(); return; }
      if (query === lastQuery) return;
      lastQuery = query;

      if (timer) clearTimeout(timer);
      timer = setTimeout(function () {
        var url = buildUrl('misc.php?action=af_aas_user_suggest&query=' + encodeURIComponent(query));

        fetch(url, { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (!j || !j.ok) { clearSuggest(); return; }
            render(j.items || []);
          })
          .catch(function () { clearSuggest(); });
      }, 160);
    }

    input.addEventListener('input', function () {
      load(input.value || '');
    });

    input.addEventListener('focus', function () {
      if ((input.value || '').trim().length >= 2) load(input.value || '');
    });

    suggestBox.addEventListener('click', function (e) {
      var item = e.target.closest('.af-aas-suggest-item');
      if (!item) return;
      input.value = item.getAttribute('data-username') || '';
      hiddenUid.value = item.getAttribute('data-uid') || '0';
      clearSuggest();
      input.focus();
    });

    suggestBox.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      var item = e.target.closest('.af-aas-suggest-item');
      if (!item) return;
      input.value = item.getAttribute('data-username') || '';
      hiddenUid.value = item.getAttribute('data-uid') || '0';
      clearSuggest();
      input.focus();
    });

    document.addEventListener('click', function (e) {
      if (e.target === input || e.target.closest('#afAasSuggest')) return;
      clearSuggest();
    });
  }
  initUcpSuggest(document);

  // userlist.php: online/offline indicator over avatar (JS-only, no inline CSS)
  (function initUserlistOnlineStatus() {
    function isUserlist() {
      var p = String(window.location.pathname || '').toLowerCase();
      if (/(^|\/)userlist\.php$/i.test(p)) return true;
      return /userlist\.php/i.test(String(window.location.href || ''));
    }
    if (!isUserlist()) return;

    function onReady(fn) {
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
      else fn();
    }

    function uidFromHref(href) {
      if (!href) return 0;
      var m = String(href).match(/[?&]uid=(\d+)/i);
      return m ? (parseInt(m[1], 10) || 0) : 0;
    }

    function getAvatarNodeInCell(td) {
      if (!td) return null;

      // самый частый случай: <a><img></a>
      var a = td.querySelector('a');
      if (a) {
        var ai = a.querySelector('img');
        if (ai) return a;
      }

      // fallback: просто <img>
      var img = td.querySelector('img');
      return img || null;
    }

    function ensureWrap(td, avatarNode) {
      // если уже есть wrap — используем
      var wrap = td.querySelector('.af-ul-avatarwrap');
      if (!wrap) {
        wrap = document.createElement('span');
        wrap.className = 'af-ul-avatarwrap';
        td.insertBefore(wrap, avatarNode);
      }

      // переносим аватар внутрь wrap, если он ещё не там
      if (avatarNode && avatarNode.parentNode !== wrap) {
        wrap.appendChild(avatarNode);
      }

      return wrap;
    }

    function ensureDot(wrap) {
      if (!wrap) return null;

      // антидубль: уже есть наша точка
      var dot = wrap.querySelector('.af-ul-status-dot[data-af-ul="1"]');
      if (dot) return dot;

      dot = document.createElement('span');
      dot.className = 'af-ul-status-dot';
      dot.setAttribute('data-af-ul', '1');
      wrap.appendChild(dot);
      return dot;
    }

    function setDotState(dot, state) {
      if (!dot) return;

      // state: 'online' | 'offline' | 'invisible'
      dot.className = 'af-ul-status-dot ' +
        (state === 'online' ? 'af-ul-online' :
         state === 'invisible' ? 'af-ul-invisible' :
         'af-ul-offline');

      dot.setAttribute('title',
        state === 'online' ? 'Онлайн' :
        state === 'invisible' ? 'Невидимка' :
        'Оффлайн'
      );
    }

    function collectUserCells() {
      // uid -> td (где аватар)
      var links = Array.prototype.slice.call(document.querySelectorAll('a[href*="member.php"][href*="uid="]'));
      var map = new Map();

      links.forEach(function (a) {
        var uid = uidFromHref(a.getAttribute('href'));
        if (!uid) return;

        var tr = a.closest('tr');
        if (!tr) return;

        // на userlist аватар в первой/второй ячейке строки — берём первую td, где есть img
        var tds = Array.prototype.slice.call(tr.querySelectorAll('td'));
        for (var i = 0; i < tds.length; i++) {
          var td = tds[i];
          if (td.querySelector('img')) {
            if (!map.has(uid)) map.set(uid, td);
            break;
          }
        }
      });

      return map;
    }

    function fetchOnlineUids() {
      var url = buildUrl('online.php');
      return fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) {
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var a = doc.querySelectorAll('a[href*="member.php"][href*="uid="]');
          var set = new Set();
          for (var i = 0; i < a.length; i++) {
            var uid = uidFromHref(a[i].getAttribute('href'));
            if (uid) set.add(uid);
          }
          return set;
        });
    }

    onReady(function () {
      var map = collectUserCells();
      if (!map || map.size === 0) return;

      fetchOnlineUids()
        .then(function (onlineSet) {
          map.forEach(function (td, uid) {
            var avatarNode = getAvatarNodeInCell(td);
            if (!avatarNode) return;

            var wrap = ensureWrap(td, avatarNode);
            var dot = ensureDot(wrap);

            // базово: онлайн/оффлайн по online.php
            var isOnline = !!(onlineSet && onlineSet.has(uid));
            setDotState(dot, isOnline ? 'online' : 'offline');
          });
        })
        .catch(function () {
          // если online.php недоступен — не ломаем страницу
        });
    });
  })();

  }

  // Keep the same lifecycle contract as Alerts: the asset can be emitted in
  // headerinclude (including by an older theme without `defer`), while the AAS
  // trigger and modal shell are rendered later in the page body.
  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    init();
  } else {
    document.addEventListener('DOMContentLoaded', init);
  }

})();

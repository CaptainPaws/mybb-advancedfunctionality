(function () {
  'use strict';

  if (window.afAqrAdminToolbarInitialized) return;
  window.afAqrAdminToolbarInitialized = true;

  var P = window.afAeAdminToolbarPayload || {};
  var available = Array.isArray(P.available) ? P.available : [];
  var layout = P.layout && typeof P.layout === 'object' ? P.layout : null;
  var dragState = null;


  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function esc(s) {
    return String(s || '').replace(/[&<>"]/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]);
    });
  }

  function uid() { return 'sec_' + Math.random().toString(16).slice(2) + Date.now().toString(16); }

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
  }

  function normalizeLayout(x) {
    if (!x || typeof x !== 'object' || !Array.isArray(x.sections)) {
      return {
        v: 1,
        sections: [
          {
            id: 'main',
            type: 'group',
            title: 'Основное',
            items: [
              'bold', 'italic', 'underline', 'strike', 'subscript', 'superscript',
              '|',
              'font', 'size', 'color', 'removeformat',
              '|',
              'undo', 'redo', 'pastetext', 'horizontalrule',
              '|',
              'left', 'center', 'right', 'justify',
              '|',
              'bulletlist', 'orderedlist',
              '|',
              'quote', 'code',
              '|',
              'link', 'unlink', 'email', 'image', 'youtube', 'emoticon',
              '|',
              'source', 'maximize'
            ]
          },
          { id: 'addons', type: 'group', title: 'Доп. кнопки', items: [] }
        ]
      };
    }
    if (!x.v) x.v = 1;
    if (!Array.isArray(x.sections)) x.sections = [];
    return x;
  }

  function buildAllowedCmdSet() {
    var s = Object.create(null);
    available.forEach(function (b) {
      if (!b || !b.cmd) return;
      s[String(b.cmd)] = true;
    });
    s['|'] = true;
    // dropdown-команды мы генерим сами как afmenu_*
    // пропускаем их через allow по префиксу при проверке
    return s;
  }

  function sanitizeLayout(lay) {
    lay = normalizeLayout(lay);

    var allowed = buildAllowedCmdSet();

    (lay.sections || []).forEach(function (sec) {
      if (!sec || typeof sec !== 'object') return;

      // страховка
      sec.id = String(sec.id || uid());
      sec.type = String(sec.type || 'group').toLowerCase();
      sec.title = String(sec.title || (sec.type === 'dropdown' ? '★' : 'Секция'));

      if (!Array.isArray(sec.items)) sec.items = [];

      // чистим мусор/пустые
      sec.items = sec.items
        .map(function (x) { return String(x || '').trim(); })
        .filter(function (cmd) {
          if (!cmd) return false;
          if (cmd === '|') return true;
          if (/^afmenu_/i.test(cmd)) return true; // внутреннее
          return !!allowed[cmd];
        });
    });

    return lay;
  }

  var state = {
    layout: sanitizeLayout(layout)
  };

  function serializeLayout() { return JSON.stringify(state.layout); }

  function setHiddenJson() {
    var hid = $('#af_aqr_layout_json');
    if (hid) hid.value = serializeLayout();
  }

  function buildToolbarFromLayout(lay) {
    var parts = [];
    var menus = [];

    (lay.sections || []).forEach(function (sec, idx) {
      if (!sec) return;

      var type = String(sec.type || 'group').toLowerCase();
      var id = String(sec.id || ('sec' + idx));
      var title = String(sec.title || '');

      var items = Array.isArray(sec.items) ? sec.items.slice() : [];

      if (type === 'dropdown') {
        var cmd = 'afmenu_' + id.replace(/[^a-z0-9_\-]/gi, '_');
        parts.push(cmd);
        menus.push({ id: id, cmd: cmd, title: (title || '★'), items: items.slice() });
        parts.push('|');
        return;
      }

      var group = [];
      items.forEach(function (it) {
        it = String(it || '').trim();
        if (!it) return;

        if (it === '|') {
          if (group.length) parts.push(group.join(','));
          group = [];
          parts.push('|');
          return;
        }

        group.push(it);
      });

      if (group.length) parts.push(group.join(','));
      parts.push('|');
    });

    var toolbar = parts.join(',');
    toolbar = toolbar.replace(/,+\|,+/g, '|').replace(/\|{2,}/g, '|');
    toolbar = toolbar.replace(/^,|,$/g, '').replace(/^\|+|\|+$/g, '');
    return { toolbar: toolbar, menus: menus };
  }

  function dndSet(ev, obj) {
    try { ev.dataTransfer.setData('application/json', JSON.stringify(obj)); } catch (e) { }
    if (obj && obj.cmd) {
      // ВАЖНО: text/plain должен быть всегда — Firefox иногда режет кастомный mime
      try { ev.dataTransfer.setData('text/plain', String(obj.cmd)); } catch (e2) { }
    }
  }

    function dndGet(ev) {
        // 1) пробуем JSON (на drop обычно работает)
        var raw = '';
        try { raw = ev.dataTransfer.getData('application/json') || ''; } catch (e) { }
        if (raw) {
            try { return JSON.parse(raw); } catch (e2) { }
        }

        // 2) пробуем text/plain
        var cmd = '';
        try { cmd = ev.dataTransfer.getData('text/plain') || ''; } catch (e3) { }
        cmd = String(cmd || '').trim();

        if (cmd) {
            // ВАЖНО: если браузер отдал только text/plain, то метаданные берём из dragState
            if (dragState && dragState.cmd && String(dragState.cmd) === cmd) {
            return {
                cmd: cmd,
                from: dragState.from || 'unknown',
                fromSid: dragState.fromSid,
                fromIdx: dragState.fromIdx
            };
            }
            return { cmd: cmd, from: 'unknown' };
        }

        // 3) ФОЛЛБЭК: в dragover/getData часто пусто — используем dragState
        if (dragState && dragState.cmd) {
            return {
            cmd: dragState.cmd,
            from: dragState.from || 'unknown',
            fromSid: dragState.fromSid,
            fromIdx: dragState.fromIdx
            };
        }

        return null;
    }

    function removeFromAnySection(cmd) {
        cmd = String(cmd || '').trim();
        if (!cmd) return false;

        var secs = state.layout.sections || [];
        for (var i = 0; i < secs.length; i++) {
            var sec = secs[i];
            if (!sec || !Array.isArray(sec.items)) continue;

            var idx = sec.items.indexOf(cmd);
            if (idx >= 0) {
            sec.items.splice(idx, 1);
            return true;
            }
        }
        return false;
    }


  function findSectionById(id) {
    id = String(id || '');
    return (state.layout.sections || []).find(function (s) { return String(s.id) === id; }) || null;
  }

    function removeFromSectionByDnd(data) {
        if (!data || !data.cmd) return false;

        // Если мы реально тащим pill — но часть меты потерялась, всё равно удаляем.
        // (trash-зоны и так проверяют dragState.from === 'pill', так что случайных удалений не будет)
        var cmd = String(data.cmd || '').trim();
        if (!cmd) return false;

        // Нормальный путь: есть fromSid
        if (data.fromSid) {
            var fromSec = findSectionById(data.fromSid);
            if (!fromSec || !Array.isArray(fromSec.items)) return false;

            var idx = (typeof data.fromIdx === 'number') ? data.fromIdx : fromSec.items.indexOf(cmd);
            if (idx < 0 || idx >= fromSec.items.length) return false;

            fromSec.items.splice(idx, 1);
            return true;
        }

        // Фоллбэк: мета потерялась (Firefox/ACP), удалим первое вхождение по cmd
        return removeFromAnySection(cmd);
    }


  // "Корзина": дроп в пустое место секций (или в левый список доступных) удаляет pill из layout
    function bindTrashDropZonesOnce() {
        // 1) Пустое место справа (контейнер секций)
        var secWrap = $('.af-aqr-sections');
        if (secWrap && !secWrap.dataset.afAqrTrashBound) {
        secWrap.dataset.afAqrTrashBound = '1';

        secWrap.addEventListener('dragover', function (ev) {
            // ВАЖНО: не читаем dataTransfer здесь — часто пусто.
            if (!dragState || dragState.from !== 'pill') return;

            // Если курсор над конкретной секцией-dropzone — это НЕ корзина
            if (ev.target && ev.target.closest && ev.target.closest('.af-aqr-drop')) return;

            ev.preventDefault(); // без этого drop не случится
            try { ev.dataTransfer.dropEffect = 'move'; } catch (e) {}
            secWrap.classList.add('is-trash-over');
        }, true);

        secWrap.addEventListener('dragleave', function (ev) {
            var rt = ev.relatedTarget;
            if (rt && secWrap.contains(rt)) return;
            secWrap.classList.remove('is-trash-over');
        }, false);

        secWrap.addEventListener('drop', function (ev) {
            // Корзина срабатывает ТОЛЬКО если дропнули НЕ в .af-aqr-drop
            if (ev.target && ev.target.closest && ev.target.closest('.af-aqr-drop')) return;

            if (!dragState || dragState.from !== 'pill') return;

            ev.preventDefault();
            secWrap.classList.remove('is-trash-over');

            var data = dndGet(ev); // здесь уже может сработать и dataTransfer, и dragState
            if (data && removeFromSectionByDnd(data)) {
            renderSections();
            schedulePreview();
            }

            dragState = null;
        }, true);

        // на всякий: если пользователь отпустил где-то “мимо”
        secWrap.addEventListener('dragend', function () {
            secWrap.classList.remove('is-trash-over');
            dragState = null;
        }, true);
        }

        // 2) Дроп обратно в "Доступные кнопки" = удалить из секции
        var availWrap = $('.af-aqr-btnlist');
        if (availWrap && !availWrap.dataset.afAqrTrashBound) {
        availWrap.dataset.afAqrTrashBound = '1';

        availWrap.addEventListener('dragover', function (ev) {
            if (!dragState || dragState.from !== 'pill') return;
            ev.preventDefault();
            try { ev.dataTransfer.dropEffect = 'move'; } catch (e) {}
            availWrap.classList.add('is-trash-over');
        }, true);

        availWrap.addEventListener('dragleave', function (ev) {
            var rt = ev.relatedTarget;
            if (rt && availWrap.contains(rt)) return;
            availWrap.classList.remove('is-trash-over');
        }, false);

        availWrap.addEventListener('drop', function (ev) {
            if (!dragState || dragState.from !== 'pill') return;

            ev.preventDefault();
            availWrap.classList.remove('is-trash-over');

            var data = dndGet(ev);
            if (data && removeFromSectionByDnd(data)) {
            renderSections();
            schedulePreview();
            }

            dragState = null;
        }, true);

        availWrap.addEventListener('dragend', function () {
            availWrap.classList.remove('is-trash-over');
            dragState = null;
        }, true);
        }

        // 3) Явная корзина (отдельный блок слева)
        var trash = $('#af_aqr_trash');
        if (trash && !trash.dataset.afAqrTrashBound) {
        trash.dataset.afAqrTrashBound = '1';

        trash.addEventListener('dragover', function (ev) {
            if (!dragState || dragState.from !== 'pill') return;
            ev.preventDefault();
            try { ev.dataTransfer.dropEffect = 'move'; } catch (e) {}
            trash.classList.add('is-trash-over');
        }, true);

        trash.addEventListener('dragleave', function (ev) {
            var rt = ev.relatedTarget;
            if (rt && trash.contains(rt)) return;
            trash.classList.remove('is-trash-over');
        }, false);

        trash.addEventListener('drop', function (ev) {
            if (!dragState || dragState.from !== 'pill') return;

            ev.preventDefault();
            trash.classList.remove('is-trash-over');

            var data = dndGet(ev);
            if (data && removeFromSectionByDnd(data)) {
            renderSections();
            schedulePreview();
            }

            dragState = null;
        }, true);

        trash.addEventListener('dragend', function () {
            trash.classList.remove('is-trash-over');
            dragState = null;
        }, true);
        }
    }

 

  function getInsertIndex(dropEl, target) {
    var pill = target && target.classList && target.classList.contains('af-aqr-pill')
      ? target
      : (target && target.closest ? target.closest('.af-aqr-pill') : null);

    if (!pill) {
      return dropEl.querySelectorAll('.af-aqr-pill').length;
    }

    var pills = Array.prototype.slice.call(dropEl.querySelectorAll('.af-aqr-pill'));
    var idx = pills.indexOf(pill);
    return idx >= 0 ? idx : pills.length;
  }


  function renderAvail() {
    var box = $('.af-aqr-btnlist');
    if (!box) return;
    box.innerHTML = '';

    // 1) обычные доступные кнопки (как было)
    available.forEach(function (b) {
      if (!b || !b.cmd) return;

      var el = document.createElement('div');
      el.className = 'af-aqr-chip';
      el.draggable = true;
      el.dataset.cmd = b.cmd;

      var ico = document.createElement('div');
      ico.className = 'ico';

      function looksLikeUrl(x) {
        x = String(x || '').trim();
        return /^https?:\/\//i.test(x) || x.startsWith('/');
      }

      function isSvgMarkupSafe(x) {
        x = String(x || '').trim();
        if (!x) return false;
        if (!(x.startsWith('<svg') && x.includes('</svg>'))) return false;
        var low = x.toLowerCase();
        if (low.includes('<script') || low.includes('onload=') || low.includes('onerror=')) return false;
        return true;
      }

      function looksLikeSvgUrl(u) {
        u = String(u || '').trim().toLowerCase();
        return u.includes('.svg') || u.startsWith('data:image/svg');
      }

      function applyMaskIcon(el, url) {
        el.style.backgroundImage = 'none';
        el.style.backgroundRepeat = '';
        el.style.backgroundPosition = '';
        el.style.backgroundSize = '';

        el.style.webkitMaskImage = 'url("' + url.replace(/"/g, '\\"') + '")';
        el.style.maskImage = 'url("' + url.replace(/"/g, '\\"') + '")';

        el.style.webkitMaskRepeat = 'no-repeat';
        el.style.maskRepeat = 'no-repeat';

        el.style.webkitMaskPosition = 'center';
        el.style.maskPosition = 'center';

        el.style.webkitMaskSize = '16px 16px';
        el.style.maskSize = '16px 16px';

        el.style.backgroundColor = 'currentColor';
      }

      if (b.icon) {
        var ic = String(b.icon).trim();

        if (isSvgMarkupSafe(ic)) {
          ico.innerHTML = ic;
          ico.style.backgroundImage = 'none';
          ico.style.backgroundColor = '';
          ico.textContent = '';
        } else if (looksLikeUrl(ic) && looksLikeSvgUrl(ic)) {
          ico.innerHTML = '';
          ico.textContent = '';
          applyMaskIcon(ico, ic);
        } else if (looksLikeUrl(ic)) {
          ico.style.backgroundImage = 'url(' + ic + ')';
          ico.style.backgroundSize = '16px 16px';
          ico.style.backgroundRepeat = 'no-repeat';
          ico.style.backgroundPosition = 'center';
          ico.style.backgroundColor = '';
          ico.style.webkitMaskImage = 'none';
          ico.style.maskImage = 'none';
          ico.textContent = '';
        } else {
          ico.textContent = b.label || '•';
        }
      } else {
        ico.textContent = b.label || '•';
      }

      var txt = document.createElement('div');
      txt.innerHTML = '<div><strong>' + esc(b.cmd) + '</strong></div><small>' + esc(b.hint || '') + '</small>';

      el.appendChild(ico);
      el.appendChild(txt);

      el.addEventListener('dragstart', function (ev) {
        dragState = { cmd: String(b.cmd), from: 'available' };
        dndSet(ev, { cmd: b.cmd, from: 'available' });
        try { ev.dataTransfer.effectAllowed = 'copyMove'; } catch (e0) {}
      });

      el.addEventListener('dragend', function () {
        dragState = null;
      });

      box.appendChild(el);
    });

    // 2) ДОБАВКА: показываем существующие dropdown-секции как "доступные" чипы,
    // чтобы можно было перетащить ИМЕННО ИХ между секциями
    var drops = (state.layout && state.layout.sections ? state.layout.sections : [])
      .filter(function (s) { return s && String(s.type).toLowerCase() === 'dropdown'; });

    if (drops.length) {
      // тонкий разделитель, чтобы визуально не мешало обычным кнопкам
      var sep = document.createElement('div');
      sep.className = 'af-aqr-avail-sep';
      sep.style.margin = '10px 0';
      sep.style.opacity = '0.7';
      sep.innerHTML = '<small>Dropdown-секции (перетащи в нужное место)</small>';
      box.appendChild(sep);

      drops.forEach(function (sec) {
        var el = document.createElement('div');
        el.className = 'af-aqr-chip af-aqr-chip-dropdown';
        el.draggable = true;

        // спец cmd (только для DnD метки)
        var token = '__af_dropdown__:' + String(sec.id);
        el.dataset.cmd = token;
        el.dataset.dropdownSid = String(sec.id);

        var ico = document.createElement('div');
        ico.className = 'ico';
        // простая звезда, без уродства и без зависимостей
        ico.innerHTML = svgStarMarkup();
        ico.style.backgroundImage = 'none';
        ico.style.backgroundColor = '';
        ico.style.webkitMaskImage = 'none';
        ico.style.maskImage = 'none';

        var txt = document.createElement('div');
        var title = String(sec.title || '★').trim();
        txt.innerHTML =
          '<div><strong>' + esc(title) + '</strong></div>' +
          '<small>' + esc('dropdown #' + String(sec.id)) + '</small>';

        el.appendChild(ico);
        el.appendChild(txt);

        el.addEventListener('dragstart', function (ev) {
          // ВАЖНО: тащим НЕ "кнопку", а "секцию dropdown"
          dragState = {
            cmd: token,
            from: 'dropdown_section',
            fromSid: String(sec.id)
          };

          dndSet(ev, {
            cmd: token,
            from: 'dropdown_section',
            fromSid: String(sec.id)
          });

          try { ev.dataTransfer.effectAllowed = 'move'; } catch (e0) {}
        });

        el.addEventListener('dragend', function () {
          dragState = null;
        });

        box.appendChild(el);
      });
    }
  }

    function renderSections() {
        var wrap = $('.af-aqr-sections');
        if (!wrap) return;
        wrap.innerHTML = '';

        (state.layout.sections || []).forEach(function (sec) {
            var box = document.createElement('div');
            box.className = 'af-aqr-sec';
            box.dataset.sid = sec.id;

            var hd = document.createElement('div');
            hd.className = 'af-aqr-sec-hd';

            var meta = document.createElement('div');
            meta.className = 'meta';

            var title = document.createElement('input');
            title.type = 'text';
            title.value = sec.title || '';
            title.placeholder = 'Название секции / символ (для dropdown)';
            title.addEventListener('input', function () {
            sec.title = title.value;
            schedulePreview();
            });

            var type = document.createElement('select');
            type.innerHTML = '<option value="group">group</option><option value="dropdown">dropdown</option>';
            type.value = String(sec.type || 'group');
            type.addEventListener('change', function () {
            sec.type = type.value;
            schedulePreview();
            });

            meta.appendChild(title);
            meta.appendChild(type);

            var del = document.createElement('a');
            del.href = '#';
            del.textContent = 'Удалить';
            del.addEventListener('click', function (ev) {
            ev.preventDefault();
            state.layout.sections = state.layout.sections.filter(function (s) { return s !== sec; });
            renderSections();
            schedulePreview();
            });

            hd.appendChild(meta);
            hd.appendChild(del);

            var drop = document.createElement('div');
            drop.className = 'af-aqr-drop';
            drop.dataset.sid = sec.id;

            // Чтобы в пустую секцию можно было нормально бросать
            drop.style.minHeight = '44px';

            function onDragOver(ev) {
            ev.preventDefault();
            drop.classList.add('is-over');

            // Мы ВСЕГДА работаем в режиме move (а source теперь copyMove),
            // так Firefox не запрещает drop.
            try { ev.dataTransfer.dropEffect = 'move'; } catch (e) { }
            }
            function onDragEnter(ev) {
            ev.preventDefault();
            drop.classList.add('is-over');
            }
            function onDragLeave(ev) {
            var rt = ev.relatedTarget;
            if (rt && drop.contains(rt)) return;
            drop.classList.remove('is-over');
            }

            drop.addEventListener('dragover', onDragOver, true);
            drop.addEventListener('dragenter', onDragEnter, true);
            drop.addEventListener('dragleave', onDragLeave, false);
            drop.addEventListener('drop', function (ev) {
              ev.preventDefault();
              drop.classList.remove('is-over');

              var data = dndGet(ev);
              if (!data || !data.cmd) return;

              // НОВОЕ: если тащим dropdown-секцию (через чип в "Доступных") —
              // то это НЕ вставка в items, а ПЕРЕМЕЩЕНИЕ dropdown-секции по порядку.
              if (data.from === 'dropdown_section' && data.fromSid) {
                var sid = String(data.fromSid);
                var secs = state.layout.sections || [];

                var fromIndex = -1;
                for (var ii = 0; ii < secs.length; ii++) {
                  if (String(secs[ii].id) === sid) { fromIndex = ii; break; }
                }

                if (fromIndex >= 0) {
                  var moving = secs[fromIndex];
                  // тащим только dropdown-секции (страховка)
                  if (moving && String(moving.type).toLowerCase() === 'dropdown') {
                    secs.splice(fromIndex, 1);

                    // цель: разместить dropdown ПОСЛЕ секции, куда ты дропнула
                    var toId = String(sec.id);
                    var toIndex = -1;
                    for (var jj = 0; jj < secs.length; jj++) {
                      if (String(secs[jj].id) === toId) { toIndex = jj; break; }
                    }
                    if (toIndex < 0) toIndex = secs.length - 1;

                    secs.splice(toIndex + 1, 0, moving);

                    // обновляем UI и превью + обновим "available", чтобы чипы соответствовали новой позиции
                    renderSections();
                    renderAvail();
                    schedulePreview();
                  }
                }

                dragState = null;
                return;
              }

              // --- старое поведение (кнопки/пиллы) ---
              sec.items = Array.isArray(sec.items) ? sec.items : [];

              var insertAt = getInsertIndex(drop, ev.target);

              // move/copy logic
              if (data.from === 'pill' && data.fromSid) {
                var fromSec = findSectionById(data.fromSid);
                if (fromSec && Array.isArray(fromSec.items)) {
                  var fromIdx = (typeof data.fromIdx === 'number')
                    ? data.fromIdx
                    : fromSec.items.indexOf(data.cmd);

                  if (fromIdx >= 0) {
                    fromSec.items.splice(fromIdx, 1);

                    if (String(fromSec.id) === String(sec.id) && fromIdx < insertAt) {
                      insertAt = Math.max(0, insertAt - 1);
                    }
                  }
                }
              }

              if (insertAt < 0) insertAt = 0;
              if (insertAt > sec.items.length) insertAt = sec.items.length;

              sec.items.splice(insertAt, 0, String(data.cmd));

              renderSections();
              schedulePreview();
            });

            // Плейсхолдер в пустой секции
            if (!sec.items || !sec.items.length) {
            var ph = document.createElement('div');
            ph.className = 'af-aqr-drop-placeholder';
            ph.textContent = 'Перетащи кнопки сюда';
            ph.style.opacity = '0.6';
            ph.style.padding = '10px';
            ph.style.pointerEvents = 'none';
            drop.appendChild(ph);
            }

            (sec.items || []).forEach(function (cmd, idx) {
            cmd = String(cmd || '').trim();
            if (!cmd) return;

            var pill = document.createElement('div');
            pill.className = 'af-aqr-pill';
            pill.draggable = true;
            pill.dataset.cmd = cmd;
            pill.dataset.sid = sec.id;
            pill.dataset.idx = String(idx);
            pill.textContent = cmd;

            pill.addEventListener('dragover', function (ev) { ev.preventDefault(); }, true);
            pill.addEventListener('dragenter', function (ev) { ev.preventDefault(); }, true);

            pill.addEventListener('dragstart', function (ev) {
              dragState = {
                cmd: String(cmd),
                from: 'pill',
                fromSid: String(sec.id),
                fromIdx: idx
              };

              dndSet(ev, {
                cmd: cmd,
                from: 'pill',
                fromSid: String(sec.id),
                fromIdx: idx
              });

              try { ev.dataTransfer.effectAllowed = 'copyMove'; } catch (e0) {}
            });

            pill.addEventListener('dragend', function () {
              dragState = null;
            });


            pill.addEventListener('dblclick', function () {
                sec.items.splice(idx, 1);
                renderSections();
                schedulePreview();
            });

            drop.appendChild(pill);
            });

            box.appendChild(hd);
            box.appendChild(drop);
            wrap.appendChild(box);
        });
    }

  var previewTimer = null;

  function schedulePreview() {
    setHiddenJson();
    if (previewTimer) clearTimeout(previewTimer);
    previewTimer = setTimeout(renderPreview, 120);
  }

  function ensureSceditor(cb) {
    if (window.jQuery && jQuery.fn && jQuery.fn.sceditor) return cb();

    var tries = 0;
    var t = setInterval(function () {
      tries++;
      if (window.jQuery && jQuery.fn && jQuery.fn.sceditor) {
        clearInterval(t);
        cb();
      }
      if (tries > 80) clearInterval(t);
    }, 100);
  }

  function svgStarMarkup() {
    // простая звезда, чтобы точно отображалась (без зависимости от шрифта)
    return '' +
      '<svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
      '<path d="M12 17.3l-6.18 3.73 1.64-7.03L2 9.24l7.19-.62L12 2l2.81 6.62 7.19.62-5.46 4.76 1.64 7.03z"></path>' +
      '</svg>';
  }

  function ensureCustomCommandsFromAvailable() {
    if (!window.jQuery || !jQuery.sceditor) return;

    function getCmd(name) {
      try {
        if (jQuery.sceditor.command && typeof jQuery.sceditor.command.get === 'function') {
          return jQuery.sceditor.command.get(name);
        }
      } catch (e0) {}
      try { if (jQuery.sceditor.commands) return jQuery.sceditor.commands[name]; } catch (e1) {}
      return null;
    }

    function setCmd(name, def) {
      try {
        if (jQuery.sceditor.command && typeof jQuery.sceditor.command.set === 'function') {
          jQuery.sceditor.command.set(name, def);
          return true;
        }
      } catch (e0) {}
      try {
        if (!jQuery.sceditor.commands) jQuery.sceditor.commands = {};
        jQuery.sceditor.commands[name] = def;
        return true;
      } catch (e1) {}
      return false;
    }

    available.forEach(function (b) {
      if (!b || !b.cmd) return;

      var cmd = String(b.cmd || '').trim();
      if (!cmd || cmd === '|' || !/^af_/i.test(cmd)) return;

      // уже есть — не трогаем
      if (getCmd(cmd)) return;

      var handler = String(b.handler || '').trim();
      var opentag = String(b.opentag || '');
      var closetag = String(b.closetag || '');

      var def = {
        tooltip: String(b.title || b.hint || cmd || '').trim() || cmd,

        exec: function (caller) {
          var ed = this;

          // 1) handler-путь (как у table)
          if (handler && window.afAqrBuiltinHandlers && window.afAqrBuiltinHandlers[handler]) {
            try {
              var h = window.afAqrBuiltinHandlers[handler];

              if (typeof h === 'function') {
                h({ editor: ed, caller: caller, cmd: cmd, payload: b });
                return;
              }
              if (h && typeof h.exec === 'function') {
                h.exec({ editor: ed, caller: caller, cmd: cmd, payload: b });
                return;
              }
            } catch (e0) {}
          }

          // 2) tag-путь (если когда-нибудь будет opentag/closetag)
          try {
            if (opentag && typeof ed.insert === 'function') {
              ed.insert(opentag, closetag || '');
              return;
            }
          } catch (e1) {}

          // 3) фоллбэк
          try {
            if (typeof ed.insert === 'function') ed.insert('[' + cmd + ']', '[/' + cmd + ']');
          } catch (e2) {}
        },

        txtExec: function (caller) {
          // в MyBB чаще работает exec и в source и в wysiwyg, но подстрахуем
          try { def.exec.call(this, caller); } catch (e0) {}
        }
      };

      setCmd(cmd, def);
    });
  }

  function ensureDropdownCommands(out) {
    if (!window.jQuery || !jQuery.sceditor) return;
    if (!out || !Array.isArray(out.menus)) return;

    // MyBB/SCEditor бывает в двух вариантах:
    // 1) jQuery.sceditor.command.set/get (новее)
    // 2) jQuery.sceditor.commands[name] = def (старее, часто в MyBB 1.8)
    function getCmd(name) {
      try {
        if (jQuery.sceditor.command && typeof jQuery.sceditor.command.get === 'function') {
          return jQuery.sceditor.command.get(name);
        }
      } catch (e0) {}
      try {
        if (jQuery.sceditor.commands) return jQuery.sceditor.commands[name];
      } catch (e1) {}
      return null;
    }

    function setCmd(name, def) {
      try {
        if (jQuery.sceditor.command && typeof jQuery.sceditor.command.set === 'function') {
          jQuery.sceditor.command.set(name, def);
          return true;
        }
      } catch (e0) {}
      try {
        if (!jQuery.sceditor.commands) jQuery.sceditor.commands = {};
        jQuery.sceditor.commands[name] = def;
        return true;
      } catch (e1) {}
      return false;
    }

    function buildDropdownContent(editor, menu) {
      var $content = jQuery('<div class="af-aqr-dd"></div>');

      (menu.items || []).forEach(function (cmd) {
        cmd = String(cmd || '').trim();
        if (!cmd || cmd === '|') return;

        var $btn = jQuery(
          '<button type="button" class="button" ' +
          'style="display:block;width:100%;margin:4px 0;text-align:left;"></button>'
        );
        $btn.text(cmd);

        $btn.on('click', function (e) {
          e.preventDefault();

          // Выполнить команду (если есть), иначе вставить текстом
          try {
            if (editor && editor.command && typeof editor.command.exec === 'function') {
              editor.command.exec(cmd);
            } else if (editor && typeof editor.execCommand === 'function') {
              editor.execCommand(cmd);
            } else if (editor && typeof editor.insert === 'function') {
              editor.insert(cmd, null);
            }
          } catch (e1) {
            try { if (editor && typeof editor.insert === 'function') editor.insert(cmd, null); } catch (e2) {}
          }

          try { if (editor && typeof editor.closeDropDown === 'function') editor.closeDropDown(true); } catch (e3) {}
        });

        $content.append($btn);
      });

      if (!$content.children().length) {
        $content.append(jQuery('<div class="smalltext" style="padding:6px 2px;">Пустое меню</div>'));
      }

      return $content;
    }

    out.menus.forEach(function (m) {
      if (!m || !m.cmd) return;

      // уже зарегистрирована — не трогаем
      if (getCmd(m.cmd)) return;

      var def = {
        tooltip: 'Dropdown: ' + (m.title || '★'),

        // Некоторые сборки SCEditor рисуют dropdown-кнопки, если есть dropDown
        dropDown: function (editor, caller /*, html */) {
          try {
            var $content = buildDropdownContent(editor, m);
            editor.createDropDown(caller, m.cmd, $content);
          } catch (e0) {}
        },

        // Универсальный путь: по клику на кнопку — открываем dropdown
        exec: function (caller) {
          try {
            var ed = this;
            if (def.dropDown) def.dropDown(ed, caller);
          } catch (e1) {}
        },
        txtExec: function (caller) {
          try {
            var ed = this;
            if (def.dropDown) def.dropDown(ed, caller);
          } catch (e2) {}
        }
      };

      setCmd(m.cmd, def);
    });
  }

    function decorateDropdownButtons(ta, out) {
      try {
        var cont = ta.previousElementSibling;
        var tb = cont ? cont.querySelector('.sceditor-toolbar') : null;
        if (!tb) return;

        function parseRgb(s) {
          s = String(s || '').trim();
          var m = s.match(/rgba?\(\s*([0-9.]+)\s*,\s*([0-9.]+)\s*,\s*([0-9.]+)(?:\s*,\s*([0-9.]+))?\s*\)/i);
          if (m) return { r: +m[1], g: +m[2], b: +m[3], a: (m[4] == null ? 1 : +m[4]) };
          return null;
        }
        function isTransparentRgb(x) { return !x || (typeof x.a === 'number' && x.a <= 0.02); }
        function getBg(el) {
          var cur = el;
          for (var i = 0; i < 8 && cur; i++) {
            var cs = null;
            try { cs = getComputedStyle(cur); } catch (e) { cs = null; }
            if (cs) {
              var bg = parseRgb(cs.backgroundColor);
              if (bg && !isTransparentRgb(bg)) return bg;
            }
            cur = cur.parentElement;
          }
          return null;
        }

        // Автоцвет иконок для mask (если уже задан — не лезем)
        try {
          var already = getComputedStyle(tb).getPropertyValue('--af-aqr-icon-color');
          if (!String(already || '').trim()) {
            var bgc = getBg(tb) || getBg(cont) || getBg(document.body);
            var r = bgc ? bgc.r : 255, g = bgc ? bgc.g : 255, b = bgc ? bgc.b : 255;
            var lum = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;
            var chosen = (lum < 0.48) ? 'rgba(255,255,255,.92)' : 'rgba(0,0,0,.72)';
            tb.style.setProperty('--af-aqr-icon-color', chosen);
          }
        } catch (eCol) {}

        function isUrl(x) {
          x = String(x || '').trim();
          return /^https?:\/\//i.test(x) || x.startsWith('/');
        }

        function isSvg(x) {
          x = String(x || '').trim();
          if (!x) return false;
          if (!(x.startsWith('<svg') && x.includes('</svg>'))) return false;
          var low = x.toLowerCase();
          if (low.includes('<script') || low.includes('onload=') || low.includes('onerror=')) return false;
          return true;
        }

        function looksLikeSvgUrl(u) {
          u = String(u || '').trim().toLowerCase();
          return u.includes('.svg') || u.startsWith('data:image/svg');
        }

        function titleSpec(t) {
          t = String(t || '').trim();
          if (isSvg(t)) return { kind: 'svg', value: t };
          if (isUrl(t)) return { kind: 'url', value: t };
          if (t) return { kind: 'text', value: t };
          return { kind: 'svg', value: svgStarMarkup() };
        }

        function applyUrlIcon(el, url) {
          url = String(url || '').trim();
          if (!url) return;

          el.style.backgroundImage = 'none';
          el.style.webkitMaskImage = 'none';
          el.style.maskImage = 'none';
          el.style.backgroundColor = '';

          if (looksLikeSvgUrl(url)) {
            el.style.webkitMaskImage = 'url("' + url.replace(/"/g, '\\"') + '")';
            el.style.maskImage = 'url("' + url.replace(/"/g, '\\"') + '")';
            el.style.webkitMaskRepeat = 'no-repeat';
            el.style.maskRepeat = 'no-repeat';
            el.style.webkitMaskPosition = 'center';
            el.style.maskPosition = 'center';
            el.style.webkitMaskSize = '16px 16px';
            el.style.maskSize = '16px 16px';

            el.style.backgroundColor = 'var(--af-aqr-icon-color, currentColor)';
          } else {
            el.style.backgroundImage = 'url("' + url.replace(/"/g, '\\"') + '")';
            el.style.backgroundRepeat = 'no-repeat';
            el.style.backgroundPosition = 'center';
            el.style.backgroundSize = '16px 16px';
          }
        }

        out.menus.forEach(function (m) {
          var a = tb.querySelector('a.sceditor-button-' + m.cmd);
          if (!a) return;

          // даём кнопке цвет, чтобы currentColor работал
          try { a.style.color = 'var(--af-aqr-icon-color, currentColor)'; } catch (e0) {}

          var d = a.querySelector('div');
          if (!d) return;

          var spec = titleSpec(m.title);

          d.innerHTML = '';
          d.textContent = '';
          d.style.backgroundImage = 'none';
          d.style.textIndent = '0';

          d.style.display = 'flex';
          d.style.alignItems = 'center';
          d.style.justifyContent = 'center';
          d.style.height = '16px';
          d.style.lineHeight = '16px';
          d.style.padding = '0';

          d.style.width = '16px';
          a.style.width = '';
          a.style.minWidth = '';
          a.style.padding = '';

          if (spec.kind === 'url') {
            applyUrlIcon(d, spec.value);
          } else if (spec.kind === 'svg') {
            d.innerHTML = spec.value;
          } else {
            d.textContent = String(spec.value).trim();
            d.style.fontSize = '12px';
            d.style.fontWeight = '700';

            d.style.width = 'auto';
            d.style.padding = '0 6px';

            a.style.width = 'auto';
            a.style.minWidth = '16px';
            a.style.padding = '0 2px';
          }
        });
      } catch (e) { }
    }

  function decorateCustomButtons(ta) {
    try {
      var cont = ta.previousElementSibling;
      var tb = cont ? cont.querySelector('.sceditor-toolbar') : null;
      if (!tb) return;

      function isUrl(x) {
        x = String(x || '').trim();
        return /^https?:\/\//i.test(x) || x.startsWith('/');
      }

      function isSvgMarkupSafe(x) {
        x = String(x || '').trim();
        if (!x) return false;
        if (!(x.startsWith('<svg') && x.includes('</svg>'))) return false;
        var low = x.toLowerCase();
        if (low.includes('<script') || low.includes('onload=') || low.includes('onerror=')) return false;
        return true;
      }

      function looksLikeSvgUrl(u) {
        u = String(u || '').trim().toLowerCase();
        return u.includes('.svg') || u.startsWith('data:image/svg');
      }

      function applyMaskIcon(el, url) {
        el.style.backgroundImage = 'none';
        el.style.backgroundRepeat = '';
        el.style.backgroundPosition = '';
        el.style.backgroundSize = '';

        el.style.webkitMaskImage = 'url("' + url.replace(/"/g, '\\"') + '")';
        el.style.maskImage = 'url("' + url.replace(/"/g, '\\"') + '")';

        el.style.webkitMaskRepeat = 'no-repeat';
        el.style.maskRepeat = 'no-repeat';

        el.style.webkitMaskPosition = 'center';
        el.style.maskPosition = 'center';

        el.style.webkitMaskSize = '16px 16px';
        el.style.maskSize = '16px 16px';

        // ВАЖНО: красим через переменную, чтобы смотрелось как остальные кнопки в ACP
        el.style.backgroundColor = 'var(--af-aqr-icon-color, currentColor)';
      }

      // available — уже есть сверху файла (var available = ...)
      available.forEach(function (b) {
        if (!b || !b.cmd) return;

        var cmd = String(b.cmd).trim();
        if (!cmd) return;

        // Декорируем ТОЛЬКО кастомные af_* кнопки
        if (!/^af_/i.test(cmd)) return;

        var a = tb.querySelector('a.sceditor-button-' + cmd);
        if (!a) return;

        // tooltip
        var t = String(b.title || b.hint || cmd).trim();
        if (t) {
          try { a.setAttribute('title', t); } catch (e0) {}
        }

        // внутренний div — "иконка"
        var d = a.querySelector('div');
        if (!d) return;

        // очищаем дефолтный спрайт SCEditor
        d.innerHTML = '';
        d.textContent = '';
        d.style.backgroundImage = 'none';
        d.style.webkitMaskImage = 'none';
        d.style.maskImage = 'none';
        d.style.backgroundColor = '';

        // нормальная центровка
        d.style.display = 'flex';
        d.style.alignItems = 'center';
        d.style.justifyContent = 'center';
        d.style.width = '16px';
        d.style.height = '16px';
        d.style.lineHeight = '16px';
        d.style.padding = '0';

        // 1) icon (SVG markup)
        var icon = String(b.icon || '').trim();
        if (icon && isSvgMarkupSafe(icon)) {
          d.innerHTML = icon;
          return;
        }

        // 2) icon (URL)
        if (icon && isUrl(icon)) {
          if (looksLikeSvgUrl(icon)) {
            applyMaskIcon(d, icon);
          } else {
            d.style.backgroundImage = 'url("' + icon.replace(/"/g, '\\"') + '")';
            d.style.backgroundRepeat = 'no-repeat';
            d.style.backgroundPosition = 'center';
            d.style.backgroundSize = '16px 16px';
          }
          return;
        }

        // 3) fallback: label/text
        var label = String(b.label || 'AE').trim();
        d.textContent = label || 'AE';
        d.style.fontSize = '11px';
        d.style.fontWeight = '800';
      });
    } catch (e) { }
  }


    function renderPreview() {
        // на всякий — если кто-то руками правил state.layout, держим его чистым
        state.layout = sanitizeLayout(state.layout);

        var out = buildToolbarFromLayout(state.layout);

        var strBox = $('.af-aqr-toolbarstr');
        if (strBox) strBox.textContent = out.toolbar || '(пусто)';

        setHiddenJson();

        ensureSceditor(function () {
        var ta = $('#af_aqr_preview_ta');
        if (!ta) return;

        // регистрируем dropdown-команды ДО инициализации sceditor,
        // иначе кнопки могут исчезнуть как "unknown"
        ensureDropdownCommands(out);
        // регистрируем кастомные af_* команды ДО инициализации sceditor,
        ensureCustomCommandsFromAvailable();


        try {
            var inst0 = jQuery(ta).sceditor('instance');
            if (inst0) inst0.destroy();
        } catch (e0) { }

        try {
            jQuery(ta).sceditor({
            format: 'bbcode',
            toolbar: out.toolbar,
            style: P.sceditorCss || '',
            height: 220,
            width: '100%',
            resizeEnabled: true
            });
        } catch (e1) { }

        // иконка/★ для dropdown-кнопок
        decorateDropdownButtons(ta, out);
        // иконки/лейблы для кастомных af_* кнопок (например af_table)
        decorateCustomButtons(ta);

        });
    }

    function addSection(type) {
      state.layout.sections.push({
        id: uid(),
        type: type || 'group',
        title: type === 'dropdown' ? '★' : 'Новая секция',
        items: []
      });

      renderSections();

      // НОВОЕ: чтобы после "+ Dropdown" он появился в доступных чипах
      if (String(type || '').toLowerCase() === 'dropdown') {
        renderAvail();
      }

      schedulePreview();
    }


  function bindActions() {
    var addGroup = $('#af_aqr_add_group');
    if (addGroup) {
      addGroup.addEventListener('click', function (ev) {
        ev.preventDefault();
        addSection('group');
      });
    }

    var addDrop = $('#af_aqr_add_dropdown');
    if (addDrop) {
      addDrop.addEventListener('click', function (ev) {
        ev.preventDefault();
        addSection('dropdown');
      });
    }

    var reset = $('#af_aqr_reset_layout');
    if (reset) {
      reset.addEventListener('click', function (ev) {
        ev.preventDefault();
        state.layout = sanitizeLayout(null);
        renderAvail();
        renderSections();
        renderPreview();
      });
    }
  }

  function init() {
    var need = $('.af-aqr-btnlist') && $('.af-aqr-sections') && $('#af_aqr_preview_ta') && $('#af_aqr_layout_json');
    if (!need) {
      setTimeout(init, 50);
      return;
    }

    // если из PHP пришёл layout — санитайзим под список доступных кнопок
    state.layout = sanitizeLayout(state.layout);

    renderAvail();
    renderSections();

    // ВАЖНО: после первичного рендера подвешиваем "корзину" (один раз)
    bindTrashDropZonesOnce();

    bindActions();

    setHiddenJson();
    renderPreview();
  }


  ready(init);
})();

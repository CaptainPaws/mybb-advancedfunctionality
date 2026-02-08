(function () {
  var modal = document.querySelector('[data-afcs-modal]');
  var frame = modal ? modal.querySelector('[data-afcs-frame]') : null;

  function closeModal() {
    if (!modal) {
      return;
    }
    modal.classList.remove('is-open');
    if (frame) {
      frame.removeAttribute('src');
    }
  }

  function openModal(url) {
    if (!modal || !frame) {
      window.open(url, '_blank');
      return;
    }
    var loadUrl = url;
    if (loadUrl.indexOf('ajax=1') === -1) {
      loadUrl += (loadUrl.indexOf('?') === -1 ? '?' : '&') + 'ajax=1';
    }
    frame.setAttribute('src', loadUrl);
    modal.classList.add('is-open');
  }

  document.addEventListener('click', function (event) {
    var plaque = event.target.closest('.af-cs-plaque__btn[data-afcs-open="1"]');
    if (plaque) {
      event.preventDefault();
      event.stopPropagation();
      var plaqueUrl = plaque.getAttribute('data-afcs-sheet') || plaque.getAttribute('href');
      if (plaqueUrl) {
        openModal(plaqueUrl);
        return;
      }
      var slug = plaque.getAttribute('data-slug');
      if (slug) {
        openModal('misc.php?action=af_charactersheet&slug=' + encodeURIComponent(slug));
      }
      return;
    }

    var trigger = event.target.closest('[data-afcs-sheet]');
    if (trigger) {
      event.preventDefault();
      var url = trigger.getAttribute('data-afcs-sheet');
      if (url) {
        openModal(url);
      }
      return;
    }

    if (event.target.closest('[data-afcs-close]')) {
      closeModal();
    }
  });

  document.querySelectorAll('[data-afcs-tabs]').forEach(function (tabs) {
    var buttons = tabs.querySelectorAll('[data-afcs-tab]');
    var panels = tabs.querySelectorAll('[data-afcs-tab-content]');

    function activateTab(name) {
      buttons.forEach(function (btn) {
        btn.classList.toggle('is-active', btn.getAttribute('data-afcs-tab') === name);
      });
      panels.forEach(function (panel) {
        panel.classList.toggle('is-active', panel.getAttribute('data-afcs-tab-content') === name);
      });
    }

    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var name = btn.getAttribute('data-afcs-tab');
        if (name) {
          activateTab(name);
        }
      });
    });
  });

  var catalog = document.querySelector('[data-afcs-catalog]');
  if (catalog) {
    var searchInput = document.querySelector('[data-afcs-search]');
    var filterRace = document.querySelector('[data-afcs-filter="race"]');
    var filterClass = document.querySelector('[data-afcs-filter="class"]');
    var filterTheme = document.querySelector('[data-afcs-filter="theme"]');
    var cards = Array.prototype.slice.call(catalog.querySelectorAll('[data-afcs-card]'));

    function normalize(value) {
      return (value || '').toLowerCase();
    }

    function collectOptions(select, key) {
      var values = {};
      cards.forEach(function (card) {
        var val = card.getAttribute('data-' + key) || '';
        val = val.trim();
        if (val) {
          values[val] = true;
        }
      });
      Object.keys(values).sort().forEach(function (val) {
        var option = document.createElement('option');
        option.value = val;
        option.textContent = val;
        select.appendChild(option);
      });
    }

    if (filterRace) {
      collectOptions(filterRace, 'race');
    }
    if (filterClass) {
      collectOptions(filterClass, 'class');
    }
    if (filterTheme) {
      collectOptions(filterTheme, 'theme');
    }

    function applyFilters() {
      var query = normalize(searchInput ? searchInput.value : '');
      var race = normalize(filterRace ? filterRace.value : '');
      var klass = normalize(filterClass ? filterClass.value : '');
      var theme = normalize(filterTheme ? filterTheme.value : '');

      cards.forEach(function (card) {
        var name = normalize(card.getAttribute('data-name'));
        var cardRace = normalize(card.getAttribute('data-race'));
        var cardClass = normalize(card.getAttribute('data-class'));
        var cardTheme = normalize(card.getAttribute('data-theme'));

        var matches = true;
        if (query && name.indexOf(query) === -1) {
          matches = false;
        }
        if (race && cardRace !== race) {
          matches = false;
        }
        if (klass && cardClass !== klass) {
          matches = false;
        }
        if (theme && cardTheme !== theme) {
          matches = false;
        }
        card.style.display = matches ? '' : 'none';
      });
    }

    if (searchInput) {
      searchInput.addEventListener('input', applyFilters);
    }
    [filterRace, filterClass, filterTheme].forEach(function (select) {
      if (select) {
        select.addEventListener('change', applyFilters);
      }
    });
  }

  var sheet = document.querySelector('[data-afcs-sheet]');
  if (sheet) {
    var sheetId = sheet.getAttribute('data-afcs-sheet-id');
    var postKey = sheet.getAttribute('data-afcs-post-key');

  function sendAction(action, payload) {
    if (!sheetId || !postKey) {
      alert('sheet_id/my_post_key missing in DOM');
      return Promise.reject(new Error('sheet_id/my_post_key missing in DOM'));
    }

    payload = payload || {};
    payload.do = action;
    payload.sheet_id = sheetId;
    payload.my_post_key = postKey;
    payload.ajax = 1; // важно: просим "ajax=1", чтобы сервер не инжектил ассеты

    function stripTags(s) {
      return String(s || '')
        .replace(/<script[\s\S]*?<\/script>/gi, '')
        .replace(/<style[\s\S]*?<\/style>/gi, '')
        .replace(/<[^>]+>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    }

    function safeUserMessage(raw, fallback) {
      var t = String(raw || '').trim();
      if (!t) return fallback || 'Ошибка запроса';

      // если это HTML/мусор — отрежем до человеческого
      var cleaned = stripTags(t);

      // частые маркеры, чтобы не показывать простыню
      if (/af_assets_begin|<link|<script|<!doctype|<html/i.test(t)) {
        cleaned = cleaned || 'Сервер вернул HTML вместо JSON. Проверь инъекцию ассетов для ajax=1.';
      }

      // ограничим длину, чтобы не взрывать alert
      if (cleaned.length > 320) cleaned = cleaned.slice(0, 320) + '…';
      return cleaned;
    }

  function extractJsonFromMixed(text) {
    var s = String(text || '');

    // 1) попробуем как чистый JSON
    try { return JSON.parse(s); } catch (e) {}

    // 2) найдём ПЕРВЫЙ корректно закрытый JSON-объект "{...}" в тексте
    var start = s.indexOf('{');
    if (start === -1) return null;

    var i = start;
    var depth = 0;
    var inStr = false;
    var esc = false;

    for (; i < s.length; i++) {
      var ch = s.charAt(i);

      if (inStr) {
        if (esc) { esc = false; continue; }
        if (ch === '\\') { esc = true; continue; }
        if (ch === '"') { inStr = false; continue; }
        continue;
      }

      if (ch === '"') { inStr = true; continue; }

      if (ch === '{') {
        depth++;
        continue;
      }
      if (ch === '}') {
        depth--;
        if (depth === 0) {
          var candidate = s.slice(start, i + 1);
          try { return JSON.parse(candidate); } catch (e2) { return null; }
        }
      }
    }

    return null;
  }

    var data = new FormData();
    Object.keys(payload).forEach(function (key) {
      var value = payload[key];
      if (typeof value === 'object' && value !== null) {
        Object.keys(value).forEach(function (sub) {
          data.append(key + '[' + sub + ']', value[sub]);
        });
        return;
      }
      data.append(key, value);
    });

    return fetch('misc.php?action=af_charactersheet_api&ajax=1', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json, text/plain, */*',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: data
    }).then(function (resp) {
      return resp.text().then(function (text) {
        if (!resp.ok) {
          // HTTP ошибка — покажем коротко, полный ответ в консоль
          console.error('[AF CS] HTTP error response:', resp.status, text);
          throw new Error(safeUserMessage(text, 'HTTP ' + resp.status));
        }

        var payloadObj = extractJsonFromMixed(text);
        if (!payloadObj) {
          console.error('[AF CS] Non-JSON response:', text);
          throw new Error(safeUserMessage(text, 'Некорректный ответ сервера'));
        }

        return payloadObj;
      });
    }).catch(function (error) {
      // здесь больше НЕ будет вывода простыни
      alert(error && error.message ? error.message : 'Ошибка запроса');
      console.error('[AF CS] Request failed:', error);
      throw error;
    });
  }

    function applyViewUpdate(payload) {
      if (payload.attributes_html) {
        var block = sheet.querySelector('[data-afcs-block="attributes"]');
        if (block) {
          block.innerHTML = payload.attributes_html;
        }
      }
      if (payload.skills_html) {
        var skillsBlock = sheet.querySelector('[data-afcs-block="skills"]');
        if (skillsBlock) {
          skillsBlock.innerHTML = payload.skills_html;
        }
      }
      if (payload.knowledge_html) {
        var knowledgeBlock = sheet.querySelector('[data-afcs-block="knowledge"]');
        if (knowledgeBlock) {
          knowledgeBlock.innerHTML = payload.knowledge_html;
        }
      }
      if (payload.progress_html) {
        var blockProgress = sheet.querySelector('[data-afcs-block="progress"]');
        if (blockProgress) {
          blockProgress.innerHTML = payload.progress_html;
        }
      }
      if (payload.view) {
        var levelValue = sheet.querySelector('[data-afcs-level-value]');
        var levelExp = sheet.querySelector('[data-afcs-level-exp]');
        var levelBar = sheet.querySelector('[data-afcs-level-bar]');
        if (levelValue) {
          levelValue.textContent = payload.view.level;
        }
        if (levelExp) {
          levelExp.textContent = payload.view.level_exp_label;
        }
        if (levelBar) {
          levelBar.style.width = payload.view.level_percent + '%';
        }
      }
      updatePool();
      initAttributesUI();
    }

    function updatePool() {
      var poolContainer = sheet.querySelector('[data-afcs-pool-max]');
      if (!poolContainer) {
        return;
      }
      var poolMax = parseInt(poolContainer.getAttribute('data-afcs-pool-max'), 10);
      if (isNaN(poolMax)) {
        poolMax = 0;
      }
      var inputs = sheet.querySelectorAll('[data-afcs-attr-input]');
      var spent = 0;
      inputs.forEach(function (input) {
        var value = parseInt(input.value || '0', 10);
        if (!isNaN(value)) {
          spent += value;
        }
      });
      var remaining = poolMax - spent;
      var spentEl = sheet.querySelector('[data-afcs-pool-spent]');
      var remainingEl = sheet.querySelector('[data-afcs-pool-remaining]');
      if (spentEl) {
        spentEl.textContent = spent;
      }
      if (remainingEl) {
        remainingEl.textContent = remaining;
      }
      var warning = sheet.querySelector('[data-afcs-pool-warning]');
      if (warning) {
        warning.hidden = remaining >= 0;
      }
      var saveButton = sheet.querySelector('[data-afcs-save-attributes]');
      if (saveButton) {
        saveButton.disabled = remaining < 0;
      }
    }

    function buildCompactAttributes(attrsRoot) {
      var compact = attrsRoot.querySelector('[data-afcs-attr-compact]');
      if (!compact) return;

      // Достаём значения/лейблы из уже отрендеренных карточек
      // Берём финальные числа из .af-cs-attr-final, лейблы из .af-cs-attr-label
      var cards = attrsRoot.querySelectorAll('.af-cs-attr-card');
      var html = '';

      cards.forEach(function (card) {
        var labelEl = card.querySelector('.af-cs-attr-label');
        var valueEl = card.querySelector('.af-cs-attr-final');

        var label = labelEl ? labelEl.textContent.trim() : '';
        var value = valueEl ? valueEl.textContent.trim() : '0';

        if (!label) return;

        html +=
          '<div class="af-cs-attr-compact__cell">' +
            '<div class="af-cs-attr-compact__label">' + escapeHtml(label) + '</div>' +
            '<div class="af-cs-attr-compact__value">' + escapeHtml(value) + '</div>' +
          '</div>';
      });

      compact.innerHTML = html;
    }

    function initAttributesUI() {
      var attrsRoot = sheet.querySelector('[data-afcs-attrs]');
      if (!attrsRoot) return;

      // Права: берём с сервера (data-afcs-can-edit="1|0")
      var canEdit = String(attrsRoot.getAttribute('data-afcs-can-edit') || '0') === '1';

      var gear = attrsRoot.querySelector('[data-afcs-attrs-toggle]');
      if (!gear) return;

      // Компактную сетку строим всегда (она нужна и гостям)
      buildCompactAttributes(attrsRoot);

      // если нельзя — гарантированно выключаем редактирование
      if (!canEdit) {
        attrsRoot.classList.remove('is-editing');
        gear.classList.remove('is-active');
        return;
      }

      // Состояние запоминаем локально (чтобы не бесило каждый раз)
      var key = 'afcs_attr_edit_' + String(sheetId || '');
      var saved = null;
      try { saved = localStorage.getItem(key); } catch (e) {}

      var isEditing = saved === '1';
      attrsRoot.classList.toggle('is-editing', isEditing);
      gear.classList.toggle('is-active', isEditing);

      // клики
      if (!gear.__afBound) {
        gear.__afBound = true;
        gear.addEventListener('click', function () {
          var now = !attrsRoot.classList.contains('is-editing');
          attrsRoot.classList.toggle('is-editing', now);
          gear.classList.toggle('is-active', now);

          try { localStorage.setItem(key, now ? '1' : '0'); } catch (e) {}

          // когда открыли редактирование — пересчитать пул (чтобы disabled/лимиты были верные)
          if (now) updatePool();
        });
      }
    }

    // безопасное экранирование для innerHTML
    function escapeHtml(s) {
      return String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    sheet.addEventListener('click', function (event) {
      var deleteButton = event.target.closest('[data-afcs-delete-sheet]');
      if (deleteButton) {
        event.preventDefault();
        if (!confirm('Удалить лист персонажа?')) {
          return;
        }
        var reason = prompt('Причина удаления (необязательно):', '') || '';
        var redirect = deleteButton.getAttribute('data-afcs-delete-redirect') || '';
        sendAction('delete_sheet', { reason: reason, redirect: redirect }).then(function (payload) {
          if (!payload.success) {
            alert((payload.error || payload.errors || 'Ошибка удаления').toString());
            return;
          }
          if (payload.redirect) {
            window.location.href = payload.redirect;
          } else {
            window.location.reload();
          }
        });
        return;
      }

      var saveAttrs = event.target.closest('[data-afcs-save-attributes]');
      if (saveAttrs) {
        var inputs = sheet.querySelectorAll('[data-afcs-attr-input]');
        var allocations = {};
        inputs.forEach(function (input) {
          allocations[input.getAttribute('data-afcs-attr-input')] = input.value || 0;
        });
        sendAction('save_attributes', { allocations: allocations }).then(function (payload) {
          if (!payload.success) {
            alert((payload.errors || payload.error || 'Ошибка сохранения').toString());
            return;
          }
          applyViewUpdate(payload);
        });
        return;
      }

      var choiceSave = event.target.closest('[data-afcs-choice-save]');
      if (choiceSave) {
        var choiceKey = choiceSave.getAttribute('data-afcs-choice-save');
        var select = sheet.querySelector('[data-afcs-choice-key="' + choiceKey + '"]');
        var choiceValue = select ? select.value : '';
        sendAction('save_choice', { choice_key: choiceKey, choice_value: choiceValue }).then(function (payload) {
          if (!payload.success) {
            alert((payload.error || 'Ошибка сохранения').toString());
            return;
          }
          applyViewUpdate(payload);
        });
        return;
      }

      var ledgerToggle = event.target.closest('[data-afcs-ledger-toggle]');
      if (ledgerToggle) {
        var ledger = sheet.querySelector('[data-afcs-ledger]');
        if (ledger) {
          ledger.hidden = !ledger.hidden;
        }
        return;
      }

      var awardToggle = event.target.closest('[data-afcs-award-toggle]');
      if (awardToggle) {
        var awardPanel = sheet.querySelector('[data-afcs-award-panel]');
        if (awardPanel) {
          awardPanel.hidden = !awardPanel.hidden;

          // FIX: если сервер отрендерил форму без data-атрибута — пометим её, чтобы submit-хендлер сработал
          var f = awardPanel.querySelector('form');
          if (f && !f.hasAttribute('data-afcs-award-form')) {
            f.setAttribute('data-afcs-award-form', '1');
          }
        }
        return;
      }

      var skillToggle = event.target.closest('[data-afcs-skill-toggle]');
      if (skillToggle) {
        var skillItem = skillToggle.closest('.af-cs-skill-item');
        if (skillItem) {
          skillItem.classList.toggle('is-controls-open');
        }
        return;
      }

      var skillChange = event.target.closest('[data-afcs-skill-change]');
      if (skillChange) {
        var slug = skillChange.getAttribute('data-slug');
        var delta = parseInt(skillChange.getAttribute('data-delta') || '0', 10);
        if (slug && (delta === 1 || delta === -1)) {
          sendAction('update_skill', { slug: slug, delta: delta }).then(function (payload) {
            if (!payload.success) {
              alert((payload.errors || payload.error || 'Ошибка сохранения').toString());
              return;
            }
            applyViewUpdate(payload);
          });
        }
        return;
      }

      var knowledgeAdd = event.target.closest('[data-afcs-knowledge-add]');
      if (knowledgeAdd) {
        var type = knowledgeAdd.getAttribute('data-afcs-knowledge-type');
        var select = sheet.querySelector('[data-afcs-knowledge-select="' + type + '"]');
        var key = select ? select.value : '';
        if (type && key) {
          sendAction('add_knowledge', { type: type, key: key }).then(function (payload) {
            if (!payload.success) {
              alert((payload.error || payload.errors || 'Ошибка сохранения').toString());
              return;
            }
            applyViewUpdate(payload);
          });
        }
        return;
      }

      var knowledgeRemove = event.target.closest('[data-afcs-knowledge-remove]');
      if (knowledgeRemove) {
        var typeRemove = knowledgeRemove.getAttribute('data-afcs-knowledge-type');
        var keyRemove = knowledgeRemove.getAttribute('data-afcs-knowledge-key');
        if (typeRemove && keyRemove) {
          sendAction('remove_knowledge', { type: typeRemove, key: keyRemove }).then(function (payload) {
            if (!payload.success) {
              alert((payload.error || payload.errors || 'Ошибка сохранения').toString());
              return;
            }
            applyViewUpdate(payload);
          });
        }
        return;
      }

    });

    sheet.addEventListener('input', function (event) {
      if (event.target.closest('[data-afcs-attr-input]')) {
        updatePool();
      }
    });

    sheet.addEventListener('submit', function (event) {
      var form = event.target.closest('[data-afcs-award-form]');
      if (!form) return;

      event.preventDefault();

      var amountEl =
        form.querySelector('input[name="amount"]') ||
        form.querySelector('input[name="exp"]') ||
        form.querySelector('input[data-afcs-award-amount]');

      var reasonEl =
        form.querySelector('input[name="reason"]') ||
        form.querySelector('textarea[name="reason"]') ||
        form.querySelector('textarea[name="comment"]') ||
        form.querySelector('[data-afcs-award-reason]');

      var payloadBase = {
        amount: amountEl ? amountEl.value : '',
        reason: reasonEl ? reasonEl.value : ''
      };

      // Фолбэк по именам do=... (на случай если бэк ждёт другое действие)
      var actions = ['grant_exp', 'manual_award', 'award_exp'];

      function tryNext(i) {
        if (i >= actions.length) {
          alert('Ошибка начисления: сервер не принял запрос (неизвестное действие).');
          return;
        }

        sendAction(actions[i], payloadBase).then(function (payload) {
          if (!payload || !payload.success) {
            // если это "не то действие" — пробуем следующее
            var msg = String((payload && (payload.error || payload.errors)) || '');
            if (/unknown|invalid|action|do|not\s+allowed|permission/i.test(msg)) {
              tryNext(i + 1);
              return;
            }
            alert((payload && (payload.error || payload.errors) ? (payload.error || payload.errors) : 'Ошибка начисления').toString());
            return;
          }

          if (amountEl) amountEl.value = '';
          if (reasonEl) reasonEl.value = '';
          applyViewUpdate(payload);
        }).catch(function () {
          tryNext(i + 1);
        });
      }

      tryNext(0);
    });


    updatePool();
    initAttributesUI();
  }

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeModal();
    }
  });
})();

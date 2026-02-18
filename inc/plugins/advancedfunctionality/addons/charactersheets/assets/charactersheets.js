(function () {
  'use strict';

  if (window.__afCharactersheetsInit) return;
  window.__afCharactersheetsInit = true;

  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
  }

  function ensureModal() {
    var modal = document.querySelector('[data-afcs-modal]');
    if (modal) {
      var frame = modal.querySelector('[data-afcs-frame]');
      if (frame) return { modal: modal, frame: frame };
    }

    var wrap = document.createElement('div');
    wrap.setAttribute('data-afcs-modal', '1');
    wrap.className = 'af-cs-modal';

    wrap.innerHTML =
      '<div class="af-cs-modal__backdrop" data-afcs-close="1"></div>' +
      '<div class="af-cs-modal__dialog" role="dialog" aria-modal="true">' +
        '<button type="button" class="af-cs-modal__close" data-afcs-close="1" aria-label="Закрыть">×</button>' +
        '<iframe class="af-cs-modal__frame" data-afcs-frame="1" src="" loading="lazy"></iframe>' +
      '</div>';

    document.body.appendChild(wrap);

    return {
      modal: wrap,
      frame: wrap.querySelector('[data-afcs-frame]')
    };
  }

  function closeModal() {
    var m = document.querySelector('[data-afcs-modal]');
    if (!m) return;

    m.classList.remove('is-open');

    var f = m.querySelector('[data-afcs-frame]');
    if (f) f.removeAttribute('src');
  }

  function openModal(url) {
    if (!url) return;

    var mf = ensureModal();
    var loadUrl = String(url);

    if (loadUrl.indexOf('ajax=1') === -1) {
      loadUrl += (loadUrl.indexOf('?') === -1 ? '?' : '&') + 'ajax=1';
    }

    mf.frame.setAttribute('src', loadUrl);
    mf.modal.classList.add('is-open');
  }

  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-afcs-close]')) {
      event.preventDefault();
      closeModal();
      return;
    }

    var opener = event.target.closest('[data-afcs-open="1"]');
    if (opener) {
      event.preventDefault();
      event.stopPropagation();
      if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();

      var url =
        opener.getAttribute('data-afcs-sheet') ||
        opener.getAttribute('href') ||
        '';

      if (!url) {
        var slug = opener.getAttribute('data-slug') || '';
        if (slug) {
          url = 'misc.php?action=af_charactersheet&slug=' + encodeURIComponent(slug);
        }
      }

      if (url) openModal(url);
      return;
    }

    var trigger = event.target.closest('[data-afcs-sheet]');
    if (trigger) {
      var tUrl = trigger.getAttribute('data-afcs-sheet');
      if (tUrl && String(tUrl).trim() !== '') {
        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
        openModal(tUrl);
      }
    }
  }, true);

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    closeModal();
    document.querySelectorAll('[data-afcs-attr-confirm-modal].is-open').forEach(function (node) {
      node.classList.remove('is-open');
    });
  });

  onReady(function () {
    function findTabsRoot(btn) {
      var root = btn.closest('[data-afcs-tabs]');
      if (root) return root;

      root = btn.closest('.af-cs-tabs');
      if (root) return root;

      root = btn.closest('[data-afcs-sheet]');
      if (root) return root;

      return document;
    }

    function activateTabInRoot(root, name) {
      if (!name) return;

      var buttons = root.querySelectorAll('[data-afcs-tab]');
      var panels = root.querySelectorAll('[data-afcs-tab-content]');

      if (!panels.length) {
        var wider = root.closest('.af-cs-tabs') || root;
        panels = wider.querySelectorAll('[data-afcs-tab-content]');
        buttons = wider.querySelectorAll('[data-afcs-tab]');
        root = wider;
      }

      buttons.forEach(function (b) {
        b.classList.toggle('is-active', b.getAttribute('data-afcs-tab') === name);
      });
      panels.forEach(function (p) {
        p.classList.toggle('is-active', p.getAttribute('data-afcs-tab-content') === name);
      });
    }

    document.addEventListener('click', function (event) {
      var tabBtn = event.target.closest('[data-afcs-tab]');
      if (!tabBtn) return;

      event.preventDefault();

      var name = tabBtn.getAttribute('data-afcs-tab');
      var root = findTabsRoot(tabBtn);
      activateTabInRoot(root, name);
    });

    (function initCatalog() {
      var catalog = document.querySelector('[data-afcs-catalog]');
      if (!catalog) return;

      var searchInput = document.querySelector('[data-afcs-search]');
      var filterRace = document.querySelector('[data-afcs-filter="race"]');
      var filterClass = document.querySelector('[data-afcs-filter="class"]');
      var filterTheme = document.querySelector('[data-afcs-filter="theme"]');
      var cards = Array.prototype.slice.call(catalog.querySelectorAll('[data-afcs-card]'));

      function normalize(value) { return (value || '').toLowerCase(); }

      function collectOptions(select, key) {
        var values = {};
        cards.forEach(function (card) {
          var val = (card.getAttribute('data-' + key) || '').trim();
          if (val) values[val] = true;
        });
        Object.keys(values).sort().forEach(function (val) {
          var option = document.createElement('option');
          option.value = val;
          option.textContent = val;
          select.appendChild(option);
        });
      }

      if (filterRace) collectOptions(filterRace, 'race');
      if (filterClass) collectOptions(filterClass, 'class');
      if (filterTheme) collectOptions(filterTheme, 'theme');

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
          if (query && name.indexOf(query) === -1) matches = false;
          if (race && cardRace !== race) matches = false;
          if (klass && cardClass !== klass) matches = false;
          if (theme && cardTheme !== theme) matches = false;

          card.style.display = matches ? '' : 'none';
        });
      }

      if (searchInput) searchInput.addEventListener('input', applyFilters);
      [filterRace, filterClass, filterTheme].forEach(function (select) {
        if (select) select.addEventListener('change', applyFilters);
      });
    })();

    (function initSheet() {
      var sheet = document.querySelector('[data-afcs-sheet]');
      if (!sheet) return;

      var sheetId = sheet.getAttribute('data-afcs-sheet-id');
      var postKey = sheet.getAttribute('data-afcs-post-key');

      function getInt(val, def) {
        var n = parseInt(String(val || ''), 10);
        return isNaN(n) ? (def || 0) : n;
      }

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

        var cleaned = stripTags(t);
        if (/af_assets_begin|<link|<script|<!doctype|<html/i.test(t)) {
          cleaned = cleaned || 'Сервер вернул HTML вместо JSON. Проверь инъекцию ассетов для ajax=1.';
        }
        if (cleaned.length > 320) cleaned = cleaned.slice(0, 320) + '…';
        return cleaned;
      }

      function extractJsonFromMixed(text) {
        var s = String(text || '');
        try { return JSON.parse(s); } catch (e) {}

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

          if (ch === '{') { depth++; continue; }
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

      function sendAction(action, payload) {
        if (!sheetId || !postKey) {
          alert('sheet_id/my_post_key missing in DOM');
          return Promise.reject(new Error('sheet_id/my_post_key missing in DOM'));
        }

        payload = payload || {};
        payload.do = action;
        payload.sheet_id = sheetId;
        payload.my_post_key = postKey;
        payload.ajax = 1;

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
          alert(error && error.message ? error.message : 'Ошибка запроса');
          console.error('[AF CS] Request failed:', error);
          throw error;
        });
      }

      function escapeHtml(s) {
        return String(s || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function buildCompactAttributes(attrsRoot) {
        var compact = attrsRoot.querySelector('[data-afcs-attr-compact]');
        if (!compact) return;

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

      // ====== NEW: инъекция степперов +/- для атрибутов ======
      function ensureAttrSteppers(attrsRoot) {
        if (!attrsRoot) return;

        var canEdit = String(attrsRoot.getAttribute('data-afcs-can-edit') || '0') === '1';
        var isEditing = attrsRoot.classList.contains('is-editing');

        // Плюсы/минусы показываем только когда можно редактировать и включен режим редактирования
        var shouldShow = canEdit && isEditing;

        var inputs = attrsRoot.querySelectorAll('input[data-afcs-attr-input]');
        inputs.forEach(function (input) {
          var key = input.getAttribute('data-afcs-attr-input');
          if (!key) return;

          // уже создано
          if (input.__afStepperMade) {
            // только переключаем видимость в зависимости от режима
            if (input.__afStepperEl) {
              input.__afStepperEl.hidden = !shouldShow;
            }
            // сам input всегда прячем, чтобы не было "стрелочек" как на скрине 2
            input.style.display = 'none';
            return;
          }

          // создаем UI
          var stepper = document.createElement('div');
          stepper.className = 'af-cs-attr-stepper';
          stepper.setAttribute('data-afcs-attr-stepper', key);
          stepper.hidden = !shouldShow;

          stepper.innerHTML =
            '<button type="button" class="af-cs-attr-stepper__btn is-minus" data-afcs-attr-change="1" data-key="' + escapeHtml(key) + '" data-delta="-1" aria-label="Уменьшить">−</button>' +
            '<div class="af-cs-attr-stepper__value" data-afcs-attr-alloc="' + escapeHtml(key) + '">0</div>' +
            '<button type="button" class="af-cs-attr-stepper__btn is-plus" data-afcs-attr-change="1" data-key="' + escapeHtml(key) + '" data-delta="1" aria-label="Увеличить">+</button>';

          // прячем инпут (чтобы не было стандартных стрелок)
          input.style.display = 'none';

          // вставляем сразу после input (в той же карточке)
          if (input.parentNode) {
            input.parentNode.insertBefore(stepper, input.nextSibling);
          }

          input.__afStepperMade = true;
          input.__afStepperEl = stepper;

          // первичная синхронизация
          var v = getInt(input.value, 0);
          var valEl = stepper.querySelector('[data-afcs-attr-alloc="' + key + '"]');
          if (valEl) valEl.textContent = String(v);
        });
      }

      function syncAttrRowUI(key) {
        var input = sheet.querySelector('[data-afcs-attr-input="' + key + '"]');
        if (!input) return;

        var v = getInt(input.value, 0);
        if (v < 0) v = 0;
        input.value = String(v);

        var alloc = sheet.querySelector('[data-afcs-attr-alloc="' + key + '"]');
        if (alloc) alloc.textContent = String(v);
      }

      function updatePool() {
        var poolContainer = sheet.querySelector('[data-afcs-pool-max]');
        if (!poolContainer) return;

        var poolMax = getInt(poolContainer.getAttribute('data-afcs-pool-max'), 0);

        var inputs = sheet.querySelectorAll('[data-afcs-attr-input]');
        var spent = 0;
        inputs.forEach(function (input) {
          spent += getInt(input.value, 0);
        });

        var remaining = poolMax - spent;

        var spentEl = sheet.querySelector('[data-afcs-pool-spent]');
        var remainingEl = sheet.querySelector('[data-afcs-pool-remaining]');
        if (spentEl) spentEl.textContent = String(spent);
        if (remainingEl) remainingEl.textContent = String(remaining);

        var warning = sheet.querySelector('[data-afcs-pool-warning]');
        if (warning) warning.hidden = remaining >= 0;

        var saveButton = sheet.querySelector('[data-afcs-save-attributes]');
        if (saveButton) saveButton.disabled = remaining < 0;

        // Блокируем/разблокируем +/− у атрибутов
        var plusBtns = sheet.querySelectorAll('[data-afcs-attr-change][data-delta="1"]');
        plusBtns.forEach(function (btn) {
          btn.disabled = remaining <= 0;
        });

        var minusBtns = sheet.querySelectorAll('[data-afcs-attr-change][data-delta="-1"]');
        minusBtns.forEach(function (btn) {
          var key = btn.getAttribute('data-key') || '';
          if (!key) return;
          var input = sheet.querySelector('[data-afcs-attr-input="' + key + '"]');
          var v = input ? getInt(input.value, 0) : 0;
          btn.disabled = v <= 0;
        });
      }

      function initAttributesUI() {
        var attrsRoot = sheet.querySelector('[data-afcs-attrs]');
        if (!attrsRoot) return;

        var canEdit = String(attrsRoot.getAttribute('data-afcs-can-edit') || '0') === '1';

        var gear = attrsRoot.querySelector('[data-afcs-attrs-toggle]');
        if (!gear) {
          buildCompactAttributes(attrsRoot);
          return;
        }

        buildCompactAttributes(attrsRoot);

        if (!canEdit) {
          attrsRoot.classList.remove('is-editing');
          gear.classList.remove('is-active');
          // даже в read-only режиме прячем стандартные инпуты (если они вдруг попали)
          ensureAttrSteppers(attrsRoot);
          updatePool();
          return;
        }

        var key = 'afcs_attr_edit_' + String(sheetId || '');
        var saved = null;
        try { saved = localStorage.getItem(key); } catch (e) {}

        var isEditing = saved === '1';
        attrsRoot.classList.toggle('is-editing', isEditing);
        gear.classList.toggle('is-active', isEditing);

        // NEW: в режиме редактирования инжектим +/- и прячем number input
        ensureAttrSteppers(attrsRoot);

        // Синк визуальных alloc из скрытых input
        var allInputs = sheet.querySelectorAll('[data-afcs-attr-input]');
        allInputs.forEach(function (inp) {
          var k = inp.getAttribute('data-afcs-attr-input');
          if (k) syncAttrRowUI(k);
        });

        if (!gear.__afBound) {
          gear.__afBound = true;
          gear.addEventListener('click', function (e) {
            e.preventDefault();
            var now = !attrsRoot.classList.contains('is-editing');
            attrsRoot.classList.toggle('is-editing', now);
            gear.classList.toggle('is-active', now);

            try { localStorage.setItem(key, now ? '1' : '0'); } catch (e2) {}

            // NEW: показать/скрыть степперы
            ensureAttrSteppers(attrsRoot);

            if (now) updatePool();
          });
        }
      }

      function applyViewUpdate(payload) {
        if (payload.attributes_html) {
          var block = sheet.querySelector('[data-afcs-block="attributes"]');
          if (block) block.innerHTML = payload.attributes_html;
        }
        if (payload.skills_html) {
          var skillsBlock = sheet.querySelector('[data-afcs-block="skills"]');
          if (skillsBlock) skillsBlock.innerHTML = payload.skills_html;
        }
        if (payload.knowledge_html) {
          var knowledgeBlock = sheet.querySelector('[data-afcs-block="knowledge"]');
          if (knowledgeBlock) knowledgeBlock.innerHTML = payload.knowledge_html;
        }
        if (payload.progress_html) {
          var blockProgress = sheet.querySelector('[data-afcs-block="progress"]');
          if (blockProgress) blockProgress.innerHTML = payload.progress_html;
        }
        if (payload.abilities_html) {
          var abilitiesBlock = sheet.querySelector('[data-afcs-block="abilities"]');
          if (abilitiesBlock) abilitiesBlock.innerHTML = payload.abilities_html;
        }
        if (payload.inventory_html) {
          var inventoryBlock = sheet.querySelector('[data-afcs-block="inventory"]');
          if (inventoryBlock) inventoryBlock.innerHTML = payload.inventory_html;
        }
        if (payload.augmentations_html) {
          var augmentsBlock = sheet.querySelector('[data-afcs-block="augmentations"]');
          if (augmentsBlock) augmentsBlock.innerHTML = payload.augmentations_html;
        }
        if (payload.equipment_html) {
          var equipmentBlock = sheet.querySelector('[data-afcs-block="equipment"]');
          if (equipmentBlock) equipmentBlock.innerHTML = payload.equipment_html;
        }
        if (payload.mechanics_html) {
          var mechanicsBlock = sheet.querySelector('[data-afcs-block="mechanics"]');
          if (mechanicsBlock) mechanicsBlock.innerHTML = payload.mechanics_html;
        }
        if (payload.view) {
          var levelValue = sheet.querySelector('[data-afcs-level-value]');
          var levelExp = sheet.querySelector('[data-afcs-level-exp]');
          var levelBar = sheet.querySelector('[data-afcs-level-bar]');
          if (levelValue) levelValue.textContent = payload.view.level;
          if (levelExp) levelExp.textContent = payload.view.level_exp_label;
          if (levelBar) levelBar.style.width = payload.view.level_percent + '%';
        }

        updatePool();
        initAttributesUI();
        initInventoryUI(sheet);
      }

      function setActiveInventoryTab(root, key) {
        if (!root || !key) return;
        var tabs = root.querySelectorAll('[data-afcs-inventory-tab]');
        var panels = root.querySelectorAll('[data-afcs-inventory-panel]');
        tabs.forEach(function (tab) {
          tab.classList.toggle('is-active', tab.getAttribute('data-afcs-inventory-tab') === key);
        });
        panels.forEach(function (panel) {
          panel.classList.toggle('is-active', panel.getAttribute('data-afcs-inventory-panel') === key);
        });
      }

      function updateInventoryInfo(panel, card) {
        if (!panel || !card) return;
        var info = panel.querySelector('[data-afcs-inventory-info]');
        if (!info) return;

        var title = card.getAttribute('data-afcs-item-title') || '';
        var desc = card.getAttribute('data-afcs-item-desc') || '';
        var qty = card.getAttribute('data-afcs-item-qty') || '0';
        var effects = card.getAttribute('data-afcs-item-effects') || '';
        var action = card.getAttribute('data-afcs-item-action') || '';

        var html = '<div class="af-cs-inventory-title">' + title + '</div>';
        if (desc) html += '<div class="af-cs-inventory-desc">' + desc + '</div>';
        html += '<div class="af-cs-inventory-row"><span>Количество</span><strong>' + qty + '</strong></div>';
        if (effects) html += '<div class="af-cs-inventory-bonus">' + effects + '</div>';
        if (action) html += '<div class="af-cs-ability-actions">' + action + '</div>';

        info.innerHTML = html;

        var cards = panel.querySelectorAll('[data-afcs-item-card]');
        cards.forEach(function (item) {
          item.classList.toggle('is-active', item === card);
        });
      }

      function closeAttributeConfirmModal() {
        var modal = sheet.querySelector('[data-afcs-attr-confirm-modal]');
        if (!modal) return;
        modal.classList.remove('is-open');
        var errorNode = modal.querySelector('[data-afcs-attr-confirm-error]');
        if (errorNode) {
          errorNode.textContent = '';
          errorNode.hidden = true;
        }
      }

      function ensureAttributeConfirmModal() {
        var modal = sheet.querySelector('[data-afcs-attr-confirm-modal]');
        if (modal) return modal;

        modal = document.createElement('div');
        modal.className = 'af-cs-confirm-modal';
        modal.setAttribute('data-afcs-attr-confirm-modal', '1');
        modal.innerHTML =
          '<div class="af-cs-confirm-modal__overlay" data-afcs-attr-confirm-close="1"></div>' +
          '<div class="af-cs-confirm-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="af-cs-confirm-title">' +
            '<h3 class="af-cs-confirm-modal__title" id="af-cs-confirm-title">Подтверждение</h3>' +
            '<div class="af-cs-confirm-modal__body">Убедитесь, что вы распределили всё правильно. После сохранения перераспределение будет доступно только на платной основе.</div>' +
            '<div class="af-cs-confirm-modal__error" data-afcs-attr-confirm-error="1" hidden></div>' +
            '<div class="af-cs-confirm-modal__actions">' +
              '<button type="button" class="af-cs-btn" data-afcs-attr-confirm-save="1">Сохранить</button>' +
              '<button type="button" class="af-cs-btn af-cs-btn--ghost" data-afcs-attr-confirm-close="1">Продолжить редактировать</button>' +
            '</div>' +
          '</div>';

        sheet.appendChild(modal);
        return modal;
      }

      function closeAttributesEditorAfterSave() {
        var attrsRoot = sheet.querySelector('[data-afcs-attrs]');
        if (!attrsRoot) return;

        attrsRoot.classList.remove('is-editing');
        var gear = attrsRoot.querySelector('[data-afcs-attrs-toggle]');
        if (gear) gear.classList.remove('is-active');

        var key = 'afcs_attr_edit_' + String(sheetId || '');
        try { localStorage.setItem(key, '0'); } catch (e) {}

        ensureAttrSteppers(attrsRoot);
      }

      function submitAttributesSave() {
        var modal = ensureAttributeConfirmModal();
        var errorNode = modal.querySelector('[data-afcs-attr-confirm-error]');
        if (errorNode) {
          errorNode.textContent = '';
          errorNode.hidden = true;
        }

        var inputs2 = sheet.querySelectorAll('[data-afcs-attr-input]');
        var allocations = {};
        inputs2.forEach(function (input2) {
          allocations[input2.getAttribute('data-afcs-attr-input')] = input2.value || 0;
        });

        sendAction('save_attributes', { allocations: allocations }).then(function (payload) {
          if (!payload.success) {
            if (errorNode) {
              errorNode.textContent = (payload.errors || payload.error || 'Ошибка сохранения').toString();
              errorNode.hidden = false;
            }
            return;
          }
          closeAttributeConfirmModal();
          applyViewUpdate(payload);
          closeAttributesEditorAfterSave();
        });
      }

      function initInventoryUI(root) {
        if (!root) return;
        var inventory = root.querySelector('[data-afcs-block="inventory"]') || root;
        if (!inventory) return;
        var activeTab = inventory.querySelector('.af-cs-tab-btn.is-active');
        if (!activeTab) {
          activeTab = inventory.querySelector('[data-afcs-inventory-tab]');
          if (activeTab) activeTab.classList.add('is-active');
        }
        if (activeTab) {
          setActiveInventoryTab(inventory, activeTab.getAttribute('data-afcs-inventory-tab'));
        }
      }


      sheet.addEventListener('change', function (event) {
        var rankSelect = event.target.closest('[data-afcs-skill-rank]');
        if (!rankSelect) return;

        var skillKey = rankSelect.getAttribute('data-skill-key');
        var skill_rank = parseInt(rankSelect.value || '0', 10);
        if (!skillKey || Number.isNaN(skill_rank)) return;

        sendAction('cs_skill_set_rank', { skill_key: skillKey, skill_rank: skill_rank }).then(function (payload) {
          if (!payload.success) {
            alert((payload.errors || payload.error || 'Ошибка сохранения').toString());
            return;
          }
          applyViewUpdate(payload);
        });
      });

      sheet.addEventListener('click', function (event) {
        var inventoryTab = event.target.closest('[data-afcs-inventory-tab]');
        if (inventoryTab) {
          event.preventDefault();
          var tabKey = inventoryTab.getAttribute('data-afcs-inventory-tab');
          setActiveInventoryTab(sheet, tabKey);
          return;
        }

        var inventoryCard = event.target.closest('[data-afcs-item-card]');
        if (inventoryCard) {
          event.preventDefault();
          var panel = inventoryCard.closest('[data-afcs-inventory-panel]');
          updateInventoryInfo(panel, inventoryCard);
          return;
        }

        // +/− атрибутов (как у навыков)
        var attrChange = event.target.closest('[data-afcs-attr-change]');
        if (attrChange) {
          event.preventDefault();

          var key = attrChange.getAttribute('data-key') || '';
          var delta = getInt(attrChange.getAttribute('data-delta'), 0);
          if (!key || (delta !== 1 && delta !== -1)) return;

          var input = sheet.querySelector('[data-afcs-attr-input="' + key + '"]');
          if (!input) return;

          var poolContainer = sheet.querySelector('[data-afcs-pool-max]');
          var poolMax = poolContainer ? getInt(poolContainer.getAttribute('data-afcs-pool-max'), 0) : 0;

          var inputs = sheet.querySelectorAll('[data-afcs-attr-input]');
          var spent = 0;
          inputs.forEach(function (i) { spent += getInt(i.value, 0); });

          var remaining = poolMax - spent;

          var cur = getInt(input.value, 0);
          if (cur < 0) cur = 0;

          if (delta === 1) {
            if (remaining <= 0) return;
            cur += 1;
          } else {
            if (cur <= 0) return;
            cur -= 1;
          }

          input.value = String(cur);
          syncAttrRowUI(key);
          updatePool();
          return;
        }

        var deleteButton = event.target.closest('[data-afcs-delete-sheet]');
        if (deleteButton) {
          event.preventDefault();
          if (!confirm('Удалить лист персонажа?')) return;

          var reason = prompt('Причина удаления (необязательно):', '') || '';
          var redirect = deleteButton.getAttribute('data-afcs-delete-redirect') || '';

          sendAction('delete_sheet', { reason: reason, redirect: redirect }).then(function (payload) {
            if (!payload.success) {
              alert((payload.error || payload.errors || 'Ошибка удаления').toString());
              return;
            }
            if (payload.redirect) window.location.href = payload.redirect;
            else window.location.reload();
          });
          return;
        }

        var ledgerToggle = event.target.closest('[data-afcs-ledger-toggle]');
        if (ledgerToggle) {
          event.preventDefault();
          var ledger = sheet.querySelector('[data-afcs-ledger]');
          if (ledger) ledger.hidden = !ledger.hidden;
          return;
        }

        var awardToggle = event.target.closest('[data-afcs-award-toggle]');
        if (awardToggle) {
          event.preventDefault();
          var awardPanel = sheet.querySelector('[data-afcs-award-panel]');
          if (awardPanel) {
            awardPanel.hidden = !awardPanel.hidden;
            var f = awardPanel.querySelector('form');
            if (f && !f.hasAttribute('data-afcs-award-form')) {
              f.setAttribute('data-afcs-award-form', '1');
            }
          }
          return;
        }

        var saveAttrs = event.target.closest('[data-afcs-save-attributes]');
        if (saveAttrs) {
          event.preventDefault();
          var modal = ensureAttributeConfirmModal();
          modal.classList.add('is-open');
          return;
        }

        var attrConfirmClose = event.target.closest('[data-afcs-attr-confirm-close]');
        if (attrConfirmClose) {
          event.preventDefault();
          closeAttributeConfirmModal();
          return;
        }

        var attrConfirmSave = event.target.closest('[data-afcs-attr-confirm-save]');
        if (attrConfirmSave) {
          event.preventDefault();
          submitAttributesSave();
          return;
        }

        var resetAttrs = event.target.closest('[data-afcs-reset-attributes]');
        if (resetAttrs) {
          event.preventDefault();
          if (!window.confirm('Сбросить атрибуты и открыть распределение заново?')) return;
          sendAction('reset_attributes', {}).then(function (payload) {
            if (!payload.success) {
              alert((payload.error || 'Ошибка сброса').toString());
              return;
            }
            applyViewUpdate(payload);
          });
          return;
        }

        var resetSkills = event.target.closest('[data-afcs-reset-skills]');
        if (resetSkills) {
          event.preventDefault();
          if (!window.confirm('Сбросить навыки персонажа?')) return;
          sendAction('reset_skills', {}).then(function (payload) {
            if (!payload.success) {
              alert((payload.error || 'Ошибка сброса').toString());
              return;
            }
            applyViewUpdate(payload);
          });
          return;
        }

        var choiceSave = event.target.closest('[data-afcs-choice-save]');
        if (choiceSave) {
          event.preventDefault();
          var choiceKey = choiceSave.getAttribute('data-afcs-choice-save');
          var selects = sheet.querySelectorAll('[data-afcs-choice-key="' + choiceKey + '"]');
          var values = [];
          if (selects && selects.length) {
            selects.forEach(function (node) {
              if (node && node.value) values.push(node.value);
            });
          }
          var choiceValue = values.length > 1 ? values.join(',') : (values[0] || '');

          sendAction('save_choice', { choice_key: choiceKey, choice_value: choiceValue }).then(function (payload) {
            if (!payload.success) {
              alert((payload.error || 'Ошибка сохранения').toString());
              return;
            }
            applyViewUpdate(payload);
          });
          return;
        }

        var skillCatalogOpen = event.target.closest('[data-afcs-skill-catalog-open]');
        if (skillCatalogOpen) {
          event.preventDefault();
          var firstBuy = sheet.querySelector('[data-afcs-skill-buy]');
          if (firstBuy && typeof firstBuy.focus === 'function') firstBuy.focus();
          return;
        }

        var skillBuy = event.target.closest('[data-afcs-skill-buy]');
        if (skillBuy) {
          event.preventDefault();
          var buyKey = skillBuy.getAttribute('data-skill-key');
          if (buyKey) {
            sendAction('buy_skill', { skill_key: buyKey }).then(function (payload) {
              if (!payload.success) {
                alert((payload.errors || payload.error || 'Ошибка сохранения').toString());
                return;
              }
              applyViewUpdate(payload);
            });
          }
          return;
        }

        var skillUnbuy = event.target.closest('[data-afcs-skill-unbuy]');
        if (skillUnbuy) {
          event.preventDefault();
          var unbuyKey = skillUnbuy.getAttribute('data-skill-key');
          if (unbuyKey) {
            sendAction('cs_skill_unbuy', { skill_key: unbuyKey }).then(function (payload) {
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
          event.preventDefault();
          var type = knowledgeAdd.getAttribute('data-afcs-knowledge-type');
          var selectK = sheet.querySelector('[data-afcs-knowledge-select="' + type + '"]');
          var keyK = selectK ? selectK.value : '';
          if (type && keyK) {
            sendAction('add_knowledge', { type: type, key: keyK }).then(function (payload) {
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
          event.preventDefault();
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

        var abilityToggle = event.target.closest('[data-afcs-ability-toggle]');
        if (abilityToggle) {
          event.preventDefault();
          var abilityType = abilityToggle.getAttribute('data-afcs-ability-type');
          var abilityKey = abilityToggle.getAttribute('data-afcs-ability-key');
          var abilityEquipped = abilityToggle.getAttribute('data-afcs-ability-equipped');
          if (abilityType && abilityKey) {
            sendAction('toggle_ability', {
              type: abilityType,
              key: abilityKey,
              equipped: abilityEquipped === '1' ? 1 : 0
            }).then(function (payload) {
              if (!payload.success) {
                alert((payload.error || payload.errors || 'Ошибка сохранения').toString());
                return;
              }
              applyViewUpdate(payload);
            });
          }
          return;
        }

        var inventoryToggle = event.target.closest('[data-afcs-inventory-toggle]');
        if (inventoryToggle) {
          event.preventDefault();
          var itemType = inventoryToggle.getAttribute('data-afcs-item-type');
          var itemKey = inventoryToggle.getAttribute('data-afcs-item-key');
          var itemEquipped = inventoryToggle.getAttribute('data-afcs-item-equipped');
          if (itemType && itemKey) {
            sendAction('inventory_toggle_item', {
              type: itemType,
              key: itemKey,
              equipped: itemEquipped === '1' ? 1 : 0
            }).then(function (payload) {
              if (!payload.success) {
                alert((payload.error || payload.errors || 'Ошибка сохранения').toString());
                return;
              }
              applyViewUpdate(payload);
            });
          }
          return;
        }

        var augEquip = event.target.closest('[data-afcs-augmentation-equip]');
        if (augEquip) {
          event.preventDefault();
          var augType = augEquip.getAttribute('data-afcs-augmentation-type');
          var augKey = augEquip.getAttribute('data-afcs-augmentation-key');
          var augDefaultSlot = augEquip.getAttribute('data-afcs-augmentation-slot-default') || '';
          var augSelect = augEquip.closest('.af-cs-augment-card') || augEquip.parentElement;
          var augSlotSelect = augSelect ? augSelect.querySelector('[data-afcs-augmentation-slot-select]') : null;
          if (augSlotSelect && !augSlotSelect.value && augSlotSelect.options && augSlotSelect.options.length === 2) {
            augSlotSelect.value = augSlotSelect.options[1].value;
          }
          var augSlot = augSlotSelect ? augSlotSelect.value : augDefaultSlot;
          if (augType && augKey && augSlot) {
            sendAction('equip_augmentation', {
              slot: augSlot,
              type: augType,
              key: augKey
            }).then(function (payload) {
              if (!payload.success) {
                alert((payload.error || payload.errors || 'Ошибка сохранения').toString());
                return;
              }
              applyViewUpdate(payload);
            });
          }
          return;
        }

        var augUnequip = event.target.closest('[data-afcs-augmentation-unequip]');
        if (augUnequip) {
          event.preventDefault();
          var augSlotKey = augUnequip.getAttribute('data-afcs-augmentation-slot');
          var augKey = augUnequip.getAttribute('data-afcs-augmentation-key');
          if (augSlotKey) {
            sendAction('unequip_augmentation', { slot: augSlotKey, key: augKey || '' }).then(function (payload) {
              if (!payload.success) {
                alert((payload.error || payload.errors || 'Ошибка сохранения').toString());
                return;
              }
              applyViewUpdate(payload);
            });
          }
          return;
        }

        var equipEquip = event.target.closest('[data-afcs-equipment-equip]');
        if (equipEquip) {
          event.preventDefault();
          var eqType = equipEquip.getAttribute('data-afcs-equipment-type');
          var eqKey = equipEquip.getAttribute('data-afcs-equipment-key');
          var eqSelectWrap = equipEquip.closest('.af-cs-augment-card') || equipEquip.parentElement;
          var eqSlotSelect = eqSelectWrap ? eqSelectWrap.querySelector('[data-afcs-equipment-slot-select]') : null;
          if (eqSlotSelect && !eqSlotSelect.value && eqSlotSelect.options && eqSlotSelect.options.length === 2) {
            eqSlotSelect.value = eqSlotSelect.options[1].value;
          }
          var eqSlot = eqSlotSelect ? eqSlotSelect.value : '';
          if (eqType && eqKey && eqSlot) {
            sendAction('equip_equipment', {
              slot: eqSlot,
              type: eqType,
              key: eqKey
            }).then(function (payload) {
              if (!payload.success) {
                alert((payload.error || payload.errors || 'Ошибка сохранения').toString());
                return;
              }
              applyViewUpdate(payload);
            });
          }
          return;
        }

        var equipUnequip = event.target.closest('[data-afcs-equipment-unequip]');
        if (equipUnequip) {
          event.preventDefault();
          var eqSlotKey = equipUnequip.getAttribute('data-afcs-equipment-slot');
          if (eqSlotKey) {
            sendAction('unequip_equipment', { slot: eqSlotKey }).then(function (payload) {
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
        // на всякий случай: если где-то остались видимые инпуты
        if (event.target && event.target.closest('[data-afcs-attr-input]')) {
          updatePool();
        }
      });

      sheet.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || String(form.nodeName).toUpperCase() !== 'FORM') return;
        if (!form.closest('[data-afcs-award-panel]')) return;

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

        sendAction('grant_exp', payloadBase).then(function (payload) {
          if (!payload || !payload.success) {
            alert((payload && (payload.error || payload.errors) ? (payload.error || payload.errors) : 'Ошибка начисления').toString());
            return;
          }

          if (amountEl) amountEl.value = '';
          if (reasonEl) reasonEl.value = '';
          applyViewUpdate(payload);
        });
      });

      sheet.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        var modal = sheet.querySelector('[data-afcs-attr-confirm-modal]');
        if (!modal || !modal.classList.contains('is-open')) return;
        event.preventDefault();
        closeAttributeConfirmModal();
      });

      updatePool();
      initAttributesUI();
      initInventoryUI(sheet);
    })();
  });
})();

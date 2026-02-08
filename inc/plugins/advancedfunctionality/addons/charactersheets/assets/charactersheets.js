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

      return fetch('misc.php?action=af_charactersheet_api', {
        method: 'POST',
        credentials: 'same-origin',
        body: data
      }).then(function (resp) {
        if (!resp.ok) {
          return resp.text().then(function (text) {
            var message = text || ('HTTP ' + resp.status);
            throw new Error(message);
          });
        }
        return resp.text().then(function (text) {
          try {
            return JSON.parse(text);
          } catch (error) {
            var message = text || 'Некорректный ответ сервера';
            throw new Error(message);
          }
        });
      }).catch(function (error) {
        alert(error.message || 'Ошибка запроса');
        console.error(error);
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

    sheet.addEventListener('click', function (event) {
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
        }
      }
    });

    sheet.addEventListener('input', function (event) {
      if (event.target.closest('[data-afcs-attr-input]')) {
        updatePool();
      }
    });

    sheet.addEventListener('submit', function (event) {
      var form = event.target.closest('[data-afcs-award-form]');
      if (!form) {
        return;
      }
      event.preventDefault();
      var amount = form.querySelector('input[name="amount"]');
      var reason = form.querySelector('input[name="reason"]');
      sendAction('grant_exp', {
        amount: amount ? amount.value : '',
        reason: reason ? reason.value : ''
      }).then(function (payload) {
        if (!payload.success) {
          alert((payload.error || 'Ошибка начисления').toString());
          return;
        }
        if (amount) {
          amount.value = '';
        }
        if (reason) {
          reason.value = '';
        }
        applyViewUpdate(payload);
      });
    });

    updatePool();
  }

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeModal();
    }
  });
})();

/* AdvancedThreadFields (AF_ATF) */
(function () {
  "use strict";

  const AF_ATF = {
    debounce(fn, wait) {
      let t = null;
      return function (...args) {
        clearTimeout(t);
        t = setTimeout(() => fn.apply(this, args), wait);
      };
    },

    qs(sel, root) {
      return (root || document).querySelector(sel);
    },

    qsa(sel, root) {
      return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    },

    escapeAttr(s) {
      return String(s)
        .replace(/&/g, "&amp;")
        .replace(/"/g, "&quot;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;");
    },

    getKbEndpoint() {
      const meta = document.querySelector('meta[name="af-atf-kb-endpoint"]');
      if (meta && meta.content) return meta.content;
      return "/misc.php?action=af_kb_get";
    },

    // Fallback for browsers without :has() and custom message wrappers.
    applyHideEditor() {
      const textarea = document.querySelector('textarea[data-af-atf-message="1"]');
      if (!textarea) return;

      const field = textarea.closest(".atf-compose__field--editor") || textarea.closest("tr");
      if (field) field.style.setProperty("display", "none", "important");
    },

    initEditSubmitFeedback() {
      const marker = AF_ATF.qs('input[name="af_atf_form_present"]');
      const form = AF_ATF.qs('form#editpost') || marker?.form;
      if (!marker || !form || !AF_ATF.qs('input[name="action"][value="do_editpost"]', form)) return;
      const button = AF_ATF.qs('input[type="submit"][name="submitbutton"]', form);
      const notice = document.createElement("p");
      notice.className = "af-atf-submit-notice";
      notice.setAttribute("role", "alert");
      notice.hidden = true;
      if (button) button.before(notice);
      else form.prepend(notice);

      function show(state, message = "") {
        form.dataset.afAtfSubmitState = state;
        notice.hidden = !message;
        notice.textContent = message;
      }

      const controls = AF_ATF.qsa('[name^="af_tf["]');
      const ownsFields = () => marker.form === form && controls.length > 0
        && controls.every((control) => control.form === form);
      if (!ownsFields() || (button && button.form !== form)) {
        show("form-ownership-error", "Форма редактирования не содержит всех полей анкеты. Обновите страницу.");
      }
      document.addEventListener("click", (event) => {
        const target = event.target;
        if (target !== button && !(target instanceof HTMLInputElement && target.name === "submitbutton"
            && (target.form === form || !target.form))) return;
        if (target.form !== form || !ownsFields()) {
          event.preventDefault();
          show("form-ownership-error", "Форма редактирования не содержит всех полей анкеты. Обновите страницу.");
          return;
        }
        // A script can cancel the click before the browser validates or emits submit.
        setTimeout(() => {
          if (event.defaultPrevented && form.dataset.afAtfSubmitState === "submit-clicked") {
            show("js-submit-blocked", "Скрипт страницы остановил отправку анкеты. Повторите попытку.");
          }
        }, 0);
        show("submit-clicked");
      }, true);

      let firstInvalid = null;
      form.addEventListener("invalid", (event) => {
        if (firstInvalid) return;
        firstInvalid = event.target;
        const control = firstInvalid;
        const label = control.labels?.[0]?.textContent.trim()
          || control.closest(".af-atf-form-field")?.querySelector(".af-atf-field-label")?.textContent.trim();
        show("html-validation-blocked", (label ? label + ". " : "") + (control.validationMessage || "Проверьте значение поля."));
        control.classList.add("af-atf-submit-invalid");
        control.addEventListener("input", () => control.classList.remove("af-atf-submit-invalid"), { once: true });
        setTimeout(() => {
          firstInvalid = null;
          if (!control.getClientRects().length) return;
          control.scrollIntoView({ block: "center" });
          control.focus({ preventScroll: true });
        }, 0);
      }, true);

      form.addEventListener("submit", (event) => {
        if (event.target !== form) return;
        if (!ownsFields()) {
          event.preventDefault();
          show("form-ownership-error", "Форма редактирования не содержит всех полей анкеты. Обновите страницу.");
          return;
        }
        show("submit-event");
        setTimeout(() => {
          if (event.defaultPrevented) show("js-submit-blocked", "Скрипт страницы остановил отправку анкеты. Повторите попытку.");
          else show("submit-accepted");
        }, 0);
      }, true);
    },

    // Existing numeric values may predate the editor's constraints. Restore
    // those constraints as soon as this specific control is edited.
    allowStoredAbilityNumbers(row) {
      AF_ATF.qsa('input[type="number"]', row).forEach((input) => {
        const validity = input.validity;
        if (!validity.rangeUnderflow && !validity.rangeOverflow && !validity.stepMismatch) return;
        const attributes = ["min", "max", "step"].map((name) => [name, input.getAttribute(name)]);
        input.removeAttribute("min");
        input.removeAttribute("max");
        input.step = "any";
        input.addEventListener("input", () => {
          attributes.forEach(([name, value]) => {
            if (value === null) input.removeAttribute(name);
            else input.setAttribute(name, value);
          });
        }, { once: true });
      });
    },

    // --- 2) userchips init ---
    initUserChipsAll() {
      const blocks = AF_ATF.qsa(".af-atf-userchips");
      if (!blocks.length) return;

      blocks.forEach((wrap) => AF_ATF.initUserChipsOne(wrap));
    },

    initKbSelects() {
      const blocks = AF_ATF.qsa(".af-atf-kb-select");
      if (!blocks.length) return;

      blocks.forEach((wrap) => {
        const select = AF_ATF.qs(".af-atf-kb-select-input", wrap);
        const preview = AF_ATF.qs(".af-atf-kb-preview", wrap);
        if (!select || !preview) return;

        const kbType = wrap.getAttribute("data-kb-type") || "";

        function renderPreview() {
          const key = String(select.value || "").trim();
          if (!key) {
            preview.innerHTML = "";
            return;
          }
          const label = select.options[select.selectedIndex]
            ? select.options[select.selectedIndex].textContent
            : key;
          const chip = document.createElement("span");
          chip.className = "af_kb_chip af-kb-chip";
          chip.setAttribute("data-kb-type", kbType);
          chip.setAttribute("data-kb-key", key);
          chip.textContent = label;
          preview.innerHTML = "";
          preview.appendChild(chip);
        }

        select.addEventListener("change", renderPreview);
        renderPreview();
      });
    },

    initKbCatalogCtaModal() {
      if (AF_ATF.__kbCatalogCtaInited) return;
      AF_ATF.__kbCatalogCtaInited = true;

      function normalizeTarget(target) {
        if (!target) return null;
        if (target.nodeType === 3) {
          return target.parentElement || null;
        }
        return target instanceof Element ? target : null;
      }

      function decorateOpener(opener) {
        if (!opener || opener.__afAtfKbCatalogDecorated) return;

        const href = String(opener.getAttribute("href") || "").trim();
        if (!href || href === "#" || href.toLowerCase().indexOf("javascript:") === 0) {
          return;
        }

        opener.setAttribute("target", "_blank");
        opener.setAttribute("rel", "noopener noreferrer");
        opener.__afAtfKbCatalogDecorated = true;
      }

      function decorateAll(root) {
        AF_ATF.qsa("[data-af-atf-kb-catalog-cta]", root || document).forEach((opener) => {
          decorateOpener(opener);
        });
      }

      // Первичный проход по уже отрисованным CTA
      decorateAll(document);

      // Страховка: если кнопка появилась позже/перерисовалась
      document.addEventListener("click", (event) => {
        const target = normalizeTarget(event.target);
        if (!target) return;

        const opener = target.closest("[data-af-atf-kb-catalog-cta]");
        if (!opener) return;

        decorateOpener(opener);
        // НИЧЕГО НЕ БЛОКИРУЕМ:
        // пусть ссылка открывается нативно в новой вкладке
      }, true);
    },

    initPointBuyAll() {
      const blocks = AF_ATF.qsa(".af-atf-pointbuy");
      if (!blocks.length) return;
      blocks.forEach((wrap) => AF_ATF.initPointBuyOne(wrap));
    },

    initPointBuyOne(wrap) {
      if (!wrap || wrap.__af_atf_pointbuy) return;
      wrap.__af_atf_pointbuy = true;

      const hidden = AF_ATF.qs(".af-atf-pointbuy-hidden", wrap);
      const inputs = AF_ATF.qsa(".af-atf-pointbuy-input", wrap);
      if (!hidden || !inputs.length) return;

      const total = parseInt(wrap.getAttribute("data-total") || "0", 10);
      const min = parseInt(wrap.getAttribute("data-min") || "0", 10);
      const max = parseInt(wrap.getAttribute("data-max") || "0", 10);
      const base = parseInt(wrap.getAttribute("data-base") || "0", 10);
      const allowNegative = wrap.getAttribute("data-allow-negative") === "1";
      const requireExact = wrap.getAttribute("data-require-exact") === "1";
      const errOver = wrap.getAttribute("data-err-overbudget") || "Over budget";
      const errRange = wrap.getAttribute("data-err-out-of-range") || "Out of range";
      const errExact = wrap.getAttribute("data-err-not-exact") || "Not exact";
      let curve = {};
      try {
        curve = JSON.parse(wrap.getAttribute("data-cost-curve") || "{}");
      } catch (e) {
        curve = {};
      }

      const spentEl = AF_ATF.qs(".af-atf-pointbuy-spent", wrap);
      const remainingEl = AF_ATF.qs(".af-atf-pointbuy-remaining", wrap);
      const errorEl = AF_ATF.qs(".af-atf-pointbuy-errors", wrap);

      function stepCost(from, to) {
        if (!curve || !curve.costs) return null;
        const key = `${from}->${to}`;
        if (Object.prototype.hasOwnProperty.call(curve.costs, key)) {
          return parseInt(curve.costs[key], 10);
        }
        return null;
      }

      function calcCost(value) {
        if (value === base) return { cost: 0, invalid: false };
        let cost = 0;
        if (value > base) {
          for (let i = base; i < value; i += 1) {
            const step = stepCost(i, i + 1);
            if (step === null) return { cost: 0, invalid: true };
            cost += step;
          }
          return { cost, invalid: false };
        }
        for (let i = base; i > value; i -= 1) {
          const step = stepCost(i - 1, i);
          if (step === null) return { cost: 0, invalid: true };
          cost -= step;
        }
        return { cost, invalid: false };
      }

      function collectValues() {
        const values = {};
        inputs.forEach((input) => {
          const code = input.getAttribute("data-attr");
          if (!code) return;
          values[code] = parseInt(input.value || "0", 10);
        });
        return values;
      }

      function update() {
        const values = collectValues();
        let spent = 0;
        let hasRangeError = false;
        let hasCostError = false;

        Object.keys(values).forEach((code) => {
          const val = values[code];
          if (Number.isNaN(val) || val < min || val > max) {
            hasRangeError = true;
            return;
          }
          const result = calcCost(val);
          if (result.invalid) {
            hasCostError = true;
            return;
          }
          spent += result.cost;
        });

        const remaining = total - spent;
        if (spentEl) spentEl.textContent = String(spent);
        if (remainingEl) remainingEl.textContent = String(remaining);

        const errors = [];
        if (hasRangeError || hasCostError) errors.push(errRange);
        if (!allowNegative && spent > total) errors.push(errOver);
        if (requireExact && spent !== total) errors.push(errExact);

        if (errorEl) {
          errorEl.textContent = errors.join(" · ");
        }

        hidden.value = JSON.stringify(values);
      }

      inputs.forEach((input) => {
        input.addEventListener("input", update);
        input.addEventListener("change", update);
      });

      update();
    },

    initDynamicKbPreviews() {
      AF_ATF.qsa(".af-atf-kb-dynamic").forEach((wrap) => {
        const preview = AF_ATF.qs(".af-atf-kb-dynamic-preview[data-preview-role='element']", wrap);
        const select = AF_ATF.qs("select", wrap);
        if (!preview || !select) return;

        function render() {
          const option = select.options[select.selectedIndex] || null;
          const key = option ? String(option.value || "").trim() : "";
          if (!key) {
            preview.innerHTML = "";
            return;
          }

          const label = option ? String(option.textContent || key) : key;
          const iconUrl = option ? String(option.getAttribute("data-icon-url") || "").trim() : "";
          const iconClass = option ? String(option.getAttribute("data-icon-class") || "").trim() : "";
          const tooltip = option ? String(option.getAttribute("data-tooltip") || "").trim() : "";

          const chip = document.createElement("span");
          chip.className = "af_kb_chip af-kb-chip af-atf-element-chip";
          if (tooltip) chip.title = tooltip;

          if (iconUrl) {
            const img = document.createElement("img");
            img.className = "af-atf-element-icon";
            img.src = iconUrl;
            img.alt = "";
            chip.appendChild(img);
          } else if (iconClass) {
            const icon = document.createElement("i");
            icon.className = `af-atf-element-icon ${iconClass}`;
            chip.appendChild(icon);
          }

          const text = document.createElement("span");
          text.className = "af-atf-element-label";
          text.textContent = label;
          chip.appendChild(text);

          preview.innerHTML = "";
          preview.appendChild(chip);
        }

        select.addEventListener("change", render);
        render();
      });
    },

    initOriginVariantDependency() {
      const wrap = AF_ATF.qs(".af-atf-origin-variant");
      if (!wrap) return;
      const variantSelect = AF_ATF.qs("select", wrap);
      const originWrap = AF_ATF.qs("[data-character-field='character_origin']")
        || AF_ATF.qs("[data-character-field='character_race']");
      const originSelect = originWrap ? AF_ATF.qs("select", originWrap) : null;
      const container = wrap.closest(".af-atf-character-top-grid-item, .atf-compose__atf-grid > .af-atf-form-field") || wrap.closest("tr");
      const endpoint = String(wrap.getAttribute("data-endpoint") || "");
      if (!variantSelect || !originSelect || !endpoint) return;

      let requestId = 0;
      const setVisible = (visible) => {
        if (container) container.style.display = visible ? "" : "none";
        variantSelect.disabled = !visible;
      };
      const replaceOptions = (items, selected) => {
        variantSelect.innerHTML = '<option value=""></option>';
        (Array.isArray(items) ? items : []).forEach((item) => {
          const value = item && item.value ? String(item.value) : "";
          if (!value) return;
          const option = document.createElement("option");
          option.value = value;
          option.textContent = item.label ? String(item.label) : value;
          variantSelect.appendChild(option);
        });
        if (selected && Array.from(variantSelect.options).some((option) => option.value === selected)) {
          variantSelect.value = selected;
        }
        setVisible(variantSelect.options.length > 1);
      };

      const load = async (preserveCurrent) => {
        const currentRequest = ++requestId;
        const origin = String(originSelect.value || "").trim();
        const selected = preserveCurrent ? String(variantSelect.value || "") : "";
        replaceOptions([], "");
        if (!origin) return;
        try {
          const url = new URL(endpoint, window.location.origin);
          url.searchParams.set("origin", origin);
          const response = await fetch(url.toString(), { credentials: "same-origin" });
          const data = await response.json();
          if (currentRequest !== requestId || !data || data.ok !== 1) return;
          replaceOptions(data.items, selected);
        } catch (e) {
          if (currentRequest === requestId) replaceOptions([], "");
        }
      };

      originSelect.addEventListener("change", () => load(false));
      // Validate and restore the server-prefilled variant only after Origin is
      // known and its dependent option set has been loaded.
      load(true);
    },

    initCharacterMechanic() {
      const switcher = AF_ATF.qs(".af-atf-character-mechanic");
      if (!switcher) return;

      function parseOptions(raw) {
        try {
          const parsed = JSON.parse(raw || "{}");
          return parsed && typeof parsed === "object" ? parsed : {};
        } catch (e) {
          return {};
        }
      }

      function renderMechanicSelects(mode) {
        AF_ATF.qsa(".af-atf-kb-mechanic").forEach((wrap) => {
          const select = AF_ATF.qs("select", wrap);
          if (!select) return;
          const optionsByMode = parseOptions(wrap.getAttribute("data-options"));
          const options = Array.isArray(optionsByMode[mode]) ? optionsByMode[mode] : [];
          const current = String(select.value || "");

          let html = '<option value=""></option>';
          let hasCurrent = false;
          options.forEach((item) => {
            const key = item && item.key ? String(item.key) : "";
            if (!key) return;
            if (key === current) hasCurrent = true;
            const title = item && item.title ? String(item.title) : key;
            html += `<option value="${AF_ATF.escapeAttr(key)}">${AF_ATF.escapeAttr(title)}</option>`;
          });
          if (current && !hasCurrent) {
            html += `<option value="${AF_ATF.escapeAttr(current)}">${AF_ATF.escapeAttr(current)}</option>`;
          }

          select.innerHTML = html;
          if (current) select.value = current;
        });
      }

      function applyMode(mode) {
        const activeMode = mode === "arpg" ? "arpg" : "dnd";

        AF_ATF.qsa(".af-atf-kb-dynamic").forEach((wrap) => {
          const scope = String(wrap.getAttribute("data-mechanic-scope") || "").trim();
          const row = wrap.closest(".af-atf-character-top-grid-item, .atf-compose__atf-grid > .af-atf-form-field") || wrap.closest("tr");
          const shouldShow = !scope || scope === activeMode;
          if (row) {
            row.style.display = shouldShow ? "" : "none";
          }
          if (!shouldShow) {
            const select = AF_ATF.qs("select", wrap);
            if (select) select.value = "";
          }
        });

        renderMechanicSelects(activeMode);
        AF_ATF.initDynamicKbPreviews();
      }

      switcher.addEventListener("change", () => applyMode(String(switcher.value || "dnd")));
      applyMode(String(switcher.value || "dnd"));
    },

    initCharacterAbilities() {
      const blocks = AF_ATF.qsa(".af-atf-abilities");
      if (!blocks.length) return;

      const createAbility = (seed) => {
        const source = seed && typeof seed === "object" ? seed : {};
        const ability = { ...source };
        const rawType = String(source.type || source.ability_type || "active").toLowerCase();
        ability.title = String(source.title || source.ability_name || "");
        ability.ability_name = ability.title;
        ability.icon = String(source.icon || source.icon_url || "");
        ability.icon_url = ability.icon;
        ability.type = rawType || "active";
        ability.subtype = String(source.subtype || "");
        ability.slot = String(source.slot || "");
        ability.damage_type = String(source.damage_type || "");
        ability.target = String(source.target || source.targeting || "");
        ability.targeting = ability.target;
        ability.range = String(source.range ?? "");
        ability.formula_profile = String(source.formula_profile || "");
        ability.duration_value = String(source.duration_value ?? source.duration ?? "");
        ability.cooldown_value = String(source.cooldown_value ?? "");
        ability.cost_value = String(source.cost_value ?? "");
        ability.cost_resource = String(source.cost_resource || "");
        ability.effects = Array.isArray(source.effects) ? source.effects.map((effect) => {
          const item = effect && typeof effect === "object" ? { ...effect } : {};
          return {
            ...item,
            effect_type: String(item.effect_type || item.kind || ""), value: String(item.value ?? ""),
            formula_profile: String(item.formula_profile || ""), coefficient: String(item.coefficient ?? ""),
            target: String(item.target || item.targeting || ""), damage_type: String(item.damage_type || ""), element: String(item.element || ""),
            duration_value: String(item.duration_value ?? item.duration ?? ""), ...(Object.prototype.hasOwnProperty.call(item, "duration_unit") ? {duration_unit: item.duration_unit} : {}),
            status_key: String(item.status_key || ""), stat_key: String(item.stat_key || ""), operation: String(item.operation || ""),
            resource_key: String(item.resource_key || ""), notes: String(item.notes || "")
          };
        }) : [];
        const abilityDescription = String(source.ability_description || source.description || source.desc || "");
        ability.ability_description = abilityDescription;
        ability.description = abilityDescription;
        ability.desc = abilityDescription;
        ability.slot_index = Number.isFinite(Number(source.slot_index)) ? Number(source.slot_index) : 0;
        ability.sortorder = Number.isFinite(Number(source.sortorder)) ? Number(source.sortorder) : 0;
        return ability;
      };

      blocks.forEach((wrap) => {
        if (wrap.__afAbilitiesInited) return;
        wrap.__afAbilitiesInited = true;

        const hidden = AF_ATF.qs(".af-atf-abilities-hidden", wrap);
        const optionsScript = AF_ATF.qs(".af-atf-abilities-options", wrap);
        const list = AF_ATF.qs(".af-atf-abilities-list", wrap);
        const addBtn = AF_ATF.qs(".af-atf-abilities-add", wrap);
        const kbBtn = AF_ATF.qs(".af-atf-abilities-kb", wrap);
        const kbNotices = JSON.parse(wrap.dataset.kbNotices || "{}");
        const maxItems = parseInt(wrap.getAttribute("data-max-items") || "8", 10) || 8;
        if (!hidden || !list || !addBtn) return;
        let selectOptions = {};
        try {
          selectOptions = JSON.parse(String(optionsScript && optionsScript.textContent ? optionsScript.textContent : "{}")) || {};
        } catch (e) {
          selectOptions = {};
        }
        const optionLabel = (row) => {
          if (!row || typeof row !== "object") return "";
          return String(row.label_ru || row.label_en || row.key || "");
        };
        const renderSelectOptions = (setName, currentValue) => {
          let html = '<option value=""></option>';
          const rows = Array.isArray(selectOptions[setName]) ? selectOptions[setName] : [];
          const seen = new Set();
          rows.forEach((row) => {
            if (!row || typeof row !== "object") return;
            const key = String(row.key || "").trim();
            if (!key || seen.has(key)) return;
            seen.add(key);
            const help = String(row.description || row.description_ru || "");
            html += `<option value="${AF_ATF.escapeAttr(key)}"${help ? ` title="${AF_ATF.escapeAttr(help)}"` : ""}${String(currentValue) === key ? " selected" : ""}>${AF_ATF.escapeAttr(optionLabel(row) || key)}</option>`;
          });
          const raw = String(currentValue || "").trim();
          if (raw && !seen.has(raw)) {
            html += `<option value="${AF_ATF.escapeAttr(raw)}" selected>${AF_ATF.escapeAttr(raw)}</option>`;
          }
          return html;
        };
        let state = [];
        let changed = false;
        let activeDialog = null;
        const dirtyRows = new WeakSet();
        const originalRows = new WeakMap();
        const limitMessage = document.createElement("p");
        limitMessage.setAttribute("role", "status");
        wrap.append(limitMessage);
        try {
          const parsed = JSON.parse(String(hidden.value || "[]"));
          const rows = Array.isArray(parsed) ? parsed : (parsed.items || parsed.abilities || Object.values(parsed));
          if (Array.isArray(rows)) state = rows.filter((row) => row && typeof row === "object")
            .map((row) => {
              const ability = row.ability_source ? row : createAbility(row);
              originalRows.set(ability, row);
              return ability;
            });
        } catch (error) {
          // Preserve the original field value until the user explicitly changes it.
        }
        function markChanged(ability) {
          changed = true;
          if (ability) dirtyRows.add(ability);
        }

        function getAbilityDescriptionValue(row) {
          const textarea = AF_ATF.qs(".af-atf-ability-description", row);
          if (!textarea) return "";

          if (window.jQuery && typeof window.jQuery === "function") {
            try {
              const instance = window.jQuery(textarea).sceditor("instance");
              if (instance && typeof instance.val === "function") {
                const richValue = instance.val();
                if (richValue !== null && richValue !== undefined) {
                  return String(richValue);
                }
              }
            } catch (e) {
              // SCEditor may not be attached
            }
          }

          return String(textarea.value || "");
        }

        function collectAbilityFromRow(row, index) {
          const descriptionValue = getAbilityDescriptionValue(row);
          const current = state[index] || {};
          const collected = createAbility({
            ...current,
            slot_index: AF_ATF.qs(".af-atf-ability-slot-index", row).value,
            title: AF_ATF.qs(".af-atf-ability-name", row).value,
            icon: AF_ATF.qs(".af-atf-ability-icon-url", row).value,
            type: AF_ATF.qs(".af-atf-ability-type", row).value,
            subtype: AF_ATF.qs(".af-atf-ability-subtype", row).value,
            slot: AF_ATF.qs(".af-atf-ability-slot", row).value,
            damage_element: AF_ATF.qs(".af-atf-ability-damage-element", row).value,
            damage_type: AF_ATF.qs(".af-atf-ability-damage-type", row).value,
            target: AF_ATF.qs(".af-atf-ability-targeting", row).value,
            range: AF_ATF.qs(".af-atf-ability-range", row).value,
            formula_profile: AF_ATF.qs(".af-atf-ability-formula-profile", row).value,
            duration_value: AF_ATF.qs(".af-atf-ability-duration-value", row).value,
            duration_unit: AF_ATF.qs(".af-atf-ability-duration-unit", row).value,
            action_cost: AF_ATF.qs(".af-atf-ability-action-cost", row).value,
            cooldown_value: AF_ATF.qs(".af-atf-ability-cooldown-value", row).value,
            cooldown_unit: AF_ATF.qs(".af-atf-ability-cooldown-unit", row).value,
            cost_value: AF_ATF.qs(".af-atf-ability-cost-value", row).value,
            cost_resource: AF_ATF.qs(".af-atf-ability-cost-resource", row).value,
            ability_description: descriptionValue,
            sortorder: AF_ATF.qs(".af-atf-ability-sortorder", row).value
          });
          ["action_cost", "duration_unit", "cooldown_unit"].forEach((key) => {
            if (collected[key] === "" && !Object.prototype.hasOwnProperty.call(current, key)) delete collected[key];
          });
          // Preserve object identity: nested-effect callbacks close over this
          // object. Replacing it made Add/Remove Effect write to a stale object
          // after any ordinary ability field had been edited.
          Object.keys(current).forEach((key) => { delete current[key]; });
          Object.assign(current, collected);
          state[index] = current;
          return current;
        }

        function syncStateFromDom() {
          AF_ATF.qsa(".af-atf-ability-item", list).forEach((row, index) => {
            const ability = state[index];
            if (!ability || ability.ability_source) return;
            if (getAbilityDescriptionValue(row) !== ability.ability_description) markChanged(ability);
            if (dirtyRows.has(ability)) collectAbilityFromRow(row, index);
          });
        }

        function sync() {
          if (changed) hidden.value = JSON.stringify(state.map((ability) =>
            dirtyRows.has(ability) ? ability : (originalRows.get(ability) || ability)));
          limitMessage.textContent = state.length >= maxItems
            ? "Можно сохранить до " + maxItems + " способностей. Удалите запись, чтобы добавить другую." : "";
          addBtn.disabled = state.length >= maxItems;
          if (kbBtn) kbBtn.disabled = state.length >= maxItems;
        }

        function render() {
          list.innerHTML = "";
          state.forEach((ability, index) => {
            const row = document.createElement("div");
            row.className = "af-atf-ability-item";
            if (ability.ability_source) {
              const key = ability.ability_kb_key;
              const title = document.createElement("strong");
              title.textContent = "Способность #" + (index + 1) + " · KB · " + (ability.title || key);
              const notice = document.createElement("p");
              notice.textContent = kbNotices[key] || "Доступность в бою проверяет ACS.";
              const description = document.createElement("p");
              description.textContent = ability.description || "";
              const remove = document.createElement("button");
              remove.type = "button";
              remove.className = "button";
              remove.textContent = "Удалить";
              remove.addEventListener("click", () => {
                syncStateFromDom();
              markChanged();
              state.splice(index, 1);
                delete kbNotices[key];
                render();
              });
              row.append(title, notice, description);
              if (wrap.dataset.kbCatalog && /^[a-z0-9_-]{2,64}$/.test(key)) {
                const url = new URL(wrap.dataset.kbCatalog, location.href);
                if (url.searchParams.has("action") && url.pathname.endsWith("/misc.php")) url.searchParams.set("action", "kb");
                else url.searchParams.delete("action");
                url.searchParams.set("type", "arpg_ability");
                url.searchParams.set("key", key);
                const link = document.createElement("a");
                link.href = url;
                link.target = "_blank";
                link.rel = "noopener";
                link.textContent = "Открыть в KB";
                row.append(link, " ");
              }
              row.append(remove);
              list.appendChild(row);
              return;
            }
            row.innerHTML = `
              <div class="af-atf-ability-header">
                <strong>Способность #${index + 1}</strong>
                <button type="button" class="button af-atf-ability-remove">Удалить</button>
              </div>
              <div class="af-atf-ability-grid">
                <label class="af-atf-ability-field">
                  <span class="af-atf-ability-label">Название способности</span>
                  <input type="text" class="textbox text_input af-atf-input af-atf-ability-name" placeholder="Название способности" value="${AF_ATF.escapeAttr(ability.title)}" />
                </label>
                <label class="af-atf-ability-field">
                  <span class="af-atf-ability-label">Иконка</span>
                  <input type="text" inputmode="url" class="textbox text_input af-atf-input af-atf-ability-icon-url" placeholder="https://..." value="${AF_ATF.escapeAttr(ability.icon)}" />
                </label>
                <label class="af-atf-ability-field">
                  <span class="af-atf-ability-label">Тип</span>
                  <select class="select af-atf-input af-atf-ability-type">${renderSelectOptions("type", ability.type)}</select>
                </label>
                <label class="af-atf-ability-field">
                  <span class="af-atf-ability-label">Подтип</span>
                  <select class="select af-atf-input af-atf-ability-subtype">${renderSelectOptions("subtype", ability.subtype)}</select>
                </label>
                <label class="af-atf-ability-field">
                  <span class="af-atf-ability-label">Слот</span>
                  <select class="select af-atf-input af-atf-ability-slot">${renderSelectOptions("slot", ability.slot)}</select>
                </label>
                <label class="af-atf-ability-field">
                  <span class="af-atf-ability-label">Тип урона</span>
                  <select class="select af-atf-input af-atf-ability-damage-type">${renderSelectOptions("damage_type", ability.damage_type)}</select>
                </label>
                <label class="af-atf-ability-field">
                  <span class="af-atf-ability-label">Цель</span>
                  <select class="select af-atf-input af-atf-ability-targeting">${renderSelectOptions("targeting", ability.target)}</select>
                </label>
                <label class="af-atf-ability-field">
                  <span class="af-atf-ability-label">Дальность</span>
                  <select class="select af-atf-input af-atf-ability-range">${renderSelectOptions("range", ability.range)}</select>
                </label>
                <label class="af-atf-ability-field">
                  <span class="af-atf-ability-label">Схема расчёта <button type="button" class="af-ability-help" aria-label="Подсказка" data-tooltip="Выбирает точную формулу из справочника механик; описание выбранной схемы приведено в подсказке списка.">?</button></span>
                  <select class="select af-atf-input af-atf-ability-formula-profile">${renderSelectOptions("formula_profile", ability.formula_profile)}</select>
                </label>
                <label class="af-atf-ability-field"><span class="af-atf-ability-label">Кулдаун <button type="button" class="af-ability-help" aria-label="Подсказка" data-tooltip="Интервал до повторного использования способности; единица выбирается отдельно.">?</button></span><input type="number" step="1" min="0" class="textbox text_input af-atf-input af-atf-ability-cooldown-value" value="${AF_ATF.escapeAttr(ability.cooldown_value)}" /><select class="select af-atf-ability-cooldown-unit">${renderSelectOptions("duration_unit", ability.cooldown_unit)}</select></label>
                <label class="af-atf-ability-field"><span>Стоимость в очках действий</span><input type="number" min="0" step="any" class="textbox af-atf-input af-atf-ability-action-cost" value="${AF_ATF.escapeAttr(ability.action_cost ?? "")}" /></label>
                <label class="af-atf-ability-field"><span>Общая длительность</span><input type="number" min="0" step="any" class="textbox af-atf-input af-atf-ability-duration-value" value="${AF_ATF.escapeAttr(ability.duration_value)}" /></label>
                <label class="af-atf-ability-field"><span>Единица общей длительности</span><select class="select af-atf-ability-duration-unit">${renderSelectOptions("duration_unit", ability.duration_unit)}</select></label>
                <label class="af-atf-ability-field"><span class="af-atf-ability-label">Стоимость <button type="button" class="af-ability-help" aria-label="Подсказка" data-tooltip="Количество выбранного ресурса, которое тратится при использовании способности.">?</button></span><input type="number" step="any" class="textbox text_input af-atf-input af-atf-ability-cost-value" value="${AF_ATF.escapeAttr(ability.cost_value)}" /></label>
                <label class="af-atf-ability-field"><span class="af-atf-ability-label">Ресурс</span><select class="select af-atf-input af-atf-ability-cost-resource">${renderSelectOptions("resource", ability.cost_resource)}</select></label>
                <label class="af-atf-ability-field">
                  <span class="af-atf-ability-label">Порядок</span>
                  <input type="number" class="textbox text_input af-atf-input af-atf-ability-sortorder" placeholder="sortorder" value="${AF_ATF.escapeAttr(ability.sortorder || index + 1)}" />
                </label>
              </div>
              <label class="af-atf-ability-description-wrap">
                <span class="af-atf-ability-label">Описание способности</span>
                <textarea class="textbox textarea af-atf-input af-atf-ability-description" rows="4" placeholder="Описание способности">${AF_ATF.escapeAttr(ability.ability_description)}</textarea>
              </label>
              <label>Стихия урона<select class="select af-atf-ability-damage-element">${renderSelectOptions("damage_element", ability.damage_element)}</select><span class="af-atf-ability-damage-error" role="status"></span></label>
              <div class="af-atf-effects"><strong>Эффекты</strong><div class="af-atf-effects-list"></div><button type="button" class="button af-atf-effect-add">Добавить эффект</button></div>
              <label style="display:none;">
                <input type="number" class="textbox text_input af-atf-ability-slot-index" value="${AF_ATF.escapeAttr(ability.slot_index || index + 1)}" />
              </label>
            `;

            if (!dirtyRows.has(ability)) AF_ATF.allowStoredAbilityNumbers(row);
            const effectsList = AF_ATF.qs(".af-atf-effects-list", row);
            const renderEffects = () => {
              effectsList.innerHTML = "";
              ability.effects.forEach((effect, effectIndex) => {
                const effectRow = document.createElement("div");
                effectRow.className = "af-atf-effect-item";
                effectRow.innerHTML = `<div class="af-atf-effect-header"><strong>Эффект #${effectIndex + 1}</strong><button type="button" class="button af-atf-effect-remove">Удалить эффект</button></div>
                  <label>Тип эффекта<select class="select af-atf-effect-type">${renderSelectOptions("effect_type", effect.effect_type)}</select></label>
                  <label data-for="value"><span><span class="af-atf-effect-value-label">Значение</span> <button type="button" class="af-ability-help" aria-label="Подсказка" data-tooltip="Числовое значение, используемое выбранной схемой расчёта.">?</button></span><input type="number" step="any" class="textbox af-atf-effect-value" value="${AF_ATF.escapeAttr(effect.value)}"></label>
                  <label data-for="formula"><span>Схема расчёта <button type="button" class="af-ability-help" aria-label="Подсказка" data-tooltip="Выбирает формулу вычисления эффекта из справочника механик.">?</button></span><select class="select af-atf-effect-formula">${renderSelectOptions("formula_profile", effect.formula_profile)}</select></label>
                  <label data-for="target">Цель<select class="select af-atf-effect-target">${renderSelectOptions("targeting", effect.target)}</select></label>
                  <label data-for="damage_type">Тип урона<select class="select af-atf-effect-damage-type">${renderSelectOptions("damage_type", effect.damage_type)}</select></label>
                  <label data-for="element">Стихия урона<select class="select af-atf-effect-damage-element">${renderSelectOptions("damage_element", effect.damage_element)}</select><span class="af-atf-damage-error" role="status"></span></label>
                  <label data-for="duration"><span>Длительность эффекта <button type="button" class="af-ability-help" aria-label="Подсказка" data-tooltip="Длительность эффекта; единица выбирается отдельно.">?</button></span><input type="number" step="1" min="0" class="textbox af-atf-effect-duration" value="${AF_ATF.escapeAttr(effect.duration_value)}"><select class="select af-atf-effect-duration-unit">${renderSelectOptions("duration_unit", effect.duration_unit)}</select></label>
                  <label data-for="status"><span>Статус / контроль <button type="button" class="af-ability-help" aria-label="Подсказка" data-tooltip="Статус или контроль, накладываемый на выбранную цель.">?</button></span><select class="select af-atf-effect-status">${renderSelectOptions("status", effect.status_key)}</select></label>
                  <label data-for="stat">Характеристика<select class="select af-atf-effect-stat">${renderSelectOptions("stat", effect.stat_key)}</select></label>
                  <label data-for="operation"><span>Операция <button type="button" class="af-ability-help" aria-label="Подсказка" data-tooltip="Определяет, как указанное значение изменяет текущую характеристику или ресурс.">?</button></span><select class="select af-atf-effect-operation">${renderSelectOptions("operation", effect.operation)}</select></label>
                  <label data-for="resource">Ресурс<select class="select af-atf-effect-resource">${renderSelectOptions("resource", effect.resource_key)}</select></label>
                  <label data-for="notes" style="display:none">Примечание<textarea class="textbox af-atf-effect-notes">${AF_ATF.escapeAttr(effect.notes)}</textarea></label>
                  `;
                const collectEffect = (event) => {
                  if (event) markChanged(ability);
                  effect.effect_type = AF_ATF.qs('.af-atf-effect-type', effectRow).value;
                  const map = {value:'value', formula:'formula_profile', target:'target', 'damage-type':'damage_type', 'damage-element':'damage_element', duration:'duration_value', status:'status_key', stat:'stat_key', operation:'operation', resource:'resource_key', notes:'notes'};
                  Object.keys(map).forEach((suffix) => { const input = AF_ATF.qs('.af-atf-effect-' + suffix, effectRow); if (input) effect[map[suffix]] = input.value; });
                  const damageElement = AF_ATF.qs('.af-atf-effect-damage-element', effectRow);
                  if (!damageElement.value) delete effect.damage_element;
                  const needsElement = effect.effect_type === 'damage' && effect.damage_type === 'elemental';
                  damageElement.required = needsElement && dirtyRows.has(ability);
                  const damageError = needsElement && !damageElement.value ? 'Выберите стихию урона. Направление магии не определяет её автоматически.' : '';
                  damageElement.setCustomValidity(dirtyRows.has(ability) ? damageError : "");
                  AF_ATF.qs('.af-atf-damage-error', effectRow).textContent = damageError;
                  const unit = AF_ATF.qs('.af-atf-effect-duration-unit', effectRow).value;
                  if (unit !== '' || Object.prototype.hasOwnProperty.call(effect, 'duration_unit')) effect.duration_unit = unit;
                  const relevant = {damage:['value','formula','target','damage_type','element'], heal:['value','formula','target'], shield:['value','formula','target','duration'], status:['status','target','duration'], control:['status','target','duration'], stat_modifier:['stat','operation','value','target','duration'], resource:['resource','operation','value','target']};
                  const valueLabels = {damage:'Значение урона',heal:'Значение лечения',shield:'Прочность щита',stat_modifier:'Величина модификатора',resource:'Изменение ресурса'};
                  const valueLabel = AF_ATF.qs('.af-atf-effect-value-label', effectRow); if (valueLabel) valueLabel.textContent = valueLabels[effect.effect_type] || 'Значение';
                  AF_ATF.qsa('[data-for]', effectRow).forEach((field) => {
                    const visible = (relevant[effect.effect_type] || []).includes(field.dataset.for);
                    field.style.display = visible ? '' : 'none';
                    AF_ATF.qsa('input,select,textarea', field).forEach((control) => { control.disabled = !visible; });
                  });
                  state[index] = ability;
                  sync();
                };
                AF_ATF.qsa('input,select,textarea', effectRow).forEach((el) => { el.addEventListener('input', collectEffect); el.addEventListener('change', collectEffect); });
                AF_ATF.qs('.af-atf-effect-remove', effectRow).addEventListener('click', () => { markChanged(ability); ability.effects.splice(effectIndex, 1); state[index] = ability; renderEffects(); sync(); });
                if (!dirtyRows.has(ability)) AF_ATF.allowStoredAbilityNumbers(effectRow);
                effectsList.appendChild(effectRow); collectEffect();
              });
            };
            AF_ATF.qs('.af-atf-effect-add', row).addEventListener('click', () => { markChanged(ability); ability.effects.push(createAbility({effects:[{}]}).effects[0]); state[index] = ability; renderEffects(); sync(); });
            renderEffects();

            const setValue = () => {
              markChanged(ability);
              state[index] = collectAbilityFromRow(row, index);
              const input = AF_ATF.qs('.af-atf-ability-damage-element', row);
              if (!input.value) delete state[index].damage_element;
              input.required = state[index].damage_type === 'elemental';
              const message = input.required && !input.value ? 'Выберите стихию урона.' : '';
              input.setCustomValidity(message);
              AF_ATF.qs('.af-atf-ability-damage-error', row).textContent = message;
              sync();
            };

            AF_ATF.qsa("input,select,textarea", row).forEach((el) => {
              el.addEventListener("input", setValue);
              el.addEventListener("change", setValue);
            });

            AF_ATF.qs(".af-atf-ability-remove", row).addEventListener("click", () => {
              syncStateFromDom();
                markChanged();
                state.splice(index, 1);
              render();
              sync();
            });

            list.appendChild(row);
          });
          sync();
        }

        function openCatalog() {
          if (activeDialog) return;
          if (state.length >= maxItems) { sync(); return; }
          const dialog = document.createElement("dialog");
          dialog.className = "af-atf-kb-ability-dialog";
          dialog.setAttribute("aria-label", "Каталог способностей KB");
          const form = hidden.form;
          const context = JSON.parse(wrap.dataset.kbContext || "{}");
          dialog.innerHTML = `
            <div class="af-atf-kb-modal-title">Каталог способностей KB</div>
            <button type="button" class="button af-atf-kb-modal-close">Закрыть</button>
            <form class="af-atf-kb-modal-body">
              <label>Поиск по названию <input type="search" name="q" maxlength="100" class="textbox"></label>
              <label>Применимость <select name="compatibility">
                <option value="compatible">Подходят</option>
                <option value="needs_review">Требуют уточнения условий</option>
                <option value="incompatible">Несовместимые</option>
                <option value="">Все</option>
              </select></label>
              <div class="af-atf-kb-ability-filters"></div>
              <button type="submit" class="button">Применить</button>
            </form>
            <p role="status" aria-live="polite"></p>
            <div class="af-atf-kb-modal-body af-atf-kb-ability-results"></div>
            <div class="af-atf-kb-ability-pages"></div>`;
          const filters = dialog.querySelector("form");
          const options = dialog.querySelector(".af-atf-kb-ability-filters");
          const results = dialog.querySelector(".af-atf-kb-ability-results");
          const pages = dialog.querySelector(".af-atf-kb-ability-pages");
          const status = dialog.querySelector("[role=status]");
          const labels = {
            ability_type: "Тип способности", damage_type: "Урон", element: "Стихия",
            direction: "Направление", distance: "Дистанция", weapon: "Оружие",
            purpose: "Назначение", sort: "Сортировка"
          };
          const statuses = {
            compatible: "Подходит", needs_review: "Требует уточнения условий",
            incompatible: "Несовместима с выбранными параметрами"
          };
          let request;
          let registry;
          const button = (text, action) => {
            const el = document.createElement("button");
            el.type = "button";
            el.className = "button";
            el.textContent = text;
            el.addEventListener("click", action);
            return el;
          };

          function setOptions(available) {
            registry = available;
            for (const [name, title] of Object.entries(labels)) {
              const label = document.createElement("label");
              label.textContent = title + " ";
              const select = document.createElement("select");
              select.name = name;
              select.add(new Option(name === "sort" ? "По названию" : "Все", ""));
              for (const [key, text] of Object.entries(available[name] || {})) {
                if (name !== "sort" || key !== "title") select.add(new Option(text, key));
              }
              label.append(select);
              options.append(label);
            }
          }

          function addAbility(item, custom) {
            syncStateFromDom();
            if (state.length >= maxItems) { status.textContent = "Достигнут предел способностей."; return; }
            const order = state.length + 1;
            if (!custom && state.some((entry) => entry.ability_source === "arpg_ability" && entry.ability_kb_key === item.kb_key)) return;
            state.push(custom
              ? createAbility({ title: item.title, description: item.description, sortorder: order, slot_index: order })
              : { ability_source: "arpg_ability", ability_kb_key: item.kb_key, catalog_definition_id: item.definition_id,
                  title: item.title, description: item.description, kb_url: item.url, sortorder: order, slot_index: order });
            markChanged();
            render();
            closeCatalog();
          }

          async function load(page = 1) {
            if (request) request.abort();
            const controller = new AbortController();
            request = controller;
            const url = new URL(wrap.dataset.kbCatalog, location.href);
            for (const [key, value] of new FormData(filters)) {
              if (value) url.searchParams.set(key, value);
            }
            for (const [facet, ids] of Object.entries(context)) {
              const value = ids.map((id) => form?.elements.namedItem("af_tf[" + id + "]")?.value?.trim()).find(Boolean);
              if (!value) continue;
              const key = facet === "element" && value === "wind" ? "air" : value;
              if (registry && !registry[facet]?.[key] && !(facet === "element" && registry.direction?.[key])) continue;
              url.searchParams.set("context_" + facet, key);
            }
            url.searchParams.set("independent_context", "1");
            url.searchParams.set("options", registry ? "0" : "1");
            url.searchParams.set("page", String(page));
            url.searchParams.set("per_page", "8");
            results.replaceChildren();
            pages.replaceChildren();
            status.textContent = "Загрузка…";
            try {
              const response = await fetch(url, { signal: controller.signal, credentials: "same-origin" });
              if (!response.ok) throw new Error("Catalog request failed");
              const payload = await response.json();
              if (controller.signal.aborted) return;
              if (!payload.success) throw new Error("Catalog request failed");
              if (!registry) setOptions(payload.options || {});
              status.textContent = payload.total
                ? "Найдено: " + payload.total + " · Страница " + payload.page + " из " + payload.pages
                : "По выбранным условиям ничего не найдено. Измените фильтр применимости, чтобы посмотреть остальные способности.";
              for (const item of payload.items) {
                const card = document.createElement("article");
                card.className = "af-atf-ability-item";
                const link = document.createElement("a");
                link.href = item.url;
                link.target = "_blank";
                link.rel = "noopener";
                link.textContent = item.title;
                const meta = document.createElement("p");
                meta.textContent = [statuses[item.compatibility], ...item.element.map((key) => registry.element[key]),
                  ...item.direction.map((key) => registry.direction[key]),
                  ...item.applicability.weapons.map((key) => registry.weapon[key]),
                  registry.distance[item.applicability.distance]].filter(Boolean).join(" · ");
                const description = document.createElement("p");
                description.textContent = item.description;
                card.append(link, meta, description);
                if (item.compatibility !== "incompatible") {
                  const pick = button(item.compatibility === "compatible" ? "Выбрать" : "Добавить для проверки", () => addAbility(item, false));
                  pick.disabled = state.length >= maxItems || state.some((entry) => entry.ability_source === "arpg_ability" && entry.ability_kb_key === item.kb_key);
                  card.append(pick);
                }
                const custom = button("Использовать как основу", () => addAbility(item, true));
                custom.disabled = state.length >= maxItems;
                card.append(custom);
                results.append(card);
              }
              if (payload.page > 1) pages.append(button("Назад", () => load(payload.page - 1)));
              if (payload.page < payload.pages) pages.append(button("Далее", () => load(payload.page + 1)));
            } catch (error) {
              if (!controller.signal.aborted) status.textContent = "Не удалось загрузить каталог. Повторите поиск.";
            }
          }

          filters.addEventListener("submit", (event) => {
            event.preventDefault();
            load();
          });
          filters.addEventListener("change", () => load());
          function cleanup() {
            if (activeDialog !== dialog) return;
            activeDialog = null;
            request?.abort();
            dialog.remove();
            (kbBtn.disabled ? list.lastElementChild?.querySelector("button") : kbBtn)?.focus({ preventScroll: true });
          }
          function closeCatalog() {
            if (dialog.open) dialog.close();
            cleanup();
          }
          dialog.querySelector(".af-atf-kb-modal-close").addEventListener("click", closeCatalog);
          dialog.addEventListener("cancel", (event) => {
            event.preventDefault();
            closeCatalog();
          });
          dialog.addEventListener("close", () => cleanup(), { once: true });
          dialog.addEventListener("click", (event) => {
            if (event.target !== dialog) return;
            const bounds = dialog.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right
                || event.clientY < bounds.top || event.clientY > bounds.bottom) closeCatalog();
          });
          activeDialog = dialog;
          document.body.append(dialog);
          try {
            dialog.showModal();
          } catch (error) {
            cleanup();
            limitMessage.textContent = "Не удалось открыть каталог в этом браузере.";
            return;
          }
          load();
        }

        kbBtn?.addEventListener("click", openCatalog);

        addBtn.addEventListener("click", () => {
          if (state.length >= maxItems) return;
          syncStateFromDom();
          markChanged();
          state.push(createAbility({ sortorder: state.length + 1, slot_index: state.length + 1 }));
          render();
          sync();
        });

        const form = hidden.form;
        if (form && !form.__afAtfAbilitiesSubmitSync) {
          form.__afAtfAbilitiesSubmitSync = true;
          form.addEventListener("submit", (event) => {
            if (event.target !== form) return;
            Array.from(form.elements).forEach((field) => {
              if (!field.matches(".af-atf-abilities-hidden")) return;
              const block = field.closest(".af-atf-abilities");
              block?.__afAtfSyncStateFromDom?.();
              block?.__afAtfSyncHidden?.();
            });
          }, true);
        }

        wrap.__afAtfSyncStateFromDom = syncStateFromDom;
        wrap.__afAtfSyncHidden = sync;

        render();
      });
    },

    initCharacterStats() {
      const blocks = AF_ATF.qsa(".af-atf-character-stats");
      if (!blocks.length) return;

      blocks.forEach((wrap) => {
        if (wrap.__afCharacterStatsInited) return;
        wrap.__afCharacterStatsInited = true;

        const hidden = AF_ATF.qs(".af-atf-character-stats-hidden", wrap);
        const inputs = AF_ATF.qsa(".af-atf-character-stats-input", wrap);
        if (!hidden || !inputs.length) return;

        function parseHidden(raw) {
          try {
            const parsed = JSON.parse(String(raw || "{}"));
            return parsed && typeof parsed === "object" ? parsed : {};
          } catch (e) {
            return {};
          }
        }

        const state = parseHidden(hidden.value);
        inputs.forEach((input) => {
          const key = String(input.getAttribute("data-key") || "").trim();
          if (!key) return;
          if (Object.prototype.hasOwnProperty.call(state, key) && state[key] !== null && state[key] !== "") {
            input.value = String(state[key]);
          }
        });

        function sync() {
          const payload = {};
          inputs.forEach((input) => {
            const key = String(input.getAttribute("data-key") || "").trim();
            if (!key) return;
            const raw = String(input.value || "").trim();
            if (!raw) return;
            const normalized = raw.replace(",", ".");
            if (!Number.isNaN(Number(normalized))) {
              payload[key] = Number(normalized);
            }
          });
          hidden.value = JSON.stringify(payload);
        }

        inputs.forEach((input) => {
          input.addEventListener("input", sync);
          input.addEventListener("change", sync);
        });

        sync();
      });
    },

    initUserChipsOne(wrap) {
      if (!wrap || wrap.__af_atf_inited) return;
      wrap.__af_atf_inited = true;

      const hidden = AF_ATF.qs(".af-atf-userchips-hidden", wrap);
      const chipsBox = AF_ATF.qs(".af-atf-userchips-chips", wrap);
      const input = AF_ATF.qs(".af-atf-userchips-input", wrap);
      const dd = AF_ATF.qs(".af-atf-userchips-dd", wrap);

      if (!hidden || !chipsBox || !input || !dd) return;

      const suggestUrl = wrap.getAttribute("data-suggest") || "";
      const resolveUrl = wrap.getAttribute("data-resolve") || "";
      const max = parseInt(wrap.getAttribute("data-max") || "0", 10) || 0;

      const state = {
        items: [],            // текущие подсказки dropdown
        activeIndex: -1,      // навигация клавиатурой
        selected: new Map(),  // uid -> username
        suggestUrl,
        resolveUrl,
        max
      };

      // ---- helpers: selected -> hidden csv ----
      function syncHidden() {
        const uids = Array.from(state.selected.keys());
        hidden.value = uids.join(",");
      }

      function canAddMore() {
        if (!state.max || state.max <= 0) return true;
        return state.selected.size < state.max;
      }

      function renderChips() {
        chipsBox.innerHTML = "";
        for (const [uid, username] of state.selected.entries()) {
          const chip = document.createElement("span");
          chip.className = "af-atf-userchip";
          chip.setAttribute("data-uid", String(uid));

          const name = document.createElement("span");
          name.className = "af-atf-userchip-name";
          name.textContent = username;

          const btn = document.createElement("button");
          btn.type = "button";
          btn.className = "af-atf-userchip-x";
          btn.textContent = "×";
          btn.title = "Убрать";

          btn.addEventListener("click", () => {
            state.selected.delete(uid);
            syncHidden();
            renderChips();
          });

          chip.appendChild(name);
          chip.appendChild(btn);
          chipsBox.appendChild(chip);
        }
      }

      function closeDropdown() {
        dd.hidden = true;
        dd.innerHTML = "";
        state.items = [];
        state.activeIndex = -1;
      }

      function openDropdown(items) {
        state.items = Array.isArray(items) ? items : [];
        state.activeIndex = -1;

        dd.innerHTML = "";

        if (!state.items.length) {
          closeDropdown();
          return;
        }

        const ul = document.createElement("div");
        ul.className = "af-atf-userchips-dd-list";

        state.items.forEach((it, idx) => {
          const row = document.createElement("div");
          row.className = "af-atf-userchips-dd-item";
          row.setAttribute("data-index", String(idx));

          const uname = (it && it.username) ? String(it.username) : "";
          const uid = (it && it.uid) ? parseInt(it.uid, 10) : 0;

          row.textContent = uname;

          row.addEventListener("mousedown", (e) => {
            // mousedown, чтобы не потерять фокус до клика
            e.preventDefault();
            if (!uid || !uname) return;
            addSelected(uid, uname);
          });

          ul.appendChild(row);
        });

        dd.appendChild(ul);
        dd.hidden = false;
      }

      function setActive(index) {
        state.activeIndex = index;
        const rows = dd.querySelectorAll(".af-atf-userchips-dd-item");
        rows.forEach((el) => el.classList.remove("is-active"));
        if (index >= 0 && index < rows.length) {
          rows[index].classList.add("is-active");
          // ensure visible
          rows[index].scrollIntoView({ block: "nearest" });
        }
      }

      function addSelected(uid, username) {
        uid = parseInt(uid, 10);
        username = String(username || "").trim();
        if (!uid || !username) return;

        if (state.selected.has(uid)) {
          input.value = "";
          closeDropdown();
          return;
        }

        if (!canAddMore()) {
          // лимит — просто закрываем и не добавляем
          input.value = "";
          closeDropdown();
          return;
        }

        state.selected.set(uid, username);
        syncHidden();
        renderChips();

        input.value = "";
        closeDropdown();
      }

      // ---- network ----
      async function fetchSuggest(query) {
        if (!state.suggestUrl) return [];
        const url = new URL(state.suggestUrl, window.location.origin);
        url.searchParams.set("query", query);

        const r = await fetch(url.toString(), {
          method: "GET",
          credentials: "same-origin"
        });

        // если сервер вернул HTML, тут будет не json -> упадём в catch
        const data = await r.json();
        if (!data || data.ok !== 1 || !Array.isArray(data.items)) return [];
        return data.items;
      }

      async function fetchResolve(uidsCsv) {
        if (!state.resolveUrl) return [];
        const url = new URL(state.resolveUrl, window.location.origin);
        url.searchParams.set("uids", uidsCsv);

        const r = await fetch(url.toString(), {
          method: "GET",
          credentials: "same-origin"
        });

        const data = await r.json();
        if (!data || data.ok !== 1 || !Array.isArray(data.items)) return [];
        return data.items;
      }

      // ---- input listeners ----
      const doSuggest = AF_ATF.debounce(async () => {
        const q = String(input.value || "").trim();
        if (!q) {
          closeDropdown();
          return;
        }

        // если уже лимит — подсказки не нужны
        if (!canAddMore()) {
          closeDropdown();
          return;
        }

        try {
          const items = await fetchSuggest(q);

          // фильтр: убираем уже выбранных
          const filtered = items.filter((it) => {
            const uid = it && it.uid ? parseInt(it.uid, 10) : 0;
            return uid > 0 && !state.selected.has(uid);
          });

          openDropdown(filtered);
        } catch (e) {
          // молча закрываем, чтобы не ломать страницу
          closeDropdown();
        }
      }, 200);

      input.addEventListener("input", () => {
        doSuggest();
      });

      input.addEventListener("keydown", (e) => {
        if (dd.hidden) return;

        const maxIndex = state.items.length - 1;
        if (e.key === "ArrowDown") {
          e.preventDefault();
          const next = state.activeIndex < maxIndex ? state.activeIndex + 1 : 0;
          setActive(next);
        } else if (e.key === "ArrowUp") {
          e.preventDefault();
          const prev = state.activeIndex > 0 ? state.activeIndex - 1 : maxIndex;
          setActive(prev);
        } else if (e.key === "Enter") {
          if (state.activeIndex >= 0 && state.activeIndex < state.items.length) {
            e.preventDefault();
            const it = state.items[state.activeIndex];
            if (it && it.uid && it.username) {
              addSelected(it.uid, it.username);
            }
          }
        } else if (e.key === "Escape") {
          e.preventDefault();
          closeDropdown();
        }
      });

      // blur: закрываем dropdown (но даём mousedown на item сработать)
      input.addEventListener("blur", () => {
        setTimeout(() => closeDropdown(), 150);
      });

      // click outside wrap -> close
      document.addEventListener("mousedown", (e) => {
        if (!wrap.contains(e.target)) {
          closeDropdown();
        }
      });

      // ---- init from existing hidden value ----
      (async function initFromHidden() {
        const csv = String(hidden.value || "").trim();
        if (!csv) {
          renderChips();
          return;
        }

        try {
          const items = await fetchResolve(csv);
          items.forEach((it) => {
            const uid = it && it.uid ? parseInt(it.uid, 10) : 0;
            const username = it && it.username ? String(it.username) : "";
            if (uid > 0 && username) {
              state.selected.set(uid, username);
            }
          });

          // respect max
          if (state.max > 0 && state.selected.size > state.max) {
            const trimmed = new Map();
            let i = 0;
            for (const [uid, username] of state.selected.entries()) {
              trimmed.set(uid, username);
              i++;
              if (i >= state.max) break;
            }
            state.selected = trimmed;
          }

          syncHidden();
          renderChips();
        } catch (e) {
          // если resolve упал — оставим как есть, но не ломаем форму
          renderChips();
        }
      })();
    }
  };

  function boot() {
    AF_ATF.applyHideEditor();
    AF_ATF.initEditSubmitFeedback();
    AF_ATF.initUserChipsAll();
    AF_ATF.initKbSelects();
    AF_ATF.initKbCatalogCtaModal();
    AF_ATF.initPointBuyAll();
    AF_ATF.initCharacterMechanic();
    AF_ATF.initOriginVariantDependency();
    AF_ATF.initDynamicKbPreviews();
    AF_ATF.initCharacterAbilities();
    AF_ATF.initCharacterStats();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }

  // на всякий случай: если страница частично перерисовывается скриптами темы
  window.AF_ATF = window.AF_ATF || AF_ATF;
})();

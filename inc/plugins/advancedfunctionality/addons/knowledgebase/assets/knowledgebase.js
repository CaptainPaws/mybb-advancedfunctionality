(function () {
    function getEditorInstance(field) {
        if (!field) {
            return null;
        }
        if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.sceditor === 'function') {
            try {
                return window.jQuery(field).sceditor('instance');
            } catch (err) {
                return null;
            }
        }
        return null;
    }

    function setFieldValue(field, value) {
        if (!field) {
            return;
        }
        var instance = getEditorInstance(field);
        if (instance) {
            instance.val(value);
            return;
        }
        field.value = value;
    }

    function initEditors(root) {
        if (!window.jQuery || !window.jQuery.fn || typeof window.jQuery.fn.sceditor !== 'function') {
            return;
        }
        var container = root || document;
        var fields = container.querySelectorAll('textarea.af-kb-editor');
        if (!fields.length) {
            return;
        }
        var baseOptions = window.sceditor_options && typeof window.sceditor_options === 'object'
            ? window.sceditor_options
            : {};
        if (!baseOptions.plugins) {
            baseOptions.plugins = 'bbcode';
        }
        if (!baseOptions.toolbar) {
            baseOptions.toolbar = 'bold,italic,underline,strike|left,center,right,justify|bulletlist,orderedlist|link,unlink|image|quote,code';
        }
        if (!baseOptions.style) {
            var defaultStyle = window.sceditor && window.sceditor.defaultOptions ? window.sceditor.defaultOptions.style : '';
            if (defaultStyle) {
                baseOptions.style = defaultStyle;
            }
        }
        fields.forEach(function (field) {
            if (getEditorInstance(field)) {
                field.dataset.afKbEditor = '1';
                return;
            }
            if (field.dataset.afKbEditor === '1') {
                return;
            }
            field.dataset.afKbEditor = '1';
            var options = Object.assign({}, baseOptions, { startInSourceMode: true });
            window.jQuery(field).sceditor(options);
        });
    }

    function initRepeater(containerId, addButtonId, templateId, indexAttr) {
        var container = document.getElementById(containerId);
        var addBtn = document.getElementById(addButtonId);
        var template = document.getElementById(templateId);
        if (!container || !addBtn || !template) {
            return null;
        }

        function currentIndex() {
            return parseInt(container.getAttribute(indexAttr) || '0', 10);
        }

        function bumpIndex() {
            var next = currentIndex() + 1;
            container.setAttribute(indexAttr, String(next));
            return next;
        }

        function addItem() {
            var index = currentIndex();
            var html = template.innerHTML.replace(/__INDEX__/g, String(index));
            var wrapper = document.createElement('div');
            wrapper.innerHTML = html;
            var lastElement = null;
            while (wrapper.firstChild) {
                var node = wrapper.firstChild;
                wrapper.removeChild(node);
                container.appendChild(node);
                if (node.nodeType === 1) {
                    lastElement = node;
                }
            }
            bumpIndex();
            if (lastElement) {
                initEditors(lastElement);
            }
            return lastElement;
        }

        addBtn.addEventListener('click', function () {
            addItem();
        });

        container.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }
            if (target.classList.contains('af-kb-remove')) {
                var item = target.closest('.af-kb-block-item, .af-kb-rel-item-edit');
                if (item && container.contains(item)) {
                    item.remove();
                }
            }
        });

        return {
            addItem: addItem,
            container: container
        };
    }

    function setValue(selector, value) {
        var field = document.querySelector(selector);
        if (!field) {
            return;
        }
        setFieldValue(field, value);
    }

    function setBlockValues(blockElement, data) {
        if (!blockElement) {
            return;
        }
        var fields = {
            block_key: data.block_key || '',
            title_ru: data.title_ru || '',
            title_en: data.title_en || '',
            content_ru: data.content_ru || '',
            content_en: data.content_en || '',
            data_json: data.data_json || '',
            icon_url: data.icon_url || '',
            icon_class: data.icon_class || '',
            sortorder: data.sortorder != null ? String(data.sortorder) : ''
        };
        Object.keys(fields).forEach(function (key) {
            var input = blockElement.querySelector('[name$="[' + key + ']"]');
            if (input) {
                setFieldValue(input, fields[key]);
            }
        });
    }

    function initCopyButtons() {
        document.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }
            if (!target.classList.contains('af-kb-copy-json')) {
                return;
            }
            var payload = target.getAttribute('data-json') || '';
            if (payload === '') {
                return;
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(payload);
                return;
            }
            var temp = document.createElement('textarea');
            temp.value = payload;
            temp.style.position = 'fixed';
            temp.style.opacity = '0';
            document.body.appendChild(temp);
            temp.select();
            try {
                document.execCommand('copy');
            } catch (err) {
                // ignore
            }
            document.body.removeChild(temp);
        });
    }

    function initTechTemplateButtons() {
        document.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }
            if (!target.classList.contains('af-kb-tech-template')) {
                return;
            }
            var fieldName = target.getAttribute('data-target');
            var template = target.getAttribute('data-template') || '';
            if (!fieldName || template === '') {
                return;
            }
            var field = document.querySelector('textarea[name="' + fieldName + '"]');
            if (!field) {
                return;
            }
            var currentValue = '';
            var instance = getEditorInstance(field);
            if (instance) {
                currentValue = instance.val();
            } else {
                currentValue = field.value;
            }
            if (currentValue.trim() === '') {
                setFieldValue(field, template);
                return;
            }
            setFieldValue(field, template + '\n' + currentValue);
        });
    }

    function applyTemplate(blockRepeater) {
        var select = document.getElementById('af-kb-template-select');
        var button = document.getElementById('af-kb-apply-template');
        if (!select || !button || !blockRepeater) {
            return;
        }

        var templates = {
            race: {
                short_en: 'A playable race with distinct traits and culture.',
                body_en: 'Describe history, physical traits, and social role. Mention affinities, weaknesses, and relations with other races.',
                meta_json: JSON.stringify({
                    tags: ['humanoid', 'common'],
                    stats: { str: 0, dex: 0, int: 0 },
                    bonuses: [{ type: 'resistance', value: 'poison' }],
                    links: { wiki: 'https://example.com/wiki/human' }
                }, null, 2),
                blocks: [
                    {
                        block_key: 'bonuses',
                        title_ru: 'Бонусы',
                        title_en: 'Bonuses',
                        content_en: '+2 to social interactions.',
                        data_json: JSON.stringify({
                            grants: ['darkvision'],
                            modifiers: [{ stat: 'dex', value: 2 }]
                        }, null, 2),
                        sortorder: 0
                    },
                    {
                        block_key: 'lore',
                        title_ru: 'Лор',
                        title_en: 'Lore',
                        content_en: 'Short lore snippet about origins and culture.',
                        data_json: '{}',
                        sortorder: 1
                    }
                ]
            },
            class: {
                short_en: 'A combat or role archetype with unique progression.',
                body_en: 'Explain the fantasy, core mechanics, and growth path. Include roles, limits, and gameplay style.',
                meta_json: JSON.stringify({
                    role: 'tank',
                    stats_focus: ['str', 'vit'],
                    starting_skills: ['shield_bash', 'taunt']
                }, null, 2),
                blocks: [
                    {
                        block_key: 'role',
                        title_ru: 'Роль',
                        title_en: 'Role',
                        content_en: 'Frontline defender that protects allies.',
                        data_json: '{}',
                        sortorder: 0
                    },
                    {
                        block_key: 'abilities',
                        title_ru: 'Способности',
                        title_en: 'Abilities',
                        content_en: 'List primary skills and passives.',
                        data_json: JSON.stringify({
                            grants: ['shield_wall'],
                            modifiers: [{ stat: 'def', value: 3 }]
                        }, null, 2),
                        sortorder: 1
                    },
                    {
                        block_key: 'progression',
                        title_ru: 'Прогрессия',
                        title_en: 'Progression',
                        content_en: 'Describe milestones and upgrades.',
                        data_json: '{}',
                        sortorder: 2
                    }
                ]
            },
            skill: {
                short_en: 'A skill with ranks, costs, and effects.',
                body_en: 'Describe activation, cooldowns, and tactical usage.',
                meta_json: JSON.stringify({
                    skill: {
                        category: 'combat',
                        rank_max: 5,
                        cooldown: 2,
                        cost: { mana: 10 },
                        effects: [{ type: 'damage', value: '2d6', damage_type: 'fire' }],
                        requirements: { level: 3, tags_any: ['pyromancer'] }
                    }
                }, null, 2),
                blocks: [
                    {
                        block_key: 'bonuses',
                        title_ru: 'Бонусы',
                        title_en: 'Bonuses',
                        content_en: '+1 damage per rank.',
                        data_json: JSON.stringify({
                            modifiers: [{ stat: 'damage', value: 1 }]
                        }, null, 2),
                        sortorder: 0
                    },
                    {
                        block_key: 'rules',
                        title_ru: 'Правила',
                        title_en: 'Rules',
                        content_en: 'Cooldown: 2 turns. Cost: 10 mana.',
                        data_json: '{}',
                        sortorder: 1
                    },
                    {
                        block_key: 'examples',
                        title_ru: 'Примеры',
                        title_en: 'Examples',
                        content_en: 'Use against clustered enemies.',
                        data_json: '{}',
                        sortorder: 2
                    }
                ]
            }
        };

        button.addEventListener('click', function () {
            var key = select.value;
            if (!key || !templates[key]) {
                return;
            }
            var preset = templates[key];
            setValue('textarea[name="short_en"]', preset.short_en);
            setValue('textarea[name="body_en"]', preset.body_en);
            setValue('textarea[name="meta_json"]', preset.meta_json);

            preset.blocks.forEach(function (block) {
                var blockElement = blockRepeater.addItem();
                setBlockValues(blockElement, block);
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var blockRepeater = initRepeater('af-kb-blocks', 'af-kb-add-block', 'af-kb-block-template', 'data-index');
        initRepeater('af-kb-relations', 'af-kb-add-relation', 'af-kb-relation-template', 'data-index');
        applyTemplate(blockRepeater);
        initEditors(document);
        initCopyButtons();
        initTechTemplateButtons();
    });
})();

(function () {
    function readJson(text, fallback) {
        try {
            var parsed = JSON.parse(text || '');
            return parsed && typeof parsed === 'object' ? parsed : fallback;
        } catch (e) {
            return fallback;
        }
    }

    function esc(value) {
        return String(value || '').replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch] || ch;
        });
    }

    function splitLines(value) {
        return String(value || '').split(/\n+/).map(function (item) { return item.trim(); }).filter(Boolean);
    }

    function deepGet(obj, path) {
        var parts = String(path || '').split('.');
        var ref = obj;
        for (var i = 0; i < parts.length; i++) {
            if (!parts[i]) {
                continue;
            }
            if (!ref || typeof ref !== 'object' || !(parts[i] in ref)) {
                return undefined;
            }
            ref = ref[parts[i]];
        }
        return ref;
    }

    function deepSet(obj, path, value) {
        var parts = String(path || '').split('.');
        var ref = obj;
        for (var i = 0; i < parts.length - 1; i++) {
            if (!parts[i]) {
                continue;
            }
            if (!ref[parts[i]] || typeof ref[parts[i]] !== 'object') {
                ref[parts[i]] = {};
            }
            ref = ref[parts[i]];
        }
        ref[parts[parts.length - 1]] = value;
    }

    function defaultRawForSchema(typeSchema, type) {
        var defaults = typeSchema.defaults && typeof typeSchema.defaults === 'object' ? JSON.parse(JSON.stringify(typeSchema.defaults)) : {};
        if (!defaults.schema) {
            defaults.schema = 'af_kb.rules.v1';
        }
        if (!defaults.type_profile) {
            defaults.type_profile = type;
        }
        if (!defaults.version) {
            defaults.version = '1.0';
        }
        return defaults;
    }

    function validateRules(data, type, typeSchema) {
        var errors = [];
        if (typeSchema.rules_enabled === false) {
            return errors;
        }
        if (String(data.schema || '') !== 'af_kb.rules.v1') {
            errors.push('schema должен быть af_kb.rules.v1');
        }
        if (String(data.type_profile || '') !== String(type || '')) {
            errors.push('type_profile должен совпадать с type записи');
        }
        var required = Array.isArray(typeSchema.rules_required_keys) ? typeSchema.rules_required_keys : [];
        required.forEach(function (key) {
            if (!(key in data)) {
                errors.push('Отсутствует обязательное поле: ' + key);
            }
        });
        return errors;
    }

    function createInputRow(label, hint) {
        var wrap = document.createElement('div');
        wrap.className = 'af-kb-rule-row';
        var title = document.createElement('label');
        title.textContent = label;
        wrap.appendChild(title);
        if (hint) {
            var help = document.createElement('div');
            help.className = 'af-kb-help';
            help.textContent = hint;
            wrap.appendChild(help);
        }
        return wrap;
    }

    function createJsonEditor(root, state, onChange) {
        var ta = document.createElement('textarea');
        ta.value = JSON.stringify(state.raw, null, 2);
        ta.addEventListener('input', function () {
            try {
                state.raw = readJson(ta.value, state.raw);
                onChange();
            } catch (e) {}
        });
        root.appendChild(ta);
        return ta;
    }

    function buildProfileSections(uiRoot, state, typeSchema, onChange) {
        var profile = typeSchema.ui_profile || state.type;
        var section = document.createElement('div');
        section.className = 'af-kb-rule-card';
        var title = document.createElement('h4');
        title.textContent = 'UI profile: ' + profile;
        section.appendChild(title);

        var profileMap = {
            race: [
                { path: 'size', label: 'size', hint: 'Размер (rules.size)' },
                { path: 'creature_type', label: 'creature_type', hint: 'Тип существа (rules.creature_type)' },
                { path: 'speed', label: 'speed', hint: 'Базовая скорость (rules.speed)' },
                { path: 'hp_base', label: 'hp_base', hint: 'Базовое HP (rules.hp_base)' },
                { path: 'languages', label: 'languages', hint: 'Список языков (rules.languages)' }
            ],
            class: [
                { path: 'hp_per_level', label: 'hp_per_level', hint: 'HP за уровень (rules.hp_per_level)' },
                { path: 'key_ability', label: 'key_ability', hint: 'Ключевая характеристика (rules.key_ability)' },
                { path: 'proficiencies', label: 'proficiencies', hint: 'Базовые профы (rules.proficiencies)' },
                { path: 'progression', label: 'progression', hint: 'Прогрессия по уровням (rules.progression)' }
            ],
            theme: [
                { path: 'fixed', label: 'fixed', hint: 'Фиксированные бонусы (rules.fixed)' },
                { path: 'grants', label: 'grants', hint: 'Автовыдачи (rules.grants)' },
                { path: 'choices', label: 'choices', hint: 'Выборы (rules.choices)' }
            ],
            skill: [
                { path: 'skill.attribute', label: 'attribute', hint: 'Ключевой атрибут (rules.skill.attribute)' },
                { path: 'skill.rank_mode', label: 'rank_mode', hint: 'binary|ranked (rules.skill.rank_mode)' },
                { path: 'skill.max_rank', label: 'max_rank', hint: 'Макс. ранг (rules.skill.max_rank)' },
                { path: 'skill.rank_bonus', label: 'rank_bonus', hint: 'Бонус за ранг (rules.skill.rank_bonus)' },
                { path: 'skill.can_buy_rank', label: 'can_buy_rank', hint: 'Можно покупать ранги (rules.skill.can_buy_rank)' }
            ],
            knowledge: [
                { path: 'knowledge_group', label: 'knowledge_group', hint: 'Группа знаний (rules.knowledge_group)' },
                { path: 'skill.attribute', label: 'attribute', hint: 'Ключевой атрибут (rules.skill.attribute)' },
                { path: 'skill.rank_mode', label: 'rank_mode', hint: 'Режим рангов (rules.skill.rank_mode)' }
            ],
            language: [
                { path: 'script', label: 'script', hint: 'Письменность (rules.script)' },
                { path: 'rarity', label: 'rarity', hint: 'common/uncommon/rare (rules.rarity)' },
                { path: 'family', label: 'family', hint: 'Семья языка (rules.family)' },
                { path: 'requires', label: 'requires', hint: 'Требования KB refs (rules.requires)' }
            ],
            spell: [
                { path: 'spell.rank', label: 'rank', hint: 'Ранг (rules.spell.rank)' },
                { path: 'spell.tradition', label: 'tradition', hint: 'Традиция (rules.spell.tradition)' },
                { path: 'spell.casting_time', label: 'casting_time', hint: 'Время каста (rules.spell.casting_time)' },
                { path: 'effects', label: 'effects', hint: 'Эффекты (rules.effects)' }
            ],
            item: [
                { path: 'item_kind', label: 'item_kind', hint: 'weapon/armor/consumable/gear/cyberware (rules.item_kind)' },
                { path: 'price', label: 'price', hint: 'Цена (rules.price)' },
                { path: 'weight', label: 'weight', hint: 'Вес (rules.weight)' },
                { path: 'on_equip', label: 'on_equip', hint: 'Эффекты при экипировке (rules.on_equip)' },
                { path: 'on_use', label: 'on_use', hint: 'Эффекты при использовании (rules.on_use)' }
            ],
            condition: [
                { path: 'condition.severity', label: 'severity', hint: 'Тяжесть (rules.condition.severity)' },
                { path: 'condition.duration_default', label: 'duration_default', hint: 'Длительность по умолчанию' },
                { path: 'condition.stacking', label: 'stacking', hint: 'none/refresh/stack' },
                { path: 'condition.effects', label: 'effects', hint: 'Эффекты состояния' }
            ],
            perk: [
                { path: 'tier', label: 'tier', hint: 'Тир перка (rules.tier)' },
                { path: 'level_req', label: 'level_req', hint: 'Требуемый уровень (rules.level_req)' },
                { path: 'prereq', label: 'prereq', hint: 'Требования (rules.prereq)' },
                { path: 'effects', label: 'effects', hint: 'Эффекты (rules.effects)' }
            ],
            faction: [
                { path: 'starting_rep', label: 'starting_rep', hint: 'Стартовая репутация' },
                { path: 'rep_gain', label: 'rep_gain', hint: 'Прирост репутации' },
                { path: 'faction_perks', label: 'faction_perks', hint: 'Перки по репе' }
            ],
            lore: []
        };

        var fields = profileMap[profile] || [];
        if (!fields.length) {
            section.insertAdjacentHTML('beforeend', '<div class="af-kb-help">Для этого профиля используйте raw JSON.</div>');
            uiRoot.appendChild(section);
            return;
        }

        fields.forEach(function (fieldDef) {
            var row = createInputRow(fieldDef.label, fieldDef.hint);
            var currentValue = deepGet(state.raw, fieldDef.path);
            var input = document.createElement('textarea');
            if (typeof currentValue === 'string') {
                input.value = currentValue;
            } else if (Array.isArray(currentValue)) {
                input.value = currentValue.join('\n');
            } else {
                input.value = JSON.stringify(currentValue != null ? currentValue : '', null, 2);
            }
            input.addEventListener('input', function () {
                var next = input.value;
                if (/^\s*[\[{]/.test(next)) {
                    next = readJson(next, currentValue);
                } else if (next === 'true' || next === 'false') {
                    next = next === 'true';
                } else if (next !== '' && !isNaN(Number(next))) {
                    next = Number(next);
                } else if (next.indexOf('\n') !== -1) {
                    next = splitLines(next);
                }
                deepSet(state.raw, fieldDef.path, next);
                onChange();
            });
            row.appendChild(input);
            section.appendChild(row);
        });

        uiRoot.appendChild(section);
    }

    function initDataUi() {
        var root = document.getElementById('af-kb-data-ui');
        var hidden = document.getElementById('af-kb-data-json');
        var raw = document.getElementById('af-kb-data-json-raw');
        if (!root || !hidden || !raw) {
            return;
        }

        var type = (root.getAttribute('data-type') || '').trim();
        var typeSchema = readJson(root.getAttribute('data-type-schema') || '{}', {});
        var parsedRaw = readJson(raw.value || '{}', {});
        var state = {
            type: type,
            raw: Object.assign(defaultRawForSchema(typeSchema, type), parsedRaw)
        };

        var wrapper = document.createElement('div');
        wrapper.className = 'af-kb-rules-wrap';
        wrapper.innerHTML = [
            '<div class="af-kb-rule-actions">',
            '<button type="button" id="kb-build-raw">Собрать Raw из UI</button>',
            '<button type="button" id="kb-parse-ui">Разобрать UI из Raw</button>',
            '<button type="button" id="kb-insert-example">Вставить пример (по профилю)</button>',
            '<button type="button" id="kb-validate-json">Валидировать JSON</button>',
            '<button type="button" id="kb-reset-defaults">Сбросить к дефолту профиля</button>',
            '</div>',
            '<div id="kb-rules-errors" class="af-kb-errors"></div>',
            '<div id="kb-ui-profile"></div>'
        ].join('');
        root.innerHTML = '';
        root.appendChild(wrapper);

        var errorsNode = wrapper.querySelector('#kb-rules-errors');
        var profileNode = wrapper.querySelector('#kb-ui-profile');

        function syncRawArea() {
            raw.value = JSON.stringify(state.raw, null, 2);
            hidden.value = raw.value;
        }

        function renderUi() {
            profileNode.innerHTML = '';
            buildProfileSections(profileNode, state, typeSchema, syncRawArea);
            syncRawArea();
        }

        function showErrors(errors) {
            if (!errors.length) {
                errorsNode.innerHTML = '';
                return;
            }
            errorsNode.innerHTML = '<div class="af-kb-help" style="color:#c62828">' + errors.map(esc).join('<br>') + '</div>';
        }

        wrapper.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }
            if (target.id === 'kb-build-raw') {
                syncRawArea();
                showErrors([]);
                return;
            }
            if (target.id === 'kb-parse-ui') {
                state.raw = readJson(raw.value || '{}', state.raw);
                renderUi();
                showErrors([]);
                return;
            }
            if (target.id === 'kb-insert-example') {
                state.raw = defaultRawForSchema(typeSchema, type);
                renderUi();
                showErrors([]);
                return;
            }
            if (target.id === 'kb-reset-defaults') {
                state.raw = defaultRawForSchema(typeSchema, type);
                renderUi();
                showErrors([]);
                return;
            }
            if (target.id === 'kb-validate-json') {
                var data = readJson(raw.value || '{}', {});
                var errors = validateRules(data, type, typeSchema);
                showErrors(errors);
            }
        });

        raw.addEventListener('input', function () {
            hidden.value = raw.value || '{}';
        });

        renderUi();
    }

    document.addEventListener('DOMContentLoaded', function () {
        initMetaUi();
        initDataUi();
    });
})();

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

    function numberOrZero(value) {
        var n = Number(value);
        return Number.isFinite(n) ? n : 0;
    }

    function splitCsv(value) {
        return String(value || '').split(',').map(function (item) { return item.trim(); }).filter(Boolean);
    }

    function splitLines(value) {
        return String(value || '').split(/\n+/).map(function (item) { return item.trim(); }).filter(Boolean);
    }

    function debounce(fn, delay) {
        var timer = null;
        return function () {
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(null, args); }, delay);
        };
    }

    function esc(value) {
        return String(value || '').replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch] || ch;
        });
    }

    var stats = ['str', 'dex', 'con', 'int', 'wis', 'cha'];
    var rankOptions = ['trained', 'expert', 'master', 'legendary', '0', '1', '2', '3', '4'];
    var kbTypes = ['skill', 'language', 'perk', 'item', 'spell', 'condition', 'trait', 'proficiency', 'weapon', 'armor'];
    var slugPattern = /^[a-z0-9_\-:.]+$/;

    function initMetaUi() {
        var root = document.getElementById('af-kb-meta-ui');
        var raw = document.getElementById('af-kb-meta-json');
        if (!root || !raw) {
            return;
        }

        var meta = readJson(raw.value, {});
        if (!meta.ui || typeof meta.ui !== 'object') {
            meta.ui = {};
        }
        if (!Array.isArray(meta.tags)) {
            meta.tags = [];
        }
        if (!meta.links || typeof meta.links !== 'object') {
            meta.links = {};
        }

        root.innerHTML = [
            '<div class="af-kb-row"><div><label>Tags (через запятую)</label><input type="text" id="af-kb-meta-tags" /></div><div><label>Wiki link</label><input type="url" id="af-kb-meta-wiki" /></div></div>',
            '<div class="af-kb-row"><div><label>Icon URL</label><input type="url" id="af-kb-meta-icon-url" /></div><div><label>Icon class</label><input type="text" id="af-kb-meta-icon-class" /></div></div>',
            '<div class="af-kb-row"><div><label>Background URL</label><input type="url" id="af-kb-meta-bg-url" /></div><div><label>Background tab URL</label><input type="url" id="af-kb-meta-bg-tab-url" /></div></div>'
        ].join('');

        var fields = {
            tags: root.querySelector('#af-kb-meta-tags'),
            wiki: root.querySelector('#af-kb-meta-wiki'),
            iconUrl: root.querySelector('#af-kb-meta-icon-url'),
            iconClass: root.querySelector('#af-kb-meta-icon-class'),
            bgUrl: root.querySelector('#af-kb-meta-bg-url'),
            bgTabUrl: root.querySelector('#af-kb-meta-bg-tab-url')
        };

        fields.tags.value = (meta.tags || []).join(', ');
        fields.wiki.value = meta.links.wiki || '';
        fields.iconUrl.value = meta.ui.icon_url || '';
        fields.iconClass.value = meta.ui.icon_class || '';
        fields.bgUrl.value = meta.ui.background_url || '';
        fields.bgTabUrl.value = meta.background_tab_url || meta.ui.background_tab_url || '';

        function syncMeta() {
            meta.tags = splitCsv(fields.tags.value);
            meta.links.wiki = fields.wiki.value.trim();
            meta.ui.icon_url = fields.iconUrl.value.trim();
            meta.ui.icon_class = fields.iconClass.value.trim();
            meta.ui.background_url = fields.bgUrl.value.trim();
            meta.ui.background_tab_url = fields.bgTabUrl.value.trim();
            meta.background_tab_url = fields.bgTabUrl.value.trim();
            raw.value = JSON.stringify(meta, null, 2);
        }

        Object.keys(fields).forEach(function (key) {
            fields[key].addEventListener('input', syncMeta);
        });
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
        var rulesEditorEnabled = typeSchema.ui_rules_editor !== false;

        function bindRawOnlyMode(message) {
            root.innerHTML = '<div class="af-kb-help">' + esc(message) + '</div>';
            hidden.value = raw.value || '{}';
            raw.addEventListener('input', function () { hidden.value = raw.value || '{}'; });
        }

        if (!rulesEditorEnabled) {
            bindRawOnlyMode('Для этого типа rules_json не используется. Доступен только raw-режим (advanced).');
            return;
        }

        if (type !== 'race') {
            bindRawOnlyMode('Универсальный режим rules_json: используйте raw JSON (advanced) или шаблон ниже.');
            return;
        }

        var parsedRaw = readJson(raw.value || '{}', {});
        var state = {
            schema: 'af_kb.rules.v1',
            size: parsedRaw.size || 'medium',
            creature_type: parsedRaw.creature_type || 'humanoid',
            speed: numberOrZero(parsedRaw.speed || 30),
            hp_base: numberOrZero(parsedRaw.hp_base || 10),
            fixed_bonuses: Object.assign({
                stats: { str: 0, dex: 0, con: 0, int: 0, wis: 0, cha: 0 },
                hp: 0,
                ep: 0,
                skill_points: 0,
                feat_points: 0,
                perk_points: 0,
                language_slots: 0
            }, parsedRaw.fixed_bonuses || {}),
            choices: Array.isArray(parsedRaw.choices) ? parsedRaw.choices.slice() : [],
            grants: Array.isArray(parsedRaw.grants) ? parsedRaw.grants.slice() : [],
            traits: Array.isArray(parsedRaw.traits) ? parsedRaw.traits.slice() : [],
            visibility: parsedRaw.visibility || 'technical',
            is_technical: true
        };
        state.fixed_bonuses.stats = (function (src) {
            var out = {};
            stats.forEach(function (key) { out[key] = numberOrZero(src && src[key]); });
            return out;
        })(state.fixed_bonuses.stats);

        var syncError = '';
        var rawError = '';

        var templates = {
            stat_bonus_choice: { type: 'stat_bonus_choice', id: 'boost_1', pick: 1, options: stats.slice(), value: 2, mode: 'add', exclude: [] },
            skill_pick_choice: { type: 'skill_pick_choice', id: 'skills_pick', pick: 2, options: [], exclude: [], grant_mode: 'rank', rank_value: 1, points_value: 2 },
            language_pick_choice: { type: 'language_pick_choice', id: 'lang_pick', pick: 1, exclude: ['common'], allow_custom: false, value: 1 },
            proficiency_pick_choice: { type: 'proficiency_pick_choice', id: 'prof_pick', pick: 1, prof_type: 'weapon', options: [], rank: 'trained', exclude: [] },
            feat_pick_choice: { type: 'feat_pick_choice', id: 'perk_pick', pick: 1, kb_type: 'perk', tag_filter: [], exclude: [] },
            equipment_pick_choice: { type: 'equipment_pick_choice', id: 'item_pick', pick: 1, kb_type: 'item', options: [], exclude: [], quantity: 1, grant: { type: 'item_grant', qty: 1 } },
            spell_pick_choice: { type: 'spell_pick_choice', id: 'spell_pick', pick: 1, kb_type: 'spell', tradition: '', school: '', level_min: 0, level_max: 1, grant: { type: 'spell_known', amount: 1 } },
            kb_pick_choice: { type: 'kb_pick_choice', id: 'kb_pick', kb_type: 'skill', pick: 1, options: [], exclude: [], grant: { type: 'kb_grant', amount: 1 } },
            skill_rank: { type: 'skill_rank', kb_type: 'skill', kb_key: 'athletics', rank: 'trained', mode: 'max' },
            resource_gain: { type: 'resource_gain', resource: 'skill_points', value: 2, stack_mode: 'add' },
            item_grant: { type: 'item_grant', kb_key: 'starter_kit', qty: 1, bind: false, equipped: false, slot: '' },
            resistance_grant: { type: 'resistance_grant', damage_type: 'fire', value: 5 },
            sense_grant: { type: 'sense_grant', sense_type: 'darkvision', range: 60 },
            speed_grant: { type: 'speed_grant', speed_type: 'swim', value: 20, condition: '' },
            trait: { key: 'humanoid', title_ru: 'Гуманоид', title_en: 'Humanoid', desc_ru: '', desc_en: '', tags: ['species'], meta: {} }
        };

        function normalizeChoice(choice) {
            var out = choice && typeof choice === 'object' ? JSON.parse(JSON.stringify(choice)) : {};
            if (out.type === 'stat_bonus') {
                out.type = 'stat_bonus_choice';
            }
            if (out.type === 'kb_pick') {
                out.type = 'kb_pick_choice';
            }
            if (out.type === 'language_pick') {
                out.type = 'language_pick_choice';
            }
            return out;
        }
        state.choices = state.choices.map(normalizeChoice);

        var choiceDefs = [
            { key: 'stat_bonus_choice', label: 'Stat bonus choice', desc: 'Выбор атрибутов + бонус', fields: [
                { name: 'id', type: 'text', required: true, hint: 'Уникальный ключ выбора', help: 'Используется для сохранения выбора персонажа.' },
                { name: 'pick', type: 'number', required: true, hint: 'Сколько вариантов выбрать' },
                { name: 'options', type: 'lines', hint: 'Какие статы доступны (по одному в строке)' },
                { name: 'value', type: 'number', required: true, hint: 'Размер бонуса за выбор' },
                { name: 'mode', type: 'select', options: ['add', 'set'], hint: 'Как применять бонус' },
                { name: 'exclude', type: 'lines', hint: 'Список запрещённых ключей' }
            ] },
            { key: 'kb_pick_choice', label: 'KB pick choice', desc: 'Универсальный выбор из KB', fields: [
                { name: 'id', type: 'text', required: true, hint: 'Уникальный ключ выбора' },
                { name: 'kb_type', type: 'select', options: kbTypes, required: true, hint: 'Каталог KB для выбора' },
                { name: 'pick', type: 'number', required: true, hint: 'Сколько элементов выбрать' },
                { name: 'options', type: 'lines', hint: 'Ограничить только этими key' },
                { name: 'exclude', type: 'lines', hint: 'Исключить key' },
                { name: 'grant', type: 'json', hint: 'Что выдать за каждый выбранный объект' }
            ] },
            { key: 'language_pick_choice', label: 'Language pick', desc: 'Выбор языков', fields: [
                { name: 'id', type: 'text', required: true, hint: 'Уникальный ключ выбора' },
                { name: 'pick', type: 'number', required: true, hint: 'Сколько языков выбрать' },
                { name: 'exclude', type: 'lines', hint: 'Исключить языки (например common)' },
                { name: 'allow_custom', type: 'checkbox', hint: 'Разрешить ручной ввод языка' },
                { name: 'value', type: 'number', hint: 'Сколько языков даётся за один выбор' }
            ] },
            { key: 'skill_pick_choice', label: 'Skill pick', desc: 'Частый кейс выбора навыков', fields: [
                { name: 'id', type: 'text', required: true, hint: 'Уникальный ключ выбора' },
                { name: 'pick', type: 'number', required: true, hint: 'Сколько навыков выбрать' },
                { name: 'options', type: 'lines', hint: 'Ограничить доступные навыки' },
                { name: 'exclude', type: 'lines', hint: 'Запретить навыки' },
                { name: 'grant_mode', type: 'select', options: ['rank', 'skill_points'], hint: 'Что начислять: ранг или очки навыков' },
                { name: 'rank_value', type: 'number', hint: 'Ранг при grant_mode=rank' },
                { name: 'points_value', type: 'number', hint: 'Очки при grant_mode=skill_points' }
            ] },
            { key: 'proficiency_pick_choice', label: 'Proficiency pick', desc: 'Выбор категории владения', fields: [
                { name: 'id', type: 'text', required: true, hint: 'Уникальный ключ выбора' },
                { name: 'pick', type: 'number', required: true, hint: 'Сколько выбрать' },
                { name: 'prof_type', type: 'select', options: ['weapon', 'armor', 'tool', 'save', 'skill'], hint: 'Тип профы' },
                { name: 'options', type: 'lines', hint: 'Разрешённые категории' },
                { name: 'rank', type: 'select', options: rankOptions, hint: 'Какой ранг дать' },
                { name: 'exclude', type: 'lines', hint: 'Исключения' }
            ] },
            { key: 'feat_pick_choice', label: 'Feat/perk pick', desc: 'Выбор фита/перка', fields: [
                { name: 'id', type: 'text', required: true, hint: 'Уникальный ключ выбора' },
                { name: 'pick', type: 'number', required: true, hint: 'Сколько выбрать' },
                { name: 'kb_type', type: 'fixed', value: 'perk', hint: 'Всегда perk' },
                { name: 'tag_filter', type: 'lines', hint: 'Фильтр по тегам' },
                { name: 'exclude', type: 'lines', hint: 'Исключить key' }
            ] },
            { key: 'equipment_pick_choice', label: 'Equipment pick', desc: 'Выбор стартового предмета', fields: [
                { name: 'id', type: 'text', required: true, hint: 'Уникальный ключ выбора' },
                { name: 'pick', type: 'number', required: true, hint: 'Сколько выбрать' },
                { name: 'kb_type', type: 'fixed', value: 'item', hint: 'Всегда item' },
                { name: 'options', type: 'lines', hint: 'Разрешённые предметы' },
                { name: 'exclude', type: 'lines', hint: 'Исключить предметы' },
                { name: 'quantity', type: 'number', hint: 'Количество по умолчанию' },
                { name: 'grant', type: 'json', hint: 'item_grant блок' }
            ] },
            { key: 'spell_pick_choice', label: 'Spell pick', desc: 'Выбор заклинаний', fields: [
                { name: 'id', type: 'text', required: true, hint: 'Уникальный ключ выбора' },
                { name: 'pick', type: 'number', required: true, hint: 'Сколько выбрать' },
                { name: 'kb_type', type: 'fixed', value: 'spell', hint: 'Всегда spell' },
                { name: 'tradition', type: 'text', hint: 'Фильтр tradition' },
                { name: 'school', type: 'text', hint: 'Фильтр school' },
                { name: 'level_min', type: 'number', hint: 'Минимальный уровень' },
                { name: 'level_max', type: 'number', hint: 'Максимальный уровень' },
                { name: 'grant', type: 'json', hint: 'spell_known блок' }
            ] }
        ];

        var grantDefs = [
            { key: 'resource_gain', label: 'Resource gain', fields: [
                { name: 'resource', type: 'select', options: ['hp', 'ep', 'skill_points', 'feat_points', 'perk_points', 'language_slots'] },
                { name: 'value', type: 'number', required: true },
                { name: 'stack_mode', type: 'select', options: ['add', 'set'] }
            ] },
            { key: 'skill_rank', label: 'Skill rank', fields: [
                { name: 'kb_type', type: 'fixed', value: 'skill' },
                { name: 'kb_key', type: 'kb_key', kbTypeField: 'kb_type', required: true },
                { name: 'rank', type: 'select', options: rankOptions },
                { name: 'mode', type: 'select', options: ['set', 'max', 'add'] }
            ] },
            { key: 'proficiency_grant', label: 'Proficiency grant', fields: [
                { name: 'prof_type', type: 'select', options: ['weapon', 'armor', 'tool', 'save', 'skill'] },
                { name: 'target_key', type: 'text', required: true },
                { name: 'rank', type: 'select', options: rankOptions },
                { name: 'mode', type: 'select', options: ['set', 'max', 'add'] }
            ] },
            { key: 'kb_grant', label: 'KB grant', fields: [
                { name: 'kb_type', type: 'select', options: kbTypes },
                { name: 'kb_key', type: 'kb_key', kbTypeField: 'kb_type', required: true },
                { name: 'amount', type: 'number' },
                { name: 'flags', type: 'json' }
            ] },
            { key: 'item_grant', label: 'Item grant', fields: [
                { name: 'kb_type', type: 'fixed', value: 'item' },
                { name: 'kb_key', type: 'kb_key', kbTypeField: 'kb_type', required: true },
                { name: 'qty', type: 'number' },
                { name: 'bind', type: 'checkbox' },
                { name: 'equipped', type: 'checkbox' },
                { name: 'slot', type: 'text' }
            ] },
            { key: 'condition_grant', label: 'Condition grant', fields: [
                { name: 'kb_type', type: 'fixed', value: 'condition' },
                { name: 'kb_key', type: 'kb_key', kbTypeField: 'kb_type', required: true },
                { name: 'duration', type: 'text' },
                { name: 'stacks', type: 'number' },
                { name: 'intensity', type: 'number' }
            ] },
            { key: 'resistance_grant', label: 'Resistance grant', fields: [
                { name: 'damage_type', type: 'text', required: true },
                { name: 'value', type: 'number', required: true }
            ] },
            { key: 'weakness_grant', label: 'Weakness grant', fields: [
                { name: 'damage_type', type: 'text', required: true },
                { name: 'value', type: 'number', required: true }
            ] },
            { key: 'speed_grant', label: 'Speed grant', fields: [
                { name: 'speed_type', type: 'select', options: ['walk', 'fly', 'swim', 'climb', 'burrow'] },
                { name: 'value', type: 'number', required: true },
                { name: 'condition', type: 'text' }
            ] },
            { key: 'sense_grant', label: 'Sense grant', fields: [
                { name: 'sense_type', type: 'text', required: true },
                { name: 'range', type: 'number' }
            ] }
        ];

        var traitFields = [
            { name: 'key', type: 'text', required: true, hint: 'Ключ особенности (slug)' },
            { name: 'title_ru', type: 'text' },
            { name: 'title_en', type: 'text' },
            { name: 'desc_ru', type: 'textarea' },
            { name: 'desc_en', type: 'textarea' },
            { name: 'tags', type: 'lines' },
            { name: 'meta', type: 'json' }
        ];

        root.innerHTML = [
            '<div id="kb-rules-errors" class="af-kb-errors"></div>',
            '<div class="af-kb-row"><div><label>Size</label><select id="kb-size"><option>tiny</option><option>small</option><option>medium</option><option>large</option><option>huge</option></select></div><div><label>Creature type</label><input type="text" id="kb-creature" /></div></div>',
            '<div class="af-kb-row"><div><label>Speed (base walk)</label><input type="number" id="kb-speed" /></div><div><label>HP base</label><input type="number" id="kb-hp-base" /></div></div>',
            '<details open="open" class="af-kb-collapsible"><summary>Fixed bonuses</summary><div id="kb-fixed-bonuses"></div></details>',
            '<details open="open" class="af-kb-collapsible"><summary>Choices</summary><div class="af-kb-inline"><button type="button" class="af-kb-add" data-add-choice="stat_bonus_choice">+2 к одному атрибуту</button><button type="button" class="af-kb-add" data-add-choice="skill_pick_choice">2 навыка trained</button><button type="button" class="af-kb-add" data-add-choice="language_pick_choice">1 язык (кроме common)</button><button type="button" class="af-kb-add" data-add-choice="kb_pick_choice">KB pick</button><button type="button" class="af-kb-add" data-add-choice="proficiency_pick_choice">Proficiency pick</button><button type="button" class="af-kb-add" data-add-choice="feat_pick_choice">Feat/perk pick</button><button type="button" class="af-kb-add" data-add-choice="equipment_pick_choice">Equipment pick</button><button type="button" class="af-kb-add" data-add-choice="spell_pick_choice">Spell pick</button></div><div id="kb-choices-list"></div></details>',
            '<details open="open" class="af-kb-collapsible"><summary>Grants</summary><div class="af-kb-inline"><button type="button" class="af-kb-add" data-add-grant="resource_gain">Выдать 2 skill_points</button><button type="button" class="af-kb-add" data-add-grant="skill_rank">Фиксированный навык trained</button><button type="button" class="af-kb-add" data-add-grant="item_grant">Стартовый предмет x1</button><button type="button" class="af-kb-add" data-add-grant="resistance_grant">Сопротивление огню 5</button><button type="button" class="af-kb-add" data-add-grant="sense_grant">Darkvision</button><button type="button" class="af-kb-add" data-add-grant="speed_grant">Скорость плавания 20</button></div><div id="kb-grants-list"></div></details>',
            '<details open="open" class="af-kb-collapsible"><summary>Traits</summary><div class="af-kb-inline"><button type="button" class="af-kb-add" id="kb-add-trait">Добавить trait</button><button type="button" class="af-kb-add" id="kb-add-trait-example">Вставить пример trait</button></div><div id="kb-traits-list"></div></details>',
            '<details class="af-kb-collapsible"><summary>Raw sync</summary><div class="af-kb-inline"><button type="button" class="af-kb-add" id="kb-sync-from-raw">Синхронизировать из raw</button><span class="af-kb-help">Raw остаётся source-of-truth и всегда доступен.</span></div><div id="kb-raw-error" class="af-kb-help"></div></details>'
        ].join('');

        var fields = {
            size: root.querySelector('#kb-size'),
            creature: root.querySelector('#kb-creature'),
            speed: root.querySelector('#kb-speed'),
            hpBase: root.querySelector('#kb-hp-base'),
            fixed: root.querySelector('#kb-fixed-bonuses'),
            choices: root.querySelector('#kb-choices-list'),
            grants: root.querySelector('#kb-grants-list'),
            traits: root.querySelector('#kb-traits-list'),
            errors: root.querySelector('#kb-rules-errors'),
            rawError: root.querySelector('#kb-raw-error')
        };

        fields.size.value = state.size;
        fields.creature.value = state.creature_type;
        fields.speed.value = state.speed;
        fields.hpBase.value = state.hp_base;

        function getDef(defs, key) {
            for (var i = 0; i < defs.length; i += 1) {
                if (defs[i].key === key) {
                    return defs[i];
                }
            }
            return null;
        }

        function createInput(def, obj, onChange) {
            var wrap = document.createElement('div');
            var label = document.createElement('label');
            label.textContent = def.name;
            wrap.appendChild(label);
            var input;
            var value = obj[def.name];
            if (def.type === 'textarea') {
                input = document.createElement('textarea');
                input.value = value || '';
            } else if (def.type === 'lines') {
                input = document.createElement('textarea');
                input.value = Array.isArray(value) ? value.join('\n') : (value || '');
            } else if (def.type === 'json') {
                input = document.createElement('textarea');
                input.value = JSON.stringify(value || {}, null, 2);
            } else if (def.type === 'number') {
                input = document.createElement('input');
                input.type = 'number';
                input.value = value != null ? String(value) : '0';
            } else if (def.type === 'select') {
                input = document.createElement('select');
                (def.options || []).forEach(function (opt) {
                    var option = document.createElement('option');
                    option.value = String(opt);
                    option.textContent = String(opt);
                    input.appendChild(option);
                });
                input.value = value != null ? String(value) : String((def.options && def.options[0]) || '');
            } else if (def.type === 'checkbox') {
                input = document.createElement('input');
                input.type = 'checkbox';
                input.checked = !!value;
            } else if (def.type === 'fixed') {
                input = document.createElement('input');
                input.type = 'text';
                input.value = def.value || '';
                input.readOnly = true;
                obj[def.name] = def.value;
            } else {
                input = document.createElement('input');
                input.type = 'text';
                input.value = value || '';
                if (def.type === 'kb_key') {
                    var datalistId = 'kb-list-' + Math.random().toString(16).slice(2);
                    var list = document.createElement('datalist');
                    list.id = datalistId;
                    input.setAttribute('list', datalistId);
                    wrap.appendChild(list);
                    var typeName = def.kbTypeField ? obj[def.kbTypeField] : obj.kb_type;
                    input.addEventListener('input', debounce(function () {
                        fetch('misc.php?action=kb_json_list&type=' + encodeURIComponent(typeName || '') + '&q=' + encodeURIComponent(input.value || ''), { credentials: 'same-origin' })
                            .then(function (res) { return res.json(); })
                            .then(function (payload) {
                                list.innerHTML = '';
                                if (!payload || !Array.isArray(payload.items)) {
                                    return;
                                }
                                payload.items.slice(0, 25).forEach(function (item) {
                                    var opt = document.createElement('option');
                                    opt.value = item.key;
                                    opt.label = (item.title || item.key) + ' (' + item.key + ')';
                                    list.appendChild(opt);
                                });
                            }).catch(function () {});
                    }, 250));
                }
            }
            input.dataset.field = def.name;
            input.addEventListener('input', function () {
                if (def.type === 'lines') {
                    obj[def.name] = splitLines(input.value);
                } else if (def.type === 'json') {
                    obj[def.name] = readJson(input.value, {});
                } else if (def.type === 'number') {
                    obj[def.name] = numberOrZero(input.value);
                } else if (def.type === 'checkbox') {
                    obj[def.name] = input.checked;
                } else {
                    obj[def.name] = input.value;
                }
                onChange();
            });
            wrap.appendChild(input);
            if (def.hint) {
                wrap.insertAdjacentHTML('beforeend', '<div class="af-kb-help">' + esc(def.hint) + '</div>');
            }
            return wrap;
        }

        function validate() {
            var errors = [];
            function validateSlugList(values, prefix) {
                (values || []).forEach(function (value) {
                    if (!slugPattern.test(value)) {
                        errors.push(prefix + ': invalid key ' + value);
                    }
                });
            }
            state.choices.forEach(function (item, i) {
                if (!item.type) {
                    errors.push('choices[' + i + ']: missing type');
                    return;
                }
                var def = getDef(choiceDefs, item.type);
                if (!def) {
                    return;
                }
                def.fields.forEach(function (field) {
                    if (field.required && (item[field.name] == null || item[field.name] === '')) {
                        errors.push('choices[' + i + '].' + field.name + ': required');
                    }
                });
                validateSlugList(item.options, 'choices[' + i + '].options');
                validateSlugList(item.exclude, 'choices[' + i + '].exclude');
            });
            state.traits.forEach(function (item, i) {
                if (!item.key) {
                    errors.push('traits[' + i + '].key: required');
                }
            });
            fields.errors.innerHTML = errors.length ? ('<div class="af-kb-help">Ошибки схемы:<br>' + errors.map(esc).join('<br>') + '</div>') : '';
            return errors;
        }

        function toPayload() {
            var payload = JSON.parse(JSON.stringify(state));
            payload.fixed_bonuses.stats = {};
            stats.forEach(function (key) { payload.fixed_bonuses.stats[key] = numberOrZero(state.fixed_bonuses.stats[key]); });
            payload.choices = state.choices.map(function (choice) {
                var out = JSON.parse(JSON.stringify(choice));
                if (out.type === 'stat_bonus_choice') {
                    out.type = 'stat_bonus';
                } else if (out.type === 'kb_pick_choice') {
                    out.type = 'kb_pick';
                } else if (out.type === 'language_pick_choice') {
                    out.type = 'language_pick';
                }
                return out;
            });
            return payload;
        }

        var syncRawDebounced = debounce(function () {
            validate();
            var payload = toPayload();
            raw.value = JSON.stringify(payload, null, 2);
            hidden.value = JSON.stringify(payload);
        }, 350);

        function syncStateFromHead() {
            state.size = fields.size.value;
            state.creature_type = fields.creature.value.trim() || 'humanoid';
            state.speed = numberOrZero(fields.speed.value);
            state.hp_base = numberOrZero(fields.hpBase.value);
            syncRawDebounced();
        }

        function renderFixedBonuses() {
            fields.fixed.innerHTML = '';
            var rowStats = document.createElement('div');
            rowStats.className = 'af-kb-row';
            stats.forEach(function (key) {
                var box = document.createElement('div');
                box.innerHTML = '<label>' + key + '</label><input type="number" data-stat="' + key + '" value="' + numberOrZero(state.fixed_bonuses.stats[key]) + '" /><div class="af-kb-help">Фиксированный бонус к атрибуту.</div>';
                rowStats.appendChild(box);
            });
            fields.fixed.appendChild(rowStats);
            var resources = ['hp', 'ep', 'skill_points', 'feat_points', 'perk_points', 'language_slots'];
            var rowResources = document.createElement('div');
            rowResources.className = 'af-kb-row';
            resources.forEach(function (key) {
                var hint = key === 'hp' ? 'Доп. очки здоровья от расы/класса/темы.' : (key === 'skill_points' ? 'Очки навыков для прокачки навыков.' : 'Ресурсное значение.');
                var box = document.createElement('div');
                box.innerHTML = '<label>' + key + '</label><input type="number" data-resource="' + key + '" value="' + numberOrZero(state.fixed_bonuses[key]) + '" /><div class="af-kb-help">' + hint + '</div>';
                rowResources.appendChild(box);
            });
            fields.fixed.appendChild(rowResources);
            fields.fixed.querySelectorAll('[data-stat]').forEach(function (input) {
                input.addEventListener('input', function () {
                    state.fixed_bonuses.stats[input.getAttribute('data-stat')] = numberOrZero(input.value);
                    syncRawDebounced();
                });
            });
            fields.fixed.querySelectorAll('[data-resource]').forEach(function (input) {
                input.addEventListener('input', function () {
                    state.fixed_bonuses[input.getAttribute('data-resource')] = numberOrZero(input.value);
                    syncRawDebounced();
                });
            });
        }

        function renderTypedList(container, dataList, defs, typeName) {
            container.innerHTML = '';
            dataList.forEach(function (item, index) {
                var card = document.createElement('div');
                card.className = 'af-kb-rule-card';
                var def = getDef(defs, item.type || item.key);
                if (!def) {
                    card.innerHTML = '<div class="af-kb-help"><strong>Unknown type:</strong> ' + esc(item.type || 'unknown') + '</div><label>Raw</label><textarea data-raw-index="' + index + '">' + esc(JSON.stringify(item, null, 2)) + '</textarea><button type="button" class="af-kb-remove" data-remove-index="' + index + '">Удалить</button>';
                    container.appendChild(card);
                    return;
                }
                var title = '<div class="af-kb-rule-card__title"><strong>' + esc(def.label) + '</strong><span class="af-kb-help">' + esc(def.desc || '') + '</span></div>';
                card.innerHTML = title;
                var grid = document.createElement('div');
                grid.className = 'af-kb-row';
                def.fields.forEach(function (field) {
                    grid.appendChild(createInput(field, item, syncRawDebounced));
                });
                card.appendChild(grid);
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'af-kb-remove';
                remove.textContent = 'Удалить';
                remove.addEventListener('click', function () {
                    dataList.splice(index, 1);
                    if (typeName === 'choice') {
                        renderChoices();
                    } else if (typeName === 'grant') {
                        renderGrants();
                    }
                    syncRawDebounced();
                });
                card.appendChild(remove);
                container.appendChild(card);
            });
            container.querySelectorAll('textarea[data-raw-index]').forEach(function (ta) {
                ta.addEventListener('input', function () {
                    dataList[Number(ta.getAttribute('data-raw-index'))] = readJson(ta.value, dataList[Number(ta.getAttribute('data-raw-index'))]);
                    syncRawDebounced();
                });
            });
            container.querySelectorAll('[data-remove-index]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    dataList.splice(Number(btn.getAttribute('data-remove-index')), 1);
                    if (typeName === 'choice') {
                        renderChoices();
                    } else if (typeName === 'grant') {
                        renderGrants();
                    }
                    syncRawDebounced();
                });
            });
        }

        function renderChoices() {
            renderTypedList(fields.choices, state.choices, choiceDefs, 'choice');
        }

        function renderGrants() {
            renderTypedList(fields.grants, state.grants, grantDefs, 'grant');
        }

        function renderTraits() {
            fields.traits.innerHTML = '';
            state.traits.forEach(function (trait, index) {
                var card = document.createElement('div');
                card.className = 'af-kb-rule-card';
                card.innerHTML = '<div class="af-kb-rule-card__title"><strong>Trait</strong></div>';
                var row = document.createElement('div');
                row.className = 'af-kb-row';
                traitFields.forEach(function (field) {
                    row.appendChild(createInput(field, trait, syncRawDebounced));
                });
                card.appendChild(row);
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'af-kb-remove';
                remove.textContent = 'Удалить';
                remove.addEventListener('click', function () {
                    state.traits.splice(index, 1);
                    renderTraits();
                    syncRawDebounced();
                });
                card.appendChild(remove);
                fields.traits.appendChild(card);
            });
        }

        root.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }
            var addChoiceType = target.getAttribute('data-add-choice');
            if (addChoiceType) {
                state.choices.push(JSON.parse(JSON.stringify(templates[addChoiceType] || { type: addChoiceType })));
                renderChoices();
                syncRawDebounced();
                return;
            }
            var addGrantType = target.getAttribute('data-add-grant');
            if (addGrantType) {
                state.grants.push(JSON.parse(JSON.stringify(templates[addGrantType] || { type: addGrantType })));
                renderGrants();
                syncRawDebounced();
                return;
            }
            if (target.id === 'kb-add-trait') {
                state.traits.push({ key: '', title_ru: '', title_en: '', desc_ru: '', desc_en: '', tags: [], meta: {} });
                renderTraits();
                syncRawDebounced();
                return;
            }
            if (target.id === 'kb-add-trait-example') {
                state.traits.push(JSON.parse(JSON.stringify(templates.trait)));
                renderTraits();
                syncRawDebounced();
                return;
            }
            if (target.id === 'kb-sync-from-raw') {
                var parsed = null;
                try {
                    parsed = JSON.parse(raw.value || '{}');
                    fields.rawError.textContent = '';
                } catch (err) {
                    fields.rawError.textContent = 'Ошибка JSON: ' + err.message;
                    return;
                }
                state.size = parsed.size || 'medium';
                state.creature_type = parsed.creature_type || 'humanoid';
                state.speed = numberOrZero(parsed.speed || 30);
                state.hp_base = numberOrZero(parsed.hp_base || 10);
                state.fixed_bonuses = Object.assign(state.fixed_bonuses, parsed.fixed_bonuses || {});
                if (!state.fixed_bonuses.stats) {
                    state.fixed_bonuses.stats = {};
                }
                stats.forEach(function (key) { state.fixed_bonuses.stats[key] = numberOrZero(state.fixed_bonuses.stats[key]); });
                state.choices = (Array.isArray(parsed.choices) ? parsed.choices : []).map(normalizeChoice);
                state.grants = Array.isArray(parsed.grants) ? parsed.grants : [];
                state.traits = Array.isArray(parsed.traits) ? parsed.traits : [];
                fields.size.value = state.size;
                fields.creature.value = state.creature_type;
                fields.speed.value = state.speed;
                fields.hpBase.value = state.hp_base;
                renderFixedBonuses();
                renderChoices();
                renderGrants();
                renderTraits();
                syncRawDebounced();
            }
        });

        [fields.size, fields.creature, fields.speed, fields.hpBase].forEach(function (field) {
            field.addEventListener('input', syncStateFromHead);
            field.addEventListener('change', syncStateFromHead);
        });

        renderFixedBonuses();
        renderChoices();
        renderGrants();
        renderTraits();
        syncRawDebounced();
    }

    document.addEventListener('DOMContentLoaded', function () {
        initMetaUi();
        initDataUi();
    });
})();

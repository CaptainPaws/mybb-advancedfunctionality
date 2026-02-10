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

    function ensureStats(stats) {
        var source = stats && typeof stats === 'object' ? stats : {};
        return {
            str: numberOrZero(source.str),
            dex: numberOrZero(source.dex),
            con: numberOrZero(source.con),
            int: numberOrZero(source.int),
            wis: numberOrZero(source.wis),
            cha: numberOrZero(source.cha)
        };
    }

    function splitCsv(value) {
        return String(value || '').split(',').map(function (item) { return item.trim(); }).filter(Boolean);
    }

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
        var rawPayload = raw.value || root.getAttribute('data-data-json') || '{}';
        var data = readJson(rawPayload, {});
        data.schema = 'af_kb.rules.v1';
        try { JSON.parse(rawPayload || '{}'); } catch (err) { root.insertAdjacentHTML('afterbegin', '<div class="af-kb-help">⚠ JSON parse warning: используются значения по умолчанию, страницу это не ломает.</div>'); }
        if (!Array.isArray(data.choices)) {
            data.choices = [];
        }

        function syncRaw(next) {
            raw.value = JSON.stringify(next, null, 2);
            hidden.value = JSON.stringify(next);
        }

        if (type === 'race') {
            root.innerHTML = [
                '<div class="af-kb-row"><div><label>Size</label><select id="kb-size"><option>tiny</option><option>small</option><option>medium</option><option>large</option><option>huge</option></select></div><div><label>Creature type</label><input type="text" id="kb-creature" /></div></div>',
                '<div class="af-kb-row"><div><label>Speed</label><input type="number" id="kb-speed" /></div><div><label>HP base</label><input type="number" id="kb-hp-base" /></div></div>',
                '<div class="af-kb-row"><div><label>Languages (csv)</label><input type="text" id="kb-languages" /></div><div><label>Resistances (csv)</label><input type="text" id="kb-resistances" /></div></div>',
                '<details open="open" class="af-kb-collapsible"><summary>Bonuses</summary><table class="af-kb-bonuses-table"><tr><th>Stat</th><th>Value</th></tr><tr><td>STR</td><td><input type="number" id="kb-str" /></td></tr><tr><td>DEX</td><td><input type="number" id="kb-dex" /></td></tr><tr><td>CON</td><td><input type="number" id="kb-con" /></td></tr><tr><td>INT</td><td><input type="number" id="kb-int" /></td></tr><tr><td>WIS</td><td><input type="number" id="kb-wis" /></td></tr><tr><td>CHA</td><td><input type="number" id="kb-cha" /></td></tr><tr><td>HP</td><td><input type="number" id="kb-fixed-hp" /></td></tr><tr><td>EP</td><td><input type="number" id="kb-fixed-ep" /></td></tr></table></details>',
                '<details open="open" class="af-kb-collapsible"><summary>Choices</summary><div class="af-kb-inline"><button type="button" class="af-kb-add" id="kb-choice-add-stat">Добавить stat_bonus</button><button type="button" class="af-kb-add" id="kb-choice-add-kb">Добавить kb_pick</button><button type="button" class="af-kb-add" id="kb-choice-add-language">Добавить language_pick</button></div><div id="kb-choices-ui"></div></details>',
                '<div class="af-kb-row"><div><label>Traits JSON</label><textarea id="kb-traits"></textarea></div><div><label>Grants JSON</label><textarea id="kb-grants"></textarea></div></div>',
                '<details class="af-kb-collapsible"><summary>Исходные данные</summary><textarea id="kb-choices" readonly="readonly"></textarea></details>'
            ].join('');

            var stats = ensureStats(((data.fixed_bonuses || {}).stats) || {});
            var fields = {
                size: root.querySelector('#kb-size'), creature: root.querySelector('#kb-creature'), speed: root.querySelector('#kb-speed'), hpBase: root.querySelector('#kb-hp-base'),
                languages: root.querySelector('#kb-languages'), resistances: root.querySelector('#kb-resistances'), traits: root.querySelector('#kb-traits'), choicesRaw: root.querySelector('#kb-choices'), grants: root.querySelector('#kb-grants'),
                str: root.querySelector('#kb-str'), dex: root.querySelector('#kb-dex'), con: root.querySelector('#kb-con'), int: root.querySelector('#kb-int'), wis: root.querySelector('#kb-wis'), cha: root.querySelector('#kb-cha'),
                hp: root.querySelector('#kb-fixed-hp'), ep: root.querySelector('#kb-fixed-ep'), choicesUi: root.querySelector('#kb-choices-ui')
            };

            var typeOptions = ['skill','knowledge','class','item','race','theme','perk','condition','spell','language'].map(function (opt) { return '<option value="'+opt+'">'+opt+'</option>'; }).join('');
            var choices = Array.isArray(data.choices) ? data.choices : [];
            function choiceTemplate(kind) {
                if (kind === 'kb') return {id:'new_kb_pick', type:'kb_pick', pick:1, kb_type:'skill'};
                if (kind === 'language') return {id:'new_language_pick', type:'language_pick', pick:1, options:[], exclude:['common']};
                return {id:'new_choice', type:'stat_bonus', pick:1, options:['str','dex','con','int','wis','cha'], value:1};
            }
            function renderChoices() {
                fields.choicesUi.innerHTML = '';
                choices.forEach(function (choice, index) {
                    var row = document.createElement('div');
                    row.className = 'af-kb-block-item';
                    row.innerHTML = '<div class="af-kb-row">'
                        + '<div><label>id</label><input type="text" data-field="id" value="'+(choice.id||'')+'" /></div>'
                        + '<div><label>type</label><select data-field="type"><option value="stat_bonus">stat_bonus</option><option value="kb_pick">kb_pick</option><option value="language_pick">language_pick</option></select></div>'
                        + '<div><label>pick</label><input type="number" data-field="pick" value="'+(choice.pick||1)+'" /></div></div>'
                        + '<div class="af-kb-row"><div><label>options (line by line)</label><textarea data-field="options">'+((choice.options||[]).join('\n'))+'</textarea></div>'
                        + '<div><label>exclude (line by line)</label><textarea data-field="exclude">'+((choice.exclude||[]).join('\n'))+'</textarea></div></div>'
                        + '<div class="af-kb-row"><div><label>value</label><input type="number" data-field="value" value="'+(choice.value||0)+'" /></div>'
                        + '<div><label>kb_type</label><select data-field="kb_type">'+typeOptions+'</select></div></div>'
                        + '<button type="button" class="af-kb-remove">Удалить выбор</button>';
                    fields.choicesUi.appendChild(row);
                    row.querySelector('[data-field="type"]').value = choice.type || 'stat_bonus';
                    row.querySelector('[data-field="kb_type"]').value = choice.kb_type || 'skill';
                    row.addEventListener('input', function () {
                        choice.id = row.querySelector('[data-field="id"]').value.trim();
                        choice.type = row.querySelector('[data-field="type"]').value;
                        choice.pick = numberOrZero(row.querySelector('[data-field="pick"]').value) || 1;
                        choice.options = row.querySelector('[data-field="options"]').value.split(/\n+/).map(function (v) { return v.trim(); }).filter(Boolean);
                        choice.exclude = row.querySelector('[data-field="exclude"]').value.split(/\n+/).map(function (v) { return v.trim(); }).filter(Boolean);
                        choice.value = numberOrZero(row.querySelector('[data-field="value"]').value);
                        choice.kb_type = row.querySelector('[data-field="kb_type"]').value;
                        syncRace();
                    });
                    row.querySelector('.af-kb-remove').addEventListener('click', function () { choices.splice(index,1); renderChoices(); syncRace(); });
                });
            }

            fields.size.value = data.size || 'medium';
            fields.creature.value = data.creature_type || 'humanoid';
            fields.speed.value = numberOrZero(data.speed || 30);
            fields.hpBase.value = numberOrZero(data.hp_base || 10);
            fields.languages.value = (data.languages || []).join(', ');
            fields.resistances.value = (data.resistances || []).join(', ');
            fields.traits.value = JSON.stringify(Array.isArray(data.traits) ? data.traits : [], null, 2);
            fields.grants.value = JSON.stringify(Array.isArray(data.grants) ? data.grants : [], null, 2);
            ['str','dex','con','int','wis','cha'].forEach(function (k) { fields[k].value = stats[k]; });
            fields.hp.value = numberOrZero((data.fixed_bonuses || {}).hp || 0);
            fields.ep.value = numberOrZero((data.fixed_bonuses || {}).ep || 0);

            function syncRace() {
                var next = {
                    schema: 'af_kb.rules.v1',
                    size: fields.size.value || 'medium',
                    creature_type: fields.creature.value.trim() || 'humanoid',
                    speed: numberOrZero(fields.speed.value),
                    hp_base: numberOrZero(fields.hpBase.value),
                    languages: splitCsv(fields.languages.value),
                    resistances: splitCsv(fields.resistances.value),
                    choices: choices,
                    traits: readJson(fields.traits.value, []),
                    grants: readJson(fields.grants.value, []),
                    fixed_bonuses: {
                        stats: ensureStats({ str: fields.str.value, dex: fields.dex.value, con: fields.con.value, int: fields.int.value, wis: fields.wis.value, cha: fields.cha.value }),
                        hp: numberOrZero(fields.hp.value),
                        ep: numberOrZero(fields.ep.value)
                    },
                    visibility: 'technical',
                    is_technical: true
                };
                fields.choicesRaw.value = JSON.stringify(choices, null, 2);
                syncRaw(next);
            }

            Object.keys(fields).forEach(function (key) {
                if (key === 'choicesUi' || key === 'choicesRaw') return;
                fields[key].addEventListener('input', syncRace);
                fields[key].addEventListener('change', syncRace);
            });
            root.querySelector('#kb-choice-add-stat').addEventListener('click', function () { choices.push(choiceTemplate('stat')); renderChoices(); syncRace(); });
            root.querySelector('#kb-choice-add-kb').addEventListener('click', function () { choices.push(choiceTemplate('kb')); renderChoices(); syncRace(); });
            root.querySelector('#kb-choice-add-language').addEventListener('click', function () { choices.push(choiceTemplate('language')); renderChoices(); syncRace(); });
            renderChoices();
            syncRace();
            return;
        }

        root.innerHTML = '<div class="af-kb-help">Для этого типа используйте Raw Data JSON (advanced).</div>';
        function syncRawOnly() {
            var parsed = readJson(raw.value, {});
            parsed.schema = 'af_kb.rules.v1';
            syncRaw(parsed);
        }
        raw.addEventListener('input', syncRawOnly);
        syncRawOnly();
    }

    document.addEventListener('DOMContentLoaded', function () {
        initMetaUi();
        initDataUi();
    });
})();

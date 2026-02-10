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
    function setByPath(obj, path, value) {
        var parts = String(path || '').split('.');
        var cursor = obj;
        for (var i = 0; i < parts.length - 1; i++) {
            if (!cursor[parts[i]] || typeof cursor[parts[i]] !== 'object') {
                cursor[parts[i]] = {};
            }
            cursor = cursor[parts[i]];
        }
        cursor[parts[parts.length - 1]] = value;
    }

    function getByPath(obj, path) {
        var parts = String(path || '').split('.');
        var cursor = obj;
        for (var i = 0; i < parts.length; i++) {
            if (!cursor || typeof cursor !== 'object' || !(parts[i] in cursor)) {
                return undefined;
            }
            cursor = cursor[parts[i]];
        }
        return cursor;
    }

    function initSchemaUI() {
        var root = document.getElementById('af-kb-schema-ui');
        var metaField = document.getElementById('af-kb-meta-json');
        if (!root || !metaField) {
            return;
        }

        var schema = {};
        var data = {};
        var itemKinds = [];
        try { schema = JSON.parse(root.getAttribute('data-type-schema') || '{}'); } catch (e) {}
        try { data = JSON.parse(metaField.value || '{}'); } catch (e2) {}
        try { itemKinds = JSON.parse(root.getAttribute('data-item-kinds') || '[]'); } catch (e3) {}

        if (!schema || !Array.isArray(schema.fields) || !schema.fields.length) {
            root.innerHTML = '<div class="af-kb-help">UI-схема недоступна, используйте raw JSON.</div>';
            return;
        }

        function render() {
            root.innerHTML = '';
            schema.fields.forEach(function (f) {
                var wrap = document.createElement('div');
                wrap.className = 'af-kb-row';
                var label = document.createElement('label');
                label.textContent = f.label_ru || f.label_en || f.path;
                wrap.appendChild(label);

                var val = getByPath(data, f.path);
                if (val === undefined && f.default !== undefined) {
                    val = f.default;
                    setByPath(data, f.path, val);
                }

                var input;
                if (f.type === 'bool') {
                    input = document.createElement('input'); input.type = 'checkbox'; input.checked = !!val;
                    input.addEventListener('change', function () { setByPath(data, f.path, !!input.checked); sync(); });
                } else if (f.type === 'number') {
                    input = document.createElement('input'); input.type = 'number'; input.value = (val === undefined ? '' : val);
                    input.addEventListener('input', function () { setByPath(data, f.path, input.value === '' ? null : Number(input.value)); sync(); });
                } else if (f.type === 'select') {
                    input = document.createElement('select');
                    var options = Array.isArray(f.options) ? f.options : [];
                    if (f.path === 'item_kind' && itemKinds.length) { options = itemKinds; }
                    options.forEach(function (op) {
                        var o = document.createElement('option');
                        o.value = op.value; o.textContent = op.label_ru || op.label_en || op.value;
                        if (String(val) === String(op.value)) o.selected = true;
                        input.appendChild(o);
                    });
                    input.addEventListener('change', function () { setByPath(data, f.path, input.value); sync(); });
                } else if (f.type === 'multiselect') {
                    input = document.createElement('select'); input.multiple = true;
                    (f.options || []).forEach(function (op) {
                        var o = document.createElement('option'); o.value = op.value; o.textContent = op.label_ru || op.label_en || op.value;
                        if (Array.isArray(val) && val.indexOf(op.value) !== -1) o.selected = true;
                        input.appendChild(o);
                    });
                    input.addEventListener('change', function () {
                        var v = Array.from(input.options).filter(function (o) { return o.selected; }).map(function (o) { return o.value; });
                        setByPath(data, f.path, v); sync();
                    });
                } else if (f.type === 'array' || f.type === 'object' || f.type === 'i18n') {
                    input = document.createElement('textarea');
                    input.value = JSON.stringify(val !== undefined ? val : (f.default !== undefined ? f.default : (f.type === 'array' ? [] : {})), null, 2);
                    input.addEventListener('input', function () {
                        try { setByPath(data, f.path, JSON.parse(input.value || (f.type === 'array' ? '[]' : '{}'))); input.style.borderColor = ''; sync(); }
                        catch (e) { input.style.borderColor = '#d00'; }
                    });
                } else {
                    input = document.createElement('input'); input.type = 'text'; input.value = val == null ? '' : String(val);
                    if (f.readonly) input.readOnly = true;
                    input.addEventListener('input', function () { setByPath(data, f.path, input.value); sync(); });
                }

                if (f.required) { label.innerHTML += ' <span style="color:#d00">*</span>'; }
                wrap.appendChild(input);
                if (f.hint_ru) { var hint = document.createElement('div'); hint.className = 'af-kb-help'; hint.textContent = f.hint_ru; wrap.appendChild(hint); }
                root.appendChild(wrap);
            });
            sync();
        }

        function sync() {
            if (!data.schema) data.schema = 'af_kb.rules.v1';
            metaField.value = JSON.stringify(data, null, 2);
        }

        data = Object.assign({}, schema.root_defaults || {}, data || {});
        render();
    }

    document.addEventListener('DOMContentLoaded', initSchemaUI);
})();

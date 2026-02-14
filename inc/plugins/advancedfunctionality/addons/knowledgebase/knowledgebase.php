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

    function deepClone(obj) {
        try {
            return JSON.parse(JSON.stringify(obj || {}));
        } catch (e) {
            return {};
        }
    }

    var stats = ['str', 'dex', 'con', 'int', 'wis', 'cha'];
    var rankOptions = ['trained', 'expert', 'master', 'legendary', '0', '1', '2', '3', '4'];
    var slugPattern = /^[a-z0-9_\-:.]+$/;

    // KB types for autocomplete endpoints (если у тебя другие — просто добавь/переименуй тут)
    var kbTypes = ['race', 'class', 'theme', 'lore', 'knowledge', 'language', 'skill', 'item', 'spell', 'perk', 'condition', 'faction'];

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

    // ---------- RULES UI (главная часть правки) ----------
    function initDataUi() {
        var root = document.getElementById('af-kb-data-ui');
        var hidden = document.getElementById('af-kb-data-json');
        var raw = document.getElementById('af-kb-data-json-raw');
        if (!root || !hidden || !raw) {
            return;
        }

        var type = (root.getAttribute('data-type') || '').trim(); // race/class/theme/lore/...
        var typeSchema = readJson(root.getAttribute('data-type-schema') || '{}', {});

        // ВАЖНО: мы больше НЕ делаем один и тот же UI на все типы.
        // Профиль UI: либо задаётся схемой (ui_profile), либо определяется по type.
        function resolveUiProfile(entryType, schema) {
            var t = String(entryType || '').trim();

            // ВАЖНО: эти три типа ДОЛЖНЫ иметь один и тот же UI-профиль (как у расы),
            // независимо от того, что лежит в schema.ui_profile.
            if (t === 'race' || t === 'class' || t === 'theme') {
                return 'heritage';
            }

            // Если не один из “большой тройки” — тогда можно доверять схеме.
            var p = (schema && typeof schema.ui_profile === 'string') ? schema.ui_profile.trim() : '';
            if (p) return p;

            // Явная мапа по остальным типам
            if (t === 'skill') return 'skill';
            if (t === 'spell') return 'spell';
            if (t === 'item') return 'item';
            if (t === 'perk') return 'perk';
            if (t === 'condition') return 'perk';
            if (t === 'language') return 'language';
            if (t === 'knowledge') return 'knowledge';
            if (t === 'lore') return 'lore';
            if (t === 'faction') return 'faction';

            // fallback: raw-only
            return t || 'raw';
        }


        var uiProfile = resolveUiProfile(type, typeSchema);
        // принудительно нормализуем type_profile для race/class/theme
        if (type === 'race' || type === 'class' || type === 'theme') {
            uiProfile = 'heritage';
        }


        // rules_json может быть выключен для некоторых типов — тогда raw-only
        var rulesEditorEnabled = (typeSchema.ui_rules_editor !== false) && (typeSchema.rules_enabled !== false);

        function bindRawOnlyMode(message) {
            root.innerHTML = '<div class="af-kb-help">' + esc(message) + '</div>';
            hidden.value = raw.value || '{}';
            raw.addEventListener('input', function () { hidden.value = raw.value || '{}'; });
        }

        if (!rulesEditorEnabled) {
            bindRawOnlyMode('Для этого типа rules_json выключен (rules_enabled/ui_rules_editor=false). Доступен только raw-режим (advanced).');
            return;
        }

        // ---------- Универсальные билд-блоки формы ----------
        function createInput(def, obj, onChange) {
            var wrap = document.createElement('div');
            var label = document.createElement('label');
            label.textContent = def.label || def.name;
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
            } else if (def.type === 'kb_key') {
                input = document.createElement('input');
                input.type = 'text';
                input.value = value || '';
                var datalistId = 'kb-list-' + Math.random().toString(16).slice(2);
                var list = document.createElement('datalist');
                list.id = datalistId;
                input.setAttribute('list', datalistId);
                wrap.appendChild(list);

                var getTypeName = function () {
                    if (def.kbTypeField && obj && obj[def.kbTypeField]) return obj[def.kbTypeField];
                    if (def.kbTypeValue) return def.kbTypeValue;
                    if (obj && obj.kb_type) return obj.kb_type;
                    return '';
                };

                input.addEventListener('input', debounce(function () {
                    var typeName = getTypeName();
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
            } else {
                input = document.createElement('input');
                input.type = 'text';
                input.value = value || '';
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

        function renderObjectList(container, list, title, fieldsDef, onChange, addPreset) {
            container.innerHTML = '';

            var head = document.createElement('div');
            head.className = 'af-kb-inline';
            head.innerHTML = '<div class="af-kb-help"><strong>' + esc(title) + '</strong></div>';

            var addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'af-kb-add';
            addBtn.textContent = 'Добавить';
            addBtn.addEventListener('click', function () {
                list.push(deepClone(addPreset || {}));
                renderObjectList(container, list, title, fieldsDef, onChange, addPreset);
                onChange();
            });

            head.appendChild(addBtn);
            container.appendChild(head);

            list.forEach(function (obj, idx) {
                var card = document.createElement('div');
                card.className = 'af-kb-rule-card';

                var row = document.createElement('div');
                row.className = 'af-kb-row';
                fieldsDef.forEach(function (def) {
                    row.appendChild(createInput(def, obj, onChange));
                });
                card.appendChild(row);

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'af-kb-remove';
                remove.textContent = 'Удалить';
                remove.addEventListener('click', function () {
                    list.splice(idx, 1);
                    renderObjectList(container, list, title, fieldsDef, onChange, addPreset);
                    onChange();
                });
                card.appendChild(remove);

                container.appendChild(card);
            });
        }

        function renderKvList(container, list, title, onChange) {
            container.innerHTML = '';

            var head = document.createElement('div');
            head.className = 'af-kb-inline';
            head.innerHTML = '<div class="af-kb-help"><strong>' + esc(title) + '</strong></div>';

            var addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'af-kb-add';
            addBtn.textContent = 'Добавить';
            addBtn.addEventListener('click', function () {
                list.push({ key: '', value: '' });
                renderKvList(container, list, title, onChange);
                onChange();
            });

            head.appendChild(addBtn);
            container.appendChild(head);

            list.forEach(function (rowObj, idx) {
                var card = document.createElement('div');
                card.className = 'af-kb-rule-card';

                var grid = document.createElement('div');
                grid.className = 'af-kb-row';

                var k = document.createElement('div');
                k.innerHTML = '<label>key</label><input type="text" value="' + esc(rowObj.key || '') + '"/>';
                var v = document.createElement('div');
                v.innerHTML = '<label>value</label><input type="text" value="' + esc(rowObj.value || '') + '"/>';

                var kInput = k.querySelector('input');
                var vInput = v.querySelector('input');

                kInput.addEventListener('input', function () { rowObj.key = kInput.value; onChange(); });
                vInput.addEventListener('input', function () { rowObj.value = vInput.value; onChange(); });

                grid.appendChild(k);
                grid.appendChild(v);

                card.appendChild(grid);

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'af-kb-remove';
                remove.textContent = 'Удалить';
                remove.addEventListener('click', function () {
                    list.splice(idx, 1);
                    renderKvList(container, list, title, onChange);
                    onChange();
                });
                card.appendChild(remove);

                container.appendChild(card);
            });
        }

        function detailsBlock(summary, innerHtml, openByDefault) {
            return '<details ' + (openByDefault ? 'open="open"' : '') + ' class="af-kb-collapsible"><summary>' + esc(summary) + '</summary>' + innerHtml + '</details>';
        }

        // ---------- Профили / дефолты ----------
        function defaultsForProfile(profile) {
            // schema + общий контейнер
            var base = {
                schema: 'af_kb.rules.v1',
                type_profile: profile,
                version: '1.0'
            };

            if (profile === 'heritage') {
                base.size = 'medium';
                base.creature_type = 'humanoid';
                base.speed = 30;
                base.hp_base = 10;
                base.fixed_bonuses = {
                    stats: { str: 0, dex: 0, con: 0, int: 0, wis: 0, cha: 0 },
                    hp: 0, ep: 0, skill_points: 0, feat_points: 0, perk_points: 0, language_slots: 0
                };
                base.choices = [];
                base.grants = [];
                base.traits = [];
                return base;
            }

            if (profile === 'skill') {
                base.skill = {
                    category: 'general', // combat/social/tech/knowledge/psi/cyber...
                    rank_max: 4,
                    cooldown: 0,
                    cost: {},
                    effects: [],
                    requirements: { level: 0, tags_any: [], tags_all: [] }
                };
                return base;
            }

            if (profile === 'spell') {
                base.spell = {
                    tradition: '', // arcane/divine/occult/primal/psi/tech (как тебе надо)
                    school: '',
                    level: 1,
                    cast_time: '',
                    range: '',
                    duration: '',
                    cost: {},
                    traits: [],
                    effects: [],
                    requirements: { level: 0, tags_any: [], tags_all: [] }
                };
                return base;
            }

            if (profile === 'item') {
                base.item = {
                    item_type: 'gear', // weapon/armor/gear/consumable/cyberware/ammo/mod
                    rarity: 'common',
                    slot: '',
                    price: 0,
                    currency: '',
                    weight: 0,
                    stack_max: 1,
                    tags: [],
                    on_use: { cooldown: 0, cost: {}, effects: [] },
                    on_equip: { effects: [], grants: [] },
                    requirements: { level: 0, tags_any: [], tags_all: [] }
                };
                return base;
            }

            if (profile === 'perk' || profile === 'condition') {
                base.perk = {
                    kind: (profile === 'condition' ? 'condition' : 'perk'),
                    duration: '',
                    stacks: 1,
                    intensity: 0,
                    tags: [],
                    modifiers: [],
                    grants: []
                };
                return base;
            }

            if (profile === 'language') {
                base.language = {
                    family: '',
                    script: '',
                    rarity: 'common',
                    tags: [],
                    notes: '',
                    grants: []
                };
                return base;
            }

            if (profile === 'knowledge') {
                base.knowledge = {
                    domain: '', // science/history/occult/tech/etc
                    tier: 0,
                    tags: [],
                    grants: [],
                    flags: []
                };
                return base;
            }

            if (profile === 'lore') {
                base.lore = {
                    scope: '', // world/region/faction/person/event
                    era: '',
                    tags: [],
                    links: [],
                    flags: []
                };
                return base;
            }

            if (profile === 'faction') {
                base.faction = {
                    alignment: '',
                    influence: 0,
                    tags: [],
                    relations: [],
                    grants: []
                };
                return base;
            }

            return base;
        }

        // Достаём raw, подмешиваем дефолты схемы и профиля
        var parsedRaw = readJson(raw.value || '{}', {});
        var schemaDefaults = (typeSchema.defaults && typeof typeSchema.defaults === 'object') ? deepClone(typeSchema.defaults) : {};
        var profileDefaults = defaultsForProfile(uiProfile);

        // merge: profileDefaults -> schemaDefaults -> parsedRaw (parsedRaw главнее всего)
        function merge3(a, b, c) {
            var out = Object.assign({}, a || {});
            Object.keys(b || {}).forEach(function (k) { out[k] = b[k]; });
            Object.keys(c || {}).forEach(function (k) { out[k] = c[k]; });
            return out;
        }

        var merged = merge3(profileDefaults, schemaDefaults, parsedRaw);

        // ---------- UI layout (разный по профилям) ----------
        // Heritage: race/class/theme (как ты и хотела: одинаковый “расовый” UI только там)
        var isRaceHead = (type === 'race'); // только race получает size/creature/speed/hp

        // Сборка HTML-контейнеров
        var html = [];
        html.push('<div class="af-kb-help">Rules UI: <strong>' + esc(type || 'unknown') + '</strong> (profile: <strong>' + esc(uiProfile) + '</strong>)</div>');
        html.push('<div id="kb-rules-errors" class="af-kb-errors"></div>');

        if (uiProfile === 'heritage' && isRaceHead) {
            html.push(
                '<div class="af-kb-row">' +
                    '<div><label>Size</label><select id="kb-size"><option>tiny</option><option>small</option><option>medium</option><option>large</option><option>huge</option></select></div>' +
                    '<div><label>Creature type</label><input type="text" id="kb-creature" /></div>' +
                '</div>' +
                '<div class="af-kb-row">' +
                    '<div><label>Speed (base walk)</label><input type="number" id="kb-speed" /></div>' +
                    '<div><label>HP base</label><input type="number" id="kb-hp-base" /></div>' +
                '</div>'
            );
        }

        // Контент по профилям
        if (uiProfile === 'heritage') {
            html.push(detailsBlock('Fixed bonuses', '<div id="kb-fixed-bonuses"></div>', true));
            html.push(detailsBlock(
                'Choices',
                '<div class="af-kb-inline">' +
                    '<button type="button" class="af-kb-add" data-add-choice="stat_bonus_choice">+2 к одному атрибуту</button>' +
                    '<button type="button" class="af-kb-add" data-add-choice="skill_pick_choice">2 навыка trained</button>' +
                    '<button type="button" class="af-kb-add" data-add-choice="language_pick_choice">1 язык (кроме common)</button>' +
                    '<button type="button" class="af-kb-add" data-add-choice="kb_pick_choice">KB pick</button>' +
                    '<button type="button" class="af-kb-add" data-add-choice="proficiency_pick_choice">Proficiency pick</button>' +
                    '<button type="button" class="af-kb-add" data-add-choice="feat_pick_choice">Feat/perk pick</button>' +
                    '<button type="button" class="af-kb-add" data-add-choice="equipment_pick_choice">Equipment pick</button>' +
                    '<button type="button" class="af-kb-add" data-add-choice="spell_pick_choice">Spell pick</button>' +
                '</div>' +
                '<div id="kb-choices-list"></div>',
                true
            ));
            html.push(detailsBlock(
                'Grants',
                '<div class="af-kb-inline">' +
                    '<button type="button" class="af-kb-add" data-add-grant="resource_gain">Выдать 2 skill_points</button>' +
                    '<button type="button" class="af-kb-add" data-add-grant="skill_rank">Фиксированный навык trained</button>' +
                    '<button type="button" class="af-kb-add" data-add-grant="item_grant">Стартовый предмет x1</button>' +
                    '<button type="button" class="af-kb-add" data-add-grant="resistance_grant">Сопротивление огню 5</button>' +
                    '<button type="button" class="af-kb-add" data-add-grant="sense_grant">Darkvision</button>' +
                    '<button type="button" class="af-kb-add" data-add-grant="speed_grant">Скорость плавания 20</button>' +
                '</div>' +
                '<div id="kb-grants-list"></div>',
                true
            ));
            html.push(detailsBlock(
                'Traits',
                '<div class="af-kb-inline">' +
                    '<button type="button" class="af-kb-add" id="kb-add-trait">Добавить trait</button>' +
                    '<button type="button" class="af-kb-add" id="kb-add-trait-example">Вставить пример trait</button>' +
                '</div>' +
                '<div id="kb-traits-list"></div>',
                true
            ));
        } else {
            // Все прочие профили — НЕ “расовый” UI
            html.push(detailsBlock('Поля профиля', '<div id="kb-profile-fields"></div>', true));

            // Общие блоки, которые реально нужны для item/spell/skill/perk и т.д.
            html.push(detailsBlock('Effects / Modifiers / Grants', '<div id="kb-profile-lists"></div>', true));
        }

        html.push(detailsBlock(
            'Raw sync',
            '<div class="af-kb-inline">' +
                '<button type="button" class="af-kb-add" id="kb-sync-from-raw">Синхронизировать из raw</button>' +
                '<span class="af-kb-help">Raw остаётся source-of-truth и всегда доступен.</span>' +
            '</div>' +
            '<div id="kb-raw-error" class="af-kb-help"></div>',
            false
        ));

        root.innerHTML = html.join('');

        // ---------- State (разный по профилям) ----------
        var state = deepClone(merged);
        state.schema = state.schema || 'af_kb.rules.v1';
        state.type_profile = state.type_profile || uiProfile;
        state.version = state.version || '1.0';

        // Нормализация arrays/objects
        function ensureObj(path, fallback) {
            var parts = path.split('.');
            var cur = state;
            for (var i = 0; i < parts.length; i++) {
                var k = parts[i];
                if (!cur[k] || typeof cur[k] !== 'object') {
                    cur[k] = (i === parts.length - 1) ? (fallback || {}) : {};
                }
                cur = cur[k];
            }
            return cur;
        }
        function ensureArr(path) {
            var parts = path.split('.');
            var cur = state;
            for (var i = 0; i < parts.length - 1; i++) {
                if (!cur[parts[i]] || typeof cur[parts[i]] !== 'object') cur[parts[i]] = {};
                cur = cur[parts[i]];
            }
            var last = parts[parts.length - 1];
            if (!Array.isArray(cur[last])) cur[last] = [];
            return cur[last];
        }

        // Heritage normalization
        if (uiProfile === 'heritage') {
            if (!state.fixed_bonuses || typeof state.fixed_bonuses !== 'object') {
                state.fixed_bonuses = { stats: {} };
            }
            if (!state.fixed_bonuses.stats || typeof state.fixed_bonuses.stats !== 'object') {
                state.fixed_bonuses.stats = {};
            }
            stats.forEach(function (k) { state.fixed_bonuses.stats[k] = numberOrZero(state.fixed_bonuses.stats[k]); });

            if (!Array.isArray(state.choices)) state.choices = [];
            if (!Array.isArray(state.grants)) state.grants = [];
            if (!Array.isArray(state.traits)) state.traits = [];
        }

        // Skill/spell/item/perk/...
        if (uiProfile === 'skill') {
            ensureObj('skill', {});
            ensureArr('skill.effects');
            ensureObj('skill.cost', {});
            ensureObj('skill.requirements', {});
            if (!Array.isArray(state.skill.requirements.tags_any)) state.skill.requirements.tags_any = [];
            if (!Array.isArray(state.skill.requirements.tags_all)) state.skill.requirements.tags_all = [];
        }
        if (uiProfile === 'spell') {
            ensureObj('spell', {});
            ensureArr('spell.effects');
            if (!Array.isArray(state.spell.traits)) state.spell.traits = [];
            ensureObj('spell.cost', {});
            ensureObj('spell.requirements', {});
            if (!Array.isArray(state.spell.requirements.tags_any)) state.spell.requirements.tags_any = [];
            if (!Array.isArray(state.spell.requirements.tags_all)) state.spell.requirements.tags_all = [];
        }
        if (uiProfile === 'item') {
            ensureObj('item', {});
            if (!Array.isArray(state.item.tags)) state.item.tags = [];
            ensureObj('item.on_use', {});
            ensureArr('item.on_use.effects');
            ensureObj('item.on_use.cost', {});
            ensureObj('item.on_equip', {});
            ensureArr('item.on_equip.effects');
            ensureArr('item.on_equip.grants');
            ensureObj('item.requirements', {});
            if (!Array.isArray(state.item.requirements.tags_any)) state.item.requirements.tags_any = [];
            if (!Array.isArray(state.item.requirements.tags_all)) state.item.requirements.tags_all = [];
        }
        if (uiProfile === 'perk' || uiProfile === 'condition') {
            ensureObj('perk', {});
            if (!Array.isArray(state.perk.tags)) state.perk.tags = [];
            ensureArr('perk.modifiers');
            ensureArr('perk.grants');
        }
        if (uiProfile === 'language') {
            ensureObj('language', {});
            if (!Array.isArray(state.language.tags)) state.language.tags = [];
            ensureArr('language.grants');
        }
        if (uiProfile === 'knowledge') {
            ensureObj('knowledge', {});
            if (!Array.isArray(state.knowledge.tags)) state.knowledge.tags = [];
            ensureArr('knowledge.grants');
            ensureArr('knowledge.flags');
        }
        if (uiProfile === 'lore') {
            ensureObj('lore', {});
            if (!Array.isArray(state.lore.tags)) state.lore.tags = [];
            ensureArr('lore.links');
            ensureArr('lore.flags');
        }
        if (uiProfile === 'faction') {
            ensureObj('faction', {});
            if (!Array.isArray(state.faction.tags)) state.faction.tags = [];
            ensureArr('faction.relations');
            ensureArr('faction.grants');
        }

        // ---------- defs (heritage) ----------
        var templates = {
            stat_bonus_choice: { type: 'stat_bonus_choice', id: 'boost_1', pick: 1, options: stats.slice(), value: 2, mode: 'add', exclude: [] },
            skill_pick_choice: { type: 'skill_pick_choice', id: 'skills_pick', pick: 2, options: [], exclude: [], grant_mode: 'rank', rank_value: 1, points_value: 2 },
            language_pick_choice: { type: 'language_pick_choice', id: 'lang_pick', pick: 1, exclude: ['common'], allow_custom: false, value: 1 },
            proficiency_pick_choice: { type: 'proficiency_pick_choice', id: 'prof_pick', pick: 1, prof_type: 'weapon', options: [], rank: 'trained', exclude: [] },
            feat_pick_choice: { type: 'feat_pick_choice', id: 'perk_pick', pick: 1, kb_type: 'perk', tag_filter: [], exclude: [] },
            equipment_pick_choice: { type: 'equipment_pick_choice', id: 'item_pick', pick: 1, kb_type: 'item', options: [], exclude: [], quantity: 1, grant: { type: 'item_grant', qty: 1 } },
            spell_pick_choice: { type: 'spell_pick_choice', id: 'spell_pick', pick: 1, kb_type: 'spell', tradition: '', school: '', level_min: 0, level_max: 1, grant: { type: 'spell_known', amount: 1 } },

            resource_gain: { type: 'resource_gain', resource: 'skill_points', value: 2, stack_mode: 'add' },
            skill_rank: { type: 'skill_rank', kb_type: 'skill', kb_key: 'athletics', rank: 'trained', mode: 'max' },
            item_grant: { type: 'item_grant', kb_key: 'starter_kit', qty: 1, bind: false, equipped: false, slot: '' },
            resistance_grant: { type: 'resistance_grant', damage_type: 'fire', value: 5 },
            sense_grant: { type: 'sense_grant', sense_type: 'darkvision', range: 60 },
            speed_grant: { type: 'speed_grant', speed_type: 'swim', value: 20, condition: '' },

            trait: { key: 'humanoid', title_ru: 'Гуманоид', title_en: 'Humanoid', desc_ru: '', desc_en: '', tags: ['species'], meta: {} }
        };

        function normalizeChoice(choice) {
            var out = choice && typeof choice === 'object' ? JSON.parse(JSON.stringify(choice)) : {};
            // обратная совместимость со старым типом
            if (out.type === 'stat_bonus') out.type = 'stat_bonus_choice';
            if (out.type === 'kb_pick') out.type = 'kb_pick_choice';
            if (out.type === 'language_pick') out.type = 'language_pick_choice';
            return out;
        }
        if (uiProfile === 'heritage') {
            state.choices = (state.choices || []).map(normalizeChoice);
        }

        var choiceDefs = [
            { key: 'stat_bonus_choice', label: 'Stat bonus choice', desc: 'Выбор атрибутов + бонус', fields: [
                { name: 'id', label: 'id', type: 'text', required: true, hint: 'Уникальный ключ выбора' },
                { name: 'pick', label: 'pick', type: 'number', required: true, hint: 'Сколько вариантов выбрать' },
                { name: 'options', label: 'options', type: 'lines', hint: 'Какие статы доступны (по одному в строке)' },
                { name: 'value', label: 'value', type: 'number', required: true, hint: 'Размер бонуса' },
                { name: 'mode', label: 'mode', type: 'select', options: ['add', 'set'], hint: 'Как применять бонус' },
                { name: 'exclude', label: 'exclude', type: 'lines', hint: 'Запрещённые ключи' }
            ] },
            { key: 'kb_pick_choice', label: 'KB pick choice', desc: 'Универсальный выбор из KB', fields: [
                { name: 'id', label: 'id', type: 'text', required: true },
                { name: 'kb_type', label: 'kb_type', type: 'select', options: kbTypes, required: true },
                { name: 'pick', label: 'pick', type: 'number', required: true },
                { name: 'options', label: 'options', type: 'lines', hint: 'Ограничить только этими key' },
                { name: 'exclude', label: 'exclude', type: 'lines' },
                { name: 'grant', label: 'grant', type: 'json', hint: 'Что выдать за каждый выбранный объект' }
            ] },
            { key: 'language_pick_choice', label: 'Language pick', desc: 'Выбор языков', fields: [
                { name: 'id', label: 'id', type: 'text', required: true },
                { name: 'pick', label: 'pick', type: 'number', required: true },
                { name: 'exclude', label: 'exclude', type: 'lines', hint: 'Исключить языки (например common)' },
                { name: 'allow_custom', label: 'allow_custom', type: 'checkbox' },
                { name: 'value', label: 'value', type: 'number', hint: 'Сколько языков за один выбор' }
            ] },
            { key: 'skill_pick_choice', label: 'Skill pick', desc: 'Выбор навыков', fields: [
                { name: 'id', label: 'id', type: 'text', required: true },
                { name: 'pick', label: 'pick', type: 'number', required: true },
                { name: 'options', label: 'options', type: 'lines' },
                { name: 'exclude', label: 'exclude', type: 'lines' },
                { name: 'grant_mode', label: 'grant_mode', type: 'select', options: ['rank', 'skill_points'] },
                { name: 'rank_value', label: 'rank_value', type: 'number' },
                { name: 'points_value', label: 'points_value', type: 'number' }
            ] },
            { key: 'proficiency_pick_choice', label: 'Proficiency pick', desc: 'Выбор владения', fields: [
                { name: 'id', label: 'id', type: 'text', required: true },
                { name: 'pick', label: 'pick', type: 'number', required: true },
                { name: 'prof_type', label: 'prof_type', type: 'select', options: ['weapon', 'armor', 'tool', 'save', 'skill'] },
                { name: 'options', label: 'options', type: 'lines' },
                { name: 'rank', label: 'rank', type: 'select', options: rankOptions },
                { name: 'exclude', label: 'exclude', type: 'lines' }
            ] },
            { key: 'feat_pick_choice', label: 'Feat/perk pick', desc: 'Выбор перка', fields: [
                { name: 'id', label: 'id', type: 'text', required: true },
                { name: 'pick', label: 'pick', type: 'number', required: true },
                { name: 'kb_type', label: 'kb_type', type: 'fixed', value: 'perk' },
                { name: 'tag_filter', label: 'tag_filter', type: 'lines' },
                { name: 'exclude', label: 'exclude', type: 'lines' }
            ] },
            { key: 'equipment_pick_choice', label: 'Equipment pick', desc: 'Выбор предмета', fields: [
                { name: 'id', label: 'id', type: 'text', required: true },
                { name: 'pick', label: 'pick', type: 'number', required: true },
                { name: 'kb_type', label: 'kb_type', type: 'fixed', value: 'item' },
                { name: 'options', label: 'options', type: 'lines' },
                { name: 'exclude', label: 'exclude', type: 'lines' },
                { name: 'quantity', label: 'quantity', type: 'number' },
                { name: 'grant', label: 'grant', type: 'json' }
            ] },
            { key: 'spell_pick_choice', label: 'Spell pick', desc: 'Выбор заклинаний', fields: [
                { name: 'id', label: 'id', type: 'text', required: true },
                { name: 'pick', label: 'pick', type: 'number', required: true },
                { name: 'kb_type', label: 'kb_type', type: 'fixed', value: 'spell' },
                { name: 'tradition', label: 'tradition', type: 'text' },
                { name: 'school', label: 'school', type: 'text' },
                { name: 'level_min', label: 'level_min', type: 'number' },
                { name: 'level_max', label: 'level_max', type: 'number' },
                { name: 'grant', label: 'grant', type: 'json' }
            ] }
        ];

        var grantDefs = [
            { key: 'resource_gain', label: 'Resource gain', fields: [
                { name: 'resource', label: 'resource', type: 'select', options: ['hp', 'ep', 'skill_points', 'feat_points', 'perk_points', 'language_slots'] },
                { name: 'value', label: 'value', type: 'number', required: true },
                { name: 'stack_mode', label: 'stack_mode', type: 'select', options: ['add', 'set'] }
            ] },
            { key: 'skill_rank', label: 'Skill rank', fields: [
                { name: 'kb_type', label: 'kb_type', type: 'fixed', value: 'skill' },
                { name: 'kb_key', label: 'kb_key', type: 'kb_key', kbTypeField: 'kb_type', required: true },
                { name: 'rank', label: 'rank', type: 'select', options: rankOptions },
                { name: 'mode', label: 'mode', type: 'select', options: ['set', 'max', 'add'] }
            ] },
            { key: 'kb_grant', label: 'KB grant', fields: [
                { name: 'kb_type', label: 'kb_type', type: 'select', options: kbTypes },
                { name: 'kb_key', label: 'kb_key', type: 'kb_key', kbTypeField: 'kb_type', required: true },
                { name: 'amount', label: 'amount', type: 'number' },
                { name: 'flags', label: 'flags', type: 'json' }
            ] },
            { key: 'item_grant', label: 'Item grant', fields: [
                { name: 'kb_type', label: 'kb_type', type: 'fixed', value: 'item' },
                { name: 'kb_key', label: 'kb_key', type: 'kb_key', kbTypeField: 'kb_type', required: true },
                { name: 'qty', label: 'qty', type: 'number' },
                { name: 'bind', label: 'bind', type: 'checkbox' },
                { name: 'equipped', label: 'equipped', type: 'checkbox' },
                { name: 'slot', label: 'slot', type: 'text' }
            ] },
            { key: 'condition_grant', label: 'Condition grant', fields: [
                { name: 'kb_type', label: 'kb_type', type: 'fixed', value: 'condition' },
                { name: 'kb_key', label: 'kb_key', type: 'kb_key', kbTypeField: 'kb_type', required: true },
                { name: 'duration', label: 'duration', type: 'text' },
                { name: 'stacks', label: 'stacks', type: 'number' },
                { name: 'intensity', label: 'intensity', type: 'number' }
            ] },
            { key: 'resistance_grant', label: 'Resistance grant', fields: [
                { name: 'damage_type', label: 'damage_type', type: 'text', required: true },
                { name: 'value', label: 'value', type: 'number', required: true }
            ] },
            { key: 'weakness_grant', label: 'Weakness grant', fields: [
                { name: 'damage_type', label: 'damage_type', type: 'text', required: true },
                { name: 'value', label: 'value', type: 'number', required: true }
            ] },
            { key: 'speed_grant', label: 'Speed grant', fields: [
                { name: 'speed_type', label: 'speed_type', type: 'select', options: ['walk', 'fly', 'swim', 'climb', 'burrow'] },
                { name: 'value', label: 'value', type: 'number', required: true },
                { name: 'condition', label: 'condition', type: 'text' }
            ] },
            { key: 'sense_grant', label: 'Sense grant', fields: [
                { name: 'sense_type', label: 'sense_type', type: 'text', required: true },
                { name: 'range', label: 'range', type: 'number' }
            ] }
        ];

        var traitFields = [
            { name: 'key', label: 'key', type: 'text', required: true, hint: 'Ключ особенности (slug)' },
            { name: 'title_ru', label: 'title_ru', type: 'text' },
            { name: 'title_en', label: 'title_en', type: 'text' },
            { name: 'desc_ru', label: 'desc_ru', type: 'textarea' },
            { name: 'desc_en', label: 'desc_en', type: 'textarea' },
            { name: 'tags', label: 'tags', type: 'lines' },
            { name: 'meta', label: 'meta', type: 'json' }
        ];

        // defs for profiles
        var effectFields = [
            { name: 'type', label: 'type', type: 'text', hint: 'damage/heal/buff/debuff/status/utility/etc' },
            { name: 'value', label: 'value', type: 'text', hint: 'например 2d6 или +2 или "stunned 1"' },
            { name: 'damage_type', label: 'damage_type', type: 'text', hint: 'fire/cold/acid/kinetic/etc' },
            { name: 'duration', label: 'duration', type: 'text', hint: 'например 1 round / 1 minute / permanent' },
            { name: 'target', label: 'target', type: 'text', hint: 'self/ally/enemy/area' }
        ];

        var modifierFields = [
            { name: 'stat', label: 'stat', type: 'text', hint: 'str/dex/con/int/wis/cha или любой ключ' },
            { name: 'value', label: 'value', type: 'number' },
            { name: 'mode', label: 'mode', type: 'select', options: ['add', 'set', 'max'] },
            { name: 'condition', label: 'condition', type: 'text', hint: 'если бонус условный — опиши коротко' }
        ];

        // ---------- DOM refs ----------
        var fields = {
            errors: root.querySelector('#kb-rules-errors'),
            rawError: root.querySelector('#kb-raw-error'),

            size: root.querySelector('#kb-size'),
            creature: root.querySelector('#kb-creature'),
            speed: root.querySelector('#kb-speed'),
            hpBase: root.querySelector('#kb-hp-base'),

            fixed: root.querySelector('#kb-fixed-bonuses'),
            choices: root.querySelector('#kb-choices-list'),
            grants: root.querySelector('#kb-grants-list'),
            traits: root.querySelector('#kb-traits-list'),

            profileFields: root.querySelector('#kb-profile-fields'),
            profileLists: root.querySelector('#kb-profile-lists')
        };

        if (uiProfile === 'heritage' && isRaceHead) {
            fields.size.value = state.size || 'medium';
            fields.creature.value = state.creature_type || 'humanoid';
            fields.speed.value = numberOrZero(state.speed != null ? state.speed : 30);
            fields.hpBase.value = numberOrZero(state.hp_base != null ? state.hp_base : 10);
        }

        // ---------- validation / payload ----------
        function getDef(defs, key) {
            for (var i = 0; i < defs.length; i += 1) {
                if (defs[i].key === key) return defs[i];
            }
            return null;
        }

        function validate() {
            var errors = [];

            if (!state.schema) errors.push('schema: required');

            // Heritage validation
            if (uiProfile === 'heritage') {
                function validateSlugList(values, prefix) {
                    (values || []).forEach(function (v) {
                        if (!slugPattern.test(v)) errors.push(prefix + ': invalid key ' + v);
                    });
                }

                state.choices.forEach(function (item, i) {
                    if (!item.type) {
                        errors.push('choices[' + i + ']: missing type');
                        return;
                    }
                    var def = getDef(choiceDefs, item.type);
                    if (!def) return;
                    def.fields.forEach(function (f) {
                        if (f.required && (item[f.name] == null || item[f.name] === '')) {
                            errors.push('choices[' + i + '].' + f.name + ': required');
                        }
                    });
                    validateSlugList(item.options, 'choices[' + i + '].options');
                    validateSlugList(item.exclude, 'choices[' + i + '].exclude');
                });

                state.traits.forEach(function (t, i) {
                    if (!t.key) errors.push('traits[' + i + '].key: required');
                });
            }

            fields.errors.innerHTML = errors.length
                ? ('<div class="af-kb-help">Ошибки схемы:<br>' + errors.map(esc).join('<br>') + '</div>')
                : '';

            return errors;
        }

        function toPayload() {
            var payload = deepClone(state);

            // ВАЖНО: для class/theme — не тащим race-only поля если они пустые,
            // но если они есть — оставим (на случай гибридов).
            if (uiProfile === 'heritage') {
                // stats всегда нормализуем
                if (!payload.fixed_bonuses) payload.fixed_bonuses = { stats: {} };
                if (!payload.fixed_bonuses.stats) payload.fixed_bonuses.stats = {};
                stats.forEach(function (k) { payload.fixed_bonuses.stats[k] = numberOrZero(payload.fixed_bonuses.stats[k]); });

                // choices types -> back-compat
                payload.choices = (payload.choices || []).map(function (c) {
                    var out = deepClone(c);
                    if (out.type === 'stat_bonus_choice') out.type = 'stat_bonus';
                    else if (out.type === 'kb_pick_choice') out.type = 'kb_pick';
                    else if (out.type === 'language_pick_choice') out.type = 'language_pick';
                    return out;
                });
            }

            // Никаких тех. ключей тут нет — мы их не добавляли.
            return payload;
        }

        var syncRawDebounced = debounce(function () {
            validate();
            var payload = toPayload();
            raw.value = JSON.stringify(payload, null, 2);
            hidden.value = JSON.stringify(payload);
        }, 250);

        function syncFromRaceHead() {
            if (!(uiProfile === 'heritage' && isRaceHead)) {
                syncRawDebounced();
                return;
            }
            state.size = fields.size.value;
            state.creature_type = (fields.creature.value || '').trim() || 'humanoid';
            state.speed = numberOrZero(fields.speed.value);
            state.hp_base = numberOrZero(fields.hpBase.value);
            syncRawDebounced();
        }

        // ---------- render heritage UI ----------
        function renderFixedBonuses() {
            if (!fields.fixed) return;
            fields.fixed.innerHTML = '';

            var rowStats = document.createElement('div');
            rowStats.className = 'af-kb-row';
            stats.forEach(function (key) {
                var box = document.createElement('div');
                box.innerHTML =
                    '<label>' + key + '</label>' +
                    '<input type="number" data-stat="' + key + '" value="' + numberOrZero(state.fixed_bonuses.stats[key]) + '" />' +
                    '<div class="af-kb-help">Фиксированный бонус к атрибуту.</div>';
                rowStats.appendChild(box);
            });
            fields.fixed.appendChild(rowStats);

            var resources = ['hp', 'ep', 'skill_points', 'feat_points', 'perk_points', 'language_slots'];
            var rowRes = document.createElement('div');
            rowRes.className = 'af-kb-row';
            resources.forEach(function (key) {
                var hint = key === 'hp'
                    ? 'Доп. очки здоровья от расы/класса/темы.'
                    : (key === 'skill_points'
                        ? 'Очки навыков для прокачки навыков.'
                        : 'Ресурсное значение.');
                var box = document.createElement('div');
                box.innerHTML =
                    '<label>' + key + '</label>' +
                    '<input type="number" data-resource="' + key + '" value="' + numberOrZero(state.fixed_bonuses[key]) + '" />' +
                    '<div class="af-kb-help">' + hint + '</div>';
                rowRes.appendChild(box);
            });
            fields.fixed.appendChild(rowRes);

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
            if (!container) return;
            container.innerHTML = '';

            dataList.forEach(function (item, index) {
                var card = document.createElement('div');
                card.className = 'af-kb-rule-card';

                var def = getDef(defs, item.type);
                if (!def) {
                    card.innerHTML =
                        '<div class="af-kb-help"><strong>Unknown type:</strong> ' + esc(item.type || 'unknown') + '</div>' +
                        '<label>Raw</label>' +
                        '<textarea data-raw-index="' + index + '">' + esc(JSON.stringify(item, null, 2)) + '</textarea>' +
                        '<button type="button" class="af-kb-remove" data-remove-index="' + index + '">Удалить</button>';
                    container.appendChild(card);
                    return;
                }

                card.innerHTML =
                    '<div class="af-kb-rule-card__title">' +
                        '<strong>' + esc(def.label) + '</strong>' +
                        (def.desc ? '<span class="af-kb-help">' + esc(def.desc) + '</span>' : '') +
                    '</div>';

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
                    if (typeName === 'choice') renderChoices();
                    if (typeName === 'grant') renderGrants();
                    syncRawDebounced();
                });
                card.appendChild(remove);

                container.appendChild(card);
            });

            container.querySelectorAll('textarea[data-raw-index]').forEach(function (ta) {
                ta.addEventListener('input', function () {
                    var idx = Number(ta.getAttribute('data-raw-index'));
                    dataList[idx] = readJson(ta.value, dataList[idx]);
                    syncRawDebounced();
                });
            });

            container.querySelectorAll('[data-remove-index]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var idx = Number(btn.getAttribute('data-remove-index'));
                    dataList.splice(idx, 1);
                    if (typeName === 'choice') renderChoices();
                    if (typeName === 'grant') renderGrants();
                    syncRawDebounced();
                });
            });
        }

        function renderChoices() { renderTypedList(fields.choices, state.choices, choiceDefs, 'choice'); }
        function renderGrants() { renderTypedList(fields.grants, state.grants, grantDefs, 'grant'); }

        function renderTraits() {
            if (!fields.traits) return;
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

        // ---------- render profile UI (skill/spell/item/perk/...) ----------
        function renderProfile() {
            if (!fields.profileFields || !fields.profileLists) return;

            fields.profileFields.innerHTML = '';
            fields.profileLists.innerHTML = '';

            if (uiProfile === 'skill') {
                var def = [
                    { name: 'category', label: 'Category', type: 'text', hint: 'combat/social/tech/knowledge/psi/cyber/...' },
                    { name: 'rank_max', label: 'Rank max', type: 'number' },
                    { name: 'cooldown', label: 'Cooldown', type: 'number', hint: 'в ходах/раундах, если нужно' }
                ];
                var costDef = [
                    { name: 'key', label: 'Cost key', type: 'text', hint: 'mana/stamina/ep/credits/humanity/etc' },
                    { name: 'value', label: 'Cost value', type: 'text', hint: 'число или формула' }
                ];

                var reqDef = [
                    { name: 'level', label: 'Min level', type: 'number' },
                    { name: 'tags_any', label: 'Tags ANY', type: 'lines', hint: 'любой из этих тегов' },
                    { name: 'tags_all', label: 'Tags ALL', type: 'lines', hint: 'все эти теги' }
                ];

                var grid = document.createElement('div');
                grid.className = 'af-kb-row';
                def.forEach(function (d) { grid.appendChild(createInput(d, state.skill, syncRawDebounced)); });
                fields.profileFields.appendChild(grid);

                // cost as KV list
                var costList = [];
                Object.keys(state.skill.cost || {}).forEach(function (k) { costList.push({ key: k, value: String(state.skill.cost[k]) }); });
                renderKvList(fields.profileLists, costList, 'Cost (key/value)', function () {
                    var o = {};
                    costList.forEach(function (row) {
                        var k = (row.key || '').trim();
                        if (!k) return;
                        o[k] = row.value;
                    });
                    state.skill.cost = o;
                    syncRawDebounced();
                });

                var effectsContainer = document.createElement('div');
                effectsContainer.className = 'af-kb-rule-card';
                fields.profileLists.appendChild(effectsContainer);
                renderObjectList(effectsContainer, state.skill.effects, 'Effects', effectFields, syncRawDebounced, { type: '', value: '', damage_type: '', duration: '', target: '' });

                var reqContainer = document.createElement('div');
                reqContainer.className = 'af-kb-rule-card';
                fields.profileLists.appendChild(reqContainer);
                var reqGrid = document.createElement('div');
                reqGrid.className = 'af-kb-row';
                reqDef.forEach(function (d) { reqGrid.appendChild(createInput(d, state.skill.requirements, syncRawDebounced)); });
                reqContainer.appendChild(reqGrid);

                return;
            }

            if (uiProfile === 'spell') {
                var defS = [
                    { name: 'tradition', label: 'Tradition', type: 'text', hint: 'arcane/divine/occult/primal/psi/tech/...' },
                    { name: 'school', label: 'School', type: 'text' },
                    { name: 'level', label: 'Level', type: 'number' },
                    { name: 'cast_time', label: 'Cast time', type: 'text' },
                    { name: 'range', label: 'Range', type: 'text' },
                    { name: 'duration', label: 'Duration', type: 'text' },
                    { name: 'traits', label: 'Traits', type: 'lines', hint: 'теги/трейты заклинания' }
                ];

                var gridS = document.createElement('div');
                gridS.className = 'af-kb-row';
                defS.forEach(function (d) { gridS.appendChild(createInput(d, state.spell, syncRawDebounced)); });
                fields.profileFields.appendChild(gridS);

                var costListS = [];
                Object.keys(state.spell.cost || {}).forEach(function (k) { costListS.push({ key: k, value: String(state.spell.cost[k]) }); });

                renderKvList(fields.profileLists, costListS, 'Cost (key/value)', function () {
                    var o = {};
                    costListS.forEach(function (row) {
                        var k = (row.key || '').trim();
                        if (!k) return;
                        o[k] = row.value;
                    });
                    state.spell.cost = o;
                    syncRawDebounced();
                });

                var effectsS = document.createElement('div');
                effectsS.className = 'af-kb-rule-card';
                fields.profileLists.appendChild(effectsS);
                renderObjectList(effectsS, state.spell.effects, 'Effects', effectFields, syncRawDebounced, { type: '', value: '', damage_type: '', duration: '', target: '' });

                var reqS = document.createElement('div');
                reqS.className = 'af-kb-rule-card';
                fields.profileLists.appendChild(reqS);

                var reqGridS = document.createElement('div');
                reqGridS.className = 'af-kb-row';
                reqGridS.appendChild(createInput({ name: 'level', label: 'Min level', type: 'number' }, state.spell.requirements, syncRawDebounced));
                reqGridS.appendChild(createInput({ name: 'tags_any', label: 'Tags ANY', type: 'lines' }, state.spell.requirements, syncRawDebounced));
                reqGridS.appendChild(createInput({ name: 'tags_all', label: 'Tags ALL', type: 'lines' }, state.spell.requirements, syncRawDebounced));
                reqS.appendChild(reqGridS);

                return;
            }

            if (uiProfile === 'item') {
                var defI = [
                    { name: 'item_type', label: 'Item type', type: 'select', options: ['weapon', 'armor', 'gear', 'consumable', 'cyberware', 'ammo', 'mod', 'implant', 'service'] },
                    { name: 'rarity', label: 'Rarity', type: 'select', options: ['common', 'uncommon', 'rare', 'unique', 'illegal', 'restricted'] },
                    { name: 'slot', label: 'Slot', type: 'text', hint: 'head/body/hand/implant/weapon_mount/etc' },
                    { name: 'price', label: 'Price', type: 'number' },
                    { name: 'currency', label: 'Currency', type: 'text', hint: 'credits/eddies/gold/...' },
                    { name: 'weight', label: 'Weight', type: 'number' },
                    { name: 'stack_max', label: 'Stack max', type: 'number' },
                    { name: 'tags', label: 'Tags', type: 'lines' }
                ];

                var gridI = document.createElement('div');
                gridI.className = 'af-kb-row';
                defI.forEach(function (d) { gridI.appendChild(createInput(d, state.item, syncRawDebounced)); });
                fields.profileFields.appendChild(gridI);

                // on_use
                var useBox = document.createElement('div');
                useBox.className = 'af-kb-rule-card';
                useBox.innerHTML = '<div class="af-kb-rule-card__title"><strong>On use</strong></div>';
                var useGrid = document.createElement('div');
                useGrid.className = 'af-kb-row';
                useGrid.appendChild(createInput({ name: 'cooldown', label: 'Cooldown', type: 'number' }, state.item.on_use, syncRawDebounced));
                useBox.appendChild(useGrid);

                var costListU = [];
                Object.keys(state.item.on_use.cost || {}).forEach(function (k) { costListU.push({ key: k, value: String(state.item.on_use.cost[k]) }); });

                var costWrapU = document.createElement('div');
                costWrapU.className = 'af-kb-rule-card';
                useBox.appendChild(costWrapU);

                renderKvList(costWrapU, costListU, 'Use cost (key/value)', function () {
                    var o = {};
                    costListU.forEach(function (row) {
                        var k = (row.key || '').trim();
                        if (!k) return;
                        o[k] = row.value;
                    });
                    state.item.on_use.cost = o;
                    syncRawDebounced();
                });

                var effWrapU = document.createElement('div');
                effWrapU.className = 'af-kb-rule-card';
                useBox.appendChild(effWrapU);

                renderObjectList(effWrapU, state.item.on_use.effects, 'Use effects', effectFields, syncRawDebounced, { type: '', value: '', damage_type: '', duration: '', target: '' });

                fields.profileLists.appendChild(useBox);

                // on_equip effects + grants
                var equipBox = document.createElement('div');
                equipBox.className = 'af-kb-rule-card';
                equipBox.innerHTML = '<div class="af-kb-rule-card__title"><strong>On equip</strong></div>';

                var effWrapE = document.createElement('div');
                effWrapE.className = 'af-kb-rule-card';
                equipBox.appendChild(effWrapE);
                renderObjectList(effWrapE, state.item.on_equip.effects, 'Equip effects', effectFields, syncRawDebounced, { type: '', value: '', damage_type: '', duration: '', target: '' });

                // grants: используем grantDefs как raw-json поля (простое)
                var grantsWrapE = document.createElement('div');
                grantsWrapE.className = 'af-kb-rule-card';
                equipBox.appendChild(grantsWrapE);

                // для item проще дать "grant json" как массив объектов (без строгих типов)
                var simpleGrantFields = [
                    { name: 'type', label: 'type', type: 'text', hint: 'kb_grant / resource_gain / condition_grant / ...' },
                    { name: 'payload', label: 'payload', type: 'json', hint: 'произвольный объект параметров' }
                ];

                // адаптер: храним как [{type:'', ...}] но UI показываем как {type, payload}
                var grantsUi = (state.item.on_equip.grants || []).map(function (g) {
                    var c = deepClone(g);
                    var t = c.type || '';
                    delete c.type;
                    return { type: t, payload: c };
                });

                renderObjectList(grantsWrapE, grantsUi, 'Equip grants', simpleGrantFields, function () {
                    state.item.on_equip.grants = grantsUi.map(function (row) {
                        var p = deepClone(row.payload || {});
                        p.type = row.type || '';
                        return p;
                    });
                    syncRawDebounced();
                }, { type: '', payload: {} });

                fields.profileLists.appendChild(equipBox);

                // requirements
                var reqBoxI = document.createElement('div');
                reqBoxI.className = 'af-kb-rule-card';
                reqBoxI.innerHTML = '<div class="af-kb-rule-card__title"><strong>Requirements</strong></div>';
                var reqGridI = document.createElement('div');
                reqGridI.className = 'af-kb-row';
                reqGridI.appendChild(createInput({ name: 'level', label: 'Min level', type: 'number' }, state.item.requirements, syncRawDebounced));
                reqGridI.appendChild(createInput({ name: 'tags_any', label: 'Tags ANY', type: 'lines' }, state.item.requirements, syncRawDebounced));
                reqGridI.appendChild(createInput({ name: 'tags_all', label: 'Tags ALL', type: 'lines' }, state.item.requirements, syncRawDebounced));
                reqBoxI.appendChild(reqGridI);
                fields.profileLists.appendChild(reqBoxI);

                return;
            }

            if (uiProfile === 'perk' || uiProfile === 'condition') {
                var defP = [
                    { name: 'kind', label: 'Kind', type: 'select', options: ['perk', 'condition', 'status', 'trait'] },
                    { name: 'duration', label: 'Duration', type: 'text', hint: 'например 1 round / 10 min / permanent' },
                    { name: 'stacks', label: 'Stacks', type: 'number' },
                    { name: 'intensity', label: 'Intensity', type: 'number' },
                    { name: 'tags', label: 'Tags', type: 'lines' }
                ];

                var gridP = document.createElement('div');
                gridP.className = 'af-kb-row';
                defP.forEach(function (d) { gridP.appendChild(createInput(d, state.perk, syncRawDebounced)); });
                fields.profileFields.appendChild(gridP);

                var modsBox = document.createElement('div');
                modsBox.className = 'af-kb-rule-card';
                fields.profileLists.appendChild(modsBox);
                renderObjectList(modsBox, state.perk.modifiers, 'Modifiers', modifierFields, syncRawDebounced, { stat: '', value: 0, mode: 'add', condition: '' });

                // grants as loose list (same adapter style)
                var grantsBox = document.createElement('div');
                grantsBox.className = 'af-kb-rule-card';
                fields.profileLists.appendChild(grantsBox);

                var simpleGrantFields2 = [
                    { name: 'type', label: 'type', type: 'text', hint: 'kb_grant/resource_gain/...' },
                    { name: 'payload', label: 'payload', type: 'json' }
                ];

                var grantsUi2 = (state.perk.grants || []).map(function (g) {
                    var c = deepClone(g);
                    var t = c.type || '';
                    delete c.type;
                    return { type: t, payload: c };
                });

                renderObjectList(grantsBox, grantsUi2, 'Grants', simpleGrantFields2, function () {
                    state.perk.grants = grantsUi2.map(function (row) {
                        var p = deepClone(row.payload || {});
                        p.type = row.type || '';
                        return p;
                    });
                    syncRawDebounced();
                }, { type: '', payload: {} });

                return;
            }

            if (uiProfile === 'language') {
                var defL = [
                    { name: 'family', label: 'Family', type: 'text' },
                    { name: 'script', label: 'Script', type: 'text' },
                    { name: 'rarity', label: 'Rarity', type: 'select', options: ['common', 'uncommon', 'rare', 'ancient', 'secret'] },
                    { name: 'tags', label: 'Tags', type: 'lines' },
                    { name: 'notes', label: 'Notes', type: 'textarea' }
                ];
                var gridL = document.createElement('div');
                gridL.className = 'af-kb-row';
                defL.forEach(function (d) { gridL.appendChild(createInput(d, state.language, syncRawDebounced)); });
                fields.profileFields.appendChild(gridL);

                var grantsBoxL = document.createElement('div');
                grantsBoxL.className = 'af-kb-rule-card';
                fields.profileLists.appendChild(grantsBoxL);

                // языки могут давать пассивки/перки (например знание шифров)
                var simpleGrantFieldsL = [
                    { name: 'type', label: 'type', type: 'text' },
                    { name: 'payload', label: 'payload', type: 'json' }
                ];
                var grantsUiL = (state.language.grants || []).map(function (g) {
                    var c = deepClone(g);
                    var t = c.type || '';
                    delete c.type;
                    return { type: t, payload: c };
                });

                renderObjectList(grantsBoxL, grantsUiL, 'Grants', simpleGrantFieldsL, function () {
                    state.language.grants = grantsUiL.map(function (row) {
                        var p = deepClone(row.payload || {});
                        p.type = row.type || '';
                        return p;
                    });
                    syncRawDebounced();
                }, { type: '', payload: {} });

                return;
            }

            if (uiProfile === 'knowledge') {
                var defK = [
                    { name: 'domain', label: 'Domain', type: 'text', hint: 'science/history/occult/tech/etc' },
                    { name: 'tier', label: 'Tier', type: 'number' },
                    { name: 'tags', label: 'Tags', type: 'lines' }
                ];
                var gridK = document.createElement('div');
                gridK.className = 'af-kb-row';
                defK.forEach(function (d) { gridK.appendChild(createInput(d, state.knowledge, syncRawDebounced)); });
                fields.profileFields.appendChild(gridK);

                var flagsBox = document.createElement('div');
                flagsBox.className = 'af-kb-rule-card';
                fields.profileLists.appendChild(flagsBox);
                renderKvList(flagsBox, state.knowledge.flags, 'Flags (key/value)', syncRawDebounced);

                var grantsBoxK = document.createElement('div');
                grantsBoxK.className = 'af-kb-rule-card';
                fields.profileLists.appendChild(grantsBoxK);

                var simpleGrantFieldsK = [
                    { name: 'type', label: 'type', type: 'text' },
                    { name: 'payload', label: 'payload', type: 'json' }
                ];
                var grantsUiK = (state.knowledge.grants || []).map(function (g) {
                    var c = deepClone(g);
                    var t = c.type || '';
                    delete c.type;
                    return { type: t, payload: c };
                });

                renderObjectList(grantsBoxK, grantsUiK, 'Grants', simpleGrantFieldsK, function () {
                    state.knowledge.grants = grantsUiK.map(function (row) {
                        var p = deepClone(row.payload || {});
                        p.type = row.type || '';
                        return p;
                    });
                    syncRawDebounced();
                }, { type: '', payload: {} });

                return;
            }

            if (uiProfile === 'lore') {
                var defLo = [
                    { name: 'scope', label: 'Scope', type: 'text', hint: 'world/region/faction/person/event' },
                    { name: 'era', label: 'Era', type: 'text', hint: 'например 2277 / "до вторжения" / "после куполов"' },
                    { name: 'tags', label: 'Tags', type: 'lines' }
                ];
                var gridLo = document.createElement('div');
                gridLo.className = 'af-kb-row';
                defLo.forEach(function (d) { gridLo.appendChild(createInput(d, state.lore, syncRawDebounced)); });
                fields.profileFields.appendChild(gridLo);

                var linksBox = document.createElement('div');
                linksBox.className = 'af-kb-rule-card';
                fields.profileLists.appendChild(linksBox);
                renderKvList(linksBox, state.lore.links, 'Links (key/value)', syncRawDebounced);

                var flagsBoxLo = document.createElement('div');
                flagsBoxLo.className = 'af-kb-rule-card';
                fields.profileLists.appendChild(flagsBoxLo);
                renderKvList(flagsBoxLo, state.lore.flags, 'Flags (key/value)', syncRawDebounced);

                return;
            }

            if (uiProfile === 'faction') {
                var defF = [
                    { name: 'alignment', label: 'Alignment', type: 'text' },
                    { name: 'influence', label: 'Influence', type: 'number' },
                    { name: 'tags', label: 'Tags', type: 'lines' }
                ];
                var gridF = document.createElement('div');
                gridF.className = 'af-kb-row';
                defF.forEach(function (d) { gridF.appendChild(createInput(d, state.faction, syncRawDebounced)); });
                fields.profileFields.appendChild(gridF);

                var relBox = document.createElement('div');
                relBox.className = 'af-kb-rule-card';
                fields.profileLists.appendChild(relBox);

                var relFields = [
                    { name: 'target_type', label: 'target_type', type: 'select', options: ['faction', 'race', 'class', 'theme', 'lore', 'knowledge'] },
                    { name: 'target_key', label: 'target_key', type: 'text' },
                    { name: 'relation', label: 'relation', type: 'text', hint: 'ally/enemy/neutral/vassal/...' },
                    { name: 'note', label: 'note', type: 'text' }
                ];
                renderObjectList(relBox, state.faction.relations, 'Relations', relFields, syncRawDebounced, { target_type: 'faction', target_key: '', relation: '', note: '' });

                var grantsBoxF = document.createElement('div');
                grantsBoxF.className = 'af-kb-rule-card';
                fields.profileLists.appendChild(grantsBoxF);

                var simpleGrantFieldsF = [
                    { name: 'type', label: 'type', type: 'text' },
                    { name: 'payload', label: 'payload', type: 'json' }
                ];
                var grantsUiF = (state.faction.grants || []).map(function (g) {
                    var c = deepClone(g);
                    var t = c.type || '';
                    delete c.type;
                    return { type: t, payload: c };
                });

                renderObjectList(grantsBoxF, grantsUiF, 'Grants', simpleGrantFieldsF, function () {
                    state.faction.grants = grantsUiF.map(function (row) {
                        var p = deepClone(row.payload || {});
                        p.type = row.type || '';
                        return p;
                    });
                    syncRawDebounced();
                }, { type: '', payload: {} });

                return;
            }

            // fallback: raw only (но НЕ ломаем сохранение)
            fields.profileFields.innerHTML = '<div class="af-kb-help">Для профиля <strong>' + esc(uiProfile) + '</strong> пока нет UI-формы. Используй raw.</div>';
        }

        // ---------- events / init ----------
        root.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLElement)) return;

            if (uiProfile === 'heritage') {
                var addChoiceType = target.getAttribute('data-add-choice');
                if (addChoiceType) {
                    state.choices.push(deepClone(templates[addChoiceType] || { type: addChoiceType }));
                    renderChoices();
                    syncRawDebounced();
                    return;
                }
                var addGrantType = target.getAttribute('data-add-grant');
                if (addGrantType) {
                    state.grants.push(deepClone(templates[addGrantType] || { type: addGrantType }));
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
                    state.traits.push(deepClone(templates.trait));
                    renderTraits();
                    syncRawDebounced();
                    return;
                }
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

                // Жёстко не “натягиваем race на item”.
                // Просто заменяем state и заново нормализуем по профилю через merged-подход.
                var next = merge3(defaultsForProfile(uiProfile), schemaDefaults, parsed);
                state = deepClone(next);

                // re-normalize by profile (минимум, чтобы UI не падал)
                if (uiProfile === 'heritage') {
                    if (!state.fixed_bonuses) state.fixed_bonuses = { stats: {} };
                    if (!state.fixed_bonuses.stats) state.fixed_bonuses.stats = {};
                    stats.forEach(function (k) { state.fixed_bonuses.stats[k] = numberOrZero(state.fixed_bonuses.stats[k]); });
                    if (!Array.isArray(state.choices)) state.choices = [];
                    if (!Array.isArray(state.grants)) state.grants = [];
                    if (!Array.isArray(state.traits)) state.traits = [];
                    state.choices = state.choices.map(normalizeChoice);

                    if (isRaceHead) {
                        fields.size.value = state.size || 'medium';
                        fields.creature.value = state.creature_type || 'humanoid';
                        fields.speed.value = numberOrZero(state.speed != null ? state.speed : 30);
                        fields.hpBase.value = numberOrZero(state.hp_base != null ? state.hp_base : 10);
                    }

                    renderFixedBonuses();
                    renderChoices();
                    renderGrants();
                    renderTraits();
                } else {
                    // профили типа item/spell/skill/etc
                    renderProfile();
                }

                syncRawDebounced();
            }
        });

        if (uiProfile === 'heritage' && isRaceHead) {
            [fields.size, fields.creature, fields.speed, fields.hpBase].forEach(function (field) {
                if (!field) return;
                field.addEventListener('input', syncFromRaceHead);
                field.addEventListener('change', syncFromRaceHead);
            });
        }

        // ---------- first render ----------
        if (uiProfile === 'heritage') {
            renderFixedBonuses();
            renderChoices();
            renderGrants();
            renderTraits();
        } else {
            renderProfile();
        }

        syncRawDebounced();
    }

    document.addEventListener('DOMContentLoaded', function () {
        initMetaUi();
        initDataUi();
    });
})();

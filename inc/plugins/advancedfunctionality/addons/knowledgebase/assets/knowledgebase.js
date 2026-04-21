(function () {
    function isAllowedKbEditorField(field) {
        if (!field || field.tagName !== 'TEXTAREA') {
            return false;
        }

        if (String(field.getAttribute('data-af-kb-editor-policy') || '').toLowerCase() === 'allow') {
            return true;
        }

        var name = String(field.getAttribute('name') || '').trim().toLowerCase();
        if (!name) {
            return false;
        }

        if (
            name === 'short_ru' || name === 'short_en' ||
            name === 'body_ru' || name === 'body_en' ||
            name === 'tech_ru' || name === 'tech_en'
        ) {
            return true;
        }

        return /^blocks\[\d+]\[content_(ru|en)]$/.test(name);
    }

    function afKbEndpoint(name, fallback) {
        var map = (window && window.afKbEndpoints) ? window.afKbEndpoints : null;
        return (map && map[name]) ? map[name] : fallback;
    }

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

    function destroyEditorInstance(field) {
        if (!field) {
            return false;
        }

        var instance = getEditorInstance(field);
        if (!instance) {
            return false;
        }

        try {
            if (typeof instance.destroy === 'function') {
                instance.destroy();
            }
        } catch (err) {
            return false;
        }

        field.removeAttribute('data-af-kb-editor');
        field.removeAttribute('data-af-kb-editor-allowed');
        field.removeAttribute('data-af-kb-editor-init');
        field.removeAttribute('data-af-kb-editor-policy');
        field.dataset.afKbEditor = '';
        return true;
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

    function markEditorPolicy(root) {
        var container = root || document;
        var fields = container.querySelectorAll('textarea');

        Array.prototype.forEach.call(fields, function (field) {
            var allowed = isAllowedKbEditorField(field);

            if (allowed) {
                field.setAttribute('data-af-kb-editor-allowed', '1');
                field.setAttribute('data-af-kb-editor-policy', 'allow');
                field.classList.remove('af-kb-editor-deny');
                field.classList.add('af-kb-editor-allow');
            } else {
                field.setAttribute('data-af-kb-editor-policy', 'deny');
                field.classList.remove('af-kb-editor-allow');
                field.classList.add('af-kb-editor-deny');
            }
        });
    }

    function cleanupDeniedEditors(root) {
        var container = root || document;
        var fields = container.querySelectorAll('textarea');

        Array.prototype.forEach.call(fields, function (field) {
            if (isAllowedKbEditorField(field)) {
                return;
            }

            destroyEditorInstance(field);
        });
    }

    function initEditors(root) {
        if (!window.jQuery || !window.jQuery.fn || typeof window.jQuery.fn.sceditor !== 'function') {
            return;
        }

        var container = root || document;
        markEditorPolicy(container);
        cleanupDeniedEditors(container);

        var fields = container.querySelectorAll('textarea');
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
            var defaultStyle = window.sceditor && window.sceditor.defaultOptions
                ? window.sceditor.defaultOptions.style
                : '';
            if (defaultStyle) {
                baseOptions.style = defaultStyle;
            }
        }

        Array.prototype.forEach.call(fields, function (field) {
            if (!isAllowedKbEditorField(field)) {
                return;
            }

            if (getEditorInstance(field)) {
                field.dataset.afKbEditor = '1';
                field.setAttribute('data-af-kb-editor-init', '1');
                return;
            }

            if (field.dataset.afKbEditor === '1' || field.getAttribute('data-af-kb-editor-init') === '1') {
                return;
            }

            field.dataset.afKbEditor = '1';
            field.setAttribute('data-af-kb-editor-init', '1');

            var options = Object.assign({}, baseOptions, { startInSourceMode: true });
            window.jQuery(field).sceditor(options);
        });
    }

    function refreshEditorPolicy(root) {
        var container = root || document;
        markEditorPolicy(container);
        cleanupDeniedEditors(container);
        initEditors(container);
    }

    function scheduleRefresh(root) {
        var container = root || document;

        if (container.__afKbEditorRefreshScheduled) {
            return;
        }

        container.__afKbEditorRefreshScheduled = true;

        window.setTimeout(function () {
            container.__afKbEditorRefreshScheduled = false;
            refreshEditorPolicy(container);
        }, 0);
    }

    function hasKbEditorSurface(root) {
        var container = root || document;
        if (!container || typeof container.querySelector !== 'function') {
            return false;
        }

        if (container.querySelector('#af-kb-rules-ui, #af-kb-meta-ui, #af-kb-blocks, #af-kb-relations, .af-kb-form')) {
            return true;
        }

        return !!container.querySelector(
            'textarea[name="short_ru"], ' +
            'textarea[name="short_en"], ' +
            'textarea[name="body_ru"], ' +
            'textarea[name="body_en"], ' +
            'textarea[name="tech_ru"], ' +
            'textarea[name="tech_en"]'
        );
    }

    function mutationNeedsRefresh(mutations) {
        for (var i = 0; i < mutations.length; i += 1) {
            var mutation = mutations[i];
            var groups = [mutation.addedNodes, mutation.removedNodes];

            for (var g = 0; g < groups.length; g += 1) {
                var nodes = groups[g];
                if (!nodes || !nodes.length) {
                    continue;
                }

                for (var n = 0; n < nodes.length; n += 1) {
                    var node = nodes[n];
                    if (!node || node.nodeType !== 1) {
                        continue;
                    }

                    if (
                        (typeof node.matches === 'function' && node.matches('textarea, .af-kb-block-item, .af-kb-rel-item-edit')) ||
                        (typeof node.querySelector === 'function' && node.querySelector('textarea, .af-kb-block-item, .af-kb-rel-item-edit'))
                    ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    function observeEditorLeaks(root) {
        var container = root || document.body;
        if (!container || !window.MutationObserver || container.__afKbEditorObserverAttached) {
            return;
        }

        if (!hasKbEditorSurface(container)) {
            return;
        }

        var observer = new MutationObserver(function (mutations) {
            if (!mutationNeedsRefresh(mutations)) {
                return;
            }

            scheduleRefresh(container);
        });

        observer.observe(container, {
            childList: true,
            subtree: true
        });

        container.__afKbEditorObserverAttached = true;
    }

    window.__afKbEditorGuard = {
        isAllowedKbEditorField: isAllowedKbEditorField,
        getEditorInstance: getEditorInstance,
        destroyEditorInstance: destroyEditorInstance,
        markEditorPolicy: markEditorPolicy,
        cleanupDeniedEditors: cleanupDeniedEditors,
        initEditors: initEditors,
        refreshEditorPolicy: refreshEditorPolicy,
        observeEditorLeaks: observeEditorLeaks,
        scheduleRefresh: scheduleRefresh
    };

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
                refreshEditorPolicy(lastElement);
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
                    scheduleRefresh(container);
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

        refreshEditorPolicy(blockElement);
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
                    tags: ['humanoid', 'common']
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
                short_en: 'A proficiency skill (PF2e/Starfinder-style): tied to an attribute and trained ranks.',
                body_en: 'Describe what this skill measures and what typical checks/actions use it.',
                meta_json: JSON.stringify({
                    tags: ['skill'],
                    skill: {
                        category: 'general',
                        key_stat: 'dex',
                        rank_max: 4,
                        armor_check_penalty: false,
                        trained_only: false,
                        notes: ''
                    }
                }, null, 2),
                blocks: [
                    {
                        block_key: 'rules',
                        title_ru: 'Правила',
                        title_en: 'Rules',
                        content_en: 'This skill is used for balance, tumbling, and escape maneuvers.',
                        data_json: '{}',
                        sortorder: 0
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

            refreshEditorPolicy(document);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var blockRepeater = initRepeater('af-kb-blocks', 'af-kb-add-block', 'af-kb-block-template', 'data-index');
        initRepeater('af-kb-relations', 'af-kb-add-relation', 'af-kb-relation-template', 'data-index');
        applyTemplate(blockRepeater);

        var editorRoot =
            document.querySelector('.af-kb-form') ||
            document.getElementById('af-kb-rules-ui') ||
            document.getElementById('af-kb-meta-ui') ||
            document.getElementById('af-kb-blocks') ||
            null;

        if (editorRoot) {
            refreshEditorPolicy(editorRoot);
            observeEditorLeaks(editorRoot);
        }

        initCopyButtons();
        initTechTemplateButtons();
    });
})();

(function () {
    var SKILL_STAT_OPTIONS = ['str', 'dex', 'con', 'int', 'wis', 'cha'];

    function normalizeSkillStatValue(skillObj) {
        if (!skillObj || typeof skillObj !== 'object') {
            return '';
        }

        var keyStat = String(skillObj.key_stat || '').trim().toLowerCase();
        var attribute = String(skillObj.attribute || '').trim().toLowerCase();
        var canonical = '';

        if (SKILL_STAT_OPTIONS.indexOf(keyStat) !== -1) {
            canonical = keyStat;
        } else if (SKILL_STAT_OPTIONS.indexOf(attribute) !== -1) {
            canonical = attribute;
        }

        if (canonical) {
            skillObj.key_stat = canonical;
            // Backward compatibility for consumers that still read skill.attribute.
            skillObj.attribute = canonical;
        } else {
            delete skillObj.key_stat;
            delete skillObj.attribute;
        }

        return canonical;
    }

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

    function getEditorInstance(field) {
        if (!field) return null;
        if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.sceditor === 'function') {
            try { return window.jQuery(field).sceditor('instance'); } catch (e) { return null; }
        }
        return null;
    }

    function getFieldValue(field) {
        if (!field) return '';
        var inst = getEditorInstance(field);
        if (inst && typeof inst.val === 'function') {
            return String(inst.val() || '');
        }
        return String(field.value || '');
    }

    function setFieldValue(field, value) {
        if (!field) return;
        var inst = getEditorInstance(field);
        if (inst && typeof inst.val === 'function') {
            inst.val(String(value || ''));
            // на всякий: некоторые сборки SCEditor любят updateOriginal()
            if (typeof inst.updateOriginal === 'function') {
                try { inst.updateOriginal(); } catch (e) {}
            }
            return;
        }
        field.value = String(value || '');
    }

    var stats = ['str', 'dex', 'con', 'int', 'wis', 'cha'];
    var rankOptions = ['trained', 'expert', 'master', 'legendary', '0', '1', '2', '3', '4'];
    var slugPattern = /^[a-z0-9_\-:.]+$/;

    // KB types for autocomplete endpoints (если у тебя другие — просто добавь/переименуй тут)
    var kbTypes = ['race', 'class', 'theme', 'lore', 'knowledge', 'language', 'skill', 'item', 'spell', 'perk', 'condition', 'faction'];
    function detectKbMechanic() {
        var rulesRoot = document.getElementById('af-kb-rules-ui');
        if (!rulesRoot) {
            return 'dnd';
        }

        var type = String(rulesRoot.getAttribute('data-type') || '').trim();
        var mechanic = String(rulesRoot.getAttribute('data-mechanic') || 'dnd').trim().toLowerCase() || 'dnd';

        if (/^arpg_/i.test(type)) {
            mechanic = 'arpg';
        }

        return mechanic;
    }

    function initMetaUi() {
        var root = document.getElementById('af-kb-meta-ui');
        var raw = document.getElementById('af-kb-meta-json');
        if (!root || !raw) {
            return;
        }

        // Для ARPG верхний meta-ui блок не нужен:
        // там уже есть отдельный envelope, а реальные media-поля живут в обычной форме записи.
        if (detectKbMechanic() === 'arpg') {
            root.innerHTML = '';
            return;
        }

        var meta = readJson(raw.value, {});
        if (!meta.ui || typeof meta.ui !== 'object') {
            meta.ui = {};
        }
        if (!Array.isArray(meta.tags)) {
            meta.tags = [];
        }

        root.innerHTML = [
            '<details open="open" class="af-kb-collapsible">',
            '<summary>Tags</summary>',
            '<label>Tags (comma separated)</label>',
            '<input type="text" id="af-kb-meta-tags" />',
            '</details>',
            '<details open="open" class="af-kb-collapsible">',
            '<summary>Media/UI</summary>',
            '<div class="af-kb-row">',
            '<div><label>Icon URL (meta.ui.icon_url)</label><input type="text" id="af-kb-meta-icon-url" /></div>',
            '<div><label>Icon Class (meta.ui.icon_class)</label><input type="text" id="af-kb-meta-icon-class" /></div>',
            '</div>',
            '<div class="af-kb-row">',
            '<div><label>Background URL (meta.ui.background_url)</label><input type="text" id="af-kb-meta-bg-url" /></div>',
            '<div><label>Background Tab URL (meta.ui.background_tab_url)</label><input type="text" id="af-kb-meta-bg-tab-url" /></div>',
            '</div>',
            '</details>',
            '<details class="af-kb-collapsible">',
            '<summary>Debug raw JSON</summary>',
            '<textarea id="af-kb-meta-raw-preview" rows="8" readonly="readonly"></textarea>',
            '</details>'
        ].join('');

        var fields = {
            tags: root.querySelector('#af-kb-meta-tags'),
            iconUrl: root.querySelector('#af-kb-meta-icon-url'),
            iconClass: root.querySelector('#af-kb-meta-icon-class'),
            bgUrl: root.querySelector('#af-kb-meta-bg-url'),
            bgTabUrl: root.querySelector('#af-kb-meta-bg-tab-url'),
            rawPreview: root.querySelector('#af-kb-meta-raw-preview')
        };
        if (!fields.tags || !fields.iconUrl || !fields.iconClass || !fields.bgUrl || !fields.bgTabUrl) {
            return;
        }

        fields.tags.value = (meta.tags || []).join(', ');
        fields.iconUrl.value = meta.ui.icon_url || '';
        fields.iconClass.value = meta.ui.icon_class || '';
        fields.bgUrl.value = meta.ui.background_url || '';
        fields.bgTabUrl.value = meta.ui.background_tab_url || '';

        function syncMeta() {
            meta.tags = splitCsv(fields.tags.value);
            meta.ui.icon_url = fields.iconUrl.value.trim();
            meta.ui.icon_class = fields.iconClass.value.trim();
            meta.ui.background_url = fields.bgUrl.value.trim();
            meta.ui.background_tab_url = fields.bgTabUrl.value.trim();
            raw.value = JSON.stringify(meta, null, 2);
            if (fields.rawPreview) {
                fields.rawPreview.value = raw.value;
            }
        }

        Object.keys(fields).forEach(function (key) {
            if (key === 'rawPreview') {
                return;
            }
            fields[key].addEventListener('input', syncMeta);
        });
        syncMeta();
    }

    // ---------- RULES UI (главная часть правки) ----------
    function initRulesUi() {
        var root = document.getElementById('af-kb-rules-ui');
        var raw = document.getElementById('af-kb-rules-json-raw');
        var metaRaw = document.getElementById('af-kb-meta-json');
        if (!root || !raw || !metaRaw) {
            return;
        }

        var type = (root.getAttribute('data-type') || '').trim(); // race/class/theme/lore/...
        var mechanic = String(root.getAttribute('data-mechanic') || 'dnd').trim().toLowerCase() || 'dnd';
        // Forensic hardening: ARPG type keys must always route to ARPG UI even if stale/duplicate
        // af_kb_types rows leaked wrong mechanic_key into the template dataset.
        if (/^arpg_/i.test(type)) {
            mechanic = 'arpg';
        }
        var typeSchema = readJson(root.getAttribute('data-type-schema') || '{}', {});
        var itemKindOptionsRaw = readJson(root.getAttribute('data-item-kinds') || '[]', []);
        var itemKindOptions = (Array.isArray(itemKindOptionsRaw) && itemKindOptionsRaw.length ? itemKindOptionsRaw : [
            { value: 'weapon' }, { value: 'armor' }, { value: 'gear' }, { value: 'consumable' },
            { value: 'ammo' }, { value: 'augmentation' }, { value: 'artifact' }, { value: 'unique' }
        ]).map(function (opt) {
            if (opt && typeof opt === 'object') return String(opt.value || '');
            return String(opt || '');
        });
        var canonicalItemKinds = ['weapon', 'armor', 'gear', 'consumable', 'ammo', 'augmentation', 'artifact', 'unique'];
        var itemKindAliases = {
            cyberware: 'augmentation',
            implant: 'augmentation',
            weapon_offhand: 'weapon',
            helmet: 'armor'
        };

        function normalizeItemKind(kind) {
            var rawKind = String(kind || '').trim().toLowerCase();
            if (!rawKind) return 'gear';
            rawKind = itemKindAliases[rawKind] || rawKind;
            if (canonicalItemKinds.indexOf(rawKind) === -1) return 'gear';
            return rawKind;
        }

        itemKindOptions = itemKindOptions.map(function (kind) {
            return normalizeItemKind(kind);
        }).filter(function (kind, idx, arr) {
            return arr.indexOf(kind) === idx;
        });

        var augmentationSlotOptions = ['', 'nervous_system', 'circulatory_system', 'immune_system', 'integumentary_system', 'operating_system', 'skeleton', 'arms', 'hands', 'legs', 'eyes', 'frontal_cortex', 'cyberaudio'];
        var itemBonusTargetOptions = ['', 'hp', 'hp_max', 'armor', 'damage', 'initiative', 'speed', 'carry', 'ep', 'attribute_points', 'skill_points', 'knowledge_slots', 'language_slots', 'str', 'dex', 'con', 'int', 'wis', 'cha'];
        var itemBonusTypeOptions = ['resource', 'stat', 'attribute', 'skill', 'custom'];
        var slotByKind = {
            armor: ['head', 'body', 'hands', 'legs', 'feet', 'back', 'belt'],
            weapon: ['weapon_mainhand', 'weapon_offhand', 'weapon_twohand', 'weapon_ranged', 'weapon_melee'],
            consumable: ['', 'support_1', 'support_2', 'support_3', 'support_4'],
            ammo: ['ammo', 'ammo_pouch'],
            gear: ['', 'gear', 'accessory'],
            artifact: ['', 'artifact', 'accessory'],
            unique: ['', 'weapon_mainhand', 'weapon_offhand', 'weapon_twohand', 'weapon_ranged', 'weapon_melee', 'head', 'body', 'hands', 'legs', 'feet', 'back', 'belt', 'support_1', 'support_2', 'support_3', 'support_4', 'ammo', 'ammo_pouch', 'gear', 'artifact', 'accessory']
        };
        var uniqueRoleToKind = { weapon: 'weapon', armor: 'armor', augmentation: 'augmentation', artifact: 'artifact', gear: 'gear', consumable: 'consumable', ammo: 'ammo' };

        // ВАЖНО: мы больше НЕ делаем один и тот же UI на все типы.
        // Профиль UI: либо задаётся схемой (ui_profile), либо определяется по type.
        function resolveUiProfile(entryType, schema) {
            var t = String(entryType || '').trim();

            // ВАЖНО: эти три типа ДОЛЖНЫ иметь один и тот же UI-профиль (как у расы),
            // независимо от того, что лежит в schema.ui_profile.
            if (t === 'race' || t === 'race_variant' || t === 'class' || t === 'theme') {
                return 'heritage';
            }

            // Если не один из “большой тройки” — тогда можно доверять схеме.
            var p = (schema && typeof schema.ui_profile === 'string') ? schema.ui_profile.trim() : '';
            if (p) return p;

            // Явная мапа по остальным типам
            if (t === 'skill') return 'skill';
            if (t === 'spell') return 'spell';
            if (t === 'item') return 'item';
            if (t === 'character') return 'character';
            if (t === 'bestiary') return 'bestiary';
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
        if (type === 'race' || type === 'race_variant' || type === 'class' || type === 'theme') {
            uiProfile = 'heritage';
        }
        // type_profile в JSON должен совпадать с entry type для race/race_variant/class/theme
        var expectedTypeProfile = (type === 'race' || type === 'race_variant' || type === 'class' || type === 'theme')
            ? type
            : uiProfile;

        // ВАЖНО: для entry type=knowledge type_profile ДОЛЖЕН быть knowledge,
        // иначе бекенд ругается mismatch. При этом структура данных остаётся skill+knowledge_group.
        if (type === 'knowledge') {
            expectedTypeProfile = 'knowledge';
        }




        // rules_json может быть выключен для некоторых типов — тогда raw-only
        var rulesEditorEnabled = (typeSchema.ui_rules_editor !== false) && (typeSchema.rules_enabled !== false);

        function bindRawOnlyMode(message) {
            root.innerHTML = '<div class="af-kb-help">' + esc(message) + '</div>';
            syncRulesToMeta(readJson(getFieldValue(raw) || '{}', {}));

            var sync = function () {
                syncRulesToMeta(readJson(getFieldValue(raw) || '{}', {}));
            };

            // input может не стрелять у sceditor, поэтому страхуемся
            raw.addEventListener('input', sync);
            raw.addEventListener('change', sync);
        }

        function bindArpgMode() {
            var payload = readJson(getFieldValue(raw) || '{}', {});
            if (!payload || typeof payload !== 'object' || Array.isArray(payload)) payload = {};

            function parseJsonSafe(text, fallback) {
                try { return JSON.parse(text); } catch (e) { return fallback; }
            }

            function deepClone(value) {
                if (value == null) return value;
                try {
                    return JSON.parse(JSON.stringify(value));
                } catch (e) {
                    return value;
                }
            }

            function isPlainObject(value) {
                return !!value && typeof value === 'object' && !Array.isArray(value);
            }

            function deepMergeDefaults(target, defaults) {
                if (Array.isArray(defaults)) {
                    return Array.isArray(target) ? target : deepClone(defaults);
                }

                if (!isPlainObject(defaults)) {
                    return target == null ? defaults : target;
                }

                var out = isPlainObject(target) ? target : {};
                Object.keys(defaults).forEach(function (key) {
                    var defVal = defaults[key];
                    var curVal = out[key];

                    if (Array.isArray(defVal)) {
                        if (!Array.isArray(curVal)) {
                            out[key] = deepClone(defVal);
                        }
                        return;
                    }

                    if (isPlainObject(defVal)) {
                        out[key] = deepMergeDefaults(curVal, defVal);
                        return;
                    }

                    if (curVal == null || curVal === '') {
                        out[key] = defVal;
                    }
                });

                return out;
            }

            function ensureObject(parent, key) {
                if (!parent[key] || typeof parent[key] !== 'object' || Array.isArray(parent[key])) parent[key] = {};
                return parent[key];
            }

            function ensureArray(parent, key) {
                if (!Array.isArray(parent[key])) parent[key] = [];
                return parent[key];
            }

            var typeMap = {
                arpg_origin: 'origin',
                arpg_archetype: 'archetype',
                arpg_element: 'element',
                arpg_faction: 'faction',
                arpg_lore: 'lore',
                arpg_ability: 'ability',
                arpg_talent: 'talent',
                arpg_item: 'item',
                arpg_bestiary: 'bestiary',
                arpg_mechanics: 'service_mechanics'
            };
            var arpgTypeAliases = {
                bestiary_arpg: 'bestiary',
                ability_arpg: 'ability',
                talent_arpg: 'talent',
                item_arpg: 'item',
                lore_arpg: 'lore',
                class_arpg: 'archetype',
                provenance: 'origin',
                race_origin: 'origin'
            };

            var simpleTypes = ['origin', 'archetype', 'element', 'faction', 'lore'];
            var heavyTypes = ['ability', 'talent', 'item', 'bestiary'];
            var serviceKinds = ['mechanic_profile', 'resource_def', 'status_def', 'modifier_template', 'formula_def', 'trigger_template', 'condition_template', 'scaling_table', 'combat_template', 'snippet'];

            function getRootDefaults() {
                var defaults = (typeSchema && typeSchema.root_defaults && typeof typeSchema.root_defaults === 'object')
                    ? deepClone(typeSchema.root_defaults)
                    : {};

                if (!defaults || typeof defaults !== 'object' || Array.isArray(defaults)) {
                    defaults = {};
                }

                return defaults;
            }

            function normalizeArpgEntityType(value) {
                var raw = String(value || '').trim().toLowerCase();
                if (!raw) return '';
                if (typeMap[raw]) return typeMap[raw];
                if (arpgTypeAliases[raw]) return arpgTypeAliases[raw];
                return raw;
            }

            function ensureRoot() {
                var rootDefaults = getRootDefaults();

                payload = deepMergeDefaults(payload, rootDefaults);

                payload.schema = 'af_kb.arpg.meta.v1';
                payload.mechanic = 'arpg';

                if (!Array.isArray(payload.tags)) payload.tags = [];
                var ui = ensureObject(payload, 'ui');
                if (typeof ui.icon_class !== 'string') ui.icon_class = '';
                if (typeof ui.icon_url !== 'string') ui.icon_url = '';
                if (typeof ui.background_url !== 'string') ui.background_url = '';
                if (typeof ui.background_tab_url !== 'string') ui.background_tab_url = '';

                ensureArray(payload, 'blocks');

                var rules = ensureObject(payload, 'rules');
                if (typeof rules.schema !== 'string' || !rules.schema) rules.schema = 'af_kb.arpg.rules.v1';
                if (typeof rules.version !== 'string' || !rules.version) rules.version = '1.0';

                var entityType = normalizeArpgEntityType(type) || normalizeArpgEntityType(rules.type_profile) || 'origin';
                rules.type_profile = entityType;

                // Влить defaults именно в rules после того, как type_profile уже известен.
                if (rootDefaults.rules && typeof rootDefaults.rules === 'object' && !Array.isArray(rootDefaults.rules)) {
                    payload.rules = deepMergeDefaults(payload.rules, rootDefaults.rules);
                    rules = payload.rules;
                }

                if (typeof rules.service_kind !== 'string') rules.service_kind = '';
                if (entityType === 'service_mechanics' && serviceKinds.indexOf(rules.service_kind) === -1) {
                    rules.service_kind = serviceKinds[0];
                }

                rules.visibility = ensureObject(rules, 'visibility');
                if (typeof rules.visibility.catalog !== 'boolean') rules.visibility.catalog = false;
                if (typeof rules.visibility.search !== 'boolean') rules.visibility.search = false;
                if (typeof rules.visibility.internal !== 'boolean') rules.visibility.internal = false;

                // Страховка для arpg_item: эти поля должны существовать в payload даже до первого input/change.
                if (entityType === 'item') {
                    if (typeof rules.item_kind !== 'string' || !rules.item_kind) rules.item_kind = 'weapon';
                    if (typeof rules.equip_slot !== 'string' || !rules.equip_slot) rules.equip_slot = 'weapon_one_hand';
                    if (typeof rules.rarity !== 'string' || !rules.rarity) rules.rarity = 'common';
                    if (typeof rules.subtype !== 'string') rules.subtype = '';
                    if (typeof rules.progression_stage !== 'string' || !rules.progression_stage) rules.progression_stage = 'base';
                    if (!Array.isArray(rules.base_stats)) rules.base_stats = [];
                    if (!Array.isArray(rules.modifiers)) rules.modifiers = [];
                    if (!Array.isArray(rules.effects)) rules.effects = [];
                    if (!Array.isArray(rules.passive_effects)) rules.passive_effects = [];
                    if (!Array.isArray(rules.triggers)) rules.triggers = [];
                    if (!Array.isArray(rules.grants)) rules.grants = [];
                    if (!Array.isArray(rules.upgrade_steps)) rules.upgrade_steps = [];

                    if (typeof rules.level_min !== 'number' || !isFinite(rules.level_min)) rules.level_min = 1;
                    if (typeof rules.level_max !== 'number' || !isFinite(rules.level_max)) rules.level_max = 100;
                    if (typeof rules.level_cap !== 'number' || !isFinite(rules.level_cap)) rules.level_cap = 100;
                }
            }

            ensureRoot();

            var entityType = normalizeArpgEntityType(payload.rules.type_profile) || normalizeArpgEntityType(type) || 'origin';

            var enums = {
                abilitySubtype: ['', 'active', 'passive', 'ultimate', 'support', 'aura', 'toggle', 'summon', 'reaction', 'movement'],
                abilitySlot: ['', 'basic', 'skill_1', 'skill_2', 'skill_3', 'support', 'ultimate', 'passive', 'custom'],
                damageType: ['', 'physical', 'fire', 'ice', 'water', 'electric', 'wind', 'earth', 'nature', 'light', 'dark', 'void', 'quantum', 'imaginary', 'aether', 'anomaly', 'ether', 'fusion', 'glacio', 'aero', 'havoc', 'spectro', 'dendro', 'pyro', 'hydro', 'electro', 'cryo', 'anemo', 'geo', 'lightning', 'poison', 'acid', 'bleed', 'arcane', 'holy', 'shadow', 'slash', 'pierce', 'blunt', 'true', 'kinetic', 'custom'],
                targeting: ['', 'self', 'single_enemy', 'single_ally', 'line', 'cone', 'aoe_ground', 'aoe_around_self', 'global', 'custom'],
                rarity: ['', 'common', 'uncommon', 'rare', 'epic', 'legendary', 'mythic', 'set', 'unique', 'custom'],
                itemKind: ['', 'weapon', 'armor', 'accessory', 'artifact', 'consumable', 'material', 'quest', 'custom'],
                equipSlot: ['', 'weapon_one_hand', 'weapon_two_hand', 'weapon_catalyst', 'weapon_ranged', 'weapon_polearm', 'head', 'chest', 'legs', 'hands', 'feet', 'ring', 'amulet', 'trinket', 'custom'],
                talentTree: ['', 'offense', 'defense', 'support', 'utility', 'custom'],
                talentRank: ['', 'common', 'uncommon', 'rare', 'epic', 'legendary', 'mythic'],
                bestiaryRank: ['normal', 'elite', 'champion', 'boss', 'world_boss', 'raid_boss', 'custom'],
                modifierMode: ['', 'flat', 'percent', 'multiplier', 'override', 'formula_ref', 'table_ref'],
                effectKind: ['', 'damage', 'heal', 'shield', 'barrier', 'status', 'proc'],
                grantType: ['', 'tag', 'ability_unlock', 'item_unlock', 'resource_bonus', 'passive_flag', 'custom']
            };

            root.innerHTML = [
                '<details open="open" class="af-kb-collapsible"><summary>UI</summary><div id="af-kb-arpg-ui"></div></details>',
                '<details open="open" class="af-kb-collapsible"><summary>Tags</summary><div id="af-kb-arpg-tags"></div></details>',
                '<details open="open" class="af-kb-collapsible"><summary>Blocks</summary><div id="af-kb-arpg-blocks"></div></details>',
                '<details open="open" class="af-kb-collapsible"><summary>Rules</summary><div id="af-kb-arpg-rules"></div></details>',
                '<details class="af-kb-collapsible"><summary>Debug raw JSON</summary><div id="af-kb-arpg-raw"></div></details>'
            ].join('');

            var tagsRoot = root.querySelector('#af-kb-arpg-tags');
            var blocksRoot = root.querySelector('#af-kb-arpg-blocks');
            var rulesRoot = root.querySelector('#af-kb-arpg-rules');
            var rawRoot = root.querySelector('#af-kb-arpg-raw');

            function syncToRaw() {
                syncEntryMediaToPayload();
                raw.value = JSON.stringify(payload, null, 2);
                syncRulesToMeta(payload);
            }

            function bindFieldSync(node, handler) {
                if (!node || typeof handler !== 'function') return;
                var tag = String(node.tagName || '').toUpperCase();
                node.addEventListener((node.type === 'checkbox' || tag === 'SELECT') ? 'change' : 'input', handler);
            }

            function getEntryMediaFields() {
                return {
                    iconUrl: document.querySelector('[name="icon_url"]'),
                    iconClass: document.querySelector('[name="icon_class"]'),
                    bannerUrl: document.querySelector('[name="banner_url"]'),
                    bgUrl: document.querySelector('[name="bg_url"]'),
                    bgTabUrl: document.querySelector('[name="bg_tab_url"]')
                };
            }

            function syncEntryMediaToPayload() {
                var media = getEntryMediaFields();
                payload.ui = payload.ui && typeof payload.ui === 'object' && !Array.isArray(payload.ui) ? payload.ui : {};

                if (media.iconUrl) payload.ui.icon_url = String(media.iconUrl.value || '').trim();
                if (media.iconClass) payload.ui.icon_class = String(media.iconClass.value || '').trim();
                if (media.bgUrl) payload.ui.background_url = String(media.bgUrl.value || '').trim();
                if (media.bgTabUrl) payload.ui.background_tab_url = String(media.bgTabUrl.value || '').trim();

                // legacy-compatible optional key; не часть каноничного ARPG schema,
                // но сохраняем для совместимости с текущим UI записи
                if (media.bannerUrl) payload.ui.banner_url = String(media.bannerUrl.value || '').trim();
            }

            function bindEntryMediaFields() {
                var media = getEntryMediaFields();

                // при первом рендере — если нижние поля пусты, а payload.ui уже содержит значения,
                // можно подставить их вниз; иначе payload.ui собираем из реальных полей формы
                if (media.iconUrl && !media.iconUrl.value && payload.ui && payload.ui.icon_url) media.iconUrl.value = String(payload.ui.icon_url || '');
                if (media.iconClass && !media.iconClass.value && payload.ui && payload.ui.icon_class) media.iconClass.value = String(payload.ui.icon_class || '');
                if (media.bgUrl && !media.bgUrl.value && payload.ui && payload.ui.background_url) media.bgUrl.value = String(payload.ui.background_url || '');
                if (media.bgTabUrl && !media.bgTabUrl.value && payload.ui && payload.ui.background_tab_url) media.bgTabUrl.value = String(payload.ui.background_tab_url || '');
                if (media.bannerUrl && !media.bannerUrl.value && payload.ui && payload.ui.banner_url) media.bannerUrl.value = String(payload.ui.banner_url || '');

                syncEntryMediaToPayload();

                Object.keys(media).forEach(function (key) {
                    var node = media[key];
                    if (!node) return;

                    node.addEventListener('input', function () {
                        syncEntryMediaToPayload();
                        syncToRaw();
                    });

                    node.addEventListener('change', function () {
                        syncEntryMediaToPayload();
                        syncToRaw();
                    });
                });
            }
            function addSelectOptions(sel, opts) {
                (opts || []).forEach(function (v) {
                    var o = document.createElement('option');
                    o.value = String(v);
                    o.textContent = String(v || '—');
                    sel.appendChild(o);
                });
            }

            function ensureRuleArray(pathKey) {
                return ensureArray(payload.rules, pathKey);
            }

            function getRuleValue(pathKey, fallback) {
                if (typeof pathKey !== 'string' || pathKey.indexOf('.') === -1) {
                    return payload.rules[pathKey] != null ? payload.rules[pathKey] : fallback;
                }
                var parts = pathKey.split('.');
                var cur = payload.rules;
                for (var i = 0; i < parts.length; i++) {
                    if (!cur || typeof cur !== 'object') return fallback;
                    cur = cur[parts[i]];
                }
                return cur != null ? cur : fallback;
            }

            function setRuleValue(pathKey, value) {
                if (typeof pathKey !== 'string' || pathKey.indexOf('.') === -1) {
                    payload.rules[pathKey] = value;
                    return;
                }
                var parts = pathKey.split('.');
                var cur = payload.rules;
                for (var i = 0; i < parts.length - 1; i++) {
                    var segment = parts[i];
                    if (!cur[segment] || typeof cur[segment] !== 'object' || Array.isArray(cur[segment])) cur[segment] = {};
                    cur = cur[segment];
                }
                cur[parts[parts.length - 1]] = value;
            }

            function createSection(container, title, help) {
                var wrap = document.createElement('div');
                wrap.className = 'af-kb-kvlist';
                wrap.innerHTML = '<h4>' + esc(title) + '</h4>' + (help ? '<div class="af-kb-help">' + esc(help) + '</div>' : '');
                container.appendChild(wrap);
                return wrap;
            }

            function fieldCell(row, col, onChange) {
                var cell = document.createElement('div');
                var label = document.createElement('label');
                label.textContent = col.label;
                cell.appendChild(label);

                var input;
                if (col.type === 'select') {
                    input = document.createElement('select');
                    addSelectOptions(input, col.options || ['']);
                    input.value = row[col.key] != null ? String(row[col.key]) : String(col.default != null ? col.default : '');
                    if (row[col.key] == null && col.default != null) row[col.key] = col.default;
                } else if (col.type === 'number') {
                    input = document.createElement('input');
                    input.type = 'number';
                    var numValue = row[col.key];
                    if (numValue == null || numValue === '') {
                        numValue = col.default != null ? col.default : 0;
                        row[col.key] = Number(numValue);
                    }
                    input.value = String(numberOrZero(numValue));
                } else {
                    input = document.createElement('input');
                    input.type = 'text';
                    var textValue = row[col.key];
                    if (textValue == null) {
                        textValue = col.default != null ? col.default : '';
                        row[col.key] = textValue;
                    }
                    input.value = String(textValue);
                }

                bindFieldSync(input, function () {
                    row[col.key] = (col.type === 'number') ? numberOrZero(input.value) : input.value;
                    onChange();
                });

                cell.appendChild(input);
                return cell;
            }

            function renderSeededArrayEditor(container, title, key, columns, seedDefs) {
                var wrap = createSection(container, title);
                var list = document.createElement('div');
                var controls = document.createElement('div');
                controls.className = 'af-kb-row';
                var select = document.createElement('select');
                var add = document.createElement('button');
                add.type = 'button';
                add.className = 'af-kb-add';
                add.textContent = 'Добавить';

                var arr = ensureRuleArray(key);
                if (!Array.isArray(seedDefs) || !seedDefs.length) {
                    seedDefs = [{ key: 'default', label: 'default', seed: {} }];
                }

                seedDefs.forEach(function (d) {
                    var opt = document.createElement('option');
                    opt.value = d.key;
                    opt.textContent = d.label;
                    select.appendChild(opt);
                });

                controls.appendChild(select);
                controls.appendChild(add);
                wrap.appendChild(controls);
                wrap.appendChild(list);

                function getSeed() {
                    var selected = select.value;
                    for (var i = 0; i < seedDefs.length; i++) {
                        if (seedDefs[i].key === selected) return JSON.parse(JSON.stringify(seedDefs[i].seed || {}));
                    }
                    return JSON.parse(JSON.stringify(seedDefs[0].seed || {}));
                }

                function redraw() {
                    list.innerHTML = '';
                    if (!arr.length) {
                        var empty = document.createElement('div');
                        empty.className = 'af-kb-help';
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'af-kb-add';
                        btn.textContent = 'Добавить';
                        btn.addEventListener('click', function () {
                            arr.push(getSeed());
                            redraw();
                            syncToRaw();
                        });
                        empty.appendChild(btn);
                        list.appendChild(empty);
                        return;
                    }

                    arr.forEach(function (row, idx) {
                        if (!row || typeof row !== 'object' || Array.isArray(row)) row = arr[idx] = {};
                        var card = document.createElement('div');
                        card.className = 'af-kb-block-item';
                        var grid = document.createElement('div');
                        grid.className = 'af-kb-row';
                        columns.forEach(function (col) { grid.appendChild(fieldCell(row, col, syncToRaw)); });
                        var del = document.createElement('button');
                        del.type = 'button';
                        del.className = 'af-kb-remove';
                        del.textContent = 'Удалить';
                        del.addEventListener('click', function () {
                            arr.splice(idx, 1);
                            redraw();
                            syncToRaw();
                        });
                        card.appendChild(grid);
                        card.appendChild(del);
                        list.appendChild(card);
                    });
                }

                add.addEventListener('click', function () {
                    arr.push(getSeed());
                    redraw();
                    syncToRaw();
                });

                redraw();
            }

            function renderRuleFields(container, defs, title) {
                var wrap = createSection(container, title || 'Rule fields');
                var grid = document.createElement('div');
                grid.className = 'af-kb-row';

                defs.forEach(function (def) {
                    var cell = document.createElement('div');
                    var label = document.createElement('label');
                    label.textContent = def.label;
                    cell.appendChild(label);

                    var currentValue = getRuleValue(
                        def.key,
                        Object.prototype.hasOwnProperty.call(def, 'default') ? def.default : ''
                    );

                    // Ключевой фикс:
                    // если поле отсутствовало в payload, материализуем default сразу в rules.
                    if (getRuleValue(def.key, null) == null && Object.prototype.hasOwnProperty.call(def, 'default')) {
                        setRuleValue(def.key, def.type === 'number' ? numberOrZero(def.default) : def.default);
                        currentValue = getRuleValue(def.key, def.default);
                    }

                    var input;
                    if (def.type === 'select') {
                        input = document.createElement('select');
                        addSelectOptions(input, def.options || ['']);
                        input.value = String(currentValue || '');
                    } else if (def.type === 'number') {
                        input = document.createElement('input');
                        input.type = 'number';
                        input.value = String(numberOrZero(currentValue));
                    } else {
                        input = document.createElement('input');
                        input.type = 'text';
                        input.value = currentValue != null ? String(currentValue) : '';
                    }

                    bindFieldSync(input, function () {
                        setRuleValue(def.key, (def.type === 'number') ? numberOrZero(input.value) : input.value);
                        syncToRaw();
                    });

                    cell.appendChild(input);
                    grid.appendChild(cell);
                });

                wrap.appendChild(grid);
            }

            tagsRoot.innerHTML = '<div class="af-kb-row"><div><label>tags (csv)</label><input id="af-arpg-tags-csv" type="text"></div></div>';
            var tagsNode = tagsRoot.querySelector('#af-arpg-tags-csv');
            tagsNode.value = (payload.tags || []).join(', ');
            bindFieldSync(tagsNode, function () {
                payload.tags = splitCsv(tagsNode.value);
                syncToRaw();
            });

            function renderBlocks() {
                var blockCols = [
                    { key: 'block_key', label: 'block_key' },
                    { key: 'level', label: 'level', type: 'number', default: 0 },
                    { key: 'title_ru', label: 'title.ru', default: '' },
                    { key: 'title_en', label: 'title.en', default: '' },
                    { key: 'effects_json', label: 'effects (json)', default: '[]' },
                    { key: 'data_json', label: 'data (json)', default: '[]' }
                ];

                var blocksCompat = payload.blocks.map(function (b) {
                    return {
                        block_key: b.block_key || '',
                        level: numberOrZero(b.level || 0),
                        title_ru: (b.title && b.title.ru) || '',
                        title_en: (b.title && b.title.en) || '',
                        effects_json: JSON.stringify(Array.isArray(b.effects) ? b.effects : [], null, 2),
                        data_json: JSON.stringify(Array.isArray(b.data) ? b.data : [], null, 2)
                    };
                });

                payload.blocks = payload.blocks || [];
                var wrap = createSection(blocksRoot, 'Display / lore blocks');
                var list = document.createElement('div');
                var add = document.createElement('button');
                add.type = 'button';
                add.className = 'af-kb-add';
                add.textContent = 'Добавить';
                wrap.appendChild(list);
                wrap.appendChild(add);

                function syncBack() {
                    payload.blocks = blocksCompat.map(function (row) {
                        return {
                            block_key: String(row.block_key || ''),
                            level: numberOrZero(row.level || 0),
                            title: { ru: String(row.title_ru || ''), en: String(row.title_en || '') },
                            effects: parseJsonSafe(row.effects_json || '[]', []),
                            data: parseJsonSafe(row.data_json || '[]', [])
                        };
                    });
                    bindEntryMediaFields();
                    syncToRaw();
                }

                function redraw() {
                    list.innerHTML = '';
                    if (!blocksCompat.length) {
                        var hint = document.createElement('div');
                        hint.className = 'af-kb-help';
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'af-kb-add';
                        btn.textContent = 'Добавить';
                        btn.addEventListener('click', function () {
                            blocksCompat.push({
                                block_key: 'overview',
                                level: 0,
                                title_ru: '',
                                title_en: '',
                                effects_json: '[]',
                                data_json: '[]'
                            });
                            redraw();
                            syncBack();
                        });
                        hint.appendChild(btn);
                        list.appendChild(hint);
                        return;
                    }

                    blocksCompat.forEach(function (row, idx) {
                        var card = document.createElement('div');
                        card.className = 'af-kb-block-item';
                        var grid = document.createElement('div');
                        grid.className = 'af-kb-row';
                        blockCols.forEach(function (col) { grid.appendChild(fieldCell(row, col, syncBack)); });
                        var del = document.createElement('button');
                        del.type = 'button';
                        del.className = 'af-kb-remove';
                        del.textContent = 'Удалить';
                        del.addEventListener('click', function () {
                            blocksCompat.splice(idx, 1);
                            redraw();
                            syncBack();
                        });
                        card.appendChild(grid);
                        card.appendChild(del);
                        list.appendChild(card);
                    });
                }

                add.addEventListener('click', function () {
                    blocksCompat.push({
                        block_key: 'overview',
                        level: 0,
                        title_ru: '',
                        title_en: '',
                        effects_json: '[]',
                        data_json: '[]'
                    });
                    redraw();
                    syncBack();
                });

                redraw();
            }

            function renderSimpleRules() {
                renderRuleFields(rulesRoot, [{ key: 'type_profile', label: 'type_profile', default: entityType }], 'Rule fields');

                if (entityType === 'origin') {
                    renderRuleFields(rulesRoot, [
                        { key: 'size', label: 'size', default: 'medium' },
                        { key: 'creature_type', label: 'creature_type', default: 'humanoid' },
                        { key: 'base_hp', label: 'base_hp', type: 'number', default: 100 },
                        { key: 'base_damage', label: 'base_damage', type: 'number', default: 10 },
                        { key: 'base_defense', label: 'base_defense', type: 'number', default: 5 },
                        { key: 'movement_speed', label: 'movement_speed', type: 'number', default: 100 },
                        { key: 'racial_bonuses_text', label: 'racial_bonuses_text', default: '' },
                        { key: 'racial_traits_text', label: 'racial_traits_text', default: '' },
                        { key: 'starting_notes', label: 'starting_notes', default: '' }
                    ], 'Origin core');
                }

                if (entityType === 'archetype') {
                    renderRuleFields(rulesRoot, [
                        { key: 'role', label: 'role', default: 'striker' },
                        { key: 'damage_bias', label: 'damage_bias', default: 'high' },
                        { key: 'defense_bias', label: 'defense_bias', default: 'low' },
                        { key: 'resource_affinity', label: 'resource_affinity', default: 'energy' },
                        { key: 'base_damage_bonus', label: 'base_damage_bonus', type: 'number', default: 0 },
                        { key: 'base_defense_bonus', label: 'base_defense_bonus', type: 'number', default: 0 },
                        { key: 'slot_rules_text', label: 'slot_rules_text', default: '' },
                        { key: 'description_notes', label: 'description_notes', default: '' }
                    ], 'Archetype core');
                }

                if (entityType === 'faction') {
                    renderRuleFields(rulesRoot, [
                        { key: 'standing_model', label: 'standing_model', default: 'neutral' },
                        { key: 'vendor_access_text', label: 'vendor_access_text', default: '' },
                        { key: 'story_flags_text', label: 'story_flags_text', default: '' },
                        { key: 'description_text', label: 'description_text', default: '' }
                    ], 'Faction core');
                }

                if (entityType === 'element') {
                    renderRuleFields(rulesRoot, [
                        { key: 'family', label: 'family', default: '' },
                        { key: 'counter_element', label: 'counter_element', default: '' },
                        { key: 'description_text', label: 'description_text', default: '' }
                    ], 'Element core');
                }

                if (entityType === 'lore') {
                    renderRuleFields(rulesRoot, [
                        { key: 'linked_entities_text', label: 'linked_entities_text', default: '' },
                        { key: 'timeline_text', label: 'timeline_text', default: '' },
                        { key: 'source_text', label: 'source_text', default: '' }
                    ], 'Lore core');
                }
            }

            function renderAbilityRules() {
                renderRuleFields(rulesRoot, [
                    { key: 'type', label: 'type', type: 'select', options: ['', 'active', 'passive', 'ultimate'], default: 'active' },
                    { key: 'subtype', label: 'subtype', type: 'select', options: enums.abilitySubtype, default: '' },
                    { key: 'slot', label: 'slot', type: 'select', options: enums.abilitySlot, default: 'skill_1' },
                    { key: 'damage_type', label: 'damage_type', type: 'select', options: enums.damageType, default: 'physical' },
                    { key: 'targeting', label: 'targeting', type: 'select', options: enums.targeting, default: 'single_enemy' },
                    { key: 'range', label: 'range', type: 'number', default: 0 },
                    { key: 'cast_time', label: 'cast_time', type: 'number', default: 0 },
                    { key: 'cooldown', label: 'cooldown', type: 'number', default: 0 },
                    { key: 'duration', label: 'duration', type: 'number', default: 0 },
                    { key: 'max_charges', label: 'max_charges', type: 'number', default: 1 },
                    { key: 'level_cap', label: 'level_cap', type: 'number', default: 20 }
                ], 'Ability core');

                renderSeededArrayEditor(rulesRoot, 'resources', 'resources', [
                    { key: 'op', label: 'op', default: 'spend' },
                    { key: 'resource_key', label: 'resource_key', default: '' },
                    { key: 'value', label: 'value', type: 'number', default: 0 },
                    { key: 'per', label: 'per', default: 'cast' },
                    { key: 'duration', label: 'duration', type: 'number', default: 0 },
                    { key: 'notes', label: 'notes', default: '' }
                ], [
                    { key: 'resource_spend', label: 'resource_spend', seed: { op: 'spend', resource_key: '', value: 0, per: 'cast', duration: 0, notes: '' } },
                    { key: 'resource_gain', label: 'resource_gain', seed: { op: 'gain', resource_key: '', value: 0, per: 'cast', duration: 0, notes: '' } },
                    { key: 'resource_drain', label: 'resource_drain', seed: { op: 'drain', resource_key: '', value: 0, per: 'cast', duration: 0, notes: '' } },
                    { key: 'resource_restore', label: 'resource_restore', seed: { op: 'restore', resource_key: '', value: 0, per: 'cast', duration: 0, notes: '' } }
                ]);

                renderSeededArrayEditor(rulesRoot, 'effects', 'effects', [
                    { key: 'kind', label: 'kind', default: 'damage' },
                    { key: 'damage_type', label: 'damage_type', type: 'select', options: enums.damageType, default: 'physical' },
                    { key: 'targeting', label: 'targeting', type: 'select', options: enums.targeting, default: 'single_enemy' },
                    { key: 'value_mode', label: 'value_mode', default: 'flat' },
                    { key: 'value', label: 'value', type: 'number', default: 0 },
                    { key: 'formula_ref', label: 'formula_ref', default: '' },
                    { key: 'duration', label: 'duration', type: 'number', default: 0 },
                    { key: 'hit_count', label: 'hit_count', type: 'number', default: 1 },
                    { key: 'status_key', label: 'status_key', default: '' },
                    { key: 'notes', label: 'notes', default: '' }
                ], [
                    { key: 'damage', label: 'damage', seed: { kind: 'damage', damage_type: 'physical', targeting: 'single_enemy', value_mode: 'flat', value: 0, formula_ref: '', duration: 0, hit_count: 1, status_key: '', notes: '' } },
                    { key: 'heal', label: 'heal', seed: { kind: 'heal', damage_type: '', targeting: 'single_ally', value_mode: 'flat', value: 0, formula_ref: '', duration: 0, hit_count: 1, status_key: '', notes: '' } },
                    { key: 'shield', label: 'shield', seed: { kind: 'shield', damage_type: '', targeting: 'single_ally', value_mode: 'flat', value: 0, formula_ref: '', duration: 0, hit_count: 1, status_key: '', notes: '' } },
                    { key: 'barrier', label: 'barrier', seed: { kind: 'barrier', damage_type: '', targeting: 'single_ally', value_mode: 'flat', value: 0, formula_ref: '', duration: 0, hit_count: 1, status_key: '', notes: '' } },
                    { key: 'status', label: 'status', seed: { kind: 'status', damage_type: '', targeting: 'single_enemy', value_mode: 'flat', value: 0, formula_ref: '', duration: 0, hit_count: 1, status_key: '', notes: '' } },
                    { key: 'proc', label: 'proc', seed: { kind: 'proc', damage_type: '', targeting: 'single_enemy', value_mode: 'flat', value: 0, formula_ref: '', duration: 0, hit_count: 1, status_key: '', notes: '' } }
                ]);

                ['modifiers', 'triggers', 'conditions', 'stacking', 'upgrade_requirements'].forEach(function (k) {
                    renderSeededArrayEditor(
                        rulesRoot,
                        k,
                        k,
                        [{ key: 'kind', label: 'kind', default: '' }, { key: 'value', label: 'value', default: '' }],
                        [{ key: 'default', label: 'default', seed: { kind: '', value: '' } }]
                    );
                });
            }

            function renderTalentRules() {
                renderRuleFields(rulesRoot, [
                    { key: 'tree', label: 'tree', type: 'select', options: enums.talentTree, default: 'offense' },
                    { key: 'tier', label: 'tier', type: 'number', default: 1 },
                    { key: 'rank', label: 'rank', type: 'select', options: enums.talentRank, default: 'rare' },
                    { key: 'slot_type', label: 'slot_type', default: 'passive' },
                    { key: 'node_label', label: 'node_label', default: '' },
                    { key: 'rank_weight', label: 'rank_weight', type: 'number', default: 1 },
                    { key: 'socket_cost', label: 'socket_cost', type: 'number', default: 1 }
                ], 'Talent core');

                renderSeededArrayEditor(rulesRoot, 'effects', 'effects', [
                    { key: 'kind', label: 'kind', default: 'flat_stat_bonus' },
                    { key: 'target', label: 'target', default: '' },
                    { key: 'damage_type', label: 'damage_type', default: '' },
                    { key: 'mode', label: 'mode', default: 'percent' },
                    { key: 'value', label: 'value', type: 'number', default: 0 },
                    { key: 'notes', label: 'notes', default: '' }
                ], [
                    { key: 'flat_stat_bonus', label: 'flat_stat_bonus', seed: { kind: 'flat_stat_bonus', target: '', damage_type: '', mode: 'flat', value: 0, notes: '' } },
                    { key: 'percent_stat_bonus', label: 'percent_stat_bonus', seed: { kind: 'percent_stat_bonus', target: '', damage_type: '', mode: 'percent', value: 0, notes: '' } },
                    { key: 'status_damage_bonus', label: 'status_damage_bonus', seed: { kind: 'status_damage_bonus', target: '', damage_type: '', mode: 'percent', value: 0, notes: '' } },
                    { key: 'resistance_bonus', label: 'resistance_bonus', seed: { kind: 'resistance_bonus', target: '', damage_type: '', mode: 'percent', value: 0, notes: '' } },
                    { key: 'passive_proc', label: 'passive_proc', seed: { kind: 'passive_proc', target: '', damage_type: '', mode: 'percent', value: 0, notes: '' } }
                ]);

                ['passive_effects', 'modifiers', 'requirements', 'mutual_exclusives'].forEach(function (k) {
                    renderSeededArrayEditor(
                        rulesRoot,
                        k,
                        k,
                        [{ key: 'kind', label: 'kind', default: '' }, { key: 'value', label: 'value', default: '' }],
                        [{ key: 'default', label: 'default', seed: { kind: '', value: '' } }]
                    );
                });

                renderSeededArrayEditor(rulesRoot, 'grants', 'grants', [
                    { key: 'grant_type', label: 'grant_type', default: 'tag' },
                    { key: 'value', label: 'value', default: '' },
                    { key: 'notes', label: 'notes', default: '' }
                ], [
                    { key: 'tag', label: 'tag', seed: { grant_type: 'tag', value: '', notes: '' } },
                    { key: 'ability_unlock', label: 'ability_unlock', seed: { grant_type: 'ability_unlock', value: '', notes: '' } },
                    { key: 'item_unlock', label: 'item_unlock', seed: { grant_type: 'item_unlock', value: '', notes: '' } },
                    { key: 'resource_bonus', label: 'resource_bonus', seed: { grant_type: 'resource_bonus', value: '', notes: '' } },
                    { key: 'passive_flag', label: 'passive_flag', seed: { grant_type: 'passive_flag', value: '', notes: '' } }
                ]);
            }

            function renderItemRules() {
                renderRuleFields(rulesRoot, [
                    { key: 'item_kind', label: 'item_kind', type: 'select', options: enums.itemKind, default: 'weapon' },
                    { key: 'equip_slot', label: 'equip_slot', type: 'select', options: enums.equipSlot, default: 'weapon_one_hand' },
                    { key: 'rarity', label: 'rarity', type: 'select', options: enums.rarity, default: 'common' },
                    { key: 'subtype', label: 'subtype', default: '' },
                    { key: 'level_min', label: 'level_min', type: 'number', default: 1 },
                    { key: 'level_max', label: 'level_max', type: 'number', default: 100 },
                    { key: 'progression_stage', label: 'progression_stage', default: 'base' },
                    { key: 'level_cap', label: 'level_cap', type: 'number', default: 100 }
                ], 'Item core');

                var itemKind = String(payload.rules.item_kind || 'weapon');

                if (itemKind === 'weapon') {
                    renderRuleFields(rulesRoot, [
                        { key: 'weapon_class', label: 'weapon_class', default: '' },
                        { key: 'base_damage', label: 'base_damage', type: 'number', default: 0 },
                        { key: 'damage_type', label: 'damage_type', default: 'physical' },
                        { key: 'attack_speed', label: 'attack_speed', type: 'number', default: 0 },
                        { key: 'range', label: 'range', type: 'number', default: 0 },
                        { key: 'crit_bonus', label: 'crit_bonus', type: 'number', default: 0 }
                    ]);
                }

                if (itemKind === 'armor') {
                    renderRuleFields(rulesRoot, [
                        { key: 'armor_class', label: 'armor_class', default: '' },
                        { key: 'base_defense', label: 'base_defense', type: 'number', default: 0 },
                        { key: 'resist_profile_text', label: 'resist_profile_text', default: '' }
                    ]);
                }

                if (itemKind === 'accessory') {
                    renderRuleFields(rulesRoot, [
                        { key: 'accessory_role', label: 'accessory_role', default: '' },
                        { key: 'passive_focus_text', label: 'passive_focus_text', default: '' }
                    ]);
                }

                if (itemKind === 'artifact') {
                    renderRuleFields(rulesRoot, [
                        { key: 'artifact_set_text', label: 'artifact_set_text', default: '' },
                        { key: 'passive_focus_text', label: 'passive_focus_text', default: '' }
                    ]);
                }

                if (itemKind === 'consumable') {
                    renderRuleFields(rulesRoot, [
                        { key: 'use_kind', label: 'use_kind', default: '' },
                        { key: 'stack_max', label: 'stack_max', type: 'number', default: 1 },
                        { key: 'use_cooldown', label: 'use_cooldown', type: 'number', default: 0 }
                    ]);
                }

                if (itemKind === 'material') {
                    renderRuleFields(rulesRoot, [
                        { key: 'material_grade', label: 'material_grade', default: '' },
                        { key: 'material_usage_text', label: 'material_usage_text', default: '' }
                    ]);
                }

                if (itemKind === 'quest') {
                    renderRuleFields(rulesRoot, [
                        { key: 'quest_usage_text', label: 'quest_usage_text', default: '' }
                    ]);
                }

                renderSeededArrayEditor(rulesRoot, 'base_stats', 'base_stats', [
                    { key: 'stat_key', label: 'stat_key', default: '' },
                    { key: 'mode', label: 'mode', default: 'flat' },
                    { key: 'value', label: 'value', type: 'number', default: 0 },
                    { key: 'notes', label: 'notes', default: '' }
                ], [
                    { key: 'hp', label: 'hp', seed: { stat_key: 'hp', mode: 'flat', value: 0, notes: '' } },
                    { key: 'atk', label: 'atk', seed: { stat_key: 'atk', mode: 'flat', value: 0, notes: '' } },
                    { key: 'def', label: 'def', seed: { stat_key: 'def', mode: 'flat', value: 0, notes: '' } },
                    { key: 'crit_rate', label: 'crit_rate', seed: { stat_key: 'crit_rate', mode: 'flat', value: 0, notes: '' } },
                    { key: 'crit_dmg', label: 'crit_dmg', seed: { stat_key: 'crit_dmg', mode: 'flat', value: 0, notes: '' } },
                    { key: 'status_hit', label: 'status_hit', seed: { stat_key: 'status_hit', mode: 'flat', value: 0, notes: '' } },
                    { key: 'status_resist', label: 'status_resist', seed: { stat_key: 'status_resist', mode: 'flat', value: 0, notes: '' } }
                ]);

                renderSeededArrayEditor(rulesRoot, 'modifiers', 'modifiers', [
                    { key: 'stat_key', label: 'stat_key', default: '' },
                    { key: 'mode', label: 'mode', default: 'percent' },
                    { key: 'value', label: 'value', type: 'number', default: 0 },
                    { key: 'condition_text', label: 'condition_text', default: '' },
                    { key: 'notes', label: 'notes', default: '' }
                ], [
                    { key: 'default', label: 'default', seed: { stat_key: '', mode: 'percent', value: 0, condition_text: '', notes: '' } }
                ]);

                ['effects', 'passive_effects', 'triggers', 'grants', 'upgrade_steps'].forEach(function (k) {
                    renderSeededArrayEditor(
                        rulesRoot,
                        k,
                        k,
                        [{ key: 'kind', label: 'kind', default: '' }, { key: 'value', label: 'value', default: '' }],
                        [{ key: 'default', label: 'default', seed: { kind: '', value: '' } }]
                    );
                });
            }

            function renderBestiaryRules() {
                function normalizeBestiaryAbilityRow(row, fallbackSortorder) {
                    var source = (row && typeof row === 'object' && !Array.isArray(row)) ? row : {};
                    var sort = numberOrZero(source.sortorder != null ? source.sortorder : (fallbackSortorder || 0));
                    var slotIndex = numberOrZero(source.slot_index != null ? source.slot_index : (sort > 0 ? sort : 1));
                    var abilityType = String(source.type || source.ability_type || 'active');
                    var normalizeRows = function (items, schema) {
                        return (Array.isArray(items) ? items : []).map(function (item) {
                            var src = (item && typeof item === 'object' && !Array.isArray(item)) ? item : {};
                            var out = {};
                            schema.forEach(function (def) {
                                if (def.type === 'number') out[def.key] = numberOrZero(src[def.key] != null ? src[def.key] : def.default);
                                else out[def.key] = String(src[def.key] != null ? src[def.key] : def.default || '');
                            });
                            return out;
                        });
                    };
                    return {
                        slot_index: slotIndex,
                        ability_name: String(source.ability_name || ''),
                        icon_url: String(source.icon_url || ''),
                        icon_class: String(source.icon_class || ''),
                        type: abilityType,
                        ability_type: abilityType,
                        subtype: String(source.subtype || ''),
                        slot: String(source.slot || ''),
                        damage_type: String(source.damage_type || 'physical'),
                        targeting: String(source.targeting || 'single_enemy'),
                        range: numberOrZero(source.range != null ? source.range : 0),
                        cast_time: numberOrZero(source.cast_time != null ? source.cast_time : 0),
                        cooldown: numberOrZero(source.cooldown != null ? source.cooldown : 0),
                        duration: numberOrZero(source.duration != null ? source.duration : 0),
                        max_charges: numberOrZero(source.max_charges != null ? source.max_charges : 0),
                        level_cap: numberOrZero(source.level_cap != null ? source.level_cap : 0),
                        ability_description: String(source.ability_description || ''),
                        resources: normalizeRows(source.resources, [
                            { key: 'op', default: 'spend' },
                            { key: 'resource_key', default: '' },
                            { key: 'value', type: 'number', default: 0 },
                            { key: 'per', default: 'cast' },
                            { key: 'duration', type: 'number', default: 0 },
                            { key: 'notes', default: '' }
                        ]),
                        effects: normalizeRows(source.effects, [
                            { key: 'kind', default: 'damage' },
                            { key: 'damage_type', default: '' },
                            { key: 'targeting', default: '' },
                            { key: 'value_mode', default: 'flat' },
                            { key: 'value', type: 'number', default: 0 },
                            { key: 'formula_ref', default: '' },
                            { key: 'duration', type: 'number', default: 0 },
                            { key: 'hit_count', type: 'number', default: 1 },
                            { key: 'status_key', default: '' },
                            { key: 'notes', default: '' }
                        ]),
                        modifiers: normalizeRows(source.modifiers, [
                            { key: 'stat_key', default: '' },
                            { key: 'mode', default: 'flat' },
                            { key: 'value', type: 'number', default: 0 },
                            { key: 'duration', type: 'number', default: 0 },
                            { key: 'condition_text', default: '' },
                            { key: 'notes', default: '' }
                        ]),
                        triggers: normalizeRows(source.triggers, [
                            { key: 'event', default: '' },
                            { key: 'action_text', default: '' },
                            { key: 'condition_text', default: '' },
                            { key: 'notes', default: '' }
                        ]),
                        conditions: normalizeRows(source.conditions, [
                            { key: 'condition_type', default: '' },
                            { key: 'value', default: '' },
                            { key: 'notes', default: '' }
                        ]),
                        stacking: normalizeRows(source.stacking, [
                            { key: 'stack_key', default: '' },
                            { key: 'max_stacks', type: 'number', default: 1 },
                            { key: 'policy', default: '' },
                            { key: 'notes', default: '' }
                        ]),
                        upgrade_requirements: normalizeRows(source.upgrade_requirements, [
                            { key: 'level', type: 'number', default: 1 },
                            { key: 'required_item_key', default: '' },
                            { key: 'required_qty', type: 'number', default: 0 },
                            { key: 'required_currency_key', default: '' },
                            { key: 'required_currency_qty', type: 'number', default: 0 },
                            { key: 'notes', default: '' }
                        ]),
                        grants: normalizeRows(source.grants, [
                            { key: 'grant_type', default: '' },
                            { key: 'value', default: '' },
                            { key: 'value_num', type: 'number', default: 0 },
                            { key: 'duration', type: 'number', default: 0 },
                            { key: 'notes', default: '' }
                        ]),
                        ability_kb_key: String(source.ability_kb_key || source.ability_key || ''),
                        ability_key: String(source.ability_key || ''),
                        notes: String(source.notes || ''),
                        sortorder: sort
                    };
                }

                function renderBestiaryNestedRows(container, title, rows, defs, seed) {
                    var box = document.createElement('div');
                    box.className = 'af-kb-rule-card';
                    container.appendChild(box);
                    renderObjectList(box, rows, title, defs, syncToRaw, seed);
                }

                renderRuleFields(rulesRoot, [
                    { key: 'family', label: 'family', default: '' },
                    { key: 'archetype', label: 'archetype', default: '' },
                    { key: 'faction', label: 'faction', default: '' },
                    { key: 'rank', label: 'rank', type: 'select', options: enums.bestiaryRank, default: 'normal' },
                    { key: 'threat_tier', label: 'threat_tier', type: 'number', default: 1 },
                    { key: 'level', label: 'level', type: 'number', default: 1 }
                ], 'Bestiary core');

                renderRuleFields(rulesRoot, [
                    { key: 'combat_stats.hp', label: 'combat_stats.hp', type: 'number', default: 0 },
                    { key: 'combat_stats.atk', label: 'combat_stats.atk', type: 'number', default: 0 },
                    { key: 'combat_stats.def', label: 'combat_stats.def', type: 'number', default: 0 },
                    { key: 'combat_stats.armor', label: 'combat_stats.armor', type: 'number', default: 0 },
                    { key: 'combat_stats.crit_rate', label: 'combat_stats.crit_rate', type: 'number', default: 0 },
                    { key: 'combat_stats.crit_dmg', label: 'combat_stats.crit_dmg', type: 'number', default: 0 },
                    { key: 'combat_stats.status_hit', label: 'combat_stats.status_hit', type: 'number', default: 0 },
                    { key: 'combat_stats.status_resist', label: 'combat_stats.status_resist', type: 'number', default: 0 }
                ], 'Combat stats');

                renderSeededArrayEditor(rulesRoot, 'resists', 'resists', [
                    { key: 'damage_type', label: 'damage_type', type: 'select', options: enums.damageType, default: 'physical' },
                    { key: 'value', label: 'value', type: 'number', default: 0 },
                    { key: 'notes', label: 'notes', default: '' }
                ], [
                    { key: 'default', label: 'default', seed: { damage_type: 'physical', value: 0, notes: '' } }
                ]);

                renderSeededArrayEditor(rulesRoot, 'weaknesses', 'weaknesses', [
                    { key: 'damage_type', label: 'damage_type', type: 'select', options: enums.damageType, default: 'physical' },
                    { key: 'value', label: 'value', type: 'number', default: 0 },
                    { key: 'notes', label: 'notes', default: '' }
                ], [
                    { key: 'default', label: 'default', seed: { damage_type: 'physical', value: 0, notes: '' } }
                ]);

                (function renderBestiaryAbilities() {
                    var wrap = createSection(rulesRoot, 'ability_keys');
                    var list = document.createElement('div');
                    wrap.appendChild(list);
                    var addBtn = document.createElement('button');
                    addBtn.type = 'button';
                    addBtn.className = 'af-kb-add';
                    addBtn.textContent = 'Добавить ability';
                    wrap.appendChild(addBtn);
                    var arr = ensureRuleArray('ability_keys');

                    function redrawAbilities() {
                        list.innerHTML = '';
                        if (!arr.length) {
                            var empty = document.createElement('div');
                            empty.className = 'af-kb-help';
                            empty.textContent = 'Список ability_keys пуст.';
                            list.appendChild(empty);
                            return;
                        }

                        arr.forEach(function (row, idx) {
                            var normalized = normalizeBestiaryAbilityRow(row, idx + 1);
                            arr[idx] = normalized;
                            var card = document.createElement('div');
                            card.className = 'af-kb-block-item';
                            list.appendChild(card);

                            var summary = document.createElement('div');
                            card.appendChild(summary);
                            var refreshSummary = function () {
                                renderInlineAbilitySummary(summary, normalized, idx, 'Ability');
                            };
                            refreshSummary();
                            card.addEventListener('input', refreshSummary);
                            card.addEventListener('change', refreshSummary);

                            var title = document.createElement('h4');
                            title.textContent = 'Ability #' + (idx + 1);
                            card.appendChild(title);

                            var coreGrid = document.createElement('div');
                            coreGrid.className = 'af-kb-row';
                            [
                                { key: 'slot_index', label: 'slot_index', type: 'number', default: 1 },
                                { key: 'ability_name', label: 'ability_name', default: '' },
                                { key: 'icon_url', label: 'icon_url', default: '' },
                                { key: 'icon_class', label: 'icon_class', default: '' },
                                { key: 'type', label: 'type', type: 'select', options: ['active', 'passive', 'ultimate'] },
                                { key: 'subtype', label: 'subtype', type: 'select', options: enums.abilitySubtype },
                                { key: 'slot', label: 'slot', type: 'select', options: enums.abilitySlot },
                                { key: 'damage_type', label: 'damage_type', type: 'select', options: enums.damageType, default: 'physical' },
                                { key: 'targeting', label: 'targeting', type: 'select', options: enums.targeting, default: 'single_enemy' },
                                { key: 'range', label: 'range', type: 'number', default: 0 },
                                { key: 'cast_time', label: 'cast_time', type: 'number', default: 0 },
                                { key: 'cooldown', label: 'cooldown', type: 'number', default: 0 },
                                { key: 'duration', label: 'duration', type: 'number', default: 0 },
                                { key: 'max_charges', label: 'max_charges', type: 'number', default: 0 },
                                { key: 'level_cap', label: 'level_cap', type: 'number', default: 0 },
                                { key: 'ability_kb_key', label: 'ability_kb_key', default: '' },
                                { key: 'sortorder', label: 'sortorder', type: 'number', default: 0 },
                                { key: 'notes', label: 'notes', default: '' }
                            ].forEach(function (col) { coreGrid.appendChild(fieldCell(normalized, col, function () { refreshSummary(); syncToRaw(); })); });
                            card.appendChild(coreGrid);

                            renderBestiaryNestedRows(card, 'resources', normalized.resources, [
                                { name: 'op', label: 'op', type: 'text' },
                                { name: 'resource_key', label: 'resource_key', type: 'text' },
                                { name: 'value', label: 'value', type: 'number' },
                                { name: 'per', label: 'per', type: 'text' },
                                { name: 'duration', label: 'duration', type: 'number' },
                                { name: 'notes', label: 'notes', type: 'text' }
                            ], { op: 'spend', resource_key: '', value: 0, per: 'cast', duration: 0, notes: '' });
                            renderBestiaryNestedRows(card, 'effects', normalized.effects, [
                                { name: 'kind', label: 'kind', type: 'select', options: enums.effectKind },
                                { name: 'damage_type', label: 'damage_type', type: 'select', options: enums.damageType },
                                { name: 'targeting', label: 'targeting', type: 'select', options: enums.targeting },
                                { name: 'value_mode', label: 'value_mode', type: 'text' },
                                { name: 'value', label: 'value', type: 'number' },
                                { name: 'formula_ref', label: 'formula_ref', type: 'text' },
                                { name: 'duration', label: 'duration', type: 'number' },
                                { name: 'hit_count', label: 'hit_count', type: 'number' },
                                { name: 'status_key', label: 'status_key', type: 'text' },
                                { name: 'notes', label: 'notes', type: 'text' }
                            ], { kind: 'damage', damage_type: '', targeting: '', value_mode: 'flat', value: 0, formula_ref: '', duration: 0, hit_count: 1, status_key: '', notes: '' });
                            renderBestiaryNestedRows(card, 'modifiers', normalized.modifiers, [
                                { name: 'stat_key', label: 'stat_key', type: 'text' },
                                { name: 'mode', label: 'mode', type: 'text' },
                                { name: 'value', label: 'value', type: 'number' },
                                { name: 'duration', label: 'duration', type: 'number' },
                                { name: 'condition_text', label: 'condition_text', type: 'text' },
                                { name: 'notes', label: 'notes', type: 'text' }
                            ], { stat_key: '', mode: 'flat', value: 0, duration: 0, condition_text: '', notes: '' });
                            renderBestiaryNestedRows(card, 'triggers', normalized.triggers, [
                                { name: 'event', label: 'event', type: 'text' },
                                { name: 'action_text', label: 'action_text', type: 'text' },
                                { name: 'condition_text', label: 'condition_text', type: 'text' },
                                { name: 'notes', label: 'notes', type: 'text' }
                            ], { event: '', action_text: '', condition_text: '', notes: '' });
                            renderBestiaryNestedRows(card, 'conditions', normalized.conditions, [
                                { name: 'condition_type', label: 'condition_type', type: 'text' },
                                { name: 'value', label: 'value', type: 'text' },
                                { name: 'notes', label: 'notes', type: 'text' }
                            ], { condition_type: '', value: '', notes: '' });
                            renderBestiaryNestedRows(card, 'stacking', normalized.stacking, [
                                { name: 'stack_key', label: 'stack_key', type: 'text' },
                                { name: 'max_stacks', label: 'max_stacks', type: 'number' },
                                { name: 'policy', label: 'policy', type: 'text' },
                                { name: 'notes', label: 'notes', type: 'text' }
                            ], { stack_key: '', max_stacks: 1, policy: '', notes: '' });
                            renderBestiaryNestedRows(card, 'upgrade_requirements', normalized.upgrade_requirements, [
                                { name: 'level', label: 'level', type: 'number' },
                                { name: 'required_item_key', label: 'required_item_key', type: 'text' },
                                { name: 'required_qty', label: 'required_qty', type: 'number' },
                                { name: 'required_currency_key', label: 'required_currency_key', type: 'text' },
                                { name: 'required_currency_qty', label: 'required_currency_qty', type: 'number' },
                                { name: 'notes', label: 'notes', type: 'text' }
                            ], { level: 1, required_item_key: '', required_qty: 0, required_currency_key: '', required_currency_qty: 0, notes: '' });
                            renderBestiaryNestedRows(card, 'grants', normalized.grants, [
                                { name: 'grant_type', label: 'grant_type', type: 'select', options: enums.grantType },
                                { name: 'value', label: 'value', type: 'text' },
                                { name: 'value_num', label: 'value_num', type: 'number' },
                                { name: 'duration', label: 'duration', type: 'number' },
                                { name: 'notes', label: 'notes', type: 'text' }
                            ], { grant_type: '', value: '', value_num: 0, duration: 0, notes: '' });

                            var del = document.createElement('button');
                            del.type = 'button';
                            del.className = 'af-kb-remove';
                            del.textContent = 'Удалить';
                            del.addEventListener('click', function () {
                                arr.splice(idx, 1);
                                redrawAbilities();
                                syncToRaw();
                            });
                            card.appendChild(del);
                        });
                    }

                    addBtn.addEventListener('click', function () {
                        arr.push(normalizeBestiaryAbilityRow({}, arr.length + 1));
                        redrawAbilities();
                        syncToRaw();
                    });

                    redrawAbilities();
                })();

                renderSeededArrayEditor(rulesRoot, 'loot', 'loot', [
                    { key: 'loot_key', label: 'loot_key', default: '' },
                    { key: 'kind', label: 'kind', type: 'select', options: ['item', 'currency', 'material', 'reward', 'custom'], default: 'item' },
                    { key: 'qty_min', label: 'qty_min', type: 'number', default: 1 },
                    { key: 'qty_max', label: 'qty_max', type: 'number', default: 1 },
                    { key: 'chance', label: 'chance', type: 'number', default: 100 },
                    { key: 'notes', label: 'notes', default: '' }
                ], [
                    { key: 'default', label: 'default', seed: { loot_key: '', kind: 'item', qty_min: 1, qty_max: 1, chance: 100, notes: '' } }
                ]);
            }

            function renderServiceRules() {
                payload.rules.type_profile = 'service_mechanics';
                renderRuleFields(rulesRoot, [
                    { key: 'type_profile', label: 'type_profile', default: 'service_mechanics' },
                    { key: 'service_kind', label: 'service_kind', type: 'select', options: serviceKinds, default: serviceKinds[0] },
                    { key: 'category', label: 'category', default: 'service.mechanics' }
                ]);
                renderRuleFields(rulesRoot, [
                    { key: 'visibility.catalog', label: 'visibility.catalog', default: false },
                    { key: 'visibility.search', label: 'visibility.search', default: false },
                    { key: 'visibility.internal', label: 'visibility.internal', default: true }
                ]);
                renderSeededArrayEditor(rulesRoot, 'entries', 'entries', [
                    { key: 'key', label: 'key', default: '' },
                    { key: 'label_ru', label: 'label_ru', default: '' },
                    { key: 'label_en', label: 'label_en', default: '' },
                    { key: 'label_img', label: 'label_img', default: '' },
                    { key: 'notes', label: 'notes', default: '' },
                    { key: 'sortorder', label: 'sortorder', type: 'number', default: 0 },
                    { key: 'is_active', label: 'is_active', type: 'number', default: 1 }
                ], [
                    { key: 'default', label: 'default', seed: { key: '', label_ru: '', label_en: '', label_img: '', notes: '', sortorder: 0, is_active: 1 } }
                ]);
            }

            renderBlocks();

            if (simpleTypes.indexOf(entityType) !== -1) renderSimpleRules();
            if (entityType === 'ability') renderAbilityRules();
            if (entityType === 'talent') renderTalentRules();
            if (entityType === 'item') renderItemRules();
            if (entityType === 'bestiary') renderBestiaryRules();
            if (entityType === 'service_mechanics') renderServiceRules();
            if (heavyTypes.indexOf(entityType) === -1 && simpleTypes.indexOf(entityType) === -1 && entityType !== 'service_mechanics') renderSimpleRules();

            rawRoot.innerHTML = '<label>raw json</label><textarea id="af-arpg-raw-json" class="af-kb-plain-textarea" data-af-kb-editor-policy="deny"></textarea>';
            var rawField = rawRoot.querySelector('#af-arpg-raw-json');
            rawField.value = JSON.stringify(payload, null, 2);
            rawField.addEventListener('input', function () {
                var parsed = parseJsonSafe(rawField.value, payload);
                if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                    payload = parsed;
                    ensureRoot();
                    syncToRaw();
                }
            });

            syncToRaw();
        }

        if (mechanic === 'arpg') {
            if (typeSchema && typeSchema.rules_enabled === false) {
                bindRawOnlyMode('ARPG тип "' + type + '" ещё не имеет готовой schema-поддержки. Доступен raw-режим с валидационной ошибкой на save.');
            } else {
                bindArpgMode();
            }
            return;
        }

        if (mechanic !== 'dnd') {
            bindRawOnlyMode('Mechanic "' + mechanic + '" пока не поддерживает визуальный rules-редактор. Доступен raw-режим.');
            return;
        }


        if (!rulesEditorEnabled) {
            bindRawOnlyMode('Для этого типа rules_json выключен (rules_enabled/ui_rules_editor=false). Доступен только raw-режим (advanced).');
            return;
        }

        // ---------- Универсальные билд-блоки формы ----------
        function createInput(def, obj, onChange) {
            var wrap = document.createElement('div');
            if (def.fullWidth) {
                wrap.classList.add('af-kb-field--full');
            }
            var label = document.createElement('label');
            label.textContent = def.label || def.name;
            wrap.appendChild(label);

            var input;
            var value = obj[def.name];

            if (def.type === 'textarea') {
                input = document.createElement('textarea');
                input.value = value || '';
                input.classList.add('af-kb-plain-textarea');
                input.setAttribute('data-af-kb-editor-policy', 'deny');
            } else if (def.type === 'lines') {
                input = document.createElement('textarea');
                input.value = Array.isArray(value) ? value.join('\n') : (value || '');
                input.classList.add('af-kb-plain-textarea');
                input.setAttribute('data-af-kb-editor-policy', 'deny');
            } else if (def.type === 'json') {
                input = document.createElement('textarea');
                input.value = JSON.stringify(value || {}, null, 2);
                input.classList.add('af-kb-plain-textarea');
                input.setAttribute('data-af-kb-editor-policy', 'deny');
            } else if (def.type === 'number') {
                input = document.createElement('input');
                input.type = 'number';
                input.value = value != null ? String(value) : '0';
            } else if (def.type === 'url') {
                input = document.createElement('input');
                input.type = 'url';
                input.value = value || '';
            } else if (def.type === 'select') {
                input = document.createElement('select');

                if (def.allowEmpty) {
                    var emptyOption = document.createElement('option');
                    emptyOption.value = '';
                    emptyOption.textContent = def.emptyLabel || '—';
                    input.appendChild(emptyOption);
                }

                (def.options || []).forEach(function (opt) {
                    var option = document.createElement('option');
                    if (opt && typeof opt === 'object') {
                        option.value = String(opt.value);
                        option.textContent = String(opt.label != null ? opt.label : opt.value);
                    } else {
                        option.value = String(opt);
                        option.textContent = String(opt);
                    }
                    input.appendChild(option);
                });

                input.value = value != null ? String(value) : String((def.options && def.options[0]) || '');
                obj[def.name] = input.value;
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
                    fetch(
                        afKbEndpoint('json_list', 'misc.php?action=kb_json_list')
                        + '&type=' + encodeURIComponent(typeName || '')
                        + '&q=' + encodeURIComponent(input.value || ''),
                        { credentials: 'same-origin' }
                    )
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
                        })
                        .catch(function () {});
                }, 250));
            } else {
                input = document.createElement('input');
                input.type = 'text';
                input.value = value || '';
            }

            input.dataset.field = def.name;
            if (def.editorPolicy) {
                input.setAttribute('data-af-kb-editor-policy', def.editorPolicy);
            }

            function commitValue() {
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

                if (typeof onChange === 'function') {
                    onChange();
                }
            }

            if (def.type === 'select' || def.type === 'checkbox') {
                input.addEventListener('change', commitValue);
            } else {
                input.addEventListener('input', commitValue);

                if (def.type === 'number' || def.type === 'kb_key') {
                    input.addEventListener('change', commitValue);
                }
            }

            if (def.suffix && def.type === 'number') {
                var suffixWrap = document.createElement('div');
                suffixWrap.className = 'af-kb-input-suffix';
                suffixWrap.appendChild(input);
                var suffixText = document.createElement('span');
                suffixText.className = 'af-kb-input-suffix__text';
                suffixText.textContent = String(def.suffix);
                suffixWrap.appendChild(suffixText);
                wrap.appendChild(suffixWrap);
            } else {
                wrap.appendChild(input);
            }

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

        function defaultsForProfile(profile, typeProfile) {
            var canonicalType = (typeProfile || profile || '').toLowerCase();
            var base = {
                schema: 'af_kb.rules.v1',
                type_profile: typeProfile || profile,
                version: '1.0'
            };

            if (profile === 'heritage') {
                base.fixed_bonuses = {
                    stats: { str: 0, dex: 0, con: 0, int: 0, wis: 0, cha: 0 },
                    hp: 0, ep: 0, skill_points: 0, feat_points: 0, perk_points: 0, language_slots: 0, knowledge_slots: 0
                };
                base.fixed = {
                    stats: { str: 0, dex: 0, con: 0, int: 0, wis: 0, cha: 0 },
                    hp: 0, speed: 0, ep: 0, armor: 0, initiative: 0, carry: 0
                };
                base.choices = [];
                base.grants = [];
                base.traits = [];
                if (canonicalType === 'race') {
                    base.size = 'medium';
                    base.creature_type = 'humanoid';
                    base.speed = 30;
                    base.hp_base = 10;
                }
                return base;
            }

            if (profile === 'skill') {
                base.skill = {
                    category: 'general',
                    key_stat: 'dex',
                    rank_max: 4,
                    armor_check_penalty: false,
                    trained_only: false,
                    notes: ''
                };
                return base;
            }

            if (profile === 'spell') {
                base.spell = {
                    tradition: '',
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
                    item_kind: 'gear',
                    rarity: 'common',
                    equip: { slot: '', armor: { ac_bonus: 0, armor_type: 'light' } },
                    bonuses: [],
                    weapon: { damage_bonus: 0, damage_type: 'kinetic', rate_of_fire: 0, range: '', ammo_type_key: '' },
                    ammo: { ammo_type: '', damage_type: 'kinetic', damage_bonus: 0 },
                    gear: { subtype: '' },
                    augmentation: { subtype: 'cybernetic', slot: '', grade: '', humanity_cost_percent: 0, modifiers: [], effects: [], grants: [], requirements: {}, conflicts: {} },
                    cyberware: { slot: '', grade: '', humanity_cost_percent: 0, modifiers: [], effects: [], grants: [], requirements: {}, conflicts: {} },
                    price: 0,
                    currency: 'credits',
                    weight: 0,
                    stack_max: 1,
                    tags: [],
                    on_use: { cooldown: 0, cost: {}, effects: [] },
                    on_equip: { effects: [], grants: [] },
                    requirements: { level: 0, tags_any: [], tags_all: [] }
                };
                base.schema = 'af_kb.item.v2';
                return base;
            }
            if (profile === 'bestiary') {
                base.creature = {
                    size: 'medium',
                    kind: 'humanoid',
                    alignment: '',
                    challenge_rating: '1',
                    xp: 0,
                    proficiency_bonus: 2,
                    armor_class: 10,
                    initiative: 0,
                    hp: { average: 10, dice: '2d8+2' },
                    speed: { walk: 30 },
                    ability_scores: { str: 10, dex: 10, con: 10, int: 10, wis: 10, cha: 10 },
                    damage_vulnerabilities: [],
                    damage_resistances: [],
                    damage_immunities: [],
                    condition_immunities: [],
                    notes: ''
                };
                base.traits = [];
                base.actions = [];
                base.reactions = [];
                base.legendary_actions = [];
                base.loot = [];
                base.gm_notes = '';
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
                base.knowledge_group = '';
                base.skill = {
                    category: 'knowledge',
                    rank_max: 4,
                    armor_check_penalty: false,
                    trained_only: true,
                    notes: ''
                };
                return base;
            }

            if (profile === 'lore') {
                base.lore = {
                    scope: '',
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

            if (profile === 'character') {
                base.type_profile = 'character';
                base.character_profile = {
                    category: 'canons',
                    character_pic: '',
                    character_prototype: '',
                    character_name: '',
                    character_name_ru: '',
                    character_nicknames: '',
                    character_element: '',
                    character_gen: '',
                    character_race: '',
                    character_class: '',
                    character_faction: '',
                    character_app: ''
                };
                base.character_stats = {
                    character_hp: 0,
                    character_defense: 0,
                    character_element_damage_bonus: 0,
                    character_crit_damage: 0,
                    character_healing_received_bonus: 0,
                    character_attack_power: 0,
                    character_elemental_mastery: 0,
                    character_healing_bonus: 0,
                    character_shield_strength: 0,
                    character_luck: 0
                };
                base.character_abilities = [];
                base.character_links = [];
                base.character_meta = {
                    contract: 'af_kb.character.contract.v1',
                    contract_version: '1.0',
                    source: 'kb_manual'
                };
                return base;
            }

            return base;
        }

        function normalizeItemCanonical(payload) {
            if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
                return payload;
            }

            var rootFields = ['item_kind', 'rarity', 'price', 'currency', 'weight', 'stack_max', 'slot', 'equip', 'weapon', 'ammo', 'gear', 'bonuses', 'passive_bonuses', 'augmentation', 'cyberware', 'tags', 'on_use', 'on_equip', 'requirements'];
            var hasItem = payload.item && typeof payload.item === 'object' && !Array.isArray(payload.item);

            if (!hasItem) {
                var rootItem = {};
                rootFields.forEach(function (k) {
                    if (Object.prototype.hasOwnProperty.call(payload, k)) {
                        rootItem[k] = payload[k];
                    }
                });
                if (Object.keys(rootItem).length) {
                    payload.item = rootItem;
                }
            }

            rootFields.forEach(function (k) { delete payload[k]; });
            return payload;
        }
        function normalizeItemBonusRows(rows) {
            if (!Array.isArray(rows)) return [];
            return rows.map(function (row) {
                if (!row || typeof row !== 'object') return null;
                var rawType = String((row.type != null ? row.type : (row.target != null ? row.target : (row.stat != null ? row.stat : ''))) || '').trim();
                var normalizedTarget = String((row.target != null ? row.target : (row.stat != null ? row.stat : (row.attribute != null ? row.attribute : (row.key != null ? row.key : '')))) || '').trim();
                var type = rawType;
                if (itemBonusTargetOptions.indexOf(rawType) !== -1) {
                    normalizedTarget = rawType;
                    type = 'resource';
                }
                if (!type && normalizedTarget) type = 'resource';
                if (!type) return null;
                return {
                    type: type,
                    target: normalizedTarget,
                    mode: String(row.mode || 'add').trim() || 'add',
                    value: numberOrZero(row.value != null ? row.value : (row.amount != null ? row.amount : 0)),
                    unit: String(row.unit || ''),
                    conditions: String(row.conditions || row.condition || ''),
                    notes: String(row.notes || '')
                };
            }).filter(function (row) { return !!row; });
        }
        function isAugmentationSlot(slot) {
            return augmentationSlotOptions.indexOf(String(slot || '').trim()) !== -1;
        }

        var parsedRaw = readJson(getFieldValue(raw) || '{}', {});
        var schemaDefaults = (typeSchema.defaults && typeof typeSchema.defaults === 'object') ? deepClone(typeSchema.defaults) : {};
        if (uiProfile === 'item') {
            parsedRaw = normalizeItemCanonical(parsedRaw || {});
            schemaDefaults = normalizeItemCanonical(schemaDefaults || {});
        }
        var profileDefaults = defaultsForProfile(uiProfile, expectedTypeProfile);

        function isPlainObject(v) {
            return v && typeof v === 'object' && !Array.isArray(v);
        }

        function deepMerge(target, source) {
            var out = isPlainObject(target) ? deepClone(target) : {};
            if (!isPlainObject(source)) return out;

            Object.keys(source).forEach(function (k) {
                var sv = source[k];
                var tv = out[k];

                // arrays — заменяем целиком
                if (Array.isArray(sv)) {
                    out[k] = sv.slice();
                    return;
                }

                // objects — мерджим рекурсивно
                if (isPlainObject(sv)) {
                    out[k] = deepMerge(isPlainObject(tv) ? tv : {}, sv);
                    return;
                }

                // primitives
                out[k] = sv;
            });

            return out;
        }

        function merge3(a, b, c) {
            return deepMerge(deepMerge(a || {}, b || {}), c || {});
        }

        var merged = merge3(profileDefaults, schemaDefaults, parsedRaw);

        // ---------- UI layout (разный по профилям) ----------
        // Heritage: race/class/theme (как ты и хотела: одинаковый “расовый” UI только там)
        var isRaceHead = (type === 'race' || type === 'race_variant');

        // Сборка HTML-контейнеров
        var html = [];
        html.push('<div class="af-kb-help">Rules UI: <strong>' + esc(type || 'unknown') + '</strong> (profile: <strong>' + esc(uiProfile) + '</strong>)</div>');
        html.push('<div id="kb-rules-errors" class="af-kb-errors"></div>');

        if (uiProfile === 'heritage') {
            html.push(
                '<div class="af-kb-row">' +
                    '<div><label>Type profile</label><input type="text" id="kb-type-profile" readonly="readonly" /></div>' +
                    '<div><label>Size</label><select id="kb-size"><option value=""></option><option>tiny</option><option>small</option><option>medium</option><option>large</option><option>huge</option></select></div>' +
                '</div>' +
                '<div class="af-kb-row">' +
                    '<div><label>Creature type</label><input type="text" id="kb-creature" /></div>' +
                    '<div><label>Speed (base walk)</label><input type="number" id="kb-speed" /></div>' +
                '</div>' +
                '<div class="af-kb-row">' +
                    '<div><label>HP base</label><input type="number" id="kb-hp-base" /></div>' +
                '</div>' +
                '<div class="af-kb-help">Пустое значение удаляет поле из rules. 0 сохраняется как 0.</div>'
            );
        }

        // Контент по профилям
        if (uiProfile === 'heritage') {
            html.push(detailsBlock('Fixed bonuses', '<div id="kb-fixed-bonuses"></div>', true));
            html.push(detailsBlock('Fixed (derived baseline)', '<div id="kb-fixed-derived"></div>', true));
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
                    '<button type="button" class="af-kb-add" data-add-grant="resource">Выдать 2 skill_points</button>' +
                    '<button type="button" class="af-kb-add" data-add-grant="skill">Фиксированный навык (rank)</button>' +
                    '<button type="button" class="af-kb-add" data-add-grant="item">Стартовый предмет x1</button>' +
                    '<button type="button" class="af-kb-add" data-add-grant="resistance">Сопротивление огню 5</button>' +
                    '<button type="button" class="af-kb-add" data-add-grant="sense">Darkvision</button>' +
                    '<button type="button" class="af-kb-add" data-add-grant="speed">Скорость плавания 20</button>' +
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
        if (window.__afKbEditorGuard) {
            window.__afKbEditorGuard.refreshEditorPolicy(root);
            window.__afKbEditorGuard.observeEditorLeaks(root);
        }

        // ---------- State (разный по профилям) ----------
        var state = deepClone(merged);
        state.schema = state.schema || 'af_kb.rules.v1';
        state.type_profile = state.type_profile || expectedTypeProfile;
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

            if (!state.fixed || typeof state.fixed !== 'object') {
                state.fixed = { stats: {} };
            }
            if (!state.fixed.stats || typeof state.fixed.stats !== 'object') {
                state.fixed.stats = {};
            }
            stats.forEach(function (k) { state.fixed.stats[k] = numberOrZero(state.fixed.stats[k]); });
            ['hp', 'speed', 'ep', 'armor', 'initiative', 'carry'].forEach(function (k) {
                state.fixed[k] = numberOrZero(state.fixed[k]);
            });

            if (!Array.isArray(state.choices)) state.choices = [];
            if (!Array.isArray(state.grants)) state.grants = [];
            if (!Array.isArray(state.traits)) state.traits = [];
        }

        // Skill/spell/item/perk/...
        if (uiProfile === 'skill') {
        ensureObj('skill', {});
        if (!state.skill.category) state.skill.category = 'general';
        normalizeSkillStatValue(state.skill);
        state.skill.rank_max = numberOrZero(state.skill.rank_max != null ? state.skill.rank_max : 4);
        state.skill.armor_check_penalty = !!state.skill.armor_check_penalty;
        state.skill.trained_only = !!state.skill.trained_only;
        if (typeof state.skill.notes !== 'string') state.skill.notes = '';
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

            // legacy mapping: item_type -> item_kind
            if (!state.item.item_type && state.item.item_kind) {
                state.item.item_type = state.item.item_kind;
            }
            state.item.item_kind = normalizeItemKind(state.item.item_kind);

            if (!state.item.equip || typeof state.item.equip !== 'object') state.item.equip = {};
            if (!state.item.equip.slot && state.item.slot) state.item.equip.slot = state.item.slot;
            if (String(state.item.equip.slot || '').indexOf('consumable_') === 0) {
                state.item.equip.slot = String(state.item.equip.slot).replace('consumable_', 'support_');
            }
            var normalizedKind = normalizeItemKind(state.item.item_kind || 'gear');
            var normalizedUniqueRole = String((state.item.unique_role || state.item.unique_base_kind) || '').trim().toLowerCase();
            var normalizedEffectiveKind = normalizedKind === 'unique' ? (uniqueRoleToKind[normalizedUniqueRole] || '') : normalizedKind;
            if (!state.item.equip.armor || typeof state.item.equip.armor !== 'object') state.item.equip.armor = {};
            if (state.item.equip.armor.ac_bonus == null) state.item.equip.armor.ac_bonus = 0;
            if (!state.item.equip.armor.armor_type) state.item.equip.armor.armor_type = 'light';
            if (!state.item.weapon || typeof state.item.weapon !== 'object') state.item.weapon = {};
            if (state.item.weapon.damage_bonus == null) state.item.weapon.damage_bonus = 0;
            if (!state.item.weapon.damage_type) state.item.weapon.damage_type = 'kinetic';
            if (!state.item.ammo || typeof state.item.ammo !== 'object') state.item.ammo = {};
            if (!state.item.ammo.ammo_type) state.item.ammo.ammo_type = '';
            if (!state.item.ammo.damage_type) state.item.ammo.damage_type = 'kinetic';
            if (state.item.ammo.damage_bonus == null) state.item.ammo.damage_bonus = 0;
            if (!state.item.gear || typeof state.item.gear !== 'object') state.item.gear = {};
            if (!state.item.gear.subtype) state.item.gear.subtype = '';
            if (!Array.isArray(state.item.bonuses)) {
                state.item.bonuses = Array.isArray(state.item.passive_bonuses) ? state.item.passive_bonuses : [];
            }
            state.item.bonuses = normalizeItemBonusRows(state.item.bonuses);
            delete state.item.passive_bonuses;
            if (!state.item.augmentation || typeof state.item.augmentation !== 'object') state.item.augmentation = {};
            if (!state.item.augmentation.slot && state.item.cyberware && typeof state.item.cyberware === 'object') {
                state.item.augmentation = deepMerge(state.item.cyberware, state.item.augmentation);
            }
            if (!state.item.augmentation.subtype) state.item.augmentation.subtype = 'cybernetic';
            if (!state.item.augmentation.slot) state.item.augmentation.slot = '';
            if (!state.item.augmentation.slot && isAugmentationSlot(state.item.equip.slot) && (normalizedKind === 'augmentation' || normalizedEffectiveKind === 'augmentation')) {
                state.item.augmentation.slot = String(state.item.equip.slot || '').trim();
                state.item.equip.slot = '';
            }
            if (!state.item.augmentation.grade) state.item.augmentation.grade = '';
            if (state.item.augmentation.humanity_cost_percent == null) state.item.augmentation.humanity_cost_percent = 0;
            if (!Array.isArray(state.item.augmentation.modifiers)) state.item.augmentation.modifiers = [];
            if (!Array.isArray(state.item.augmentation.effects)) state.item.augmentation.effects = [];
            if (!Array.isArray(state.item.augmentation.grants)) state.item.augmentation.grants = [];
            if (!state.item.augmentation.requirements || typeof state.item.augmentation.requirements !== 'object') state.item.augmentation.requirements = {};
            if (!state.item.augmentation.conflicts || typeof state.item.augmentation.conflicts !== 'object') state.item.augmentation.conflicts = {};
            if (!state.item.cyberware || typeof state.item.cyberware !== 'object') state.item.cyberware = {};
            if (!state.item.cyberware.slot) state.item.cyberware.slot = '';
            if (!state.item.cyberware.grade) state.item.cyberware.grade = '';
            if (state.item.cyberware.humanity_cost_percent == null) state.item.cyberware.humanity_cost_percent = 0;
            if (!Array.isArray(state.item.cyberware.modifiers)) state.item.cyberware.modifiers = [];
            if (!Array.isArray(state.item.cyberware.effects)) state.item.cyberware.effects = [];
            if (!Array.isArray(state.item.cyberware.grants)) state.item.cyberware.grants = [];
            if (!state.item.cyberware.requirements || typeof state.item.cyberware.requirements !== 'object') state.item.cyberware.requirements = {};
            if (!state.item.cyberware.conflicts || typeof state.item.cyberware.conflicts !== 'object') state.item.cyberware.conflicts = {};
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
        if (uiProfile === 'bestiary') {
            ensureObj('creature', {});
            ensureObj('creature.hp', {});
            ensureObj('creature.speed', {});
            ensureObj('creature.ability_scores', {});
            ['str', 'dex', 'con', 'int', 'wis', 'cha'].forEach(function (k) {
                state.creature.ability_scores[k] = numberOrZero(state.creature.ability_scores[k] != null ? state.creature.ability_scores[k] : 10);
            });
            state.creature.xp = numberOrZero(state.creature.xp != null ? state.creature.xp : 0);
            state.creature.armor_class = numberOrZero(state.creature.armor_class != null ? state.creature.armor_class : 10);
            state.creature.initiative = numberOrZero(state.creature.initiative != null ? state.creature.initiative : 0);
            state.creature.proficiency_bonus = numberOrZero(state.creature.proficiency_bonus != null ? state.creature.proficiency_bonus : 2);
            state.creature.hp.average = numberOrZero(state.creature.hp.average != null ? state.creature.hp.average : 10);
            state.creature.hp.dice = String(state.creature.hp.dice || '2d8+2');
            state.creature.speed.walk = numberOrZero(state.creature.speed.walk != null ? state.creature.speed.walk : 30);
            if (!Array.isArray(state.creature.damage_vulnerabilities)) state.creature.damage_vulnerabilities = [];
            if (!Array.isArray(state.creature.damage_resistances)) state.creature.damage_resistances = [];
            if (!Array.isArray(state.creature.damage_immunities)) state.creature.damage_immunities = [];
            if (!Array.isArray(state.creature.condition_immunities)) state.creature.condition_immunities = [];
            if (typeof state.creature.notes !== 'string') state.creature.notes = '';
            if (!Array.isArray(state.traits)) state.traits = [];
            if (!Array.isArray(state.actions)) state.actions = [];
            if (!Array.isArray(state.reactions)) state.reactions = [];
            if (!Array.isArray(state.legendary_actions)) state.legendary_actions = [];
            if (!Array.isArray(state.loot)) state.loot = [];
            if (typeof state.gm_notes !== 'string') state.gm_notes = '';
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
            // knowledge хранится в state.skill + state.knowledge_group
            ensureObj('skill', {});
            if (!state.skill.category) state.skill.category = 'knowledge';
            normalizeSkillStatValue(state.skill);
            state.skill.rank_max = numberOrZero(state.skill.rank_max != null ? state.skill.rank_max : 4);
            state.skill.armor_check_penalty = !!state.skill.armor_check_penalty;
            state.skill.trained_only = !!state.skill.trained_only;
            if (typeof state.skill.notes !== 'string') state.skill.notes = '';

            if (typeof state.knowledge_group !== 'string') state.knowledge_group = '';
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
        function normalizeCharacterAbilityRow(ability, fallbackSortorder) {
            var row = (ability && typeof ability === 'object' && !Array.isArray(ability)) ? ability : {};
            var sort = numberOrZero(row.sortorder != null ? row.sortorder : (fallbackSortorder || 0));
            var slotIndex = numberOrZero(row.slot_index != null ? row.slot_index : (sort > 0 ? sort : 1));
            var abilityType = String(row.ability_type || row.type || 'active');
            var normalizeRows = function (items, schema) {
                return (Array.isArray(items) ? items : []).map(function (item) {
                    var source = (item && typeof item === 'object' && !Array.isArray(item)) ? item : {};
                    var out = {};
                    schema.forEach(function (def) {
                        if (def.type === 'number') {
                            out[def.key] = numberOrZero(source[def.key] != null ? source[def.key] : def.default);
                        } else {
                            out[def.key] = String(source[def.key] != null ? source[def.key] : def.default || '');
                        }
                    });
                    return out;
                });
            };

            return {
                slot_index: slotIndex,
                ability_name: String(row.ability_name || ''),
                icon_url: String(row.icon_url || ''),
                icon_class: String(row.icon_class || ''),
                type: abilityType,
                ability_type: abilityType,
                subtype: String(row.subtype || ''),
                slot: String(row.slot || ''),
                damage_type: String(row.damage_type || ''),
                targeting: String(row.targeting || ''),
                value_mode: String(row.value_mode || ''),
                range: numberOrZero(row.range != null ? row.range : 0),
                cast_time: numberOrZero(row.cast_time != null ? row.cast_time : 0),
                cooldown: numberOrZero(row.cooldown != null ? row.cooldown : 0),
                duration: String(row.duration != null ? row.duration : ''),
                max_charges: numberOrZero(row.max_charges != null ? row.max_charges : 0),
                level_cap: numberOrZero(row.level_cap != null ? row.level_cap : 0),
                damage_value: numberOrZero(row.damage_value != null ? row.damage_value : 0),
                shield_value: numberOrZero(row.shield_value != null ? row.shield_value : 0),
                heal_value: numberOrZero(row.heal_value != null ? row.heal_value : 0),
                ability_description: String(row.ability_description || ''),
                resources: normalizeRows(row.resources, [
                    { key: 'op', default: 'spend' },
                    { key: 'resource_key', default: '' },
                    { key: 'value', type: 'number', default: 0 },
                    { key: 'per', default: 'cast' },
                    { key: 'duration', type: 'number', default: 0 },
                    { key: 'notes', default: '' }
                ]),
                effects: normalizeRows(row.effects, [
                    { key: 'kind', default: 'damage' },
                    { key: 'damage_type', default: '' },
                    { key: 'targeting', default: '' },
                    { key: 'value_mode', default: 'flat' },
                    { key: 'value', type: 'number', default: 0 },
                    { key: 'formula_ref', default: '' },
                    { key: 'duration', type: 'number', default: 0 },
                    { key: 'hit_count', type: 'number', default: 1 },
                    { key: 'status_key', default: '' },
                    { key: 'notes', default: '' }
                ]),
                modifiers: normalizeRows(row.modifiers, [
                    { key: 'stat_key', default: '' },
                    { key: 'mode', default: 'flat' },
                    { key: 'value', type: 'number', default: 0 },
                    { key: 'duration', type: 'number', default: 0 },
                    { key: 'condition_text', default: '' },
                    { key: 'notes', default: '' }
                ]),
                triggers: normalizeRows(row.triggers, [
                    { key: 'event', default: '' },
                    { key: 'action_text', default: '' },
                    { key: 'condition_text', default: '' },
                    { key: 'notes', default: '' }
                ]),
                conditions: normalizeRows(row.conditions, [
                    { key: 'condition_type', default: '' },
                    { key: 'value', default: '' },
                    { key: 'notes', default: '' }
                ]),
                stacking: normalizeRows(row.stacking, [
                    { key: 'stack_key', default: '' },
                    { key: 'max_stacks', type: 'number', default: 1 },
                    { key: 'policy', default: '' },
                    { key: 'notes', default: '' }
                ]),
                upgrade_requirements: normalizeRows(row.upgrade_requirements, [
                    { key: 'level', type: 'number', default: 1 },
                    { key: 'required_item_key', default: '' },
                    { key: 'required_qty', type: 'number', default: 0 },
                    { key: 'required_currency_key', default: '' },
                    { key: 'required_currency_qty', type: 'number', default: 0 },
                    { key: 'notes', default: '' }
                ]),
                grants: normalizeRows(row.grants, [
                    { key: 'grant_type', default: '' },
                    { key: 'value', default: '' },
                    { key: 'value_num', type: 'number', default: 0 },
                    { key: 'duration', type: 'number', default: 0 },
                    { key: 'notes', default: '' }
                ]),
                ability_kb_key: String(row.ability_kb_key || row.ability_key || ''),
                sortorder: sort
            };
        }

        function renderInlineAbilitySummary(container, ability, idx, titlePrefix) {
            container.innerHTML = '';
            container.className = 'af-kb-inline-ability-summary';
            var isRuUi = String((document.documentElement && document.documentElement.lang) || '').toLowerCase().indexOf('ru') === 0;
            var mechanicsOptionSets = (window.afKbArpgMechanicsOptionSets && typeof window.afKbArpgMechanicsOptionSets === 'object')
                ? window.afKbArpgMechanicsOptionSets
                : {};
            var enumSets = {
                type: 'ability_type',
                subtype: 'ability_subtype',
                slot: 'ability_slot',
                damage_type: 'ability_damage_type',
                targeting: 'ability_targeting',
                value_mode: 'ability_value_mode'
            };
            var fieldLabels = isRuUi
                ? {
                    type: 'Тип',
                    subtype: 'Подтип',
                    slot: 'Слот',
                    damage_type: 'Тип урона',
                    targeting: 'Цель',
                    value_mode: 'Режим значения',
                    range: 'Дальность',
                    cast_time: 'Время каста',
                    cooldown: 'Перезарядка',
                    duration: 'Длительность',
                    max_charges: 'Макс. заряды',
                    level_cap: 'Предел уровня'
                }
                : {
                    type: 'Type',
                    subtype: 'Subtype',
                    slot: 'Slot',
                    damage_type: 'Damage type',
                    targeting: 'Targeting',
                    value_mode: 'Value mode',
                    range: 'Range',
                    cast_time: 'Cast time',
                    cooldown: 'Cooldown',
                    duration: 'Duration',
                    max_charges: 'Max charges',
                    level_cap: 'Level cap'
                };
            var resolveEnumLabel = function (dict, key) {
                var cleanKey = String(key || '').trim();
                if (!cleanKey) return '';
                var setKey = enumSets[dict] || '';
                if (setKey) {
                    var rows = Array.isArray(mechanicsOptionSets[setKey]) ? mechanicsOptionSets[setKey] : [];
                    for (var i = 0; i < rows.length; i += 1) {
                        var row = rows[i];
                        if (!row || String(row.key || '').trim() !== cleanKey) {
                            continue;
                        }
                        var localized = String(isRuUi ? (row.label_ru != null ? row.label_ru : '') : (row.label_en != null ? row.label_en : ''));
                        if (localized) {
                            return localized;
                        }
                        var fallbackLabel = String(row.label_ru != null ? row.label_ru : (row.label_en != null ? row.label_en : ''));
                        if (fallbackLabel) {
                            return fallbackLabel;
                        }
                        break;
                    }
                }
                return cleanKey.replace(/_/g, ' ').replace(/\b\w/g, function (ch) { return ch.toUpperCase(); });
            };
            var iconWrap = document.createElement('div');
            iconWrap.className = 'af-kb-inline-ability-summary__icon';
            var iconUrl = String((ability && ability.icon_url) || '').trim();
            var iconClass = String((ability && ability.icon_class) || '').trim();
            if (iconUrl) {
                var img = document.createElement('img');
                img.src = iconUrl;
                img.alt = '';
                img.loading = 'lazy';
                iconWrap.appendChild(img);
            } else if (iconClass) {
                var icon = document.createElement('i');
                icon.className = iconClass;
                icon.setAttribute('aria-hidden', 'true');
                iconWrap.appendChild(icon);
            } else {
                var fallback = document.createElement('span');
                fallback.className = 'af-kb-inline-ability-summary__icon-fallback';
                fallback.textContent = '∅';
                iconWrap.appendChild(fallback);
            }
            container.appendChild(iconWrap);

            var body = document.createElement('div');
            body.className = 'af-kb-inline-ability-summary__body';
            container.appendChild(body);

            var title = document.createElement('div');
            title.className = 'af-kb-inline-ability-summary__title';
            title.textContent = String((ability && ability.ability_name) || '').trim() || ((titlePrefix || 'Ability') + ' #' + (idx + 1));
            body.appendChild(title);

            var chips = document.createElement('div');
            chips.className = 'af-kb-inline-ability-summary__chips';
            body.appendChild(chips);
            var addChip = function (label, value, always) {
                var out = String(value != null ? value : '').trim();
                if (!always && out === '') return;
                var chip = document.createElement('span');
                chip.className = 'af-kb-inline-ability-summary__chip';
                chip.textContent = out !== '' ? (label + ': ' + out) : label;
                chips.appendChild(chip);
            };
            addChip(fieldLabels.type, resolveEnumLabel('type', String((ability && (ability.type || ability.ability_type)) || 'active') || 'active'), true);
            [
                ['subtype', ability ? ability.subtype : ''],
                ['slot', ability ? ability.slot : ''],
                ['damage_type', ability ? ability.damage_type : ''],
                ['targeting', ability ? ability.targeting : ''],
                ['value_mode', ability ? ability.value_mode : ''],
                ['range', ability ? ability.range : ''],
                ['cast_time', ability ? ability.cast_time : ''],
                ['cooldown', ability ? ability.cooldown : ''],
                ['duration', ability ? ability.duration : ''],
                ['max_charges', ability ? ability.max_charges : ''],
                ['level_cap', ability ? ability.level_cap : '']
            ].forEach(function (pair) {
                var key = pair[0];
                var value = pair[1];
                if (value == null || value === '') return;
                if (typeof value === 'number' && value <= 0) return;
                if (key === 'subtype' || key === 'slot' || key === 'damage_type' || key === 'targeting' || key === 'value_mode') {
                    value = resolveEnumLabel(key, value);
                }
                addChip(fieldLabels[key] || key, value, false);
            });

            var counters = document.createElement('div');
            counters.className = 'af-kb-inline-ability-summary__counters';
            body.appendChild(counters);
            [
                ['resources', ability ? ability.resources : []],
                ['effects', ability ? ability.effects : []],
                ['modifiers', ability ? ability.modifiers : []],
                ['triggers', ability ? ability.triggers : []],
                ['conditions', ability ? ability.conditions : []],
                ['stacking', ability ? ability.stacking : []],
                ['upgrade requirements', ability ? ability.upgrade_requirements : []]
            ].forEach(function (item) {
                var count = Array.isArray(item[1]) ? item[1].length : 0;
                if (!count) return;
                var chip = document.createElement('span');
                chip.className = 'af-kb-inline-ability-summary__counter';
                chip.textContent = item[0] + ': ' + count;
                counters.appendChild(chip);
            });
        }

        if (uiProfile === 'character') {
            ensureObj('character_profile', {});
            ensureObj('character_stats', {});
            ensureObj('character_meta', {});
            ensureArr('character_abilities');
            ensureArr('character_links');
            if (!state.character_profile.category) state.character_profile.category = 'canons';
            ['character_pic', 'character_prototype', 'character_name', 'character_name_ru', 'character_nicknames', 'character_element', 'character_gen', 'character_race', 'character_class', 'character_faction', 'character_app'].forEach(function (k) {
                state.character_profile[k] = String(state.character_profile[k] || '');
            });
            ['character_hp', 'character_defense', 'character_element_damage_bonus', 'character_crit_damage', 'character_healing_received_bonus', 'character_attack_power', 'character_elemental_mastery', 'character_healing_bonus', 'character_shield_strength', 'character_luck'].forEach(function (k) {
                state.character_stats[k] = numberOrZero(state.character_stats[k] != null ? state.character_stats[k] : 0);
            });
            state.character_abilities = state.character_abilities.map(function (ability) {
                return normalizeCharacterAbilityRow(ability, 0);
            });
            if (!state.character_meta.contract) state.character_meta.contract = 'af_kb.character.contract.v1';
            if (!state.character_meta.contract_version) state.character_meta.contract_version = '1.0';
            if (!state.character_meta.source) state.character_meta.source = 'kb_manual';
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

            resource: { op: 'resource', key: 'skill_points', value: 2, mode: 'add' },
            skill: { op: 'skill', key: 'athletics', rank: 1 },
            item: { op: 'item', key: 'starter_kit', amount: 1 },
            resistance: { op: 'resistance', key: 'fire', value: 5 },
            sense: { op: 'sense', key: 'darkvision', value: 60 },
            speed: { op: 'speed', kind: 'swim', value: 20, mode: 'set' },

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
            state.grants = (state.grants || []).map(normalizeGrant);
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
            { key: 'resource', label: 'Resource', fields: [
                { name: 'key', label: 'key', type: 'select', options: ['hp', 'ep', 'skill_points', 'feat_points', 'perk_points', 'language_slots', 'knowledge_slots'] },
                { name: 'value', label: 'value', type: 'number', required: true },
                { name: 'mode', label: 'mode', type: 'select', options: ['add', 'set'] }
            ] },
            { key: 'skill', label: 'Skill rank', fields: [
                { name: 'key', label: 'skill key', type: 'kb_key', kbTypeValue: 'skill', required: true },
                { name: 'rank', label: 'rank (0..4)', type: 'select', options: ['0', '1', '2', '3', '4'], required: true },
                { name: 'rank_max', label: 'rank_max', type: 'number', hint: 'Опционально: ограничение максимума ранга' }
            ] },
            { key: 'item', label: 'Item', fields: [
                { name: 'key', label: 'item key', type: 'kb_key', kbTypeValue: 'item', required: true },
                { name: 'amount', label: 'amount', type: 'number' }
            ] },
            { key: 'resistance', label: 'Resistance', fields: [
                { name: 'key', label: 'key', type: 'text', required: true },
                { name: 'value', label: 'value', type: 'number', required: true },
                { name: 'title_ru', label: 'title_ru', type: 'text' },
                { name: 'title_en', label: 'title_en', type: 'text' },
                { name: 'desc_ru', label: 'desc_ru', type: 'textarea' },
                { name: 'desc_en', label: 'desc_en', type: 'textarea' },
                { name: 'unit', label: 'unit', type: 'text' },
                { name: 'format', label: 'format', type: 'text', hint: 'например value / value+unit' }
            ] },
            { key: 'speed', label: 'Speed', fields: [
                { name: 'kind', label: 'kind', type: 'select', options: ['walk', 'fly', 'swim', 'climb', 'burrow'] },
                { name: 'value', label: 'value', type: 'number', required: true },
                { name: 'mode', label: 'mode', type: 'select', options: ['set', 'add'] }
            ] },
            { key: 'sense', label: 'Sense', fields: [
                { name: 'key', label: 'key', type: 'text', required: true },
                { name: 'value', label: 'value', type: 'number' },
                { name: 'title_ru', label: 'title_ru', type: 'text' },
                { name: 'title_en', label: 'title_en', type: 'text' },
                { name: 'desc_ru', label: 'desc_ru', type: 'textarea' },
                { name: 'desc_en', label: 'desc_en', type: 'textarea' },
                { name: 'unit', label: 'unit', type: 'text' },
                { name: 'format', label: 'format', type: 'text', hint: 'например value / value+unit' }
            ] }
        ];

        function normalizeGrant(grant) {
            var out = grant && typeof grant === 'object' ? JSON.parse(JSON.stringify(grant)) : {};
            if (!out.op && out.type === 'resource_gain') out.op = 'resource';
            if (!out.op && out.type === 'skill_rank') out.op = 'skill';
            if (!out.op && out.type === 'item_grant') out.op = 'item';
            if (!out.op && out.type === 'resistance_grant') out.op = 'resistance';
            if (!out.op && out.type === 'sense_grant') out.op = 'sense';
            if (!out.op && out.type === 'speed_grant') out.op = 'speed';

            if (out.op === 'resource') {
                if (!out.key) out.key = out.resource || 'skill_points';
                if (out.mode == null) out.mode = out.stack_mode || 'add';
            } else if (out.op === 'skill') {
                if (!out.key) out.key = out.kb_key || out.skill_key || '';
                if (out.rank == null) out.rank = out.skill_rank != null ? out.skill_rank : out.value;
                if (out.rank == null) out.rank = 1;
            } else if (out.op === 'item') {
                if (!out.key) out.key = out.kb_key || '';
                if (out.amount == null) out.amount = out.qty != null ? out.qty : 1;
            } else if (out.op === 'resistance') {
                if (!out.key) out.key = out.damage_type || '';
            } else if (out.op === 'sense') {
                if (!out.key) out.key = out.sense_type || '';
                if (out.value == null) out.value = out.range != null ? out.range : 0;
            } else if (out.op === 'speed') {
                if (!out.kind) out.kind = out.speed_type || 'walk';
                if (out.mode == null) out.mode = 'set';
            }

            delete out.type;
            delete out.resource;
            delete out.stack_mode;
            delete out.kb_type;
            delete out.kb_key;
            delete out.skill_key;
            delete out.skill_rank;
            delete out.qty;
            delete out.damage_type;
            delete out.sense_type;
            delete out.range;
            delete out.speed_type;

            return out;
        }

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

            typeProfile: root.querySelector('#kb-type-profile'),
            size: root.querySelector('#kb-size'),
            creature: root.querySelector('#kb-creature'),
            speed: root.querySelector('#kb-speed'),
            hpBase: root.querySelector('#kb-hp-base'),

            fixed: root.querySelector('#kb-fixed-bonuses'),
            fixedDerived: root.querySelector('#kb-fixed-derived'),
            choices: root.querySelector('#kb-choices-list'),
            grants: root.querySelector('#kb-grants-list'),
            traits: root.querySelector('#kb-traits-list'),

            profileFields: root.querySelector('#kb-profile-fields'),
            profileLists: root.querySelector('#kb-profile-lists')
        };

        if (uiProfile === 'heritage') {
            if (fields.typeProfile) fields.typeProfile.value = expectedTypeProfile || '';
            if (fields.size) fields.size.value = state.size != null ? String(state.size) : (isRaceHead ? 'medium' : '');
            if (fields.creature) fields.creature.value = state.creature_type != null ? String(state.creature_type) : (isRaceHead ? 'humanoid' : '');
            if (fields.speed) fields.speed.value = state.speed != null ? String(state.speed) : (isRaceHead ? '30' : '');
            if (fields.hpBase) fields.hpBase.value = state.hp_base != null ? String(state.hp_base) : (isRaceHead ? '10' : '');
        }

        // ---------- validation / payload ----------
        function getDef(defs, key) {
            for (var i = 0; i < defs.length; i += 1) {
                if (defs[i].key === key) return defs[i];
            }
            return null;
        }

        function syncAugmentationDomFallbacks() {
            if (uiProfile !== 'item' || !fields.profileFields || !state.item || typeof state.item !== 'object') {
                return;
            }

            if (!state.item.augmentation || typeof state.item.augmentation !== 'object') {
                state.item.augmentation = {};
            }

            if (!state.item.cyberware || typeof state.item.cyberware !== 'object') {
                state.item.cyberware = {};
            }

            var kind = normalizeItemKind((state.item.item_kind || 'gear'));
            var augmentationMode = kind === 'augmentation'
                || String((state.item.augmentation && state.item.augmentation.slot) || '').trim() !== ''
                || String((state.item.cyberware && state.item.cyberware.slot) || '').trim() !== '';

            if (!augmentationMode) {
                return;
            }

            var slotNode = fields.profileFields.querySelector('select[data-field="slot"], input[data-field="slot"]');
            var subtypeNode = fields.profileFields.querySelector('select[data-field="subtype"], input[data-field="subtype"]');
            var humanityNode = fields.profileFields.querySelector('input[data-field="humanity_cost_percent"]');

            if (slotNode) {
                state.item.augmentation.slot = String(slotNode.value || '').trim();
            }
            if (subtypeNode) {
                state.item.augmentation.subtype = String(subtypeNode.value || '').trim();
            }
            if (humanityNode) {
                state.item.augmentation.humanity_cost_percent = numberOrZero(humanityNode.value);
            }

            state.item.cyberware = deepMerge(state.item.cyberware || {}, state.item.augmentation || {});
        }

        function validate() {
            var errors = [];
            var validationState = toPayload();

            if (!validationState || typeof validationState !== 'object') {
                validationState = {};
            }

            if (!validationState.schema) {
                errors.push('schema: required');
            }

            if (uiProfile === 'knowledge') {
                if (!String(validationState.knowledge_group || '').trim()) {
                    errors.push('knowledge_group: required');
                }
            }

            if (uiProfile === 'item') {
                var item = (validationState.item && typeof validationState.item === 'object')
                    ? validationState.item
                    : {};

                var rarityAllowed = ['common', 'uncommon', 'rare', 'unique', 'illegal', 'restricted', 'legendary', 'mythic'];
                var rarity = String(item.rarity || 'common').trim().toLowerCase();
                if (rarityAllowed.indexOf(rarity) === -1) {
                    errors.push('item.rarity: unsupported value');
                }

                var kind = normalizeItemKind(item.item_kind || 'gear');
                item.item_kind = kind;

                var uniqueRole = String(item.unique_role || item.unique_base_kind || '').trim().toLowerCase();
                var effectiveKind = kind === 'unique' ? (uniqueRoleToKind[uniqueRole] || '') : kind;

                var equip = (item.equip && typeof item.equip === 'object') ? item.equip : {};
                var equipSlot = String(equip.slot || '').trim().toLowerCase();

                var augmentation = (item.augmentation && typeof item.augmentation === 'object') ? item.augmentation : {};
                var cyberware = (item.cyberware && typeof item.cyberware === 'object') ? item.cyberware : {};

                var augmentationSlot = String(augmentation.slot || cyberware.slot || '').trim().toLowerCase();
                if (!augmentationSlot && equipSlot && augmentationSlotOptions.indexOf(equipSlot) !== -1) {
                    augmentationSlot = equipSlot;
                }

                var allowed = slotByKind[effectiveKind || kind] || [''];

                if (kind === 'augmentation') {
                    if (augmentationSlot && augmentationSlotOptions.indexOf(augmentationSlot) === -1) {
                        errors.push('item.augmentation.slot: incompatible with augmentation');
                    }
                } else if (kind === 'unique' && !effectiveKind) {
                    errors.push('item.unique_role: required for unique');
                } else {
                    if (equipSlot && allowed.indexOf(equipSlot) === -1) {
                        errors.push('item.equip.slot: incompatible with item_kind=' + (effectiveKind || kind));
                    }

                    if (
                        (kind === 'armor' || kind === 'weapon' || effectiveKind === 'armor' || effectiveKind === 'weapon')
                        && !equipSlot
                    ) {
                        errors.push('item.equip.slot: required for armor/weapon');
                    }
                }

                if (kind === 'augmentation') {
                    var humanityCost = numberOrZero(
                        augmentation.humanity_cost_percent != null
                            ? augmentation.humanity_cost_percent
                            : (cyberware.humanity_cost_percent || 0)
                    );

                    if (humanityCost < 0 || humanityCost > 100) {
                        errors.push('item.augmentation.humanity_cost_percent: range 0..100');
                    }
                }
            }

            if (uiProfile === 'heritage') {
                function validateSlugList(values, prefix) {
                    (values || []).forEach(function (v) {
                        if (!slugPattern.test(v)) {
                            errors.push(prefix + ': invalid key ' + v);
                        }
                    });
                }

                validationState.choices = Array.isArray(validationState.choices) ? validationState.choices : [];
                validationState.traits = Array.isArray(validationState.traits) ? validationState.traits : [];

                validationState.choices.forEach(function (item, i) {
                    if (!item.type) {
                        errors.push('choices[' + i + ']: missing type');
                        return;
                    }

                    var def = getDef(choiceDefs, item.type);
                    if (!def) {
                        return;
                    }

                    def.fields.forEach(function (f) {
                        if (f.required && (item[f.name] == null || item[f.name] === '')) {
                            errors.push('choices[' + i + '].' + f.name + ': required');
                        }
                    });

                    validateSlugList(item.options, 'choices[' + i + '].options');
                    validateSlugList(item.exclude, 'choices[' + i + '].exclude');
                });

                validationState.traits.forEach(function (t, i) {
                    if (!t.key) {
                        errors.push('traits[' + i + '].key: required');
                    }
                });
            }

            fields.errors.innerHTML = errors.length
                ? ('<div class="af-kb-help">Ошибки схемы:<br>' + errors.map(esc).join('<br>') + '</div>')
                : '';

            return errors;
        }

        function sanitizePayloadByProfile(payload, profile) {
            var p = deepClone(payload || {});
            p.schema = p.schema || 'af_kb.rules.v1';
            p.type_profile = expectedTypeProfile || profile || p.type_profile || 'raw';
            p.version = p.version || '1.0';

            if (profile === 'skill') {
                var skill = (p.skill && typeof p.skill === 'object') ? p.skill : {};
                var keyStat = normalizeSkillStatValue(skill);
                return {
                    schema: p.schema,
                    type_profile: expectedTypeProfile || 'skill',
                    version: p.version,
                    skill: {
                        category: String(skill.category || 'general'),
                        key_stat: keyStat || null,
                        attribute: keyStat || null,
                        rank_max: numberOrZero(skill.rank_max != null ? skill.rank_max : 4),
                        armor_check_penalty: !!skill.armor_check_penalty,
                        trained_only: !!skill.trained_only,
                        notes: (typeof skill.notes === 'string') ? skill.notes : ''
                    }
                };
            }

            if (profile === 'spell') {
                var s = (p.spell && typeof p.spell === 'object') ? p.spell : {};
                var reqS = (s.requirements && typeof s.requirements === 'object') ? s.requirements : {};
                if (!Array.isArray(reqS.tags_any)) reqS.tags_any = [];
                if (!Array.isArray(reqS.tags_all)) reqS.tags_all = [];
                if (reqS.level == null) reqS.level = 0;

                return {
                    schema: p.schema,
                    type_profile: expectedTypeProfile || 'spell',
                    version: p.version,
                    spell: {
                        tradition: String(s.tradition || ''),
                        school: String(s.school || ''),
                        level: numberOrZero(s.level != null ? s.level : 1),
                        cast_time: String(s.cast_time || ''),
                        range: String(s.range || ''),
                        duration: String(s.duration || ''),
                        cost: (s.cost && typeof s.cost === 'object') ? s.cost : {},
                        traits: Array.isArray(s.traits) ? s.traits : [],
                        effects: Array.isArray(s.effects) ? s.effects : [],
                        requirements: reqS
                    }
                };
            }

            if (profile === 'item') {
                var it = (p.item && typeof p.item === 'object') ? p.item : {};

                // legacy mapping
                if (!it.item_kind && it.item_type) {
                    it.item_kind = it.item_type;
                }

                var reqI = (it.requirements && typeof it.requirements === 'object') ? it.requirements : {};
                if (!Array.isArray(reqI.tags_any)) reqI.tags_any = [];
                if (!Array.isArray(reqI.tags_all)) reqI.tags_all = [];
                if (reqI.level == null) reqI.level = 0;

                var onUse = (it.on_use && typeof it.on_use === 'object') ? it.on_use : {};
                if (onUse.cooldown == null) onUse.cooldown = 0;
                if (!onUse.cost || typeof onUse.cost !== 'object') onUse.cost = {};
                if (!Array.isArray(onUse.effects)) onUse.effects = [];

                var onEquip = (it.on_equip && typeof it.on_equip === 'object') ? it.on_equip : {};
                if (!Array.isArray(onEquip.effects)) onEquip.effects = [];
                if (!Array.isArray(onEquip.grants)) onEquip.grants = [];

                var itemOut = deepClone(it);
                itemOut.item_kind = normalizeItemKind(it.item_kind || 'gear');
                itemOut.unique_role = String(it.unique_role || it.unique_base_kind || '');
                itemOut.unique_base_kind = itemOut.unique_role;
                itemOut.rarity = String(it.rarity || 'common');
                itemOut.equip = {
                    slot: String((it.equip && it.equip.slot) || it.slot || '').replace(/^consumable_/, 'support_'),
                    armor: {
                        ac_bonus: numberOrZero((it.equip && it.equip.armor && it.equip.armor.ac_bonus) != null ? it.equip.armor.ac_bonus : 0),
                        armor_type: String((it.equip && it.equip.armor && it.equip.armor.armor_type) || 'light')
                    }
                };
                itemOut.weapon = (it.weapon && typeof it.weapon === 'object') ? it.weapon : {};
                itemOut.weapon.damage_bonus = numberOrZero(itemOut.weapon.damage_bonus != null ? itemOut.weapon.damage_bonus : 0);
                itemOut.weapon.damage_type = String(itemOut.weapon.damage_type || 'kinetic');
                itemOut.ammo = (it.ammo && typeof it.ammo === 'object') ? it.ammo : {};
                itemOut.ammo.ammo_type = String(itemOut.ammo.ammo_type || '');
                itemOut.ammo.damage_type = String(itemOut.ammo.damage_type || 'kinetic');
                itemOut.ammo.damage_bonus = numberOrZero(itemOut.ammo.damage_bonus != null ? itemOut.ammo.damage_bonus : 0);
                itemOut.gear = (it.gear && typeof it.gear === 'object') ? it.gear : {};
                itemOut.gear.subtype = String(itemOut.gear.subtype || '');
                itemOut.bonuses = normalizeItemBonusRows(Array.isArray(it.bonuses) ? it.bonuses : (Array.isArray(it.passive_bonuses) ? it.passive_bonuses : []));
                var augmentationRaw = (it.augmentation && typeof it.augmentation === 'object') ? it.augmentation : ((it.cyberware && typeof it.cyberware === 'object') ? it.cyberware : {});
                itemOut.augmentation = deepClone(augmentationRaw || {});
                itemOut.augmentation.subtype = String(itemOut.augmentation.subtype || (itemOut.item_kind === 'augmentation' ? 'cybernetic' : ''));
                itemOut.augmentation.slot = String(itemOut.augmentation.slot || '');
                var itemOutUniqueRole = String(itemOut.unique_role || '').trim().toLowerCase();
                var itemOutEffectiveKind = itemOut.item_kind === 'unique' ? (uniqueRoleToKind[itemOutUniqueRole] || '') : itemOut.item_kind;
                if (!itemOut.augmentation.slot && isAugmentationSlot(itemOut.equip.slot) && (itemOut.item_kind === 'augmentation' || itemOutEffectiveKind === 'augmentation')) {
                    itemOut.augmentation.slot = String(itemOut.equip.slot || '').trim();
                    itemOut.equip.slot = '';
                }
                var legacyGrade = String(itemOut.augmentation.grade || '');
                if (!legacyGrade && it.cyberware && typeof it.cyberware === 'object') {
                    legacyGrade = String(it.cyberware.grade || '');
                }
                itemOut.augmentation.grade = legacyGrade;
                itemOut.augmentation.humanity_cost_percent = Math.max(0, Math.min(100, numberOrZero(itemOut.augmentation.humanity_cost_percent != null ? itemOut.augmentation.humanity_cost_percent : 0)));
                itemOut.augmentation.modifiers = Array.isArray(itemOut.augmentation.modifiers) ? itemOut.augmentation.modifiers.filter(function (row) { return row && row.type; }) : [];
                itemOut.augmentation.effects = Array.isArray(itemOut.augmentation.effects) ? itemOut.augmentation.effects.filter(function (row) { return row && row.event && row.effect_type; }) : [];
                itemOut.augmentation.grants = Array.isArray(itemOut.augmentation.grants) ? itemOut.augmentation.grants.filter(function (row) { return row && row.grant_type; }) : [];
                itemOut.augmentation.requirements = (itemOut.augmentation.requirements && typeof itemOut.augmentation.requirements === 'object') ? itemOut.augmentation.requirements : {};
                itemOut.augmentation.conflicts = (itemOut.augmentation.conflicts && typeof itemOut.augmentation.conflicts === 'object') ? itemOut.augmentation.conflicts : {};
                itemOut.cyberware = deepClone(itemOut.augmentation);
                itemOut.price = numberOrZero(it.price != null ? it.price : 0);
                itemOut.currency = String(it.currency || 'credits');
                itemOut.weight = numberOrZero(it.weight != null ? it.weight : 0);
                itemOut.stack_max = numberOrZero(it.stack_max != null ? it.stack_max : 1);
                itemOut.tags = Array.isArray(it.tags) ? it.tags : [];
                itemOut.on_use = onUse;
                itemOut.on_equip = onEquip;
                itemOut.requirements = reqI;
                return normalizeItemCanonical({ schema: 'af_kb.item.v2', type_profile: expectedTypeProfile || 'item', version: p.version, item: itemOut });
            }

            if (profile === 'knowledge') {
                var skillK = (p.skill && typeof p.skill === 'object') ? p.skill : {};
                var keyStatK = normalizeSkillStatValue(skillK);
                return {
                    schema: p.schema,
                    type_profile: 'knowledge',
                    version: p.version,
                    knowledge_group: String(p.knowledge_group || ''),
                    skill: {
                        category: 'knowledge',
                        key_stat: keyStatK || null,
                        attribute: keyStatK || null,
                        rank_max: numberOrZero(skillK.rank_max != null ? skillK.rank_max : 4),
                        armor_check_penalty: !!skillK.armor_check_penalty,
                        trained_only: !!skillK.trained_only,
                        notes: (typeof skillK.notes === 'string') ? skillK.notes : ''
                    }
                };
            }
            if (profile === 'bestiary') {
                var c = (p.creature && typeof p.creature === 'object') ? p.creature : {};
                var hp = (c.hp && typeof c.hp === 'object') ? c.hp : {};
                var speed = (c.speed && typeof c.speed === 'object') ? c.speed : {};
                var as = (c.ability_scores && typeof c.ability_scores === 'object') ? c.ability_scores : {};
                return {
                    schema: p.schema,
                    type_profile: expectedTypeProfile || 'bestiary',
                    version: p.version,
                    creature: {
                        size: String(c.size || 'medium'),
                        kind: String(c.kind || 'humanoid'),
                        alignment: String(c.alignment || ''),
                        challenge_rating: String(c.challenge_rating || '1'),
                        xp: numberOrZero(c.xp != null ? c.xp : 0),
                        proficiency_bonus: numberOrZero(c.proficiency_bonus != null ? c.proficiency_bonus : 2),
                        armor_class: numberOrZero(c.armor_class != null ? c.armor_class : 10),
                        initiative: numberOrZero(c.initiative != null ? c.initiative : 0),
                        hp: {
                            average: numberOrZero(hp.average != null ? hp.average : 10),
                            dice: String(hp.dice || '2d8+2')
                        },
                        speed: {
                            walk: numberOrZero(speed.walk != null ? speed.walk : 30)
                        },
                        ability_scores: {
                            str: numberOrZero(as.str != null ? as.str : 10),
                            dex: numberOrZero(as.dex != null ? as.dex : 10),
                            con: numberOrZero(as.con != null ? as.con : 10),
                            int: numberOrZero(as.int != null ? as.int : 10),
                            wis: numberOrZero(as.wis != null ? as.wis : 10),
                            cha: numberOrZero(as.cha != null ? as.cha : 10)
                        },
                        damage_vulnerabilities: Array.isArray(c.damage_vulnerabilities) ? c.damage_vulnerabilities : [],
                        damage_resistances: Array.isArray(c.damage_resistances) ? c.damage_resistances : [],
                        damage_immunities: Array.isArray(c.damage_immunities) ? c.damage_immunities : [],
                        condition_immunities: Array.isArray(c.condition_immunities) ? c.condition_immunities : [],
                        notes: String(c.notes || '')
                    },
                    traits: Array.isArray(p.traits) ? p.traits : [],
                    actions: Array.isArray(p.actions) ? p.actions : [],
                    reactions: Array.isArray(p.reactions) ? p.reactions : [],
                    legendary_actions: Array.isArray(p.legendary_actions) ? p.legendary_actions : [],
                    loot: Array.isArray(p.loot) ? p.loot : [],
                    gm_notes: String(p.gm_notes || '')
                };
            }

            if (profile === 'character') {
                var profileC = (p.character_profile && typeof p.character_profile === 'object') ? p.character_profile : {};
                var statsC = (p.character_stats && typeof p.character_stats === 'object') ? p.character_stats : {};
                var metaC = (p.character_meta && typeof p.character_meta === 'object') ? p.character_meta : {};
                var abilitiesC = Array.isArray(p.character_abilities) ? p.character_abilities : [];
                return {
                    schema: p.schema,
                    type_profile: 'character',
                    version: p.version,
                    character_profile: {
                        category: String(profileC.category || 'canons'),
                        character_pic: String(profileC.character_pic || ''),
                        character_prototype: String(profileC.character_prototype || ''),
                        character_name: String(profileC.character_name || ''),
                        character_name_ru: String(profileC.character_name_ru || ''),
                        character_nicknames: String(profileC.character_nicknames || ''),
                        character_element: String(profileC.character_element || ''),
                        character_gen: String(profileC.character_gen || ''),
                        character_race: String(profileC.character_race || ''),
                        character_class: String(profileC.character_class || ''),
                        character_faction: String(profileC.character_faction || ''),
                        character_app: String(profileC.character_app || '')
                    },
                    character_stats: {
                        character_hp: numberOrZero(statsC.character_hp != null ? statsC.character_hp : 0),
                        character_defense: numberOrZero(statsC.character_defense != null ? statsC.character_defense : 0),
                        character_element_damage_bonus: numberOrZero(statsC.character_element_damage_bonus != null ? statsC.character_element_damage_bonus : 0),
                        character_crit_damage: numberOrZero(statsC.character_crit_damage != null ? statsC.character_crit_damage : 0),
                        character_healing_received_bonus: numberOrZero(statsC.character_healing_received_bonus != null ? statsC.character_healing_received_bonus : 0),
                        character_attack_power: numberOrZero(statsC.character_attack_power != null ? statsC.character_attack_power : 0),
                        character_elemental_mastery: numberOrZero(statsC.character_elemental_mastery != null ? statsC.character_elemental_mastery : 0),
                        character_healing_bonus: numberOrZero(statsC.character_healing_bonus != null ? statsC.character_healing_bonus : 0),
                        character_shield_strength: numberOrZero(statsC.character_shield_strength != null ? statsC.character_shield_strength : 0),
                        character_luck: numberOrZero(statsC.character_luck != null ? statsC.character_luck : 0)
                    },
                    character_abilities: abilitiesC.map(function (row) {
                        return normalizeCharacterAbilityRow(row, 0);
                    }),
                    character_links: Array.isArray(p.character_links) ? p.character_links : [],
                    character_meta: {
                        contract: String(metaC.contract || 'af_kb.character.contract.v1'),
                        contract_version: String(metaC.contract_version || '1.0'),
                        source: String(metaC.source || 'kb_manual')
                    }
                };
            }

            if (profile === 'heritage') {
                ['size', 'creature_type', 'speed', 'hp_base'].forEach(function (k) {
                    if (p[k] === '') delete p[k];
                });
                if (typeof p.size !== 'string' || !p.size.trim()) delete p.size;
                if (typeof p.creature_type !== 'string' || !p.creature_type.trim()) delete p.creature_type;
                if (p.speed == null || p.speed === '') delete p.speed;
                if (p.hp_base == null || p.hp_base === '') delete p.hp_base;
                return p;
            }

            // прочие профили пока не режем жёстко
            return p;
        }

        function toPayload() {
            if (uiProfile === 'item') {
                syncAugmentationDomFallbacks();
            }            
            var payload = deepClone(state);

            // Heritage back-compat (как у тебя было)
            if (uiProfile === 'heritage') {
                if (!payload.fixed_bonuses) payload.fixed_bonuses = { stats: {} };
                if (!payload.fixed_bonuses.stats) payload.fixed_bonuses.stats = {};
                stats.forEach(function (k) { payload.fixed_bonuses.stats[k] = numberOrZero(payload.fixed_bonuses.stats[k]); });
                if (!payload.fixed) payload.fixed = { stats: {} };
                if (!payload.fixed.stats) payload.fixed.stats = {};
                stats.forEach(function (k) { payload.fixed.stats[k] = numberOrZero(payload.fixed.stats[k]); });
                ['hp', 'speed', 'ep', 'armor', 'initiative', 'carry'].forEach(function (k) { payload.fixed[k] = numberOrZero(payload.fixed[k]); });

                payload.choices = (payload.choices || []).map(function (c) {
                    var out = deepClone(c);
                    if (out.type === 'stat_bonus_choice') out.type = 'stat_bonus';
                    else if (out.type === 'kb_pick_choice') out.type = 'kb_pick';
                    else if (out.type === 'language_pick_choice') out.type = 'language_pick';
                    return out;
                });
            }

            payload = sanitizePayloadByProfile(payload, uiProfile);

            return payload;
        }

        function syncRulesToMeta(payload) {
            // Для ARPG payload уже является полным envelope.
            // Нельзя повторно засовывать его в meta.rules, иначе получаем дубль:
            // meta.ui + payload.ui + entry fields.
            if (mechanic === 'arpg') {
                return;
            }

            var meta = readJson(getFieldValue(metaRaw) || '{}', {});
            if (!meta || typeof meta !== 'object' || Array.isArray(meta)) {
                meta = {};
            }
            meta.rules = payload;
            setFieldValue(metaRaw, JSON.stringify(meta, null, 2));
        }

        function shouldLogRulesDebug() {
            try {
                return /(?:^|[?&])kb_debug_rules=1(?:&|$)/.test(window.location.search || '');
            } catch (e) {
                return false;
            }
        }

        function syncRawNow() {
            validate();
            var payload = toPayload();
            if (shouldLogRulesDebug()) {
                console.log('KB save payload grants', payload && payload.grants ? payload.grants : []);
            }
            setFieldValue(raw, JSON.stringify(payload, null, 2));
            syncRulesToMeta(payload);
        }


        var syncRawDebounced = debounce(syncRawNow, 250);

        function optionalNumberOrUndefined(value) {
            var str = String(value == null ? '' : value).trim();
            if (str === '') return undefined;
            return numberOrZero(str);
        }


        function syncFromHeritageBase() {
            if (uiProfile !== 'heritage') {
                syncRawDebounced();
                return;
            }

            var size = (fields.size && fields.size.value ? String(fields.size.value).trim() : '');
            var creature = (fields.creature && fields.creature.value ? String(fields.creature.value).trim() : '');
            var speed = optionalNumberOrUndefined(fields.speed ? fields.speed.value : '');
            var hpBase = optionalNumberOrUndefined(fields.hpBase ? fields.hpBase.value : '');

            if (size === '') delete state.size;
            else state.size = size;

            if (creature === '') delete state.creature_type;
            else state.creature_type = creature;

            if (speed === undefined) delete state.speed;
            else state.speed = speed;

            if (hpBase === undefined) delete state.hp_base;
            else state.hp_base = hpBase;

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

            var resources = ['hp', 'ep', 'skill_points', 'feat_points', 'perk_points', 'language_slots', 'knowledge_slots'];
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

        function renderFixedDerived() {
            if (!fields.fixedDerived) return;
            fields.fixedDerived.innerHTML = '';

            var rowStats = document.createElement('div');
            rowStats.className = 'af-kb-row';
            stats.forEach(function (key) {
                var box = document.createElement('div');
                box.innerHTML = '<label>stats.' + key + '</label>' +
                    '<input type="number" data-fixed-stat="' + key + '" value="' + numberOrZero(state.fixed.stats[key]) + '" />';
                rowStats.appendChild(box);
            });
            fields.fixedDerived.appendChild(rowStats);

            var rowBase = document.createElement('div');
            rowBase.className = 'af-kb-row';
            ['hp', 'speed', 'ep', 'armor', 'initiative', 'carry'].forEach(function (key) {
                var box = document.createElement('div');
                box.innerHTML = '<label>' + key + '</label>' +
                    '<input type="number" data-fixed-value="' + key + '" value="' + numberOrZero(state.fixed[key]) + '" />';
                rowBase.appendChild(box);
            });
            fields.fixedDerived.appendChild(rowBase);

            fields.fixedDerived.querySelectorAll('[data-fixed-stat]').forEach(function (input) {
                input.addEventListener('input', function () {
                    state.fixed.stats[input.getAttribute('data-fixed-stat')] = numberOrZero(input.value);
                    syncRawDebounced();
                });
            });
            fields.fixedDerived.querySelectorAll('[data-fixed-value]').forEach(function (input) {
                input.addEventListener('input', function () {
                    state.fixed[input.getAttribute('data-fixed-value')] = numberOrZero(input.value);
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

                var itemType = typeName === 'grant' ? (item.op || item.type) : item.type;
                var def = getDef(defs, itemType);
                if (!def) {
                    card.innerHTML =
                        '<div class="af-kb-help"><strong>Unknown type:</strong> ' + esc(itemType || 'unknown') + '</div>' +
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
                { name: 'key_stat', label: 'Ключевой атрибут навыка', type: 'select', allowEmpty: true, emptyLabel: '— не выбран —', options: ['str','dex','con','int','wis','cha'], hint: 'Какой атрибут даёт модификатор навыка' },
                { name: 'rank_max', label: 'Rank max', type: 'number', hint: 'Обычно 4 (0..4)' }
            ];

            var flags = [
                { name: 'armor_check_penalty', label: 'Armor check penalty', type: 'checkbox', hint: 'Если броня/нагрузка влияет на этот навык' },
                { name: 'trained_only', label: 'Trained only', type: 'checkbox', hint: 'Если навык нельзя использовать без ранга trained' }
            ];

            var notes = [
                { name: 'notes', label: 'Notes', type: 'textarea', hint: 'Короткие правила/исключения (например “Escape использует Acrobatics”)' }
            ];

            var grid = document.createElement('div');
            grid.className = 'af-kb-row';
            def.forEach(function (d) { grid.appendChild(createInput(d, state.skill, syncRawDebounced)); });
            fields.profileFields.appendChild(grid);

            var grid2 = document.createElement('div');
            grid2.className = 'af-kb-row';
            flags.forEach(function (d) { grid2.appendChild(createInput(d, state.skill, syncRawDebounced)); });
            fields.profileFields.appendChild(grid2);

            var grid3 = document.createElement('div');
            grid3.className = 'af-kb-row';
            notes.forEach(function (d) { grid3.appendChild(createInput(d, state.skill, syncRawDebounced)); });
            fields.profileFields.appendChild(grid3);

            // Для навыков НЕ показываем cost/effects/requirements — это для spell/abilities.
            fields.profileLists.innerHTML = '<div class="af-kb-help">Для навыков cost/effects не используются. Это “профессии” с привязкой к атрибуту и рангами.</div>';

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
                var kind = normalizeItemKind((state.item && state.item.item_kind) || 'gear');
                state.item.item_kind = kind;
                var ensureSlotInOptions = function (slotValue, options) {
                    var slot = String(slotValue || '').trim();
                    if (!slot) return String((options && options[0]) || '');
                    return (options || []).indexOf(slot) === -1 ? String((options && options[0]) || '') : slot;
                };
                if (kind === 'unique') {
                    if (!state.item.unique_role && state.item.unique_base_kind) state.item.unique_role = state.item.unique_base_kind;
                    if (!state.item.unique_role) state.item.unique_role = 'gear';
                    state.item.unique_base_kind = state.item.unique_role;
                }
                if (kind === 'armor') {
                    state.item.equip.slot = ensureSlotInOptions((state.item.equip && state.item.equip.slot) || '', slotByKind.armor);
                } else if (kind === 'weapon') {
                    state.item.equip.slot = ensureSlotInOptions((state.item.equip && state.item.equip.slot) || '', slotByKind.weapon);
                } else if (kind === 'augmentation') {
                    state.item.equip.slot = '';
                } else if (kind === 'unique') {
                    var normalizedUniqueKind = uniqueRoleToKind[String(state.item.unique_role || '').trim().toLowerCase()] || 'gear';
                    if (normalizedUniqueKind === 'armor' || normalizedUniqueKind === 'weapon') {
                        state.item.equip.slot = ensureSlotInOptions((state.item.equip && state.item.equip.slot) || '', slotByKind[normalizedUniqueKind] || []);
                    } else if (normalizedUniqueKind === 'augmentation') {
                        state.item.equip.slot = '';
                    }
                }
                var augmentationMode = (kind === 'augmentation') || String((state.item && state.item.augmentation && state.item.augmentation.slot) || '').trim() !== '' || String((state.item && state.item.cyberware && state.item.cyberware.slot) || '').trim() !== '';
                var defI = [
                    { name: 'item_kind', label: 'Тип предмета', type: 'select', options: itemKindOptions },
                    { name: 'rarity', label: 'Редкость', type: 'select', options: ['common', 'uncommon', 'rare', 'unique', 'illegal', 'restricted', 'legendary', 'mythic'] },
                    { name: 'price', label: 'Цена', type: 'number' },
                    { name: 'currency', label: 'Валюта', type: 'text', hint: 'credits/eddies/gold/...' },
                    { name: 'weight', label: 'Вес', type: 'number' },
                    { name: 'stack_max', label: 'Макс. в стаке', type: 'number' },
                    { name: 'tags', label: 'Теги', type: 'lines' }
                ];

                var gridI = document.createElement('div');
                gridI.className = 'af-kb-row';
                defI.forEach(function (d) {
                    gridI.appendChild(createInput(d, state.item, function () {
                        if (d.name === 'item_kind') renderProfile();
                        syncRawDebounced();
                    }));
                });
                fields.profileFields.appendChild(gridI);

                var equipGrid = document.createElement('div');
                equipGrid.className = 'af-kb-row';
                if (kind === 'armor') {
                    equipGrid.appendChild(createInput({ name: 'slot', label: 'Слот экипировки', type: 'select', options: slotByKind.armor }, state.item.equip, syncRawDebounced));
                    equipGrid.appendChild(createInput({ name: 'ac_bonus', label: 'Бонус брони', type: 'number' }, state.item.equip.armor, syncRawDebounced));
                    equipGrid.appendChild(createInput({ name: 'armor_type', label: 'Тип брони', type: 'select', options: ['light', 'medium', 'heavy'] }, state.item.equip.armor, syncRawDebounced));
                } else if (kind === 'weapon') {
                    equipGrid.appendChild(createInput({ name: 'slot', label: 'Слот экипировки', type: 'select', options: slotByKind.weapon }, state.item.equip, syncRawDebounced));
                    equipGrid.appendChild(createInput({ name: 'damage_bonus', label: 'Damage bonus', type: 'number' }, state.item.weapon, syncRawDebounced));
                    equipGrid.appendChild(createInput({ name: 'damage_type', label: 'Damage type', type: 'select', options: ['kinetic', 'thermal', 'electric', 'chemical', 'emp', 'explosive'] }, state.item.weapon, syncRawDebounced));
                } else if (kind === 'consumable') {
                    equipGrid.appendChild(createInput({ name: 'slot', label: 'Quick slot', type: 'select', options: slotByKind.consumable }, state.item.equip, syncRawDebounced));
                } else if (kind === 'ammo') {
                    equipGrid.appendChild(createInput({ name: 'slot', label: 'Слот экипировки', type: 'select', options: slotByKind.ammo }, state.item.equip, syncRawDebounced));
                    equipGrid.appendChild(createInput({ name: 'ammo_type', label: 'Ammo type', type: 'select', options: ['', 'pistol', 'rifle', 'shotgun', 'sniper', 'energy'] }, state.item.ammo, syncRawDebounced));
                    equipGrid.appendChild(createInput({ name: 'damage_type', label: 'Damage type', type: 'select', options: ['kinetic', 'thermal', 'electric', 'chemical', 'emp', 'explosive'] }, state.item.ammo, syncRawDebounced));
                    equipGrid.appendChild(createInput({ name: 'damage_bonus', label: 'Damage bonus', type: 'number' }, state.item.ammo, syncRawDebounced));
                } else if (kind === 'gear') {
                    equipGrid.appendChild(createInput({ name: 'subtype', label: 'Gear subtype', type: 'select', options: ['', 'cyberdeck', 'scanner', 'drone', 'medkit', 'toolkit', 'jammer', 'cloak', 'hacking_module'] }, state.item.gear, syncRawDebounced));
                    equipGrid.appendChild(createInput({ name: 'slot', label: 'Слот экипировки', type: 'select', options: slotByKind.gear }, state.item.equip, syncRawDebounced));
                } else if (augmentationMode) {
                    equipGrid.appendChild(createInput({ name: 'subtype', label: 'Augmentation subtype', type: 'select', options: ['', 'cybernetic', 'biomechanical', 'symbiotic'] }, state.item.augmentation, syncRawDebounced));
                    equipGrid.appendChild(createInput({ name: 'slot', label: 'Augmentation slot', type: 'select', options: augmentationSlotOptions }, state.item.augmentation, syncRawDebounced));
                    equipGrid.appendChild(createInput({ name: 'humanity_cost_percent', label: 'Humanity cost / Влияние на человечность (%)', type: 'number', hint: 'При надевании уменьшает человечность на X%' }, state.item.augmentation, syncRawDebounced));
                    equipGrid.insertAdjacentHTML('beforeend', '<div class="af-kb-help">Поле <strong>Augmentation grade</strong> скрыто: в текущей логике оно не участвует в расчётах и сохраняется только для legacy-совместимости.</div>');
                } else if (kind === 'artifact') {
                    equipGrid.appendChild(createInput({ name: 'slot', label: 'Слот экипировки', type: 'select', options: slotByKind[kind] }, state.item.equip, syncRawDebounced));
                } else if (kind === 'unique') {
                    equipGrid.appendChild(createInput({ name: 'unique_role', label: 'Базовый тип уникального', type: 'select', options: ['', 'weapon', 'armor', 'augmentation', 'artifact', 'gear', 'consumable', 'ammo'] }, state.item, function () {
                        state.item.unique_base_kind = state.item.unique_role || '';
                        renderProfile();
                        syncRawDebounced();
                    }));
                    var uniqueKind = uniqueRoleToKind[String(state.item.unique_role || '').trim().toLowerCase()] || 'gear';
                    if (uniqueKind !== 'augmentation') {
                        equipGrid.appendChild(createInput({ name: 'slot', label: 'Слот экипировки', type: 'select', options: slotByKind[uniqueKind] || slotByKind.gear }, state.item.equip, syncRawDebounced));
                    }
                }
                fields.profileFields.appendChild(equipGrid);
                var currentSlot = String((state.item.equip && state.item.equip.slot) || '');
                var slotKind = kind === 'unique' ? (uniqueRoleToKind[String(state.item.unique_role || '').trim().toLowerCase()] || kind) : kind;
                if (!augmentationMode && currentSlot && (slotByKind[slotKind] || ['']).indexOf(currentSlot) === -1) {
                    fields.profileFields.insertAdjacentHTML('beforeend', '<div class="af-kb-help">⚠ Несовместимый slot для item_kind: ' + esc(currentSlot) + '</div>');
                }

                var passiveBonusKinds = { armor: true, artifact: true, unique: true, augmentation: true };
                var uniqueBaseKind = uniqueRoleToKind[String(state.item.unique_role || '').trim().toLowerCase()] || '';
                var shouldShowBonuses = !!passiveBonusKinds[kind] || (kind === 'unique' && !!passiveBonusKinds[uniqueBaseKind]) || kind === 'weapon' || kind === 'gear' || kind === 'consumable' || kind === 'ammo';
                if (shouldShowBonuses) {
                    var passiveBonusCard = document.createElement('div');
                    passiveBonusCard.className = 'af-kb-rule-card';
                    fields.profileLists.appendChild(passiveBonusCard);
                    var bonusFields = [
                        { name: 'type', label: 'Type', type: 'select', options: itemBonusTypeOptions },
                        { name: 'target', label: 'Target', type: 'select', options: itemBonusTargetOptions },
                        { name: 'mode', label: 'Mode', type: 'select', options: ['add', 'set', 'mul', 'max'] },
                        { name: 'value', label: 'Value', type: 'number' },
                        { name: 'unit', label: 'Unit', type: 'select', options: ['', 'flat', '%', 'points'] },
                        { name: 'conditions', label: 'Conditions', type: 'text' },
                        { name: 'notes', label: 'Notes', type: 'text' }
                    ];
                    renderObjectList(passiveBonusCard, state.item.bonuses, 'Passive bonuses', bonusFields, function () {
                        state.item.bonuses = normalizeItemBonusRows(state.item.bonuses);
                        syncRawDebounced();
                    }, { type: 'resource', target: 'armor', mode: 'add', value: 0, unit: 'flat', conditions: '', notes: '' });
                }

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

                if (augmentationMode) {
                    var cyberBox = document.createElement('div');
                    cyberBox.className = 'af-kb-rule-card';
                    cyberBox.innerHTML = '<div class="af-kb-rule-card__title"><strong>Augmentation → Effects</strong></div>';

                    var modifierFieldsCyber = [
                        { name: 'type', label: 'Type', type: 'select', options: ['attribute_bonus_str','attribute_bonus_dex','attribute_bonus_con','attribute_bonus_int','attribute_bonus_wis','attribute_bonus_cha','hp_max','hp_regen_flat','hp_regen_percent','stamina_max','stamina_regen','energy_max','energy_regen','armor_flat','armor_percent','evasion','mitigation_chance','mitigation_strength','speed_flat','speed_percent','jump_height','dash_cost_reduction','carry_capacity','loot_radius','interaction_range','perception','detection_range','enemy_visibility_reduction','crit_chance','crit_damage','accuracy','ads_speed','recoil_reduction','spread_reduction','reload_speed','damage_percent','damage_melee_percent','damage_ranged_percent','headshot_damage_percent','stealth_damage_percent','damage_fire_percent','damage_electric_percent','damage_chemical_percent','resist_fire','resist_electric','resist_chemical','resist_poison','resist_bleed','dot_damage_percent','dot_resist_percent','attack_speed','block_efficiency','parry_window','ram_max','ram_recovery_rate','quickhack_damage_percent','quickhack_upload_speed','quickhack_crit_chance','quickhack_crit_damage','cyberware_cooldown_reduction','ability_cooldown_reduction','scan_speed','ice_resistance','trace_time_increase'] },
                        { name: 'value', label: 'Value', type: 'number' },
                        { name: 'unit', label: 'Unit', type: 'select', options: ['', '%', 'flat', 'sec', 'points'] },
                        { name: 'scope', label: 'Scope', type: 'select', options: ['always', 'while_equipped', 'while_active'] }
                    ];
                    var effectsFieldsCyber = [
                        { name: 'event', label: 'Event', type: 'select', options: ['on_kill', 'on_hit', 'on_crit', 'on_damage_taken', 'on_low_hp', 'on_enter_combat', 'on_exit_combat', 'on_scan', 'on_quickhack', 'on_dash'] },
                        { name: 'effect_type', label: 'Action/Effect type', type: 'select', options: ['reduce_cooldown', 'apply_status', 'shield_or_heal', 'low_hp_bonus', 'active_ability'] },
                        { name: 'chance', label: 'Chance %', type: 'number' },
                        { name: 'cooldown_sec', label: 'Cooldown sec', type: 'number' },
                        { name: 'params', label: 'Params', type: 'json' }
                    ];
                    var grantsFieldsCyber = [
                        { name: 'grant_type', label: 'Grant type', type: 'select', options: ['perk', 'skill', 'resistance', 'action', 'kb_pick'] },
                        { name: 'target', label: 'Target', type: 'text' },
                        { name: 'value', label: 'Amount / Rank / Value', type: 'number' }
                    ];

                    var modsWrap = document.createElement('div');
                    modsWrap.className = 'af-kb-rule-card';
                    cyberBox.appendChild(modsWrap);
                    renderObjectList(modsWrap, state.item.augmentation.modifiers, 'Modifiers', modifierFieldsCyber, syncRawDebounced, { type: '', value: 0, unit: '', scope: 'while_equipped' });

                    var fxWrap = document.createElement('div');
                    fxWrap.className = 'af-kb-rule-card';
                    cyberBox.appendChild(fxWrap);
                    renderObjectList(fxWrap, state.item.augmentation.effects, 'Effects', effectsFieldsCyber, syncRawDebounced, { event: 'on_kill', effect_type: 'reduce_cooldown', chance: 0, cooldown_sec: 0, params: {} });

                    var grantsWrap = document.createElement('div');
                    grantsWrap.className = 'af-kb-rule-card';
                    cyberBox.appendChild(grantsWrap);
                    renderObjectList(grantsWrap, state.item.augmentation.grants, 'Grants', grantsFieldsCyber, syncRawDebounced, { grant_type: 'perk', target: '', value: 0 });

                    var reqConflictRow = document.createElement('div');
                    reqConflictRow.className = 'af-kb-row';
                    reqConflictRow.appendChild(createInput({ name: 'requirements', label: 'Requirements & limits', type: 'json' }, state.item.augmentation, syncRawDebounced));
                    reqConflictRow.appendChild(createInput({ name: 'conflicts', label: 'Conflicts', type: 'json' }, state.item.augmentation, syncRawDebounced));
                    cyberBox.appendChild(reqConflictRow);

                    fields.profileLists.appendChild(cyberBox);
                }

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
            if (uiProfile === 'bestiary') {
                var creatureDefsTop = [
                    { name: 'size', label: 'Size', type: 'select', options: ['tiny', 'small', 'medium', 'large', 'huge', 'gargantuan'] },
                    { name: 'kind', label: 'Category/family', type: 'text' },
                    { name: 'alignment', label: 'Alignment', type: 'text' },
                    { name: 'challenge_rating', label: 'Challenge', type: 'text' }
                ];
                var creatureDefsCombat = [
                    { name: 'xp', label: 'XP', type: 'number' },
                    { name: 'armor_class', label: 'Armor class', type: 'number' },
                    { name: 'initiative', label: 'Initiative', type: 'number' },
                    { name: 'proficiency_bonus', label: 'Proficiency bonus', type: 'number' }
                ];
                var gridB1 = document.createElement('div');
                gridB1.className = 'af-kb-row';
                creatureDefsTop.forEach(function (d) { gridB1.appendChild(createInput(d, state.creature, syncRawDebounced)); });
                fields.profileFields.appendChild(gridB1);

                var gridB2 = document.createElement('div');
                gridB2.className = 'af-kb-row';
                creatureDefsCombat.forEach(function (d) { gridB2.appendChild(createInput(d, state.creature, syncRawDebounced)); });
                fields.profileFields.appendChild(gridB2);

                var hpSpeedObj = {
                    average: state.creature.hp.average,
                    dice: state.creature.hp.dice,
                    walk: state.creature.speed.walk
                };
                var hpSpeedDefs = [
                    { name: 'average', label: 'HP average', type: 'number' },
                    { name: 'dice', label: 'HP dice', type: 'text' },
                    { name: 'walk', label: 'Speed (walk)', type: 'number' }
                ];
                var gridB3 = document.createElement('div');
                gridB3.className = 'af-kb-row';
                hpSpeedDefs.forEach(function (d) {
                    gridB3.appendChild(createInput(d, hpSpeedObj, function () {
                        state.creature.hp.average = numberOrZero(hpSpeedObj.average);
                        state.creature.hp.dice = String(hpSpeedObj.dice || '');
                        state.creature.speed.walk = numberOrZero(hpSpeedObj.walk);
                        syncRawDebounced();
                    }));
                });
                fields.profileFields.appendChild(gridB3);

                var scoreDefs = [
                    { name: 'str', label: 'STR', type: 'number' },
                    { name: 'dex', label: 'DEX', type: 'number' },
                    { name: 'con', label: 'CON', type: 'number' },
                    { name: 'int', label: 'INT', type: 'number' },
                    { name: 'wis', label: 'WIS', type: 'number' },
                    { name: 'cha', label: 'CHA', type: 'number' }
                ];
                var gridB4 = document.createElement('div');
                gridB4.className = 'af-kb-row';
                scoreDefs.forEach(function (d) { gridB4.appendChild(createInput(d, state.creature.ability_scores, syncRawDebounced)); });
                fields.profileFields.appendChild(gridB4);

                var notesGrid = document.createElement('div');
                notesGrid.className = 'af-kb-row';
                notesGrid.appendChild(createInput({ name: 'notes', label: 'Creature notes', type: 'textarea' }, state.creature, syncRawDebounced));
                notesGrid.appendChild(createInput({ name: 'gm_notes', label: 'GM notes', type: 'textarea' }, state, syncRawDebounced));
                fields.profileFields.appendChild(notesGrid);

                var defenseBox = document.createElement('div');
                defenseBox.className = 'af-kb-rule-card';
                defenseBox.innerHTML = '<div class="af-kb-rule-card__title"><strong>Defenses</strong></div>';
                var defenseGrid = document.createElement('div');
                defenseGrid.className = 'af-kb-row';
                defenseGrid.appendChild(createInput({ name: 'damage_resistances', label: 'Resistances', type: 'lines' }, state.creature, syncRawDebounced));
                defenseGrid.appendChild(createInput({ name: 'damage_immunities', label: 'Immunities', type: 'lines' }, state.creature, syncRawDebounced));
                defenseGrid.appendChild(createInput({ name: 'damage_vulnerabilities', label: 'Weaknesses', type: 'lines' }, state.creature, syncRawDebounced));
                defenseGrid.appendChild(createInput({ name: 'condition_immunities', label: 'Condition immunities', type: 'lines' }, state.creature, syncRawDebounced));
                defenseBox.appendChild(defenseGrid);
                fields.profileLists.appendChild(defenseBox);

                var traitFields = [
                    { name: 'name', label: 'Name', type: 'text' },
                    { name: 'desc', label: 'Description', type: 'textarea' }
                ];
                var actionFields = [
                    { name: 'name', label: 'Name', type: 'text' },
                    { name: 'attack_bonus', label: 'Attack bonus', type: 'number' },
                    { name: 'damage', label: 'Damage', type: 'text' },
                    { name: 'desc', label: 'Description', type: 'textarea' }
                ];
                var lootFields = [
                    { name: 'kind', label: 'Kind', type: 'select', options: ['item', 'currency', 'resource', 'table'] },
                    { name: 'ref_key', label: 'Ref key', type: 'text' },
                    { name: 'chance', label: 'Chance %', type: 'number' },
                    { name: 'qty_min', label: 'Qty min', type: 'number' },
                    { name: 'qty_max', label: 'Qty max', type: 'number' },
                    { name: 'notes', label: 'Notes', type: 'text' }
                ];

                var traitsBox = document.createElement('div');
                traitsBox.className = 'af-kb-rule-card';
                fields.profileLists.appendChild(traitsBox);
                renderObjectList(traitsBox, state.traits, 'Traits/features', traitFields, syncRawDebounced, { name: '', desc: '' });

                var actionsBox = document.createElement('div');
                actionsBox.className = 'af-kb-rule-card';
                fields.profileLists.appendChild(actionsBox);
                renderObjectList(actionsBox, state.actions, 'Actions', actionFields, syncRawDebounced, { name: '', attack_bonus: 0, damage: '', desc: '' });

                var reactionsBox = document.createElement('div');
                reactionsBox.className = 'af-kb-rule-card';
                fields.profileLists.appendChild(reactionsBox);
                renderObjectList(reactionsBox, state.reactions, 'Reactions', actionFields, syncRawDebounced, { name: '', attack_bonus: 0, damage: '', desc: '' });

                var legendaryBox = document.createElement('div');
                legendaryBox.className = 'af-kb-rule-card';
                fields.profileLists.appendChild(legendaryBox);
                renderObjectList(legendaryBox, state.legendary_actions, 'Legendary actions', actionFields, syncRawDebounced, { name: '', attack_bonus: 0, damage: '', desc: '' });

                var lootBox = document.createElement('div');
                lootBox.className = 'af-kb-rule-card';
                fields.profileLists.appendChild(lootBox);
                renderObjectList(lootBox, state.loot, 'Drops / rewards', lootFields, syncRawDebounced, { kind: 'item', ref_key: '', chance: 0, qty_min: 1, qty_max: 1, notes: '' });
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
                var defKG = [
                    { name: 'knowledge_group', label: 'Knowledge group', type: 'text', hint: 'Напр. history / xenobiology / occult / corporate_law (ОБЯЗАТЕЛЬНО)' }
                ];

                var defK = [
                    { name: 'key_stat', label: 'Ключевой атрибут навыка', type: 'select', allowEmpty: true, emptyLabel: '— не выбран —', options: ['str','dex','con','int','wis','cha'], hint: 'Ключевой атрибут проверки по знанию' },
                    { name: 'rank_max', label: 'Rank max', type: 'number', hint: 'Обычно 4 (0..4)' }
                ];

                var flagsK = [
                    { name: 'trained_only', label: 'Trained only', type: 'checkbox', hint: 'Если знание нельзя использовать без trained' },
                    { name: 'armor_check_penalty', label: 'Armor check penalty', type: 'checkbox', hint: 'Если броня/нагрузка влияет (обычно false)' }
                ];

                var notesK = [
                    { name: 'notes', label: 'Notes', type: 'textarea', hint: 'Примеры проверок, область применения.' }
                ];

                // knowledge_group — top-level поле
                var gridKG = document.createElement('div');
                gridKG.className = 'af-kb-row';
                defKG.forEach(function (d) { gridKG.appendChild(createInput(d, state, syncRawDebounced)); });
                fields.profileFields.appendChild(gridKG);

                // skill-поля
                var gridK1 = document.createElement('div');
                gridK1.className = 'af-kb-row';
                defK.forEach(function (d) { gridK1.appendChild(createInput(d, state.skill, syncRawDebounced)); });
                fields.profileFields.appendChild(gridK1);

                var gridK2 = document.createElement('div');
                gridK2.className = 'af-kb-row';
                flagsK.forEach(function (d) { gridK2.appendChild(createInput(d, state.skill, syncRawDebounced)); });
                fields.profileFields.appendChild(gridK2);

                var gridK3 = document.createElement('div');
                gridK3.className = 'af-kb-row';
                notesK.forEach(function (d) { gridK3.appendChild(createInput(d, state.skill, syncRawDebounced)); });
                fields.profileFields.appendChild(gridK3);

                fields.profileLists.innerHTML =
                    '<div class="af-kb-help">' +
                    'Тип knowledge сохраняется как skill(category=knowledge) + knowledge_group. ' +
                    'Если knowledge_group пустой — валидатор не пропустит.' +
                    '</div>';

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

            if (uiProfile === 'character') {
                var mechanicsOptionSets = (window.afKbArpgMechanicsOptionSets && typeof window.afKbArpgMechanicsOptionSets === 'object')
                    ? window.afKbArpgMechanicsOptionSets
                    : {};
                var publicTypeOptions = (window.afKbArpgPublicTypeOptions && typeof window.afKbArpgPublicTypeOptions === 'object')
                    ? window.afKbArpgPublicTypeOptions
                    : {};
                var mapOptionRows = function (rows, fallback) {
                    var out = [];
                    (Array.isArray(rows) ? rows : []).forEach(function (row) {
                        if (row && typeof row === 'object') {
                            var value = String(row.key != null ? row.key : (row.value != null ? row.value : '')).trim();
                            if (!value) return;
                            var label = String(row.label_ru != null ? row.label_ru : (row.label_en != null ? row.label_en : value));
                            out.push({ value: value, label: label });
                            return;
                        }
                        var primitiveValue = String(row || '').trim();
                        if (!primitiveValue) return;
                        out.push({ value: primitiveValue, label: primitiveValue });
                    });
                    if (out.length) return out;
                    return (fallback || []).map(function (value) { return { value: String(value), label: String(value) }; });
                };
                var optionsFromPublicType = function (typeKey) {
                    return mapOptionRows(publicTypeOptions[typeKey], []);
                };
                var optionsFromMechanics = function (setKey, fallback) {
                    return mapOptionRows(mechanicsOptionSets[setKey], fallback || []);
                };
                var abilityTypeOptions = optionsFromMechanics('ability_type', []);
                var abilitySubtypeOptions = optionsFromMechanics('ability_subtype', []);
                var abilitySlotOptions = optionsFromMechanics('ability_slot', []);
                var abilityDamageTypeOptions = optionsFromMechanics('ability_damage_type', []);
                var abilityTargetingOptions = optionsFromMechanics('ability_targeting', []);
                var abilityValueModeOptions = optionsFromMechanics('ability_value_mode', []);
                var characterGenderOptions = optionsFromMechanics('character_gender', []);
                var characterElementOptions = optionsFromPublicType('arpg_element');
                var characterRaceOptions = optionsFromPublicType('arpg_origin');
                var characterClassOptions = optionsFromPublicType('arpg_archetype');
                var characterFactionOptions = optionsFromPublicType('arpg_faction');
                var profileDefs = [
                    { name: 'category', label: 'Категория', type: 'select', options: ['canons', 'originals', 'roles'] },
                    { name: 'character_pic', label: 'Изображение персонажа', type: 'url' },
                    { name: 'character_prototype', label: 'Прототип', type: 'text' },
                    { name: 'character_name', label: 'Имя (EN)', type: 'text' },
                    { name: 'character_name_ru', label: 'Имя (RU)', type: 'text' },
                    { name: 'character_nicknames', label: 'Прозвища', type: 'text' },
                    { name: 'character_element', label: 'Стихия', type: 'select', options: characterElementOptions, allowEmpty: true, emptyLabel: '—' },
                    { name: 'character_gen', label: 'Пол', type: 'select', options: characterGenderOptions, allowEmpty: true, emptyLabel: '—' },
                    { name: 'character_race', label: 'Раса', type: 'select', options: characterRaceOptions, allowEmpty: true, emptyLabel: '—' },
                    { name: 'character_class', label: 'Класс', type: 'select', options: characterClassOptions, allowEmpty: true, emptyLabel: '—' },
                    { name: 'character_faction', label: 'Фракция', type: 'select', options: characterFactionOptions, allowEmpty: true, emptyLabel: '—' }
                ];
                var profileTextareaDefs = [
                    { name: 'character_app', label: 'Внешность', type: 'textarea', editorPolicy: 'allow', fullWidth: true }
                ];
                var statsDefs = [
                    { name: 'character_hp', label: 'HP', type: 'number' },
                    { name: 'character_defense', label: 'Defense', type: 'number' },
                    { name: 'character_attack_power', label: 'Attack power', type: 'number' },
                    { name: 'character_element_damage_bonus', label: 'Element dmg bonus', type: 'number', suffix: '%' },
                    { name: 'character_crit_damage', label: 'Crit damage', type: 'number', suffix: '%' },
                    { name: 'character_healing_received_bonus', label: 'Healing received bonus', type: 'number', suffix: '%' },
                    { name: 'character_elemental_mastery', label: 'Elemental mastery', type: 'number' },
                    { name: 'character_healing_bonus', label: 'Healing bonus', type: 'number', suffix: '%' },
                    { name: 'character_shield_strength', label: 'Shield strength', type: 'number', suffix: '%' },
                    { name: 'character_luck', label: 'Luck', type: 'number', suffix: '%' }
                ];

                var profileGrid = document.createElement('div');
                profileGrid.className = 'af-kb-row';
                profileDefs.forEach(function (d) { profileGrid.appendChild(createInput(d, state.character_profile, syncRawDebounced)); });
                fields.profileFields.appendChild(profileGrid);
                var profileTextareaGrid = document.createElement('div');
                profileTextareaGrid.className = 'af-kb-row';
                profileTextareaDefs.forEach(function (d) { profileTextareaGrid.appendChild(createInput(d, state.character_profile, syncRawDebounced)); });
                fields.profileFields.appendChild(profileTextareaGrid);

                var statsGrid = document.createElement('div');
                statsGrid.className = 'af-kb-row';
                statsDefs.forEach(function (d) { statsGrid.appendChild(createInput(d, state.character_stats, syncRawDebounced)); });
                fields.profileFields.appendChild(statsGrid);

                var abilitiesBox = document.createElement('div');
                abilitiesBox.className = 'af-kb-rule-card';
                fields.profileLists.appendChild(abilitiesBox);
                function renderCharacterAbilities() {
                    abilitiesBox.innerHTML = '';

                    var list = document.createElement('div');
                    abilitiesBox.appendChild(list);

                    var addBtn = document.createElement('button');
                    addBtn.type = 'button';
                    addBtn.className = 'af-kb-add';
                    addBtn.textContent = 'Добавить способность';
                    addBtn.addEventListener('click', function () {
                        state.character_abilities.push(normalizeCharacterAbilityRow({}, state.character_abilities.length + 1));
                        renderCharacterAbilities();
                        syncRawDebounced();
                    });
                    abilitiesBox.appendChild(addBtn);

                    if (!state.character_abilities.length) {
                        var empty = document.createElement('div');
                        empty.className = 'af-kb-help';
                        empty.textContent = 'Список способностей пуст.';
                        list.appendChild(empty);
                        return;
                    }

                    state.character_abilities.forEach(function (ability, idx) {
                        var normalized = normalizeCharacterAbilityRow(ability, idx + 1);
                        state.character_abilities[idx] = normalized;

                        var card = document.createElement('div');
                        card.className = 'af-kb-block-item';
                        list.appendChild(card);

                        var summary = document.createElement('div');
                        card.appendChild(summary);
                        var refreshSummary = function () {
                            renderInlineAbilitySummary(summary, normalized, idx, 'Способность');
                        };
                        refreshSummary();
                        card.addEventListener('input', refreshSummary);
                        card.addEventListener('change', refreshSummary);

                        var title = document.createElement('h4');
                        title.textContent = 'Способность #' + (idx + 1);
                        card.appendChild(title);

                        var coreDefs = [
                            { name: 'slot_index', label: 'Индекс слота', type: 'number' },
                            { name: 'ability_name', label: 'Название способности', type: 'text' },
                            { name: 'icon_url', label: 'Иконка', type: 'url' },
                            { name: 'type', label: 'Тип', type: 'select', options: abilityTypeOptions, allowEmpty: true, emptyLabel: '—' },
                            { name: 'subtype', label: 'Подтип', type: 'select', options: abilitySubtypeOptions, allowEmpty: true, emptyLabel: '—' },
                            { name: 'slot', label: 'Слот', type: 'select', options: abilitySlotOptions, allowEmpty: true, emptyLabel: '—' },
                            { name: 'damage_type', label: 'Тип урона', type: 'select', options: abilityDamageTypeOptions, allowEmpty: true, emptyLabel: '—' },
                            { name: 'targeting', label: 'Цель', type: 'select', options: abilityTargetingOptions, allowEmpty: true, emptyLabel: '—' },
                            { name: 'value_mode', label: 'Режим значения', type: 'select', options: abilityValueModeOptions, allowEmpty: true, emptyLabel: '—' },
                            { name: 'range', label: 'Дальность', type: 'number' },
                            { name: 'duration', label: 'Продолжительность', type: 'text' },
                            { name: 'damage_value', label: 'Урон', type: 'number' },
                            { name: 'shield_value', label: 'Щит', type: 'number' },
                            { name: 'heal_value', label: 'Лечение', type: 'number' }
                        ];
                        var coreTextareaDefs = [
                            { name: 'ability_description', label: 'Описание способности', type: 'textarea', editorPolicy: 'allow', fullWidth: true }
                        ];
                        var coreGrid = document.createElement('div');
                        coreGrid.className = 'af-kb-row';
                        coreDefs.forEach(function (d) { coreGrid.appendChild(createInput(d, normalized, function () {
                            normalized.ability_type = normalized.type || 'active';
                            refreshSummary();
                            syncRawDebounced();
                        })); });
                        card.appendChild(coreGrid);
                        var coreTextareaGrid = document.createElement('div');
                        coreTextareaGrid.className = 'af-kb-row';
                        coreTextareaDefs.forEach(function (d) { coreTextareaGrid.appendChild(createInput(d, normalized, function () {
                            normalized.ability_type = normalized.type || 'active';
                            refreshSummary();
                            syncRawDebounced();
                        })); });
                        card.appendChild(coreTextareaGrid);

                        var del = document.createElement('button');
                        del.type = 'button';
                        del.className = 'af-kb-remove';
                        del.textContent = 'Удалить способность';
                        del.addEventListener('click', function () {
                            state.character_abilities.splice(idx, 1);
                            renderCharacterAbilities();
                            syncRawDebounced();
                        });
                        card.appendChild(del);
                    });
                }

                renderCharacterAbilities();

                var metaGrid = document.createElement('div');
                metaGrid.className = 'af-kb-row';
                metaGrid.appendChild(createInput({ name: 'contract', label: 'contract', type: 'text' }, state.character_meta, syncRawDebounced));
                metaGrid.appendChild(createInput({ name: 'contract_version', label: 'contract_version', type: 'text' }, state.character_meta, syncRawDebounced));
                metaGrid.appendChild(createInput({ name: 'source', label: 'source', type: 'text' }, state.character_meta, syncRawDebounced));
                fields.profileLists.appendChild(metaGrid);
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
                    state.grants.push(deepClone(templates[addGrantType] || { op: addGrantType }));
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
                    parsed = JSON.parse(getFieldValue(raw) || '{}');
                    if (uiProfile === 'item') {
                        parsed = normalizeItemCanonical(parsed || {});
                    }
                    fields.rawError.textContent = '';
                } catch (err) {
                    fields.rawError.textContent = 'Ошибка JSON: ' + err.message;
                    return;
                }

                // заменяем state и заново нормализуем по профилю через merged-подход.
                var next = merge3(defaultsForProfile(uiProfile, expectedTypeProfile), schemaDefaults, parsed);
                state = deepClone(next);
                // чистим raw/дефолты от мусора по профилю
                state = sanitizePayloadByProfile(state, uiProfile);


                // re-normalize by profile (минимум, чтобы UI не падал)
                if (uiProfile === 'heritage') {
                    if (!state.fixed_bonuses) state.fixed_bonuses = { stats: {} };
                    if (!state.fixed_bonuses.stats) state.fixed_bonuses.stats = {};
                    stats.forEach(function (k) { state.fixed_bonuses.stats[k] = numberOrZero(state.fixed_bonuses.stats[k]); });
                    if (!state.fixed) state.fixed = { stats: {} };
                    if (!state.fixed.stats) state.fixed.stats = {};
                    stats.forEach(function (k) { state.fixed.stats[k] = numberOrZero(state.fixed.stats[k]); });
                    ['hp', 'speed', 'ep', 'armor', 'initiative', 'carry'].forEach(function (k) { state.fixed[k] = numberOrZero(state.fixed[k]); });
                    if (!Array.isArray(state.choices)) state.choices = [];
                    if (!Array.isArray(state.grants)) state.grants = [];
                    if (!Array.isArray(state.traits)) state.traits = [];
                    state.choices = state.choices.map(normalizeChoice);
                    state.grants = state.grants.map(normalizeGrant);

                    if (fields.size) fields.size.value = state.size != null ? String(state.size) : (isRaceHead ? 'medium' : '');
                    if (fields.creature) fields.creature.value = state.creature_type != null ? String(state.creature_type) : (isRaceHead ? 'humanoid' : '');
                    if (fields.speed) fields.speed.value = state.speed != null ? String(state.speed) : (isRaceHead ? '30' : '');
                    if (fields.hpBase) fields.hpBase.value = state.hp_base != null ? String(state.hp_base) : (isRaceHead ? '10' : '');

                    renderFixedBonuses();
                    renderFixedDerived();
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

        // flush before submit (иначе debounce может не успеть)
        var form = raw.closest('form');
        if (form) {
            form.addEventListener('submit', function () {
                syncRawNow();
                if (window.__afKbEditorGuard) {
                    window.__afKbEditorGuard.refreshEditorPolicy(root);
                }
            });
        }

        if (uiProfile === 'heritage') {
            [fields.size, fields.creature, fields.speed, fields.hpBase].forEach(function (field) {
                if (!field) return;
                field.addEventListener('input', syncFromHeritageBase);
                field.addEventListener('change', syncFromHeritageBase);
            });
        }

        // ---------- first render ----------
        if (uiProfile === 'heritage') {
            renderFixedBonuses();
            renderFixedDerived();
            renderChoices();
            renderGrants();
            renderTraits();
        } else {
            renderProfile();
        }

        syncRawNow();
    }

    function initCharacterStatusModal() {
        var openButton = document.querySelector('[data-af-kb-status-open="1"]');
        var modal = document.querySelector('[data-af-kb-status-modal="1"]');
        if (!openButton || !modal) {
            return;
        }

        var closeButton = modal.querySelector('[data-af-kb-status-close="1"]');
        var select = modal.querySelector('[data-af-kb-status-select="1"]');
        var linkInput = modal.querySelector('[data-af-kb-status-link="1"]');
        var dateInput = modal.querySelector('[data-af-kb-status-date="1"]');
        var linkWrap = modal.querySelector('[data-af-kb-status-link-wrap="1"]');
        var dateWrap = modal.querySelector('[data-af-kb-status-date-wrap="1"]');
        var form = modal.querySelector('[data-af-kb-status-form="1"]');

        var syncFields = function () {
            var mode = select ? String(select.value || '') : 'free';
            var needsLink = mode === 'occupied' || mode === 'held';
            var needsDate = mode === 'held';
            if (linkWrap) {
                linkWrap.style.display = needsLink ? '' : 'none';
            }
            if (dateWrap) {
                dateWrap.style.display = needsDate ? '' : 'none';
            }
            if (linkInput) {
                linkInput.required = needsLink;
            }
            if (dateInput) {
                dateInput.required = needsDate;
            }
        };

        openButton.addEventListener('click', function () {
            modal.style.display = 'flex';
            modal.classList.add('is-active');
            syncFields();
        });
        if (closeButton) {
            closeButton.addEventListener('click', function () {
                modal.style.display = 'none';
                modal.classList.remove('is-active');
            });
        }
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                modal.style.display = 'none';
                modal.classList.remove('is-active');
            }
        });
        if (select) {
            select.addEventListener('change', syncFields);
        }
        if (form) {
            form.addEventListener('submit', function (event) {
                syncFields();
                if ((linkInput && linkInput.required && !String(linkInput.value || '').trim())
                    || (dateInput && dateInput.required && !String(dateInput.value || '').trim())) {
                    event.preventDefault();
                }
            });
        }
        syncFields();
    }

    document.addEventListener('DOMContentLoaded', function () {
        try {
            initMetaUi();
        } catch (errMeta) {
            if (window.console && typeof window.console.error === 'function') {
                window.console.error('[AF KB] initMetaUi failed', errMeta);
            }
        }

        try {
            initRulesUi();
        } catch (errRules) {
            if (window.console && typeof window.console.error === 'function') {
                window.console.error('[AF KB] initRulesUi failed', errRules);
            }
        }

        try {
            initCharacterStatusModal();
        } catch (errStatusModal) {
            if (window.console && typeof window.console.error === 'function') {
                window.console.error('[AF KB] initCharacterStatusModal failed', errStatusModal);
            }
        }
    });
})();

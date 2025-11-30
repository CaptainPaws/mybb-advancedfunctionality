<?php

if (!defined('IN_MYBB')) {
    die('No direct access');
}

class AF_Admin_Advancedquickreply
{
    /**
     * Точка входа из AF-роутера.
     */
    public static function dispatch(): void
    {
        global $mybb, $db, $lang, $page;

        require_once MYBB_ROOT.'admin/inc/class_form.php';

        // Какая вкладка активна
        $tab = $mybb->get_input('tab');
        if (!in_array($tab, ['toolbar', 'bbcodes'], true)) {
            $tab = 'toolbar';
        }

        // Вкладки
        $sub_tabs = [
            'toolbar' => [
                'title'       => $lang->af_advancedquickreply_tab_toolbar ?? 'Тулбар',
                'link'        => 'index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=toolbar',
                'description' => $lang->af_advancedquickreply_tab_toolbar_desc
                    ?? 'Конструктор layout\'а тулбара SCEditor для быстрого ответа.',
            ],
            'bbcodes' => [
                'title'       => $lang->af_advancedquickreply_tab_bbcodes ?? 'BB-коды',
                'link'        => 'index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes',
                'description' => $lang->af_advancedquickreply_tab_bbcodes_desc
                    ?? 'Список стандартных и кастомных BB-кодов + иконки.',
            ],
        ];

        $page->output_nav_tabs($sub_tabs, $tab);

        if ($tab === 'bbcodes') {
            self::dispatch_bbcodes();
        } else {
            self::render_toolbar_tab();
        }
    }

    /* =========================================================
     *  ТАБ "ТУЛБАР" — С ПРЕВЬЮ
     * ======================================================= */
    protected static function render_toolbar_tab(): void
    {
        global $mybb, $lang, $page, $db;

        // Сохранение
        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            // БЕРЁМ СЫРОЙ JSON ИЗ $_POST, ИНАЧЕ MyBB его экранирует
            $toolbar_json = '';
            if (isset($_POST['toolbar'])) {
                $toolbar_json = (string)$_POST['toolbar'];
            }

            $toolbar_json = trim($toolbar_json);

            // Пишем как есть (JSON или, теоретически, старый строковый формат)
            $db->update_query('settings', [
                'value' => $db->escape_string($toolbar_json),
            ], "name='af_advancedquickreply_toolbar'");

            if (function_exists('rebuild_settings')) {
                rebuild_settings();
            }

            $msg = $lang->af_advancedquickreply_saved
                ?? 'Настройки Advanced QuickReply сохранены.';
            flash_message($msg, 'success');

            admin_redirect('index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=toolbar');
        }

        $intro = $lang->af_advancedquickreply_intro
            ?? 'Advanced QuickReply включает полный редактор в форме быстрого ответа и позволяет задать layout тулбара SCEditor. '
            .'Ниже — визуальный конструктор: перетаскивай кнопки между группами и создавай выпадающие меню.';

        $page->output_inline_message($intro);

        // Сырой setting (может быть старой строкой, может быть уже JSON)
        $current_raw = (string)($mybb->settings['af_advancedquickreply_toolbar'] ?? '');

        // Собираем полный список кнопок (стандартные + кастомные BB-коды)
        $all_buttons = self::get_toolbar_buttons();

        // Превращаем setting в нормализованный массив layout'а
        $layout = self::normalize_layout($current_raw, $all_buttons);

        // Добавляем заглушки для кнопок, которых нет в списке (например, BB-код удалён/отключён)
        $used_ids = [];
        foreach ($layout as $group) {
            if (!is_array($group) || ($group['type'] ?? '') !== 'group') {
                continue;
            }
            foreach ($group['items'] ?? [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (($item['type'] ?? '') === 'button' && !empty($item['id'])) {
                    $used_ids[$item['id']] = true;
                } elseif (($item['type'] ?? '') === 'menu') {
                    foreach ($item['items'] ?? [] as $sub) {
                        if (($sub['type'] ?? '') === 'button' && !empty($sub['id'])) {
                            $used_ids[$sub['id']] = true;
                        }
                    }
                }
            }
        }

        foreach (array_keys($used_ids) as $bid) {
            if (!isset($all_buttons[$bid])) {
                $all_buttons[$bid] = [
                    'id'          => $bid,
                    'source'      => 'unknown',
                    'tag'         => '',
                    'label'       => $bid,
                    'description' => 'Эта кнопка ссылается на BB-код, которого больше нет или он отключён.',
                    'fa'          => '',
                    'icon_url'    => '',
                    'tooltip'     => $bid.' (отсутствует в списке BB-кодов)',
                ];
            }
        }

        // JSON для JS
        $layout_json  = json_encode($layout, JSON_UNESCAPED_UNICODE);
        $buttons_json = json_encode($all_buttons, JSON_UNESCAPED_UNICODE);

        // Подстраховка от </script>
        $layout_json_js  = str_replace('</', '<\/', $layout_json);
        $buttons_json_js = str_replace('</', '<\/', $buttons_json);

        // CSS для конструктора
        echo '<style type="text/css">
        .af-aqr-toolbar-builder {
            margin: 10px 0 15px 0;
        }
        .af-aqr-toolbar-row {
            margin-bottom: 10px;
            padding: 8px;
            border: 1px solid #ccc;
            background: #ffffff;
        }
        .af-aqr-toolbar-groups {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .af-aqr-group {
            border: 1px solid #ddd;
            background: #f5f5f5;
            border-radius: 3px;
            padding: 4px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 140px;
            min-height: 32px;
        }
        .af-aqr-group-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            color: #555;
        }
        .af-aqr-group-title {
            font-weight: bold;
        }
        .af-aqr-group-actions {
            display: flex;
            gap: 4px;
        }
        .af-aqr-group-actions button {
            font-size: 10px;
            padding: 1px 4px;
        }
        .af-aqr-group-body {
            display: flex;
            flex-wrap: wrap;
            gap: 3px;
            min-height: 24px;
        }
        .af-aqr-btn {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 6px;
            border: 1px solid #ccc;
            border-radius: 3px;
            background: #fff;
            font-size: 11px;
            cursor: move;
            white-space: nowrap;
        }
        .af-aqr-btn-icon {
            display: inline-flex;
            align-items: center;
        }
        .af-aqr-btn-label {
            white-space: nowrap;
        }
        .af-aqr-btn-remove {
            margin-left: 3px;
            font-weight: bold;
            color: #a00;
            cursor: pointer;
        }
        .af-aqr-btn-remove:hover {
            color: #d00;
        }
        .af-aqr-btn--palette {
            background: #f0f6ff;
        }
        .af-aqr-btn--missing {
            background: #fff4f4;
            border-color: #e99;
        }
        .af-aqr-menu-block {
            border: 1px dashed #bbb;
            border-radius: 3px;
            padding: 3px;
            background: #fafafa;
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 120px;
        }
        .af-aqr-menu-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
        }
        .af-aqr-menu-title {
            font-weight: bold;
            cursor: pointer;
        }
        .af-aqr-menu-actions {
            display: flex;
            gap: 4px;
        }
        .af-aqr-menu-actions button {
            font-size: 10px;
            padding: 1px 4px;
        }
        .af-aqr-menu-body {
            display: flex;
            flex-wrap: wrap;
            gap: 3px;
            min-height: 22px;
        }
        .af-aqr-available {
            margin-top: 12px;
            border: 1px solid #ccc;
            background: #ffffff;
            padding: 8px;
        }
        .af-aqr-available-caption {
            font-weight: bold;
            margin-bottom: 6px;
        }
        .af-aqr-available-list {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }
        .af-aqr-drop-hint {
            font-size: 11px;
            color: #888;
            margin-top: 4px;
        }
        .af-aqr-add-group {
            margin-top: 6px;
        }
        </style>';

        // Форма
        $form = new Form(
            'index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=toolbar',
            'post'
        );

        echo $form->generate_hidden_field('my_post_key', $mybb->post_code);

        $form_container = new FormContainer(
            $lang->af_advancedquickreply_admin_title ?? 'Advanced QuickReply'
        );

        $help_text = $lang->af_advancedquickreply_toolbar_help
            ?? 'Перетаскивай кнопки из блока «Доступные BB-коды» в группы тулбара. '
            .'Можно создавать несколько групп (разделяются вертикальной чертой в реальном тулбаре) и выпадающие меню внутри групп. '
            .'Кнопку можно удалить из тулбара крестиком — она вернётся в список доступных.';

        $builder_html = '
        <div class="af-aqr-toolbar-builder">
            <div class="af-aqr-toolbar-row">
                <div class="af-aqr-toolbar-caption"><strong>Текущий тулбар</strong></div>
                <div id="af-aqr-toolbar-groups" class="af-aqr-toolbar-groups"></div>
                <button type="button" id="af-aqr-add-group" class="af-aqr-add-group">+ Добавить группу</button>
                <div class="af-aqr-drop-hint">Подсказка: перетащи кнопку из списка ниже на группу, в меню или на другую кнопку (чтобы вставить перед ней).</div>
            </div>
            <div class="af-aqr-available">
                <div class="af-aqr-available-caption">Доступные BB-коды</div>
                <div id="af-aqr-available-list" class="af-aqr-available-list"></div>
                <div class="af-aqr-drop-hint">Кнопки, уже использованные в тулбаре, отсюда исчезают. Чтобы убрать кнопку из тулбара, нажми на крестик на ней.</div>
            </div>
        </div>
        '.$form->generate_hidden_field('toolbar', $layout_json, [
            'id' => 'af_aqr_toolbar_input',
        ]).'
        ';

        $label = $lang->af_advancedquickreply_toolbar_label ?? 'Layout тулбара';

        $form_container->output_row(
            $label,
            $help_text,
            $builder_html
        );

        $form_container->end();

        $buttons   = [];
        $buttons[] = $form->generate_submit_button(
            $lang->af_advancedquickreply_save_button ?? 'Сохранить'
        );
        $form->output_submit_wrapper($buttons);

        $form->end();

        // JS-конструктор
        echo '<script type="text/javascript">
        (function() {
            var AF_AQR_LAYOUT  = '.$layout_json_js.';
            var AF_AQR_BUTTONS = '.$buttons_json_js.';
            // Глобальная инфа о текущем перетаскивании — для капризных браузеров
            var AF_AQR_DRAG = null;

            if (!Array.isArray(AF_AQR_LAYOUT)) {
                AF_AQR_LAYOUT = [];
            }
            if (!AF_AQR_BUTTONS || typeof AF_AQR_BUTTONS !== "object") {
                AF_AQR_BUTTONS = {};
            }

            function setDrag(id, origin) {
                AF_AQR_DRAG = { id: id, origin: origin || "palette" };
            }

            function getDrag(e) {
                var data = AF_AQR_DRAG;

                if (e && e.dataTransfer) {
                    try {
                        var raw = e.dataTransfer.getData("text/plain") || e.dataTransfer.getData("text") || "";
                        if (raw) {
                            try {
                                var parsed = JSON.parse(raw);
                                if (parsed && parsed.id) {
                                    data = parsed;
                                }
                            } catch (ex) {
                                // Если это просто строка с id
                                data = { id: raw };
                            }
                        }
                    } catch (e2) {
                        // молча
                    }
                }

                if (!data || !data.id) return null;
                if (!AF_AQR_BUTTONS[data.id]) return null;

                return data;
            }

            function getUsedIds() {
                var used = {};
                AF_AQR_LAYOUT.forEach(function(group) {
                    if (!group || group.type !== "group" || !Array.isArray(group.items)) return;
                    group.items.forEach(function(item) {
                        if (!item || typeof item !== "object") return;
                        if (item.type === "button" && item.id) {
                            used[item.id] = true;
                        } else if (item.type === "menu" && Array.isArray(item.items)) {
                            item.items.forEach(function(sub) {
                                if (sub && sub.type === "button" && sub.id) {
                                    used[sub.id] = true;
                                }
                            });
                        }
                    });
                });
                return used;
            }

            function removeFromItems(items, id) {
                if (!Array.isArray(items)) return false;
                var changed = false;
                for (var i = items.length - 1; i >= 0; i--) {
                    var it = items[i];
                    if (!it || typeof it !== "object") continue;
                    if (it.type === "button" && it.id === id) {
                        items.splice(i, 1);
                        changed = true;
                    } else if (it.type === "menu" && Array.isArray(it.items)) {
                        if (removeFromItems(it.items, id)) {
                            changed = true;
                        }
                    }
                }
                return changed;
            }

            function removeFromLayout(id) {
                var changed = false;
                AF_AQR_LAYOUT.forEach(function(group) {
                    if (!group || group.type !== "group" || !Array.isArray(group.items)) return;
                    if (removeFromItems(group.items, id)) {
                        changed = true;
                    }
                });
                return changed;
            }

            function appendToGroup(groupIndex, id) {
                if (!AF_AQR_BUTTONS[id]) return;
                removeFromLayout(id);

                if (!AF_AQR_LAYOUT[groupIndex] || AF_AQR_LAYOUT[groupIndex].type !== "group") {
                    AF_AQR_LAYOUT[groupIndex] = { type: "group", items: [] };
                }
                if (!Array.isArray(AF_AQR_LAYOUT[groupIndex].items)) {
                    AF_AQR_LAYOUT[groupIndex].items = [];
                }
                AF_AQR_LAYOUT[groupIndex].items.push({ type: "button", id: id });
            }

            function appendToMenu(groupIndex, menuIndex, id) {
                if (!AF_AQR_BUTTONS[id]) return;
                removeFromLayout(id);

                var group = AF_AQR_LAYOUT[groupIndex];
                if (!group || group.type !== "group" || !Array.isArray(group.items)) return;
                var menu = group.items[menuIndex];
                if (!menu || menu.type !== "menu") return;
                if (!Array.isArray(menu.items)) {
                    menu.items = [];
                }
                menu.items.push({ type: "button", id: id });
            }

            function moveBefore(id, targetId) {
                if (!AF_AQR_BUTTONS[id]) return;

                removeFromLayout(id);

                var inserted = false;

                AF_AQR_LAYOUT.forEach(function(group) {
                    if (inserted) return;
                    if (!group || group.type !== "group" || !Array.isArray(group.items)) return;

                    var items = group.items;
                    for (var i = 0; i < items.length; i++) {
                        var it = items[i];
                        if (!it || typeof it !== "object") continue;

                        if (it.type === "button" && it.id === targetId) {
                            items.splice(i, 0, { type: "button", id: id });
                            inserted = true;
                            return;
                        } else if (it.type === "menu" && Array.isArray(it.items)) {
                            var mItems = it.items;
                            for (var j = 0; j < mItems.length; j++) {
                                var sub = mItems[j];
                                if (sub && sub.type === "button" && sub.id === targetId) {
                                    mItems.splice(j, 0, { type: "button", id: id });
                                    inserted = true;
                                    return;
                                }
                            }
                        }
                    }
                });

                if (!inserted) {
                    if (!AF_AQR_LAYOUT.length) {
                        AF_AQR_LAYOUT.push({ type: "group", items: [] });
                    }
                    if (!Array.isArray(AF_AQR_LAYOUT[0].items)) {
                        AF_AQR_LAYOUT[0].items = [];
                    }
                    AF_AQR_LAYOUT[0].items.push({ type: "button", id: id });
                }
            }

            function createButtonElement(btnId, inPalette, missing) {
                var meta = AF_AQR_BUTTONS[btnId];
                var el = document.createElement("div");
                el.className = "af-aqr-btn";
                if (inPalette) {
                    el.className += " af-aqr-btn--palette";
                }
                if (missing) {
                    el.className += " af-aqr-btn--missing";
                }

                el.setAttribute("draggable", "true");
                el.setAttribute("data-btn-id", btnId);
                el.title = meta && meta.tooltip ? meta.tooltip : btnId;

                var iconWrap = document.createElement("span");
                iconWrap.className = "af-aqr-btn-icon";

                if (meta) {
                    if (meta.fa) {
                        var i = document.createElement("i");
                        i.className = meta.fa;
                        iconWrap.appendChild(i);
                    } else if (meta.icon_url) {
                        var img = document.createElement("img");
                        img.src = meta.icon_url;
                        img.style.maxWidth = "16px";
                        img.style.maxHeight = "16px";
                        img.alt = "";
                        iconWrap.appendChild(img);
                    }
                }

                var labelSpan = document.createElement("span");
                labelSpan.className = "af-aqr-btn-label";
                labelSpan.textContent = meta && meta.label ? meta.label : btnId;

                el.appendChild(iconWrap);
                el.appendChild(labelSpan);

                if (!inPalette) {
                    var remove = document.createElement("span");
                    remove.className = "af-aqr-btn-remove";
                    remove.textContent = "×";
                    remove.title = "Убрать из тулбара";
                    remove.addEventListener("click", function(e) {
                        e.stopPropagation();
                        e.preventDefault();
                        removeFromLayout(btnId);
                        renderAll();
                    });
                    el.appendChild(remove);
                }

                el.addEventListener("dragstart", function(e) {
                    e.dataTransfer.effectAllowed = "move";
                    var payload = { id: btnId, origin: inPalette ? "palette" : "layout" };
                    setDrag(btnId, payload.origin);
                    try {
                        e.dataTransfer.setData("text/plain", JSON.stringify(payload));
                        e.dataTransfer.setData("text", btnId);
                    } catch (ex) {
                        // иногда браузеры куксятся — игнорируем
                    }
                });

                el.addEventListener("dragover", function(e) {
                    e.preventDefault();
                });

                el.addEventListener("drop", function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var data = getDrag(e);
                    if (!data) return;
                    moveBefore(data.id, btnId);
                    renderAll();
                });

                return el;
            }

            function renderAll() {
                var groupsRoot = document.getElementById("af-aqr-toolbar-groups");
                var availRoot  = document.getElementById("af-aqr-available-list");
                var hidden     = document.getElementById("af_aqr_toolbar_input");

                if (!groupsRoot || !availRoot || !hidden) return;

                groupsRoot.innerHTML = "";
                availRoot.innerHTML  = "";

                var used = getUsedIds();

                // Рендер групп
                AF_AQR_LAYOUT.forEach(function(group, gIndex) {
                    if (!group || group.type !== "group") return;
                    if (!Array.isArray(group.items)) group.items = [];

                    var groupEl = document.createElement("div");
                    groupEl.className = "af-aqr-group";

                    var header = document.createElement("div");
                    header.className = "af-aqr-group-header";

                    var title = document.createElement("div");
                    title.className = "af-aqr-group-title";
                    title.textContent = "Группа " + (gIndex + 1);

                    var actions = document.createElement("div");
                    actions.className = "af-aqr-group-actions";

                    var addMenuBtn = document.createElement("button");
                    addMenuBtn.type = "button";
                    addMenuBtn.textContent = "+ Меню";
                    addMenuBtn.addEventListener("click", function(e) {
                        e.preventDefault();
                        if (!Array.isArray(group.items)) group.items = [];
                        group.items.push({
                            type: "menu",
                            label: "Меню",
                            items: []
                        });
                        renderAll();
                    });
                    actions.appendChild(addMenuBtn);

                    var delGroupBtn = document.createElement("button");
                    delGroupBtn.type = "button";
                    delGroupBtn.textContent = "×";
                    delGroupBtn.title = "Удалить группу";
                    delGroupBtn.addEventListener("click", function(e) {
                        e.preventDefault();
                        AF_AQR_LAYOUT.splice(gIndex, 1);
                        renderAll();
                    });
                    actions.appendChild(delGroupBtn);

                    header.appendChild(title);
                    header.appendChild(actions);

                    var body = document.createElement("div");
                    body.className = "af-aqr-group-body";
                    body.setAttribute("data-group-index", String(gIndex));

                    body.addEventListener("dragover", function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                    });
                    body.addEventListener("drop", function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        var data = getDrag(e);
                        if (!data) return;
                        appendToGroup(gIndex, data.id);
                        renderAll();
                    });

                    group.items.forEach(function(item, itemIndex) {
                        if (!item || typeof item !== "object") return;

                        if (item.type === "button" && item.id) {
                            var missing = !AF_AQR_BUTTONS[item.id];
                            var btnEl = createButtonElement(item.id, false, missing);
                            body.appendChild(btnEl);
                        } else if (item.type === "menu") {
                            var menuEl = document.createElement("div");
                            menuEl.className = "af-aqr-menu-block";

                            var mHeader = document.createElement("div");
                            mHeader.className = "af-aqr-menu-header";

                            var mTitle = document.createElement("div");
                            mTitle.className = "af-aqr-menu-title";
                            mTitle.textContent = item.label || "Меню";
                            mTitle.title = "Кликни, чтобы переименовать меню";
                            mTitle.addEventListener("click", function(e) {
                                e.preventDefault();
                                var name = prompt("Название меню:", item.label || "");
                                if (name !== null) {
                                    item.label = name.trim() || "Меню";
                                    renderAll();
                                }
                            });

                            var mActions = document.createElement("div");
                            mActions.className = "af-aqr-menu-actions";

                            var addBtn = document.createElement("button");
                            addBtn.type = "button";
                            addBtn.textContent = "+";
                            addBtn.title = "Чтобы добавить кнопку в меню — просто перетащи её внутрь";
                            addBtn.disabled = true;
                            mActions.appendChild(addBtn);

                            var delBtn = document.createElement("button");
                            delBtn.type = "button";
                            delBtn.textContent = "×";
                            delBtn.title = "Удалить меню";
                            delBtn.addEventListener("click", function(e) {
                                e.preventDefault();
                                group.items.splice(itemIndex, 1);
                                renderAll();
                            });
                            mActions.appendChild(delBtn);

                            mHeader.appendChild(mTitle);
                            mHeader.appendChild(mActions);

                            var mBody = document.createElement("div");
                            mBody.className = "af-aqr-menu-body";
                            mBody.setAttribute("data-group-index", String(gIndex));
                            mBody.setAttribute("data-menu-index", String(itemIndex));

                            mBody.addEventListener("dragover", function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                            });
                            mBody.addEventListener("drop", function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                var data = getDrag(e);
                                if (!data) return;
                                var gi = parseInt(this.getAttribute("data-group-index"), 10);
                                var mi = parseInt(this.getAttribute("data-menu-index"), 10);
                                appendToMenu(gi, mi, data.id);
                                renderAll();
                            });

                            if (!Array.isArray(item.items)) {
                                item.items = [];
                            }

                            item.items.forEach(function(sub) {
                                if (!sub || sub.type !== "button" || !sub.id) return;
                                var missingSub = !AF_AQR_BUTTONS[sub.id];
                                var subEl = createButtonElement(sub.id, false, missingSub);
                                mBody.appendChild(subEl);
                            });

                            menuEl.appendChild(mHeader);
                            menuEl.appendChild(mBody);
                            body.appendChild(menuEl);
                        }
                    });

                    groupEl.appendChild(header);
                    groupEl.appendChild(body);
                    groupsRoot.appendChild(groupEl);
                });

                // Если нет ни одной группы — создаём одну пустую
                if (!AF_AQR_LAYOUT.length) {
                    AF_AQR_LAYOUT.push({ type: "group", items: [] });
                    return renderAll();
                }

                // Рендер доступных кнопок (которые не используются)
                var usedIds = getUsedIds();
                Object.keys(AF_AQR_BUTTONS).forEach(function(id) {
                    if (usedIds[id]) return;
                    var meta = AF_AQR_BUTTONS[id];
                    var missing = (meta.source === "unknown");
                    var bEl = createButtonElement(id, true, missing);
                    availRoot.appendChild(bEl);
                });

                // Обновляем скрытое поле (JSON)
                hidden.value = JSON.stringify(AF_AQR_LAYOUT);
            }

            function init() {
                var addGroupBtn = document.getElementById("af-aqr-add-group");
                if (addGroupBtn) {
                    addGroupBtn.addEventListener("click", function(e) {
                        e.preventDefault();
                        AF_AQR_LAYOUT.push({
                            type: "group",
                            items: []
                        });
                        renderAll();
                    });
                }

                // Подстраховка: пересохраняем layout при submit
                var hidden = document.getElementById("af_aqr_toolbar_input");
                if (hidden && hidden.form) {
                    hidden.form.addEventListener("submit", function() {
                        var h = document.getElementById("af_aqr_toolbar_input");
                        if (h) {
                            h.value = JSON.stringify(AF_AQR_LAYOUT);
                        }
                    });
                }

                renderAll();
            }

            if (document.readyState === "loading") {
                document.addEventListener("DOMContentLoaded", init);
            } else {
                init();
            }
        })();
        </script>';
    }


    /**
    * Собирает список кнопок для конструктора тулбара
    * (стандартные BB-коды + кастомные MyCode).
    */
    protected static function get_toolbar_buttons(): array
    {
        global $db, $mybb;

        $buttons = [];
        $bburl   = rtrim($mybb->settings['bburl'] ?? '', '/');

        // Маппинг тегов стандартных BB-кодов на команды SCEditor
        $default_map = [
            'b'       => ['button' => 'bold',       'label' => 'Жирный [b]'],
            'i'       => ['button' => 'italic',     'label' => 'Курсив [i]'],
            'u'       => ['button' => 'underline',  'label' => 'Подчёркнутый [u]'],
            's'       => ['button' => 'strike',     'label' => 'Зачёркнутый [s]'],
            'url'     => ['button' => 'link',       'label' => 'Ссылка [url]'],
            'img'     => ['button' => 'image',      'label' => 'Изображение [img]'],
            'email'   => ['button' => 'email',      'label' => 'Email [email]'],
            'quote'   => ['button' => 'quote',      'label' => 'Цитата [quote]'],
            'code'    => ['button' => 'code',       'label' => 'Код [code]'],
            'php'     => ['button' => 'php',        'label' => 'PHP-код [php]'],
            'list'    => ['button' => 'bulletlist', 'label' => 'Список [list]'],
            'video'   => ['button' => 'video',      'label' => 'Видео [video]'],
            'size'    => ['button' => 'size',       'label' => 'Размер [size]'],
            'font'    => ['button' => 'font',       'label' => 'Шрифт [font]'],
            'color'   => ['button' => 'color',      'label' => 'Цвет [color]'],
            'align'   => ['button' => 'left',       'label' => 'Выравнивание [align]'],
            'spoiler' => ['button' => 'spoiler',    'label' => 'Спойлер [spoiler]'],
        ];

        // Оверрайды стандартных BB-кодов
        $overrides = [];
        if ($db->table_exists('af_aqr_defaultcodes')) {
            $q = $db->simple_select('af_aqr_defaultcodes', '*');
            while ($row = $db->fetch_array($q)) {
                $overrides[$row['tag']] = $row;
            }
        }

        foreach ($default_map as $tag => $info) {
            $btn_id = $info['button'];

            $title       = $info['label'];
            $description = '';
            $enabled     = 1;
            $fa          = '';
            $img         = '';

            if (isset($overrides[$tag])) {
                $ov = $overrides[$tag];
                if ($ov['title'] !== '') {
                    $title = $ov['title'];
                }
                if ($ov['description'] !== '') {
                    $description = $ov['description'];
                }
                $enabled = (int)($ov['enabled'] ?? 1);
                $fa      = trim($ov['fa_icon'] ?? '');
                $img     = trim($ov['af_button_icon'] ?? '');
            }

            if ($enabled === 0) {
                continue; // выключенные дефолтные BB-коды не предлагаем как кнопки
            }

            $icon_url = '';
            if ($img !== '') {
                $icon_url = $img;
                if ($bburl !== '' && !preg_match('#^https?://#i', $icon_url) && $icon_url[0] !== '/') {
                    $icon_url = $bburl.'/'.$icon_url;
                }
            }

            $buttons[$btn_id] = [
                'id'          => $btn_id,
                'source'      => 'default',
                'tag'         => $tag,
                'label'       => $title,
                'description' => $description,
                'fa'          => $fa,
                'icon_url'    => $icon_url,
            ];
        }

        // Дополнительные команды SCEditor, не привязанные к конкретному BB-коду
        $extra = [
            'removeformat' => 'Очистить форматирование',
            'emoticon'     => 'Смайлы',
            'source'       => 'Источник (BBCode/HTML)',
        ];

        foreach ($extra as $id => $label) {
            if (isset($buttons[$id])) {
                continue;
            }
            $buttons[$id] = [
                'id'          => $id,
                'source'      => 'builtin',
                'tag'         => '',
                'label'       => $label,
                'description' => '',
                'fa'          => '',
                'icon_url'    => '',
            ];
        }

        // Кастомные BB-коды (MyCode)
        if ($db->table_exists('mycode')) {
            $fields = 'cid,title,description,active,af_button_icon';
            if ($db->field_exists('af_fa_icon', 'mycode')) {
                $fields .= ',af_fa_icon';
            }

            $q2 = $db->simple_select('mycode', $fields, '', ['order_by' => 'parseorder', 'order_dir' => 'ASC']);
            while ($row = $db->fetch_array($q2)) {
                $cid = (int)$row['cid'];
                if ($cid <= 0) {
                    continue;
                }

                $btn_id = 'mycode_'.$cid;

                $label = $row['title'] !== '' ? $row['title'] : 'MyCode #'.$cid;

                $icon_url = '';
                $img = trim($row['af_button_icon'] ?? '');
                if ($img !== '') {
                    $icon_url = $img;
                    if ($bburl !== '' && !preg_match('#^https?://#i', $icon_url) && $icon_url[0] !== '/') {
                        $icon_url = $bburl.'/'.$icon_url;
                    }
                }

                $buttons[$btn_id] = [
                    'id'          => $btn_id,
                    'source'      => 'mycode',
                    'cid'         => $cid,
                    'label'       => $label,
                    'description' => $row['description'] ?? '',
                    'fa'          => trim($row['af_fa_icon'] ?? ''),
                    'icon_url'    => $icon_url,
                ];
            }
        }

        // Тултипы
        foreach ($buttons as $id => &$b) {
            $tip = $b['label'];
            if (!empty($b['description'])) {
                $tip .= ' — '.$b['description'];
            }
            $b['tooltip'] = $tip;
        }
        unset($b);

        return $buttons;
    }

/**
 * Нормализует layout из setting'а:
 *  - если там JSON — используем его;
 *  - если строка формата "bold,italic|..." — парсим в массив;
 *  - если пусто — собираем дефолт.
 */
protected static function normalize_layout(string $raw, array $buttons): array
{
    $layout = [];

    $raw = trim($raw);

    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            // Валидируем JSON-структуру
            foreach ($decoded as $group) {
                if (!is_array($group) || ($group['type'] ?? '') !== 'group') {
                    continue;
                }
                $items = [];
                foreach ($group['items'] ?? [] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    if (($item['type'] ?? '') === 'button' && !empty($item['id'])) {
                        $items[] = [
                            'type' => 'button',
                            'id'   => (string)$item['id'],
                        ];
                    } elseif (($item['type'] ?? '') === 'menu') {
                        $menu_items = [];
                        foreach ($item['items'] ?? [] as $sub) {
                            if (!is_array($sub)) {
                                continue;
                            }
                            if (($sub['type'] ?? '') === 'button' && !empty($sub['id'])) {
                                $menu_items[] = [
                                    'type' => 'button',
                                    'id'   => (string)$sub['id'],
                                ];
                            }
                        }
                        $items[] = [
                            'type'  => 'menu',
                            'label' => (string)($item['label'] ?? 'Меню'),
                            'items' => $menu_items,
                        ];
                    }
                }
                if ($items) {
                    $layout[] = [
                        'type'  => 'group',
                        'items' => $items,
                    ];
                }
            }

            if (!empty($layout)) {
                return $layout;
            }
        }

        // Легаси-строка "bold,italic|font,size"
        $groups = explode('|', $raw);
        foreach ($groups as $g) {
            $g = trim($g);
            if ($g === '') {
                continue;
            }
            $items = [];
            $parts = explode(',', $g);
            foreach ($parts as $p) {
                $id = trim($p);
                if ($id === '') {
                    continue;
                }
                $items[] = [
                    'type' => 'button',
                    'id'   => $id,
                ];
            }
            if ($items) {
                $layout[] = [
                    'type'  => 'group',
                    'items' => $items,
                ];
            }
        }

        if (!empty($layout)) {
            return $layout;
        }
    }

    // Дефолт: одна группа с базовыми кнопками, если они есть
    $default_order = [
        'bold',
        'italic',
        'underline',
        'strike',
        'link',
        'unlink',
        'image',
        'quote',
        'code',
        'bulletlist',
        'orderedlist',
        'spoiler',
        'source',
    ];

    $items = [];
    foreach ($default_order as $id) {
        if (isset($buttons[$id])) {
            $items[] = [
                'type' => 'button',
                'id'   => $id,
            ];
        }
    }

    if ($items) {
        $layout[] = [
            'type'  => 'group',
            'items' => $items,
        ];
    }

    return $layout;
}


    /* =========================================================
     *  ТАБ "BB-КОДЫ" — LIST / ADD / EDIT / DELETE / TOGGLE
     * ======================================================= */

    protected static function dispatch_bbcodes(): void
    {
        global $mybb;

        $do = $mybb->get_input('do');

        switch ($do) {
            case 'add':
                self::render_bbcodes_form();
                break;
            case 'edit':
                $cid = (int)$mybb->get_input('cid');
                self::render_bbcodes_form($cid);
                break;
            case 'delete':
                self::handle_bbcodes_delete();
                break;
            case 'toggle':
                self::handle_bbcodes_toggle();
                break;
            case 'edit_default':
                $tag = trim($mybb->get_input('tag'));
                self::render_default_bbcode_form($tag);
                break;
            case 'toggle_default':
                self::handle_default_toggle();
                break;
            default:
                self::render_bbcodes_list();
        }
    }

    /**
     * Список стандартных и кастомных BB-кодов.
     */
    protected static function render_bbcodes_list(): void
    {
        global $db, $page, $mybb;

        // Кнопка "Создать" — ВВЕРХУ и заметная
        echo '<div style="margin: 10px 0;">
    <a href="index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes&do=add" class="submit_button">
        Создать BB-код
    </a>
</div>';

        $page->output_inline_message(
            'Здесь выводятся стандартные BB-коды MyBB и кастомные BB-коды (таблица <code>mycode</code>). '
            .'Кастомные можно редактировать, отключать и удалять, а также задавать для них иконки. '
            .'Для стандартных BB-кодов доступны изменение названия/описания/иконки и флаг включения.'
        );

        // Подтягиваем оверрайды для стандартных BB-кодов
        $overrides = [];
        if ($db->table_exists('af_aqr_defaultcodes')) {
            $q = $db->simple_select('af_aqr_defaultcodes', '*');
            while ($row = $db->fetch_array($q)) {
                $overrides[$row['tag']] = $row;
            }
        }

        // --- Стандартные BB-коды ---
        $default_bbcodes = [
            'b'       => 'Жирный [b]',
            'i'       => 'Курсив [i]',
            'u'       => 'Подчёркнутый [u]',
            's'       => 'Зачёркнутый [s]',
            'url'     => 'Ссылка [url]',
            'img'     => 'Изображение [img]',
            'email'   => 'Email [email]',
            'quote'   => 'Цитата [quote]',
            'code'    => 'Код [code]',
            'php'     => 'PHP-код [php]',
            'list'    => 'Список [list] / [*]',
            'video'   => 'Видео [video]',
            'size'    => 'Размер шрифта [size]',
            'font'    => 'Шрифт [font]',
            'color'   => 'Цвет [color]',
            'align'   => 'Выравнивание [align]',
            'spoiler' => 'Спойлер [spoiler]',
        ];

        $table = new Table;
        $table->construct_header('Тег', ['width' => '10%']);
        $table->construct_header('Название / описание');
        $table->construct_header('Включён', ['width' => '8%']);
        $table->construct_header('Иконка', ['width' => '15%']);
        $table->construct_header('Действия', ['width' => '20%']);

        foreach ($default_bbcodes as $tag => $desc) {
            $tag_key = $tag;

            $title       = $desc;
            $description = '';
            $enabled     = 1;
            $icon_html   = '-';

            if (isset($overrides[$tag_key])) {
                $ov = $overrides[$tag_key];
                if ($ov['title'] !== '') {
                    $title = $ov['title'];
                }
                if ($ov['description'] !== '') {
                    $description = $ov['description'];
                }
                $enabled = (int)$ov['enabled'];

                if ($ov['af_button_icon'] !== '') {
                    $icon_src = $ov['af_button_icon'];

                    if (!preg_match('#^https?://#i', $icon_src) && $icon_src[0] !== '/') {
                        $base = rtrim($mybb->settings['bburl'] ?? '', '/');
                        if ($base !== '') {
                            $icon_src = $base.'/'.$icon_src;
                        }
                    }

                    $safe_icon = htmlspecialchars_uni($icon_src);

                    if (preg_match('#\.(png|jpe?g|gif|webp|svg)$#i', $ov['af_button_icon'])) {
                        $icon_html = '<img src="'.$safe_icon.'" alt="" style="max-height:20px;max-width:80px;" />';
                    } else {
                        $icon_html = '<code>'.$safe_icon.'</code>';
                    }
                }

            }

            $tag_display = '['.htmlspecialchars_uni($tag_key).']';

            $full_desc = htmlspecialchars_uni($title);
            if ($description !== '') {
                $full_desc .= '<br /><span class="smalltext">'.nl2br(htmlspecialchars_uni($description)).'</span>';
            }

            $status = $enabled ? 'Да' : 'Нет';

            $edit_link   = 'index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes&do=edit_default&tag='.urlencode($tag_key);
            $toggle_link = 'index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes&do=toggle_default&tag='.urlencode($tag_key).'&my_post_key='.htmlspecialchars_uni($mybb->post_code);

            $toggle_label = $enabled ? 'Выключить' : 'Включить';

            $actions = '<a href="'.$edit_link.'">Редактировать</a> | '
                     . '<a href="'.$toggle_link.'" onclick="return confirm(\'Изменить статус этого BB-кода?\');">'.$toggle_label.'</a>';

            $table->construct_cell($tag_display);
            $table->construct_cell($full_desc);
            $table->construct_cell($status);
            $table->construct_cell($icon_html);
            $table->construct_cell($actions);
            $table->construct_row();
        }

        echo '<h3>Стандартные BB-коды MyBB</h3>';
        $table->output('Стандартные BB-коды');

        // --- Кастомные BB-коды (таблица mycode) ---
        $query = $db->simple_select('mycode', '*', '', ['order_by' => 'parseorder', 'order_dir' => 'ASC']);

        echo '<br /><h3>Кастомные BB-коды (MyCode)</h3>';

        $table2 = new Table;
        $table2->construct_header('Название', ['width' => '20%']);
        $table2->construct_header('Описание');
        $table2->construct_header('Активен', ['width' => '8%']);
        $table2->construct_header('Порядок', ['width' => '8%']);
        $table2->construct_header('Иконка', ['width' => '15%']);
        $table2->construct_header('Действия', ['width' => '20%']);

        while ($row = $db->fetch_array($query)) {
            $cid        = (int)$row['cid'];
            $title      = htmlspecialchars_uni($row['title']);
            $desc       = htmlspecialchars_uni($row['description']);
            $active     = (int)$row['active'];
            $active_lbl = $active ? 'Да' : 'Нет';
            $parseorder = (int)$row['parseorder'];

            $icon = $row['af_button_icon'] ?? '';
            $icon_html = '-';
            if ($icon !== '') {
                $icon_src = $icon;

                if (!preg_match('#^https?://#i', $icon_src) && $icon_src[0] !== '/') {
                    $base = rtrim($mybb->settings['bburl'] ?? '', '/');
                    if ($base !== '') {
                        $icon_src = $base.'/'.$icon_src;
                    }
                }

                $safe_icon = htmlspecialchars_uni($icon_src);

                if (preg_match('#\.(png|jpe?g|gif|webp|svg)$#i', $icon)) {
                    $icon_html = '<img src="'.$safe_icon.'" alt="" style="max-height:20px;max-width:80px;" />';
                } else {
                    $icon_html = '<code>'.$safe_icon.'</code>';
                }
            }


            $edit_link    = 'index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes&do=edit&cid='.$cid;
            $toggle_link  = 'index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes&do=toggle&cid='.$cid.'&my_post_key='.htmlspecialchars_uni($mybb->post_code);
            $delete_link  = 'index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes&do=delete&cid='.$cid.'&my_post_key='.htmlspecialchars_uni($mybb->post_code);
            $toggle_label = $active ? 'Выключить' : 'Включить';

            $actions = '<a href="'.$edit_link.'">Редактировать</a> | '
                     . '<a href="'.$toggle_link.'" onclick="return confirm(\'Изменить статус этого BB-кода?\');">'.$toggle_label.'</a> | '
                     . '<a href="'.$delete_link.'" onclick="return confirm(\'Удалить этот BB-код?\');">Удалить</a>';

            $table2->construct_cell($title);
            $table2->construct_cell($desc);
            $table2->construct_cell($active_lbl);
            $table2->construct_cell((string)$parseorder);
            $table2->construct_cell($icon_html);
            $table2->construct_cell($actions);
            $table2->construct_row();
        }

        $table2->output('Кастомные BB-коды');
    }

    /**
     * Вкл/выкл кастомного BB-кода (mycode.active).
     */
    protected static function handle_bbcodes_toggle(): void
    {
        global $mybb, $db;

        $cid = (int)$mybb->get_input('cid');
        if ($cid <= 0) {
            flash_message('Некорректный идентификатор BB-кода.', 'error');
            admin_redirect('index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes');
        }

        verify_post_check($mybb->get_input('my_post_key'));

        $row = $db->fetch_array($db->simple_select('mycode', 'cid,active', 'cid='.$cid, ['limit' => 1]));
        if (!$row) {
            flash_message('BB-код не найден.', 'error');
            admin_redirect('index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes');
        }

        $new = $row['active'] ? 0 : 1;

        $db->update_query('mycode', ['active' => $new], 'cid='.$cid);

        flash_message($new ? 'BB-код включён.' : 'BB-код выключен.', 'success');
        admin_redirect('index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes');
    }

    /* ====== Форма стандартного BB-кода (метаданные + иконка) ====== */
    protected static function render_default_bbcode_form(string $tag): void
    {
        global $db, $mybb, $page;

        $tag = trim($tag);
        if ($tag === '') {
            flash_message('Не указан тег BB-кода.', 'error');
            admin_redirect('index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes');
        }

        // Базовые дефолты (fallback)
        $default_bbcodes = [
            'b'       => 'Жирный [b]',
            'i'       => 'Курсив [i]',
            'u'       => 'Подчёркнутый [u]',
            's'       => 'Зачёркнутый [s]',
            'url'     => 'Ссылка [url]',
            'img'     => 'Изображение [img]',
            'email'   => 'Email [email]',
            'quote'   => 'Цитата [quote]',
            'code'    => 'Код [code]',
            'php'     => 'PHP-код [php]',
            'list'    => 'Список [list] / [*]',
            'video'   => 'Видео [video]',
            'size'    => 'Размер шрифта [size]',
            'font'    => 'Шрифт [font]',
            'color'   => 'Цвет [color]',
            'align'   => 'Выравнивание [align]',
            'spoiler' => 'Спойлер [spoiler]',
        ];

        if (!isset($default_bbcodes[$tag])) {
            flash_message('Это не стандартный BB-код MyBB.', 'error');
            admin_redirect('index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes');
        }

        // Базовый объект
        $data = [
            'tag'            => $tag,
            'title'          => $default_bbcodes[$tag],
            'description'    => '',
            'enabled'        => 1,
            'af_button_icon' => '',
            'fa_icon'        => '',
        ];

        if ($db->table_exists('af_aqr_defaultcodes')) {
            $row = $db->fetch_array($db->simple_select(
                'af_aqr_defaultcodes',
                '*',
                "tag='".$db->escape_string($tag)."'",
                ['limit' => 1]
            ));
            if ($row) {
                $data = array_merge($data, $row);
            }
        }

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            $title      = trim($mybb->get_input('title'));
            $desc       = trim($mybb->get_input('description'));
            $enabled    = (int)$mybb->get_input('enabled');
            $icon_value = trim($mybb->get_input('af_button_icon'));
            $fa_icon    = trim($mybb->get_input('fa_icon'));

            if ($title === '') {
                $title = $default_bbcodes[$tag];
            }

            // upload иконки (если есть)
            if (!empty($_FILES['af_button_icon_file']['name'])) {
                $upload = $_FILES['af_button_icon_file'];

                if ($upload['error'] === UPLOAD_ERR_OK && is_uploaded_file($upload['tmp_name'])) {
                    $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true)) {
                        flash_message('Недопустимый формат иконки. Разрешены: png, jpg, jpeg, gif, webp, svg.', 'error');
                    } else {
                        $icons_dir_fs  = AF_ADDONS.'advancedquickreply/icons/';
                        $icons_dir_rel = 'inc/plugins/advancedfunctionality/addons/advancedquickreply/icons/';

                        if (!is_dir($icons_dir_fs)) {
                            @mkdir($icons_dir_fs, 0755, true);
                        }

                        $safe_name = 'default_'.$tag.'_'.time().'.'.$ext;
                        $dest_fs   = $icons_dir_fs.$safe_name;
                        $dest_rel  = $icons_dir_rel.$safe_name;

                        if (move_uploaded_file($upload['tmp_name'], $dest_fs)) {
                            $icon_value = $dest_rel;
                        } else {
                            flash_message('Не удалось сохранить файл иконки на сервере.', 'error');
                        }
                    }
                }
            }

            // upsert в af_aqr_defaultcodes
            if (!$db->table_exists('af_aqr_defaultcodes')) {
                // на всякий пожарный, если вдруг таблица не создалась
                $collation = $db->build_create_table_collation();
                $db->write_query("
                    CREATE TABLE ".TABLE_PREFIX."af_aqr_defaultcodes (
                        tag            varchar(32)  NOT NULL,
                        title          varchar(100) NOT NULL default '',
                        description    text         NOT NULL,
                        enabled        tinyint(1)   NOT NULL default 1,
                        fa_icon        varchar(100) NOT NULL default '',
                        af_button_icon varchar(255) NOT NULL default '',
                        PRIMARY KEY (tag)
                    ) ENGINE=MyISAM {$collation}
                ");
            } elseif (!$db->field_exists('fa_icon', 'af_aqr_defaultcodes')) {
                $db->add_column('af_aqr_defaultcodes', 'fa_icon', "varchar(100) NOT NULL default ''");
            }

            $record = [
                'title'          => $db->escape_string($title),
                'description'    => $db->escape_string($desc),
                'enabled'        => $enabled ? 1 : 0,
                'af_button_icon' => $db->escape_string($icon_value),
                'fa_icon'        => $db->escape_string($fa_icon),
            ];

            $exists = $db->fetch_array($db->simple_select(
                'af_aqr_defaultcodes',
                'tag',
                "tag='".$db->escape_string($tag)."'",
                ['limit' => 1]
            ));
            if ($exists) {
                $db->update_query('af_aqr_defaultcodes', $record, "tag='".$db->escape_string($tag)."'");
            } else {
                $record['tag'] = $db->escape_string($tag);
                $db->insert_query('af_aqr_defaultcodes', $record);
            }

            flash_message('Настройки стандартного BB-кода сохранены.', 'success');
            admin_redirect('index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes');
        }

        $title_text = 'Стандартный BB-код ['.htmlspecialchars_uni($tag).']';

        echo '<h3>'.$title_text.'</h3>';

        $form = new Form(
            'index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes&do=edit_default&tag='.urlencode($tag),
            'post',
            '',
            true
        );
        echo $form->generate_hidden_field('my_post_key', $mybb->post_code);

        $container = new FormContainer($title_text);

        $container->output_row(
            'Название',
            'Текстовое название для админки / подсказок.',
            $form->generate_text_box('title', $data['title'], ['style' => 'width: 300px;'])
        );

        $container->output_row(
            'Описание',
            'Произвольное описание / подсказка.',
            $form->generate_text_area('description', $data['description'], ['rows' => 3, 'style' => 'width: 98%;'])
        );

        $container->output_row(
            'Включён',
            'Этот флаг можно использовать в дальнейшем для скрытия кнопки из тулбара или для кастомного парсинга.',
            $form->generate_yes_no_radio('enabled', (int)$data['enabled'])
        );

        $icon_desc = 'Можно указать либо прямую ссылку (URL), либо загрузить файл. '
                   .'Загруженные файлы будут сохранены в <code>inc/plugins/advancedfunctionality/addons/advancedquickreply/icons/</code>.';

        $icon_html = $form->generate_text_box('af_button_icon', $data['af_button_icon'], ['style' => 'width: 60%;']).'<br /><br />'
                   . 'Загрузить новую иконку: '.$form->generate_file_upload_box('af_button_icon_file');

        if (!empty($data['af_button_icon'])) {
            $icon_src = $data['af_button_icon'];

            if (!preg_match('#^https?://#i', $icon_src) && $icon_src[0] !== '/') {
                $base = rtrim($mybb->settings['bburl'] ?? '', '/');
                if ($base !== '') {
                    $icon_src = $base.'/'.$icon_src;
                }
            }

            $safe_icon = htmlspecialchars_uni($icon_src);

            if (preg_match('#\.(png|jpe?g|gif|webp|svg)$#i', $data['af_button_icon'])) {
                $icon_html .= '<br /><br />Текущая картинка-иконка:<br />'
                            . '<img src="'.$safe_icon.'" alt="" style="max-height:40px;max-width:120px;border:1px solid #ccc;padding:2px;" />';
            } else {
                $icon_html .= '<br /><br />Текущее значение: <code>'.$safe_icon.'</code>';
            }
        }

        $container->output_row(
            'Иконка BB-кода (картинка)',
            $icon_desc,
            $icon_html
        );

        $fa_desc = 'Класс Font Awesome для кнопки этого BB-кода. Например: <code>fa-solid fa-quote-left</code> или <code>fas fa-bold</code>.<br />'
                 .'Используется, только если на фронте подключён Font Awesome. '
                 .'Если указано значение здесь, картинка-иконка будет проигнорирована.';

        // Поле + кнопка + контейнер-подборщик
        $fa_html  = '<div class="af-aqr-fa-block">';
        $fa_html .= $form->generate_text_box('fa_icon', $data['fa_icon'], [
            'style' => 'width: 60%;',
            'id'    => 'af_aqr_fa_input',
        ]);
        if (!empty($data['fa_icon'])) {
            $fa_html .= '<br /><br />Текущее значение: <code>'.htmlspecialchars_uni($data['fa_icon']).'</code>';
        }
        $fa_html .= '<br /><br /><a href="#" class="button" id="af_aqr_fa_toggle">Открыть подбор иконок</a>';
        $fa_html .= '<div id="af_aqr_fa_picker" class="af-aqr-fa-picker" style="display:none;margin-top:10px;">'
                  . '   <input type="text" id="af_aqr_fa_search" class="af-aqr-fa-search" '
                  . '          placeholder="Поиск по названию иконки..." style="width:60%;" />'
                  . '   <div id="af_aqr_fa_list" class="af-aqr-fa-list" style="margin-top:8px;"></div>'
                  . '</div>';
        $fa_html .= '</div>';

        $container->output_row(
            'Иконка BB-кода (Font Awesome)',
            $fa_desc,
            $fa_html
        );

        $container->end();

        $buttons = [];
        $buttons[] = $form->generate_submit_button('Сохранить');
        $form->output_submit_wrapper($buttons);

        $form->end();

        // Общий JS/CSS для FA-подборщика — выводим один раз на страницу
        if (!defined('AF_AQR_FA_JS')) {
            define('AF_AQR_FA_JS', 1);

            echo '<style type="text/css">
        .af-aqr-fa-picker {
            border: 1px solid #ccc;
            background: #fff;
            padding: 8px;
            max-height: 260px;
            overflow: auto;
        }
        .af-aqr-fa-list {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 6px;
        }
        .af-aqr-fa-item {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 3px 6px;
            border: 1px solid #ddd;
            background: #f7f7f7;
            cursor: pointer;
            font-size: 11px;
        }
        .af-aqr-fa-item i {
            font-size: 14px;
        }
        .af-aqr-fa-item-name {
            white-space: nowrap;
        }
        .af-aqr-fa-item:hover {
            background: #e5f0ff;
            border-color: #99b;
        }
        </style>';

                    echo '<script type="text/javascript">
        (function() {
            var AF_AQR_FA_ICONS = [
                "fa-solid fa-bold",
                "fa-solid fa-italic",
                "fa-solid fa-underline",
                "fa-solid fa-strikethrough",
                "fa-solid fa-link",
                "fa-solid fa-unlink",
                "fa-solid fa-image",
                "fa-solid fa-photo-film",
                "fa-solid fa-quote-left",
                "fa-solid fa-code",
                "fa-solid fa-list-ul",
                "fa-solid fa-list-ol",
                "fa-solid fa-font",
                "fa-solid fa-text-height",
                "fa-solid fa-palette",
                "fa-solid fa-align-left",
                "fa-solid fa-align-center",
                "fa-solid fa-align-right",
                "fa-solid fa-align-justify",
                "fa-solid fa-eye-slash",
                "fa-solid fa-square-plus",
                "fa-solid fa-square-minus",
                "fa-solid fa-square-caret-down",
                "fa-solid fa-square-caret-right",
                "fa-solid fa-bolt",
                "fa-solid fa-dragon",
                "fa-solid fa-scroll",
                "fa-solid fa-wand-sparkles"
            ];

            function initFaPicker() {
                var input  = document.getElementById("af_aqr_fa_input");
                var toggle = document.getElementById("af_aqr_fa_toggle");
                var picker = document.getElementById("af_aqr_fa_picker");
                var search = document.getElementById("af_aqr_fa_search");
                var list   = document.getElementById("af_aqr_fa_list");

                if (!input || !toggle || !picker || !search || !list) {
                    return;
                }

                function renderIcons(filter) {
                    list.innerHTML = "";
                    var f = (filter || "").toLowerCase();

                    AF_AQR_FA_ICONS.forEach(function(cls) {
                        if (f && cls.toLowerCase().indexOf(f) === -1) {
                            return;
                        }

                        var btn = document.createElement("button");
                        btn.type = "button";
                        btn.className = "af-aqr-fa-item";
                        btn.setAttribute("data-class", cls);
                        btn.title = cls;

                        var icon = document.createElement("i");
                        icon.className = cls;
                        btn.appendChild(icon);

                        var name = document.createElement("span");
                        name.className = "af-aqr-fa-item-name";
                        name.textContent = cls;
                        btn.appendChild(name);

                        btn.addEventListener("click", function(e) {
                            e.preventDefault();
                            input.value = cls;
                        });

                        list.appendChild(btn);
                    });

                    if (!list.children.length) {
                        var empty = document.createElement("div");
                        empty.className = "smalltext";
                        empty.textContent = "Ничего не нашлось по этому запросу.";
                        list.appendChild(empty);
                    }
                }

                toggle.addEventListener("click", function(e) {
                    e.preventDefault();
                    if (picker.style.display === "none" || picker.style.display === "") {
                        picker.style.display = "block";
                        renderIcons(search.value);
                    } else {
                        picker.style.display = "none";
                    }
                });

                search.addEventListener("keyup", function() {
                    renderIcons(search.value);
                });
            }

            if (document.readyState === "loading") {
                document.addEventListener("DOMContentLoaded", initFaPicker);
            } else {
                initFaPicker();
            }
        })();
        </script>';
        }
    }


    

    /**
     * Быстрый togglе enabled для стандартного BB-кода.
     */
    protected static function handle_default_toggle(): void
    {
        global $mybb, $db;

        $tag = trim($mybb->get_input('tag'));
        if ($tag === '') {
            flash_message('Не указан тег BB-кода.', 'error');
            admin_redirect('index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes');
        }

        verify_post_check($mybb->get_input('my_post_key'));

        if (!$db->table_exists('af_aqr_defaultcodes')) {
            $collation = $db->build_create_table_collation();
            $db->write_query("
                CREATE TABLE ".TABLE_PREFIX."af_aqr_defaultcodes (
                    tag            varchar(32)  NOT NULL,
                    title          varchar(100) NOT NULL default '',
                    description    text         NOT NULL,
                    enabled        tinyint(1)   NOT NULL default 1,
                    af_button_icon varchar(255) NOT NULL default '',
                    PRIMARY KEY (tag)
                ) ENGINE=MyISAM {$collation}
            ");
        }

        $row = $db->fetch_array($db->simple_select('af_aqr_defaultcodes', '*', "tag='".$db->escape_string($tag)."'", ['limit' => 1]));
        if ($row) {
            $new = $row['enabled'] ? 0 : 1;
            $db->update_query('af_aqr_defaultcodes', ['enabled' => $new], "tag='".$db->escape_string($tag)."'");
        } else {
            // если записи ещё нет — создаём с enabled=0 (выключаем)
            $db->insert_query('af_aqr_defaultcodes', [
                'tag'            => $db->escape_string($tag),
                'title'          => '',
                'description'    => '',
                'enabled'        => 0,
                'af_button_icon' => '',
            ]);
        }

        flash_message('Статус стандартного BB-кода изменён.', 'success');
        admin_redirect('index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes');
    }


    /* ====== Кастомные BB-коды (форма + удаление) ====== */
    protected static function render_bbcodes_form(int $cid = 0): void
    {
        global $mybb, $db, $page;

        // Проверяем, какие столбцы реально существуют в mycode
        $has_allowhtml      = $db->field_exists('allowhtml', 'mycode');
        $has_allowmycode    = $db->field_exists('allowmycode', 'mycode');
        $has_allowsmilies   = $db->field_exists('allowsmilies', 'mycode');
        $has_allowimgcode   = $db->field_exists('allowimgcode', 'mycode');
        $has_allowvideocode = $db->field_exists('allowvideocode', 'mycode');
        $has_af_button_icon = $db->field_exists('af_button_icon', 'mycode');
        $has_af_fa_icon     = $db->field_exists('af_fa_icon', 'mycode');

        $is_edit = $cid > 0;

        // Базовые значения (на случай отсутствия каких-то колонок)
        $mycode = [
            'title'          => '',
            'description'    => '',
            'regex'          => '',
            'replacement'    => '',
            'active'         => 1,
            'parseorder'     => 0,
            'allowhtml'      => 0,
            'allowmycode'    => 1,
            'allowsmilies'   => 1,
            'allowimgcode'   => 1,
            'allowvideocode' => 1,
            'af_button_icon' => '',
            'af_fa_icon'     => '',
        ];

        if ($is_edit) {
            $row = $db->fetch_array($db->simple_select('mycode', '*', 'cid='.$cid, ['limit' => 1]));
            if (!$row) {
                flash_message('Запрошенный BB-код не найден.', 'error');
                admin_redirect('index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes');
            }
            // merge не перетрёт ключи, которых нет в $row
            $mycode = array_merge($mycode, $row);
        }

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));

            // Базовые поля, которые точно есть в любой нормальной схеме mycode
            $data = [
                'title'       => trim($mybb->get_input('title')),
                'description' => trim($mybb->get_input('description')),
                'regex'       => trim($mybb->get_input('regex')),
                'replacement' => trim($mybb->get_input('replacement')),
                'active'      => (int)$mybb->get_input('active'),
                'parseorder'  => (int)$mybb->get_input('parseorder'),
            ];

            // Дополнительные флаги — только если колонка существует
            if ($has_allowhtml) {
                $data['allowhtml'] = (int)$mybb->get_input('allowhtml');
            }
            if ($has_allowmycode) {
                $data['allowmycode'] = (int)$mybb->get_input('allowmycode');
            }
            if ($has_allowsmilies) {
                $data['allowsmilies'] = (int)$mybb->get_input('allowsmilies');
            }
            if ($has_allowimgcode) {
                $data['allowimgcode'] = (int)$mybb->get_input('allowimgcode');
            }
            if ($has_allowvideocode) {
                $data['allowvideocode'] = (int)$mybb->get_input('allowvideocode');
            }
            if ($has_af_fa_icon) {
                $data['af_fa_icon'] = trim($mybb->get_input('af_fa_icon'));
            }

            if ($data['title'] === '' || $data['regex'] === '' || $data['replacement'] === '') {
                flash_message('Название, регулярное выражение и замена обязательны.', 'error');
            } else {
                // Экранирование перед SQL
                $escaped = [];
                foreach ($data as $k => $v) {
                    $escaped[$k] = is_string($v) ? $db->escape_string($v) : $v;
                }

                if ($is_edit) {
                    $db->update_query('mycode', $escaped, 'cid='.$cid);
                } else {
                    $cid    = (int)$db->insert_query('mycode', $escaped);
                    $is_edit = true;
                }

                // Картинка-иконка — тоже только если колонка есть
                if ($has_af_button_icon) {
                    $icon_value = trim($mybb->get_input('af_button_icon'));

                    if (!empty($_FILES['af_button_icon_file']['name'])) {
                        $upload = $_FILES['af_button_icon_file'];

                        if ($upload['error'] === UPLOAD_ERR_OK && is_uploaded_file($upload['tmp_name'])) {
                            $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
                            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true)) {
                                flash_message('Недопустимый формат иконки. Разрешены: png, jpg, jpeg, gif, webp, svg.', 'error');
                            } else {
                                $icons_dir_fs  = AF_ADDONS.'advancedquickreply/icons/';
                                $icons_dir_rel = 'inc/plugins/advancedfunctionality/addons/advancedquickreply/icons/';

                                if (!is_dir($icons_dir_fs)) {
                                    @mkdir($icons_dir_fs, 0755, true);
                                }

                                $safe_name = 'mycode_'.$cid.'_'.time().'.'.$ext;
                                $dest_fs   = $icons_dir_fs.$safe_name;
                                $dest_rel  = $icons_dir_rel.$safe_name;

                                if (move_uploaded_file($upload['tmp_name'], $dest_fs)) {
                                    $icon_value = $dest_rel;
                                } else {
                                    flash_message('Не удалось сохранить файл иконки на сервере.', 'error');
                                }
                            }
                        }
                    }

                    $db->update_query('mycode', [
                        'af_button_icon' => $db->escape_string($icon_value),
                    ], 'cid='.$cid);
                }

                flash_message($is_edit ? 'BB-код обновлён.' : 'BB-код создан.', 'success');
                admin_redirect('index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes');
            }

            // Если была ошибка — подмешиваем введённые значения обратно
            $mycode = array_merge($mycode, $data);
            if ($has_af_button_icon) {
                $mycode['af_button_icon'] = $mybb->get_input('af_button_icon');
            }
        }

        $title_text = $is_edit ? 'Редактирование BB-кода' : 'Создание BB-кода';
        echo '<h3>'.$title_text.'</h3>';

        $form = new Form(
            'index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes&do='.($is_edit ? 'edit&cid='.$cid : 'add'),
            'post',
            '',
            true
        );
        echo $form->generate_hidden_field('my_post_key', $mybb->post_code);

        $container = new FormContainer($title_text);

        $container->output_row(
            'Название',
            'Отображается в админке в списке MyCode.',
            $form->generate_text_box('title', $mycode['title'], ['style' => 'width: 300px;'])
        );

        $container->output_row(
            'Описание',
            '',
            $form->generate_text_area('description', $mycode['description'], ['rows' => 2, 'style' => 'width: 98%;'])
        );

        $container->output_row(
            'Регулярное выражение (Regex)',
            'Полный PCRE-шаблон, как в стандартном разделе «Дополнительный MyCode». '
            .'Пишется вместе с разделителями и флагами (например, <code>#...#si</code>).<br />'
            .'Примеры:<br />'
            .'<code>#\\[spoiler\\](.*?)\\[/spoiler\\]#si</code> — [spoiler]текст[/spoiler].<br />'
            .'<code>#\\[box=(.*?)\\](.*?)\\[/box\\]#si</code> — [box=заголовок]текст[/box].<br />'
            .'<code>#\\[hr\\]#i</code> — одиночный тег [hr].',
            $form->generate_text_area('regex', $mycode['regex'], ['rows' => 3, 'style' => 'width: 98%; font-family: monospace;'])
        );

        $container->output_row(
            'Замена',
            'Строка, на которую будет заменён найденный текст. Подстановки $1, $2, $3 и т.д. '
            .'соответствуют захваченным группам в регулярном выражении.<br />'
            .'Примеры:<br />'
            .'<code>&lt;div class=&quot;spoiler&quot;&gt;&lt;div class=&quot;spoiler-header&quot;&gt;Спойлер&lt;/div&gt;&lt;div class=&quot;spoiler-body&quot;&gt;$1&lt;/div&gt;&lt;/div&gt;</code><br />'
            .'<code>&lt;div class=&quot;box&quot;&gt;&lt;div class=&quot;box-title&quot;&gt;$1&lt;/div&gt;&lt;div class=&quot;box-body&quot;&gt;$2&lt;/div&gt;&lt;/div&gt;</code>',
            $form->generate_text_area('replacement', $mycode['replacement'], ['rows' => 3, 'style' => 'width: 98%; font-family: monospace;'])
        );

        $container->output_row(
            'Активен',
            '',
            $form->generate_yes_no_radio('active', (int)$mycode['active'])
        );

        $container->output_row(
            'Порядок парсинга',
            'Чем меньше число, тем раньше будет применён этот BB-код. Обычно достаточно оставить 0.',
            $form->generate_text_box('parseorder', (string)(int)$mycode['parseorder'], ['style' => 'width: 80px;'])
        );

        // Флаги — рисуем только если соответствующая колонка есть
        if ($has_allowhtml) {
            $container->output_row(
                'Разрешить HTML',
                '',
                $form->generate_yes_no_radio('allowhtml', (int)$mycode['allowhtml'])
            );
        }

        if ($has_allowmycode) {
            $container->output_row(
                'Разрешить MyCode внутри',
                '',
                $form->generate_yes_no_radio('allowmycode', (int)$mycode['allowmycode'])
            );
        }

        if ($has_allowsmilies) {
            $container->output_row(
                'Разрешить смайлы',
                '',
                $form->generate_yes_no_radio('allowsmilies', (int)$mycode['allowsmilies'])
            );
        }

        if ($has_allowimgcode) {
            $container->output_row(
                'Разрешить [img]',
                '',
                $form->generate_yes_no_radio('allowimgcode', (int)$mycode['allowimgcode'])
            );
        }

        if ($has_allowvideocode) {
            $container->output_row(
                'Разрешить [video]',
                '',
                $form->generate_yes_no_radio('allowvideocode', (int)$mycode['allowvideocode'])
            );
        }

        // Иконка-картинка — тоже только если колонка есть
        if ($has_af_button_icon) {
            $icon_desc = 'Можно указать либо прямую ссылку (URL), либо загрузить файл. '
                       .'Загруженные файлы будут сохранены в <code>inc/plugins/advancedfunctionality/addons/advancedquickreply/icons/</code>.';

            $icon_html = $form->generate_text_box('af_button_icon', $mycode['af_button_icon'], ['style' => 'width: 60%;']).'<br /><br />'
                       . 'Загрузить новую иконку: '.$form->generate_file_upload_box('af_button_icon_file');

            if (!empty($mycode['af_button_icon'])) {
                $safe_icon = htmlspecialchars_uni($mycode['af_button_icon']);
                if (preg_match('#\.(png|jpe?g|gif|webp|svg)$#i', $mycode['af_button_icon'])) {
                    $icon_html .= '<br /><br />Текущая картинка-иконка:<br /><img src="'.$safe_icon.'" alt="" style="max-height:40px;max-width:120px;border:1px solid #ccc;padding:2px;" />';
                } else {
                    $icon_html .= '<br /><br />Текущее значение: <code>'.$safe_icon.'</code>';
                }
            }

            $container->output_row(
                'Иконка BB-кода (картинка)',
                $icon_desc,
                $icon_html
            );
        }

        // Font Awesome — только если колонка есть
        if ($has_af_fa_icon) {
            $fa_desc = 'Класс Font Awesome для иконки на кнопке. Например: <code>fa-solid fa-dragon</code> или <code>fas fa-bolt</code>.<br />'
                     .'Используется, только если на фронте подключён Font Awesome. '
                     .'Если указано значение здесь, картинка-иконка будет проигнорирована.';

            $fa_html  = '<div class="af-aqr-fa-block">';
            $fa_html .= $form->generate_text_box('af_fa_icon', $mycode['af_fa_icon'], [
                'style' => 'width: 60%;',
                'id'    => 'af_aqr_fa_input',
            ]);
            if (!empty($mycode['af_fa_icon'])) {
                $fa_html .= '<br /><br />Текущее значение: <code>'.htmlspecialchars_uni($mycode['af_fa_icon']).'</code>';
            }
            $fa_html .= '<br /><br /><a href="#" class="button" id="af_aqr_fa_toggle">Открыть подбор иконок</a>';
            $fa_html .= '<div id="af_aqr_fa_picker" class="af-aqr-fa-picker" style="display:none;margin-top:10px;">'
                      . '   <input type="text" id="af_aqr_fa_search" class="af-aqr_fa-search" '
                      . '          placeholder="Поиск по названию иконки..." style="width:60%;" />'
                      . '   <div id="af_aqr_fa_list" class="af-aqr-fa-list" style="margin-top:8px;"></div>'
                      . '</div>';
            $fa_html .= '</div>';

            $container->output_row(
                'Иконка BB-кода (Font Awesome)',
                $fa_desc,
                $fa_html
            );
        }

        $container->end();

        $buttons = [];
        $buttons[] = $form->generate_submit_button($is_edit ? 'Сохранить изменения' : 'Создать BB-код');
        $form->output_submit_wrapper($buttons);

        $form->end();

        // Общий JS/CSS для FA-подборщика — только один раз
        if ($has_af_fa_icon && !defined('AF_AQR_FA_JS')) {
            define('AF_AQR_FA_JS', 1);

            echo '<style type="text/css">
        .af-aqr-fa-picker {
            border: 1px solid #ccc;
            background: #fff;
            padding: 8px;
            max-height: 260px;
            overflow: auto;
        }
        .af-aqr-fa-list {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 6px;
        }
        .af-aqr-fa-item {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 3px 6px;
            border: 1px solid #ddd;
            background: #f7f7f7;
            cursor: pointer;
            font-size: 11px;
        }
        .af-aqr-fa-item i {
            font-size: 14px;
        }
        .af-aqr-fa-item-name {
            white-space: nowrap;
        }
        .af-aqr-fa-item:hover {
            background: #e5f0ff;
            border-color: #99b;
        }
        </style>';

            echo '<script type="text/javascript">
        (function() {
            var AF_AQR_FA_ICONS = [
                "fa-solid fa-bold",
                "fa-solid fa-italic",
                "fa-solid fa-underline",
                "fa-solid fa-strikethrough",
                "fa-solid fa-link",
                "fa-solid fa-unlink",
                "fa-solid fa-image",
                "fa-solid fa-photo-film",
                "fa-solid fa-quote-left",
                "fa-solid fa-code",
                "fa-solid fa-list-ul",
                "fa-solid fa-list-ol",
                "fa-solid fa-font",
                "fa-solid fa-text-height",
                "fa-solid fa-palette",
                "fa-solid fa-align-left",
                "fa-solid fa-align-center",
                "fa-solid fa-align-right",
                "fa-solid fa-align-justify",
                "fa-solid fa-eye-slash",
                "fa-solid fa-square-plus",
                "fa-solid fa-square-minus",
                "fa-solid fa-square-caret-down",
                "fa-solid fa-square-caret-right",
                "fa-solid fa-bolt",
                "fa-solid fa-dragon",
                "fa-solid fa-scroll",
                "fa-solid fa-wand-sparkles"
            ];

            function initFaPicker() {
                var input  = document.getElementById("af_aqr_fa_input");
                var toggle = document.getElementById("af_aqr_fa_toggle");
                var picker = document.getElementById("af_aqr_fa_picker");
                var search = document.getElementById("af_aqr_fa_search");
                var list   = document.getElementById("af_aqr_fa_list");

                if (!input || !toggle || !picker || !search || !list) {
                    return;
                }

                function renderIcons(filter) {
                    list.innerHTML = "";
                    var f = (filter || "").toLowerCase();

                    AF_AQR_FA_ICONS.forEach(function(cls) {
                        if (f && cls.toLowerCase().indexOf(f) === -1) {
                            return;
                        }

                        var btn = document.createElement("button");
                        btn.type = "button";
                        btn.className = "af-aqr-fa-item";
                        btn.setAttribute("data-class", cls);
                        btn.title = cls;

                        var icon = document.createElement("i");
                        icon.className = cls;
                        btn.appendChild(icon);

                        var name = document.createElement("span");
                        name.className = "af-aqr-fa-item-name";
                        name.textContent = cls;
                        btn.appendChild(name);

                        btn.addEventListener("click", function(e) {
                            e.preventDefault();
                            input.value = cls;
                        });

                        list.appendChild(btn);
                    });

                    if (!list.children.length) {
                        var empty = document.createElement("div");
                        empty.className = "smalltext";
                        empty.textContent = "Ничего не нашлось по этому запросу.";
                        list.appendChild(empty);
                    }
                }

                toggle.addEventListener("click", function(e) {
                    e.preventDefault();
                    if (picker.style.display === "none" || picker.style.display === "") {
                        picker.style.display = "block";
                        renderIcons(search.value);
                    } else {
                        picker.style.display = "none";
                    }
                });

                search.addEventListener("keyup", function() {
                    renderIcons(search.value);
                });
            }

            if (document.readyState === "loading") {
                document.addEventListener("DOMContentLoaded", initFaPicker);
            } else {
                initFaPicker();
            }
        })();
        </script>';
        }
    }


    /**
     * Удаление кастомного BB-кода.
     */
    protected static function handle_bbcodes_delete(): void
    {
        global $mybb, $db;

        $cid = (int)$mybb->get_input('cid');
        if ($cid <= 0) {
            flash_message('Некорректный идентификатор BB-кода.', 'error');
            admin_redirect('index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes');
        }

        verify_post_check($mybb->get_input('my_post_key'));

        $db->delete_query('mycode', 'cid='.$cid);

        flash_message('BB-код удалён.', 'success');
        admin_redirect('index.php?module=advancedfunctionality&af_view=advancedquickreply&tab=bbcodes');
    }
}

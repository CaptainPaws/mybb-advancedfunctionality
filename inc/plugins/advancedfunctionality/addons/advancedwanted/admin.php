<?php
if (!defined('IN_MYBB') || !defined('IN_ADMINCP')) {
    die('No direct access');
}

class AF_Admin_Advancedwanted
{
    private const FIELD_TYPES = [
        'text', 'textarea', 'url', 'image', 'number', 'select', 'multi',
        'checkbox', 'radio', 'kb_dynamic',
    ];

    public static function dispatch(string $action = ''): string
    {
        $html = self::render();
        echo $html;
        return $html;
    }

    public static function render(): string
    {
        global $db, $mybb;

        $tab = in_array($mybb->get_input('tab'), ['entries', 'fields', 'settings'], true)
            ? $mybb->get_input('tab') : 'entries';
        $fieldErrors = [];
        $submittedField = null;

        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));
            $do = $mybb->get_input('do');
            if ($do === 'save_field') {
                $tab = 'fields';
                $id = (int)$mybb->get_input('id');
                [$submittedField, $fieldErrors] = self::fieldFromRequest($id);
                if (!$fieldErrors) {
                    $data = self::fieldDatabaseData($submittedField);
                    if (!empty($submittedField['settings']['is_title'])) {
                        // A schema has exactly one semantic title field.
                        foreach (af_wanted_fields(false) as $other) {
                            if ((int)$other['id'] === $id || empty($other['settings']['is_title'])) continue;
                            $other['settings']['is_title'] = false;
                            $db->update_query(AF_WANTED_FIELDS, ['settings_json' => $db->escape_string(json_encode($other['settings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))], 'id=' . (int)$other['id']);
                        }
                    }
                    if ($id > 0) {
                        $db->update_query(AF_WANTED_FIELDS, $data, 'id=' . $id);
                        flash_message('Поле успешно изменено.', 'success');
                    } else {
                        $db->insert_query(AF_WANTED_FIELDS, $data);
                        flash_message('Поле успешно создано.', 'success');
                    }
                    admin_redirect(self::fieldsUrl());
                }
            } elseif ($do === 'delete_field') {
                self::deleteField((int)$mybb->get_input('id'));
                admin_redirect(self::fieldsUrl());
            } elseif ($do === 'delete_entry') {
                $id = (int)$mybb->get_input('id');
                $db->delete_query(AF_WANTED_VALUES, 'wanted_id=' . $id);
                $db->delete_query(AF_WANTED_ENTRIES, 'id=' . $id);
                admin_redirect('index.php?module=advancedfunctionality&af_view=advancedwanted');
            } elseif ($do === 'save_reservation') {
                $id = (int)$mybb->get_input('id');
                $entry = (array)$db->fetch_array($db->simple_select(AF_WANTED_ENTRIES, '*', 'id=' . $id, ['limit' => 1]));
                $mode = (string)$mybb->get_input('reservation_owner_type');
                $owner = trim((string)$mybb->get_input('reservation_owner'));
                $date = trim((string)$mybb->get_input('reserved_until'));
                $until = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ? strtotime($date . ' 23:59:59') : false;
                $reservedUid = null;
                $guestName = '';
                if (!$entry || !in_array($mode, ['user', 'guest'], true) || !$until || $until <= TIME_NOW) {
                    flash_message('Укажите владельца и будущую дату окончания брони.', 'error');
                    admin_redirect('index.php?module=advancedfunctionality&af_view=advancedwanted');
                }
                if ($mode === 'user') {
                    $userWhere = ctype_digit($owner) ? 'uid=' . (int)$owner : "username='" . $db->escape_string($owner) . "'";
                    $reservedUid = (int)$db->fetch_field($db->simple_select('users', 'uid', $userWhere, ['limit' => 1]), 'uid');
                    if ($reservedUid < 1) {
                        flash_message('Пользователь не найден.', 'error');
                        admin_redirect('index.php?module=advancedfunctionality&af_view=advancedwanted');
                    }
                } else {
                    $guestName = $owner;
                    if ($guestName === '' || my_strlen($guestName) > 190) {
                        flash_message('Укажите имя гостя длиной до 190 символов.', 'error');
                        admin_redirect('index.php?module=advancedfunctionality&af_view=advancedwanted');
                    }
                }
                $db->update_query(AF_WANTED_ENTRIES, [
                    'status' => 'reserved', 'reserved_by_uid' => $reservedUid,
                    'reserved_guest_name' => $db->escape_string($guestName),
                    'reserved_at' => (int)($entry['reserved_at'] ?: TIME_NOW),
                    'reserved_until' => (int)$until, 'updated_at' => TIME_NOW,
                ], 'id=' . $id . ' AND application_tid IS NULL');
                $saved = (int)$db->affected_rows() === 1;
                flash_message($saved ? 'Бронь обновлена.' : 'Бронь нельзя изменить при активной анкете.', $saved ? 'success' : 'error');
                admin_redirect('index.php?module=advancedfunctionality&af_view=advancedwanted');
            } elseif (in_array($do, ['release_reservation', 'return_active', 'archive_entry'], true)) {
                $id = (int)$mybb->get_input('id');
                $action = $do === 'archive_entry' ? 'archive' : $do;
                $where = 'id=' . $id;
                if ($do === 'release_reservation') {
                    $where .= " AND status='reserved'";
                }
                $db->update_query(AF_WANTED_ENTRIES, af_wanted_lifecycle_data($action), $where);
                if ((int)$db->affected_rows() === 1) {
                    flash_message('Lifecycle Wanted обновлён.', 'success');
                } else {
                    flash_message('Действие неприменимо: запись уже изменена или не найдена.', 'error');
                }
                admin_redirect('index.php?module=advancedfunctionality&af_view=advancedwanted');
            }
        }

        $base = 'index.php?module=advancedfunctionality&af_view=advancedwanted';
        $html = '<h1>AdvancedWanted</h1><p><a href="' . $base . '&amp;tab=entries">Записи</a> | '
            . '<a href="' . $base . '&amp;tab=fields">Поля формы</a> | '
            . '<a href="' . $base . '&amp;tab=settings">Настройки</a></p>';
        if ($tab === 'settings') {
            return $html . '<p>Права групп, forum ID и pagination настраиваются в Configuration → Settings → AdvancedWanted.</p>';
        }
        if ($tab === 'fields') {
            return $html . self::renderFields($base, $submittedField, $fieldErrors);
        }
        return $html . self::renderEntries();
    }

    private static function fieldFromRequest(int $id): array
    {
        global $db, $mybb;

        $title = trim((string)$mybb->get_input('title'));
        $key = strtolower(trim((string)$mybb->get_input('field_key')));
        $type = (string)$mybb->get_input('type');
        $errors = [];
        if ($id > 0 && !$db->fetch_field($db->simple_select(AF_WANTED_FIELDS, 'id', 'id=' . $id, ['limit' => 1]), 'id')) {
            $errors[] = 'Редактируемое поле не найдено.';
        }
        if ($title === '') {
            $errors[] = 'Название поля обязательно.';
        }
        if ($key === '') {
            $errors[] = 'Ключ поля обязателен.';
        } elseif (!preg_match('/^[a-z0-9_]{1,64}$/', $key)) {
            $errors[] = 'Ключ может содержать только строчные латинские буквы, цифры и _. Максимальная длина — 64 символа.';
        }
        if (!in_array($type, self::FIELD_TYPES, true)) {
            $errors[] = 'Выбран недопустимый тип поля.';
        }
        $source = trim((string)$mybb->get_input('source'));
        $dependsOn = trim((string)$mybb->get_input('depends_on'));
        $atfFieldKey = trim((string)$mybb->get_input('atf_field_key'));
        if ($type === 'kb_dynamic' && !array_key_exists($source, af_wanted_kb_source_map())) {
            $errors[] = 'Для KB dynamic выберите поддерживаемый source.';
        }
        if ($type === 'kb_dynamic' && $source === 'origin_variant' && $dependsOn === '') {
            $errors[] = 'Для разновидности происхождения укажите Depends on (обычно origin).';
        }
        if ($atfFieldKey !== '') {
            $available = self::atfFieldOptions($type);
            if (!array_key_exists($atfFieldKey, $available)) {
                $errors[] = 'Выбранное поле ATF не существует, неактивно, недоступно форуму анкет или несовместимо по типу.';
            }
        }
        if ($key !== '' && preg_match('/^[a-z0-9_]{1,64}$/', $key)) {
            $where = "field_key='" . $db->escape_string($key) . "'" . ($id > 0 ? ' AND id!=' . $id : '');
            if ($db->fetch_field($db->simple_select(AF_WANTED_FIELDS, 'id', $where, ['limit' => 1]), 'id')) {
                $errors[] = 'Поле с ключом «' . $key . '» уже существует. Укажите уникальный ключ.';
            }
        }

        $options = [];
        foreach (preg_split('/\R/u', trim((string)$mybb->get_input('options'))) as $line) {
            if ($line === '') {
                continue;
            }
            if (strpos($line, '=') === false) {
                $errors[] = 'Каждая опция должна иметь формат key=Название.';
                continue;
            }
            [$optionKey, $label] = array_map('trim', explode('=', $line, 2));
            if ($optionKey === '' || $label === '') {
                $errors[] = 'Ключ и название опции не могут быть пустыми.';
                continue;
            }
            $options[$optionKey] = $label;
        }
        $field = [
            'id' => $id,
            'title' => $title,
            'field_key' => $key,
            'type' => $type,
            'required' => self::checked('required'),
            'active' => self::checked('active'),
            'sortorder' => (int)$mybb->get_input('sortorder'),
            'settings' => [
                'source' => $source,
                'depends_on' => $dependsOn,
                'options' => $options,
                'filterable' => (bool)self::checked('filterable'),
                'show_card' => (bool)self::checked('show_card'),
                'show_detail' => (bool)self::checked('show_detail'),
                'is_title' => (bool)self::checked('is_title'),
                'atf_field_key' => $atfFieldKey,
            ],
        ];
        return [$field, array_values(array_unique($errors))];
    }

    private static function checked(string $name): int
    {
        global $mybb;
        return (int)((string)$mybb->get_input($name) === '1');
    }

    private static function fieldDatabaseData(array $field): array
    {
        global $db;
        return [
            'field_key' => $db->escape_string($field['field_key']),
            'title' => $db->escape_string($field['title']),
            'type' => $field['type'],
            'required' => (int)$field['required'],
            'active' => (int)$field['active'],
            'sortorder' => (int)$field['sortorder'],
            'settings_json' => $db->escape_string(json_encode($field['settings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }

    private static function deleteField(int $id): void
    {
        global $db;
        if ($id < 1 || !$db->fetch_field($db->simple_select(AF_WANTED_FIELDS, 'id', 'id=' . $id, ['limit' => 1]), 'id')) {
            flash_message('Поле не найдено.', 'error');
            return;
        }
        $used = (int)$db->fetch_field($db->simple_select(AF_WANTED_VALUES, 'COUNT(*) AS total', 'field_id=' . $id), 'total');
        if ($used > 0) {
            flash_message('Поле используется в записях Wanted и не может быть удалено.', 'error');
            return;
        }
        $db->delete_query(AF_WANTED_FIELDS, 'id=' . $id);
        flash_message('Поле удалено.', 'success');
    }

    private static function renderFields(string $base, ?array $submitted, array $errors): string
    {
        global $db, $mybb;
        ob_start();
        $table = new Table;
        $table->construct_header('Название');
        $table->construct_header('Ключ');
        $table->construct_header('Тип', ['width' => '12%']);
        $table->construct_header('Обязательно', ['class' => 'align_center', 'width' => '10%']);
        $table->construct_header('Активно', ['class' => 'align_center', 'width' => '8%']);
        $table->construct_header('Порядок', ['class' => 'align_center', 'width' => '8%']);
        $table->construct_header('Действия', ['class' => 'align_center', 'width' => '15%']);
        $hasFields = false;
        foreach (af_wanted_fields(false) as $field) {
            $hasFields = true;
            $id = (int)$field['id'];
            $actions = '<a href="' . $base . '&amp;tab=fields&amp;do=field_edit&amp;id=' . $id . '">Изменить</a> | '
                . '<form method="post" action="' . $base . '&amp;tab=fields" style="display:inline">'
                . '<input type="hidden" name="my_post_key" value="' . self::h($mybb->post_code) . '">'
                . '<input type="hidden" name="do" value="delete_field"><input type="hidden" name="id" value="' . $id . '">'
                . '<button class="button button_danger" type="submit">Удалить</button></form>';
            $table->construct_cell(self::h($field['title']));
            $table->construct_cell(self::h($field['field_key']));
            $table->construct_cell(self::h($field['type']));
            $table->construct_cell($field['required'] ? 'Да' : 'Нет', ['class' => 'align_center']);
            $table->construct_cell($field['active'] ? 'Да' : 'Нет', ['class' => 'align_center']);
            $table->construct_cell((int)$field['sortorder'], ['class' => 'align_center']);
            $table->construct_cell($actions, ['class' => 'align_center']);
            $table->construct_row();
        }
        if (!$hasFields) {
            $table->construct_cell('Поля пока не созданы.', ['colspan' => 7]);
            $table->construct_row();
        }
        $table->output('Поля формы');
        echo '<div style="margin-top:10px"><a class="button button_primary" href="' . $base
            . '&amp;tab=fields&amp;do=field_add">Добавить поле</a></div>';

        $do = (string)$mybb->get_input('do');
        $edit = (int)($mybb->get_input('id') ?: $mybb->get_input('edit'));
        $showEditor = $submitted !== null || $do === 'field_add' || $do === 'field_edit' || $edit > 0;
        if (!$showEditor) {
            return (string)ob_get_clean();
        }
        $field = $submitted;
        if ($field === null && $edit > 0) {
            $field = (array)$db->fetch_array($db->simple_select(AF_WANTED_FIELDS, '*', 'id=' . $edit, ['limit' => 1]));
            if ($field) {
                $field['settings'] = json_decode((string)$field['settings_json'], true) ?: [];
            }
        }
        $field = $field ?: ['id' => 0, 'title' => '', 'field_key' => '', 'type' => 'text', 'required' => 0, 'active' => 1, 'sortorder' => 0, 'settings' => []];
        $settings = (array)($field['settings'] ?? []);
        $options = '';
        foreach ((array)($settings['options'] ?? []) as $key => $label) {
            $options .= $key . '=' . $label . "\n";
        }
        if ($errors) {
            echo '<div class="error"><p><strong>Поле не сохранено:</strong></p><ul>';
            foreach ($errors as $error) {
                echo '<li>' . self::h($error) . '</li>';
            }
            echo '</ul></div>';
        }
        $id = (int)($field['id'] ?? 0);
        $form = new Form($base . '&tab=fields', 'post');
        echo $form->generate_hidden_field('my_post_key', $mybb->post_code);
        echo $form->generate_hidden_field('do', 'save_field');
        echo $form->generate_hidden_field('id', $id);

        $main = new Table;
        self::row($main, 'Название', $form->generate_text_box('title', $field['title'], ['maxlength' => 255]));
        self::row($main, 'Ключ', $form->generate_text_box('field_key', $field['field_key'], ['maxlength' => 64])
            . '<div class="smalltext">Уникальный machine key. Например: <code>origin</code>, <code>faction</code>, <code>description</code>.</div>');
        $types = array_combine(self::FIELD_TYPES, self::FIELD_TYPES);
        self::row($main, 'Тип', $form->generate_select_box('type', $types, $field['type']));
        self::row($main, 'Порядок', $form->generate_numeric_field('sortorder', (int)$field['sortorder']));
        $main->output('Основные параметры');

        $source = new Table;
        self::row($source, 'Источник вариантов', $form->generate_select_box('source', self::sourceOptions(), (string)($settings['source'] ?? '')));
        self::row($source, 'Зависит от поля', $form->generate_select_box('depends_on', self::dependencyOptions($id), (string)($settings['depends_on'] ?? '')));
        self::row($source, 'Варианты значений', $form->generate_text_area('options', $options, ['rows' => 7])
            . '<div class="smalltext">По одному варианту на строку в формате <code>key=Название</code>. Например: <code>male=Мужской</code>.</div>');
        $source->output('Источник данных');

        $integration = new Table;
        self::row($integration, 'Поле ATF для предзаполнения', $form->generate_select_box('atf_field_key', self::atfFieldOptions((string)$field['type']), (string)($settings['atf_field_key'] ?? ''))
            . '<div class="smalltext">Показываются активные поля реальной схемы ATF для настроенного форума анкет и только совместимые типы. Значение копируется один раз и остаётся редактируемым.</div>');
        $integration->output('Интеграция с ATF');

        $behavior = new Table;
        self::row($behavior, 'Активно', $form->generate_check_box('active', '1', 'Поле доступно в форме', ['checked' => !empty($field['active'])]));
        self::row($behavior, 'Обязательное поле', $form->generate_check_box('required', '1', 'Требовать заполнение', ['checked' => !empty($field['required'])]));
        self::row($behavior, 'Использовать как имя / title', $form->generate_check_box('is_title', '1', 'Основной заголовок Wanted (может быть только один)', ['checked' => !empty($settings['is_title'])]));
        self::row($behavior, 'Фильтрация', $form->generate_check_box('filterable', '1', 'Можно использовать как фильтр', ['checked' => !empty($settings['filterable'])]));
        $behavior->output('Поведение');

        $display = new Table;
        self::row($display, 'Карточка', $form->generate_check_box('show_card', '1', 'Показывать в карточке', ['checked' => !empty($settings['show_card'])]));
        self::row($display, 'Страница записи', $form->generate_check_box('show_detail', '1', 'Показывать на странице записи', ['checked' => !empty($settings['show_detail'])]));
        $display->construct_cell(
            $form->generate_submit_button($id ? 'Сохранить изменения' : 'Сохранить поле', ['class' => 'button button_primary'])
            . ' <a class="button" href="' . $base . '&amp;tab=fields">Отмена</a>',
            ['colspan' => 2, 'class' => 'align_center']
        );
        $display->construct_row();
        $display->output('Отображение');
        echo $form->end();
        echo self::fieldEditorScript();
        return (string)ob_get_clean();
    }

    private static function sourceOptions(): array
    {
        $labels = ['origin' => 'Происхождение', 'origin_variant' => 'Разновидность происхождения', 'archetype' => 'Архетип',
            'faction' => 'Фракция', 'element' => 'Элемент', 'weapon' => 'Тип оружия', 'gender' => 'Пол'];
        $options = ['' => 'Не выбран'];
        foreach (af_wanted_kb_source_map() as $key => $_config) {
            $options[$key] = $labels[$key] ?? $key;
        }
        return $options;
    }

    private static function dependencyOptions(int $editingId): array
    {
        $options = ['' => 'Не зависит'];
        foreach (af_wanted_fields(true) as $field) {
            if ((int)$field['id'] !== $editingId) {
                $options[$field['field_key']] = $field['title'] . ' (' . $field['field_key'] . ')';
            }
        }
        return $options;
    }

    private static function atfFieldOptions(string $wantedType): array
    {
        $options = ['' => 'Не синхронизировать'];
        foreach (af_wanted_atf_fields() as $field) {
            $key = trim((string)($field['name'] ?? ''));
            $type = trim((string)($field['type'] ?? ''));
            if ($key === '' || !af_wanted_mapping_types_compatible($wantedType, $type)) continue;
            $title = trim((string)($field['title'] ?? ''));
            $options[$key] = ($title !== '' ? $title : $key) . ' (' . $key . ')';
        }
        return $options;
    }

    private static function row(Table $table, string $label, string $content): void
    {
        $table->construct_cell($label, ['width' => '25%']);
        $table->construct_cell($content);
        $table->construct_row();
    }

    private static function fieldEditorScript(): string
    {
        return '<script>document.addEventListener("DOMContentLoaded",function(){var type=document.querySelector("select[name=type]");'
            . 'if(!type)return;var rows={source:document.querySelector("select[name=source]").closest("tr"),depends:document.querySelector("select[name=depends_on]").closest("tr"),options:document.querySelector("textarea[name=options]").closest("tr")};'
            . 'function updateWantedFieldSettings(){var value=type.value,stat=["select","multi","radio"].indexOf(value)!==-1,dyn=value==="kb_dynamic";rows.source.style.display=dyn?"":"none";rows.depends.style.display=dyn?"":"none";rows.options.style.display=stat?"":"none";}type.addEventListener("change",updateWantedFieldSettings);updateWantedFieldSettings();});</script>';
    }

    private static function renderEntries(): string
    {
        global $db, $mybb;
        af_wanted_normalize_expired_reservations();
        $html = '<table class="general"><tr><th>ID</th><th>Автор</th><th>Статус</th><th>Бронь</th><th>TID</th><th>Accepted UID</th><th>Создано</th><th>Действия</th></tr>';
        $query = $db->write_query('SELECT e.*,u.username,ru.username reserved_name FROM ' . TABLE_PREFIX . AF_WANTED_ENTRIES . ' e LEFT JOIN ' . TABLE_PREFIX . 'users u ON u.uid=e.author_uid LEFT JOIN ' . TABLE_PREFIX . 'users ru ON ru.uid=e.reserved_by_uid ORDER BY e.id DESC LIMIT 100');
        while ($entry = $db->fetch_array($query)) {
            $reservation = self::reservationEditor($entry);
            $html .= '<tr><td>' . (int)$entry['id'] . '</td><td>' . self::h($entry['username']) . '</td><td>' . self::h($entry['status']) . '</td>'
                . '<td>' . $reservation . '</td><td>' . (int)$entry['application_tid'] . '</td><td>' . (int)$entry['accepted_uid'] . '</td><td>' . my_date('relative', $entry['created_at']) . '</td><td>'
                . self::lifecycleActions($entry)
                . '<form method="post"><input type="hidden" name="my_post_key" value="' . self::h($mybb->post_code) . '">'
                . '<input type="hidden" name="do" value="delete_entry"><input type="hidden" name="id" value="' . (int)$entry['id'] . '"><button type="submit">Удалить</button></form></td></tr>';
        }
        return $html . '</table>';
    }

    private static function lifecycleActions(array $entry): string
    {
        global $mybb;
        $buttons = [];
        if ($entry['status'] === 'reserved') {
            $buttons['release_reservation'] = 'Снять бронь';
        }
        if ($entry['status'] !== 'open') {
            $buttons['return_active'] = 'Вернуть в Active';
        }
        if ($entry['status'] !== 'archived') {
            $buttons['archive_entry'] = 'Отправить в Archive';
        }
        $html = '';
        foreach ($buttons as $action => $label) {
            $html .= '<form method="post"><input type="hidden" name="my_post_key" value="' . self::h($mybb->post_code) . '">'
                . '<input type="hidden" name="do" value="' . $action . '"><input type="hidden" name="id" value="' . (int)$entry['id'] . '">'
                . '<button type="submit">' . $label . '</button></form>';
        }
        return $html;
    }

    private static function reservationEditor(array $entry): string
    {
        global $mybb;
        if ($entry['status'] !== 'reserved' || !empty($entry['application_tid'])) return '—';
        $isUser = (int)$entry['reserved_by_uid'] > 0;
        $owner = $isUser ? (string)$entry['reserved_name'] : (string)$entry['reserved_guest_name'];
        $date = !empty($entry['reserved_until']) ? date('Y-m-d', (int)$entry['reserved_until']) : '';
        return '<form method="post" class="af-wanted-admin-reservation">'
            . '<input type="hidden" name="my_post_key" value="' . self::h($mybb->post_code) . '">'
            . '<input type="hidden" name="do" value="save_reservation"><input type="hidden" name="id" value="' . (int)$entry['id'] . '">'
            . '<label>Тип <select name="reservation_owner_type"><option value="user"' . ($isUser ? ' selected' : '') . '>Пользователь</option><option value="guest"' . (!$isUser ? ' selected' : '') . '>Гость</option></select></label> '
            . '<label>Пользователь / гость <input name="reservation_owner" value="' . self::h($owner) . '" required></label> '
            . '<label>Придержано до: <input type="date" name="reserved_until" value="' . self::h($date) . '" required></label> '
            . '<button type="submit">Сохранить бронь</button></form>';
    }

    private static function fieldsUrl(): string
    {
        return 'index.php?module=advancedfunctionality&af_view=advancedwanted&tab=fields';
    }

    private static function input(string $name, string $label, $value): string
    {
        return '<label>' . $label . ' <input name="' . $name . '" value="' . self::h((string)$value) . '"></label>';
    }

    private static function h(string $value): string
    {
        return htmlspecialchars_uni($value);
    }
}

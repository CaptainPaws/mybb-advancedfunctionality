<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

// Фолбэк: если по какой-то причине не подключён advancedalerts.php
// и глобальный хелпер не определён — определяем здесь.
if (!function_exists('af_advancedalerts_unregister_type')) {
    /**
     * Удаление типа уведомлений по коду:
     *  - находим type_id;
     *  - чистим alert_settings.type_id;
     *  - чистим alerts.type_id;
     *  - удаляем запись из alert_types.
     */
    function af_advancedalerts_unregister_type(string $code): void
    {
        global $db;

        $code = trim($code);
        if ($code === '') {
            return;
        }

        // Находим ID типа
        $row = $db->fetch_array(
            $db->simple_select(
                'alert_types',
                'id',
                "code='".$db->escape_string($code)."'", 
                ['limit' => 1]
            )
        );

        if (!$row) {
            return;
        }

        $type_id = (int)$row['id'];
        if ($type_id <= 0) {
            return;
        }

        // Таблицы могут отсутствовать, так что аккуратно
        if ($db->table_exists('alert_settings')) {
            // В НАШЕЙ схеме колонка называется type_id
            $db->delete_query('alert_settings', "type_id={$type_id}");
        }

        if ($db->table_exists('alerts')) {
            $db->delete_query('alerts', "type_id={$type_id}");
        }

        $db->delete_query('alert_types', "id={$type_id}");
    }
}


/**
 * AF Admin Controller: Advanced Alerts
 * URL: /admin/index.php?module=advancedfunctionality&af_view=AdvancedAlerts
 *
 * ВАЖНО:
 *  - Шапку, левое меню и breadcrumbs выводит AF-роутер.
 *  - Здесь НЕЛЬЗЯ вызывать $page->output_header() / output_footer(),
 *    иначе всё дублируется.
 */
class AF_Admin_AdvancedAlerts
{
    public static function dispatch()
    {
        global $mybb, $lang, $page;

        // Пытаемся загрузить админский файл языка
        $lang->load('advancedfunctionality_advancedalerts', true, true);

        // Если язык по каким-то причинам не подхватился или в файле нет нужных ключей —
        // забиваем дефолтные строки, чтобы интерфейс не был пустым.
        if (!isset($lang->af_advancedalerts_admin_tab_types) || $lang->af_advancedalerts_admin_tab_types === '') {
            $lang->af_advancedalerts_admin_breadcrumb        = 'Advanced Alerts';
            $lang->af_advancedalerts_admin_title             = 'Advanced Alerts — типы уведомлений';
            $lang->af_advancedalerts_admin_title_edit        = 'Advanced Alerts — редактирование типа';

            $lang->af_advancedalerts_admin_tab_types         = 'Типы уведомлений';
            $lang->af_advancedalerts_admin_tab_edit          = 'Редактирование';

            $lang->af_advancedalerts_admin_col_code          = 'Код';
            $lang->af_advancedalerts_admin_col_title         = 'Название';
            $lang->af_advancedalerts_admin_col_enabled       = 'Включено';
            $lang->af_advancedalerts_admin_col_user_disable  = 'Можно отключить в UCP';
            $lang->af_advancedalerts_admin_col_actions       = 'Действия';

            $lang->af_advancedalerts_admin_table_title       = 'Зарегистрированные типы уведомлений';

            $lang->af_advancedalerts_admin_add_legend        = 'Добавить новый тип уведомлений';
            $lang->af_advancedalerts_admin_field_code        = 'Код типа';
            $lang->af_advancedalerts_admin_field_code_desc   = 'Уникальный системный идентификатор (латиница и подчёркивания), например subscribed_thread.';
            $lang->af_advancedalerts_admin_field_title       = 'Название';
            $lang->af_advancedalerts_admin_field_title_desc  = 'Человеко-читаемое название типа уведомления (для UCP и админки).';
            $lang->af_advancedalerts_admin_field_enabled     = 'Включено по умолчанию';
            $lang->af_advancedalerts_admin_field_can_disable = 'Пользователь может отключить в UCP';

            $lang->af_advancedalerts_admin_add_button        = 'Добавить тип';

            $lang->af_advancedalerts_admin_error_code_empty  = 'Не указан код типа уведомления.';
            $lang->af_advancedalerts_admin_error_not_found   = 'Тип уведомления не найден.';

            $lang->af_advancedalerts_admin_edit_legend       = 'Редактировать тип уведомления';
            $lang->af_advancedalerts_admin_save_button       = 'Сохранить изменения';

            $lang->af_advancedalerts_admin_confirm_delete    = 'Удалить этот тип уведомлений? Пользовательские настройки для него также будут удалены.';

            $lang->af_advancedalerts_admin_msg_added         = 'Тип уведомления добавлен.';
            $lang->af_advancedalerts_admin_msg_updated       = 'Тип уведомления обновлён.';
            $lang->af_advancedalerts_admin_msg_deleted       = 'Тип уведомления удалён.';

            $lang->af_advancedalerts_admin_empty             = 'Типов уведомлений пока нет.';

            $lang->af_advancedalerts_admin_action_edit       = 'Редактировать';
            $lang->af_advancedalerts_admin_action_delete     = 'Удалить';
        }

        // Классы форм / таблиц (на случай, если MyBB их не подгрузил)
        if (!class_exists('Table')) {
            require_once MYBB_ROOT . MYBB_ADMIN_DIR . '/inc/class_table.php';
        }
        if (!class_exists('Form')) {
            require_once MYBB_ROOT . MYBB_ADMIN_DIR . '/inc/class_form.php';
        }

        $sub = $mybb->get_input('af_action');
        if ($sub === '') {
            $sub = 'list';
        }

        switch ($sub) {
            case 'add':
                self::handle_add();
                break;

            case 'edit':
                self::handle_edit();
                break;

            case 'delete':
                self::handle_delete();
                break;

            default:
                self::handle_list();
        }
    }

    /**
     * Список типов + форма добавления нового
     */
    protected static function handle_list(): void
    {
        global $db, $mybb, $page, $lang;

        // Вкладки (пока одна, но по канону AF)
        $tabs = [
            'types' => [
                'title' => $lang->af_advancedalerts_admin_tab_types,
                'link'  => 'index.php?module=advancedfunctionality&af_view=AdvancedAlerts',
            ],
        ];
        $page->output_nav_tabs($tabs, 'types');

        // ===== Таблица типов =====
        $table = new Table;
        $table->construct_header($lang->af_advancedalerts_admin_col_code);
        $table->construct_header($lang->af_advancedalerts_admin_col_title);
        $table->construct_header($lang->af_advancedalerts_admin_col_enabled,       ['class' => 'align_center', 'width' => '10%']);
        $table->construct_header($lang->af_advancedalerts_admin_col_user_disable,  ['class' => 'align_center', 'width' => '15%']);
        $table->construct_header($lang->af_advancedalerts_admin_col_actions,       ['class' => 'align_center', 'width' => '20%']);

        $q = $db->simple_select('alert_types', '*', '', [
            'order_by'  => 'code',
            'order_dir' => 'ASC'
        ]);

        while ($row = $db->fetch_array($q)) {
            $id    = (int)$row['id'];
            $code  = htmlspecialchars_uni($row['code']);
            $title = htmlspecialchars_uni($row['title'] ?: $row['code']);

            $enabled    = ((int)$row['enabled'] === 1)              ? $lang->yes : $lang->no;
            $canDisable = ((int)$row['can_be_user_disabled'] === 1) ? $lang->yes : $lang->no;

            $editUrl = 'index.php?module=advancedfunctionality&af_view=AdvancedAlerts&af_action=edit&id='.$id;
            $delUrl  = 'index.php?module=advancedfunctionality&af_view=AdvancedAlerts&af_action=delete&id='.$id.'&my_post_key='.$mybb->post_code;

            $actions = '<a href="'.$editUrl.'">'.$lang->af_advancedalerts_admin_action_edit.'</a>'
                     . ' | '
                     . '<a href="'.$delUrl.'" onclick="return confirm(\''.
                        addslashes($lang->af_advancedalerts_admin_confirm_delete).
                        '\');">'.$lang->af_advancedalerts_admin_action_delete.'</a>';

            $table->construct_cell($code);
            $table->construct_cell($title);
            $table->construct_cell($enabled,    ['class' => 'align_center']);
            $table->construct_cell($canDisable, ['class' => 'align_center']);
            $table->construct_cell($actions,    ['class' => 'align_center']);
            $table->construct_row();
        }

        if ($table->num_rows() === 0) {
            $table->construct_cell($lang->af_advancedalerts_admin_empty, ['colspan' => 5]);
            $table->construct_row();
        }

        $table->output($lang->af_advancedalerts_admin_table_title);

        // ===== Форма добавления нового типа =====
        echo '<br />';

        $form = new Form(
            'index.php?module=advancedfunctionality&af_view=AdvancedAlerts&af_action=add',
            'post'
        );

        $form_container = new FormContainer($lang->af_advancedalerts_admin_add_legend);

        $form_container->output_row(
            $lang->af_advancedalerts_admin_field_code,
            $lang->af_advancedalerts_admin_field_code_desc,
            $form->generate_text_box('code', '', ['id' => 'code'])
        );

        $form_container->output_row(
            $lang->af_advancedalerts_admin_field_title,
            $lang->af_advancedalerts_admin_field_title_desc,
            $form->generate_text_box('title', '', ['id' => 'title'])
        );

        $form_container->output_row(
            $lang->af_advancedalerts_admin_field_enabled,
            '',
            $form->generate_yes_no_radio('enabled', 1)
        );

        $form_container->output_row(
            $lang->af_advancedalerts_admin_field_can_disable,
            '',
            $form->generate_yes_no_radio('can_be_user_disabled', 1)
        );

        $form_container->end();

        $buttons = [];
        $buttons[] = $form->generate_submit_button($lang->af_advancedalerts_admin_add_button);
        $form->output_submit_wrapper($buttons);

        $form->end();
    }

    /**
     * Обработка POST формы "Добавить тип"
     */
    protected static function handle_add(): void
    {
        global $mybb, $lang;

        if (my_strtolower($mybb->request_method) !== 'post') {
            admin_redirect('index.php?module=advancedfunctionality&af_view=AdvancedAlerts');
        }

        verify_post_check($mybb->get_input('my_post_key'));

        $code    = trim($mybb->get_input('code'));
        $title   = trim($mybb->get_input('title'));
        $enabled = (int)$mybb->get_input('enabled') === 1;
        $can     = (int)$mybb->get_input('can_be_user_disabled') === 1;

        if ($code === '') {
            flash_message($lang->af_advancedalerts_admin_error_code_empty, 'error');
            admin_redirect('index.php?module=advancedfunctionality&af_view=AdvancedAlerts');
        }

        // Используем общий хелпер из advancedalerts.php
        afaa_register_type([
            'code'                 => $code,
            'title'                => ($title !== '' ? $title : $code),
            'enabled'              => $enabled ? 1 : 0,
            'can_be_user_disabled' => $can ? 1 : 0,
        ]);

        flash_message($lang->af_advancedalerts_admin_msg_added, 'success');
        admin_redirect('index.php?module=advancedfunctionality&af_view=AdvancedAlerts');
    }

    /**
     * Страница редактирования + сохранение
     */
    protected static function handle_edit(): void
    {
        global $mybb, $db, $page, $lang;

        $id = (int)$mybb->get_input('id');
        if ($id <= 0) {
            admin_redirect('index.php?module=advancedfunctionality&af_view=AdvancedAlerts');
        }

        $row = $db->fetch_array(
            $db->simple_select('alert_types', '*', "id={$id}", ['limit' => 1])
        );
        if (!$row) {
            flash_message($lang->af_advancedalerts_admin_error_not_found, 'error');
            admin_redirect('index.php?module=advancedfunctionality&af_view=AdvancedAlerts');
        }

        if (my_strtolower($mybb->request_method) === 'post') {
            // Сохранение
            verify_post_check($mybb->get_input('my_post_key'));

            $code    = trim($mybb->get_input('code'));
            $title   = trim($mybb->get_input('title'));
            $enabled = (int)$mybb->get_input('enabled') === 1 ? 1 : 0;
            $can     = (int)$mybb->get_input('can_be_user_disabled') === 1 ? 1 : 0;

            if ($code === '') {
                flash_message($lang->af_advancedalerts_admin_error_code_empty, 'error');
                admin_redirect('index.php?module=advancedfunctionality&af_view=AdvancedAlerts&af_action=edit&id='.$id);
            }

            $update = [
                'code'                 => $db->escape_string($code),
                'title'                => $db->escape_string($title !== '' ? $title : $code),
                'enabled'              => $enabled,
                'can_be_user_disabled' => $can,
            ];

            $db->update_query('alert_types', $update, "id={$id}");

            flash_message($lang->af_advancedalerts_admin_msg_updated, 'success');
            admin_redirect('index.php?module=advancedfunctionality&af_view=AdvancedAlerts');
        }

        // Вкладки: список + активная "Редактирование"
        $tabs = [
            'types' => [
                'title' => $lang->af_advancedalerts_admin_tab_types,
                'link'  => 'index.php?module=advancedfunctionality&af_view=AdvancedAlerts',
            ],
            'edit' => [
                'title' => $lang->af_advancedalerts_admin_tab_edit,
                'link'  => 'index.php?module=advancedfunctionality&af_view=AdvancedAlerts&af_action=edit&id='.$id,
            ],
        ];
        $page->output_nav_tabs($tabs, 'edit');

        $form = new Form(
            'index.php?module=advancedfunctionality&af_view=AdvancedAlerts&af_action=edit&id='.$id,
            'post'
        );

        $form_container = new FormContainer($lang->af_advancedalerts_admin_edit_legend);

        $form_container->output_row(
            $lang->af_advancedalerts_admin_field_code,
            $lang->af_advancedalerts_admin_field_code_desc,
            $form->generate_text_box('code', $row['code'], ['id' => 'code'])
        );

        $form_container->output_row(
            $lang->af_advancedalerts_admin_field_title,
            $lang->af_advancedalerts_admin_field_title_desc,
            $form->generate_text_box('title', $row['title'], ['id' => 'title'])
        );

        $form_container->output_row(
            $lang->af_advancedalerts_admin_field_enabled,
            '',
            $form->generate_yes_no_radio('enabled', (int)$row['enabled'])
        );

        $form_container->output_row(
            $lang->af_advancedalerts_admin_field_can_disable,
            '',
            $form->generate_yes_no_radio('can_be_user_disabled', (int)$row['can_be_user_disabled'])
        );

        $form_container->end();

        $buttons = [];
        $buttons[] = $form->generate_submit_button($lang->af_advancedalerts_admin_save_button);
        $form->output_submit_wrapper($buttons);

        $form->end();
    }

    /**
     * Удаление типа уведомления (+ его пользовательских настроек)
     */
    protected static function handle_delete(): void
    {
        global $mybb, $db, $lang;

        $id = (int)$mybb->get_input('id');
        if ($id <= 0) {
            admin_redirect('index.php?module=advancedfunctionality&af_view=AdvancedAlerts');
        }

        // CSRF из my_post_key в GET
        verify_post_check($mybb->get_input('my_post_key'));

        $row = $db->fetch_array(
            $db->simple_select('alert_types', 'code', "id={$id}", ['limit' => 1])
        );

        if ($row && $row['code'] !== '') {
            // Аккуратное удаление через наш хелпер
            af_advancedalerts_unregister_type((string)$row['code']);
        } else {
            // На всякий случай, если нет кода
            $db->delete_query('alert_types', "id={$id}");
        }

        flash_message($lang->af_advancedalerts_admin_msg_deleted, 'success');
        admin_redirect('index.php?module=advancedfunctionality&af_view=AdvancedAlerts');
    }
}

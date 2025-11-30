<?php

return [
    'id'          => 'advancedalerts',
    'name'        => 'Advanced Alerts',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',
    'description' => 'Внутренняя система уведомлений AF (колокольчик, поп-ап, тосты, звук) с поддержкой упоминаний @username и автодополнением.',
    'version'     => '1.1.0',
    'bootstrap'   => 'advancedalerts.php',

    /**
     * Базовое имя языкового файла (без .lang.php).
     * AF-ядро должно создать:
     *   inc/languages/{lang}/advancedfunctionality_advancedalerts.lang.php
     *   inc/languages/{lang}/admin/advancedfunctionality_advancedalerts.lang.php
     */
    'langfile'    => 'advancedfunctionality_advancedalerts',

    // === Описание админ-модуля для ядра AF ===
    'admin'       => [
        'slug'        => 'AdvancedAlerts',
        'title'       => 'Advanced Alerts',
        'description' => 'Уведомления, колокольчик, поп-апы, тосты и упоминания @username.',
        'controller'  => 'admin.php',
    ],

    // Языки: ядро AF создаст файлы автоматически
    'lang' => [
        'russian' => [
            'front' => [
                // Advanced Alerts
                'af_advancedalerts_name'        => 'Advanced Alerts',
                'af_advancedalerts_description' => 'Внутренняя система уведомлений AF с поп-апами, тостами и упоминаниями пользователей.',

                // Advanced Mentions (для фронта — описание функции упоминаний)
                'af_advancedmentions_name'        => 'Advanced Mentions',
                'af_advancedmentions_description' => 'Упоминания пользователей по @username с автодополнением.',
            ],
            'admin' => [
                // === Группа настроек Advanced Alerts ===
                'af_advancedalerts_group'      => 'AF: Advanced Alerts',
                'af_advancedalerts_group_desc' => 'Настройки внутренней системы уведомлений AF (колокольчик, поп-ап, тосты).',

                'af_advancedalerts_enabled'      => 'Включить Advanced Alerts',
                'af_advancedalerts_enabled_desc' => 'Глобальное включение внутренней системы уведомлений.',

                'af_aa_allow_user_disable'      => 'Разрешить отключать типы в UCP',
                'af_aa_allow_user_disable_desc' => 'Показывать чекбоксы типов уведомлений в пользовательских настройках.',

                'af_aa_dropdown_limit'      => 'Сколько показывать в поп-апе',
                'af_aa_dropdown_limit_desc' => 'Количество уведомлений в выпадающем списке колокольчика.',

                'af_aa_toast_limit'      => 'Максимум тост-плашек',
                'af_aa_toast_limit_desc' => 'Одновременно видимых всплывающих карточек-уведомлений.',

                'af_aa_poll_seconds'      => 'Интервал опроса (сек.)',
                'af_aa_poll_seconds_desc' => 'Как часто опрашивать сервер на предмет новых уведомлений.',

                'af_aa_page_perpage'      => 'Уведомлений на странице списка',
                'af_aa_page_perpage_desc' => 'Сколько уведомлений выводить на странице /misc.php?action=af_alerts.',

                'af_aa_mention_all_groups'      => 'Группы с правом @all',
                'af_aa_mention_all_groups_desc' => 'ID групп через запятую, которым разрешено использовать глобальный тег @all.',

                'af_aa_group_mention_groups'      => 'Группы с правом @group{ID}',
                'af_aa_group_mention_groups_desc' => 'ID групп через запятую, которым разрешено использовать тег @group{ID}.',

                // === Блок настроек для логики упоминаний ===
                'af_advancedmentions_group'       => 'AF: Advanced Mentions',
                'af_advancedmentions_group_desc'  => 'Настройки упоминаний пользователей (@username) во фронтенде.',

                'af_advancedmentions_enabled'      => 'Включить Advanced Mentions',
                'af_advancedmentions_enabled_desc' => 'Если включено, пользователи смогут упоминать друг друга по @username.',

                'af_advancedmentions_click_insert'      => 'Клик по нику вставляет упоминание',
                'af_advancedmentions_click_insert_desc' => 'Если включено, клик по нику в постбите вставляет @"username" в форму ответа вместо перехода в профиль.',

                'af_advancedmentions_suggest_min'       => 'Минимум символов для подсказок',
                'af_advancedmentions_suggest_min_desc'  => 'Сколько символов после @ нужно ввести, чтобы показать список пользователей (по умолчанию 2).',

                // === Язык для admin.php (управление типами уведомлений) ===
                'af_advancedalerts_admin_breadcrumb'      => 'Advanced Alerts',
                'af_advancedalerts_admin_title'           => 'Advanced Alerts — типы уведомлений',
                'af_advancedalerts_admin_title_edit'      => 'Advanced Alerts — редактирование типа',

                'af_advancedalerts_admin_tab_types'       => 'Типы уведомлений',
                'af_advancedalerts_admin_tab_edit'        => 'Редактирование',

                'af_advancedalerts_admin_col_code'        => 'Код',
                'af_advancedalerts_admin_col_title'       => 'Название',
                'af_advancedalerts_admin_col_enabled'     => 'Включено',
                'af_advancedalerts_admin_col_user_disable'=> 'Можно отключить в UCP',
                'af_advancedalerts_admin_col_actions'     => 'Действия',

                'af_advancedalerts_admin_table_title'     => 'Зарегистрированные типы уведомлений',

                'af_advancedalerts_admin_add_legend'      => 'Добавить новый тип уведомлений',
                'af_advancedalerts_admin_field_code'      => 'Код типа',
                'af_advancedalerts_admin_field_code_desc' => 'Уникальный системный идентификатор (латиница и подчёркивания), например subscribed_thread.',
                'af_advancedalerts_admin_field_title'     => 'Название',
                'af_advancedalerts_admin_field_title_desc'=> 'Человеко-читаемое название типа уведомления (для UCP и админки).',
                'af_advancedalerts_admin_field_enabled'   => 'Включено по умолчанию',
                'af_advancedalerts_admin_field_can_disable'=> 'Пользователь может отключить в UCP',

                'af_advancedalerts_admin_add_button'      => 'Добавить тип',

                'af_advancedalerts_admin_error_code_empty'=> 'Не указан код типа уведомления.',
                'af_advancedalerts_admin_error_not_found' => 'Тип уведомления не найден.',

                'af_advancedalerts_admin_edit_legend'     => 'Редактировать тип уведомления',
                'af_advancedalerts_admin_save_button'     => 'Сохранить изменения',

                'af_advancedalerts_admin_confirm_delete'  => 'Удалить этот тип уведомлений? Пользовательские настройки для него также будут удалены.',

                'af_advancedalerts_admin_msg_added'       => 'Тип уведомления добавлен.',
                'af_advancedalerts_admin_msg_updated'     => 'Тип уведомления обновлён.',
                'af_advancedalerts_admin_msg_deleted'     => 'Тип уведомления удалён.',

                'af_advancedalerts_admin_empty'           => 'Типов уведомлений пока нет.',
            ],
        ],

        'english' => [
            'front' => [
                // Advanced Alerts
                'af_advancedalerts_name'        => 'Advanced Alerts',
                'af_advancedalerts_description' => 'Internal AF notification system with popups, toasts and user mentions.',

                // Advanced Mentions
                'af_advancedmentions_name'        => 'Advanced Mentions',
                'af_advancedmentions_description' => 'User mentions using @username with autocomplete.',
            ],
            'admin' => [
                // Settings group
                'af_advancedalerts_group'      => 'AF: Advanced Alerts',
                'af_advancedalerts_group_desc' => 'Settings for the internal AF notification system (bell, popup, toasts).',

                'af_advancedalerts_enabled'      => 'Enable Advanced Alerts',
                'af_advancedalerts_enabled_desc' => 'Global switch for the internal notification system.',

                'af_aa_allow_user_disable'      => 'Allow disabling types in UCP',
                'af_aa_allow_user_disable_desc' => 'Show checkboxes for notification types in user control panel.',

                'af_aa_dropdown_limit'      => 'Items in popup dropdown',
                'af_aa_dropdown_limit_desc' => 'How many notifications to show in the bell dropdown.',

                'af_aa_toast_limit'      => 'Maximum toast count',
                'af_aa_toast_limit_desc' => 'How many toast cards may be visible at the same time.',

                'af_aa_poll_seconds'      => 'Polling interval (sec)',
                'af_aa_poll_seconds_desc' => 'How often to poll the server for new notifications.',

                'af_aa_page_perpage'      => 'Alerts per page',
                'af_aa_page_perpage_desc' => 'Number of rows on /misc.php?action=af_alerts page.',

                'af_aa_mention_all_groups'      => 'Groups allowed to use @all',
                'af_aa_mention_all_groups_desc' => 'Comma-separated group IDs allowed to use global @all tag.',

                'af_aa_group_mention_groups'      => 'Groups allowed to use @group{ID}',
                'af_aa_group_mention_groups_desc' => 'Comma-separated group IDs allowed to use @group{ID} tag.',

                // Mentions settings
                'af_advancedmentions_group'       => 'AF: Advanced Mentions',
                'af_advancedmentions_group_desc'  => 'Settings for @username mentions on the frontend.',

                'af_advancedmentions_enabled'      => 'Enable Advanced Mentions',
                'af_advancedmentions_enabled_desc' => 'If enabled, users can mention each other using @username.',

                'af_advancedmentions_click_insert'      => 'Click on username inserts mention',
                'af_advancedmentions_click_insert_desc' => 'If enabled, clicking a username in postbit inserts @"username" into reply form instead of going to profile.',

                'af_advancedmentions_suggest_min'       => 'Minimum characters for suggestions',
                'af_advancedmentions_suggest_min_desc'  => 'How many characters after @ are required to show suggestions (default 2).',

                // Admin page language (admin.php)
                'af_advancedalerts_admin_breadcrumb'      => 'Advanced Alerts',
                'af_advancedalerts_admin_title'           => 'Advanced Alerts — notification types',
                'af_advancedalerts_admin_title_edit'      => 'Advanced Alerts — edit type',

                'af_advancedalerts_admin_tab_types'       => 'Notification types',
                'af_advancedalerts_admin_tab_edit'        => 'Edit',

                'af_advancedalerts_admin_col_code'        => 'Code',
                'af_advancedalerts_admin_col_title'       => 'Title',
                'af_advancedalerts_admin_col_enabled'     => 'Enabled',
                'af_advancedalerts_admin_col_user_disable'=> 'User can disable',
                'af_advancedalerts_admin_col_actions'     => 'Actions',

                'af_advancedalerts_admin_table_title'     => 'Registered notification types',

                'af_advancedalerts_admin_add_legend'      => 'Add new notification type',
                'af_advancedalerts_admin_field_code'      => 'Type code',
                'af_advancedalerts_admin_field_code_desc' => 'Unique system identifier (latin + underscores), e.g. subscribed_thread.',
                'af_advancedalerts_admin_field_title'     => 'Title',
                'af_advancedalerts_admin_field_title_desc'=> 'Human readable notification type title (for UCP and ACP).',
                'af_advancedalerts_admin_field_enabled'   => 'Enabled by default',
                'af_advancedalerts_admin_field_can_disable'=> 'User can disable in UCP',

                'af_advancedalerts_admin_add_button'      => 'Add type',

                'af_advancedalerts_admin_error_code_empty'=> 'Notification type code is required.',
                'af_advancedalerts_admin_error_not_found' => 'Notification type not found.',

                'af_advancedalerts_admin_edit_legend'     => 'Edit notification type',
                'af_advancedalerts_admin_save_button'     => 'Save changes',

                'af_advancedalerts_admin_confirm_delete'  => 'Delete this notification type? User preferences for it will be removed as well.',

                'af_advancedalerts_admin_msg_added'       => 'Notification type has been added.',
                'af_advancedalerts_admin_msg_updated'     => 'Notification type has been updated.',
                'af_advancedalerts_admin_msg_deleted'     => 'Notification type has been deleted.',

                'af_advancedalerts_admin_empty'           => 'No notification types yet.',
            ],
        ],
    ],
];

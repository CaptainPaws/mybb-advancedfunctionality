<?php

return [
    'id'          => 'advancedaccountswitcher',
    'name'        => 'Advanced Account Switcher',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',
    'description' => 'Дополнительные аккаунты (альты) с быстрым переключением без перелогина: создание, привязка существующих, отвязка, контроль по группам.',
    'version'     => '1.0.0',
    'bootstrap'   => 'advancedaccountswitcher.php',

    'assets'      => [
        'front' => [
            'js'  => ['assets/advancedaccountswitcher.js'],
            'css' => ['assets/advancedaccountswitcher.css'],
        ],
        'admin' => [
            'js'  => [],
            'css' => [],
        ],
    ],

    'admin'       => [
        'slug'       => 'advancedaccountswitcher',
        'controller' => 'admin.php',
        'title'      => 'Advanced Account Switcher',
    ],

    // Языки — ядро AF сгенерит RU/EN
    'lang'        => [
        'russian' => [
            'front' => [
                'af_advancedaccountswitcher_name'        => 'Advanced Account Switcher',
                'af_advancedaccountswitcher_description' => 'Дополнительные аккаунты (альты) с быстрым переключением без перелогина.',

                // ===== UI (templates) =====
                'af_aas_ucp_title'                => 'Дополнительные аккаунты',
                'af_aas_header_title_switch'      => 'Переключить аккаунт',
                'af_aas_header_label_accounts'    => 'Аккаунты',
                'af_aas_modal_title_accounts'     => 'Аккаунты',
                'af_aas_modal_close_title'        => 'Закрыть',
                'af_aas_footer_manage_accounts'   => '⚙ Управление аккаунтами',
                'af_aas_footer_account_list'      => '📋 Список аккаунтов',
                'af_aas_btn_login'                => 'Войти',
                'af_aas_panel_empty'              => 'Пока нет доступных аккаунтов.',

                'af_aas_ucp_linked_title'         => 'Привязанные аккаунты',
                'af_aas_ucp_btn_switch'           => 'Переключиться',
                'af_aas_ucp_empty'                => 'Пока нет привязанных аккаунтов.',
                'af_aas_ucp_warn_not_master'      => 'Управление дополнительными аккаунтами доступно только из мастер-аккаунта.',
                'af_aas_ucp_btn_unlink'           => 'Отвязать',

                'af_aas_ucp_create_title'         => 'Создать дополнительный аккаунт',
                'af_aas_ucp_create_username'      => 'Логин (username)',
                'af_aas_ucp_create_password'      => 'Пароль',
                'af_aas_ucp_create_password2'     => 'Пароль ещё раз',
                'af_aas_ucp_create_hint'          => 'Email будет взят от мастер-аккаунта. Аккаунт создаётся без активации и попадёт в группу “Registered”.',
                'af_aas_ucp_create_btn'           => 'Создать и привязать',

                'af_aas_ucp_link_title'           => 'Привязать существующий аккаунт',
                'af_aas_ucp_link_username'        => 'Имя пользователя',
                'af_aas_ucp_link_username_hint'   => 'Начни вводить ник — появится список. Выбери нужный аккаунт и введи его пароль.',
                'af_aas_ucp_link_password'        => 'Пароль этого аккаунта',
                'af_aas_ucp_link_btn'             => 'Привязать',

                'af_aas_account_list_col_account'    => 'Аккаунт',
                'af_aas_account_list_col_reg'        => 'Регистрация',
                'af_aas_account_list_col_active'     => 'Активность',
                'af_aas_account_list_col_posts'      => 'Сообщений',
                'af_aas_account_list_col_threads'    => 'Тем',
                'af_aas_account_list_col_link'       => 'Привязка',
                'af_aas_account_list_col_reputation' => 'Репутация',
                'af_aas_account_list_hint'           => 'В колонке “Привязка” показывается мастер-аккаунт, если он есть и если у мастера не включена приватность.',
                'af_aas_account_list_empty'          => 'Пока ничего не найдено.',

                'af_aas_privacy_title'            => 'Приватность',
                'af_aas_privacy_checkbox'         => 'Не показывать связанные аккаунты в списке пользователей',
                'af_aas_privacy_hint'             => 'Если включено — в “Пользователи” не будет показан мастер-аккаунт и не будет показана привязка у связанных аккаунтов.',
                'af_aas_privacy_btn_save'         => 'Сохранить',

                'af_aas_badge_master'             => 'master',
                'af_aas_ucp_nav_label'            => '👥 Дополнительные аккаунты',
                'af_aas_userlist_title'           => 'Пользователи',
                'af_aas_controls_title'           => 'Поиск и сортировка',
                'af_aas_label_username'           => 'Ник',
                'af_aas_label_match'              => 'Совпадение',
                'af_aas_label_sort'               => 'Сортировка',
                'af_aas_label_order'              => 'Порядок',
                'af_aas_label_perpage'            => 'На странице',
                'af_aas_match_begins'             => 'Начинается с',
                'af_aas_match_contains'           => 'Содержит',
                'af_aas_match_exact'              => 'Точно',

                'af_aas_btn_show'                 => 'Показать',
                'af_aas_btn_reset'                => 'Сброс',

                'af_aas_letter_label'             => 'Буква',
                'af_aas_letter_other'             => '#',
                'af_aas_letter_reset'             => 'сброс',

                'af_aas_empty'                    => 'Ничего не найдено.',
                'af_aas_col_linkage'              => 'Привязка',

                // ===== messages/errors =====
                'af_aas_msg_switched'             => 'Переключено.',
                'af_aas_err_invalid_uid'          => 'Некорректный UID для переключения.',
                'af_aas_err_target_not_found'     => 'Целевой пользователь не найден.',
                'af_aas_err_target_banned'        => 'Нельзя переключиться на забаненный аккаунт.',
                'af_aas_err_missing_loginkey'     => 'У целевого аккаунта отсутствует loginkey.',

                'af_aas_msg_privacy_saved'        => 'Настройка приватности сохранена.',

                'af_aas_err_limit_reached'        => 'Достигнут лимит дополнительных аккаунтов для этого мастер-аккаунта.',
                'af_aas_err_fill_all_fields'      => 'Заполни все поля.',
                'af_aas_err_passwords_mismatch'   => 'Пароли не совпадают.',
                'af_aas_err_master_not_found'     => 'Мастер-аккаунт не найден.',
                'af_aas_err_create_failed'        => 'Не удалось создать аккаунт.',
                'af_aas_err_uid_equals_master'    => 'Критическая ошибка: созданный UID совпал с мастер-аккаунтом (проверь возврат insert_user).',
                'af_aas_msg_created_linked'       => 'Аккаунт создан и привязан.',

                'af_aas_err_pick_account'         => 'Выбери аккаунт из списка и введи пароль.',
                'af_aas_err_cannot_link_self'     => 'Нельзя привязать мастер-аккаунт к самому себе.',
                'af_aas_err_already_linked'       => 'Этот аккаунт уже привязан (возможно к другому мастер-аккаунту).',
                'af_aas_err_account_not_found'    => 'Аккаунт не найден.',
                'af_aas_err_wrong_password'       => 'Пароль неверный.',
                'af_aas_msg_linked'               => 'Аккаунт привязан.',

                'af_aas_err_uid_invalid'          => 'Некорректный UID.',
                'af_aas_msg_unlinked'             => 'Аккаунт отвязан.',

                'af_aas_err_tpl_missing'          => 'Не найден шаблон {1}. Переустанови шаблоны аддона.',

                // ===== extra (missing keys used in PHP fallbacks / logs) =====
                'af_aas_ban_reason_master'        => 'Мастер-аккаунт забанен',

                // ===== userlist controls (duplicated to avoid hardcoded strings in PHP) =====
                'af_aas_userlist_controls_title'  => 'Поиск и сортировка',
                'af_aas_userlist_label_username'  => 'Ник',
                'af_aas_userlist_label_match'     => 'Совпадение',
                'af_aas_userlist_match_begins'    => 'Начинается с',
                'af_aas_userlist_match_contains'  => 'Содержит',
                'af_aas_userlist_match_exact'     => 'Точно',
                'af_aas_userlist_label_sort'      => 'Сортировка',
                'af_aas_userlist_label_order'     => 'Порядок',
                'af_aas_userlist_label_perpage'   => 'На странице',
                'af_aas_userlist_btn_show'        => 'Показать',
                'af_aas_userlist_btn_reset'       => 'Сброс',
                'af_aas_userlist_label_letter'    => 'Буква',

                // ===== additional UI phrases that часто забывают, но потом “стреляет” =====
                'af_aas_userlist_letter_all'      => 'Все',
                'af_aas_userlist_sort_username'   => 'Ник',
                'af_aas_userlist_sort_regdate'    => 'Регистрация',
                'af_aas_userlist_sort_lastvisit'  => 'Последний визит',
                'af_aas_userlist_sort_posts'      => 'Сообщения',
                'af_aas_userlist_sort_threads'    => 'Темы',
                'af_aas_order_asc'                => 'По возрастанию',
                'af_aas_order_desc'               => 'По убыванию',

                // ===== header/modal misc =====
                'af_aas_header_open_title'        => 'Открыть список аккаунтов',
                'af_aas_header_loading'           => 'Загрузка...',
                'af_aas_header_error'             => 'Не удалось загрузить список аккаунтов.',
            ],
            'admin' => [
                'af_advancedaccountswitcher_group'       => 'AF: Advanced Account Switcher',
                'af_advancedaccountswitcher_group_desc'  => 'Настройки дополнительных аккаунтов и переключения.',

                'af_advancedaccountswitcher_enabled'      => 'Включить аддон',
                'af_advancedaccountswitcher_enabled_desc' => 'Включает функционал дополнительных аккаунтов и переключения.',

                'af_advancedaccountswitcher_allowed_groups'      => 'Группы, которым доступны доп. аккаунты',
                'af_advancedaccountswitcher_allowed_groups_desc' => 'Список ID групп через запятую. По умолчанию 2 (Registered).',

                'af_advancedaccountswitcher_max_linked'      => 'Максимум доп. аккаунтов',
                'af_advancedaccountswitcher_max_linked_desc' => 'Сколько дополнительных аккаунтов можно привязать к мастер-аккаунту.',

                'af_advancedaccountswitcher_allow_create'      => 'Разрешить создание доп. аккаунтов',
                'af_advancedaccountswitcher_allow_create_desc' => 'Если включено — мастер может создать новый дополнительный аккаунт прямо из UCP (без активации), email берётся от мастера.',

                'af_advancedaccountswitcher_allow_link_existing'      => 'Разрешить привязку существующих аккаунтов',
                'af_advancedaccountswitcher_allow_link_existing_desc' => 'Если включено — мастер может привязать уже существующий аккаунт (username + пароль).',

                'af_advancedaccountswitcher_ui_header'      => 'Показывать переключатель в шапке',
                'af_advancedaccountswitcher_ui_header_desc' => 'Вставляет дропдаун/список переключения в шапку (через pre_output_page).',

                'af_advancedaccountswitcher_log_switches'      => 'Логировать переключения',
                'af_advancedaccountswitcher_log_switches_desc' => 'Пишет переключения в таблицу логов (полезно для антиабьюза).',

                'af_advancedaccountswitcher_ban_propagation'      => 'Распространять бан мастера на доп. аккаунты',
                'af_advancedaccountswitcher_ban_propagation_desc' => 'Если мастер забанен — доп. аккаунты будут автоматически забанены/блокированы.',

                'af_advancedaccountswitcher_shadow_session'      => 'Оставлять “теневую” сессию предыдущего аккаунта',
                'af_advancedaccountswitcher_shadow_session_desc' => 'При переключении создаёт “теневую” запись в sessions для старого uid, чтобы он не исчезал из онлайна мгновенно (до стандартного sessiontimeout).',

                'af_advancedaccountswitcher_pm_notify_master'      => 'Уведомлять мастера о ЛС на доп. аккаунты',
                'af_advancedaccountswitcher_pm_notify_master_desc' => 'Если включено — при ЛС на дополнительный аккаунт мастер получит уведомление через advancedalertsandmentions (если доступно).',

                // ===== ACP UI strings =====
                'af_aas_admin_title'                => 'Advanced Account Switcher',
                'af_aas_admin_tab_links'            => 'Связи',
                'af_aas_admin_tab_audit'            => 'Логи действий',
                'af_aas_admin_tab_switches'         => 'Логи переключений',

                'af_aas_admin_search_placeholder'   => 'Поиск по нику...',
                'af_aas_admin_btn_search'           => 'Искать',
                'af_aas_admin_btn_filter'           => 'Фильтровать',

                'af_aas_admin_th_id'                => 'ID',
                'af_aas_admin_th_master'            => 'Master',
                'af_aas_admin_th_attached'          => 'Attached',
                'af_aas_admin_th_date'              => 'Дата',

                'af_aas_admin_empty_links'          => 'Пока нет связей.',
                'af_aas_admin_note_links'           => 'Показаны последние 200 связей (лимит для производительности).',

                'af_aas_admin_audit_title'          => 'Advanced Account Switcher — Логи действий',
                'af_aas_admin_switches_title'       => 'Advanced Account Switcher — Логи переключений',

                'af_aas_admin_th_action'            => 'Действие',
                'af_aas_admin_th_actor'             => 'Кто',
                'af_aas_admin_th_ip'                => 'IP',
                'af_aas_admin_th_when'              => 'Когда',

                'af_aas_admin_empty_audit'          => 'Пока нет логов действий.',
                'af_aas_admin_note_audit'           => 'Показаны последние 200 записей. Храним максимум 5000, старые автоматически удаляются.',

                'af_aas_admin_empty_switches'       => 'Пока нет логов переключений.',
                'af_aas_admin_note_switches'        => 'Показаны последние 200 переключений.',

                'af_aas_admin_filter_action_any'     => '— действие —',
                'af_aas_admin_th_from'               => 'От',
                'af_aas_admin_th_to'                 => 'К',

                'af_aas_admin_filter_action_create'  => 'Создание',
                'af_aas_admin_filter_action_link'    => 'Привязка',
                'af_aas_admin_filter_action_unlink'  => 'Отвязка',

                // ===== task strings =====
                'af_aas_task_title' => 'AF AAS cleanup',
                'af_aas_task_desc'  => 'Cleanup для Advanced Account Switcher: тени-сессии, битые связи.',
            ],
        ],

        'english' => [
            'front' => [
                'af_advancedaccountswitcher_name'        => 'Advanced Account Switcher',
                'af_advancedaccountswitcher_description' => 'Linked extra accounts with fast switching without re-login.',

                // ===== UI (templates) =====
                'af_aas_ucp_title'                => 'Extra Accounts',
                'af_aas_header_title_switch'      => 'Switch account',
                'af_aas_header_label_accounts'    => 'Accounts',
                'af_aas_modal_title_accounts'     => 'Accounts',
                'af_aas_modal_close_title'        => 'Close',
                'af_aas_footer_manage_accounts'   => '⚙ Manage accounts',
                'af_aas_footer_account_list'      => '📋 Account list',
                'af_aas_btn_login'                => 'Log in',
                'af_aas_panel_empty'              => 'No available accounts yet.',

                'af_aas_ucp_linked_title'         => 'Linked accounts',
                'af_aas_ucp_btn_switch'           => 'Switch',
                'af_aas_ucp_empty'                => 'No linked accounts yet.',
                'af_aas_ucp_warn_not_master'      => 'Managing extra accounts is available only from the master account.',
                'af_aas_ucp_btn_unlink'           => 'Unlink',

                'af_aas_ucp_create_title'         => 'Create extra account',
                'af_aas_ucp_create_username'      => 'Username',
                'af_aas_ucp_create_password'      => 'Password',
                'af_aas_ucp_create_password2'     => 'Password again',
                'af_aas_ucp_create_hint'          => 'Email will be copied from the master account. The account is created without activation and will be placed into the “Registered” group.',
                'af_aas_ucp_create_btn'           => 'Create & link',

                'af_aas_ucp_link_title'           => 'Link existing account',
                'af_aas_ucp_link_username'        => 'Username',
                'af_aas_ucp_link_username_hint'   => 'Start typing a username — a list will appear. Pick an account and enter its password.',
                'af_aas_ucp_link_password'        => 'That account password',
                'af_aas_ucp_link_btn'             => 'Link',

                'af_aas_account_list_col_account'    => 'Account',
                'af_aas_account_list_col_reg'        => 'Registered',
                'af_aas_account_list_col_active'     => 'Activity',
                'af_aas_account_list_col_posts'      => 'Posts',
                'af_aas_account_list_col_threads'    => 'Threads',
                'af_aas_account_list_col_link'       => 'Link',
                'af_aas_account_list_col_reputation' => 'Reputation',
                'af_aas_account_list_hint'           => 'The “Link” column shows the master account if it exists and the master has not enabled privacy.',
                'af_aas_account_list_empty'          => 'Nothing found yet.',

                'af_aas_privacy_title'            => 'Privacy',
                'af_aas_privacy_checkbox'         => 'Hide linked accounts in the public user list',
                'af_aas_privacy_hint'             => 'If enabled, the master account and link info won’t be shown publicly in “Users”.',
                'af_aas_privacy_btn_save'         => 'Save',

                'af_aas_badge_master'             => 'master',
                'af_aas_ucp_nav_label'            => '👥 Extra accounts',
                'af_aas_userlist_title'           => 'Users',
                'af_aas_controls_title'           => 'Search & Sort',
                'af_aas_label_username'           => 'Username',
                'af_aas_label_match'              => 'Match',
                'af_aas_label_sort'               => 'Sort',
                'af_aas_label_order'              => 'Order',
                'af_aas_label_perpage'            => 'Per page',

                'af_aas_match_begins'             => 'Begins with',
                'af_aas_match_contains'           => 'Contains',
                'af_aas_match_exact'              => 'Exact',

                'af_aas_btn_show'                 => 'Show',
                'af_aas_btn_reset'                => 'Reset',

                'af_aas_letter_label'             => 'Letter',
                'af_aas_letter_other'             => '#',
                'af_aas_letter_reset'             => 'reset',

                'af_aas_empty'                    => 'Nothing found.',
                'af_aas_col_linkage'              => 'Linked to',

                // ===== messages/errors =====
                'af_aas_msg_switched'             => 'Switched.',
                'af_aas_err_invalid_uid'          => 'Invalid UID for switching.',
                'af_aas_err_target_not_found'     => 'Target user not found.',
                'af_aas_err_target_banned'        => 'You cannot switch to a banned account.',
                'af_aas_err_missing_loginkey'     => 'Target account has no loginkey.',

                'af_aas_msg_privacy_saved'        => 'Privacy setting saved.',

                'af_aas_err_limit_reached'        => 'Extra accounts limit reached for this master account.',
                'af_aas_err_fill_all_fields'      => 'Fill in all fields.',
                'af_aas_err_passwords_mismatch'   => 'Passwords do not match.',
                'af_aas_err_master_not_found'     => 'Master account not found.',
                'af_aas_err_create_failed'        => 'Failed to create account.',
                'af_aas_err_uid_equals_master'    => 'Critical error: new UID equals master UID (check insert_user return).',
                'af_aas_msg_created_linked'       => 'Account created and linked.',

                'af_aas_err_pick_account'         => 'Pick an account from the list and enter its password.',
                'af_aas_err_cannot_link_self'     => 'You cannot link the master account to itself.',
                'af_aas_err_already_linked'       => 'This account is already linked (possibly to another master).',
                'af_aas_err_account_not_found'    => 'Account not found.',
                'af_aas_err_wrong_password'       => 'Wrong password.',
                'af_aas_msg_linked'               => 'Account linked.',

                'af_aas_err_uid_invalid'          => 'Invalid UID.',
                'af_aas_msg_unlinked'             => 'Account unlinked.',

                'af_aas_err_tpl_missing'          => 'Template {1} not found. Reinstall addon templates.',

                // ===== extra (missing keys used in PHP fallbacks / logs) =====
                'af_aas_ban_reason_master'        => 'Master account is banned',

                // ===== userlist controls (duplicated to avoid hardcoded strings in PHP) =====
                'af_aas_userlist_controls_title'  => 'Search & sorting',
                'af_aas_userlist_label_username'  => 'Username',
                'af_aas_userlist_label_match'     => 'Match',
                'af_aas_userlist_match_begins'    => 'Begins with',
                'af_aas_userlist_match_contains'  => 'Contains',
                'af_aas_userlist_match_exact'     => 'Exact',
                'af_aas_userlist_label_sort'      => 'Sort',
                'af_aas_userlist_label_order'     => 'Order',
                'af_aas_userlist_label_perpage'   => 'Per page',
                'af_aas_userlist_btn_show'        => 'Show',
                'af_aas_userlist_btn_reset'       => 'Reset',
                'af_aas_userlist_label_letter'    => 'Letter',

                // ===== additional UI phrases that often pop up in templates/controls =====
                'af_aas_userlist_letter_all'      => 'All',
                'af_aas_userlist_sort_username'   => 'Username',
                'af_aas_userlist_sort_regdate'    => 'Registered',
                'af_aas_userlist_sort_lastvisit'  => 'Last visit',
                'af_aas_userlist_sort_posts'      => 'Posts',
                'af_aas_userlist_sort_threads'    => 'Threads',
                'af_aas_order_asc'                => 'Ascending',
                'af_aas_order_desc'               => 'Descending',

                // ===== header/modal misc =====
                'af_aas_header_open_title'        => 'Open account list',
                'af_aas_header_loading'           => 'Loading...',
                'af_aas_header_error'             => 'Failed to load account list.',
            ],
            'admin' => [
                'af_advancedaccountswitcher_group'       => 'AF: Advanced Account Switcher',
                'af_advancedaccountswitcher_group_desc'  => 'Settings for extra accounts and switching.',

                'af_advancedaccountswitcher_enabled'      => 'Enable addon',
                'af_advancedaccountswitcher_enabled_desc' => 'Enables extra accounts and switching.',

                'af_advancedaccountswitcher_allowed_groups'      => 'Allowed groups',
                'af_advancedaccountswitcher_allowed_groups_desc' => 'Comma-separated group IDs. Default 2 (Registered).',

                'af_advancedaccountswitcher_max_linked'      => 'Max extra accounts',
                'af_advancedaccountswitcher_max_linked_desc' => 'How many extra accounts can be linked to a master.',

                'af_advancedaccountswitcher_allow_create'      => 'Allow create extra accounts',
                'af_advancedaccountswitcher_allow_create_desc' => 'Master can create an extra account from UCP (no activation), email is copied from master.',

                'af_advancedaccountswitcher_allow_link_existing'      => 'Allow link existing accounts',
                'af_advancedaccountswitcher_allow_link_existing_desc' => 'Master can link an existing account (username + password).',

                'af_advancedaccountswitcher_ui_header'      => 'Show switcher in header',
                'af_advancedaccountswitcher_ui_header_desc' => 'Injects header switcher UI via pre_output_page.',

                'af_advancedaccountswitcher_log_switches'      => 'Log switches',
                'af_advancedaccountswitcher_log_switches_desc' => 'Logs switches to database.',

                'af_advancedaccountswitcher_ban_propagation'      => 'Propagate master ban to extras',
                'af_advancedaccountswitcher_ban_propagation_desc' => 'If master is banned, extra accounts will be blocked/banned.',

                'af_advancedaccountswitcher_shadow_session'      => 'Create shadow session for previous uid',
                'af_advancedaccountswitcher_shadow_session_desc' => 'Keeps previous uid visible online until sessiontimeout.',

                'af_advancedaccountswitcher_pm_notify_master'      => 'Notify master about PMs on extras',
                'af_advancedaccountswitcher_pm_notify_master_desc' => 'Sends a notification via advancedalertsandmentions if available.',

                // ===== ACP UI strings =====
                'af_aas_admin_title'                => 'Advanced Account Switcher',
                'af_aas_admin_tab_links'            => 'Links',
                'af_aas_admin_tab_audit'            => 'Action logs',
                'af_aas_admin_tab_switches'         => 'Switch logs',

                'af_aas_admin_search_placeholder'   => 'Search by username...',
                'af_aas_admin_btn_search'           => 'Search',
                'af_aas_admin_btn_filter'           => 'Filter',

                'af_aas_admin_th_id'                => 'ID',
                'af_aas_admin_th_master'            => 'Master',
                'af_aas_admin_th_attached'          => 'Attached',
                'af_aas_admin_th_date'              => 'Date',

                'af_aas_admin_empty_links'          => 'No links yet.',
                'af_aas_admin_note_links'           => 'Showing last 200 links (performance limit).',

                'af_aas_admin_audit_title'          => 'Advanced Account Switcher — Action logs',
                'af_aas_admin_switches_title'       => 'Advanced Account Switcher — Switch logs',

                'af_aas_admin_th_action'            => 'Action',
                'af_aas_admin_th_actor'             => 'Actor',
                'af_aas_admin_th_ip'                => 'IP',
                'af_aas_admin_th_when'              => 'When',

                'af_aas_admin_empty_audit'          => 'No action logs yet.',
                'af_aas_admin_note_audit'           => 'Showing last 200 records. Max 5000 kept, old ones are pruned automatically.',

                'af_aas_admin_empty_switches'       => 'No switch logs yet.',
                'af_aas_admin_note_switches'        => 'Showing last 200 switches.',

                'af_aas_admin_filter_action_any'    => '— action —',
                'af_aas_admin_th_from'               => 'From',
                'af_aas_admin_th_to'                 => 'To',

                'af_aas_admin_filter_action_create'  => 'Create',
                'af_aas_admin_filter_action_link'    => 'Link',
                'af_aas_admin_filter_action_unlink'  => 'Unlink',

                // ===== task strings =====
                'af_aas_task_title' => 'AF AAS cleanup',
                'af_aas_task_desc'  => 'Cleanup for Advanced Account Switcher: shadow sessions, broken links.',
            ],
        ],
    ],
];

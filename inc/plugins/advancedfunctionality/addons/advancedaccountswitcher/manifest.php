<?php

return [
    'id'          => 'advancedaccountswitcher',
    'name'        => 'Advanced Account Switcher',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',
    'description' => 'Дополнительные аккаунты (альты) с быстрым переключением без перелогина: создание, привязка существующих, отвязка, контроль по группам.',
    'version'     => '1.0.0',
    'bootstrap'   => 'advancedaccountswitcher.php',

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
            ],
        ],
        'english' => [
            'front' => [
                'af_advancedaccountswitcher_name'        => 'Advanced Account Switcher',
                'af_advancedaccountswitcher_description' => 'Linked extra accounts with fast switching without re-login.',
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
            ],
        ],
    ],
];

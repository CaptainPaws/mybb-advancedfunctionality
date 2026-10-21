<?php

return [
    'id' => 'advancedcombatsystem',
    'name' => 'AdvancedCombatSystem',
    'version' => '0.2.1',
    'author' => 'CaptainPaws',
    'description' => 'AdvancedCombatSystem SQL foundation and lifecycle (ACS-1.2).',
    'bootstrap' => 'advancedcombatsystem.php',
    'admin' => [
        'slug' => 'advancedcombatsystem',
        'title' => 'AdvancedCombatSystem',
        'controller' => 'admin.php',
    ],
    // No frontend combat surface exists in ACS-1.1.
    // AF still loads the lightweight bootstrap independently of this policy.
    'frontend' => [
        'mode' => 'contextual',
        'routes' => [],
        'response_rules' => [],
        'directory_fallback' => false,
    ],
    'lang' => [
        'russian' => [
            'front' => [
                'af_advancedcombatsystem_name' => 'AdvancedCombatSystem',
                'af_advancedcombatsystem_description' => 'Каркас модульной боевой системы.',
            ],
            'admin' => [
                'af_advancedcombatsystem_name' => 'AdvancedCombatSystem',
                'af_advancedcombatsystem_version' => 'Версия аддона',
                'af_advancedcombatsystem_status' => 'Состояние',
                'af_advancedcombatsystem_enabled' => 'Включён',
                'af_advancedcombatsystem_disabled' => 'Отключён',
                'af_advancedcombatsystem_notice' => 'ACS-1.2: инфраструктура данных и миграций. Игровая механика ещё не реализована.',
                'af_advancedcombatsystem_schema' => 'Версия схемы',
                'af_advancedcombatsystem_migrations' => 'Миграции: версия / результат / шаг / error_id',
                'af_advancedcombatsystem_compatibility' => 'Совместимость БД',
                'af_advancedcombatsystem_install' => 'Установить / продолжить установку',
                'af_advancedcombatsystem_upgrade' => 'Обновить / продолжить миграции',
                'af_advancedcombatsystem_uninstall' => 'Удалить пустую инфраструктуру ACS',
                'af_advancedcombatsystem_confirm' => 'Для удаления отключите ACS и введите DELETE EMPTY ACS FOUNDATION. Любые данные или неизвестные обязательства блокируют удаление.',
                'af_advancedcombatsystem_success' => 'Команда выполнена.',
                'af_advancedcombatsystem_unavailable' => 'Не установлена / не подтверждена',
            ],
        ],
        'english' => [
            'front' => [
                'af_advancedcombatsystem_name' => 'AdvancedCombatSystem',
                'af_advancedcombatsystem_description' => 'Modular combat system foundation.',
            ],
            'admin' => [
                'af_advancedcombatsystem_name' => 'AdvancedCombatSystem',
                'af_advancedcombatsystem_version' => 'Addon version',
                'af_advancedcombatsystem_status' => 'Status',
                'af_advancedcombatsystem_enabled' => 'Enabled',
                'af_advancedcombatsystem_disabled' => 'Disabled',
                'af_advancedcombatsystem_notice' => 'ACS-1.2: data and migration infrastructure. Gameplay mechanics are not implemented yet.',
                'af_advancedcombatsystem_schema' => 'Schema version',
                'af_advancedcombatsystem_migrations' => 'Migrations: version / result / step / error_id',
                'af_advancedcombatsystem_compatibility' => 'Database compatibility',
                'af_advancedcombatsystem_install' => 'Install / resume installation',
                'af_advancedcombatsystem_upgrade' => 'Upgrade / resume migrations',
                'af_advancedcombatsystem_uninstall' => 'Remove empty ACS foundation',
                'af_advancedcombatsystem_confirm' => 'Disable ACS and enter DELETE EMPTY ACS FOUNDATION to remove it. Any data or unknown obligations block removal.',
                'af_advancedcombatsystem_success' => 'Command completed.',
                'af_advancedcombatsystem_unavailable' => 'Not installed / not verified',
            ],
        ],
    ],
];

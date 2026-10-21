<?php

return [
    'id' => 'advancedcombatsystem',
    'name' => 'AdvancedCombatSystem',
    'version' => '0.1.0',
    'author' => 'CaptainPaws',
    'description' => 'AdvancedCombatSystem bootstrap and administration (ACS-1.1).',
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
                'af_advancedcombatsystem_notice' => 'ACS-1.1: зарегистрирован каркас аддона. Игровая механика ещё не реализована.',
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
                'af_advancedcombatsystem_notice' => 'ACS-1.1: the addon foundation is registered. Gameplay mechanics are not implemented yet.',
            ],
        ],
    ],
];

<?php
/**
 * AF Addon Manifest: AdvancedProfileFields
 * MyBB 1.8.40, PHP 8.0–8.5
 */

return [
    'id'          => 'advancedprofilefields',
    'name'        => 'AdvancedProfileFields',
    'version'     => '1.1.0',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',
    'description' => 'Системные и дополнительные поля профиля, включая независимый аватар персонажа.',
    'bootstrap'   => 'advancedprofilefields.php',
    // ATF owns usercp_avatar while enabled. APF only supplies the component
    // variable and a narrowly scoped seed-upgrade normalizer; it never patches
    // the ATF-owned template directly.
    'compatibility_providers' => [
        'adaptivethemeframework' => 'af_apf_register_atf_compatibility_normalizer',
    ],
    'frontend' => [
        'mode' => 'contextual',
        'routes' => [],
        'response_rules' => [
            ['has_apf_output' => true],
        ],
        // pre_output owns component-aware delivery; keep the legacy blacklist too.
        'directory_fallback' => false,
    ],

    'admin' => [
        'slug'       => 'advancedprofilefields',
        'controller' => 'admin.php',
        'icon'       => 'user',
    ],

    // Ядро AF подхватит и сгенерирует языки по этим ключам.
    'lang' => [
        'front' => [
            'af_advancedprofilefields_name'        => 'AdvancedProfileFields',
            'af_advancedprofilefields_description' => 'CSS-классы для доп. полей профиля, постов и тем.',
        ],
        'admin' => [
            'af_advancedprofilefields_group'       => 'AdvancedProfileFields',
            'af_advancedprofilefields_group_desc'  => 'Настройки и обслуживание аддона AdvancedProfileFields.',
            'af_advancedprofilefields_enabled'      => 'Включить AdvancedProfileFields',
            'af_advancedprofilefields_enabled_desc' => 'Добавляет CSS-классы к дополнительным полям профиля (customfields) и к строкам "Сообщений/Тем".',
        ],
    ],
];

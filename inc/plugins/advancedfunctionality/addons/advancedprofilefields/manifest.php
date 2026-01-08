<?php
/**
 * AF Addon Manifest: AdvancedProfileFields
 * MyBB 1.8.38–1.8.39, PHP 8.0–8.4
 */

return [
    'id'          => 'advancedprofilefields',
    'name'        => 'AdvancedProfileFields',
    'version'     => '1.0.0',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',
    'description' => 'Добавляет CSS-классы для дополнительных полей профиля (customfields) в профиле, UserCP, регистрации и постбите, а также помечает поля "Сообщений" и "Тем".',
    'bootstrap'   => 'advancedprofilefields.php',

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

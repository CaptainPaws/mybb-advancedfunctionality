<?php

return [
    'id'          => 'advancedposteravatar',
    'name'        => 'AdvancedAvatar',
    'author'      => 'CaptainPaws',
    'authorsite'  => '',
    'description' => 'Единый renderer аватаров последнего постера и списка online.',
    'version'     => '2.0.0',
    'bootstrap'   => 'advancedposteravatar.php',
    // Activation-time integrations are loaded explicitly by their consumer;
    // they must not depend on global_start/frontend initialization order.
    'compatibility_providers' => [
        'adaptivethemeframework' => 'af_apa_register_atf_compatibility_normalizer',
    ],
    'frontend' => [
        'mode' => 'contextual',
        'routes' => [
            ['script' => 'index.php'],
            ['script' => 'forumdisplay.php'],
            ['script' => 'online.php'],
        ],
        // This addon owns its conditional CSS/JS injection.
        'directory_fallback' => false,
    ],

    'lang'        => [
        'russian' => [
            'front' => [
                'af_advancedposteravatar_name'        => 'AdvancedAvatar',
                'af_advancedposteravatar_description' => 'Показывает аватары последних постеров и online-пользователей.',
            ],
            'admin' => [
                'af_advancedposteravatar_group'      => 'AF: Advanced Poster Avatar',
                'af_advancedposteravatar_group_desc' => 'Настройки отображения аватара последнего постера.',
            ],
        ],
        'english' => [
            'front' => [
                'af_advancedposteravatar_name'        => 'AdvancedAvatar',
                'af_advancedposteravatar_description' => 'Shows last-poster and online-list avatars with one renderer.',
            ],
            'admin' => [
                'af_advancedposteravatar_group'      => 'AF: Advanced Poster Avatar',
                'af_advancedposteravatar_group_desc' => 'Settings for last poster avatar display.',
            ],
        ],
    ],
];

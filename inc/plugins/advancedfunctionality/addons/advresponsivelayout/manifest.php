<?php
return [
    'id' => 'advresponsivelayout',
    'name' => 'Adaptive Responsive Layout',
    'author' => 'CaptainPaws',
    'authorsite' => 'https://github.com/CaptainPaws',
    'description' => 'Full mobile responsive system for forum core navigation and AF plugin pages.',
    'version' => '2.0.0',
    'bootstrap' => 'advresponsivelayout.php',
    'assets' => [
        'front' => [
            'css' => [],
            'js' => [],
        ],
    ],
    'lang' => [
        'russian' => [
            'front' => [
                'af_advresponsivelayout_name' => 'Мобильная адаптивная система',
                'af_advresponsivelayout_description' => 'Полноценный mobile-first layout слой для форума и страниц AF без замены скинов.',
            ],
            'admin' => [
                'af_advresponsivelayout_group' => 'AF: Adaptive Responsive Layout',
                'af_advresponsivelayout_group_desc' => 'Настройки полноценной мобильной адаптивной системы форума и плагинов.',
            ],
        ],
        'english' => [
            'front' => [
                'af_advresponsivelayout_name' => 'Mobile Responsive System',
                'af_advresponsivelayout_description' => 'Full mobile-first responsive layout layer for forum and AF pages without skin replacement.',
            ],
            'admin' => [
                'af_advresponsivelayout_group' => 'AF: Adaptive Responsive Layout',
                'af_advresponsivelayout_group_desc' => 'Settings for full mobile responsive forum/plugin layout system.',
            ],
        ],
    ],
];

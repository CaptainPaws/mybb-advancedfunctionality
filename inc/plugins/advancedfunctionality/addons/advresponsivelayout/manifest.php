<?php
return [
    'id' => 'advresponsivelayout',
    'name' => 'Adaptive Responsive Layout',
    'author' => 'CaptainPaws',
    'authorsite' => 'https://github.com/CaptainPaws',
    'description' => 'System responsive layer for AdvancedFunctionality front-end layouts.',
    'version' => '1.0.0',
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
                'af_advresponsivelayout_name' => 'Адаптивный слой',
                'af_advresponsivelayout_description' => 'Системный responsive-слой без замены скинов и пресетов.',
            ],
            'admin' => [
                'af_advresponsivelayout_group' => 'AF: Adaptive Responsive Layout',
                'af_advresponsivelayout_group_desc' => 'Настройки системного адаптивного слоя и responsive-fixes.',
            ],
        ],
        'english' => [
            'front' => [
                'af_advresponsivelayout_name' => 'Adaptive Responsive Layout',
                'af_advresponsivelayout_description' => 'System responsive layer without replacing skins and presets.',
            ],
            'admin' => [
                'af_advresponsivelayout_group' => 'AF: Adaptive Responsive Layout',
                'af_advresponsivelayout_group_desc' => 'Settings for system responsive layout/fix layer.',
            ],
        ],
    ],
];

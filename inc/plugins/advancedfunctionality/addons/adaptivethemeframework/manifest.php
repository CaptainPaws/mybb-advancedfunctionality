<?php
/**
 * AF Addon Manifest: Adaptive Theme Framework.
 */
return [
    'id' => 'adaptivethemeframework',
    'name' => 'Adaptive Theme Framework',
    'description' => 'Presentation framework for adaptive AF component-slot layouts.',
    'version' => '0.2.0',
    'author' => 'AdvancedFunctionality',
    'bootstrap' => 'adaptivethemeframework.php',

    // ATF owns the site-wide presentation layer. Asset permission is therefore
    // global and declarative; no legacy page blacklist is needed.
    'frontend' => [
        'mode' => 'global',
        'directory_fallback' => false,
    ],

    // Assets will be introduced with the component-slot implementation.
    'assets' => [
        'front' => [
            'css' => [],
            'js' => [],
        ],
    ],

    'lang' => [
        'russian' => [
            'front' => [
                'af_adaptivethemeframework_name' => 'Adaptive Theme Framework',
                'af_adaptivethemeframework_description' => 'Адаптивный слой представления на основе компонентных слотов.',
            ],
            'admin' => [
                'af_adaptivethemeframework_group' => 'AF: Adaptive Theme Framework',
                'af_adaptivethemeframework_group_desc' => 'Настройки адаптивного слоя представления.',
            ],
        ],
        'english' => [
            'front' => [
                'af_adaptivethemeframework_name' => 'Adaptive Theme Framework',
                'af_adaptivethemeframework_description' => 'Adaptive presentation layer based on component slots.',
            ],
            'admin' => [
                'af_adaptivethemeframework_group' => 'AF: Adaptive Theme Framework',
                'af_adaptivethemeframework_group_desc' => 'Settings for the adaptive presentation layer.',
            ],
        ],
    ],
];

<?php
/**
 * AF Addon Manifest: Adaptive Theme Framework.
 */
return [
    'id' => 'adaptivethemeframework',
    'name' => 'Adaptive Theme Framework',
    'description' => 'Presentation framework for adaptive AF component-slot layouts.',
    'version' => '0.4.0',
    'author' => 'AdvancedFunctionality',
    'bootstrap' => 'adaptivethemeframework.php',

    // ATF owns the site-wide presentation layer. Asset permission is therefore
    // global and declarative; no legacy page blacklist is needed.
    'frontend' => [
        'mode' => 'global',
        'directory_fallback' => false,
    ],

    // CSS is a normal AF theme source and is therefore composed into
    // advancedstyles.css. It is deliberately not queued as a direct asset.
    'assets' => [
        'front' => [
            'css' => [],
            'js' => [],
        ],
    ],

    'theme_stylesheets' => [[
        'id' => 'adaptivethemeframework_design_system',
        'file' => 'assets/adaptivethemeframework.css',
        'stylesheet_name' => 'af_adaptivethemeframework.css',
        'attach' => [],
        'enabled_setting' => 'af_adaptivethemeframework_enabled',
    ]],

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

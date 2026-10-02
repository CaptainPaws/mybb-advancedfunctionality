<?php
/**
 * AF Addon Manifest: Adaptive Theme Framework.
 */
return [
    'id' => 'adaptivethemeframework',
    'name' => 'Adaptive Theme Framework',
    'description' => 'Presentation framework for adaptive AF component-slot layouts.',
    'version' => '0.17.0',
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
                'atf_pm_compose' => 'Написать ЛС',
                'atf_pm_tracking' => 'Отслеживание',
                'atf_pm_advanced_search' => 'Расширенный поиск',
                'atf_pm_edit_folders' => 'Редактировать папки',
                'atf_pm_clear_folders' => 'Очистить папки',
                'atf_pm_export' => 'Экспорт сообщений',
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
                'atf_pm_compose' => 'Compose message',
                'atf_pm_tracking' => 'Tracking',
                'atf_pm_advanced_search' => 'Advanced search',
                'atf_pm_edit_folders' => 'Edit folders',
                'atf_pm_clear_folders' => 'Clear folders',
                'atf_pm_export' => 'Export messages',
            ],
            'admin' => [
                'af_adaptivethemeframework_group' => 'AF: Adaptive Theme Framework',
                'af_adaptivethemeframework_group_desc' => 'Settings for the adaptive presentation layer.',
            ],
        ],
    ],
];

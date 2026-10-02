<?php
/**
 * AF Addon Manifest: Adaptive Theme Framework.
 */
return [
    'id' => 'adaptivethemeframework',
    'name' => 'Adaptive Theme Framework',
    'description' => 'Presentation framework for adaptive AF component-slot layouts.',
    'version' => '0.24.0',
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
                'atf_forum_layout' => 'Отображение форумов',
                'atf_forum_layout_full' => 'По ширине',
                'atf_forum_layout_grid' => 'Сетка',
                'atf_presentation_save' => 'Сохранить',
                'atf_pm_compose' => 'Написать ЛС',
                'atf_pm_tracking' => 'Отслеживание',
                'atf_pm_advanced_search' => 'Расширенный поиск',
                'atf_pm_edit_folders' => 'Редактировать папки',
                'atf_pm_clear_folders' => 'Очистить папки',
                'atf_pm_export' => 'Экспорт сообщений',
                'atf_ucp_nav_overview' => 'Обзор',
                'atf_ucp_nav_profile' => 'Профиль',
                'atf_ucp_nav_preferences' => 'Настройки',
                'atf_ucp_nav_security' => 'Безопасность',
                'atf_ucp_nav_social' => 'Социальное',
                'atf_ucp_nav_subscriptions' => 'Подписки',
                'atf_ucp_nav_content' => 'Контент',
                'atf_ucp_nav_messages' => 'Личные сообщения',
                'atf_ucp_nav_public_profile' => 'Публичный профиль',
                'atf_ucp_nav_general_preferences' => 'Общие настройки',
                'atf_ucp_nav_password' => 'Пароль',
                'atf_ucp_nav_email' => 'Электронная почта',
                'atf_ucp_nav_username' => 'Имя пользователя',
                'atf_ucp_nav_buddy_ignore' => 'Друзья и игнорируемые',
                'atf_ucp_nav_group_memberships' => 'Членство в группах',
                'atf_ucp_nav_threads' => 'Темы',
                'atf_ucp_nav_forums' => 'Форумы',
                'atf_ucp_nav_drafts' => 'Черновики',
                'atf_ucp_nav_attachments' => 'Вложения',
                'atf_ucp_navigation' => 'Панель управления пользователя',
                'atf_ucp_section_navigation' => 'Навигация раздела',
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
                'atf_forum_layout' => 'Forum layout',
                'atf_forum_layout_full' => 'Full width',
                'atf_forum_layout_grid' => 'Grid',
                'atf_presentation_save' => 'Save',
                'atf_pm_compose' => 'Compose message',
                'atf_pm_tracking' => 'Tracking',
                'atf_pm_advanced_search' => 'Advanced search',
                'atf_pm_edit_folders' => 'Edit folders',
                'atf_pm_clear_folders' => 'Clear folders',
                'atf_pm_export' => 'Export messages',
                'atf_ucp_nav_overview' => 'Overview',
                'atf_ucp_nav_profile' => 'Profile',
                'atf_ucp_nav_preferences' => 'Preferences',
                'atf_ucp_nav_security' => 'Security',
                'atf_ucp_nav_social' => 'Social',
                'atf_ucp_nav_subscriptions' => 'Subscriptions',
                'atf_ucp_nav_content' => 'Content',
                'atf_ucp_nav_messages' => 'Messages',
                'atf_ucp_nav_public_profile' => 'View public profile',
                'atf_ucp_nav_general_preferences' => 'General preferences',
                'atf_ucp_nav_password' => 'Password',
                'atf_ucp_nav_email' => 'Email',
                'atf_ucp_nav_username' => 'Username',
                'atf_ucp_nav_buddy_ignore' => 'Buddy / Ignore',
                'atf_ucp_nav_group_memberships' => 'Group memberships',
                'atf_ucp_nav_threads' => 'Threads',
                'atf_ucp_nav_forums' => 'Forums',
                'atf_ucp_nav_drafts' => 'Drafts',
                'atf_ucp_nav_attachments' => 'Attachments',
                'atf_ucp_navigation' => 'User control panel',
                'atf_ucp_section_navigation' => 'Section navigation',
            ],
            'admin' => [
                'af_adaptivethemeframework_group' => 'AF: Adaptive Theme Framework',
                'af_adaptivethemeframework_group_desc' => 'Settings for the adaptive presentation layer.',
            ],
        ],
    ],
];

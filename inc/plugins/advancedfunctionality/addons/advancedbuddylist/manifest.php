<?php

if (!defined('IN_MYBB')) {
    die('No direct access');
}

return [
    'id'          => 'advancedbuddylist',
    'type'        => 'addon',
    'name'        => 'Advanced Buddy List',
    'description' => 'Взаимные друзья, заявки, односторонний игнор и поиск пользователей.',
    'version'     => '2.3.1',
    'compatibility' => '18*',
    'author'      => 'CaptainPaws',
    'website'     => 'https://github.com/CaptainPaws',
    'bootstrap'   => 'advancedbuddylist.php',

    // Generic AF lifecycle migration loads the canonical bootstrap once when a legacy switch exists.
    'legacy_ids' => ['advancedbyddylist'],
    'legacy_lifecycle_settings' => ['af_abdl_enabled'],

    'frontend' => [
        'mode' => 'contextual',
        'routes' => [
            ['script' => 'buddy.php'],
        ],
        'response_rules' => [],
        'directory_fallback' => false,
    ],


    'lang' => [
        'russian' => [
            'front' => [
                'af_abdl_title'          => 'Друзья',
                'af_abdl_tab_friends'    => 'Друзья',
                'af_abdl_tab_ignore'     => 'Игнор',
                'af_abdl_online'         => 'В сети',
                'af_abdl_offline'        => 'Не в сети',
                'af_abdl_send_pm'        => 'Отправить личное сообщение',
                'af_abdl_manage_lists'   => 'Друзья/Игнор список',
                'af_abdl_close'          => 'Закрыть',
                'af_abdl_empty'          => 'Пусто.',
            ],
            'admin' => [
                'af_abdl_group'          => 'AF: Advanced Buddy List',
                'af_abdl_group_desc'     => 'Настройки улучшенной модалки друзей/игнора.',
            ],
        ],
        'english' => [
            'front' => [
                'af_abdl_title'          => 'Friends',
                'af_abdl_tab_friends'    => 'Friends',
                'af_abdl_tab_ignore'     => 'Ignore',
                'af_abdl_online'         => 'Online',
                'af_abdl_offline'        => 'Offline',
                'af_abdl_send_pm'        => 'Send private message',
                'af_abdl_manage_lists'   => 'Friends/Ignore list',
                'af_abdl_close'          => 'Close',
                'af_abdl_empty'          => 'Empty.',
            ],
            'admin' => [
                'af_abdl_group'          => 'AF: Advanced Buddy List',
                'af_abdl_group_desc'     => 'Settings for improved friends/ignore modal.',
            ],
        ],
    ],
];

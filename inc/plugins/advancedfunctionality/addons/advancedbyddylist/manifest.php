<?php

if (!defined('IN_MYBB')) {
    die('No direct access');
}

return [
    'id'          => 'advancedbyddylist',
    'type'        => 'addon',
    'name'        => 'Advanced Buddy List',
    'description' => 'Улучшенная модалка друзей/игнора для MyBB.popupWindow (misc.php?action=buddypopup&modal=1).',
    'version'     => '1.0.0',
    'compatibility' => '18*',
    'author'      => 'CaptainPaws',
    'website'     => 'https://github.com/CaptainPaws',
    'bootstrap'   => 'advancedbyddylist.php',


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
                'af_abdl_enabled'        => 'Включить Advanced Buddy List',
                'af_abdl_enabled_desc'   => 'Если выключено — будет использоваться стандартный шаблон misc_buddypopup.',
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
                'af_abdl_enabled'        => 'Enable Advanced Buddy List',
                'af_abdl_enabled_desc'   => 'If disabled — default misc_buddypopup template will be used.',
            ],
        ],
    ],
];

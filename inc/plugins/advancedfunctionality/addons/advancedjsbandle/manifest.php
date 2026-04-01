<?php

return [
    'id'        => 'advancedjsbandle',
    'name'      => 'JS Bundle',
    'version'   => '1.0.2',
    'author'      => 'CaptainPaws',
    'website'     => 'https://github.com/CaptainPaws',
    'bootstrap' => 'advancedjsbandle.php',
    'theme_stylesheets' => [
        [
            'id' => 'advancedjsbandle_scroll_buttons',
            'file' => 'assets/scroll-buttons.css',
            'stylesheet_name' => 'af_advancedjsbandle_scroll_buttons.css',
            'attach' => [['file' => 'global']],
            'enabled_setting' => 'af_advancedjsbandle_enabled',
        ],
        [
            'id' => 'advancedjsbandle_af_quickquote',
            'file' => 'assets/af_quickquote.css',
            'stylesheet_name' => 'af_advancedjsbandle_af_quickquote.css',
            'attach' => [['file' => 'global'], ['file' => 'showthread.php'], ['file' => 'forumdisplay.php']],
            'enabled_setting' => 'af_advancedjsbandle_enabled',
        ],
        [
            'id' => 'advancedjsbandle_fimp',
            'file' => 'assets/fimp.css',
            'stylesheet_name' => 'af_advancedjsbandle_fimp.css',
            'attach' => [['file' => 'global'], ['file' => 'showthread.php'], ['file' => 'forumdisplay.php']],
            'enabled_setting' => 'af_advancedjsbandle_enabled',
        ],
        [
            'id' => 'advancedjsbandle_postbit_fa_icons',
            'file' => 'assets/postbit-fa-icons.css',
            'stylesheet_name' => 'af_advancedjsbandle_postbit_fa_icons.css',
            'attach' => [['file' => 'global'], ['file' => 'showthread.php'], ['file' => 'forumdisplay.php'], ['file' => 'private.php']],
            'enabled_setting' => 'af_advancedjsbandle_enabled',
        ],
        [
            'id' => 'advancedjsbandle_quote_avatars',
            'file' => 'assets/quote-avatars.css',
            'stylesheet_name' => 'af_advancedjsbandle_quote_avatars.css',
            'attach' => [['file' => 'global'], ['file' => 'showthread.php'], ['file' => 'private.php']],
            'enabled_setting' => 'af_advancedjsbandle_enabled',
        ],
    ],

    'lang' => [
        'front' => [
            'name'        => 'JS Bundle',
            'description' => 'Подключает JS-файлы из assets аддона в headerinclude после {$stylesheets}.',
        ],
        'admin' => [
            'group'        => 'JS Bundle',
            'group_desc'   => 'Автоподключение JS из папки assets в headerinclude.',
            'enabled'      => 'Включить JS Bundle',
            'enabled_desc' => 'Если включено — аддон синхронизирует блок скриптов в шаблоне headerinclude.',
        ],
    ],
];

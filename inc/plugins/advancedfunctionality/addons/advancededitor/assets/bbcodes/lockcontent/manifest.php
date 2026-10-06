<?php

return [
    'view_assets' => ['js' => [], 'css' => ['bbcodes/lockcontent/lockcontent_view.css']],
    'view_markers' => ['af-aqr-lc-quote'],
    'runtime' => ['activation' => 'click', 'requires' => []],
    'id'    => 'lockcontent',
    'title' => 'Скрыть содержимое',
    'tags' => ['hide'],

    // кнопки, которые пак отдаёт в тулбар
    'buttons' => [
        [
            'cmd'     => 'af_lockcontent',
            'name'    => 'lockcontent',
            'title'   => 'Скрыть содержимое',
            'icon'    => 'img/locked.svg',
            'handler' => 'lockcontent',
        ],
    ],

    // ассеты пакета (относительно assets/)
    'assets' => [
        'css' => [
            'bbcodes/lockcontent/lockcontent.css',
        ],
        'js'  => [
            'bbcodes/lockcontent/lockcontent.js',
        ],
    ],

    // php-парсер пакета (относительно папки pack)
    // у тебя он называется server.php — и это ОК, дискавери поддерживает любое имя файла
    'parser' => 'server.php',
];

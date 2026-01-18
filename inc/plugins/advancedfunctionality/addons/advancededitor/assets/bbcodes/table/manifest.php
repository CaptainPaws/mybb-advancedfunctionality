<?php

return [
    'id'    => 'table',
    'title' => 'Таблица',

    // по этим тегам диспетчер решает, есть ли смысл запускать parser
    'tags' => ['table'],

    // кнопки, которые пак отдаёт в тулбар
    'buttons' => [
        [
            'cmd'     => 'af_table',
            'name'    => 'table',
            'title'   => 'Таблица',
            // хранится внутри assets/, поэтому относительный путь:
            'icon'    => '/assets/img/tablebb.svg',
            // handler = ключ JS-хендлера (таблица регистрирует себя как "table")
            'handler' => 'table',
        ],
    ],

    // ассеты пакета (относительно assets/)
    'assets' => [
        'css' => [
            'bbcodes/table/table.css',
        ],
        'js'  => [
            'bbcodes/table/table.js',
        ],
    ],

    // php-парсер пакета (относительно папки pack)
    'parser' => 'parser.php',
];

<?php

return [
    'id'    => 'tables',
    'title' => 'Таблицы',
    'tags'  => ['table', 'tr', 'td', 'th'],

    'buttons' => [
        [
            'cmd'     => 'af_tables',
            'name'    => 'tables',
            'title'   => 'Таблица',
            'icon'    => 'img/tablebb.svg',
            'handler' => 'tables',
        ],
    ],

    'assets' => [
        'css' => [
            'bbcodes/tables/tables.css',
        ],
        'js'  => [
            'bbcodes/jscolorpiker/jscolor.js',
            'bbcodes/tables/tables.js',
        ],
    ],

    'parser' => 'tables.php',
];

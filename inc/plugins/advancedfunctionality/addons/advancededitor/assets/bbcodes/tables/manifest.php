<?php

return [
    'view_assets' => ['js' => [], 'css' => ['bbcodes/tables/tables_view.css']],
    'view_markers' => ['af-bb-table'],
    'runtime' => ['activation' => 'click', 'requires' => []],
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

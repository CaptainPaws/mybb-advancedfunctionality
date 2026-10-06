<?php

return [
    'view_assets' => ['js' => [], 'css' => ['bbcodes/abbr/abbr_view.css']],
    'view_markers' => ['af-bb-abbr'],
    'runtime' => ['activation' => 'click', 'requires' => []],
    'id'    => 'abbr',
    'title' => 'Пояснение',
    'tags'  => ['abbr'],

    'buttons' => [
        [
            'cmd'      => 'af_abbr',
            'name'     => 'abbr',
            'title'    => 'Поясняющий текст',
            'iconClass'=> 'fa-solid fa-circle-info',
            'hint'     => 'Вставить [abbr="подсказка"]текст[/abbr]',
            'handler'  => 'abbr',
            'opentag'  => '[abbr=""]',
            'closetag' => '[/abbr]',
        ],
    ],

    'assets' => [
        'css' => [
            'bbcodes/abbr/abbr.css',
        ],
        'js' => [
            'bbcodes/abbr/abbr.js',
        ],
    ],

    'parser' => 'abbr.php',
];

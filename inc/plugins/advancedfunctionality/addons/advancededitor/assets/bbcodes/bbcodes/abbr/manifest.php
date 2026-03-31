<?php

return [
    'id'    => 'abbr',
    'title' => 'Пояснение',
    'tags'  => ['abbr'],

    'buttons' => [
        [
            'cmd'      => 'af_abbr',
            'name'     => 'abbr',
            'title'    => 'Поясняющий текст',
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

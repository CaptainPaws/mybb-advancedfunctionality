<?php

return [
    'id'    => 'fontfamily',
    'title' => 'Шрифт (семейства)',

    'tags' => ['font'],

    'buttons' => [
        [
            'cmd'     => 'af_font',
            'name'    => 'fontfamily',
            'title'   => 'Шрифт (семейства)',
            'icon'    => 'img/font.svg',
            'handler' => 'fontfamily',
        ],
    ],

    'assets' => [
        'css' => [
            'bbcodes/fontfamily/fontfamily.css',
        ],
        'js'  => [
            'bbcodes/fontfamily/fontfamily.js',
        ],
    ],

    // ВАЖНО: мост фронт <-> бек
    'parser' => 'fontfamily.php',
];

<?php

return [
    'id'    => 'jscolorpiker',
    'title' => 'Расширенный выбор цвета (jscolor)',
    'tags'  => ['color'],
    'parser'=> 'jscolorpiker.php',

    'buttons' => [
        [
            'cmd'     => 'color',
            'name'    => 'color',
            'title'   => 'Цвет (расширенный)',
            'icon'    => 'img/color.svg',
            'handler' => 'jscolorpiker',
        ],
    ],

    'assets' => [
        'css' => [
            'bbcodes/jscolorpiker/jscolorpiker.css',
        ],
        'js'  => [
            'bbcodes/jscolorpiker/jscolor.js',
            'bbcodes/jscolorpiker/jscolorpiker.js',
        ],
    ],
];

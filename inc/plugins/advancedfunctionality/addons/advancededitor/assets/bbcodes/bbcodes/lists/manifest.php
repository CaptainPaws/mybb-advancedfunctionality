<?php

return [
    'id'    => 'lists',
    'title' => 'Списки',

    'tags' => ['ul', 'ol', 'li'],

    'buttons' => [
        [
            'cmd'     => 'af_ul_disc',
            'name'    => 'lists_ul_disc',
            'title'   => 'Список: точки (•)',
            'handler' => 'lists',
        ],
        [
            'cmd'     => 'af_ul_square',
            'name'    => 'lists_ul_square',
            'title'   => 'Список: квадраты (■)',
            'handler' => 'lists',
        ],
        [
            'cmd'     => 'af_ul_decimal',
            'name'    => 'lists_ul_decimal',
            'title'   => 'Список: нумерация (1,2,3)',
            'handler' => 'lists',
        ],
        [
            'cmd'     => 'af_ul_upper_roman',
            'name'    => 'lists_ul_upper_roman',
            'title'   => 'Список: римские (I, II, III)',
            'handler' => 'lists',
        ],
        [
            'cmd'     => 'af_ul_upper_alpha',
            'name'    => 'lists_ul_upper_alpha',
            'title'   => 'Список: буквы (A, B, C)',
            'handler' => 'lists',
        ],
        [
            'cmd'     => 'af_ul_lower_alpha',
            'name'    => 'lists_ul_lower_alpha',
            'title'   => 'Список: буквы (a, b, c)',
            'handler' => 'lists',
        ],
    ],

    'assets' => [
        'css' => [
            'bbcodes/lists/lists.css',
        ],
        'js'  => [
            'bbcodes/lists/lists.js',
        ],
    ],

    'parser' => 'lists.php',
];

<?php

return [
    'id'    => 'mark',
    'title' => 'Маркер',
    'tags'  => ['mark'],

    'buttons' => [
        [
            'cmd'     => 'af_mark',
            'name'    => 'mark',
            'title'   => 'Маркер',
            'handler' => 'mark',
            'opentag' => '[mark]',
            'closetag'=> '[/mark]',
        ],
    ],

    'assets' => [
        'css' => [
            'bbcodes/mark/mark.css',
        ],
        'js' => [
            'bbcodes/mark/mark.js',
        ],
    ],

    'parser' => 'mark.php',
];

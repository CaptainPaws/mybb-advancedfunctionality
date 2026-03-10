<?php

return [
    'id'    => 'align',
    'title' => 'Выравнивание текста',

    'tags' => ['align'],

    'assets' => [
        'css' => [
            'bbcodes/align/align.css',
        ],
        'js'  => [
            'bbcodes/align/align.js',
        ],
    ],

    'parser' => 'align.php',
];
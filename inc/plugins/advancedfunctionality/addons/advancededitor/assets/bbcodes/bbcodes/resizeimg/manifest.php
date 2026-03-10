<?php

return [
    'id'    => 'resizeimg',
    'title' => 'Ресайз изображений',

    // нужно ловить именно img, потому что теперь формат хранения:
    // [img width=200 height=200]...[/img]
    'tags' => ['img'],

    'buttons' => [],

    'assets' => [
        'css' => [
            'bbcodes/resizeimg/resizeimg.css',
        ],
        'js'  => [
            'bbcodes/resizeimg/resizeimg.js',
        ],
    ],

    'parser' => 'resizeimg.php',
];
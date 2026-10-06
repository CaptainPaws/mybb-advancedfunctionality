<?php

return [
    'view_assets' => ['js' => [], 'css' => ['bbcodes/resizeimg/resizeimg_view.css']],
    'view_markers' => ['af-resizeimg'],
    'runtime' => ['activation' => 'click', 'requires' => []],
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
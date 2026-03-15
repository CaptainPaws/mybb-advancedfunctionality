<?php

return [
    'id' => 'stikers',
    'title' => 'Стикеры',
    'tags' => [],
    'buttons' => [
        [
            'cmd' => 'af_stikers',
            'name' => 'stikers',
            'title' => 'Стикеры',
            'icon' => 'img/img/image.svg',
            'handler' => 'stikers',
        ],
    ],
    'assets' => [
        'css' => ['bbcodes/stikers/stikers.css'],
        'js' => ['bbcodes/stikers/stikers.js'],
    ],
];

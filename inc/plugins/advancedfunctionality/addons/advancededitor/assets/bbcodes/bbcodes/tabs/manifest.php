<?php

return [
    'id'    => 'tabs',
    'title' => 'Табы',
    'tags'  => ['tabs', 'tab'],

    'buttons' => [
        [
            'cmd'     => 'af_tabs',
            'name'    => 'tabs',
            'title'   => 'Табы',
            'icon'    => 'img/tablebb.svg',
            'handler' => 'tabs',
        ],
    ],

    'assets' => [
        'css' => [
            'bbcodes/tabs/tabs.css',
        ],
        'js'  => [
            'bbcodes/tabs/tabs.js',
        ],
    ],

    'parser' => 'tabs.php',
];

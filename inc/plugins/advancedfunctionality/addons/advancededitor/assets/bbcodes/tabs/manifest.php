<?php

return [
    'view_markers' => ['data-af-tabs-root'],
    'view_assets' => ['js' => ['bbcodes/tabs/tabs_view.js'], 'css' => ['bbcodes/tabs/tabs_view.css']],
    'runtime' => ['view' => true, 'activation' => 'click', 'requires' => [], 'editor' => true],
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

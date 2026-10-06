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
            'iconSvg'  => '<svg class="af-ae-menu-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 8V5h5v3M9 8V5h5v3M14 8V5h6v15H4V8h16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
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

<?php

return [
    'view_assets' => ['js' => [], 'css' => ['bbcodes/mark/mark_view.css']],
    'view_markers' => ['af-ae-mark-render'],
    'runtime' => ['activation' => 'click', 'requires' => []],
    'id'    => 'mark',
    'title' => 'Маркер',
    'tags'  => ['mark'],

    'buttons' => [
        [
            'cmd'      => 'af_mark',
            'name'     => 'mark',
            'title'    => 'Маркер',
            'iconSvg'  => '<svg class="af-ae-menu-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 16.5L15.5 6l3 3L8 19.5 4.5 20zM13.5 8l3 3M3 21h9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'handler'  => 'mark',
            'opentag'  => '[mark]',
            'closetag' => '[/mark]',
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

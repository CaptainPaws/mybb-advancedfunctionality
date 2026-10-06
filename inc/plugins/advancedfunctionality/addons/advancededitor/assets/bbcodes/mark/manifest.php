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
            'iconClass'=> 'fa-solid fa-highlighter',
            'iconGlyph'=> "\u{F591}",
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

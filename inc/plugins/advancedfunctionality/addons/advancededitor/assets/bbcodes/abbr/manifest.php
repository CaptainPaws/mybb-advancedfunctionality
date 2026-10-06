<?php

return [
    'view_assets' => ['js' => [], 'css' => ['bbcodes/abbr/abbr_view.css']],
    'view_markers' => ['af-bb-abbr'],
    'runtime' => ['activation' => 'click', 'requires' => []],
    'id'    => 'abbr',
    'title' => 'Пояснение',
    'tags'  => ['abbr'],

    'buttons' => [
        [
            'cmd'      => 'af_abbr',
            'name'     => 'abbr',
            'title'    => 'Поясняющий текст',
            'iconSvg'  => '<svg class="af-ae-menu-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 10.5v6M12 7.5h.01" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
            'hint'     => 'Вставить [abbr="подсказка"]текст[/abbr]',
            'handler'  => 'abbr',
            'opentag'  => '[abbr=""]',
            'closetag' => '[/abbr]',
        ],
    ],

    'assets' => [
        'css' => [
            'bbcodes/abbr/abbr.css',
        ],
        'js' => [
            'bbcodes/abbr/abbr.js',
        ],
    ],

    'parser' => 'abbr.php',
];

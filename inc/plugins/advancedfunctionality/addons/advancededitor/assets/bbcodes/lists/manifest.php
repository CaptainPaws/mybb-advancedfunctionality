<?php

return [
    'view_assets' => ['js' => [], 'css' => ['bbcodes/lists/lists_view.css']],
    'view_markers' => ['af-ae-list'],
    'runtime' => ['activation' => 'click', 'requires' => []],
    'id'    => 'lists',
    'title' => 'Списки',

    'tags' => ['ul', 'ol', 'li'],

    'buttons' => [
        [
            'cmd'     => 'af_ul_disc',
            'name'    => 'lists_ul_disc',
            'title'   => 'Список: точки (•)',
            'iconSvg'  => '<svg class="af-ae-menu-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="5" cy="6" r="1.4" fill="currentColor"/><circle cx="5" cy="12" r="1.4" fill="currentColor"/><circle cx="5" cy="18" r="1.4" fill="currentColor"/><path d="M9 6h11M9 12h11M9 18h11" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
            'handler' => 'lists',
        ],
        [
            'cmd'     => 'af_ul_square',
            'name'    => 'lists_ul_square',
            'title'   => 'Список: квадраты (■)',
            'iconSvg'  => '<svg class="af-ae-menu-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3.6" y="4.6" width="2.8" height="2.8" rx=".3" fill="currentColor"/><rect x="3.6" y="10.6" width="2.8" height="2.8" rx=".3" fill="currentColor"/><rect x="3.6" y="16.6" width="2.8" height="2.8" rx=".3" fill="currentColor"/><path d="M9 6h11M9 12h11M9 18h11" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
            'handler' => 'lists',
        ],
        [
            'cmd'     => 'af_ul_decimal',
            'name'    => 'lists_ul_decimal',
            'title'   => 'Список: нумерация (1,2,3)',
            'iconSvg'  => '<svg class="af-ae-menu-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4.5 4.5h1.5v4M4.3 8.5h3M4.2 12.7c.3-1 1.1-1.5 2-1.5 1.1 0 1.8.6 1.8 1.5 0 1-1 1.6-2.9 3H8" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 6h10M10 12h10M10 18h10" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
            'handler' => 'lists',
        ],
        [
            'cmd'     => 'af_ul_upper_roman',
            'name'    => 'lists_ul_upper_roman',
            'title'   => 'Список: римские (I, II, III)',
            'iconSvg'  => '<svg class="af-ae-menu-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4.5 4.5v4M7 4.5v4M4.5 11v4M7 11v4M9.5 11v4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M12 6h8M12 12h8M12 18h8" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
            'handler' => 'lists',
        ],
        [
            'cmd'     => 'af_ul_upper_alpha',
            'name'    => 'lists_ul_upper_alpha',
            'title'   => 'Список: буквы (A, B, C)',
            'iconSvg'  => '<svg class="af-ae-menu-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.5 8.5l2-5 2 5M4.2 6.7h2.6M4 16v-5h2.1c1.2 0 1.9.5 1.9 1.3 0 .7-.5 1.1-1.1 1.3.8.2 1.3.7 1.3 1.4 0 .7-.6 1-2 1H4z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M11 6h9M11 12h9M11 18h9" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
            'handler' => 'lists',
        ],
        [
            'cmd'     => 'af_ul_lower_alpha',
            'name'    => 'lists_ul_lower_alpha',
            'title'   => 'Список: буквы (a, b, c)',
            'iconSvg'  => '<svg class="af-ae-menu-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7.7 7.2c-.5-.5-1.1-.8-1.8-.8-1.3 0-2.2.8-2.2 2s.9 2 2.2 2c.7 0 1.4-.3 1.8-.8V6.4M4 16.8V12h2c1.5 0 2.5.9 2.5 2.4S7.5 16.8 6 16.8H4z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/><path d="M11 6h9M11 12h9M11 18h9" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>',
            'handler' => 'lists',
        ],
    ],

    'assets' => [
        'css' => [
            'bbcodes/lists/lists.css',
        ],
        'js'  => [
            'bbcodes/lists/lists.js',
        ],
    ],

    'parser' => 'lists.php',
];

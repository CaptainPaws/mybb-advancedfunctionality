<?php

return [
    'view_markers' => ['data-af-accordion'],
    'view_assets' => ['js' => ['bbcodes/accordion/accordion_view.js'], 'css' => ['bbcodes/accordion/accordion_view.css']],
    'runtime' => ['view' => true, 'activation' => 'click', 'requires' => [], 'editor' => true],
    'id'    => 'accordion',
    'title' => 'Accordion',
    'tags'  => ['accordion', 'accitem'],

    'buttons' => [
        [
            'cmd'      => 'af_accordion',
            'name'     => 'accordion',
            'title'    => 'Аккордеон',
            'iconSvg'  => '<svg class="af-ae-menu-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="4" y="4.5" width="16" height="4" rx="1" fill="none" stroke="currentColor" stroke-width="1.6"/><rect x="4" y="10" width="16" height="4" rx="1" fill="none" stroke="currentColor" stroke-width="1.6"/><rect x="4" y="15.5" width="16" height="4" rx="1" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M17 6.5l1 1 1-1M17 12l1 1 1-1M17 17.5l1 1 1-1" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'hint'     => 'Вставить [accordion] с двумя [accitem]',
            'handler'  => '',
            'opentag'  => '[accordion]
[accitem title="Заголовок 1"]
Контент 1
[/accitem]
[accitem title="Заголовок 2"]
Контент 2
[/accitem]
[/accordion]',
            'closetag' => '',
        ],
    ],

    'assets' => [
        'css' => [],
        'js' => [],
    ],

    'parser' => 'accordion.php',
];

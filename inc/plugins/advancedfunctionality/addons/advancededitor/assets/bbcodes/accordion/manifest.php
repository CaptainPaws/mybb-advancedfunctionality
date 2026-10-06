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
            'iconClass'=> 'fa-solid fa-bars-staggered',
            'hint'     => 'Вставить [accordion] с двумя [accitem]',
            'icon'     => 'img/starmenu.svg',
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

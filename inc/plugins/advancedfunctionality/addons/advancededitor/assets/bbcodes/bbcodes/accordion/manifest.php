<?php

return [
    'id'    => 'accordion',
    'title' => 'Accordion',
    'tags'  => ['accordion', 'accitem'],

    'buttons' => [
        [
            'cmd'      => 'af_accordion',
            'name'     => 'accordion',
            'title'    => 'Аккордеон',
            'hint'     => 'Вставить [accordion] с двумя [accitem]',
            'icon'     => 'img/starmenu.svg',
            'handler'  => 'accordion',
            'opentag'  => '[accordion direction="down"]',
            'closetag' => '[/accordion]',
        ],
    ],

    'assets' => [
        'css' => [
            'bbcodes/bbcodes/accordion/accordion.css',
        ],
        'js' => [
            'bbcodes/bbcodes/accordion/accordion.js',
        ],
    ],

    'parser' => 'accordion.php',
];

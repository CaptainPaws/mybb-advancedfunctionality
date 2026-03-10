<?php

return [
    'id'    => 'tquote',
    'title' => 'Типографическая цитата',

    // ВАЖНО:
    // больше НЕ цепляемся за quote.
    // tquote живёт как отдельный BBCode.
    'tags'  => ['tquote'],

    'buttons' => [
        [
            'cmd'     => 'af_tquote',
            'name'    => 'tquote',
            'title'   => 'Типографическая цитата',
            'icon'    => 'img/quote.svg',
            'handler' => 'tquote',
        ],
    ],

    'assets' => [
        'css' => [
            'bbcodes/tquote/tquote.css',
        ],
        'js'  => [
            'bbcodes/tquote/tquote.js',
        ],
    ],

    'parser' => 'tquote.php',
];

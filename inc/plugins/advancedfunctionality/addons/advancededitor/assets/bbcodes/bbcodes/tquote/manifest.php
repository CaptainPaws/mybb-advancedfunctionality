<?php

return [
    'id'    => 'tquote',
    'title' => 'Типографическая цитата',
    'tags'  => ['tquote', 'quote'],

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

    // server-side parser пакета
    'parser' => 'tquote.php',
];

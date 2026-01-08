<?php

return [
    'id'    => 'spoiler',
    'title' => 'Спойлер',

    // по этим тегам диспетчер решает, есть ли смысл запускать parser
    'tags' => ['spoiler'],

    'buttons' => [
        [
            'cmd'     => 'af_spoiler',
            'name'    => 'spoiler',
            'title'   => 'Спойлер',
            'icon'    => 'bbcodes/spoiler/icon.svg', // глазик с перечёркиванием
            'handler' => 'spoiler',
        ],
    ],

    'assets' => [
        'css' => [
            'bbcodes/spoiler/spoiler.css',
        ],
        'js'  => [
            'bbcodes/spoiler/spoiler.js',
        ],
    ],

    // server-side (хуки parse_message_start/end)
    'parser' => 'server.php',
];

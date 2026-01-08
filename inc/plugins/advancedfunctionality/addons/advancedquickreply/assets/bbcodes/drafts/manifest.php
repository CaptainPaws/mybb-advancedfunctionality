<?php

return [
    'id'    => 'drafts',
    'title' => 'Черновики',

    // контентный пак
    'tags' => ['drafts'],

    // КНОПКА ДЛЯ КОНСТРУКТОРА ТУЛБАРА
    'buttons' => [
        [
            'cmd'     => 'af_drafts',
            'name'    => 'drafts',
            'title'   => 'Черновики',
            // путь внутри assets/ (иконка лежит в assets/img/)
            'icon'    => 'img/drafts.svg',
            'handler' => 'drafts',
        ],
    ],

    // ФРОНТ-РЕСУРСЫ ПАКА
    'assets' => [
        'css' => [
            'bbcodes/drafts/drafts.css',
        ],
        'js'  => [
            'bbcodes/drafts/drafts.js',
        ],
    ],

    // parser не нужен
];

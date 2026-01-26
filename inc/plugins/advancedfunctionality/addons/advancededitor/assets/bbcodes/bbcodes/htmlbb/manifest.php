<?php

return [
    'id'    => 'htmlbb',
    'title' => 'HTML-блок',

    // Диспетчер AE ищет эти теги, чтобы понять, что этот пакет нужен
    'tags' => ['html', 'htmlbb'],

    // КНОПКА ДЛЯ КОНСТРУКТОРА ТУЛБАРА
    'buttons' => [
        [
            'cmd'     => 'af_htmlbb',
            'name'    => 'htmlbb',
            'title'   => 'HTML-блок',
            'icon'    => 'img/source.svg',
            'handler' => 'htmlbb',
        ],
    ],

    // ФРОНТ-РЕСУРСЫ
    'assets' => [
        'css' => [
            'bbcodes/htmlbb/htmlbb.css',
        ],
        'js' => [
            'bbcodes/htmlbb/htmlbb.js',
        ],
    ],

    // PHP-часть (payload+parser)
    'parser' => 'htmlbb.php',
];

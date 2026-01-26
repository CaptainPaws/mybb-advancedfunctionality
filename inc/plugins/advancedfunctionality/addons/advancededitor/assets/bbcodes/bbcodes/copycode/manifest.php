<?php

return [
    'id'    => 'copycode',
    'title' => 'Копирование кода',
    'tags'  => ['code', 'copy'],

    // Кнопку в тулбар не делаем — это enhancement при рендере постов.
    'buttons' => [],

    'assets' => [
        'css' => [
            'bbcodes/copycode/copycode.css',
        ],
        'js'  => [
            'bbcodes/copycode/copycode.js',
        ],
    ],

    // серверный парсер не нужен
    'parser' => null,
];

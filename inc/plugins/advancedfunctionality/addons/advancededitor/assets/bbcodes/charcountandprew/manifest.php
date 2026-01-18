<?php

return [
    'id'    => 'charcountandprew',
    'title' => 'Символы и превью (в форме + в постах)',

    // Теги тут не нужны — это не парсер BBCode, а фронтовый функционал.
    'tags' => [],

    // Это не BB-кнопка — оставляем пусто (ничего не ломает).
    'buttons' => [],

    'assets' => [
        'css' => [
            'bbcodes/charcountandprew/charcountandprew.css',
        ],
        'js'  => [
            'bbcodes/charcountandprew/charcountandprew.js',
        ],
    ],
];

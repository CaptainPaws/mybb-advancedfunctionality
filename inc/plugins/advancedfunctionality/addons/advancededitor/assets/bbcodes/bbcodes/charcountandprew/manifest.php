<?php

return [
    'view_assets' => ['js' => ['bbcodes/bbcodes/charcountandprew/charcountandprew_view.js'], 'css' => ['bbcodes/bbcodes/charcountandprew/charcountandprew_view.css']],
    'view_markers' => ['post_body'],
    'runtime' => ['activation' => 'click', 'triggers' => ['previewpost'], 'requires' => []],
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

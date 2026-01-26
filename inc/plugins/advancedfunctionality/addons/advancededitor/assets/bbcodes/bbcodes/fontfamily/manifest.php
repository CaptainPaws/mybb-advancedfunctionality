<?php

return [
    'id'    => 'fontfamily',
    'title' => 'Шрифт (семейства)',

    // по этим тегам диспетчер понимает, что пак относится к контенту
    'tags' => ['font'],

    // КНОПКА ДЛЯ КОНСТРУКТОРА ТУЛБАРА
    'buttons' => [
        [
            // ВАЖНО: намеренно используем cmd = 'font',
            // чтобы:
            // 1) пак считался "включенным" даже в дефолтной раскладке (там уже есть 'font')
            // 2) мы полностью перехватили стандартную команду SCEditor "font"
            'cmd'     => 'af_font',
            'name'    => 'fontfamily',
            'title'   => 'Шрифт (семейства)',
            'icon'    => 'img/font.svg',
            'handler' => 'fontfamily',
        ],
    ],

    // ФРОНТ-РЕСУРСЫ
    'assets' => [
        'css' => [
            'bbcodes/fontfamily/fontfamily.css',
        ],
        'js'  => [
            'bbcodes/fontfamily/fontfamily.js',
        ],
    ],
];

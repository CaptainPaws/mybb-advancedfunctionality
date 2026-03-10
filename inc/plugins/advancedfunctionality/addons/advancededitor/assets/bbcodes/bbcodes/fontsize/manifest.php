<?php

return [
    'id'    => 'fontsize',
    'title' => 'Размер шрифта (px)',

    // По этим тегам диспетчер AE понимает, что пак относится к контенту
    'tags' => ['size'],

    // ВАЖНО: подключаем PHP-мост
    'parser' => 'fontsize.php',

    // Кнопка для конструктора тулбара
    'buttons' => [
        [
            'cmd'     => 'af_fontsize',
            'name'    => 'fontsize',
            'title'   => 'Размер шрифта (8–36px)',
            'icon'    => 'img/size.svg',
            'handler' => 'fontsize',
        ],
    ],

    'assets' => [
        'css' => [
            'bbcodes/fontsize/fontsize.css',
        ],
        'js'  => [
            'bbcodes/fontsize/fontsize.js',
        ],
    ],
];

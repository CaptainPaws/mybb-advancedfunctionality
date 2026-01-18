<?php

return [
    'id'    => 'fontsize',
    'title' => 'Размер шрифта (px)',

    // по этим тегам диспетчер понимает, что пак относится к контенту
    'tags' => ['size'],

    // КНОПКА ДЛЯ КОНСТРУКТОРА ТУЛБАРА (чтоб ты видела её в ACP)
    'buttons' => [
        [
            // ВАЖНО: делаем свой cmd, чтобы не конфликтовать с базовым `size`
            // (но твой JS всё равно патчит базовый `size`, когда загрузится)
            'cmd'     => 'af_fontsize',
            'name'    => 'fontsize',
            'title'   => 'Размер шрифта (8–36px)',
            'icon'    => 'img/size.svg',      // <-- твой путь внутри assets/
            'handler' => 'fontsize',
        ],
    ],

    // ФРОНТ-РЕСУРСЫ ПАКА
    'assets' => [
        'css' => [
            'bbcodes/fontsize/fontsize.css',
        ],
        'js'  => [
            'bbcodes/fontsize/fontsize.js',
        ],
    ],

    // parser не нужен — ты рендеришь через MyCode (и это ок)
];

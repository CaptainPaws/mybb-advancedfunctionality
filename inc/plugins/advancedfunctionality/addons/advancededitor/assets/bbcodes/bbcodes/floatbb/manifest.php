<?php

return [
    'id'    => 'floatbb',
    'title' => 'Обтекание (слева/справа)',

    // Диспетчер AE ищет эти теги в тексте, чтобы понять, запускать парсер
    'tags' => ['float', 'floatbb'],

    // КНОПКА ДЛЯ КОНСТРУКТОРА ТУЛБАРА
    'buttons' => [
        [
            'cmd'     => 'af_floatbb',
            'name'    => 'floatbb',
            'title'   => 'Обтекание (слева/справа)',
            'icon'    => 'img/floatbb.svg',
            'handler' => 'floatbb',
        ],
    ],

    // ФРОНТ-РЕСУРСЫ
    'assets' => [
        'css' => [
            'bbcodes/floatbb/floatbb.css',
        ],
        'js'  => [
            'bbcodes/floatbb/floatbb.js',
        ],
    ],

    // Парсер (подхватит общий dispatch в parse_message_end)
    'parser' => 'floatbb.php',
];

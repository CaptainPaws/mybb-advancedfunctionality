<?php

return [
    'id'    => 'indent',
    'title' => 'Отступ абзаца (1–3em)',

    // Диспетчер AE ищет эти теги в тексте, чтобы понять, запускать парсер
    'tags' => ['indent'],

    // КНОПКА ДЛЯ КОНСТРУКТОРА ТУЛБАРА
    'buttons' => [
        [
            'cmd'     => 'af_indent',
            'name'    => 'indent',
            'title'   => 'Отступ (1–3em)',
            'icon'    => 'img/indent.svg',
            'handler' => 'indent',
        ],
    ],

    // ФРОНТ-РЕСУРСЫ
    'assets' => [
        'css' => [
            'bbcodes/indent/indent.css',
        ],
        'js'  => [
            'bbcodes/indent/indent.js',
        ],
    ],

    // ВАЖНО: парсер пакета (его подхватит твой общий dispatch в parse_message_end)
    'parser' => 'indent.php',
];

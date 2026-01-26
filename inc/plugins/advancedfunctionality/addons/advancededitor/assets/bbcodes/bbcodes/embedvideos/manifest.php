<?php

return [
    'id'    => 'embedvideos',
    'title' => 'Вставить видео',

    // Диспетчер AE ищет эти теги, чтобы понять, что этот пакет нужен
    'tags' => ['video', 'embedvideos'],

    // КНОПКА ДЛЯ КОНСТРУКТОРА ТУЛБАРА
    'buttons' => [
        [
            'cmd'     => 'af_embedvideos',
            'name'    => 'embedvideos',
            'title'   => 'Вставить видео',
            'icon'    => 'img/embedvideos.svg',
            'handler' => 'embedvideos',
        ],
    ],

    // ФРОНТ-РЕСУРСЫ
    'assets' => [
        'css' => [
            'bbcodes/embedvideos/embedvideos.css',
        ],
        'js' => [
            'bbcodes/embedvideos/embedvideos.js',
        ],
    ],

    // PHP-часть (payload)
    'parser' => 'embedvideos.php',

    // UI providers (для dropdown)
    'providers' => [
        'youtube'  => ['label' => 'YouTube',   'domains' => ['youtube.com', 'youtu.be']],
        'rutube'   => ['label' => 'RuTube',    'domains' => ['rutube.ru']],
        'coub'     => ['label' => 'Coub',      'domains' => ['coub.com']],
        'kodik'    => ['label' => 'Kodik',     'domains' => ['kodik.info']],
        'tme'      => ['label' => 'Telegram',  'domains' => ['t.me']],
        'other'    => ['label' => 'Другой',    'domains' => []],
    ],
];

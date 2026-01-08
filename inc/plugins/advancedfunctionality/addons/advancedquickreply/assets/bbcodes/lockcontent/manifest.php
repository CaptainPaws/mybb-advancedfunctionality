<?php

return [
    'id'    => 'lockcontent',
    'title' => 'Скрыть содержимое',
    'tags' => ['hide'],

    // кнопки, которые пак отдаёт в тулбар
    'buttons' => [
        [
            'cmd'     => 'af_lockcontent',
            'name'    => 'lockcontent',
            'title'   => 'Скрыть содержимое',

            // Иконка: у тебя уже лежит в assets/img/locked.svg
            // normalize_icon_url умеет "img/..." и превращает в абсолютный URL.
            // (НЕ надо держать абсолютный https://... в манифесте)
            'icon'    => 'img/locked.svg',

            // handler = ключ JS-хендлера (lockcontent.js регистрирует себя как "lockcontent")
            'handler' => 'lockcontent',
        ],
    ],

    // ассеты пакета (относительно assets/)
    'assets' => [
        'css' => [
            'bbcodes/lockcontent/lockcontent.css',
        ],
        'js'  => [
            'bbcodes/lockcontent/lockcontent.js',
        ],
    ],

    // php-парсер пакета (относительно папки pack)
    // у тебя он называется server.php — и это ОК, дискавери поддерживает любое имя файла
    'parser' => 'server.php',
];

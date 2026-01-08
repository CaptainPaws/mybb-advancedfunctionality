<?php

return [
    'id'          => 'indexredirect',
    'name'        => 'Редирект главной (index.php → /)',
    'description' => 'Делает редирект только при прямом заходе на /index.php. Не трогает остальные ссылки.',
    'version'     => '1.0.0',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',

    // bootstrap-файл аддона
    'bootstrap'   => 'indexredirect.php',

    // языки (ядро AF подхватит и сгенерит RU/EN при синхронизации)
    'lang' => [
        'front' => [
            'af_indexredirect_name'        => 'Редирект главной (index.php → /)',
            'af_indexredirect_description' => 'Редиректит только прямой URL /index.php на корень сайта.',
        ],
        'admin' => [
            'af_indexredirect_group'        => 'Редирект главной (index.php → /)',
            'af_indexredirect_group_desc'   => 'Настройки аддона редиректа главной страницы.',
            'af_indexredirect_enabled'      => 'Включить редирект /index.php → /',
            'af_indexredirect_enabled_desc' => 'Если включено — прямой запрос /index.php будет перенаправлен на /.',
        ],
    ],

    // НЕТ 'admin' — чтобы AF не добавлял пункт в боковое меню и не ждал контроллер
];

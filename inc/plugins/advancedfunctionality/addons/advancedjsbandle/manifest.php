<?php

return [
    'id'        => 'advancedjsbandle',
    'name'      => 'JS Bundle',
    'version'   => '1.0.2',
    'author'      => 'CaptainPaws',
    'website'     => 'https://github.com/CaptainPaws',
    'bootstrap' => 'advancedjsbandle.php',

    'lang' => [
        'front' => [
            'name'        => 'JS Bundle',
            'description' => 'Подключает JS-файлы из assets аддона в headerinclude после {$stylesheets}.',
        ],
        'admin' => [
            'group'        => 'JS Bundle',
            'group_desc'   => 'Автоподключение JS из папки assets в headerinclude.',
            'enabled'      => 'Включить JS Bundle',
            'enabled_desc' => 'Если включено — аддон синхронизирует блок скриптов в шаблоне headerinclude.',
        ],
    ],
];

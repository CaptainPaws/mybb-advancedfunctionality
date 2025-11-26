<?php

return [
    'id'          => 'advancedmentions',
    'name'        => 'Advanced Mentions',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',
    'description' => 'Упоминания пользователей по никнейму с автодополнением и интеграцией с Advanced Alerts.',
    'version'     => '1.0.0',
    'bootstrap'   => 'advancedmentions.php',

    // Языки: ядро AF создаст файлы автоматически
    'lang' => [
        'russian' => [
            'front' => [
                'af_advancedmentions_name'        => 'Advanced Mentions',
                'af_advancedmentions_description' => 'Упоминания пользователей по @username с автодополнением.',
            ],
            'admin' => [
                'af_advancedmentions_group'       => 'AF: Advanced Mentions',
                'af_advancedmentions_group_desc'  => 'Настройки упоминаний пользователей (@username) во фронтенде.',
                'af_advancedmentions_enabled'     => 'Включить Advanced Mentions',
                'af_advancedmentions_enabled_desc'=> 'Если включено, пользователи смогут упоминать друг друга по @username.',
                'af_advancedmentions_click_insert'      => 'Клик по нику вставляет упоминание',
                'af_advancedmentions_click_insert_desc' => 'Если включено, клик по нику в постбите вставляет @"username" в форму ответа вместо перехода в профиль.',
                'af_advancedmentions_suggest_min'       => 'Минимум символов для подсказок',
                'af_advancedmentions_suggest_min_desc'  => 'Сколько символов после @ нужно ввести, чтобы показать список пользователей (по умолчанию 2).',
            ],
        ],
        'english' => [
            'front' => [
                'af_advancedmentions_name'        => 'Advanced Mentions',
                'af_advancedmentions_description' => 'User mentions using @username with autocomplete.',
            ],
            'admin' => [
                'af_advancedmentions_group'       => 'AF: Advanced Mentions',
                'af_advancedmentions_group_desc'  => 'Settings for @username mentions on the frontend.',
                'af_advancedmentions_enabled'     => 'Enable Advanced Mentions',
                'af_advancedmentions_enabled_desc'=> 'If enabled, users can mention each other using @username.',
                'af_advancedmentions_click_insert'      => 'Click on username inserts mention',
                'af_advancedmentions_click_insert_desc' => 'If enabled, clicking a username in postbit inserts @"username" into reply form instead of going to profile.',
                'af_advancedmentions_suggest_min'       => 'Minimum characters for suggestions',
                'af_advancedmentions_suggest_min_desc'  => 'How many characters after @ are required to show suggestions (default 2).',
            ],
        ],
    ],
];

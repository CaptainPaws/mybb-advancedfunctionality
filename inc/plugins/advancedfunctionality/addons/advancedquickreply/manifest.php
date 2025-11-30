<?php

return [
    'id'          => 'advancedquickreply',
    'name'        => 'Advanced QuickReply',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',
    'description' => 'Заменяет урезанную форму быстрого ответа на полный редактор MyBB.',
    'version'     => '1.0.0',
    'bootstrap'   => 'advancedquickreply.php',

    // Языки — ядро AF сгенерит файлы RU/EN для фронта и админки
    'lang'        => [
        'russian' => [
            'front' => [
                'af_advancedquickreply_name'        => 'Advanced QuickReply',
                'af_advancedquickreply_description' => 'Полный редактор в форме быстрого ответа.',
            ],
            'admin' => [
                'af_advancedquickreply_group'        => 'AF: Advanced QuickReply',
                'af_advancedquickreply_group_desc'   => 'Настройки расширенного быстрого ответа.',
                'af_advancedquickreply_enabled'      => 'Включить Advanced QuickReply',
                'af_advancedquickreply_enabled_desc' => 'Если включено, в форме быстрого ответа отображается полный редактор с BB-кодами.',
            ],
        ],
        'english' => [
            'front' => [
                'af_advancedquickreply_name'        => 'Advanced QuickReply',
                'af_advancedquickreply_description' => 'Full editor in the quick reply form.',
            ],
            'admin' => [
                'af_advancedquickreply_group'        => 'AF: Advanced QuickReply',
                'af_advancedquickreply_group_desc'   => 'Settings for the enhanced quick reply.',
                'af_advancedquickreply_enabled'      => 'Enable Advanced QuickReply',
                'af_advancedquickreply_enabled_desc' => 'If enabled, the full editor with all BBCodes is shown in the quick reply form.',
            ],
        ],
    ],

    // Отдельной страницы в админке AF больше нет —
    // включение/выключение идёт через стандартную группу настроек MyBB.
];

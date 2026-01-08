<?php
/**
 * AF Addon manifest: AdvancedOnlineAvatar
 */

return [
    'id'        => 'advancedonlineavatar',
    'name'      => 'AdvancedOnlineAvatar',
    'version'   => '1.0.0',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',
    'bootstrap' => 'advancedonlineavatar.php',

    'description' => 'Добавляет аватары на странице who is online (/online.php).',

    // (опционально) для автогенерации языков ядром AF
    'lang' => [
        'front' => [
            'af_advancedonlineavatar_name'        => 'Аватары онлайн',
            'af_advancedonlineavatar_description' => 'Показывает аватары пользователей на странице “Кто онлайн”.',
        ],
        'admin' => [
            'af_advancedonlineavatar_group'       => 'Аватары онлайн',
            'af_advancedonlineavatar_group_desc'  => 'Настройки аддона AdvancedOnlineAvatar.',
        ],
    ],
];

<?php

return [
    'id'        => 'headerwelcomeavatar',
    'name'      => 'Header Welcome Avatar',
    'version'   => '1.0.0',
    'author'      => 'CaptainPaws',
    'authorsite'  => '',
    'bootstrap' => 'headerwelcomeavatar.php',

    // Языковые ключи (AF ядро сгенерит RU/EN front+admin)
    'lang' => [
        // front
        'af_headerwelcomeavatar_name'        => 'Аватар в приветствии',
        'af_headerwelcomeavatar_description' => 'Серверная вставка аватара и перестройка welcome-блока в хедере (без JS-гадания).',

        // admin/settings
        'af_headerwelcomeavatar_group'       => 'Аватар в приветствии',
        'af_headerwelcomeavatar_group_desc'  => 'Настройки аддона: аватар и кнопки в блоке приветствия.',

        'af_headerwelcomeavatar_enabled'      => 'Включить аддон',
        'af_headerwelcomeavatar_enabled_desc' => 'Если выключено — ничего не меняем в выводе.',

        'af_headerwelcomeavatar_inline_css'      => 'Встраивать CSS inline',
        'af_headerwelcomeavatar_inline_css_desc' => 'Рекомендуется: не зависит от доступности файлов assets по вебу.',

        'af_headerwelcomeavatar_load_js'      => 'Подключать JS',
        'af_headerwelcomeavatar_load_js_desc' => 'По умолчанию не нужно. Включай только если добавишь логику в JS.',

        'af_headerwelcomeavatar_inline_js'      => 'Встраивать JS inline',
        'af_headerwelcomeavatar_inline_js_desc' => 'Если JS включён: inline безопаснее, чем внешний файл.',
    ],
];

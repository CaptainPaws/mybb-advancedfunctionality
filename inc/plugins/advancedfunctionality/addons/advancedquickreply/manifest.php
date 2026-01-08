<?php

return [
    'id'          => 'advancedquickreply',
    'name'        => 'Advanced Quick Reply',
    'description' => 'Расширяет SCEditor: кастомные кнопки, управление в ACP, опциональное выключение пользователем.',
    'version'     => '1.0.0',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',

    'bootstrap'   => 'advancedquickreply.php',

    // AF router (левое меню / роутинг админки)
    'admin' => [
        'slug'       => 'advancedquickreply',
        'controller' => 'admin.php',
    ],

    // языки (ядро AF подхватит и сгенерит RU/EN)
    'lang' => [
        'front' => [
            'af_advancedquickreply_name'        => 'Advanced Quick Reply',
            'af_advancedquickreply_description' => 'Кастомные кнопки редактора в стиле MHEditor.',

            'af_advancedquickreply_useeditor'   => 'Включить расширенный редактор (Advanced Quick Reply)',
        ],
        'admin' => [
            'af_advancedquickreply_group'       => 'Advanced Quick Reply',
            'af_advancedquickreply_group_desc'  => 'Настройка кнопок редактора и поведения на фронте.',

            'af_advancedquickreply_enabled'      => 'Включить аддон',
            'af_advancedquickreply_enabled_desc' => 'Если выключено — кнопки не встраиваются, админка доступна.',

            'af_advancedquickreply_user_toggle'      => 'Разрешить пользователям выключать редактор',
            'af_advancedquickreply_user_toggle_desc' => 'Добавляет чекбокс в UserCP (как у MHEditor).',

            'af_advancedquickreply_apply_where'      => 'Где применять',
            'af_advancedquickreply_apply_where_desc' => 'both = везде, quickreply = только быстрый ответ, full = только полные формы.',
        ],
    ],
];

<?php
return [
    'id'          => 'advancedrules',
    'name'        => 'Advanced Rules',
    'version'     => '1.0.0',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',
    'description' => 'Страница правил форума с категориями. Управление: Админка → Расширенный функционал → Правила.',
    // Файл bootstrap относительно каталога аддона:
    'bootstrap'   => 'advancedrules.php',
    // Описываем, что есть внутренняя админ-страница (для левого меню AF)
    'admin' => [
        'slug'  => 'advancedrules',
        'title' => 'Правила',
        // контроллер админки (относительно каталога аддона)
        'controller' => 'admin.php',
    ],
    // Языковые строки для автогенерации ядром AF (опционально)
    'lang' => [
        'russian' => [
            'front' => [
                'af_advancedrules_name'        => 'Advanced Rules',
                'af_advancedrules_description' => 'Страница правил форума.',
            ],
            'admin' => [
                'af_advancedrules_group'       => 'AF: Правила',
                'af_advancedrules_group_desc'  => 'Настройки внутреннего аддона «Правила».',
                'af_advancedrules_enabled'     => 'Включить страницу правил',
                'af_advancedrules_enabled_desc'=> 'Да/Нет',
                'af_advancedrules_nav_text'    => 'Название ссылки в меню',
                'af_advancedrules_nav_text_desc'=> 'Текст пункта меню (по умолчанию «Правила»).',
                'af_advancedrules_nav_where'   => 'CSS-селектор контейнера меню',
                'af_advancedrules_nav_where_desc'=> 'По умолчанию ul.menu.top_links',
            ],
        ],
        'english' => [
            'front' => [
                'af_advancedrules_name'        => 'Advanced Rules',
                'af_advancedrules_description' => 'Forum rules page.',
            ],
            'admin' => [
                'af_advancedrules_group'        => 'AF: Rules',
                'af_advancedrules_group_desc'   => 'Settings for internal addon "Rules".',
                'af_advancedrules_enabled'      => 'Enable rules page',
                'af_advancedrules_enabled_desc' => 'Yes/No',
                'af_advancedrules_nav_text'     => 'Menu link text',
                'af_advancedrules_nav_text_desc'=> 'Defaults to "Rules".',
                'af_advancedrules_nav_where'    => 'Menu container CSS selector',
                'af_advancedrules_nav_where_desc'=> 'Defaults to ul.menu.top_links',
            ],
        ],
    ],
];

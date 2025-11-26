<?php
return [
    'id'          => 'fastnews',
    'name'        => 'Advanced FastNews',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',
    'description' => 'Выводит над контентом блок коротких новостей/объявлений из настроек.',
    'version'     => '1.1.0',
    'bootstrap'   => 'fastnews.php',

    // ЯЗЫКИ
    'lang' => [
        'russian' => [
            'front' => [
                'af_fastnews_name'        => 'Быстрые новости',
                'af_fastnews_description' => 'Короткий инфо-блок над контентом.',
            ],
            'admin' => [
                'af_fastnews_group'            => 'AF: Быстрые новости',
                'af_fastnews_group_desc'       => 'Настройки внутреннего аддона «Быстрые новости».',
                'af_fastnews_enabled'          => 'Включить блок',
                'af_fastnews_enabled_desc'     => 'Да/Нет',
                'af_fastnews_html'             => 'Содержимое блока',
                'af_fastnews_html_desc'        => 'Можно HTML/BBCode (если разрешено на форуме).',
                'af_fastnews_visible_for'      => 'ID групп, через запятую',
                'af_fastnews_visible_for_desc' => 'Пусто — показывать всем.',

                // Подписи для админ-страницы
                'af_fastnews_admin_title'      => 'Быстрые новости',
                'af_fastnews_admin_overview'   => 'Обзор',
                'af_fastnews_admin_quickedit'  => 'Быстрая правка',
                'af_fastnews_admin_preview'    => 'Предпросмотр',
                'af_fastnews_admin_save'       => 'Сохранить',
                'af_fastnews_admin_settings'   => 'Перейти в полные настройки',
                'af_fastnews_admin_saved'      => 'Настройки сохранены.',
            ],
        ],
        'english' => [
            'front' => [
                'af_fastnews_name'        => 'Fast News',
                'af_fastnews_description' => 'Short info block above content.',
            ],
            'admin' => [
                'af_fastnews_group'            => 'AF: Fast News',
                'af_fastnews_group_desc'       => 'Settings for the internal addon "Fast News".',
                'af_fastnews_enabled'          => 'Enable block',
                'af_fastnews_enabled_desc'     => 'Yes/No',
                'af_fastnews_html'             => 'Block content',
                'af_fastnews_html_desc'        => 'HTML/BBCode allowed if forum permits.',
                'af_fastnews_visible_for'      => 'Group IDs, comma-separated',
                'af_fastnews_visible_for_desc' => 'Empty — show to everyone.',

                'af_fastnews_admin_title'      => 'Fast News',
                'af_fastnews_admin_overview'   => 'Overview',
                'af_fastnews_admin_quickedit'  => 'Quick edit',
                'af_fastnews_admin_preview'    => 'Preview',
                'af_fastnews_admin_save'       => 'Save',
                'af_fastnews_admin_settings'   => 'Go to full settings',
                'af_fastnews_admin_saved'      => 'Settings saved.',
            ],
        ],
    ],

    // АДМИН-ИНТЕГРАЦИЯ ДЛЯ ЛЕВОГО МЕНЮ
    'admin' => [
        'slug'       => 'fastnews',
        'title'      => 'Быстрые новости',
        'controller' => 'fastnews_admin.php',
        'order'      => 20,
    ],

];

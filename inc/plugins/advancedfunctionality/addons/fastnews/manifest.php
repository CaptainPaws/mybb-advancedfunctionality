<?php
return [
    'id'          => 'fastnews',
    'name'        => 'Advanced FastNews',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',
    'description' => 'Блок коротких новостей/объявлений. Вставка в шаблоны: {$fastnews}.',
    'version'     => '1.2.0',
    'bootstrap'   => 'fastnews.php',

    'lang' => [
        'russian' => [
            'front' => [
                'af_fastnews_name'        => 'Быстрые новости',
                'af_fastnews_description' => 'Короткий инфо-блок. Вставка: {$fastnews}.',
            ],
            'admin' => [
                'af_fastnews_group'            => 'AF: Быстрые новости',
                'af_fastnews_group_desc'       => 'Настройки внутреннего аддона «Быстрые новости».',

                'af_fastnews_enabled'          => 'Включить блок',
                'af_fastnews_enabled_desc'     => 'Да/Нет',

                // Эти тексты остаются, но значение НЕ хранится в settings.php
                'af_fastnews_html'             => 'Содержимое блока',
                'af_fastnews_html_desc'        => 'Можно HTML/BBCode (если разрешено на форуме).',
                'af_fastnews_html_help'        => 'Вставка на форуме через переменную шаблона: {$fastnews}',

                'af_fastnews_visible_for'      => 'ID групп, через запятую',
                'af_fastnews_visible_for_desc' => 'Пусто — показывать всем.',

                // Админ-страницы
                'af_fastnews_admin_title'        => 'Быстрые новости',
                'af_fastnews_admin_quickedit'    => 'Контент',
                'af_fastnews_admin_preview'      => 'Предпросмотр',
                'af_fastnews_admin_save'         => 'Сохранить',
                'af_fastnews_admin_settings'     => 'Настройки',
                'af_fastnews_admin_settings_tab' => 'Настройки',
                'af_fastnews_admin_saved'        => 'Сохранено.',
            ],
        ],
        'english' => [
            'front' => [
                'af_fastnews_name'        => 'Fast News',
                'af_fastnews_description' => 'Short info block. Insert: {$fastnews}.',
            ],
            'admin' => [
                'af_fastnews_group'            => 'AF: Fast News',
                'af_fastnews_group_desc'       => 'Settings for the internal addon "Fast News".',

                'af_fastnews_enabled'          => 'Enable block',
                'af_fastnews_enabled_desc'     => 'Yes/No',

                'af_fastnews_html'             => 'Block content',
                'af_fastnews_html_desc'        => 'HTML/BBCode allowed if forum permits.',
                'af_fastnews_html_help'        => 'Insert in templates using: {$fastnews}',

                'af_fastnews_visible_for'      => 'Group IDs, comma-separated',
                'af_fastnews_visible_for_desc' => 'Empty — show to everyone.',

                'af_fastnews_admin_title'        => 'Fast News',
                'af_fastnews_admin_quickedit'    => 'Content',
                'af_fastnews_admin_preview'      => 'Preview',
                'af_fastnews_admin_save'         => 'Save',
                'af_fastnews_admin_settings'     => 'Settings',
                'af_fastnews_admin_settings_tab' => 'Settings',
                'af_fastnews_admin_saved'        => 'Saved.',
            ],
        ],
    ],

    'admin' => [
        'slug'       => 'fastnews',
        'title'      => 'Быстрые новости',
        'controller' => 'fastnews_admin.php',
        'order'      => 20,
    ],
];

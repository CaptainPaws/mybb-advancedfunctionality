<?php

return [
    'id'          => 'advancedpostcounter',
    'name'        => 'AdvancedPostCounter',
    'author'      => 'CaptainPaws',
    'authorsite'  => '',
    'description' => 'Считает посты пользователя только в выбранных форумах (опционально с дочерними) и показывает число в постбите и профиле.',
    'version'     => '1.0.0',
    'bootstrap'   => 'advancedpostcounter.php',

    // Языки — ядро AF сгенерит RU/EN для фронта и админки
    'lang'        => [
        'russian' => [
            'front' => [
                'af_advancedpostcounter_name'        => 'AdvancedPostCounter',
                'af_advancedpostcounter_description' => 'Счётчик постов в выбранных форумах.',
                'af_advancedpostcounter_label'       => 'Игровых постов:',
                'af_apc_find_posts'                  => 'Найти все посты',
                'af_apc_postsbyuser_title'           => 'Посты пользователя',
                'af_apc_postsbyuser_empty'           => 'Постов не найдено.',
                'af_apc_postsbyuser_not_configured'  => 'Форумы для подсчёта не выбраны.',
            ],
            'admin' => [
                'af_advancedpostcounter_group'                 => 'AF: AdvancedPostCounter',
                'af_advancedpostcounter_group_desc'            => 'Настройки счётчика постов по выбранным форумам.',
                'af_advancedpostcounter_enabled'               => 'Включить AdvancedPostCounter',
                'af_advancedpostcounter_enabled_desc'          => 'Включает подсчёт и вывод счётчика.',
                'af_advancedpostcounter_forums'                => 'ID форумов для подсчёта',
                'af_advancedpostcounter_forums_desc'           => 'Через запятую. Пример: 2,5,7',
                'af_advancedpostcounter_include_children'      => 'Учитывать дочерние форумы',
                'af_advancedpostcounter_include_children_desc' => 'Если включено — все подфорумы выбранных форумов тоже попадают в подсчёт.',
                'af_advancedpostcounter_count_firstpost'       => 'Учитывать первый пост темы',
                'af_advancedpostcounter_count_firstpost_desc'  => 'Если выключено — стартовый пост темы не считается.',
                'af_advancedpostcounter_show_postbit'          => 'Показывать в постбите',
                'af_advancedpostcounter_show_postbit_desc'     => 'Выводит счётчик в блоке автора (postbit).',
                'af_advancedpostcounter_show_profile'          => 'Показывать в профиле',
                'af_advancedpostcounter_show_profile_desc'     => 'Выводит счётчик в профиле пользователя.',
                'af_apc_assets_blacklist'                      => 'Blacklist отключения ассетов',
                'af_apc_assets_blacklist_desc'                 => 'По одной строке: script.php или script.php?action=name. На совпавших страницах APC JS/CSS не подключаются.',
            ],
        ],

        'english' => [
            'front' => [
                'af_advancedpostcounter_name'        => 'AdvancedPostCounter',
                'af_advancedpostcounter_description' => 'Counts posts in selected forums.',
                'af_advancedpostcounter_label'       => 'Counted posts:',
                'af_apc_find_posts'                  => 'Find all posts',
                'af_apc_postsbyuser_title'           => 'User posts',
                'af_apc_postsbyuser_empty'           => 'No posts found.',
                'af_apc_postsbyuser_not_configured'  => 'No tracked forums configured.',
            ],
            'admin' => [
                'af_advancedpostcounter_group'                 => 'AF: AdvancedPostCounter',
                'af_advancedpostcounter_group_desc'            => 'Settings for forum-scoped post counter.',
                'af_advancedpostcounter_enabled'               => 'Enable AdvancedPostCounter',
                'af_advancedpostcounter_enabled_desc'          => 'Enables counting and displaying.',
                'af_advancedpostcounter_forums'                => 'Forum IDs to track',
                'af_advancedpostcounter_forums_desc'           => 'Comma-separated. Example: 2,5,7',
                'af_advancedpostcounter_include_children'      => 'Include child forums',
                'af_advancedpostcounter_include_children_desc' => 'If enabled, all subforums of selected forums are included.',
                'af_advancedpostcounter_count_firstpost'       => 'Count thread starter post',
                'af_advancedpostcounter_count_firstpost_desc'  => 'If disabled, the first post of each thread is excluded.',
                'af_advancedpostcounter_show_postbit'          => 'Show in postbit',
                'af_advancedpostcounter_show_postbit_desc'     => 'Displays the counter in postbit author area.',
                'af_advancedpostcounter_show_profile'          => 'Show in profile',
                'af_advancedpostcounter_show_profile_desc'     => 'Displays the counter in member profile.',
                'af_apc_assets_blacklist'                      => 'Assets blacklist',
                'af_apc_assets_blacklist_desc'                 => 'One condition per line: script.php or script.php?action=name. APC JS/CSS are disabled on matching pages.',
            ],
        ],
    ],
];

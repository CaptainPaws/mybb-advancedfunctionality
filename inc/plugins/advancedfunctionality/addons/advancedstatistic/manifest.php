<?php
/**
 * AF Addon Manifest: Advanced Statistic
 */
return [
    'id'        => 'advancedstatistic',
    'name'      => 'Advanced Statistic',
    'version'   => '1.0.0',
    'author'    => 'CaptainPaws',
    'website'   => 'https://github.com/CaptainPaws',
    'bootstrap' => 'advancedstatistic.php',

    // Языки — ядро AF сгенерит RU/EN для фронта и админки
    'lang' => [
        'russian' => [
            'front' => [
                'af_advancedstatistic_title'          => 'Статистика форума',
                'af_advancedstatistic_recent_threads' => 'Последние ответы в темах',
                'af_advancedstatistic_online_now'     => 'Сейчас онлайн',
                'af_advancedstatistic_online_today'   => 'Онлайн сегодня',
                'af_advancedstatistic_none'           => 'Никого',
                'af_advancedstatistic_total'          => 'Всего: {1}',

                // Нижняя статистика (лейблы)
                'af_advancedstatistic_stat_messages'  => 'сообщений',
                'af_advancedstatistic_stat_threads'   => 'тем',
                'af_advancedstatistic_stat_members'   => 'зарегистрировано',
                'af_advancedstatistic_stat_apcposts'  => 'написано постов',
                'af_advancedstatistic_stat_newest'    => 'последний зарегистрированный',

                // Подпись к recent threads
                'af_advancedstatistic_posted_by'      => 'автор:',

                // Новые ключи вместо хардкода (meta + today breakdown)
                'af_advancedstatistic_meta_online_now' => 'онлайн сейчас',
                'af_advancedstatistic_meta_staff'      => 'персонал',
                'af_advancedstatistic_meta_users'      => 'пользователей',
                'af_advancedstatistic_meta_guests'     => 'гостей',
                'af_advancedstatistic_today_breakdown' => '{1} пользователей | {2} гостей',
            ],
            'admin' => [
                'af_advancedstatistic_group'            => 'Advanced Statistic',
                'af_advancedstatistic_group_desc'       => 'Заменяет блоки "Кто онлайн" и "Статистика форума" на единый кастомный блок (как на макете).',
                'af_advancedstatistic_enabled'          => 'Включить',
                'af_advancedstatistic_enabled_desc'     => 'Включает замену статистических блоков на главной странице (index.php).',
                'af_advancedstatistic_online_limit'     => 'Лимит аватарок Online now',
                'af_advancedstatistic_online_limit_desc'=> 'Сколько пользователей показывать аватарками (по умолчанию 12).',
                'af_advancedstatistic_today_limit'      => 'Лимит списка Online today',
                'af_advancedstatistic_today_limit_desc' => 'Сколько пользователей показывать списком за сутки (по умолчанию 40).',
                'af_advancedstatistic_recent_limit'     => 'Лимит Recent threads',
                'af_advancedstatistic_recent_limit_desc'=> 'Сколько последних тем выводить слева (по умолчанию 5).',
                'af_advancedstatistic_avatar_size'      => 'Размер аватарок Online now',
                'af_advancedstatistic_avatar_size_desc' => 'Размер квадрата аватара в пикселях (по умолчанию 48).',
            ],
        ],

        'english' => [
            'front' => [
                'af_advancedstatistic_title'          => 'Forum statistics',
                'af_advancedstatistic_recent_threads' => 'Recent threads',
                'af_advancedstatistic_online_now'     => 'Online now',
                'af_advancedstatistic_online_today'   => 'Online today',
                'af_advancedstatistic_none'           => 'None',
                'af_advancedstatistic_total'          => 'Total: {1}',

                // Bottom stats labels
                'af_advancedstatistic_stat_messages'  => 'messages',
                'af_advancedstatistic_stat_threads'   => 'threads',
                'af_advancedstatistic_stat_members'   => 'registered',
                'af_advancedstatistic_stat_apcposts'  => 'posts written',
                'af_advancedstatistic_stat_newest'    => 'newest member',

                // Recent threads caption
                'af_advancedstatistic_posted_by'      => 'posted by',

                // New keys replacing hardcode (meta + today breakdown)
                'af_advancedstatistic_meta_online_now' => 'online now',
                'af_advancedstatistic_meta_staff'      => 'staff',
                'af_advancedstatistic_meta_users'      => 'users',
                'af_advancedstatistic_meta_guests'     => 'guests',
                'af_advancedstatistic_today_breakdown' => '{1} users | {2} guests',
            ],
            'admin' => [
                'af_advancedstatistic_group'            => 'Advanced Statistic',
                'af_advancedstatistic_group_desc'       => 'Replaces Who is online + Board stats with one custom block.',
                'af_advancedstatistic_enabled'          => 'Enable',
                'af_advancedstatistic_enabled_desc'     => 'Enables block replacement on index.php.',
                'af_advancedstatistic_online_limit'     => 'Online now avatar limit',
                'af_advancedstatistic_online_limit_desc'=> 'How many users to show as avatars (default 12).',
                'af_advancedstatistic_today_limit'      => 'Online today list limit',
                'af_advancedstatistic_today_limit_desc' => 'How many users to show in 24h list (default 40).',
                'af_advancedstatistic_recent_limit'     => 'Recent threads limit',
                'af_advancedstatistic_recent_limit_desc'=> 'How many latest threads to show (default 5).',
                'af_advancedstatistic_avatar_size'      => 'Online now avatar size',
                'af_advancedstatistic_avatar_size_desc' => 'Avatar square size in px (default 48).',
            ],
        ],
    ],
];

<?php

return [
    'id'          => 'smarturltitles',
    'name'        => 'Smart URL Titles',
    'description' => 'Автоматически подставляет заголовки страниц для ссылок вида [url]https://...[/url] и “голых” URL в постах/темах/ЛС.',
    'version'     => '1.0.0',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',

    'bootstrap'   => 'smarturltitles.php',

    'admin' => [
        'slug'       => 'smarturltitles',
        'controller' => 'admin.php',
        'icon'       => '🔗',
    ],

    'lang' => [
        'front' => [
            'af_smarturltitles_name'        => 'Smart URL Titles',
            'af_smarturltitles_description' => 'Подстановка заголовков страниц для ссылок без имени.',
        ],
        'admin' => [
            'af_smarturltitles_group'      => 'Smart URL Titles',
            'af_smarturltitles_group_desc' => 'Настройки подстановки заголовков страниц для ссылок без имени.',

            'af_smarturltitles_enabled'      => 'Включить Smart URL Titles',
            'af_smarturltitles_enabled_desc' => 'Если выключено — аддон ничего не делает.',

            'af_sut_title_length'      => 'Максимальная длина заголовка',
            'af_sut_title_length_desc' => 'Обрезать заголовки длиннее указанного числа символов. 0 = без ограничений.',

            'af_sut_url_count'      => 'Максимум ссылок на пост',
            'af_sut_url_count_desc' => 'Сколько ссылок максимум обрабатывать в одном сообщении, чтобы избегать таймаутов. 0 = без ограничений.',

            'af_sut_timeout'      => 'Таймаут запроса (сек)',
            'af_sut_timeout_desc' => 'Сколько ждать ответа сайта при попытке получить заголовок.',

            'af_sut_range'      => 'Лимит скачиваемых данных (байт)',
            'af_sut_range_desc' => 'Сколько максимум данных читать с сайта, чтобы вытащить заголовок. Рекомендуется 500000.',
        ],
    ],
];

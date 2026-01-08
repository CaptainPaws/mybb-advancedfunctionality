<?php

return [
    'id'          => 'advancedgiphy',
    'name'        => 'AdvancedGiphy',
    'description' => 'Кнопка GIPHY в редакторе (SCEditor/Rin Editor): поиск GIF и вставка как [img]. Плюс ограничение max-width для GIPHY-картинок через CSS.',
    'version'     => '1.0.0',
    'author'      => 'CaptainPaws',
    'authorsite'  => '',

    // bootstrap-файл аддона
    'bootstrap'   => 'advancedgiphy.php',

    // (опционально) пункт в меню AF в ACP. Если не нужен пункт в сайдбаре — просто удали блок 'admin'.
    'admin' => [
        'slug'       => 'advancedgiphy',
        'name'       => 'AdvancedGiphy',
        'controller' => 'admin.php',
    ],

    // языки (ядро AF подхватит и сгенерит RU/EN при синхронизации)
    'lang' => [
        'front' => [
            'af_advancedgiphy_name'        => 'AdvancedGiphy',
            'af_advancedgiphy_description' => 'Поиск GIF в GIPHY и вставка как [img].',
        ],
        'admin' => [
            'af_advancedgiphy_group'              => 'AdvancedGiphy',
            'af_advancedgiphy_group_desc'         => 'Настройки кнопки GIPHY и ограничение размера вставленных GIF.',
            'af_advancedgiphy_key'                => 'GIPHY API Key',
            'af_advancedgiphy_key_desc'           => 'Ключ API из панели разработчика GIPHY (developers.giphy.com). Если пусто — кнопка будет показывать ошибку.',
            'af_advancedgiphy_limit'              => 'Лимит результатов',
            'af_advancedgiphy_limit_desc'         => 'Сколько GIF показывать за один запрос (5–100).',
            'af_advancedgiphy_rating'             => 'Рейтинг',
            'af_advancedgiphy_rating_desc'        => 'Фильтр рейтинга контента GIPHY.',
            'af_advancedgiphy_maxwidth'           => 'Max ширина GIPHY-картинок в постах (px)',
            'af_advancedgiphy_maxwidth_desc'      => 'Ограничивает max-width для картинок с домена giphy.com. 0 = без ограничения.',
            'af_advancedgiphy_admin_title'        => 'AdvancedGiphy',
            'af_advancedgiphy_admin_intro'        => 'Этот аддон добавляет кнопку поиска GIF через GIPHY в SCEditor и ограничивает ширину giphy-картинок через CSS.',
            'af_advancedgiphy_admin_open_settings' => 'Открыть настройки',
        ],
    ],
];

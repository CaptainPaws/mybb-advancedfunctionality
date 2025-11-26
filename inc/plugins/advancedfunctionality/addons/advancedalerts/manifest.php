<?php
return [
    'id'        => 'advancedalerts',
    'name'      => 'Advanced Alerts',
    'version'   => '1.0.0',
    'author'    => 'CaptainPaws',
    'bootstrap' => 'advancedalerts.php',

    // Этот блок читает ядро AF и добавляет пункт в левое меню AF
    'admin' => [
        // Ровно так ядро собирает класс: AF_Admin_{slug}::dispatch()
        'slug'       => 'AdvancedAlerts',
        'title'      => 'Уведомления',
        'menu_group' => 'settings',      // в какой группе меню показать (можно 'settings' / 'tools' и т.п.)
        'order'      => 20,              // порядок в левом меню AF
        'controller' => 'admin.php',     // файл с классом контроллера
    ],

    // Языки (если у тебя автогенерация от AF включена — ядро подхватит эти ключи)
    'lang' => [
        'russian' => [
            'admin' => [
                'af_advancedalerts_menu' => 'Уведомления',
            ],
        ],
        'english' => [
            'admin' => [
                'af_advancedalerts_menu' => 'Alerts',
            ],
        ],
    ],
];

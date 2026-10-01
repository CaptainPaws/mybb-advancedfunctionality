<?php

return [
    'id'          => 'advancedappearance',
    'name'        => 'AdvancedAppearance',
    'version'     => '1.0.0',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',
    'description' => 'Каталог визуальных пресетов и назначения пользователям для APUI.',
    'bootstrap'   => 'advancedappearance.php',

    'menu_provider' => [
        'callback' => 'af_advancedappearance_menu_provider',
        'items' => [
            ['key'=>'presets','label'=>'Пресеты','icon'=>'fa-solid fa-palette','type'=>'link','section'=>'links','default_container'=>'user_drawer','allowed_containers'=>['user_drawer'],'default_sortorder'=>50,'visibility'=>'af_advancedappearance_presets_visible','action'=>['url'=>'apstudio.php']],
            ['key'=>'fitting_room','label'=>'Примерочная','icon'=>'fa-solid fa-shirt','type'=>'link','section'=>'links','default_container'=>'user_drawer','allowed_containers'=>['user_drawer'],'default_sortorder'=>55,'visibility'=>true,'action'=>['url'=>'fittingroom.php']],
        ],
    ],

    'frontend' => [
        'mode' => 'contextual',
        'routes' => [
            ['script' => 'apstudio.php'],
            ['script' => 'fittingroom.php'],
        ],
        'response_rules' => [
            ['has_appearance_runtime' => true],
        ],
        'directory_fallback' => false,
    ],
    'admin' => [
        'slug'       => 'advancedappearance',
        'controller' => 'admin.php',
        'icon'       => 'paint-brush',
    ],
];

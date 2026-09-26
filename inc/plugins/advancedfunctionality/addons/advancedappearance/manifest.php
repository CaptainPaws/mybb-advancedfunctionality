<?php

return [
    'id'          => 'advancedappearance',
    'name'        => 'AdvancedAppearance',
    'version'     => '1.0.0',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',
    'description' => 'Каталог визуальных пресетов и назначения пользователям для APUI.',
    'bootstrap'   => 'advancedappearance.php',
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

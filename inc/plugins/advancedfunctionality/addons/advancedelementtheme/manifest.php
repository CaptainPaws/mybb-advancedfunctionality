<?php
return [
    'id' => 'advancedelementtheme',
    'name' => 'AdvancedElementTheme',
    'description' => 'Единая визуальная палитра KB arpg_element.',
    'version' => '1.3.0',
    'author' => 'CaptainPaws',
    'bootstrap' => 'advancedelementtheme.php',
    'admin' => ['slug' => 'advancedelementtheme', 'controller' => 'admin.php'],
    'assets' => ['front' => ['css' => [], 'js' => []]],
    'frontend' => [
        'mode' => 'contextual',
        'routes' => [],
        'response_rules' => [['has_element_surface' => true]],
        'directory_fallback' => false,
    ],
];

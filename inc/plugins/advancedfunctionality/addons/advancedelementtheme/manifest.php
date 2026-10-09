<?php
return [
    'id' => 'advancedelementtheme',
    'name' => 'AdvancedElementTheme',
    'description' => 'Единая визуальная палитра KB arpg_element.',
    'version' => '1.4.0',
    'author' => 'CaptainPaws',
    'bootstrap' => 'advancedelementtheme.php',
    'admin' => ['slug' => 'advancedelementtheme', 'controller' => 'admin.php'],
    'assets' => ['front' => ['css' => [], 'js' => []]],
    // Runtime owns these sources; managed theme copies are detached by AF sync.
    'theme_stylesheets' => [
        ['file' => 'assets/element-theme.css', 'disable_theme_integration' => true, 'conditional' => true],
        ['file' => 'assets/element-effects.css', 'disable_theme_integration' => true, 'conditional' => true],
        ['file' => 'assets/advancedelementtheme-admin.css', 'admin_only' => true, 'disable_theme_integration' => true],
    ],
    'frontend' => [
        'mode' => 'contextual',
        'routes' => [],
        'response_rules' => [['has_element_surface' => true]],
        'directory_fallback' => false,
    ],
];

<?php
return [
    'id' => 'advancedcharacters',
    'name' => 'AdvancedCharacters',
    'description' => 'Публичная витрина существующих персонажей Knowledge Base.',
    'version' => '1.0.0',
    'author' => 'AdvancedFunctionality',
    'bootstrap' => 'advancedcharacters.php',
    'frontend' => [
        'mode' => 'contextual',
        'routes' => [
            ['script' => 'characters.php'],
        ],
        // CSS is delivered through the theme stylesheet registry, not scanning assets/.
        'directory_fallback' => false,
    ],
    'theme_stylesheets' => [[
        'id' => 'advancedcharacters_main',
        'file' => 'assets/advancedcharacters.css',
        'stylesheet_name' => 'af_advancedcharacters.css',
        'attach' => [['file' => 'characters.php']],
    ]],
    'lang' => [
        'russian' => ['front' => ['af_advancedcharacters_name' => 'Персонажи']],
        'english' => ['front' => ['af_advancedcharacters_name' => 'Characters']],
    ],
];

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
        'resources' => [
            'character_catalog_stylesheet' => [
                'mode' => 'contextual',
                'routes' => [],
                'response_rules' => [['has_character_catalog' => true]],
                'directory_fallback' => false,
            ],
            // Character detail is rendered by Knowledge Base on its normal
            // route. The page provider grants this stylesheet only after it
            // sees the character-specific view wrapper.
            'character_detail_stylesheet' => [
                'mode' => 'contextual',
                'routes' => [],
                'response_rules' => [['has_character_detail' => true]],
                'directory_fallback' => false,
            ],
        ],
        // CSS is delivered through the theme stylesheet registry, not scanning assets/.
        'directory_fallback' => false,
    ],
    'theme_stylesheets' => [[
        'id' => 'advancedcharacters_main',
        'file' => 'assets/advancedcharacters.css',
        'stylesheet_name' => 'af_advancedcharacters.css',
        'attach' => [['file' => 'characters.php']],
        // Conditional delivery is owned by the character surface provider so
        // KB detail pages can use this stylesheet without attaching it to all
        // KB routes.
        'conditional' => true,
    ]],
    'lang' => [
        'russian' => ['front' => ['af_advancedcharacters_name' => 'Персонажи']],
        'english' => ['front' => ['af_advancedcharacters_name' => 'Characters']],
    ],
];

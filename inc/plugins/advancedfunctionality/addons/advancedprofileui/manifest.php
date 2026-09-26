<?php

return [
    'id'          => 'advancedprofileui',
    'name'        => 'AdvancedProfileUI',
    'version'     => '1.0.0',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',
    'description' => 'Каркас кастомных шаблонов member_profile и postbit_classic с безопасным override/restore.',
    'bootstrap'   => 'advancedprofileui.php',
    'frontend' => [
        'mode' => 'contextual',
        'routes' => [],
        'response_rules' => [
            ['has_apui_output' => true],
        ],
        // pre_output owns response-aware CSS/JS delivery.
        'directory_fallback' => false,
    ],
    'admin' => [
        'slug'       => 'advancedprofileui',
        'controller' => 'admin.php',
        'icon'       => 'user',
    ],
    'theme_stylesheets' => [
        [
            'id' => 'advancedprofileui_main',
            'file' => 'assets/advancedprofileui.css',
            'stylesheet_name' => 'af_advancedprofileui.css',
            'attach' => [
                ['file' => 'member.php'],
                ['file' => 'showthread.php'],
            ],
            'enabled_setting' => 'af_advancedprofileui_enabled',
        ],
    ],
];

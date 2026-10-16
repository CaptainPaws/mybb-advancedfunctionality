<?php

return [
    'id'          => 'advancedprofileui',
    'name'        => 'AdvancedProfileUI',
    'version'     => '1.2.0',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',
    'description' => 'Каркас кастомных шаблонов member_profile и postbit_classic с безопасным override/restore.',
    'bootstrap'   => 'advancedprofileui.php',
    // ATF activation can safely hand off APUI-owned full templates by their
    // explicit owner markers after an ATF -> legacy/APUI -> ATF lifecycle.
    'compatibility_providers' => [
        'adaptivethemeframework' => [
            'callback' => 'af_apui_register_atf_compatibility_normalizer',
            // APUI may already be disabled when ATF is re-enabled, but its
            // backup table still proves a legitimate APUI restore predecessor.
            'load_when_disabled' => true,
        ],
    ],
    'frontend' => [
        'mode' => 'contextual',
        'routes' => [['script'=>'apstudio.php'],['script'=>'usercp.php','action'=>'editsig'],['script'=>'usercp.php','action'=>'do_editsig']],
        'resources'=>[
            'signature_studio'=>['mode'=>'contextual','routes'=>[['script'=>'apstudio.php']],'directory_fallback'=>false],
            'signature_builder'=>['mode'=>'contextual','routes'=>[['script'=>'usercp.php','action'=>'editsig'],['script'=>'usercp.php','action'=>'do_editsig']],'directory_fallback'=>false],
        ],
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
                ['file'=>'usercp.php','action'=>'editsig'],
                ['file'=>'usercp.php','action'=>'do_editsig'],
                ['file' => 'showthread.php'],
            ],
            'enabled_setting' => 'af_advancedprofileui_enabled',
        ],
    ],
];

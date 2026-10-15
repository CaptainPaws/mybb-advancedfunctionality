<?php
/** AdvancedMessenger owns PM functionality; ATF owns the optional exterior shell. */
return [
    'id' => 'advancedmessenger',
    'name' => 'Advanced Messenger',
    'description' => 'Диалоги и встроенный редактор поверх штатных личных сообщений MyBB.',
    'version' => '1.3.2',
    'author' => 'AdvancedFunctionality',
    'bootstrap' => 'advancedmessenger.php',
    'frontend' => [
        'mode' => 'contextual',
        'routes' => [['script' => 'private.php']],
        'directory_fallback' => false,
    ],
    'theme_stylesheets' => [
        ['id'=>'assets__messenger','file'=>'assets/messenger.css','attach'=>[['file'=>'private.php']],'enabled_setting'=>'af_advancedmessenger_enabled','disable_theme_integration'=>true,'exclude_autodiscovery'=>true],
        ['id'=>'assets__native-panels','file'=>'assets/native-panels.css','attach'=>[['file'=>'private.php']],'enabled_setting'=>'af_advancedmessenger_enabled','disable_theme_integration'=>true,'exclude_autodiscovery'=>true],
    ],
    'assets' => ['front' => [
        'css' => ['assets/native-panels.css', 'assets/messenger.css'],
        'js' => ['assets/messenger.js', 'assets/composer.js'],
    ]],
];

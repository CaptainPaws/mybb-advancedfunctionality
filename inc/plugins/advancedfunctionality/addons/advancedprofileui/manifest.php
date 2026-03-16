<?php

return [
    'id'          => 'advancedprofileui',
    'name'        => 'AdvancedProfileUI',
    'version'     => '1.0.0',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',
    'description' => 'Каркас кастомных шаблонов member_profile и postbit_classic с безопасным override/restore.',
    'bootstrap'   => 'advancedprofileui.php',
    'admin' => [
        'slug'       => 'advancedprofileui',
        'controller' => 'admin.php',
        'icon'       => 'user',
    ],
];

<?php
return [
    'id' => 'balance',
    'name' => 'Balance',
    'description' => 'EXP + Credits balance and transaction log.',
    'version' => '1.0.0',
    'author' => 'CaptainPaws',
    'website' => 'https://github.com/CaptainPaws',
    'bootstrap' => 'balance.php',
    'admin' => [
        'slug' => 'balance',
        'name' => 'Balance',
        'controller' => 'admin.php',
    ],
    'lang' => [
        'russian' => ['front' => [], 'admin' => []],
        'english' => ['front' => [], 'admin' => []],
    ],
];

<?php

return [
    'id'          => 'advancedposteravatar',
    'name'        => 'Advanced Poster Avatar',
    'author'      => 'CaptainPaws',
    'authorsite'  => '',
    'description' => 'Показывает аватар последнего постера в списке форумов и тем (index.php, forumdisplay.php).',
    'version'     => '1.0.0',
    'bootstrap'   => 'advancedposteravatar.php',

    'lang'        => [
        'russian' => [
            'front' => [
                'af_advancedposteravatar_name'        => 'Advanced Poster Avatar',
                'af_advancedposteravatar_description' => 'Показывает аватар последнего постера в списке форумов и тем.',
            ],
            'admin' => [
                'af_advancedposteravatar_group'      => 'AF: Advanced Poster Avatar',
                'af_advancedposteravatar_group_desc' => 'Настройки отображения аватара последнего постера.',
            ],
        ],
        'english' => [
            'front' => [
                'af_advancedposteravatar_name'        => 'Advanced Poster Avatar',
                'af_advancedposteravatar_description' => 'Shows the last poster avatar in forum/thread lists.',
            ],
            'admin' => [
                'af_advancedposteravatar_group'      => 'AF: Advanced Poster Avatar',
                'af_advancedposteravatar_group_desc' => 'Settings for last poster avatar display.',
            ],
        ],
    ],
];

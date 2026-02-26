<?php
/**
 * AF Addon Manifest: Force Refresh After Quick Reply
 * MyBB 1.8.x, PHP 8.0–8.4
 */
return [
    'id'          => 'forcerefresh',
    'name'        => 'Force Refresh (Quick Reply)',
    'description' => 'Перезагружает showthread.php после успешной AJAX-отправки ответа через быстрый ответ.',
    'version'     => '1.0.0',
    'author'      => 'CaptainPaws',
    'bootstrap'   => 'forcerefresh.php',

    'lang' => [
        'ru' => [
            'af_forcerefresh_name' => 'Force Refresh (Quick Reply)',
            'af_forcerefresh_description' => 'Перезагружает страницу темы после успешной AJAX-отправки ответа через быстрый ответ.',

            'af_forcerefresh_group' => 'AF: Force Refresh',
            'af_forcerefresh_group_desc' => 'Настройки перезагрузки после быстрого ответа.',

            'af_forcerefresh_enabled' => 'Включить',
            'af_forcerefresh_enabled_desc' => 'Если включено — после успешной AJAX-отправки через быстрый ответ в теме страница будет перезагружена.',
            'af_forcerefresh_delay_ms' => 'Задержка перед перезагрузкой (мс)',
            'af_forcerefresh_delay_ms_desc' => 'Например 200–600 мс. 0 = сразу.',
        ],
        'en' => [
            'af_forcerefresh_name' => 'Force Refresh (Quick Reply)',
            'af_forcerefresh_description' => 'Reload showthread.php after successful AJAX quick reply.',

            'af_forcerefresh_group' => 'AF: Force Refresh',
            'af_forcerefresh_group_desc' => 'Reload settings.',

            'af_forcerefresh_enabled' => 'Enable',
            'af_forcerefresh_enabled_desc' => 'If enabled — reload after successful AJAX quick reply.',
            'af_forcerefresh_delay_ms' => 'Reload delay (ms)',
            'af_forcerefresh_delay_ms_desc' => 'Example 200–600 ms. 0 = immediate.',
        ],
    ],
];
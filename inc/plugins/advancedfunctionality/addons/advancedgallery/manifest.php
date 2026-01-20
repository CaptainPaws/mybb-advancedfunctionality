<?php

return [
    'id'          => 'advancedgallery',
    'name'        => 'Галерея',
    'description' => 'Пользовательская галерея изображений с загрузкой и модерацией.',
    'version'     => '1.0.0',
    'author'      => 'AdvancedFunctionality',
    'authorsite'  => '',

    'bootstrap'   => 'advancedgallery.php',

    'admin' => [
        'slug'       => 'advancedgallery',
        'name'       => 'Галерея',
        'controller' => 'admin.php',
    ],

    'lang' => [
        'front' => [
            'af_advancedgallery_name'        => 'Галерея',
            'af_advancedgallery_description' => 'Галерея изображений.',
        ],
        'admin' => [
            'af_advancedgallery_group'                   => 'Галерея',
            'af_advancedgallery_group_desc'              => 'Настройки галереи изображений.',
            'af_advancedgallery_enabled'                 => 'Включить галерею',
            'af_advancedgallery_items_per_page'          => 'Элементов на страницу',
            'af_advancedgallery_upload_max_mb'           => 'Макс. размер файла (МБ)',
            'af_advancedgallery_allowed_ext'             => 'Разрешённые расширения',
            'af_advancedgallery_thumb_w'                 => 'Ширина превью',
            'af_advancedgallery_thumb_h'                 => 'Высота превью',
            'af_advancedgallery_can_upload_groups'       => 'Группы с правом загрузки',
            'af_advancedgallery_can_moderate_groups'     => 'Группы модерации',
            'af_advancedgallery_autoapprove_groups'      => 'Группы автопринятия',
        ],
    ],
];

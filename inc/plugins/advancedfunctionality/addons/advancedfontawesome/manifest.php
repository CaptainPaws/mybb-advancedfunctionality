<?php
/**
 * AF Addon Manifest: AdvancedFontAwesome
 */
return [
    'id'       => 'advancedfontawesome',
    'name'     => 'Advanced Font Awesome',
    'version'  => '1.0.0',
    'author'   => 'CaptainPaws',
    'bootstrap'=> 'advancedfontawesome.php',

    'lang' => [
        'front' => [
            'af_advancedfontawesome_name'        => 'Advanced Font Awesome',
            'af_advancedfontawesome_description' => 'Глобальное подключение Font Awesome, иконки форумов и кнопка [fa] в редакторе.',
        ],
        'admin' => [
            'af_advancedfontawesome_group'        => 'Advanced Font Awesome',
            'af_advancedfontawesome_group_desc'   => 'Настройки Font Awesome и иконок форумов.',

            // важно: AF-ядро почти всегда создаёт enabled-настройку для аддона
            'af_advancedfontawesome_enabled'      => 'Включить Advanced Font Awesome',
            'af_advancedfontawesome_enabled_desc' => 'Включает подключение Font Awesome, поддержку иконок форумов и тег [fa].',

            'af_advancedfontawesome_icon_label'   => 'Иконка',
            'af_advancedfontawesome_icon_desc'    => 'Укажите класс Font Awesome (например: fa-solid fa-star).',
            'af_advancedfontawesome_icon_search'  => 'Поиск иконок...',
            'af_advancedfontawesome_admin_title'  => 'Font Awesome для форумов',
        ],
    ],


    'admin' => [
        'slug'       => 'advancedfontawesome',
        'controller' => 'admin.php',
    ],
];

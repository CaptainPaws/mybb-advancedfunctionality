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
            'af_advancedfontawesome_admin_desc'   => 'Управление иконками Font Awesome для форумов.',
            'af_advancedfontawesome_forum_search_label' => 'Поиск форума',
            'af_advancedfontawesome_forum_search_placeholder' => 'Введите название форума',
            'af_advancedfontawesome_filter_icon_label' => 'Показывать только с иконкой',
            'af_advancedfontawesome_save_changes' => 'Сохранить изменения',
            'af_advancedfontawesome_sticky_save'  => 'Сохранить',
            'af_advancedfontawesome_table_forum'  => 'Форум',
            'af_advancedfontawesome_table_fid'    => 'FID',
            'af_advancedfontawesome_table_icon'   => 'Иконка',
            'af_advancedfontawesome_table_preview'=> 'Превью',
            'af_advancedfontawesome_table_pick'   => 'Выбрать',
            'af_advancedfontawesome_table_clear'  => 'Очистить',
            'af_advancedfontawesome_empty'        => 'Форумы не найдены.',
            'af_advancedfontawesome_saved'        => 'Иконки форумов сохранены.',
            'af_advancedfontawesome_picker_title' => 'Выбор иконки',
            'af_advancedfontawesome_picker_close' => 'Закрыть',
            'af_advancedfontawesome_found'        => 'Найдено',
            'af_advancedfontawesome_type_more'    => 'Введите 2+ символа',
            'af_advancedfontawesome_no_icons'     => 'Иконки не найдены',
            'af_advancedfontawesome_style_solid'  => 'Solid',
            'af_advancedfontawesome_style_regular'=> 'Regular',
            'af_advancedfontawesome_style_brands' => 'Brands',
        ],
    ],


    'admin' => [
        'slug'       => 'advancedfontawesome',
        'controller' => 'admin.php',
    ],
];

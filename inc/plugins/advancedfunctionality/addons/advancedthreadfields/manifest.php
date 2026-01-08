<?php
/**
 * AF Addon Manifest: AdvancedThreadFields
 * MyBB 1.8.x, PHP 8.0–8.4
 */

return [
    'id'          => 'advancedthreadfields',
    'name'        => 'AdvancedThreadFields',
    'description' => 'Дополнительные поля для тем (как XThreads): ввод в newthread/editpost, хранение значений, вывод в showthread/forumdisplay, фильтры.',
    'version'     => '1.0.0',
    'author'   => 'CaptainPaws',
    'website'     => 'https://github.com/CaptainPaws',
    'bootstrap'   => 'advancedthreadfields.php',

    'admin' => [
        'slug'       => 'advancedthreadfields',
        'controller' => 'admin.php',
        'name'       => 'Поля тем',
        'icon'       => 'fas fa-list-alt',
        'order'      => 30,
    ],

    /**
     * Языковые пакеты генерируются ядром AF по этим ключам.
     * Здесь важно иметь и RU и EN тексты (front/admin).
     */
    'lang' => [
        'front' => [
            // RU
            'af_atf_name_ru'        => 'AdvancedThreadFields',
            'af_atf_description_ru' => 'Дополнительные поля для тем.',

            // EN
            'af_atf_name_en'        => 'AdvancedThreadFields',
            'af_atf_description_en' => 'Additional thread fields.',
        ],

        'admin' => [
            // RU
            'af_atf_group_ru'        => 'AdvancedThreadFields',
            'af_atf_group_desc_ru'   => 'Настройки и управление полями для тем.',
            'af_atf_enabled_ru'      => 'Включить AdvancedThreadFields',
            'af_atf_enabled_desc_ru' => 'Включает обработку полей тем на форуме.',

            'af_atf_nav_fields_ru'   => 'Поля тем',
            'af_atf_nav_add_ru'      => 'Добавить поле',
            'af_atf_nav_edit_ru'     => 'Редактировать поле',
            'af_atf_nav_delete_ru'   => 'Удалить поле',

            'af_atf_field_title_ru'     => 'Название (title)',
            'af_atf_field_name_ru'      => 'Ключ (name)',
            'af_atf_field_desc_ru'      => 'Описание',
            'af_atf_field_type_ru'      => 'Тип',
            'af_atf_field_options_ru'   => 'Опции (для select/radio/checkboxgroup)',
            'af_atf_field_required_ru'  => 'Обязательное',
            'af_atf_field_active_ru'    => 'Активное',
            'af_atf_field_forums_ru'    => 'Форумы (ID через запятую; пусто = везде)',
            'af_atf_field_show_thread_ru' => 'Показывать в теме',
            'af_atf_field_show_forum_ru'  => 'Показывать в списке тем',
            'af_atf_field_sort_ru'        => 'Порядок',
            'af_atf_field_maxlen_ru'      => 'Макс. длина',
            'af_atf_field_regex_ru'       => 'Regex валидации',
            'af_atf_field_format_ru'      => 'Формат вывода (используй {LABEL} и {VALUE})',

            'af_atf_btn_save_ru'     => 'Сохранить',
            'af_atf_btn_delete_ru'   => 'Удалить',
            'af_atf_confirm_delete_ru' => 'Удалить поле и все его значения?',

            // EN
            'af_atf_group_en'        => 'AdvancedThreadFields',
            'af_atf_group_desc_en'   => 'Settings and management for thread fields.',
            'af_atf_enabled_en'      => 'Enable AdvancedThreadFields',
            'af_atf_enabled_desc_en' => 'Enables thread fields processing on the board.',

            'af_atf_nav_fields_en'   => 'Thread fields',
            'af_atf_nav_add_en'      => 'Add field',
            'af_atf_nav_edit_en'     => 'Edit field',
            'af_atf_nav_delete_en'   => 'Delete field',

            'af_atf_field_title_en'     => 'Title',
            'af_atf_field_name_en'      => 'Key (name)',
            'af_atf_field_desc_en'      => 'Description',
            'af_atf_field_type_en'      => 'Type',
            'af_atf_field_options_en'   => 'Options (for select/radio/checkboxgroup)',
            'af_atf_field_required_en'  => 'Required',
            'af_atf_field_active_en'    => 'Active',
            'af_atf_field_forums_en'    => 'Forums (IDs comma-separated; empty = all)',
            'af_atf_field_show_thread_en' => 'Show in thread',
            'af_atf_field_show_forum_en'  => 'Show in thread list',
            'af_atf_field_sort_en'        => 'Sort order',
            'af_atf_field_maxlen_en'      => 'Max length',
            'af_atf_field_regex_en'       => 'Validation regex',
            'af_atf_field_format_en'      => 'Display format (use {LABEL} and {VALUE})',

            'af_atf_btn_save_en'     => 'Save',
            'af_atf_btn_delete_en'   => 'Delete',
            'af_atf_confirm_delete_en' => 'Delete this field and all its values?',
        ],
    ],
];

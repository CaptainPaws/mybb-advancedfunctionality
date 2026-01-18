<?php
/**
 * AF Addon Manifest: AdvancedEditor
 */
return [
    'id'       => 'advancededitor',
    'name'     => 'Advanced Editor',
    'version'  => '1.0.0',
    'author'   => 'CaptainPaws',
    'bootstrap'=> 'advancededitor.php',

    // AF core сам синхронизирует языки по этим ключам (как у вас принято)
    'lang' => [
        'front' => [
            'af_advancededitor_name'        => 'Advanced Editor',
            'af_advancededitor_description' => 'Единый расширенный редактор (SCEditor) + кастомный тулбар + BB-паки.',
        ],
        'admin' => [
            'af_advancededitor_group'       => 'Advanced Editor',
            'af_advancededitor_group_desc'  => 'Настройка расширенного редактора, кнопок и тулбара.',
            'af_advancededitor_enabled'     => 'Включить Advanced Editor',
            'af_advancededitor_enabled_desc'=> 'Если выключено — аддон не вмешивается в редактор.',
        ],
    ],

    'admin' => [
        'slug'       => 'advancededitor',
        'controller' => 'admin.php', // AF router загрузит и вызовет AF_Admin_AdvancedEditor::dispatch()
    ],
];

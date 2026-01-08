<?php
/**
 * AF Addon manifest: AdvancedMenu
 */

return [
    'id'        => 'advancedmenu',
    'name'      => 'AdvancedMenu',
    'version'   => '1.0.0',
    'author'      => 'CaptainPaws',
    'authorsite'  => 'https://github.com/CaptainPaws',
    'bootstrap' => 'advancedmenu.php',

    'admin' => [
        'slug'       => 'advancedmenu',
        'name'       => 'AdvancedMenu',
        'controller' => 'admin.php',
        'icon'       => 'fa fa-bars',
    ],

    /**
     * AF core синхронизирует/генерирует языки по этим ключам.
     * (Если твой генератор использует только ключи — ему всё равно, значения проигнорируются.)
     */
    'lang' => [
        'front' => [
            'af_advancedmenu_name'        => 'Advanced Menu',
            'af_advancedmenu_description' => 'Конструктор верхнего и пользовательского меню (без правки шаблонов).',
        ],
        'admin' => [
            'af_advancedmenu_group'       => 'Advanced Menu',
            'af_advancedmenu_group_desc'  => 'Управление пунктами меню и режимами встраивания.',
        ],
    ],
];

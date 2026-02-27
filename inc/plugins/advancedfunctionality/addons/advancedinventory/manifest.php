<?php
return [
    'id' => 'advancedinventory',
    'name' => 'Advanced Inventory',
    'author' => 'CaptainPaws',
    'authorsite' => 'https://github.com/CaptainPaws',
    'description' => 'Standalone user inventory for AdvancedFunctionality.',
    'version' => '1.0.0',
    'bootstrap' => 'advancedinventory.php',
    'lang' => [
        'russian' => [
            'front' => [
                'af_advancedinventory_name' => 'Инвентарь',
                'af_advancedinventory_description' => 'Самостоятельный инвентарь пользователей.',
                'af_advancedinventory_title' => 'Инвентарь',
                'af_advancedinventory_tab_equipment' => 'Экипировка',
                'af_advancedinventory_tab_resources' => 'Ресурсы',
                'af_advancedinventory_tab_pets' => 'Питомцы',
                'af_advancedinventory_tab_customization' => 'Кастомизация профиля',
                'af_advancedinventory_filter_all' => 'Все',
                'af_advancedinventory_empty' => 'Инвентарь пуст.',
                'af_advancedinventory_error_disabled' => 'Инвентарь отключён',
                'af_advancedinventory_error_denied' => 'Недостаточно прав для просмотра.',
            ],
            'admin' => [
                'af_advancedinventory_group' => 'AF: Инвентарь',
                'af_advancedinventory_group_desc' => 'Настройки аддона инвентаря.',
                'af_advancedinventory_admin_title' => 'Инвентари пользователей',
            ],
        ],
        'english' => [
            'front' => [
                'af_advancedinventory_name' => 'Inventory',
                'af_advancedinventory_description' => 'Standalone user inventory.',
                'af_advancedinventory_title' => 'Inventory',
                'af_advancedinventory_tab_equipment' => 'Equipment',
                'af_advancedinventory_tab_resources' => 'Resources',
                'af_advancedinventory_tab_pets' => 'Pets',
                'af_advancedinventory_tab_customization' => 'Profile Customization',
                'af_advancedinventory_filter_all' => 'All',
                'af_advancedinventory_empty' => 'Inventory is empty.',
                'af_advancedinventory_error_disabled' => 'Inventory is disabled',
                'af_advancedinventory_error_denied' => 'Not allowed to view this inventory.',
            ],
            'admin' => [
                'af_advancedinventory_group' => 'AF: Inventory',
                'af_advancedinventory_group_desc' => 'Inventory addon settings.',
                'af_advancedinventory_admin_title' => 'User inventories',
            ],
        ],
    ],
    'admin' => [
        'slug' => 'advancedinventory',
        'title' => 'Advanced Inventory',
        'controller' => 'admin.php',
        'order' => 36,
    ],
];

<?php
if (!defined('IN_MYBB')) {
    die('No direct access');
}

function af_cs_get_attribute_catalog(): array
{
    $is_ru = af_charactersheets_is_ru();

    if ($is_ru) {
        return [
            'str' => 'Сила',
            'dex' => 'Ловкость',
            'con' => 'Конституция',
            'int' => 'Интеллект',
            'wis' => 'Мудрость',
            'cha' => 'Харизма',
        ];
    }

    return [
        'str' => 'Strength',
        'dex' => 'Dexterity',
        'con' => 'Constitution',
        'int' => 'Intelligence',
        'wis' => 'Wisdom',
        'cha' => 'Charisma',
    ];
}

function af_charactersheets_get_attribute_labels(): array
{
    return af_cs_get_attribute_catalog();
}

function af_charactersheets_default_attributes(): array
{
    $catalog = af_cs_get_attribute_catalog();
    return array_fill_keys(array_keys($catalog), 0);
}

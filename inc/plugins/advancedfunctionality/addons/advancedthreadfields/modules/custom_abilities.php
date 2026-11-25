<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Check posted types before the historical normalizer can cast or discard them. */
function af_atf_custom_ability_shape_errors(array $ability): array
{
    $errors = [];
    $fields = ['title','name','ability_name','icon','icon_url','description','ability_description','desc',
        'type','ability_type','subtype','slot','damage_type','damage_element','target','targeting','range',
        'formula_profile','duration_value','duration','duration_unit','cooldown_value','cooldown_unit',
        'cost_value','cost_resource','action_cost','damage_value','heal_value','shield_value','slot_index',
        'sortorder','ability_kb_key','ability_key'];
    foreach ($fields as $field) {
        if (isset($ability[$field]) && !is_string($ability[$field]) && !is_int($ability[$field]) && !is_float($ability[$field])) {
            $errors[] = 'Некорректный формат поля '.$field.'.';
        }
    }
    $effects = $ability['effects'] ?? [];
    if (!is_array($effects) || !array_is_list($effects)) return [...$errors,'Эффекты должны быть списком.'];
    foreach ($effects as $index=>$effect) {
        if (!is_array($effect)) {
            $errors[] = 'Некорректный формат эффекта №'.($index+1).'.';
            continue;
        }
        foreach (['kind','effect_type','value','formula_profile','formula_ref','coefficient','hit_count','target','targeting',
            'damage_type','damage_element','element','duration','duration_value','duration_unit','status_key',
            'stat_key','operation','resource_key','notes'] as $field) {
            if (isset($effect[$field]) && !is_string($effect[$field]) && !is_int($effect[$field]) && !is_float($effect[$field])) {
                $errors[] = 'Эффект №'.($index+1).'. Некорректный формат поля '.$field.'.';
            }
        }
    }
    return $errors;
}

function af_atf_custom_ability_options(array $options): array
{
    if (function_exists('af_kb_arpg_character_stat_registry')) {
        $options['stat'] = [];
        foreach (af_kb_arpg_character_stat_registry() as $key=>$stat) {
            $options['stat'][] = ['key'=>$stat['acs_key'] ?? $key,'label_ru'=>$stat['label']];
        }
    }
    return $options;
}

/** Validate supplied values. Missing pieces are allowed in an application draft. */
function af_atf_custom_ability_errors(array $ability, array $options): array
{
    $errors = [];
    $labels = [
        'type'=>'тип', 'subtype'=>'подтип', 'slot'=>'слот', 'damage_type'=>'тип урона', 'damage_element'=>'стихия урона',
        'target'=>'цель', 'range'=>'дальность', 'formula_profile'=>'профиль формулы', 'formula_ref'=>'формула',
        'duration_value'=>'длительность', 'duration'=>'длительность', 'duration_unit'=>'единица длительности',
        'cooldown_value'=>'кулдаун', 'cooldown_unit'=>'единица кулдауна', 'cost_resource'=>'ресурс стоимости',
        'action_cost'=>'стоимость в AP', 'cost_value'=>'стоимость ресурса', 'effect_type'=>'тип эффекта',
        'status_key'=>'статус', 'operation'=>'операция', 'resource_key'=>'ресурс эффекта', 'stat_key'=>'характеристика',
        'value'=>'величина эффекта', 'coefficient'=>'коэффициент', 'hit_count'=>'число попаданий',
    ];
    $validate = static function (array $row, array $fields, array $numbers, string $prefix) use ($options,$labels,&$errors): void {
        foreach ($fields as $key=>$registry) {
            $value = $row[$key] ?? '';
            if ($value === '') continue;
            $choices = array_column($options[$registry] ?? [], 'key');
            if (!is_string($value) || ($choices ? !in_array($value,$choices,true) : !preg_match('/\A[a-z0-9_.:-]{1,128}\z/D',$value))) {
                $errors[] = $prefix.'Недопустимое значение поля «'.($labels[$key] ?? $key).'».';
            }
        }
        foreach ($numbers as $key=>$nonnegative) {
            $value = $row[$key] ?? '';
            if ($value !== '' && (!is_numeric($value) || !is_finite((float)$value) || ($nonnegative && (float)$value < 0)
                || (in_array($key,['cooldown_value','duration_value','duration','hit_count'],true) && floor((float)$value) !== (float)$value))) {
                $errors[] = $prefix.'Проверьте числовое значение поля «'.($labels[$key] ?? $key).'».';
            }
        }
        if (function_exists('af_kb_arpg_validate_element_fields')) {
            $elementErrors = [];
            $leaf = array_diff_key($row, ['effects'=>true]);
            af_kb_arpg_validate_element_fields($leaf,$elementErrors);
            foreach ($elementErrors as $code) {
                // A missing element is a readiness issue. An explicitly supplied invalid element is an input error.
                if ($code === 'damage_element_required' && ($row['damage_element'] ?? '') === '') continue;
                $errors[] = $prefix.af_kb_arpg_element_error_message($code);
            }
        }
    };

    $validate($ability, [
        'type'=>'type','subtype'=>'subtype','slot'=>'slot','damage_type'=>'damage_type',
        'damage_element'=>'damage_element','target'=>'targeting','range'=>'range','formula_profile'=>'formula_profile',
        'duration_unit'=>'duration_unit','cooldown_unit'=>'duration_unit','cost_resource'=>'resource',
    ], [
        'action_cost'=>true,'cost_value'=>true,'duration_value'=>true,'cooldown_value'=>true,
        'damage_value'=>true,'heal_value'=>true,'shield_value'=>true,
    ], '');
    foreach ($ability['effects'] as $index=>$effect) {
        $validate($effect, [
            'effect_type'=>'effect_type','damage_type'=>'damage_type','damage_element'=>'damage_element',
            'target'=>'targeting','status_key'=>'status','operation'=>'operation','resource_key'=>'resource',
            'formula_profile'=>'formula_profile','formula_ref'=>'formula_def','duration_unit'=>'duration_unit','stat_key'=>'stat',
        ], [
            'value'=>in_array($effect['effect_type'],['damage','heal','shield'],true),
            'coefficient'=>false,'duration_value'=>true,'duration'=>true,'hit_count'=>true,
        ], 'Эффект №'.($index+1).'. ');
    }
    return array_values(array_unique($errors));
}

/** Advisory only. ACS still checks the actual snapshot, references and permissions. */
function af_atf_custom_ability_readiness(array $ability): array
{
    if (!function_exists('af_kb_arpg_character_ability_profile')) return ['Проверка определения KB сейчас недоступна.'];
    $issues = [];
    af_kb_arpg_character_ability_profile($ability,'character_abilities.0',$issues);
    af_kb_arpg_bestiary_ability_pin($ability,'character_abilities.0',$issues);
    $reasons = [];
    foreach ($issues as $issue) {
        if ($issue['severity'] !== 'error') continue;
        $path = preg_replace('/\Acharacter_abilities\.0\.?/','',$issue['path']);
        $reasons[] = match ($issue['code']) {
            'custom_ability_effects_missing' => 'Заполните эффекты способности.',
            'custom_ability_target_missing' => 'Выберите цель способности.',
            'required_field_missing' => 'Заполните обязательный для ACS параметр.',
            'duration_unit_missing' => 'Укажите единицу времени.',
            'damage_element_required' => 'Выберите основную стихию урона.',
            'formula_binding_missing' => 'Привяжите профиль расчёта к формуле KB.',
            'reference_unresolved' => 'Ссылка на определение KB не разрешена.',
            'effect_value_missing' => 'Укажите значение эффекта или формулу.',
            'ability_version_pin_missing','ability_version_pin_mismatch' => 'Не подтверждена версия боевого определения.',
            default => 'Проверьте механический параметр.',
        }.($path !== '' ? ' '.$path : '');
    }
    return array_values(array_unique($reasons));
}

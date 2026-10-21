<?php
/** Editor-only reference choices and explicit save-time pinning. No I/O on include. */
if (!defined('IN_MYBB')) { die('No direct access'); }

function af_kb_arpg_editor_choices(): array
{
    global $db;
    $choices = ['magic_direction'=>[], 'formula_def'=>[], 'formula_profile'=>[], 'condition_template'=>[], 'arpg_ability'=>[], 'arpg_bestiary'=>[], 'arpg_item'=>[]];
    $query = $db->simple_select('af_kb_entries', '*', "active=1 AND type IN ('arpg_element','arpg_mechanics','arpg_ability','arpg_bestiary','arpg_item')", ['order_by'=>'title_ru, `key`']);
    while ($row = $db->fetch_array($query)) {
        if (!af_kb_entry_visible_in_context($row, 'catalog', true)) continue;
        $label = (string)($row['title_ru'] ?: $row['title_en'] ?: $row['key']);
        if ($row['type'] === 'arpg_element') {
            $element = af_kb_arpg_read_element($row['key']);
            if ($element['supported'] && ($element['definition']['kind'] ?? '') === 'magic_direction') {
                $root = $element['root_element'];
                $choices['magic_direction'][] = ['value'=>$row['key'], 'label'=>$label . ' · ' . (af_kb_arpg_primary_elements()[$root]['ru'] ?? $root)];
            }
            continue;
        }
        $issues = [];
        $rules = af_kb_arpg_acs_source($row, $issues)['rules'];
        if ($issues) continue;
        if ($row['type'] !== 'arpg_mechanics') {
            $choices[$row['type']][] = ['value'=>$row['key'], 'label'=>$label,
                'description'=>(string)($rules['description_text'] ?? $row['short_ru'] ?? ''), 'type'=>(string)($rules['type'] ?? '')];
            continue;
        }
        $kind = $rules['service_kind'] ?? '';
        if (!in_array($kind, ['formula_def','formula_profile','condition_template'], true)) continue;
        $definitions = $rules['entries'] ?? [$rules];
        foreach ($definitions as $definition) {
            if (!is_array($definition) || empty($definition['key']) || (isset($definition['active']) && !$definition['active'])) continue;
            $key = $definition['key'];
            $choices[$kind][] = ['value'=>$key, 'label'=>(string)($definition['label_ru'] ?? $definition['title_ru'] ?? ($label . ' · ' . $key)), 'version'=>$definition['version'] ?? null];
        }
    }
    return $choices;
}

/** Only explicit editor commands create/refresh refs. Existing pins are untouched. */
function af_kb_arpg_prepare_editor_refs(array $row, array &$errors, int $depth = 0): array
{
    if ($depth > 16) { $errors[] = 'element_validation_depth_limit'; return $row; }
    if (array_key_exists('_editor_magic_direction_key', $row)) {
        $key = $row['_editor_magic_direction_key']; unset($row['_editor_magic_direction_key']);
        if ($key === '') unset($row['magic_direction_ref']);
        elseif (!is_string($key) || !preg_match(AF_KB_KEY_PATTERN, $key)) $errors[] = 'magic_direction_ref_invalid';
        else {
            $element = af_kb_arpg_read_element($key);
            if (!$element['supported'] || ($element['definition']['kind'] ?? '') !== 'magic_direction') $errors[] = 'magic_direction_unavailable';
            else $row['magic_direction_ref'] = array_intersect_key($element['definition'], array_flip(['key','definition_version','fingerprint']));
        }
    }
    if (array_key_exists('_editor_formula_key', $row)) {
        $key = $row['_editor_formula_key']; unset($row['_editor_formula_key']);
        $issues = [];
        $ref = is_string($key) ? af_kb_arpg_acs_reference('formula_def', $key, 'formula_refs', $issues) : [];
        $version = $ref['definition']['version'] ?? null;
        if ($issues || empty($ref['resolved']) || !is_string($version)) $errors[] = 'Выбранная формула недоступна или не имеет поддерживаемой версии.';
        else { $row['key'] = $key; $row['version'] = $version; }
    }
    foreach (['effects','character_abilities','ability_keys','formula_refs','passive_effects'] as $field) {
        if (!is_array($row[$field] ?? null)) continue;
        foreach ($row[$field] as $i=>$child) if (is_array($child)) $row[$field][$i] = af_kb_arpg_prepare_editor_refs($child, $errors, $depth+1);
    }
    return $row;
}

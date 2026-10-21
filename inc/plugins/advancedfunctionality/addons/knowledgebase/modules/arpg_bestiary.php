<?php
/** KB data boundary only. Including this file performs no I/O. */
if (!defined('IN_MYBB')) { die('No direct access'); }

/** One storage stat, with a distinct canonical ACS spelling. */
function af_kb_arpg_storage_stat_key(string $key): string
{
    return $key === 'status_resistance' ? 'status_resist' : $key;
}

/** No fallback value is produced for missing, invalid or conflicting aliases. */
function af_kb_arpg_status_resistance(array $stats, string $path, array &$diagnostics, string $canonical = 'status_resistance', string $legacy = 'status_resist'): array
{
    $sources = array_intersect_key($stats, array_flip([$canonical, $legacy]));
    $result = ['canonical_key'=>$canonical, 'source_values'=>$sources, 'available'=>false];
    if (!$sources || !array_filter($sources, static fn($v) => $v !== null && $v !== '')) {
        af_kb_arpg_acs_diagnostic($diagnostics, 'required_stat_missing', $path . '.' . $canonical);
        return $result;
    }
    foreach ($sources as $key => $value) {
        if (is_bool($value) || !is_numeric($value) || !is_finite((float)$value) || (float)$value < 0) {
            af_kb_arpg_acs_diagnostic($diagnostics, 'invalid_stat_value', $path . '.' . $key);
            return $result;
        }
    }
    if (count($sources) === 2 && (float)$sources[$canonical] !== (float)$sources[$legacy]) {
        af_kb_arpg_acs_diagnostic($diagnostics, 'status_resistance_alias_conflict', $path . '.' . $canonical);
        return $result;
    }
    $result['available'] = true;
    $result['value'] = reset($sources); // Equal aliases represent one value, never a sum.
    return $result;
}

/** Save remains compatible with missing legacy values; conflicts require explicit correction. */
function af_kb_arpg_validate_status_fields(array $rules, array &$errors): void
{
    $diagnostics = [];
    foreach ([[$rules, 'rules', 'status_resistance_base', 'status_resist_base'],
        [is_array($rules['combat_stats'] ?? null) ? $rules['combat_stats'] : [], 'rules.combat_stats', 'status_resistance', 'status_resist'],
        [is_array($rules['character_stats'] ?? null) ? $rules['character_stats'] : [], 'rules.character_stats', 'character_status_resistance', 'character_status_resist']] as [$stats,$path,$canonical,$legacy]) {
        if (array_key_exists($canonical, $stats) || array_key_exists($legacy, $stats)) af_kb_arpg_status_resistance($stats, $path, $diagnostics, $canonical, $legacy);
    }
    foreach ($diagnostics as $issue) {
        if ($issue['code'] !== 'required_stat_missing') $errors[] = $issue['path'] . ': ' . $issue['code'];
    }
}

/** Project modifier identifiers once, keeping raw/source definitions untouched. */
function af_kb_arpg_project_stat_identifiers(array $value): array
{
    if (isset($value['stat_key']) && in_array($value['stat_key'], ['status_resist','status_resistance'], true)) {
        $value['source_stat_key'] = $value['stat_key'];
        $value['stat_key'] = 'status_resistance';
    }
    foreach ($value as $key => $child) {
        if (in_array($key, ['raw','definition','source_values','references','provenance'], true)) continue;
        if (is_array($child)) $value[$key] = af_kb_arpg_project_stat_identifiers($child);
    }
    return $value;
}

/** Sparse, unambiguous RES map. Missing elements stay missing and are diagnosed. */
function af_kb_arpg_bestiary_resistances(array $rows, array &$diagnostics): array
{
    $result = [];
    $seen = [];
    foreach ($rows as $row) {
        $key = $row['canonical_element'] ?? null;
        if (!isset(af_kb_arpg_primary_elements()[$key ?? ''])) continue;
        if (isset($seen[$key])) {
            unset($result[$key]); // Ambiguous aliases must not supply a usable RES.
            continue;
        }
        $seen[$key] = true;
        $params = $row['parameters'];
        if (($params['scope'] ?? null) !== 'elemental' || ($params['unit'] ?? null) !== 'RES') continue;
        $value = $params['value'] ?? null;
        if (!is_bool($value) && is_numeric($value) && is_finite((float)$value) && (float)$value >= 0) {
            $result[$key] = ['value'=>$value, 'source_index'=>$row['source_index'], 'source_element'=>$params['damage_element'] ?? $params['element'] ?? $params['legacy_key'] ?? $row['source_index']];
        }
    }
    foreach (array_keys(af_kb_arpg_primary_elements()) as $key) {
        if (!isset($result[$key])) af_kb_arpg_acs_diagnostic($diagnostics, 'required_resistance_missing', 'resists.' . $key);
    }
    return $result;
}

/** Read only: a reference is usable only against the requested version/fingerprint. */
function af_kb_arpg_bestiary_ability_pin(array $ability, string $path, array &$diagnostics): void
{
    $key = $ability['ability_kb_key'] ?? $ability['ability_key'] ?? null;
    if ($key === null || $key === '') return; // Inline definitions retain the outer template fingerprint.
    $reference = af_kb_arpg_acs_reference('arpg_ability', $key, $path . '.ability_kb_key', $diagnostics);
    foreach (['ability_definition_version'=>'definition_version', 'ability_fingerprint'=>'fingerprint'] as $field => $resolvedField) {
        if (!array_key_exists($field, $ability) || $ability[$field] === '') {
            af_kb_arpg_acs_diagnostic($diagnostics, 'ability_version_pin_missing', $path . '.' . $field);
        } elseif (!$reference['resolved'] || ($field === 'ability_definition_version'
            ? (!is_array($ability[$field]) || af_kb_arpg_acs_fingerprint($ability[$field]) !== af_kb_arpg_acs_fingerprint($reference[$resolvedField] ?? []))
            : $ability[$field] !== ($reference[$resolvedField] ?? null))) {
            af_kb_arpg_acs_diagnostic($diagnostics, 'ability_version_pin_mismatch', $path . '.' . $field);
        }
    }
}

/** Explicit editor selection/refresh only; never invoked by reads or previews. */
function af_kb_arpg_prepare_bestiary_ability_ref(array $ability, array &$errors): array
{
    if (!array_key_exists('_editor_ability_key', $ability)) return $ability;
    $key = $ability['_editor_ability_key']; unset($ability['_editor_ability_key']);
    if ($key === '') {
        unset($ability['ability_kb_key'], $ability['ability_key'], $ability['ability_definition_version'], $ability['ability_fingerprint']);
        return $ability;
    }
    if (!is_string($key) || !preg_match(AF_KB_KEY_PATTERN, $key)) {
        $errors[] = 'Выберите существующую способность из Базы знаний.'; return $ability;
    }
    $diagnostics = [];
    $reference = af_kb_arpg_acs_reference('arpg_ability', $key, 'ability_keys', $diagnostics);
    if ($diagnostics || empty($reference['resolved']) || !is_array($reference['definition_version'] ?? null) || empty($reference['fingerprint'])) {
        $errors[] = 'Выбранная способность недоступна. Проверьте запись Базы знаний.'; return $ability;
    }
    $ability['ability_kb_key'] = $key;
    unset($ability['ability_key']);
    $ability['ability_definition_version'] = $reference['definition_version'];
    $ability['ability_fingerprint'] = $reference['fingerprint'];
    return $ability;
}

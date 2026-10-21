<?php
/** KB-owned taxonomy. Definitions only: no queries, seed or writes on include. */
if (!defined('IN_MYBB')) { die('No direct access'); }

function af_kb_arpg_primary_elements(): array
{
    return [
        'fire'=>['ru'=>'Огонь','en'=>'Fire'], 'water'=>['ru'=>'Вода','en'=>'Water'],
        'air'=>['ru'=>'Воздух','en'=>'Air'], 'earth'=>['ru'=>'Земля','en'=>'Earth'],
        'ice'=>['ru'=>'Лёд','en'=>'Ice'], 'lightning'=>['ru'=>'Молния','en'=>'Lightning'],
        'nature'=>['ru'=>'Природа','en'=>'Nature'], 'light'=>['ru'=>'Свет','en'=>'Light'],
        'shadow'=>['ru'=>'Тьма','en'=>'Shadow'], 'death'=>['ru'=>'Смерть','en'=>'Death'],
        'life'=>['ru'=>'Жизнь','en'=>'Life'], 'chaos'=>['ru'=>'Хаос','en'=>'Chaos'],
        'mind'=>['ru'=>'Разум','en'=>'Mind'], 'abyss'=>['ru'=>'Бездна','en'=>'Abyss'],
        'gravity'=>['ru'=>'Гравитация','en'=>'Gravity'], 'astral'=>['ru'=>'Астрал','en'=>'Astral'],
        'space'=>['ru'=>'Пространство','en'=>'Space'],
    ];
}

function af_kb_arpg_canonical_element_key(string $key): string
{
    return $key === 'wind' ? 'air' : $key;
}

/** Exact, uncached read; keys are validated before constructing the query. */
function af_kb_arpg_element_lookup(string $key): ?array
{
    global $db;
    if (!preg_match(AF_KB_KEY_PATTERN, $key) || !is_object($db) || !$db->table_exists('af_kb_entries')) return null;
    $query = $db->simple_select('af_kb_entries', '*', "type='arpg_element' AND `key`='" . $db->escape_string($key) . "'", ['limit'=>1]);
    $row = $db->fetch_array($query);
    return is_array($row) ? $row : null;
}

function af_kb_arpg_element_node(array $entry, array &$diagnostics): array
{
    $source = af_kb_arpg_acs_source($entry, $diagnostics);
    $rules = $source['rules'];
    $key = (string)($entry['key'] ?? '');
    $canonical = af_kb_arpg_canonical_element_key($key);
    $primary = isset(af_kb_arpg_primary_elements()[$canonical]);
    $kind = $rules['entity_kind'] ?? ($primary ? 'primary_element' : null);
    if (!preg_match(AF_KB_KEY_PATTERN, $key)) af_kb_arpg_acs_diagnostic($diagnostics, 'element_key_invalid', 'element.key');
    if (!in_array($kind, ['primary_element','magic_direction'], true) || ($kind === 'primary_element') !== $primary) {
        af_kb_arpg_acs_diagnostic($diagnostics, 'element_kind_invalid', 'element.entity_kind');
    }
    if ($kind === 'magic_direction') {
        foreach (array_keys($rules) as $field) {
            if (in_array($field, ['resistances','resists','RES'], true) || str_starts_with((string)$field, 'RES_')) af_kb_arpg_acs_diagnostic($diagnostics, 'direction_res_forbidden', 'element.' . $field);
        }
    }
    if (isset($entry['active']) && !$entry['active']) af_kb_arpg_acs_diagnostic($diagnostics, 'inactive_definition', 'element.active');
    if (isset($rules['element_schema']) && $rules['element_schema'] !== 'af_kb.element.v1') af_kb_arpg_acs_diagnostic($diagnostics, 'element_schema_unsupported', 'element.element_schema');
    $version = $rules['element_revision'] ?? null;
    if ($version !== null && (!is_int($version) || $version < 1)) af_kb_arpg_acs_diagnostic($diagnostics, 'element_version_invalid', 'element.element_revision');
    return ['key'=>$canonical, 'source_key'=>$key, 'kind'=>$kind, 'definition_version'=>$version,
        'fingerprint'=>af_kb_arpg_acs_fingerprint(['key'=>$key,'rules'=>$rules]), 'rules'=>$rules,
        'active'=>(bool)($entry['active'] ?? true), 'title_ru'=>$entry['title_ru'] ?? '', 'title_en'=>$entry['title_en'] ?? ''];
}

/** A bounded, read-only parent walk; lookup injection also permits isolated fixtures. */
function af_kb_arpg_read_element(string $key, ?callable $lookup = null, ?array $candidate = null): array
{
    $lookup = $lookup ?? 'af_kb_arpg_element_lookup';
    $diagnostics = [];
    $chain = [];
    $visited = [];
    $root = null;
    $pending = null;
    $sourceKey = $key;
    for ($depth = 0; $depth < 128; ++$depth) {
        if (!preg_match(AF_KB_KEY_PATTERN, $key)) {
            af_kb_arpg_acs_diagnostic($diagnostics, 'element_key_invalid', 'element.key'); break;
        }
        $canonical = af_kb_arpg_canonical_element_key($key);
        if (isset($visited[$canonical])) {
            af_kb_arpg_acs_diagnostic($diagnostics, 'element_cycle', 'element.parent_ref'); break;
        }
        $visited[$canonical] = true;
        // Resolve air/wind once, preserving the stored identity. Never create two roots.
        if ($canonical === 'air') {
            $air = $lookup('air'); $wind = $lookup('wind');
            if ($candidate !== null && $depth === 0) {
                if (($candidate['key'] ?? '') === 'air') $air = $candidate;
                if (($candidate['key'] ?? '') === 'wind') $wind = $candidate;
            }
            if ($air && $wind && !empty($air['active']) && !empty($wind['active'])) af_kb_arpg_acs_diagnostic($diagnostics, 'element_alias_collision', 'element.air');
            $entry = $key === 'wind' ? ($wind ?? $air) : ($air ?? $wind);
        } else {
            $entry = $lookup($key);
        }
        if ($candidate !== null && $depth === 0) $entry = $candidate;
        if (!$entry) {
            af_kb_arpg_acs_diagnostic($diagnostics, 'element_parent_missing', 'element.' . $canonical); break;
        }
        $node = af_kb_arpg_element_node($entry, $diagnostics);
        $chain[] = $node;
        if ($node['key'] !== $canonical) af_kb_arpg_acs_diagnostic($diagnostics, 'element_identity_mismatch', 'element.key');
        if ($pending !== null && (($pending['kind'] ?? null) !== $node['kind']
            || ($pending['definition_version'] ?? null) !== $node['definition_version']
            || ($pending['fingerprint'] ?? null) !== $node['fingerprint'])) {
            af_kb_arpg_acs_diagnostic($diagnostics, 'element_parent_version_mismatch', 'element.parent_ref');
        }
        if ($node['kind'] === 'primary_element') {
            if (!empty($node['rules']['parent_ref'])) af_kb_arpg_acs_diagnostic($diagnostics, 'primary_element_has_parent', 'element.parent_ref');
            $root = $node['key']; break;
        }
        $pending = $node['rules']['parent_ref'] ?? null;
        if (!is_array($pending) || !is_string($pending['key'] ?? null)) {
            af_kb_arpg_acs_diagnostic($diagnostics, 'element_parent_required', 'element.parent_ref'); break;
        }
        $key = $pending['key'];
    }
    if ($root === null && count($chain) === 128) af_kb_arpg_acs_diagnostic($diagnostics, 'element_depth_limit', 'element.parent_ref');
    foreach ($chain as $node) {
        if (isset($node['rules']['root_element']) && $node['rules']['root_element'] !== $root) af_kb_arpg_acs_diagnostic($diagnostics, 'element_root_changed', 'element.root_element');
    }
    return ['schema'=>'af_kb.element.v1', 'registry_version'=>'primary-elements-17-v1', 'alias_policy'=>'wind-air-v1',
        'key'=>af_kb_arpg_canonical_element_key($sourceKey), 'requested_key'=>$sourceKey, 'source_key'=>$chain[0]['source_key'] ?? $sourceKey,
        'root_element'=>$root, 'definition'=>$chain[0] ?? null, 'provenance'=>$chain, 'diagnostics'=>$diagnostics,
        'supported'=>!array_filter($diagnostics, static fn($d) => $d['severity'] === 'error'), 'executable'=>false];
}

/** On-demand primary catalog; missing definitions stay diagnostic, never fabricated. */
function af_kb_arpg_list_primary_elements(): array
{
    global $db;
    $entries = [];
    if (is_object($db) && $db->table_exists('af_kb_entries')) {
        $keys = array_merge(array_keys(af_kb_arpg_primary_elements()), ['wind']);
        $query = $db->simple_select('af_kb_entries', '*', "type='arpg_element' AND `key` IN ('" . implode("','", $keys) . "')");
        while ($entry = $db->fetch_array($query)) $entries[$entry['key']] = $entry;
    }
    $lookup = static fn(string $key): ?array => $entries[$key] ?? null;
    $result = [];
    foreach (af_kb_arpg_primary_elements() as $key => $labels) $result[$key] = af_kb_arpg_read_element($key, $lookup);
    return $result;
}

/** Paginated direction reads, independent of any showthread runtime. */
function af_kb_arpg_list_magic_directions(int $offset = 0, int $limit = 100): array
{
    global $db;
    if (!is_object($db) || !$db->table_exists('af_kb_entries')) return [];
    $keys = array_merge(array_keys(af_kb_arpg_primary_elements()), ['wind']);
    $where = "type='arpg_element' AND active=1 AND `key` NOT IN ('" . implode("','", $keys) . "')";
    $query = $db->simple_select('af_kb_entries', '*', $where, ['order_by'=>'`key`', 'start'=>max(0,$offset), 'limit'=>max(1,min(500,$limit))]);
    $entries = [];
    while ($row = $db->fetch_array($query)) $entries[$row['key']] = $row;
    // Share ancestor reads only within this list operation, never across writes.
    $cache = $entries;
    $lookup = static function (string $key) use (&$cache): ?array {
        if (!array_key_exists($key, $cache)) $cache[$key] = af_kb_arpg_element_lookup($key);
        return $cache[$key];
    };
    $rows = [];
    foreach ($entries as $key => $entry) {
        $result = af_kb_arpg_read_element($key, $lookup);
        if (($result['definition']['kind'] ?? null) === 'magic_direction') $rows[] = $result;
    }
    return $rows;
}

/** Called only by the existing authorised/CSRF-protected edit command, under its taxonomy lock. */
function af_kb_arpg_prepare_element(string $key, array $rules, ?array $existing, array &$errors, ?callable $lookup = null, bool $active = true): array
{
    $lookup = $lookup ?? 'af_kb_arpg_element_lookup';
    if (!in_array($rules['entity_kind'] ?? null, ['primary_element','magic_direction'], true)) { $errors[] = 'element_kind_invalid'; return $rules; }
    $oldIssues = [];
    $old = $existing ? af_kb_arpg_element_node($existing, $oldIssues) : null;
    foreach ($oldIssues as $issue) if (in_array($issue['code'], ['source_conflict','invalid_json','element_version_invalid'], true)) $errors[] = $issue['code'];
    if (($old['definition_version'] ?? 0) >= PHP_INT_MAX) { $errors[] = 'element_version_exhausted'; return $rules; }
    // Editors carry the previously read revision, preventing stale admin overwrites.
    if ($old && ($rules['element_revision'] ?? null) !== $old['definition_version']) $errors[] = 'element_version_conflict';
    $rules['element_schema'] = 'af_kb.element.v1';
    $rules['element_revision'] = (int)($old['definition_version'] ?? 0) + 1;
    $canonical = af_kb_arpg_canonical_element_key($key);
    if (($rules['entity_kind'] ?? '') === 'magic_direction') {
        $parentKey = $rules['parent_ref']['key'] ?? '';
        if (!is_string($parentKey) || !preg_match(AF_KB_KEY_PATTERN, $parentKey)) {
            $errors[] = 'element_parent_required'; return $rules;
        }
        $parent = af_kb_arpg_read_element($parentKey, $lookup);
        foreach ($parent['diagnostics'] as $issue) if ($issue['severity'] === 'error') $errors[] = $issue['code'];
        foreach ($parent['provenance'] as $ancestor) if ($ancestor['key'] === $canonical) $errors[] = 'element_cycle';
        if ($parent['definition']) {
            $rules['parent_ref'] = array_intersect_key($parent['definition'], array_flip(['kind','key','definition_version','fingerprint']));
            $rules['root_element'] = $parent['root_element'];
        }
    } else {
        $rules['entity_kind'] = 'primary_element';
        $rules['parent_ref'] = null;
        $rules['root_element'] = $canonical;
    }
    $candidate = ['key'=>$key, 'active'=>$active, 'data_json'=>json_encode($rules, JSON_THROW_ON_ERROR)];
    $checked = af_kb_arpg_read_element($key, $lookup, $candidate);
    foreach ($checked['diagnostics'] as $issue) if ($issue['severity'] === 'error' && $issue['code'] !== 'inactive_definition') $errors[] = $issue['code'];
    return $rules;
}

/** One connection-scoped mutex for parent edits; no locks/queries during module loading. */
function af_kb_arpg_element_lock(bool $release = false): bool
{
    global $db;
    $name = 'af_kb_elements_' . substr(hash('sha256', (defined('TABLE_PREFIX') ? TABLE_PREFIX : '') . (string)($db->database ?? '')), 0, 32);
    $sql = $release ? "SELECT RELEASE_LOCK('" . $name . "') AS acquired" : "SELECT GET_LOCK('" . $name . "', 5) AS acquired";
    $row = $db->fetch_array($db->write_query($sql));
    return (int)($row['acquired'] ?? 0) === 1;
}

/** Bounded suggestions only. The editor also accepts any valid parent key. */
function af_kb_arpg_element_editor_options(): array
{
    $options = [];
    foreach (array_merge(array_values(af_kb_arpg_list_primary_elements()), af_kb_arpg_list_magic_directions()) as $result) {
        $node = $result['definition'];
        if (!$node || !$result['supported']) continue;
        $options[] = ['key'=>$node['source_key'], 'label'=>$node['title_ru'] ?: $node['source_key'],
            'kind'=>$node['kind'], 'root_element'=>$result['root_element']];
    }
    return $options;
}

/** Validation/normalization of explicit elemental damage and separate classification. */
function af_kb_arpg_element_damage(array $row, string $path, array &$diagnostics): array
{
    $element = $row['damage_element'] ?? $row['element'] ?? null;
    $canonical = is_string($element) ? af_kb_arpg_canonical_element_key($element) : null;
    // Classification on a character is not an attack declaration. Empty optional
    // controls likewise do not declare a damage component.
    $isCharacter = ($row['type_profile'] ?? null) === 'character';
    $kind = $row['effect_type'] ?? $row['kind'] ?? null;
    $isResistance = ($row['unit'] ?? null) === 'RES';
    $isDamage = !$isCharacter && !$isResistance && ($kind === null || in_array($kind, ['damage', 'proc'], true));
    $hasExplicit = array_key_exists('damage_element', $row) && $row['damage_element'] !== '' && $row['damage_element'] !== null;
    if ($isDamage && (($row['damage_type'] ?? null) === 'elemental' || $hasExplicit)) {
        if (!$hasExplicit || !is_string($element) || !isset(af_kb_arpg_primary_elements()[$canonical])) {
            af_kb_arpg_acs_diagnostic($diagnostics, 'damage_element_required', $path . '.damage_element');
        }
        if ($hasExplicit && isset($row['element']) && $row['element'] !== '' && (!is_string($row['element']) || af_kb_arpg_canonical_element_key($row['element']) !== $canonical)) {
            af_kb_arpg_acs_diagnostic($diagnostics, 'damage_element_conflict', $path . '.damage_element');
        }
    }
    if (!$isCharacter && !$isResistance && $hasExplicit && !in_array($row['damage_type'] ?? null, ['elemental','hybrid'], true)) {
        af_kb_arpg_acs_diagnostic($diagnostics, 'damage_element_not_elemental', $path . '.damage_type');
    }
    $direction = null;
    if (array_key_exists('magic_direction_ref', $row) && $row['magic_direction_ref'] !== null) {
        $ref = $row['magic_direction_ref'];
        if (!is_array($ref) || !is_string($ref['key'] ?? null)) {
            af_kb_arpg_acs_diagnostic($diagnostics, 'magic_direction_ref_invalid', $path . '.magic_direction_ref');
        } else {
            $direction = af_kb_arpg_read_element($ref['key']);
            if (!$direction['supported'] || ($direction['definition']['kind'] ?? null) !== 'magic_direction') {
                af_kb_arpg_acs_diagnostic($diagnostics, 'magic_direction_unavailable', $path . '.magic_direction_ref');
            }
            foreach (['definition_version','fingerprint'] as $field) {
                if (!array_key_exists($field, $ref) || $ref[$field] !== ($direction['definition'][$field] ?? null)) {
                    af_kb_arpg_acs_diagnostic($diagnostics, 'magic_direction_version_mismatch', $path . '.magic_direction_ref.' . $field);
                }
            }
        }
    }
    // Never derive damage from the direction/root, including blood -> life.
    return ['element'=>$canonical, 'source_element'=>$element, 'magic_direction'=>$direction];
}

function af_kb_arpg_validate_element_fields(array $row, array &$errors, int $depth = 0): void
{
    if ($depth > 16) { $errors[] = 'element_validation_depth_limit'; return; }
    $diagnostics = [];
    af_kb_arpg_element_damage($row, 'definition', $diagnostics);
    foreach ($diagnostics as $issue) if ($issue['severity'] === 'error') $errors[] = $issue['code'];
    foreach (['resistances','resists'] as $field) {
        if (isset($row[$field])) {
            $issues = [];
            af_kb_arpg_acs_resistances($row[$field], $field, $issues);
            // New typed RES validation does not force conversion of untouched legacy damage_type rows.
            foreach ($issues as $issue) {
                if ($issue['severity'] === 'error' && (str_starts_with($issue['code'], 'element_resistance_') || str_starts_with($issue['code'], 'damage_element_'))) $errors[] = $issue['code'];
            }
        }
    }
    foreach (['effects','character_abilities','ability_keys','passive_effects'] as $field) {
        foreach (is_array($row[$field] ?? null) ? $row[$field] : [] as $child) {
            if (is_array($child)) af_kb_arpg_validate_element_fields($child, $errors, $depth + 1);
        }
    }
}

/** UI translation only: stable diagnostic codes remain unchanged in owner APIs. */
function af_kb_arpg_element_error_message(string $code): string
{
    return [
        'damage_element_required' => 'Для стихийного урона выберите «Стихия урона»: одну из 17 основных стихий.',
        'damage_element_conflict' => 'Стихия урона конфликтует с сохранённой стихией компонента. Проверьте оба значения.',
        'damage_element_not_elemental' => 'Стихия урона допустима только для стихийного или смешанного урона.',
        'magic_direction_version_mismatch' => 'Версия магического направления изменилась. Выберите направление заново.',
        'magic_direction_unavailable' => 'Выбранное магическое направление недоступно.',
    ][$code] ?? $code;
}

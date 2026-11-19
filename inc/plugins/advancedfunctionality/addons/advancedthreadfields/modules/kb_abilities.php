<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

function af_atf_kb_ability_context_fields(array $fields): array
{
    $names = [
        'element' => ['character_element'],
        'weapon' => ['character_weapon', 'character_weapon_type', 'weapon_type'],
    ];
    $byName = array_column($fields, 'fieldid', 'name');
    $context = [];
    foreach ($names as $facet => $aliases) {
        foreach ($aliases as $name) {
            if (!empty($byName[$name])) $context[$facet][] = (int)$byName[$name];
        }
    }
    return $context;
}

function af_atf_kb_ability_context(array $fields, array $values, array $registry): array
{
    $context = [];
    foreach (af_atf_kb_ability_context_fields($fields) as $facet => $ids) {
        foreach ($ids as $id) {
            $value = is_string($values[$id] ?? null) ? trim($values[$id]) : '';
            if ($value === '') continue;
            if ($facet === 'element') {
                $value = af_kb_arpg_canonical_element_key($value);
                if (isset($registry['directions'][$value])) {
                    $context['direction'] = $value;
                    $value = $registry['directions'][$value]['root'];
                }
            }
            if (isset($registry['options'][$facet][$value])) $context[$facet] = $value;
            break;
        }
    }
    return $context;
}

function af_atf_kb_ability_enabled(): bool
{
    global $mybb;
    return !empty($mybb->settings['af_knowledgebase_enabled'])
        && function_exists('af_kb_ability_catalog_public_row') && af_kb_can_view()
        && !empty(af_kb_find_type_row('arpg_ability')['active']);
}

function af_atf_kb_ability_has_links(array $rows): bool
{
    foreach ($rows as $row) {
        if (is_array($row) && array_key_exists('ability_source', $row)) return true;
    }
    return false;
}

/** Preserve only presentation and the existing ACS pin on a ready-made link. */
function af_atf_kb_ability_row(array $row, int $order): array
{
    $result = ['ability_source' => is_string($row['ability_source']) ? $row['ability_source'] : '',
        'sortorder' => $order, 'slot_index' => $order];
    foreach (['ability_kb_key', 'catalog_definition_id', 'ability_fingerprint', 'title', 'type', 'description', 'icon', 'kb_url'] as $field) {
        $result[$field] = is_string($row[$field] ?? null) ? my_substr($row[$field], 0, $field === 'description' ? 5000 : 512) : '';
    }
    $result['ability_definition_version'] = is_array($row['ability_definition_version'] ?? null)
        ? array_intersect_key($row['ability_definition_version'], array_flip(['revision', 'declared_version', 'fingerprint'])) : [];
    return $result;
}

function af_atf_kb_ability_saved_notice(array $ability): string
{
    if (!af_atf_kb_ability_enabled()) return 'Определение KB сейчас недоступно. Ссылка сохранена.';
    $key = $ability['ability_kb_key'];
    $entry = preg_match(AF_KB_KEY_PATTERN, $key) ? af_kb_arpg_acs_lookup('arpg_ability', $key) : null;
    if (!af_kb_ability_catalog_public_row($entry)) return 'Определение KB сейчас недоступно. Ссылка сохранена.';
    return af_kb_ability_catalog_definition_id($entry) !== $ability['catalog_definition_id']
        ? 'Запись KB изменилась. Для обновления удалите эту строку и выберите запись заново.' : '';
}

/** Never use submitted descriptions, rule data or combat pins to create a link. */
function af_atf_kb_ability_validate(array $rows, array $stored, array $fields, array $incoming, array &$errors): array
{
    $previous = [];
    foreach ($stored as $row) {
        if (($row['ability_source'] ?? '') === 'arpg_ability') $previous[$row['ability_kb_key']] = $row;
    }
    $registry = null;
    $seen = [];
    foreach ($rows as $index => &$row) {
        if (!array_key_exists('ability_source', $row)) continue;
        $key = $row['ability_kb_key'];
        if ($row['ability_source'] !== 'arpg_ability' || !preg_match('/^[a-z0-9_-]{2,64}$/D', $key) || isset($seen[$key])) {
            $errors[] = 'Неверная или повторная ссылка на способность KB.';
            continue;
        }
        $seen[$key] = true;
        $old = $previous[$key] ?? null;
        if ($old && $old['catalog_definition_id'] === $row['catalog_definition_id']) {
            $row = af_atf_kb_ability_row($old, $index + 1);
            continue;
        }

        if (!af_atf_kb_ability_enabled()) {
            $errors[] = 'Каталог способностей KB недоступен.';
            continue;
        }
        $entry = af_kb_arpg_acs_lookup('arpg_ability', $key);
        if (!af_kb_ability_catalog_public_row($entry) || af_kb_ability_catalog_definition_id($entry) !== $row['catalog_definition_id']) {
            $errors[] = 'Способность KB «' . $key . '» изменилась или недоступна. Выберите её заново.';
            continue;
        }
        $registry ??= af_kb_ability_catalog_registry();
        $projection = af_kb_ability_catalog_project($entry, $registry);
        $context = af_atf_kb_ability_context($fields, $incoming, $registry);
        if (!$projection || (string)$entry['key'] !== $key || af_kb_ability_catalog_status($projection['applicability'], $context, $registry) === 'incompatible') {
            $errors[] = 'Способность KB «' . $key . '» несовместима с выбранными параметрами анкеты.';
            continue;
        }
        $issues = [];
        $source = af_kb_arpg_acs_source($entry, $issues);
        if ($issues || !$source['fingerprint']) {
            $errors[] = 'Не удалось закрепить версию способности KB «' . $key . '».';
            continue;
        }

        $row = af_atf_kb_ability_row([
            'ability_source' => 'arpg_ability', 'ability_kb_key' => $key,
            'catalog_definition_id' => af_kb_ability_catalog_definition_id($entry),
            'title' => af_kb_pick_text($entry, 'title') ?: $key, 'type' => $projection['type'],
            'description' => af_kb_pick_text($entry, 'short'),
            'icon' => af_kb_sanitize_url(af_kb_get_entry_ui($entry)['icon_url']),
            'kb_url' => af_kb_url(['type' => 'arpg_ability', 'key' => $key]),
            'ability_definition_version' => ['revision' => $entry['revision'] ?? $source['rules']['revision'] ?? null,
                'declared_version' => $source['rules']['version'] ?? null, 'fingerprint' => $source['fingerprint']],
            'ability_fingerprint' => $source['fingerprint'],
        ], $index + 1);
    }
    unset($row);
    return $rows;
}

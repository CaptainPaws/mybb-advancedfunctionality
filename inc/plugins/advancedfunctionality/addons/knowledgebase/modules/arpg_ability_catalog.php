<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Presentation groups for the existing ARPG item equip slots and KB weapon types. */
function af_kb_ability_weapon_groups(): array
{
    return [
        'weapon_one_hand' => ['sword'],
        'weapon_two_hand' => ['greatsword'],
        'weapon_polearm' => ['spear'],
        'weapon_ranged' => ['bow', 'firearm'],
        'weapon_catalyst' => ['catalyst'],
    ];
}

function af_kb_ability_catalog_registry(): array
{
    global $db;

    $locale = af_kb_is_ru() ? 'ru' : 'en';
    $options = [
        'ability_type' => [],
        'damage_type' => [],
        'weapon' => [],
        'element' => ['none' => $locale === 'ru' ? 'Без стихии' : 'No element'],
        'direction' => [],
        'distance' => [],
        'purpose' => [],
        'sort' => [],
    ];
    $inlineEnums = af_kb_arpg_inline_enum_registry();
    foreach ($inlineEnums['type'] as $key => $labels) {
        $options['ability_type'][$key] = $labels[$locale];
    }
    foreach (['ability_type' => 'ability_type', 'ability_damage_type' => 'damage_type', 'weapon_type' => 'weapon'] as $set => $facet) {
        foreach (af_kb_get_arpg_mechanics_options($set) as $row) {
            $options[$facet][$row['key']] = $row['label_' . $locale];
        }
    }
    foreach (af_kb_arpg_damage_type_values() as $key) {
        if ($key !== '') {
            $options['damage_type'][$key] ??= $inlineEnums['damage_type'][$key][$locale] ?? $key;
        }
    }
    $weaponTypes = $options['weapon'];
    $groups = [];
    foreach (af_kb_ability_weapon_groups() as $slot => $keys) {
        $groups[$slot] = array_values(array_intersect($keys, array_keys($weaponTypes)));
    }
    foreach ([
        'weapon' => [
            'weapon_one_hand' => ['Одноручное', 'One-handed'],
            'weapon_two_hand' => ['Двуручное', 'Two-handed'],
            'weapon_polearm' => ['Древковое', 'Polearm'],
            'weapon_ranged' => ['Дальнобойное', 'Ranged'],
            'weapon_catalyst' => ['Катализатор', 'Catalyst'],
            'none' => ['Без требования к оружию', 'No weapon requirement'],
            'any' => ['Универсальное', 'Any weapon'],
        ],
        'distance' => [
            'melee' => ['Ближний бой', 'Melee'],
            'ranged' => ['Дальний бой', 'Ranged'],
            'any' => ['Универсальная', 'Any distance'],
        ],
        'purpose' => [
            'attack' => ['Атака', 'Attack'], 'heal' => ['Лечение', 'Healing'],
            'defense' => ['Защита', 'Defense'], 'buff' => ['Усиление', 'Buff'],
            'debuff' => ['Ослабление', 'Debuff'], 'control' => ['Контроль', 'Control'],
            'mixed' => ['Смешанная', 'Mixed'],
        ],
        'sort' => [
            'title' => ['По названию', 'Name'], 'type' => ['По типу', 'Type'],
            'element' => ['По стихии', 'Element'],
        ],
    ] as $facet => $entries) {
        foreach ($entries as $key => $labels) {
            $options[$facet][$key] = $labels[$locale === 'ru' ? 0 : 1];
        }
    }
    // "healing" is the existing KB category used for the support facet.
    $options['damage_type']['healing'] = $locale === 'ru' ? 'Без урона / поддержка' : 'No damage / support';

    foreach (af_kb_arpg_primary_elements() as $key => $labels) {
        $options['element'][$key] = $labels[$locale];
    }
    $elementRows = [];
    $query = $db->simple_select('af_kb_entries', 'type, `key`, title_ru, title_en, active, meta_json, data_json', "type='arpg_element' AND active=1");
    while ($row = $db->fetch_array($query)) {
        if (af_kb_ability_catalog_visible(af_kb_decode_json($row['meta_json']))
            && af_kb_ability_catalog_visible(af_kb_decode_json($row['data_json']))) {
            $elementRows[$row['key']] = $row;
        }
    }
    $lookup = static fn(string $key): ?array => $elementRows[$key] ?? null;
    $directions = [];
    foreach ($elementRows as $key => $row) {
        if (isset($options['element'][af_kb_arpg_canonical_element_key($key)])) {
            continue;
        }
        $direction = af_kb_arpg_read_element($key, $lookup);
        if ($direction['supported'] && ($direction['definition']['kind'] ?? '') === 'magic_direction') {
            $directions[$key] = [
                'root' => $direction['root_element'],
                'version' => $direction['definition']['definition_version'],
                'fingerprint' => $direction['definition']['fingerprint'],
            ];
            $options['direction'][$key] = af_kb_pick_text($row, 'title') ?: $key;
        }
    }
    asort($options['direction'], SORT_NATURAL | SORT_FLAG_CASE);

    return ['options' => $options, 'directions' => $directions, 'weapon_groups' => $groups];
}

/** Optional metadata on KB definitions. A missing axis remains unknown. */
function af_kb_ability_catalog_metadata_valid($value): bool
{
    if (!is_array($value) || ($value['version'] ?? null) !== 1
        || array_diff(array_keys($value), ['version', 'weapons', 'distance', 'required_element', 'purpose'])) {
        return false;
    }
    if (array_key_exists('weapons', $value)) {
        $weapons = $value['weapons'];
        if (!is_array($weapons) || !array_is_list($weapons) || !$weapons) {
            return false;
        }
        foreach ($weapons as $weapon) {
            if (!is_string($weapon) || !preg_match(AF_KB_KEY_PATTERN, $weapon)
                || (in_array($weapon, ['none', 'any'], true) && count($weapons) !== 1)) {
                return false;
            }
        }
    }
    if (array_key_exists('distance', $value) && !in_array($value['distance'], ['melee', 'ranged', 'any'], true)) {
        return false;
    }
    if (array_key_exists('required_element', $value)
        && (!is_string($value['required_element']) || !preg_match(AF_KB_KEY_PATTERN, $value['required_element']))) {
        return false;
    }
    return !array_key_exists('purpose', $value)
        || in_array($value['purpose'], ['attack', 'heal', 'defense', 'buff', 'debuff', 'control', 'mixed'], true);
}

function af_kb_ability_catalog_effect_purpose(array $effect): ?string
{
    $kind = $effect['effect_type'] ?? $effect['kind'] ?? '';
    if ($kind === 'stat_modifier' && is_numeric($effect['value'] ?? null)) {
        $value = (float)$effect['value'];
        return match ($effect['operation'] ?? '') {
            'add' => $value > 0 ? 'buff' : ($value < 0 ? 'debuff' : null),
            'subtract' => $value > 0 ? 'debuff' : ($value < 0 ? 'buff' : null),
            default => null,
        };
    }
    return match ($kind) {
        'damage' => 'attack', 'heal' => 'heal', 'shield', 'cleanse' => 'defense',
        'control' => 'control', default => null,
    };
}

/**
 * These two public types can store visibility on the envelope or rules leaf.
 * Honor restrictive flags from both persisted JSON columns before projecting.
 */
function af_kb_ability_catalog_visible(array $source): bool
{
    for ($depth = 0; $depth <= 4; ++$depth) {
        $visibility = $source['visibility'] ?? [];
        if (!is_array($visibility) || !empty($visibility['internal'])
            || (array_key_exists('catalog', $visibility) && empty($visibility['catalog']))
            || (array_key_exists('search', $visibility) && empty($visibility['search']))) {
            return false;
        }
        $nested = $source['rules'] ?? $source['meta']['rules'] ?? null;
        if (!is_array($nested) || !$nested) {
            return true;
        }
        $source = $nested;
    }
    return false;
}

/** Read only classification fields. No reference expansion for ability mechanics. */
function af_kb_ability_catalog_project(array $row, array $registry): ?array
{
    $meta = af_kb_decode_json($row['meta_json']);
    $data = af_kb_decode_json($row['data_json']);
    if ((int)$row['active'] !== 1 || !af_kb_ability_catalog_visible($meta) || !af_kb_ability_catalog_visible($data)) {
        return null;
    }
    // Match KB's legacy rules lookup order for database rows.
    $stored = ($meta['schema'] ?? '') === AF_KB_RULES_SCHEMA ? $meta : ($meta['rules'] ?? []);
    if (!is_array($stored) || !$stored) {
        $stored = $data;
    }
    $rules = af_kb_arpg_extract_rules_leaf(is_array($stored['meta']['rules'] ?? null) ? $stored['meta']['rules'] : $stored);
    if (!$rules) {
        return null;
    }

    $metadata = $rules['ability_catalog'] ?? $meta['ability_catalog'] ?? null;
    $metadata = af_kb_ability_catalog_metadata_valid($metadata) ? $metadata : [];
    $options = $registry['options'];
    $weapons = array_values(array_intersect($metadata['weapons'] ?? [], array_keys($options['weapon'])));
    // A partly unrecognized list cannot prove exclusion or compatibility.
    if (count($weapons) !== count($metadata['weapons'] ?? [])) {
        $weapons = [];
    }
    $requirement = af_kb_arpg_canonical_element_key($metadata['required_element'] ?? '');
    if ($requirement !== 'any' && !isset($options['element'][$requirement]) && !isset($registry['directions'][$requirement])) {
        $requirement = null;
    }

    $categories = $elements = $directions = $purposes = [];
    $hasDamage = $ambiguousPurpose = false;
    $effects = af_kb_arpg_pick_array($rules, ['effects', 'ability_effects']);
    foreach ($effects as $effect) {
        if (!is_array($effect)) {
            $ambiguousPurpose = true;
            continue;
        }
        $kind = $effect['effect_type'] ?? $effect['kind'] ?? '';
        $purpose = af_kb_ability_catalog_effect_purpose($effect);
        if ($purpose === null) {
            $ambiguousPurpose = true;
        } else {
            $purposes[$purpose] = true;
        }
        if ($kind === 'damage') {
            $hasDamage = true;
            if (is_string($effect['damage_type'] ?? null) && $effect['damage_type'] !== '') {
                $categories[$effect['damage_type']] = true;
            } elseif (is_string($rules['damage_type'] ?? null)) {
                $categories[$rules['damage_type']] = true;
            }
        }
    }

    if (!$categories && is_string($rules['damage_type'] ?? null)) {
        $categories[$rules['damage_type']] = true;
    }
    $purpose = $metadata['purpose'] ?? null;
    if ($purpose === null && !$ambiguousPurpose && empty($rules['triggers']) && empty($rules['modifiers']) && $purposes) {
        $purpose = count($purposes) > 1 ? 'mixed' : array_key_first($purposes);
    }
    if (!$hasDamage && !$ambiguousPurpose && $purposes && empty($rules['triggers'])) {
        $categories = ['healing' => true];
    } elseif ($hasDamage) {
        unset($categories['healing']);
    }

    $unknownElement = false;
    $primary = af_kb_arpg_primary_elements();
    foreach (array_merge([$rules], $effects) as $definition) {
        if (!is_array($definition)) {
            continue;
        }
        $element = $definition['damage_element'] ?? $definition['element'] ?? '';
        if ($element !== '') {
            $element = is_string($element) ? af_kb_arpg_canonical_element_key($element) : '';
            if (isset($primary[$element])) {
                $elements[$element] = true;
            } else {
                $unknownElement = true;
            }
        }
        $reference = $definition['magic_direction_ref'] ?? null;
        if ($reference !== null) {
            $key = is_array($reference) ? ($reference['key'] ?? null) : null;
            $direction = is_string($key) ? ($registry['directions'][$key] ?? null) : null;
            if ($direction && array_key_exists('definition_version', $reference)
                && $reference['definition_version'] === $direction['version']
                && ($reference['fingerprint'] ?? null) === $direction['fingerprint']) {
                $directions[$key] = true;
                $elements[$direction['root']] = true;
            } else {
                $unknownElement = true;
            }
        }
    }
    if (isset($registry['directions'][$requirement])) {
        $directions[$requirement] = true;
        $elements[$registry['directions'][$requirement]['root']] = true;
    } elseif (isset($options['element'][$requirement]) && $requirement !== 'none') {
        $elements[$requirement] = true;
    }
    if (!$elements && !$unknownElement && ($requirement === 'none'
        || ($categories && !array_diff(array_keys($categories), ['physical', 'true', 'healing'])))) {
        $elements['none'] = true;
    }

    $type = af_kb_arpg_pick_first($rules, ['type', 'ability_type', 'activation_type', 'cast_kind', 'kind'], '');
    return [
        'type' => is_string($type) ? $type : '',
        'element' => array_keys($elements),
        'direction' => array_keys($directions),
        'damage_type' => array_keys(array_intersect_key($categories, $options['damage_type'])),
        'purpose' => $purpose,
        'applicability' => ['weapons' => $weapons, 'distance' => $metadata['distance'] ?? null, 'required_element' => $requirement],
    ];
}

function af_kb_ability_catalog_definition_id(array $row): string
{
    return substr(af_kb_arpg_acs_fingerprint(array_intersect_key($row, array_flip([
        'data_json', 'meta_json', 'title_ru', 'title_en', 'short_ru', 'short_en', 'body_ru', 'body_en',
    ]))), strlen('sha256:'));
}

function af_kb_ability_catalog_public_row(?array $row): bool
{
    return $row !== null && (int)$row['active'] === 1
        && af_kb_ability_catalog_visible(af_kb_decode_json($row['meta_json']))
        && af_kb_ability_catalog_visible(af_kb_decode_json($row['data_json']))
        && !empty(af_kb_find_type_row('arpg_ability')['active']);
}

/** Resolve a pinned character link. An unavailable or changed definition fails closed. */
function af_kb_arpg_character_ability_profile(array $ability, string $path, array &$diagnostics): array
{
    if (($ability['ability_source'] ?? null) !== 'arpg_ability') {
        return af_kb_arpg_acs_ability($ability, $path, $diagnostics);
    }
    $key = $ability['ability_kb_key'] ?? '';
    $row = is_string($key) && preg_match(AF_KB_KEY_PATTERN, $key) ? af_kb_arpg_acs_lookup('arpg_ability', $key) : null;
    if (!af_kb_ability_catalog_public_row($row)) {
        af_kb_arpg_acs_diagnostic($diagnostics, 'ability_definition_unavailable', $path);
        return [];
    }
    $source = af_kb_arpg_acs_source($row, $diagnostics);
    $version = ['revision' => $row['revision'] ?? $source['rules']['revision'] ?? null,
        'declared_version' => $source['rules']['version'] ?? null, 'fingerprint' => $source['fingerprint']];
    if (!is_array($ability['ability_definition_version'] ?? null)
        || af_kb_arpg_acs_fingerprint($ability['ability_definition_version']) !== af_kb_arpg_acs_fingerprint($version)
        || ($ability['ability_fingerprint'] ?? null) !== $source['fingerprint'] || !$source['fingerprint']) {
        af_kb_arpg_acs_diagnostic($diagnostics, 'ability_version_pin_mismatch', $path);
        return [];
    }
    if (($source['rules']['schema'] ?? null) !== AF_KB_ARPG_RULES_SCHEMA
        || ($source['rules']['type_profile'] ?? null) !== 'ability') {
        af_kb_arpg_acs_diagnostic($diagnostics, 'unsupported_rules_schema', $path);
        return [];
    }
    $profile = af_kb_arpg_acs_ability($source['rules'], $path, $diagnostics);
    $profile['references']['ability_kb_key'] = ['kind' => 'arpg_ability', 'key' => $key, 'resolved' => true,
        'entry_id' => $row['id'], 'entry_key' => $key, 'fingerprint' => $source['fingerprint'], 'definition_version' => $version];
    return $profile;
}

function af_kb_ability_catalog_weapon_types(string $weapon, array $registry): array
{
    return $registry['weapon_groups'][$weapon] ?? [$weapon];
}

function af_kb_ability_catalog_status(array $applicability, array $context, array $registry): string
{
    $weapon = $context['weapon'] ?? '';
    $allowed = $applicability['weapons'];
    $weaponMatch = null;
    if ($allowed === ['none'] || $allowed === ['any']) {
        $weaponMatch = true;
    } elseif ($allowed && $weapon !== '' && $weapon !== 'any') {
        $candidates = af_kb_ability_catalog_weapon_types($weapon, $registry);
        $allowedTypes = [];
        foreach ($allowed as $key) {
            $allowedTypes = array_merge($allowedTypes, af_kb_ability_catalog_weapon_types($key, $registry));
        }
        if (in_array($weapon, $allowed, true)) {
            $weaponMatch = true;
        } elseif ($candidates && $allowedTypes) {
            $overlap = array_intersect($candidates, $allowedTypes);
            $weaponMatch = !$overlap ? false : (count($overlap) === count($candidates) ? true : null);
        }
    }

    $distance = $applicability['distance'];
    $selectedDistance = $context['distance'] ?? '';
    $distanceMatch = $distance === 'any' ? true : null;
    if ($distance !== null && $distance !== 'any' && in_array($selectedDistance, ['melee', 'ranged'], true)) {
        $distanceMatch = $distance === $selectedDistance;
    }

    $required = $applicability['required_element'];
    $direction = $context['direction'] ?? '';
    $element = $context['element'] ?? '';
    $root = $registry['directions'][$direction]['root'] ?? '';
    $elementMatch = $required === 'any' ? true : null;
    if ($required !== null && $required !== 'any' && ($root === '' || $element === '' || $root === $element)) {
        if (isset($registry['directions'][$required])) {
            if ($direction !== '') {
                $elementMatch = $direction === $required;
            } elseif ($element !== '' && $element !== $registry['directions'][$required]['root']) {
                $elementMatch = false;
            }
        } elseif ($element !== '' || $root !== '') {
            $elementMatch = $required === ($root ?: $element);
        }
    }

    $matches = [$weaponMatch, $distanceMatch, $elementMatch];
    if (in_array(false, $matches, true)) {
        return 'incompatible';
    }
    return in_array(null, $matches, true) ? 'needs_review' : 'compatible';
}

function af_kb_ability_catalog_matches(array $ability, array $filters, array $registry): bool
{
    if (isset($filters['ability_type']) && $ability['type'] !== $filters['ability_type']) {
        return false;
    }
    foreach (['damage_type', 'element', 'direction'] as $facet) {
        if (isset($filters[$facet]) && !in_array($filters[$facet], $ability[$facet], true)) {
            return false;
        }
    }
    foreach (['distance', 'purpose'] as $facet) {
        $value = $facet === 'distance' ? $ability['applicability']['distance'] : $ability['purpose'];
        if (isset($filters[$facet]) && $filters[$facet] !== $value) {
            return false;
        }
    }
    if (!isset($filters['weapon'])) {
        return true;
    }
    $selected = $filters['weapon'];
    $candidates = af_kb_ability_catalog_weapon_types($selected, $registry);
    foreach ($ability['applicability']['weapons'] as $weapon) {
        if ((!in_array($selected, ['none', 'any'], true) && in_array($weapon, ['none', 'any'], true))
            || $weapon === $selected || array_intersect($candidates, af_kb_ability_catalog_weapon_types($weapon, $registry))) {
            return true;
        }
    }
    return false;
}

function af_kb_ability_catalog_input(array $registry, bool $api): array
{
    global $mybb;

    $filters = $context = [];
    foreach ($registry['options'] as $facet => $options) {
        $value = trim($mybb->get_input($facet));
        if ($facet === 'element') {
            $value = af_kb_arpg_canonical_element_key($value);
        }
        if ($value !== '' && isset($options[$value])) {
            $filters[$facet] = $value;
        } elseif ($value !== '' && $api) {
            af_kb_render_json_error('Invalid ' . $facet, 400);
        }
    }
    foreach (['element', 'direction', 'weapon', 'distance'] as $facet) {
        $value = $api ? trim($mybb->get_input('context_' . $facet)) : '';
        if ($facet === 'element') {
            $value = af_kb_arpg_canonical_element_key($value);
        }
        if ($facet === 'element' && isset($registry['directions'][$value])) {
            $context['direction'] = $value;
            $value = $registry['directions'][$value]['root'];
        }
        if ($value !== '' && !isset($registry['options'][$facet][$value])) {
            if (!$api || $mybb->get_input('independent_context') !== '1') {
                af_kb_render_json_error('Invalid context_' . $facet, 400);
            }
            $value = '';
        }
        $context[$facet] = $value !== '' ? $value : ($context[$facet] ?? (($api && $mybb->get_input('independent_context') === '1') ? '' : ($filters[$facet] ?? '')));
    }
    $query = trim($mybb->get_input('q'));
    if (my_strlen($query) > 100) {
        $query = my_substr($query, 0, 100);
    }
    return [$filters, $context, $query];
}

/**
 * Scan only the indexed active ability subset in keyset batches. Keep sort keys
 * and IDs until the page is known, then load presentation data for that page.
 */
function af_kb_ability_catalog_page(array $registry, array $filters, array $context, string $query, int $page, int $perPage, string $status = ''): array
{
    global $db;

    $where = "type='arpg_ability' AND active=1";
    if ($query !== '') {
        $literal = strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']);
        $like = $db->escape_string('%' . $literal . '%');
        $where .= " AND (title_ru LIKE '{$like}' ESCAPE '!' OR title_en LIKE '{$like}' ESCAPE '!')";
    }
    $sort = $filters['sort'] ?? 'title';
    $matches = [];
    $cursor = '';
    do {
        $batch = $db->simple_select('af_kb_entries', 'id,type,`key`,title_ru,title_en,active,sortorder,meta_json,data_json',
            $where . $cursor, ['order_by' => 'sortorder ASC, id', 'order_dir' => 'ASC', 'limit' => 200]);
        $read = 0;
        while ($row = $db->fetch_array($batch)) {
            ++$read;
            $lastId = (int)$row['id'];
            $lastSort = (int)$row['sortorder'];
            $cursor = " AND (sortorder>{$lastSort} OR sortorder={$lastSort} AND id>{$lastId})";
            $ability = af_kb_ability_catalog_project($row, $registry);
            if ($ability === null || !af_kb_ability_catalog_matches($ability, $filters, $registry)
                || ($status !== '' && af_kb_ability_catalog_status($ability['applicability'], $context, $registry) !== $status)) {
                continue;
            }
            $label = '';
            if ($sort === 'element') {
                $labels = array_intersect_key($registry['options']['element'], array_flip($ability['element']));
                $labels = array_values($labels);
                natcasesort($labels);
                $label = $labels ? reset($labels) : '';
            } elseif ($sort === 'type') {
                $label = $registry['options']['ability_type'][$ability['type']] ?? $ability['type'];
            }
            $preference = 0;
            if ($status === 'compatible' && !isset($filters['sort'])) {
                if (in_array($context['element'] ?? '', $ability['element'], true)) --$preference;
                if (in_array($context['direction'] ?? '', $ability['direction'], true)) --$preference;
            }
            $matches[$lastId] = [$preference, $label, af_kb_pick_text($row, 'title') ?: $row['key']];
        }
        $db->free_result($batch);
    } while ($read === 200);

    uasort($matches, static function (array $left, array $right): int {
        return ($left[0] <=> $right[0]) ?: strnatcasecmp($left[1], $right[1]) ?: strnatcasecmp($left[2], $right[2]);
    });
    $total = count($matches);
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min(max(1, $page), $pages);
    $ids = array_keys(array_slice($matches, ($page - 1) * $perPage, $perPage, true));
    $items = $rows = [];
    if ($ids) {
        $result = $db->simple_select('af_kb_entries',
            'id,type,`key`,title_ru,title_en,short_ru,short_en,body_ru,body_en,active,meta_json,data_json,icon_url,icon_class,banner_url,bg_url',
            $where . ' AND id IN (' . implode(',', $ids) . ')');
        while ($row = $db->fetch_array($result)) {
            $ability = af_kb_ability_catalog_project($row, $registry);
            if ($ability === null || !af_kb_ability_catalog_matches($ability, $filters, $registry)
                || ($status !== '' && af_kb_ability_catalog_status($ability['applicability'], $context, $registry) !== $status)) {
                continue;
            }
            $id = (int)$row['id'];
            $ui = af_kb_get_entry_ui($row);
            $items[$id] = array_merge($ability, [
                'kb_key' => $row['key'],
                'title' => af_kb_pick_text($row, 'title') ?: $row['key'],
                'description' => af_kb_pick_text($row, 'short'),
                'icon' => ['url' => af_kb_sanitize_url($ui['icon_url']), 'class' => af_kb_sanitize_icon_class($ui['icon_class'])],
                'definition_id' => af_kb_ability_catalog_definition_id($row),
                'url' => af_kb_url(['type' => 'arpg_ability', 'key' => $row['key']]),
                'compatibility' => af_kb_ability_catalog_status($ability['applicability'], $context, $registry),
            ]);
            $rows[$row['key']] = $row;
        }
    }
    $orderedItems = [];
    foreach ($ids as $id) {
        if (isset($items[$id])) {
            $orderedItems[] = $items[$id];
        }
    }
    return ['items' => $orderedItems, 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages];
}

function af_kb_handle_ability_catalog_json(): void
{
    global $mybb;

    if (strtoupper($mybb->request_method ?? 'GET') !== 'GET') {
        header('Allow: GET');
        af_kb_render_json_error('Method not allowed', 405);
    }
    if (!af_kb_can_view()) {
        af_kb_render_json_error('No access', 403);
    }
    $type = af_kb_find_type_row('arpg_ability');
    if (!af_kb_catalog_type_is_public($type)) {
        af_kb_render_json_error('Ability catalog unavailable', 404);
    }

    $registry = af_kb_ability_catalog_registry();
    [$filters, $context, $query] = af_kb_ability_catalog_input($registry, true);
    $perPage = (int)$mybb->get_input('per_page', MyBB::INPUT_INT);
    $status = $mybb->get_input('compatibility');
    if (!in_array($status, ['', 'compatible', 'needs_review', 'incompatible'], true)) {
        af_kb_render_json_error('Invalid compatibility', 400);
    }
    $page = af_kb_ability_catalog_page($registry, $filters, $context, $query,
        (int)$mybb->get_input('page', MyBB::INPUT_INT), min(50, $perPage > 0 ? $perPage : AF_KB_PERPAGE), $status);
    if ($mybb->get_input('options') === '1') {
        $page['options'] = $registry['options'];
    }
    unset($page['rows']);
    af_kb_send_json(['success' => true] + $page);
}

function af_kb_render_ability_catalog(): void
{
    global $mybb, $lang, $headerinclude, $header, $footer;

    $typeRow = af_kb_find_type_row('arpg_ability');
    if (!af_kb_catalog_type_is_public($typeRow)) {
        error($lang->af_kb_not_found ?? 'Not found');
    }
    $ru = af_kb_is_ru();
    $registry = af_kb_ability_catalog_registry();
    [$filters, $context, $query] = af_kb_ability_catalog_input($registry, false);
    $page = af_kb_ability_catalog_page($registry, $filters, $context, $query,
        (int)$mybb->get_input('page', MyBB::INPUT_INT), AF_KB_PERPAGE);

    $url = af_kb_url(['type' => 'arpg_ability']);
    $title = af_kb_pick_text($typeRow, 'title') ?: ($ru ? 'Способности' : 'Abilities');
    if (function_exists('add_breadcrumb')) {
        add_breadcrumb($lang->af_kb_catalog_title ?? 'Knowledge Base', af_kb_url());
        add_breadcrumb($title, $url);
    }
    $kb_page_title = htmlspecialchars_uni($title);
    $kb_body_style = af_kb_build_body_bg_style($typeRow['bg_url'] ?? '');
    if (af_kb_frontend_asset_allowed('header_ensure')) {
        $kb_body_style .= '<style>
            .af-kb-ability-filters { display: grid; grid-template-columns: repeat(auto-fit,minmax(min(100%,9rem),1fr)); gap: .65rem; align-items: end; margin: 0 0 1rem; }
            .af-kb-ability-filters label { display: grid; gap: .25rem; min-width: 0; }
            .af-kb-ability-filters :is(input,select) { width: 100%; min-width: 0; box-sizing: border-box; }
        </style>';
    }

    $labels = $ru
        ? ['ability_type' => 'Тип способности', 'damage_type' => 'Урон', 'element' => 'Стихия', 'direction' => 'Направление',
            'distance' => 'Дистанция', 'weapon' => 'Оружие', 'purpose' => 'Назначение', 'sort' => 'Сортировка']
        : ['ability_type' => 'Ability type', 'damage_type' => 'Damage', 'element' => 'Element', 'direction' => 'Direction',
            'distance' => 'Distance', 'weapon' => 'Weapon', 'purpose' => 'Purpose', 'sort' => 'Sort'];
    $form = '<form class="af-kb-ability-filters" method="get" action="' . (af_kb_alias_available() ? 'kb.php' : 'misc.php') . '">';
    if (!af_kb_alias_available()) {
        $form .= '<input type="hidden" name="action" value="kb" />';
    }
    $form .= '<input type="hidden" name="type" value="arpg_ability" /><label>'
        . ($ru ? 'Поиск по названию' : 'Search by name')
        . '<input type="search" class="textbox" name="q" maxlength="100" value="' . htmlspecialchars_uni($query) . '" /></label>';
    foreach ($labels as $facet => $label) {
        $form .= '<label>' . htmlspecialchars_uni($label) . '<select name="' . $facet . '">';
        if ($facet !== 'sort') {
            $form .= '<option value="">' . ($ru ? 'Все' : 'All') . '</option>';
        }
        foreach ($registry['options'][$facet] as $key => $option) {
            $selected = ($filters[$facet] ?? '') === (string)$key ? ' selected' : '';
            $form .= '<option value="' . htmlspecialchars_uni((string)$key) . '"' . $selected . '>'
                . htmlspecialchars_uni($option) . '</option>';
        }
        $form .= '</select></label>';
    }
    $form .= '<button class="af-kb-btn" type="submit">' . ($ru ? 'Применить' : 'Apply') . '</button></form>';

    $statuses = $ru
        ? ['compatible' => 'Подходит', 'incompatible' => 'Не подходит', 'needs_review' => 'Требует уточнения']
        : ['compatible' => 'Compatible', 'incompatible' => 'Incompatible', 'needs_review' => 'Needs review'];
    $showCompatibility = (bool)array_filter($context);
    $cards = '';
    foreach ($page['items'] as $ability) {
        $tags = [];
        foreach ([
            'ability_type' => [$ability['type']],
            'element' => $ability['element'],
            'direction' => $ability['direction'],
            'weapon' => $ability['applicability']['weapons'],
            'distance' => [$ability['applicability']['distance'] ?? ''],
        ] as $facet => $keys) {
            foreach ($keys as $key) {
                if (isset($registry['options'][$facet][$key])) {
                    $tags[] = $registry['options'][$facet][$key];
                }
            }
        }
        if (!$ability['applicability']['weapons']) {
            $tags[] = $ru ? 'Оружие требует уточнения' : 'Weapon requirement unclear';
        }
        if ($showCompatibility) {
            $tags[] = $statuses[$ability['compatibility']];
        }
        $cards .= af_kb_catalog_entry_card($page['rows'][$ability['kb_key']], $typeRow, $tags, $ru ? 'Просмотр механики' : 'View mechanics');
    }
    if ($cards === '') {
        $cards = '<div class="af-kb-empty">' . ($ru ? 'Способности не найдены.' : 'No abilities found.') . '</div>';
    }
    $params = ['type' => 'arpg_ability'] + $filters;
    if ($query !== '') {
        $params['q'] = $query;
    }
    $pagination = $page['pages'] > 1 ? multipage($page['total'], AF_KB_PERPAGE, $page['page'], af_kb_url($params)) : '';
    $actions = af_kb_back_link_html(af_kb_url(), ['kb']);
    if (af_kb_can_edit()) {
        $managementUrl = af_kb_url(['type' => 'arpg_ability', 'manage' => '1'], true);
        $actions .= '<a class="af-kb-btn" href="' . $managementUrl . '">' . ($ru ? 'Управление записями' : 'Manage entries') . '</a>';
    }
    $af_kb_content = '<section class="af-kb-ability-catalog"><div class="af-kb-category-toolbar">' . $actions . '</div>'
        . '<header class="af-kb-type-header af-kb-category-heading"><h1>' . $kb_page_title . '</h1></header>'
        . $form . '<div class="af-kb-entries af-kb-entries--cards af-kb-category-entry-grid">' . $cards
        . '</div><div class="af-kb-pagination">' . $pagination . '</div></section>';
    eval("\$output = \"" . af_kb_get_template('knowledgebase_page') . "\";");
    output_page($output);
    exit;
}

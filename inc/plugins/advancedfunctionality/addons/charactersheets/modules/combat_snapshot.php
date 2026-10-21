<?php
/** Explicit server-only read path. Never call the view/calculator/sync stack here. */
if (!defined('IN_MYBB')) { die('No direct access'); }

function af_cs_snapshot_issue(array &$issues, string $code, string $path, string $severity = 'error'): void
{
    $issues[] = ['code'=>$code, 'path'=>$path, 'severity'=>$severity];
}

function af_cs_snapshot_number($value): bool
{
    return !is_bool($value) && is_numeric($value) && is_finite((float)$value) && (float)$value >= 0;
}

/** Authenticated actor comes from MyBB, never context/uid claims. No data on refusal. */
function af_charactersheets_prepare_combat_snapshot(int $sheetId, int $actorUid, array $context = []): array
{
    global $mybb, $db;
    $denied = ['schema_version'=>1, 'contract_id'=>'IC-CS.combat-preparation.v1', 'ready'=>false,
        'diagnostics'=>[['code'=>'access_denied','path'=>'actor','severity'=>'error']]];
    if ($sheetId <= 0 || $actorUid <= 0 || $actorUid !== (int)($mybb->user['uid'] ?? 0)) return $denied;
    foreach (['charactersheets','knowledgebase','advancedinventory'] as $addon) {
        if (!function_exists('af_is_addon_enabled') || !af_is_addon_enabled($addon)) {
            return ['schema_version'=>1,'contract_id'=>'IC-CS.combat-preparation.v1','ready'=>false,
                'diagnostics'=>[['code'=>'dependency_unavailable','path'=>$addon,'severity'=>'error']]];
        }
    }
    // Only these declaration-only KB readers are loaded on an explicit API call.
    require_once AF_ADDONS . 'knowledgebase/knowledgebase.php';
    if (!is_object($db) || !$db->table_exists(AF_CS_SHEETS_TABLE)) return $denied;
    $sheet = $db->fetch_array($db->simple_select(AF_CS_SHEETS_TABLE, '*', 'id='.$sheetId, ['limit'=>1]));
    if (!is_array($sheet) || !af_charactersheets_user_can_edit_sheet($sheet, $mybb->user) || !af_kb_can_view()) return $denied;
    $tid = (int)($sheet['tid'] ?? 0);
    $thread = $db->fetch_array($db->simple_select('threads', '*', 'tid='.$tid, ['limit'=>1]));
    if (!is_array($thread) || !function_exists('forum_permissions') || !function_exists('get_forum')) return $denied;
    $fid = (int)$thread['fid'];
    $permissions = forum_permissions($fid);
    $forum = get_forum($fid);
    // Password-protected forums need a separately verified owner contract; no bypass.
    if (!$forum || empty($permissions['canview']) || empty($permissions['canviewthreads']) || !empty($forum['password'])
        || (int)($thread['visible'] ?? 0) !== 1
        || (!empty($permissions['canonlyviewownthreads']) && (int)$thread['uid'] !== $actorUid)) return $denied;
    try {
        return af_cs_snapshot_read($sheet, $context);
    } catch (Throwable $error) {
        return ['schema_version'=>1,'contract_id'=>'IC-CS.combat-preparation.v1','ready'=>false,
            'diagnostics'=>[['code'=>'source_read_failed','path'=>'sources','severity'=>'error']]];
    }
}

/** Read set includes exact SQL predicates and captured rows for optimistic readback. */
function af_cs_snapshot_select(string $table, string $where, array &$readSet): array
{
    global $db;
    if (!$db->table_exists($table)) throw new RuntimeException('Missing source table');
    $rows = [];
    $query = $db->simple_select($table, '*', $where);
    while ($row = $db->fetch_array($query)) $rows[] = $row;
    usort($rows, static fn($a,$b) => strcmp(af_kb_arpg_acs_fingerprint($a),af_kb_arpg_acs_fingerprint($b)));
    $readSet[] = ['table'=>$table,'where'=>$where,'hash'=>af_kb_arpg_acs_fingerprint($rows)];
    return $rows;
}

function af_cs_snapshot_definition(string $type, string $key, array &$readSet, array &$issues): ?array
{
    global $db;
    if (!preg_match(AF_KB_KEY_PATTERN, $key)) {
        af_cs_snapshot_issue($issues, 'definition_key_invalid', $type); return null;
    }
    $where = "type='".$db->escape_string($type)."' AND `key`='".$db->escape_string($key)."'";
    $rows = af_cs_snapshot_select('af_kb_entries', $where, $readSet);
    if (count($rows) !== 1 || empty($rows[0]['active'])) {
        af_cs_snapshot_issue($issues, 'definition_missing_or_ambiguous', $type); return null;
    }
    if (!af_kb_entry_visible_in_context($rows[0], 'entry', af_kb_can_edit())) {
        af_cs_snapshot_issue($issues, 'definition_access_denied', $type); return null;
    }
    $problems = [];
    $source = af_kb_arpg_acs_source($rows[0], $problems);
    $issues = array_merge($issues, $problems);
    if ($type !== 'arpg_element' && (($source['rules']['schema'] ?? null) !== AF_KB_ARPG_RULES_SCHEMA || !str_starts_with($type,'arpg_'))) af_cs_snapshot_issue($issues,'definition_contract_unverified',$type);
    return ['type'=>$type, 'key'=>$key, 'entry'=>$rows[0], 'rules'=>$source['rules'],
        'version'=>['schema'=>$source['rules']['schema'] ?? null,'revision'=>$rows[0]['revision'] ?? $source['rules']['revision'] ?? null,
            'declared_version'=>$source['rules']['version'] ?? null,'fingerprint'=>$source['fingerprint']]];
}

/** One application per modifier, reusing the exact CS legacy arithmetic. */
function af_cs_snapshot_apply(array $stats, array $source, array &$breakdown, array &$issues): array
{
    $definitions = af_kb_arpg_origin_modifier_stat_definitions();
    foreach (['base_stats','modifiers'] as $bucket) {
        $rows = $source['rules'][$bucket] ?? [];
        if (!is_array($rows)) { af_cs_snapshot_issue($issues,'modifier_list_invalid',$source['id'].'.'.$bucket); continue; }
        foreach ($rows as $index => $modifier) {
            if (!is_array($modifier)) { af_cs_snapshot_issue($issues,'modifier_invalid',$source['id'].'.'.$bucket); continue; }
            $key = $modifier['stat_key'] ?? '';
            $storageKey = is_string($key) ? af_kb_arpg_storage_stat_key($key) : '';
            $definition = $definitions[$storageKey] ?? null;
            $mode = $modifier['mode'] ?? 'flat';
            $conditional = trim((string)($modifier['condition_text'] ?? '')) !== '';
            $valid = $definition && in_array($mode,['flat','percent'],true) && !is_bool($modifier['value'] ?? null)
                && is_numeric($modifier['value'] ?? null) && is_finite((float)$modifier['value']);
            $record = ['source_id'=>$source['id'],'source_type'=>$source['kind'],'source_version'=>$source['version'],
                'stat_key'=>$storageKey === 'status_resist' ? 'status_resistance' : $storageKey,'source_stat_key'=>$key,
                'operation'=>$mode,'value'=>$modifier['value'] ?? null,'source_path'=>$bucket.'.'.$index,'included_in_total'=>false];
            if (!$valid || $conditional) {
                af_cs_snapshot_issue($issues, $conditional ? 'conditional_modifier_requires_combat_adapter' : 'modifier_unsupported', $source['id'].'.'.$bucket.'.'.$index);
            } else {
                $field = $definition['vm_field'];
                if (!array_key_exists($field,$stats)) {
                    af_cs_snapshot_issue($issues,'modifier_base_missing',$field);
                } else {
                    $record['before'] = $stats[$field];
                    $stats = af_charactersheets_arpg_apply_equipment_rules($stats, [[$bucket=>[$modifier]]]);
                    $record['after'] = $stats[$field];
                    $record['included_in_total'] = true;
                }
            }
            $breakdown[] = $record;
        }
    }
    foreach (['math_modifiers','passive_effects','bonuses','permanent_modifiers'] as $field) {
        if (!empty($source['rules'][$field])) af_cs_snapshot_issue($issues,'source_semantics_unverified',$source['id'].'.'.$field);
    }
    return $stats;
}

/** Internal owner implementation: caller must pass the authenticated permission boundary. */
function af_cs_snapshot_read(array $sheet, array $context): array
{
    global $db;
    $issues = $readSet = $definitions = $breakdown = [];
    $sheetId = (int)$sheet['id']; $uid = (int)$sheet['uid']; $tid = (int)$sheet['tid'];
    $failure = static function (string $code) { return ['schema_version'=>1,'contract_id'=>'IC-CS.combat-preparation.v1','ready'=>false,
        'diagnostics'=>[['code'=>$code,'path'=>'character','severity'=>'error']]]; };
    if ($uid <= 0 || $tid <= 0) return $failure('sheet_owner_missing');
    $freshSheet = af_cs_snapshot_select(AF_CS_SHEETS_TABLE, 'id='.$sheetId, $readSet);
    if (count($freshSheet) !== 1 || $freshSheet[0] !== $sheet) return $failure('source_changed_during_read');
    af_cs_snapshot_select('threads', 'tid='.$tid, $readSet);
    $accept = af_cs_snapshot_select(AF_CS_TABLE, 'tid='.$tid, $readSet);
    if (count($accept) !== 1 || (int)($accept[0]['kb_entry_id'] ?? 0) <= 0) return $failure('explicit_character_link_missing');
    $linked = af_cs_snapshot_select('af_kb_entries', 'id='.(int)$accept[0]['kb_entry_id'], $readSet);
    if (count($linked) !== 1 || ($linked[0]['type'] ?? '') !== 'character' || empty($linked[0]['active'])) return $failure('character_definition_missing');
    if (!af_kb_entry_visible_in_context($linked[0], 'entry', af_kb_can_edit())) return $failure('definition_access_denied');
    $raw = af_kb_arpg_acs_source($linked[0], $issues);
    $rules = $raw['rules'];
    $meta = $rules['character_meta'] ?? [];
    if (($rules['schema'] ?? null) !== AF_KB_CHARACTER_SCHEMA || ($meta['mechanic'] ?? null) !== 'arpg') return $failure('arpg_contract_required');
    if ((isset($meta['contract']) && $meta['contract'] !== AF_KB_CHARACTER_SCHEMA) || (isset($meta['contract_version']) && $meta['contract_version'] !== '1.0')) return $failure('character_contract_version_unsupported');
    if ((int)($meta['availability']['owner_uid'] ?? 0) !== $uid || (int)($meta['source_tid'] ?? 0) !== $tid) return $failure('character_owner_link_mismatch');
    $build = json_decode((string)($sheet['build_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($build)) return $failure('build_invalid');
    $profile = $rules['character_profile'] ?? [];
    $rawStats = $rules['character_stats'] ?? [];
    if (!is_array($profile) || !is_array($rawStats)) return $failure('character_stats_invalid');
    foreach (['hp','health','defense','def','attack_power','atk','attack','crit_rate','crit_damage','critical_damage','elemental_mastery','mastery','element_damage_bonus','elemental_damage_bonus','healing_bonus','shield_strength','healing_received_bonus','accuracy','luck','status_hit','status_resist'] as $alias) {
        if (array_key_exists($alias,$rawStats)) af_cs_snapshot_issue($issues,'legacy_stat_alias_untracked','character_stats.'.$alias);
    }
    foreach (array_merge(array_column(af_kb_arpg_character_stat_registry(),'vm_field'),['character_status_resistance']) as $field) {
        if (array_key_exists($field,$rawStats) && !af_cs_snapshot_number($rawStats[$field])) af_cs_snapshot_issue($issues,'invalid_stat_value','character_stats.'.$field);
    }
    // The audited ATF→KB producer never applies live equipment/talents to these totals.
    if (($meta['source'] ?? null) !== 'af_atf_bridge') af_cs_snapshot_issue($issues,'base_equipment_inclusion_unknown','character_meta.source');
    foreach (['equipment_bonuses','permanent_modifiers','base_stats','modifiers','math_modifiers'] as $field) {
        if (!empty($build[$field]) || !empty($rules[$field])) af_cs_snapshot_issue($issues,'source_breakdown_unavailable',$field);
    }
    $originRules = $archetypeRules = $variantRules = [];
    foreach (['origin'=>['arpg_origin','character_origin'], 'archetype'=>['arpg_archetype','character_archetype'], 'origin_variant'=>['arpg_origin_variant','character_origin_variant']] as $kind=>[$type,$field]) {
        $key = $profile[$field] ?? ($kind === 'archetype' ? ($profile['character_class'] ?? '') : ($kind === 'origin' ? ($profile['character_race'] ?? '') : ''));
        if ($key === '') continue;
        if (!is_string($key)) { af_cs_snapshot_issue($issues,'definition_key_invalid',$field); continue; }
        $definition = af_cs_snapshot_definition($type,$key,$readSet,$issues);
        if (!$definition) continue;
        $definitions[$kind] = $definition;
        $sourceRules = $definition['rules'];
        foreach ($sourceRules as $field=>$value) {
            if ((str_ends_with((string)$field,'_base') || str_ends_with((string)$field,'_per_level') || in_array($field,['movement_speed','base_damage_bonus','base_defense_bonus'],true))
                && (is_bool($value) || !is_numeric($value) || !is_finite((float)$value))) af_cs_snapshot_issue($issues,'invalid_stat_value',$kind.'.'.$field);
        }
        foreach (['base_stats','math_modifiers','permanent_modifiers'] as $field) if (!empty($sourceRules[$field])) af_cs_snapshot_issue($issues,'source_semantics_unverified',$kind.'.'.$field);
        if ($kind !== 'origin_variant' && !empty($sourceRules['modifiers'])) af_cs_snapshot_issue($issues,'source_semantics_unverified',$kind.'.modifiers');
        if ($kind === 'origin_variant') foreach (($sourceRules['modifiers'] ?? []) as $modifier) {
            if (af_kb_arpg_validate_origin_variant_modifier($modifier) || !is_finite((float)($modifier['value'] ?? 0)) || !empty($modifier['condition_text'])) af_cs_snapshot_issue($issues,'modifier_unsupported',$kind.'.modifiers');
        }
        if (array_key_exists('status_resistance_base',$sourceRules)) af_cs_snapshot_issue($issues,'canonical_base_requires_view_alignment',$kind.'.status_resistance_base');
        if ($kind === 'origin') $originRules = $sourceRules;
        elseif ($kind === 'archetype') $archetypeRules = $sourceRules;
        else $variantRules = $sourceRules;
    }
    if ($variantRules) {
        $variantKey = $db->escape_string($definitions['origin_variant']['key']);
        af_cs_snapshot_select('af_kb_relations', "to_type='arpg_origin_variant' AND to_key='".$variantKey."' AND from_type='arpg_origin' AND rel_type='origin_has_variant'", $readSet);
        // Parent relationship is owned by KB; no synthesized relation from the key/name.
        $parent = af_kb_get_origin_parent_for_variant($definitions['origin_variant']['key'], true);
        if (($parent['origin']['key'] ?? null) !== ($definitions['origin']['key'] ?? null)) af_cs_snapshot_issue($issues,'origin_variant_parent_mismatch','origin_variant');
    }
    $level = $profile['character_level'] ?? $profile['level'] ?? 1;
    if (!is_numeric($level) || (float)$level < 1 || (float)$level > PHP_INT_MAX || (float)$level !== floor((float)$level)) return $failure('level_invalid');
    if (array_filter($issues,static fn($i) => in_array($i['code'],['invalid_stat_value','modifier_unsupported'],true))) return ['schema_version'=>1,'contract_id'=>'IC-CS.combat-preparation.v1','ready'=>false,'diagnostics'=>$issues];
    $computed = af_kb_build_arpg_character_stats_from_sources($profile,[],$originRules,$variantRules,$archetypeRules);
    $stats = af_kb_build_arpg_character_stats_from_sources($profile,$rawStats,$originRules,$variantRules,$archetypeRules);
    $provided = [];
    $originDefinitions = af_kb_arpg_origin_modifier_stat_definitions();
    foreach (af_kb_arpg_character_stat_registry() as $key=>$entry) {
        $field = $entry['vm_field'];
        $baseField = $key === 'speed' ? 'movement_speed' : ($originDefinitions[$key]['origin_field'] ?? null);
        $baseSources = $key === 'speed' ? [$originRules,$variantRules] : [$originRules,$variantRules,$archetypeRules];
        $provided[$field] = array_key_exists($field,$rawStats);
        foreach ($baseSources as $sourceRules) {
            if ($baseField !== null && array_key_exists($baseField,$sourceRules)) {
                if (!af_cs_snapshot_number($sourceRules[$baseField])) af_cs_snapshot_issue($issues,'invalid_stat_value',$baseField);
                else $provided[$field] = true;
            }
        }
    }
    $statusIssues = [];
    $status = af_kb_arpg_status_resistance($rawStats,'character_stats',$statusIssues,'character_status_resistance','character_status_resist');
    if ($status['available']) {
        $stats['character_status_resist'] = ($computed['character_status_resist'] ?? 0) + (float)$status['value'];
        $provided['character_status_resist'] = true;
    } elseif (!$provided['character_status_resist'] || array_key_exists('character_status_resist',$rawStats) || array_key_exists('character_status_resistance',$rawStats)) {
        unset($stats['character_status_resist']);
        $issues = array_merge($issues,$statusIssues);
    }
    foreach (af_kb_arpg_character_stat_registry() as $key=>$entry) {
        $field = $entry['vm_field'];
        // No default zero from a helper qualifies as an explicit source value.
        if (!$provided[$field]) {
            unset($stats[$field]);
            af_cs_snapshot_issue($issues,'required_stat_missing',$field);
        }
        if (array_key_exists($field,$rawStats) && !af_cs_snapshot_number($rawStats[$field])) af_cs_snapshot_issue($issues,'invalid_stat_value',$field);
        if ($key === 'accuracy' && isset($variantRules['accuracy_base'])) af_cs_snapshot_issue($issues,'legacy_variant_accuracy_projection_unverified',$field);
        $breakdown[] = ['source_id'=>'character:'.$linked[0]['key'],'source_type'=>'base_stats','stat_key'=>$entry['acs_key'] ?? $key,
            'operation'=>$key === 'accuracy' ? 'base_total' : 'manual_addition','value'=>$rawStats[$field] ?? ($key === 'status_resist' ? ($status['value'] ?? null) : null),
            'included_in_total'=>array_key_exists($field,$rawStats) || ($key === 'status_resist' && $status['available']),
            'source_version'=>['schema'=>$rules['schema'],'fingerprint'=>$raw['fingerprint']],
            'computed_candidate'=>$computed[$field] ?? null];
    }
    $baseStats = $stats;
    foreach ($definitions as $kind=>$definition) {
        $candidate = af_kb_build_arpg_character_stats_from_sources($profile,[],$kind === 'origin' ? $originRules : [],$kind === 'origin_variant' ? $variantRules : [],$kind === 'archetype' ? $archetypeRules : []);
        $breakdown[] = ['source_id'=>$definition['type'].':'.$definition['key'],'source_type'=>$kind,'source_version'=>$definition['version'],
            'operation'=>'kb_base_and_growth','contributions'=>$candidate,'included_in_total'=>true,'accuracy_included_in_total'=>!array_key_exists('character_accuracy',$rawStats)];
    }
    $equipped = af_cs_snapshot_select('af_advinv_equipped','uid='.$uid,$readSet);
    $itemIds = array_values(array_unique(array_map(static fn($b) => max(0,(int)($b['item_id'] ?? 0)), $equipped)));
    $talentRefs = array_values((array)($build['talents']['slots'] ?? []));
    $talentRefs = array_filter($talentRefs, static fn($key) => is_string($key) && preg_match(AF_KB_KEY_PATTERN,$key));
    $itemPredicate = 'id IN ('.implode(',', $itemIds ?: [0]).')';
    if ($talentRefs) $itemPredicate .= " OR (entity='abilities' AND subtype='talent' AND kb_type='arpg_talent' AND kb_key IN ('".implode("','",array_map([$db,'escape_string'],$talentRefs))."'))";
    $inventory = af_cs_snapshot_select('af_advinv_items','uid='.$uid.' AND ('.$itemPredicate.')',$readSet);
    $items = []; foreach ($inventory as $item) $items[(int)$item['id']] = $item;
    usort($equipped, static fn($a,$b) => strcmp((string)$a['equip_slot'],(string)$b['equip_slot']));
    $equipment = []; $equipmentDefinitions = []; $seen = []; $weapons = 0;
    foreach ($equipped as $binding) {
        $id = (int)($binding['item_id'] ?? 0); $slot = (string)($binding['equip_slot'] ?? '');
        $item = $items[$id] ?? null;
        if (!$item || (int)($item['qty'] ?? 0) <= 0 || ($item['entity'] ?? '') !== 'equipment' || isset($seen[$id])) {
            af_cs_snapshot_issue($issues,'equipment_binding_invalid','equipment'); continue;
        }
        $seen[$id] = true;
        if (($item['subtype'] ?? '') === 'weapon' || str_starts_with($slot,'weapon_')) ++$weapons;
        if (($item['kb_type'] ?? '') !== 'arpg_item') { af_cs_snapshot_issue($issues,'equipment_definition_not_arpg','equipment.'.$id); continue; }
        $definition = af_cs_snapshot_definition('arpg_item',(string)($item['kb_key'] ?? ''),$readSet,$issues);
        if (!$definition) continue;
        $equipmentDefinitions[] = $definition;
        if (!in_array($item['subtype'] ?? '', ['weapon','armor','ammo','gear','artifact'], true)) af_cs_snapshot_issue($issues,'equipment_subtype_unverified','equipment.'.$id);
        $source = ['id'=>'inventory:'.$id,'kind'=>'equipment','version'=>$definition['version'], 'rules'=>$definition['rules']];
        $equipment[] = ['item_id'=>$id,'slot'=>$slot,'binding'=>$binding,'instance_fingerprint'=>af_kb_arpg_acs_fingerprint($item),
            'kb_ref'=>['type'=>$definition['type'],'key'=>$definition['key'],'version'=>$definition['version']],'included_in_total'=>true];
        $stats = af_cs_snapshot_apply($stats,$source,$breakdown,$issues);
        // Instance meta bonuses are not used by the ARPG stat panel; cannot silently apply them.
        $instanceMeta = json_decode((string)($item['meta_json'] ?? '{}'),true);
        if (!is_array($instanceMeta)) af_cs_snapshot_issue($issues,'equipment_instance_metadata_invalid','equipment.'.$id);
        if (!empty($instanceMeta['bonuses']) || !empty($instanceMeta['modifiers'])) af_cs_snapshot_issue($issues,'equipment_instance_bonus_semantics_unknown','equipment.'.$id);
    }
    if ($weapons > 1) af_cs_snapshot_issue($issues,'active_weapon_ambiguous','equipment');
    $talents = []; $talentDefinitions = []; $talentKeys = [];
    if (isset($build['talents']['slots']) && !is_array($build['talents']['slots'])) af_cs_snapshot_issue($issues,'talent_slots_invalid','talents');
    foreach (is_array($build['talents']['slots'] ?? null) ? $build['talents']['slots'] : [] as $slot=>$key) {
        if ($key === '') continue;
        $owned = array_filter($inventory, static fn($item) => ($item['entity'] ?? '') === 'abilities' && ($item['subtype'] ?? '') === 'talent' && ($item['kb_type'] ?? '') === 'arpg_talent' && ($item['kb_key'] ?? '') === $key && (int)($item['qty'] ?? 0) > 0);
        if (!$owned || isset($talentKeys[$key])) { af_cs_snapshot_issue($issues,'talent_ownership_or_slot_invalid','talents'); continue; }
        $talentKeys[$key] = true;
        $definition = af_cs_snapshot_definition('arpg_talent',$key,$readSet,$issues);
        if (!$definition) continue;
        $talentDefinitions[] = $definition;
        $talents[] = ['slot'=>$slot,'key'=>$key,'version'=>$definition['version'],'ownership_item_ids'=>array_values(array_map(static fn($row) => (int)$row['id'],$owned)),
            'ownership_fingerprints'=>array_values(array_map('af_kb_arpg_acs_fingerprint',$owned))];
        $stats = af_cs_snapshot_apply($stats,['id'=>'talent:'.$key,'kind'=>'talents','version'=>$definition['version'],'rules'=>$definition['rules']],$breakdown,$issues);
    }
    $characteristics = $base = [];
    foreach (af_kb_arpg_character_stat_registry() as $key=>$entry) {
        $canonical = $entry['acs_key'] ?? $key; $field = $entry['vm_field'];
        if (array_key_exists($field,$baseStats)) $base[$canonical] = $baseStats[$field];
        if (array_key_exists($field,$stats) && af_cs_snapshot_number($stats[$field])) $characteristics[$canonical] = $stats[$field];
        else af_cs_snapshot_issue($issues,'required_stat_missing_or_invalid',$canonical);
    }
    $resRows = af_kb_arpg_acs_resistances($rules['resistances'] ?? [],'resistances',$issues);
    $resistances = af_kb_arpg_bestiary_resistances($resRows,$issues);
    // Other source RES/resource contributions require their own explicit inclusion proof.
    foreach (array_merge(array_values($definitions), $equipmentDefinitions, $talentDefinitions) as $definition) {
        foreach (['resists','resistances','resources'] as $field) if (!empty($definition['rules'][$field])) af_cs_snapshot_issue($issues,'resource_resistance_inclusion_unverified',$field);
    }
    $abilities = [];
    if (isset($rules['character_abilities']) && !is_array($rules['character_abilities'])) af_cs_snapshot_issue($issues,'ability_list_invalid','abilities');
    foreach (is_array($rules['character_abilities'] ?? null) ? $rules['character_abilities'] : [] as $index=>$ability) {
        if (!is_array($ability)) { af_cs_snapshot_issue($issues,'ability_invalid','abilities'); continue; }
        af_kb_arpg_bestiary_ability_pin($ability,'character_abilities.'.$index,$issues);
        $abilityDto = af_kb_arpg_normalize_entry($linked[0],(int)$index);
        $issues = array_merge($issues,$abilityDto['diagnostics']);
        $abilities[] = ['index'=>$index,'version'=>$abilityDto['definition_version'],'definition'=>$abilityDto['normalized']['ability_profile'] ?? null,
            'modifiers_in_character_total'=>false];
    }
    $classification = af_kb_arpg_element_damage($rules,'character',$issues);
    $captured = [];
    foreach (array_merge(array_values($definitions),$equipmentDefinitions,$talentDefinitions) as $sourceDefinition) {
        if (isset($sourceDefinition['rules']['magic_direction_ref'])) {
            $sourceClassification = af_kb_arpg_element_damage($sourceDefinition['rules'],$sourceDefinition['type'],$issues);
            af_cs_snapshot_capture_refs($sourceClassification,$readSet,$issues,$captured);
        }
    }
    af_cs_snapshot_capture_refs(['classification'=>$classification,'abilities'=>$abilities],$readSet,$issues,$captured);
    if (!empty($rules['resources'])) af_cs_snapshot_issue($issues,'resources_semantics_unverified','resources');
    if (array_filter($issues,static fn($i) => $i['code'] === 'definition_access_denied')) return ['schema_version'=>1,'contract_id'=>'IC-CS.combat-preparation.v1','ready'=>false,'diagnostics'=>[['code'=>'definition_access_denied','path'=>'references','severity'=>'error']]];
    $resources = ['hp'=>['maximum'=>$characteristics['hp'] ?? null, 'current'=>null],'source_declared'=>$rules['resources'] ?? []];
    af_cs_snapshot_issue($issues,'current_resources_not_owned_by_contract','resources','warning');
    // Re-read every captured source; no transaction/DDL and no persistent state changes.
    foreach ($readSet as $read) {
        $check = [];
        $rows = af_cs_snapshot_select($read['table'],$read['where'],$check);
        if (af_kb_arpg_acs_fingerprint($rows) !== $read['hash']) af_cs_snapshot_issue($issues,'source_changed_during_read','sources');
    }
    $result = ['schema_version'=>1,'contract_id'=>'IC-CS.combat-preparation.v1','builder_version'=>'cs-readonly-1',
        'numeric_policy'=>'binary64-half_up-v1','calculation_policy'=>'cs-arpg-legacy-additive-v1','engine_version'=>null,
        'element_registry_version'=>'primary-elements-17-v1','element_alias_policy'=>'wind-air-v1',
        'identity'=>['sheet_id'=>$sheetId,'owner_uid'=>$uid,'tid'=>$tid,'source_type'=>'character', 'kb_key'=>$linked[0]['key'],
            'source_fingerprint'=>$raw['fingerprint'],'definition_version'=>['schema'=>$rules['schema'],'revision'=>$linked[0]['revision'] ?? $rules['revision'] ?? null,'declared_version'=>$rules['version'] ?? null,'fingerprint'=>$raw['fingerprint']],'sheet_fingerprint'=>af_kb_arpg_acs_fingerprint($sheet)],
        'stat_provenance'=>['status_resistance'=>$status],'base_characteristics'=>$base,'characteristics'=>$characteristics,'resources'=>$resources,'resistances'=>$resistances,
        'classification'=>$classification,'abilities'=>$abilities,'equipment'=>$equipment,'talents'=>$talents,
        'sources'=>array_map(static fn($d) => array_diff_key($d,['entry'=>true]),array_merge(array_values($definitions),$equipmentDefinitions,$talentDefinitions)),'source_breakdown'=>$breakdown,'equipment_policy'=>'already_included_do_not_reapply',
        'read_set'=>$readSet,'diagnostics'=>$issues,'persistent'=>false,'executable'=>false];
    if (isset($context['expected_source_fingerprint']) && $context['expected_source_fingerprint'] !== $raw['fingerprint']) af_cs_snapshot_issue($result['diagnostics'],'source_version_mismatch','character');
    $result['ready'] = !array_filter($result['diagnostics'], static fn($i) => $i['severity'] === 'error');
    $result['fingerprint'] = af_kb_arpg_acs_fingerprint($result);
    return $result;
}

/** Capture/authorize only resolved references returned by KB; never scan a catalog. */
function af_cs_snapshot_capture_refs(array $value, array &$readSet, array &$issues, array &$seen): void
{
    global $db;
    if (isset($value['source_key'],$value['kind'],$value['fingerprint']) && in_array($value['kind'],['primary_element','magic_direction'],true)) {
        $key = (string)$value['source_key']; $identity = 'arpg_element:'.$key;
        if (!isset($seen[$identity])) {
            $seen[$identity] = true;
            $definition = af_cs_snapshot_definition('arpg_element',$key,$readSet,$issues);
            if ($definition) {
                $node = af_kb_arpg_element_node($definition['entry'],$issues);
                if ($node['fingerprint'] !== $value['fingerprint']) af_cs_snapshot_issue($issues,'source_changed_during_read','elements');
            }
        }
    } elseif (!empty($value['resolved']) && isset($value['entry_id'],$value['fingerprint'])) {
        $id = (int)$value['entry_id']; $identity = 'entry:'.$id;
        if (!isset($seen[$identity])) {
            $seen[$identity] = true;
            $rows = af_cs_snapshot_select('af_kb_entries','id='.$id,$readSet);
            if (count($rows) !== 1 || !af_kb_entry_visible_in_context($rows[0],'entry',af_kb_can_edit())) af_cs_snapshot_issue($issues,'definition_access_denied','references');
            else {
                $source = af_kb_arpg_acs_source($rows[0],$issues);
                if ($source['fingerprint'] !== $value['fingerprint']) af_cs_snapshot_issue($issues,'source_changed_during_read','references');
            }
        }
    }
    foreach ($value as $key=>$child) {
        if (is_array($child) && !in_array($key,['raw','rules','parameters','entry'],true)) af_cs_snapshot_capture_refs($child,$readSet,$issues,$seen);
    }
}

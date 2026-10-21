<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Validation only: uses the KB registry, never recalculates a CS total. */
final class AF_ACS_CapabilityValidator
{
    public const IDS = ['accuracy_registry','accuracy_roundtrip','canonical_elements_17','magic_direction_hierarchy',
        'legacy_element_aliases','explicit_damage_element','element_snapshot_provenance','status_resistance_mapping',
        'bestiary_snapshot','character_snapshot','equipment_source_breakdown','snapshot_read_only','definition_versioning'];

    public static function issue(array &$issues, string $code, string $path): void
    {
        $issues[] = ['code'=>$code,'path'=>$path,'severity'=>'error'];
    }

    public static function row(string $id, bool $ready, string $scope, array $issues = []): array
    {
        return ['capability'=>$id,'interface_status'=>'EXISTING','readiness'=>$ready ? 'READY' : 'BLOCKED',
            'scope'=>$scope,'diagnostics'=>$issues,'reason'=>$ready ? null : ($issues[0]['code'] ?? 'capability_validation_required')];
    }

    public static function number($value): bool
    {
        return !is_bool($value) && is_numeric($value) && is_finite((float)$value) && (float)$value >= 0;
    }

    public static function fingerprint($value): bool
    {
        return is_string($value) && preg_match('/\Asha256:[a-f0-9]{64}\z/D',$value) === 1;
    }

    /** Every stat has one canonical identity; missing and zero remain distinct. */
    public static function stats(array $stats, array &$issues): void
    {
        foreach (af_kb_arpg_character_stat_registry() as $key=>$entry) {
            $canonical = $entry['acs_key'] ?? $key;
            if (!array_key_exists($canonical,$stats)) self::issue($issues,'required_stat_missing','characteristics.'.$canonical);
            elseif (!self::number($stats[$canonical])) self::issue($issues,'invalid_stat_value','characteristics.'.$canonical);
        }
        if (array_key_exists('status_resist',$stats)) self::issue($issues,'status_resistance_alias_conflict','characteristics.status_resistance');
    }

    public static function resistances(array $values, array &$issues): void
    {
        $registry = af_kb_arpg_primary_elements();
        if (count($registry) !== 17) self::issue($issues,'element_kind_invalid','element_registry');
        foreach ($registry as $key=>$unused) {
            if (!array_key_exists($key,$values)) self::issue($issues,'required_resistance_missing','resists.'.$key);
            elseif (!is_array($values[$key]) || !self::number($values[$key]['value'] ?? null)) self::issue($issues,'element_resistance_value_invalid','resists.'.$key);
        }
        foreach ($values as $key=>$unused) {
            if (!is_string($key) || !isset($registry[$key])) self::issue($issues,$key === 'wind' ? 'element_resistance_alias_collision' : 'element_resistance_key_invalid','resists');
        }
    }

    public static function explicitDamage(array $value, array &$issues): void
    {
        if (($value['damage_type'] ?? null) === 'elemental' && !array_key_exists('damage_element',$value)) self::issue($issues,'damage_element_required','definition.damage_element');
        if (isset($value['source_key'],$value['kind']) && in_array($value['kind'],['primary_element','magic_direction'],true)
            && (!is_int($value['definition_version'] ?? null) || $value['definition_version'] < 1 || !self::fingerprint($value['fingerprint'] ?? null))) self::issue($issues,'element_version_invalid','references');
        foreach ($value as $key=>$child) if (is_array($child) && $key !== 'raw') self::explicitDamage($child,$issues);
    }

    /** Validate owner-issued policy/trace, not another arithmetic application. */
    public static function character(array $snapshot): array
    {
        $issues = $snapshot['diagnostics'] ?? [];
        $statsIssues = $resIssues = $equipmentIssues = $versionIssues = $readIssues = [];
        self::stats(is_array($snapshot['characteristics'] ?? null) ? $snapshot['characteristics'] : [],$statsIssues);
        self::resistances(is_array($snapshot['resistances'] ?? null) ? $snapshot['resistances'] : [],$resIssues);
        if (($snapshot['equipment_policy'] ?? null) !== 'already_included_do_not_reapply' || !is_array($snapshot['source_breakdown'] ?? null)
            || !is_array($snapshot['base_characteristics'] ?? null) || !is_array($snapshot['equipment'] ?? null)) self::issue($equipmentIssues,'source_breakdown_unavailable','equipment');
        $ids = [];
        foreach ((array)($snapshot['equipment'] ?? []) as $item) {
            $id = $item['item_id'] ?? null;
            if (!is_int($id) || $id <= 0 || isset($ids[$id]) || ($item['included_in_total'] ?? null) !== true
                || !self::fingerprint($item['instance_fingerprint'] ?? null) || !self::fingerprint($item['kb_ref']['version']['fingerprint'] ?? null)) {
                self::issue($equipmentIssues,'equipment_binding_invalid','equipment');
            } else $ids[$id] = true;
        }
        foreach ((array)($snapshot['source_breakdown'] ?? []) as $modifier) {
            if (!in_array($modifier['source_type'] ?? null,['equipment','talents'],true)) continue;
            if (!is_string($modifier['source_id'] ?? null) || !is_string($modifier['stat_key'] ?? null)
                || !self::fingerprint($modifier['source_version']['fingerprint'] ?? null) || !is_bool($modifier['included_in_total'] ?? null)) self::issue($equipmentIssues,'source_breakdown_unavailable','equipment.trace');
            if (($modifier['included_in_total'] ?? false) && (!is_numeric($modifier['value'] ?? null) || !is_finite((float)$modifier['value'])
                || !self::number($modifier['before'] ?? null) || !self::number($modifier['after'] ?? null))) self::issue($equipmentIssues,'source_breakdown_unavailable','equipment.trace');
            if (($modifier['source_type'] ?? null) === 'equipment' && (!preg_match('/\Ainventory:(\d+)\z/D',(string)($modifier['source_id'] ?? ''),$match)
                || !isset($ids[(int)$match[1]]))) self::issue($equipmentIssues,'equipment_binding_invalid','equipment.trace');
        }
        if (($snapshot['contract_id'] ?? null) !== 'IC-CS.combat-preparation.v1' || ($snapshot['schema_version'] ?? null) !== 1
            || ($snapshot['builder_version'] ?? null) !== 'cs-readonly-1' || ($snapshot['persistent'] ?? null) !== false
            || ($snapshot['executable'] ?? null) !== false || !is_array($snapshot['read_set'] ?? null)) self::issue($readIssues,'unsupported_character_contract','snapshot');
        if (!self::fingerprint($snapshot['fingerprint'] ?? null) || !self::fingerprint($snapshot['identity']['source_fingerprint'] ?? null)
            || ($snapshot['identity']['definition_version']['schema'] ?? null) !== AF_KB_CHARACTER_SCHEMA
            || ($snapshot['element_registry_version'] ?? null) !== 'primary-elements-17-v1' || ($snapshot['element_alias_policy'] ?? null) !== 'wind-air-v1') self::issue($versionIssues,'unsupported_character_contract','snapshot.version');
        if (isset($snapshot['fingerprint']) && $snapshot['fingerprint'] !== af_kb_arpg_acs_fingerprint(array_diff_key($snapshot,['fingerprint'=>true]))) self::issue($versionIssues,'source_changed_during_read','snapshot.fingerprint');
        if (empty($snapshot['read_set'])) self::issue($readIssues,'unsupported_character_contract','snapshot.read_set');
        foreach ((array)($snapshot['read_set'] ?? []) as $read) if (!self::fingerprint($read['hash'] ?? null)) self::issue($versionIssues,'source_changed_during_read','snapshot.read_set');
        foreach ($issues as $issue) {
            if (in_array($issue['code'],['source_breakdown_unavailable','base_equipment_inclusion_unknown','equipment_binding_invalid','equipment_instance_bonus_semantics_unknown','active_weapon_ambiguous','modifier_base_missing','conditional_modifier_requires_combat_adapter','modifier_unsupported'],true)) $equipmentIssues[] = $issue;
        }
        self::explicitDamage(['abilities'=>$snapshot['abilities'] ?? [],'classification'=>$snapshot['classification'] ?? []],$versionIssues);
        $all = array_merge($issues,$statsIssues,$resIssues,$equipmentIssues,$readIssues,$versionIssues);
        $error = (bool)array_filter($all,static fn($i) => ($i['severity'] ?? 'error') === 'error');
        $capabilities = [];
        foreach (['character_snapshot'=>($snapshot['ready'] ?? false) && !$error,'equipment_source_breakdown'=>!$equipmentIssues,
            'snapshot_read_only'=>!$readIssues,'definition_versioning'=>!$versionIssues,'element_snapshot_provenance'=>!$versionIssues && !$resIssues,
            'canonical_elements_17'=>!$resIssues,'legacy_element_aliases'=>!$resIssues,
            'explicit_damage_element'=>!$versionIssues,'magic_direction_hierarchy'=>!$versionIssues,
            'status_resistance_mapping'=>array_key_exists('status_resistance',$snapshot['characteristics'] ?? []) && !$statsIssues] as $id=>$ready) {
            $capabilities[$id] = self::row($id,$ready,'selected_character',$ready ? [] : $all);
        }
        return ['ready'=>($snapshot['ready'] ?? false) && !$error,'diagnostics'=>$all,'capabilities'=>$capabilities];
    }
}

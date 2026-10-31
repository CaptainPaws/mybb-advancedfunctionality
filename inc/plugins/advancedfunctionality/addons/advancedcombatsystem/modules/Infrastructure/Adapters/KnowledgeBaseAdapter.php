<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/CapabilityValidator.php';

/** Authenticated, owner-authorized KB reads. No JSON parser, migrations or writes. */
final class AF_ACS_KnowledgeBaseAdapter
{
    private function available(int $actorUid): bool
    {
        global $mybb;
        if ($actorUid <= 0 || $actorUid !== (int)($mybb->user['uid'] ?? 0) || !function_exists('af_is_addon_enabled')
            || !af_is_addon_enabled('advancedcombatsystem') || !af_is_addon_enabled('knowledgebase') || !empty($GLOBALS['af_acs_deactivated'])) return false;
        require_once AF_ADDONS.'knowledgebase/knowledgebase.php';
        return af_kb_can_view();
    }

    private function failure(string $code): array
    {
        return ['schema_version'=>1,'contract_id'=>'IC-KB.read.v1','ready'=>false,'executable'=>false,'persistent'=>false,
            'diagnostics'=>[['code'=>$code,'path'=>'source','severity'=>'error']]];
    }

    private function visible(array $entry, int $actorUid): bool
    {
        if (!af_kb_entry_visible_in_context($entry,'entry',af_kb_can_edit())) return false;
        if (($entry['type'] ?? null) === 'character' && !af_kb_can_edit()) {
            $issues = [];
            $rules = af_kb_arpg_acs_source($entry,$issues)['rules'];
            return !$issues && (int)($rules['character_meta']['availability']['owner_uid'] ?? 0) === $actorUid;
        }
        return true;
    }

    /** Check every owner-resolved nested reference before returning any data. */
    private function references(array $value, int $actorUid, array &$issues, array &$seen): bool
    {
        if (isset($value['source_key'],$value['kind'],$value['fingerprint']) && in_array($value['kind'],['primary_element','magic_direction'],true)) {
            $key = (string)$value['source_key']; $index = 'arpg_element:'.$key;
            if (!isset($seen[$index])) {
                $seen[$index] = true;
                $entry = af_kb_arpg_element_lookup($key);
                if (!$entry || !$this->visible($entry,$actorUid)) return false;
                $node = af_kb_arpg_element_node($entry,$issues);
                if ($node['fingerprint'] !== $value['fingerprint']) AF_ACS_CapabilityValidator::issue($issues,'source_changed_during_read','references');
                if (!is_int($node['definition_version']) || $node['definition_version'] < 1) AF_ACS_CapabilityValidator::issue($issues,'element_version_invalid','references');
            }
        } elseif (!empty($value['resolved']) && isset($value['entry_key'],$value['kind'],$value['fingerprint'])) {
            $type = in_array($value['kind'],['arpg_ability','arpg_item','arpg_bestiary'],true) ? $value['kind'] : 'arpg_mechanics';
            $index = $type.':'.$value['entry_key'];
            if (!isset($seen[$index])) {
                $seen[$index] = true;
                $entry = af_kb_arpg_acs_lookup($type,(string)$value['entry_key']);
                if (!$entry || !$this->visible($entry,$actorUid)) return false;
                $source = af_kb_arpg_acs_source($entry,$issues);
                if ($source['fingerprint'] !== $value['fingerprint']) AF_ACS_CapabilityValidator::issue($issues,'source_changed_during_read','references');
            }
        }
        foreach ($value as $key=>$child) {
            if (is_array($child) && !in_array($key,['raw','parameters','rules','entry'],true) && !$this->references($child,$actorUid,$issues,$seen)) return false;
        }
        return true;
    }

    public function readDefinition(string $type, string $key, int $actorUid, ?int $abilityIndex = null): array
    {
        try {
            if (!$this->available($actorUid)) return $this->failure('access_denied');
            if (!in_array($type,['character','arpg_bestiary','arpg_ability','arpg_element'],true)
                || !preg_match(AF_KB_KEY_PATTERN,$key)) return $this->failure('unsupported_entry_type');
            if ($abilityIndex !== null && $type !== 'character') return $this->failure('unexpected_ability_index');
            $sourceKey = $key;
            if ($type === 'arpg_element') $sourceKey = af_kb_arpg_read_element($key)['source_key'];
            $entry = af_kb_arpg_acs_lookup($type,$sourceKey);
            if (!$entry || empty($entry['active'])) return $this->failure('definition_not_found');
            if (!$this->visible($entry,$actorUid)) return $this->failure('definition_access_denied');
            $dto = af_kb_arpg_read_definition($type,$key,$abilityIndex);
            if ($type === 'arpg_element') {
                // Taxonomy owns a dedicated validator; the generic mechanics recognizer
                // does not recognize minimal legacy element definitions.
                $element = af_kb_arpg_read_element($key);
                $dto['normalized'] = ['element_definition'=>$element];
                $dto['diagnostics'] = $element['diagnostics'];
                $dto['definition_version']['schema'] = $element['schema'];
                $dto['definition_version']['revision'] = $element['definition']['definition_version'] ?? null;
                $dto['definition_version']['identity_fingerprint'] = $element['definition']['fingerprint'] ?? null;
            }
            if (!$this->visible($dto['raw']['entry'] ?? $entry,$actorUid)) return $this->failure('definition_access_denied');
            if ($type !== 'arpg_element') $dto['definition_version']['schema'] = $dto['normalized']['definition']['schema'] ?? null;
            $issues = $dto['diagnostics'];
            // A whole character definition is not an indexed ability or a CS snapshot.
            if ($type === 'character' && $abilityIndex === null) $issues = array_values(array_filter($issues,static fn($i) => $i['code'] !== 'character_ability_missing'));
            if (in_array($type,['arpg_bestiary','arpg_ability'],true)) {
                $validationErrors = [];
                $envelope = af_kb_arpg_envelope_defaults($type);
                $envelope['rules'] = $dto['normalized']['definition'];
                af_kb_validate_arpg_entry_by_type($type,$envelope,$validationErrors); // Validate a copy; never replace/read-repair the source.
                if ($validationErrors) AF_ACS_CapabilityValidator::issue($issues,'unsupported_rules_schema','definition.validation');
            }
            $source = af_kb_arpg_acs_source($entry,$issues);
            if ($source['fingerprint'] !== ($dto['definition_version']['fingerprint'] ?? null)) AF_ACS_CapabilityValidator::issue($issues,'source_changed_during_read','definition');
            if (!AF_ACS_CapabilityValidator::fingerprint($dto['definition_version']['fingerprint'] ?? null)) AF_ACS_CapabilityValidator::issue($issues,'source_changed_during_read','definition.version');
            if ($type === 'arpg_bestiary') {
                AF_ACS_CapabilityValidator::stats((array)($dto['normalized']['combat_stats'] ?? []),$issues);
                AF_ACS_CapabilityValidator::resistances((array)($dto['normalized']['element_resistances'] ?? []),$issues);
            }
            if ($type === 'character') {
                $rules = $dto['normalized']['definition'];
                $normalizedStats = [];
                foreach (af_kb_arpg_character_stat_registry() as $stat=>$definition) {
                    if (array_key_exists($definition['vm_field'],(array)($rules['character_stats'] ?? []))) $normalizedStats[$definition['acs_key'] ?? $stat] = $rules['character_stats'][$definition['vm_field']];
                }
                $status = af_kb_arpg_status_resistance((array)($rules['character_stats'] ?? []),'character_stats',$issues,'character_status_resistance','character_status_resist');
                unset($normalizedStats['status_resistance']); // A conflict must not leave a chosen alias in the DTO.
                if ($status['available']) $normalizedStats['status_resistance'] = $status['value'];
                $dto['normalized']['definition_stats'] = $normalizedStats;
                AF_ACS_CapabilityValidator::stats($normalizedStats,$issues);
                $dto['normalized']['element_resistances'] = af_kb_arpg_bestiary_resistances($dto['normalized']['resistances'] ?? [],$issues);
                $dto['normalized']['classification'] = af_kb_arpg_element_damage($rules,'character',$issues);
                if ($abilityIndex === null) {
                    $dto['normalized']['ability_profiles'] = [];
                    if (!is_array($rules['character_abilities'] ?? [])) AF_ACS_CapabilityValidator::issue($issues,'expected_array','character_abilities');
                    foreach (is_array($rules['character_abilities'] ?? null) ? $rules['character_abilities'] : [] as $index=>$ability) {
                        if (!is_array($ability)) { AF_ACS_CapabilityValidator::issue($issues,'expected_ability_object','character_abilities'); continue; }
                        af_kb_arpg_bestiary_ability_pin($ability,'character_abilities.'.$index,$issues);
                        $dto['normalized']['ability_profiles'][] = af_kb_arpg_acs_ability($ability,'character_abilities.'.$index,$issues);
                    }
                }
            }
            $seen = [];
            if (!$this->references($dto['normalized'],$actorUid,$issues,$seen)) return $this->failure('definition_access_denied');
            AF_ACS_CapabilityValidator::explicitDamage($dto['normalized'],$issues);
            if ($type !== 'character' && $type !== 'arpg_element' && ($dto['normalized']['definition']['schema'] ?? null) !== AF_KB_ARPG_RULES_SCHEMA) AF_ACS_CapabilityValidator::issue($issues,'unsupported_rules_schema','definition.schema');
            $ready = !array_filter($issues,static fn($i) => $i['severity'] === 'error');
            $cap = $type === 'arpg_bestiary' ? 'bestiary_snapshot' : ($type === 'arpg_element' ? 'magic_direction_hierarchy' : 'definition_versioning');
            $capabilities = [];
            foreach (array_unique(array_merge([$cap,'definition_versioning','element_snapshot_provenance','magic_direction_hierarchy'],$type === 'arpg_element' ? [] : ['explicit_damage_element'],$type === 'arpg_bestiary' ? ['canonical_elements_17','legacy_element_aliases','status_resistance_mapping'] : [])) as $id) $capabilities[$id] = AF_ACS_CapabilityValidator::row($id,$ready,'selected_definition',$issues);
            return ['schema_version'=>1,'contract_id'=>'IC-KB.read.v1','adapter_version'=>'acs-kb-adapter-1',
                'ready'=>$ready,'readiness_scope'=>$type === 'character' ? 'definition_inputs_not_calculated_snapshot' : 'selected_definition','executable'=>false,'persistent'=>false,'source'=>$dto['source'],'definition_version'=>$dto['definition_version'],
                'data'=>$dto['normalized'],'diagnostics'=>$issues,'capabilities'=>$capabilities];
        } catch (Throwable $error) { return $this->failure('source_read_failed'); }
    }

    /** Explicit owner-authorized acquisition of an exact formula; evaluation uses this retained copy only. */
    public function readFormula(array $pin, int $actorUid, string $expectedUnit): array
    {
        try {
            if (!$this->available($actorUid)) return $this->failure('access_denied');
            if (!is_string($pin['key'] ?? null) || !preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/D',$pin['key'])) return $this->failure('invalid_formula_contract');
            // Inspect both potential owner records before the KB resolver can return their contents.
            foreach (array_unique([$pin['key'],'formula_def']) as $entryKey) {
                $candidate=af_kb_arpg_acs_lookup('arpg_mechanics',$entryKey);
                if ($candidate && !$this->visible($candidate,$actorUid)) return $this->failure('definition_access_denied');
            }
            $issues=[];
            $reference=af_kb_arpg_acs_reference('formula_def',$pin['key'],'formula',$issues);
            if (empty($reference['resolved']) || !isset($reference['formula']) || array_filter($issues,static fn($i)=>$i['severity']==='error')) {
                $code=$issues[0]['code'] ?? 'reference_unresolved';
                return $this->failure($code);
            }
            $entry=af_kb_arpg_acs_lookup('arpg_mechanics',$reference['entry_key']);
            if (!$entry || !$this->visible($entry,$actorUid)) return $this->failure('definition_access_denied');
            if (empty($entry['active'])) return $this->failure('inactive_definition');
            $source=af_kb_arpg_acs_source($entry,$issues);
            if ($issues || (string)($entry['id'] ?? '')!==(string)$reference['entry_id'] || $source['fingerprint']!==$reference['fingerprint']) return $this->failure('source_changed_during_read');
            if (array_key_exists('schema',$source['rules']) && $source['rules']['schema']!==AF_KB_ARPG_RULES_SCHEMA) return $this->failure('unsupported_rules_schema');
            require_once __DIR__.'/../../Domain/Formula.php';
            AF_ACS_Formula::compile($reference['formula'],$pin,$expectedUnit); // Structural diagnostics only, never execute AST on read.
            return ['schema_version'=>1,'contract_id'=>'IC-KB.read.v1','ready'=>true,'executable'=>false,'persistent'=>false,
                'source'=>['type'=>'arpg_mechanics','key'=>$reference['entry_key'],'id'=>$reference['entry_id']],
                'definition_version'=>$reference['definition_version'],'data'=>['formula'=>$reference['formula']],
                'compiler_version'=>AF_ACS_Formula::VERSION,'diagnostics'=>[]];
        } catch (AF_ACS_FormulaError $error) { return $this->failure($error->diagnosticCode); }
        catch (Throwable $error) { return $this->failure('source_read_failed'); }
    }

    /** Fixed-size primary catalog only; never enumerate all characters/directions. */
    public function primaryElements(int $actorUid): array
    {
        try {
            if (!$this->available($actorUid)) return $this->failure('access_denied');
            $catalog = af_kb_arpg_list_primary_elements(); $issues = []; $seen = [];
            if (count($catalog) !== 17 || array_keys($catalog) !== array_keys(af_kb_arpg_primary_elements())) AF_ACS_CapabilityValidator::issue($issues,'element_kind_invalid','element_registry');
            foreach ($catalog as $element) {
                $issues = array_merge($issues,$element['diagnostics']);
                if (!$this->references($element,$actorUid,$issues,$seen)) return $this->failure('definition_access_denied');
            }
            return ['schema_version'=>1,'contract_id'=>'IC-KB-2','ready'=>!array_filter($issues,static fn($i) => $i['severity'] === 'error'),
                'data'=>$catalog,'diagnostics'=>$issues,'persistent'=>false,'executable'=>false];
        } catch (Throwable $error) { return $this->failure('source_read_failed'); }
    }
}

<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Application/AuthorizationService.php';
require_once __DIR__.'/../Infrastructure/Adapters/KnowledgeBaseAdapter.php';
require_once __DIR__.'/../Infrastructure/Adapters/CharacterSheetAdapter.php';

/** Explicit ACP probes: memory contracts, a fixed primary catalog, optional ONE source. */
final class AF_ACS_IntegrationCapabilities
{
    private function combine(array $base, array $probe): array
    {
        if ($base['scope'] === 'not_checked') return $probe;
        $base['scope'] .= ' + '.$probe['scope'];
        $base['diagnostics'] = array_merge($base['diagnostics'],$probe['diagnostics']);
        if ($probe['readiness'] === 'BLOCKED' && $base['readiness'] !== 'BLOCKED') { $base['readiness'] = 'BLOCKED'; $base['reason'] = $probe['reason']; }
        return $base;
    }

    public function inspect(array $targets = []): array
    {
        (new AF_ACS_AuthorizationService)->requirePermission('admin.integrations.view');
        global $mybb;
        $rows=[];
        foreach(array_merge(AF_ACS_CapabilityValidator::IDS,['kb.definition_reader','kb.combat_snapshot','cs.combat_snapshot']) as $id)$rows[$id]=AF_ACS_CapabilityValidator::notChecked($id);
        $kbEnabled=function_exists('af_is_addon_enabled')&&af_is_addon_enabled('knowledgebase');
        $csEnabled=function_exists('af_is_addon_enabled')&&af_is_addon_enabled('charactersheets');
        $memoryIds=['accuracy_registry','accuracy_roundtrip','legacy_element_aliases','status_resistance_mapping','definition_versioning','explicit_damage_element','magic_direction_hierarchy','element_snapshot_provenance'];
        if($kbEnabled){
            try{
                require_once AF_ADDONS.'knowledgebase/knowledgebase.php';
                foreach($this->memoryProofs() as $id=>$ready)$rows[$id]=AF_ACS_CapabilityValidator::row($id,$ready,'kb_memory_contract',$ready?[]:[['code'=>'unsupported_rules_schema','path'=>'contract_probe','severity'=>'error']]);
            }catch(Throwable $error){foreach($memoryIds as $id)$rows[$id]=AF_ACS_CapabilityValidator::row($id,false,'kb_memory_contract',[['code'=>'contract_probe_unavailable','path'=>'contract_probe','severity'=>'error']]);}
            // The fixed 17-element registry is separate from memory proofs and selected sources.
            try{
                $catalog=(new AF_ACS_KnowledgeBaseAdapter)->primaryElements((int)$mybb->user['uid']);
                $rows['canonical_elements_17']=AF_ACS_CapabilityValidator::row('canonical_elements_17',$catalog['ready'],'primary_catalog',$catalog['diagnostics']);
                if(in_array('element_version_invalid',array_column($catalog['diagnostics'],'code'),true))$rows['canonical_elements_17']['reason']='element_version_invalid';
                if(in_array('element_alias_collision',array_column($catalog['diagnostics'],'code'),true))$rows['legacy_element_aliases']=$this->combine($rows['legacy_element_aliases'],AF_ACS_CapabilityValidator::row('legacy_element_aliases',false,'primary_catalog',$catalog['diagnostics']));
            }catch(Throwable $error){$rows['canonical_elements_17']=AF_ACS_CapabilityValidator::row('canonical_elements_17',false,'primary_catalog',[['code'=>'source_read_failed','path'=>'element_registry','severity'=>'error']]);}
        }else{
            foreach([...$memoryIds,'canonical_elements_17'] as $id)$rows[$id]=AF_ACS_CapabilityValidator::row($id,false,'dependency',[['code'=>'OWNER_DISABLED','path'=>'knowledgebase','severity'=>'error']]);
        }
        $sheet=trim((string)($targets['sheet_id']??''));
        if($sheet!==''){
            if(!preg_match('/\A[1-9][0-9]{0,9}\z/D',$sheet)||(float)$sheet>2147483647){
                $snapshot=['ready'=>false,'diagnostics'=>[['code'=>'invalid_source_id','path'=>'sheet_id','severity'=>'error']]];
            }elseif(!$csEnabled||!$kbEnabled){
                $snapshot=['ready'=>false,'diagnostics'=>[['code'=>'OWNER_DISABLED','path'=>!$csEnabled?'charactersheets':'knowledgebase','severity'=>'error']]];
            }else{
                // Adapter catches failures and preserves the owner's minimal access-denied response.
                $snapshot=(new AF_ACS_CharacterSheetAdapter)->createSnapshot((int)$sheet,(int)$mybb->user['uid']);
            }
            $rows['cs.combat_snapshot']=AF_ACS_CapabilityValidator::row('cs.combat_snapshot',!empty($snapshot['ready']),'selected_character',$snapshot['diagnostics']??[]);
            foreach($snapshot['capabilities']??[] as $id=>$row)$rows[$id]=$this->combine($rows[$id],$row);
            foreach(['character_snapshot','equipment_source_breakdown','snapshot_read_only'] as $id)if(!isset($snapshot['capabilities'][$id]))$rows[$id]=AF_ACS_CapabilityValidator::row($id,false,'selected_character',$snapshot['diagnostics']??[]);
        }
        $key=trim((string)($targets['kb_key']??''));
        if($key!==''){
            $definition=$kbEnabled?(new AF_ACS_KnowledgeBaseAdapter)->readDefinition((string)($targets['kb_type']??''),$key,(int)$mybb->user['uid']):['ready'=>false,'diagnostics'=>[['code'=>'OWNER_DISABLED','path'=>'knowledgebase','severity'=>'error']]];
            foreach(['kb.definition_reader','kb.combat_snapshot'] as $id)$rows[$id]=AF_ACS_CapabilityValidator::row($id,!empty($definition['ready']),'selected_definition_read_only',$definition['diagnostics']??[]);
            foreach($definition['capabilities']??[] as $id=>$row)$rows[$id]=$this->combine($rows[$id],$row);
            if(($targets['kb_type']??'')==='arpg_bestiary')$rows['bestiary_snapshot']=AF_ACS_CapabilityValidator::row('bestiary_snapshot',!empty($definition['ready']),'selected_definition',$definition['diagnostics']??[]);
        }
        return $rows; // No definitions, snapshots, source JSON or receipt payloads in ACP output.
    }

    /** Real validation/normalization, no DB save or source mutation. */
    private function memoryProofs(): array
    {
        $registry = af_kb_arpg_character_stat_registry();
        $proof = ['accuracy_registry'=>($registry['accuracy']['vm_field'] ?? null) === 'character_accuracy',
            'legacy_element_aliases'=>af_kb_arpg_canonical_element_key('wind') === 'air' && af_kb_arpg_canonical_element_key('air') === 'air'];
        $issues = []; $conflictIssues = [];
        $zero = af_kb_arpg_status_resistance(['status_resist'=>0],'probe',$issues);
        $conflict = af_kb_arpg_status_resistance(['status_resist'=>0,'status_resistance'=>1],'probe',$conflictIssues);
        $proof['status_resistance_mapping'] = $zero['available'] && $zero['value'] === 0 && !$issues && !$conflict['available']
            && !isset($registry['status_resistance']) && ($registry['status_resist']['acs_key'] ?? null) === 'status_resistance'
            && in_array('status_resistance_alias_conflict',array_column($conflictIssues,'code'),true);
        $rules = af_kb_default_type_profile_payload_arpg('arpg_bestiary');
        $rules['family'] = $rules['archetype'] = $rules['faction'] = 'contract_probe';
        $rules['combat_stats']['accuracy'] = 0; $rules['combat_stats']['status_resist'] = 0;
        foreach (array_keys(af_kb_arpg_primary_elements()) as $key) $rules['resists'][$key] = ['scope'=>'elemental','unit'=>'RES','value'=>0];
        $envelope = af_kb_arpg_envelope_defaults('arpg_bestiary'); $envelope['rules'] = $rules;
        $errors = [];
        $saved = af_kb_validate_rules_json_by_type_arpg('arpg_bestiary',json_encode($envelope,JSON_THROW_ON_ERROR),$errors);
        $dto = af_kb_arpg_normalize_entry(['type'=>'arpg_bestiary','key'=>'contract_probe','data_json'=>$saved]);
        $missing = $envelope; unset($missing['rules']['combat_stats']['accuracy']);
        $missingErrors = [];
        $missingSaved = af_kb_validate_rules_json_by_type_arpg('arpg_bestiary',json_encode($missing,JSON_THROW_ON_ERROR),$missingErrors);
        $missingDto = af_kb_arpg_normalize_entry(['type'=>'arpg_bestiary','key'=>'contract_probe','data_json'=>$missingSaved]);
        $proof['accuracy_roundtrip'] = !$errors && !$missingErrors && $dto['supported'] && ($dto['normalized']['combat_stats']['accuracy'] ?? null) === 0
            && !array_key_exists('accuracy',$missingDto['normalized']['combat_stats']) && in_array('required_stat_missing',array_column($missingDto['diagnostics'],'code'),true);
        $proof['definition_versioning'] = AF_ACS_CapabilityValidator::fingerprint($dto['definition_version']['fingerprint']) && $dto['definition_version']['declared_version'] !== null
            && $dto === af_kb_arpg_normalize_entry(['type'=>'arpg_bestiary','key'=>'contract_probe','data_json'=>$saved]);
        $damageIssues = []; $missingDamage = [];
        $explicit = af_kb_arpg_element_damage(['damage_type'=>'elemental','damage_element'=>'wind'],'probe',$damageIssues);
        af_kb_arpg_element_damage(['damage_type'=>'elemental'],'probe',$missingDamage);
        $physical = af_kb_arpg_element_damage(['damage_type'=>'physical'],'probe',$damageIssues);
        $proof['explicit_damage_element'] = !$damageIssues && $explicit['element'] === 'air' && $physical['element'] === null
            && in_array('damage_element_required',array_column($missingDamage,'code'),true);
        $root = ['type'=>'arpg_element','key'=>'life','active'=>1,'data_json'=>json_encode(['entity_kind'=>'primary_element','element_revision'=>1,'root_element'=>'life'])];
        $nodeIssues = []; $rootNode = af_kb_arpg_element_node($root,$nodeIssues);
        $parent = array_intersect_key($rootNode,array_flip(['key','kind','definition_version','fingerprint']));
        $branch = ['type'=>'arpg_element','key'=>'contract_branch','active'=>1,'data_json'=>json_encode(['entity_kind'=>'magic_direction','element_revision'=>1,'root_element'=>'life','parent_ref'=>$parent])];
        $hierarchy = af_kb_arpg_read_element('contract_branch',static fn($key) => ['life'=>$root,'contract_branch'=>$branch][$key] ?? null);
        $cycle = $branch; $cycle['data_json'] = json_encode(['entity_kind'=>'magic_direction','element_revision'=>1,'parent_ref'=>['key'=>'contract_branch']]);
        $bad = af_kb_arpg_read_element('contract_branch',static fn($key) => $cycle);
        $orphan = af_kb_arpg_read_element('contract_branch',static fn($key) => $key === 'contract_branch' ? $branch : null);
        $proof['magic_direction_hierarchy'] = $hierarchy['supported'] && $hierarchy['root_element'] === 'life'
            && in_array('element_cycle',array_column($bad['diagnostics'],'code'),true) && in_array('element_parent_missing',array_column($orphan['diagnostics'],'code'),true);
        $proof['element_snapshot_provenance'] = $proof['magic_direction_hierarchy'] && count($hierarchy['provenance']) === 2
            && AF_ACS_CapabilityValidator::fingerprint($hierarchy['definition']['fingerprint']) && $hierarchy['definition']['definition_version'] === 1;
        return $proof;
    }
}

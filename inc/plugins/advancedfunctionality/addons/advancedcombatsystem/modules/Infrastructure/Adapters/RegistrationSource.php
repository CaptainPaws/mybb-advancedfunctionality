<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/CharacterSheetAdapter.php';
require_once __DIR__.'/KnowledgeBaseAdapter.php';
require_once __DIR__.'/PermanentPetSource.php';

/** Explicit preparation only; never entered by showthread or saved-entity reads. */
class AF_ACS_RegistrationSource
{
    public function __construct(private ?AF_ACS_PermanentPetSource $pets=null) {}
    public function read(string $kind,string $key,int $actor): array
    {
        if ($kind==='pet') {
            if(!$this->pets)return $this->blocked('permanent_pet_source_unavailable');
            if(!af_is_addon_enabled('knowledgebase'))return $this->blocked('dependency_unavailable');
            require_once AF_ADDONS.'knowledgebase/knowledgebase.php';
            $dto=$this->pets->read($key,$actor);
            if(($dto['schema_version']??null)!==1 || ($dto['contract_id']??null)!=='IC-ACS.permanent-pet-preparation.v1'
                || ($dto['persistent']??null)!==false || ($dto['executable']??null)!==false
                || !AF_ACS_CapabilityValidator::fingerprint($dto['fingerprint']??null)
                || $dto['fingerprint']!==af_kb_arpg_acs_fingerprint(array_diff_key($dto,['fingerprint'=>true]))
                || !AF_ACS_CapabilityValidator::fingerprint($dto['identity']['definition_version']['fingerprint']??null))return $this->blocked('unsupported_pet_contract');
            if(($dto['identity']['source_type']??null)!=='permanent_pet' || ($dto['identity']['owner_uid']??null)!==$actor
                || ($dto['identity']['source_key']??null)!==$key)return $this->blocked('registration_ownership_required');
            return $dto;
        }
        if ($kind==='character') {
            $dto=(new AF_ACS_CharacterSheetAdapter)->createSnapshot((int)AF_ACS_Repository::integer($key,'2147483647'),$actor);
            if (($dto['identity']['owner_uid']??null)!==$actor) return $this->blocked('registration_ownership_required');
            return $dto;
        }
        $dto=(new AF_ACS_KnowledgeBaseAdapter)->readDefinition('arpg_bestiary',$key,$actor);
        if(empty($dto['ready']))return $dto;
        $rules=$dto['data']['definition'];
        // KB validates definitions but does not promise these modifiers are calculated totals.
        foreach(['modifiers','math_modifiers','equipment','base_stats','permanent_modifiers'] as $field) {
            if(!empty($rules[$field]))return $this->blocked('bestiary_modifier_provenance_unavailable');
        }
        $stats=$dto['data']['combat_stats'];
        $dto['characteristics']=$stats;
        $dto['resistances']=$dto['data']['element_resistances'];
        $dto['resources']=['hp'=>['maximum'=>$stats['hp']??null,'current'=>null]];
        if(!empty($rules['resources']))return $this->blocked('resource_semantics_unverified');
        $dto['equipment_policy']='already_included_do_not_reapply';
        $dto['source_breakdown']=[['source_type'=>'bestiary_template','source_version'=>$dto['definition_version'],'included_in_total'=>true]];
        return $dto;
    }
    private function blocked(string $code): array
    {
        return ['ready'=>false,'diagnostics'=>[['code'=>$code,'path'=>'source','severity'=>'error']]];
    }
    /** Capture referenced abilities, not merely a key pointing at tomorrow's definition. */
    public function definitions(array $snapshot,int $actor): array
    {
        $definitions=[];$queue=[$snapshot];$seen=[];
        while($queue) {
            $value=array_pop($queue);
            if(!empty($value['resolved']) && ($value['kind']??'')==='arpg_ability') {
                $key=(string)$value['entry_key'];$hash=(string)$value['fingerprint'];
                if(isset($seen[$key]) && $seen[$key]!==$hash)throw new RuntimeException('ACS_DEFINITION_VERSION_CONFLICT');
                if(!isset($seen[$key])) {
                    if(count($seen)>=128)throw new RuntimeException('ACS_DEFINITION_LIMIT');
                    $seen[$key]=$hash;
                    $dto=(new AF_ACS_KnowledgeBaseAdapter)->readDefinition('arpg_ability',$key,$actor);
                    if(empty($dto['ready']) || ($dto['definition_version']['fingerprint']??null)!==$hash)throw new RuntimeException('ACS_DEFINITION_VERSION_CONFLICT');
                    $definitions[$key]=$dto;$queue[]=$dto;
                }
            }
            foreach($value as $child)if(is_array($child))$queue[]=$child;
        }
        return $definitions;
    }
    /** Paginated candidates; selecting one still runs owner API authorization/validation. */
    public function choices(string $kind,int $actor,int $after=0): array
    {
        global $db;
        if($kind==='pet')return $this->pets?$this->pets->choices($actor,$after):['items'=>[],'next_after'=>null];
        if($kind==='character') {
            if(!af_is_addon_enabled('charactersheets') || !$db->table_exists('af_cs_sheets'))return [];
        } else {
            if(!af_is_addon_enabled('knowledgebase'))return [];
            require_once AF_ADDONS.'knowledgebase/knowledgebase.php';
            if(!af_kb_can_view() || !$db->table_exists('af_kb_entries'))return [];
        }
        $table=$kind==='character'?'af_cs_sheets':'af_kb_entries';
        $where=$kind==='character'?'uid='.$actor:'type=\'arpg_bestiary\' AND active=1';
        $query=$db->simple_select($table,'*',$where.' AND id>'.max(0,$after),['order_by'=>'id','order_dir'=>'ASC','limit'=>50]);$result=[];$cursor=null;
        while($row=$db->fetch_array($query)) {
            $cursor=(int)$row['id'];
            if($kind==='character')$result[]=['key'=>(string)$row['id'],'label'=>'Лист #'.$row['id'].' · тема #'.$row['tid']];
            elseif(af_kb_entry_visible_in_context($row,'entry',af_kb_can_edit()))$result[]=['key'=>$row['key'],'label'=>af_kb_pick_text($row,'title')?:$row['key']];
        }
        return ['items'=>$result,'next_after'=>$cursor];
    }
}

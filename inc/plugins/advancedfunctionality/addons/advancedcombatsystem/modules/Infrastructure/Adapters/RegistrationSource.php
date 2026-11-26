<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/CharacterSheetAdapter.php';
require_once __DIR__.'/CharacterRegistrationGrant.php';
require_once __DIR__.'/KnowledgeBaseAdapter.php';
require_once __DIR__.'/PermanentPetSource.php';

/** Explicit preparation only; never entered by showthread or saved-entity reads. */
class AF_ACS_RegistrationSource
{
    public function __construct(private ?AF_ACS_PermanentPetSource $pets=null) {}
    public function read(string $kind,string $key,int $actor,?AF_ACS_CharacterRegistrationGrant $grant=null,?callable $onError=null): array
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
            $sheetId=(int)AF_ACS_Repository::integer($key,'2147483647');
            if ($grant) {
                if ($grant->sheetId!==$sheetId) return $this->blocked('registration_ownership_required');
                $grant->assertRead($grant->ownerUid,$actor);
            } else {
                $sheet=$this->ownedSheet('id='.$sheetId,$actor);
                if (!$sheet) return $this->blocked('registration_ownership_required');
                if (!$this->sheetMatchesSlug($sheet)) return $this->blocked('sheet_topic_mismatch');
            }
            $dto=(new AF_ACS_CharacterSheetAdapter)->createSnapshot($sheetId,$actor,$onError===null?[]:['on_source_error'=>$onError],$grant);
            if (isset($dto['identity']) && (($dto['identity']['owner_uid']??null)!==($grant?->ownerUid??$actor)
                || ($grant && ($dto['identity']['tid']??null)!==$grant->sourceTid))) return $this->blocked('registration_ownership_required');
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
    public function definitions(array $snapshot,int $actor,bool $registrationDiagnostics=false): array
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
                    $adapter=new AF_ACS_KnowledgeBaseAdapter();
                    $dto=$adapter->readDefinition('arpg_ability',$key,$actor,reportUnexpected:$registrationDiagnostics);
                    $actual=$dto['definition_version']['fingerprint']??null;
                    if ($actual!==null && $actual!==$hash) throw new RuntimeException('ACS_DEFINITION_VERSION_CONFLICT');
                    if (empty($dto['ready']) && $registrationDiagnostics) throw new AF_ACS_RegistrationBlocked($dto['diagnostics']);
                    if (empty($dto['ready']) || $actual!==$hash) throw new RuntimeException('ACS_DEFINITION_VERSION_CONFLICT');
                    $dto=$adapter->pinSpawnTemplates($dto,$actor);
                    $definitions[$key]=$dto;$queue[]=$dto;
                }
            }
            foreach($value as $child)if(is_array($child))$queue[]=$child;
        }
        return $definitions;
    }
    public function characterGrant(string $sid,string $key,int $actor): ?AF_ACS_CharacterRegistrationGrant
    {
        global $db;
        AF_ACS_CharacterRegistrationGrant::assertGm($sid,$actor);
        $sheetId=(int)AF_ACS_Repository::integer($key,'2147483647');
        if (!af_is_addon_enabled('charactersheets') || !af_is_addon_enabled('knowledgebase')
            || !$db->table_exists('af_cs_sheets') || !$db->table_exists('af_kb_entries')) {
            throw new RuntimeException('ACS_DEPENDENCY_UNAVAILABLE');
        }
        $sheet=$db->fetch_array($db->simple_select('af_cs_sheets','*','id='.$sheetId,['limit'=>1]));
        if (!$sheet) throw new RuntimeException('ACS_CHARACTER_SOURCE_UNAVAILABLE');
        if ((int)$sheet['uid']===$actor) return null;
        $this->loadCharacterSources();
        return AF_ACS_CharacterRegistrationGrant::forSheet($sid,$actor,$sheet);
    }

    private function loadCharacterSources(): void
    {
        require_once AF_ADDONS.'charactersheets/charactersheets.php';
        af_charactersheets_require_modules(['combat_snapshot','frontend']);
        require_once AF_ADDONS.'knowledgebase/knowledgebase.php';
    }

    private function ownedSheet(string $predicate,int $actor): array
    {
        global $db,$mybb;
        if ($actor<=0 || $actor!==(int)($mybb->user['uid']??0)
            || !af_is_addon_enabled('charactersheets') || !$db->table_exists('af_cs_sheets')) return [];
        return (array)$db->fetch_array($db->simple_select('af_cs_sheets','*','uid='.$actor.' AND '.$predicate,['limit'=>1]));
    }

    private function sheetMatchesSlug(array $sheet): bool
    {
        return !preg_match('/^thread-(\d+)$/D',(string)$sheet['slug'],$match) || (int)$match[1]===(int)$sheet['tid'];
    }

    private function characterChoice(array $sheet,int $actor,?string $gmSession=null): array
    {
        $this->loadCharacterSources();
        $owner=(int)$sheet['uid'];
        $choice=['key'=>(string)$sheet['id'],'label'=>'Лист '.(string)$sheet['slug'],'sheet_id'=>(int)$sheet['id']];
        if ($gmSession!==null && $owner!==$actor) {
            try {
                $thread=(new AF_ACS_SessionAccess())->thread((string)$sheet['tid']);
            } catch (RuntimeException) {
                return [];
            }
            if ((int)$thread['visible']!==1 || !af_kb_can_view()) return [];
        } else {
            $thread=af_cs_snapshot_source_thread($sheet,$actor,$reason);
            if (!$thread) return $gmSession!==null?[]:$choice+['state'=>$reason];
        }
        $tid=(int)$sheet['tid'];
        $choice['label']=(string)$thread['subject'];
        $choice['thread_id']=$tid;
        $crossOwner=$gmSession!==null && $owner!==$actor;
        if (!$crossOwner) $choice['sheet_url']=af_charactersheets_url(['slug'=>$sheet['slug']]);
        if ($owner===$actor && (int)$thread['firstpost']>0) $choice['application_url']='editpost.php?pid='.(int)$thread['firstpost'];
        $accept=af_charactersheets_get_accept_row($tid);
        $entryId=(int)($accept['kb_entry_id']??0);
        global $db;
        $entry=$entryId>0 ? $db->fetch_array($db->simple_select('af_kb_entries','*',"id=".$entryId." AND type='character' AND active=1",['limit'=>1])) : [];
        if ($entry && !af_kb_entry_visible_in_context($entry,'entry',af_kb_can_edit())) {
            return $crossOwner?[]:$choice+['state'=>'definition_access_denied'];
        }
        if (!$this->sheetMatchesSlug($sheet)) {
            unset($choice['sheet_url']);
            return $choice+['state'=>'sheet_topic_mismatch'];
        }
        if ($crossOwner && (empty($accept['accepted']) || (int)$accept['uid']!==$owner
            || (string)$accept['sheet_slug']!==(string)$sheet['slug'])) return $choice+['state'=>'character_accept_link_invalid'];
        if ($entryId<=0) return $choice+['state'=>'explicit_character_link_missing'];
        if (!$entry) return $choice+['state'=>'character_definition_missing'];
        $issues=[];
        $payload=af_kb_arpg_acs_source($entry,$issues)['rules'];
        if (array_filter($issues,static fn($issue)=>($issue['severity']??'error')==='error')) {
            return $choice+['state'=>'character_rules_invalid','diagnostics'=>AF_ACS_CapabilityValidator::safeDiagnostics($issues)];
        }
        $meta=$payload['character_meta']??[];
        if ((int)($meta['source_tid']??0)!==$tid || (int)($meta['availability']['owner_uid']??0)!==$owner) {
            return $choice+['state'=>'character_owner_link_mismatch'];
        }
        $profile=$payload['character_profile']??[];
        $choice['label']=trim((string)($profile['character_name_ru']??'')) ?: (trim((string)($profile['character_name']??'')) ?: (af_kb_pick_text($entry,'title') ?: $choice['label']));
        $choice['portrait']=is_string($profile['character_pic']??null)?trim($profile['character_pic']):'';
        $choice['kb_url']=af_kb_url(['type'=>'character','key'=>$entry['key']]);
        $choice['owner_uid']=$owner;
        $choice['owner_name']=(string)(get_user($owner)['username']??'');
        if ($crossOwner) {
            try {
                AF_ACS_CharacterRegistrationGrant::forSheet($gmSession,$actor,$sheet);
            } catch (RuntimeException $error) {
                return $choice+['state'=>strtolower(substr($error->getMessage(),4))];
            }
        }
        if ($owner===$actor && af_kb_can_edit()) $choice['kb_edit_url']=af_kb_url(['action'=>'kb_edit','type'=>'character','key'=>$entry['key']]);
        if (($payload['schema']??null)!==AF_KB_CHARACTER_SCHEMA || ($meta['mechanic']??null)!=='arpg') {
            return $choice+['state'=>'arpg_contract_required'];
        }
        return $choice+['state'=>'unchecked'];
    }

    private function gmChoices(string $sid,int $actor,int $after,string $search): array
    {
        global $db;
        AF_ACS_CharacterRegistrationGrant::assertGm($sid,$actor);
        if (!$db->table_exists('af_charactersheets_accept')) return ['items'=>[],'reason'=>'dependency_unavailable'];
        $search=trim($search);
        if (mb_strlen($search)>191) return ['items'=>[],'reason'=>'sheet_lookup_invalid'];
        $filter='';
        $exact=false;
        if (preg_match('/^thread-\d+$/iD',$search)) {
            $filter=" AND s.slug='".$db->escape_string($search)."'";
            $exact=true;
        } elseif (preg_match('/^(?:(tid|id):)?([1-9]\d{0,9})$/iD',$search,$match)) {
            $id=(int)$match[2];
            $filter=match(strtolower($match[1]??'')) {
                'tid'=>' AND s.tid='.$id,
                'id'=>' AND s.id='.$id,
                default=>' AND (s.id='.$id.' OR s.tid='.$id.')',
            };
            $exact=true;
        } elseif ($search!=='') {
            $value=$db->escape_string($search);
            $filter=" AND (s.slug='{$value}' OR LOCATE('{$value}',u.username)>0 OR LOCATE('{$value}',t.subject)>0
                OR LOCATE('{$value}',k.title_ru)>0 OR LOCATE('{$value}',k.title_en)>0
                OR LOCATE('{$value}',CASE WHEN JSON_VALID(k.data_json) THEN
                    CONCAT_WS(' ',JSON_UNQUOTE(JSON_EXTRACT(k.data_json,'$.character_profile.character_name_ru')),
                        JSON_UNQUOTE(JSON_EXTRACT(k.data_json,'$.character_profile.character_name'))) END)>0
                OR LOCATE('{$value}',CASE WHEN JSON_VALID(k.meta_json) THEN
                    CONCAT_WS(' ',JSON_UNQUOTE(JSON_EXTRACT(k.meta_json,'$.rules.character_profile.character_name_ru')),
                        JSON_UNQUOTE(JSON_EXTRACT(k.meta_json,'$.rules.character_profile.character_name'))) END)>0)";

        }
        $items=[];
        $cursor=max(0,$after);
        do {
            // The joined search uses only native labels; publication and source permissions are checked before projection.
            $query=$db->query("SELECT s.* FROM ".TABLE_PREFIX."af_cs_sheets s
                JOIN ".TABLE_PREFIX."threads t ON t.tid=s.tid AND t.visible=1
                JOIN ".TABLE_PREFIX."users u ON u.uid=s.uid
                LEFT JOIN ".TABLE_PREFIX."af_charactersheets_accept a ON a.tid=s.tid
                LEFT JOIN ".TABLE_PREFIX."af_kb_entries k ON k.id=a.kb_entry_id AND k.type='character' AND k.active=1
                WHERE s.id>".$cursor.$filter." ORDER BY s.id LIMIT 50");
            $scanned=0;
            while ($row=$db->fetch_array($query)) {
                ++$scanned;
                $cursor=(int)$row['id'];
                $choice=$this->characterChoice($row,$actor,$sid);
                if (!$choice) continue;
                if (count($items)===20) return ['items'=>$items,'next_after'=>(int)end($items)['key']];
                $items[]=$choice;
            }
        } while ($scanned===50);
        return ['items'=>$items,'next_after'=>null,'reason'=>$exact && !$items?'gm_sheet_lookup_unavailable':''];
    }

    /** Paginated candidates; selecting one still runs owner API authorization/validation. */
    public function choices(string $kind,int $actor,int $after=0,string $slug='',?string $gmSession=null): array
    {
        global $db;
        if($kind==='pet')return $this->pets?$this->pets->choices($actor,$after):['items'=>[],'next_after'=>null];
        if($kind==='character') {
            if(!af_is_addon_enabled('charactersheets') || !$db->table_exists('af_cs_sheets')
                || !af_is_addon_enabled('knowledgebase') || !$db->table_exists('af_kb_entries')) {
                return ['items'=>[],'reason'=>'dependency_unavailable'];
            }
            if ($gmSession!==null) return $this->gmChoices($gmSession,$actor,$after,$slug);
            if ($slug!=='') {
                if (!preg_match('/^[a-zA-Z0-9_-]{1,191}$/D',$slug)) return ['items'=>[],'reason'=>'sheet_lookup_invalid'];
                $row=$this->ownedSheet("slug='".$db->escape_string($slug)."'",$actor);
                if ($row) return ['items'=>[$this->characterChoice($row,$actor)]];
                if (preg_match('/^thread-(\d{1,10})$/D',$slug,$match)) {
                    $owned=$this->ownedSheet('1=1',$actor);
                    if ($owned && (int)$owned['tid']!==(int)$match[1]) {
                        return ['items'=>[],'reason'=>'sheet_lookup_other_topic'];
                    }
                }
                return ['items'=>[],'reason'=>'sheet_lookup_unavailable'];
            }
        } else {
            if(!af_is_addon_enabled('knowledgebase'))return [];
            require_once AF_ADDONS.'knowledgebase/knowledgebase.php';
            if(!af_kb_can_view() || !$db->table_exists('af_kb_entries'))return [];
        }
        $table=$kind==='character'?'af_cs_sheets':'af_kb_entries';
        $where=$kind==='character'?'uid='.$actor:'type=\'arpg_bestiary\' AND active=1';
        if ($kind!=='character' && $slug!=='') {
            if (mb_strlen($slug)>191) return ['items'=>[],'reason'=>'sheet_lookup_invalid'];
            $value=$db->escape_string($slug);
            $where.=" AND (`key`='{$value}' OR LOCATE('{$value}',title_ru)>0 OR LOCATE('{$value}',title_en)>0)";
        }
        $query=$db->simple_select($table,'*',$where.' AND id>'.max(0,$after),['order_by'=>'id','order_dir'=>'ASC','limit'=>50]);$result=[];$cursor=null;$scanned=0;
        while($row=$db->fetch_array($query)) {
            ++$scanned;
            $cursor=(int)$row['id'];
            if($kind==='character')$result[]=$this->characterChoice($row,$actor);
            elseif(af_kb_entry_visible_in_context($row,'entry',af_kb_can_edit()))$result[]=['key'=>$row['key'],'label'=>af_kb_pick_text($row,'title')?:$row['key'],'portrait'=>(string)($row['icon_url']??'')];
        }
        return ['items'=>$result,'next_after'=>$scanned===50?$cursor:null];
    }
}

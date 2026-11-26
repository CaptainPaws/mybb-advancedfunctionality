<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/SessionService.php';
require_once __DIR__.'/../Infrastructure/EntityRepository.php';
require_once __DIR__.'/../Infrastructure/Adapters/RegistrationSource.php';

final class AF_ACS_RegistrationBlocked extends RuntimeException
{
    public function __construct(public readonly array $diagnostics) {parent::__construct('ACS_REGISTRATION_BLOCKED');}
}

/** Registration only: no combat state transitions, recalculation, summons or AP. */
final class AF_ACS_EntityService
{
    public function __construct(private AF_ACS_EntityRepository $repository,private AF_ACS_SessionAccess $access,private AF_ACS_RegistrationSource $source) {}
    private function access(string $sid,string $kind, bool $write): array
    {
        $view=(new AF_ACS_SessionService($this->repository,$this->access))->view($sid);
        if($write) {
            if($view['session']['status']!=='active')throw new RuntimeException('ACS_SESSION_TRANSITION_BLOCKED');
            if(in_array($kind,['npc','monster'],true)) {
                if(!$view['can_manage'])throw new RuntimeException('ACS_REGISTRATION_DENIED');
            } else {
                if(!$view['can_register_own'] && !($kind==='character' && $view['can_register_characters']))throw new RuntimeException('ACS_REGISTRATION_DENIED');
            }
        }
        return $view;
    }
    public function list(string $sid,string $after='0'): array
    {
        $view=$this->access($sid,'character',false);$rows=$this->repository->entities($sid,$after,$view['can_manage']?null:$this->access->uid());$visible=[];
        foreach($rows as $row)if($view['can_manage'] || $row['owner_uid']===$this->access->uid())$visible[]=$row;
        return ['items'=>$visible,'next_after'=>count($rows)===100?(string)end($rows)['id']:null];
    }
    public function snapshot(string $sid,string $id): array
    {
        $view=$this->access($sid,'character',false);$row=$this->repository->snapshot($sid,$id);
        if(!$row || ($row['owner_uid']!==null ? (string)$row['owner_uid']!==$this->access->uid() : !$view['can_manage']))throw new RuntimeException('ACS_REGISTRATION_DENIED');
        if(!hash_equals($row['content_hash'],hash('sha256',$row['payload_json'])))throw new RuntimeException('ACS_SNAPSHOT_HASH_MISMATCH');
        return json_decode($row['payload_json'],true,64,JSON_THROW_ON_ERROR);
    }
    public function choices(string $sid,string $kind,int $after=0,string $slug=''): array
    {
        $this->kind($kind);
        $view=$this->access($sid,$kind,true);
        return $this->source->choices($kind,(int)$this->access->uid(),$after,$slug,
            $kind==='character' && $view['can_register_characters'] ? $sid : null);
    }
    private function kind(string $kind): void
    {
        if(!in_array($kind,['character','pet','npc','monster'],true))throw new RuntimeException('ACS_ENTITY_KIND_INVALID');
    }
    private function registrationSource(string $sid,string $kind,string $key,string &$phase,?callable $onError=null): array
    {
        $phase='access';
        $this->kind($kind);
        $view=$this->access($sid,$kind,true);
        if ($key==='' || strlen($key)>191) throw new RuntimeException('ACS_SOURCE_REQUIRED');
        $actor=(int)$this->access->uid();
        $phase='character_grant';
        $grant=$kind==='character' && $view['can_register_characters'] ? $this->source->characterGrant($sid,$key,$actor) : null;
        $phase='source_read';
        return [$this->source->read($kind,$key,$actor,$grant,onError:$onError),$grant,$actor];
    }

    private static function sourceHp(array $dto): array
    {
        $maximum=$dto['characteristics']['hp']??null;
        $resource=$dto['resources']['hp']??[];
        if (!AF_ACS_CapabilityValidator::number($maximum) || !AF_ACS_CapabilityValidator::number($resource['maximum']??null)
            || (float)$resource['maximum']!==(float)$maximum) throw new RuntimeException('ACS_HP_RANGE');
        $current=$resource['current']??null;
        foreach ([$maximum,$current] as $value) {
            if ($value!==null && (!AF_ACS_CapabilityValidator::number($value) || (float)$value>9007199254740991
                || (float)$value!==floor((float)$value))) throw new RuntimeException('ACS_HP_INTEGER_REQUIRED');
        }
        if ($current!==null && (float)$current>(float)$maximum) throw new RuntimeException('ACS_HP_RANGE');
        return ['maximum_hp'=>sprintf('%.0f',(float)$maximum),
            'source_current_hp'=>$current===null?null:sprintf('%.0f',(float)$current)];
    }

    /** Only the selected source is read. The card receives no combat snapshot or private source data. */
    public function characterPrefill(string $sid,string $key): array
    {
        $phase='access';
        $failure=null;
        try {
            [$dto]=$this->registrationSource($sid,'character',$key,$phase,static function (Throwable $error,string $point) use (&$failure): void {
                $failure=af_advancedcombatsystem_source_check_error($error,$point,'entity_prefill');
            });
            if ($failure!==null) return $failure;
            if (!isset($dto['characteristics']['hp'])) return [];
            return self::sourceHp($dto);
        } catch (Throwable $error) {
            return $this->validationFailure($error,$phase,'entity_prefill');
        }
    }

    private function prepare(string $sid,array $input,bool $write=false,?string &$phase=null,?callable $onSourceError=null,?array &$prefill=null): array
    {
        $kind=(string)($input['kind']??'');$key=(string)($input['source_key']??'');
        $phase='access';
        [$dto,$grant,$actor]=$this->registrationSource($sid,$kind,$key,$phase,$onSourceError);
        $owner=$grant?->ownerUid??$actor;
        if ($prefill!==null && $kind==='character' && isset($dto['characteristics']['hp'])) {
            try {
                $prefill=self::sourceHp($dto);
            } catch (RuntimeException $error) {
                // Preserve diagnostics from the source. Ready snapshots validate HP below.
            }
        }
        if(empty($dto['ready']))throw new AF_ACS_RegistrationBlocked($dto['diagnostics']??[['code'=>'snapshot_source_unavailable','path'=>'source','severity'=>'error']]);
        $phase='source_validation';
        $issues=[];$stats=(array)($dto['characteristics']??[]);
        AF_ACS_CapabilityValidator::stats($stats,$issues);
        AF_ACS_CapabilityValidator::resistances((array)($dto['resistances']??[]),$issues);
        if(($dto['equipment_policy']??null)!=='already_included_do_not_reapply')AF_ACS_CapabilityValidator::issue($issues,'equipment_provenance_incomplete','equipment');
        if($issues)throw new AF_ACS_RegistrationBlocked($issues);
        $phase='initial_hp';
        $hp=self::sourceHp($dto);
        $hpMax=$hp['maximum_hp'];
        $current=$hp['source_current_hp'];
        if ($current===null) {
            $initial=$input['initial_hp']??null;
            if ($kind==='character' && !$write && ($initial===null || $initial==='')) $initial=$hpMax;
            if ($initial===null || $initial==='') throw new AF_ACS_RegistrationBlocked([['code'=>'initial_hp_required','path'=>'initial_hp','severity'=>'error']]);
            $current=AF_ACS_Repository::integer($initial,'9007199254740991',true);
            if ((float)$current>(float)$hpMax) throw new RuntimeException('ACS_HP_RANGE');
        }
        // Other resources require an explicit owner contract before projection.
        foreach((array)($dto['resources']??[]) as $resource=>$value)if($resource!=='hp' && !empty($value))throw new AF_ACS_RegistrationBlocked([['code'=>'resource_semantics_unverified','path'=>'resources','severity'=>'error']]);
        $isCharacter=$kind==='character';$isOwned=in_array($kind,['character','pet'],true);
        $fingerprint=$isOwned?($dto['identity']['source_fingerprint']??null):($dto['definition_version']['fingerprint']??null);
        if(!AF_ACS_CapabilityValidator::fingerprint($fingerprint))throw new RuntimeException('ACS_DEFINITION_VERSION_CONFLICT');
        $phase='ability_definitions';
        $definitions=$this->source->definitions($dto,$actor,registrationDiagnostics:true);
        $snapshot=['schema_version'=>1,'contract_id'=>'IC-ACS.entity-registration.v1','contract_version'=>'1',
            'numeric_policy'=>'binary64-half_up-v1','engine_version'=>'acs-registration-1','source_snapshot'=>$dto,
            'pinned_abilities'=>$definitions,'initial_resources'=>['hp'=>['current'=>$current,'maximum'=>$hpMax,'source'=>($dto['resources']['hp']['current']??null)===null?'explicit_registration_input':'owner_contract']],
            'equipment_policy'=>'already_included_do_not_reapply'];
        if ($isCharacter) {
            $phase='inventory_read';
            require_once __DIR__.'/../Infrastructure/Adapters/EquipmentAdapter.php';
            require_once __DIR__.'/../../../advancedinventory/modules/InventoryRead.php';
            global $db;
            $inventory=$this->repository->withReadConnection($db,static function () use ($db,$grant,$owner,$actor,$write) {
                try {
                    $reader=new AF_ADVINV_InventoryRead($db,grant:$grant);
                    if ($write) $reader->fence($owner,$actor);
                    return $reader->read($owner,$actor);
                } catch (mysqli_sql_exception $error) {
                    throw new RuntimeException('INV_READ_FAILED',(int)$error->getCode(),$error);
                }
            });
            $phase='equipment_projection';
            $snapshot['equipment_snapshot']=(new AF_ACS_EquipmentAdapter())->prepare($dto,$inventory,$sid,$actor,$grant);
            if ($grant) $snapshot['registration_context']=['actor_uid'=>$actor,'owner_uid'=>$owner,'session_id'=>$sid,
                'sheet_id'=>$grant->sheetId,'source_tid'=>$grant->sourceTid];
        }
        $phase='snapshot_serialization';
        AF_ACS_Repository::json($snapshot);
        $preparationFingerprint=af_kb_arpg_acs_fingerprint($snapshot);
        $name=trim((string)($input['name']??''));
        if ($name==='') $name=$isCharacter
            ? (($dto['identity']['character_name']??'') ?: 'Персонаж · лист #'.$key)
            : 'Бестиарий · '.$key;
        if(mb_strlen($name)>191)throw new RuntimeException('ACS_INVALID_TEXT');
        return $hp+['kind'=>$kind,'owner_uid'=>$isOwned?(string)$owner:null,'controller_uid'=>$isOwned?(string)$owner:(string)$actor,
            'sheet_id'=>$isCharacter?$key:null,'source'=>['source_system'=>$kind==='pet'?'permanent_pet':($isCharacter?'charactersheets':'knowledgebase'),'source_key'=>$key,'contract_id'=>$kind==='pet'?'ic-acs.permanent-pet.v1':($isCharacter?'ic-cs.combat-preparation.v1':'ic-kb.read.v1'),'contract_version'=>'1'],
            'source_fingerprint'=>$fingerprint,'preparation_fingerprint'=>$preparationFingerprint,'snapshot'=>$snapshot,'stats'=>$stats,'hp'=>$current,'hp_max'=>$hpMax,
            'resources'=>['hp'=>['current'=>$current,'maximum'=>$hpMax,'is_integer'=>true]],'name'=>$name];
    }
    private const CHARACTER_SOURCE_ERRORS = [
        'ACS_CHARACTER_SOURCE_UNAVAILABLE','ACS_CHARACTER_TOPIC_UNAVAILABLE',
        'ACS_CHARACTER_ACCEPT_LINK_INVALID','ACS_CHARACTER_DEFINITION_UNAVAILABLE',
    ];

    /** Explicit POST preview returns diagnostics only; never exposes another owner's DTO. */
    public function validate(string $sid,array $input): array
    {
        $phase='csrf';
        $sourceFailure=null;
        $prefill=[];
        try {
            $this->access->csrf();
            $registration=$this->prepare($sid,$input,phase:$phase,onSourceError:static function (Throwable $error,string $point) use (&$sourceFailure): void {
                $sourceFailure??=af_advancedcombatsystem_source_check_error($error,$point);
            },prefill:$prefill);
            return ['ready'=>true,'status'=>'ready','diagnostics'=>[],
                'name'=>$registration['name'],'initial_hp'=>$registration['hp'],'maximum_hp'=>$registration['maximum_hp'],
                'source_current_hp'=>$registration['source_current_hp'],
                'source_fingerprint'=>$registration['source_fingerprint'],'preparation_fingerprint'=>$registration['preparation_fingerprint']];
        } catch (AF_ACS_RegistrationBlocked $error) {
            if ($sourceFailure!==null) return $sourceFailure;
            $diagnostics=AF_ACS_CapabilityValidator::safeDiagnostics($error->diagnostics);
            $codes=array_column($diagnostics,'code');
            if (in_array('source_read_failed',$codes,true)) return af_advancedcombatsystem_source_check_error($error,$phase);
            $denied=(bool)array_intersect($codes,['access_denied','definition_access_denied','registration_ownership_required','equipment_access_denied']);
            return ['ready'=>false,'status'=>$denied?'denied':'blocked','diagnostics'=>$diagnostics]+($denied?[]:$prefill);
        } catch (Throwable $error) {
            $result=$this->validationFailure($error,$phase);
            return $result+($result['status']==='denied'?[]:$prefill);
        }
    }

    private function validationFailure(Throwable $error,string $phase,string $operation='entity_validate'): array
    {
        $code=$error->getMessage();
        $denied=in_array($code,['ACS_LOGIN_REQUIRED','ACS_CSRF_REQUIRED','ACS_GM_REQUIRED','ACS_REGISTRATION_DENIED','ACS_SESSION_UNAVAILABLE',
            'ACS_THREAD_UNAVAILABLE','ACS_USER_UNAVAILABLE','ACS_CHARACTER_SOURCE_UNAVAILABLE','ACS_CHARACTER_TOPIC_UNAVAILABLE',
            'ACS_EQUIPMENT_ACCESS_DENIED','INV_ACCESS_DENIED'],true);
        $path=match($code) {
            'ACS_INVALID_INTEGER','ACS_INTEGER_RANGE'=>$phase==='initial_hp'?'initial_hp':'source',
            'ACS_HP_RANGE','ACS_HP_INTEGER_REQUIRED'=>'initial_hp',
            'ACS_DEFINITION_VERSION_CONFLICT'=>$phase==='ability_definitions'?'abilities':'source',
            'ACS_DEFINITION_LIMIT'=>'abilities',
            'ACS_EQUIPMENT_SOURCE_UNAVAILABLE','ACS_EQUIPMENT_ACCESS_DENIED','ACS_EQUIPMENT_BINDING_INVALID',
            'ACS_EQUIPMENT_DEFINITION_UNAVAILABLE','ACS_EQUIPMENT_SLOT_INVALID','ACS_EQUIPMENT_COSMETIC_SOURCE',
            'ACS_EQUIPMENT_PROVENANCE_BLOCKED','ACS_EQUIPMENT_SOURCE_CHANGED','ACS_EQUIPMENT_RESERVATION_INVALID',
            'INV_ACCESS_DENIED','INV_SOURCE_CHANGED','INV_VERSION_CONFLICT','INV_INCOMPLETE_SNAPSHOT'=>'equipment',
            'ACS_SOURCE_REQUIRED','ACS_ENTITY_KIND_INVALID','ACS_INVALID_TEXT','ACS_JSON_TOO_LARGE',
            'ACS_DEPENDENCY_UNAVAILABLE','ACS_SESSION_TRANSITION_BLOCKED','ACS_POST_REQUIRED'=>'source',
            default=>$denied || in_array($code,self::CHARACTER_SOURCE_ERRORS,true)?'source':null,
        };
        if ($path===null) return af_advancedcombatsystem_source_check_error($error,$phase,$operation);
        if ($code==='ACS_DEFINITION_VERSION_CONFLICT' && $phase==='ability_definitions') {
            return ['ready'=>false,'status'=>'blocked','diagnostics'=>[
                ['code'=>'ability_version_pin_mismatch','path'=>'abilities','severity'=>'error'],
            ]];
        }
        $result=['ready'=>false,'status'=>$denied?'denied':'blocked',
            'diagnostics'=>[['code'=>strtolower(str_starts_with($code,'ACS_')?substr($code,4):$code),'path'=>$path,'severity'=>'error']]];
        if ($denied) $result['message']='Источник недоступен по правам. Проверьте доступ к листу и текущей сессии.';
        return $result;
    }
    public function register(array $input): array
    {
        $this->access->csrf();$actor=$this->access->uid();$sid=AF_ACS_Repository::integer($input['session_id']??null);
        $kind=(string)($input['kind']??'');$this->kind($kind);
        $key=(string)($input['operation_key']??'');if(!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,95}\z/D',$key))throw new RuntimeException('ACS_OPERATION_KEY_REQUIRED');
        $reason=trim((string)($input['reason']??''));if($reason==='' || mb_strlen($reason)>1000)throw new RuntimeException('ACS_REASON_REQUIRED');
        // Hash original intent, not the mutable live definition: a committed retry never rereads KB/CS.
        $request=['schema_version'=>1,'actor_uid'=>$actor,'session_id'=>$sid,'expected_version'=>AF_ACS_Repository::integer($input['expected_version']??null),'kind'=>$kind,'source_key'=>(string)($input['source_key']??''),'name'=>(string)($input['name']??''),'initial_hp'=>(string)($input['initial_hp']??''),'reason'=>$reason];
        $checked=(string)($input['preparation_fingerprint']??'');
        if ($checked!=='') {
            if (!AF_ACS_CapabilityValidator::fingerprint($checked)) throw new RuntimeException('ACS_DEFINITION_VERSION_CONFLICT');
            $request['preparation_fingerprint']=$checked;
        }
        return $this->repository->command('session:'.$actor.':'.$key,'entity.register',$request,
            function($session)use($sid,$kind){$this->access($sid,$kind,true);},
            function($session)use($sid,$input,$checked){
                try {
                    $registration=$this->prepare($sid,$input,true);
                } catch (RuntimeException $error) {
                    if (!in_array($error->getMessage(),self::CHARACTER_SOURCE_ERRORS,true)) throw $error;
                    throw new AF_ACS_RegistrationBlocked([['code'=>strtolower(substr($error->getMessage(),4)),
                        'path'=>'source','severity'=>'error']]);
                }
                if (isset($registration['snapshot']['registration_context']) && $checked==='') throw new RuntimeException('ACS_SOURCE_CHECK_REQUIRED');
                if ($checked!=='' && !hash_equals($checked,$registration['preparation_fingerprint'])) {
                    throw new RuntimeException('ACS_DEFINITION_VERSION_CONFLICT');
                }
                return $this->repository->register($session,$registration);
            });
    }
}

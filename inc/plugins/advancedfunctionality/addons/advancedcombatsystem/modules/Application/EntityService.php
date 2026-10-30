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
                if(!$view['can_register_own'])throw new RuntimeException('ACS_REGISTRATION_DENIED');
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
    public function choices(string $sid,string $kind,int $after=0): array
    {
        $this->kind($kind);$this->access($sid,$kind,true);return $this->source->choices($kind,(int)$this->access->uid(),$after);
    }
    private function kind(string $kind): void
    {
        if(!in_array($kind,['character','pet','npc','monster'],true))throw new RuntimeException('ACS_ENTITY_KIND_INVALID');
    }
    private function prepare(string $sid,array $input): array
    {
        $kind=(string)($input['kind']??'');$this->kind($kind);$this->access($sid,$kind,true);
        $key=(string)($input['source_key']??'');
        if($key==='' || strlen($key)>191)throw new RuntimeException('ACS_SOURCE_REQUIRED');
        $dto=$this->source->read($kind,$key,(int)$this->access->uid());
        if(empty($dto['ready']))throw new AF_ACS_RegistrationBlocked($dto['diagnostics']??[['code'=>'snapshot_source_unavailable','path'=>'source','severity'=>'error']]);
        $issues=[];$stats=(array)($dto['characteristics']??[]);
        AF_ACS_CapabilityValidator::stats($stats,$issues);
        AF_ACS_CapabilityValidator::resistances((array)($dto['resistances']??[]),$issues);
        if(($dto['equipment_policy']??null)!=='already_included_do_not_reapply')AF_ACS_CapabilityValidator::issue($issues,'equipment_provenance_incomplete','equipment');
        if($issues)throw new AF_ACS_RegistrationBlocked($issues);
        $hpMax=$stats['hp'];
        if(!AF_ACS_CapabilityValidator::number($dto['resources']['hp']['maximum']??null)
            || (float)$dto['resources']['hp']['maximum']!==(float)$hpMax)throw new RuntimeException('ACS_HP_RANGE');
        // SQL hp/hp_max are integers. Do not silently round a source value.
        if(!is_numeric($hpMax) || (float)$hpMax>9007199254740991 || (float)$hpMax!==floor((float)$hpMax))throw new RuntimeException('ACS_HP_INTEGER_REQUIRED');
        $hpMax=sprintf('%.0f',(float)$hpMax);
        $current=$dto['resources']['hp']['current']??null;
        if($current===null) {
            $initial=$input['initial_hp']??null;
            if($initial===null || $initial==='')throw new AF_ACS_RegistrationBlocked([['code'=>'initial_hp_required','path'=>'initial_hp','severity'=>'error']]);
            $current=AF_ACS_Repository::integer($initial,'9007199254740991',true);
        } else {
            if(!AF_ACS_CapabilityValidator::number($current) || (float)$current>9007199254740991 || (float)$current!==floor((float)$current))throw new RuntimeException('ACS_HP_INTEGER_REQUIRED');
            $current=sprintf('%.0f',(float)$current);
        }
        if((float)$current>(float)$hpMax)throw new RuntimeException('ACS_HP_RANGE');
        // Other resources require an explicit owner contract before projection.
        foreach((array)($dto['resources']??[]) as $resource=>$value)if($resource!=='hp' && !empty($value))throw new AF_ACS_RegistrationBlocked([['code'=>'resource_semantics_unverified','path'=>'resources','severity'=>'error']]);
        $isCharacter=$kind==='character';$isOwned=in_array($kind,['character','pet'],true);
        $fingerprint=$isOwned?($dto['identity']['source_fingerprint']??null):($dto['definition_version']['fingerprint']??null);
        if(!AF_ACS_CapabilityValidator::fingerprint($fingerprint))throw new RuntimeException('ACS_DEFINITION_VERSION_CONFLICT');
        $definitions=$this->source->definitions($dto,(int)$this->access->uid());
        $snapshot=['schema_version'=>1,'contract_id'=>'IC-ACS.entity-registration.v1','contract_version'=>'1',
            'numeric_policy'=>'binary64-half_up-v1','engine_version'=>'acs-registration-1','source_snapshot'=>$dto,
            'pinned_abilities'=>$definitions,'initial_resources'=>['hp'=>['current'=>$current,'maximum'=>$hpMax,'source'=>($dto['resources']['hp']['current']??null)===null?'explicit_registration_input':'owner_contract']],
            'equipment_policy'=>'already_included_do_not_reapply'];
        AF_ACS_Repository::json($snapshot);
        $name=trim((string)($input['name']??''));if($name==='')$name=$isCharacter?'Персонаж · лист #'.$key:'Бестиарий · '.$key;
        if(mb_strlen($name)>191)throw new RuntimeException('ACS_INVALID_TEXT');
        return ['kind'=>$kind,'owner_uid'=>$isOwned?$this->access->uid():null,'controller_uid'=>$this->access->uid(),
            'sheet_id'=>$isCharacter?$key:null,'source'=>['source_system'=>$kind==='pet'?'permanent_pet':($isCharacter?'charactersheets':'knowledgebase'),'source_key'=>$key,'contract_id'=>$kind==='pet'?'ic-acs.permanent-pet.v1':($isCharacter?'ic-cs.combat-preparation.v1':'ic-kb.read.v1'),'contract_version'=>'1'],
            'source_fingerprint'=>$fingerprint,'snapshot'=>$snapshot,'stats'=>$stats,'hp'=>$current,'hp_max'=>$hpMax,
            'resources'=>['hp'=>['current'=>$current,'maximum'=>$hpMax,'is_integer'=>true]],'name'=>$name];
    }
    /** Explicit POST preview returns diagnostics only; never exposes another owner's DTO. */
    public function validate(string $sid,array $input): array
    {
        $this->access->csrf();
        try{$registration=$this->prepare($sid,$input);return ['ready'=>true,'diagnostics'=>[],'source_fingerprint'=>$registration['source_fingerprint']];}
        catch(AF_ACS_RegistrationBlocked $e){return ['ready'=>false,'diagnostics'=>$this->safeDiagnostics($e->diagnostics)];}
    }
    private function safeDiagnostics(array $issues): array
    {
        return array_map(static fn($i)=>['code'=>preg_match('/\A[a-z0-9_]{1,96}\z/D',(string)($i['code']??''))?$i['code']:'snapshot_source_unavailable','path'=>preg_match('/\A[a-zA-Z0-9_.]{1,191}\z/D',(string)($i['path']??''))?$i['path']:'source','severity'=>($i['severity']??'')==='warning'?'warning':'error'],array_slice($issues,0,32));
    }
    public function register(array $input): array
    {
        $this->access->csrf();$actor=$this->access->uid();$sid=AF_ACS_Repository::integer($input['session_id']??null);
        $kind=(string)($input['kind']??'');$this->kind($kind);
        $key=(string)($input['operation_key']??'');if(!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,95}\z/D',$key))throw new RuntimeException('ACS_OPERATION_KEY_REQUIRED');
        $reason=trim((string)($input['reason']??''));if($reason==='' || mb_strlen($reason)>1000)throw new RuntimeException('ACS_REASON_REQUIRED');
        // Hash original intent, not the mutable live definition: a committed retry never rereads KB/CS.
        $request=['schema_version'=>1,'actor_uid'=>$actor,'session_id'=>$sid,'expected_version'=>AF_ACS_Repository::integer($input['expected_version']??null),'kind'=>$kind,'source_key'=>(string)($input['source_key']??''),'name'=>(string)($input['name']??''),'initial_hp'=>(string)($input['initial_hp']??''),'reason'=>$reason];
        return $this->repository->command('session:'.$actor.':'.$key,'entity.register',$request,
            function($session)use($sid,$kind){$this->access($sid,$kind,true);},
            function($session)use($sid,$input){return $this->repository->register($session,$this->prepare($sid,$input));});
    }
}

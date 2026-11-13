<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/SealService.php';
require_once __DIR__.'/../Domain/PreparedAction.php';

/** Explicit confirmed ACS publication; never calls MyBB Gateway or executes a trigger. */
final class AF_ACS_PreparedActionService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access) {}
    private function authorize(array $draft): void
    {
        $session=$this->repo->session($draft['session_id'])??throw new RuntimeException('ACS_SESSION_UNAVAILABLE');
        $this->access->draftThread($session['tid']);
        if ($session['status']!=='active' || !$this->repo->roles($session['id'],$this->access->uid()) || $draft['author_uid']!==$this->access->uid()) throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
        $actions=json_decode($draft['mechanics_json'],true,32,JSON_THROW_ON_ERROR)['actions']??[];
        if (count($actions)!==1) throw new RuntimeException('ACS_PREPARED_UNSUPPORTED');
        $grant=$this->repo->controllerFor($session['id'],$actions[0]['actor_id'],$this->access->uid(),'ordinary');
        if (!$grant) throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
        AF_ACS_Control::action($grant,$actions[0]['ability_key']);
    }
    public function publish(array $input): array
    {
        $this->access->csrf();
        if (array_diff(array_keys($input),['draft_id','expected_draft_version','expected_version','operation_key'])) throw new RuntimeException('ACS_PREPARED_UNSUPPORTED');
        $draft=$this->repo->draft(AF_ACS_Repository::integer($input['draft_id']??null),$this->access->uid())??throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
        $this->authorize($draft);
        $key=$input['operation_key']??null;if(!is_string($key)||!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,63}\z/D',$key))throw new RuntimeException('ACS_OPERATION_KEY_REQUIRED');
        $request=['schema_version'=>1,'actor_uid'=>$this->access->uid(),'session_id'=>$draft['session_id'],'draft_id'=>$draft['id'],
            'expected_version'=>AF_ACS_Repository::integer($input['expected_version']??null),
            'expected_draft_version'=>AF_ACS_Repository::integer($input['expected_draft_version']??null),'operation_key'=>$key];
        return $this->repo->command('session:'.$this->access->uid().':prepared:'.$key,'encounter.prepared_register',$request,
            function()use($draft){$current=$this->repo->draft($draft['id'],$this->access->uid())??throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');$this->authorize($current);},
            function($session)use($draft,$request){
                $current=$this->repo->draft($draft['id'],$this->access->uid());
                if($current['draft_version']!==$request['expected_draft_version']||$current['status']!=='sealed'||$current['pid']!==null)throw new RuntimeException('ACS_DRAFT_VERSION_CONFLICT');
                $view=(new AF_ACS_SealService($this->repo,$this->access))->status($current['id']);
                if(!$view['confirmed']||$view['blocked'])throw new RuntimeException('ACS_SEAL_SUMMARY_CHANGED');
                $builder=new AF_ACS_BuilderService($this->repo,$this->access);$scope=$builder->context($session['id'],$current['encounter_id'],$current['turn_id']);
                $actions=json_decode($current['mechanics_json'],true,32,JSON_THROW_ON_ERROR)['actions'];
                $p=$builder->prepare(['session_id'=>$session['id'],'encounter_id'=>$current['encounter_id'],'turn_id'=>$current['turn_id'],'scope_token'=>$scope['scope_token'],'actions'=>$actions,'scenario'=>'hit'],$current['id']);
                $a=$actions[0];$ability=$p['context']['abilities'][$a['actor_id']][$a['ability_key']]??[];
                if(!isset($ability['prepared_action']))throw new RuntimeException('ACS_PREPARED_UNSUPPORTED');
                $seals=$this->repo->sealRows($current['id'],true);if(count($seals)!==1)throw new RuntimeException('ACS_PREPARED_UNSUPPORTED');
                // Recheck time-limited authority after deterministic read preflight.
                $this->authorize($current);
                return $this->repo->registerPrepared($session,$this->repo->encounter($session['id'],$current['encounter_id'],true),$current,$seals[0],$ability,$p['prepared'][0][2]);
            },fn($session,$event)=>$this->repo->linkPreparedEvent($session,$event));
    }
    public function cancel(array $r,bool $recover=false):array
    {
        $this->access->csrf();$fields=['session_id','encounter_id','entity_id','prepared_action_id','prepared_version','expected_version','operation_key'];
        if(array_diff(array_keys($r),$fields)||array_diff($fields,array_keys($r)))throw new RuntimeException('ACS_PREPARED_UNSUPPORTED');
        foreach(array_diff($fields,['operation_key']) as $k)$r[$k]=AF_ACS_Repository::integer($r[$k]);
        if(!is_string($r['operation_key'])||!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,63}\z/D',$r['operation_key']))throw new RuntimeException('ACS_OPERATION_KEY_REQUIRED');
        $r=['schema_version'=>1,'actor_uid'=>$this->access->uid()]+$r;
        $authorize=function()use($r,$recover){$this->view($r['session_id'],$r['encounter_id'],$r['entity_id']);if($recover&&(string)$this->repo->entity($r['session_id'],$r['entity_id'])['owner_uid']!==$this->access->uid())throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');};$authorize();
        return $this->repo->command('session:'.$this->access->uid().($recover?':prepared-release:':':prepared-cancel:').$r['operation_key'],$recover?'encounter.prepared_release':'encounter.prepared_cancel',$r,$authorize,
            fn($s)=>$recover?$this->repo->recoverPreparedItems($s,$this->repo->encounter($r['session_id'],$r['encounter_id'],true),$r['entity_id'],$r['prepared_action_id'],$r['prepared_version']):$this->repo->cancelPrepared($s,$this->repo->encounter($r['session_id'],$r['encounter_id'],true),$r['prepared_action_id'],$r['prepared_version'],$r['entity_id'],$r['operation_key']));
    }
    /** Explicit source scope: even GM has no automatic access by known prepared ID. */
    public function view(string $sid,string $eid,string $entity): array
    {
        $session=$this->repo->session($sid)??throw new RuntimeException('ACS_SESSION_UNAVAILABLE');$this->access->draftThread($session['tid']);
        if(!$this->repo->roles($sid,$this->access->uid())||!$this->repo->controllerFor($sid,$entity,$this->access->uid()))throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
        $out=[];foreach($this->repo->preparedRows($sid,$eid,$entity) as $row)$out[]=['prepared_action_id'=>(string)$row['id'],'entity_id'=>$entity,'can_release'=>$row['cost_state']==='recovery_required'&&(string)$row['owner_uid']===$this->access->uid(),'status'=>$row['status'],'prepared_version'=>(string)$row['prepared_version'],'cost_state'=>$row['cost_state'],'duration'=>$row['expires_boundary']];return $out;
    }
}

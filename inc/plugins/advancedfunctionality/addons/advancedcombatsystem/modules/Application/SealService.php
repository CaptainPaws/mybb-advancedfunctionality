<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/BuilderService.php';
require_once __DIR__.'/../Infrastructure/RandomSource.php';

/** Preparation only: immutable attempts/tape and quota, never a combat commit. */
final class AF_ACS_SealService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access,private ?AF_ACS_RandomSource $random=null){}
    private function draft(string $id):array
    {
        (new AF_ACS_DraftService($this->repo,$this->access))->restore($id);
        return $this->repo->draft($id,$this->access->uid())??throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
    }
    private function scope(array $draft):array
    {
        return (new AF_ACS_BuilderService($this->repo,$this->access))->context($draft['session_id'],$draft['encounter_id'],$draft['turn_id']);
    }
    private function prepare(array $draft,array $actions):array
    {
        $scope=$this->scope($draft);
        if($scope['encounter_status']!=='active'||$scope['turn']['status']!=='pending')throw new RuntimeException('ACS_SEAL_TURN_UNAVAILABLE');
        return (new AF_ACS_BuilderService($this->repo,$this->access))->prepare(['session_id'=>$draft['session_id'],'encounter_id'=>$draft['encounter_id'],'turn_id'=>$draft['turn_id'],'scope_token'=>$scope['scope_token'],'actions'=>$actions,'scenario'=>'hit'],$draft['id']);
    }
    private function actions(array $draft):array{return json_decode($draft['mechanics_json'],true,32,JSON_THROW_ON_ERROR)['actions'];}
    public function status(string $id):array{return $this->view($this->draft(AF_ACS_Repository::integer($id)));}
    private function view(array $draft):array
    {
        $all=$this->repo->sealRows($draft['id']);$history=[];$active=[];$actions=[];$tape=[];
        foreach($all as $row){$payload=$this->repo->sealPayload($row);$t=$this->repo->sealTape((string)$row['id']);
            if(!hash_equals($payload['tape_hash']??'',hash('sha256',AF_ACS_Repository::json(['schema_version'=>1,'tape'=>$t]))))throw new RuntimeException('ACS_SEAL_TAPE_INVALID');
            $history[]=['id'=>(string)$row['id'],'history_index'=>(int)$row['action_index'],'status'=>$row['status'],'ap_cost'=>(int)$row['ap_cost'],'action'=>$payload['action'],'rolls'=>$t];
            if($row['status']==='sealed'){$active[]=$row;$actions[]=$payload['action'];$tape[]=$t;}
        }
        $result=['schema_version'=>1,'draft_id'=>$draft['id'],'draft_version'=>$draft['draft_version'],'active_count'=>count($active),'history'=>$history,'quota_used'=>$this->repo->sealQuota($draft['turn_id']),'quota_budget'=>null,'summary'=>null,'confirmed'=>false,'confirmation_hash'=>null,'confirmable'=>false,'changed'=>false,'blocked'=>false,'publication_enabled'=>false,'persistent_combat_state'=>false];
        try{$scope=$this->scope($draft);$result['quota_budget']=$scope['turn']['ap'];if(!$active)return $result;
            if(array_slice($this->actions($draft),0,count($actions))!==$actions)throw new RuntimeException('ACS_SEAL_IMMUTABLE');
            $p=$this->prepare($draft,$actions);$dependencies=[];
            foreach($active as $i=>$row){$payload=$this->repo->sealPayload($row);$a=$payload['action'];
                $pin=$p['context']['abilities'][$a['actor_id']][$a['ability_key']]['source_ref']??null;
                if($pin!==$payload['pin'])throw new RuntimeException('ACS_SEAL_PIN_CHANGED');
                $this->repo->verifySealDefinition($draft['session_id'],$row,$payload);
                $v=$p['prepared'][$i][2];unset($v['session_version'],$v['encounter_version']);ksort($v);$dependencies[]=$v;
            }
            $ordered=$p['ordered'];foreach($ordered as &$a)unset($a['scenario']);unset($a);
            $p['context']=$this->repo->reactionContext($p['context'],$active);
            $resolved=AF_ACS_Resolver::resolve($p['context'],$ordered,$tape,'seal');
            if($this->prepare($draft,$actions)['prepared']!==$p['prepared'])throw new RuntimeException('ACS_SEAL_SUMMARY_CHANGED');
            $latest=$this->repo->draft($draft['id'],$draft['author_uid']);if(!$latest||$latest['draft_version']!==$draft['draft_version'])throw new RuntimeException('ACS_DRAFT_VERSION_CONFLICT');
            $rows=[];
            foreach($resolved->serverPlan()['actions'] as $i=>$step)$rows[]=['sealed_action_id'=>(string)$active[$i]['id'],'actor_id'=>$step['actor_id'],'target_id'=>$step['targets'][0],'ability_key'=>$actions[$i]['ability_key'],'outcome'=>$step['scenario'],'rolls'=>$step['rolls'],'probabilities'=>$step['probabilities'],'costs'=>$step['costs'],'damage'=>$step['allocation']['integer_budget']]+AF_ACS_Resolver::targetSummary($step)+(isset($step['status_evaluations'])?['status_evaluation_hash'=>hash('sha256',AF_ACS_Repository::json(['schema_version'=>1,'evaluations'=>$step['status_evaluations']]))]:[]);
            $entities=[];foreach($resolved->serverState()['entities'] as $id=>$e)$entities[]=['entity_id'=>(string)$id,'hp'=>$e['hp'],'max_hp'=>$e['max_hp'],'life_state'=>$e['life_state']];
            $summary=['schema_version'=>1,'engine_version'=>AF_ACS_Resolver::VERSION,'numeric_policy'=>AF_ACS_Numeric::POLICY,'rng_version'=>'uniform53-v1','label'=>'Запечатанные серверные результаты — ещё не опубликованы','actions'=>$rows,'virtual_entities'=>$entities,'virtual_groups'=>$resolved->serverState()['groups']];
            if (isset($resolved->serverPlan()['item_state'])) $summary['item_state_hash']=hash('sha256',AF_ACS_Repository::json(['schema_version'=>1,'items'=>$resolved->serverPlan()['item_state']]));
            $dependencyHash=hash('sha256',AF_ACS_Repository::json(['schema_version'=>1,'dependencies'=>$dependencies]));
            if(isset($resolved->serverPlan()['entity_end_boundaries']))$summary['status_end_hash']=hash('sha256',AF_ACS_Repository::json(['schema_version'=>1,'boundaries'=>$resolved->serverPlan()['entity_end_boundaries']]));
            if(array_filter($resolved->serverState()['status_instances']??[],static fn($r)=>isset($r['spec']['upkeep'])))$summary['sustained_state_hash']=hash('sha256',AF_ACS_Repository::json(['schema_version'=>1,'instances'=>$resolved->serverState()['status_instances']]));
            if(($reactionHash=AF_ACS_Resolver::reactionHash($resolved,$p['context']))!==null)$summary['reaction_hash']=$reactionHash;
            $mechanical=$summary;unset($mechanical['label']);
            $simulationHash=hash('sha256',AF_ACS_Repository::json(['schema_version'=>1,'actions'=>$actions,'tape'=>$tape,'summary'=>$mechanical]));
            $confirmation=hash('sha256','acs-confirm-v1'."\n".$dependencyHash."\n".$simulationHash);
            $old=json_decode($draft['dependency_json'],true,32,JSON_THROW_ON_ERROR)['seal']??[];
            $result['summary']=$summary;$result['dependency_hash']=$dependencyHash;$result['simulation_hash']=$simulationHash;$result['confirmation_hash']=$confirmation;
            $result['changed']=isset($old['simulation_hash'])&&($old['simulation_hash']!==$simulationHash||($old['dependency_hash']??null)!==$dependencyHash);
            $result['confirmable']=count($actions)===count($this->actions($draft));$result['confirmed']=$result['confirmable']&&$draft['confirmation_hash']!==null&&hash_equals($draft['confirmation_hash'],$confirmation);
        }catch(Throwable $e){$result['blocked']=true;$result['reason']=AF_ACS_BuilderService::message($e);}
        return $result;
    }
    public function command(string $op,array $input):array
    {
        $this->access->csrf();
        if(!in_array($op,['seal','cancel_tail','confirm','prepare_reactions'],true)||array_diff(array_keys($input),['draft_id','expected_draft_version','operation_key','confirmation_hash']))throw new RuntimeException('ACS_SEAL_INPUT_INVALID');
        $draft=$this->draft(AF_ACS_Repository::integer($input['draft_id']??null));
        $key=$input['operation_key']??null;if(!is_string($key)||!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,95}\z/D',$key))throw new RuntimeException('ACS_OPERATION_KEY_REQUIRED');
        $request=['schema_version'=>1,'op'=>$op,'author_uid'=>$this->access->uid(),'session_id'=>$draft['session_id'],'draft_id'=>$draft['id'],'expected_draft_version'=>AF_ACS_Repository::integer($input['expected_draft_version']??null,'18446744073709551614'),'operation_key'=>$key];
        if($op==='cancel_tail')$request['reason']='author_cancel_tail';
        if($op==='confirm'){$h=$input['confirmation_hash']??null;if(!is_string($h)||!preg_match('/\A[a-f0-9]{64}\z/D',$h))throw new RuntimeException('ACS_SEAL_INPUT_INVALID');$request['confirmation_hash']=$h;}
        return $this->repo->sealCommand($request,function($d)use($op){
            $this->draft($d['id']);$s=$this->scope($d);if($s['encounter_status']!=='active'||$s['turn']['status']!=='pending')throw new RuntimeException('ACS_SEAL_TURN_UNAVAILABLE');
            if($op!=='cancel_tail') {
                if (array_filter($this->actions($d),fn($a)=>AF_ACS_ItemActionService::isItem($a['ability_key']))) {global $db;$uid=(int)$this->access->uid();(new AF_ADVINV_InventoryRead($db))->fence($uid,$uid);}
                $this->prepare($d,$this->actions($d));
            }
        },function($d,$operation)use($op,$request){
            $actions=$this->actions($d);$active=$this->repo->sealRows($d['id'],true);
            if($op==='seal'){
                if(count($actions)<=count($active))throw new RuntimeException('ACS_SEAL_NOTHING_TO_APPEND');
                $p=$this->prepare($d,$actions);
                foreach($active as $i=>$row){$old=$this->repo->sealPayload($row);if(($actions[$i]??null)!==$old['action'])throw new RuntimeException('ACS_SEAL_IMMUTABLE');$a=$old['action'];if($p['context']['abilities'][$a['actor_id']][$a['ability_key']]['source_ref']!==$old['pin'])throw new RuntimeException('ACS_SEAL_PIN_CHANGED');$this->repo->verifySealDefinition($d['session_id'],$row,$old);}
                $used=$this->repo->sealQuota($d['turn_id']);$cost=0;$costs=[];
                foreach(array_slice($actions,count($active)) as $a){$c=$p['context']['abilities'][$a['actor_id']][$a['ability_key']]['costs']??null;
                    if(!is_array($c)||count($c)!==1||$c[0]['kind']!=='ap'||!is_int($c[0]['amount'])||$c[0]['amount']<=0)throw new RuntimeException('ACS_SEAL_ZERO_AP_UNSUPPORTED');$cost+=$c[0]['amount'];$costs[]=$c[0]['amount'];}
                if($used+$cost>$p['scope']['turn']['ap'])throw new RuntimeException('ACS_SEAL_QUOTA_EXHAUSTED');
                $history=$this->repo->sealRows($d['id']);if(count($history)+count($actions)-count($active)>200)throw new RuntimeException('ACS_SEAL_HISTORY_LIMIT');$next=$history?(int)end($history)['action_index']+1:0;
                $query=new AF_ACS_QueryService($this->repo,$this->access);$definitions=[];$statusIndexes=[];
                foreach(array_slice($actions,count($active)) as $a){
                    $position=count($active)+count($definitions);$definitions[]=$p['definitions'][$position]??$query->sealDefinition($d['session_id'],$a['actor_id'],$a['ability_key']);
                    $indexes=[];foreach($a['targets'] as $target)$indexes[$target]=AF_ACS_Resolver::statusRollIndexes($p['context'],$a['actor_id'],$target,$p['context']['abilities'][$a['actor_id']][$a['ability_key']]);$statusIndexes[]=count($a['targets'])===1?array_values($indexes)[0]:$indexes;
                }
                $items=new AF_ACS_ItemActionService($this->repo,$this->access);$protected=[];
                foreach ($definitions as $i=>&$definition) if (isset($definition['item_action'])) {
                    $spec=&$definition['item_action'];$a=$actions[count($active)+$i];
                    $itemKey='acs:item:reserve:'.$d['id'].':'.($next+$i);
                    if ($spec['operation']!=='consume' && isset($spec['outgoing_item_id'])) {
                        $old=$spec['outgoing_reservation']??$protected[$a['actor_id']]??null;
                        if (!$old) $old=$items->reserve(['item_id'=>$spec['outgoing_item_id']],$d['session_id'],$a['actor_id'],$itemKey.':old');
                        $spec['outgoing_reservation']=$old;$protected[$a['actor_id']]=$old;
                    }
                    $spec['reservation']=$items->reserve($spec,$d['session_id'],$a['actor_id'],$itemKey);
                    $spec['snapshot_id']=$this->repo->sealItemSnapshot($d,$definition);
                    unset($spec);
                }unset($definition);
                // Everything above is deterministic preflight. Once drawn, all tape/quota persists even if simulation is blocked.
                foreach(array_slice($actions,count($active)) as $i=>$a){
                    $ability=$p['context']['abilities'][$a['actor_id']][$a['ability_key']];
                    $this->repo->appendSeal($d,$a,$definitions[$i],$ability['source_ref'],$costs[$i],$operation,$next+$i,($this->random??new AF_ACS_RandomSource)->action($statusIndexes[$i]));
                }
            }elseif($op==='cancel_tail'){
                if(!$active)throw new RuntimeException('ACS_SEAL_NOTHING_TO_CANCEL');$last=end($active);$this->repo->cancelSeal((string)$last['id']);
                (new AF_ACS_ItemActionService($this->repo,$this->access))->cancel($d,$last,count($active)===1);array_splice($actions,count($active)-1,1);
                $d['mechanics_json']=AF_ACS_Repository::json(['schema_version'=>1,'actions'=>$actions]);
            }
            if($op==='prepare_reactions')$p=$this->prepare($d,$actions);
            if(in_array($op,['seal','prepare_reactions'],true)){$this->repo->prepareReactionRolls($d,$p['context'],$this->random??new AF_ACS_RandomSource);}
            $view=$this->view($d);$confirmation=null;
            if($op==='confirm'){
                if($view['blocked']||!$view['confirmable']||$view['confirmation_hash']===null||!hash_equals($view['confirmation_hash'],$request['confirmation_hash']))throw new RuntimeException('ACS_SEAL_SUMMARY_CHANGED');
                $confirmation=$view['confirmation_hash'];
            }
            $hashes=array_intersect_key($view,array_flip(['dependency_hash','simulation_hash']));
            $saved=$this->repo->projectSeal($d,$actions,$hashes,$confirmation);
            $view['draft_version']=$saved['draft_version'];$view['confirmed']=$confirmation!==null;
            return $view;
        });
    }
}

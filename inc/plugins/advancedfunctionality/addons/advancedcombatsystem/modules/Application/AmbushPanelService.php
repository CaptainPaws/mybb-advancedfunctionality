<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/AmbushStartService.php';

/** GM-only allowlisted projection. Never serializes a start receipt/contract/tape. */
final class AF_ACS_AmbushPanelService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access,private ?AF_ACS_AmbushStartService $start=null) {$this->start??=new AF_ACS_AmbushStartService($repo,$access);}
    public function snapshot(string $sid,string $eid):array
    {
        $sid=AF_ACS_Repository::integer($sid);$eid=AF_ACS_Repository::integer($eid);
        $raw=$this->start->view($sid,$eid);$session=$this->repo->session($sid);$enc=$this->repo->encounter($sid,$eid);
        $ledger=$this->repo->preRoundCommitments($sid,$eid);$count=0;$unsupported=0;$groups=[];$seen=[];
        $after='0';do{$members=$this->repo->participants($eid,$after);foreach($members as $m){$after=$m['id'];if($m['participation']!=='engaged')continue;
            $entity=$this->repo->entity($sid,$m['entity_id']);$g=$entity['control_group_id'];
            $groups[$g]=['group_id'=>$g,'budget'=>$this->repo->preRoundBudget($sid,$g),'commitment'=>$ledger['totals'][$g]??0];
            foreach($this->repo->preparedRows($sid,$eid,$m['entity_id']) as $p){if($p['status']!=='active'||isset($seen[$p['id']]))continue;$seen[$p['id']]=true;
                try{$c=json_decode($p['contract_json'],true,64,JSON_THROW_ON_ERROR);AF_ACS_PreparedAction::startExecution($c['start_execution']??null);if($p['cost_state']!=='paid')throw new RuntimeException('blocked');++$count;}catch(Throwable){++$unsupported;}
            }
        }}while(count($members)===100);
        foreach($groups as &$g)$g['remaining_first_round']=AF_ACS_Clock::availableAP($g['budget'],0,$g['commitment']);unset($g);
        $op=$raw['operation_status']??null;$confirmed=$op==='committed'&&$this->repo->ambushStartConfirmed($sid,$eid);if($op==='committed'&&!$confirmed)$op='recovery_required';
        $phase=$confirmed?($enc['status']==='active'&&$enc['round_number']==='1'?'active':'start_confirmed'):($op==='recovery_required'?'recovery_required':($op==='prepared'?(isset($raw['outcome']['causal_seal'])?'ambush_resolution':'encounter_start'):$enc['status']));
        $ready=in_array($enc['status'],['preparation','ambush_ready'],true)?(new AF_ACS_EncounterService($this->repo,$this->access))->readiness($sid,$eid,false,$enc['status']==='ambush_ready'):null;
        $obligations=false;if($raw['start_operation_id']===null)try{$this->repo->assertNoObligations($sid,$eid);}catch(Throwable){$obligations=true;}
        // Diagnostics are intentionally aggregate. No participant positions or GM reasons cross this DTO.
        return ['schema_version'=>1,'session_id'=>$sid,'encounter_id'=>$eid,'tid'=>$session['tid'],'viewer_uid'=>$this->access->uid(),
            'expected_version'=>$session['session_version'],'encounter_version'=>$enc['encounter_version'],'phase'=>$phase,
            'prepared_count'=>$count,'unsupported_count'=>$unsupported,'groups'=>array_values($groups),
            'participants_ready'=>$ready===null?null:$ready['can_start'],'blocker_count'=>($ready===null?0:count($ready['diagnostics']))+($obligations?1:0),'operations_blocked'=>$obligations,
            'can_ready'=>$enc['status']==='preparation'&&$raw['start_operation_id']===null&&!$obligations&&($ready['can_start']??false),
            'can_start'=>$enc['status']==='ambush_ready'&&$raw['start_operation_id']===null&&$unsupported===0&&!$obligations&&($ready['can_start']??false),
            'start_operation_id'=>$raw['start_operation_id'],'operation_status'=>$op,'mechanics_confirmed'=>$confirmed,
            'round_id'=>$confirmed?($raw['outcome']['round_id']??null):null,
            'executed_count'=>$confirmed?count($raw['outcome']['prepared_executions']??[]):0,
            'post_created'=>false];
    }
    public function command(string $op,array $input):array
    {
        $this->start->command($op,$input);
        return $this->snapshot($input['session_id'],$input['encounter_id']);
    }
}

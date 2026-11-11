<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/SessionService.php';
require_once __DIR__.'/../Infrastructure/EncounterRepository.php';

/** Exact owner consent, or a separately proven replacement policy; never derived from GM role. */
final class AF_ACS_DelegationService
{
    public function __construct(private AF_ACS_EncounterRepository $repo,private AF_ACS_SessionAccess $access) {}
    private function context(string $sid): array
    {
        return (new AF_ACS_SessionService($this->repo,$this->access))->view($sid);
    }
    private function entities(string $sid,string $owner,array $ids,?string $group): array
    {
        if($group===null){
            if(!$ids)throw new RuntimeException('ACS_DELEGATION_DENIED');
            foreach($ids as $id){$e=$this->repo->entity($sid,$id);if(!$e || $e['owner_uid']!==$owner || $e['group_owner']!==$owner || $e['group_kind']!=='player' || !in_array($e['kind'],['character','pet'],true))throw new RuntimeException('ACS_DELEGATION_DENIED');}
            return $ids;
        }
        $rows=$this->repo->delegationEntities($sid,$owner,$group);$g=$this->repo->restGroup($sid,$group);
        if(!$g || (string)$g['owner_uid']!==$owner || $g['kind']!=='player' || !$rows)throw new RuntimeException('ACS_DELEGATION_DENIED');
        if(count($rows)>100)throw new RuntimeException('ACS_DELEGATION_SCOPE_TOO_LARGE');
        $allowed=[];foreach($rows as $row){if(!in_array($row['kind'],['character','pet'],true))throw new RuntimeException('ACS_DELEGATION_DENIED');$allowed[]=(string)$row['id'];}
        return $allowed;
    }

    private function authority(array $session,string $owner,array $ids,?string $group,array $actions): array
    {
        if($this->access->uid()===$owner)return ['kind'=>'owner','actor_uid'=>$owner];
        $override=$this->access->delegationReplacement($session,$owner,$ids,$group,$actions);
        if(!$override || !(($override['kind']??null)==='native_admin' || AF_ACS_AuthorizationService::sessionGm($session,$this->repo->roles($session['id'],$this->access->uid()),$this->access->uid())))throw new RuntimeException('ACS_DELEGATION_DENIED');
        return $override;
    }
    public function view(string $sid,string $after='0'): array
    {
        $view=$this->context($sid);$uid=$this->access->uid();$items=[];
        $rows=$this->repo->delegationList($sid,$uid,$view['can_manage'],$after);
        foreach($rows as $row){
            if((string)$row['owner_uid']!==$uid && (string)$row['delegate_uid']!==$uid && !$view['can_manage'])continue;
            $scope=json_decode($row['scope_json'],true,32,JSON_THROW_ON_ERROR);
            $canRevoke=in_array($uid,[(string)$row['owner_uid'],(string)$row['delegate_uid']],true);
            if(!$canRevoke){try{$this->authority($view['session'],(string)$row['owner_uid'],$scope['entity_ids'],$scope['control_group_id'],$scope['permitted_actions']);$canRevoke=true;}catch(RuntimeException){}}
            $items[]=['id'=>(string)$row['id'],'owner_uid'=>(string)$row['owner_uid'],'delegate_uid'=>(string)$row['delegate_uid'],'status'=>$row['status'],'delegation_version'=>(string)$row['delegation_version'],'valid_until'=>$row['valid_until']===null?null:(string)$row['valid_until'],
                'entity_ids'=>$scope['entity_ids'],'control_group_id'=>$scope['control_group_id'],'permitted_actions'=>$scope['permitted_actions'],'valid_from'=>$scope['valid_from'],
                'can_revoke'=>$canRevoke];
        }
        // Cursor follows examined rows, never a count of hidden rows.
        return ['session'=>$view['session'],'items'=>$items,'can_request_replacement'=>$view['can_manage']||$this->access->administrator(),'next_after'=>count($rows)===100?(string)end($rows)['id']:null];
    }
    public function command(string $op,array $input): array
    {
        $this->access->csrf();if(!in_array($op,['grant','revoke'],true))throw new RuntimeException('ACS_DELEGATION_INPUT_INVALID');
        $allowed=['session_id','expected_version','operation_key','reason','owner_uid','delegate_uid','entity_ids','control_group_id','permitted_actions','valid_from','valid_until','duration_seconds','delegation_id','delegation_version'];
        if(array_diff(array_keys($input),$allowed))throw new RuntimeException('ACS_DELEGATION_INPUT_INVALID');
        $sid=AF_ACS_Repository::integer($input['session_id']??null);$uid=$this->access->uid();$key=(string)($input['operation_key']??'');
        if(!preg_match('/\A[a-z0-9][a-z0-9_.-]{15,95}\z/D',$key))throw new RuntimeException('ACS_OPERATION_KEY_REQUIRED');
        $reason=$input['reason']??null;if(!is_string($reason)||!mb_check_encoding($reason,'UTF-8')||str_contains($reason,"\0"))throw new RuntimeException('ACS_REASON_REQUIRED');$reason=trim($reason);if($reason===''||mb_strlen($reason)>1000)throw new RuntimeException('ACS_REASON_REQUIRED');
        $r=['schema_version'=>1,'session_id'=>$sid,'actor_uid'=>$uid,'expected_version'=>AF_ACS_Repository::integer($input['expected_version']??null),'reason'=>$reason];
        if($op==='grant'){
            $r['owner_uid']=AF_ACS_Repository::integer($input['owner_uid']??$uid,'4294967295');$r['delegate_uid']=AF_ACS_Repository::integer($input['delegate_uid']??null,'4294967295');
            if($r['owner_uid']===$r['delegate_uid'])throw new RuntimeException('ACS_DELEGATION_INPUT_INVALID');
            $ids=$input['entity_ids']??[];if(is_string($ids))$ids=trim($ids)===''?[]:explode(',',trim($ids));
            if(!is_array($ids)||!array_is_list($ids)||count($ids)>100)throw new RuntimeException('ACS_DELEGATION_INPUT_INVALID');
            $r['entity_ids']=array_map(static fn($id)=>AF_ACS_Repository::integer(is_string($id)?trim($id):$id),$ids);sort($r['entity_ids'],SORT_STRING);
            $r['control_group_id']=empty($input['control_group_id'])?null:AF_ACS_Repository::integer($input['control_group_id']);
            if(count(array_unique($r['entity_ids']))!==count($ids) || ($r['control_group_id']!==null)===(bool)$ids)throw new RuntimeException('ACS_DELEGATION_INPUT_INVALID');
            $actions=$input['permitted_actions']??['ordinary'];if(is_string($actions))$actions=explode(',',trim($actions));
            if(!is_array($actions)||!array_is_list($actions)||!in_array('ordinary',$actions,true)||array_diff($actions,AF_ACS_AuthorizationService::DELEGATION_ACTIONS)||count(array_unique($actions))!==count($actions))throw new RuntimeException('ACS_DELEGATION_INPUT_INVALID');
            sort($actions,SORT_STRING);$r['permitted_actions']=$actions;
            $r['valid_from']=AF_ACS_Repository::integer($input['valid_from']??'0','9007199254740991',true);
            $r['valid_until']=empty($input['valid_until'])?null:AF_ACS_Repository::integer($input['valid_until'],'9007199254740991');
            $r['duration_seconds']=AF_ACS_Repository::integer($input['duration_seconds']??'0','2419200',true);
            if($r['duration_seconds']!=='0' && $r['valid_until']!==null)throw new RuntimeException('ACS_DELEGATION_INPUT_INVALID');
        }else{$r['delegation_id']=AF_ACS_Repository::integer($input['delegation_id']??null);$r['delegation_version']=AF_ACS_Repository::integer($input['delegation_version']??null,'18446744073709551614');}
        $operation='session:'.$uid.':'.$key;
        return $this->repo->command($operation,'delegation.'.$op,$r,
            function($s)use($sid,$r,$op){
                $this->context($sid);
                if($op==='grant'){
                    if($s['status']!=='active')throw new RuntimeException('ACS_SESSION_TRANSITION_BLOCKED');
                    $ids=$this->entities($sid,$r['owner_uid'],$r['entity_ids'],$r['control_group_id']);$this->authority($s,$r['owner_uid'],$ids,$r['control_group_id'],$r['permitted_actions']);
                    foreach([$r['owner_uid'],$r['delegate_uid']] as $member){$this->access->user($member);$this->access->thread($s['tid'],$member);if(!array_intersect(array_column($this->repo->roles($sid,$member),'role'),['owner','gm','assistant_gm','player']))throw new RuntimeException('ACS_DELEGATION_DENIED');}
                }else{
                    $row=$this->repo->delegation($sid,$r['delegation_id']);if(!$row)throw new RuntimeException('ACS_DELEGATION_DENIED');
                    if(!in_array($this->access->uid(),[(string)$row['owner_uid'],(string)$row['delegate_uid']],true)){$scope=json_decode($row['scope_json'],true,32,JSON_THROW_ON_ERROR);$this->authority($s,(string)$row['owner_uid'],$scope['entity_ids'],$scope['control_group_id'],$scope['permitted_actions']);}
                }
            },
            function($s)use($op,$r,$sid,$operation){
                if($op==='revoke')return $this->repo->revokeDelegation($s,$this->repo->delegation($sid,$r['delegation_id']),$r,$operation);
                if($r['valid_from']==='0')$r['valid_from']=(string)time();
                if($r['duration_seconds']!=='0')$r['valid_until']=(string)(max(time(),(int)$r['valid_from'])+(int)$r['duration_seconds']);
                if($r['valid_until']!==null && (int)$r['valid_until']<=max(time(),(int)$r['valid_from']))throw new RuntimeException('ACS_DELEGATION_INPUT_INVALID');
                $ids=$this->entities($sid,$r['owner_uid'],$r['entity_ids'],$r['control_group_id']);$authority=$this->authority($s,$r['owner_uid'],$ids,$r['control_group_id'],$r['permitted_actions']);
                if(($authority['kind']??null)==='quest_rule' && ($r['valid_until']===null || (int)$r['valid_until']>(int)$authority['valid_until']))throw new RuntimeException('ACS_DELEGATION_DENIED');
                return $this->repo->grantDelegation($s,$r,$operation,$authority);
            });
    }
}

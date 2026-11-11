<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Same session command transaction and audit ledger as existing ACS commands. */
trait AF_ACS_DelegationPersistence
{
    public function delegation(string $sid,string $id): ?array
    {
        return $this->sql('SELECT * FROM '.$this->table('delegations').' WHERE session_id=? AND id=?',[$sid,$id])['rows'][0]??null;
    }
    public function delegationRows(string $sid,string $uid): array
    {
        return $this->sql('SELECT * FROM '.$this->table('delegations').' WHERE session_id=? AND delegate_uid=? AND status=? ORDER BY id',[$sid,$uid,'active'])['rows'];
    }
    public function delegationList(string $sid,string $uid,bool $manager,string $after): array
    {
        return $this->sql('SELECT * FROM '.$this->table('delegations').' WHERE session_id=? AND id>?'.($manager?'':' AND (owner_uid=? OR delegate_uid=?)').' ORDER BY id LIMIT 100',$manager?[$sid,self::integer($after,zero:true)]:[$sid,self::integer($after,zero:true),$uid,$uid])['rows'];
    }
    public function delegationEntities(string $sid,string $owner,?string $group=null): array
    {
        return $this->sql('SELECT id,kind,owner_uid,control_group_id FROM '.$this->table('entities').' WHERE session_id=? AND owner_uid=?'.($group===null?'':' AND control_group_id=?').' ORDER BY id LIMIT 101',$group===null?[$sid,$owner]:[$sid,$owner,$group])['rows'];
    }
    public function grantDelegation(array $session,array $request,string $operation,array $authority): array
    {
        $this->commandWrite();
        $scope=['schema_version'=>1,'scope'=>'session','entity_ids'=>$request['entity_ids'],'control_group_id'=>$request['control_group_id'],
            'permitted_actions'=>$request['permitted_actions'],'valid_from'=>$request['valid_from']==='0'?(string)time():$request['valid_from'],
            'reason'=>$request['reason'],'authority'=>$authority,'audit_refs'=>['grant_operation_key'=>$operation]];
        $id=$this->sql('INSERT INTO '.$this->table('delegations').' (session_id,owner_uid,delegate_uid,status,delegation_version,valid_until,scope_json) VALUES (?,?,?,?,1,?,?)',[$session['id'],$request['owner_uid'],$request['delegate_uid'],'active',$request['valid_until'],self::json($scope)])['id'];
        $session['_command_result']=['delegation_id'=>$id,'delegation_version'=>'1','owner_uid'=>$request['owner_uid'],'delegate_uid'=>$request['delegate_uid'],'status'=>'active','scope'=>$scope,'valid_until'=>$request['valid_until']];return $session;
    }
    public function revokeDelegation(array $session,array $row,array $request,string $operation): array
    {
        $this->commandWrite();$scope=json_decode($row['scope_json'],true,32,JSON_THROW_ON_ERROR);
        $scope['revocation']=['reason'=>$request['reason'],'actor_uid'=>$request['actor_uid'],'at'=>(string)time()];$scope['audit_refs']['revoke_operation_key']=$operation;
        if($this->sql('UPDATE '.$this->table('delegations')." SET status='revoked',delegation_version=delegation_version+1,scope_json=? WHERE session_id=? AND id=? AND delegation_version=? AND status='active'",[self::json($scope),$session['id'],$row['id'],$request['delegation_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $current=$this->delegation($session['id'],(string)$row['id']);
        $session['_command_result']=['delegation_id'=>(string)$row['id'],'before_delegation_version'=>(string)$row['delegation_version'],'delegation_version'=>(string)$current['delegation_version'],'status'=>'revoked','scope'=>$scope];return $session;
    }
}

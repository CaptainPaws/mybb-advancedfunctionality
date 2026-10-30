<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/Repository.php';

/** Session projection and append-only audit share one existing ACS transaction. */
class AF_ACS_SessionRepository extends AF_ACS_Repository
{
    private bool $inCommand = false;
    private function commandWrite(): void {if(!$this->inCommand)throw new RuntimeException('ACS_COMMAND_REQUIRED');}
    private function sessionRow(array $row): array
    {
        if((string)$row['schema_version']!=='1')throw new RuntimeException('ACS_SESSION_SCHEMA_UNSUPPORTED');
        foreach(['id','tid','owner_uid','session_version','next_sequence','created_at','updated_at'] as $field)$row[$field]=(string)$row[$field];
        return $row;
    }
    public function session(string $sessionId): ?array
    {
        $row=parent::session($sessionId);return $row?$this->sessionRow($row):null;
    }
    public function roles(string $id, string $uid): array
    {
        return $this->sql('SELECT role FROM '.$this->table('session_roles').' WHERE session_id=? AND uid=? AND (expires_at IS NULL OR expires_at>?)', [self::integer($id),self::integer($uid,'4294967295'),time()])['rows'];
    }
    public function members(string $id): array
    {
        $rows=$this->sql('SELECT uid,role,role_version,expires_at FROM '.$this->table('session_roles').' WHERE session_id=? ORDER BY uid,role LIMIT 201',[self::integer($id)])['rows'];
        foreach($rows as &$row) {foreach(['uid','role_version','expires_at'] as $field)if($row[$field]!==null)$row[$field]=(string)$row[$field];}unset($row);return $rows;
    }
    /** Access candidates only: application must check native topic permissions too. */
    public function candidates(string $uid, bool $administrator, string $after = '0', ?string $tid = null): array
    {
        $where = 's.id>?'; $params=[self::integer($after,zero:true)];
        if (!$administrator) { $where.=' AND EXISTS (SELECT 1 FROM '.$this->table('session_roles').' r WHERE r.session_id=s.id AND r.uid=? AND (r.expires_at IS NULL OR r.expires_at>?))';array_push($params,$uid,time()); }
        if ($tid!==null) {$where.=' AND s.tid=?';$params[]=$tid;}
        return array_map(fn($row)=>$this->sessionRow($row),$this->sql('SELECT s.* FROM '.$this->table('sessions').' s WHERE '.$where.' ORDER BY s.id LIMIT 100',$params)['rows']);
    }
    public function insertSession(string $tid, string $owner, array $config): array
    {
        $this->commandWrite();
        $id=$this->sql('INSERT INTO '.$this->table('sessions').' (tid,owner_uid,status,session_version,next_sequence,schema_version,config_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)',[$tid,$owner,'active','1','1',1,self::json($config),time(),time()])['id'];
        $this->assign($id,$owner,'owner');return $this->session($id);
    }
    public function assign(string $id, string $uid, string $role): void
    {
        $this->commandWrite();
        $old=$this->sql('SELECT id,role_version FROM '.$this->table('session_roles').' WHERE session_id=? AND uid=? FOR UPDATE',[$id,$uid])['rows'];
        if(count($old)>1)throw new RuntimeException('ACS_ROLE_AMBIGUOUS');
        if($old) {
            self::integer($old[0]['role_version'],'18446744073709551614');
            $this->sql('UPDATE '.$this->table('session_roles').' SET role=?,role_version=role_version+1,expires_at=NULL WHERE id=?',[$role,$old[0]['id']]);
        } else $this->sql('INSERT INTO '.$this->table('session_roles').' (session_id,uid,role,permissions_json,role_version,expires_at) VALUES (?,?,?,?,?,NULL)',[$id,$uid,$role,self::json(['schema_version'=>1]),1]);
    }

    public function remove(string $id, string $uid): void
    {
        $this->commandWrite();
        $this->sql('DELETE FROM '.$this->table('session_roles').' WHERE session_id=? AND uid=?',[$id,$uid]);
    }
    public function owner(string $id, string $uid): void
    {
        $this->commandWrite();
        $this->sql('UPDATE '.$this->table('sessions').' SET owner_uid=? WHERE id=?',[$uid,$id]);
    }
    /** Internal command boundary; callers supply authorization, never client callbacks. */
    public function command(string $key, string $kind, array $request, callable $authorize, callable $apply): array
    {
        $key=self::key($key);$kind=self::key($kind,64);$json=self::json($request);$hash=hash('sha256',$json);
        return $this->transactions->run(function () use($key,$kind,$request,$json,$hash,$authorize,$apply) {
            $this->writable();
            $old=$this->sql('SELECT * FROM '.$this->table('operations').' WHERE operation_key=? FOR UPDATE',[$key])['rows'][0]??null;
            $id=$old ? (string)$old['session_id'] : ($request['session_id']??null);
            $session=$id===null?null:$this->sessionRow($this->lockSession($id));
            $authorize($session);
            if($old) {
                if($old['kind']!==$kind || !hash_equals($old['payload_hash'],$hash))throw new RuntimeException('ACS_OPERATION_KEY_CONFLICT');
                if($old['status']!=='committed')throw new RuntimeException('ACS_RECOVERY_REQUIRED');
                return json_decode($old['result_json'],true,512,JSON_THROW_ON_ERROR);
            }
            $recent=$this->sql('SELECT COUNT(*) AS n FROM '.$this->table('operations').' WHERE operation_key LIKE ? AND updated_at>?',['session:'.$request['actor_uid'].':%',time()-60])['rows'][0]['n'];
            if((int)$recent>=20)throw new RuntimeException('ACS_RATE_LIMIT');
            if($session && (string)$session['session_version']!==$request['expected_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
            $this->inCommand=true;
            try {$session=$apply($session);} finally {$this->inCommand=false;}
            $id=(string)$session['id'];
            $version=self::integer($session['session_version'],'18446744073709551614');
            $sequence=self::integer($session['next_sequence'],'18446744073709551614');
            $this->sql('INSERT INTO '.$this->table('operations').' (operation_key,session_id,kind,payload_hash,status,operation_version,request_json,result_json,updated_at) VALUES (?,?,?,?,?,?,?,?,?)',[$key,$id,$kind,$hash,'prepared',1,$json,self::json(['schema_version'=>1]),time()]);
            $event=$this->sql('INSERT INTO '.$this->table('events').' (session_id,session_sequence,operation_key,event_type,actor_uid,engine_version,created_at) VALUES (?,?,?,?,?,?,?)',[$id,$sequence,$key,$kind,$request['actor_uid'],'acs-sessions-1',time()])['id'];
            $details=self::json(['schema_version'=>1,'numeric_policy'=>'binary64-half_up-v1','request'=>$request,'before_version'=>$version]);
            $visibility=self::json(['schema_version'=>1,'scope'=>'session_management']);
            $this->sql('INSERT INTO '.$this->table('event_details').' (event_id,schema_version,numeric_policy,payload_json,visibility_json,content_hash) VALUES (?,?,?,?,?,?)',[$event,1,'binary64-half_up-v1',$details,$visibility,hash('sha256',$details."\n".$visibility)]);
            if($this->sql('UPDATE '.$this->table('sessions').' SET session_version=session_version+1,next_sequence=next_sequence+1,updated_at=? WHERE id=? AND session_version=?',[time(),$id,$version])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            $result=['schema_version'=>1,'session_id'=>$id,'session_version'=>(string)$this->session($id)['session_version'],'event_id'=>$event];
            $this->sql('UPDATE '.$this->table('operations').' SET status=?,operation_version=operation_version+1,result_json=? WHERE operation_key=?',['committed',self::json($result),$key]);
            return $result;
        });
    }
}

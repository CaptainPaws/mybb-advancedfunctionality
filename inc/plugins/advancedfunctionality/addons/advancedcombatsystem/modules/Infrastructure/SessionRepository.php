<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/Repository.php';
require_once __DIR__.'/SessionObligationsQuery.php';

/** Session projection and append-only audit share one existing ACS transaction. */
class AF_ACS_SessionRepository extends AF_ACS_Repository
{
    private bool $inCommand = false;
    protected function internalCommand(callable $apply):mixed
    {
        $this->transactions->assertInternalBoundary();$old=$this->inCommand;$this->inCommand=true;try{return $apply();}finally{$this->inCommand=$old;}
    }
    protected function commandWrite(): void {if(!$this->inCommand)throw new RuntimeException('ACS_COMMAND_REQUIRED');}
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
    public function hasTopicSession(string $tid): bool
    {
        return (bool)$this->sql('SELECT id FROM '.$this->table('sessions').' WHERE tid=? LIMIT 1', [$tid])['rows'];
    }

    public function atfTopicStatus(string $tid, string $uid): ?array
    {
        $session = $this->sql('SELECT s.id,s.owner_uid,MAX(r.role=?) AS is_gm,MAX(r.role=?) AS is_owner FROM '.$this->table('sessions').' s
            JOIN '.$this->table('session_roles').' r ON r.session_id=s.id
            WHERE s.tid=? AND r.uid=? AND r.role IN (?,?,?,?,?) AND (r.expires_at IS NULL OR r.expires_at>?)
            GROUP BY s.id,s.owner_uid ORDER BY is_gm DESC,(is_owner AND s.owner_uid=?) DESC,s.id LIMIT 1',
            ['gm','owner',$tid,$uid,'owner','gm','assistant_gm','player','observer',time(),$uid])['rows'][0] ?? null;
        if ($session === null) { return null; }
        $encounter = $this->sql('SELECT status FROM '.$this->table('encounters')." WHERE session_id=?
            ORDER BY CASE WHEN status IN ('active','paused','pending_resolution') THEN 0 WHEN status IN ('preparation','ambush_ready') THEN 1 ELSE 2 END,id DESC LIMIT 1",
            [$session['id']])['rows'][0] ?? null;
        return ['id'=>$session['id'], 'encounter_status'=>$encounter['status'] ?? null,
            'can_manage'=>(bool)$session['is_gm'] || ((bool)$session['is_owner'] && (string)$session['owner_uid'] === $uid)];
    }

    public function roles(string $id, string $uid): array
    {
        return $this->sql('SELECT uid,role,expires_at FROM '.$this->table('session_roles').' WHERE session_id=? AND uid=? AND (expires_at IS NULL OR expires_at>?)', [self::integer($id),self::integer($uid,'4294967295'),time()])['rows'];
    }
    public function members(string $id): array
    {
        $rows=$this->sql('SELECT uid,role,role_version,expires_at FROM '.$this->table('session_roles').' WHERE session_id=? ORDER BY uid,role LIMIT 201',[self::integer($id)])['rows'];
        foreach($rows as &$row) {foreach(['uid','role_version','expires_at'] as $field)if($row[$field]!==null)$row[$field]=(string)$row[$field];}unset($row);return $rows;
    }
    public function memberSearch(string $query): array
    {
        if (preg_match('/\A[0-9]{1,10}\z/D',$query)) {
            $where='uid=? OR LOCATE(?,username)>0';
            $order='(uid=?) DESC,username,uid';
            $params=[$query,$query,$query];
        } else {
            $where='LOCATE(?,username)>0';
            $order='username,uid';
            $params=[$query];
        }
        return array_column($this->sql('SELECT uid FROM `'.$this->prefix.'users` WHERE '.$where
            .' ORDER BY '.$order.' LIMIT 20',$params)['rows'],'uid');
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
    /** Code-owned extension factory. Providers cannot come from an HTTP request. */
    protected function obligationsQuery(): AF_ACS_SessionObligationsQuery
    {
        return new AF_ACS_SessionObligationsQuery($this->connection);
    }
    public function obligations(string $sid): array { return $this->obligationsQuery()->inspect($sid); }
    /** The existing command transaction owns the session lock, CAS, receipt and event. */
    public function lifecycle(array $session,string $operation): array
    {
        $this->commandWrite();
        [$before,$after]=match($operation){
            'request_close'=>['active','pending_close'], 'close'=>['pending_close','closed'],
            'reopen'=>['closed','active'], 'archive'=>['closed','archived'],
            default=>throw new RuntimeException('ACS_SESSION_TRANSITION_BLOCKED')
        };
        if($session['status']!==$before)throw new RuntimeException('ACS_SESSION_TRANSITION_BLOCKED');
        $obligations=$this->obligations($session['id']);
        if($operation!=='reopen' && $obligations['explicitly_blocked'])throw new RuntimeException('ACS_SESSION_OBLIGATIONS_BLOCKED');
        if($this->sql('UPDATE '.$this->table('sessions').' SET status=? WHERE id=? AND status=? AND session_version=?',[$after,$session['id'],$before,$session['session_version']])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
        $session['_command_result']=['status'=>$after,'previous_status'=>$before,'obligations_preserved'=>true,'outstanding_obligations'=>$obligations];return $session;
    }
    /** Internal command boundary; callers supply authorization, never client callbacks. */
    public function command(string $key, string $kind, array $request, callable $authorize, callable $apply, ?callable $afterEvent=null,?callable $existingResult=null,?string &$phase=null): array
    {
        $key=self::key($key);$kind=self::key($kind,64);$json=self::json($request);$hash=hash('sha256',$json);
        return $this->transactions->run(function () use($key,$kind,$request,$json,$hash,$authorize,$apply,$afterEvent,$existingResult,&$phase) {
            $this->writable();
            $old=$this->sql('SELECT * FROM '.$this->table('operations').' WHERE operation_key=? FOR UPDATE',[$key])['rows'][0]??null;
            $id=$old ? (string)$old['session_id'] : ($request['session_id']??null);
            $session=$id===null?null:$this->sessionRow($this->lockSession($id));
            if ($phase!==null) $phase='role_authorize';
            $authorize($session);
            if ($phase!==null) $phase='role_guard';
            if($old) {
                if($old['kind']!==$kind || !hash_equals($old['payload_hash'],$hash))throw new RuntimeException('ACS_OPERATION_KEY_CONFLICT');
                if($old['status']!=='committed')throw new RuntimeException('ACS_RECOVERY_REQUIRED');
                return json_decode($old['result_json'],true,512,JSON_THROW_ON_ERROR);
            }
            if ($kind === 'session.create') {
                $this->assertEpisodeCombat($request['tid']);
                if ($this->episodeMode($request['tid']) === null && function_exists('af_atf_acs_forum_enabled')) {
                    $thread = $this->sql('SELECT fid FROM `'.$this->prefix.'threads` WHERE tid=?', [$request['tid']])['rows'][0] ?? null;
                    if ($thread && af_atf_acs_forum_enabled((int)$thread['fid']) && !$this->hasTopicSession($request['tid'])
                        && !af_atf_acs_has_topic_intent((int)$request['tid'])) {
                        throw new RuntimeException('ACS_EPISODE_NORMAL');
                    }
                }
            }
            // Source-level replay before CAS/RNG. Only server code may supply this read callback.
            if($existingResult!==null && ($saved=$existingResult($session))!==null)return $saved;
            if ($session && !in_array($kind,['session.request_close','session.close','session.reopen','session.archive','delegation.revoke','encounter.side_change','encounter.relation_set','encounter.controller_set'],true)) { $this->assertNoPendingPublication((string)$session['id']); }
            $recent=$this->sql('SELECT COUNT(*) AS n FROM '.$this->table('operations').' WHERE operation_key LIKE ? AND updated_at>?',['session:'.$request['actor_uid'].':%',time()-60])['rows'][0]['n'];
            if((int)$recent>=20)throw new RuntimeException('ACS_RATE_LIMIT');
            if($session && (string)$session['session_version']!==$request['expected_version'])throw new RuntimeException('ACS_VERSION_CONFLICT');
            $this->inCommand=true;
            try {$session=$apply($session);} finally {$this->inCommand=false;}
            if ($phase!==null) $phase='role_audit';
            $id=(string)$session['id'];
            $version=self::integer($session['session_version'],'18446744073709551614');
            $sequence=self::integer($session['next_sequence'],'18446744073709551614');
            $this->sql('INSERT INTO '.$this->table('operations').' (operation_key,session_id,encounter_id,kind,payload_hash,status,operation_version,request_json,result_json,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',[$key,$id,$session['_command_result']['encounter_id']??null,$kind,$hash,'prepared',1,$json,self::json(['schema_version'=>1]),time()]);
            $event=$this->sql('INSERT INTO '.$this->table('events').' (session_id,session_sequence,encounter_id,event_sequence,operation_key,event_type,actor_uid,engine_version,created_at) VALUES (?,?,?,?,?,?,?,?,?)',[$id,$sequence,$session['_command_result']['encounter_id']??null,$session['_command_result']['encounter_sequence']??null,$key,$kind,$request['actor_uid'],str_starts_with($kind,'encounter.')?'acs-encounters-1':($kind==='entity.register'?'acs-registration-1':'acs-sessions-1'),time()])['id'];
            if($afterEvent!==null)$session=$afterEvent($session,$event);
            $details=self::json(['schema_version'=>1,'numeric_policy'=>'binary64-half_up-v1','request'=>$request,'before_version'=>$version,'result'=>$session['_command_result']??[],'snapshot_refs'=>isset($session['_command_result']['snapshot_id'])?[$session['_command_result']['snapshot_id']]:[]]);
            $visibility=self::json(['schema_version'=>1,'scope'=>str_starts_with($kind,'encounter.')?'encounter_management':'session_management']);
            $this->sql('INSERT INTO '.$this->table('event_details').' (event_id,schema_version,numeric_policy,payload_json,visibility_json,content_hash) VALUES (?,?,?,?,?,?)',[$event,1,'binary64-half_up-v1',$details,$visibility,hash('sha256',$details."\n".$visibility)]);
            if($this->sql('UPDATE '.$this->table('sessions').' SET session_version=session_version+1,next_sequence=next_sequence+1,updated_at=? WHERE id=? AND session_version=?',[time(),$id,$version])['affected']!==1)throw new RuntimeException('ACS_VERSION_CONFLICT');
            $result=['schema_version'=>1,'session_id'=>$id,'session_version'=>(string)$this->session($id)['session_version'],'event_id'=>$event]+($session['_command_result']??[]);
            $this->sql('UPDATE '.$this->table('operations').' SET status=?,operation_version=operation_version+1,result_json=? WHERE operation_key=?',['committed',self::json($result),$key]);
            if($kind==='encounter.ambush_start' && $this instanceof AF_ACS_EncounterRepository){
                $pending=$this->checkEndConditions($id,$result['encounter_id'],$event,$request['actor_uid']);
                if($pending){$result['pending_resolution']=$pending;$result['status']='pending_resolution';$result['session_version']=$this->session($id)['session_version'];$result['encounter_version']=$this->encounter($id,$result['encounter_id'])['encounter_version'];
                    $this->sql('UPDATE '.$this->table('operations').' SET result_json=? WHERE operation_key IN (?,?)',[self::json($result),$key,$request['start_operation_id']]);}
            }
            // Noncritical notification source, committed with its existing immutable command event.
            if(in_array($kind,['encounter.outcome_confirm','encounter.gm_skip','encounter.gm_forfeit','encounter.deadline_process','encounter.pause','encounter.resume','encounter.deadline_set','encounter.extend_deadline','session.close','session.archive','session.reopen','session.set_role','session.remove_member','session.transfer_owner','delegation.grant','delegation.revoke'],true)){
                if ($phase!==null) $phase='role_notification';
                $notice=self::json(['schema_version'=>1,'session_id'=>$id,'event_id'=>$event]);$noticeKey='alerts:event:'.$event;
                $this->sql('INSERT INTO '.$this->table('outbox').' (message_key,submission_id,kind,payload_hash,payload_json,status,available_at,created_at) VALUES (?,?,?,?,?,?,?,?)',[$noticeKey,$noticeKey,'alerts.source',hash('sha256',$notice),$notice,'pending',time(),time()]);
            }
            // Durable pointer only. Aggregation/consumer failure cannot undo the confirmed outcome.
            if(in_array($kind,['encounter.outcome_confirm','correction.review'],true)){
                $eid=$result['encounter_id'];$message=self::json(['schema_version'=>1,'session_id'=>$id,'encounter_id'=>$eid,'source_sequence'=>$sequence]);
                $this->sql('INSERT INTO '.$this->table('outbox').' (message_key,submission_id,kind,payload_hash,payload_json,status,available_at,created_at) VALUES (?,?,?,?,?,?,?,?)',
                    ['statistics:refresh:'.$event,'statistics:'.$event,'statistics.refresh',hash('sha256',$message),$message,'pending',time(),time()]);
            }
            if ($phase!==null) $phase='role_commit';
            return $result;
        });
    }
}

<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Draft-only persistence. Does not use command(), emit combat events or change session versions. */
trait AF_ACS_DraftPersistence
{
    public function draft(string $id,string $uid): ?array
    {
        $row=$this->sql('SELECT d.*,e.session_id FROM '.$this->table('drafts').' d JOIN '.$this->table('encounters').' e ON e.id=d.encounter_id WHERE d.id=? AND d.author_uid=?',[self::integer($id),$uid])['rows'][0]??null;
        if($row)foreach(['id','session_id','encounter_id','turn_id','author_uid','draft_version','updated_at'] as $f)$row[$f]=(string)$row[$f];
        return $row;
    }

    /** Shared ACS lock serializes CAS/create with mechanics. Receipt contains no prose/actions. */
    public function saveDraft(array $request,string $mechanics,string $prose,callable $authorize): array
    {
        $payload=self::json($request+['mechanics_json'=>$mechanics,'prose'=>$prose]);$hash=hash('sha256',$payload);
        $key='draft:'.$request['author_uid'].':'.self::key($request['operation_key'],96);
        return $this->transactions->run(function()use($request,$mechanics,$prose,$hash,$key,$authorize){
            $this->writable();$this->lockSession($request['session_id']);
            $dependencies=$authorize(); // Fresh native permissions, current turn and control even on replay.
            $old=$this->sql('SELECT kind,payload_hash,status,result_json FROM '.$this->table('operations').' WHERE operation_key=? FOR UPDATE',[$key])['rows'][0]??null;
            if($old){
                if($old['kind']!=='draft.save'||!hash_equals($old['payload_hash'],$hash))throw new RuntimeException('ACS_OPERATION_KEY_CONFLICT');
                if($old['status']!=='committed')throw new RuntimeException('ACS_RECOVERY_REQUIRED');
                return json_decode($old['result_json'],true,32,JSON_THROW_ON_ERROR);
            }
            $count=$this->sql('SELECT COUNT(*) AS n FROM '.$this->table('operations').' WHERE operation_key LIKE ? AND updated_at>?',['draft:'.$request['author_uid'].':%',time()-60])['rows'][0]['n'];
            if((int)$count>=60)throw new RuntimeException('ACS_RATE_LIMIT');
            $draftId=$request['draft_id'];$version=$request['expected_draft_version'];$now=time();
            if($draftId===null){
                if($version!=='0')throw new RuntimeException('ACS_DRAFT_VERSION_CONFLICT');
                if($this->sql('SELECT id FROM '.$this->table('drafts').' WHERE turn_id=? AND author_uid=? AND status=? LIMIT 1 FOR UPDATE',[$request['turn_id'],$request['author_uid'],'draft'])['rows'])throw new RuntimeException('ACS_DRAFT_ALREADY_EXISTS');
                $draftId=$this->sql('INSERT INTO '.$this->table('drafts').' (encounter_id,turn_id,author_uid,status,draft_version,mechanics_json,prose,dependency_json,confirmation_hash,pid,updated_at) VALUES (?,?,?,?,1,?,?,?,NULL,NULL,?)',[$request['encounter_id'],$request['turn_id'],$request['author_uid'],'draft',$mechanics,$prose,self::json($dependencies),$now])['id'];
            }else{
                $row=$this->draft($draftId,$request['author_uid']);
                if(!$row||$row['session_id']!==$request['session_id']||$row['encounter_id']!==$request['encounter_id']||$row['turn_id']!==$request['turn_id'])throw new RuntimeException('ACS_DRAFT_UNAVAILABLE');
                if($row['status']!=='draft'||$row['pid']!==null||$row['confirmation_hash']!==null)throw new RuntimeException('ACS_DRAFT_READ_ONLY');
                self::integer($version,'18446744073709551614');
                if($this->sql('UPDATE '.$this->table('drafts').' SET mechanics_json=?,prose=?,dependency_json=?,draft_version=draft_version+1,updated_at=? WHERE id=? AND author_uid=? AND draft_version=? AND status=?',[$mechanics,$prose,self::json($dependencies),$now,$draftId,$request['author_uid'],$version,'draft'])['affected']!==1)throw new RuntimeException('ACS_DRAFT_VERSION_CONFLICT');
            }
            $saved=$this->draft($draftId,$request['author_uid']);
            $result=['schema_version'=>1,'draft_id'=>$draftId,'draft_version'=>$saved['draft_version'],'session_id'=>$request['session_id'],'encounter_id'=>$request['encounter_id'],'turn_id'=>$request['turn_id'],'status'=>'draft','saved_at'=>$saved['updated_at']];
            $receipt=$request;unset($receipt['operation_key']);
            $this->sql('INSERT INTO '.$this->table('operations').' (operation_key,session_id,encounter_id,kind,payload_hash,status,operation_version,request_json,result_json,updated_at) VALUES (?,?,?,?,?,?,1,?,?,?)',[$key,$request['session_id'],$request['encounter_id'],'draft.save',$hash,'committed',self::json($receipt),self::json($result),$now]);
            return $result;
        });
    }
}

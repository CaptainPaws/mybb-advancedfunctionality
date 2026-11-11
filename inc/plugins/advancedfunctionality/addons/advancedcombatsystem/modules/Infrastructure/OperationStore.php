<?php

if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/Repository.php';

final class AF_ACS_OperationStore extends AF_ACS_Repository
{
    /** Register/lookup only. This API never executes an external side effect. */
    public function register(string $key, string $sessionId, string $kind, array $request): array
    {
        $key = self::key($key);
        $sessionId = self::integer($sessionId);
        $kind = self::key($kind, 64);
        $json = self::json($request);
        $hash = hash('sha256', $json);
        return $this->transactions->run(function () use ($key, $sessionId, $kind, $json, $hash) {
            $this->writable();
            $this->lockSession($sessionId);
            $old = $this->get($key);
            if ($old) {
                if ($old['payload_hash'] !== $hash || (string)$old['session_id'] !== $sessionId || $old['kind'] !== $kind) { throw new RuntimeException('ACS_OPERATION_KEY_CONFLICT'); }
                return $old;
            }
            $this->sql('INSERT INTO '.$this->table('operations').' (operation_key,session_id,kind,payload_hash,status,operation_version,request_json,result_json,updated_at) VALUES (?,?,?,?,?,?,?,?,?)', [$key, $sessionId, $kind, $hash, 'prepared', '1', $json, self::json(['schema_version' => 1]), time()]);
            return $this->get($key);
        });
    }

    public function get(string $key): ?array
    {
        $row=$this->sql('SELECT * FROM '.$this->table('operations').' WHERE operation_key=?', [self::key($key)])['rows'][0] ?? null;
        if ($row) foreach (['session_id','encounter_id','operation_version','retry_at','updated_at'] as $field) if ($row[$field]!==null) $row[$field]=(string)$row[$field];
        return $row;
    }

    /** CAS, terminal states cannot return to prepared or be applied twice. */
    public function transition(string $key, string $expectedVersion, string $status, array $result): array
    {
        $key = self::key($key);
        $version = self::integer($expectedVersion, '18446744073709551614');
        $json = self::json($result);
        return $this->transactions->run(function () use ($key, $version, $status, $json) {
            $this->writable();
            $row = $this->get($key);
            if (!$row) { throw new RuntimeException('ACS_OPERATION_NOT_FOUND'); }
            $this->lockSession((string)$row['session_id']);
            if (!in_array($row['status'], ['prepared', 'recovery_required'], true)
                || !in_array($status, ['committed', 'rejected', 'released', 'recovery_required'], true)
                || ($row['status'] === 'recovery_required' && $status === 'recovery_required')
                || $row['external_system'] !== null || $row['encounter_id'] !== null) { throw new RuntimeException('ACS_OPERATION_TRANSITION_DENIED'); }
            $updated = $this->sql('UPDATE '.$this->table('operations').' SET status=?,operation_version=operation_version+1,result_json=?,updated_at=? WHERE operation_key=? AND operation_version=?', [$status, $json, time(), $key, $version]);
            if ($updated['affected'] !== 1) { throw new RuntimeException('ACS_VERSION_CONFLICT'); }
            return $this->get($key);
        });
    }
    /** Durable intent for standalone owner transfer/grant only. No combat consume outside Writer. */
    public function inventoryRegister(string $sid,array $intent,callable $authorize): array
    {
        require_once __DIR__.'/../Domain/InventoryReceipt.php';
        $sid=self::integer($sid);$key=self::key($intent['operation_key']);$json=AF_ADVINV_OwnerProtocol::canonical($intent);$hash=hash('sha256',$json);
        return $this->transactions->run(function()use($sid,$key,$intent,$json,$hash,$authorize){
            $this->writable();$session=$this->lockSession($sid);$authorize($session);
            $old=$this->get($key);
            if ($old) {if ($old['session_id']!==$sid || $old['external_system']!=='advancedinventory' || $old['request_json']!==$json || !hash_equals($old['payload_hash'],$hash)) throw new RuntimeException('ACS_OPERATION_KEY_CONFLICT');return $old;}
            if ($session['status']!=='active') throw new RuntimeException('ACS_SESSION_TRANSITION_BLOCKED');
            $this->assertNoPendingPublication($sid);
            $this->sql('INSERT INTO '.$this->table('operations').' (operation_key,session_id,kind,external_system,external_ref,payload_hash,status,operation_version,request_json,result_json,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$key,$sid,'inventory.'.$intent['operation'],'advancedinventory',$key,$hash,'prepared',1,$json,self::json(['schema_version'=>1]),time()]);
            $this->inventoryAudit($session,$key,'prepared','1',$intent['actor']['uid'],['payload_hash'=>$intent['payload_hash']]);return $this->get($key);
        });
    }
    /** Before external dispatch persist ambiguity; never reclaim this phase for blind execution. */
    public function inventoryTransition(string $key,string $expected,string $status,array $result,callable $authorize): array
    {
        return $this->transactions->run(function()use($key,$expected,$status,$result,$authorize){
            $this->writable();$row=$this->get($key)??throw new RuntimeException('ACS_OPERATION_NOT_FOUND');
            $session=$this->lockSession($row['session_id']);$authorize($session);$row=$this->get($key);
            if ($row['external_system']!=='advancedinventory' || $row['external_ref']!==$key || !hash_equals($row['payload_hash'],hash('sha256',$row['request_json']))) throw new RuntimeException('ACS_INVENTORY_OPERATION_INTEGRITY');
            if ($row['operation_version']!==self::integer($expected,'9007199254740990')) throw new RuntimeException('ACS_VERSION_CONFLICT');
            if (!in_array($row['status'],['prepared','recovery_required'],true) || !in_array($status,['committed','rejected','released','recovery_required'],true) || $status==='recovery_required'&&$row['status']!=='prepared') throw new RuntimeException('ACS_OPERATION_TRANSITION_DENIED');
            if ($status!=='recovery_required') {
                require_once __DIR__.'/../Domain/InventoryReceipt.php';
                AF_ACS_InventoryReceipt::verify(json_decode($row['request_json'],true,64,JSON_THROW_ON_ERROR),$result['owner_receipt']??[]);
                if (AF_ACS_InventoryReceipt::status($result['owner_receipt'])!==$status) throw new RuntimeException('ACS_INVENTORY_OPERATION_INTEGRITY');
            }
            if ($this->sql('UPDATE '.$this->table('operations').' SET status=?,operation_version=operation_version+1,result_json=?,updated_at=? WHERE operation_key=? AND operation_version=?',[$status,self::json($result),time(),$key,$expected])['affected']!==1) throw new RuntimeException('ACS_VERSION_CONFLICT');
            $intent=json_decode($row['request_json'],true,64,JSON_THROW_ON_ERROR);if ($row['kind']!=='inventory.'.$intent['operation'] || $intent['operation_key']!==$key || AF_ADVINV_OwnerProtocol::canonical($intent['scope'])!==AF_ADVINV_OwnerProtocol::canonical(['system'=>'acs','context_key'=>$row['session_id']]) || !hash_equals($intent['payload_hash'],AF_ADVINV_OwnerProtocol::payloadHash($intent))) throw new RuntimeException('ACS_INVENTORY_OPERATION_INTEGRITY');$version=(string)((int)$expected+1);
            $this->inventoryAudit($session,$key,$status,$version,$intent['actor']['uid'],$result);return $this->get($key);
        });
    }
    private function inventoryAudit(array $session,string $key,string $status,string $version,int $uid,array $evidence): void
    {
        $eventKey='inventory:'.hash('sha256',$key.':'.$version);$seq=self::integer($session['next_sequence'],'18446744073709551614');
        $event=$this->sql('INSERT INTO '.$this->table('events').' (session_id,session_sequence,operation_key,event_type,actor_uid,engine_version,created_at) VALUES (?,?,?,?,?,?,?)',[$session['id'],$seq,$eventKey,'inventory.'.$status,$uid,'acs-inventory-recovery-1',time()])['id'];
        $payload=self::json(['schema_version'=>1,'operation_key'=>$key,'operation_version'=>$version,'status'=>$status,'evidence'=>$evidence]);$visibility=self::json(['schema_version'=>1,'scope'=>'session_management']);
        $this->sql('INSERT INTO '.$this->table('event_details').' (event_id,schema_version,numeric_policy,payload_json,visibility_json,content_hash) VALUES (?,?,?,?,?,?)',[$event,1,'binary64-half_up-v1',$payload,$visibility,hash('sha256',$payload."\n".$visibility)]);
        if ($this->sql('UPDATE '.$this->table('sessions').' SET session_version=session_version+1,next_sequence=next_sequence+1,updated_at=? WHERE id=? AND session_version=?',[time(),$session['id'],$session['session_version']])['affected']!==1) throw new RuntimeException('ACS_VERSION_CONFLICT');
    }

}

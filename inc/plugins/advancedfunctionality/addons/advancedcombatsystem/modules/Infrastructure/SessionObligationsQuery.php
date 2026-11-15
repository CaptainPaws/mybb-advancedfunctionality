<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/Repository.php';
require_once __DIR__.'/../Domain/Custody.php';
require_once __DIR__.'/../Domain/InventoryReceipt.php';

/** Future subsystems supply durable facts, not inferred rewards or client status.
 * A source must inspect the same writer while the caller owns the session lock.
 * Recovery scope is a server capability implemented by that subsystem, including archive access.
 */
interface AF_ACS_SessionObligationSource
{
    public function inspectSession(string $sid): array;
    public function recoveryScope(): ?string;
}
class AF_ACS_SessionObligationsQuery extends AF_ACS_Repository
{
    /** Code-only composition; never populated from config_json or HTTP. */
    protected function additionalSources(): array {return [];}
    public function inspect(string $sid): array
    {
        $sid=self::integer($sid);
        $result=['schema_version'=>1,'confirmed_completed'=>[],'pending'=>[],'recovery_required'=>[],'explicitly_blocked'=>[]];
        $add=static function(string $state,string $source,string $ref,?string $scope=null)use(&$result):void {
            $result[$state][]=['source'=>$source,'reference'=>$ref,'recovery_scope'=>$scope];
        };
        // Every source is bounded. Overflow blocks rather than silently omitting obligations.
        $scan=function(string $source,string $sql,callable $classify)use($sid,$add):void {
            $rows=$this->sql($sql,[$sid])['rows'];
            if(count($rows)>200){$add('explicitly_blocked',$source,'scan_limit');array_pop($rows);}
            foreach($rows as $row)$classify($row);
        };
        // Terminal history is counted, never truncated into a permanent close barrier.
        foreach(['encounter'=>['encounters','completed'],'custody'=>['custody','returned']] as $source=>[$table,$status]){
            $n=$this->sql('SELECT COUNT(*) AS n FROM '.$this->table($table).' WHERE session_id=? AND status=?',[$sid,$status])['rows'][0]['n'];
            if((int)$n>0)$add('confirmed_completed',$source,'records:'.$n);
        }
        $scan('encounter','SELECT id FROM '.$this->table('encounters')." WHERE session_id=? AND status NOT IN ('preparation','completed') LIMIT 201",static fn($r)=>$add('explicitly_blocked','encounter',(string)$r['id']));
        $scan('publication','SELECT submission_id FROM '.$this->table('submissions')." WHERE session_id=? AND status NOT IN ('committed','rejected') LIMIT 201",static fn($r)=>$add('explicitly_blocked','publication',$r['submission_id']));
        $scan('sealed_action','SELECT a.id FROM '.$this->table('sealed_actions').' a JOIN '.$this->table('drafts').' d ON d.id=a.draft_id JOIN '.$this->table('encounters')." e ON e.id=d.encounter_id WHERE e.session_id=? AND a.status='sealed' LIMIT 201",static fn($r)=>$add('explicitly_blocked','sealed_action',(string)$r['id']));
        $scan('prepared_execution','SELECT x.id FROM '.$this->table('prepared_executions').' x JOIN '.$this->table('prepared_actions')." p ON p.id=x.prepared_action_id WHERE p.session_id=? AND x.status='armed' LIMIT 201",static fn($r)=>$add('explicitly_blocked','prepared_execution',(string)$r['id']));
        $scan('custody','SELECT * FROM '.$this->table('custody')." WHERE session_id=? AND status<>'returned' ORDER BY id LIMIT 201",static function($r)use($add){
            try{AF_ACS_Custody::payload($r);if(!in_array($r['status'],['held','returned'],true))throw new RuntimeException('ACS_CUSTODY_INTEGRITY');}
            catch(Throwable){$add('explicitly_blocked','custody',(string)$r['id']);return;}
            $add($r['status']==='returned'?'confirmed_completed':'pending','custody',(string)$r['id'],'custody.return');
        });
        $scan('prepared_cost','SELECT id,status,cost_state,contract_hash,contract_json FROM '.$this->table('prepared_actions')." WHERE session_id=? AND (status='active' OR cost_state='recovery_required') LIMIT 201",static function($r)use($add){
            // Cancel paid preparations while active; only terminal unused reserves have recovery authority.
            $state=in_array($r['status'],['expired','cancelled'],true)&&hash_equals($r['contract_hash'],hash('sha256',$r['contract_json']))?'recovery_required':'explicitly_blocked';
            $add($state,'prepared_cost',(string)$r['id'],$state==='recovery_required'?'prepared.release':null);
        });
        $scan('operation','SELECT operation_key,kind,status,external_system,external_ref,request_json,payload_hash FROM '.$this->table('operations')." WHERE session_id=? AND status NOT IN ('committed','rejected','released') LIMIT 201",static function($r)use($add,$sid){
            $scope=$r['external_system']==='advancedinventory'?'inventory.lookup':($r['kind']==='prepared.release'?'prepared.release':null);
            if(!hash_equals($r['payload_hash'],hash('sha256',$r['request_json'])))$scope=null;
            if($scope==='inventory.lookup')try{
                $intent=json_decode($r['request_json'],true,64,JSON_THROW_ON_ERROR);
                if(($intent['scope']??null)!==['context_key'=>$sid,'system'=>'acs'] && ($intent['scope']??null)!==['system'=>'acs','context_key'=>$sid])throw new RuntimeException('ACS_INVENTORY_OPERATION_INTEGRITY');
                if(($intent['operation_key']??null)!==$r['operation_key']||$r['external_ref']!==$r['operation_key']||!hash_equals($intent['payload_hash']??'',AF_ADVINV_OwnerProtocol::payloadHash($intent)))throw new RuntimeException('ACS_INVENTORY_OPERATION_INTEGRITY');
            }catch(Throwable){$scope=null;}
            $add($scope===null?'explicitly_blocked':($r['status']==='recovery_required'?'recovery_required':'pending'),'operation',$r['operation_key'],$scope);
        });
        // A configured, confirmed source must be generated while active, including an empty result.
        $scan('loot_generation','SELECT e.id,e.config_json FROM '.$this->table('encounters').' e WHERE e.session_id=? AND e.status=\'completed\' AND JSON_LENGTH(JSON_EXTRACT(e.config_json,\'$.loot_sources\'))>(SELECT COUNT(*) FROM '.$this->table('loot_pools').' p WHERE p.encounter_id=e.id) LIMIT 201',function($r)use($add,$sid){
            $sources=json_decode($r['config_json'],true,64,JSON_THROW_ON_ERROR)['loot_sources']??[];
            foreach($sources as $identity=>$pin)if(!$this->sql('SELECT id FROM '.$this->table('loot_pools').' WHERE session_id=? AND encounter_id=? AND source_key=?',[$sid,$r['id'],$identity])['rows'])$add('explicitly_blocked','loot_generation',(string)$r['id'].':'.$identity);
        });
        $scan('loot_pool','SELECT id FROM '.$this->table('loot_pools')." WHERE session_id=? AND status='open' ORDER BY id LIMIT 201",static fn($r)=>$add('pending','loot_pool',(string)$r['id'],'loot.pool.inspect'));
        foreach($this->additionalSources() as $source){
            if(!$source instanceof AF_ACS_SessionObligationSource)throw new RuntimeException('ACS_OBLIGATION_SOURCE_INVALID');
            $rows=$source->inspectSession($sid);$scope=$source->recoveryScope();
            if(count($rows)>200)throw new RuntimeException('ACS_OBLIGATION_SOURCE_INVALID');
            foreach($rows as $r){
                if(array_keys($r)!==['state','source','reference']||!in_array($r['state'],['confirmed_completed','pending','recovery_required','explicitly_blocked'],true)||!is_string($r['source'])||!is_string($r['reference'])||strlen($r['reference'])>191)throw new RuntimeException('ACS_OBLIGATION_SOURCE_INVALID');
                $state=$r['state'];if(in_array($state,['pending','recovery_required'],true)&&($scope===null||$scope===''))$state='explicitly_blocked';
                $add($state,$r['source'],$r['reference'],$scope);
            }
        }
        $result['recovery_available_in']=['pending_close','closed','archived'];
        return $result;
    }
}

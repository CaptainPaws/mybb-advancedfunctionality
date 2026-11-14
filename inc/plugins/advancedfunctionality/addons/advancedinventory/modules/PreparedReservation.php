<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Existing reservation's owner-issued obligation. No new ledger, quantities or independent COMMIT. */
trait AF_ADVINV_PreparedReservation
{
    private ?array $preparedConsumeIntent=null;
    private ?array $preparedConsumeBinding=null;
    private function preparedStorage():void
    {
        $this->requireTransaction();
        if(!function_exists('af_is_addon_enabled')||!af_is_addon_enabled('advancedinventory'))throw new RuntimeException('INV_DEPENDENCY_UNAVAILABLE');
        $this->schema();
        foreach(['prepared_binding_json'=>'longtext','prepared_binding_hash'=>'char(64)'] as $name=>$type){
            $c=$this->one('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$this->prefix.'af_advinv_reservations',$name]);
            if(!$c||$c['COLUMN_TYPE']!==$type)throw new RuntimeException('INV_PREPARED_SCHEMA_NOT_READY');
        }
        if(!$this->access instanceof AF_ADVINV_PreparedOwnerPolicy)throw new RuntimeException('INV_PREPARED_POLICY_REQUIRED');
        $this->access->uid();
    }
    private static function preparedItemDigest(array $item):string
    {
        return hash('sha256',self::canonical(array_intersect_key($item,array_flip(['id','uid','entity','subtype','kb_type','kb_key','meta_json']))));
    }
    public function bindPreparedReservation(array $b):array
    {
        $this->preparedStorage();
        global $mybb;if(($mybb->request_method??'')!=='post'||!function_exists('verify_post_check')||!verify_post_check($mybb->get_input('my_post_key'),true))throw new RuntimeException('INV_CSRF_REQUIRED');
        self::fields($b,['schema','owner_uid','originating_uid','session_id','encounter_id','entity_id','prepared_action_id','source_sealed_action_id','cost_index','cost_key','qty','definition','ability_pin','item_id','item_fingerprint','inventory_version','reservation_id','reservation_version','reserve_receipt','obligation_hash']);
        if(!in_array($b['schema'],['prepared_reserved_consume.v1','prepared_reserved_start_consume.v1'],true)||$b['owner_uid']!==$this->access->uid()||$b['originating_uid']!==$b['owner_uid']||!is_int($b['cost_index'])||$b['cost_index']<0||$b['cost_index']>15)throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
        foreach(['owner_uid','originating_uid','item_id','qty','inventory_version','reservation_version'] as $k)self::positive($b[$k],self::MAX_VERSION);
        foreach(['session_id','encounter_id','entity_id','prepared_action_id','source_sealed_action_id','reservation_id'] as $k)if(!is_string($b[$k])||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$b[$k]))throw new RuntimeException('INV_PREPARED_BINDING_INTEGRITY');
        if($b['schema']==='prepared_reserved_start_consume.v1'){if(!$this->access instanceof AF_ADVINV_PreparedStartOwnerPolicy)throw new RuntimeException('INV_PREPARED_POLICY_REQUIRED');$this->access->authorizePreparedStartIssuance($b);}
        $this->access->authorize('consume',$b['owner_uid'],['system'=>'acs','context_key'=>$b['session_id']],'acs-item:'.$b['session_id'].':'.$b['entity_id'],true);
        if(!$this->one('SELECT uid FROM `'.$this->prefix.'users` WHERE uid=?',[$b['owner_uid']]))throw new RuntimeException('INV_OWNER_UNAVAILABLE');
        $owner=$this->one('SELECT inventory_version FROM '.$this->table('owner_state').' WHERE uid=? FOR UPDATE',[$b['owner_uid']]);
        $r=$this->one('SELECT * FROM '.$this->table('reservations').' WHERE id=? FOR UPDATE',[$b['reservation_id']]);
        if(!$owner||(int)$owner['inventory_version']!==$b['inventory_version']||!$r)throw new RuntimeException('INV_PREPARED_BINDING_INTEGRITY');
        $this->confirmed($r);$origin=$this->one('SELECT * FROM '.$this->table('external_operations').' WHERE operation_key=?',[$r['operation_key']]);
        if(self::canonical($this->receipt($origin))!==self::canonical($b['reserve_receipt'])||(int)$origin['actor_uid']!==$b['owner_uid']||(int)$r['owner_uid']!==$b['owner_uid']||(int)$r['item_id']!==$b['item_id']||(int)$r['remaining_qty']!==$b['qty']||(int)$r['reservation_version']!==$b['reservation_version']||$r['context_system']!=='acs'||$r['context_key']!==$b['session_id']||$r['status']!=='active')throw new RuntimeException('INV_PREPARED_BINDING_INTEGRITY');
        $item=$this->one('SELECT * FROM '.$this->table('items').' WHERE id=? FOR UPDATE',[$b['item_id']]);
        if(!$item||(int)$item['uid']!==$b['owner_uid']||$item['entity']!=='equipment'||$item['kb_type']!=='arpg_item'||!in_array($item['subtype'],['consumable','ammo'],true)||$item['kb_key']!==$b['definition']['key'])throw new RuntimeException('INV_PREPARED_BINDING_INTEGRITY');
        $b['grant_generation']=$this->access->preparedGrantGeneration($b);$b['owner_item_digest']=self::preparedItemDigest($item);
        $json=self::canonical($b);$hash=hash('sha256',$json);
        if(($r['prepared_binding_json']===null)!==($r['prepared_binding_hash']===null))throw new RuntimeException('INV_PREPARED_BINDING_INTEGRITY');
        if($r['prepared_binding_json']!==null&&($r['prepared_binding_json']!==$json||$r['prepared_binding_hash']!==$hash))throw new RuntimeException('INV_PREPARED_BINDING_INTEGRITY');
        if($r['prepared_binding_json']===null)$this->sql('UPDATE '.$this->table('reservations').' SET prepared_binding_json=?,prepared_binding_hash=? WHERE id=? AND prepared_binding_json IS NULL',[$json,$hash,$r['id']]);
        return ['schema'=>$b['schema'],'reservation_id'=>(string)$r['id'],'prepared_action_id'=>$b['prepared_action_id'],'cost_index'=>$b['cost_index'],'owner_uid'=>$b['owner_uid'],'originating_uid'=>$b['originating_uid'],'binding_hash'=>$hash,'grant_generation'=>$b['grant_generation'],'state'=>'active'];
    }
    private function preparedContext(array $intent,bool $history,array $priorReceipts=[]):array
    {
        $this->preparedStorage();$this->validate('consume',$intent);$this->identity($intent);
        $hint=$this->one('SELECT owner_uid FROM '.$this->table('reservations').' WHERE id=?',[$intent['payload']['reservation_id']]);
        if(!$hint)throw new RuntimeException('INV_PREPARED_BINDING_INTEGRITY');
        $owner=$this->one('SELECT inventory_version FROM '.$this->table('owner_state').' WHERE uid=? FOR UPDATE',[$hint['owner_uid']]);
        $r=$this->one('SELECT * FROM '.$this->table('reservations').' WHERE id=? FOR UPDATE',[$intent['payload']['reservation_id']]);
        if(!$owner||!$r||$r['prepared_binding_json']===null||!hash_equals((string)$r['prepared_binding_hash'],hash('sha256',$r['prepared_binding_json'])))throw new RuntimeException('INV_PREPARED_BINDING_INTEGRITY');
        $b=json_decode($r['prepared_binding_json'],true,24,JSON_THROW_ON_ERROR);$this->confirmed($r);
        if(!in_array($b['schema']??null,['prepared_reserved_consume.v1','prepared_reserved_start_consume.v1'],true)||$b['reservation_id']!==(string)$r['id']||$b['owner_uid']!==(int)$r['owner_uid']||$b['item_id']!==(int)$r['item_id']||$b['session_id']!==$r['context_key']||$r['context_system']!=='acs'||self::canonical($intent['scope'])!==self::canonical(['system'=>'acs','context_key'=>$b['session_id']])||self::canonical($intent['payload'])!==self::canonical(['reservation_id'=>$b['reservation_id'],'qty'=>$b['qty'],'expected_version'=>$b['reservation_version']]))throw new RuntimeException('INV_PREPARED_BINDING_INTEGRITY');
        $start=$b['schema']==='prepared_reserved_start_consume.v1';
        if(!preg_match('/\A'.($start?'acs-prepared-start':'acs-prepared').':([1-9][0-9]*):([1-9][0-9]*):([0-9]+)\z/D',$intent['actor']['authorization_ref'],$m)||$m[1]!==$b['prepared_action_id']||(int)$m[3]!==$b['cost_index']||$intent['operation_key']!=='acs:prepared:trigger-consume:'.$m[1].':'.$m[2].':'.$m[3])throw new RuntimeException('INV_PREPARED_BINDING_INTEGRITY');
        if($start){if(!$this->access instanceof AF_ADVINV_PreparedStartOwnerPolicy)throw new RuntimeException('INV_PREPARED_POLICY_REQUIRED');$this->access->authorizePreparedStartObligation($b,$intent,$history);}else $this->access->authorizePreparedObligation($b,$intent,$history);
        if(!$history){
            if(!hash_equals($b['grant_generation'],$this->access->preparedGrantGeneration($b)))throw new RuntimeException('INV_PREPARED_REVOKED');
            if($r['status']!=='active'||(int)$r['remaining_qty']!==$b['qty']||(int)$r['reservation_version']!==$b['reservation_version'])throw new RuntimeException('INV_PREPARED_BINDING_INTEGRITY');
            $expected=$b['inventory_version'];$seen=[];
            foreach($priorReceipts as $receipt){
                $key=$receipt['operation_key']??'';$prefix='acs:prepared:trigger-consume:'.$b['prepared_action_id'].':'.$m[2].':';
                $saved=$this->one('SELECT * FROM '.$this->table('external_operations').' WHERE operation_key=?',[$key]);
                if(!$saved||isset($seen[$key])||$key===$intent['operation_key']||!str_starts_with($key,$prefix)||(int)$saved['owner_uid']!==$b['owner_uid']||(int)$saved['actor_uid']!==$intent['actor']['uid']||$saved['operation_type']!=='consume'||$saved['status']!=='committed'||self::canonical($this->receipt($saved))!==self::canonical($receipt)||self::canonical($receipt['inventory_versions'])!==self::canonical([['uid'=>$b['owner_uid'],'before'=>$expected,'after'=>$expected+1]]))throw new RuntimeException('INV_PREPARED_OUTCOME_INTEGRITY');
                $seen[$key]=true;$expected++;
            }
            if((int)$owner['inventory_version']!==$expected)throw new RuntimeException('INV_PREPARED_VERSION_CONFLICT');
        }
        return [$b,$r,(int)$owner['inventory_version']];
    }
    /** Exact historical key only, including after revocation; never grants future spending. */
    public function lookupPreparedConsume(array $intent):array
    {
        $this->preparedContext($intent,true);
        $prior=$this->one('SELECT * FROM '.$this->table('external_operations').' WHERE operation_key=?',[$intent['operation_key']]);
        if(!$prior)return ['status'=>'not_found','operation_key'=>$intent['operation_key']];
        if((int)$prior['actor_uid']!==$intent['actor']['uid']||$prior['intent_json']!==self::canonical($intent))throw new RuntimeException('INV_PREPARED_OUTCOME_INTEGRITY');
        $this->samePrincipal($prior,$intent);return $this->receipt($prior);
    }
    public function preparedConsumptionEvidence(array $intent,array $priorReceipts=[]):array
    {
        [$b,$r,$version]=$this->preparedContext($intent,false,$priorReceipts);
        $item=$this->one('SELECT * FROM '.$this->table('items').' WHERE id=? FOR UPDATE',[$b['item_id']]);
        if(!$item||(int)$item['uid']!==$b['owner_uid']||!hash_equals($b['owner_item_digest'],self::preparedItemDigest($item)))throw new RuntimeException('INV_PREPARED_BINDING_INTEGRITY');
        return ['binding'=>$b,'binding_hash'=>$r['prepared_binding_hash'],'item'=>$item,'inventory_version'=>$version];
    }
    public function participatePreparedConsume(array $intent,array $priorReceipts=[]):AF_ADVINV_ProvisionalOwnerResult
    {
        if($this->preparedConsumeIntent!==null)throw new RuntimeException('INV_PREPARED_BINDING_INTEGRITY');
        $e=$this->preparedConsumptionEvidence($intent,$priorReceipts);$this->preparedConsumeIntent=$intent;$this->preparedConsumeBinding=$e['binding'];
        try{return $this->participate('consume',$intent);}finally{$this->preparedConsumeIntent=null;$this->preparedConsumeBinding=null;}
    }
    private function authorizePreparedConsumption(array $intent,int $owner,bool $mutation):void
    {
        $b=$this->preparedConsumeBinding;
        if(!$b||!$this->access instanceof AF_ADVINV_PreparedOwnerPolicy||$intent['operation']!=='consume'||!$mutation||$owner!==$b['owner_uid']||self::canonical($intent)!==self::canonical($this->preparedConsumeIntent))throw new RuntimeException('INV_PREPARED_BINDING_INTEGRITY');
        if($b['schema']==='prepared_reserved_start_consume.v1'){if(!$this->access instanceof AF_ADVINV_PreparedStartOwnerPolicy)throw new RuntimeException('INV_PREPARED_POLICY_REQUIRED');$this->access->authorizePreparedStartObligation($b,$intent,false);}else $this->access->authorizePreparedObligation($b,$intent,false);
        if(!hash_equals($b['grant_generation'],$this->access->preparedGrantGeneration($b)))throw new RuntimeException('INV_PREPARED_REVOKED');
    }

}

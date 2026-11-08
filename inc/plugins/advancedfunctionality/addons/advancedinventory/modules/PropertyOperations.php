<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/WriterBoundary.php';

/** Transfer and explicitly authorized KB grants. No loot allocation or custody. */
final class AF_ADVINV_PropertyOperations
{
    private AF_ADVINV_WriterBoundary $writer;
    public function __construct(private mysqli $link, private AF_ADVINV_OwnerAccess $access)
    {
        $this->writer=new AF_ADVINV_WriterBoundary($link);
    }
    public function execute(string $operation, array $r): array
    {
        if (!in_array($operation,['transfer','grant'],true)) throw new InvalidArgumentException('INV_INVALID_OPERATION');
        (new AF_ADVINV_OwnerProtocol($this->link,$this->access))->validateCommand($operation,$r);
        if (!function_exists('af_is_addon_enabled') || !af_is_addon_enabled('advancedinventory') || $this->access->uid()!==$r['actor']['uid']) throw new RuntimeException('INV_ACCESS_DENIED');
        $p=$r['payload'];$owner=$operation==='transfer'?$p['from_uid']:$p['recipient_uid'];
        $owners=$operation==='transfer'?[$owner,$p['to_uid']]:[$owner];
        $authorize=function () use ($operation,$owner,$r) {
            if ($this->access->uid()!==$r['actor']['uid']) throw new RuntimeException('INV_ACCESS_DENIED');
            $this->access->authorize($operation,$owner,['system'=>$r['scope']['system'],'context_key'=>$r['scope']['context_key']],$r['actor']['authorization_ref'],true);
        };
        $authorize();
        try {
            (new AF_ADVINV_OwnerProtocol($this->link,$this->access))->assertExternalBoundary();
            return $this->writer->run($owners,function () use ($r,$operation,$p,$owner,$owners,$authorize) {
                $w=$this->writer;$authorize();
                $prior=$w->rows('SELECT * FROM '.$w->table('external_operations').' WHERE operation_key=? FOR UPDATE',[$r['operation_key']])[0]??null;
                if ($prior) return $this->replay($prior,$r);
                $before=[];foreach (array_unique($owners) as $uid) $before[$uid]=$this->version($uid);
                $code='OK';foreach ($before as $v) if ($v>=9007199254740991) $code='VERSION_EXHAUSTED';
                $itemId=null;$destination=null;$qty=$p['qty']??$p['normalized_item']['qty'];
                foreach (array_unique($owners) as $uid) if (!$w->rows('SELECT uid FROM `'.TABLE_PREFIX.'users` WHERE uid=?',[$uid])) $code='OWNER_NOT_FOUND';
                if ($operation==='transfer') {
                    $itemId=$p['item_id'];
                    if ($p['from_uid']===$p['to_uid']) $code='SAME_OWNER';
                    elseif ($before[$owner]!==$p['expected_versions']['from'] || $before[$p['to_uid']]!==$p['expected_versions']['to']) $code='VERSION_CONFLICT';
                    if ($code==='OK') {
                        $item=$w->rows('SELECT * FROM '.$w->table('items').' WHERE id=? AND uid=?',[$itemId,$owner])[0]??null;
                        if (!$item) $code='ITEM_NOT_OWNED';
                        elseif ($item['entity']==='customization' || $item['kb_type']==='appearance' || str_starts_with((string)$item['kb_key'],'appearance:') || isset((json_decode((string)($item['meta_json']??''),true)??[])['appearance'])) $code='ITEM_KIND_UNSUPPORTED';
                        elseif ($qty>(int)$item['qty'] || $qty<1) $code='INSUFFICIENT_AVAILABLE';
                        else {
                            $target=$this->candidate($p['to_uid'],$item,false);$ids=[$itemId];if ($target) $ids[]=(int)$target['id'];sort($ids,SORT_NUMERIC);
                            foreach ($ids as $id) $w->rows('SELECT id FROM '.$w->table('items').' WHERE id=? FOR UPDATE',[$id]);
                        }
                        if ($code==='OK' && $w->rows('SELECT id FROM '.$w->table('reservations')." WHERE item_id=? AND status='active' ORDER BY id FOR UPDATE",[$itemId])) $code='ITEM_RESERVED';
                        if ($code==='OK') {
                            // Source/destination owners are already sorted/locked; no inverse owner acquisition.
                            $destination=$this->insertItem($p['to_uid'],$item,$qty);
                            if ($qty===(int)$item['qty']) {
                                foreach (['equipped'=>'equip_slot','support_slots'=>'slot_code'] as $binding=>$slot) {
                                    $w->rows('SELECT '.$slot.' FROM '.$w->table($binding).' WHERE uid=? AND item_id=? ORDER BY '.$slot.' FOR UPDATE',[$owner,$itemId]);
                                    $w->query('DELETE FROM '.$w->table($binding).' WHERE uid=? AND item_id=?',[$owner,$itemId]);
                                }
                                $w->query('DELETE FROM '.$w->table('items').' WHERE uid=? AND id=?',[$owner,$itemId]);$w->touch($owner);
                            }
                            else { $w->query('UPDATE '.$w->table('items').' SET qty=qty-?,updated_at=? WHERE id=? AND uid=?',[$qty,time(),$itemId,$owner]);$w->touch($owner); }
                        }
                    }
                } else {
                    $this->sourcesReady();
                    $source=$w->rows('SELECT * FROM '.$w->table('grant_sources').' WHERE source_system=? AND source_key=? FOR UPDATE',[$p['provenance']['system'],$p['provenance']['source_key']])[0]??null;
                    if ($source) $code='PROVENANCE_CONFLICT';
                    if ($code==='OK') {
                        $item=$this->definition($p['normalized_item']);
                        if ($item===null) $code='DEFINITION_CONFLICT';
                        else { $itemId=$this->insertItem($owner,$item,$qty);$destination=$itemId; }
                    }
                }
                $versions=[];foreach ($before as $uid=>$v) $versions[]=['uid'=>$uid,'before'=>$v,'after'=>$this->version($uid)];
                $result=['schema_version'=>1,'operation_key'=>$r['operation_key'],'operation_type'=>$operation,'payload_hash'=>$r['payload_hash'],'actor_uid'=>$r['actor']['uid'],'scope'=>$r['scope'],'status'=>$code==='OK'?'committed':'rejected','code'=>$code,'external_ref'=>$destination===null?null:(string)$destination,'item_id'=>$itemId,'destination_item_id'=>$destination,'qty'=>$qty,'version'=>$this->version($owner),'inventory_versions'=>$versions,'reservation'=>null];
                if ($operation==='grant') $result['provenance']=$p['provenance'];
                $result['result_hash']=hash('sha256',AF_ADVINV_OwnerProtocol::canonical($result));
                if ($operation==='grant' && $code==='OK') $this->saveSource($p['provenance']['system'],$p['provenance']['source_key'],$owner,$r['operation_key'],$r['payload_hash'],$result);
                $w->query('INSERT INTO '.$w->table('external_operations').' (operation_key,payload_hash,operation_type,actor_uid,context_system,context_key,owner_uid,item_id,status,external_ref,intent_json,result_json,result_hash,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$r['operation_key'],$r['payload_hash'],$operation,$r['actor']['uid'],$r['scope']['system'],$r['scope']['context_key'],$owner,$itemId,$result['status'],$result['external_ref'],AF_ADVINV_OwnerProtocol::canonical($r),AF_ADVINV_OwnerProtocol::canonical($result),$result['result_hash'],time(),time()]);
                return $result;
            });
        } catch (mysqli_sql_exception $e) {
            if ((int)$e->getCode()===1062) {
                $prior=$this->writer->rows('SELECT * FROM '.$this->writer->table('external_operations').' WHERE operation_key=?',[$r['operation_key']])[0]??null;
                if ($prior) return $this->replay($prior,$r);
                throw new RuntimeException('INV_PROVENANCE_CONFLICT');
            }
            return ['status'=>'recovery_required','operation_key'=>$r['operation_key'],'code'=>'INV_OUTCOME_UNKNOWN'];
        } catch (RuntimeException $e) {
            if ($e->getMessage()==='INV_WRITE_OUTCOME_UNKNOWN' || $e->getMessage()==='INV_DATABASE_ERROR') return ['status'=>'recovery_required','operation_key'=>$r['operation_key'],'code'=>'INV_OUTCOME_UNKNOWN'];
            throw $e;
        }
    }
    private function replay(array $row, array $r): array
    {
        if ((int)$row['actor_uid']!==$r['actor']['uid'] || $row['context_system']!==$r['scope']['system'] || $row['context_key']!==$r['scope']['context_key']) throw new RuntimeException('INV_ACCESS_DENIED');
        if ($row['intent_json']!==AF_ADVINV_OwnerProtocol::canonical($r) || !hash_equals($row['payload_hash'],$r['payload_hash'])) throw new RuntimeException('INV_IDEMPOTENCY_CONFLICT');
        // Read immutable receipt directly: core lookup deliberately refuses ambient tx.
        $result=json_decode($row['result_json'],true,32,JSON_THROW_ON_ERROR);$hash=$result['result_hash'];unset($result['result_hash']);
        if (!hash_equals($row['result_hash'],$hash) || !hash_equals($hash,hash('sha256',AF_ADVINV_OwnerProtocol::canonical($result))) || $result['operation_key']!==$row['operation_key'] || $result['payload_hash']!==$row['payload_hash'] || $result['status']!==$row['status'] || $result['actor_uid']!==(int)$row['actor_uid'] || $result['scope']!=$r['scope']) throw new RuntimeException('INV_RECEIPT_INTEGRITY');
        $result['result_hash']=$hash;return $result;
    }
    private function version(int $uid): int { return (int)$this->writer->rows('SELECT inventory_version FROM '.$this->writer->table('owner_state').' WHERE uid=?',[$uid])[0]['inventory_version']; }

    private function definition(array $normalized): ?array
    {
        $w=$this->writer;$ref=$normalized['definition_ref'];
        if ($ref['type']!=='arpg_item') return null;
        if (!af_is_addon_enabled('knowledgebase') || !function_exists('af_kb_get_normalized_item_profile')) throw new RuntimeException('INV_KB_UNAVAILABLE');
        $engine=$w->rows('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[TABLE_PREFIX.'af_kb_entries'])[0]['ENGINE']??null;
        if ($engine!=='InnoDB') throw new RuntimeException('INV_KB_NOT_TRANSACTIONAL');
        $row=$w->rows('SELECT * FROM `'.TABLE_PREFIX.'af_kb_entries` WHERE BINARY type=BINARY ? AND BINARY `key`=BINARY ? ORDER BY id LIMIT 2 FOR SHARE',[$ref['type'],$ref['key']]);
        if (count($row)!==1) return null;
        $entry=$row[0];$meta=(string)($entry['meta_json']??'');$hash=hash('sha256',$meta);
        if ($meta!==$normalized['snapshot_json'] || !hash_equals($ref['hash'],$hash) || $ref['version']!=='sha256:'.$hash) return null;
        $profile=af_kb_get_normalized_item_profile($entry,'inventory');
        if (empty($profile['supported']) || !empty($profile['skip']) || $normalized['entity']!=='equipment' || !in_array($profile['item_kind'],['consumable','ammo'],true) || $normalized['subtype']!==$profile['item_kind']) return null;
        return ['entity'=>'equipment','slot'=>'equipment','subtype'=>$profile['item_kind'],'kb_type'=>$ref['type'],'kb_key'=>$ref['key'],'meta_json'=>$meta,'title'=>$entry['title_ru']??$entry['title']??$ref['key'],'icon'=>$entry['icon']??''];
    }
    private function candidate(int $uid,array $item,bool $lock): ?array
    {
        $w=$this->writer;
        return $w->rows('SELECT i.* FROM '.$w->table('items')." i WHERE uid=? AND BINARY entity=BINARY ? AND BINARY subtype=BINARY ? AND BINARY kb_type=BINARY ? AND BINARY kb_key=BINARY ? AND BINARY COALESCE(meta_json,'')=BINARY ? AND NOT EXISTS (SELECT 1 FROM ".$w->table('reservations')." r WHERE r.item_id=i.id AND r.status='active') ORDER BY id LIMIT 1".($lock?' FOR UPDATE':''),[$uid,$item['entity'],$item['subtype'],$item['kb_type'],$item['kb_key'],(string)($item['meta_json']??'')])[0]??null;
    }
    private function insertItem(int $uid, array $item, int $qty): int
    {
        $w=$this->writer;
        $row=$this->candidate($uid,$item,true);
        if ($row) {
            if ((int)$row['qty']<1 || (int)$row['qty']>2147483647-$qty) throw new RuntimeException('INV_QUANTITY_OVERFLOW');
            $id=(int)$row['id'];$w->query('UPDATE '.$w->table('items').' SET qty=qty+?,updated_at=? WHERE id=?',[$qty,time(),$id]);
        } else {
            $w->query('INSERT INTO '.$w->table('items').' (uid,entity,slot,subtype,kb_type,kb_key,title,icon,qty,meta_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$uid,$item['entity'],$item['slot'],$item['subtype'],$item['kb_type'],$item['kb_key'],$item['title']??'',$item['icon']??'',$qty,(string)($item['meta_json']??''),time(),time()]);$id=$w->insertId();
        }
        $w->touch($uid);return $id;
    }
    public function sourcesReady(): void
    {
        $w=$this->writer;$engine=$w->rows('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[TABLE_PREFIX.'af_advinv_grant_sources'])[0]['ENGINE']??null;
        if ($engine!=='InnoDB') throw new RuntimeException('INV_GRANT_SCHEMA_NOT_READY');
        $pk=[];foreach ($w->rows('SHOW INDEX FROM '.$w->table('grant_sources')) as $r) if ($r['Key_name']==='PRIMARY' && (int)$r['Non_unique']===0) $pk[]=$r['Column_name'];
        if ($pk!==['source_system','source_key']) throw new RuntimeException('INV_GRANT_SCHEMA_NOT_READY');
    }
    public function saveSource(string $system,string $key,int $uid,string $operation,string $hash,array $result): void
    {
        $this->writer->held($uid);
        $this->writer->query('INSERT INTO '.$this->writer->table('grant_sources').' (source_system,source_key,recipient_uid,operation_key,payload_hash,result_json,result_hash) VALUES (?,?,?,?,?,?,?)',[$system,$key,$uid,$operation,$hash,AF_ADVINV_OwnerProtocol::canonical($result),$result['result_hash']]);
    }
}

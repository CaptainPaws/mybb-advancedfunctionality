<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/PropertyOperations.php';

/** Ordinary paid Shop delivery, distinct from an ACS/loot grant command. */
function af_advinv_deliver_shop_line(int $uid,int $orderId,int $line,array $item): array
{
    global $db,$mybb;
    if ((int)($mybb->user['uid']??0)!==$uid || !af_advancedshop_can_buy() || !af_advancedshop_can_view_shop() || !empty($mybb->usergroup['isbannedgroup']) || $orderId<1 || $line<0) throw new RuntimeException('INV_SHOP_DELIVERY_DENIED');
    $writer=af_advinv_writer();
    return $writer->run([$uid],function () use ($writer,$uid,$orderId,$line,$item,$db) {
        $order=$writer->rows('SELECT * FROM `'.TABLE_PREFIX.'af_shop_orders` WHERE order_id=? AND uid=? FOR UPDATE',[$orderId,$uid])[0]??null;
        if (!$order || $order['status']!=='paid') throw new RuntimeException('INV_SHOP_ORDER_NOT_PAID');
        $items=json_decode($order['items_json'],true,32,JSON_THROW_ON_ERROR);
        if (!isset($items[$line]) || AF_ADVINV_OwnerProtocol::canonical($items[$line])!==AF_ADVINV_OwnerProtocol::canonical($item)) throw new RuntimeException('INV_SHOP_LINE_CONFLICT');
        $source='order:'.$orderId.':line:'.$line;
        $operation='shop:delivery:'.$orderId.':'.$line;
        $hash=hash('sha256',AF_ADVINV_OwnerProtocol::canonical(['uid'=>$uid,'order_id'=>$orderId,'line'=>$line,'item'=>$item]));
        $property=new AF_ADVINV_PropertyOperations($db->write_link,new AF_ADVINV_MybbOwnerAccess());$property->sourcesReady();
        $prior=$writer->rows('SELECT * FROM '.$writer->table('grant_sources').' WHERE source_system=? AND source_key=? FOR UPDATE',['shop',$source])[0]??null;
        if ($prior) {
            if ($prior['operation_key']!==$operation || (int)$prior['recipient_uid']!==$uid || !hash_equals($prior['payload_hash'],$hash)) throw new RuntimeException('INV_SHOP_DELIVERY_CONFLICT');
            $result=json_decode($prior['result_json'],true,32,JSON_THROW_ON_ERROR);$rh=$result['result_hash'];unset($result['result_hash']);
            if (!hash_equals($prior['result_hash'],$rh) || !hash_equals($rh,hash('sha256',AF_ADVINV_OwnerProtocol::canonical($result))) || $result['operation_key']!==$operation || $result['payload_hash']!==$hash || $result['order_id']!==$orderId || $result['line']!==$line) throw new RuntimeException('INV_RECEIPT_INTEGRITY');
            $result['result_hash']=$rh;return $result;
        }
        $id=af_advancedshop_grant_inventory_item_locked($uid,$item,$orderId,$line);
        $result=['schema_version'=>1,'operation_key'=>$operation,'status'=>'committed','item_id'=>$id,'qty'=>af_advancedshop_normalize_kb_type((string)($item['kb_type']??''))==='arpg_talent'?1:(int)$item['qty'],'order_id'=>$orderId,'line'=>$line,'payload_hash'=>$hash];
        $result['result_hash']=hash('sha256',AF_ADVINV_OwnerProtocol::canonical($result));
        $property->saveSource('shop',$source,$uid,$operation,$hash,$result);return $result;
    });
}

/** Authoritative, read-only paid-delivery lookup; distinct from ACS operation lookup. */
function af_advinv_lookup_shop_delivery(string $operationKey): array
{
    global $db,$mybb;
    if (!preg_match('/\Ashop:delivery:([1-9][0-9]{0,9}):([0-9]{1,9})\z/D',$operationKey,$match)) throw new InvalidArgumentException('INV_INVALID_KEY');
    $uid=(int)($mybb->user['uid']??0);
    if ($uid<1 || !empty($mybb->usergroup['isbannedgroup']) || !af_advancedshop_can_view_shop()) throw new RuntimeException('INV_ACCESS_DENIED');
    try {
        (new AF_ADVINV_OwnerProtocol($db->write_link))->assertExternalBoundary();
        $w=af_advinv_writer();
        $order=$w->rows('SELECT uid,status FROM `'.TABLE_PREFIX.'af_shop_orders` WHERE order_id=?',[$match[1]])[0]??null;
        if (!$order || (int)$order['uid']!==$uid || $order['status']!=='paid') throw new RuntimeException('INV_ACCESS_DENIED');
        $row=$w->rows('SELECT * FROM '.$w->table('grant_sources').' WHERE operation_key=?',[$operationKey])[0]??null;
        if (!$row) return ['status'=>'not_found','operation_key'=>$operationKey];
        if ((int)$row['recipient_uid']!==$uid || $row['source_system']!=='shop') throw new RuntimeException('INV_ACCESS_DENIED');
        $result=json_decode($row['result_json'],true,32,JSON_THROW_ON_ERROR);$hash=$result['result_hash'];unset($result['result_hash']);
        if (!hash_equals($row['result_hash'],$hash) || !hash_equals($hash,hash('sha256',AF_ADVINV_OwnerProtocol::canonical($result))) || $result['operation_key']!==$operationKey) throw new RuntimeException('INV_RECEIPT_INTEGRITY');
        $result['result_hash']=$hash;return $result;
    } catch (mysqli_sql_exception|Error $e) { return ['status'=>'recovery_required','operation_key'=>$operationKey,'code'=>'INV_OUTCOME_UNKNOWN']; }
}

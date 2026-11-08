<?php
/** Disposable real MySQL only, same invocation as acs_inventory_owner_sql.php. */
define('ACS_INV_WRITER_LIBRARY',1);
require __DIR__.'/acs_inventory_writers_sql.php';
function propertyBootstrap(mysqli $native): void
{
    global $db,$plugins,$mybb;
    $db=new InventoryFixtureDatabase($native);
    $plugins=new class { public function add_hook(...$args) {} };
    $mybb=(object)['user'=>['uid'=>10,'usergroup'=>2],'usergroup'=>['canview'=>1], 'settings'=>['af_advancedinventory_debug_enabled'=>0,'af_advancedinventory_enabled'=>1,'af_advancedshop_enabled'=>1,'af_balance_tx_enable'=>0]];
    require_once AF_ADDONS.'advancedinventory/advancedinventory.php';
    require_once AF_ADDONS.'knowledgebase/knowledgebase.php';
    require_once AF_ADDONS.'advancedshop/advancedshop.php';
    require_once AF_ADDONS.'balance/balance.php';
}
if (($argv[1]??'')==='property-worker') {
    $native=connectFixture($argv[2]);propertyBootstrap($native);
    $r=json_decode(base64_decode($argv[3]),true,32,JSON_THROW_ON_ERROR);
    $owner=new AF_ADVINV_OwnerProtocol($native,new FixtureAccess($r['actor']['uid']));
    echo "ready\n";flush();echo json_encode($owner->{$r['operation']}($r),JSON_THROW_ON_ERROR)."\n";exit;
}
function propertyParallel(mysqli $lock,string $database,array $requests): array
{
    $lock->begin_transaction();$lock->query('SELECT uid FROM t_af_advinv_owner_state WHERE uid IN (10,11) ORDER BY uid FOR UPDATE');
    $workers=[];
    foreach ($requests as $r) {
        $proc=proc_open([PHP_BINARY,__FILE__,'property-worker',$database,base64_encode(json_encode($r,JSON_THROW_ON_ERROR))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);check(trim(fgets($pipes[1]))==='ready','property worker ready');$workers[]=[$proc,$pipes];
    }
    // One worker waits at the common SQL guard; the second may wait on the earlier sorted advisory lock.
    $until=microtime(true)+3;do { $n=(int)scalar($lock,'SELECT COUNT(*) FROM performance_schema.data_lock_waits');if ($n>=1) break;usleep(10000); } while(microtime(true)<$until);
    $lock->commit();check($n>=1,'property race reached actual SQL lock');$out=[];
    foreach ($workers as [$proc,$pipes]) { $s=stream_get_contents($pipes[1]);$e=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($proc)===0,'property worker '.$e);$out[]=json_decode(trim($s),true,32,JSON_THROW_ON_ERROR); }
    return $out;
}
function transferRequest(string $key,int $id,int $qty,int $from,int $to,int $vf,int $vt): array
{
    return request('transfer',$key,['item_id'=>$id,'qty'=>$qty,'from_uid'=>$from,'to_uid'=>$to,'expected_versions'=>['from'=>$vf,'to'=>$vt]],'s1',$from);
}
$database='acs613b_fixture_'.bin2hex(random_bytes(8));$admin=connectFixture();$admin->query('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4');$native=connectFixture($database);
try {
    $proposal=file_get_contents(dirname(__DIR__).'/docs/contracts/fixtures/ACS_INVENTORY_OWNER_PROPOSAL.sql');$items=substr($proposal,strpos($proposal,'CREATE TABLE af_advinv_items'));$items=substr($items,0,strpos($items,';'));
    $native->query(str_replace('CREATE TABLE af_advinv_items','CREATE TABLE t_af_advinv_items',$items));
    $native->query('CREATE TABLE t_users(uid INT UNSIGNED PRIMARY KEY) ENGINE=MyISAM');$native->query('INSERT INTO t_users VALUES (10),(11)');
    $native->query('CREATE TABLE t_af_advinv_equipped(uid INT UNSIGNED,equip_slot VARCHAR(64),item_id INT UNSIGNED,updated_at INT UNSIGNED,PRIMARY KEY(uid,equip_slot)) ENGINE=InnoDB');
    $native->query('CREATE TABLE t_af_advinv_support_slots(uid INT UNSIGNED,slot_code VARCHAR(64),item_id BIGINT UNSIGNED,sortorder INT DEFAULT 0,created_at BIGINT UNSIGNED DEFAULT 0,updated_at BIGINT UNSIGNED DEFAULT 0,PRIMARY KEY(uid,slot_code)) ENGINE=InnoDB');
    foreach (AF_ADVINV_OwnerSchema::statements(TABLE_PREFIX) as $sql) $native->query($sql);
    $native->query('INSERT INTO t_af_advinv_owner_state VALUES (10,1),(11,1)');
    $native->query("INSERT INTO t_af_advinv_items(id,uid,entity,subtype,kb_type,kb_key,qty,meta_json,created_at,updated_at) VALUES(21,10,'equipment','consumable','arpg_item','potion',3,'{}',1,1)");
    propertyBootstrap($native);
    $owner=new AF_ADVINV_OwnerProtocol($native,new FixtureAccess());
    $reserved=$owner->reserve(reserveRequest('property-reserve',21,1,1));
    $blocked=$owner->transfer(transferRequest('blocked',21,1,10,11,version($native),1));check($blocked['code']==='ITEM_RESERVED','reserved transfer denied');
    $owner->release(request('release','property-release',['reservation_id'=>$reserved['external_ref'],'reason'=>'test']));
    $tr=transferRequest('partial',21,1,10,11,version($native),1);$moved=$owner->transfer($tr);
    check($moved['status']==='committed' && scalar($native,'SELECT qty FROM t_af_advinv_items WHERE id=21')==='2','partial source decremented');
    check($owner->transfer($tr)==$moved && $owner->lookup(lookupRequest('partial'))==$moved,'transfer receipt replay/lookup');
    $race=propertyParallel($native,$database,[transferRequest('race-a',21,2,10,11,version($native),2),transferRequest('race-b',21,2,10,11,version($native),2)]);
    check(count(array_filter($race,fn($r)=>$r['status']==='committed'))===1 && scalar($native,'SELECT SUM(qty) FROM t_af_advinv_items')==='3','concurrent transfer conserves property');
    $recipient=(int)$moved['destination_item_id'];
    $native->query('CREATE TABLE t_af_kb_entries(id INT UNSIGNED PRIMARY KEY,type VARCHAR(32),`key` VARCHAR(64),meta_json MEDIUMTEXT,title_ru VARCHAR(255),title_en VARCHAR(255),icon VARCHAR(255)) ENGINE=InnoDB');
    $native->query('CREATE TABLE t_af_kb_types(id INT PRIMARY KEY,type VARCHAR(32),type_key VARCHAR(32),mechanic_key VARCHAR(32),active INT) ENGINE=InnoDB');$native->query("INSERT INTO t_af_kb_types VALUES(1,'arpg_item','arpg_item','arpg',1)");
    $meta=json_encode(['rules'=>['schema'=>'af_kb.arpg.rules.v1','item_kind'=>'consumable']],JSON_UNESCAPED_SLASHES);$escaped=$native->real_escape_string($meta);
    $native->query("INSERT INTO t_af_kb_entries VALUES (1,'arpg_item','potion','$escaped','Potion','Potion','')");
    $hash=hash('sha256',$meta);
    $grant=request('grant','grant-one',['recipient_uid'=>10,'normalized_item'=>['qty'=>2,'definition_ref'=>['type'=>'arpg_item','key'=>'potion','version'=>'sha256:'.$hash,'hash'=>$hash],'snapshot_json'=>$meta,'entity'=>'equipment','subtype'=>'consumable'],'provenance'=>['system'=>'acs','source_key'=>'approved-reward-1']]);
    $granted=$owner->grant($grant);check($granted['status']==='committed','verified KB grant '.json_encode($granted));
    $total=scalar($native,'SELECT SUM(qty) FROM t_af_advinv_items');
    check($owner->grant($grant)==$granted && scalar($native,'SELECT SUM(qty) FROM t_af_advinv_items')===$total,'duplicate grant');
    check($owner->lookup(lookupRequest('grant-one'))==$granted,'grant lookup');
    fails(fn()=>af_advinv_writer()->run([10],fn()=>$owner->grant($grant)),'INV_AMBIENT_TRANSACTION');
    $other=$grant;$other['operation_key']='acs:grant-other';$other['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($other);
    check($owner->grant($other)['code']==='PROVENANCE_CONFLICT','same provenance different key denied');
    $other=$grant;$other['payload']['normalized_item']['qty']=1;$other['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($other);fails(fn()=>$owner->grant($other),'INV_IDEMPOTENCY_CONFLICT');
    $parallelGrant=$grant;$parallelGrant['operation_key']='acs:parallel-grant';$parallelGrant['payload']['provenance']['source_key']='parallel-source';$parallelGrant['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($parallelGrant);
    $grants=propertyParallel($native,$database,[$parallelGrant,$parallelGrant]);check($grants[0]==$grants[1] && $grants[0]['status']==='committed','concurrent duplicate grant');
    $stale=$grant;$stale['operation_key']='acs:grant-stale';$stale['payload']['provenance']['source_key']='stale-source';$stale['payload']['normalized_item']['snapshot_json']='{}';$stale['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($stale);
    check($owner->grant($stale)['code']==='DEFINITION_CONFLICT','stale KB rejected');
    $faultDb=connectFixture($database,true);$faultOwner=new AF_ADVINV_OwnerProtocol($faultDb,new FixtureAccess());$lost=$grant;$lost['operation_key']='acs:lost-grant';$lost['payload']['provenance']['source_key']='lost-source';$lost['payload_hash']=AF_ADVINV_OwnerProtocol::payloadHash($lost);$faultDb->loseCommit=true;
    check($faultOwner->grant($lost)['status']==='recovery_required','unknown grant outcome');$known=$owner->lookup(lookupRequest('lost-grant'));check($known['status']==='committed' && $owner->grant($lost)==$known,'unknown grant lookup no duplicate');$faultDb->close();
    // Real Shop schema and actual checkout/Balance/grant functions, not a mocked delivery callback.
    $shopSource=file_get_contents(AF_ADDONS.'advancedshop/advancedshop.php');
    preg_match_all('/\$db->write_query\("CREATE TABLE " \. TABLE_PREFIX \. "(af_shop_(?:categories|slots|carts|cart_items|orders)) \((.*?)\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"\);/s',$shopSource,$matches,PREG_SET_ORDER);
    check(count($matches)===5,'real shop DDL');foreach ($matches as $match) $native->query('CREATE TABLE t_'.$match[1].' ('.$match[2].') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $native->query('CREATE TABLE t_af_shop_shops(shop_id INT AUTO_INCREMENT PRIMARY KEY,code VARCHAR(64) UNIQUE,title_ru VARCHAR(255) DEFAULT \'\',title_en VARCHAR(255) DEFAULT \'\',settings_json TEXT,enabled INT DEFAULT 1) ENGINE=InnoDB');
    $native->query("INSERT INTO t_af_shop_shops(code,settings_json) VALUES ('equipment','{}')");
    $native->query('ALTER TABLE t_af_shop_carts ADD checkout_key CHAR(64) NULL');$native->query('ALTER TABLE t_af_shop_orders ADD checkout_key CHAR(64) NULL, ADD UNIQUE KEY owner_checkout(uid,checkout_key)');
    $native->query('CREATE TABLE t_af_balance(uid INT PRIMARY KEY,exp BIGINT DEFAULT 0,credits BIGINT DEFAULT 0,ability_tokens BIGINT DEFAULT 0,updated_at INT) ENGINE=InnoDB');$native->query('INSERT INTO t_af_balance VALUES(10,0,10000,0,1)');
    $db->insert_query('af_shop_categories',['shop_id'=>1,'parent_id'=>0,'title'=>'Items','description'=>'','enabled'=>1]);
    $slot=$db->insert_query('af_shop_slots',['shop_id'=>1,'cat_id'=>1,'source_type'=>'kb','source_ref_id'=>1,'kb_id'=>1,'kb_type'=>'arpg_item','kb_key'=>'potion','price'=>100,'currency'=>'credits','stock'=>-1,'limit_per_user'=>0,'enabled'=>1,'meta_json'=>'{}']);
    $cart=af_advancedshop_get_or_create_cart(1,10);$db->insert_query('af_shop_cart_items',['cart_id'=>$cart['cart_id'],'slot_id'=>$slot,'qty'=>1]);$key=af_advancedshop_cart_key((int)$cart['cart_id']);
    $id=(int)$granted['item_id'];$reserve=$owner->reserve(reserveRequest('shop-existing', $id,1,version($native)));$qtyBefore=scalar($native,'SELECT qty FROM t_af_advinv_items WHERE id='.$id);
    $shop=$db->fetch_array($db->simple_select('af_shop_shops','*','shop_id=1'));$order=af_advancedshop_checkout_order(10,$shop,$key);
    check($order['status']==='paid' && af_shop_get_balance(10,'credits')===9900,'ordinary checkout with active reservation');
    check(scalar($native,'SELECT qty FROM t_af_advinv_items WHERE id='.$id)===$qtyBefore,'checkout preserves reserved stack');
    $total=scalar($native,'SELECT SUM(qty) FROM t_af_advinv_items');
    check(af_advancedshop_checkout_order(10,$shop,$key)['order_id']==$order['order_id'] && scalar($native,'SELECT SUM(qty) FROM t_af_advinv_items')===$total && af_shop_get_balance(10,'credits')===9900,'checkout retry idempotent');
    $line=json_decode($order['items_json'],true)[0];af_advancedshop_grant_inventory_item(10,$line,(int)$order['order_id'],0);check(scalar($native,'SELECT SUM(qty) FROM t_af_advinv_items')===$total,'direct paid delivery duplicate');
    check(scalar($native,"SELECT COUNT(*) FROM t_af_advinv_grant_sources WHERE source_system='shop'")==='1','separate Shop delivery receipt');
    $delivery=af_advinv_lookup_shop_delivery('shop:delivery:'.$order['order_id'].':0');check($delivery['status']==='committed','Shop source lookup');
    $shopStack=(int)$delivery['item_id'];$shopReserve=$owner->reserve(reserveRequest('purchased-stack',$shopStack,1,version($native)));$shopQty=scalar($native,'SELECT qty FROM t_af_advinv_items WHERE id='.$shopStack);
    $cart=af_advancedshop_get_or_create_cart(1,10);$db->insert_query('af_shop_cart_items',['cart_id'=>$cart['cart_id'],'slot_id'=>$slot,'qty'=>1]);$key2=af_advancedshop_cart_key((int)$cart['cart_id']);
    $beforeQty=scalar($native,'SELECT SUM(qty) FROM t_af_advinv_items');$beforeMoney=af_shop_get_balance(10,'credits');$beforeOrders=scalar($native,'SELECT COUNT(*) FROM t_af_shop_orders');
    $native->query("CREATE TRIGGER deny_delivery BEFORE INSERT ON t_af_advinv_grant_sources FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture delivery failure'");
    try { af_advancedshop_checkout_order(10,$shop,$key2);throw new RuntimeException('FAIL: late Shop fault accepted'); } catch (mysqli_sql_exception $e) {}
    $native->query('DROP TRIGGER deny_delivery');
    check(scalar($native,'SELECT SUM(qty) FROM t_af_advinv_items')===$beforeQty && af_shop_get_balance(10,'credits')===$beforeMoney && scalar($native,'SELECT COUNT(*) FROM t_af_shop_orders')===$beforeOrders,'late Shop rollback includes currency/order/items');
    $faultDb=connectFixture($database,true);propertyBootstrap($faultDb);$faultDb->loseCommit=true;
    fails(fn()=>af_advancedshop_checkout_order(10,$shop,$key2),'INV_WRITE_OUTCOME_UNKNOWN');
    propertyBootstrap($native);$confirmed=af_advancedshop_checkout_order(10,$shop,$key2);
    check(af_shop_get_balance(10,'credits')===$beforeMoney-100 && (int)scalar($native,'SELECT SUM(qty) FROM t_af_advinv_items')===(int)$beforeQty+1,'lost checkout ack no double payment/grant');
    check(af_advinv_lookup_shop_delivery('shop:delivery:'.$confirmed['order_id'].':0')['status']==='committed','lost checkout delivery lookup');$faultDb->close();
    check(scalar($native,'SELECT qty FROM t_af_advinv_items WHERE id='.$shopStack)===$shopQty,'repeat purchase cannot merge into reserved Shop stack');
    $owner->release(request('release','sell-after-release',['reservation_id'=>$shopReserve['external_ref'],'reason'=>'fixture release']));
    $money=af_shop_get_balance(10,'credits');$sale=af_advinv_writer()->sell(10,$shopStack,1);check($sale['sold_minor']===80 && af_shop_get_balance(10,'credits')===$money+80,'ordinary sale after release');
    $policy=new AF_ADVINV_MybbOwnerAccess();fails(fn()=>$policy->authorize('grant',10,['system'=>'inventory','context_key'=>'owner:10'],'inventory-owner-v1',true),'INV_GRANT_NOT_AUTHORIZED');
    echo "PASS: reserved/partial/concurrent transfer, duplicate/provenance/KB grant, lost owner outcome lookup, real Shop checkout with active reservation and paid delivery dedupe; MySQL ".$native->server_info."\n";
} finally { $native->close();$admin->query('DROP DATABASE `'.$database.'`');$admin->close(); }

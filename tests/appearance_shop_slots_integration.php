<?php
/** Isolated appearance_test database only; actual Shop/Appearance/Balance services on MySQL. */
define('AA_SHOP_FIXTURE',true);
require __DIR__.'/appearance_personal_integration.php';
require AF_ADDONS.'advancedshop/advancedshop.php';
require AF_ADDONS.'balance/balance.php';
$mybb->settings+=['af_advancedshop_enabled'=>1,'af_advancedshop_allow_guest_view'=>1,'af_balance_tx_enable'=>1,
    'af_advancedinventory_enabled'=>0,'af_advancedshop_manage_groups'=>'4'];
function slot_check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function slot_refuses(callable $fn){try{$fn();}catch(Throwable $e){return;}throw new RuntimeException('Expected refusal');}
function slot_cart(int $uid,int $qty=1): array {
    global $db,$mybb,$shop,$product;
    $mybb->user['uid']=$uid;
    $cart=af_advancedshop_get_or_create_cart((int)$shop['shop_id'],$uid);
    $db->delete_query('af_shop_cart_items','cart_id='.(int)$cart['cart_id']);
    $db->insert_query('af_shop_cart_items',['cart_id'=>$cart['cart_id'],'slot_id'=>$product,'qty'=>$qty]);
    af_advancedshop_cart_changed((int)$cart['cart_id']);
    $cart['checkout_key']=af_advancedshop_cart_key((int)$cart['cart_id']);return $cart;
}
if (($argv[1]??'')==='--csrf') {
    $mybb->request_method='post';$_POST=[];af_advancedshop_assert_post_key();exit;
}
if (($argv[1]??'')==='--race') {
    $mybb->user['uid']=20;
    $shop=$db->fetch_array($db->simple_select('af_shop_shops','*',"code='appearance_slots'"));
    $key=$argv[2];$order=af_advancedshop_checkout_order(20,$shop,$key);echo $order['order_id'];exit;
}
// This fixture's database name and table prefix are fixed in the shared bootstrap.
foreach(['af_shop_orders','af_shop_cart_items','af_shop_carts','af_shop_slots','af_shop_categories','af_shop_shops',
    'af_aa_slot_grants','af_aa_presets','af_aa_assignments','af_balance','af_balance_tx','af_kb_entries'] as $table)
    $db->write_query('DROP TABLE IF EXISTS '.TABLE_PREFIX.$table);
$db->write_query('CREATE TABLE IF NOT EXISTS '.TABLE_PREFIX.'settings (sid INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(191),value TEXT) ENGINE=InnoDB');
af_aa_ensure_schema();af_aa_personal_install_schema();
$source=file_get_contents(AF_ADDONS.'advancedshop/advancedshop.php');
preg_match_all('/\$db->write_query\("CREATE TABLE " \. TABLE_PREFIX \. "(af_shop_(?:categories|slots|carts|cart_items|orders)) \((.*?)\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"\);/s',$source,$matches,PREG_SET_ORDER);
slot_check(count($matches)===5,'Shop install DDL changed; update fixture extraction.');
foreach($matches as $match)$db->write_query('CREATE TABLE '.TABLE_PREFIX.$match[1].' ('.$match[2].') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
af_advancedshop_ensure_shops_schema();af_advancedshop_slot_install_schema();af_advancedshop_slot_install_schema();
slot_check(appearance_count('af_shop_slots',"source_type='appearance_slot'")===1,'Upgrade duplicated products.');
slot_check(appearance_count('af_shop_slots','enabled=1')===0,'Upgrade invented a live/free price.');
$db->write_query('CREATE TABLE '.TABLE_PREFIX.'af_balance (uid INT PRIMARY KEY,exp BIGINT DEFAULT 0,credits BIGINT DEFAULT 0,ability_tokens BIGINT DEFAULT 0,updated_at INT DEFAULT 0) ENGINE=InnoDB');
$db->write_query('CREATE TABLE '.TABLE_PREFIX.'af_balance_tx (id INT AUTO_INCREMENT PRIMARY KEY,uid INT,kind VARCHAR(32),amount BIGINT,balance_after BIGINT,reason VARCHAR(64),source VARCHAR(64),ref_type VARCHAR(32),ref_id INT,meta_json TEXT,actor_uid INT,created_at INT) ENGINE=InnoDB');
$db->write_query('CREATE TABLE '.TABLE_PREFIX.'af_kb_entries (id INT PRIMARY KEY,type VARCHAR(32),`key` VARCHAR(128),title_ru TEXT,title_en TEXT) ENGINE=InnoDB');
$shop=$db->fetch_array($db->simple_select('af_shop_shops','*',"code='appearance_slots'"));
$product=(int)$db->fetch_field($db->simple_select('af_shop_slots','slot_id',"source_type='appearance_slot'"),'slot_id');
$db->update_query('af_shop_slots',['price'=>2500,'enabled'=>1],'slot_id='.$product);
foreach([7,8,20] as $uid) $db->insert_query('af_balance',['uid'=>$uid,'credits'=>100000]);
$cart=slot_cart(7);
slot_check(af_aa_personal_slots(7,'postbit')['capacity']===1,'Free slot lost.');
$order=af_advancedshop_checkout_order(7,$shop,$cart['checkout_key']);$orderId=(int)$order['order_id'];
slot_check(af_aa_personal_slots(7,'postbit')['capacity']===2,'Purchase did not increase capacity.');
slot_check(af_shop_get_balance(7,'credits')===97500,'Balance not charged exactly once.');
slot_check(appearance_count('af_balance_tx',"uid=7 AND reason='shop_purchase'")===1,'No purchase ledger.');
slot_check(appearance_count('af_aa_presets','owner_uid=7')===0,'Purchase created empty preset.');
slot_check(appearance_count('af_aa_assignments','entity_id=7')===0,'Purchase changed assignment.');
slot_check(str_contains(af_advancedshop_slot_product_url('postbit'),'appearance-slot-'.$product),'Workshop product URL missing.');
slot_check(af_aa_personal_list(7,'postbit')['slots']['available']===2,'Workshop capacity stale.');
$again=af_advancedshop_checkout_order(7,$shop,$cart['checkout_key']);af_advancedshop_replay_slot_order($orderId);
slot_check((int)$again['order_id']===$orderId && appearance_count('af_aa_slot_grants')===1 && af_shop_get_balance(7,'credits')===97500,'Retry duplicated purchase/grant.');
$mybb->user['uid']=8;slot_refuses(fn()=>af_advancedshop_replay_slot_order($orderId));
slot_refuses(fn()=>af_advancedshop_grant_inventory_item(8,['source_type'=>'appearance_slot'],0));
slot_refuses(fn()=>af_advancedshop_slot_product(2));
$mybb->user['uid']=7;slot_check(af_aa_personal_slots(7,'profile')['purchased']===0,'Targets share capacity.');
$mybb->settings['af_advancedappearance_max_slots_postbit']=2;$cart=slot_cart(7);
slot_refuses(fn()=>af_advancedshop_checkout_order(7,$shop,$cart['checkout_key']));
slot_check(af_shop_get_balance(7,'credits')===97500 && appearance_count('af_shop_orders')===1,'Cap refusal charged money.');
$mybb->settings['af_advancedappearance_max_slots_postbit']=20;$cart=slot_cart(8);
$db->update_query('af_balance',['credits'=>0],'uid=8');slot_refuses(fn()=>af_advancedshop_checkout_order(8,$shop,$cart['checkout_key']));
slot_check(appearance_count('af_aa_slot_grants','uid=8')===0,'Insufficient balance granted slots.');
// An exception AFTER balance debit must roll back money, ledger, order and grant together.
$db->update_query('af_balance',['credits'=>100000],'uid=8');$cart=slot_cart(8);
$db->write_query('CREATE TRIGGER '.TABLE_PREFIX."deny_slot BEFORE INSERT ON ".TABLE_PREFIX."af_aa_slot_grants FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture grant failure'");
slot_refuses(fn()=>af_advancedshop_checkout_order(8,$shop,$cart['checkout_key']));
$db->write_query('DROP TRIGGER '.TABLE_PREFIX.'deny_slot');
slot_check(af_shop_get_balance(8,'credits')===100000 && appearance_count('af_balance_tx','uid=8')===0 && appearance_count('af_shop_orders','uid=8')===0,'Failed grant did not roll back money/order.');
// A stale cart revision, disabled product, malformed order and failed post key must not grant.
$cart=slot_cart(7);$oldKey=$cart['checkout_key'];af_advancedshop_cart_changed((int)$cart['cart_id']);
slot_refuses(fn()=>af_advancedshop_checkout_order(7,$shop,$oldKey));
$db->update_query('af_shop_slots',['enabled'=>0],'slot_id='.$product);
slot_refuses(fn()=>af_advancedshop_checkout_order(7,$shop));$db->update_query('af_shop_slots',['enabled'=>1],'slot_id='.$product);
slot_check(appearance_count('af_shop_orders')===1,'Failed purchase created paid order.');
$csrf=shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --csrf');
slot_check(str_contains($csrf,'Invalid post key'),'Direct HTTP could bypass CSRF.');
// Real two-process checkout race against the same server-issued revision.
$cart=slot_cart(20);$children=[];
for($i=0;$i<2;$i++){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'--race',$cart['checkout_key']],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$proc,$pipes];}
$ids=[];foreach($children as [$proc,$pipes]){$ids[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);slot_check(proc_close($proc)===0,'Race worker failed: '.$err);}
slot_check($ids[0]===$ids[1] && appearance_count('af_shop_orders','uid=20')===1 && af_shop_get_balance(20,'credits')===97500,'Concurrent checkout duplicated order.');
slot_check(appearance_count('af_aa_slot_grants','uid=20')===1,'Concurrent grant duplicated capacity.');
// Revocation preserves over-limit presets and published assignments, blocks new creations.
$mybb->user['uid']=7;$first=af_aa_personal_create(7,'postbit','One','{}');$second=af_aa_personal_create(7,'postbit','Two','{}');
af_aa_personal_activate(7,'postbit',$second);
$mybb->user['usergroup']=4;$mybb->usergroup['cancp']=1;
af_advancedshop_refund_slot_order($orderId);af_advancedshop_refund_slot_order($orderId);
slot_check(af_shop_get_balance(7,'credits')===100000,'Refund missing or duplicated.');
slot_check(af_aa_personal_slots(7,'postbit')['capacity']===1 && af_aa_personal_slots(7,'postbit')['used']===2,'Refund removed presets or retained capacity.');
slot_check(af_aa_personal_list(7,'postbit')['active_selection']['id']===$second,'Refund reset active preset.');
slot_refuses(fn()=>af_aa_personal_create(7,'postbit','Forbidden','{}'));
slot_refuses(fn()=>af_advancedshop_replay_slot_order($orderId));
slot_check(af_advancedshop_normalize_source_type('appearance')==='appearance' && af_advancedshop_normalize_source_type('kb')==='kb','Legacy product types changed.');
slot_check(af_advancedshop_checkout_item_destination(['source_type'=>'appearance_slot'])==='appearance','Slot routed to inventory.');
echo "Shop slots: purchase, replay, concurrency, quota, CSRF, ownership, refusal and refund checks passed.\n";

<?php
/** Permanent Appearance capacity uses the existing Shop order/Balance transaction. */
if (!defined('IN_MYBB')) die('No direct access');

function af_advancedshop_slot_target(int $ref): string
{
    return [1=>'postbit',2=>'profile',3=>'sheet',4=>'theme'][$ref]??'';
}

function af_advancedshop_slot_provider(): void
{
    if (!function_exists('af_aa_slot_assert_purchase') || !af_aa_is_enabled() || !af_aa_personal_schema_available())
        throw new RuntimeException('Активируйте/обновите AdvancedAppearance.');
}

function af_advancedshop_slot_product(int $ref, bool $selling=true): array
{
    af_advancedshop_slot_provider();
    $target=af_advancedshop_slot_target($ref);
    $product=af_aa_slot_product_registry()[$target]??null;
    if (!$product) throw new RuntimeException('Unknown appearance slot target.');
    if ($selling) af_aa_personal_target($target);
    return $product;
}

/** Additive upgrade only. Old orders keep NULL keys and are never backfilled/replayed. */
function af_advancedshop_slot_install_schema(): void
{
    global $db;
    foreach (['af_shop_carts','af_shop_orders'] as $table) {
        if (!$db->table_exists($table)) continue;
        if (!$db->field_exists('checkout_key',$table)) $db->write_query('ALTER TABLE '.TABLE_PREFIX.$table.' ADD checkout_key CHAR(64) NULL');
    }
    if ($db->table_exists('af_shop_orders')) {
        $q=$db->write_query("SHOW INDEX FROM ".TABLE_PREFIX."af_shop_orders WHERE Key_name='owner_checkout'");
        if (!$db->fetch_array($q)) $db->write_query('ALTER TABLE '.TABLE_PREFIX.'af_shop_orders ADD UNIQUE KEY owner_checkout (uid,checkout_key)');
    }
    // Checkout rollback cannot protect historical MyISAM shop tables.
    foreach (['af_shop_carts','af_shop_cart_items','af_shop_orders','af_shop_slots'] as $table) {
        if (!$db->table_exists($table)) continue;
        $row=$db->fetch_array($db->write_query("SHOW TABLE STATUS WHERE Name='".$db->escape_string(TABLE_PREFIX.$table)."'"));
        if ($row && strcasecmp($row['Engine'],'InnoDB')!==0) $db->write_query('ALTER TABLE '.TABLE_PREFIX.$table.' ENGINE=InnoDB');
    }
    af_advancedshop_seed_slot_products();
}

function af_advancedshop_seed_slot_products(): void
{
    global $db;
    if (!$db->table_exists('af_shop_slots') || !$db->table_exists('af_shop_categories')) return;
    $table=af_advancedshop_shops_table();
    // A separate stable shop code avoids imposing categories on existing stores.
    $db->write_query('INSERT IGNORE INTO '.TABLE_PREFIX.$table." (code,title_ru,title_en,settings_json) VALUES ('appearance_slots','Слоты оформления','Appearance slots','{}')");
    $shop=(int)$db->fetch_field($db->simple_select($table,'shop_id',"code='appearance_slots'"),'shop_id');
    $category=(int)$db->fetch_field($db->simple_select('af_shop_categories','cat_id',"shop_id={$shop}",['order_by'=>'cat_id','limit'=>1]),'cat_id');
    if (!$category) $category=(int)$db->insert_query('af_shop_categories',['shop_id'=>$shop,'parent_id'=>0,'title'=>'Слоты оформления','description'=>'Постоянная вместимость личных пресетов','enabled'=>1]);
    // No price was supplied: administrator explicitly sets price and enables the product.
    if (!$db->fetch_array($db->simple_select('af_shop_slots','slot_id',"shop_id={$shop} AND source_type='appearance_slot' AND source_ref_id=1",['limit'=>1])))
        $db->insert_query('af_shop_slots',['shop_id'=>$shop,'cat_id'=>$category,'source_type'=>'appearance_slot','source_ref_id'=>1,
            'kb_id'=>0,'kb_type'=>'appearance_slot','kb_key'=>'appearance_slot.postbit','price'=>0,'currency'=>'credits',
            'stock'=>-1,'limit_per_user'=>0,'enabled'=>0,'meta_json'=>'{}']);
}

function af_advancedshop_slot_product_url(string $target): string
{
    global $db,$mybb;
    if (empty($mybb->settings['af_advancedshop_enabled']) || !$db->table_exists('af_shop_slots') || !$db->table_exists('af_shop_categories')) return '';
    try {$registry=af_aa_slot_product_registry();if (!isset($registry[$target])) return '';af_aa_personal_target($target);}catch(Throwable $e){return '';}
    $ref=(int)$registry[$target]['ref_id'];
    $row=$db->fetch_array($db->query('SELECT s.slot_id,s.cat_id,sh.code FROM '.TABLE_PREFIX.'af_shop_slots s'
        .' INNER JOIN '.TABLE_PREFIX.af_advancedshop_shops_table().' sh ON(sh.shop_id=s.shop_id AND sh.enabled=1)'
        .' INNER JOIN '.TABLE_PREFIX.'af_shop_categories c ON(c.cat_id=s.cat_id AND c.shop_id=s.shop_id AND c.enabled=1)'
        ." WHERE s.source_type='appearance_slot' AND s.source_ref_id={$ref} AND s.enabled=1 ORDER BY s.slot_id LIMIT 1"));
    if (!$row) return '';
    return af_advancedshop_url('shop_category',['shop'=>$row['code'],'cat_id'=>(int)$row['cat_id']]).'#appearance-slot-'.(int)$row['slot_id'];
}

/** Same owner mutex as Appearance CRUD: quota checks and grants serialize together. No nested transaction. */
function af_advancedshop_order_transaction(int $uid, callable $work)
{
    global $db;
    if ($uid<1) throw new RuntimeException('auth');
    $database=(string)$db->fetch_field($db->write_query('SELECT DATABASE() AS db_name'),'db_name');
    $key='af_aa_owner_'.sha1($database.':'.TABLE_PREFIX.':'.$uid);
    if ((int)$db->fetch_field($db->write_query("SELECT GET_LOCK('{$key}',10) AS acquired"),'acquired')!==1) throw new RuntimeException('busy');
    try {
        $db->write_query('START TRANSACTION');$result=$work();$db->write_query('COMMIT');return $result;
    } catch(Throwable $e){$db->write_query('ROLLBACK');throw $e;}
    finally {$db->write_query("SELECT RELEASE_LOCK('{$key}')");}
}

function af_advancedshop_cart_key(int $cartId): string
{
    global $db;
    if (!$db->field_exists('checkout_key','af_shop_carts')) throw new RuntimeException('Обновите AdvancedShop.');
    $key=bin2hex(random_bytes(32));
    $db->write_query('UPDATE '.TABLE_PREFIX."af_shop_carts SET checkout_key='{$key}' WHERE cart_id={$cartId} AND checkout_key IS NULL");
    return (string)$db->fetch_field($db->simple_select('af_shop_carts','checkout_key','cart_id='.$cartId),'checkout_key');
}

function af_advancedshop_cart_changed(int $cartId): void
{
    global $db;
    $db->update_query('af_shop_carts',['checkout_key'=>bin2hex(random_bytes(32)),'updated_at'=>TIME_NOW],'cart_id='.$cartId);
}

/** Read trusted paid order snapshot, never a caller-supplied item payload. Caller owns transaction. */
function af_advancedshop_grant_slot_order_line(int $uid, int $orderId, int $line): void
{
    global $db;
    af_advancedshop_slot_provider();
    $order=$db->fetch_array($db->write_query('SELECT * FROM '.TABLE_PREFIX.'af_shop_orders WHERE order_id='.$orderId.' FOR UPDATE'));
    if (!$order || (int)$order['uid']!==$uid || $order['status']!=='paid') throw new RuntimeException('Order is not paid by this user.');
    $items=af_advancedshop_json_decode_assoc($order['items_json']);$item=$items[$line]??[];
    if (($item['source_type']??'')!=='appearance_slot' || ($item['entitlement_key']??'')!=='appearance_slot.'.($item['slot_target']??''))
        throw new RuntimeException('Order has no slot entitlement.');
    $target=(string)$item['slot_target'];
    if (af_advancedshop_slot_target((int)$item['source_ref_id'])!==$target) throw new RuntimeException('Order target mismatch.');
    af_aa_slot_grant_order_line($uid,$target,(int)$item['qty'],$orderId,$line);
}

/** Idempotent trusted recovery entry point; no HTTP grant route. */
function af_advancedshop_replay_slot_order(int $orderId): void
{
    global $db,$mybb;
    $uid=(int)($mybb->user['uid']??0);
    af_advancedshop_order_transaction($uid,function()use($db,$uid,$orderId){
        $order=$db->fetch_array($db->simple_select('af_shop_orders','*',"order_id={$orderId} AND uid={$uid} AND status='paid'"));
        if (!$order) throw new RuntimeException('Paid order not found.');
        foreach(af_advancedshop_json_decode_assoc($order['items_json']) as $line=>$item)
            if (($item['source_type']??'')==='appearance_slot') af_advancedshop_grant_slot_order_line($uid,$orderId,(int)$line);
    });
}

function af_advancedshop_assert_transactional_tables(array $tables): void
{
    global $db;
    foreach(array_unique($tables) as $table){
        $row=$db->fetch_array($db->write_query("SHOW TABLE STATUS WHERE Name='".$db->escape_string(TABLE_PREFIX.$table)."'"));
        if (!$row || strcasecmp((string)$row['Engine'],'InnoDB')!==0) throw new RuntimeException('Transactional InnoDB storage required: '.$table);
    }
}

/** Shared checkout service. Cart and balance are reread after locking, before charging. */
function af_advancedshop_checkout_order(int $uid, array $shop, string $requestedKey=''): array
{
    global $db,$mybb;
    if ($uid!==(int)($mybb->user['uid']??0) || !af_advancedshop_can_buy() || !af_advancedshop_can_view_shop()
        || !empty($mybb->usergroup['isbannedgroup'])) throw new RuntimeException('Not allowed');
    if ($requestedKey!=='' && !preg_match('/^[a-f0-9]{64}$/D',$requestedKey)) throw new RuntimeException('Invalid checkout key.');
    af_advancedshop_require_balance_runtime();
    return af_advancedshop_order_transaction($uid,function()use($db,$mybb,$uid,$shop,$requestedKey){
        if ($requestedKey!=='') {
            $prior=$db->fetch_array($db->simple_select('af_shop_orders','*',"uid={$uid} AND checkout_key='".$db->escape_string($requestedKey)."' AND shop_id=".(int)$shop['shop_id'],['limit'=>1]));
            if ($prior) {
                if ($prior['status']!=='paid') throw new RuntimeException('Order is no longer paid.');
                return $prior;
            }
        }
        $active=$db->fetch_array($db->simple_select(af_advancedshop_shops_table(),'*','shop_id='.(int)$shop['shop_id'].' AND enabled=1',['limit'=>1]));
        if (!$active) throw new RuntimeException('Shop is unavailable.');
        $cart=af_advancedshop_get_or_create_cart((int)$shop['shop_id'],$uid);
        $db->write_query('SELECT cart_id FROM '.TABLE_PREFIX.'af_shop_carts WHERE cart_id='.(int)$cart['cart_id'].' FOR UPDATE');
        $key=af_advancedshop_cart_key((int)$cart['cart_id']);
        if ($requestedKey!=='' && !hash_equals($key,$requestedKey)) throw new RuntimeException('Корзина изменилась. Обновите страницу.');
        [$items,$totals]=af_advancedshop_checkout_collect_items((int)$cart['cart_id']);
        if (!$items || !$totals) throw new RuntimeException('empty');
        $required=['af_shop_carts','af_shop_cart_items','af_shop_orders','af_shop_slots'];
        if (!defined('AF_BALANCE_TABLE')) throw new RuntimeException('Balance is unavailable.');
        $required[]=AF_BALANCE_TABLE;
        if (!empty($mybb->settings['af_balance_tx_enable'])) $required[]=AF_BALANCE_TX_TABLE;
        $slotQuantities=[];
        foreach($items as $item){
            if ($item['source_type']==='appearance_slot') {
                $slotQuantities[$item['slot_target']]=($slotQuantities[$item['slot_target']]??0)+(int)$item['qty'];
                $required[]='af_aa_slot_grants';
            } else {
                if (empty($mybb->settings['af_advancedinventory_enabled'])) throw new RuntimeException('Advanced Inventory отключён.');
                af_advancedshop_ensure_inventory_grant_available($uid);
                if (defined('AF_ADVINV_TABLE_ITEMS')) $required[]=AF_ADVINV_TABLE_ITEMS;
            }
        }
        af_advancedshop_assert_transactional_tables($required);
        foreach($slotQuantities as $target=>$qty) af_aa_slot_assert_purchase($uid,$target,$qty);
        // Existing Balance API remains the only writer and ledger. Hold its row through COMMIT.
        af_balance_get($uid);
        $db->write_query('SELECT uid FROM '.TABLE_PREFIX.AF_BALANCE_TABLE.' WHERE uid='.$uid.' FOR UPDATE');
        foreach($totals as $currency=>$amount) if (af_shop_get_balance($uid,$currency)<$amount) throw new RuntimeException('Недостаточно '.af_advancedshop_currency_label($currency).'.');
        $payload=['shop_id'=>(int)$shop['shop_id'],'uid'=>$uid,'total'=>array_sum($totals),
            'currency'=>count($totals)===1?array_key_first($totals):'mixed','created_at'=>TIME_NOW,'status'=>'paid',
            'checkout_key'=>$key,'items_json'=>json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
        $write=$payload;foreach(['currency','checkout_key','items_json'] as $field)$write[$field]=$db->escape_string($write[$field]);
        $id=(int)$db->insert_query('af_shop_orders',$write);
        if (!$id) throw new RuntimeException('Не удалось создать заказ.');
        foreach($totals as $currency=>$amount) af_shop_sub_balance($uid,$currency,$amount,'shop_purchase',['order_id'=>$id,'shop'=>$shop['code']]);
        foreach($items as $line=>$item) af_advancedshop_grant_inventory_item($uid,$item,$id,(int)$line);
        $db->delete_query('af_shop_cart_items','cart_id='.(int)$cart['cart_id']);af_advancedshop_cart_changed((int)$cart['cart_id']);
        return $payload+['order_id'=>$id];
    });
}

/** No refund flow existed in Shop. Explicit manager-only service for slot-only orders, with Balance audit. */
function af_advancedshop_refund_slot_order(int $orderId): void
{
    global $db;
    if (!af_advancedshop_can_manage()) throw new RuntimeException('Not allowed');
    af_advancedshop_slot_provider();af_advancedshop_require_balance_runtime();
    $order=$db->fetch_array($db->simple_select('af_shop_orders','*','order_id='.$orderId,['limit'=>1]));
    if (!$order) throw new RuntimeException('Order not found.');
    $uid=(int)$order['uid'];
    af_advancedshop_order_transaction($uid,function()use($db,$uid,$orderId){
        $order=$db->fetch_array($db->write_query('SELECT * FROM '.TABLE_PREFIX.'af_shop_orders WHERE order_id='.$orderId.' FOR UPDATE'));
        if ($order['status']==='refunded') return;
        if ($order['status']!=='paid') throw new RuntimeException('Order is not paid.');
        $items=af_advancedshop_json_decode_assoc($order['items_json']);$totals=[];
        if (!$items) throw new RuntimeException('No refundable items.');
        foreach($items as $item){
            if (($item['source_type']??'')!=='appearance_slot' || ($item['entitlement_key']??'')!=='appearance_slot.'.($item['slot_target']??''))
                throw new RuntimeException('Mixed/legacy inventory orders require their own refund policy.');
            $currency=af_advancedshop_normalize_currency_slug($item['currency']);
            $totals[$currency]=($totals[$currency]??0)+(int)$item['qty']*(int)$item['price_each'];
        }
        af_advancedshop_assert_transactional_tables(['af_shop_orders','af_aa_slot_grants',AF_BALANCE_TABLE,AF_BALANCE_TX_TABLE]);
        af_balance_get($uid);$db->write_query('SELECT uid FROM '.TABLE_PREFIX.AF_BALANCE_TABLE.' WHERE uid='.$uid.' FOR UPDATE');
        foreach($totals as $currency=>$amount) af_shop_add_balance($uid,$currency,$amount,'shop_refund',['order_id'=>$orderId]);
        af_aa_slot_revoke_order($uid,$orderId);
        $db->update_query('af_shop_orders',['status'=>'refunded'],'order_id='.$orderId);
    });
}

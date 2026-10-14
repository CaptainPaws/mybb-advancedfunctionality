<?php
/** Trusted Shop adapter. Writes join the caller's transaction and owner lock; never open HTTP endpoints. */
if (!defined('IN_MYBB')) die('No direct access');

function af_aa_slot_product_registry(): array
{
    $result=[];
    foreach (af_aa_personal_targets() as $target=>$meta) {
        $result[$target]=['target'=>$target,'ref_id'=>array_search($target,['postbit','profile','sheet','theme'],true)+1,
            'key'=>'appearance_slot.'.$target,'title'=>'Дополнительный слот: '.$meta['label'],
            'implemented'=>$meta['implemented']];
    }
    return $result;
}

/** Shop must use this policy instead of duplicating Appearance quota arithmetic. */
function af_aa_slot_assert_purchase(int $uid, string $target, int $quantity): void
{
    af_aa_personal_actor($uid);af_aa_personal_target($target);
    if ($quantity<1 || $quantity>1000) throw new AF_AA_PersonalException('Invalid slot quantity.',422);
    $slots=af_aa_personal_slots($uid,$target);
    // Compare uncapped capacity: lowering ACP maximum must not permit more purchases.
    if ($slots['free']+$slots['purchased']+$quantity>$slots['maximum'])
        throw new AF_AA_PersonalException('Достигнут максимум слотов оформления.',409);
}

/** Order evidence is verified by Shop; source key is its immutable order line, never user input. */
function af_aa_slot_grant_order_line(int $uid, string $target, int $quantity, int $orderId, int $line): void
{
    global $db;
    if ($orderId<1 || $line<0 || !isset(af_aa_slot_product_registry()[$target])) throw new AF_AA_PersonalException('Invalid grant source.',422);
    $key='shop:'.$orderId.':'.$line.':'.$target;
    $old=$db->fetch_array($db->simple_select('af_aa_slot_grants','*',"source_key='".$db->escape_string($key)."'",['limit'=>1]));
    if ($old) {
        if ((int)$old['uid']!==$uid || $old['preset_type']!==$target || (int)$old['capacity']!==$quantity)
            throw new AF_AA_PersonalException('Grant source conflict.',409);
        // Revoked rights are never resurrected by replaying a historical paid line.
        return;
    }
    af_aa_slot_assert_purchase($uid,$target,$quantity);
    $db->insert_query('af_aa_slot_grants',['uid'=>$uid,'preset_type'=>$db->escape_string($target),'capacity'=>$quantity,
        'source_key'=>$db->escape_string($key),'is_enabled'=>1,'created_at'=>TIME_NOW,'updated_at'=>TIME_NOW]);
    af_aa_personal_invalidate($uid);
}

function af_aa_slot_revoke_order(int $uid, int $orderId): void
{
    global $db;
    if ($uid<1 || $orderId<1 || !af_aa_personal_schema_available()) throw new AF_AA_PersonalException('Invalid revocation.',422);
    $db->update_query('af_aa_slot_grants',['is_enabled'=>0,'updated_at'=>TIME_NOW],
        "uid={$uid} AND source_key LIKE 'shop:{$orderId}:%'");
    af_aa_personal_invalidate($uid);
    // Existing presets and assignments deliberately survive reduced capacity.
}

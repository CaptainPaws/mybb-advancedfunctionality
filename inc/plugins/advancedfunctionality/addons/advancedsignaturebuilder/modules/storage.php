<?php
if (!defined('IN_MYBB')) die('No direct access');
function af_asb_available(): bool {return $GLOBALS['af_asb_available']??=isset($GLOBALS['db']) && $GLOBALS['db']->table_exists('af_asb_signatures');}
function af_asb_row(int $uid): ?array {
    global $db;
    if (!af_asb_available()) return null;
    if (!array_key_exists($uid,$GLOBALS['af_asb_rows']??[])) {
        $row=$db->fetch_array($db->query('SELECT b.*,u.signature AS native_signature FROM '.TABLE_PREFIX.'af_asb_signatures b INNER JOIN '.TABLE_PREFIX."users u ON u.uid=b.uid WHERE b.uid={$uid}"));
        $GLOBALS['af_asb_rows'][$uid]=$row?:null;
    }
    return $GLOBALS['af_asb_rows'][$uid];
}
function af_asb_write(int $uid,array $fields): void {
    global $db;
    if (!af_asb_available()) throw new RuntimeException('Обновите AdvancedSignatureBuilder через ACP.');
    $row=$db->fetch_array($db->simple_select('af_asb_signatures','uid',"uid={$uid}"));
    $fields['updated_at']=defined('TIME_NOW')?TIME_NOW:time();
    foreach ($fields as $key=>$value) if (is_string($value)) $fields[$key]=$db->escape_string($value);
    if ($row) $db->update_query('af_asb_signatures',$fields,"uid={$uid}");else {$fields['uid']=$uid;$db->insert_query('af_asb_signatures',$fields);}
    unset($GLOBALS['af_asb_rows'][$uid]);
}
function af_asb_owned(?array $row,string $native): bool {return $row && !empty($row['published_json']) && !empty($row['published_hash']) && hash_equals($row['published_hash'],hash('sha256',$native));}

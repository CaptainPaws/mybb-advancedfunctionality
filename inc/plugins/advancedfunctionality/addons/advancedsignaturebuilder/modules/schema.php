<?php
if (!defined('IN_MYBB')) die('No direct access');
function af_asb_install_schema(): void {
    global $db;
    $db->write_query('CREATE TABLE IF NOT EXISTS '.TABLE_PREFIX."af_asb_signatures (
        uid INT UNSIGNED NOT NULL PRIMARY KEY, draft_json MEDIUMTEXT NULL, published_json MEDIUMTEXT NULL,
        published_hash VARCHAR(64) NOT NULL DEFAULT '', legacy_backup MEDIUMTEXT NULL, updated_at INT UNSIGNED NOT NULL DEFAULT 0
    )".$db->build_create_table_collation());
    if ($db->table_exists('af_apui_signature_builder')) {
        // Copy byte-for-byte, once per uid. Newer ASB data always wins; never drop the backup table.
        $fields='uid,draft_json,published_json,published_hash,legacy_backup,updated_at';
        $db->write_query('INSERT IGNORE INTO '.TABLE_PREFIX.'af_asb_signatures ('.$fields.') SELECT '.$fields.' FROM '.TABLE_PREFIX.'af_apui_signature_builder');
    }
    unset($GLOBALS['af_asb_available'],$GLOBALS['af_asb_rows']);
}
function af_asb_install_settings(): void {
    global $db,$mybb;
    $name='af_'.AF_ASB_ID;
    $group=$db->fetch_array($db->simple_select('settinggroups','gid',"name='{$name}'"));
    $gid=$group?(int)$group['gid']:(int)$db->insert_query('settinggroups',['name'=>$name,'title'=>'AdvancedSignatureBuilder','description'=>'Конструктор содержимого подписей','disporder'=>80,'isdefault'=>0]);
    $defs=[['enabled','Включить конструктор','yesno','1'],['css_delivery_mode','Доставка CSS','select\nfile=File\ntheme=Theme\nauto=Auto','auto']];
    foreach (['title'=>100,'subtitle'=>100,'text'=>500,'status'=>100] as $key=>$default) $defs[]=['signature_'.$key.'_limit','Подпись: лимит '.$key,'numeric',(string)($mybb->settings['af_advancedprofileui_signature_'.$key.'_limit']??$default)];
    foreach ($defs as $order=>[$key,$title,$type,$value]) {
        $row=$db->fetch_array($db->simple_select('settings','sid',"name='{$name}_{$key}'"));
        $fields=['gid'=>$gid,'title'=>$db->escape_string($title),'description'=>$db->escape_string('Окончательные ограничения подписи задаёт MyBB.'),'optionscode'=>$db->escape_string(str_replace('\\n',"\n",$type)),'disporder'=>$order,'isdefault'=>0];
        if ($row) $db->update_query('settings',$fields,'sid='.(int)$row['sid']);
        else $db->insert_query('settings',$fields+['name'=>$name.'_'.$key,'value'=>$db->escape_string($value)]);
    }
}

<?php
/** Retired v1 decoration: read compatibility, immutable recovery and proven portable BBCode repair. */
if (!defined('IN_MYBB')) die('No direct access');
function af_asb_needs_background_republish(?array $row,string $native): bool {
    if (!af_asb_owned($row,$native)) return false;
    try { return array_key_exists('background',af_asb_decode($row['published_json'])); }
    catch(Throwable $e){ return false; }
}
function af_asb_background_backup(array $row,string $native): string {
    return json_encode(['native_signature'=>$native,'published_json'=>$row['published_json']??null,'published_hash'=>$row['published_hash']??'',
        'draft_json'=>$row['draft_json']??null],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}
/** Pure plan. Never remove arbitrary IMG tags; reproduce the complete old compiler output first. */
function af_asb_background_migration_plan(array $row): array {
    $fields=[];$native=(string)$row['native_signature'];$retired=false;$replacement=null;
    foreach (['draft_json','published_json'] as $key) {
        if (empty($row[$key])) continue;
        try { $raw=af_asb_decode($row[$key]); } catch(Throwable $e){continue;}
        if (!array_key_exists('background',$raw)) continue;
        $retired=true;$clean=$raw;unset($clean['background']);
        if ($key==='draft_json') { $fields[$key]=json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);continue; }
        if (!af_asb_owned($row,$native)) continue;
        try {
            $model=af_asb_validate($clean,(int)$row['uid'],false);
            $new=af_asb_compile($model);
            $image=af_asb_validate(['images'=>[$raw['background']]],(int)$row['uid'],false)['images'][0];
            $url=af_asb_image_url($image);
            // Missing/deleted media or changed MyBB parsing settings cannot prove the former bytes.
            $old=($url!==''?'[img]'.$url.'[/img]'.($new!==''?"\n":''):'').$new;
            if ($old!==$native) continue;
            $replacement=$new;$fields[$key]=json_encode($model,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $fields['published_hash']=hash('sha256',$new);
        } catch(Throwable $e){continue;}
    }
    if ($retired && ($row['background_backup']??null)===null) $fields['background_backup']=af_asb_background_backup($row,$native);
    return ['fields'=>$fields,'native'=>$replacement];
}
/** Upgrade only. One compare-and-swap statement protects both native text and its matching AF hash. */
function af_asb_migrate_backgrounds(): void {
    global $db;
    $q=$db->query('SELECT b.*,u.signature AS native_signature FROM '.TABLE_PREFIX.'af_asb_signatures b INNER JOIN '.TABLE_PREFIX.'users u ON u.uid=b.uid');
    while ($row=$db->fetch_array($q)) {
        $plan=af_asb_background_migration_plan($row);if (!$plan['fields']) continue;
        $literal=static fn($v)=>$v===null?'NULL':"'".$db->escape_string((string)$v)."'";
        $set=[];foreach ($plan['fields'] as $key=>$value) $set[]='b.'.$key.'='.$literal($value);
        if ($plan['native']!==null) $set[]='u.signature='.$literal($plan['native']);
        $where=['b.uid='.(int)$row['uid'],'BINARY u.signature='.$literal($row['native_signature'])];
        foreach (['draft_json','published_json','published_hash','background_backup'] as $key) $where[]='BINARY b.'.$key.' <=> BINARY '.$literal($row[$key]??null);
        $db->write_query('UPDATE '.TABLE_PREFIX.'af_asb_signatures b INNER JOIN '.TABLE_PREFIX.'users u ON u.uid=b.uid SET '.implode(',',$set).' WHERE '.implode(' AND ',$where));
    }
    unset($GLOBALS['af_asb_rows']);
}

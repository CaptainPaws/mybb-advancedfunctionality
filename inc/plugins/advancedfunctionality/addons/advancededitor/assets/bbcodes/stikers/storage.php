<?php
if (!defined('IN_MYBB')) die('No direct access');
require_once __DIR__.'/external.php';

function af_ae_sticker_staff(): bool
{
    global $mybb, $db;
    $uid=(int)($mybb->user['uid'] ?? 0);
    if ($uid<=0) return false;
    // MyBB combines primary/additional group permissions in usergroup on the server.
    if (!empty($mybb->usergroup['cancp']) || !empty($mybb->usergroup['issupermod'])) return true;
    $ids=[(int)($mybb->user['usergroup'] ?? 0)];
    foreach (explode(',',(string)($mybb->user['additionalgroups'] ?? '')) as $id) if ((int)$id>0) $ids[]=(int)$id;
    $q=$db->simple_select('usergroups','cancp,issupermod','gid IN ('.implode(',',array_unique($ids)).')');
    while ($group=$db->fetch_array($q)) if (!empty($group['cancp']) || !empty($group['issupermod'])) return true;
    return function_exists('is_moderator') && (bool)is_moderator(0,'',$uid);
}

/** Read actual bytes only for authoritative owner records inside the existing sticker root. */
function af_ae_sticker_usage(int $uid): array
{
    global $db;
    $used=0;$seen=[];
    if ($uid>0) {
        $q=$db->simple_select(AF_AE_STIKERS_TABLE,'path',"is_user_sticker=1 AND uid={$uid}");
        while ($row=$db->fetch_array($q)) {
            $path=realpath((string)$row['path']);
            if (!$path || isset($seen[$path]) || !af_advancededitor_stikers_is_path_inside($path,af_advancededitor_stikers_abs()) || !is_file($path)) continue;
            $seen[$path]=true;clearstatcache(true,$path);
            $size=@filesize($path);
            if ($size===false) throw new RuntimeException('Не удалось определить размер существующего стикера.');
            $used+=$size;
        }
    }
    $settings=af_advancededitor_stikers_get_settings();
    $limit=(int)$settings['user_quota_mb']*1048576;
    if ($settings['staff_exempt'] && af_ae_sticker_staff()) $limit=0;
    return ['used_bytes'=>$used,'limit_bytes'=>$limit,'available_bytes'=>$limit>0?max(0,$limit-$used):null,'full'=>$limit>0 && $used>=$limit];
}

function af_ae_sticker_locked(int $uid, callable $operation): array
{
    global $db;
    // Database-scoped owner lock also works with the legacy MyISAM tables.
    $name='ae_sticker_'.substr(hash('sha256',TABLE_PREFIX.af_advancededitor_stikers_abs()),0,32).'_'.$uid;
    $escaped=$db->escape_string($name);
    $locked=(int)$db->fetch_field($db->write_query("SELECT GET_LOCK('{$escaped}',10) AS acquired"),'acquired');
    if ($locked!==1) throw new RuntimeException('Стикеры уже обновляются. Повторите попытку.');
    try {return $operation();}
    finally {$db->write_query("SELECT RELEASE_LOCK('{$escaped}')");}
}

function af_ae_sticker_save(int $uid, ?array $file, string $external='', string $name=''): array
{
    global $db;
    if ($uid<=0) throw new RuntimeException('Требуется авторизация.');
    $settings=af_advancededitor_stikers_get_settings();
    $remote=$external!=='';$metadata=[];
    if ($remote) {
        if (!$settings['allow_external']) throw new RuntimeException('Внешние стикеры отключены.');
        $metadata=af_ae_sticker_inspect_external($external);
        $name=trim($name);
        if (preg_match('//u',$name)!==1 || strlen($name)>240) throw new RuntimeException('Название должно быть в UTF-8, не длиннее 240 байт.');
    } else {
        $error='';
        if (!$file || !af_advancededitor_stikers_is_allowed_upload($file,$error,true)) throw new RuntimeException($error ?: 'Файл не получен.');
    }
    return af_ae_sticker_locked($uid,static function() use ($uid,$file,$remote,$external,$db,$metadata,$name) {
        $count=(int)$db->fetch_field($db->simple_select(AF_AE_STIKERS_TABLE,'COUNT(id) AS c',"is_user_sticker=1 AND uid={$uid}"),'c');
        if ($count>=af_advancededitor_stikers_max_per_user()) throw new RuntimeException('Достигнут лимит пользовательских стикеров.');
        $target='';$ext=$metadata['ext'] ?? '';$title=$name!==''?$name:'Внешний стикер';$url=$external;
        if (!$remote) {
            $usage=af_ae_sticker_usage($uid);
            $size=filesize($file['tmp_name']);
            if ($usage['limit_bytes']>0 && ($usage['full'] || $size>$usage['available_bytes'])) throw new RuntimeException('Недостаточно места для стикеров. Удалите локальные файлы перед новой загрузкой.');
            $dir=af_advancededitor_stikers_get_user_folder($uid);
            if (!is_dir($dir)) @mkdir($dir,0775,true);
            if (!is_dir($dir) || !af_advancededitor_stikers_is_path_inside($dir,af_advancededitor_stikers_abs())) throw new RuntimeException('Не удалось создать папку пользователя.');
            [$name,$ext]=af_advancededitor_stikers_unique_path($dir,(string)$file['name']);
            $target=rtrim($dir,'/\\').DIRECTORY_SEPARATOR.$name;
            if (!@move_uploaded_file($file['tmp_name'],$target)) { @unlink($target); throw new RuntimeException('Не удалось сохранить файл.'); }
            $url=af_advancededitor_stikers_url('stikers'.$uid.'/'.$name);
            $title=pathinfo($name,PATHINFO_FILENAME);
        }
        $saved=false;
        // A database driver may terminate the request: do not leave an unregistered original.
        register_shutdown_function(static function() use (&$saved,$target) {if (!$saved && $target!=='') @unlink($target);});
        try {
            $values=['title'=>$title,'slug'=>$title,'path'=>$target,'url'=>$url,'ext'=>$ext,'is_user_sticker'=>1,'uid'=>$uid,'category_id'=>0,'sortorder'=>0,'created_at'=>TIME_NOW,'source'=>$remote?'url':'local'];
            $quoted=[];foreach ($values as $value) $quoted[]="'".$db->escape_string((string)$value)."'";
            $ok=$db->write_query('INSERT INTO '.TABLE_PREFIX.AF_AE_STIKERS_TABLE.' (`'.implode('`,`',array_keys($values)).'`) VALUES ('.implode(',',$quoted).')',true);
            if (!$ok) throw new RuntimeException('Не удалось записать стикер.');
            $id=(int)$db->insert_id();$saved=true;
        } finally {if (!$saved && $target!=='') @unlink($target);}
        return ['id'=>$id,'url'=>$url,'storage'=>af_ae_sticker_usage($uid)];
    });
}

function af_ae_sticker_delete(int $uid, int $id): array
{
    global $db;
    return af_ae_sticker_locked($uid,static function() use ($uid,$id,$db) {
        $row=$db->fetch_array($db->simple_select(AF_AE_STIKERS_TABLE,'*',"id={$id} AND is_user_sticker=1 AND uid={$uid}",['limit'=>1]));
        if (empty($row['id'])) throw new RuntimeException('Стикер не найден.');
        $path=(string)$row['path'];
        if ($path!=='' && is_file($path)) {
            if (!af_advancededitor_stikers_is_path_inside($path,af_advancededitor_stikers_abs()) || !@unlink($path)) throw new RuntimeException('Не удалось удалить локальный файл.');
        }
        $db->delete_query(AF_AE_STIKERS_TABLE,"id={$id} AND uid={$uid} AND is_user_sticker=1");
        $db->delete_query(AF_AE_STIKERS_RECENT_TABLE,"uid={$uid} AND sticker_id={$id}");
        return ['id'=>$id,'storage'=>af_ae_sticker_usage($uid)];
    });
}

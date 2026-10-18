<?php
/** ACP user libraries. All mutations are POST + MyBB CSRF + server-side owner checks. */
if (!defined('IN_MYBB') || !defined('IN_ADMINCP')) die('No direct access');

function ag_admin_escape($value): string {return htmlspecialchars_uni((string)$value);}
function ag_admin_url(array $params=[]): string {return 'index.php?'.http_build_query(['module'=>'advancedfunctionality','af_view'=>'advancedgallery','ag_tab'=>'users']+$params);}
function ag_admin_nav(bool $users=false): string
{
    return '<nav class="ag-admin-tabs"><a class="button" href="index.php?module=advancedfunctionality&amp;af_view=advancedgallery"'.(!$users?' aria-current="page"':'').'>Обзор и настройки</a> <a class="button" href="'.ag_admin_escape(ag_admin_url()).'"'.($users?' aria-current="page"':'').'>Медиа пользователей</a></nav>';
}
function ag_admin_assets(): string
{
    global $mybb;
    $base=rtrim((string)$mybb->settings['bburl'],'/').'/inc/plugins/advancedfunctionality/addons/advancedgallery/assets/';
    return '<link rel="stylesheet" href="'.ag_admin_escape($base.'admin-media.css?v='.AF_AG_VER).'"><script src="'.ag_admin_escape($base.'admin-media.js?v='.AF_AG_VER).'" defer></script>';
}
function ag_admin_hidden(int $uid=0): string
{
    return '<input type="hidden" name="module" value="advancedfunctionality"><input type="hidden" name="af_view" value="advancedgallery"><input type="hidden" name="ag_tab" value="users">'.($uid?'<input type="hidden" name="uid" value="'.$uid.'">':'');
}
function ag_admin_select(string $name,array $options,string $current): string
{
    $html='<select name="'.ag_admin_escape($name).'">';
    foreach ($options as $value=>$label) $html.='<option value="'.ag_admin_escape($value).'"'.((string)$value===$current?' selected':'').'>'.ag_admin_escape($label).'</option>';
    return $html.'</select>';
}
function ag_admin_input(string $name,string $type,string $value): string {return '<input name="'.ag_admin_escape($name).'" type="'.$type.'" value="'.ag_admin_escape($value).'"'.($type==='number'?' min="0" step="any"':'').'>';}
function ag_admin_date(int $date): string {return $date>0?gmdate('d.m.Y H:i',$date).' UTC':'Нет данных';}
function ag_admin_page(int $total,int $page,int $per,array $params): string
{
    $pages=max(1,(int)ceil($total/$per));$out='<nav class="ag-admin-pagination" aria-label="Страницы">Всего: '.$total.' · Страница '.$page.' из '.$pages.' ';
    foreach (array_unique([1,max(1,$page-1),$page,min($pages,$page+1),$pages]) as $p) $out.='<a class="button" href="'.ag_admin_escape(ag_admin_url(array_replace($params,['page'=>$p]))).'"'.($p===$page?' aria-current="page"':'').'>'.$p.'</a> ';
    return $out.'</nav>';
}
function ag_admin_ids(): array
{
    global $mybb;
    $raw=$mybb->get_input('ids',MyBB::INPUT_ARRAY);
    if (!$raw || count($raw)>24) throw new RuntimeException('Выберите от 1 до 24 изображений.');
    $ids=[];foreach ($raw as $id) {if (!is_scalar($id) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$id)) throw new RuntimeException('Некорректный ID изображения.');$ids[(int)$id]=true;}
    return array_keys($ids);
}
function ag_admin_owned_rows(int $uid,array $ids): array
{
    global $db;
    $rows=[];$q=$db->simple_select('af_gallery_media','*','uid_owner='.$uid.' AND id IN ('.implode(',',$ids).')');
    while ($row=$db->fetch_array($q)) $rows[]=$row;
    if (count($rows)!==count($ids)) throw new RuntimeException('Изображение не найдено или принадлежит другому владельцу. Обновите медиатеку.');
    return $rows;
}
function ag_admin_status(array $m): string {return (int)$m['usage_flags']>0?'used':((int)$m['usage_checked_at']>0?'not_found':'unchecked');}
function ag_admin_usage_text(array $m): string
{
    $out=[];$flags=(int)$m['usage_flags'];
    foreach ([1=>'Аватар',2=>'Анкета / CharacterSheets',4=>'Посты, подписи или другое оформление'] as $bit=>$label) if ($flags&$bit) $out[]=$label;
    return $out?'Используется: '.implode('; ',$out):((int)$m['usage_checked_at']>0?'Не найдено использование':'Не проверено');
}

function ag_admin_media_api(int $uid): array
{
    global $db,$mybb;
    if ($mybb->request_method!=='post' || !ag_cleanup_admin() || $uid<=0) throw new RuntimeException('Нет прав администратора.');
    verify_post_check($mybb->get_input('my_post_key'));
    $op=$mybb->get_input('ag_op');
    if ($op==='audit_start') {$a=ag_cleanup_start($uid);return ['token'=>$a['token']];}
    if ($op==='audit_step') return ag_cleanup_step($uid,$mybb->get_input('token'));
    if ($op==='prepare_delete') {
        $ids=ag_admin_ids();$rows=ag_admin_owned_rows($uid,$ids);$probe=ag_cleanup_probe($uid,$rows);
        $items=[];
        $summary=['count'=>count($rows),'bytes'=>0,'linked'=>0,'protected'=>0,'unchecked'=>0,'external'=>0];
        foreach ($rows as $m) {
            $flags=(int)$m['usage_flags']|($probe['flags'][(int)$m['id']]??0);
            $summary['bytes']+=$m['type']==='local'?(int)$m['filesize']:0;
            $summary['linked']+=(int)($flags>0);
            $summary['protected']+=(int)(!empty($m['cleanup_protected']) || !empty($m['appearance_retained']));
            $summary['unchecked']+=(int)(!$flags && ($probe['unknown'] || !$m['usage_checked_at']));
            $summary['external']+=(int)($m['type']!=='local');
            $items[]=['bytes'=>$m['type']==='local'?(int)$m['filesize']:0,'linked'=>$flags>0,
                'protected'=>!empty($m['cleanup_protected']) || !empty($m['appearance_retained']),
                'unchecked'=>!$flags && ($probe['unknown'] || !$m['usage_checked_at'])];
        }
        $token=bin2hex(random_bytes(16));
        $db->delete_query('af_gallery_cleanup_jobs','expires_at<'.TIME_NOW);
        $db->insert_query('af_gallery_cleanup_jobs',['token'=>$token,'uid_actor'=>(int)$mybb->user['uid'],'uid_owner'=>$uid,'ids_json'=>$db->escape_string(json_encode($ids)),
            'processed'=>0,'created_at'=>TIME_NOW,'expires_at'=>TIME_NOW+900,'confirmed'=>0,'allow_linked'=>0,'allow_protected'=>0,'allow_unknown'=>0,'result_json'=>'{}']);
        return ['token'=>$token,'summary'=>$summary,'items'=>$items];
    }
    if ($op==='delete_batch') {
        $token=$mybb->get_input('token');
        if (!preg_match('/^[a-f0-9]{32}$/D',$token)) throw new RuntimeException('Некорректное задание удаления.');
        $lock='ag_cleanup_'.sha1(TABLE_PREFIX.$token);
        if ((int)$db->fetch_field($db->write_query("SELECT GET_LOCK('{$lock}',0) AS n"),'n')!==1) throw new RuntimeException('Пакет уже обрабатывается. Повторите запрос.');
        try {
            $job=$db->fetch_array($db->simple_select('af_gallery_cleanup_jobs','*',"token='{$token}' AND uid_owner=".$uid.' AND uid_actor='.(int)$mybb->user['uid'].' AND expires_at>='.TIME_NOW));
            if (!$job) throw new RuntimeException('Задание истекло. Выберите изображения заново.');
            if (!$job['confirmed']) {
                foreach (['allow_linked','allow_protected','allow_unknown'] as $flag) $job[$flag]=$mybb->get_input($flag)==='1'?1:0;
                $db->update_query('af_gallery_cleanup_jobs',['confirmed'=>1,'allow_linked'=>$job['allow_linked'],'allow_protected'=>$job['allow_protected'],'allow_unknown'=>$job['allow_unknown']],"token='{$token}'");
            }
            $all=json_decode($job['ids_json'],true);$chunk=array_slice($all,(int)$job['processed'],5);
            $rows=[];if ($chunk) {$q=$db->simple_select('af_gallery_media','*','uid_owner='.$uid.' AND id IN ('.implode(',',array_map('intval',$chunk)).')');while ($m=$db->fetch_array($q)) $rows[(int)$m['id']]=$m;}
            $probe=$rows?ag_cleanup_probe($uid,array_values($rows)):['flags'=>[],'unknown'=>false];
            $result=json_decode($job['result_json'],true)+['deleted'=>0,'skipped'=>0,'bytes'=>0,'external'=>0,'errors'=>[]];
            foreach ($chunk as $id) {
                $m=$rows[$id]??null;
                try {
                    if (!$m) throw new RuntimeException('Запись уже удалена или владелец изменился.');
                    $flags=(int)$m['usage_flags']|($probe['flags'][$id]??0);
                    $unknown=!$flags && ($probe['unknown'] || !$m['usage_checked_at']);
                    if (($flags && !$job['allow_linked']) || ($unknown && !$job['allow_unknown']) || ((!empty($m['cleanup_protected']) || !empty($m['appearance_retained'])) && !$job['allow_protected'])) throw new RuntimeException('Пропущено: требуется отдельное разрешение на удаление.');
                    if (!ag_delete_unretained_media($id,$uid,(bool)$job['allow_protected'],(bool)$job['allow_protected'],(bool)$job['allow_linked'],(bool)$job['allow_unknown'])) throw new RuntimeException('Защита или владелец изображения изменились.');
                    $result['deleted']++;$result['external']+=(int)($m['type']==='remote');$result['bytes']+=(int)($GLOBALS['ag_cleanup_delete_result']['bytes']??0);
                    if (!empty($GLOBALS['ag_cleanup_delete_result']['cleanup_failed'])) $result['errors'][]='#'.$id.': запись удалена, очистка временного файла не завершена; подробности в журнале.';
                } catch (RuntimeException $e) {$result['skipped']++;$result['errors'][]='#'.$id.': '.$e->getMessage();}
                $job['processed']++;
                $result['errors']=array_slice($result['errors'],-60);
                $db->update_query('af_gallery_cleanup_jobs',['processed'=>(int)$job['processed'],'result_json'=>$db->escape_string(json_encode($result,JSON_UNESCAPED_UNICODE))],"token='{$token}'");
            }
            return ['done'=>(int)$job['processed']>=count($all),'processed'=>(int)$job['processed'],'total'=>count($all),'result'=>$result];
        } finally {$db->write_query("SELECT RELEASE_LOCK('{$lock}')");}
    }
    $ids=ag_admin_ids();$rows=ag_admin_owned_rows($uid,$ids);
    if (count($rows)!==1) throw new RuntimeException('Для этого действия выберите одно изображение.');
    $m=$rows[0];$id=(int)$m['id'];
    if ($op==='protect') {
        $value=$mybb->get_input('value')==='1'?1:0;
        $db->update_query('af_gallery_media',['cleanup_protected'=>$value],'id='.$id.' AND uid_owner='.$uid);
        ag_cleanup_log($uid,$id,'admin_protect',['protected'=>$value]);return ['protected'=>$value];
    }
    if ($op==='moderate') {
        $status=$mybb->get_input('status');if (!in_array($status,['approved','pending','rejected'],true)) throw new RuntimeException('Некорректный статус.');
        $db->update_query('af_gallery_media',['status'=>$status,'updated_at'=>TIME_NOW],'id='.$id.' AND uid_owner='.$uid);
        ag_cleanup_log($uid,$id,'admin_moderate',['status'=>$status]);return ['status'=>$status];
    }
    if ($op==='move') {
        $albumId=$mybb->get_input('album_id',MyBB::INPUT_INT);$album=ag_get_album($albumId);
        if (!$album || (int)$album['uid_owner']!==$uid || !ag_provider_album_accepts($album,$m)) throw new RuntimeException('Альбом другого владельца или не подходит этому провайдеру.');
        $limit=(int)af_ag_get_setting('max_media_per_album',200);
        $exists=$db->fetch_array($db->simple_select('af_gallery_album_media','id','album_id='.$albumId.' AND media_id='.$id));
        if (!$exists && $limit>0 && ag_album_media_count($albumId)>=$limit) throw new RuntimeException('Достигнут лимит изображений альбома.');
        $db->write_query('START TRANSACTION');
        try {
            $locked=$db->fetch_array($db->write_query('SELECT uid_owner FROM '.TABLE_PREFIX.'af_gallery_media WHERE id='.$id.' FOR UPDATE'));
            if (!$locked || (int)$locked['uid_owner']!==$uid) throw new RuntimeException('Владелец изображения изменился.');
            $currentAlbum=$db->fetch_array($db->write_query('SELECT * FROM '.TABLE_PREFIX.'af_gallery_albums WHERE id='.$albumId.' FOR UPDATE'));
            if (!$currentAlbum || (int)$currentAlbum['uid_owner']!==$uid || !ag_provider_album_accepts($currentAlbum,$m)) throw new RuntimeException('Альбом изменился.');
            $db->write_query('DELETE am FROM '.TABLE_PREFIX.'af_gallery_album_media am INNER JOIN '.TABLE_PREFIX.'af_gallery_albums a ON a.id=am.album_id WHERE am.media_id='.$id.' AND a.uid_owner='.$uid);
            $db->update_query('af_gallery_albums',['cover_media_id'=>0,'updated_at'=>TIME_NOW],'uid_owner='.$uid.' AND cover_media_id='.$id);
            $db->insert_query('af_gallery_album_media',['album_id'=>$albumId,'media_id'=>$id,'sort_order'=>0,'created_at'=>TIME_NOW]);
            $db->update_query('af_gallery_albums',['updated_at'=>TIME_NOW],'id='.$albumId);
            $db->write_query('COMMIT');
        } catch (Throwable $e) {$db->write_query('ROLLBACK');throw $e;}
        ag_cleanup_log($uid,$id,'admin_move',['album_id'=>$albumId]);return ['moved'=>true];
    }
    throw new RuntimeException('Неизвестное действие.');
}

function ag_admin_users_render(): string
{
    global $db,$mybb;
    $q=mb_substr(trim($mybb->get_input('q')),0,120);$days=max(0,min(36500,$mybb->get_input('inactive_days',MyBB::INPUT_INT)));
    $sort=$mybb->get_input('sort');$orders=['name'=>'u.username','uid'=>'m.uid_owner','activity'=>'COALESCE(u.lastactive,0)','bytes'=>'local_bytes','count'=>'media_count'];
    if (!isset($orders[$sort])) $sort='bytes';
    $direction=$mybb->get_input('direction')==='asc'?'asc':'desc';$page=max(1,$mybb->get_input('page',MyBB::INPUT_INT));$per=30;
    $where='m.uid_owner>0';
    if ($q!=='') $where.=ctype_digit($q)?' AND m.uid_owner='.(int)$q:" AND u.username LIKE '%".$db->escape_string_like($q)."%'";
    if ($days) $where.=' AND COALESCE(u.lastactive,0)<='.(TIME_NOW-$days*86400);
    $join=' FROM '.TABLE_PREFIX.'af_gallery_media m LEFT JOIN '.TABLE_PREFIX.'users u ON u.uid=m.uid_owner';
    $total=(int)$db->fetch_field($db->write_query('SELECT COUNT(DISTINCT m.uid_owner) AS n'.$join.' WHERE '.$where),'n');
    $page=min($page,max(1,(int)ceil($total/$per)));
    $query=$db->write_query("SELECT m.uid_owner, u.username,u.avatar,u.lastactive,g.title AS group_title,
        SUM(m.type='local') AS local_count,SUM(m.type='remote') AS external_count,COUNT(*) AS media_count,
        COALESCE(SUM(CASE WHEN m.type='local' THEN m.filesize ELSE 0 END),0) AS local_bytes".$join.' LEFT JOIN '.TABLE_PREFIX.'usergroups g ON g.gid=u.usergroup WHERE '.$where.' GROUP BY m.uid_owner,u.username,u.avatar,u.lastactive,g.title ORDER BY '.$orders[$sort].' '.$direction.',m.uid_owner ASC LIMIT '.(($page-1)*$per).','.$per);
    $html='<h2>Медиа пользователей</h2><form method="get" class="ag-admin-filters">'.ag_admin_hidden().'<label>Имя или UID'.ag_admin_input('q','search',$q).'</label><label>Неактивен более (дней)'.ag_admin_input('inactive_days','number',(string)$days).'<small>0 — все · Быстрый выбор: <button type="button" data-ag-days="30">30</button> <button type="button" data-ag-days="90">90</button> <button type="button" data-ag-days="180">180</button> <button type="button" data-ag-days="365">365</button></small></label><label>Сортировка'.ag_admin_select('sort',['name'=>'Имя','uid'=>'UID','activity'=>'Последняя активность','bytes'=>'Объём локальных оригиналов','count'=>'Количество изображений'],$sort).'</label><label>Порядок'.ag_admin_select('direction',['desc'=>'По убыванию','asc'=>'По возрастанию'],$direction).'</label><button class="button" type="submit">Найти</button></form>';
    $html.='<div class="ag-admin-table"><table class="general"><thead><tr><th>UID</th><th>Пользователь</th><th>Группа MyBB</th><th>Последняя активность</th><th>Локальные</th><th>Внешние</th><th>Локальные оригиналы</th><th></th></tr></thead><tbody>';
    while ($row=$db->fetch_array($query)) {
        $avatar=(string)($row['avatar']??'');if ($avatar!=='' && !preg_match('~^(?:https?://|\.?/?uploads/|images/)~i',$avatar)) $avatar='';
        if ($avatar!=='' && !preg_match('~^https?://~i',$avatar)) $avatar=rtrim((string)$mybb->settings['bburl'],'/').'/'.ltrim($avatar,'./');
        $html.='<tr><td>'.(int)$row['uid_owner'].'</td><td><span class="ag-admin-user">'.($avatar?'<img width="36" height="36" loading="lazy" referrerpolicy="no-referrer" src="'.ag_admin_escape($avatar).'" alt="">':'').ag_admin_escape($row['username']??'Удалённый пользователь').'</span></td><td>'.ag_admin_escape($row['group_title']??'—').'</td><td>'.ag_admin_escape(ag_admin_date((int)$row['lastactive'])).'</td><td>'.(int)$row['local_count'].'</td><td>'.(int)$row['external_count'].'</td><td>'.ag_storage_mb((float)$row['local_bytes']).' МиБ</td><td><a class="button" href="'.ag_admin_escape(ag_admin_url(['uid'=>(int)$row['uid_owner']])).'">Открыть медиатеку</a></td></tr>';
    }
    if (!$total) $html.='<tr><td colspan="8">Пользователи с медиа не найдены.</td></tr>';
    return $html.'</tbody></table></div>'.ag_admin_page($total,$page,$per,['q'=>$q,'inactive_days'=>$days,'sort'=>$sort,'direction'=>$direction]);
}

function ag_admin_library_render(int $uid): string
{
    global $db,$mybb;
    $user=get_user($uid)?:['username'=>'Удалённый пользователь #'.$uid,'lastactive'=>0];
    $stats=$db->fetch_array($db->simple_select('af_gallery_media',"SUM(type='local') AS locals,SUM(type='remote') AS externals,COALESCE(SUM(CASE WHEN type='local' THEN filesize ELSE 0 END),0) AS bytes",'uid_owner='.$uid));
    $albums=[];$albumOptions=[''=>'Все альбомы'];$aq=$db->simple_select('af_gallery_albums','*','uid_owner='.$uid,['order_by'=>'id']);
    while ($album=$db->fetch_array($aq)) {$albums[(int)$album['id']]=$album;$albumOptions[(int)$album['id']]=$album['title'].(!empty($album['system_key'])?' · системный':'');}
    $params=['uid'=>$uid];foreach (['album','provider','status','from','to','min_mb','max_mb','usage','sort','direction'] as $key) $params[$key]=trim($mybb->get_input($key));
    $where='m.uid_owner='.$uid;
    if ($params['album']!=='' && ctype_digit($params['album'])) $where.=' AND EXISTS (SELECT 1 FROM '.TABLE_PREFIX.'af_gallery_album_media am INNER JOIN '.TABLE_PREFIX.'af_gallery_albums a ON a.id=am.album_id WHERE am.media_id=m.id AND a.uid_owner='.$uid.' AND a.id='.(int)$params['album'].')';
    $providerSql="CASE WHEN m.type='local' THEN 'local' WHEN m.provider IN ('imgbb','postimages') THEN m.provider ELSE 'url' END";
    if (in_array($params['provider'],['local','imgbb','postimages','url'],true)) $where.=' AND ('.$providerSql.")='".$params['provider']."'";
    if (in_array($params['status'],['approved','pending','rejected'],true)) $where.=" AND m.status='".$params['status']."'";
    foreach (['from'=>'>=','to'=>'<='] as $key=>$op) if ($params[$key]!=='') {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$params[$key],new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d')!==$params[$key]) throw new RuntimeException('Некорректная дата фильтра.');
        $where.=' AND m.created_at'.$op.($date->getTimestamp()+($key==='to'?86399:0));
    }
    foreach (['min_mb'=>'>=','max_mb'=>'<='] as $key=>$op) if ($params[$key]!=='') {
        if (!is_numeric($params[$key]) || !is_finite((float)$params[$key]) || (float)$params[$key]<0 || (float)$params[$key]>1048576) throw new RuntimeException('Некорректный размер фильтра.');
        $where.=' AND m.filesize'.$op.(int)round((float)$params[$key]*1048576);
    }
    $usage=['avatar'=>'(m.usage_flags & 1)>0','application'=>'(m.usage_flags & 2)>0','other'=>'(m.usage_flags & 4)>0','not_found'=>'m.usage_flags=0 AND m.usage_checked_at>0','unchecked'=>'m.usage_flags=0 AND m.usage_checked_at=0'];
    if (isset($usage[$params['usage']])) $where.=' AND ('.$usage[$params['usage']].')';
    $sorts=['date'=>'m.created_at','size'=>'m.filesize','title'=>'m.title','id'=>'m.id'];if (!isset($sorts[$params['sort']])) $params['sort']='date';
    $direction=$params['direction']==='asc'?'ASC':'DESC';$params['direction']=strtolower($direction);
    $total=(int)$db->fetch_field($db->write_query('SELECT COUNT(*) AS n FROM '.TABLE_PREFIX.'af_gallery_media m WHERE '.$where),'n');
    $per=24;$page=min(max(1,$mybb->get_input('page',MyBB::INPUT_INT)),max(1,(int)ceil($total/$per)));
    $q=$db->write_query('SELECT m.* FROM '.TABLE_PREFIX.'af_gallery_media m WHERE '.$where.' ORDER BY '.$sorts[$params['sort']].' '.$direction.',m.id '.$direction.' LIMIT '.(($page-1)*$per).','.$per);
    $media=[];while ($m=$db->fetch_array($q)) $media[(int)$m['id']]=$m;
    $mediaAlbums=[];
    if ($media) {
        $q=$db->write_query('SELECT am.media_id,a.title FROM '.TABLE_PREFIX.'af_gallery_album_media am INNER JOIN '.TABLE_PREFIX.'af_gallery_albums a ON a.id=am.album_id WHERE a.uid_owner='.$uid.' AND am.media_id IN ('.implode(',',array_keys($media)).')');
        while ($a=$db->fetch_array($q)) $mediaAlbums[(int)$a['media_id']][]=$a['title'];
    }
    $audit=ag_cleanup_audit($uid);
    $html='<p><a href="'.ag_admin_escape(ag_admin_url()).'">← Пользователи</a></p><h2>'.ag_admin_escape($user['username']).' · UID '.$uid.'</h2><p><a target="_blank" rel="noopener" href="'.ag_admin_escape(rtrim((string)$mybb->settings['bburl'],'/').'/member.php?action=profile&uid='.$uid).'">Профиль</a> · Последняя активность: '.ag_admin_escape(ag_admin_date((int)($user['lastactive']??0))).'</p><div class="ag-admin-stats"><span>Локальных: '.(int)$stats['locals'].'</span><span>Внешних: '.(int)$stats['externals'].'</span><span>Оригиналы: '.ag_storage_mb((float)$stats['bytes']).' МиБ</span><span>Альбомов: '.count($albums).'</span></div>';
    $html.='<section class="ag-admin-audit"><p>Проверка связей: '.ag_admin_escape($audit?(['running'=>'в процессе','complete'=>'завершена','partial'=>'частично завершена'][$audit['state']]??$audit['state']).' · '.ag_admin_date((int)($audit['finished_at']?:$audit['started_at'])):'не запускалась').'. <button type="button" class="button" data-ag-audit>Проверить / обновить связи</button> <button type="button" class="button" data-ag-stop hidden>Приостановить проверку</button>'.('<button type="button" class="button" data-ag-resume="'.ag_admin_escape($audit['token']??'').'"'.($audit && $audit['state']==='running'?'':' hidden').'>Продолжить проверку</button>').'</p><p>Проверяются явные ID и прямые ссылки в постах (включая анкеты), аватарах, полях профиля, масках, пресетах, CharacterSheets, подписях и текстовых данных аддонов AF. «Не найдено использование» относится к проверенным источникам на момент проверки и не гарантирует отсутствие внешних, CSS или иных ссылок. Во время проверки не следует редактировать связанные данные.</p></section>';
    $html.='<form method="get" class="ag-admin-filters">'.ag_admin_hidden($uid);
    foreach (['album'=>['Альбом',$albumOptions],'provider'=>['Хранилище',[''=>'Все','local'=>'Local','imgbb'=>'ImgBB','postimages'=>'Postimages','url'=>'URL']],'status'=>['Модерация',[''=>'Все','approved'=>'Одобрено','pending'=>'На проверке','rejected'=>'Отклонено']],'usage'=>['Использование',[''=>'Все','avatar'=>'Аватар','application'=>'Анкета / CharacterSheets','other'=>'Другое известное','not_found'=>'Без найденных связей','unchecked'=>'Не проверено']],'sort'=>['Сортировка',['date'=>'Дата','size'=>'Размер','title'=>'Название','id'=>'ID']],'direction'=>['Порядок',['desc'=>'По убыванию','asc'=>'По возрастанию']]] as $name=>[$label,$options]) $html.='<label>'.$label.ag_admin_select($name,$options,$params[$name]).'</label>';
    foreach (['from'=>['Загружено с','date'],'to'=>['Загружено по','date'],'min_mb'=>['Размер от (МиБ)','number'],'max_mb'=>['Размер до (МиБ)','number']] as $name=>[$label,$type]) $html.='<label>'.$label.ag_admin_input($name,$type,$params[$name]).'</label>';
    $html.='<button class="button" type="submit">Применить</button></form><div class="ag-admin-bulk"><button type="button" class="button" data-ag-select="all">Выделить отображаемые</button> <button type="button" class="button" data-ag-select="none">Снять выделение</button> <button type="button" class="button" data-ag-select="unlinked">Выделить без найденных связей</button> <button type="button" class="button" data-ag-delete>Удалить выбранные</button><span data-ag-selected>Выбрано: 0</span></div><div class="ag-admin-grid">';
    foreach ($media as $id=>$m) {
        $url=$m['type']==='local'?ag_local_media_url($m['storage_path']):$m['remote_url'];if (!preg_match('~^https?://~i',$url)) $url='';
        $thumb=$m['thumb_path'];if ($thumb==='' || ltrim($thumb,'./')===ltrim($m['storage_path'],'./') || $thumb===$m['remote_url']) $thumb=ag_placeholder_thumb_url('Нет миниатюры');
        elseif ($m['type']==='local') $thumb=ag_local_media_url($thumb);
        elseif (!preg_match('~^https?://~i',$thumb)) $thumb=ag_placeholder_thumb_url('Нет миниатюры');
        $protected=!empty($m['cleanup_protected']) || !empty($m['appearance_retained']);$status=ag_admin_status($m);
        $source=$m['type']==='local'?'Local':(['imgbb'=>'ImgBB','postimages'=>'Postimages'][$m['provider']]??'URL');
        $html.='<article class="ag-admin-card" data-media-id="'.$id.'" data-usage="'.$status.'" data-protected="'.(int)$protected.'" data-manual-protected="'.(int)!empty($m['cleanup_protected']).'" data-url="'.ag_admin_escape($url).'"><label><input type="checkbox" data-ag-choice value="'.$id.'"> #'.$id.' · '.ag_admin_escape($m['title']?:$m['original_name']?:'Без названия').'</label><img class="ag-admin-thumb" loading="lazy" decoding="async" referrerpolicy="no-referrer" src="'.ag_admin_escape($thumb).'" alt="Миниатюра"><p>'.ag_admin_escape(implode(', ',$mediaAlbums[$id]??['Без альбома'])).'</p><p>'.ag_admin_escape(ag_admin_date((int)$m['created_at'])).' · '.ag_storage_mb((int)$m['filesize']).' МиБ · <strong>'.ag_admin_escape($source).'</strong></p><p>'.ag_admin_escape(['approved'=>'Одобрено','pending'=>'На проверке','rejected'=>'Отклонено'][$m['status']]??$m['status']).'</p><p class="ag-admin-usage">'.ag_admin_escape(ag_admin_usage_text($m)).'</p>'.(!empty($m['cleanup_protected'])?'<p>Защищено от очистки</p>':'').(!empty($m['appearance_retained'])?'<p>Ранее закреплено механизмом оформления</p>':'').'<div class="ag-admin-card-actions">';
        if ($url!=='') $html.='<a class="button" target="_blank" rel="noopener noreferrer" href="'.ag_admin_escape($url).'">Открыть оригинал</a><button class="button" type="button" data-ag-copy="url">Скопировать URL</button><button class="button" type="button" data-ag-copy="bbcode">Скопировать BBCode</button>';
        $html.='<button type="button" class="button" data-ag-protect>'.(!empty($m['cleanup_protected'])?'Снять защиту':'Сохранить / Защитить от очистки').'</button><label>Переместить'.ag_admin_select('target_album',[''=>'Выберите альбом']+array_diff_key($albumOptions,[''=>true]),'').'</label><button type="button" class="button" data-ag-move>Переместить</button><label>Статус'.ag_admin_select('target_status',['approved'=>'Одобрено','pending'=>'На проверке','rejected'=>'Отклонено'],$m['status']).'</label><button type="button" class="button" data-ag-moderate>Сохранить статус</button><button type="button" class="button" data-ag-delete-one>Удалить из Gallery</button></div></article>';
    }
    if (!$media) $html.='<p>Изображения по этим фильтрам не найдены.</p>';
    $log='<h3>Последние административные действия</h3><table class="general"><tr><th>Когда</th><th>Администратор</th><th>Изображение</th><th>Действие</th></tr>';
    $logs=$db->write_query("SELECT l.*,u.username FROM ".TABLE_PREFIX."af_gallery_logs l LEFT JOIN ".TABLE_PREFIX."users u ON u.uid=l.uid_actor WHERE l.uid_owner=".$uid." AND l.action LIKE 'admin\_%' ORDER BY l.id DESC LIMIT 20");
    while ($entry=$db->fetch_array($logs)) $log.='<tr><td>'.ag_admin_escape(ag_admin_date((int)$entry['created_at'])).'</td><td>'.ag_admin_escape($entry['username']??('#'.$entry['uid_actor'])).'</td><td>#'.(int)$entry['media_id'].'</td><td>'.ag_admin_escape(['admin_delete'=>'Удаление','admin_move'=>'Перемещение','admin_moderate'=>'Модерация','admin_protect'=>'Изменение защиты'][$entry['action']]??$entry['action']).'</td></tr>';
    $log.='</table>';
    return $html.'</div>'.ag_admin_page($total,$page,$per,$params).$log.'<dialog data-ag-confirm><h3>Подтвердите удаление из Gallery</h3><div data-ag-summary></div><label><input type="checkbox" name="allow_linked"> Разрешаю удаление изображений с подтверждёнными связями. Отображение в связанных материалах может нарушиться.</label><label><input type="checkbox" name="allow_protected"> Разрешаю удаление защищённых и ранее закреплённых изображений.</label><label><input type="checkbox" name="allow_unknown"> Разрешаю удаление изображений, использование которых не удалось проверить.</label><p>Внешние файлы остаются на ImgBB, Postimages и других сайтах. Удаляются только записи из Gallery.</p><button type="button" class="button" data-ag-confirm-delete>Удалить выбранные</button> <button type="button" class="button" data-ag-cancel>Отмена</button></dialog>';
}

function ag_admin_media_render(): string
{
    global $mybb;
    $uid=$mybb->get_input('uid',MyBB::INPUT_INT);
    if ($mybb->request_method==='post' && $mybb->get_input('ag_op')!=='') {
        try {$data=['ok'=>true]+ag_admin_media_api($uid);}
        catch (RuntimeException $e) {$data=['ok'=>false,'error'=>$e->getMessage()];}
        catch (Throwable $e) {error_log('Gallery ACP: '.$e->getMessage());$data=['ok'=>false,'error'=>'Не удалось выполнить действие. Подробности в серверном журнале.'];}
        // AF renders its header before addon dispatch. An inert JSON envelope also preserves ACP session errors.
        return '<script type="application/json" data-ag-admin-result>'.json_encode($data,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE).'</script>';
    }
    $html=ag_admin_assets().ag_admin_nav(true).'<section class="ag-admin-media af-box" data-ag-admin data-uid="'.$uid.'" data-endpoint="'.ag_admin_escape(ag_admin_url(['uid'=>$uid])).'" data-post-key="'.ag_admin_escape($mybb->post_code).'"><p role="status" aria-live="polite" data-ag-feedback></p>';
    try {$html.=$uid>0?ag_admin_library_render($uid):ag_admin_users_render();}
    catch (RuntimeException $e) {$html.='<p role="alert">'.ag_admin_escape($e->getMessage()).'</p>';}
    return $html.'</section>';
}

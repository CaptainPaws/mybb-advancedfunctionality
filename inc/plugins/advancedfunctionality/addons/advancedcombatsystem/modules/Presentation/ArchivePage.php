<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/../Application/ArchiveService.php';
/** Explicit lazy navigation. No JS, polling, prefetch or thread-wide history query. */
final class AF_ACS_ArchivePage
{
    private static function esc(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public static function render(AF_ACS_ArchiveService $service,array $r):string
    {
        $sid=AF_ACS_Repository::integer($r['session_id']??'');$eid=AF_ACS_Repository::integer($r['encounter_id']??'');$f=array_intersect_key($r,array_flip(['round','actor','entity','control_group','category','event_type','pid','search']));$p=$service->page($sid,$eid,$f,$r['cursor']??'');
        $base=['action'=>'acs_archive','session_id'=>$sid,'encounter_id'=>$eid]+$f;ob_start();echo '<h2>Боевой архив</h2><form method="get" action="misc.php">';foreach(['action'=>'acs_archive','session_id'=>$sid,'encounter_id'=>$eid] as $k=>$v)echo '<input type="hidden" name="'.self::esc($k).'" value="'.self::esc($v).'">';
        foreach(['round'=>'Раунд','actor'=>'Действующий','entity'=>'Участник','control_group'=>'Группа','category'=>'Категория','event_type'=>'Тип события','pid'=>'PID','search'=>'Поиск'] as $k=>$label)if($p['identity_filters']||!in_array($k,['actor','entity','control_group'],true))echo '<label>'.self::esc($label).' <input name="'.self::esc($k).'" value="'.self::esc($f[$k]??'').'"></label> ';echo '<button>Найти</button></form>';
        if($p['visibility']==='reveal_all'||!array_filter(array_intersect_key($f,array_flip(['actor','entity','control_group']))))foreach(['json','csv'] as $format)echo '<a href="'.self::esc('xmlhttp.php?'.http_build_query(['action'=>'af_addon_api','addon'=>'advancedcombatsystem','op'=>'archive_export','session_id'=>$sid,'encounter_id'=>$eid,'format'=>$format]+$f)).'">'.strtoupper($format).'</a> ';
        global $mybb;
        if($p['can_manage']){
            echo '<form method="post" action="misc.php?action=acs_archive">';
            foreach(['session_id'=>$sid,'encounter_id'=>$eid,'operation_key'=>bin2hex(random_bytes(16)),'my_post_key'=>$mybb->post_code??'']+$p['management'] as $k=>$v)echo '<input type="hidden" name="'.self::esc($k).'" value="'.self::esc($v).'">';
            echo '<label>Видимость архива <select name="mode">';foreach(['keep_hidden','reveal_results','reveal_all'] as $mode)echo '<option'.($p['visibility']===$mode?' selected':'').'>'.$mode.'</option>';echo '</select></label> <label>Причина <input name="reason" required maxlength="1000"></label> <button>Изменить видимость</button></form>';
            if($p['can_export_extended'])foreach(['json','csv'] as $format)echo '<a href="'.self::esc('xmlhttp.php?'.http_build_query(['action'=>'af_addon_api','addon'=>'advancedcombatsystem','op'=>'archive_export','session_id'=>$sid,'encounter_id'=>$eid,'format'=>$format,'extended'=>'1']+$f)).'">GM '.strtoupper($format).'</a> ';
        }
        foreach($p['events'] as $e){echo '<article><h3>'.self::esc($e['type']).' · #'.self::esc($e['event_id']).'</h3>';if(!empty($e['post']['url']))echo '<a href="'.self::esc($e['post']['url']).'">Исходный пост</a>';echo '<pre>'.self::esc(json_encode($e,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)).'</pre></article>';}
        if(!$p['events'])echo '<p>Нет доступных событий на этой странице.</p>';if($p['next_cursor']!==null)echo '<a href="'.self::esc('misc.php?'.http_build_query($base+['cursor'=>$p['next_cursor']])).'">Следующая страница</a>';return ob_get_clean();
    }
}

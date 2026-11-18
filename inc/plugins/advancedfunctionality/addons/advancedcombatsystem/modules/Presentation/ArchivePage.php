<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/../Application/ArchiveService.php';
/** Lazy, native journal and archive. Only authorized service DTOs enter HTML. */
final class AF_ACS_ArchivePage
{
    private static function esc(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function url(array $r):string{return 'misc.php?'.http_build_query($r);}
    private static function label(string $key):string
    {
        return ['combat.resolved'=>'Подтверждённый бой','encounter.outcome_confirm'=>'Подтверждённый исход','correction.review'=>'Пересмотр результата','combat'=>'Бой','round'=>'Раунд','encounter'=>'Столкновение','loot'=>'Добыча','reward'=>'Награды','inventory'=>'Имущество','correction'=>'Corrections','achievement'=>'Достижения','archive'=>'Архив','status'=>'Состояние','reward_type'=>'Тип награды','reward_version'=>'Версия','recipient_key'=>'Получатель','qty'=>'Количество','recipient_group_id'=>'Группа получателя','recipient_uid'=>'UID получателя','allocation_version'=>'Версия распределения','source'=>'Источник','reference'=>'Ссылка на обязательство','recovery_scope'=>'Разрешённое восстановление','costs'=>'Расходы','components'=>'Компоненты','children'=>'Связанные действия','rolls'=>'Броски','probabilities'=>'Вероятности','calculations'=>'Подробные вычисления','other'=>'Прочее','victory'=>'Победа','defeat'=>'Поражение','draw'=>'Ничья','escape'=>'Выход из боя','inconclusive'=>'Без окончательного исхода'][$key]??$key;
    }
    private static function values(mixed $v):string
    {
        if(!is_array($v))return self::esc(is_bool($v)?($v?'Да':'Нет'):($v??'—'));
        $html='<dl>';foreach($v as $key=>$value){if($value===[]||$value===null)continue;$html.='<dt>'.self::esc(self::label((string)$key)).'</dt><dd>'.self::values($key==='status'&&is_string($value)?(['pending'=>'Ожидает · не выдано','prepared'=>'Намерение сохранено','recovery_required'=>'Требует восстановления','issued'=>'Выдано','blocked'=>'Заблокировано','generated'=>'Сгенерировано · распределяется','awaiting_issue'=>'Победитель определён · ожидает выдачи','pending_issue'=>'Выдача не подтверждена','issue_rejected'=>'Owner отклонил выдачу','allocation_closed'=>'Распределение закрыто'][$value]??$value):$value).'</dd>';}return $html.'</dl>';
    }
    private static function event(array $e):string
    {
        $id=self::esc($e['event_id']);$html='<article id="acs-event-'.$id.'"><h3>'.self::esc(self::label($e['type'])).' · #'.$id.'</h3>';
        if(isset($e['round']))$html.='<p>Раунд '.self::esc($e['round']).'</p>';
        if(!empty($e['post']['url']))$html.='<p><a href="'.self::esc($e['post']['url']).'">Исходный MyBB-пост</a></p>';
        elseif(isset($e['post']))$html.='<p>Исходный пост недоступен; сохранённая история остаётся в архиве.</p>';
        if($e['detail_level']==='hidden')return $html.'<p>Бой подтверждён. Механические подробности скрыты политикой архива.</p></article>';
        if($e['detail_level']==='approximate')$html.='<p>Разрешены только частичные сведения; это не полный механический отчёт.</p>';
        if(isset($e['outcome']))$html.='<p>Итог: '.self::esc(self::label($e['outcome']['kind'])).'</p>';
        if(isset($e['correction']))$html.='<p>Связано с событием #'.self::esc($e['correction']['original_event_id']).' · revision '.self::esc($e['correction']['revision']).' · '.self::esc($e['correction']['disposition']).'. Текущая revision '.self::esc($e['correction']['current_revision']).' · '.self::esc($e['correction']['current_disposition']).'. Исходное событие не заменено.</p>';
        if(!empty($e['actions'])){
            $html.='<details><summary>Действия по порядку</summary><ol>';
            foreach($e['actions'] as $a){$html.='<li><p>'.self::esc($a['ability_key']??'Подтверждённое действие');if(isset($a['actor_id']))$html.=' · исполнитель #'.self::esc($a['actor_id']);$html.=' · '.self::esc($a['outcome']).'</p>';
                if(isset($a['targets']))$html.='<p>Цели: '.self::esc(implode(', ',$a['targets'])).'</p>';
                if(isset($a['damage']))$html.='<p>Сохранённый бюджет урона: '.self::esc($a['damage']).' (не сумма фактической потери HP)</p>';
                $details=array_intersect_key($a,array_flip(['costs','components','children','rolls','probabilities','calculations']));
                if(array_filter($details))$html.='<details><summary>Разрешённые расходы, формулы и причинные связи</summary>'.self::values($details).'</details>';
                $html.='</li>';}$html.='</ol></details>';
        }
        if(isset($e['reason']))$html.='<p>Причина: '.self::esc($e['reason']).'</p>';
        return $html.'</article>';
    }
    public static function render(AF_ACS_ArchiveService $service,array $r):string
    {
        global $mybb;
        $sid=AF_ACS_Repository::integer($r['session_id']??'');$eid=AF_ACS_Repository::integer($r['encounter_id']??'');
        $f=array_filter(array_intersect_key($r,array_flip(['round','actor','entity','control_group','category','event_type','pid','search'])),static fn($v)=>$v!=='');
        $p=$service->page($sid,$eid,$f,$r['cursor']??'');$base=['action'=>'acs_archive','session_id'=>$sid,'encounter_id'=>$eid];
        $section=$r['archive_section']??'journal';if(!in_array($section,['journal','participants','rewards','loot','obligations'],true))$section='journal';
        $html='<section class="af-acs af-acs-archive"><h1>Боевой журнал и архив</h1><p>Столкновение #'.self::esc($eid).' · '.self::esc($p['encounter_status']).'</p>';
        if($p['outcome'])$html.='<p>Подтверждённый итог: '.self::esc(self::label($p['outcome']['kind'])).(isset($p['outcome']['winner_side_id'])?' · сторона #'.self::esc($p['outcome']['winner_side_id']):'').'</p>';
        else $html.='<p>Разрешённый подтверждённый итог не предоставлен. Отсутствие данных не означает отсутствие боя.</p>';
        $html.='<nav aria-label="Разделы архива">';$sections=['journal'=>'История'];if($p['can_manage'])$sections['participants']='Участники';if($p['can_export_extended'])$sections+=['rewards'=>'Награды','loot'=>'Добыча','obligations'=>'Обязательства'];
        foreach($sections as $key=>$label)$html.='<a '.($section===$key?'aria-current="page" ':'').'href="'.self::esc(self::url($base+['archive_section'=>$key])).'">'.$label.'</a> ';
        $html.='<a href="'.self::esc(self::url($base+['category'=>'correction'])).'">Связанные corrections</a></nav>';
        if($section==='participants'){
            $m=$service->roster($sid,$eid,$r['manifest_after']??'0');
            $html.='<h2>Зарегистрированные участники</h2><p>Текущие имена и состояние участия, только для GM. Исторические состояния и действия смотрите в журнале.</p><ul>';
            foreach($m['items'] as $item)$html.='<li>'.self::esc($item['name']).' · '.self::esc($item['kind']).' #'.self::esc($item['entity_id']).' · сторона '.self::esc($item['side_id']).' · '.self::esc($item['participation']).'</li>';
            $html.='</ul>';if(!$m['items'])$html.='<p>На этой странице записей нет.</p>';
            if($m['next_after']!==null)$html.='<p><a href="'.self::esc(self::url($base+['archive_section'=>'participants','manifest_after'=>$m['next_after']])).'">Следующие участники</a></p>';
            return $html.'</section>';
        }
        if($section!=='journal'){
            // Existing extended-export permission is deliberately retained for current overlays.
            $m=$service->manifest($sid,$eid,$section,$r['manifest_after']??'0');
            $html.='<h2>'.self::esc($sections[$section]??'Сведения').'</h2><p>Текущее состояние; оно не заменяет immutable историю.</p>';
            if($section==='obligations'){
                $html.='<p>Обязательства всей сессии. Перечень неполный: достижения и связанные с ними награды исключены существующим контрактом.</p>';
                foreach(['pending'=>'Ожидает исполнения','recovery_required'=>'Требует восстановления','explicitly_blocked'=>'Заблокировано'] as $key=>$label)$html.='<h3>'.$label.'</h3>'.self::values($m['items'][$key]??[]);
                $html.='<p><a href="misc.php?action=acs_loot&amp;session_id='.self::esc($sid).'&amp;encounter_id='.self::esc($eid).'&amp;completion_tab=recovery">Открыть разрешённое восстановление</a></p>';
            }else{
                foreach($m['items'] as $item)$html.='<article><h3>Запись #'.self::esc($item['id']).'</h3>'.self::values($item).'</article>';
                if(!$m['items'])$html.='<p>На этой странице нет разрешённых записей.</p>';
            }
            if($m['next_after']!==null)$html.='<a href="'.self::esc(self::url($base+['archive_section'=>$section,'manifest_after'=>$m['next_after']])).'">Следующая страница</a>';
            return $html.'</section>';
        }
        $html.='<form method="get" action="misc.php">';foreach($base as $k=>$v)$html.='<input type="hidden" name="'.$k.'" value="'.self::esc($v).'">';
        foreach(['round'=>'Раунд','actor'=>'Исполнитель (ID)','entity'=>'Участник (ID)','control_group'=>'Группа (ID)','event_type'=>'Тип события','pid'=>'Исходный PID','search'=>'Поиск по разрешённым сведениям'] as $k=>$label)if($p['identity_filters']||!in_array($k,['actor','entity','control_group'],true))$html.='<label>'.$label.' <input name="'.$k.'" '.($k==='search'?'maxlength="120" ':'').'value="'.self::esc($f[$k]??'').'"></label>';
        $html.='<label>Категория <select name="category"><option value="">Все</option>';foreach(AF_ACS_Archive::CATEGORIES as $category)$html.='<option value="'.$category.'"'.(($f['category']??'')===$category?' selected':'').'>'.self::esc(self::label($category)).'</option>';
        $html.='</select></label><button>Применить фильтры</button><a href="'.self::esc(self::url($base)).'">Сбросить</a></form>';
        if($p['identity_filters']){$ids=[];foreach($p['events'] as $event)foreach($event['entities']??[] as $id)$ids[$id]=$id;
            if($ids)$html.='<details><summary>Участники видимых событий этой страницы</summary><p>'.self::esc(implode(', ',$ids)).'</p><p>Это не полный состав столкновения.</p></details>';}
        foreach($p['events'] as $event)$html.=self::event($event);
        if(!$p['events'])$html.='<p>На этой странице доступных событий нет.</p>';
        if($p['next_cursor']!==null)$html.='<p><a href="'.self::esc(self::url($base+$f+['cursor'=>$p['next_cursor']])).'">Следующая страница истории</a></p>';
        $html.='<details><summary>JSON / CSV экспорт</summary><p>Файл содержит одну серверную страницу. Продолжение выдаётся API в X-ACS-Next-Cursor; это не полный архив одним файлом.</p>';
        foreach([false,true] as $extended){if($extended&&!$p['can_export_extended'])continue;if(!$extended&&$p['visibility']!=='reveal_all'&&array_filter(array_intersect_key($f,array_flip(['actor','entity','control_group']))))continue;
            foreach(['json','csv'] as $format)$html.='<a href="'.self::esc('xmlhttp.php?'.http_build_query(['action'=>'af_addon_api','addon'=>'advancedcombatsystem','op'=>'archive_export','session_id'=>$sid,'encounter_id'=>$eid,'format'=>$format,'extended'=>$extended?'1':'0']+$f)).'">'.($extended?'Расширенный GM ':'Обычный ').strtoupper($format).'</a> ';}
        $html.='</details>';
        if($p['can_manage']){
            $html.='<details><summary>Политика видимости архива</summary><form method="post" action="misc.php?action=acs_archive">';
            foreach(['session_id'=>$sid,'encounter_id'=>$eid,'operation_key'=>bin2hex(random_bytes(16)),'my_post_key'=>$mybb->post_code??'']+$p['management'] as $k=>$v)$html.='<input type="hidden" name="'.$k.'" value="'.self::esc($v).'">';
            $html.='<label>Видимость <select name="mode">';foreach(['keep_hidden'=>'Сохранить секретность','reveal_results'=>'Разрешить частичные результаты','reveal_all'=>'Раскрыть полный разрешённый бой'] as $mode=>$label)$html.='<option value="'.$mode.'"'.($p['visibility']===$mode?' selected':'').'>'.$label.'</option>';
            $html.='</select></label><p>Изменение политики может раскрыть сведения участникам и отменяет старые курсоры. Оно не пересчитывает бой.</p><label>Причина <input name="reason" required maxlength="1000"></label><button>Изменить видимость</button></form></details>';
        }
        return $html.'</section>';
    }
}

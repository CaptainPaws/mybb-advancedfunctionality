<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

final class AF_ACS_ThreadPanel
{
    private static function esc(mixed $v): string {return htmlspecialchars_uni((string)$v);}
    public static function render(array $v,string $tid): string
    {
        $esc=self::esc(...);$sid=$v['session_id'];$eid=$v['encounter_id'];$id='acs-panel-'.$sid;
        $state=['preparation'=>'Подготовка','ambush_ready'=>'Подготовка','active'=>'Активно','paused'=>'Пауза','pending_resolution'=>'Ожидание результата','completed'=>'Завершено'][$v['status']]??'Состояние недоступно';
        $turns=['pending'=>'Ожидает хода','resolved'=>'Ход завершён','passed'=>'Ход пропущен','forfeited'=>'Ход закрыт'];
        $kinds=['character'=>'Персонаж','pet'=>'Питомец','summon'=>'Призыв','npc'=>'NPC','monster'=>'Монстр'];
        $summary=$esc($v['title']).' · '.$esc($state).' · Раунд '.$esc($v['round']);
        foreach($v['groups'] as $g)if($g['own']&&$g['ap']!==null)$summary.=' · AP '.$esc($g['ap'][0]).' / '.$esc($g['ap'][1]);
        ob_start();
        echo '<section class="af-acs-panel" data-acs-panel id="'.$id.'"><div class="af-acs-panel__anchor"></div><div class="af-acs-panel__compact" hidden><button type="button" data-acs-open aria-controls="'.$id.'-body">'.$summary.' · Открыть панель</button></div><details class="af-acs-panel__details" open><summary>'.$summary.'</summary><div id="'.$id.'-body" class="af-acs-panel__body"><button type="button" data-acs-close hidden>Свернуть</button>';
        echo '<p>Порядок ходов свободный. Ниже показаны ходы доступных вам групп.</p><nav aria-label="Действия столкновения">';
        $base='showthread.php?tid='.$tid;
        if($v['can_build'])echo '<a href="'.$esc($base.'&acs_builder='.$sid).'">Открыть конструктор</a> ';
        echo '<a href="misc.php?action=acs_archive&amp;session_id='.$esc($sid).'&amp;encounter_id='.$esc($eid).'">Журнал</a> <a href="misc.php?action=acs_loot&amp;session_id='.$esc($sid).'&amp;encounter_id='.$esc($eid).'">Добыча</a> ';
        if($v['can_manage'])echo '<a href="'.$esc($base.'&acs_session='.$sid.'&acs_encounter='.$eid).'">Панель GM</a>';
        echo '</nav><div class="af-acs-panel__filters" hidden><label>Сторона <select data-acs-side><option value="">Все доступные</option>';
        foreach($v['sides'] as $key=>$name)echo '<option value="'.$esc($key).'">'.$esc($name).'</option>';
        echo '</select></label><label>Группа <select data-acs-group><option value="">Все доступные</option>';
        foreach($v['groups'] as $g)echo '<option value="'.$esc($g['id']).'">'.$esc($g['members'][0]['name']).($g['own']?' · Ваша группа':'').'</option>';
        echo '</select></label></div><div class="af-acs-panel__groups">';
        foreach($v['groups'] as $g){
            echo '<section class="af-acs-panel__group" data-group="'.$esc($g['id']).'" data-side="'.$esc($g['side']).'"><h3>'.($g['own']?'Ваша группа · ':'').$esc($g['members'][0]['name']).'</h3>';
            if($g['side']!==''&&isset($v['sides'][$g['side']]))echo '<p>'.$esc($v['sides'][$g['side']]).'</p>';
            if($g['ap']!==null)echo '<p>Общий AP: '.$esc($g['ap'][0]).' / '.$esc($g['ap'][1]).' · '.$esc($turns[$g['turn']]??'Ход недоступен').'</p>';
            if($g['remaining']!==null)echo '<p>Осталось: <span data-acs-seconds="'.$esc($g['remaining']).'" data-paused="'.($v['status']==='paused'?'1':'0').'">'.$esc($g['remaining']).' с</span></p>';
            echo '<ul>';
            foreach($g['members'] as $m){echo '<li><strong>'.$esc($m['name']).'</strong>'.($m['kind']!==null?' · '.$esc($kinds[$m['kind']]??'Существо'):'');
                if($m['hp']!==null)echo '<p>HP: '.$esc($m['hp'][0]).' / '.$esc($m['hp'][1]).'</p>';
                foreach($m['resources'] as $r)echo '<p>'.$esc($r[0]).': '.$esc($r[1]).' / '.$esc($r[2]).'</p>';
                foreach($m['barriers'] as $r)echo '<p>Барьер: '.$esc($r[0]).' / '.$esc($r[1]).'</p>';
                foreach($m['statuses'] as $r)echo '<p>Статус: '.$esc($r[0]).' × '.$esc($r[1]).'</p>';
                echo '</li>';
            }
            echo '</ul></section>';
        }
        echo '</div><p data-acs-empty hidden>Нет доступных участников для выбранного фильтра.</p></div></details></section>';
        return ob_get_clean();
    }
}

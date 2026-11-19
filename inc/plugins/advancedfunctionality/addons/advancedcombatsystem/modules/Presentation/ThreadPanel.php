<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

final class AF_ACS_ThreadPanel
{
    private static function esc(mixed $value): string { return htmlspecialchars_uni((string)$value); }

    /** Only already-discovered memberships are handed to this presentation helper. */
    public static function sessions(array $sessions,string $tid,?string $selected,?string $next,string $after): string
    {
        $esc=self::esc(...);$base='showthread.php?tid='.$tid;
        if(count($sessions)===1&&$selected!==null&&$next===null&&$after==='0')return '';
        $html='<form class="af-acs-panel__sessions" method="get" action="showthread.php"><input type="hidden" name="tid" value="'.$esc($tid).'"><label>Сессия ACS <select name="acs_panel_session" required><option value="">Выберите сессию</option>';
        $states=['active'=>'Активна','pending_close'=>'Ожидает закрытия','closed'=>'Закрыта','archived'=>'В архиве'];
        foreach($sessions as $row){$id=(string)$row['id'];$html.='<option value="'.$esc($id).'"'.($id===$selected?' selected':'').'>Сессия #'.$esc($id).' · '.$esc($states[$row['status']]??'Состояние недоступно').'</option>';}
        $html.='</select></label> <button class="atf-button atf-button--secondary" type="submit">Открыть</button></form>';
        if($after!=='0')$html.='<p><a href="'.$esc($base).'">К началу списка сессий</a></p>';
        if($next!==null)$html.='<p><a href="'.$esc($base.'&acs_sessions_after='.$next).'">Другие доступные сессии</a></p>';
        return $html;
    }

    public static function gmUrl(array $view,string $tid): string
    {
        $url='showthread.php?tid='.$tid.'&acs_session='.$view['session_id'].'&acs_setup=1';
        if ($view['encounter_id']!==null) $url.='&acs_encounter='.$view['encounter_id'];
        return $url.'&gm_step='.($view['encounter_id']!==null ? '1' : '2').'#acs-thread-panel';
    }

    public static function builderUrl(array $view,string $tid): ?string
    {
        if (!$view['can_build'] || !in_array($view['status']??null,['preparation','active','paused'],true)) return null;
        return 'showthread.php?tid='.$tid.'&acs_builder='.$view['session_id']
            .'&acs_encounter='.$view['encounter_id'].'#acs-thread-panel';
    }

    private static function action(string $url,string $label,string $tool): string
    {
        return '<a class="atf-button atf-button--secondary" data-acs-modal="'.self::esc($tool)
            .'" href="'.self::esc($url).'">'.self::esc($label).'</a>';
    }

    public static function missing(string $setupUrl): string
    {
        return '<section class="af-acs-combat-bar"><strong>Боевой эпизод</strong>'
            .self::action($setupUrl,'Настроить бой','gm').'</section>';
    }

    public static function render(array $view,string $tid,bool $canOpenGm): string
    {
        $sid=$view['session_id'];$eid=$view['encounter_id'];
        $states=['preparation'=>'Подготовка','ambush_ready'=>'Готовность засады','active'=>'Активно',
            'paused'=>'Пауза','pending_resolution'=>'Ожидание результата','completed'=>'Завершено',
            'pending_close'=>'Ожидает закрытия','closed'=>'Закрыта','archived'=>'В архиве'];
        $state=$eid===null && $view['session_status']==='active'
            ? 'Подготовка · столкновение не создано'
            : ($states[$view['status']??$view['session_status']]??'Состояние недоступно');
        $title=trim((string)($view['title']??'')) ?: 'Сессия ACS #'.$sid;
        $meta=$state;
        if ((int)($view['round']??0)>0) $meta.=' · Раунд '.$view['round'];
        $html='<section class="af-acs-combat-bar" aria-label="Панель боя" data-acs-combat-bar>'
            .'<div><strong>'.self::esc($title).'</strong><p>'.self::esc($meta).'</p></div>';
        $own=[];$visible=[];
        foreach ($view['groups']??[] as $group) {
            if (!$group['own']) {
                foreach ($group['members'] as $member) $visible[]=$member['name'];
                continue;
            }
            $members=[];
            foreach ($group['members'] as $member) {
                $members[]=$member['name'].($member['hp']===null?'':' · HP '.implode(' / ',$member['hp']));
            }
            $own[]=implode(', ',$members).($group['ap']===null?'':' · AP '.implode(' / ',$group['ap']));
        }
        if ($own) $html.='<ul><li>'.implode('</li><li>',array_map(self::esc(...),$own)).'</li></ul>';
        if ($visible) {
            $html.='<p>В поле зрения: '.self::esc(implode(', ',array_slice($visible,0,5)))
                .(count($visible)>5?'…':'').'</p>';
        }

        $actions='';
        if ($canOpenGm) $actions.=self::action(self::gmUrl($view,$tid),$eid===null?'Настроить бой':'Управление боем','gm');
        $builder=self::builderUrl($view,$tid);
        if ($builder!==null) $actions.=self::action($builder,'Конструктор действий','builder');
        if ($actions!=='') $html.='<nav aria-label="Действия ACS">'.$actions.'</nav>';
        return $html.'</section>';
    }
}

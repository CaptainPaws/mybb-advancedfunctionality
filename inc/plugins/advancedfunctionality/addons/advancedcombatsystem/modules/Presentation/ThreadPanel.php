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
        return $url.'&gm_stage=settings#acs-thread-panel';
    }

    public static function builderUrl(array $view,string $tid): ?string
    {
        if (!$view['can_build'] || !in_array($view['status']??null,['preparation','active','paused'],true)) return null;
        return 'showthread.php?tid='.$tid.'&acs_builder='.$view['session_id']
            .'&acs_encounter='.$view['encounter_id'].'#acs-thread-panel';
    }

    private static function icon(string $name): string
    {
        return '<i class="fa-solid fa-'.$name.'" aria-hidden="true"></i>';
    }

    public static function swords(): string
    {
        return '<svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'
            .'<path d="m13.5 10.5 6-6H22V7l-6 6M2 22l5-5m-2-2 4 4m1.5-5.5L4 7V4h3l11.5 11.5M22 22l-5-5m2-2-4 4"/></svg>';
    }

    private static function action(string $url,string $label,string $tool): string
    {
        return '<a class="atf-button atf-button--secondary af-acs-combat-bar__action af-acs-combat-bar__action--'.$tool
            .'" data-acs-modal="'.$tool.'" href="'.self::esc($url).'">'.self::swords().self::esc($label).'</a>';
    }

    private static function frame(): string
    {
        return function_exists('af_adaptivethemeframework_gothic_frame') ? af_adaptivethemeframework_gothic_frame() : '';
    }

    public static function missing(string $setupUrl): string
    {
        return '<section class="af-acs-combat-bar atf-ornate">'.self::frame().'<strong>Боевой эпизод</strong>'
            .'<nav class="af-acs-combat-bar__actions" aria-label="Действия ACS">'.self::action($setupUrl,'Настроить бой','gm').'</nav></section>';
    }

    private static function avatar(array $member): string
    {
        $url=$member['avatar'];
        $img=preg_match('~^(https?://|(?:\./)?(?:uploads|images)/)[^\x00-\x20]+$~iD',$url)
            ? '<img src="'.self::esc($url).'" alt="" loading="lazy" decoding="async">' : '';
        $icon=match($member['kind']){'npc','monster','summon'=>'dragon',default=>'user'};
        return '<span class="af-acs-combat-bar__avatar" aria-hidden="true">'.self::icon($icon).$img.'</span>';
    }

    private static function meter(string $type,?array $values,bool $compact=false): string
    {
        $ap=$type==='ap';
        $label=$ap?'AP':'HP';
        $percent=$values!==null && $values[1]>0 ? min(100,max(0,100*(float)$values[0]/(float)$values[1])) : 0;
        $percentText=number_format($percent,0,'.','').'%';
        $text=$values===null?'—':implode(' / ',$values);
        $html='<span class="af-acs-combat-bar__meter af-acs-combat-bar__meter--'.$type.'"'.($compact?' aria-hidden="true"':'').'>';
        if (!$compact) $html.='<span>'.self::icon($ap?'bolt':'heart').' '.$label.' '.self::esc($text).'</span>';
        $segments=$ap && $values!==null && $values[1]>0 && $values[1]<=10 ? (int)$values[1] : 0;
        $html.='<span class="af-acs-combat-bar__track" aria-hidden="true">';
        if ($segments) {
            for ($i=0;$i<$segments;$i++) {
                $html.='<span class="af-acs-combat-bar__segment'.($i<$values[0]?' is-filled':'').'"></span>';
            }
        } elseif ($values!==null) {
            $html.='<span class="af-acs-combat-bar__fill" style="width:'.number_format($percent,6,'.','').'%"></span>';
        } elseif ($compact) {
            $html.='—';
        }
        $html.='</span>';
        if (!$compact) $html.='<span>'.($values===null?'—':$percentText).'</span>';
        return $html.'</span>';
    }

    private static function characters(array $groups): string
    {
        $html='';
        foreach ($groups as $group) {
            if (!$group['own']) continue;
            $shared=count($group['members'])>1;
            $html.='<div class="af-acs-combat-bar__party">';
            foreach ($group['members'] as $member) {
                $html.='<div class="af-acs-combat-bar__character">'.self::avatar($member)
                    .'<div class="af-acs-combat-bar__vitals"><strong>'.self::esc($member['name']).'</strong>'
                    .self::meter('hp',$member['hp']);
                if (!$shared) $html.=self::meter('ap',$member['round_member']?$group['ap']:null);
                $html.='</div></div>';
            }
            if ($shared) {
                $html.='<div class="af-acs-combat-bar__vitals af-acs-combat-bar__shared"><strong>Общий бюджет группы управления</strong>'
                    .self::meter('ap',$group['ap']).'</div>';
            }
            $html.='</div>';
        }
        return $html ?: '<p class="af-acs-combat-bar__empty">Нет ваших участников</p>';
    }

    private static function roster(array $groups): string
    {
        $sides=[];$total=0;
        foreach ($groups as $group) {
            foreach ($group['members'] as $member) {
                $key=$member['side_id']===null?'unassigned':'side-'.$member['side_id'];
                $sides[$key]['name']=$member['side_name']??'Без стороны';
                $member['ap']=$member['round_member']?$group['ap']:null;
                $sides[$key]['members'][]=$member;
                ++$total;
            }
        }
        if (!$total) return '<p class="af-acs-combat-bar__empty">Нет участников</p>';
        uasort($sides,static function (array $a,array $b): int {
            $order=['Игроки'=>0,'Враги'=>1,'Без стороны'=>3];
            return ($order[$a['name']]??2)<=>($order[$b['name']]??2);
        });
        $html='<div class="af-acs-combat-bar__roster"><h3>'.self::icon('users').' Состав столкновения ('.$total.')</h3>'
            .'<div class="af-acs-combat-bar__sides">';
        foreach ($sides as $side) {
            $name=$side['name'];
            $variant=match($name){'Игроки'=>'players','Враги'=>'enemies',default=>'neutral'};
            $html.='<section class="af-acs-combat-bar__side af-acs-combat-bar__side--'.$variant.'"><h4>'
                .self::icon($variant==='enemies'?'skull':'users').' '.self::esc($name).' ('.count($side['members']).')</h4>'
                .'<div class="af-acs-combat-bar__carousel" data-acs-carousel>'
                .'<button type="button" data-acs-step="-1" aria-label="Предыдущие участники: '.self::esc($name).'" disabled>'
                .self::icon('chevron-left').'</button>'
                .'<div class="af-acs-combat-bar__rail" data-acs-rail role="group" tabindex="0" aria-label="'.self::esc($name).'">';
            foreach ($side['members'] as $member) {
                $stats='HP '.($member['hp']===null?'—':implode(' / ',$member['hp']))
                    .' · AP группы '.($member['ap']===null?'—':implode(' / ',$member['ap']));
                $label=self::esc($member['name'].' · '.$stats);
                $html.='<div class="af-acs-combat-bar__member" tabindex="0" role="group" aria-label="'.$label.'" title="'.$label.'">'
                    .self::avatar($member).'<span class="af-acs-combat-bar__name" aria-hidden="true">'.self::esc($member['name']).'</span>'
                    .self::meter('hp',$member['hp'],true).self::meter('ap',$member['ap'],true)
                    .'<span class="af-acs-combat-bar__member-info" aria-hidden="true">'.self::esc($member['name']).'<br>'.self::esc($stats).'</span></div>';
            }
            $html.='</div><button type="button" data-acs-step="1" aria-label="Следующие участники: '.self::esc($name).'" disabled>'
                .self::icon('chevron-right').'</button></div></section>';
        }
        return $html.'</div></div>';
    }

    public static function render(array $view,string $tid,bool $canOpenGm): string
    {
        $sid=$view['session_id'];$eid=$view['encounter_id'];
        $states=['preparation'=>'Подготовка','ambush_ready'=>'Готовность засады','active'=>'Активно',
            'paused'=>'Пауза','pending_resolution'=>'Ожидание результата','completed'=>'Завершено',
            'pending_close'=>'Ожидает закрытия','closed'=>'Закрыта','archived'=>'В архиве'];
        $status=$view['status']??$view['session_status'];
        $state=$eid===null && $status==='active'
            ? 'Подготовка · столкновение не создано'
            : ($states[$status]??'Состояние недоступно');
        $title=trim((string)($view['title']??'')) ?: 'Сессия ACS #'.$sid;
        $roster=$view['can_view_roster']??false;
        $html='<section class="af-acs-combat-bar atf-ornate'.($roster?' af-acs-combat-bar--gm':'')
            .'" aria-label="Панель боя" data-acs-combat-bar>'.self::frame()
            .'<div class="af-acs-combat-bar__encounter"><span class="af-acs-combat-bar__crest" aria-hidden="true">'
            .self::icon('shield').self::swords().'</span><div><h2>'.self::esc($title).'</h2>'
            .'<p><span class="af-acs-combat-bar__status'.($eid!==null && $status==='active'?' is-active':'').'">'.self::esc($state).'</span>'
            .((int)($view['round']??0)>0?' · Раунд '.self::esc($view['round']):'').'</p></div></div>';
        $groups=$view['groups']??[];
        if ($eid!==null) {
            $html.='<div class="af-acs-combat-bar__center">'.($roster?self::roster($groups):self::characters($groups)).'</div>';
        }
        $actions='';
        if ($canOpenGm) $actions.=self::action(self::gmUrl($view,$tid),$eid===null?'Настроить бой':'Управление боем','gm');
        $builder=self::builderUrl($view,$tid);
        if ($builder!==null) $actions.=self::action($builder,'Конструктор действий','builder');
        if ($actions!=='') $html.='<nav class="af-acs-combat-bar__actions" aria-label="Действия ACS">'.$actions.'</nav>';
        return $html.'</section>';
    }
}

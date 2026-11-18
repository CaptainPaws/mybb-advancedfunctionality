<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/LootAllocationController.php';
require_once __DIR__.'/GmIntent.php';
/** Small server-rendered view. Only filtered DTOs enter HTML; no raw generation/KB/other claims. */
final class AF_ACS_LootAllocationPage
{
    private static function esc(mixed $v):string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public static function lootState(string $state):string
    {
        return match($state){'generated'=>'Сгенерировано · распределяется','awaiting_issue'=>'Победитель определён · ожидает выдачи','pending_issue'=>'Требует восстановления · выдача не подтверждена','issued'=>'Выдано','issue_rejected'=>'Выдача отклонена owner-сервисом · победитель сохранён','allocation_closed'=>'Заявки закрыты · получатель не определён',default=>'Состояние не подтверждено'};
    }
    public static function renderView(array $view,string $postKey,string $url):string
    {
        $html='<section class="af-acs"><h2>Добыча столкновения #'.self::esc($view['encounter_id']).'</h2><p>Назначение получателя и выдача в Inventory — отдельные этапы. Статус выдачи указан у каждого предмета.</p>';
        foreach($view['entries'] as $e){
            $html.='<article><h3>'.self::esc($e['item_key']).' × '.self::esc($e['qty']).'</h3><p>'.self::esc(['gm_assign'=>'GM-распределение','free_claim'=>'Самостоятельная заявка','need_greed'=>'Нужно / Не откажусь / Пропустить'][$e['mode']]??$e['mode']).' · '.self::esc(self::lootState($e['status'])).'</p>';
            foreach($e['own_claims'] as $c)$html.='<p>Ваша группа #'.self::esc($c['control_group_id']).': '.self::esc(['need'=>'Нужно','greed'=>'Не откажусь','pass'=>'Пропустить'][$c['claim']]??$c['claim']).' · '.($c['closed_at']===null?'заявка открыта':'заявка закрыта').'</p>';
            if(isset($e['winner']))$html.='<p>Подтверждённый получатель: '.($e['winner']['control_group_id']===null?'не определён':'группа #'.self::esc($e['winner']['control_group_id'])).'</p>';
            if(isset($e['claims'])){ $html.='<details><summary>Заявки групп</summary><ul>';foreach($e['claims'] as $c)$html.='<li>Группа #'.self::esc($c['control_group_id']).' · '.self::esc(['need'=>'Нужно','greed'=>'Не откажусь','pass'=>'Пропустить'][$c['claim']]??$c['claim']).'</li>';$html.='</ul></details>'; }
            $actions=[];if($e['can_assign'])$actions[]=['loot_gm_assign',null];if($e['can_close'])$actions[]=['loot_allocation_close',null];
            if($e['can_claim'])foreach($e['own_groups'] as $group)$actions[]=[$e['mode']==='free_claim'?'loot_free_claim':'loot_claim',$group];
            foreach($actions as [$op,$group]){
                $fields=['op'=>$op,'session_id'=>$view['session_id'],'encounter_id'=>$view['encounter_id'],'entry_id'=>$e['entry_id'],'expected_version'=>$view['session_version'],'expected_allocation_version'=>$e['allocation_version'],'operation_key'=>bin2hex(random_bytes(16)),'my_post_key'=>$postKey];
                if($group!==null){$version='0';foreach($e['own_claims'] as $c)if($c['control_group_id']===$group)$version=$c['claim_version'];$fields+=['control_group_id'=>$group,'expected_claim_version'=>$version];}
                $html.='<form method="post" action="'.self::esc($url).'">';foreach($fields as $k=>$v)$html.='<input type="hidden" name="'.self::esc($k).'" value="'.self::esc($v).'">';
                if($op==='loot_gm_assign'){$html.='<label>Получатель <select name="control_group_id" required>';foreach($e['eligible_groups'] as $g)if($g['owner_uid']!==null)$html.='<option value="'.self::esc($g['control_group_id']).'">Группа #'.self::esc($g['control_group_id']).' · UID '.self::esc($g['owner_uid']).'</option>';$html.='</select></label>';}
                if($op==='loot_claim'){$html.='<label>Заявка группы #'.self::esc($group).' <select name="claim">';foreach($e['allow_pass']?['need','greed','pass']:['need','greed'] as $choice)$html.='<option value="'.$choice.'">'.(['need'=>'Нужно','greed'=>'Не откажусь','pass'=>'Пропустить'][$choice]).'</option>';$html.='</select></label>';}
                $label=match($op){'loot_gm_assign'=>'Назначить получателя','loot_allocation_close'=>'Закрыть заявки','loot_free_claim'=>'Заявить получение',default=>'Сохранить заявку'};
                $html.=AF_ACS_GmIntent::checkbox($op);
                $html.='<label>Причина <input name="reason" maxlength="1000" required></label><button type="submit">'.$label.'</button></form>';
            }$html.='</article>';
        }
        if(!$view['entries'])$html.='<p>Доступной добычи нет.</p>';return $html.'</section>';
    }
    public static function render(string $sid,string $eid):string
    {
        require_once __DIR__.'/CompletionPage.php';
        return AF_ACS_CompletionPage::render($sid,$eid);
    }
}

<?php
if (!defined('IN_MYBB')) {die('No direct access');}
require_once __DIR__.'/LootAllocationController.php';
/** Small server-rendered view. Only filtered DTOs enter HTML; no raw generation/KB/other claims. */
final class AF_ACS_LootAllocationPage
{
    private static function esc(mixed $v):string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public static function renderView(array $view,string $postKey,string $url):string
    {
        $html='<section class="af-acs"><h2>Добыча столкновения #'.self::esc($view['encounter_id']).'</h2><p>Распределение резервирует получателя. Предметы ещё не выданы в Inventory.</p>';
        foreach($view['entries'] as $e){
            $html.='<article><h3>'.self::esc($e['item_key']).' × '.self::esc($e['qty']).'</h3><p>'.self::esc($e['mode']).' · '.self::esc($e['status']).'</p>';
            foreach($e['own_claims'] as $c)$html.='<p>Ваша группа #'.self::esc($c['control_group_id']).': '.self::esc($c['claim']).'</p>';
            if(isset($e['winner']))$html.='<p>Подтверждённый получатель: '.($e['winner']['control_group_id']===null?'не определён':'группа #'.self::esc($e['winner']['control_group_id'])).'</p>';
            $actions=[];if($e['can_assign'])$actions[]=['loot_gm_assign',null];if($e['can_close'])$actions[]=['loot_allocation_close',null];
            if($e['can_claim'])foreach($e['own_groups'] as $group)$actions[]=[$e['mode']==='free_claim'?'loot_free_claim':'loot_claim',$group];
            foreach($actions as [$op,$group]){
                $fields=['op'=>$op,'session_id'=>$view['session_id'],'encounter_id'=>$view['encounter_id'],'entry_id'=>$e['entry_id'],'expected_version'=>$view['session_version'],'expected_allocation_version'=>$e['allocation_version'],'operation_key'=>bin2hex(random_bytes(16)),'my_post_key'=>$postKey];
                if($group!==null){$version='0';foreach($e['own_claims'] as $c)if($c['control_group_id']===$group)$version=$c['claim_version'];$fields+=['control_group_id'=>$group,'expected_claim_version'=>$version];}
                $html.='<form method="post" action="'.self::esc($url).'">';foreach($fields as $k=>$v)$html.='<input type="hidden" name="'.self::esc($k).'" value="'.self::esc($v).'">';
                if($op==='loot_gm_assign'){$html.='<label>Получатель <select name="control_group_id" required>';foreach($e['eligible_groups'] as $g)if($g['owner_uid']!==null)$html.='<option value="'.self::esc($g['control_group_id']).'">Группа #'.self::esc($g['control_group_id']).' · UID '.self::esc($g['owner_uid']).'</option>';$html.='</select></label>';}
                if($op==='loot_claim'){$html.='<label>Заявка группы #'.self::esc($group).' <select name="claim">';foreach($e['allow_pass']?['need','greed','pass']:['need','greed'] as $choice)$html.='<option value="'.$choice.'">'.$choice.'</option>';$html.='</select></label>';}
                $label=match($op){'loot_gm_assign'=>'Назначить получателя','loot_allocation_close'=>'Закрыть заявки','loot_free_claim'=>'Заявить получение',default=>'Сохранить заявку'};
                $html.='<label>Причина <input name="reason" maxlength="1000" required></label><button type="submit">'.$label.'</button></form>';
            }$html.='</article>';
        }
        if(!$view['entries'])$html.='<p>Доступной добычи нет.</p>';return $html.'</section>';
    }
    public static function render(string $sid,string $eid):string
    {
        global $mybb,$db;
        $repo=new AF_ACS_EncounterRepository($db);$access=new AF_ACS_SessionAccess;
        $scope=(new AF_ACS_SessionService($repo,$access))->view($sid);$url='misc.php?action=acs_loot&session_id='.rawurlencode($sid).'&encounter_id='.rawurlencode($eid);
        if($eid==='')return '<form method="get" action="misc.php"><input type="hidden" name="action" value="acs_loot"><input type="hidden" name="session_id" value="'.self::esc($sid).'"><label>Столкновение #<input name="encounter_id" inputmode="numeric" required></label><button>Открыть добычу</button></form>';
        $service=new AF_ACS_LootAllocationService($repo,$access);$notice='';
        if($mybb->request_method==='post')try{
            $input=$_POST;$op=(string)($input['op']??'');foreach(['op','my_post_key'] as $k)unset($input[$k]);
            if(($input['session_id']??null)!==$sid||($input['encounter_id']??null)!==$eid)throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
            AF_ACS_LootAllocationController::handle($service,$op,$input,'post');$notice='<p role="status">Распределение сохранено. Выдача предметов не выполнялась.</p>';
        }catch(Throwable){$notice='<p role="alert">Изменение не подтверждено. Проверьте права и актуальную версию; при неизвестном ответе повторите прежнюю команду с прежним ключом.</p>';}
        return $notice.self::renderView($service->view($sid,$eid),(string)$mybb->post_code,$url);
    }
}

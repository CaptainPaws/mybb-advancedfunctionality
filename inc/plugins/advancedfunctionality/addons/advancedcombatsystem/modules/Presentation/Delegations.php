<?php
if (!defined('IN_MYBB')) {die('No direct access');}

/** Metadata/consent only. No character snapshots, inventory, drafts or GM authority grants. */
final class AF_ACS_DelegationsPage
{
    private static function esc(mixed $v): string {return htmlspecialchars_uni((string)$v);}
    private static function form(string $url,string $op,array $fields): void
    {
        global $mybb;echo '<form method="post" action="'.self::esc($url).'">';
        foreach(['op'=>'delegation_'.$op,'operation_key'=>bin2hex(random_bytes(16)),'my_post_key'=>$mybb->post_code]+$fields as $k=>$v)echo '<input type="hidden" name="'.self::esc($k).'" value="'.self::esc($v).'">';
    }
    public static function render(string $sid,string $url,?AF_ACS_DelegationService $service=null): void
    {
        global $mybb;$service??=af_advancedcombatsystem_delegations();$view=$service->view($sid,$mybb->get_input('delegation_after')?:'0');$s=$view['session'];
        $labels=['ordinary'=>'Обычные боевые действия','item_consume'=>'Расходники','equipment_change'=>'Смена экипировки','escape'=>'Побег','surrender'=>'Капитуляция'];
        echo '<section id="acs-delegations"><h3>Делегирование управления</h3><p>Владелец и делегат используют один ход, AP, квоту подготовки и сроки. Передача собственности, выдача предметов и повторное делегирование не разрешаются.</p>';
        foreach($view['items'] as $row){
            $until=$row['valid_until']===null?'без срока':(function_exists('my_date')?my_date(($mybb->settings['dateformat']??'Y-m-d').' '.($mybb->settings['timeformat']??'H:i'),(int)$row['valid_until']):gmdate('Y-m-d H:i',(int)$row['valid_until']).' UTC');$actions=array_map(static fn($a)=>$labels[$a]??$a,$row['permitted_actions']);
            echo '<p>#'.self::esc($row['id']).' · UID '.self::esc($row['owner_uid']).' → UID '.self::esc($row['delegate_uid']).' · '.self::esc($row['control_group_id']===null?'Существа: '.implode(', ',$row['entity_ids']):'Группа #'.$row['control_group_id']).' · '.self::esc(implode(', ',$actions)).' · '.self::esc($until).' · '.self::esc($row['status']==='revoked'?'Отозвано':((int)$row['valid_from']>time()?'Ещё не началось':($row['valid_until']!==null&&(int)$row['valid_until']<=time()?'Истекло':'Действует'))).'</p>';
            if($row['can_revoke'] && $row['status']==='active'){self::form($url,'revoke',['session_id'=>$sid,'expected_version'=>$s['session_version'],'delegation_id'=>$row['id'],'delegation_version'=>$row['delegation_version']]);echo '<label>Причина <input name="reason" maxlength="1000" required></label><button>Отозвать</button></form>';}
        }
        if($view['next_after']!==null)echo '<a href="'.self::esc($url.'&session_id='.$sid.'&delegation_after='.$view['next_after']).'">Следующие разрешения</a>';
        if($s['status']==='active'){
            $fields=['session_id'=>$sid,'expected_version'=>$s['session_version']];if(!$view['can_request_replacement'])$fields['owner_uid']=(string)(int)$mybb->user['uid'];
            self::form($url,'grant',$fields);
            if($view['can_request_replacement'])echo '<label>UID владельца <input type="number" min="1" name="owner_uid" value="'.self::esc($mybb->user['uid']).'" required></label><p>Назначение за другого владельца требует утверждённого правила квеста или отдельного административного права.</p>';
            echo '<h4>Делегировать своих существ</h4><label>UID делегата <input type="number" min="1" name="delegate_uid" required></label><label>ID существ через запятую <input name="entity_ids" maxlength="2099"></label><label>Либо ID своей группы целиком <input type="number" min="1" name="control_group_id"></label><p>Выберите точных существ либо всю свою группу, включая её питомцев.</p>';
            foreach($labels as $category=>$label)echo '<label><input type="checkbox" name="permitted_actions[]" value="'.$category.'"'.($category==='ordinary'?' checked required':'').'>'.self::esc($label).'</label>';
            echo '<p>Побег и капитуляция требуют отдельной поддержанной механики. Предметные категории дополнительно проверяются Inventory; custody не заменяет разрешения.</p><label>Срок с текущего момента <select name="duration_seconds"><option value="3600">1 час</option><option value="86400" selected>1 день</option><option value="604800">7 дней</option><option value="0">Без срока</option></select></label><label>Причина <input name="reason" maxlength="1000" required></label><button>Выдать разрешение</button></form>';
        }
        echo '</section>';
    }
}

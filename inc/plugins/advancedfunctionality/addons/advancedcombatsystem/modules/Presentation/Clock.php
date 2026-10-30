<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Minimal native forms/metadata; no action builder, JSON editor or automatic polling. */
final class AF_ACS_ClockPage
{
    private static function esc(mixed $v): string {return htmlspecialchars_uni((string)$v);}
    private static function form(string $url,string $op,array $view,array $fields=[]): void
    {
        global $mybb;
        echo '<form method="post" action="'.self::esc($url).'">';
        $fields+=['acs_encounter_op'=>'clock_'.$op,'session_id'=>$view['session']['id'],'tid'=>$view['session']['tid'],'encounter_id'=>$view['encounter']['id'],'encounter_version'=>$view['encounter']['encounter_version'],'expected_version'=>$view['session']['session_version'],'operation_key'=>bin2hex(random_bytes(16)),'my_post_key'=>$mybb->post_code];
        foreach($fields as $k=>$v)echo '<input type="hidden" name="'.self::esc($k).'" value="'.self::esc($v).'">';
        echo '<label>Причина <input name="reason" maxlength="1000" required></label>';
    }
    public static function rounds(array $view,array $roundView,string $url,AF_ACS_ClockService $clock): void
    {
        echo '<h3>AP групп</h3><ul>';
        foreach($roundView['turns'] as $turn) {
            $available=AF_ACS_Clock::availableAP((int)$turn['ap_budget'],(int)$turn['ap_spent'],(int)$turn['pre_round_ap_commitment']);
            echo '<li>Группа #'.self::esc($turn['control_group_id']).': '.self::esc($available).' / '.self::esc($turn['ap_budget']).' AP';
            if($turn['status']==='pending' && $view['encounter']['status']==='active')foreach($turn['individual_turns'] as $individual)if((string)$individual['start_done']==='0') {
                self::form($url,'entity_start',$view,['entity_turn_id'=>$individual['id']]);echo '<button type="submit">Обработать начало хода существа #'.self::esc($individual['entity_id']).'</button></form>';
            }
            echo '</li>';
        }
        echo '</ul><p>Общий бюджет персонажа и питомцев. Произвольное списание/повторная выдача AP недоступны.</p>';
        $data=$clock->overview($view['session']['id']);
        echo '<h3>Кулдауны</h3><ul>';
        foreach($data['cooldowns'] as $row)echo '<li>'.self::esc($row['name']).' · '.self::esc($row['ability_key']).': '.self::esc($row['remaining_turns']).' ходов · '.($row['scope']==='session'?'сессионный':'боевой').(!empty($row['ready'])?'':' · источник не подтверждён, обработка заблокирована').'</li>';
        echo '</ul><p>Кулдаун появляется только после подтверждённого действия. На этом этапе кнопки создания кулдауна или исполнения способности нет.</p>';
    }
    public static function rest(array $view,string $url,AF_ACS_ClockService $clock): void
    {
        $data=$clock->overview($view['session']['id']);echo '<h3>Отдых группы</h3>';
        if(!$data['rest_allowed'])echo '<p>'.self::esc($data['rest_reason']).'</p>';
        if($view['session']['status']==='active')foreach($data['groups'] as $group) {
            echo '<h4>Группа #'.self::esc($group['id']).' · '.($group['owner_uid']===null?'NPC / монстр':'владелец UID '.self::esc($group['owner_uid'])).'</h4>';
            if($group['short_available']) {self::form($url,'rest_short',$view,['control_group_id'=>$group['id']]);echo '<button type="submit">Короткий отдых: 25% максимального HP</button></form>';}
            if($data['full_rest_allowed']) {self::form($url,'rest_full',$view,['control_group_id'=>$group['id']]);echo '<label><input type="checkbox" name="confirm_full" value="1" required> Разрешаю полный отдых группы до максимума HP</label><button type="submit">Полноценный отдых</button></form>';}
        }
        if($view['encounter']['status']==='completed') {self::form($url,'combat_reset',$view);echo '<button type="submit">Сбросить боевые кулдауны завершённого столкновения</button></form>';}
        echo '<p>Короткий отдых — один раз на группу между завершённым и следующим боем. Присутствующие постоянные питомцы восстанавливаются вместе с персонажем; dead не воскрешаются. Прочие ресурсы без подтверждённого KB-контракта восстановления не изменяются.</p><h3>Подтверждённые восстановления</h3><ul>';
        foreach($data['history'] as $row) {$r=json_decode($row['result_json'],true);echo '<li>Событие #'.self::esc($row['id']).' · '.($row['event_type']==='session.rest_short'?'Короткий':'Полноценный').' отдых · группа #'.self::esc($r['control_group_id']).' · UID '.self::esc($row['actor_uid']).'</li>';}
        echo '</ul>';
    }
}

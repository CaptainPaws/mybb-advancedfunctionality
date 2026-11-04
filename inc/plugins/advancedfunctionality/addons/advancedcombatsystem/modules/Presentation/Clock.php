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
        self::sustained($view,$url,$clock);
    }
    public static function sustained(array $view,string $url,AF_ACS_ClockService $clock):void
    {
        $data=$clock->sustained($view['session']['id'],$view['encounter']['id']);echo '<h3>Поддерживаемые эффекты</h3><p>Оплата в начале хода источника после обязательных тиков. Без выбора — FIFO.</p>';
        foreach($data['sources'] as $source){echo '<h4>Источник #'.self::esc($source['source_entity_id']).'</h4><ol>';$ids=[];
            foreach($source['effects'] as $e){if($e['status']==='active')$ids[]=$e['id'];
                $costs=[];foreach($e['costs'] as $c)$costs[]=$c['amount'].' '.($c['resource']??strtoupper($c['kind']));
                echo '<li>#'.self::esc($e['id']).' '.self::esc($e['effect_key']).' · '.self::esc(implode(', ',$costs)).' · '.($e['priority']===null?'FIFO':'приоритет '.self::esc($e['priority'])).' · '.self::esc(self::payment($e)).'</li>';
            }echo '</ol>';
            if($ids&&($source['turn']['editable']??false)){
                if($source['turn']['applies_next'])echo '<p>Начало уже обработано. Новый порядок применяется со следующего начала хода источника.</p>';
                self::form($url,'upkeep_order',$view,['entity_turn_id'=>$source['turn']['id']]);
                echo '<label>Порядок ID активных эффектов через запятую <input name="effect_order" value="'.self::esc(implode(',',$ids)).'" maxlength="2800" required></label><button>Сохранить порядок поддержания</button></form>';
            }
        }
    }
    private static function payment(array $e):string
    {
        if($e['termination_reason']!==null)return 'Прекращён: '.match($e['termination_reason']){'source_inactive'=>'источник выведен из строя','kb_control'=>'условие контроля KB',default=>'недостаточно допустимых ресурсов'};
        if($e['status']!=='active')return 'Завершён';
        if($e['last_payment']===null)return 'Оплата ещё не выполнялась';
        return $e['last_payment']['paid']?'Последняя оплата выполнена':'Последняя оплата отклонена';
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

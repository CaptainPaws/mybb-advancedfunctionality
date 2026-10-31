<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

final class AF_ACS_RoundManagementPage
{
    private static function esc(mixed $v): string {return htmlspecialchars_uni((string)$v);}
    private static function form(string $url,string $op,array $view,array $fields=[]): void
    {
        global $mybb;
        echo '<form method="post" action="'.self::esc($url).'">';
        $fields+=['acs_encounter_op'=>'manage_'.$op,'session_id'=>$view['session']['id'],'tid'=>$view['session']['tid'],'encounter_id'=>$view['encounter']['id'],'expected_version'=>$view['session']['session_version'],'encounter_version'=>$view['encounter']['encounter_version'],'operation_key'=>bin2hex(random_bytes(16)),'my_post_key'=>$mybb->post_code];
        foreach($fields as $key=>$value)echo '<input type="hidden" name="'.self::esc($key).'" value="'.self::esc($value).'">';
    }
    private static function submit(string $label): void
    {
        echo '<label>Причина <input name="reason" maxlength="1000" required></label><label><input name="confirm" value="1" type="checkbox" required> Подтверждаю операцию</label><button type="submit">'.self::esc($label).'</button></form>';
    }
    public static function render(array $view,array $roundView,string $url,AF_ACS_RoundManagementService $service): void
    {
        global $mybb;
        $data=$service->overview($view['session']['id'],$view['encounter']['id'],$mybb->get_input('turn_after')?:'0');$state=$data['status'];
        echo '<h3>Управление раундом</h3><p>Столкновение: '.($state==='paused'?'Пауза':($state==='active'?'Активно':'Подготовка')).'</p>';
        if($view['session']['status']!=='active')return;
        if(in_array($state,['active','paused'],true)) {
            self::form($url,$state==='active'?'pause':'resume',$view);self::submit($state==='active'?'Поставить на паузу':'Возобновить');
        }
        $names=['off'=>'Выключены','notify'=>'Уведомление без пропуска','timeout_pass'=>'Пропуск по истечении срока','gm_skip'=>'Ручной пропуск мастером'];
        echo '<p>Политика дедлайна: '.self::esc($names[$data['policy']['mode']]).'</p>';
        if(in_array($state,['preparation','active'],true)) {
            self::form($url,'deadline_set',$view);echo '<label>Политика <select name="deadline_mode">';
            foreach($names as $key=>$label)echo '<option value="'.$key.'"'.($data['policy']['mode']===$key?' selected':'').'>'.self::esc($label).'</option>';echo '</select></label><label>Продолжительность в секундах <input name="seconds" type="number" min="1" step="1" value="'.self::esc($data['policy']['seconds']??'').'"></label><p>Обязательно для включённого дедлайна. Сохранение явно переустанавливает срок pending ходов от текущего момента.</p>';self::submit('Сохранить политику');
        }
        $byId=array_column($data['turns'],null,'turn_id');
        foreach($roundView['turns'] as $turn) {
            $remaining=$byId[$turn['id']]['remaining_seconds']??null;
            echo '<p>Группа #'.self::esc($turn['control_group_id']).' · '.($remaining===null?'Нет действующего дедлайна':('Осталось '.self::esc($remaining).' сек.')).'</p>';
            if($turn['status']!=='pending')continue;
            $fields=['turn_id'=>$turn['id'],'turn_version'=>$turn['turn_version']];
            if($state==='active') {
                self::form($url,'gm_skip',$view,$fields);self::submit('Пропустить ход группы');
                self::form($url,'gm_forfeit',$view,$fields);echo '<p>Только если все существа группы подтверждённо выбыли. Недееспособность сама по себе не закрывает ход.</p>';self::submit('Закрыть выбывшую группу');
                if(in_array($data['policy']['mode'],['notify','timeout_pass'],true) && $remaining!==null && $remaining==='0') {self::form($url,'deadline_process',$view,$fields);self::submit('Обработать наступивший дедлайн');}
            }
            if(in_array($state,['active','paused'],true) && $remaining!==null) {self::form($url,'extend_deadline',$view,$fields);echo '<label>Добавить секунд <input name="seconds" type="number" min="1" step="1" required></label>';self::submit('Продлить дедлайн');}
        }
        if($state==='active' && $roundView['round']!==null) {self::form($url,'round_next',$view,['round_id'=>$roundView['round']['id']]);echo '<p>Переход разрешён только после закрытия всех ходов и обязательных границ. Новые группы входят в новый roster.</p>';self::submit('Перейти к следующему раунду');}
        echo '<p>Фоновый scheduler ACS не подключён: обработка срока требует отдельного авторизованного POST. GET не пропускает ходы. Для уведомлений пока фиксируется событие; доставка через IC-AAM относится к этапу 11.</p>';
    }
}

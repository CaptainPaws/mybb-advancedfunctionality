<?php
if (!defined('IN_MYBB')) {die('No direct access');}
/** Small explicit GM panel. No automatic dispatch, polling or client-provided owner results. */
final class AF_ACS_InventoryObligations
{
    private static function esc(mixed $value): string {return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function hidden(array $fields): string
    {$out='';foreach($fields as $key=>$value)$out.='<input type="hidden" name="'.self::esc($key).'" value="'.self::esc($value).'">';return $out;}
    public static function render(array $page,string $csrf,string $notice='',string $lookupKey=''): string
    {
        $sid=$page['session_id'];$url='misc.php?action=acs_recovery&session_id='.rawurlencode($sid);$base=['session_id'=>$sid,'tid'=>$page['tid'],'my_post_key'=>$csrf];
        $html='<section class="af-acs-inventory"><h3>Имущество и обязательства сессии</h3>'.$notice.'<p>Сессия: '.self::esc($page['session_status']).'. Inventory: '.($page['inventory']==='available'?'доступен':'недоступен; состояние резервов не подтверждено').'. Закрытие сессии сохраняет обязательства возврата.</p><ul>';
        foreach($page['items'] as $item) {
            $html.='<li>Предмет #'.self::esc($item['item_id']).' · собственник UID '.self::esc($item['owner_uid']).' · хранитель '.self::esc($item['custodian_entity_id']??'не назначен').' · временный пользователь '.self::esc($item['active_user_entity_id']??'нет').' · custody '.self::esc($item['status']).' · резерв #'.self::esc($item['reservation']['reservation_id']).': '.self::esc($item['reservation']['status']);
            if ($item['available_commands']) {
                $key=bin2hex(random_bytes(16));$html.='<form method="post" action="'.self::esc($url).'">'.self::hidden($base+['inventory_action'=>'custody','inventory_item_id'=>$item['item_id'],'expected_version'=>$page['session_version'],'expected_custody_version'=>$item['custody_version'],'operation_key'=>$key]);
                $labels=['return'=>'Вернуть доступность владельцу','assign'=>'Назначить хранителя (снять временное использование)','temporary_use'=>'Явно разрешить использование хранителю'];
                $html.='<label>Действие <select name="custody_operation">';foreach($item['available_commands'] as $command)$html.='<option value="'.self::esc($command).'">'.self::esc($labels[$command]).'</option>';
                $html.='</select></label><label>Сущность хранителя (для назначения) <input name="custodian_entity_id" inputmode="numeric" value="'.self::esc($item['custodian_entity_id']??'').'"></label><label>Причина <input name="reason" maxlength="512" required></label><button type="submit">Подтвердить</button></form>';
            }else $html.='<p>Изменения недоступны до проверки состояния и обязательств.</p>';
            $html.='</li>';
        }
        if (!$page['items'])$html.='<li>Записей custody нет.</li>';$html.='</ul>';
        if ($page['can_confiscate']) $html.='<details><summary>Конфисковать экипированный экземпляр</summary><form method="post" action="'.self::esc($url).'">'.self::hidden($base+['inventory_action'=>'custody','custody_operation'=>'confiscate','expected_version'=>$page['session_version'],'expected_custody_version'=>'0','operation_key'=>bin2hex(random_bytes(16))]).'<label>Сущность владельца <input name="owner_entity_id" inputmode="numeric" required></label><label>Предмет Inventory <input name="inventory_item_id" inputmode="numeric" required></label><label>Причина <input name="reason" maxlength="512" required></label><button type="submit">Конфисковать</button></form></details>';
        $html.='<h4>Активные глобальные резервы этой сессии</h4><ul>';
        foreach($page['reservations'] as $r)$html.='<li>#'.self::esc($r['reservation_id']).' · предмет #'.self::esc($r['item_id']).' · собственник UID '.self::esc($r['owner_uid']).' · '.self::esc($r['status']).' · остаток '.self::esc($r['remaining_qty']).'</li>';
        if (!$page['reservations'])$html.='<li>'.($page['inventory']==='available'?'На этой странице активных резервов нет.':'Список резервов недоступен; отсутствие обязательств не подтверждено.').'</li>';$html.='</ul><h4>Операции Inventory</h4><ul>';
        foreach($page['operations'] as $op) {
            $html.='<li>'.self::esc($op['kind']).' · предмет #'.self::esc($op['item_id']??'после подтверждения').' · собственник/получатель UID '.self::esc($op['owner_uid']).' · '.self::esc($op['operation_key']).' · UID '.self::esc($op['actor_uid']).' · '.self::esc($op['status']);
            if($op['can_lookup'])$html.='<form method="post" action="'.self::esc($url).'">'.self::hidden($base+['inventory_action'=>'recover','operation_key'=>$op['operation_key'],'expected_operation_version'=>$op['operation_version']]).'<button type="submit">Проверить прежний исход (lookup)</button></form>';
            else if(in_array($op['status'],['prepared','recovery_required'],true))$html.='<p>Lookup выполняет исходный автор. GM не подменяет его полномочия.</p>';
            $html.='</li>';
        }
        if(!$page['operations'])$html.='<li>Операций на этой странице нет.</li>';$html.='</ul>';
        foreach(['next_after'=>'custody_after','next_operation_after'=>'inventory_after','next_reservation_after'=>'reservation_after'] as $key=>$parameter)if($page[$key]!==null)$html.='<p><a href="'.self::esc($url.'&'.$parameter.'='.rawurlencode($page[$key])).'">Следующая страница '.self::esc($parameter==='custody_after'?'custody':($parameter==='inventory_after'?'операций':'резервов')).'</a></p>';
        $html.='<form method="post" action="'.self::esc($url).'">'.self::hidden($base+['inventory_action'=>'custody_lookup']).'<label>Ключ команды custody с неизвестным исходом <input name="operation_key" value="'.self::esc($lookupKey).'" required></label><button type="submit">Найти прежний результат</button></form>';
        return $html.'<p>При неизвестном ответе используйте прежний ключ. Lookup не повторяет расход или передачу. Возврат не восстанавливает старую экипировку автоматически.</p></section>';
    }
}

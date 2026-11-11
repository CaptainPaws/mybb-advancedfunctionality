<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Infrastructure/RecoveryQueueQuery.php';

/** Explicit GM navigation only; no heavy recovery services on ordinary showthread. */
final class AF_ACS_RecoveryPage
{
    private static function esc(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function url(string $sid,string $id=''):string{return 'misc.php?action=acs_recovery&session_id='.rawurlencode($sid).($id!==''?'&submission_id='.rawurlencode($id):'');}
    public static function render(string $sid): string
    {
        global $db,$mybb;
        if(!af_is_addon_enabled('advancedcombatsystem')||!empty($GLOBALS['af_acs_deactivated']))throw new RuntimeException('ACS_DISABLED');
        $access=new AF_ACS_SessionAccess;$query=new AF_ACS_RecoveryQueueQuery($db);
        $page=$query->page($sid,$mybb->get_input('after'),$access);$id=$mybb->get_input('submission_id');$notice='';$view=null;
        if($id!==''){
            $query->requireSubmission($sid,$id,$access);
            require_once __DIR__.'/../Application/RecoveryService.php';$service=new AF_ACS_RecoveryService($db,$access);
            if($mybb->request_method==='post'){
                try{
                    if($mybb->get_input('session_id')!==$sid || $mybb->get_input('tid')!==$page['tid'])throw new RuntimeException('ACS_RECOVERY_PERMISSION_DENIED');
                    $strategy=$mybb->get_input('strategy');$version=$mybb->get_input('expected_state_version');$reason=$mybb->get_input('reason');
                    $view=$strategy==='quarantine'?$service->requestReview($id,$version,$reason):$service->resolve($id,$version,$strategy,$reason);
                    $notice='<p role="status">Решение сохранено в журнале. Механика этой командой не применяется.</p>';
                    $page=$query->page($sid,$mybb->get_input('after'),$access);
                }catch(Throwable $error){$notice='<p role="alert">Решение не подтверждено. Проверьте полномочия, CSRF, причину и текущую версию. Перечитайте состояние перед повтором.</p>';}
            }
            $view=$service->inspect($id);
        }
        require_once __DIR__.'/../Infrastructure/InventoryObligationsQuery.php';
        require_once __DIR__.'/InventoryObligations.php';
        $inventoryQuery=new AF_ACS_InventoryObligationsQuery($db);$inventoryNotice='';$lookupKey='';
        // Same GM/native context was checked above; mutations additionally check CSRF/CAS in services.
        if ($mybb->request_method==='post' && $mybb->get_input('inventory_action')!=='') {
            $lookupKey=$mybb->get_input('operation_key');
            try {
                if ($mybb->get_input('session_id')!==$sid || $mybb->get_input('tid')!==$page['tid']) throw new RuntimeException('ACS_RECOVERY_PERMISSION_DENIED');
                if ($mybb->get_input('inventory_action')==='recover') {
                    require_once __DIR__.'/../Application/RecoveryService.php';
                    $out=(new AF_ACS_RecoveryService($db,$access))->recoverInventory($sid,$lookupKey,$mybb->get_input('expected_operation_version'));
                    $inventoryNotice='<p role="status">Исход операции: '.self::esc($out['status']).'. Lookup: '.self::esc($out['lookup_state']??'подтверждён').'.</p>';
                } else {
                    require_once __DIR__.'/../Application/CustodyService.php';$custody=new AF_ACS_CustodyService($db,$access);
                    if ($mybb->get_input('inventory_action')==='custody_lookup') {
                        $access->csrf();$out=$custody->lookup($sid,$lookupKey);
                    } elseif ($mybb->get_input('inventory_action')==='custody') {
                        $input=[];foreach(['session_id','expected_version','expected_custody_version','operation_key','reason','inventory_item_id','owner_entity_id','custodian_entity_id'] as $field)$input[$field]=$mybb->get_input($field);
                        $out=$custody->command($mybb->get_input('custody_operation'),$input);
                    } else throw new RuntimeException('ACS_CUSTODY_INPUT_INVALID');
                    $inventoryNotice='<p role="status">Прежний ключ '.self::esc($lookupKey).' · состояние: '.self::esc($out['status']).'.</p>';
                }
            } catch (Throwable $error) {$inventoryNotice='<p role="alert">Исход не подтверждён. Обновите состояние; при неизвестном ответе проверьте прежний ключ '.self::esc($lookupKey).' через lookup. Не отправляйте новую операцию.</p>';}
        }
        $inventoryPage=$inventoryQuery->page($sid,$access,$mybb->get_input('custody_after')?:'0',$mybb->get_input('inventory_after'),$mybb->get_input('reservation_after')?:'0');
        $html='<section class="af-acs-recovery"><h2>ACS · Восстановление публикаций</h2>'.$notice.'<p><a href="showthread.php?tid='.self::esc($page['tid']).'">Вернуться в тему</a></p>';
        $html.='<p>Сообщения MyBB и боевая история не исправляются этой страницей. Неизвестный PID нельзя подтвердить по совпадению автора, текста или времени.</p><ul>';
        foreach($page['items'] as $r)$html.='<li><a href="'.self::esc(self::url($sid,$r['submission_id'])).'">Отправка '.self::esc(substr($r['submission_id'],0,8)).'</a> · '.self::esc($r['status']).' · последняя фаза '.self::esc($r['last_phase']).' · PID '.self::esc($r['pid']??'не подтверждён').'</li>';
        if(!$page['items'])$html.='<li>Незавершённых отправок нет.</li>';$html.='</ul>';
        if($page['next_after']!==null)$html.='<p><a href="'.self::esc(self::url($sid).'&after='.$page['next_after']).'">Следующие отправки</a></p>';
        if($view){
            $html.='<h3>Выбранная отправка</h3><dl>';
            foreach(['Состояние'=>$view['status'],'Последняя фаза'=>$view['last_phase'],'Версия'=>$view['state_version'],'Подтверждённый PID'=>$view['pid']??'нет','Состояние сообщения'=>$view['post']['state']] as $k=>$v)$html.='<dt>'.self::esc($k).'</dt><dd>'.self::esc($v).'</dd>';
            $html.='</dl>';
            foreach($view['candidates']??[] as $c)$html.='<p>Кандидат PID '.self::esc($c['pid']).' — только наблюдение, не доказанная связь.</p>';
            $html.='<details><summary>Причины и аудит</summary><ul>';
            foreach($view['audit']??[] as $a){$e=json_decode($a['evidence_json'],true,32,JSON_THROW_ON_ERROR);$html.='<li>Версия '.self::esc($a['state_version']).' · '.self::esc($a['reason']).' · UID '.self::esc($a['actor_uid']).(isset($e['reason'])?' · '.self::esc($e['reason']):'').'</li>';}
            $html.='</ul></details>';
            $commands=$view['available_commands']??[];
            if(!in_array($view['status'],['RECOVERY_REQUIRED','COMMITTED','REJECTED'],true))$commands=['quarantine'];
            $labels=['quarantine'=>'Передать на ручную проверку и заблокировать продолжение','hold'=>'Оставить заблокированной с пояснением','resume_known_post'=>'Восстановить подтверждение известного поста','reject_unsent'=>'Отклонить доказанно неотправленную операцию'];
            if($commands){
                $html.='<form method="post" action="'.self::esc(self::url($sid,$id)).'">';
                foreach(['action'=>'acs_recovery','session_id'=>$sid,'submission_id'=>$id,'tid'=>$page['tid'],'expected_state_version'=>$view['state_version'],'my_post_key'=>$mybb->post_code] as $k=>$v)$html.='<input type="hidden" name="'.$k.'" value="'.self::esc($v).'">';
                $html.='<label>Решение <select name="strategy">';foreach($commands as $cmd)if(isset($labels[$cmd]))$html.='<option value="'.$cmd.'">'.$labels[$cmd].'</option>';
                $html.='</select></label><label>Причина <textarea name="reason" rows="3" maxlength="1024" required data-af-ae-skip="1"></textarea></label><button type="submit">Записать решение</button></form><p>Подтверждение известного поста не применяет бой: автор проходит отдельную проверку writer. Ввести новый PID или изменить броски здесь нельзя.</p>';
            }else{$html.='<p>Терминальная история не редактируется. Исправления механики требуют отдельного подтверждённого события.</p>';}
        }
        return $html.AF_ACS_InventoryObligations::render($inventoryPage,$mybb->post_code,$inventoryNotice,$lookupKey).'</section>';
    }
}

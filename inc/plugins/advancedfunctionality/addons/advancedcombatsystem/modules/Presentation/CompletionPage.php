<?php
if(!defined('IN_MYBB')){die('No direct access');}
require_once __DIR__.'/LootAllocationPage.php';
require_once __DIR__.'/GmIntent.php';

/** Native forms compose existing commands. No game/owner operation is run by GET. */
final class AF_ACS_CompletionPage
{
    private static function esc(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public static function lootState(string $state):string
    {
        return AF_ACS_LootAllocationPage::lootState($state);
    }
    private static function outcomeLabel(string $kind):string
    {return ['victory'=>'Победа','defeat'=>'Поражение','draw'=>'Ничья','escape'=>'Выход из боя','inconclusive'=>'Без окончательного исхода'][$kind]??$kind;}
    private static function amount(int $minor):string
    {return intdiv($minor,100).'.'.str_pad((string)($minor%100),2,'0',STR_PAD_LEFT);}
    private static function form(string $url,string $op,array $fields,string $body,string $label,bool $reason=true):string
    {
        global $mybb;
        $html='<form method="post" action="'.self::esc($url).'">';
        foreach(['op'=>$op,'my_post_key'=>$mybb->post_code]+$fields as $k=>$v)$html.='<input type="hidden" name="'.self::esc($k).'" value="'.self::esc($v).'">';
        return $html.$body.AF_ACS_GmIntent::checkbox($op).($reason?'<label>Причина <input name="reason" maxlength="1000" required></label>':'').'<button type="submit">'.self::esc($label).'</button></form>';
    }
    private static function failure(Throwable $error):string
    {
        $failure=af_advancedcombatsystem_session_failure($error);
        $message=match($error->getMessage()){
            'ACS_VERSION_CONFLICT'=>'Версия изменилась. Перечитайте состояние; сохранённый результат не перебрасывается.',
            'ACS_GM_REQUIRED','ACS_LOOT_ISSUER_REQUIRED'=>'Операция недоступна текущему пользователю. Восстановление выдачи выполняет прежний уполномоченный отправитель.',
            'ACS_REWARD_ALLOCATION_INVALID'=>'Распределение не сохранено. Используйте только разрешённых получателей и целые минимальные единицы; для ручного режима сумма должна совпасть с объёмом источника.',
            'ACS_LOOT_GRANT_KIND_UNSUPPORTED','ACS_LOOT_OWNER_METADATA_UNSUPPORTED'=>'Inventory не поддерживает выдачу этого определения. Победитель сохранён; прямой выдачи или замены предмета здесь нет.',
            'ACS_LOOT_OWNER_DEFINITION_CHANGED'=>'Определение предмета изменилось после закрепления. Выдача заблокирована; победитель и исходные снимки сохранены.',
            'ACS_LOOT_CONFIRMED_OUTCOME_REQUIRED','ACS_REWARD_CONFIRMED_OUTCOME_REQUIRED','ACS_STATISTICS_COMPLETED_REQUIRED'=>'Сначала требуется подтверждённый исход столкновения.',
            'ACS_UI_CONFIRMATION_REQUIRED'=>'Нужно явно подтвердить последствия операции.',
            'ACS_PERMANENT_SYNC_BLOCKED'=>'Постоянная синхронизация CharacterSheets не поддержана owner API.',
            default=>'Результат не подтверждён. Обновите состояние и проверьте прежнюю операцию. Не отправляйте новую выдачу после неизвестного ответа.',
        };
        return '<p role="alert">'.self::esc($message).' error_id='.self::esc($failure['error_id']).'</p>';
    }
    private static function dispatch(object $db,AF_ACS_EncounterRepository $repo,AF_ACS_SessionAccess $access,string $sid,string $eid,bool $gm):string
    {
        $input=$_POST;$op=$input['op']??null;
        if(!is_string($op)||($input['session_id']??null)!==$sid)throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
        foreach(['op','my_post_key','acs_confirm'] as $key)unset($input[$key]);
        if(isset($input['encounter_id'])&&$input['encounter_id']!==$eid)throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
        AF_ACS_GmIntent::requireConfirmation($op);
        if(in_array($op,['loot_gm_assign','loot_free_claim','loot_claim','loot_allocation_close'],true)){
            require_once __DIR__.'/LootAllocationController.php';AF_ACS_LootAllocationController::handle(new AF_ACS_LootAllocationService($repo,$access),$op,$input,'post');
            return '<p role="status">Заявка или распределение сохранены. Выдача не выполнялась.</p>';
        }
        if(!$gm)throw new RuntimeException('ACS_GM_REQUIRED');
        if(in_array($op,['outcome_confirm','outcome_continue'],true)){
            require_once __DIR__.'/EndConditionController.php';AF_ACS_EndConditionController::handle(new AF_ACS_EncounterService($repo,$access),$op,$input,'post');
        }elseif(in_array($op,['loot_configure','loot_generate'],true)){
            require_once __DIR__.'/LootController.php';AF_ACS_LootController::handle(new AF_ACS_LootService($repo,$access),$op,$input,'post');
        }elseif(in_array($op,['loot_issue','loot_issue_recover'],true)){
            require_once __DIR__.'/LootIssueController.php';$out=AF_ACS_LootIssueController::handle(new AF_ACS_LootIssueService($db,$access),$op,$input,'post');
            return '<p role="status">'.self::esc(self::lootState($out['status'])).'.</p>';
        }elseif(in_array($op,['reward_configure','reward_distribute','reward_issue','reward_recover'],true)){
            require_once __DIR__.'/RewardController.php';
            // A reward id must belong to the encounter displayed by this form.
            if(isset($input['reward_id'])&&(!is_string($input['reward_id'])||$repo->rewardRow($sid,$input['reward_id'])['encounter_id']!==$eid))throw new RuntimeException('ACS_REWARD_INPUT_INVALID');
            if($op==='reward_distribute'){
                if(array_key_exists('allocations',$input))throw new RuntimeException('ACS_REWARD_INPUT_INVALID');
                $amounts=$input['manual_amount']??[];unset($input['manual_amount']);$input['allocations']=[];
                if(!is_array($amounts)||count($amounts)>200)throw new RuntimeException('ACS_REWARD_ALLOCATION_INVALID');
                foreach($amounts as $key=>$amount){
                    if(!is_string($amount)||!preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/D',$amount)||strlen($amount)>16||(strlen($amount)===16&&strcmp($amount,'9007199254740991')>0))throw new RuntimeException('ACS_REWARD_ALLOCATION_INVALID');
                    if($amount!=='0')$input['allocations'][]=['recipient_key'=>(string)$key,'amount_minor'=>(int)$amount];
                }
            }
            $out=AF_ACS_RewardController::handle(new AF_ACS_RewardService($db,$access),$op,$input,'post');
            if(isset($out['status']))return '<p role="status">'.self::esc(self::rewardState($out['status'])).'.</p>';
        }elseif(in_array($op,['achievement_configure','achievement_evaluate'],true)){
            require_once __DIR__.'/AchievementController.php';AF_ACS_AchievementController::handle($db,$access,$op,$input,'post');
        }elseif(in_array($op,['statistics_sync','statistics_recover'],true)){
            $fields=$op==='statistics_sync'?['session_id','encounter_id','visibility']:['session_id','encounter_id'];
            if(array_diff(array_keys($input),$fields)||array_diff($fields,array_keys($input)))throw new RuntimeException('ACS_STATISTICS_INPUT_INVALID');
            foreach($input as $v)if(!is_string($v))throw new RuntimeException('ACS_STATISTICS_INPUT_INVALID');
            require_once __DIR__.'/../Application/StatisticsService.php';$service=new AF_ACS_StatisticsService($db,$access);
            $out=$op==='statistics_sync'?$service->synchronize($sid,$eid,$input['visibility']):$service->recover($sid,$eid);
            return '<p role="status">Статистика: '.(($out['publication']??null)==='delivered'?'доставка подтверждена':'доставка ожидается').'.</p>';
        }else throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
        return '<p role="status">Команда сохранена. Фактические состояния показаны ниже; начисление и выдача подтверждаются отдельно.</p>';
    }
    private static function rewardState(string $state):string
    {
        return match($state){'pending'=>'Ожидает выдачи · не начислено','prepared'=>'Намерение сохранено · не начислено','recovery_required'=>'Требует восстановления · начисление не подтверждено','issued'=>'Начислено · owner receipt проверен','blocked'=>'Заблокировано · не начислено',default=>'Состояние не подтверждено'};
    }
    public static function render(string $sid,string $eid):string
    {
        global $db,$mybb;
        $repo=new AF_ACS_EncounterRepository($db);$access=new AF_ACS_SessionAccess;
        $scope=(new AF_ACS_SessionService($repo,$access))->view($sid);$sid=$scope['session']['id'];$gm=$scope['can_audit'];
        $base='misc.php?action=acs_loot&session_id='.rawurlencode($sid);
        if($eid===''&&$gm){
            $list=(new AF_ACS_EncounterService($repo,$access))->list($sid,$mybb->get_input('encounter_after')?:'0');$items=$list['items'];$html='<section class="af-acs af-acs-completion"><h1>Завершение и награды</h1><p>Выберите столкновение. Навигация не изменяет его состояние.</p><ul>';
            foreach($items as $item)$html.='<li><a href="'.self::esc($base.'&encounter_id='.$item['id'].'&completion_tab=recovery').'">'.self::esc($item['name']).' · #'.self::esc($item['id']).'</a></li>';
            $html.='</ul>';if($list['next_after']!==null)$html.='<p><a href="'.self::esc($base.'&completion_tab=recovery&encounter_after='.$list['next_after']).'">Следующие столкновения</a></p>';
            return $html.'</section>';
        }
        if($eid==='')return '<form method="get" action="misc.php"><input type="hidden" name="action" value="acs_loot"><input type="hidden" name="session_id" value="'.self::esc($sid).'"><label>Столкновение #<input name="encounter_id" inputmode="numeric" required></label><button>Открыть завершение и награды</button></form>';
        $eid=AF_ACS_Repository::integer($eid);$url=$base.'&encounter_id='.rawurlencode($eid);
        // Validate encounter membership without exposing GM participant/config DTOs to players.
        if(!$repo->encounter($sid,$eid))throw new RuntimeException('ACS_ENCOUNTER_UNAVAILABLE');
        $tab=$mybb->get_input('completion_tab');$tabs=['outcome'=>'Результат','loot'=>'Добыча','rewards'=>'Награды','achievements'=>'Достижения','recovery'=>'Восстановление'];
        if(!isset($tabs[$tab]))$tab='loot';if(!$gm&&in_array($tab,['outcome','recovery'],true))$tab='loot';
        $notice='';
        if($mybb->request_method==='post'&&isset($_POST['op']))try{
            if((int)($_SERVER['CONTENT_LENGTH']??0)>65536)throw new RuntimeException('ACS_LOOT_INPUT_INVALID');
            $notice=self::dispatch($db,$repo,$access,$sid,$eid,$gm);$scope=(new AF_ACS_SessionService($repo,$access))->view($sid);
        }catch(Throwable $error){$notice=self::failure($error);}
        $html='<section class="af-acs af-acs-completion"><h1>Завершение и награды</h1><p><a href="showthread.php?tid='.self::esc($scope['session']['tid']).'">Вернуться в тему</a></p><nav aria-label="Этапы завершения">';
        foreach($tabs as $key=>$label)if($gm||!in_array($key,['outcome','recovery'],true))$html.='<a '.($tab===$key?'aria-current="page" ':'').'href="'.self::esc($url.'&completion_tab='.$key).'">'.self::esc($label).'</a> ';
        $html.='</nav>'.$notice;$action=$url.'&completion_tab='.$tab;
        try{
            if($tab==='loot')$html.=self::loot($db,$repo,$access,$scope,$eid,$action,$gm);
            elseif($tab==='rewards')$html.=self::rewards($db,$repo,$access,$scope,$eid,$action,$gm);
            elseif($tab==='achievements')$html.=self::achievements($db,$repo,$access,$scope,$eid,$action,$gm);
            elseif($tab==='outcome')$html.=self::outcome($repo,$access,$sid,$eid,$action);
            else $html.=self::recovery($db,$repo,$access,$sid,$eid,$action);
        }catch(Throwable $error){$html.=self::failure($error);}
        return $html.'</section>';
    }
    private static function common(array $scope,array $e):array
    {
        return ['session_id'=>$scope['session']['id'],'encounter_id'=>$e['id'],'expected_version'=>$scope['session']['session_version'],'encounter_version'=>$e['encounter_version'],'operation_key'=>bin2hex(random_bytes(16))];
    }
    private static function outcome(AF_ACS_EncounterRepository $repo,AF_ACS_SessionAccess $access,string $sid,string $eid,string $url):string
    {
        require_once __DIR__.'/EndConditionController.php';$state=(new AF_ACS_EncounterService($repo,$access))->resolution($sid,$eid);
        $html='<h2>Результат боя</h2><p>Подтверждение использует сохранённого кандидата. Resolver не запускается повторно; награды автоматически не выдаются.</p>';
        if($state['confirmed'])$html.='<p>Исход подтверждён: '.self::esc(self::outcomeLabel($state['confirmed']['outcome']['kind'])).'. Перейдите к добыче и наградам.</p>';
        $pending=$state['pending'];if(!$pending)return $html.'<p>Нет ожидающего подтверждения кандидата.</p>';
        $html.='<p>Ожидает решения · условие '.self::esc($pending['condition']).' · предполагаемая сторона победителя: '.self::esc($pending['winner_side_id']??'не определена').'</p>';$options='';
        foreach($pending['outcomes'] as $outcome)$options.='<option value="'.self::esc($outcome).'">'.self::esc(self::outcomeLabel($outcome)).'</option>';
        $fields=['session_id'=>$sid,'encounter_id'=>$eid,'expected_version'=>$state['expected_version'],'encounter_version'=>$state['encounter_version'],'resolution_id'=>$pending['resolution_id'],'operation_key'=>bin2hex(random_bytes(16))];
        $html.=self::form($url,'outcome_confirm',$fields,'<label>Предложенный исход <select name="outcome">'.$options.'</select></label>','Подтвердить исход');
        if($pending['allow_continuation']){$fields['operation_key']=bin2hex(random_bytes(16));$html.=self::form($url,'outcome_continue',$fields,'','Продолжить бой по сохранённой политике');}
        return $html;
    }
    private static function loot(object $db,AF_ACS_EncounterRepository $repo,AF_ACS_SessionAccess $access,array $scope,string $eid,string $url,bool $gm):string
    {
        require_once __DIR__.'/../Application/LootAllocationService.php';
        $view=(new AF_ACS_LootAllocationService($repo,$access))->view($scope['session']['id'],$eid);
        $html=AF_ACS_LootAllocationPage::renderView($view,(string)$GLOBALS['mybb']->post_code,$url);
        if(!$gm)return $html;
        require_once __DIR__.'/../Application/LootIssueService.php';$issue=new AF_ACS_LootIssueService($db,$access);
        $e=$repo->encounter($scope['session']['id'],$eid);$config=json_decode($e['config_json'],true,64,JSON_THROW_ON_ERROR);
        if($e['status']==='completed')foreach($config['loot_sources']??[] as $source=>$pin){
            require_once __DIR__.'/../Application/LootService.php';$pool=(new AF_ACS_LootService($repo,$access))->inspect($scope['session']['id'],$eid,$source)['pool'];
            $html.='<p>Источник '.self::esc($source).' · '.($pool===null?'не сгенерирован':'генерация сохранена').'</p>';
            if($pool===null&&$scope['session']['status']!=='active')$html.='<p>BLOCKED: новый источник генерируется только в активной сессии. Закрытие не даёт права на новую генерацию.</p>';
            elseif($pool===null)$html.=self::form($url,'loot_generate',self::common($scope,$e)+['source_key'=>$source],'','Сгенерировать закреплённый источник');
        }
        foreach($view['entries'] as $entry)if(in_array($entry['status'],['awaiting_issue','pending_issue','issued','issue_rejected'],true))try{
            $state=$issue->inspect($scope['session']['id'],$eid,$entry['entry_id']);$html.='<article><h3>Выдача '.self::esc($entry['item_key']).'</h3><p>'.self::esc(self::lootState($state['status'])).'</p>';
            if($state['status']==='awaiting_issue')$html.=self::form($url,'loot_issue',['session_id'=>$scope['session']['id'],'encounter_id'=>$eid,'entry_id'=>$entry['entry_id'],'expected_version'=>$scope['session']['session_version'],'expected_allocation_version'=>$entry['allocation_version'],'operation_key'=>bin2hex(random_bytes(16))],'','Запросить выдачу в Inventory');
            elseif($state['status']==='pending_issue'){
                $html.='<p>Победитель и результаты need/greed сохранены. Прежняя операция: '.self::esc($state['operation_key']).'.</p>';
                $html.=self::form($url,'loot_issue_recover',['session_id'=>$scope['session']['id'],'encounter_id'=>$eid,'entry_id'=>$entry['entry_id'],'expected_operation_version'=>$state['operation_version']],'','Проверить прежнюю выдачу (lookup)',false);
                if($state['owner_status']==='prepared')$html.=self::resume($db,$access,$scope['session']['id'],$eid,$entry['entry_id'],'loot',$url);
            }
            $html.='</article>';
        }catch(Throwable $error){$html.=self::failure($error);}
        return $html.self::configuration($scope,$e,$url,'loot_configure');
    }
    private static function resume(object $db,AF_ACS_SessionAccess $access,string $sid,string $eid,string $id,string $kind,string $url):string
    {
        try{
            require_once __DIR__.'/../Infrastructure/CompletionQuery.php';
            $original=(new AF_ACS_CompletionQuery($db))->retainedIssue($sid,$eid,$id,$kind,$access);
            return '<p>Намерение сохранено до отправки. Продолжение использует исходную команду, её версии, причину и ключ. Если отправка уже началась, сервис выполняет только безопасную проверку.</p>'.self::form($url,$kind==='loot'?'loot_issue':'reward_issue',$original,'','Продолжить сохранённое намерение',false);
        }catch(Throwable $error){return self::failure($error);}
    }
    private static function configuration(array $scope,array $e,string $url,string $op):string
    {
        if($scope['session']['status']!=='active'||$e['status']!=='preparation'||$e['round_number']!=='0')return '';
        $body='<label>Ключ разрешённого KB-источника <input name="kb_key" maxlength="64" required></label>';
        if($op==='loot_configure')$body.='<label>Источник <select name="source_kind"><option value="encounter">Столкновение</option><option value="kb">KB</option><option value="monster">NPC</option></select></label><label>ID NPC (только для NPC) <input name="source_entity_id" inputmode="numeric"></label>';
        return '<details><summary>Закрепить источник до старта</summary><p>Только существующие поддержанные определения KB. После старта новые источники не добавляются.</p>'.self::form($url,$op,self::common($scope,$e),$body,'Закрепить источник').'</details>';
    }
    private static function rewards(object $db,AF_ACS_EncounterRepository $repo,AF_ACS_SessionAccess $access,array $scope,string $eid,string $url,bool $gm):string
    {
        $html='<h2>EXP и валюта</h2><p>Обязательство и начисление различаются. Успех показан только после проверки owner receipt. Автоматической компенсации нет.</p>';
        if(!$gm)return $html.'<p>Текущий серверный контракт чтения наград доступен только GM. Обратитесь к GM за разрешённым результатом; суммы и получатели здесь не раскрываются.</p>';
        require_once __DIR__.'/../Application/RewardService.php';$service=new AF_ACS_RewardService($db,$access);$sid=$scope['session']['id'];$e=$repo->encounter($sid,$eid);
        $config=json_decode($e['config_json'],true,64,JSON_THROW_ON_ERROR);
        if($e['status']==='completed')foreach($config['reward_sources']??[] as $key=>$pin)try{
            $facts=$repo->rewardPlanFacts($sid,$eid,$key);$d=$facts['definition'];
            $saved=$repo->rewardCommandReceipt($sid,$eid,'reward.distribute','$.reward_plan.kb_key',$key);
            $html.='<details><summary>Распределение источника '.self::esc($key).'</summary>';
            if($saved)$html.='<p>Распределение закреплено. Выдача каждому получателю показана отдельно ниже.</p>';
            elseif($scope['session']['status']!=='active')$html.='<p>BLOCKED: новое распределение требует активной сессии. Сохранённые обязательства продолжают отображаться ниже.</p>';
            else{
                $body=($d['reward_type']==='permanent_sync'?'<p>Постоянная синхронизация CharacterSheets останется BLOCKED: подтверждённого owner API нет.</p>':'').'<p>Режим: '.self::esc(['each'=>'Каждому','split_equal'=>'Поровну','manual'=>'Вручную'][$d['mode']]).' · объём в минимальных единицах: '.self::esc($d['amount_minor']).'</p><ul>';
                foreach($facts['eligible'] as $recipient){$body.='<li>UID '.self::esc($recipient['uid']).' · '.self::esc($recipient['recipient_key']);if($d['mode']==='manual')$body.='<label>Количество в минимальных единицах <input name="manual_amount['.self::esc($recipient['recipient_key']).']" type="number" min="0" max="9007199254740991" step="1" value="0" required></label>';$body.='</li>';}
                $html.=self::form($url,'reward_distribute',self::common($scope,$e)+['kb_key'=>$key],$body.'</ul>','Закрепить распределение');
            }$html.='</details>';
        }catch(Throwable $error){$html.=self::failure($error);}
        $after=$GLOBALS['mybb']->get_input('reward_after')?:'0';$rows=$service->view($sid,$eid,$after)['rewards'];
        foreach($rows as $r){
            $type=['experience'=>'EXP','currency'=>'Валюта','permanent_sync'=>'Постоянная синхронизация CharacterSheets'][$r['reward_type']]??'Награда';
            $html.='<article><h3>'.self::esc($type).' · UID '.self::esc($r['recipient']['uid']).'</h3><p>'.self::esc(self::rewardState($r['status'])).'</p>';
            if($r['reward_type']!=='permanent_sync')$html.='<p>Количество: '.self::esc(self::amount($r['recipient']['amount_minor'])).' ('.self::esc($r['recipient']['amount_minor']).' минимальных единиц) · '.self::esc($r['recipient']['recipient_key']).'</p>';
            if($r['status']==='pending')$html.=self::form($url,'reward_issue',['session_id'=>$sid,'reward_id'=>$r['reward_id'],'expected_version'=>$scope['session']['session_version'],'expected_reward_version'=>$r['reward_version'],'operation_key'=>bin2hex(random_bytes(16))],'','Запросить начисление');
            elseif(in_array($r['status'],['prepared','recovery_required'],true)){
                $html.='<p>Прежняя операция: '.self::esc($r['operation_key']).'.</p>'.self::form($url,'reward_recover',['session_id'=>$sid,'reward_id'=>$r['reward_id'],'expected_operation_version'=>$r['operation_version']],'','Проверить прежнее начисление (lookup)',false);
                if($r['status']==='prepared')$html.=self::resume($db,$access,$sid,$eid,$r['reward_id'],'reward',$url);
            }elseif($r['status']==='blocked')$html.='<p>Owner API постоянной синхронизации CharacterSheets отсутствует. Это не EXP и не валюта; обход выдачи недоступен.</p>';
            $html.='</article>';
        }
        if(!$rows)$html.='<p>На этой странице нет обязательств по наградам.</p>';
        if(count($rows)===100)$html.='<p><a href="'.self::esc($url.'&reward_after='.end($rows)['reward_id']).'">Следующие получатели</a></p>';
        return $html.self::configuration($scope,$e,$url,'reward_configure');
    }
    private static function achievements(object $db,AF_ACS_EncounterRepository $repo,AF_ACS_SessionAccess $access,array $scope,string $eid,string $url,bool $gm):string
    {
        require_once __DIR__.'/../Application/AchievementService.php';
        $after=$gm?($GLOBALS['mybb']->get_input('achievement_after')?:'0'):'0';
        $rows=(new AF_ACS_AchievementService($db,$access))->view($scope['session']['id'],$after)['achievements'];
        $html='<h2>Достижения</h2><p>Разрешённый накопленный прогресс, связанный с сессией. Открытое достижение не означает, что его награда уже начислена.</p>';
        foreach($rows as $r)$html.='<article><h3>'.self::esc($r['name']).'</h3><p>'.self::esc($r['subject_kind']).' · '.self::esc($r['subject_key']).' · прогресс '.self::esc($r['progress']).' · '.($r['unlocked']?'достижение открыто':'ещё не открыто').(!empty($r['review_required'])?' · требуется проверка':'').'</p></article>';
        if(!$rows)$html.='<p>Доступного прогресса нет.</p>';
        if($gm){$e=$repo->encounter($scope['session']['id'],$eid);$html.=self::configuration($scope,$e,$url,'achievement_configure');
            if(count($rows)===100)$html.='<p><a href="'.self::esc($url.'&achievement_after='.end($rows)['progress_id']).'">Следующий прогресс</a></p>';
            try{
                require_once __DIR__.'/../Infrastructure/CompletionQuery.php';
                $pending=(new AF_ACS_CompletionQuery($db))->achievementSources($scope['session']['id'],$eid,$access);
                $html.='<h3>Необработанные источники этого столкновения</h3><p>Только сохранённая очередь. Повтор источника не увеличивает прогресс; механика боя не исполняется повторно.</p>';
                foreach($pending as $source){$fields=self::common($scope,$e);unset($fields['encounter_version']);
                    $html.=self::form($url,'achievement_evaluate',$fields+['achievement_key'=>$source['achievement_key'],'source_event_id'=>$source['source_event_id']],'<p>'.self::esc($source['achievement_key']).' · '.($source['event_type']==='combat.resolved'?'Подтверждённое боевое действие':'Подтверждённый исход').' #'.self::esc($source['source_event_id']).'</p>','Обработать источник');}
                if(!$pending)$html.='<p>Нет ожидающих источников.</p>';
            }catch(Throwable $error){$html.=self::failure($error);}
        }return $html;
    }
    private static function recovery(object $db,AF_ACS_EncounterRepository $repo,AF_ACS_SessionAccess $access,string $sid,string $eid,string $url):string
    {
        $scope=(new AF_ACS_SessionService($repo,$access))->view($sid,true);
        $html='<h2>Долги сессии</h2><p>Обязательства относятся ко всей сессии. Закрытие боя не подтверждает их исполнение; неподдержанные операции остаются заблокированными.</p>';
        foreach(['pending'=>'Ожидает исполнения','recovery_required'=>'Требует восстановления','explicitly_blocked'=>'Заблокировано'] as $key=>$label){
            $html.='<h3>'.$label.'</h3><ul>';$items=$scope['outstanding_obligations'][$key]??[];
            foreach($items as $r){
                $source=['encounter'=>'Столкновение','publication'=>'Публикация','sealed_action'=>'Запечатанное действие','prepared_execution'=>'Подготовленное действие','prepared_cost'=>'Резерв подготовки','custody'=>'Временное хранение','operation'=>'Owner-операция','loot_generation'=>'Генерация добычи','loot_pool'=>'Добыча','reward_distribution'=>'Распределение наград','reward'=>'Начисление награды','achievement_source'=>'Источник достижения','achievement_review'=>'Проверка достижения','compensation'=>'Компенсация','statistics'=>'Доставка статистики'][$r['source']]??'Дополнительное обязательство';
                $hint=match($r['recovery_scope']??null){'inventory.lookup'=>'Проверьте прежний ключ в панели Inventory.','custody.return'=>'Возврат доступен только существующей custody-командой после проверки резерва.','reward.balance_issue'=>'Откройте награды соответствующего столкновения и сохранённое намерение.','reward.balance_lookup'=>'В наградах выполните lookup прежней операции Balance.','loot.allocation'=>'Откройте добычу соответствующего столкновения; победитель при восстановлении не меняется.','statistics.sync'=>'Восстановите доставку статистики соответствующего столкновения.','achievement.evaluate'=>'Обработайте конкретный сохранённый источник на вкладке достижений.','achievement.review'=>'Требуется существующий review-контракт и отдельные полномочия. Здесь редактора истории нет.',default=>'Автоматического безопасного действия здесь нет. Проверьте действующий контракт и права; долг не списан.'};
                $html.='<li><strong>'.self::esc($source).'</strong> · '.self::esc($r['reference']).'<p>'.self::esc($hint).'</p></li>';
            }
            if(!$items)$html.='<li>Нет записей.</li>';$html.='</ul>';
        }
        try{
        require_once __DIR__.'/../Application/RecoveryService.php';$service=new AF_ACS_RecoveryService($db,$access);$state=$service->inspectStatistics($sid,$eid);
        $html.='<h3>Statistics выбранного столкновения</h3><p>'.($state['aggregate_version']===null?'Агрегат ещё не подготовлен':('Версия '.self::esc($state['aggregate_version']))).' · '.($state['pending']?'доставка ожидается':'нет ожидающих сообщений').'</p>';
        $encounter=$repo->encounter($sid,$eid);
        if($encounter['status']!=='completed')$html.='<p>Статистика отправляется только после подтверждённого завершения боя.</p>';
        elseif($state['aggregate_version']===null)$html.=self::form($url,'statistics_sync',['session_id'=>$sid,'encounter_id'=>$eid],'<label>Видимость первоначальной публикации <select name="visibility"><option value="private">Только GM</option><option value="participants">Участники</option><option value="public">Публично</option></select></label>','Подготовить и отправить статистику',false);
        elseif($state['pending'])$html.=self::form($url,'statistics_recover',['session_id'=>$sid,'encounter_id'=>$eid],'','Восстановить доставку с прежними ключами',false);
        }catch(Throwable $error){$html.=self::failure($error);}
        $html.='<p>Inventory, custody и публикации: <a href="misc.php?action=acs_recovery&amp;session_id='.self::esc($sid).'">открыть существующую панель восстановления</a>. Balance: вкладка «Награды», lookup каждой прежней операции. Добыча: вкладка «Добыча», lookup закреплённого победителя.</p>';
        return $html;
    }
}

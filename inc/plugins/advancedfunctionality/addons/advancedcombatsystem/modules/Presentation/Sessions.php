<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Application/SessionService.php';
require_once __DIR__.'/GmIntent.php';

final class AF_ACS_SessionsPage
{
    private const TABS = [
        'overview'=>'Обзор', 'members'=>'Участники и роли', 'entities'=>'Существа',
        'delegations'=>'Делегирование', 'management'=>'Управление сессией', 'recovery'=>'Восстановление',
    ];
    private const ROLES = [
        'owner'=>'Владелец', 'gm'=>'Мастер', 'assistant_gm'=>'Помощник мастера',
        'player'=>'Игрок', 'observer'=>'Наблюдатель',
    ];
    private const OPERATIONS = [
        'request_close'=>'Подготовить закрытие', 'close'=>'Закрыть с сохранением обязательств',
        'reopen_session'=>'Переоткрыть сессию', 'archive'=>'Архивировать с сохранением обязательств',
    ];

    private static function esc(mixed $value): string { return htmlspecialchars_uni((string)$value); }

    private static function state(string $status): string
    {
        return match ($status) {
            'active'=>'Активна', 'pending_close'=>'Ожидает закрытия', 'closed'=>'Закрыта',
            'archived'=>'В архиве', default=>'Состояние недоступно',
        };
    }

    private static function form(string $url,string $operation,array $fields): void
    {
        global $mybb;
        echo '<form method="post" action="'.self::esc($url).'">';
        echo AF_ACS_GmIntent::checkbox($operation);
        foreach (['op'=>$operation,'operation_key'=>bin2hex(random_bytes(16)),'my_post_key'=>$mybb->post_code]+$fields as $key=>$value) {
            echo '<input type="hidden" name="'.self::esc($key).'" value="'.self::esc($value).'">';
        }
    }

    private static function submit(string $label): void
    {
        echo '<label>Причина <textarea name="reason" rows="2" maxlength="1000" required></textarea></label>';
        echo '<button class="atf-button" type="submit">'.self::esc($label).'</button></form>';
    }

    public static function render(bool $admin, string &$assets = ''): void
    {
        global $db,$mybb;
        if (!af_is_addon_enabled('advancedcombatsystem')) { echo '<p>ACS отключён.</p>'; return; }
        $service=af_advancedcombatsystem_sessions();
        $access=new AF_ACS_SessionAccess;
        $url=$admin?'index.php?module=advancedfunctionality&af_view=advancedcombatsystem&acs_section=sessions':'misc.php?action=acs_sessions';
        $sid=$mybb->get_input('session_id');
        $notice='';$sourceCheck=[];
        $operation='';
        if ($mybb->request_method==='post') {
            $operation=$mybb->get_input('op');
            try {
                $notice=self::post($service,$operation,$sid,$sourceCheck);
            } catch (Throwable $error) {
                $failure=af_advancedcombatsystem_session_failure($error);
                $notice='<div class="af-acs-standalone__notice error" role="alert"><p>'.self::esc($failure['message'])
                    .' Код обращения: '.self::esc($failure['error_id']).'</p>';
                if (!empty($failure['diagnostics'])) {
                    require_once __DIR__.'/Entities.php';
                    $notice.=AF_ACS_EntitiesPage::diagnostics($failure['diagnostics']);
                }
                $notice.='</div>';
            }
        }
        if ($sid==='') {
            self::catalog($service,$access,$url,$notice);
            return;
        }

        try {
            $view=$service->view($sid,true);
            $session=$view['session'];
            $thread=$access->thread($session['tid']);
            $repository=new AF_ACS_SessionRepository($db);
            $roles=array_column($repository->roles($session['id'],$access->uid()),'role');
        } catch (Throwable $error) {
            if (!$admin) http_response_code(403);
            echo '<p role="alert">Сессия недоступна.</p><p><a href="'.self::esc($url).'">К списку сессий</a></p>';
            return;
        }

        $sid=$session['id'];
        $detail=$url.'&session_id='.$sid;
        if (!$admin) add_breadcrumb('Сессия #'.$sid,$detail);
        $staff=$view['can_manage'] || $view['can_audit'] || $access->administrator();
        $participant=(bool)array_intersect($roles,['owner','gm','assistant_gm','player']);
        $tabs=['overview'=>true,'members'=>$staff || $participant,'entities'=>$staff || $participant,
            'delegations'=>$staff || $participant,'management'=>$staff,'recovery'=>$view['can_audit']];
        $tab=$mybb->get_input('acs_session_tab');
        if ($tab==='') $tab=self::defaultTab($operation);

        // Receipts and validation feedback stay on the relevant legacy or current section.
        $tab=!empty($tabs[$tab])?$tab:'overview';
        $tabUrl=$detail.'&acs_session_tab='.$tab;
        $threadUrl=($admin?'../':'').'showthread.php?tid='.$session['tid'].'&acs_panel_session='.$sid.'#acs-thread-panel';
        $gm='';
        if ($view['can_audit']) {
            try {
                if (($repository->episodeMode($session['tid'])['mode']??null)!=='normal') {
                    $gm=($admin?'../':'').'showthread.php?tid='.$session['tid'].'&acs_session='.$sid.'&acs_setup=1&gm_step=1#acs-thread-panel';
                }
            } catch (Throwable $error) { /* Other session tools remain available. */ }
        }
        if ($gm!=='' && !$admin && in_array($tab,['overview','management'],true)) {
            require_once __DIR__.'/ThreadComponents.php';
            $assets=af_advancedcombatsystem_presentation_css('has_acs_panel').af_advancedcombatsystem_panel_script();
        }

        echo '<header class="af-acs-standalone__hero atf-card atf-stack"><div><h2>Сессия #'.self::esc($sid).'</h2><p>'
            .self::esc($thread['subject']).'</p></div>';
        echo '<dl class="af-acs-standalone__status"><div><dt>Состояние</dt><dd><span class="atf-chip">'
            .self::esc(self::state($session['status'])).'</span></dd></div>';
        if ($roles) echo '<div><dt>Ваша роль</dt><dd>'.self::esc(implode(', ',array_map(static fn($role)=>self::ROLES[$role]??'Участник',$roles))).'</dd></div>';
        if ($staff) echo '<div><dt>Владелец</dt><dd>UID '.self::esc($session['owner_uid']).'</dd></div><div><dt>Версия</dt><dd>'.self::esc($session['session_version']).'</dd></div>';
        echo '</dl><div class="atf-card__actions"><a class="atf-button atf-button--secondary" href="'.self::esc($threadUrl).'">Вернуться в тему</a>'
            .'<a class="atf-button atf-button--secondary" href="'.self::esc($url).'">Все сессии</a></div></header>';
        echo '<nav class="af-acs-standalone__tabs" aria-label="Разделы сессии">';
        foreach (self::TABS as $key=>$label) {
            if ($tabs[$key]) echo '<a class="atf-button atf-button--secondary" href="'.self::esc($detail.'&acs_session_tab='.$key).'"'
                .($key===$tab?' aria-current="page"':'').'>'.self::esc($label).'</a>';
        }
        echo '</nav>'.$notice;
        echo '<section class="af-acs-standalone__section atf-card atf-stack"><h2>'.self::esc(self::TABS[$tab]).'</h2>';
        if ($gm!=='' && in_array($tab,['overview','management'],true)) {
            echo '<p><a class="atf-button" data-acs-modal="gm" href="'.self::esc($gm).'">Открыть мастер GM</a></p>'
                .'<p data-acs-session-refresh="'.self::esc($sid).'" hidden role="status">Изменения сохранены. <a href="'.self::esc($tabUrl).'">Обновить страницу сессии</a></p>';
        }
        ob_start();
        try {
            switch ($tab) {
                case 'overview':
                    echo '<p>Тема <a href="'.self::esc($threadUrl).'">#'.self::esc($session['tid']).'</a></p>';
                    break;
                case 'members':
                    self::members($view,$access,$staff,$tabUrl,$operation);
                    break;
                case 'entities':
                    require_once __DIR__.'/Entities.php';
                    AF_ACS_EntitiesPage::render($sid,$view,$tabUrl,check:$sourceCheck);
                    break;
                case 'delegations':
                    require_once __DIR__.'/Delegations.php';
                    AF_ACS_DelegationsPage::render($sid,$tabUrl);
                    break;
                case 'management':
                    self::management($view,$tabUrl,$operation);
                    break;
                case 'recovery':
                    self::recovery($view,$admin);
                    break;
            }
            echo ob_get_clean();
        } catch (Throwable $error) {
            ob_end_clean();
            echo '<p role="alert">Раздел сессии недоступен.</p>';
        }
        echo '</section>';
    }

    private static function defaultTab(string $operation): string
    {
        global $mybb;
        if (str_starts_with($operation,'entity_') || $mybb->get_input('entity_after')!=='' || $mybb->get_input('source_after')!=='' || $mybb->get_input('source_slug')!=='' || $mybb->get_input('kind')!=='') return 'entities';
        if (str_starts_with($operation,'delegation_') || $mybb->get_input('delegation_after')!=='') return 'delegations';
        if (in_array($operation,['set_role','remove_member','transfer_owner'],true)) return 'members';
        return isset(self::OPERATIONS[$operation])?'management':'overview';
    }

    private static function post(AF_ACS_SessionService $service,string $operation,string &$sid,array &$sourceCheck): string
    {
        global $mybb;
        $input=[];
        foreach (['operation_key','reason','tid','owner_uid','session_id','expected_version','uid','role','kind','source_key','name','initial_hp','preparation_fingerprint'] as $key) {
            $input[$key]=$mybb->get_input($key);
        }
        AF_ACS_GmIntent::requireConfirmation($operation);
        if (str_starts_with($operation,'delegation_')) {
            $delegationInput=[];
            foreach (['session_id','expected_version','operation_key','reason','owner_uid','delegate_uid','entity_ids','control_group_id','permitted_actions','valid_from','valid_until','duration_seconds','delegation_id','delegation_version'] as $field) {
                if (array_key_exists($field,$_POST)) {
                    $delegationInput[$field]=in_array($field,['entity_ids','permitted_actions'],true)?$_POST[$field]:$mybb->get_input($field);
                }
            }
            af_advancedcombatsystem_delegations()->command(substr($operation,11),$delegationInput);
            return '<p class="af-acs-standalone__notice" role="status">Делегирование обновлено.</p>';
        }
        if ($operation==='entity_validate') {
            $sourceCheck=af_advancedcombatsystem_entities()->validate($input['session_id'],$input)+$input;
            return '';
        }
        if ($operation==='entity_register' && $input['preparation_fingerprint']==='') {
            throw new RuntimeException('ACS_SOURCE_CHECK_REQUIRED');
        }
        $result=$operation==='entity_register'?af_advancedcombatsystem_entities()->register($input):$service->command($operation,$input);
        $sid=$result['session_id'];
        return '<p class="af-acs-standalone__notice" role="status">Изменение сохранено.</p>';
    }

    private static function members(array $view,AF_ACS_SessionAccess $access,bool $staff,string $url,string $operation): void
    {
        $session=$view['session'];
        $uid=$access->uid();
        echo '<ul class="af-acs-standalone__members">';
        foreach ($view['members'] as $member) {
            if (!$staff && $member['uid']!==$uid) continue;
            $expiry=$member['expires_at'];
            echo '<li class="atf-card"><strong>UID '.self::esc($member['uid']).'</strong> <span class="atf-chip">'
                .self::esc(self::ROLES[$member['role']]??'Участник').'</span>';
            if ($expiry!==null) echo '<small>'.self::esc((int)$expiry<=time()?'Срок истёк':'До '.my_date('relative',(int)$expiry)).'</small>';
            echo '</li>';
        }
        echo '</ul>';
        if (!$view['can_manage']) return;
        $actions=['set_role'=>'Назначить / изменить роль','remove_member'=>'Удалить участника'];
        if ($access->administrator()) $actions['transfer_owner']='Передать владение сессией';
        $defaultRole=json_decode($session['config_json'],true)['default_member_role']??'observer';
        foreach ($actions as $op=>$label) {
            self::disclosure($label,$op===$operation);
            self::form($url,$op,['session_id'=>$session['id'],'expected_version'=>$session['session_version']]);
            echo '<label>'.($op==='transfer_owner'?'UID нового владельца':'UID участника').' <input name="uid" type="number" min="1" required></label>';
            if ($op==='set_role') {
                echo '<label>Роль <select name="role">';
                foreach (self::ROLES as $role=>$name) if ($role!=='owner') {
                    echo '<option value="'.self::esc($role).'"'.($role===$defaultRole?' selected':'').'>'.self::esc($name).'</option>';
                }
                echo '</select></label>';
            }
            self::submit($label);
            echo '</details>';
        }
    }

    private static function management(array $view,string $url,string $operation): void
    {
        $session=$view['session'];
        echo '<p>Закрытие и архивирование сохраняют обязательства, резервы и историю. Переоткрытие сохраняет ресурсы сессии. Завершённые столкновения остаются завершёнными.</p>';
        foreach (self::OPERATIONS as $op=>$label) {
            if (empty($view['can_'.($op==='reopen_session'?'reopen':$op)])) continue;
            self::disclosure($label,$op===$operation);
            self::form($url,$op,['session_id'=>$session['id'],'expected_version'=>$session['session_version']]);
            self::submit($label);
            echo '</details>';
        }
    }

    private static function recovery(array $view,bool $admin): void
    {
        $states=['confirmed_completed'=>'Подтверждены','pending'=>'Ожидают',
            'recovery_required'=>'Требуют восстановления','explicitly_blocked'=>'Блокируют закрытие'];
        echo '<dl class="af-acs-standalone__status">';
        foreach ($states as $state=>$label) echo '<div><dt>'.self::esc($label).'</dt><dd>'
            .count($view['outstanding_obligations'][$state]??[]).'</dd></div>';
        echo '</dl><p><a class="atf-button" href="'.($admin?'../':'').'misc.php?action=acs_recovery&amp;session_id='
            .self::esc($view['session']['id']).'">Открыть восстановление обязательств</a></p>';
    }

    private static function disclosure(string $title,bool $open=false): void
    {
        echo '<details class="af-acs-standalone__disclosure"'.($open?' open':'').'><summary>'.self::esc($title).'</summary>';
    }

    private static function catalog(AF_ACS_SessionService $service,AF_ACS_SessionAccess $access,string $url,string $notice): void
    {
        global $mybb;
        $list=$service->list($mybb->get_input('after')?:'0');
        echo $notice.'<section class="af-acs-standalone__section atf-card atf-stack"><h2>Доступные сессии</h2>';
        if (!$list['items']) {
            echo '<p>На этой странице нет доступных сессий.</p>';
        } else {
            echo '<div class="af-acs-standalone__table" role="region" aria-label="Доступные сессии" tabindex="0"><table><thead><tr><th scope="col">Сессия и тема</th><th scope="col">Состояние</th></tr></thead><tbody>';
            foreach ($list['items'] as $item) {
                $row=$item['session'];
                echo '<tr><td><a href="'.self::esc($url.'&session_id='.$row['id']).'">#'.self::esc($row['id']).' — '
                    .self::esc($item['subject']).'</a></td><td><span class="atf-chip">'.self::esc(self::state($row['status'])).'</span></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        if ($list['next_after']!==null) echo '<p><a class="atf-button atf-button--secondary" href="'.self::esc($url.'&after='.$list['next_after']).'">Следующие сессии</a></p>';
        if ($access->canCreate()) {
            self::disclosure('Создать сессию',$mybb->request_method==='post');
            self::form($url,'create',[]);
            echo '<label>ID темы <input type="number" min="1" name="tid" required></label><label>UID владельца <input type="number" min="1" name="owner_uid" value="'.self::esc($access->uid()).'" required></label>';
            self::submit('Создать сессию');
            echo '</details>';
        }
        echo '</section>';
    }
}

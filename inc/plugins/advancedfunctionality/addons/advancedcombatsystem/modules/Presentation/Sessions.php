<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Application/SessionService.php';

final class AF_ACS_SessionsPage
{
    private static function esc(mixed $v): string {return htmlspecialchars_uni((string)$v);}
    private static function form(string $url,string $operation,array $fields): void
    {
        global $mybb;
        echo '<form method="post" action="'.self::esc($url).'">';
        foreach(['op'=>$operation,'operation_key'=>bin2hex(random_bytes(16)),'my_post_key'=>$mybb->post_code]+$fields as $key=>$value)echo '<input type="hidden" name="'.self::esc($key).'" value="'.self::esc($value).'">';
    }
    public static function render(bool $admin): void
    {
        global $mybb;
        if(!af_is_addon_enabled('advancedcombatsystem')) {echo '<p>ACS отключён.</p>';return;}
        $service=af_advancedcombatsystem_sessions();$access=new AF_ACS_SessionAccess;
        $url=$admin?'index.php?module=advancedfunctionality&af_view=advancedcombatsystem&acs_section=sessions':'misc.php?action=acs_sessions';
        $sid=$mybb->get_input('session_id');
        if($mybb->request_method==='post') {
            try {
                $input=[];foreach(['operation_key','reason','tid','owner_uid','session_id','expected_version','uid','role'] as $key)$input[$key]=$mybb->get_input($key);
                $result=$service->command($mybb->get_input('op'),$input);$sid=$result['session_id'];echo '<p>Изменение сохранено.</p>';
            } catch(Throwable $error) {
                // No exception contents, SQL or protected data in browser output.
                $failure=af_advancedcombatsystem_session_failure($error);
                echo '<div class="error">'.self::esc($failure['message']).' Код обращения: '.self::esc($failure['error_id']).'</div>';
            }
        }
        if($sid!=='') {
            try {
                $view=$service->view($sid);$session=$view['session'];
                echo '<h2>Сессия #'.self::esc($session['id']).'</h2><p>Тема: <a href="'.($admin?'../':'').'showthread.php?tid='.self::esc($session['tid']).'">#'.self::esc($session['tid']).'</a> · Владелец UID '.self::esc($session['owner_uid']).' · '.self::esc($session['status']).' · Версия '.self::esc($session['session_version']).'</p>';
                echo '<ul>';foreach($view['members'] as $member)echo '<li>UID '.self::esc($member['uid']).' — '.self::esc(self::roles()[$member['role']]??$member['role']).'</li>';echo '</ul>';
                if($view['can_manage']) {
                    foreach(['set_role'=>'Назначить / изменить роль','remove_member'=>'Удалить участника'] as $op=>$label) {
                        self::form($url,$op,['session_id'=>$sid,'expected_version'=>$session['session_version']]);
                        echo '<h3>'.$label.'</h3><label>UID участника <input name="uid" type="number" min="1" required></label>';
                        if($op==='set_role') {echo '<label>Роль <select name="role">';foreach(self::roles() as $role=>$name)if($role!=='owner')echo '<option value="'.$role.'"'.($role===(json_decode($session['config_json'],true)['default_member_role']??'observer')?' selected':'').'>'.$name.'</option>';echo '</select></label>';}
                        echo '<label>Причина <input name="reason" maxlength="1000" required></label><button type="submit">'.$label.'</button></form>';
                    }
                    if($access->administrator()) {
                        self::form($url,'transfer_owner',['session_id'=>$sid,'expected_version'=>$session['session_version']]);
                        echo '<h3>Передать владение сессией</h3><label>UID нового владельца <input type="number" min="1" name="uid" required></label><label>Причина <input name="reason" maxlength="1000" required></label><button type="submit">Передать владение</button></form>';
                    }
                }
                echo '<p>Закрытие, архив и reopen пока недоступны: проверки обязательств будут реализованы на этапе 10.</p>';
            } catch(Throwable $error) {echo '<p>Сессия недоступна.</p>';}
        }
        $list=$service->list($mybb->get_input('after')?:'0');
        echo '<h2>Доступные сессии</h2><table><thead><tr><th>Сессия / тема</th><th>Владелец</th><th>Состояние</th></tr></thead><tbody>';
        foreach($list['items'] as $item) {$row=$item['session'];echo '<tr><td><a href="'.self::esc($url.'&session_id='.$row['id']).'">#'.self::esc($row['id']).' — '.self::esc($item['subject']).'</a></td><td>UID '.self::esc($row['owner_uid']).'</td><td>'.self::esc($row['status']).'</td></tr>';}
        echo '</tbody></table>';
        if($list['next_after']!==null)echo '<a href="'.self::esc($url.'&after='.$list['next_after']).'">Далее</a>';
        if($access->canCreate()) {
            self::form($url,'create',[]);echo '<h3>Создать сессию</h3><label>ID темы <input type="number" min="1" name="tid" required></label><label>UID владельца <input type="number" min="1" name="owner_uid" value="'.self::esc($access->uid()).'" required></label><label>Причина <input name="reason" maxlength="1000" required></label><button type="submit">Создать сессию</button></form>';
        }
    }
    private static function roles(): array {return ['owner'=>'Владелец','gm'=>'Мастер','assistant_gm'=>'Помощник мастера','player'=>'Игрок','observer'=>'Наблюдатель'];}
}

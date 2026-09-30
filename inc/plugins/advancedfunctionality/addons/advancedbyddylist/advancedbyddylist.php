<?php
/** Advanced Buddy List: reciprocal friendships and one-way ignores. */
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_ABDL_ID', 'advancedbyddylist'); // Historical addon id; retained for upgrades.
define('AF_ABDL_FRIENDSHIPS', 'af_buddy_friendships');
define('AF_ABDL_IGNORES', 'af_buddy_ignores');
define('AF_ABDL_PAGE_ALIAS_SIGNATURE', 'AF_ABDL_BUDDY_PAGE_ALIAS');

function af_advancedbyddylist_menu_provider(): void
{
    af_menu_register_item([
        'key' => 'friends', 'source_addon' => AF_ABDL_ID, 'label' => 'Друзья',
        'icon' => 'fa-solid fa-user-group', 'type' => 'link', 'section' => 'links',
        'default_container' => 'user_drawer', 'default_sortorder' => 30,
        'visibility' => static function (): bool { global $mybb; return !empty($mybb->user['uid']) && af_abdl_is_enabled(); },
        'action' => ['url' => 'buddy.php'],
    ]);
}

function af_advancedbyddylist_install(): void
{
    af_abdl_ensure_settings();
    af_abdl_ensure_schema();
    af_abdl_migrate_legacy();
    af_abdl_install_page_alias();
}
function af_advancedbyddylist_activate(): void { af_advancedbyddylist_install(); }
function af_advancedbyddylist_upgrade(): void { af_abdl_install_page_alias(); }
function af_advancedbyddylist_deactivate(): void { /* Relations intentionally survive deactivation. */ }
function af_advancedbyddylist_uninstall(): void
{
    af_abdl_remove_settings();
    af_abdl_remove_page_alias();
    /* User relations are retained deliberately. */
}
function af_advancedbyddylist_init(): void
{
    global $mybb;

    // Only administrators visiting the frontend repair a deleted alias. Existing
    // files are never rewritten here, so normal requests incur no file writes.
    if (!defined('IN_ADMINCP')
        && af_abdl_is_enabled()
        && !empty($mybb->usergroup)
        && (int)($mybb->usergroup['cancp'] ?? 0) === 1
        && !file_exists(MYBB_ROOT . 'buddy.php')
    ) {
        af_abdl_install_page_alias();
    }
}

function af_abdl_install_page_alias(): bool
{
    $source = __DIR__ . '/assets/buddy.php';
    $destination = MYBB_ROOT . 'buddy.php';

    if (!is_file($source) || !is_readable($source)) {
        return false;
    }

    $sourceCode = @file_get_contents($source);
    if ($sourceCode === false || trim($sourceCode) === '') {
        return false;
    }

    if (is_file($destination)) {
        $destinationCode = @file_get_contents($destination);
        if ($destinationCode === false
            || strpos($destinationCode, AF_ABDL_PAGE_ALIAS_SIGNATURE) === false
        ) {
            return false;
        }
        if (hash('sha256', $destinationCode) === hash('sha256', $sourceCode)) {
            return true;
        }
    }

    // PHP cannot atomically replace an existing file with rename() on every
    // supported Windows setup. Use a locked overwrite there; new aliases and
    // Unix updates use a verified temporary file followed by an atomic rename.
    if (DIRECTORY_SEPARATOR === '\\' && is_file($destination)) {
        $written = @file_put_contents($destination, $sourceCode, LOCK_EX);
        return $written === strlen($sourceCode)
            && @file_get_contents($destination) === $sourceCode;
    }

    $temporary = @tempnam(dirname($destination), '.af-abdl-');
    if ($temporary === false) {
        return false;
    }

    $written = @file_put_contents($temporary, $sourceCode, LOCK_EX);
    $valid = $written === strlen($sourceCode)
        && @file_get_contents($temporary) === $sourceCode;
    if (!$valid || !@rename($temporary, $destination)) {
        @unlink($temporary);
        return false;
    }

    @chmod($destination, 0644);
    return @file_get_contents($destination) === $sourceCode;
}

function af_abdl_remove_page_alias(): bool
{
    $destination = MYBB_ROOT . 'buddy.php';
    if (!is_file($destination) || !is_readable($destination)) {
        return !file_exists($destination);
    }

    $code = @file_get_contents($destination);
    if ($code === false || strpos($code, AF_ABDL_PAGE_ALIAS_SIGNATURE) === false) {
        return false;
    }

    return @unlink($destination);
}

function af_abdl_is_enabled(): bool
{
    global $mybb;
    return !isset($mybb->settings['af_abdl_enabled']) || !empty($mybb->settings['af_abdl_enabled']);
}

function af_abdl_ensure_schema(): void
{
    global $db;
    $collation = $db->build_create_table_collation();
    if (!$db->table_exists(AF_ABDL_FRIENDSHIPS)) {
        $db->write_query("CREATE TABLE `{$db->table_prefix}" . AF_ABDL_FRIENDSHIPS . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `requester_uid` int unsigned NOT NULL,
            `addressee_uid` int unsigned NOT NULL,
            `pair_low` int unsigned NOT NULL,
            `pair_high` int unsigned NOT NULL,
            `status` enum('pending','accepted') NOT NULL DEFAULT 'pending',
            `created_at` int unsigned NOT NULL,
            `updated_at` int unsigned NOT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `pair` (`pair_low`,`pair_high`),
            KEY `requester_status` (`requester_uid`,`status`), KEY `addressee_status` (`addressee_uid`,`status`)
        ) ENGINE=MyISAM{$collation}");
    }
    if (!$db->table_exists(AF_ABDL_IGNORES)) {
        $db->write_query("CREATE TABLE `{$db->table_prefix}" . AF_ABDL_IGNORES . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT, `uid` int unsigned NOT NULL,
            `ignored_uid` int unsigned NOT NULL, `created_at` int unsigned NOT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `owner_target` (`uid`,`ignored_uid`), KEY `ignored_uid` (`ignored_uid`)
        ) ENGINE=MyISAM{$collation}");
    }
}

/** One-time, idempotent import. Legacy buddy entries become accepted relations. */
function af_abdl_migrate_legacy(): void
{
    global $db;
    if (!$db->table_exists(AF_ABDL_FRIENDSHIPS) || !$db->table_exists(AF_ABDL_IGNORES)) return;
    $q = $db->simple_select('users', 'uid,buddylist,ignorelist', "buddylist<>'' OR ignorelist<>''");
    while ($user = $db->fetch_array($q)) {
        $uid = (int)$user['uid'];
        foreach (af_abdl_parse_uid_csv((string)$user['buddylist']) as $other) {
            if ($other === $uid || !af_abdl_user_exists($other)) continue;
            [$low, $high] = [min($uid, $other), max($uid, $other)];
            $exists = (int)$db->fetch_field($db->simple_select(AF_ABDL_FRIENDSHIPS, 'id', "pair_low={$low} AND pair_high={$high}", ['limit'=>1]), 'id');
            if (!$exists) $db->insert_query(AF_ABDL_FRIENDSHIPS, ['requester_uid'=>$uid,'addressee_uid'=>$other,'pair_low'=>$low,'pair_high'=>$high,'status'=>'accepted','created_at'=>TIME_NOW,'updated_at'=>TIME_NOW]);
        }
        foreach (af_abdl_parse_uid_csv((string)$user['ignorelist']) as $other) {
            if ($other === $uid || !af_abdl_user_exists($other)) continue;
            $exists = (int)$db->fetch_field($db->simple_select(AF_ABDL_IGNORES, 'id', "uid={$uid} AND ignored_uid={$other}", ['limit'=>1]), 'id');
            if (!$exists) $db->insert_query(AF_ABDL_IGNORES, ['uid'=>$uid,'ignored_uid'=>$other,'created_at'=>TIME_NOW]);
        }
    }
}

function af_abdl_user_exists(int $uid): bool
{
    global $db;
    return $uid > 0 && (int)$db->fetch_field($db->simple_select('users', 'uid', "uid={$uid}", ['limit'=>1]), 'uid') === $uid;
}
function af_abdl_parse_uid_csv(string $csv): array
{
    $out=[]; foreach (preg_split('~\s*,\s*~', trim($csv), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $value) { $id=(int)$value; if ($id>0) $out[$id]=$id; }
    return array_values($out);
}

function af_abdl_relation(int $uid, int $other): ?array
{
    global $db;
    $low=min($uid,$other); $high=max($uid,$other);
    $row=$db->fetch_array($db->simple_select(AF_ABDL_FRIENDSHIPS, '*', "pair_low={$low} AND pair_high={$high}", ['limit'=>1]));
    return $row ?: null;
}
function af_abdl_is_ignored_either_way(int $uid, int $other): bool
{
    global $db;
    return (bool)$db->fetch_field($db->simple_select(AF_ABDL_IGNORES, 'id', "(uid={$uid} AND ignored_uid={$other}) OR (uid={$other} AND ignored_uid={$uid})", ['limit'=>1]), 'id');
}

/** Execute a state transition. This is the authoritative backend validation layer. */
function af_abdl_apply_action(string $action, int $uid, int $target): array
{
    global $db;
    if ($uid <= 0) return [false, 'Требуется авторизация.'];
    if ($target <= 0 || $target === $uid || !af_abdl_user_exists($target)) return [false, 'Некорректный пользователь.'];
    $relation=af_abdl_relation($uid,$target);
    [$low,$high]=[min($uid,$target),max($uid,$target)];

    if ($action === 'add') {
        if (af_abdl_is_ignored_either_way($uid,$target)) return [false, 'Сначала необходимо снять игнорирование.'];
        if ($relation) return [false, 'Заявка или дружба уже существует.'];
        $db->insert_query(AF_ABDL_FRIENDSHIPS,['requester_uid'=>$uid,'addressee_uid'=>$target,'pair_low'=>$low,'pair_high'=>$high,'status'=>'pending','created_at'=>TIME_NOW,'updated_at'=>TIME_NOW]);
        af_abdl_alert($target,'buddy_request',$uid);
        return [true,'Заявка отправлена.'];
    }
    if ($action === 'accept') {
        if (!$relation || $relation['status'] !== 'pending' || (int)$relation['addressee_uid'] !== $uid) return [false,'Эта входящая заявка недоступна.'];
        if (af_abdl_is_ignored_either_way($uid,$target)) return [false,'Нельзя принять заявку при активном игнорировании.'];
        $db->update_query(AF_ABDL_FRIENDSHIPS,['status'=>'accepted','updated_at'=>TIME_NOW],'id='.(int)$relation['id']);
        af_abdl_sync_legacy_pair($uid,$target); af_abdl_alert($target,'buddy_accepted',$uid);
        return [true,'Заявка принята.'];
    }
    if ($action === 'decline' && $relation && $relation['status']==='pending' && (int)$relation['addressee_uid']===$uid) {
        $db->delete_query(AF_ABDL_FRIENDSHIPS,'id='.(int)$relation['id']); return [true,'Заявка отклонена.'];
    }
    if ($action === 'cancel' && $relation && $relation['status']==='pending' && (int)$relation['requester_uid']===$uid) {
        $db->delete_query(AF_ABDL_FRIENDSHIPS,'id='.(int)$relation['id']); return [true,'Заявка отменена.'];
    }
    if ($action === 'remove' && $relation && $relation['status']==='accepted') {
        $db->delete_query(AF_ABDL_FRIENDSHIPS,'id='.(int)$relation['id']); af_abdl_sync_legacy_pair($uid,$target); return [true,'Дружба удалена.'];
    }
    if ($action === 'ignore') {
        if ($relation) $db->delete_query(AF_ABDL_FRIENDSHIPS,'id='.(int)$relation['id']);
        if (!$db->fetch_field($db->simple_select(AF_ABDL_IGNORES,'id',"uid={$uid} AND ignored_uid={$target}",['limit'=>1]),'id')) {
            $db->insert_query(AF_ABDL_IGNORES,['uid'=>$uid,'ignored_uid'=>$target,'created_at'=>TIME_NOW]);
        }
        af_abdl_sync_legacy_pair($uid,$target); return [true,'Пользователь добавлен в игнор-лист.'];
    }
    if ($action === 'unignore') {
        $db->delete_query(AF_ABDL_IGNORES,"uid={$uid} AND ignored_uid={$target}"); af_abdl_sync_legacy_user($uid); return [true,'Пользователь удалён из игнор-листа.'];
    }
    return [false,'Действие не соответствует текущему состоянию связи.'];
}

/** AF tables are truth; MyBB CSV fields are only a compatibility projection. */
function af_abdl_sync_legacy_pair(int $a, int $b): void { af_abdl_sync_legacy_user($a); af_abdl_sync_legacy_user($b); }
function af_abdl_sync_legacy_user(int $uid): void
{
    global $db;
    $friends=[]; $q=$db->simple_select(AF_ABDL_FRIENDSHIPS,'requester_uid,addressee_uid',"status='accepted' AND (requester_uid={$uid} OR addressee_uid={$uid})");
    while ($r=$db->fetch_array($q)) $friends[]=(int)$r['requester_uid']===$uid?(int)$r['addressee_uid']:(int)$r['requester_uid'];
    $ignores=[]; $q=$db->simple_select(AF_ABDL_IGNORES,'ignored_uid',"uid={$uid}"); while($r=$db->fetch_array($q)) $ignores[]=(int)$r['ignored_uid'];
    sort($friends); sort($ignores);
    $db->update_query('users',['buddylist'=>implode(',',$friends),'ignorelist'=>implode(',',$ignores)],"uid={$uid}");
}

function af_abdl_alert(int $to, string $code, int $from): void
{
    if (function_exists('af_aam_register_type')) af_aam_register_type($code, $code === 'buddy_request' ? 'Новая заявка в друзья' : 'Заявка в друзья принята', 1, 1, 1);
    if (function_exists('af_aam_add_alert')) af_aam_add_alert($to,$code,0,$from,['url'=>'buddy.php?tab=friends']);
}

function af_abdl_fetch_users(array $ids): array
{
    global $db;
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids)))); if (!$ids) return [];
    $in=implode(',',$ids); $online=[]; $q=$db->simple_select('sessions','uid',"uid IN ({$in}) AND time>".(TIME_NOW-900)); while($r=$db->fetch_array($q)) $online[(int)$r['uid']]=true;
    $users=[]; $q=$db->simple_select('users','uid,username,avatar,lastactive',"uid IN ({$in})"); while($r=$db->fetch_array($q)){ $r['online']=isset($online[(int)$r['uid']]); $users[(int)$r['uid']]=$r; }
    return $users;
}

function af_abdl_action_buttons(int $uid, int $target): string
{
    global $db;
    $relation=af_abdl_relation($uid,$target);
    $ignored=(bool)$db->fetch_field($db->simple_select(AF_ABDL_IGNORES,'id',"uid={$uid} AND ignored_uid={$target}",['limit'=>1]),'id');
    $buttons='';
    if ($ignored) $buttons.=af_abdl_button('unignore',$target,'Убрать из игнор-листа');
    elseif (!$relation) $buttons.=af_abdl_button('add',$target,'Добавить в друзья').af_abdl_button('ignore',$target,'Добавить в игнор-лист','muted');
    elseif ($relation['status']==='accepted') $buttons.=af_abdl_button('remove',$target,'Убрать из друзей').af_abdl_button('ignore',$target,'Добавить в игнор-лист','muted');
    elseif ((int)$relation['requester_uid']===$uid) $buttons.='<span class="af-abdl-chip">Заявка отправлена</span>'.af_abdl_button('cancel',$target,'Отменить','muted');
    else $buttons.=af_abdl_button('accept',$target,'Принять').af_abdl_button('decline',$target,'Отклонить','muted');
    return $buttons;
}
function af_abdl_button(string $action,int $uid,string $label,string $class=''): string
{
    global $mybb;
    return '<form class="af-abdl-action" method="post" action="buddy.php"><input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni($mybb->post_code).'"><input type="hidden" name="action" value="'.$action.'"><input type="hidden" name="uid" value="'.$uid.'"><button class="af-abdl-btn '.$class.'" type="submit">'.htmlspecialchars_uni($label).'</button></form>';
}
function af_abdl_card(array $u,string $actions='',bool $pm=false): string
{
    $id=(int)$u['uid']; $avatar=trim((string)$u['avatar']) ?: 'images/default_avatar.png';
    $html='<article class="af-abdl-card" data-uid="'.$id.'"><img class="af-abdl-avatar" src="'.htmlspecialchars_uni($avatar).'" alt=""><div class="af-abdl-person"><strong>'.htmlspecialchars_uni($u['username']).'</strong>';
    if(isset($u['online'])) $html.='<span class="af-abdl-status '.($u['online']?'online':'').'">'.($u['online']?'В сети':'Не в сети').'</span>';
    $html.='</div><div class="af-abdl-actions"><a class="af-abdl-btn muted" href="member.php?action=profile&amp;uid='.$id.'">Профиль</a>';
    if($pm) $html.='<a class="af-abdl-btn muted" href="private.php?action=send&amp;uid='.$id.'">ЛС</a>';
    return $html.$actions.'</div></article>';
}

function af_abdl_render_page(): void
{
    global $mybb,$db,$headerinclude,$header,$footer;
    if (empty($mybb->user['uid'])) error_no_permission();
    if (!af_abdl_is_enabled()) error('Advanced Buddy List отключён.');
    af_abdl_ensure_schema(); $uid=(int)$mybb->user['uid'];
    $ajax=!empty($mybb->input['ajax']);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $valid=verify_post_check((string)($mybb->input['my_post_key'] ?? ''),true);
        $result=$valid ? af_abdl_apply_action((string)($mybb->input['action'] ?? ''),$uid,(int)($mybb->input['uid'] ?? 0)) : [false,'Неверный CSRF-токен.'];
        if ($ajax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>$result[0],'message'=>$result[1]],JSON_UNESCAPED_UNICODE); exit; }
        if (!$result[0]) error($result[1]);
        redirect('buddy.php?tab=' . (((string)$mybb->input['action']==='unignore'||(string)$mybb->input['action']==='ignore')?'ignore':'friends'),$result[1]);
    }
    $tab=in_array(($mybb->input['tab']??'friends'),['friends','ignore','search'],true)?(string)$mybb->input['tab']:'friends';
    $content='';
    if ($tab==='friends') {
        $relations=[]; $ids=[]; $q=$db->simple_select(AF_ABDL_FRIENDSHIPS,'*',"requester_uid={$uid} OR addressee_uid={$uid}",['order_by'=>'updated_at','order_dir'=>'DESC']);
        while($r=$db->fetch_array($q)){ $other=(int)$r['requester_uid']===$uid?(int)$r['addressee_uid']:(int)$r['requester_uid']; $r['other']=$other; $relations[]=$r; $ids[]=$other; }
        $users=af_abdl_fetch_users($ids); $accepted=$incoming=$outgoing='';
        foreach($relations as $r){ if(!isset($users[$r['other']]))continue; if($r['status']==='accepted')$accepted.=af_abdl_card($users[$r['other']],af_abdl_button('remove',$r['other'],'Убрать из друзей'),true); elseif((int)$r['addressee_uid']===$uid)$incoming.=af_abdl_card($users[$r['other']],af_abdl_button('accept',$r['other'],'Принять').af_abdl_button('decline',$r['other'],'Отклонить')); else $outgoing.=af_abdl_card($users[$r['other']],'<span class="af-abdl-chip">Ожидание</span>'.af_abdl_button('cancel',$r['other'],'Отменить')); }
        $content=af_abdl_section('Друзья',$accepted).af_abdl_section('Входящие заявки',$incoming).af_abdl_section('Исходящие заявки',$outgoing);
    } elseif($tab==='ignore') {
        $ids=[];$q=$db->simple_select(AF_ABDL_IGNORES,'ignored_uid',"uid={$uid}",['order_by'=>'created_at','order_dir'=>'DESC']);while($r=$db->fetch_array($q))$ids[]=(int)$r['ignored_uid'];$users=af_abdl_fetch_users($ids);foreach($ids as $id)if(isset($users[$id]))$content.=af_abdl_card($users[$id],af_abdl_button('unignore',$id,'Убрать из игнор-листа'));$content=af_abdl_section('Игнор-лист',$content);
    } else {
        $query=trim((string)($mybb->input['q']??'')); $content='<form class="af-abdl-search" method="get"><input type="hidden" name="tab" value="search"><input name="q" minlength="2" maxlength="50" value="'.htmlspecialchars_uni($query).'" placeholder="Имя пользователя"><button class="af-abdl-btn">Найти</button></form><div class="af-abdl-results">';
        if(my_strlen($query)>=2){$esc=$db->escape_string_like($query);$q=$db->simple_select('users','uid,username,avatar,lastactive',"uid<>{$uid} AND username LIKE '%{$esc}%'",['order_by'=>'username','limit'=>25]);while($u=$db->fetch_array($q))$content.=af_abdl_card($u,af_abdl_action_buttons($uid,(int)$u['uid']));}$content.='</div>';
    }
    $base=rtrim((string)$mybb->settings['bburl'],'/').'/inc/plugins/advancedfunctionality/addons/'.AF_ABDL_ID.'/assets/';
    $headerinclude.='<link rel="stylesheet" href="'.htmlspecialchars_uni($base.'advancedbyddylist.css?v=2').'"><script defer src="'.htmlspecialchars_uni($base.'advancedbyddylist.js?v=2').'"></script>';
    $tabs='';foreach(['friends'=>'Друзья','ignore'=>'Игнор-лист','search'=>'Поиск'] as $key=>$label)$tabs.='<a class="af-abdl-tab '.($tab===$key?'is-active':'').'" href="buddy.php?tab='.$key.'">'.$label.'</a>';
    $page='<!doctype html><html><head><title>Друзья</title>'.$headerinclude.'</head><body>'.$header.'<main class="af-abdl-page"><header class="af-abdl-heading"><h1>Друзья</h1><nav class="af-abdl-tabs">'.$tabs.'</nav></header><div id="af-abdl-content">'.$content.'</div></main>'.$footer.'</body></html>';
    output_page($page);
}
function af_abdl_section(string $title,string $content): string { return '<section class="af-abdl-section"><h2>'.$title.'</h2>'.($content?:'<p class="af-abdl-empty">Пока пусто.</p>').'</section>'; }

function af_abdl_ensure_settings(): void
{
    global $db; $gid=(int)$db->fetch_field($db->simple_select('settinggroups','gid',"name='af_abdl'",['limit'=>1]),'gid');
    if(!$gid){$db->insert_query('settinggroups',['name'=>'af_abdl','title'=>'AF: Advanced Buddy List','description'=>'Полноценная система друзей и игнорирования.','disporder'=>100,'isdefault'=>0]);$gid=(int)$db->insert_id();}
    if(!$db->fetch_field($db->simple_select('settings','sid',"name='af_abdl_enabled'",['limit'=>1]),'sid'))$db->insert_query('settings',['name'=>'af_abdl_enabled','title'=>'Enable Advanced Buddy List','description'=>'Enable friendship system.','optionscode'=>'yesno','value'=>'1','disporder'=>1,'gid'=>$gid]);
    rebuild_settings();
}
function af_abdl_remove_settings(): void { global $db; $db->delete_query('settings',"name='af_abdl_enabled'");$db->delete_query('settinggroups',"name='af_abdl'");rebuild_settings(); }

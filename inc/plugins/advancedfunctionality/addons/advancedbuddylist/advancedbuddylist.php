<?php
/** Advanced Buddy List: reciprocal friendships and one-way ignores. */
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_ABDL_ID', 'advancedbuddylist');
define('AF_ABDL_LEGACY_ID', 'advancedbyddylist');
define('AF_ABDL_PAGE_ALIAS_SIGNATURE', 'AF_ABDL_BUDDY_PAGE_ALIAS');

function af_advancedbuddylist_install(): void
{
    $stage = 'migrate_identity';

    try {
        af_abdl_migrate_addon_identity();

        $stage = 'install_page_alias';
        af_abdl_install_page_alias();
    } catch (Throwable $error) {
        if (function_exists('af_store_addon_lifecycle_diagnostic')) {
            af_store_addon_lifecycle_diagnostic(AF_ABDL_ID, 'enable', $stage, $error);
        }
        throw $error;
    }
}
function af_advancedbuddylist_activate(): void { af_advancedbuddylist_install(); }
function af_advancedbuddylist_upgrade(): void { af_advancedbuddylist_install(); }
function af_advancedbuddylist_deactivate(): void { /* Relations intentionally survive deactivation. */ }
function af_advancedbuddylist_uninstall(): void
{
    af_abdl_remove_settings();
    af_abdl_remove_page_alias();
    /* User relations are retained deliberately. */
}
function af_advancedbuddylist_init(): void
{
    global $mybb;

    // Also completes cleanup when AF loaded us through manifest legacy_ids.
    af_abdl_migrate_addon_identity();

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
    if (function_exists('af_is_addon_enabled')) {
        return af_is_addon_enabled(AF_ABDL_ID);
    }
    return isset($mybb->settings['af_advancedbuddylist_enabled'])
        && (string)$mybb->settings['af_advancedbuddylist_enabled'] === '1';
}

/**
 * MyBB owns buddy/ignore persistence. Advanced Buddy List is only a UI facade.
 * Native sources: users.buddylist, users.ignorelist and buddyrequests.
 */
function af_abdl_parse_uid_csv(string $csv): array
{
    $out=[];
    foreach (preg_split('~\\s*,\\s*~', trim($csv), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $value) {
        $id=(int)$value;
        if ($id>0) $out[$id]=$id;
    }
    return array_values($out);
}

function af_abdl_native_state(int $uid): array
{
    global $db, $mybb;

    $buddies=array_fill_keys(af_abdl_parse_uid_csv((string)($mybb->user['buddylist'] ?? '')), true);
    $ignored=array_fill_keys(af_abdl_parse_uid_csv((string)($mybb->user['ignorelist'] ?? '')), true);
    $incoming=[];
    $outgoing=[];

    $q=$db->simple_select(
        'buddyrequests',
        'id,uid,touid,date',
        'uid='.(int)$uid.' OR touid='.(int)$uid,
        ['order_by'=>'date','order_dir'=>'DESC']
    );
    while($row=$db->fetch_array($q)) {
        if ((int)$row['touid'] === $uid) {
            $incoming[(int)$row['uid']]=$row;
        } else {
            $outgoing[(int)$row['touid']]=$row;
        }
    }

    return [
        'buddies'=>$buddies,
        'ignored'=>$ignored,
        'incoming'=>$incoming,
        'outgoing'=>$outgoing,
    ];
}

function af_abdl_native_form(array $fields,string $label,string $class='',string $icon=''): string
{
    global $mybb;

    $fields=array_merge([
        'my_post_key'=>(string)$mybb->post_code,
        'af_abdl_return'=>'1',
    ], $fields);

    $hidden='';
    foreach($fields as $name=>$value) {
        $hidden.='<input type="hidden" name="'.htmlspecialchars_uni((string)$name).'" value="'.htmlspecialchars_uni((string)$value).'">';
    }

    $iconHtml=$icon!=='' ? '<i class="fa-solid '.htmlspecialchars_uni($icon).'" aria-hidden="true"></i>' : '';
    $labelHtml=$iconHtml!=='' ? $iconHtml : htmlspecialchars_uni($label);
    $extraClass=$iconHtml!=='' ? ' af-abdl-icon-btn' : '';
    $formClass=$iconHtml!=='' ? ' af-abdl-icon-action' : '';

    $actionUrl=rtrim((string)($mybb->settings['bburl'] ?? ''), '/').'/usercp.php';

    return '<form class="af-abdl-action'.$formClass.'" method="post" action="'.htmlspecialchars_uni($actionUrl).'">'
        .$hidden
        .'<button class="af-abdl-btn '.$class.$extraClass.'" type="submit" title="'.htmlspecialchars_uni($label).'" aria-label="'.htmlspecialchars_uni($label).'">'
        .$labelHtml
        .'</button></form>';
}

function af_abdl_native_add_friend_form(array $user): string
{
    return af_abdl_native_form([
        'action'=>'do_editlists',
        'manage'=>'buddy',
        'add_username'=>(string)$user['username'],
    ], 'Добавить в друзья', '', 'fa-user-plus');
}

function af_abdl_native_remove_friend_form(int $uid): string
{
    return af_abdl_native_form([
        'action'=>'do_editlists',
        'manage'=>'buddy',
        'delete'=>$uid,
    ], 'Убрать из друзей');
}

function af_abdl_native_ignore_form(array $user): string
{
    return af_abdl_native_form([
        'action'=>'do_editlists',
        'manage'=>'ignored',
        'add_username'=>(string)$user['username'],
    ], 'Добавить в игнор-лист', 'muted');
}

function af_abdl_native_unignore_form(int $uid): string
{
    return af_abdl_native_form([
        'action'=>'do_editlists',
        'manage'=>'ignored',
        'delete'=>$uid,
    ], 'Убрать из игнор-листа');
}

function af_abdl_native_request_form(string $action,int $requestId,string $label,string $class=''): string
{
    return af_abdl_native_form([
        'action'=>$action,
        'id'=>$requestId,
    ], $label, $class);
}

function af_abdl_fetch_users(array $ids): array
{
    global $db;
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids)))); if (!$ids) return [];
    $in=implode(',',$ids); $online=[]; $q=$db->simple_select('sessions','uid',"uid IN ({$in}) AND time>".(TIME_NOW-900)); while($r=$db->fetch_array($q)) $online[(int)$r['uid']]=true;
    $users=[]; $q=$db->simple_select('users','uid,username,avatar,lastactive',"uid IN ({$in})"); while($r=$db->fetch_array($q)){ $r['online']=isset($online[(int)$r['uid']]); $users[(int)$r['uid']]=$r; }
    return $users;
}

function af_abdl_search_action(array $user,array $state): string
{
    $target=(int)$user['uid'];
    if (isset($state['buddies'][$target])) return '<span class="af-abdl-chip">В друзьях</span>';
    if (isset($state['ignored'][$target])) return '<span class="af-abdl-chip">В игнор-листе</span>';
    if (isset($state['outgoing'][$target])) return '<span class="af-abdl-chip">Заявка отправлена</span>';
    if (isset($state['incoming'][$target])) return '<span class="af-abdl-chip">Входящая заявка</span>';
    return af_abdl_native_add_friend_form($user);
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

    $uid=(int)$mybb->user['uid'];
    $state=af_abdl_native_state($uid);
    $tab=in_array(($mybb->input['tab']??'friends'),['friends','ignore','search'],true)
        ? (string)$mybb->input['tab']
        : 'friends';
    $content='';

    if ($tab==='friends') {
        $friendIds=array_map('intval', array_keys($state['buddies']));
        $incomingIds=array_map('intval', array_keys($state['incoming']));
        $outgoingIds=array_map('intval', array_keys($state['outgoing']));
        $users=af_abdl_fetch_users(array_merge($friendIds,$incomingIds,$outgoingIds));

        $accepted=$incoming=$outgoing='';
        foreach($friendIds as $id) {
            if (!isset($users[$id])) continue;
            $accepted.=af_abdl_card($users[$id],af_abdl_native_remove_friend_form($id),true);
        }
        foreach($state['incoming'] as $fromUid=>$request) {
            $fromUid=(int)$fromUid;
            if (!isset($users[$fromUid])) continue;
            $actions=af_abdl_native_request_form('acceptrequest',(int)$request['id'],'Принять')
                .af_abdl_native_request_form('declinerequest',(int)$request['id'],'Отклонить','muted');
            $incoming.=af_abdl_card($users[$fromUid],$actions);
        }
        foreach($state['outgoing'] as $toUid=>$request) {
            $toUid=(int)$toUid;
            if (!isset($users[$toUid])) continue;
            $actions='<span class="af-abdl-chip">Ожидание</span>'
                .af_abdl_native_request_form('cancelrequest',(int)$request['id'],'Отменить','muted');
            $outgoing.=af_abdl_card($users[$toUid],$actions);
        }

        $content=af_abdl_section('Друзья',$accepted)
            .af_abdl_section('Входящие заявки',$incoming)
            .af_abdl_section('Исходящие заявки',$outgoing);
    } elseif($tab==='ignore') {
        $ignoreIds=array_map('intval', array_keys($state['ignored']));
        $users=af_abdl_fetch_users($ignoreIds);
        $rows='';
        foreach($ignoreIds as $id) {
            if (!isset($users[$id])) continue;
            $rows.=af_abdl_card($users[$id],af_abdl_native_unignore_form($id));
        }
        $content=af_abdl_section('Игнор-лист',$rows);
    } else {
        $query=trim((string)($mybb->input['q']??''));
        $content='<form class="af-abdl-search" method="get"><input type="hidden" name="tab" value="search"><input name="q" minlength="2" maxlength="50" value="'.htmlspecialchars_uni($query).'" placeholder="Имя пользователя"><button class="af-abdl-btn">Найти</button></form><div class="af-abdl-results">';
        $where="uid<>{$uid}";
        if(my_strlen($query)>=2){
            $esc=$db->escape_string_like($query);
            $where.=" AND username LIKE '%{$esc}%'";
        }
        $q=$db->simple_select('users','uid,username,avatar,lastactive',$where,['order_by'=>'username','order_dir'=>'ASC','limit'=>50]);
        while($user=$db->fetch_array($q)){
            $content.=af_abdl_card($user,af_abdl_search_action($user,$state));
        }
        $content.='</div>';
    }

    $base=rtrim((string)$mybb->settings['bburl'],'/').'/inc/plugins/advancedfunctionality/addons/'.AF_ABDL_ID.'/assets/';
    $assetVersion='2.3.1';
    $headerinclude.='<link rel="stylesheet" href="'.htmlspecialchars_uni($base.'advancedbuddylist.css?v='.$assetVersion).'"><script defer src="'.htmlspecialchars_uni($base.'advancedbuddylist.js?v='.$assetVersion).'"></script>';
    $tabs='';
    foreach(['friends'=>'Друзья','ignore'=>'Игнор-лист','search'=>'Поиск'] as $key=>$label) {
        $tabs.='<a class="af-abdl-tab '.($tab===$key?'is-active':'').'" href="buddy.php?tab='.$key.'">'.$label.'</a>';
    }
    $page='<!doctype html><html><head><title>Друзья</title>'.$headerinclude.'</head><body>'.$header
        .'<main class="af-abdl-page"><header class="af-abdl-heading"><h1>Друзья</h1><nav class="af-abdl-tabs">'.$tabs
        .'</nav></header><div id="af-abdl-content">'.$content.'</div></main>'.$footer.'</body></html>';
    output_page($page);
}
function af_abdl_section(string $title,string $content): string { return '<section class="af-abdl-section"><h2>'.$title.'</h2>'.($content?:'<p class="af-abdl-empty">Пока пусто.</p>').'</section>'; }

/**
 * Migrate lifecycle ownership without touching friendship/ignore relations.
 * Canonical > old AF lifecycle > old private switch > enabled-by-default.
 */
function af_abdl_migrate_addon_identity(): void
{
    global $db, $mybb;
    static $migrated = false;
    if ($migrated) return;
    $names = ['af_advancedbuddylist_enabled', 'af_advancedbyddylist_enabled', 'af_abdl_enabled'];
    $found = [];
    $query = $db->simple_select('settings', 'sid,gid,name,value', "name IN ('".implode("','", $names)."')");
    while ($row = $db->fetch_array($query)) $found[(string)$row['name']] = $row;
    $value = af_abdl_select_lifecycle_value(array_map(static fn(array $row): string => (string)$row['value'], $found));
    $changed = false;

    $gid = (int)$db->fetch_field($db->simple_select('settinggroups','gid',"name='af_advancedbuddylist'",['limit'=>1]),'gid');
    if (!$gid) {
        $db->insert_query('settinggroups',['name'=>'af_advancedbuddylist','title'=>'AF: Advanced Buddy List','description'=>'Полноценная система друзей и игнорирования.','disporder'=>100,'isdefault'=>0]);
        $gid=(int)$db->insert_id();
        $changed = true;
    }
    if (!isset($found[$names[0]])) {
        $db->insert_query('settings',['name'=>$names[0],'title'=>'Enable Advanced Buddy List','description'=>'Enable friendship system.','optionscode'=>'yesno','value'=>$value,'disporder'=>1,'gid'=>$gid]);
        $changed = true;
    } elseif ((int)$found[$names[0]]['gid'] !== $gid) {
        $db->update_query('settings', ['gid'=>$gid], 'sid='.(int)$found[$names[0]]['sid']);
        $changed = true;
    }
    if (isset($found[$names[1]]) || isset($found[$names[2]])) {
        $db->delete_query('settings', "name IN ('af_advancedbyddylist_enabled','af_abdl_enabled')");
        $changed = true;
    }
    foreach (['af_advancedbyddylist','af_abdl'] as $group) {
        $legacyGid=(int)$db->fetch_field($db->simple_select('settinggroups','gid',"name='".$db->escape_string($group)."'",['limit'=>1]),'gid');
        if ($legacyGid && !(int)$db->fetch_field($db->simple_select('settings','sid','gid='.$legacyGid,['limit'=>1]),'sid')) {
            $db->delete_query('settinggroups','gid='.$legacyGid);
            $changed = true;
        }
    }
    af_abdl_migrate_theme_stylesheet_ownership();
    if ($changed) rebuild_settings();
    if (isset($mybb->settings) && is_array($mybb->settings)) {
        $mybb->settings[$names[0]] = $value;
        unset($mybb->settings[$names[1]], $mybb->settings[$names[2]]);
    }

    // Mark the migration complete only after every step succeeds. If a SQL or
    // filesystem error interrupts the migration, a retry must be allowed.
    $migrated = true;
}

function af_abdl_select_lifecycle_value(array $settings): string
{
    foreach (['af_advancedbuddylist_enabled','af_advancedbyddylist_enabled','af_abdl_enabled'] as $name) {
        if (array_key_exists($name, $settings)) return (string)$settings[$name];
    }
    return '1';
}

/** Generic AF manifest migration entry point. */
function af_advancedbuddylist_migrate_identity(): void { af_abdl_migrate_addon_identity(); }

function af_abdl_migrate_theme_stylesheet_ownership(): void
{
    global $db;

    // Existing AF installations may have an older registry schema. The Buddy
    // identity migration writes updated_at, so upgrade the shared registry
    // before touching legacy ownership rows.
    if (function_exists('af_theme_stylesheets_install_schema')) {
        af_theme_stylesheets_install_schema();
    }

    if (defined('AF_THEME_STYLESHEETS_TABLE') && $db->table_exists(AF_THEME_STYLESHEETS_TABLE)) {
        $q=$db->simple_select(AF_THEME_STYLESHEETS_TABLE,'id,theme_tid,logical_id',"addon_id='advancedbyddylist'");
        while($row=$db->fetch_array($q)) {
            $logical=preg_replace('~^advancedbyddylist~','advancedbuddylist',(string)$row['logical_id']);
            // Prefer the historical row because it may contain the site's
            // manual override state; discard a newly auto-created duplicate.
            $duplicate=$db->fetch_field($db->simple_select(AF_THEME_STYLESHEETS_TABLE,'id',
                "theme_tid=".(int)$row['theme_tid']." AND addon_id='advancedbuddylist' AND logical_id='".$db->escape_string($logical)."'",['limit'=>1]),'id');
            if ($duplicate && (int)$duplicate !== (int)$row['id']) $db->delete_query(AF_THEME_STYLESHEETS_TABLE,'id='.(int)$duplicate);
            $db->update_query(AF_THEME_STYLESHEETS_TABLE,['addon_id'=>AF_ABDL_ID,'logical_id'=>$logical,'updated_at'=>TIME_NOW],'id='.(int)$row['id']);
        }
    }
    if (!function_exists('af_theme_stylesheet_parse_bundle') || !function_exists('af_theme_stylesheet_encode_section')) return;
    $q=$db->simple_select('themestylesheets','sid,stylesheet',"name='advancedstyles.css'");
    while($row=$db->fetch_array($q)) {
        $parsed=af_theme_stylesheet_parse_bundle((string)$row['stylesheet']);
        if (empty($parsed['ok'])) continue;
        $css=(string)$row['stylesheet']; $changed=false; $edits=[];
        $sections=$parsed['sections'];
        foreach($sections as $section) {
            $meta=$section['meta'];
            if (($meta['addon_id']??'') !== AF_ABDL_LEGACY_ID) continue;
            $meta['addon_id']=AF_ABDL_ID;
            foreach(['logical_id','source_file'] as $key) $meta[$key]=str_replace(AF_ABDL_LEGACY_ID,AF_ABDL_ID,(string)($meta[$key]??''));
            $replacement=af_theme_stylesheet_encode_section($meta,(string)$section['body']);
            $targetId=sha1((string)$meta['addon_id']."\0".(string)$meta['logical_id']."\0".(string)$meta['source_file']);
            if (isset($sections[$targetId])) $edits[]=['start'=>$sections[$targetId]['start'],'end'=>$sections[$targetId]['end'],'body'=>''];
            $edits[]=['start'=>$section['start'],'end'=>$section['end'],'body'=>$replacement]; $changed=true;
        }
        usort($edits,static fn(array $a,array $b): int=>$b['start']<=>$a['start']);
        foreach($edits as $edit) $css=substr($css,0,$edit['start']).$edit['body'].substr($css,$edit['end']);
        if ($changed) $db->update_query('themestylesheets',['stylesheet'=>$db->escape_string($css),'lastmodified'=>TIME_NOW],'sid='.(int)$row['sid']);
    }
}

function af_abdl_remove_settings(): void
{
    global $db;
    $gid=(int)$db->fetch_field($db->simple_select('settinggroups','gid',"name='af_advancedbuddylist'",['limit'=>1]),'gid');
    $db->delete_query('settings',"name='af_advancedbuddylist_enabled'");
    if ($gid && !(int)$db->fetch_field($db->simple_select('settings','sid','gid='.$gid,['limit'=>1]),'sid')) $db->delete_query('settinggroups','gid='.$gid);
    rebuild_settings();
}

<?php
/** Personal presets share Appearance's catalog/assignments. No upload or user CSS. */
if (!defined('IN_MYBB')) die('No direct access');

class AF_AA_PersonalException extends RuntimeException {}

function af_aa_personal_targets(): array
{
    return [
        'postbit' => ['label'=>'Постбит', 'implemented'=>true, 'assignment_target'=>AF_AA_TARGET_APUI_POSTBIT_PACK, 'entity_type'=>'user'],
        'profile' => ['label'=>'Профиль', 'implemented'=>false, 'assignment_target'=>AF_AA_TARGET_APUI_PROFILE_PACK, 'entity_type'=>'user'],
        'sheet' => ['label'=>'Лист персонажа', 'implemented'=>false, 'assignment_target'=>AF_AA_TARGET_APUI_SHEET_PACK, 'entity_type'=>'user'],
        'theme' => ['label'=>'Тема', 'implemented'=>false, 'assignment_target'=>AF_AA_TARGET_APUI_THREAD_PACK, 'entity_type'=>'thread'],
    ];
}

function af_aa_personal_fonts(): array
{
    return ['inherit'=>'inherit', 'system'=>'system-ui, sans-serif', 'sans'=>'Arial, sans-serif',
        'serif'=>'Georgia, serif', 'mono'=>'monospace'];
}

/** Static decorations only. Canvas, canonical element keys and motion permissions stay owned by ElementTheme. */
function af_aa_personal_effects(): array { return ['inherit', 'flat', 'soft_glow']; }

function af_aa_personal_system_preset(string $target): array
{
    if (!isset(af_aa_personal_targets()[$target])) throw new AF_AA_PersonalException('Unknown target.', 422);
    return ['id'=>0, 'target_key'=>$target, 'title'=>'По умолчанию', 'virtual'=>true, 'immutable'=>true, 'settings'=>new stdClass()];
}

function af_aa_personal_field_exists(string $field): bool
{
    global $db;
    return $db->table_exists(AF_AA_PRESETS_TABLE_NAME) && $db->field_exists($field, AF_AA_PRESETS_TABLE_NAME);
}

/** Additive migration at install/activate/upgrade only; legacy values/assignments are untouched. */
function af_aa_personal_install_schema(): void
{
    global $db;
    $fields = [
        'owner_uid'=>'INT UNSIGNED NOT NULL DEFAULT 0',
        'schema_version'=>'SMALLINT UNSIGNED NOT NULL DEFAULT 1',
        'publication_state'=>"VARCHAR(16) NOT NULL DEFAULT 'ready'",
        'draft_json'=>'MEDIUMTEXT NULL',
    ];
    foreach ($fields as $field=>$definition) if (!af_aa_personal_field_exists($field)) {
        $db->write_query('ALTER TABLE '.AF_AA_PRESETS_TABLE.' ADD '.$field.' '.$definition);
    }
    $q=$db->write_query("SHOW INDEX FROM ".AF_AA_PRESETS_TABLE." WHERE Key_name='owner_target'");
    if (!$db->fetch_array($q)) $db->write_query('ALTER TABLE '.AF_AA_PRESETS_TABLE.' ADD KEY owner_target (owner_uid,target_key,enabled)');
    $db->write_query('CREATE TABLE IF NOT EXISTS '.TABLE_PREFIX."af_aa_slot_grants (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT, uid INT UNSIGNED NOT NULL,
        preset_type VARCHAR(24) NOT NULL, capacity INT UNSIGNED NOT NULL,
        source_key VARCHAR(191) NOT NULL, is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at BIGINT UNSIGNED NOT NULL DEFAULT 0, updated_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id), UNIQUE KEY source_key (source_key), KEY owner_type (uid,preset_type,is_enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Atomic draft/publish/delete/assignment transactions require transactional Appearance tables.
    foreach ([AF_AA_PRESETS_TABLE_NAME, AF_AA_ASSIGNMENTS_TABLE_NAME, 'af_aa_slot_grants'] as $table) {
        $q=$db->write_query("SHOW TABLE STATUS WHERE Name='".$db->escape_string(TABLE_PREFIX.$table)."'");
        $row=$db->fetch_array($q);
        if ($row && strcasecmp((string)$row['Engine'], 'InnoDB')!==0) $db->write_query('ALTER TABLE '.TABLE_PREFIX.$table.' ENGINE=InnoDB');
    }
    unset($GLOBALS['af_aa_personal_schema_ready']);
}

function af_aa_personal_schema_available(): bool
{
    global $db;
    if (!array_key_exists('af_aa_personal_schema_ready', $GLOBALS)) {
        $GLOBALS['af_aa_personal_schema_ready']=af_aa_personal_field_exists('owner_uid')
            && af_aa_personal_field_exists('schema_version') && af_aa_personal_field_exists('draft_json')
            && af_aa_personal_field_exists('publication_state') && $db->table_exists('af_aa_slot_grants');
    }
    return $GLOBALS['af_aa_personal_schema_ready'];
}

function af_aa_personal_catalog_scope(): string
{
    return af_aa_personal_field_exists('owner_uid') ? ' AND owner_uid=0' : '';
}

function af_aa_personal_setting(string $key, int $fallback, int $min, int $max): int
{
    global $mybb;
    $value=$mybb->settings['af_advancedappearance_'.$key] ?? $fallback;
    return max($min, min($max, (int)$value));
}

function af_aa_personal_ensure_settings(): void
{
    global $db;
    if (!function_exists('af_ensure_setting')) return;
    $settings=[
        'personal_types'=>['Разрешённые личные пресеты','CSV target keys; сейчас реализован только postbit.','text','postbit'],
        'personal_json_limit'=>['Максимальный JSON (байт)','Структурированные параметры; raw CSS запрещён.','numeric','4096'],
        'personal_title_limit'=>['Длина названия','Количество Unicode символов, до 191.','numeric','80'],
        'personal_media_limit'=>['Медиа на один пресет','Количество уникальных Gallery media IDs.','numeric','3'],
    ];
    foreach (af_aa_personal_targets() as $key=>$meta) {
        $settings['free_slots_'.$key]=['Бесплатные слоты: '.$meta['label'],'Виртуальный системный пресет не расходует ёмкость.','numeric',$key==='postbit'?'1':'0'];
        $settings['max_slots_'.$key]=['Максимум слотов: '.$meta['label'],'Общий предел бесплатной и приобретённой ёмкости.','numeric','20'];
    }
    $order=10;
    foreach ($settings as $key=>$entry) {
        $name='af_advancedappearance_'.$key;
        $q=$db->simple_select('settings','sid',"name='".$db->escape_string($name)."'",['limit'=>1]);
        if (!$db->fetch_field($q,'sid')) af_ensure_setting('af_advancedappearance',$name,$entry[0],$entry[1],$entry[2],$entry[3],$order);
        $order++;
    }
}

function af_aa_personal_actor(int $uid): void
{
    global $mybb;
    if (!af_aa_is_enabled() || $uid<=0 || $uid!==(int)($mybb->user['uid']??0)
        || (isset($mybb->usergroup['canview']) && !$mybb->usergroup['canview'])
        || !empty($mybb->usergroup['isbannedgroup'])) throw new AF_AA_PersonalException('forbidden',403);
    if (!af_aa_personal_schema_available()) throw new AF_AA_PersonalException('Activate/upgrade AdvancedAppearance first.',503);
}

function af_aa_personal_target(string $target, bool $write=true): array
{
    global $mybb;
    $meta=af_aa_personal_targets()[$target]??null;
    if (!$meta) throw new AF_AA_PersonalException('Unknown target.',422);
    if ($write) {
        $allowed=array_map('trim',explode(',',(string)($mybb->settings['af_advancedappearance_personal_types']??'postbit')));
        if (!$meta['implemented'] || !in_array($target,$allowed,true)) throw new AF_AA_PersonalException('Personal target is not enabled/implemented.',422);
    }
    return $meta;
}

/** Serialize per-owner quotas and assignments even when MyBB users is a legacy MyISAM table. */
function af_aa_personal_transaction(int $uid, callable $work)
{
    global $db;
    af_aa_personal_actor($uid);
    $database=(string)$db->fetch_field($db->write_query('SELECT DATABASE() AS db_name'),'db_name');
    $key='af_aa_owner_'.sha1($database.':'.TABLE_PREFIX.':'.$uid);
    $acquired=(int)$db->fetch_field($db->write_query("SELECT GET_LOCK('{$key}',10) AS acquired"),'acquired');
    if ($acquired!==1) throw new AF_AA_PersonalException('Busy; retry this request.',409);
    try {
        $db->write_query('START TRANSACTION');
        $result=$work();
        $db->write_query('COMMIT');
        af_aa_personal_invalidate($uid);
        return $result;
    } catch (Throwable $e) {
        $db->write_query('ROLLBACK'); throw $e;
    } finally {
        $db->write_query("SELECT RELEASE_LOCK('{$key}')");
    }
}

function af_aa_personal_invalidate(int $uid): void
{
    // All old public helpers use these same request caches; do not leave stale personal definitions.
    unset($GLOBALS['af_aa_payload_cache_runtime'][$uid]);
    $GLOBALS['af_aa_preset_cache_runtime']=[];
    $GLOBALS['af_aa_assignment_cache_runtime']=[];
}

function af_aa_personal_slots(int $uid, string $target): array
{
    global $db;
    af_aa_personal_actor($uid);af_aa_personal_target($target,false);
    $max=af_aa_personal_setting('max_slots_'.$target,20,0,1000);
    $free=af_aa_personal_setting('free_slots_'.$target,$target==='postbit'?1:0,0,1000);
    $purchased=(int)$db->fetch_field($db->simple_select('af_aa_slot_grants','COALESCE(SUM(capacity),0) AS n',
        "uid='{$uid}' AND preset_type='".$db->escape_string($target)."' AND is_enabled=1"),'n');
    $used=(int)$db->fetch_field($db->simple_select(AF_AA_PRESETS_TABLE_NAME,'COUNT(*) AS n',
        "owner_uid='{$uid}' AND schema_version=2 AND target_key='".$db->escape_string($target)."'"),'n');
    $capacity=min($max,$free+$purchased);
    return ['system'=>1,'free'=>$free,'purchased'=>$purchased,'maximum'=>$max,'capacity'=>$capacity,
        'used'=>$used,'available'=>max(0,$capacity-$used),'total_with_system'=>1+$capacity];
}

function af_aa_personal_title(string $title): string
{
    $title=trim($title);$count=preg_match_all('/./us',$title,$chars);
    if ($title==='' || $count===false || $count>af_aa_personal_setting('personal_title_limit',80,1,191)
        || preg_match('/[\x00-\x1f<>]/u',$title)) throw new AF_AA_PersonalException('Invalid preset title.',422);
    return $title;
}

/** Strict sparse schema; stored=true uses fixed structural limits, not newly lowered ACP write quotas. */
function af_aa_personal_decode(string $json, int $uid, bool $publish, bool $checkMedia=true, bool $stored=false): array
{
    $limit=$stored?32768:af_aa_personal_setting('personal_json_limit',4096,2,32768);
    if (strlen($json)>$limit) throw new AF_AA_PersonalException('JSON limit exceeded.',422);
    try {$object=json_decode($json,false,8,JSON_THROW_ON_ERROR);}catch (JsonException $e){throw new AF_AA_PersonalException('Invalid JSON.',422);}
    if (!$object instanceof stdClass) throw new AF_AA_PersonalException('Settings must be a JSON object.',422);
    $settings=(array)$object;$media=[];
    $images=['author_bg_media_id','name_bg_media_id','plaque_bg_media_id'];
    foreach ($settings as $key=>$value) {
        if (in_array($key,$images,true)) {
            if ($value!==null && (!is_int($value) || $value<=0 || $value>4294967295)) throw new AF_AA_PersonalException('Invalid media ID.',422);
            if ($value!==null) $media[$value]=true;
        } elseif (in_array($key,['text_color','accent_color','author_tint'],true)) {
            if ($value!==null && (!is_string($value) || !preg_match('/^#[0-9a-fA-F]{6}$/D',$value))) throw new AF_AA_PersonalException('Invalid color.',422);
        } elseif ($key==='overlay_opacity') {
            if ((!is_int($value) && !is_float($value)) || $value<0 || $value>1 || !is_finite((float)$value)) throw new AF_AA_PersonalException('Invalid opacity.',422);
        } elseif ($key==='font_family') {
            if (!is_string($value) || !array_key_exists($value,af_aa_personal_fonts())) throw new AF_AA_PersonalException('Invalid font family.',422);
        } elseif ($key==='effect_preset') {
            if (!is_string($value) || !in_array($value,af_aa_personal_effects(),true)) throw new AF_AA_PersonalException('Invalid effect preset.',422);
        } else throw new AF_AA_PersonalException('Unknown setting: '.$key,422);
    }
    if (count($media)>($stored?3:af_aa_personal_setting('personal_media_limit',3,0,3))) throw new AF_AA_PersonalException('Media limit exceeded.',422);
    if ($checkMedia && $media) {
        if (!function_exists('ag_get_owned_media') || !function_exists('ag_retain_owned_media')) throw new AF_AA_PersonalException('Gallery Media API unavailable.',503);
        foreach (array_keys($media) as $id) {
            $row=ag_get_owned_media($uid,$id);
            if (!$row || $row['type']!=='local' || !in_array($row['mime'],['image/jpeg','image/png','image/gif','image/webp'],true)
                || !in_array($row['status'],$publish?['approved']:['approved','pending'],true)) throw new AF_AA_PersonalException('Media is not owned/usable.',422);
        }
        // Validate every ID first. Pin before persisting references; Gallery owns deletion races.
        if ($publish) foreach (array_keys($media) as $id) if (!ag_retain_owned_media($uid,$id)) throw new AF_AA_PersonalException('Media could not be retained.',409);
    }
    return $settings;
}

function af_aa_personal_encode(array $settings): string {return json_encode((object)$settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}

function af_aa_personal_owned_row(int $uid, int $id): array
{
    global $db;
    af_aa_personal_actor($uid);
    if ($id<=0) throw new AF_AA_PersonalException('The system preset is immutable.',422);
    $row=$db->fetch_array($db->simple_select(AF_AA_PRESETS_TABLE_NAME,'*',"id='{$id}' AND owner_uid='{$uid}' AND schema_version=2",['limit'=>1]));
    if (!$row) throw new AF_AA_PersonalException('Preset not found.',404);
    foreach (['id','owner_uid','schema_version','enabled','sortorder'] as $key) $row[$key]=(int)$row[$key];
    return $row;
}

function af_aa_personal_create(int $uid, string $target, string $title, string $json='{}', bool $draft=false): int
{
    global $db;
    af_aa_personal_actor($uid);af_aa_personal_target($target);$title=af_aa_personal_title($title);
    return af_aa_personal_transaction($uid,static function () use ($db,$uid,$target,$title,$json,$draft): int {
        if (!af_aa_personal_slots($uid,$target)['available']) throw new AF_AA_PersonalException('No free preset slots.',409);
        $settings=af_aa_personal_decode($json,$uid,!$draft);
        $data=['slug'=>$db->escape_string('user-'.$uid.'-'.bin2hex(random_bytes(12))), 'title'=>$db->escape_string($title),
            'description'=>'','preview_image'=>'','target_key'=>$db->escape_string($target),
            'settings_json'=>$db->escape_string($draft?'{}':af_aa_personal_encode($settings)), 'owner_uid'=>$uid,'schema_version'=>2,
            'publication_state'=>$draft?'draft':'ready','enabled'=>1,'sortorder'=>0,'created_at'=>TIME_NOW,'updated_at'=>TIME_NOW];
        if ($draft) $data['draft_json']=$db->escape_string(af_aa_personal_encode($settings));
        return (int)$db->insert_query(AF_AA_PRESETS_TABLE_NAME,$data);
    });
}

function af_aa_personal_save(int $uid, int $id, string $json, bool $draft=false): void
{
    global $db;
    af_aa_personal_transaction($uid,static function () use ($db,$uid,$id,$json,$draft): void {
        $row=af_aa_personal_owned_row($uid,$id);af_aa_personal_target($row['target_key']);
        $settings=af_aa_personal_decode($json,$uid,!$draft);
        $data=['updated_at'=>TIME_NOW];
        if ($draft) $data['draft_json']=$db->escape_string(af_aa_personal_encode($settings));
        else {$data['settings_json']=$db->escape_string(af_aa_personal_encode($settings));$data['publication_state']='ready';}
        $db->update_query(AF_AA_PRESETS_TABLE_NAME,$data,"id='{$id}' AND owner_uid='{$uid}'");
        if (!$draft) $db->write_query('UPDATE '.AF_AA_PRESETS_TABLE." SET draft_json=NULL WHERE id='{$id}' AND owner_uid='{$uid}'");
    });
}

function af_aa_personal_rename(int $uid, int $id, string $title): void
{
    global $db;$title=af_aa_personal_title($title);
    af_aa_personal_transaction($uid,static function () use ($db,$uid,$id,$title): void {
        $row=af_aa_personal_owned_row($uid,$id);af_aa_personal_target($row['target_key']);
        $db->update_query(AF_AA_PRESETS_TABLE_NAME,['title'=>$db->escape_string($title),'updated_at'=>TIME_NOW],"id='{$id}' AND owner_uid='{$uid}'");
    });
}

function af_aa_personal_clone(int $uid, int $id, string $title): int
{
    global $db;$title=af_aa_personal_title($title);
    return af_aa_personal_transaction($uid,static function () use ($db,$uid,$id,$title): int {
        $row=af_aa_personal_owned_row($uid,$id);af_aa_personal_target($row['target_key']);
        if (!af_aa_personal_slots($uid,$row['target_key'])['available']) throw new AF_AA_PersonalException('No free preset slots.',409);
        af_aa_personal_decode($row['settings_json'],$uid,$row['publication_state']==='ready');
        if ($row['draft_json']!==null) af_aa_personal_decode($row['draft_json'],$uid,false);
        unset($row['id']);$row['slug']=$db->escape_string('user-'.$uid.'-'.bin2hex(random_bytes(12)));$row['title']=$db->escape_string($title);
        foreach (['description','preview_image','target_key','settings_json','publication_state'] as $key) $row[$key]=$db->escape_string((string)$row[$key]);
        if ($row['draft_json']===null) unset($row['draft_json']);else $row['draft_json']=$db->escape_string($row['draft_json']);
        $row['created_at']=TIME_NOW;$row['updated_at']=TIME_NOW;
        return (int)$db->insert_query(AF_AA_PRESETS_TABLE_NAME,$row);
    });
}

function af_aa_personal_delete(int $uid, int $id): void
{
    global $db;
    af_aa_personal_transaction($uid,static function () use ($db,$uid,$id): void {
        af_aa_personal_owned_row($uid,$id);
        $db->delete_query(AF_AA_ASSIGNMENTS_TABLE_NAME,"preset_id='{$id}'");
        $db->delete_query(AF_AA_PRESETS_TABLE_NAME,"id='{$id}' AND owner_uid='{$uid}'");
    });
}

function af_aa_personal_activate(int $uid, string $target, int $id): void
{
    global $db;
    af_aa_personal_actor($uid);$meta=af_aa_personal_target($target,$id!==0);
    if (!$meta['implemented']) throw new AF_AA_PersonalException('Target is not implemented.',422);
    af_aa_personal_transaction($uid,static function () use ($db,$uid,$target,$id,$meta): void {
        $key=$db->escape_string($meta['assignment_target']);
        if ($id===0) {
            $keys=["'{$key}'"];
            if ($target==='postbit') foreach (array_keys(af_aa_get_supported_fragment_keys()) as $fragment) {
                if (str_starts_with($fragment,'postbit_')) $keys[]="'".$db->escape_string(AF_AA_TARGET_APUI_FRAGMENT_PACK.':'.$fragment)."'";
            }
            $db->delete_query(AF_AA_ASSIGNMENTS_TABLE_NAME,"entity_type='user' AND entity_id='{$uid}' AND target_key IN (".implode(',',$keys).')');
            // Do not permit stale legacy item activation to reappear after reset.
            if ($db->table_exists('af_aa_active')) $db->delete_query('af_aa_active',"entity_type='user' AND entity_id='{$uid}' AND target_key IN (".implode(',',$keys).')');
            return;
        }
        $row=af_aa_personal_owned_row($uid,$id);
        if ($row['target_key']!==$target || $row['publication_state']!=='ready' || !(int)$row['enabled']) throw new AF_AA_PersonalException('Preset is not ready for this target.',422);
        af_aa_personal_decode($row['settings_json'],$uid,true);
        $db->write_query('INSERT INTO '.AF_AA_ASSIGNMENTS_TABLE." (entity_type,entity_id,target_key,preset_id,is_enabled,created_at,updated_at)
            VALUES ('user',{$uid},'{$key}',{$id},1,".TIME_NOW.','.TIME_NOW.")
            ON DUPLICATE KEY UPDATE preset_id={$id},is_enabled=1,updated_at=".TIME_NOW);
        if ($db->table_exists('af_aa_active')) $db->delete_query('af_aa_active',"entity_type='user' AND entity_id='{$uid}' AND target_key='{$key}'");
    });
}

function af_aa_personal_active_row(int $uid): array
{
    if (!af_aa_personal_schema_available()) return [];
    $assignment=af_aa_get_active_assignment('user',$uid,AF_AA_TARGET_APUI_POSTBIT_PACK);
    $row=af_aa_get_preset_by_id((int)($assignment['preset_id']??0));
    return (int)($row['owner_uid']??0)===$uid && (int)($row['schema_version']??0)===2
        && ($row['target_key']??'')==='postbit' && ($row['publication_state']??'')==='ready' ? $row : [];
}

/** Sparse bridge for legacy APUI background consumers; no JSON values are interpreted as CSS. */
function af_aa_personal_legacy_settings(array $row): array
{
    try {$s=af_aa_personal_decode($row['settings_json'],(int)$row['owner_uid'],true,false,true);}catch(AF_AA_PersonalException $e){return [];}
    $out=[];
    foreach (['author','name','plaque'] as $role) {
        $key=$role.'_bg_media_id';
        if (array_key_exists($key,$s)) {
            $url=$s[$key]!==null && function_exists('ag_resolve_media_url')?ag_resolve_media_url($s[$key],'full'):'';
            // Missing/deleted/rejected references inherit; explicit null clears the image.
            if ($s[$key]===null || $url!=='') $out['postbit_'.$role.'_bg_url']=$url;
        }
    }
    if (isset($s['overlay_opacity'])) {
        $alpha=(string)$s['overlay_opacity'];$overlay='linear-gradient(rgba(0,0,0,'.$alpha.'),rgba(0,0,0,'.$alpha.'))';
        $out['postbit_author_overlay']=$overlay;$out['postbit_name_overlay']=$overlay;
    }
    return $out;
}

/** Approved personal presentation is scoped to existing postbit nodes; no grid/layout/identity mutation. */
function af_aa_personal_render_css(int $uid): string
{
    $row=af_aa_personal_active_row($uid);if (!$row) return '';
    try {$s=af_aa_personal_decode($row['settings_json'],$uid,true,false,true);}catch(AF_AA_PersonalException $e){return '';}
    $root='.af-aa-postbit-user-'.(int)$uid;
    $scope=static fn(string $suffix='')=>'body.atf-active .atf-post'.$root.$suffix.', '.$root.$suffix;
    $css='';$vars=[];
    foreach (af_aa_personal_legacy_settings($row) as $key=>$value) {
        $map=['postbit_author_bg_url'=>'--af-apui-postbit-author-bg-image','postbit_name_bg_url'=>'--af-apui-postbit-name-bg-image',
            'postbit_plaque_bg_url'=>'--af-apui-postbit-plaque-bg-image','postbit_author_overlay'=>'--af-apui-postbit-author-overlay','postbit_name_overlay'=>'--af-apui-postbit-name-overlay'];
        $vars[]=$map[$key].':'.(str_ends_with($key,'_url')?af_aa_css_url_value($value):$value).';';
    }
    if (!empty($s['accent_color'])) {$vars[]='--atf-post-accent:'.$s['accent_color'].';';$vars[]='--atf-post-accent-soft:color-mix(in srgb, '.$s['accent_color'].' 18%, transparent);';$vars[]='--atf-post-accent-gradient:linear-gradient(135deg, '.$s['accent_color'].', '.$s['accent_color'].');';}
    if ($vars) $css.=$scope().'{'.implode('',$vars).'}';
    $text=[];
    if (!empty($s['text_color'])) $text[]='color:'.$s['text_color'].';';
    if (isset($s['font_family']) && $s['font_family']!=='inherit') $text[]='font-family:'.af_aa_personal_fonts()[$s['font_family']].';';
    if ($text) $css.=$scope(' .atf-post__message-body').', '.$scope(' .af-apui-postbit-message').'{'.implode('',$text).'}';
    if (!empty($s['author_tint'])) $css.=$scope(' .atf-post__sidebar-inner').', '.$scope(' .af-apui-postbit-author__inner').'{background-color:'.$s['author_tint'].';}';
    if (($s['effect_preset']??'inherit')==='flat') $css.=$scope().'{box-shadow:none;}';
    if (($s['effect_preset']??'inherit')==='soft_glow') $css.=$scope().'{box-shadow:0 0 16px '.($s['accent_color']??'var(--atf-post-accent, #789abc)').';}';
    return $css;
}

function af_aa_personal_list(int $uid, string $target): array
{
    global $db;
    af_aa_personal_actor($uid);$meta=af_aa_personal_target($target,false);
    $rows=[];$q=$db->simple_select(AF_AA_PRESETS_TABLE_NAME,'*',"owner_uid='{$uid}' AND schema_version=2 AND target_key='".$db->escape_string($target)."'",['order_by'=>'id']);
    while($row=$db->fetch_array($q)) {
        foreach (['id','owner_uid','schema_version','enabled','sortorder'] as $key) $row[$key]=(int)$row[$key];
        $rows[]=$row;
    }
    $assignment=$meta['implemented']?af_aa_get_active_assignment('user',$uid,$meta['assignment_target']):[];
    $selected=af_aa_get_preset_by_id((int)($assignment['preset_id']??0));
    $selection=['kind'=>'system','id'=>0,'title'=>'По умолчанию'];
    if ($selected && (int)($selected['owner_uid']??0)===0 && $selected['target_key']===$meta['assignment_target']) {
        $selection=['kind'=>'catalog','id'=>(int)$selected['id'],'title'=>$selected['title']];
    } elseif ($selected && (int)($selected['owner_uid']??0)===$uid && ($selected['target_key']??'')===$target
        && (int)($selected['schema_version']??0)===2 && ($selected['publication_state']??'')==='ready') {
        $selection=['kind'=>'personal','id'=>(int)$selected['id'],'title'=>$selected['title']];
    }
    return ['active_selection'=>$selection,'system_preset'=>af_aa_personal_system_preset($target),'presets'=>$rows,'slots'=>af_aa_personal_slots($uid,$target),
        'assignment'=>$assignment,'target'=>$meta,'targets'=>af_aa_personal_targets(),'fonts'=>af_aa_personal_fonts(),'effects'=>af_aa_personal_effects()];
}

/** Existing apstudio alias exposes this narrow authenticated API, while its legacy admin UI stays gated. */
function af_aa_personal_api(): void
{
    global $mybb;
    header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');
    try {
        $uid=(int)($mybb->user['uid']??0);af_aa_personal_actor($uid);
        $input=$_POST+$_GET;
        if (str_contains(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')) {
            $raw=file_get_contents('php://input');
            if (strlen($raw)>65536) throw new AF_AA_PersonalException('Request too large.',413);
            try {$body=json_decode($raw,false,16,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new AF_AA_PersonalException('Invalid request JSON.',422);}
            if (!$body instanceof stdClass) throw new AF_AA_PersonalException('Request must be an object.',422);
            $input=(array)$body+$_GET;
        }
        foreach (['operation','target','title','settings_json','my_post_key'] as $field) {
            if (isset($input[$field]) && !is_string($input[$field])) throw new AF_AA_PersonalException('Invalid request field: '.$field,422);
        }
        foreach (['id','uid'] as $field) if (isset($input[$field]) && ((!is_int($input[$field]) && !is_string($input[$field]))
            || !preg_match('/^[0-9]+$/D',(string)$input[$field]) || (float)$input[$field]>4294967295)) throw new AF_AA_PersonalException('Invalid identifier.',422);
        if (isset($input['uid']) && (int)$input['uid']!==$uid) throw new AF_AA_PersonalException('forbidden',403);
        $operation=$input['operation']??'list';$target=$input['target']??'postbit';$id=(int)($input['id']??0);
        if (!in_array($operation,['list','get'],true)) {
            if (strtolower($mybb->request_method)!=='post') throw new AF_AA_PersonalException('POST required.',405);
            verify_post_check($input['my_post_key']??'',true);
        }
        if (in_array($operation,['save','draft'],true) && $id>0 && !array_key_exists('settings_json',$input)) throw new AF_AA_PersonalException('settings_json required.',422);
        $json=$input['settings_json']??'{}';$title=$input['title']??'';
        switch ($operation) {
            case 'list':$result=af_aa_personal_list($uid,$target);break;
            case 'get':$result=$id===0?af_aa_personal_system_preset($target):af_aa_personal_owned_row($uid,$id);break;
            case 'create':$result=['id'=>af_aa_personal_create($uid,$target,$title,$json)];break;
            case 'draft':
                if ($id===0) $result=['id'=>af_aa_personal_create($uid,$target,$title,$json,true)];
                else {af_aa_personal_save($uid,$id,$json,true);$result=['id'=>$id];}break;
            case 'save':af_aa_personal_save($uid,$id,$json);$result=['id'=>$id];break;
            case 'rename':af_aa_personal_rename($uid,$id,$title);$result=['id'=>$id];break;
            case 'clone':$result=['id'=>af_aa_personal_clone($uid,$id,$title)];break;
            case 'delete':af_aa_personal_delete($uid,$id);$result=['deleted'=>$id];break;
            case 'activate':af_aa_personal_activate($uid,$target,$id);$result=['active_id'=>$id];break;
            case 'reset':af_aa_personal_activate($uid,$target,0);$result=['active_id'=>0];break;
            default:throw new AF_AA_PersonalException('Unknown operation.',422);
        }
        echo json_encode(['ok'=>true,'data'=>$result],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
    } catch (AF_AA_PersonalException $e) {
        http_response_code($e->getCode()?:422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_HEX_TAG|JSON_HEX_AMP);
    } catch (Throwable $e) {
        error_log('[AdvancedAppearance] personal API failed');http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Appearance request failed.']);
    }
    exit;
}

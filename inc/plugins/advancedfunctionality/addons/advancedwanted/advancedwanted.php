<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
define('AF_WANTED_ID', 'advancedwanted');
define('AF_WANTED_ENTRIES', 'af_wanted_entries');
define('AF_WANTED_FIELDS', 'af_wanted_fields');
define('AF_WANTED_VALUES', 'af_wanted_values');
define('AF_WANTED_PAGE_ALIAS_SIGNATURE', 'AF_WANTED_PAGE_ALIAS');

function af_advancedwanted_install(): bool {
    af_wanted_ensure_schema();
    af_wanted_ensure_settings();
    return af_wanted_ensure_page_alias();
}
function af_advancedwanted_activate(): bool {
    af_wanted_ensure_schema();
    af_wanted_ensure_settings();
    return af_wanted_ensure_page_alias();
}
function af_advancedwanted_upgrade(): bool {
    return af_advancedwanted_activate();
}
function af_advancedwanted_init(): void {
    global $plugins;
    if (is_object($plugins)) {
      $plugins->add_hook('datahandler_post_insert_thread', 'af_wanted_thread_created');
      $plugins->add_hook('parse_message_end', 'af_wanted_parse_message_end', 20);
      $plugins->add_hook('newreply_start', 'af_wanted_prefill_reply');
      $plugins->add_hook('pre_output_page', 'af_wanted_ensure_chip_runtime', 20);
    }
    // AF invokes addon init from its global_start bootstrap.  Normalize here
    // rather than registering a second global_start callback too late in the
    // same hook dispatch; no separate cron is needed.
    af_wanted_normalize_expired_reservations();
}
function af_advancedwanted_uninstall(): void {
    global $db;
    $db->delete_query('settings', "name LIKE 'af_wanted_%'");
    $db->delete_query('settinggroups', "name IN ('af_wanted','af_advancedwanted')");
    af_wanted_remove_page_alias();
    if (function_exists('rebuild_settings')) rebuild_settings();
}
function af_wanted_ensure_page_alias(): bool {
    $source=__DIR__.'/assets/wanted.php';
    $target=MYBB_ROOT.'wanted.php';
    if (!is_file($source)) return false;
    if (is_file($target)) {
        $existing=(string)@file_get_contents($target);
        if (strpos($existing,AF_WANTED_PAGE_ALIAS_SIGNATURE)===false) return false;
        if (hash_file('sha256',$source)===hash_file('sha256',$target)) return true;
    }
    if (!@copy($source,$target)) return false;
    @chmod($target,0644);
    return true;
}
function af_wanted_remove_page_alias(): void {
    $target=MYBB_ROOT.'wanted.php';
    if (!is_file($target)) return;
    $existing=(string)@file_get_contents($target);
    if (strpos($existing,AF_WANTED_PAGE_ALIAS_SIGNATURE)!==false) @unlink($target);
}
function af_wanted_ensure_schema(): void {
    global $db;
    if (!is_object($db)) return;
    if (!$db->table_exists(AF_WANTED_ENTRIES)) $db->write_query("CREATE TABLE ".TABLE_PREFIX.AF_WANTED_ENTRIES." (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT, author_uid INT UNSIGNED NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'open',
      reserved_by_uid INT UNSIGNED DEFAULT NULL, reserved_at INT UNSIGNED NOT NULL DEFAULT 0, application_tid INT UNSIGNED DEFAULT NULL,
      accepted_uid INT UNSIGNED DEFAULT NULL, created_at INT UNSIGNED NOT NULL, updated_at INT UNSIGNED NOT NULL, views INT UNSIGNED NOT NULL DEFAULT 0, archived_at INT UNSIGNED NOT NULL DEFAULT 0,
      PRIMARY KEY(id), KEY status(status), KEY author_uid(author_uid), KEY application_tid(application_tid), KEY accepted_uid(accepted_uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if(!$db->field_exists('reserved_guest_name',AF_WANTED_ENTRIES))$db->add_column(AF_WANTED_ENTRIES,'reserved_guest_name',"VARCHAR(190) NOT NULL DEFAULT '' AFTER reserved_by_uid");
    if(!$db->field_exists('reserved_until',AF_WANTED_ENTRIES))$db->add_column(AF_WANTED_ENTRIES,'reserved_until','INT UNSIGNED NOT NULL DEFAULT 0 AFTER reserved_at');
    if(!$db->field_exists('views',AF_WANTED_ENTRIES))$db->add_column(AF_WANTED_ENTRIES,'views','INT UNSIGNED NOT NULL DEFAULT 0 AFTER updated_at');
    if (!$db->table_exists(AF_WANTED_FIELDS)) $db->write_query("CREATE TABLE ".TABLE_PREFIX.AF_WANTED_FIELDS." (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT, field_key VARCHAR(64) NOT NULL, title VARCHAR(190) NOT NULL, type VARCHAR(24) NOT NULL DEFAULT 'text',
      required TINYINT(1) NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1, sortorder INT NOT NULL DEFAULT 0, settings_json TEXT,
      PRIMARY KEY(id), UNIQUE KEY field_key(field_key), KEY active_sort(active,sortorder)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$db->table_exists(AF_WANTED_VALUES)) $db->write_query("CREATE TABLE ".TABLE_PREFIX.AF_WANTED_VALUES." (
      wanted_id INT UNSIGNED NOT NULL, field_id INT UNSIGNED NOT NULL, value TEXT, value_key VARCHAR(190) NOT NULL DEFAULT '',
      PRIMARY KEY(wanted_id,field_id), KEY field_value(field_id,value_key), KEY wanted_id(wanted_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Existing installs predate the explicit title flag. Resolve the actual
    // schema field by its administrator-visible label once, then persist its key.
    $hasTitle=false;foreach(af_wanted_fields(false) as $configuredField)if(!empty($configuredField['settings']['is_title'])){$hasTitle=true;break;}
    if(!$hasTitle){$candidate=$db->fetch_array($db->simple_select(AF_WANTED_FIELDS,'*',"active=1 AND title='Имя и фамилия [en]'",['limit'=>1]));if($candidate){$settings=json_decode((string)$candidate['settings_json'],true)?:[];$settings['is_title']=true;$db->update_query(AF_WANTED_FIELDS,['settings_json'=>$db->escape_string(json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))],'id='.(int)$candidate['id']);}}
}
function af_wanted_ensure_settings(): void {
    global $db;
    if (!is_object($db)) return;
    // AF opens the setting group named after the addon id (af_{addon id}).  The
    // former af_wanted group was therefore a valid MyBB group, but was not the
    // group linked from AdvancedFunctionality's addon settings screen.
    $groupName='af_advancedwanted';
    $group=$db->fetch_array($db->simple_select('settinggroups','gid',"name='".$db->escape_string($groupName)."'",['limit'=>1]));
    $gid=(int)($group['gid']??0);
    if (!$gid) $gid=(int)$db->insert_query('settinggroups',['name'=>$groupName,'title'=>'AF: AdvancedWanted','description'=>'Права и интеграция каталога Wanted.','disporder'=>35,'isdefault'=>0]);
    $defs=[
      ['af_wanted_view_groups','Группы просмотра','-1','text'],['af_wanted_create_groups','Группы создания Wanted','2','text'],
      ['af_wanted_edit_groups','Группы редактирования своих Wanted','2','text'],['af_wanted_delete_groups','Группы удаления своих Wanted','2','text'],
      ['af_wanted_reserve_groups','Группы reservation','2','text'],['af_wanted_moderate_groups','Группы модерации','4','text'],
      ['af_wanted_allow_guest_reservation','Разрешить бронь гостям','0','yesno'],
      ['af_wanted_guest_reservation_days','Срок брони гостя, дней','3','numeric'],
      ['af_wanted_user_reservation_days','Срок брони пользователя, дней','7','numeric'],
      ['af_wanted_application_forum','ID форума анкет ATF','0','numeric'],['af_wanted_discussion_tid','ID темы обсуждения Wanted','0','numeric'],
      ['af_wanted_per_page','Записей на страницу','12','numeric']];
    foreach($defs as $i=>$d){$sid=(int)$db->fetch_field($db->simple_select('settings','sid',"name='".$db->escape_string($d[0])."'"),'sid');$data=['title'=>$d[1],'description'=>$d[3]==='text'?'CSV gid; -1 = все группы.':'','optionscode'=>$d[3],'disporder'=>$i+1,'gid'=>$gid];if($sid)$db->update_query('settings',$data,'sid='.$sid);else{$data['name']=$d[0];$data['value']=$d[2];$db->insert_query('settings',$data);}}
    // Remove the now-empty, unreachable legacy group after moving its rows.
    $legacy=(int)$db->fetch_field($db->simple_select('settinggroups','gid',"name='af_wanted'",['limit'=>1]),'gid');
    if($legacy&&!(int)$db->fetch_field($db->simple_select('settings','COUNT(*) AS total','gid='.$legacy),'total'))$db->delete_query('settinggroups','gid='.$legacy);
    if(function_exists('rebuild_settings')) rebuild_settings();
}
function af_wanted_groups(string $name): bool {
    global $mybb;
    $raw=(string)($mybb->settings['af_wanted_'.$name.'_groups']??'');
    if (in_array('-1',array_map('trim',explode(',',$raw)),true)) return true;
    $mine=array_filter(array_map('intval',array_merge([(string)($mybb->user['usergroup']??0)],explode(',',(string)($mybb->user['additionalgroups']??'')))));
    return (bool)array_intersect($mine,array_map('intval',explode(',',$raw)));
}
function af_wanted_permission_name(string $action): string {
    if ($action==='apply') return 'create';
    return in_array($action,['view','create','edit','delete','reserve','moderate'],true)?$action:'';
}
function af_wanted_can(string $action, array $entry=[]): bool {
    global $mybb;
    $uid=(int)($mybb->user['uid']??0);
    if (af_wanted_groups('moderate')) return true;
    $permission=af_wanted_permission_name($action);
    if ($permission==='') return false;
    if ($permission==='view') return af_wanted_groups('view');
    if($permission==='reserve'&&!$uid)return !empty($mybb->settings['af_wanted_allow_guest_reservation']);
    if (!$uid) return false;
    if($permission==='reserve')return af_wanted_groups('reserve');
    if ($permission==='create') return af_wanted_groups('create');
    if (($permission==='edit'||$permission==='delete') && (int)($entry['author_uid']??0)===$uid) return af_wanted_groups($permission);
    return false;
}
function af_wanted_fields(bool $active=true): array {
    global $db; $out=[]; if(!is_object($db)||!$db->table_exists(AF_WANTED_FIELDS)) return [];
    $q=$db->simple_select(AF_WANTED_FIELDS,'*',$active?'active=1':'1=1',['order_by'=>'sortorder ASC, id ASC']);
    while($r=$db->fetch_array($q)){ $r['settings']=json_decode((string)$r['settings_json'],true)?:[]; $out[]=$r; } return $out;
}
/**
 * Maps Wanted source names to the real KB service and its canonical registry key.
 * Keep this explicit: public KB entry types and mechanics option sets have different APIs.
 */
function af_wanted_kb_source_map(): array {
    return [
        'origin' => ['resolver' => 'public_type', 'key' => 'arpg_origin'],
        'origin_variant' => ['resolver' => 'origin_variants'],
        'archetype' => ['resolver' => 'public_type', 'key' => 'arpg_archetype'],
        'faction' => ['resolver' => 'public_type', 'key' => 'arpg_faction'],
        'element' => ['resolver' => 'public_type', 'key' => 'arpg_element'],
        'weapon' => ['resolver' => 'mechanics', 'key' => 'weapon_type', 'service_kind' => 'weapon_type'],
        'gender' => ['resolver' => 'mechanics', 'key' => 'character_gender', 'service_kind' => 'snippet'],
    ];
}
function af_wanted_kb_rows_to_options(array $rows): array {
    $out=[];
    foreach($rows as $index=>$row) {
        if(!is_array($row)) continue;
        $key=trim((string)($row['key']??$index));
        if($key==='') continue;
        $label='';
        foreach(['label_ru','title_ru','label_en','title_en'] as $labelKey) {
            $label=trim((string)($row[$labelKey]??''));if($label!=='')break;
        }
        $out[$key]=$label!==''?$label:$key;
    }
    return $out;
}
function af_wanted_origin_variant_options(string $originKey): array {
    if($originKey===''||!function_exists('af_kb_get_origin_variants')) return [];
    $rows=[];
    foreach((array)af_kb_get_origin_variants($originKey,true) as $relation) {
        $variant=is_array($relation['variant']??null)?$relation['variant']:$relation;
        $key=trim((string)($variant['key']??$relation['variant_key']??''));
        if($key==='') continue;
        $label='';foreach(['title_ru','title_en','title','label'] as $labelKey){$label=trim((string)($variant[$labelKey]??''));if($label!=='')break;}
        $rows[$key]=$label!==''?$label:$key;
    }
    return $rows;
}
function af_wanted_options(array $field, array $input=[]): array {
    $settings=(array)($field['settings']??[]);
    if(($field['type']??'')!=='kb_dynamic') {
        $out=[]; foreach((array)($settings['options']??[]) as $key=>$label) $out[(string)$key]=(string)$label; return $out;
    }
    $source=(string)($settings['source']??'');
    $definition=af_wanted_kb_source_map()[$source]??null;
    if(!$definition) return [];
    if($definition['resolver']==='origin_variants') {
        $parentKey=(string)($settings['depends_on']??'origin');
        return af_wanted_origin_variant_options(trim((string)($input[$parentKey]??'')));
    }
    if($definition['resolver']==='public_type'&&function_exists('af_kb_get_public_type_options')) {
        return af_wanted_kb_rows_to_options((array)af_kb_get_public_type_options($definition['key']));
    }
    if($definition['resolver']==='mechanics'&&function_exists('af_kb_get_arpg_mechanics_options')) {
        return af_wanted_kb_rows_to_options((array)af_kb_get_arpg_mechanics_options($definition['key'],$definition['service_kind']));
    }
    return [];
}
function af_wanted_label(array $field,string $value,array $context=[]): string { $o=af_wanted_options($field,$context); return $o[$value]??$value; }
function af_wanted_entry(int $id): array {
 global $db;if($id<1)return [];
 af_wanted_normalize_expired_reservations($id);
 $q=$db->write_query('SELECT e.*,u.username,u.avatar author_avatar,u.avatardimensions author_avatar_dimensions,ru.username reserved_name,au.username accepted_name FROM '.TABLE_PREFIX.AF_WANTED_ENTRIES.' e LEFT JOIN '.TABLE_PREFIX.'users u ON u.uid=e.author_uid LEFT JOIN '.TABLE_PREFIX.'users ru ON ru.uid=e.reserved_by_uid LEFT JOIN '.TABLE_PREFIX.'users au ON au.uid=e.accepted_uid WHERE e.id='.$id.' LIMIT 1');
 return (array)$db->fetch_array($q);
}
/** Count at most one direct detail-page visit per browser per wanted entry in a 30-minute window. */
function af_wanted_record_view(int $id): bool {
 global $db,$mybb;
 if($id<1||!is_object($db)||strtoupper((string)($mybb->request_method??''))!=='GET'||!empty($_SERVER['HTTP_X_REQUESTED_WITH']))return false;
 $userAgent=trim((string)($_SERVER['HTTP_USER_AGENT']??''));
 if($userAgent===''||preg_match('/bot|crawler|spider|slurp|preview|headless|uptime|monitor/i',$userAgent))return false;
 $now=defined('TIME_NOW')?(int)TIME_NOW:time();$window=1800;
 $seen=json_decode((string)(function_exists('my_getcookie')?my_getcookie('af_wanted_views'):''),true);if(!is_array($seen))$seen=[];
 foreach($seen as $seenId=>$seenAt){$seenId=(string)$seenId;$seenAt=(int)$seenAt;if(!ctype_digit($seenId)||$seenAt<=$now-$window||$seenAt>$now){unset($seen[$seenId]);continue;}$seen[$seenId]=$seenAt;}
 $key=(string)$id;if(isset($seen[$key]))return false;
 $db->write_query('UPDATE '.TABLE_PREFIX.AF_WANTED_ENTRIES.' SET views=views+1 WHERE id='.$id);
 $seen[$key]=$now;arsort($seen,SORT_NUMERIC);if(count($seen)>100)$seen=array_slice($seen,0,100,true);
 if(function_exists('my_setcookie'))my_setcookie('af_wanted_views',json_encode($seen),$window,true);
 return true;
}
function af_wanted_values(array $ids): array {
 global $db; $out=[]; $ids=array_values(array_filter(array_map('intval',$ids))); if(!$ids)return $out;
 $q=$db->simple_select(AF_WANTED_VALUES,'*','wanted_id IN ('.implode(',',$ids).')'); while($r=$db->fetch_array($q))$out[(int)$r['wanted_id']][(int)$r['field_id']]=(string)$r['value']; return $out;
}
function af_wanted_validate(array $fields,array $raw): array {
 $clean=[];$errors=[];foreach($fields as $f){$k=(string)$f['field_key'];$v=$raw[$k]??'';if(is_array($v))$v=array_values(array_filter(array_map('strval',$v)));else$v=trim((string)$v);
 $settings=(array)($f['settings']??[]);
 $skipRequired=false;$invalidDependency=false;
 // Dependent KB fields are normalized against the authoritative resolver. A
 // browser-hidden stale value must never survive a save.
 if(($f['type']??'')==='kb_dynamic'&&($settings['source']??'')==='origin_variant'){
   $parent=(string)($settings['depends_on']??'origin');$available=af_wanted_origin_variant_options(trim((string)($raw[$parent]??'')));
   if(!$available){if($v!=='')$errors[]='Недопустимое сочетание «'.$f['title'].'» и выбранного происхождения.';$v='';$skipRequired=true;}elseif($v!==''&&!array_key_exists((string)$v,$available)){$errors[]='Недопустимое сочетание «'.$f['title'].'» и выбранного происхождения.';$invalidDependency=true;}
 }
 if(!$skipRequired&&!empty($f['required'])&&($v===''||$v===[]))$errors[]='Заполните поле «'.$f['title'].'».';
 if($f['type']==='number'&&$v!==''&&!is_numeric($v))$errors[]='Поле «'.$f['title'].'» должно быть числом.';
 if(in_array($f['type'],['url','image'],true)&&$v!==''&&!af_wanted_valid_http_url((string)$v))$errors[]='Некорректный HTTP(S) URL в поле «'.$f['title'].'».';
 if(!$invalidDependency&&in_array($f['type'],['select','radio','kb_dynamic'],true)&&$v!==''&&!array_key_exists((string)$v,af_wanted_options($f,$raw)))$errors[]='Недопустимое значение поля «'.$f['title'].'».';
 if($f['type']==='multi'){$allowed=af_wanted_options($f,$raw);if(!is_array($v))$errors[]='Недопустимый формат поля «'.$f['title'].'».';else foreach($v as $selected)if(!array_key_exists((string)$selected,$allowed)){$errors[]='Недопустимое значение поля «'.$f['title'].'».';break;}}
 $clean[(int)$f['id']]=is_array($v)?json_encode($v,JSON_UNESCAPED_UNICODE):(string)$v;} return [$clean,$errors];
}
function af_wanted_valid_http_url(string $value): bool { if(!filter_var($value,FILTER_VALIDATE_URL))return false;$scheme=strtolower((string)parse_url($value,PHP_URL_SCHEME));return in_array($scheme,['http','https'],true); }
function af_wanted_save_values(int $id,array $values): void { global $db; foreach($values as $fid=>$v){$db->replace_query(AF_WANTED_VALUES,['wanted_id'=>$id,'field_id'=>(int)$fid,'value'=>$db->escape_string($v),'value_key'=>$db->escape_string(substr($v,0,190))]);} }
function af_wanted_h(string $v): string { return htmlspecialchars_uni($v); }
function af_wanted_status_label(string $s): string { return ['open'=>'Свободно','reserved'=>'Придержано','application'=>'Анкета подана','archived'=>'Закрыто'][$s]??$s; }
function af_wanted_lifecycle_data(string $action): array {
 if($action==='release_reservation'||$action==='return_active')return ['status'=>'open','reserved_by_uid'=>null,'reserved_guest_name'=>'','reserved_at'=>0,'reserved_until'=>0,'application_tid'=>null,'accepted_uid'=>null,'archived_at'=>0,'updated_at'=>TIME_NOW];
 if($action==='archive')return ['status'=>'archived','reserved_by_uid'=>null,'reserved_guest_name'=>'','reserved_at'=>0,'reserved_until'=>0,'application_tid'=>null,'accepted_uid'=>null,'archived_at'=>TIME_NOW,'updated_at'=>TIME_NOW];
 return [];
}
function af_wanted_normalize_expired_reservations(int $id=0): int {
 global $db;
 if(!is_object($db)||!$db->table_exists(AF_WANTED_ENTRIES))return 0;
 $where="status='reserved' AND reserved_until>0 AND reserved_until<".TIME_NOW.' AND application_tid IS NULL';
 if($id>0)$where.=' AND id='.$id;
 $db->update_query(AF_WANTED_ENTRIES,af_wanted_lifecycle_data('release_reservation'),$where);
 return (int)$db->affected_rows();
}
function af_wanted_reservation_days(bool $guest): int {
 global $mybb;
 $key=$guest?'af_wanted_guest_reservation_days':'af_wanted_user_reservation_days';
 // The legacy value remains a compatibility fallback only; new installs get
 // explicit guest/user settings and every reservation stores its own deadline.
 $fallback=$guest?(int)($mybb->settings['af_wanted_reservation_days']??3):7;
 return max(1,min(365,(int)($mybb->settings[$key]??$fallback)));
}
function af_wanted_display_value(array $field,string $value,array $all=[]): string {
 if(($field['type']??'')==='multi'){
   $selected=json_decode($value,true);if(!is_array($selected))return $value;
   $options=af_wanted_options($field,$all);$labels=[];foreach($selected as $key)$labels[]=$options[(string)$key]??(string)$key;
   return implode(', ',$labels);
 }
 if(($field['type']??'')==='checkbox')return $value==='1'?'Да':'Нет';
 $options=af_wanted_options($field,$all);return $options[$value]??$value;
}
function af_wanted_parse_textarea(string $value): string {
 if($value==='')return '';
 if(!class_exists('postParser')&&defined('MYBB_ROOT')){$parserFile=MYBB_ROOT.'inc/class_parser.php';if(is_file($parserFile))require_once $parserFile;}
 if(!class_exists('postParser'))return nl2br(af_wanted_h($value));
 $parser=new postParser();
 return $parser->parse_message($value,[
  'allow_html'=>0,'allow_mycode'=>1,'allow_basicmycode'=>1,'allow_smilies'=>0,
  'allow_imgcode'=>1,'allow_videocode'=>0,'allow_list'=>1,'allow_alignmycode'=>1,
  'allow_font'=>1,'allow_color'=>1,'allow_size'=>1,'filter_badwords'=>0,'nl2br'=>1,
 ]);
}
/**
 * Render a stored field for public display.  Unlike af_wanted_display_value(),
 * this is an HTML boundary: every scalar is escaped here and only known field
 * types are allowed to introduce markup.
 */
function af_wanted_render_field_value(array $field,string $value,array $all=[]): string {
 $type=(string)($field['type']??'text');
 if($type==='image') {
   return af_wanted_valid_http_url($value)
     ? '<img class="af-wanted-field-image" src="'.af_wanted_h($value).'" alt="" loading="lazy">'
     : '';
 }
 if($type==='textarea')return af_wanted_parse_textarea($value);
 $display=af_wanted_display_value($field,$value,$all);
 if($type==='url'&&af_wanted_valid_http_url($display)) {
   return '<a href="'.af_wanted_h($display).'" rel="noopener noreferrer">'.af_wanted_h($display).'</a>';
 }
 return af_wanted_h($display);
}
function af_wanted_entry_title(int $id,array $fields,array $values): string {
 foreach($fields as $field) {
   if(empty($field['active'])||empty($field['settings']['is_title']))continue;
   $value=trim((string)($values[(int)$field['id']]??''));if($value!=='')return $value;
 }
 return 'Персонаж без имени';
}
function af_wanted_primary_image(array $fields,array $values): string {
 foreach($fields as $field) {
   if(($field['type']??'')!=='image')continue;
   $value=(string)($values[(int)$field['id']]??'');if(af_wanted_valid_http_url($value))return $value;
 }
 return '';
}
/** Load ATF's public schema API in ACP, where only the current addon's bootstrap is loaded. */
function af_wanted_load_atf_schema_api(): bool {
 if(function_exists('af_atf_get_fields_for_forum'))return true;
 $path=function_exists('af_get_addon_bootstrap_path')?af_get_addon_bootstrap_path('advancedthreadfields'):null;
 if((!is_string($path)||$path==='')&&defined('AF_ADDONS'))$path=rtrim(AF_ADDONS,'/\\').'/advancedthreadfields/advancedthreadfields.php';
 if(is_string($path)&&$path!==''&&is_file($path))require_once $path;
 return function_exists('af_atf_get_fields_for_forum');
}
/** Return active ATF fields applicable to the configured application forum. */
function af_wanted_atf_fields(): array {
 $fid=(int)($GLOBALS['mybb']->settings['af_wanted_application_forum']??0);
 af_wanted_load_atf_schema_api();
 if(function_exists('af_atf_get_fields_for_forum')&&$fid>0)return af_atf_get_fields_for_forum($fid);
 if(function_exists('af_atf_get_fields_cached')){
  $out=[];foreach(af_atf_get_fields_cached() as $field)if(!empty($field['active'])&&!empty($field['group_active'])&&($fid<1||!function_exists('af_atf_forum_allowed')||af_atf_forum_allowed($field,$fid)))$out[]=$field;return $out;
 }
 return [];
}
/** Describe whether the configured application forum has a usable live ATF schema. */
function af_wanted_atf_schema_status(): array {
 global $db,$mybb;
 $fid=(int)($mybb->settings['af_wanted_application_forum']??0);
 if($fid<1)return ['code'=>'forum_required','fid'=>0,'fields'=>[]];
 $forum=[];
 if(function_exists('get_forum'))$forum=(array)get_forum($fid);
 elseif(is_object($db)&&method_exists($db,'table_exists')&&$db->table_exists('forums'))$forum=(array)$db->fetch_array($db->simple_select('forums','fid','fid='.$fid,['limit'=>1]));
 if(empty($forum['fid']))return ['code'=>'forum_required','fid'=>$fid,'fields'=>[]];
 $fields=af_wanted_atf_fields();
 return ['code'=>$fields?'ok':'fields_missing','fid'=>$fid,'fields'=>$fields];
}
function af_wanted_mapping_types_compatible(string $wantedType,string $atfType): bool {
 $matrix=[
  'text'=>['text','url','number'],
  'url'=>['text','url'],'image'=>['text','url'],
  'number'=>['number','text'],'select'=>['select','kb_dynamic'],
  'radio'=>['radio','select'],
  'kb_dynamic'=>['kb_dynamic','select'],
  'multi'=>['multi'],'checkbox'=>['checkbox'],
  'textarea'=>['textarea'],
 ];
 $wantedType=strtolower(trim($wantedType));$atfType=strtolower(trim($atfType));
 // An unknown/internal ATF type must remain selectable: only reject pairs for
 // which both sides have a known, unambiguous storage contract.
 $knownAtf=['text','textarea','url','image','number','select','multi','checkbox','radio','kb_dynamic'];
 if(!isset($matrix[$wantedType])||!in_array($atfType,$knownAtf,true))return true;
 return in_array($atfType,$matrix[$wantedType],true);
}
/** Build editable ATF defaults solely from administrator-configured mappings. */
function af_wanted_build_atf_prefill(int $id): array {
 $fields=af_wanted_fields();$stored=af_wanted_values([$id])[$id]??[];$targets=[];
 foreach(af_wanted_atf_fields() as $target)$targets[(string)($target['name']??'')]=$target;
 $values=[];$originValues=[];$variantMappings=[];
 foreach($fields as $field){
  $targetKey=trim((string)($field['settings']['atf_field_key']??''));if($targetKey===''||!isset($targets[$targetKey]))continue;
  $target=$targets[$targetKey];if(!af_wanted_mapping_types_compatible((string)$field['type'],(string)$target['type']))continue;
  $raw=$stored[(int)$field['id']]??'';$value=is_array($raw)?$raw:trim((string)$raw);if($value===''||$value===[])continue;
  if($field['type']==='checkbox')$value=in_array((string)$value,['1','true','yes','on'],true)?'1':'0';
  if($field['type']==='multi'&&is_array($value))$value=json_encode(array_values($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $values[$targetKey]=$value;
  $source=(string)($field['settings']['source']??'');if($source==='origin')$originValues[(string)$field['field_key']]=(string)$raw;if($source==='origin_variant')$variantMappings[]=[$targetKey,(string)$raw,(string)($field['settings']['depends_on']??'')];
 }
 // A variant is meaningful only while the authoritative KB relation accepts it
 // for the selected origin. Never copy a stale dependent value into ATF.
 foreach($variantMappings as [$targetKey,$variant,$parentKey]){$origin=(string)($originValues[$parentKey]??'');unset($values[$targetKey]);if($origin!==''&&array_key_exists($variant,af_wanted_origin_variant_options($origin)))$values[$targetKey]=$variant;}
 return ['source'=>'wanted','wanted_id'=>$id,'mechanic'=>'arpg','values'=>$values];
}
function af_wanted_store_atf_prefill(int $id,int $uid,int $fid): string {
 global $cache;$token=bin2hex(random_bytes(16));$payload=af_wanted_build_atf_prefill($id);
 $payload['uid']=$uid;$payload['forum_id']=$fid;$payload['created_at']=TIME_NOW;$payload['expires_at']=TIME_NOW+1800;
 $stored=function_exists('af_atf_prefill_store_save')&&af_atf_prefill_store_save($token,$payload);
 if(!$stored&&is_object($cache)&&method_exists($cache,'update')){$cache->update('af_atf_prefill_'.$token,$payload);$stored=true;}
 return $stored?$token:'';
}
function af_wanted_field_control(array $f,$value,array $all=[]): string {
 $k=af_wanted_h((string)$f['field_key']);$v=is_array($value)?$value:(string)$value;$req=!empty($f['required'])?' required':'';$type=(string)$f['type'];
 if($type==='textarea')return '<textarea class="af-atf-bbcode-editor af-wanted-richtext" name="fields['.$k.']" rows="9"'.$req.'>'.af_wanted_h((string)$v).'</textarea>';
 if(in_array($type,['select','radio','kb_dynamic'],true)){ $opts=af_wanted_options($f,$all); if($type==='radio'){ $h='';foreach($opts as $ok=>$ol)$h.='<label><input type="radio" name="fields['.$k.']" value="'.af_wanted_h($ok).'"'.((string)$v===$ok?' checked':'').$req.'> '.af_wanted_h($ol).'</label> ';return $h;} $h='<select class="af-wanted-select" name="fields['.$k.']"'.$req.'><option value=""></option>';foreach($opts as $ok=>$ol)$h.='<option value="'.af_wanted_h($ok).'"'.((string)$v===$ok?' selected':'').'>'.af_wanted_h($ol).'</option>';return $h.'</select>'; }
 if($type==='multi'){ $vals=is_array($v)?$v:(json_decode((string)$v,true)?:[]);$h='<select class="af-wanted-select" multiple name="fields['.$k.'][]"'.$req.'>';foreach(af_wanted_options($f,$all) as $ok=>$ol)$h.='<option value="'.af_wanted_h($ok).'"'.(in_array($ok,$vals,true)?' selected':'').'>'.af_wanted_h($ol).'</option>';return $h.'</select>'; }
 if($type==='checkbox')return '<input type="checkbox" name="fields['.$k.']" value="1"'.((string)$v==='1'?' checked':'').'>';
 $htmlType=in_array($type,['url','image'],true)?'url':($type==='number'?'number':'text'); return '<input class="af-wanted-input" type="'.$htmlType.'" name="fields['.$k.']" value="'.af_wanted_h((string)$v).'"'.$req.($type==='number'?' step="any"':'').($type==='image'?' placeholder="https://example.com/image.jpg"':'').'>';
}
function af_wanted_dependency_data(array $fields): array {
 $data=[];
 foreach($fields as $field){
  $settings=(array)($field['settings']??[]);
  if(($field['type']??'')!=='kb_dynamic'||($settings['source']??'')!=='origin_variant')continue;
  $parent=(string)($settings['depends_on']??'');if($parent==='')continue;
  $parentField=null;foreach($fields as $candidate)if((string)$candidate['field_key']===$parent){$parentField=$candidate;break;}
  if(!$parentField)continue;
  $variants=[];foreach(af_wanted_options($parentField) as $originKey=>$unused)$variants[$originKey]=af_wanted_origin_variant_options($originKey);
  $data[]=['field'=>(string)$field['field_key'],'depends_on'=>$parent,'options'=>$variants];
 }
 return $data;
}
function af_wanted_dependency_script(array $fields): string {
 if(!af_wanted_frontend_asset_allowed('dependency'))return '';
 $data=af_wanted_dependency_data($fields);if(!$data)return '';
 $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
 return '<script>(function(){var dependencies='.$json.';dependencies.forEach(function(config){var parent=document.querySelector("[name=\\"fields["+config.depends_on+"]\\"]");var child=document.querySelector("[name=\\"fields["+config.field+"]\\"]");if(!parent||!child)return;var cell=child.closest(".af-wanted-field");function update(initial){var rows=config.options[parent.value]||{},saved=initial?child.value:"";if(!initial)child.value="";while(child.options.length)child.remove(0);child.add(new Option("",""));Object.keys(rows).forEach(function(key){child.add(new Option(rows[key],key));});child.value=Object.prototype.hasOwnProperty.call(rows,saved)?saved:"";child.disabled=Object.keys(rows).length===0;if(cell){cell.hidden=child.disabled;cell.classList.toggle("is-hidden",child.disabled);}}parent.addEventListener("change",function(){update(false);});update(true);});})();</script>';
}
function af_wanted_render_page(): void {
 global $mybb,$db,$headerinclude,$header,$footer,$theme;
 if(!af_wanted_can('view')) error_no_permission();
 $action=$mybb->get_input('action');$id=(int)$mybb->get_input('id');$entry=$id?af_wanted_entry($id):[];
 if($action==='modal'&&$mybb->get_input('ajax')==='1'){if(!$entry)error('Wanted не найден.');header('Content-Type: application/json; charset='.$mybb->settings['charset']);echo json_encode(['entry'=>af_wanted_modal_payload($entry)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
 if($action==='discuss'){
   $tid=(int)($mybb->settings['af_wanted_discussion_tid']??0);if(!$entry||$tid<1)error('Тема обсуждения не настроена.');
   $thread=(array)$db->fetch_array($db->simple_select('threads','tid,fid,visible','tid='.$tid,['limit'=>1]));$permissions=$thread?forum_permissions((int)$thread['fid']):[];
   if(!$thread||($thread['visible']??0)!=1||empty($permissions['canview'])||empty($permissions['canviewthreads'])||empty($permissions['canpostreplys']))error_no_permission();
   my_setcookie('af_wanted_discuss',af_wanted_discussion_intent_encode($id,(int)($mybb->user['uid']??0),$tid),900,true);redirect('newreply.php?tid='.$tid,'Контекст Wanted подготовлен.');
 }
 if($mybb->request_method==='post'){ verify_post_check($mybb->get_input('my_post_key'));
   if($action==='save'){ $editing=!empty($entry);if(!af_wanted_can($editing?'edit':'create',$entry)||($editing&&$entry['status']==='application'&&!af_wanted_groups('moderate')))error_no_permission(); [$vals,$errs]=af_wanted_validate(af_wanted_fields(),(array)($mybb->input['fields']??[]));if($errs)error(implode('<br>',$errs));if(!$editing){$id=(int)$db->insert_query(AF_WANTED_ENTRIES,['author_uid'=>(int)$mybb->user['uid'],'status'=>'open','created_at'=>TIME_NOW,'updated_at'=>TIME_NOW]);}else{$db->update_query(AF_WANTED_ENTRIES,['updated_at'=>TIME_NOW],'id='.$id);}af_wanted_save_values($id,$vals);redirect('wanted.php?action=view&id='.$id,'Запись сохранена.'); }
   if($action==='reserve'&&af_wanted_can('reserve',$entry)){ $uid=(int)$mybb->user['uid'];$guest='';if(!$uid){$guest=trim((string)$mybb->get_input('guest_name'));if($guest==='')error('Укажите ваше имя.');if(my_strlen($guest)>190)error('Имя слишком длинное.');}$until=TIME_NOW+af_wanted_reservation_days($uid===0)*86400;$db->update_query(AF_WANTED_ENTRIES,['status'=>'reserved','reserved_by_uid'=>$uid?:null,'reserved_guest_name'=>$guest,'reserved_at'=>TIME_NOW,'reserved_until'=>$until,'updated_at'=>TIME_NOW],"id=$id AND status='open'");if((int)$db->affected_rows()!==1)error('Эта запись уже недоступна для бронирования.');redirect('wanted.php?action=view&id='.$id,'Персонаж придержан.'); }
   if($action==='release'){if(!$entry||$entry['status']!=='reserved'||(int)$entry['reserved_by_uid']!==(int)$mybb->user['uid'])error_no_permission();$db->update_query(AF_WANTED_ENTRIES,af_wanted_lifecycle_data('release_reservation'),'id='.$id." AND status='reserved' AND reserved_by_uid=".(int)$mybb->user['uid']);if((int)$db->affected_rows()!==1)error('Бронь уже изменена.');redirect('wanted.php?action=view&id='.$id,'Бронь снята.'); }
   if($action==='moderate_reservation'){
     if(!$entry||!af_wanted_groups('moderate'))error_no_permission();$mode=(string)$mybb->get_input('reservation_action');
     if($mode==='release'){
       if(!empty($entry['application_tid'])||$entry['status']==='application')error('Нельзя снять бронь при активной анкете.');
       $db->update_query(AF_WANTED_ENTRIES,af_wanted_lifecycle_data('release_reservation'),'id='.$id);redirect('wanted.php?action=view&id='.$id,'Бронь снята.');
     }
     if($mode!=='save'||!empty($entry['application_tid'])||$entry['status']==='application')error('Бронь нельзя изменить.');
     $ownerType=(string)$mybb->get_input('reservation_owner_type');$ownerUid=0;$guest='';
     if($ownerType==='user'){$ownerUid=(int)$mybb->get_input('reservation_uid');$user=$ownerUid>0?(array)$db->fetch_array($db->simple_select('users','uid','uid='.$ownerUid,['limit'=>1])):[];if(empty($user['uid']))error('Пользователь не найден.');}
     elseif($ownerType==='guest'){$guest=trim((string)$mybb->get_input('reservation_guest_name'));if($guest===''||my_strlen($guest)>190)error('Укажите корректное имя гостя.');}
     else error('Выберите тип владельца брони.');
     $date=trim((string)$mybb->get_input('reserved_until'));$dateObject=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
     if(!$dateObject||$dateObject->format('Y-m-d')!==$date)error('Укажите корректную дату окончания брони.');
     $until=$dateObject->getTimestamp()+86399;if($until<TIME_NOW)error('Дата окончания брони уже прошла.');
     $db->update_query(AF_WANTED_ENTRIES,['status'=>'reserved','reserved_by_uid'=>$ownerUid?:null,'reserved_guest_name'=>$guest,'reserved_at'=>(int)($entry['reserved_at']?:TIME_NOW),'reserved_until'=>$until,'updated_at'=>TIME_NOW],'id='.$id);
     redirect('wanted.php?action=view&id='.$id,'Бронь обновлена.');
   }
   if($action==='delete'&&af_wanted_can('delete',$entry)){ $db->delete_query(AF_WANTED_VALUES,'wanted_id='.$id);$db->delete_query(AF_WANTED_ENTRIES,'id='.$id);redirect('wanted.php','Запись удалена.'); }
   if($action==='apply'&&af_wanted_can('apply',$entry)){
     if(!in_array($entry['status']??'', ['open','reserved'],true)||(($entry['status']??'')==='reserved'&&(int)$entry['reserved_by_uid']>0&&(int)$entry['reserved_by_uid']!==(int)$mybb->user['uid']&&!af_wanted_groups('moderate')))error('Запись недоступна для подачи анкеты.');
     $fid=(int)($mybb->settings['af_wanted_application_forum']??0);
     if($fid<1)error('Форум для подачи анкет Wanted не настроен.');
     $intent=af_wanted_intent_encode($id,(int)$mybb->user['uid'],$fid);
     my_setcookie('af_wanted_apply',$intent,1800,true);
     $prefillToken=af_wanted_store_atf_prefill($id,(int)$mybb->user['uid'],$fid);
     redirect('newthread.php?fid='.$fid.($prefillToken!==''?'&af_atf_prefill_token='.rawurlencode($prefillToken):''),'Создайте тему анкеты. Связь с Wanted будет добавлена автоматически.');
   }
 }
 if($action==='view'&&!empty($entry)&&af_wanted_record_view($id))$entry['views']=(int)($entry['views']??0)+1;
 if($action===''&&$mybb->get_input('ajax')==='1'){
   header('Content-Type: text/html; charset='.$mybb->settings['charset']);
   echo af_wanted_catalog();
   exit;
 }
 $title='Нужные персонажи';add_breadcrumb($title,'wanted.php');
 $createAction=$action===''&&af_wanted_can('create')?'<a class="af-wanted-button af-wanted-create" href="wanted.php?action=create"><i class="fa-solid fa-plus" aria-hidden="true"></i><span>Создать нужного персонажа</span></a>':'';
 $pageHead=$action===''?'<header class="af-wanted-page-head"><div class="af-kb-header"><h1>'.$title.'</h1><p class="af-wanted-page-description">Ищите персонажей для своих историй, знакомьтесь с действующими заявками и выбирайте удобный способ отклика.</p></div>'.$createAction.'</header>':'';
 $body='<div class="pun atf-page-shell"><main class="atf-ui af-wanted'.($action==='view'?' af-wanted--detail-page':' atf-page-surface').'">'.$pageHead;
 if($action==='create'||$action==='edit'){if(!af_wanted_can($action==='create'?'create':'edit',$entry))error_no_permission();$fields=af_wanted_fields();$vals=$entry?af_wanted_values([$id])[$id]??[]:[];$byKey=[];foreach($fields as $f)$byKey[$f['field_key']]=$vals[(int)$f['id']]??'';$body.='<section class="af-wanted-panel"><h2>'.($id?'Изменить Wanted #'.$id:'Новая заявка Wanted').'</h2><form class="af-wanted-form" method="post" action="wanted.php?action=save'.($id?'&id='.$id:'').'"><input type="hidden" name="my_post_key" value="'.af_wanted_h((string)$mybb->post_code).'">';foreach($fields as $f){$settings=(array)($f['settings']??[]);$dependent=($f['type']==='kb_dynamic'&&($settings['source']??'')==='origin_variant');$available=$dependent?af_wanted_options($f,$byKey):[];$hidden=$dependent&&!$available;$body.='<div class="af-wanted-field af-wanted-field--'.af_wanted_h((string)$f['type']).($hidden?' is-hidden':'').'"'.($hidden?' hidden':'').'><label>'.af_wanted_h($f['title']).(!empty($f['required'])?' *':'').'</label>'.af_wanted_field_control($f,$vals[(int)$f['id']]??'',$byKey).'</div>';}$body.='<div class="af-wanted-form-actions"><button class="af-wanted-button" type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i><span>Сохранить</span></button><a class="af-wanted-button" href="'.($id?'wanted.php?action=view&amp;id='.$id:'wanted.php').'"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i><span>Отмена</span></a></div></form></section>'.af_wanted_dependency_script($fields);
 } elseif($action==='view'){if(!$entry)error('Wanted не найден.');$body.=af_wanted_render_detail($entry,true);
 } else {$body.=af_wanted_catalog();if(af_wanted_frontend_asset_allowed('catalog'))$body.='<script defer src="inc/plugins/advancedfunctionality/addons/advancedwanted/assets/advancedwanted_catalog.js"></script>';}
 $body.='</main></div>';
 if (function_exists('af_front_output_template_string')) {
     af_front_output_template_string($title,'{$wanted_body}',['wanted_body'=>$body]);
 }
 output_page('<!DOCTYPE html><html><head><title>'.af_wanted_h($title).'</title>'.$headerinclude.'</head><body>'.$header.$body.$footer.'</body></html>');
}
function af_wanted_actions(array $e,bool $detail=false,string $segment='all'): string {
 global $mybb;
 $id=(int)$e['id'];$uid=(int)($mybb->user['uid']??0);
 $ownsReservation=$e['status']==='reserved'&&$uid>0&&(int)$e['reserved_by_uid']===$uid;
 $lifecycle='';$primaryLifecycle='';$reserveLifecycle='';$discussionLifecycle='';$owner='';
 $post='<input type="hidden" name="my_post_key" value="'.af_wanted_h((string)$mybb->post_code).'">';
 if($e['status']==='open'&&af_wanted_can('reserve',$e)){$guest=$uid?'':'<label class="af-wanted-guest-name">Ваше имя <input class="af-wanted-input" name="guest_name" required maxlength="190"></label>';$reserveLifecycle.='<form class="af-wanted-reserve-form'.($uid?'':' af-wanted-reserve-form--guest').'" method="post" action="wanted.php?action=reserve&id='.$id.'">'.$post.$guest.'<button class="af-wanted-button" type="submit"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i><span>Придержать</span></button></form>';}
 if($ownsReservation)$reserveLifecycle.='<form class="af-wanted-release-form" method="post" action="wanted.php?action=release&id='.$id.'">'.$post.'<button class="af-wanted-button" type="submit"><i class="fa-solid fa-bookmark" aria-hidden="true"></i><span>Отказаться / Снять бронь</span></button></form>';
 $guestReservation=$e['status']==='reserved'&&(int)($e['reserved_by_uid']??0)===0&&trim((string)($e['reserved_guest_name']??''))!=='';
 if(($e['status']==='open'||$ownsReservation||$guestReservation||af_wanted_groups('moderate'))&&af_wanted_can('apply',$e))$primaryLifecycle.='<form class="af-wanted-apply-form" method="post" action="wanted.php?action=apply&id='.$id.'">'.$post.'<button class="af-wanted-button" type="submit"><i class="fa-solid fa-feather-pointed" aria-hidden="true"></i><span>Подать анкету</span></button></form>';
 if($e['status']==='application'&&!empty($e['application_tid']))$primaryLifecycle.='<a class="af-wanted-button af-wanted-application-link" href="showthread.php?tid='.(int)$e['application_tid'].'"><i class="fa-solid fa-file-lines" aria-hidden="true"></i><span>Открыть анкету</span></a>';
 if($e['status']==='archived'&&!empty($e['application_tid']))$primaryLifecycle.='<a class="af-wanted-button af-wanted-application-link" href="showthread.php?tid='.(int)$e['application_tid'].'"><i class="fa-solid fa-file-lines" aria-hidden="true"></i><span>Принятая анкета</span></a>';
 if(af_wanted_discussion_allowed())$discussionLifecycle.='<a class="af-wanted-button af-wanted-discuss" href="wanted.php?action=discuss&amp;id='.$id.'"><i class="fa-regular fa-comments" aria-hidden="true"></i><span>Обсудить</span></a>';
 $lifecycle=$reserveLifecycle.$primaryLifecycle.$discussionLifecycle;
 if(af_wanted_can('edit',$e)&&$e['status']!=='application')$owner.='<a class="af-wanted-button af-wanted-detail-menu__item" role="menuitem" href="wanted.php?action=edit&id='.$id.'"><i class="fa-solid fa-pen" aria-hidden="true"></i><span>Изменить</span></a>';
 if(af_wanted_can('delete',$e))$owner.='<form class="af-wanted-detail-menu__delete" role="presentation" method="post" action="wanted.php?action=delete&id='.$id.'" data-af-wanted-confirm="Удалить эту заявку?"><input type="hidden" name="my_post_key" value="'.af_wanted_h((string)$mybb->post_code).'"><button type="submit" class="af-wanted-button af-wanted-detail-menu__item" role="menuitem"><i class="fa-solid fa-trash" aria-hidden="true"></i><span>Удалить</span></button></form>';
 $moderation='';if($detail&&af_wanted_groups('moderate')&&empty($e['application_tid'])&&$e['status']!=='application'){
  $isUser=(int)($e['reserved_by_uid']??0)>0;$date=!empty($e['reserved_until'])?my_date('Y-m-d',(int)$e['reserved_until']):my_date('Y-m-d',TIME_NOW+86400);
  $moderation='<form class="af-wanted-reservation-admin__form" method="post" action="wanted.php?action=moderate_reservation&id='.$id.'">'.$post.'<input type="hidden" name="reservation_action" value="save"><label><span>Тип брони</span><select class="af-wanted-select" name="reservation_owner_type"><option value="user"'.($isUser?' selected':'').'>Пользователь</option><option value="guest"'.(!$isUser?' selected':'').'>Гость</option></select></label><label><span>UID</span><input class="af-wanted-input" type="number" min="1" name="reservation_uid" value="'.($isUser?(int)$e['reserved_by_uid']:'').'"></label><label><span>Имя гостя</span><input class="af-wanted-input" maxlength="190" name="reservation_guest_name" value="'.af_wanted_h((string)($e['reserved_guest_name']??'')).'"></label><label><span>Срок брони</span><input class="af-wanted-input" type="date" name="reserved_until" value="'.af_wanted_h($date).'" required></label><button class="af-wanted-button" type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i><span>Сохранить</span></button></form>'.($e['status']==='reserved'?'<form class="af-wanted-reservation-admin__release" method="post" action="wanted.php?action=moderate_reservation&id='.$id.'">'.$post.'<input type="hidden" name="reservation_action" value="release"><button class="af-wanted-button" type="submit"><i class="fa-solid fa-unlock" aria-hidden="true"></i><span>Снять бронь</span></button></form>':'');
 }
 $menu=$owner!==''?'<div class="af-wanted-detail-menu atf-dropdown"><button class="af-wanted-detail-menu__toggle atf-icon-button atf-dropdown__toggle" id="af-wanted-detail-menu-toggle-'.$id.'" type="button" aria-label="Действия с заявкой" aria-haspopup="menu" aria-expanded="false" aria-controls="af-wanted-detail-menu-'.$id.'"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button><div class="af-wanted-detail-menu__panel atf-dropdown__panel" id="af-wanted-detail-menu-'.$id.'" role="menu" aria-labelledby="af-wanted-detail-menu-toggle-'.$id.'" hidden>'.$owner.'</div></div>':'';
 $reservationPanel=$moderation!==''?'<details class="af-wanted-reservation-admin"><summary><i class="fa-solid fa-gear" aria-hidden="true"></i><span>Управление бронью</span><i class="fa-solid fa-chevron-down af-wanted-reservation-admin__chevron" aria-hidden="true"></i></summary><div class="af-wanted-reservation-admin__body">'.$moderation.'</div></details>':'';
 if($segment==='menu')return $menu;
 if($segment==='lifecycle'){if($lifecycle==='')return '';return '<div class="af-wanted-actions af-wanted-actions--lifecycle">'.($primaryLifecycle!==''?'<div class="af-wanted-actions__primary">'.$primaryLifecycle.'</div>':'').(($reserveLifecycle!==''||$discussionLifecycle!=='')?'<div class="af-wanted-actions__secondary">'.$reserveLifecycle.$discussionLifecycle.'</div>':'').'</div>';}
 if($segment==='management')return $reservationPanel;
 return '<div class="af-wanted-actions"><div class="af-wanted-actions__lifecycle">'.$lifecycle.'</div><div class="af-wanted-actions__owner">'.$owner.'</div></div>'.$reservationPanel;
}
function af_wanted_discussion_allowed(): bool {
 global $db,$mybb;$tid=(int)($mybb->settings['af_wanted_discussion_tid']??0);if($tid<1)return false;
 $thread=(array)$db->fetch_array($db->simple_select('threads','fid,visible','tid='.$tid,['limit'=>1]));if(!$thread||($thread['visible']??0)!=1)return false;$p=forum_permissions((int)$thread['fid']);return !empty($p['canview'])&&!empty($p['canviewthreads'])&&!empty($p['canpostreplys']);
}
function af_wanted_detail_field_icon(string $key): string {
 $icons=[
  'name'=>'fa-user','name_en'=>'fa-user','name_ru'=>'fa-user','prototype'=>'fa-id-card','faceclaim'=>'fa-id-card','full_name'=>'fa-user','fullname'=>'fa-user','character_name'=>'fa-user','name_and_surname'=>'fa-user',
  'origin'=>'fa-dna','origin_type'=>'fa-dna','origin_variant'=>'fa-layer-group','subtype'=>'fa-layer-group','race'=>'fa-dna','ancestry'=>'fa-dna',
  'archetype'=>'fa-shield-halved','class'=>'fa-shield-halved','faction'=>'fa-users','group'=>'fa-users',
  'activity'=>'fa-briefcase','occupation'=>'fa-briefcase','profession'=>'fa-briefcase','author'=>'fa-user-pen',
 ];
 return $icons[strtolower(trim($key))]??'fa-tag';
}
function af_wanted_reservation_html(array $entry): string {
 if(($entry['status']??'')!=='reserved')return '';$uid=(int)($entry['reserved_by_uid']??0);$name=$uid?(string)($entry['reserved_name']??''):(string)($entry['reserved_guest_name']??'');$owner='<span class="af-wanted-reservation-owner">'.($uid?build_profile_link(af_wanted_h($name),$uid):af_wanted_h($name)).'</span>';$until=(int)($entry['reserved_until']??0);
 return '<div class="af-wanted-detail-field af-wanted-reservation"><i class="fa-solid fa-bookmark" aria-hidden="true"></i><dt>Бронь</dt><dd>Придержано до: '.($until?af_wanted_h(my_date('d.m.y',$until)):'—').' за&nbsp;'.$owner.'</dd></div>';
}
function af_wanted_status_chip(array $entry): string {
 $status=(string)($entry['status']??'');$modifier=in_array($status,['open','reserved','application','archived'],true)?' af-wanted-status--'.$status:'';
 if($status!=='reserved')return '<span class="af-wanted-status'.$modifier.'">'.af_wanted_h(af_wanted_status_label($status)).'</span>';
 $uid=(int)($entry['reserved_by_uid']??0);$name=$uid?(string)($entry['reserved_name']??''):(string)($entry['reserved_guest_name']??'');$owner=$uid?build_profile_link(af_wanted_h($name),$uid):af_wanted_h($name);$until=(int)($entry['reserved_until']??0);
 return '<span class="af-wanted-status'.$modifier.' af-wanted-status--reservation">Придержано до: '.($until?af_wanted_h(my_date('d.m.y',$until)):'—').' за&nbsp;'.$owner.'</span>';
}
function af_wanted_catalog(): string {
 global $db,$mybb;
 af_wanted_normalize_expired_reservations();
 $tab=$mybb->get_input('tab')==='archive'?'archive':'active';$where=[$tab==='archive'?"e.status='archived'":"e.status IN ('open','reserved','application')"];$params=['tab'=>$tab];
 $status=$mybb->get_input('status');if($tab==='active'&&in_array($status,['open','reserved','application'],true)){$where[]="e.status='".$db->escape_string($status)."'";$params['status']=$status;}
 $fields=af_wanted_fields();$filters=[];foreach($fields as $f){if(empty($f['settings']['filterable'])||!in_array($f['type'],['select','kb_dynamic','radio','checkbox','number','text'],true))continue;$key=(string)$f['field_key'];$value=trim((string)$mybb->get_input($key));if($value!==''&&in_array($f['type'],['select','kb_dynamic','radio'],true)&&!array_key_exists($value,af_wanted_options($f,$mybb->input)))$value='';if($value!==''){$alias='vf'.(int)$f['id'];$where[]="EXISTS (SELECT 1 FROM ".TABLE_PREFIX.AF_WANTED_VALUES." $alias WHERE $alias.wanted_id=e.id AND $alias.field_id=".(int)$f['id']." AND $alias.value_key='".$db->escape_string($value)."')";$params[$key]=$value;}$filters[]=[$f,$value];}
 $search=trim((string)$mybb->get_input('search'));if($search!==''){$searchFieldIds=[];foreach($fields as $field)if(!empty($field['settings']['is_title'])||$field['type']==='textarea')$searchFieldIds[]=(int)$field['id'];if($searchFieldIds){$where[]="EXISTS (SELECT 1 FROM ".TABLE_PREFIX.AF_WANTED_VALUES." vs WHERE vs.wanted_id=e.id AND vs.field_id IN (".implode(',',$searchFieldIds).") AND vs.value LIKE '%".$db->escape_string_like($search)."%')";}else{$where[]='1=0';}$params['search']=$search;}
 $sort=$mybb->get_input('sort')==='oldest'?'oldest':'newest';$params['sort']=$sort;$order=$sort==='oldest'?'e.created_at ASC':'e.created_at DESC';$per=max(1,min(100,(int)($mybb->settings['af_wanted_per_page']??12)));$page=max(1,(int)$mybb->get_input('page'));$start=($page-1)*$per;$condition=implode(' AND ',$where);
 $count=(int)$db->fetch_field($db->write_query('SELECT COUNT(*) c FROM '.TABLE_PREFIX.AF_WANTED_ENTRIES.' e WHERE '.$condition),'c');$entries=[];$q=$db->write_query('SELECT e.*,u.username,ru.username reserved_name,au.username accepted_name FROM '.TABLE_PREFIX.AF_WANTED_ENTRIES.' e LEFT JOIN '.TABLE_PREFIX.'users u ON u.uid=e.author_uid LEFT JOIN '.TABLE_PREFIX.'users ru ON ru.uid=e.reserved_by_uid LEFT JOIN '.TABLE_PREFIX.'users au ON au.uid=e.accepted_uid WHERE '.$condition.' ORDER BY '.$order.' LIMIT '.$start.','.$per);while($r=$db->fetch_array($q))$entries[]=$r;$values=af_wanted_values(array_column($entries,'id'));
 $query=http_build_query($params);$tabParams=$params;unset($tabParams['status']);$tabParams['tab']='active';$activeUrl='wanted.php?'.http_build_query($tabParams);$tabParams['tab']='archive';$archiveUrl='wanted.php?'.http_build_query($tabParams);
 $h='<section id="af-wanted-catalog" class="af-wanted-catalog" data-af-wanted-catalog><div class="af-wanted-toolbar"><nav class="af-wanted-tabs" aria-label="Разделы Wanted"><a class="af-wanted-tab'.($tab==='active'?' is-active':'').'" href="'.af_wanted_h($activeUrl).'"><i class="fa-solid fa-crosshairs" aria-hidden="true"></i><span>Активный поиск</span></a><a class="af-wanted-tab'.($tab==='archive'?' is-active':'').'" href="'.af_wanted_h($archiveUrl).'"><i class="fa-solid fa-box-archive" aria-hidden="true"></i><span>Архив</span></a></nav></div>';
 $h.='<form class="af-wanted-filters" method="get" action="wanted.php"><input type="hidden" name="tab" value="'.$tab.'"><div class="af-wanted-filter-fields af-kb-character-filter-fields"><label class="af-wanted-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><span class="af-wanted-sr-only">Поиск</span><input class="af-wanted-input" type="search" name="search" value="'.af_wanted_h($search).'" placeholder="Поиск по персонажам..."></label>';if($tab==='active'){$h.='<label>Статус <select class="af-wanted-select" name="status"><option value="">Все</option>';foreach(['open'=>'Свободные','reserved'=>'Придержанные','application'=>'С анкетой'] as $k=>$l)$h.='<option value="'.$k.'"'.($status===$k?' selected':'').'>'.$l.'</option>';$h.='</select></label>';}foreach($filters as [$f,$v]){$settings=(array)$f['settings'];$dependsOn=($f['type']==='kb_dynamic'&&($settings['source']??'')==='origin_variant')?(string)($settings['depends_on']??'origin'):'';$h.='<label>'.af_wanted_h($f['title']).' <select class="af-wanted-select" name="'.af_wanted_h($f['field_key']).'"'.($dependsOn!==''?' data-depends-on="'.af_wanted_h($dependsOn).'"':'').'><option value="">Все</option>';foreach(af_wanted_options($f,$mybb->input) as $k=>$l)$h.='<option value="'.af_wanted_h($k).'"'.($v===$k?' selected':'').'>'.af_wanted_h($l).'</option>';$h.='</select></label>';}$h.='<label>Сортировка <select class="af-wanted-select" name="sort"><option value="newest">Новые сначала</option><option value="oldest"'.($sort==='oldest'?' selected':'').'>Старые сначала</option></select></label><div class="af-wanted-filter-actions"><button class="af-wanted-button" type="submit"><i class="fa-solid fa-sliders" aria-hidden="true"></i><span>Применить</span></button><a class="af-wanted-button af-wanted-button--quiet" href="wanted.php?tab='.$tab.'"><span>Сбросить</span></a></div></div></form><div class="af-wanted-results af-kb-character-results" aria-live="polite"><div class="af-wanted-results-meta"><span>Найдено: <strong>'.(int)$count.'</strong></span></div><div class="af-wanted-grid af-kb-entries af-kb-entries--cards">';
 foreach($entries as $e){
   $entryId=(int)$e['id'];$ev=$values[$entryId]??[];$byKey=[];
   foreach($fields as $field)$byKey[$field['field_key']]=$ev[(int)$field['id']]??'';
   $cardTitle=af_wanted_entry_title($entryId,$fields,$ev);$imageUrl=af_wanted_primary_image($fields,$ev);
   $image=$imageUrl!==''?af_wanted_render_field_value(['type'=>'image'],$imageUrl):'<div class="af-kb-char-card__pic-placeholder" aria-hidden="true"></div>';
   $details='';foreach($fields as $f){$v=$ev[(int)$f['id']]??'';$settings=(array)($f['settings']??[]);if($v===''||empty($settings['show_card'])||!in_array($f['type'],['select','radio','kb_dynamic'],true))continue;if($f['type']==='kb_dynamic'&&($settings['source']??'')==='origin_variant'&&!af_wanted_options($f,$byKey))continue;$details.='<span class="af-wanted-chip">'.af_wanted_render_field_value($f,$v,$byKey).'</span>';}
   $cardStatus=$e['status']==='reserved'?'<span class="af-wanted-status af-wanted-status--reserved">'.af_wanted_h(af_wanted_status_label('reserved')).'</span>':af_wanted_status_chip($e);
   $excerpt='';foreach($fields as $field){$settings=(array)($field['settings']??[]);if(($field['type']??'')!=='textarea'||empty($settings['show_card']))continue;$plain=trim(preg_replace('/\s+/u',' ',strip_tags((string)($ev[(int)$field['id']]??'')))??'');if($plain==='')continue;$excerpt='<p class="af-wanted-card__excerpt">'.af_wanted_h($plain).'</p>';break;}
   $created=(int)($e['created_at']??0);$author=build_profile_link(af_wanted_h((string)$e['username']),(int)$e['author_uid']);
   $views=(int)($e['views']??0);
   $h.='<article class="af-wanted-card af-kb-char-card"><div class="af-kb-char-card__pic">'.$image.'<div class="af-wanted-card__image-overlay">'.$cardStatus.'</div></div><div class="af-wanted-card__body af-kb-char-card__body"><h2>'.af_wanted_h($cardTitle).'</h2><div class="af-wanted-card__fields">'.$details.'</div>'.$excerpt.'<div class="af-wanted-card__byline"><span>Автор: '.$author.'</span>'.($created?'<time datetime="'.af_wanted_h(gmdate('c',$created)).'">'.af_wanted_h(my_date('d.m.Y',$created)).'</time>':'').'</div>';
   if($e['status']==='archived'&&!empty($e['accepted_uid']))$h.='<p class="af-wanted-card__accepted">Игрок: '.build_profile_link(af_wanted_h((string)$e['accepted_name']),(int)$e['accepted_uid']).'</p>';
   $h.='<footer class="af-wanted-card__footer"><span class="af-wanted-card__views"><i class="fa-solid fa-eye" aria-hidden="true"></i><span>'.number_format($views,0,'.',' ').'</span></span><a class="af-wanted-button af-wanted-card__more" href="wanted.php?action=view&id='.$entryId.'"><span>Подробнее</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a></footer></div></article>';
 }
 $h.='</div>';if($count>$per&&function_exists('multipage'))$h.='<nav class="af-wanted-pagination" aria-label="Страницы">'.multipage($count,$per,$page,'wanted.php?'.$query).'</nav>';return $h.'</div></section>';
}
function af_wanted_link_application(int $wantedId,int $tid,int $uid): bool {
 global $db;
 $e=af_wanted_entry($wantedId);
 $moderator=af_wanted_groups('moderate');
 if(!$e||$tid<1||$uid<1||!in_array($e['status'],['open','reserved'],true)||($e['status']==='reserved'&&(int)$e['reserved_by_uid']>0&&(int)$e['reserved_by_uid']!==$uid&&!$moderator))return false;
 $reservationGuard="(status='open' OR (status='reserved' AND (reserved_by_uid=$uid OR (reserved_by_uid IS NULL AND reserved_guest_name<>'')))".($moderator?" OR status='reserved'":'').')';
 $db->write_query("UPDATE ".TABLE_PREFIX.AF_WANTED_ENTRIES." SET status='application',application_tid=$tid,reserved_by_uid=NULL,reserved_guest_name='',reserved_at=0,reserved_until=0,updated_at=".TIME_NOW." WHERE id=$wantedId AND $reservationGuard");
 if((int)$db->affected_rows()!==1)return false;
 if(function_exists('af_cwf_upsert_row'))af_cwf_upsert_row($tid,['wanted_id'=>$wantedId]);
 return true;
}
function af_wanted_application_accepted(int $wantedId,int $tid,int $acceptedUid): bool {global $db;if($wantedId<1&&$tid>0){$e=$db->fetch_array($db->simple_select(AF_WANTED_ENTRIES,'id','application_tid='.$tid,['limit'=>1]));$wantedId=(int)($e['id']??0);}if($wantedId<1||$tid<1||$acceptedUid<1)return false;$db->update_query(AF_WANTED_ENTRIES,['status'=>'archived','accepted_uid'=>$acceptedUid,'archived_at'=>TIME_NOW,'updated_at'=>TIME_NOW],"id=$wantedId AND status='application' AND application_tid=$tid");return (int)$db->affected_rows()===1;}
function af_wanted_application_released(int $wantedId,int $tid,int $applicantUid): bool {global $db;$e=$wantedId?af_wanted_entry($wantedId):(array)$db->fetch_array($db->simple_select(AF_WANTED_ENTRIES,'*','application_tid='.$tid,['limit'=>1]));if(!$e||($tid>0&&(int)($e['application_tid']??0)!==$tid))return false;$reserved=(int)$e['reserved_by_uid']===$applicantUid&&$applicantUid>0;$db->update_query(AF_WANTED_ENTRIES,['status'=>$reserved?'reserved':'open','application_tid'=>null,'accepted_uid'=>null,'archived_at'=>0,'updated_at'=>TIME_NOW],'id='.(int)$e['id']." AND status='application' AND application_tid=".(int)$tid);return (int)$db->affected_rows()===1;}
function af_wanted_intent_secret(): string {
 global $mybb;
 return hash('sha256',(string)($mybb->config['database']['password']??'').'|'.(string)($mybb->settings['bburl']??'').'|'.(string)$mybb->post_code);
}
function af_wanted_intent_encode(int $id,int $uid,int $fid): string {
 if($id<1||$uid<1||$fid<1)return '';
 $payload=$id.'.'.$uid.'.'.$fid.'.'.(TIME_NOW+1800);
 return base64_encode($payload.'.'.hash_hmac('sha256',$payload,af_wanted_intent_secret()));
}
function af_wanted_intent_decode(string $token): array {
 $raw=base64_decode($token,true);
 if(!is_string($raw)||$raw==='')return [];
 $p=explode('.',$raw);
 if(count($p)!==5)return [];
 [$id,$uid,$fid,$exp,$sig]=$p;
 foreach([$id,$uid,$fid,$exp] as $number)if(!preg_match('/^[0-9]+$/D',$number))return [];
 $payload=$id.'.'.$uid.'.'.$fid.'.'.$exp;
 $expires=(int)$exp;
 if((int)$id<1||(int)$uid<1||(int)$fid<1||$expires<TIME_NOW||$expires>TIME_NOW+1800)return [];
 if(!hash_equals(hash_hmac('sha256',$payload,af_wanted_intent_secret()),$sig))return [];
 return ['wanted_id'=>(int)$id,'uid'=>(int)$uid,'fid'=>(int)$fid,'expires'=>$expires];
}
function af_wanted_thread_created($handler): void {
 global $db,$mybb;
 $intent=af_wanted_intent_decode((string)($mybb->cookies['af_wanted_apply']??''));
 if(!$intent||(int)$intent['uid']!==(int)($mybb->user['uid']??0))return;
 $tid=(int)($handler->data['tid']??$handler->tid??0);
 if($tid<1)return;
 // Read the persisted topic: handler globals/current user are not proof of its
 // actual forum or author (moderation/import code may create on their behalf).
 $thread=(array)$db->fetch_array($db->simple_select('threads','tid,fid,uid','tid='.$tid,['limit'=>1]));
 if((int)($thread['tid']??0)!==$tid||(int)($thread['fid']??0)!==(int)$intent['fid']||(int)($thread['uid']??0)!==(int)$intent['uid'])return;
 if(af_wanted_link_application((int)$intent['wanted_id'],$tid,(int)$thread['uid']))my_unsetcookie('af_wanted_apply');
}
function af_wanted_discussion_intent_encode(int $id,int $uid,int $tid): string {
 $payload=$id.'.'.$uid.'.'.$tid.'.'.(TIME_NOW+900);return base64_encode($payload.'.'.hash_hmac('sha256',$payload,af_wanted_intent_secret()));
}
function af_wanted_discussion_intent_decode(string $token): array {
 $raw=base64_decode($token,true);$p=is_string($raw)?explode('.',$raw):[];if(count($p)!==5)return [];[$id,$uid,$tid,$exp,$sig]=$p;$payload=$id.'.'.$uid.'.'.$tid.'.'.$exp;if(!ctype_digit($id.$uid.$tid.$exp)||(int)$id<1||(int)$tid<1||(int)$exp<TIME_NOW||(int)$exp>TIME_NOW+900||!hash_equals(hash_hmac('sha256',$payload,af_wanted_intent_secret()),$sig))return [];return ['wanted_id'=>(int)$id,'uid'=>(int)$uid,'tid'=>(int)$tid];
}
function af_wanted_prefill_reply(): void {
 global $mybb;$intent=af_wanted_discussion_intent_decode((string)($mybb->cookies['af_wanted_discuss']??''));if(!$intent||(int)$mybb->get_input('tid')!==$intent['tid']||(int)($mybb->user['uid']??0)!==$intent['uid'])return;$entry=af_wanted_entry($intent['wanted_id']);if(!$entry)return;$mention='[mention='.(int)$entry['author_uid'].']'.(string)$entry['username'].'[/mention]';if(trim((string)($mybb->input['message']??''))==='')$mybb->input['message']=$mention.' [wanted='.$intent['wanted_id'].']';my_unsetcookie('af_wanted_discuss');
}
function af_wanted_parse_message_end(&$message,&$options=null): void {
 if(!is_string($message)||stripos($message,'[wanted=')===false)return;
 $message=preg_replace_callback('/\[wanted=([0-9]{1,10})\]/i',static function(array $m): string{$entry=af_wanted_entry((int)$m[1]);if(!$entry)return '';$fields=af_wanted_fields();$values=af_wanted_values([(int)$entry['id']])[(int)$entry['id']]??[];$title=af_wanted_entry_title((int)$entry['id'],$fields,$values);$image=af_wanted_primary_image($fields,$values);$icon=$image!==''?'<img class="af-wanted-chip-image" src="'.af_wanted_h($image).'" alt="" loading="lazy">':'<span class="af-wanted-chip-fallback" aria-hidden="true"></span>';return '<span class="af-wanted-post-chip" data-wanted-id="'.(int)$entry['id'].'" data-wanted-title="'.af_wanted_h($title).'" tabindex="0" role="button"><span class="af-wanted-chip-icon">'.$icon.'</span><span class="af-wanted-chip-label">'.af_wanted_h($title).'</span></span>';},$message);
}
/**
 * Wanted BBCode is expanded after the normal asset pass on some post pages.
 * Inject its owner runtime late, without enrolling Wanted entities in KB JS.
 */
function af_wanted_ensure_chip_runtime(string &$page=''): void {
 global $mybb;if(stripos($page,'af-wanted-post-chip')===false||stripos($page,'advancedwanted_modal.js')!==false)return;
 if(!af_wanted_frontend_asset_allowed('modal',['has_wanted_chip'=>true]))return;
 $bburl=rtrim((string)($mybb->settings['bburl']??''),'/');if($bburl==='')return;
 $base=$bburl.'/inc/plugins/advancedfunctionality/addons/advancedwanted/assets/';$file=__DIR__.'/assets/advancedwanted_modal.js';
 $inject='<link rel="stylesheet" href="'.$base.'advancedwanted.css"><script src="'.$base.'advancedwanted_modal.js?v='.(is_file($file)?(int)filemtime($file):1).'" defer></script>';
 if(stripos($page,'</head>')!==false)$page=str_ireplace('</head>',$inject.'</head>',$page);else$page.=$inject;
}

/** Apply the manifest decision and the pilot's legacy blacklist together. */
function af_wanted_frontend_asset_allowed(string $resource,array $responseFacts=[]): bool {
 if(function_exists('af_frontend_asset_allowed')&&!af_frontend_asset_allowed(AF_WANTED_ID,$resource,null,$responseFacts))return false;
 return !function_exists('af_is_blacklisted')||!af_is_blacklisted(AF_WANTED_ID);
}
function af_wanted_modal_payload(array $entry): array {
 return ['id'=>(int)$entry['id'],'detail_html'=>af_wanted_render_detail($entry,false)];
}

function af_wanted_detail_menu_script(): string {
 if (function_exists('af_adaptivethemeframework_presentation_is_active') && af_adaptivethemeframework_presentation_is_active()) return '';
 return '<script>(function(){if(window.afWantedDetailMenuBound)return;window.afWantedDetailMenuBound=true;var selector=".af-wanted-detail-menu";function setOpen(root,open,focusFirst){if(!root)return;var button=root.querySelector(".af-wanted-detail-menu__toggle"),panel=root.querySelector(".af-wanted-detail-menu__panel");if(!button||!panel)return;button.setAttribute("aria-expanded",open?"true":"false");panel.hidden=!open;if(open&&focusFirst){var first=panel.querySelector("[role=menuitem]:not([disabled])");if(first)first.focus();}}function closeAll(except){document.querySelectorAll(selector).forEach(function(root){if(root!==except)setOpen(root,false,false);});}document.addEventListener("click",function(event){var button=event.target.closest&&event.target.closest(".af-wanted-detail-menu__toggle");if(button){var root=button.closest(selector),opening=button.getAttribute("aria-expanded")!=="true";closeAll(root);setOpen(root,opening,opening);return;}var item=event.target.closest&&event.target.closest(".af-wanted-detail-menu__item");if(item){setOpen(item.closest(selector),false,false);return;}if(!(event.target.closest&&event.target.closest(selector)))closeAll(null);});document.addEventListener("keydown",function(event){var root=event.target.closest&&event.target.closest(selector);if(!root)return;var button=root.querySelector(".af-wanted-detail-menu__toggle"),panel=root.querySelector(".af-wanted-detail-menu__panel"),items=panel?Array.prototype.slice.call(panel.querySelectorAll("[role=menuitem]:not([disabled])")):[],index=items.indexOf(event.target);if(event.key==="Escape"){event.preventDefault();setOpen(root,false,false);button.focus();return;}if((event.key==="ArrowDown"||event.key==="ArrowUp")&&event.target===button){event.preventDefault();setOpen(root,true,false);if(items.length)items[event.key==="ArrowDown"?0:items.length-1].focus();return;}if(index<0||!items.length)return;if(event.key==="ArrowDown"||event.key==="ArrowUp"){event.preventDefault();items[(index+(event.key==="ArrowDown"?1:items.length-1))%items.length].focus();}else if(event.key==="Home"){event.preventDefault();items[0].focus();}else if(event.key==="End"){event.preventDefault();items[items.length-1].focus();}});document.addEventListener("focusin",function(event){if(!(event.target.closest&&event.target.closest(selector)))closeAll(null);});document.addEventListener("submit",function(event){var form=event.target.closest&&event.target.closest(".af-wanted-detail-menu__delete");if(!form)return;setOpen(form.closest(selector),false,false);var message=form.getAttribute("data-af-wanted-confirm");if(message&&!window.confirm(message)){event.preventDefault();var toggle=form.closest(selector).querySelector(".af-wanted-detail-menu__toggle");if(toggle)toggle.focus();}});})();</script>';
}

/** One detail renderer shared by the full page and the post-chip modal. */
function af_wanted_render_detail(array $entry,bool $withActions=true): string {
 $id=(int)$entry['id'];$fields=af_wanted_fields();$vals=af_wanted_values([$id])[$id]??[];$byKey=[];foreach($fields as $f)$byKey[$f['field_key']]=$vals[(int)$f['id']]??'';
 $title=af_wanted_entry_title($id,$fields,$vals);$image=af_wanted_primary_image($fields,$vals);$info='';$content='';
 foreach($fields as $f){
  $value=$vals[(int)$f['id']]??'';$settings=(array)($f['settings']??[]);
  if(strtolower(trim((string)($f['field_key']??'')))==='author')continue;
  if(($f['type']??'')==='image'||!empty($settings['is_title'])||!($settings['show_detail']??true)||$value==='')continue;
  if(($f['type']??'')==='kb_dynamic'&&($settings['source']??'')==='origin_variant'&&!af_wanted_options($f,$byKey))continue;
  $rendered=af_wanted_render_field_value($f,$value,$byKey);if($rendered==='')continue;
  $row=($f['type']??'')==='textarea'?'<section class="af-wanted-detail-section af-wanted-detail-section--textarea"><h3>'.af_wanted_h($f['title']).'</h3><span class="atf-separator" aria-hidden="true"></span><div class="af-wanted-detail-section__body">'.$rendered.'</div></section>':'<div class="af-wanted-detail-field af-wanted-detail-field--'.af_wanted_h((string)$f['type']).'"><i class="fa-solid '.af_wanted_detail_field_icon((string)($f['field_key']??'')).'" aria-hidden="true"></i><dt>'.af_wanted_h($f['title']).'</dt><dd>'.$rendered.'</dd></div>';
  if(($f['type']??'')==='textarea')$content.=$row;else$info.=$row;
 }
 $author=build_profile_link(af_wanted_h((string)$entry['username']),(int)$entry['author_uid']);
 $player=$entry['status']==='archived'&&!empty($entry['accepted_uid'])?'<div class="af-wanted-detail-field"><i class="fa-solid fa-user" aria-hidden="true"></i><dt>Игрок</dt><dd>'.build_profile_link(af_wanted_h((string)$entry['accepted_name']),(int)$entry['accepted_uid']).'</dd></div>':'';
 $imageMarkup=$image!==''?af_wanted_render_field_value(['type'=>'image'],$image):'<div class="af-wanted-detail-image-placeholder" aria-hidden="true"></div>';
 $created=(int)($entry['created_at']??0);$updated=(int)($entry['updated_at']??0);
 $views=(int)($entry['views']??0);
 $authorUser=['uid'=>(int)$entry['author_uid'],'username'=>(string)$entry['username'],'avatar'=>(string)($entry['author_avatar']??''),'avatardimensions'=>(string)($entry['author_avatar_dimensions']??'')];
 if(function_exists('af_avatar_render'))$avatarMarkup=af_avatar_render($authorUser,'wanted',['decorative'=>true]);
 else {
  $formatted=function_exists('format_avatar')?format_avatar($authorUser['avatar'],$authorUser['avatardimensions'],'48|48'):[];
  $avatarUrl=(string)($formatted['image']??$authorUser['avatar']);
  if($avatarUrl==='')$avatarUrl=str_replace('{theme}',(string)($GLOBALS['theme']['imgdir']??'images'),(string)($GLOBALS['mybb']->settings['useravatar']??'images/default_avatar.png'));
  $avatarMarkup='<img src="'.af_wanted_h($avatarUrl).'" width="48" height="48" alt="" loading="lazy">';
 }
 $avatar='<span class="af-wanted-detail-meta__avatar">'.$avatarMarkup.'</span>';
 $meta='<footer class="af-wanted-detail-meta"><span class="af-wanted-detail-meta__item af-wanted-detail-meta__author">'.$avatar.'<span class="af-wanted-detail-meta__author-copy"><strong>Автор заявки</strong>'.$author.'</span></span>'.($created?'<span class="af-wanted-detail-meta__item"><strong>Создано</strong><time datetime="'.af_wanted_h(gmdate('c',$created)).'">'.af_wanted_h(my_date('d.m.Y, H:i',$created)).'</time></span>':'').($updated>$created?'<span class="af-wanted-detail-meta__item"><strong>Обновлено</strong><time datetime="'.af_wanted_h(gmdate('c',$updated)).'">'.af_wanted_h(my_date('d.m.Y, H:i',$updated)).'</time></span>':'').'<span class="af-wanted-detail-meta__item af-wanted-detail-meta__views"><strong>Просмотры</strong><span><i class="fa-solid fa-eye" aria-hidden="true"></i> '.number_format($views,0,'.',' ').'</span></span></footer>';
 $menu=$withActions?af_wanted_actions($entry,true,'menu'):'';$viewTools=$withActions?'<div class="af-wanted-detail-tools"><a class="af-wanted-button af-wanted-detail-back" href="wanted.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i><span>Назад</span></a>'.$menu.'</div>':'';
 $actions=$withActions?af_wanted_actions($entry,true,'lifecycle'):'';$management=$withActions?af_wanted_actions($entry,true,'management'):'';$menuScript=$menu!==''?af_wanted_detail_menu_script():'';
 return '<article class="af-wanted-detail af-wanted-detail--dossier atf-page-surface"><header class="af-wanted-detail-header"><div class="af-wanted-detail-header__top"><div class="af-wanted-detail-heading"><h2 class="af-wanted-detail-title">'.af_wanted_h($title).'</h2>'.af_wanted_status_chip($entry).'</div>'.$viewTools.'</div><span class="af-wanted-detail-header__rule" aria-hidden="true"></span></header><div class="af-wanted-detail-overview"><figure class="af-wanted-detail-image">'.$imageMarkup.'</figure><div class="af-wanted-detail-sidebar"><aside class="af-wanted-infobox"><h3 class="af-wanted-infobox__title"><i class="fa-solid fa-list-check" aria-hidden="true"></i><span>Ключевая информация</span></h3><dl>'.af_wanted_reservation_html($entry).$info.$player.'</dl></aside>'.$actions.'</div></div><div class="af-wanted-detail-content"><div class="af-wanted-detail-sections">'.($content!==''?$content:'<p class="af-wanted-empty">Подробное описание заявки пока не добавлено.</p>').'</div></div>'.$meta.$management.$menuScript.'</article>';
}

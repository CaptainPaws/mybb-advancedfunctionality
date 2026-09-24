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
    if (is_object($plugins)) { $plugins->add_hook('datahandler_post_insert_thread', 'af_wanted_thread_created'); }
}
function af_advancedwanted_uninstall(): void {
    global $db;
    $db->delete_query('settings', "name LIKE 'af_wanted_%'");
    $db->delete_query('settinggroups', "name='af_wanted'");
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
      accepted_uid INT UNSIGNED DEFAULT NULL, created_at INT UNSIGNED NOT NULL, updated_at INT UNSIGNED NOT NULL, archived_at INT UNSIGNED NOT NULL DEFAULT 0,
      PRIMARY KEY(id), KEY status(status), KEY author_uid(author_uid), KEY application_tid(application_tid), KEY accepted_uid(accepted_uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$db->table_exists(AF_WANTED_FIELDS)) $db->write_query("CREATE TABLE ".TABLE_PREFIX.AF_WANTED_FIELDS." (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT, field_key VARCHAR(64) NOT NULL, title VARCHAR(190) NOT NULL, type VARCHAR(24) NOT NULL DEFAULT 'text',
      required TINYINT(1) NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 1, sortorder INT NOT NULL DEFAULT 0, settings_json TEXT,
      PRIMARY KEY(id), UNIQUE KEY field_key(field_key), KEY active_sort(active,sortorder)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$db->table_exists(AF_WANTED_VALUES)) $db->write_query("CREATE TABLE ".TABLE_PREFIX.AF_WANTED_VALUES." (
      wanted_id INT UNSIGNED NOT NULL, field_id INT UNSIGNED NOT NULL, value TEXT, value_key VARCHAR(190) NOT NULL DEFAULT '',
      PRIMARY KEY(wanted_id,field_id), KEY field_value(field_id,value_key), KEY wanted_id(wanted_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function af_wanted_ensure_settings(): void {
    global $db;
    if (!is_object($db)) return;
    $group=$db->fetch_array($db->simple_select('settinggroups','gid',"name='af_wanted'",['limit'=>1]));
    $gid=(int)($group['gid']??0);
    if (!$gid) $gid=(int)$db->insert_query('settinggroups',['name'=>'af_wanted','title'=>'AdvancedWanted','description'=>'Права и интеграция каталога Wanted.','disporder'=>35,'isdefault'=>0]);
    $defs=[
      ['af_wanted_view_groups','Группы просмотра','-1'],['af_wanted_create_groups','Группы создания','2'],
      ['af_wanted_edit_groups','Группы редактирования своих','2'],['af_wanted_delete_groups','Группы удаления своих','2'],
      ['af_wanted_moderate_groups','Группы модерации','4'],['af_wanted_application_forum','ID форума анкет','0'],
      ['af_wanted_per_page','Записей на страницу','12']];
    foreach($defs as $i=>$d) if(!$db->fetch_field($db->simple_select('settings','sid',"name='".$db->escape_string($d[0])."'"),'sid'))
      $db->insert_query('settings',['name'=>$d[0],'title'=>$d[1],'description'=>'CSV gid; -1 = все группы.','optionscode'=>$i>4?'numeric':'text','value'=>$d[2],'disporder'=>$i+1,'gid'=>$gid]);
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
    if ($action==='reserve'||$action==='apply') return 'create';
    return in_array($action,['view','create','edit','delete','moderate'],true)?$action:'';
}
function af_wanted_can(string $action, array $entry=[]): bool {
    global $mybb;
    $uid=(int)($mybb->user['uid']??0);
    if (af_wanted_groups('moderate')) return true;
    $permission=af_wanted_permission_name($action);
    if ($permission==='') return false;
    if ($permission==='view') return af_wanted_groups('view');
    if (!$uid) return false;
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
 $q=$db->write_query('SELECT e.*,u.username FROM '.TABLE_PREFIX.AF_WANTED_ENTRIES.' e LEFT JOIN '.TABLE_PREFIX.'users u ON u.uid=e.author_uid WHERE e.id='.$id.' LIMIT 1');
 return (array)$db->fetch_array($q);
}
function af_wanted_values(array $ids): array {
 global $db; $out=[]; $ids=array_values(array_filter(array_map('intval',$ids))); if(!$ids)return $out;
 $q=$db->simple_select(AF_WANTED_VALUES,'*','wanted_id IN ('.implode(',',$ids).')'); while($r=$db->fetch_array($q))$out[(int)$r['wanted_id']][(int)$r['field_id']]=(string)$r['value']; return $out;
}
function af_wanted_validate(array $fields,array $raw): array {
 $clean=[];$errors=[];foreach($fields as $f){$k=(string)$f['field_key'];$v=$raw[$k]??'';if(is_array($v))$v=array_values(array_filter(array_map('strval',$v)));else$v=trim((string)$v);
 if(!empty($f['required'])&&($v===''||$v===[]))$errors[]='Заполните поле «'.$f['title'].'».';
 if($f['type']==='number'&&$v!==''&&!is_numeric($v))$errors[]='Поле «'.$f['title'].'» должно быть числом.';
 if(in_array($f['type'],['url','image'],true)&&$v!==''&&!af_wanted_valid_http_url((string)$v))$errors[]='Некорректный HTTP(S) URL в поле «'.$f['title'].'».';
 if(in_array($f['type'],['select','radio','kb_dynamic'],true)&&$v!==''&&!array_key_exists((string)$v,af_wanted_options($f,$raw)))$errors[]='Недопустимое значение поля «'.$f['title'].'».';
 if($f['type']==='multi'){$allowed=af_wanted_options($f,$raw);if(!is_array($v))$errors[]='Недопустимый формат поля «'.$f['title'].'».';else foreach($v as $selected)if(!array_key_exists((string)$selected,$allowed)){$errors[]='Недопустимое значение поля «'.$f['title'].'».';break;}}
 $clean[(int)$f['id']]=is_array($v)?json_encode($v,JSON_UNESCAPED_UNICODE):(string)$v;} return [$clean,$errors];
}
function af_wanted_valid_http_url(string $value): bool { if(!filter_var($value,FILTER_VALIDATE_URL))return false;$scheme=strtolower((string)parse_url($value,PHP_URL_SCHEME));return in_array($scheme,['http','https'],true); }
function af_wanted_save_values(int $id,array $values): void { global $db; foreach($values as $fid=>$v){$db->replace_query(AF_WANTED_VALUES,['wanted_id'=>$id,'field_id'=>(int)$fid,'value'=>$db->escape_string($v),'value_key'=>$db->escape_string(substr($v,0,190))]);} }
function af_wanted_h(string $v): string { return htmlspecialchars_uni($v); }
function af_wanted_status_label(string $s): string { return ['open'=>'Свободно','reserved'=>'Придержано','application'=>'Анкета подана','archived'=>'Закрыто'][$s]??$s; }
function af_wanted_display_value(array $field,string $value,array $all=[]): string {
 if(($field['type']??'')==='multi'){
   $selected=json_decode($value,true);if(!is_array($selected))return $value;
   $options=af_wanted_options($field,$all);$labels=[];foreach($selected as $key)$labels[]=$options[(string)$key]??(string)$key;
   return implode(', ',$labels);
 }
 if(($field['type']??'')==='checkbox')return $value==='1'?'Да':'Нет';
 $options=af_wanted_options($field,$all);return $options[$value]??$value;
}
function af_wanted_field_control(array $f,$value,array $all=[]): string {
 $k=af_wanted_h((string)$f['field_key']);$v=is_array($value)?$value:(string)$value;$req=!empty($f['required'])?' required':'';$type=(string)$f['type'];
 if($type==='textarea')return '<textarea name="fields['.$k.']"'.$req.'>'.af_wanted_h((string)$v).'</textarea>';
 if(in_array($type,['select','radio','kb_dynamic'],true)){ $opts=af_wanted_options($f,$all); if($type==='radio'){ $h='';foreach($opts as $ok=>$ol)$h.='<label><input type="radio" name="fields['.$k.']" value="'.af_wanted_h($ok).'"'.((string)$v===$ok?' checked':'').$req.'> '.af_wanted_h($ol).'</label> ';return $h;} $h='<select name="fields['.$k.']"'.$req.'><option value=""></option>';foreach($opts as $ok=>$ol)$h.='<option value="'.af_wanted_h($ok).'"'.((string)$v===$ok?' selected':'').'>'.af_wanted_h($ol).'</option>';return $h.'</select>'; }
 if($type==='multi'){ $vals=is_array($v)?$v:(json_decode((string)$v,true)?:[]);$h='<select multiple name="fields['.$k.'][]"'.$req.'>';foreach(af_wanted_options($f,$all) as $ok=>$ol)$h.='<option value="'.af_wanted_h($ok).'"'.(in_array($ok,$vals,true)?' selected':'').'>'.af_wanted_h($ol).'</option>';return $h.'</select>'; }
 if($type==='checkbox')return '<input type="checkbox" name="fields['.$k.']" value="1"'.((string)$v==='1'?' checked':'').'>';
 $htmlType=in_array($type,['url','image'],true)?'url':($type==='number'?'number':'text'); return '<input type="'.$htmlType.'" name="fields['.$k.']" value="'.af_wanted_h((string)$v).'"'.$req.($type==='number'?' step="any"':'').($type==='image'?' placeholder="https://example.com/image.jpg"':'').'>';
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
 $data=af_wanted_dependency_data($fields);if(!$data)return '';
 $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
 return '<script>(function(){var dependencies='.$json.';dependencies.forEach(function(config){var parent=document.querySelector("[name=\\"fields["+config.depends_on+"]\\"]");var child=document.querySelector("[name=\\"fields["+config.field+"]\\"]");if(!parent||!child)return;parent.addEventListener("change",function(){var rows=config.options[parent.value]||{};child.value="";while(child.options.length)child.remove(0);child.add(new Option("",""));Object.keys(rows).forEach(function(key){child.add(new Option(rows[key],key));});});});})();</script>';
}
function af_wanted_render_page(): void {
 global $mybb,$db,$headerinclude,$header,$footer,$theme;
 if(!af_wanted_can('view')) error_no_permission();
 $action=$mybb->get_input('action');$id=(int)$mybb->get_input('id');$entry=$id?af_wanted_entry($id):[];
 if($mybb->request_method==='post'){ verify_post_check($mybb->get_input('my_post_key'));
   if($action==='save'){ $editing=!empty($entry);if(!af_wanted_can($editing?'edit':'create',$entry)||($editing&&$entry['status']==='application'&&!af_wanted_groups('moderate')))error_no_permission(); [$vals,$errs]=af_wanted_validate(af_wanted_fields(),(array)($mybb->input['fields']??[]));if($errs)error(implode('<br>',$errs));if(!$editing){$id=(int)$db->insert_query(AF_WANTED_ENTRIES,['author_uid'=>(int)$mybb->user['uid'],'status'=>'open','created_at'=>TIME_NOW,'updated_at'=>TIME_NOW]);}else{$db->update_query(AF_WANTED_ENTRIES,['updated_at'=>TIME_NOW],'id='.$id);}af_wanted_save_values($id,$vals);redirect('wanted.php?action=view&id='.$id,'Запись сохранена.'); }
   if($action==='reserve'&&af_wanted_can('reserve',$entry)){ $db->write_query("UPDATE ".TABLE_PREFIX.AF_WANTED_ENTRIES." SET status='reserved',reserved_by_uid=".(int)$mybb->user['uid'].",reserved_at=".TIME_NOW.",updated_at=".TIME_NOW." WHERE id=$id AND status='open'");if((int)$db->affected_rows()!==1)error('Эта запись уже недоступна для бронирования.');redirect('wanted.php?action=view&id='.$id,'Персонаж придержан.'); }
   if($action==='delete'&&af_wanted_can('delete',$entry)){ $db->delete_query(AF_WANTED_VALUES,'wanted_id='.$id);$db->delete_query(AF_WANTED_ENTRIES,'id='.$id);redirect('wanted.php','Запись удалена.'); }
   if($action==='apply'&&af_wanted_can('apply',$entry)){ if(!in_array($entry['status']??'', ['open','reserved'],true)||(($entry['status']??'')==='reserved'&&(int)$entry['reserved_by_uid']!==(int)$mybb->user['uid']))error('Запись недоступна для подачи анкеты.');$intent=af_wanted_intent_encode($id,(int)$mybb->user['uid']); my_setcookie('af_wanted_apply',$intent,1800,true);$fid=(int)($mybb->settings['af_wanted_application_forum']??0);redirect('newthread.php?fid='.$fid,'Создайте тему анкеты. Связь с Wanted будет добавлена автоматически.'); }
 }
 if($action===''&&$mybb->get_input('ajax')==='1'){
   header('Content-Type: text/html; charset='.$mybb->settings['charset']);
   echo af_wanted_catalog();
   exit;
 }
 $title='Нужные персонажи';add_breadcrumb($title,'wanted.php');
 $body='<div class="af-wanted"><h1>'.$title.'</h1>';
 if($action==='create'||$action==='edit'){if(!af_wanted_can($action==='create'?'create':'edit',$entry))error_no_permission();$fields=af_wanted_fields();$vals=$entry?af_wanted_values([$id])[$id]??[]:[];$byKey=[];foreach($fields as $f)$byKey[$f['field_key']]=$vals[(int)$f['id']]??'';$body.='<form method="post" action="wanted.php?action=save'.($id?'&id='.$id:'').'"><input type="hidden" name="my_post_key" value="'.$mybb->post_code.'">';foreach($fields as $f)$body.='<div class="af-wanted-field"><label>'.af_wanted_h($f['title']).(!empty($f['required'])?' *':'').'</label>'.af_wanted_field_control($f,$vals[(int)$f['id']]??'',$byKey).'</div>';$body.='<button class="button" type="submit">Сохранить</button></form>'.af_wanted_dependency_script($fields);
 } elseif($action==='view'){if(!$entry)error('Wanted не найден.');$fields=af_wanted_fields();$vals=af_wanted_values([$id])[$id]??[];$byKey=[];foreach($fields as $f)$byKey[$f['field_key']]=$vals[(int)$f['id']]??'';$body.='<h2>Wanted #'.$id.'</h2><p><span class="af-wanted-status">'.af_wanted_status_label($entry['status']).'</span></p><p>Автор: '.build_profile_link(af_wanted_h((string)$entry['username']),(int)$entry['author_uid']).'</p><dl>';foreach($fields as $f)if(($f['settings']['show_detail']??true)&&isset($vals[$f['id']])&&$vals[$f['id']]!=='')$body.='<dt>'.af_wanted_h($f['title']).'</dt><dd>'.nl2br(af_wanted_h(af_wanted_display_value($f,$vals[$f['id']],$byKey))).'</dd>';$body.='</dl>'.af_wanted_actions($entry);
 } else $body.=af_wanted_catalog().'<script defer src="inc/plugins/advancedfunctionality/addons/advancedwanted/assets/advancedwanted_catalog.js"></script>';
 $body.='</div>';
 if (function_exists('af_front_output_template_string')) {
     af_front_output_template_string($title,'{$wanted_body}',['wanted_body'=>$body]);
 }
 output_page('<!DOCTYPE html><html><head><title>'.af_wanted_h($title).'</title>'.$headerinclude.'</head><body>'.$header.$body.$footer.'</body></html>');
}
function af_wanted_actions(array $e): string { global $mybb; $id=(int)$e['id'];$h='';$post='<input type="hidden" name="my_post_key" value="'.af_wanted_h((string)$mybb->post_code).'">';if($e['status']==='open'&&af_wanted_can('reserve',$e))$h.='<form method="post" action="wanted.php?action=reserve&id='.$id.'">'.$post.'<button>Придержать</button></form>';if(in_array($e['status'],['open','reserved'],true)&&af_wanted_can('apply',$e))$h.='<form method="post" action="wanted.php?action=apply&id='.$id.'">'.$post.'<button>Подать анкету</button></form>';if($e['status']==='application'&&!empty($e['application_tid']))$h.='<a class="button" href="showthread.php?tid='.(int)$e['application_tid'].'">Открыть анкету</a>';if(af_wanted_can('edit',$e)&&$e['status']!=='application')$h.='<a class="button" href="wanted.php?action=edit&id='.$id.'">Изменить</a>';if(af_wanted_can('delete',$e))$h.='<form method="post" action="wanted.php?action=delete&id='.$id.'">'.$post.'<button type="submit" class="button">Удалить</button></form>';return '<div class="af-wanted-actions">'.$h.'</div>'; }
function af_wanted_catalog(): string {
 global $db,$mybb;
 $tab=$mybb->get_input('tab')==='archive'?'archive':'active';$where=[$tab==='archive'?"e.status='archived'":"e.status IN ('open','reserved','application')"];$params=['tab'=>$tab];
 $status=$mybb->get_input('status');if($tab==='active'&&in_array($status,['open','reserved','application'],true)){$where[]="e.status='".$db->escape_string($status)."'";$params['status']=$status;}
 $fields=af_wanted_fields();$filters=[];foreach($fields as $f){if(empty($f['settings']['filterable'])||!in_array($f['type'],['select','kb_dynamic','radio','checkbox','number','text'],true))continue;$key=(string)$f['field_key'];$value=trim((string)$mybb->get_input($key));if($value!==''&&in_array($f['type'],['select','kb_dynamic','radio'],true)&&!array_key_exists($value,af_wanted_options($f,$mybb->input)))$value='';if($value!==''){$alias='vf'.(int)$f['id'];$where[]="EXISTS (SELECT 1 FROM ".TABLE_PREFIX.AF_WANTED_VALUES." $alias WHERE $alias.wanted_id=e.id AND $alias.field_id=".(int)$f['id']." AND $alias.value_key='".$db->escape_string($value)."')";$params[$key]=$value;}$filters[]=[$f,$value];}
 $search=trim((string)$mybb->get_input('search'));if($search!==''){$searchFieldIds=[];foreach($fields as $field)if(in_array((string)$field['field_key'],['title','name','character_name','description'],true))$searchFieldIds[]=(int)$field['id'];if($searchFieldIds){$where[]="EXISTS (SELECT 1 FROM ".TABLE_PREFIX.AF_WANTED_VALUES." vs WHERE vs.wanted_id=e.id AND vs.field_id IN (".implode(',',$searchFieldIds).") AND vs.value LIKE '%".$db->escape_string_like($search)."%')";}else{$where[]='1=0';}$params['search']=$search;}
 $sort=$mybb->get_input('sort')==='oldest'?'oldest':'newest';$params['sort']=$sort;$order=$sort==='oldest'?'e.created_at ASC':'e.created_at DESC';$per=max(1,min(100,(int)($mybb->settings['af_wanted_per_page']??12)));$page=max(1,(int)$mybb->get_input('page'));$start=($page-1)*$per;$condition=implode(' AND ',$where);
 $count=(int)$db->fetch_field($db->write_query('SELECT COUNT(*) c FROM '.TABLE_PREFIX.AF_WANTED_ENTRIES.' e WHERE '.$condition),'c');$entries=[];$q=$db->write_query('SELECT e.*,u.username,ru.username reserved_name,au.username accepted_name FROM '.TABLE_PREFIX.AF_WANTED_ENTRIES.' e LEFT JOIN '.TABLE_PREFIX.'users u ON u.uid=e.author_uid LEFT JOIN '.TABLE_PREFIX.'users ru ON ru.uid=e.reserved_by_uid LEFT JOIN '.TABLE_PREFIX.'users au ON au.uid=e.accepted_uid WHERE '.$condition.' ORDER BY '.$order.' LIMIT '.$start.','.$per);while($r=$db->fetch_array($q))$entries[]=$r;$values=af_wanted_values(array_column($entries,'id'));
 $query=http_build_query($params);$tabParams=$params;unset($tabParams['status']);$tabParams['tab']='active';$activeUrl='wanted.php?'.http_build_query($tabParams);$tabParams['tab']='archive';$archiveUrl='wanted.php?'.http_build_query($tabParams);
 $h='<section id="af-wanted-catalog" data-af-wanted-catalog><div class="af-wanted-tabs"><a class="button'.($tab==='active'?' is-active':'').'" href="'.af_wanted_h($activeUrl).'">Активный поиск</a><a class="button'.($tab==='archive'?' is-active':'').'" href="'.af_wanted_h($archiveUrl).'">Архив</a></div>';if(af_wanted_can('create'))$h.='<p><a class="button" href="wanted.php?action=create">Создать нужного персонажа</a></p>';
 $h.='<form class="af-wanted-filters" method="get" action="wanted.php"><input type="hidden" name="tab" value="'.$tab.'"><label>Поиск <input name="search" value="'.af_wanted_h($search).'"></label>';if($tab==='active'){$h.='<label>Статус <select name="status"><option value="">Все</option>';foreach(['open'=>'Свободные','reserved'=>'Придержанные','application'=>'С анкетой'] as $k=>$l)$h.='<option value="'.$k.'"'.($status===$k?' selected':'').'>'.$l.'</option>';$h.='</select></label>';}foreach($filters as [$f,$v]){$settings=(array)$f['settings'];$dependsOn=($f['type']==='kb_dynamic'&&($settings['source']??'')==='origin_variant')?(string)($settings['depends_on']??'origin'):'';$h.='<label>'.af_wanted_h($f['title']).' <select name="'.af_wanted_h($f['field_key']).'"'.($dependsOn!==''?' data-depends-on="'.af_wanted_h($dependsOn).'"':'').'><option value="">Все</option>';foreach(af_wanted_options($f,$mybb->input) as $k=>$l)$h.='<option value="'.af_wanted_h($k).'"'.($v===$k?' selected':'').'>'.af_wanted_h($l).'</option>';$h.='</select></label>';}$h.='<label>Сортировка <select name="sort"><option value="newest">Новые сначала</option><option value="oldest"'.($sort==='oldest'?' selected':'').'>Старые сначала</option></select></label><button type="submit">Применить</button><a href="wanted.php?tab='.$tab.'">Сбросить</a></form><div class="af-wanted-results" aria-live="polite"><div class="af-wanted-grid">';
 foreach($entries as $e){$ev=$values[$e['id']]??[];$byKey=[];foreach($fields as $field)$byKey[$field['field_key']]=$ev[(int)$field['id']]??'';$title='Wanted #'.(int)$e['id'];$image='';$details='';foreach($fields as $f){$v=$ev[$f['id']]??'';if($v==='')continue;if(in_array($f['field_key'],['title','name','character_name'],true))$title=af_wanted_h($v);if($f['type']==='image'&&af_wanted_valid_http_url((string)$v))$image='<img src="'.af_wanted_h($v).'" alt="">';if(!empty($f['settings']['show_card'])&&!in_array($f['type'],['textarea','image'],true))$details.='<span><b>'.af_wanted_h($f['title']).':</b> '.af_wanted_h(af_wanted_display_value($f,$v,$byKey)).'</span>';}$h.='<article class="af-wanted-card">'.$image.'<h2>'.$title.'</h2><small>Wanted #'.(int)$e['id'].' · '.af_wanted_status_label($e['status']).'</small><div>'.$details.'</div><p>Автор: '.build_profile_link(af_wanted_h((string)$e['username']),(int)$e['author_uid']).'</p>';if($e['status']==='reserved')$h.='<p>Придержано пользователем '.build_profile_link(af_wanted_h((string)$e['reserved_name']),(int)$e['reserved_by_uid']).'</p>';if($e['status']==='archived'&&!empty($e['accepted_uid']))$h.='<p>Игрок: '.build_profile_link(af_wanted_h((string)$e['accepted_name']),(int)$e['accepted_uid']).'</p>';$h.='<a class="button" href="wanted.php?action=view&id='.(int)$e['id'].'">Подробнее</a>'.af_wanted_actions($e).'</article>';}$h.='</div>';if($count>$per&&function_exists('multipage'))$h.='<nav class="af-wanted-pagination" aria-label="Страницы">'.multipage($count,$per,$page,'wanted.php?'.$query).'</nav>';return $h.'</div></section>';
}
function af_wanted_link_application(int $wantedId,int $tid,int $uid): bool { global $db;$e=af_wanted_entry($wantedId);if(!$e||$tid<1||!in_array($e['status'],['open','reserved'],true)||($e['status']==='reserved'&&(int)$e['reserved_by_uid']!==$uid))return false;$db->write_query("UPDATE ".TABLE_PREFIX.AF_WANTED_ENTRIES." SET status='application',application_tid=$tid,updated_at=".TIME_NOW." WHERE id=$wantedId AND status IN ('open','reserved')");if((int)$db->affected_rows()!==1)return false;if(function_exists('af_cwf_upsert_row'))af_cwf_upsert_row($tid,['wanted_id'=>$wantedId]);return true; }
function af_wanted_application_accepted(int $wantedId,int $tid,int $acceptedUid): bool {global $db;if($wantedId<1&&$tid>0){$e=$db->fetch_array($db->simple_select(AF_WANTED_ENTRIES,'id','application_tid='.$tid,['limit'=>1]));$wantedId=(int)($e['id']??0);}if($wantedId<1)return false;$db->update_query(AF_WANTED_ENTRIES,['status'=>'archived','accepted_uid'=>$acceptedUid?:null,'archived_at'=>TIME_NOW,'updated_at'=>TIME_NOW],"id=$wantedId AND status='application'");return (int)$db->affected_rows()===1;}
function af_wanted_application_released(int $wantedId,int $tid,int $applicantUid): bool {global $db;$e=$wantedId?af_wanted_entry($wantedId):(array)$db->fetch_array($db->simple_select(AF_WANTED_ENTRIES,'*','application_tid='.$tid,['limit'=>1]));if(!$e)return false;$reserved=(int)$e['reserved_by_uid']===$applicantUid&&$applicantUid>0;$db->update_query(AF_WANTED_ENTRIES,['status'=>$reserved?'reserved':'open','application_tid'=>null,'updated_at'=>TIME_NOW],'id='.(int)$e['id']." AND status='application'");return (int)$db->affected_rows()===1;}
function af_wanted_intent_encode(int $id,int $uid): string { global $mybb; $payload=$id.'.'.$uid.'.'.(TIME_NOW+1800);$secret=(string)($mybb->config['database']['password']??$mybb->post_code);return base64_encode($payload.'.'.hash_hmac('sha256',$payload,$secret)); }
function af_wanted_intent_decode(string $token): array { global $mybb;$raw=base64_decode($token,true);if(!$raw)return [];$p=explode('.',$raw);if(count($p)!==4)return [];[$id,$uid,$exp,$sig]=$p;$payload=$id.'.'.$uid.'.'.$exp;$secret=(string)($mybb->config['database']['password']??$mybb->post_code);if(!hash_equals(hash_hmac('sha256',$payload,$secret),$sig)||(int)$exp<TIME_NOW)return [];return ['wanted_id'=>(int)$id,'uid'=>(int)$uid]; }
function af_wanted_thread_created($handler): void {global $mybb;$intent=af_wanted_intent_decode((string)($mybb->cookies['af_wanted_apply']??''));if(!$intent||(int)$intent['uid']!==(int)$mybb->user['uid'])return;$tid=(int)($handler->tid??$handler->data['tid']??0);if($tid>0)af_wanted_link_application((int)$intent['wanted_id'],$tid,(int)$mybb->user['uid']);my_unsetcookie('af_wanted_apply');}

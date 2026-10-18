<?php
/** Gallery maintenance: additive metadata, bounded reference audits, safe local paths. */
if (!defined('IN_MYBB')) die('No direct access');

function ag_cleanup_admin(): bool
{
    global $mybb;
    return defined('IN_ADMINCP') && IN_ADMINCP && (int)($mybb->user['uid']??0)>0 && !empty($mybb->usergroup['cancp']);
}

function ag_ensure_cleanup_schema(): void
{
    global $db;
    $collation=method_exists($db,'build_create_table_collation')?$db->build_create_table_collation():' DEFAULT CHARSET=utf8mb4';
    foreach (['cleanup_protected'=>'TINYINT NOT NULL DEFAULT 0','usage_flags'=>'INT UNSIGNED NOT NULL DEFAULT 0','usage_checked_at'=>'INT UNSIGNED NOT NULL DEFAULT 0'] as $name=>$definition) {
        if (!$db->field_exists($name,'af_gallery_media')) $db->write_query('ALTER TABLE '.TABLE_PREFIX.'af_gallery_media ADD '.$name.' '.$definition);
    }
    if (!$db->table_exists('af_gallery_cleanup_jobs')) $db->write_query('CREATE TABLE '.TABLE_PREFIX."af_gallery_cleanup_jobs (
        token VARCHAR(32) NOT NULL PRIMARY KEY, uid_actor INT NOT NULL, uid_owner INT NOT NULL, ids_json TEXT NOT NULL,
        processed INT UNSIGNED NOT NULL DEFAULT 0, created_at INT UNSIGNED NOT NULL, expires_at INT UNSIGNED NOT NULL,
        confirmed TINYINT NOT NULL DEFAULT 0, allow_linked TINYINT NOT NULL DEFAULT 0, allow_protected TINYINT NOT NULL DEFAULT 0,
        allow_unknown TINYINT NOT NULL DEFAULT 0, result_json TEXT NOT NULL, KEY expires_at (expires_at)
    ) ENGINE=InnoDB".$collation);
    if (!$db->table_exists('af_gallery_audits')) $db->write_query('CREATE TABLE '.TABLE_PREFIX."af_gallery_audits (
        uid_owner INT NOT NULL PRIMARY KEY, token VARCHAR(32) NOT NULL, state VARCHAR(16) NOT NULL,
        cursor_json MEDIUMTEXT NOT NULL, started_at INT UNSIGNED NOT NULL, finished_at INT UNSIGNED NOT NULL DEFAULT 0,
        max_media_id INT UNSIGNED NOT NULL DEFAULT 0, partial TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB".$collation);
}

/** Only Gallery-owned files, never an arbitrary DB path or a symlink below the storage root. */
function ag_cleanup_local_path(string $path,int $uid): string
{
    if ($path==='') return '';
    if ($uid<=0 || preg_match('~[\\\\\x00]|(?:^|/)\.\.(?:/|$)~',$path)) throw new RuntimeException('Недопустимый путь файла Gallery.');
    $root=rtrim(af_ag_resolve_upload_path(),'/').'/af_gallery';
    $root=preg_replace('~/+~','/',str_replace('/./','/',$root));
    $absolute=preg_replace('~/+~','/',str_replace('/./','/',af_ag_abs_path($path)));
    if (is_link($root)) throw new RuntimeException('Каталог Gallery не может быть символической ссылкой.');
    $prefix=$root.'/'.$uid.'/';
    if (!str_starts_with($absolute,$prefix)) throw new RuntimeException('Файл находится вне каталога владельца Gallery.');
    $current=$root;
    foreach (explode('/',substr($absolute,strlen($root)+1)) as $part) {
        $current.='/'.$part;
        if (is_link($current)) throw new RuntimeException('Удаление через символическую ссылку запрещено.');
    }
    if (file_exists($absolute)) {
        $realRoot=realpath($root);$real=realpath($absolute);
        if (!$realRoot || !$real || !str_starts_with($real,$realRoot.'/'.$uid.'/') || !is_file($real)) throw new RuntimeException('Недопустимый локальный файл Gallery.');
        if (!is_writable(dirname($real))) throw new RuntimeException('Каталог файла Gallery недоступен для удаления.');
    }
    return $absolute;
}

function ag_cleanup_log(int $owner,int $id,string $action,array $details=[]): void
{
    global $db,$mybb;
    $db->insert_query('af_gallery_logs',['uid_actor'=>(int)$mybb->user['uid'],'uid_owner'=>$owner,'media_id'=>$id,
        'action'=>$db->escape_string($action),'details'=>$db->escape_string(json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),'created_at'=>TIME_NOW]);
}

/** Source names/columns come exclusively from database metadata, never from request input. */
function ag_cleanup_sources(): array
{
    global $db;
    $sources=[];$partial=false;
    foreach ($db->list_tables($db->database,TABLE_PREFIX) as $full) {
        if (!str_starts_with($full,TABLE_PREFIX)) continue;
        $table=substr($full,strlen(TABLE_PREFIX));
        if (!preg_match('/^[a-zA-Z0-9_]+$/D',$table)) continue;
        if (!in_array($table,['users','userfields','posts'],true) && !str_starts_with($table,'af_')) continue;
        // Memberships, telemetry and backups are not content references. Provider secrets never enter an audit.
        if (str_starts_with($table,'af_gallery_') || preg_match('/(?:_logs?|_ledger|_cache|_history|_sessions?|_events?)$/',$table)) continue;
        $fields=[];$pk=[];
        $q=$db->write_query('SHOW COLUMNS FROM `'.TABLE_PREFIX.$table.'`');
        while ($column=$db->fetch_array($q)) {
            $name=$column['Field'];
            if (!preg_match('/^[a-zA-Z0-9_]+$/D',$name)) continue;
            if ($column['Key']==='PRI') {$pk[]=$name;}
            if ($table==='users' && !in_array($name,['avatar','signature'],true)) continue;
            if ($table==='posts' && $name!=='message') continue;
            if (preg_match('/(?:char|text|json)/i',$column['Type']) || preg_match('/(?:^|_)media_id$/D',$name)) $fields[]=$name;
        }
        if (!$fields) continue;
        if (!$pk) {$partial=true;continue;}
        $columns=implode(',',array_map(static fn($name)=>'`'.$name.'`',$pk));
        $last=$db->fetch_array($db->write_query('SELECT '.$columns.' FROM `'.TABLE_PREFIX.$table.'` ORDER BY '.implode(',',array_map(static fn($name)=>'`'.$name.'` DESC',$pk)).' LIMIT 1'));
        // These consumers use Gallery IDs. Other addons' numeric media IDs may belong to another catalog.
        $galleryIds=in_array($table,['af_aa_presets','af_aa_assignments','af_asb_signatures','af_apui_signature_builder'],true);
        $sources[]=['table'=>$table,'fields'=>$fields,'pk'=>$pk,'gallery_ids'=>$galleryIds,'end_key'=>$last?array_map(static fn($name)=>$last[$name],$pk):[]];
    }
    return ['sources'=>$sources,'partial'=>$partial];
}

function ag_cleanup_audit(int $uid): ?array
{
    global $db;
    return $db->fetch_array($db->simple_select('af_gallery_audits','*','uid_owner='.$uid))?:null;
}

function ag_cleanup_start(int $uid): array
{
    global $db;
    $registry=ag_cleanup_sources();$token=bin2hex(random_bytes(16));
    $max=(int)$db->fetch_field($db->simple_select('af_gallery_media','MAX(id) AS n','uid_owner='.$uid),'n');
    $db->write_query('START TRANSACTION');
    try {
        $db->delete_query('af_gallery_audits','uid_owner='.$uid);
        $db->insert_query('af_gallery_audits',['uid_owner'=>$uid,'token'=>$token,'state'=>'running','cursor_json'=>$db->escape_string(json_encode(['sources'=>$registry['sources'],'at'=>0,'after'=>[]])),
            'started_at'=>TIME_NOW,'finished_at'=>0,'max_media_id'=>$max,'partial'=>(int)$registry['partial']]);
        $db->update_query('af_gallery_media',['usage_flags'=>0,'usage_checked_at'=>0],'uid_owner='.$uid);
        $db->write_query('COMMIT');
    } catch (Throwable $e) {$db->write_query('ROLLBACK');throw $e;}
    return ag_cleanup_audit($uid);
}

/** Extract explicit ID keys and literal image URLs; no remote HTTP requests or filesystem scan. */
function ag_cleanup_tokens($value,string $key,int $category,array &$tokens,int $depth=0): void
{
    if ($depth>30) return;
    if (preg_match('/avatar/i',$key)) $category=1;
    if (is_array($value)) {
        foreach ($value as $k=>$v) ag_cleanup_tokens($v,$key.'.'.$k,$category,$tokens,$depth+1);
        return;
    }
    if (preg_match('/(?:^|[_.])(?:[a-z_]*media_id|media_ids\.\d+)$/i',$key) && is_numeric($value) && (int)$value>0) $tokens[$category]['ids'][(int)$value]=true;
    if (!is_string($value) || $value==='') return;
    $decoded=json_decode($value,true);
    if (is_array($decoded)) {ag_cleanup_tokens($decoded,$key,$category,$tokens,$depth+1);return;}
    $text=html_entity_decode(str_replace('\\/','/',$value),ENT_QUOTES|ENT_HTML5,'UTF-8');
    preg_match_all('~https?://[^\s<>"\x27\[\]{}()\\\\]+|(?:\./|/)?(?:[a-zA-Z0-9_.%-]+/)*af_gallery/[^\s<>"\x27\[\]{}()\\\\]+~i',$text,$matches);
    global $mybb;
    $base=rtrim((string)$mybb->settings['bburl'],'/').'/';
    foreach ($matches[0] as $url) {
        $url=rtrim($url,',;.');
        $tokens[$category]['urls'][$url]=true;
        if (str_starts_with($url,$base)) {
            $relative=rawurldecode(explode('?',explode('#',substr($url,strlen($base)))[0])[0]);
            $relative=ltrim($relative,'./');
            $tokens[$category]['urls'][$relative]=true;
            $tokens[$category]['urls']['./'.$relative]=true;
        }
        if (!preg_match('~^https?://~i',$url)) {
            $relative=ltrim(rawurldecode(explode('?',explode('#',$url)[0])[0]),'./');
            $tokens[$category]['urls'][$relative]=true;
            $tokens[$category]['urls']['./'.$relative]=true;
        }
    }
}

function ag_cleanup_match_rows(int $uid,array $source,array $rows,?array $onlyIds=null,bool $persist=true): array
{
    global $db;
    $tokens=[];$found=[];
    foreach ($rows as $row) foreach ($source['fields'] as $field) {
        $category=($source['table']==='posts' && !empty($row['_application'])) || preg_match('/character|sheet|application|questionnaire/i',$source['table']) || str_contains((string)($row['target_key']??''),'sheet')?2:4;
        if (str_contains((string)($row['field_key']??''),'avatar') || preg_match('/^mask_(?:topbar|sidebar)_media_id$/D',$field)) $category=1;
        ag_cleanup_tokens($row[$field]??null,$field,$category,$tokens);
    }
    foreach ($tokens as $category=>$values) {
        $ids=!empty($source['gallery_ids'])?array_keys($values['ids']??[]):[];$urls=array_keys($values['urls']??[]);
        foreach (array_chunk(array_merge(array_map(static fn($id)=>['id',$id],$ids),array_map(static fn($url)=>['url',$url],$urls)),100) as $chunk) {
            $parts=[];
            foreach ($chunk as [$kind,$value]) {
                if ($kind==='id') $parts[]='id='.(int)$value;
                else {
                    $literal="'".$db->escape_string($value)."'";
                    $parts[]='remote_url='.$literal.' OR storage_path='.$literal.' OR thumb_path='.$literal.' OR preview_path='.$literal;
                }
            }
            $where='uid_owner='.$uid.' AND ('.implode(' OR ',$parts).')';
            if ($onlyIds!==null) $where.=' AND id IN ('.implode(',',array_map('intval',$onlyIds)).')';
            $q=$db->simple_select('af_gallery_media','id',$where);
            while ($m=$db->fetch_array($q)) $found[(int)$m['id']]=($found[(int)$m['id']]??0)|$category;
        }
    }
    if ($persist) foreach ($found as $id=>$flags) $db->write_query('UPDATE '.TABLE_PREFIX.'af_gallery_media SET usage_flags=usage_flags|'.(int)$flags.' WHERE id='.$id.' AND uid_owner='.$uid);
    return $found;
}

function ag_cleanup_source_query(array $source,string $where,int $limit,int $offset=0): string
{
    global $db;
    $table=$source['table'];
    $extra=$table==='posts' && $db->table_exists('af_character_workflow')?',EXISTS(SELECT 1 FROM '.TABLE_PREFIX.'af_character_workflow cw WHERE cw.tid=s.tid) AS _application':'';
    return 'SELECT s.*'.$extra.' FROM `'.TABLE_PREFIX.$table.'` s WHERE '.$where.' ORDER BY '.implode(',',array_map(static fn($pk)=>'s.`'.$pk.'`',$source['pk'])).' LIMIT '.$offset.','.$limit;
}

/** Lexicographic primary-key paging also handles APF's composite (uid, field_key) key. */
function ag_cleanup_key_condition(array $source,array $values,string $operator): string
{
    global $db;
    $columns=implode(',',array_map(static fn($name)=>'s.`'.$name.'`',$source['pk']));
    $literals=implode(',',array_map(static fn($value)=>"'".$db->escape_string((string)$value)."'",$values));
    return '('.$columns.')'.$operator.'('.$literals.')';
}

function ag_cleanup_step(int $uid,string $token): array
{
    global $db;
    $db->write_query('START TRANSACTION');
    try {
        $audit=$db->fetch_array($db->write_query('SELECT * FROM '.TABLE_PREFIX.'af_gallery_audits WHERE uid_owner='.$uid.' FOR UPDATE'));
        if (!$audit || !hash_equals($audit['token'],$token)) throw new RuntimeException('Проверка заменена другой сессией.');
        $cursor=json_decode($audit['cursor_json'],true);
        if ($audit['state']!=='running') {$db->write_query('COMMIT');return ['done'=>true,'state'=>$audit['state']];}
        $source=$cursor['sources'][$cursor['at']]??null;
        if ($source) {
            if (!$db->table_exists($source['table'])) {$audit['partial']=1;$rows=[];}
            else {
                $where=empty($source['end_key'])?'0=1':ag_cleanup_key_condition($source,$source['end_key'],'<=');
                if (!empty($cursor['after'])) $where.=' AND '.ag_cleanup_key_condition($source,$cursor['after'],'>');
                $q=$db->write_query(ag_cleanup_source_query($source,$where,100));
                $rows=[];while ($row=$db->fetch_array($q)) $rows[]=$row;
                ag_cleanup_match_rows($uid,$source,$rows);
            }
            if (count($rows)<100) {$cursor['at']++;$cursor['after']=[];}
            else {
                $last=end($rows);
                $cursor['after']=array_map(static fn($name)=>$last[$name],$source['pk']);
            }
        }
        $done=$cursor['at']>=count($cursor['sources']);
        $data=['cursor_json'=>$db->escape_string(json_encode($cursor)),'partial'=>(int)$audit['partial']];
        if ($done) {
            $data['state']=$audit['partial']?'partial':'complete';$data['finished_at']=TIME_NOW;
            if (!$audit['partial']) $db->update_query('af_gallery_media',['usage_checked_at'=>(int)$audit['started_at']],'uid_owner='.$uid.' AND id<='.(int)$audit['max_media_id']);
        }
        $db->update_query('af_gallery_audits',$data,'uid_owner='.$uid);
        $db->write_query('COMMIT');
        return ['done'=>$done,'state'=>$data['state']??'running','source'=>$source['table']??'','sources_done'=>$cursor['at'],'sources_total'=>count($cursor['sources'])];
    } catch (Throwable $e) {$db->write_query('ROLLBACK');throw $e;}
}

/** Fresh pre-delete check: bounded returned rows; too many matches remain unverified. */
function ag_cleanup_probe(int $uid,array $media): array
{
    global $db,$mybb;
    $audit=ag_cleanup_audit($uid);
    $registry=ag_cleanup_sources();
    $found=[];$unknown=$registry['partial'] || !$audit || $audit['state']!=='complete';$ids=array_map('intval',array_column($media,'id'));
    foreach ($registry['sources']??[] as $source) {
        if (!$db->table_exists($source['table'])) {$unknown=true;continue;}
        $conditions=[];
        foreach ($source['fields'] as $field) {
            if (preg_match('/(?:^|_)media_id$/D',$field)) {if (empty($source['gallery_ids'])) continue;$conditions[]='s.`'.$field.'` IN ('.implode(',',$ids).')';continue;}
            foreach ($media as $m) {
                $needles=[];
                foreach ([$m['remote_url'],$m['storage_path'],$m['thumb_path'],$m['preview_path']] as $saved) {
                    if ($saved==='') continue;
                    $needles[]=$saved;
                    if (!preg_match('~^https?://~i',$saved)) $needles[]=ltrim($saved,'./');
                }
                foreach (array_unique($needles) as $url) {
                    if ($url==='') continue;
                    // Search a literal path too, so same-site absolute and relative URLs are both covered.
                    $needle=str_replace('/','\\/',(string)$url);
                    $conditions[]='s.`'.$field.'` LIKE \'%'.$db->escape_string_like((string)$url).'%\'';
                    if ($needle!==$url) $conditions[]='s.`'.$field.'` LIKE \'%'.$db->escape_string_like($needle).'%\'';
                }
            }
            $pattern='(^|[^0-9])('.implode('|',$ids).')([^0-9]|$)';
            if (!empty($source['gallery_ids'])) $conditions[]='(s.`'.$field.'` LIKE \'%media_id%\' AND s.`'.$field.'` REGEXP \''.$db->escape_string($pattern).'\')';
        }
        if (!$conditions) continue;
        $q=$db->write_query(ag_cleanup_source_query($source,'('.implode(' OR ',$conditions).')',101));
        $rows=[];while ($row=$db->fetch_array($q)) $rows[]=$row;
        if (count($rows)>100) $unknown=true;
        foreach (ag_cleanup_match_rows($uid,$source,$rows,$ids) as $id=>$flags) $found[$id]=($found[$id]??0)|$flags;
    }
    return ['flags'=>$found,'unknown'=>$unknown];
}

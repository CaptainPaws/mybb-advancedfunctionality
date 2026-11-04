<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../Application/AuthorizationService.php';

/** Explicit ACP-only observations. Never executes handlers, loads plugins or probes writes. */
final class AF_ACS_PublicationDiagnostics
{
    private const REFERENCE = [
        'inc/datahandlers/post.php'=>'9d7a5aee7cf100bff0cbd918934b16524f3d556e94ced4719bef97622c6a5d13',
        'newreply.php'=>'affb30736516ac15f7597424b27df39c074093bbed8922e54e121bda9fdc4f43',
        'inc/db_mysqli.php'=>'99f7077ef3dd5c2032e9a690dfd50f4b707147d7ec43621ed5bc875cb8edde4a',
        'inc/class_core.php'=>'6fe9f32d470218e4fc0b15e9631007d9108c1e2cae53f8abf1862ec771488d7b',
    ];
    private static function row(string $name,string $status,string $detail):array { return compact('name','status','detail'); }
    private static function identifier(mixed $value):string
    {
        return is_string($value)&&strlen($value)<=180&&preg_match('/\A[a-zA-Z_\\\\][a-zA-Z0-9_\\\\:>\-]*\z/D',$value)?$value:'[неименованный callback]';
    }
    /** Hide SQL errors, never include exception messages, connection objects or configuration. */
    private static function select(object $db,string $sql,bool $writer=false):?array
    {
        try {
            $q=$db->query($sql,1,$writer?1:0);
            if(!$q)return null;
            $rows=[];
            while($r=$db->fetch_array($q))$rows[]=$r;
            if(method_exists($db,'free_result'))$db->free_result($q);
            return $rows;
        } catch(Throwable $e) { return null; }
    }
    public function collect():array
    {
        (new AF_ACS_AuthorizationService)->requirePermission('admin.diagnostics.view');
        global $mybb,$db,$plugins;
        $report=['report_version'=>1,'scope'=>'Текущий ACP bootstrap; не трассировка newreply/Quick Edit. Регистрация не доказывает исполнение.',
            'publication'=>self::row('IC-MYBB-1','BLOCKED','Координируемая публикация с восстановлением по ACS Stage 4 Revision 2 ещё не реализована. Диагностика не разрешает публикацию.'),'mybb'=>[],'database'=>[],'tables'=>[],'triggers'=>[],'hooks'=>[],'integrations'=>[]];
        $v=(string)($mybb->version??'');
        $report['mybb'][]=self::row('MyBB', $v==='1.8.41'?'PASS':'WARNING',preg_match('/\A[0-9.]{1,20}\z/D',$v)?$v:'Версия не распознана');
        $report['mybb'][]=self::row('PHP','PASS',PHP_VERSION);
        $report['mybb'][]=self::row('DB driver','NOT_VERIFIED',self::identifier(is_object($db)?get_class($db):'unavailable'));
        $report['mybb'][]=self::row('PostDataHandler',is_file(MYBB_ROOT.'inc/datahandlers/post.php')?'NOT_VERIFIED':'BLOCKED',is_file(MYBB_ROOT.'inc/datahandlers/post.php')?'Штатный путь существует; файл не подключается и handler не вызывается.':'Файл штатного handler отсутствует.');
        foreach(self::REFERENCE as $file=>$expected){
            $path=MYBB_ROOT.$file;$actual=is_file($path)&&is_readable($path)?@hash_file('sha256',$path):false;
            $report['mybb'][]=self::row($file,$actual===false?'BLOCKED':($actual===$expected?'PASS':'WARNING'), 'SHA256: '.($actual?:'недоступен').'; upstream 1.8.41 (не installed): '.$expected);
        }
        $required=['posts','threads','forums','users','threadsubscriptions','attachments','mailqueue','privatemessages','moderatorlog','datacache'];
        $tables=['posts','threads','forums','users','threadsubscriptions','attachments','mailqueue','privatemessages','moderatorlog','datacache','af_balance','af_balance_tx','af_post_rewards','af_cs_sheets','aam_alerts','af_aa_assignments','af_gallery_media'];
        // Current ledger definitions only; requiring declarative arrays does not run migrations.
        foreach(glob(__DIR__.'/../../migrations/[0-9][0-9][0-9]_*.php') as $file){
            $migration=require $file;
            foreach(array_keys($migration['tables']??[]) as $name){$tables[]='af_acs_'.$name;$required[]='af_acs_'.$name;}
        }
        $tables=array_values(array_unique($tables));
        if(is_object($db)&&isset($db->table_prefix)&&preg_match('/\A[a-zA-Z0-9_]*\z/D',$db->table_prefix)){
            $read=self::select($db,'SELECT VERSION() AS version, CONNECTION_ID() AS connection_id');
            $write=self::select($db,'SELECT VERSION() AS version, CONNECTION_ID() AS connection_id',true);
            $version=$write[0]['version']??null;
            $report['database'][]=self::row('MySQL/MariaDB',$version?'PASS':'NOT_VERIFIED',is_string($version)?substr(preg_replace('/[^a-zA-Z0-9. +_\-]/','',$version),0,100):'Нет metadata-доступа');
            $same=isset($read[0]['connection_id'],$write[0]['connection_id'])&&$read[0]['connection_id']===$write[0]['connection_id'];
            // Only object identity plus server connection IDs; no host, user, password or IDs exported.
            $links=isset($db->read_link,$db->write_link)&&$db->read_link===$db->write_link;
            $report['database'][]=self::row('Read/write connections',$same&&$links?'PASS':'NOT_VERIFIED',$same&&$links?'Один handle и connection ID в текущем запросе. Это не доказательство routing всех вложенных handlers.':'Единственное соединение не подтверждено; нужны routing/read-your-writes и writer-аудит.');
            $names=array_map(fn($n)=>"'".$db->table_prefix.$n."'",$tables);
            $where=' IN ('.implode(',',$names).')';
            $metadata=self::select($db,'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME'.$where,true);
            $engines=[];foreach($metadata??[] as $r)$engines[$r['TABLE_NAME']]=$r['ENGINE'];
            foreach($tables as $name){$engine=$engines[$db->table_prefix.$name]??null;
                $report['tables'][]=self::row($name,$metadata===null?'NOT_VERIFIED':($engine===null?(in_array($name,$required,true)?'BLOCKED':'WARNING'):(!str_starts_with($name,'af_acs_')||strtoupper($engine)==='INNODB'?'PASS':'BLOCKED')), $metadata===null?'Нет metadata-доступа':($engine===null?'Не обнаружена; для optional owner/future ветки сверить применимость.':'Engine: '.self::identifier($engine).(str_starts_with($name,'af_acs_')?'; ACS требует InnoDB.':'; Revision 2 не требует миграции MyBB.')));
            }
            $triggers=self::select($db,'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE'.$where,true);
            if($triggers===null)$report['triggers'][]=self::row('Triggers','NOT_VERIFIED','Нет metadata-доступа.');
            else {
                $report['triggers'][]=self::row('Видимые triggers','NOT_VERIFIED','Найдено: '.count($triggers).'. Пустой результат не доказывает отсутствие: видимость зависит от TRIGGER privilege. Тела не читаются.');
                foreach($triggers as $r){$logical=substr($r['EVENT_OBJECT_TABLE'],strlen($db->table_prefix));
                    $report['triggers'][]=self::row('trigger:'.substr(hash('sha256',$r['TRIGGER_NAME']),0,16),'WARNING',$logical.' / '.self::identifier($r['ACTION_TIMING']).' / '.self::identifier($r['EVENT_MANIPULATION']).'; необходим приватный аудит тела.');}
            }
        } else $report['database'][]=self::row('Metadata','NOT_VERIFIED','DB adapter/prefix не поддержан; запросы не выполнялись.');
        foreach(($plugins->hooks??[]) as $hook=>$priorities){
            if(!preg_match('/\A(?:datahandler_(?:post|pm)_|newreply_|newthread_|editpost_|post_|moderation_|xmlhttp(?:_|$)|global_end|shutdown)/',$hook))continue;
            foreach($priorities as $priority=>$callbacks)foreach($callbacks as $entry){
                $cb=$entry['function']??$entry['class_method']??null;$source=$entry['file']??'';$name='[неименованный callback]';
                try {
                    if(is_string($cb)){$name=self::identifier($cb);if(function_exists($cb))$source=(new ReflectionFunction($cb))->getFileName()?:$source;}
                    elseif(is_array($cb)&&count($cb)===2){$class=is_object($cb[0])?get_class($cb[0]):$cb[0];$name=self::identifier($class.'::'.$cb[1]);if((is_object($cb[0])||class_exists($class,false))&&method_exists($class,$cb[1]))$source=(new ReflectionMethod($class,$cb[1]))->getFileName()?:$source;}
                }catch(Throwable $e){}
                $owner='не определён';$normalized=str_replace('\\','/',(string)$source);
                if(preg_match('~/inc/plugins/advancedfunctionality/addons/([a-z0-9_]+)/~',$normalized,$m))$owner=$m[1];
                elseif(preg_match('~/inc/plugins/([a-z0-9_]+)\.php$~',$normalized,$m))$owner='plugin:'.$m[1];
                $report['hooks'][]=['hook'=>self::identifier($hook),'callback'=>$name,'priority'=>(int)$priority,'owner'=>$owner];
            }
        }
        usort($report['hooks'],fn($a,$b)=>[$a['hook'],$a['priority'],$a['callback']]<=>[$b['hook'],$b['priority'],$b['callback']]);
        $risks=[
            'advancedappearance'=>['AdvancedAppearance','Требуется joinable owner writer без собственного START/COMMIT и outcome-aware cache.'],
            'balance'=>['Balance','Нужны writer/read-your-writes, locks и receipts начислений.'],
            'charactersheets'=>['CharacterSheets','Нужен аудит Balance→EXP→progress записи и lost updates.'],
            'advancedpostcounter'=>['AdvancedPostCounter','Проверить users engine, counters и shutdown rebuild после outcome.'],
            'advancedalertsandmentions'=>['AAM','Нужны outcome-aware alerts/cache и идемпотентная доставка.'],
            'smarturltitles'=>['SmartURLTitles','Сетевые transformations завершать до transaction и фиксации prose.'],
        ];
        foreach($risks as $id=>[$label,$reason]){
            $detected=is_file(__DIR__.'/../../../'.$id.'/'.$id.'.php');$enabled=function_exists('af_is_addon_enabled')?af_is_addon_enabled($id):null;
            $report['integrations'][]=self::row($label,$enabled?'WARNING':'NOT_VERIFIED','Файл: '.($detected?'есть':'нет').'; enabled: '.($enabled===null?'неизвестно':($enabled?'да':'нет')).'. '.$reason.' Текущая регистрация не доказывает безопасное исполнение.');
        }
        $report['integrations'][]=self::row('MyBB cache/shutdown','NOT_VERIFIED','Backend и внешние side effects не трассировались. Нужна проверка восстановления координируемой публикации; конфигурация не экспортируется.');
        $report['integrations'][]=self::row('ACS coordinator','BLOCKED','Внутренний bounded Gateway проверен upstream fixtures; installed binding и publication coordinator/recovery не подтверждены. Наличие класса не разрешает публикацию.');
        return $report;
    }
    public static function render():void
    {
        $report=(new self)->collect();$esc=fn($v)=>htmlspecialchars_uni((string)$v);
        echo '<h2>Публикация → Совместимость</h2><p>'.$esc($report['scope']).'</p><p>IC-MYBB-1: BLOCKED. Только read-only metadata; тестовые посты и rollback probes не выполняются.</p>';
        $json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
        echo '<p><a class="button" download="acs-publication-compatibility.json" href="data:application/json;base64,'.base64_encode($json).'">Экспорт обезличенного технического отчёта</a></p>';
        foreach(['mybb'=>'MyBB','database'=>'База данных','tables'=>'Таблицы: известный write set и текущая схема ACS','triggers'=>'Triggers','integrations'=>'Интеграции'] as $key=>$title){
            echo '<h3>'.$esc($title).'</h3><table class="general"><tr><th>Проверка</th><th>Статус</th><th>Сведения</th></tr>';
            foreach($report[$key] as $r)echo '<tr><td>'.$esc($r['name']).'</td><td>'.$esc($r['status']).'</td><td>'.$esc($r['detail']).'</td></tr>';
            echo '</table>';
        }
        echo '<h3>Зарегистрированные hooks текущего bootstrap</h3><table class="general"><tr><th>Hook</th><th>Callback</th><th>Priority</th><th>Addon/plugin</th></tr>';
        foreach($report['hooks'] as $r)echo '<tr><td>'.$esc($r['hook']).'</td><td>'.$esc($r['callback']).'</td><td>'.$esc($r['priority']).'</td><td>'.$esc($r['owner']).'</td></tr>';
        echo '</table><p>Нужен отдельный аудит newreply/Quick Edit. Пустой список в ACP не доказывает отсутствие hooks публикации.</p>';
    }
}

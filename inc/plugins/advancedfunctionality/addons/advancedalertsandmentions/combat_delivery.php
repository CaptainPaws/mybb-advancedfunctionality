<?php
if(!defined('IN_MYBB')){die('No direct access');}

/** Code-only authority must bind the exact durable producer envelope, never HTTP fields. */
interface AF_AAM_DeliveryAuthority { public function authorize(array $message):bool; }

final class AF_AAM_CombatDelivery
{
    public const TYPES=['acs_turn'=>'Доступен ход','acs_deadline'=>'Скоро срок хода','acs_missed'=>'Ход пропущен','acs_combat'=>'Бой: атаки и реакции','acs_round'=>'Новый раунд','acs_completed'=>'Бой завершён','acs_reward'=>'Награда подтверждена','acs_publication'=>'Проблема публикации','acs_gm'=>'Важное действие GM'];
    private mysqli $link;
    private string $prefix;
    private bool $checked=false;
    public function __construct(object $database,private AF_AAM_DeliveryAuthority $authority)
    {
        $this->link=$database instanceof mysqli?$database:($database->write_link??throw new RuntimeException('AAM_WRITER_REQUIRED'));
        $this->prefix=(string)TABLE_PREFIX;if(!preg_match('/\A[A-Za-z0-9_]*\z/D',$this->prefix))throw new RuntimeException('AAM_PREFIX_INVALID');
    }
    public static function json(array $value):string
    {
        $normalize=function($v)use(&$normalize){if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as &$x)$x=$normalize($x);unset($x);return $v;};
        return json_encode($normalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    public static function validate(array $m):void
    {
        $fields=['operation_key','payload_hash','recipient_uid','event_identity','type','payload'];
        if(array_diff(array_keys($m),$fields)||array_diff($fields,array_keys($m))||!is_array($m['payload'])||array_diff(array_keys($m['payload']),['session_id','tid','gm_only'])||array_diff(['session_id','tid','gm_only'],array_keys($m['payload']))||!is_bool($m['payload']['gm_only']??null))throw new RuntimeException('AAM_MESSAGE_INVALID');
        foreach(['operation_key','payload_hash','recipient_uid','event_identity','type'] as $k)if(!is_string($m[$k]))throw new RuntimeException('AAM_MESSAGE_INVALID');
        foreach([$m['recipient_uid'],$m['payload']['session_id'],$m['payload']['tid']] as $n)if(!is_string($n)||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$n))throw new RuntimeException('AAM_MESSAGE_INVALID');
        if(strlen($m['recipient_uid'])>10||strlen($m['payload']['tid'])>10||(strlen($m['recipient_uid'])===10&&strcmp($m['recipient_uid'],'4294967295')>0)||(strlen($m['payload']['tid'])===10&&strcmp($m['payload']['tid'],'4294967295')>0)||!isset(self::TYPES[$m['type']])||!preg_match('/\Aacs:alert:[a-f0-9]{64}\z/D',$m['operation_key'])||!preg_match('/\Aacs:[a-z0-9:._-]{1,160}\z/D',$m['event_identity']))throw new RuntimeException('AAM_MESSAGE_INVALID');
        $body=$m;unset($body['payload_hash']);if(!hash_equals(hash('sha256',self::json($body)),$m['payload_hash']))throw new RuntimeException('AAM_PAYLOAD_CONFLICT');
        if($m['operation_key']!=='acs:alert:'.hash('sha256',$m['event_identity'].':'.$m['recipient_uid'].':'.$m['type']))throw new RuntimeException('AAM_IDENTITY_CONFLICT');
    }
    private function sql(string $query,array $args=[]):array
    {
        $s=$this->link->prepare($query);if(!$s)throw new RuntimeException('AAM_DATABASE_UNAVAILABLE');
        try{
            if($args&&!$s->bind_param(str_repeat('s',count($args)),...$args))throw new RuntimeException('AAM_DATABASE_UNAVAILABLE');
            if(!$s->execute())throw new RuntimeException('AAM_DATABASE_UNAVAILABLE');
            $r=$s->get_result();return ['rows'=>$r?$r->fetch_all(MYSQLI_ASSOC):[],'id'=>(string)$s->insert_id];
        }finally{$s->close();}
    }
    private function ready():void
    {
        if(!function_exists('af_aam_is_enabled')||!af_aam_is_enabled())throw new RuntimeException('AAM_UNAVAILABLE');
        if($this->checked)return;
        $rows=$this->sql('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?,?)',[$this->prefix.'aam_alerts',$this->prefix.'aam_alert_types',$this->prefix.'aam_delivery_receipts'])['rows'];
        if(count($rows)!==3)throw new RuntimeException('AAM_DELIVERY_NOT_INSTALLED');foreach($rows as $r)if(strcasecmp($r['ENGINE'],'InnoDB')!==0)throw new RuntimeException('AAM_TRANSACTIONAL_STORAGE_REQUIRED');
        $index=$this->sql("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX",[$this->prefix.'aam_delivery_receipts'])['rows'];
        if(array_column($index,'COLUMN_NAME')!==['operation_key'])throw new RuntimeException('AAM_DELIVERY_SCHEMA_INVALID');$this->checked=true;
    }
    private function receipt(array $row,array $m):array
    {
        if(!hash_equals($row['payload_hash'],$m['payload_hash'])||(string)$row['uid']!==$m['recipient_uid']||$row['event_identity']!==$m['event_identity']||$row['type_code']!==$m['type'])throw new RuntimeException('AAM_PAYLOAD_CONFLICT');
        $r=json_decode($row['receipt_json'],true,16,JSON_THROW_ON_ERROR);
        if(!hash_equals($row['receipt_hash'],hash('sha256',self::json($r)))||($r['operation_key']??null)!==$m['operation_key']||($r['payload_hash']??null)!==$m['payload_hash']||($r['recipient_uid']??null)!==$m['recipient_uid']||($r['event_identity']??null)!==$m['event_identity']||($r['type']??null)!==$m['type']||!in_array($r['status']??null,['delivered','skipped'],true))throw new RuntimeException('AAM_RECEIPT_INVALID');return $r;
    }
    public function lookup(array $m):?array
    {
        self::validate($m);$this->ready();$this->authority->authorize($m);
        $r=$this->sql('SELECT * FROM `'.$this->prefix.'aam_delivery_receipts` WHERE operation_key=?',[$m['operation_key']])['rows'][0]??null;return $r?$this->receipt($r,$m):null;
    }
    public function deliver(array $m):array
    {
        self::validate($m);$this->ready();$allowed=$this->authority->authorize($m);
        // SET TRANSACTION refuses an ambient transaction, unlike START TRANSACTION.
        $autocommit=$this->link->query('SELECT @@session.autocommit');
        if(!$autocommit||(string)$autocommit->fetch_row()[0]!=='1')throw new RuntimeException('AAM_AMBIENT_TRANSACTION');
        if(!$this->link->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'))throw new RuntimeException('AAM_AMBIENT_TRANSACTION');$started=false;
        try{
            if(!$this->link->begin_transaction())throw new RuntimeException('AAM_TRANSACTION_UNAVAILABLE');$started=true;
            $this->sql('INSERT INTO `'.$this->prefix.'aam_delivery_receipts` (operation_key,payload_hash,uid,event_identity,type_code,receipt_json,receipt_hash,created_at) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE operation_key=operation_key',[$m['operation_key'],$m['payload_hash'],$m['recipient_uid'],$m['event_identity'],$m['type'],'','',time()]);
            $row=$this->sql('SELECT * FROM `'.$this->prefix.'aam_delivery_receipts` WHERE operation_key=? FOR UPDATE',[$m['operation_key']])['rows'][0];
            if($row['receipt_json']!==''){$r=$this->receipt($row,$m);$this->link->rollback();$started=false;return $r;}
            if(!hash_equals($row['payload_hash'],$m['payload_hash']))throw new RuntimeException('AAM_PAYLOAD_CONFLICT');
            $type=$this->sql('SELECT id,enabled,can_be_user_disabled,default_user_enabled FROM `'.$this->prefix.'aam_alert_types` WHERE code=?',[$m['type']])['rows'][0]??throw new RuntimeException('AAM_TYPE_NOT_INSTALLED');
            $user=$this->sql('SELECT uid,af_aam_disabled_types FROM `'.$this->prefix.'users` WHERE uid=?',[$m['recipient_uid']])['rows'][0]??null;
            $disabled=$user?json_decode($user['af_aam_disabled_types']?:'[]',true):[];
            $reason=!$allowed?'access_changed':(!$user?'recipient_missing':((string)$type['enabled']!=='1'?'type_disabled':((string)$type['can_be_user_disabled']==='1'&&is_array($disabled)&&in_array($m['type'],$disabled,true)?'user_disabled':null)));
            $id=null;
            if($reason===null)$id=$this->sql('INSERT INTO `'.$this->prefix.'aam_alerts` (uid,is_read,dateline,type_id,object_id,from_uid,forced,extra) VALUES (?,0,?,?,0,0,0,?)',[$m['recipient_uid'],time(),$type['id'],self::json(['acs'=>$m['payload']])])['id'];
            $receipt=['operation_key'=>$m['operation_key'],'payload_hash'=>$m['payload_hash'],'recipient_uid'=>$m['recipient_uid'],'event_identity'=>$m['event_identity'],'type'=>$m['type'],'status'=>$id===null?'skipped':'delivered','alert_id'=>$id,'reason'=>$reason];$json=self::json($receipt);
            $this->sql('UPDATE `'.$this->prefix.'aam_delivery_receipts` SET receipt_json=?,receipt_hash=? WHERE operation_key=?',[$json,hash('sha256',$json),$m['operation_key']]);
            if(!$this->link->commit())throw new RuntimeException('AAM_COMMIT_UNKNOWN');$started=false;return $receipt;
        }finally{if($started)$this->link->rollback();}
    }
}

/** Controlled owner install only. Never called by delivery, lookup, formatter or ACS GET. */
function af_aam_install_combat_delivery():void
{
    global $db;
    if(!$db->table_exists('aam_delivery_receipts'))$db->write_query('CREATE TABLE '.TABLE_PREFIX.'aam_delivery_receipts (operation_key varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,payload_hash char(64) CHARACTER SET ascii NOT NULL,uid int unsigned NOT NULL,event_identity varchar(180) CHARACTER SET ascii NOT NULL,type_code varchar(40) CHARACTER SET ascii NOT NULL,receipt_json text NOT NULL,receipt_hash char(64) CHARACTER SET ascii NOT NULL,created_at bigint NOT NULL,PRIMARY KEY(operation_key),KEY recipient(uid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    foreach(AF_AAM_CombatDelivery::TYPES as $code=>$title)af_aam_register_type($code,$title,1,1,1);
}

/** Native AAM read formatter. Current access is rechecked; old payload contains no narrative/IDs. */
function af_aam_format_combat_alert(array $alert,array $extra):array
{
    global $db,$mybb;
    $hidden=['text'=>'Боевое уведомление недоступно.','url'=>''];
    if(!af_is_addon_enabled('advancedcombatsystem')||!isset(AF_AAM_CombatDelivery::TYPES[$alert['code']??''])||(string)($alert['uid']??'')!==(string)($mybb->user['uid']??''))return $hidden;
    $p=$extra['acs']??[];if(!is_array($p)||!is_string($p['session_id']??null)||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$p['session_id']))return $hidden;
    try{
        require_once __DIR__.'/../advancedcombatsystem/modules/Infrastructure/Adapters/SessionAccess.php';
        $access=new AF_ACS_SessionAccess;$uid=$access->uid();
        $s=$db->fetch_array($db->write_query('SELECT id,tid,owner_uid FROM '.TABLE_PREFIX."af_acs_sessions WHERE id='".$db->escape_string($p['session_id'])."' LIMIT 1"));
        if(!$s||(string)$s['tid']!==(string)($p['tid']??''))return $hidden;$access->thread((string)$s['tid']);
        $q=$db->write_query('SELECT uid,role,expires_at FROM '.TABLE_PREFIX."af_acs_session_roles WHERE session_id='".$db->escape_string($p['session_id'])."' AND uid=".(int)$uid." AND (expires_at IS NULL OR expires_at>".time().")");$roles=[];while($role=$db->fetch_array($q))$roles[]=$role;
        if(!$roles||!empty($p['gm_only'])&&!AF_ACS_AuthorizationService::sessionGm($s,$roles,$uid))return $hidden;
        return ['text'=>AF_AAM_CombatDelivery::TYPES[$alert['code']].'. Откройте боевую панель для актуального состояния.','url'=>'showthread.php?tid='.(int)$s['tid']];
    }catch(Throwable){return $hidden;}
}

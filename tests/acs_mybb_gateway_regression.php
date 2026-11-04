<?php
declare(strict_types=1);
// Usage: php tests/acs_mybb_gateway_regression.php /tmp/acs222-socket/r2.sock /absolute/disposable/mybb_1841
// Reuses the real ACS seal fixture; upstream core is external and never written into the repository.
$core=$argv[2] ?? '';
if (!str_starts_with($core,'/') || !is_file($core.'/inc/datahandlers/post.php')) throw new RuntimeException('Supply a disposable upstream mybb_1841 tree');
putenv('ACS_MYBB_FIXTURE_ROOT='.$core);
define('THIS_SCRIPT','newreply.php');

// Bounded native environment fixtures. The PostDataHandler, parser and DB_MySQLi are upstream code.
function get_user($uid): array { return ['uid'=>$uid,'username'=>'Hero','lastpost'=>$GLOBALS['acsGatewayLastPost'] ?? 0]; }
function get_thread($tid): array { return $GLOBALS['acsGatewayTopic'] ?? ['tid'=>$tid,'fid'=>1,'uid'=>7,'visible'=>1,'subject'=>'Fixture','closed'=>0]; }
function is_moderator(...$args): bool { return false; }
function check_forum_password($fid): void {}
function user_permissions($uid): array { return ['cancp'=>0,'canpostattachments'=>1]; }
function trim_blank_chrs($v): string { return trim($v); }
function my_strlen($v): int { return mb_strlen((string)$v,'UTF-8'); }
function my_substr($v,$start,$length=null): string { return mb_substr($v,$start,$length,'UTF-8'); }
function my_strpos($a,$b) { return mb_strpos($a,$b,0,'UTF-8'); }
function validate_utf8_string($v,$supplementary=true): string { return (string)$v; }
function get_execution_time(): float { return microtime(true); }
function get_subscription_method($tid): string { return 'none'; }
function add_shutdown($callback,$arguments): void { $GLOBALS['acsGatewayShutdown'][]=[$callback,$arguments]; }
function is_member(...$args): bool { return false; }
function update_last_post(...$a): void { $GLOBALS['acsGatewayCounters'][]='lastpost'; }
function update_forum_counters(...$a): void { $GLOBALS['acsGatewayCounters'][]='forum'; }
function update_forum_lastpost(...$a): void { $GLOBALS['acsGatewayCounters'][]='forum_last'; }
function update_thread_counters(...$a): void { $GLOBALS['acsGatewayCounters'][]='thread'; }

$GLOBALS['acsPublicationFixture']=static function(array $f):void {
    extract($f);global $mybb,$db,$plugins,$cache,$lang,$session;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/Adapters/MybbPostGateway.php';
    require_once MYBB_ROOT.'inc/datahandler.php';
    require_once MYBB_ROOT.'inc/db_base.php';
    require_once MYBB_ROOT.'inc/db_mysqli.php';
    if (!defined('TIME_NOW')) define('TIME_NOW',time());
    $oldDb=$db; $oldMybb=$mybb;
    $mybb=new class($mybb) {
        public array $user,$usergroup,$settings,$input,$cookies=[]; public array $binary_fields=['posts'=>['ipaddress'=>true]];
        public string $request_method='post',$version='1.8.41'; public bool $debug_mode=false;
        public function __construct($old) { foreach(['user','usergroup','settings','input'] as $p) $this->$p=$old->$p; }
        public function get_input($name,$type=0) { return $this->input[$name] ?? ''; }
    };
    $mybb->user+=['username'=>'Hero','suspendposting'=>0,'moderateposts'=>0];
    $mybb->usergroup+=['maxposts'=>0];
    $mybb->settings+=['postfloodcheck'=>1,'postfloodsecs'=>30,'postmergemins'=>0,'maxmessagelength'=>65535,
        'mycodemessagelength'=>1,'minmessagelength'=>1,'maxpostimages'=>0,'maxpostvideos'=>0,'subscribeexcerpt'=>50,'bblanguage'=>'english','threadreadcut'=>1,'readparentforums'=>0];
    $cache=new class { public function read($key) { return []; } };
    $lang=new class { public string $emailbit_viewthread='', $quote='', $slaps='', $with_trout=''; public function load(...$args):void {} };
    $session=(object)['packedip'=>inet_pton('127.0.0.1')];
    $db=new DB_MySQLi;
    $db->read_link=$db->write_link=$db->current_link=$link;$db->table_prefix=TABLE_PREFIX;$db->db_encoding='utf8mb4';$db->type='mysqli';
    $store=new AF_ACS_SubmissionStore($link);$accessNative=new AF_ACS_SessionAccess;
    $gateway=new AF_ACS_MybbPostGateway($store,$repo,$accessNative,$db);
    // Existing seal fixture ends with a deliberately unsealed suffix. Keep its active prefix only.
    $d=$drafts->restore($saved['draft_id']);$active=[];
    foreach($repo->sealRows($d['draft_id'],true) as $a) $active[]=$repo->sealPayload($a)['action'];
    $drafts->save(['session_id'=>$sid,'encounter_id'=>$eid,'turn_id'=>$turnId,'draft_id'=>$d['draft_id'],'expected_draft_version'=>$d['draft_version'],
        'operation_key'=>'gateway_prepare_fixture','prose'=>'Gateway narrative','actions'=>$active]);
    $v=$seal->status($d['draft_id']);$confirmed=$seal->command('confirm',['draft_id'=>$d['draft_id'],'expected_draft_version'=>$v['draft_version'],
        'operation_key'=>'gateway_confirm_fixture','confirmation_hash'=>$v['confirmation_hash']]);
    $baseDraft=$repo->draft($d['draft_id'],'7');
    $link->query('CREATE TABLE fixture_posts (pid int unsigned AUTO_INCREMENT PRIMARY KEY,tid int unsigned,replyto int unsigned DEFAULT 0,fid int unsigned,subject varchar(255),icon int DEFAULT 0,uid int unsigned,username varchar(255),dateline bigint,message text,ipaddress varbinary(16),includesig int DEFAULT 0,smilieoff int DEFAULT 0,visible int DEFAULT 1) ENGINE=MyISAM');
    $link->query('CREATE TABLE fixture_attachments (aid int PRIMARY KEY,pid int DEFAULT 0,uid int,posthash varchar(32),visible int DEFAULT 1) ENGINE=MyISAM');
    $link->query('CREATE TABLE fixture_threadsubscriptions (sid int AUTO_INCREMENT PRIMARY KEY,uid int,tid int,notification int,dateline bigint) ENGINE=MyISAM');
    $link->query('CREATE TABLE fixture_threads (tid int PRIMARY KEY,lastpost bigint,fid int,lastposteruid int,uid int DEFAULT 7,visible int DEFAULT 1,closed varchar(30) DEFAULT 0) ENGINE=MyISAM');
    $link->query('INSERT INTO fixture_threads (tid,lastpost,fid,lastposteruid) VALUES(51,0,1,7)');
    $link->query('CREATE TABLE fixture_threadsread (tid int,uid int,dateline bigint,PRIMARY KEY(tid,uid)) ENGINE=MyISAM');
    $link->query('CREATE TABLE fixture_forumsread (fid int,uid int,dateline bigint,PRIMARY KEY(fid,uid)) ENGINE=MyISAM');
    // No subscribers; schema is bounded to the native join used by insert_post.
    $link->query('CREATE TABLE fixture_users (uid int PRIMARY KEY,username varchar(255),email varchar(255),language varchar(50),loginkey varchar(50),salt varchar(20),regdate bigint,lastactive bigint,lastpost bigint DEFAULT 0,postnum int DEFAULT 0) ENGINE=MyISAM');
    $link->query("INSERT INTO fixture_users (uid,username) VALUES(7,'Hero')");
    $plugins=new class($store,$db) {
        public array $calls=[]; public string $mode=''; public ?AF_ACS_MybbPostGateway $gateway=null; public ?AF_ACS_MybbPostResult $nested=null; public ?AF_ACS_MybbPostContext $context=null;
        public function __construct(private AF_ACS_SubmissionStore $store,private object $db) {}
        public function run_hooks($name,&$arg=null) {
            $this->store->assertMybbBoundary($this->db); $this->calls[]=$name;
            if($name==='datahandler_post_validate_post' && $this->mode==='invalid') $arg->set_error('fixture_invalid');
            if($name==='datahandler_post_validate_post' && $this->mode==='draft_race') $arg->data['pid']=1;
            if($name==='datahandler_post_insert_post') {
                snapshot_assert($this->store->submission($this->context->submission['submission_id'])['status']==='posting','Native hook entered before durable POSTING');
                if($this->mode==='service_reenter') ($GLOBALS['acsPublishReenter'])();
                if($this->mode==='before_insert_throw') throw new RuntimeException('fixture before insert');
                if($this->mode==='wrong_uid') $arg->post_insert_data['uid']=8;
                if($this->mode==='wrong_tid') $arg->post_insert_data['tid']=52;
                if($this->mode==='wrong_fid') $arg->post_insert_data['fid']=2;
                if($this->mode==='reenter') $this->nested=$this->gateway->publish($this->context);
            }
            if($name==='datahandler_post_insert_post_end' && $this->mode==='interrupt') {
                $link=$this->db->write_link;
                file_put_contents($GLOBALS['argv'][4],json_encode(['database'=>$link->query('SELECT DATABASE()')->fetch_row()[0],
                    'submission_id'=>$this->context->submission['submission_id']],JSON_THROW_ON_ERROR));
                exit(86); // Real request termination: Gateway shutdown quarantine must persist.
            }
            if($name==='newreply_do_newreply_end' && $this->mode==='state_conflict') $this->db->write_link->query('UPDATE fixture_af_acs_entity_states SET state_version=state_version+1');
            if($name==='datahandler_post_insert_post_end' && $this->mode==='late_throw') throw new RuntimeException('fixture late hook');
            return $arg;
        }
    };
    $plugins->gateway=$gateway;
    $GLOBALS['acsGatewayTopic']=['tid'=>51,'fid'=>1,'uid'=>7,'visible'=>1,'subject'=>'Fixture','closed'=>0,'sticky'=>0,'lastpost'=>0];
    $game=[];foreach(['sessions','encounters','turns','entity_states','resources','rolls','sealed_actions','snapshots','events','event_details'] as $t) $game[$t]=$link->query('SELECT * FROM fixture_af_acs_'.$t.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    if (isset($GLOBALS['acsPublishServiceFixture'])) {
        ($GLOBALS['acsPublishServiceFixture'])(get_defined_vars());
        $db=$oldDb;$mybb=$oldMybb;return;
    }
    $next=0;
    // Each case starts from the same sealed fixture, never production cleanup/recovery logic.
    $context=static function(array $options=[])use(&$next,$link,$store,$baseDraft,$sid,$d,$confirmed,$plugins,&$mybb):AF_ACS_MybbPostContext {
        $link->query('DELETE FROM fixture_af_acs_publication_audit');$link->query('DELETE FROM fixture_af_acs_submissions');
        $st=$link->prepare("UPDATE fixture_af_acs_drafts SET status='sealed',draft_version=?,confirmation_hash=? WHERE id=?");
        $st->bind_param('sss',$baseDraft['draft_version'],$baseDraft['confirmation_hash'],$baseDraft['id']);$st->execute();
        $link->query('TRUNCATE fixture_posts');$link->query("INSERT INTO fixture_posts (pid,uid,tid,fid,subject,message,dateline,visible) VALUES(1,8,51,1,'Topic','First post',0,1)");
        $mybb->input=['my_post_key'=>'token'];$plugins->calls=[];$plugins->mode='';$GLOBALS['acsGatewayModeration']=false;$GLOBALS['acsGatewayLastPost']=0;
        $id=str_pad(dechex(++$next),32,'0',STR_PAD_LEFT);
        $r=$store->register(['schema_version'=>1,'submission_id'=>$id,'draft_id'=>$d['draft_id'],'author_uid'=>'7',
            'expected_draft_version'=>$baseDraft['draft_version'],'confirmation_hash'=>$confirmed['confirmation_hash']],static function(){});
        $r=$store->validating($id,$r['state_version'],static function(){});
        return $plugins->context=AF_ACS_MybbPostContext::fromStore($store,$id,$r['state_version'],$options);
    };
    $expect=static function(AF_ACS_MybbPostResult $r,AF_ACS_MybbPostStatus $s,string $label):void {
        snapshot_assert($r->status===$s,$label.': '.$r->status->value.' / '.$r->code);
    };
    if (($GLOBALS['argv'][3] ?? '')==='--interrupt') { $c=$context();$plugins->mode='interrupt';$gateway->publish($c);throw new LogicException('Expected process termination'); }
    $c=$context();$r=$gateway->publish($c);$expect($r,AF_ACS_MybbPostStatus::POST_CONFIRMED,'native success');
    snapshot_assert($r->pid==='2','Unverified PID');
    snapshot_assert($link->query('SELECT postnum FROM fixture_users WHERE uid=7')->fetch_row()[0]==='1','Native post counter missing');
    foreach(['newreply_do_newreply_start','datahandler_post_validate_post','datahandler_post_insert_post','datahandler_post_insert_post_end','newreply_do_newreply_end'] as $hook) snapshot_assert(count(array_keys($plugins->calls,$hook,true))===1,'Missing/duplicate native hook '.$hook);
    $calls=$plugins->calls;$expect($gateway->publish($c),AF_ACS_MybbPostStatus::POST_CONFIRMED,'lost HTTP response replay');snapshot_assert($calls===$plugins->calls,'Replay invoked hooks');
    snapshot_assert($link->query('SELECT COUNT(*) FROM fixture_posts')->fetch_row()[0]==='2','Duplicate post after response loss');
    snapshot_assert($link->query('SELECT postnum FROM fixture_users WHERE uid=7')->fetch_row()[0]==='1','Replay repeated native reward/counter lifecycle');
    $c=$context();$plugins->mode='invalid';$expect($gateway->publish($c),AF_ACS_MybbPostStatus::REJECTED,'native validation failure');
    snapshot_assert($link->query('SELECT COUNT(*) FROM fixture_posts')->fetch_row()[0]==='1','Invalid native post inserted');
    $c=$context();$GLOBALS['acsGatewayLastPost']=TIME_NOW;$r=$gateway->publish($c);$expect($r,AF_ACS_MybbPostStatus::REJECTED,'native flood');snapshot_assert(in_array('post_flooding',$r->validationErrors,true)||in_array('post_flooding_one_second',$r->validationErrors,true),'Flood validation bypassed');
    $c=$context();$GLOBALS['fixture_deny_post']=true;$expect($gateway->publish($c),AF_ACS_MybbPostStatus::REJECTED,'native permissions');unset($GLOBALS['fixture_deny_post']);
    $c=$context();$GLOBALS['acsGatewayClosedForum']=true;$expect($gateway->publish($c),AF_ACS_MybbPostStatus::REJECTED,'closed forum');unset($GLOBALS['acsGatewayClosedForum']);
    $c=$context();$GLOBALS['acsGatewayTopic']['closed']=1;$expect($gateway->publish($c),AF_ACS_MybbPostStatus::REJECTED,'closed topic');$GLOBALS['acsGatewayTopic']['closed']=0;
    $c=$context();$GLOBALS['acsGatewayPassword']='fixture-password';$expect($gateway->publish($c),AF_ACS_MybbPostStatus::REJECTED,'forum password');unset($GLOBALS['acsGatewayPassword']);
    $c=$context();$mybb->input['pid']=1;$expect($gateway->publish($c),AF_ACS_MybbPostStatus::REJECTED,'draft conversion');
    $c=$context();$plugins->mode='draft_race';$expect($gateway->publish($c),AF_ACS_MybbPostStatus::REJECTED,'validator draft mutation');
    $c=$context();$plugins->mode='late_throw';$r=$gateway->publish($c);$expect($r,AF_ACS_MybbPostStatus::RECOVERY_REQUIRED,'late exception');
    $j=$store->submission($c->submission['submission_id']);snapshot_assert($j['status']==='recovery_required'&&$j['pid']===null&&str_contains($j['evidence_json'],'observed_pid'),'Late hook evidence missing');
    $calls=$plugins->calls;$expect($gateway->publish($c),AF_ACS_MybbPostStatus::RECOVERY_REQUIRED,'uncertain retry');snapshot_assert($calls===$plugins->calls,'Uncertain retry ran hooks');
    $c=$context();$plugins->mode='before_insert_throw';$expect($gateway->publish($c),AF_ACS_MybbPostStatus::RECOVERY_REQUIRED,'exception in insertion lifecycle');
    foreach(['wrong_uid','wrong_tid','wrong_fid'] as $mode) {$c=$context();$plugins->mode=$mode;$expect($gateway->publish($c),AF_ACS_MybbPostStatus::RECOVERY_REQUIRED,$mode);}
    $c=$context();$GLOBALS['acsGatewayModeration']=true;$expect($gateway->publish($c),AF_ACS_MybbPostStatus::MODERATION_PENDING,'moderation');
    $c=$context(['quick_reply'=>true]);$expect($gateway->publish($c),AF_ACS_MybbPostStatus::POST_CONFIRMED,'quick reply native lifecycle');
    $c=$context();$mybb->settings['postmergemins']=10;$mybb->settings['postmergesep']='[hr]';$mybb->settings['postmergeuignore']='';$mybb->settings['postmergefignore']='';
    $link->query('UPDATE fixture_threads SET lastpost='.TIME_NOW);$expect($gateway->publish($c),AF_ACS_MybbPostStatus::REJECTED,'native merge');$mybb->settings['postmergemins']=0;
    $c=$context();$_FILES=['attachments'=>['name'=>'file.txt']];$expect($gateway->publish($c),AF_ACS_MybbPostStatus::REJECTED,'unsupported upload');$_FILES=[];
    $c=$context();$mybb->input['my_post_key']='invalid';$expect($gateway->publish($c),AF_ACS_MybbPostStatus::REJECTED,'CSRF');
    snapshot_assert($store->submission($c->submission['submission_id'])['status']==='validating'&&$plugins->calls===[],'CSRF failure changed another intent');
    $c=$context();$mybb->user['uid']=8;$expect($gateway->publish($c),AF_ACS_MybbPostStatus::REJECTED,'actor mismatch');$mybb->user['uid']=7;
    snapshot_assert($store->submission($c->submission['submission_id'])['status']==='validating'&&$plugins->calls===[],'Wrong actor changed intent');
    $c=$context();$plugins->mode='reenter';$expect($gateway->publish($c),AF_ACS_MybbPostStatus::POST_CONFIRMED,'reentrant hook');
    snapshot_assert($plugins->nested->status===AF_ACS_MybbPostStatus::RECOVERY_REQUIRED,'Recursive call was allowed');
    snapshot_assert($link->query('SELECT COUNT(*) FROM fixture_posts')->fetch_row()[0]==='2','Reentrant hook duplicated post');
    $c=$context(['posthash'=>str_repeat('a',32)]);$link->query("INSERT INTO fixture_attachments VALUES(1,0,7,REPEAT('a',32),1)");
    $expect($gateway->publish($c),AF_ACS_MybbPostStatus::REJECTED,'preuploaded attachment');$link->query('TRUNCATE fixture_attachments');
    $c=$context();$link->query("UPDATE fixture_af_acs_turns SET status='passed' WHERE id=".$turnId);
    $expect($gateway->publish($c),AF_ACS_MybbPostStatus::REJECTED,'closed combat turn');$link->query("UPDATE fixture_af_acs_turns SET status='pending' WHERE id=".$turnId);
    $c=$context();$mybb->version='1.8.40';$expect($gateway->publish($c),AF_ACS_MybbPostStatus::REJECTED,'unverified core');$mybb->version='1.8.41';
    $c=$context();$confirmedPost=$gateway->publish($c);$link->query('DELETE FROM fixture_posts WHERE pid='.(int)$confirmedPost->pid);
    $calls=$plugins->calls;$expect($gateway->publish($c),AF_ACS_MybbPostStatus::RECOVERY_REQUIRED,'deleted known PID replay');snapshot_assert($plugins->calls===$calls,'Deleted-post replay inserted');
    $c=$context();$r=$store->posting($c->submission['submission_id'],$c->submission['state_version'],[
        'dependency_hash'=>$confirmed['dependency_hash'],'simulation_hash'=>$confirmed['simulation_hash'],'confirmation_hash'=>$confirmed['confirmation_hash']],static function(){});
    $expect($gateway->publish($c),AF_ACS_MybbPostStatus::RECOVERY_REQUIRED,'interrupted POSTING');snapshot_assert($plugins->calls===[],'POSTING repeated native lifecycle');
    $c=$context();$link->begin_transaction();$expect($gateway->publish($c),AF_ACS_MybbPostStatus::RECOVERY_REQUIRED,'ambient transaction');snapshot_assert($plugins->calls===[],'Hooks inside ambient transaction');$link->rollback();
    $c=$context();$lock='acs:'.substr(hash('sha256',$link->query('SELECT DATABASE()')->fetch_row()[0].':'.TABLE_PREFIX),0,56);
    $st=$link->prepare('SELECT GET_LOCK(?,0)');$st->bind_param('s',$lock);$st->execute();$st->get_result()->free();$st->close();
    $expect($gateway->publish($c),AF_ACS_MybbPostStatus::RECOVERY_REQUIRED,'ambient ACS lock');snapshot_assert($plugins->calls===[],'Hooks under ACS lock');$st=$link->prepare('SELECT RELEASE_LOCK(?)');$st->bind_param('s',$lock);$st->execute();$st->get_result()->free();$st->close();
    foreach($game as $t=>$rows) snapshot_assert($link->query('SELECT * FROM fixture_af_acs_'.$t.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)===$rows,'Gateway changed combat/tape: '.$t);
    snapshot_assert($link->query("SHOW TABLE STATUS LIKE 'fixture_posts'")->fetch_assoc()['Engine']==='MyISAM','Gateway migrated MyBB');
    $db=$oldDb;$mybb=$oldMybb;
    echo "ACS Gateway: upstream handler validation/insertion, PID, replay, moderation, flood/permissions, merge/draft refusal, late failures/quarantine, transaction/lock boundary and immutable mechanics passed\n";
};
require __DIR__.'/acs_seal_regression.php';

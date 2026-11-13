<?php
/** Fresh PHP request process for the disposable concurrent writer test. */
$socket=$argv[1]??'';$database=$argv[3]??'';
if(!str_starts_with($socket,'/tmp/acs222-socket/')||!preg_match('/\Aacs622_[a-f0-9]{12}\z/D',$database))throw new RuntimeException('Disposable worker only');
require dirname(__DIR__).'/combat_snapshot_readonly.php';
define('TABLE_PREFIX','t_');define('AF_CACHE','/tmp/');define('TIME_NOW',time());
require __DIR__.'/InventoryFixtureDatabase.php';
require AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/CombatCommitWriter.php';
function verify_post_check($key,$silent=false):bool{return $key==='token';}
function htmlspecialchars_uni($s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
$mybb=new class($mybb){public array $user,$usergroup,$settings;public string $request_method='post';public array $input=['my_post_key'=>'token'];public function __construct($m){$this->user=$m->user;$this->user['uid']=8;$this->usergroup=$m->usergroup+['canview'=>1];$this->settings=$m->settings;}public function get_input($key){return $this->input[$key]??'';}};
$link=new mysqli('localhost','root','',$database,0,$socket);$link->set_charset('utf8mb4');$db=new InventoryFixtureDatabase($link);
$plugins=new class{public function add_hook(...$args){}};require AF_ADDONS.'advancedinventory/advancedinventory.php';

$access=new class extends AF_ACS_SessionAccess{public function user(string $uid):array{return ['uid'=>(int)$uid];}public function thread(string $tid,?string $uid=null):array{return ['tid'=>51,'fid'=>2,'uid'=>7,'visible'=>1,'closed'=>'0'];}};
fwrite(STDOUT,"READY\n");try{$receipt=(new AF_ACS_CombatCommitWriter($db,$access))->commit($argv[4],$argv[5]);}catch(RuntimeException $e){if(!in_array($e->getMessage(),['builder_version_conflict','ACS_MAINTENANCE_BUSY','ACS_COMBAT_DEPENDENCY_CONFLICT','ACS_COMBAT_VERSION_CONFLICT','ACS_COMBAT_CONFIRMATION_CONFLICT','ACS_SEAL_TURN_UNAVAILABLE'],true))throw $e;$receipt=['conflict'=>$e->getMessage()];}fwrite(STDOUT,json_encode($receipt,JSON_THROW_ON_ERROR)."\n");

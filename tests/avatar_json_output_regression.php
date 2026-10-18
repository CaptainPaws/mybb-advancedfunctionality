<?php
/** Short process checks: real Core bridge/helper + its exit-time shutdown buffer. */
if (($argv[1] ?? '') === '--worker') {
    define('IN_MYBB', 1);
    define('THIS_SCRIPT', 'usercp.php');
    define('MYBB_ROOT', dirname(__DIR__).'/');
    $plugins = new class {function add_hook(...$args) {}};
    $mybb = new class {
        public $input=[];
        public $user=['uid'=>7];
        public $usergroup=['canusercp'=>1];
        public $settings=[];
        function get_input($key, $type=0) {return $this->input[$key]??'';}
    };
    $db = new class {
        function simple_select($table,$fields,$where,$options=[]) {
            if($table!=='users'||$where!=="uid='7'")throw new RuntimeException('Unexpected DB access');
            return ['avatar'=>'saved.jpg','avatardimensions'=>'12|16','avatartype'=>'remote'];
        }
        function fetch_array($row) {return $row;}
    };
    function verify_post_check($key) {if($key!=='valid')throw new RuntimeException('CSRF');}
    function error_no_permission() {throw new RuntimeException('Permission');}
    function af_apf_is_enabled() {return true;}
    function af_apf_secondary_avatar_path($uid) {return '';}
    function af_apf_get_secondary_avatar($uid) {return '';}
    define('AF_APF_SECONDARY_AVATAR_KEY','profile_secondary_avatar');
    require MYBB_ROOT.'inc/plugins/advancedfunctionality.php';
    $GLOBALS['af_addons_bootstrapped']=true;
    require AF_BASE.'avatar.php';
    $mode=$argv[2];
    $mybb->input=['action'=>'avatar','af_avatar_state'=>'1','role'=>$mode==='secondary'?'secondary':'primary','my_post_key'=>'valid','atf_avatar_async'=>'1'];
    // Exactly the fallback buffer started on global_start for usercp.php.
    advancedfunctionality_outputbuffer_start();
    if($mode==='saved')af_avatar_saved_response('primary','saved.jpg');
    if($mode==='error')af_avatar_json(['ok'=>false,'error'=>'Rejected'],422);
    advancedfunctionality_avatar_state_bridge();
    throw new RuntimeException('State route returned instead of exiting');
}
foreach (['primary','secondary','saved','error'] as $mode) {
    $process=proc_open([PHP_BINARY,__FILE__,'--worker',$mode],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $raw=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    if($code!==0||$stderr!=='')throw new RuntimeException($mode.': '.$stderr.$raw);
    $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if(str_contains($raw,'<!--')||str_contains($raw,'<link')||str_contains($raw,'<script'))throw new RuntimeException('HTML appended to '.$mode);
    if($mode==='error') {if($data['ok']!==false)throw new RuntimeException('Lost error response');}
    elseif(!$data['ok']||$data['avatar']['uid']!==7)throw new RuntimeException('Lost saved state');
}
echo "Avatar JSON output passed: both GET roles, verified POST receipt, error response; actual Core shutdown buffer preserves entire JSON.\n";

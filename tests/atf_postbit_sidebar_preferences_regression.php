<?php
define('IN_MYBB', true);
define('TABLE_PREFIX', 'mybb_');
define('TIME_NOW', 1700000000);
define('AF_ADDONS', __DIR__.'/../inc/plugins/advancedfunctionality/addons/');
function htmlspecialchars_uni($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function verify_post_check($value, $silent = false) { return $value === 'csrf-token'; }
function check_sidebar($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$db = new class {
    public array $rows = [42=>['forum_layout'=>'grid']];
    public int $reads = 0;
    public bool $fail = false;
    function table_exists($table) { return true; }
    function simple_select($table, $fields, $where) {
        $this->reads++; preg_match('/uid=\'(\d+)\'/', $where, $match);
        $rows = []; foreach ($this->rows[(int)$match[1]] ?? [] as $key=>$value) $rows[] = ['preference_key'=>$key, 'preference_value'=>$value];
        return (object)['rows'=>$rows, 'i'=>0];
    }
    function fetch_array($query) { return $query->rows[$query->i++] ?? null; }
    function replace_query($table, $row) { if ($this->fail) return false; $this->rows[$row['uid']][$row['preference_key']] = $row['preference_value']; return true; }
};
$mybb = new class {
    public array $user = ['uid'=>42], $settings = ['bburl'=>'https://forum.test'], $input = [];
    public string $request_method = 'post', $post_code = 'csrf-token';
    function get_input($key) { return $this->input[$key] ?? ''; }
};
$lang = new class { function load($name) {} };
require AF_ADDONS.'adaptivethemeframework/adaptivethemeframework.php';
$prefs = af_adaptivethemeframework_presentation_preferences();
check_sidebar($prefs === ['forum_layout'=>'grid','postbit_sidebar_hidden'=>false], 'Default sidebar must be visible');
for ($i=0; $i<50; $i++) { af_adaptivethemeframework_presentation_preferences(); af_adaptivethemeframework_preferences_asset(); }
check_sidebar($db->reads === 1, 'Preferences must load once per request, not once per post');
$mybb->input = ['my_post_key'=>'csrf-token', 'postbit_sidebar_hidden'=>'1', 'uid'=>'7'];
[$status, $result] = af_adaptivethemeframework_sidebar_save_request();
check_sidebar($status === 200 && $result['postbit_sidebar_hidden'] && !isset($db->rows[7]) && $db->rows[42]['forum_layout'] === 'grid', 'UID isolation or independent preferences lost');
unset($GLOBALS['atf_presentation_preferences']);
check_sidebar(af_adaptivethemeframework_presentation_preferences()['postbit_sidebar_hidden'], 'Server choice did not survive fresh request cache');
$widget = af_adaptivethemeframework_render_theme_preferences();
$page = '<html><head></head><body class="atf-active" data-atf-owned="1"><article class="atf-post">Post</article></body></html>';
af_adaptivethemeframework_mark_page($page);
check_sidebar(str_contains($page, '<body data-atf-postbit-sidebar="hidden"') && strpos($page,'data-atf-preferences-bootstrap') < strpos($page,'<body') && !str_contains(substr($page,0,strpos($page,'</head>')), ' defer'), 'Early bootstrap/body seed missing');
$once = $page; af_adaptivethemeframework_mark_page($page); check_sidebar($page === $once, 'Bootstrap was duplicated');
$member = ['widget'=>$widget, 'bootstrap'=>af_adaptivethemeframework_preferences_asset(true), 'page'=>$page];
$mybb->input['my_post_key']='invalid'; check_sidebar(af_adaptivethemeframework_sidebar_save_request()[0]===403, 'CSRF not checked');
$mybb->input['my_post_key']='csrf-token'; $mybb->input['postbit_sidebar_hidden']='true'; check_sidebar(af_adaptivethemeframework_sidebar_save_request()[0]===422, 'Invalid boolean accepted');
$mybb->input['postbit_sidebar_hidden']='0'; $mybb->request_method='get'; check_sidebar(af_adaptivethemeframework_sidebar_save_request()[0]===405, 'GET changed preferences');
$mybb->request_method='post'; $db->fail=true; check_sidebar(af_adaptivethemeframework_sidebar_save_request()[0]===500 && $db->rows[42]['postbit_sidebar_hidden']==='1', 'Failure reported as success'); $db->fail=false;
$mybb->user=['uid'=>7]; check_sidebar(!af_adaptivethemeframework_presentation_preferences()['postbit_sidebar_hidden'], 'Other UID inherited preference');
$mybb->user=['uid'=>0]; $reads=$db->reads;
$guest = ['widget'=>af_adaptivethemeframework_render_theme_preferences(), 'bootstrap'=>af_adaptivethemeframework_preferences_asset(true)];
check_sidebar($reads===$db->reads && af_adaptivethemeframework_sidebar_save_request()[0]===403, 'Guest used server preferences');
if (in_array('--browser-fixture', $argv, true)) { echo json_encode(compact('member','guest')); exit; }
echo "ATF sidebar preferences: default, one request read, persistence, UID isolation, independent forum layout, CSRF/POST/validation/failure, guest no-DB and early bootstrap passed.\n";

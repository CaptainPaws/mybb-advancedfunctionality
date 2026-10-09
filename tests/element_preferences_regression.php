<?php
define('IN_MYBB', 1); define('TABLE_PREFIX', 'mybb_'); define('TIME_NOW', 1700000000);
function htmlspecialchars_uni($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function verify_post_check($key, $silent=false) { return $key === 'csrf-token'; }
require __DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedelementtheme/preferences.php';
function pref_check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$db = new class {
    public array $rows=[]; public int $reads=0; public bool $available=true; public bool $fail=false; public int $creates=0;
    function table_exists($table) { pref_check($table==='af_presentation_preferences','Separate preference table'); return $this->available; }
    function build_create_table_collation() { return ' DEFAULT CHARSET=utf8mb4'; }
    function write_query($sql) { ++$this->creates; $this->available=true; }
    function escape_string($s) { return addslashes($s); }
    function simple_select($table,$fields,$where,$options=[]) { ++$this->reads; preg_match("/uid='(\d+)'/",$where,$m); pref_check(str_contains($where,"preference_key='element_effects'"),'Wrong preference key'); return (int)$m[1]; }
    function fetch_field($uid,$key) { return $this->rows[$uid] ?? null; }
    function replace_query($table,$row) { if($this->fail) return false; $this->rows[$row['uid']]=stripslashes($row['preference_value']); return true; }
};
$mybb = new class {
    public array $user=['uid'=>42]; public array $settings=['bburl'=>'https://forum.test']; public string $post_code='csrf-token'; public string $request_method='post';
    public array $input=['my_post_key'=>'csrf-token'];
    function get_input($key) { return $this->input[$key] ?? ''; }
};
$defaults=af_elementtheme_preferences_defaults();
pref_check(af_elementtheme_preferences()===$defaults,'Defaults not enabled');
for($i=0;$i<60;$i++) af_elementtheme_preferences();
pref_check($db->reads===1,'N+1 preference reads');
foreach($defaults as $key=>$_) $mybb->input[$key]='1';
$mybb->input['effects_enabled']='0'; $mybb->input['effects_application']='0'; $mybb->input['uid']='999';
[$status,$result]=af_elementtheme_preferences_save_request(); pref_check($status===200,'Save failed');
pref_check(isset($db->rows[42])&&!isset($db->rows[999]),'Client changed another UID');
pref_check(!$result['preferences']['effects_enabled']&&!$result['preferences']['effects_application']&&$result['preferences']['effects_sheet'],'Master lost individual preferences');
unset($GLOBALS['af_elementtheme_preferences']);
pref_check(af_elementtheme_preferences()===$result['preferences'],'New request/device lost preferences');
$mybb->user=['uid'=>43]; pref_check(af_elementtheme_preferences()===$defaults,'Preferences leaked to another account');
$mybb->user=['uid'=>42];
$mybb->input['effects_enabled']='1'; [$status,$result]=af_elementtheme_preferences_save_request(); pref_check($status===200&&!$result['preferences']['effects_application'],'Master ON overwrote surface flags');
$before=$db->rows;
$mybb->request_method='get'; pref_check(af_elementtheme_preferences_save_request()[0]===405,'GET writes allowed');
$mybb->request_method='post'; $mybb->input['my_post_key']='bad'; pref_check(af_elementtheme_preferences_save_request()[0]===403,'Missing CSRF');
$mybb->input['my_post_key']='csrf-token'; $mybb->input['effects_sheet']='bad'; pref_check(af_elementtheme_preferences_save_request()[0]===422,'Unvalidated preferences');
$mybb->input['effects_sheet']='1'; $mybb->user=['uid'=>0]; pref_check(af_elementtheme_preferences_save_request()[0]===403,'Guest wrote server storage');
pref_check($db->rows===$before,'Rejected requests wrote state');
$mybb->user=['uid'=>42]; $db->fail=true; pref_check(af_elementtheme_preferences_save_request()[0]===500,'Failed write reported success'); $db->fail=false;
$GLOBALS['af_elementtheme_preferences_available']=false; pref_check(af_elementtheme_preferences_save_request()[0]===503,'Missing storage reported success');
unset($GLOBALS['af_elementtheme_preferences_available']);
$db->available=false; af_elementtheme_preferences_schema(); af_elementtheme_preferences_schema(); pref_check($db->creates===1,'Schema not idempotent');
$widget=af_elementtheme_render_preferences_widget();
foreach(array_keys($defaults) as $key) pref_check(str_contains($widget,'type="checkbox" name="'.$key.'"'),'Missing switch '.$key);
pref_check(str_contains($widget,'my_post_key')&&str_contains($widget,'data-uid="42"'),'Widget authentication contract');
if(in_array('--browser-fixture',$argv,true)) { echo json_encode(['widget'=>$widget,'defaults'=>$defaults]); exit; }
echo "Element preferences: UID isolation, one request load, cross-request persistence, master/surface flags, CSRF/POST/validation, failure reporting, shared schema and widget passed.\n";

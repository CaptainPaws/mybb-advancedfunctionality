<?php
// Read-only MyBB boundary for exercising the real response compiler.
define('IN_MYBB', 1); define('AF_ADDONS',1); define('MYBB_ROOT',getcwd().'/'); define('THIS_SCRIPT','showthread.php'); define('TABLE_PREFIX','mybb_');
function htmlspecialchars_uni($s) { return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
class DB {
 public function table_exists($t) { return false; }
 public function escape_string($s) { return addslashes($s); }
 public function simple_select($t,$f='*',$w='',$o=[]) { return ['table'=>$t,'where'=>$w]; }
 public function fetch_field($q,$f) { return ''; }
 public function fetch_array($q) { return false; }
 public function update_query(...$a) {}
}
$db=new DB; $mybb=(object)['settings'=>['bburl'=>'http://127.0.0.1:8765','af_advancededitor_enabled'=>'1'],'user'=>['uid'=>1,'usergroup'=>2], 'post_code'=>'fixture'];
require MYBB_ROOT.'inc/plugins/advancedfunctionality/addons/advancededitor/advancededitor.php';
$layout=['sections'=>[['type'=>'group','title'=>'Full toolbar','items'=>['bold','italic','underline','strike','quote','code','link','image','bulletlist','left','center','right','justify','maximize','color','af_tables','af_stikers','af_drafts','af_font','af_fontsize','af_spoiler','af_accordion','af_tabs','af_embedvideos','af_resizeimg','af_tquote','af_lockcontent','af_abbr','af_mark','af_togglemode']],['type'=>'dropdown','title'=>'Доп. меню','items'=>['af_indent','af_floatbb','af_htmlbb']]]];
// This DB returns only the configured toolbar layout; other settings stay at defaults.
class FixtureDB extends DB {
 public function fetch_field($q,$f) { global $layout; if (str_contains($q['where'],'af_advancededitor_help_enabled')) return '1'; return str_contains($q['where'],'af_advancededitor_toolbar_layout') ? json_encode($layout) : ''; }
}
$db=new FixtureDB;
$GLOBALS['af_ae_external_capabilities']['kb-insert'] = ['activation'=>'click','requires'=>[],'js'=>[$mybb->settings['bburl'].'/inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase_insert.js'],'css'=>[$mybb->settings['bburl'].'/inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase_insert.css']];
$GLOBALS['af_ae_external_buttons']['af_kb_insert'] = ['cmd'=>'af_kb_insert','title'=>'Insert KB','label'=>'KB','handler'=>'kb_insert','capability'=>'kb-insert'];
$page='<!doctype html><html><head><meta charset="UTF-8"><script src="/jscripts/jquery.js"></script></head><body><form id="quick_reply_form"><input name="tid" value="14"><textarea name="message" id="message">Привет 😀</textarea><button type="submit">Submit</button><button name="previewpost" type="submit">Preview</button></form><div class="post" id="post_699"><div class="atf-post__content"><div class="atf-post__meta-line">Meta</div><div class="post_body atf-post__message" id="pid_699"><div class="atf-post__message-body">Body</div></div><div class="af-ccp-postcount">100</div></div></div></body></html>';
af_advancededitor_pre_output($page);
if (!function_exists('af_advancededitor_shell_registry')) {
    $page = str_replace('</head>', '<link rel="stylesheet" href="/inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase_kbui.css"><script src="/inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase_insert.js"></script></head>', $page);
}
echo $page;

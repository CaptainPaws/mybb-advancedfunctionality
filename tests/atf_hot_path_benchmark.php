<?php
// Isolated CPU benchmark, not a measurement of a live MyBB deployment.
// Usage: php tests/atf_hot_path_benchmark.php [absolute bootstrap path]
define('IN_MYBB', true);
define('THIS_SCRIPT', 'showthread.php');
define('TABLE_PREFIX', 'mybb_');
define('AF_ADDONS', dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/');
$bootstrap = $argv[1] ?? AF_ADDONS . 'adaptivethemeframework/adaptivethemeframework.php';
require $bootstrap;
function htmlspecialchars_uni($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function af_apf_get_secondary_avatar(int $uid): string { return ''; }
function af_apui_get_profile_character_payload(int $uid): array { return []; }
function af_elementtheme_resolve_key(string $v): string { return ''; }
$mybb = (object)['settings'=>[], 'user'=>['uid'=>42], 'usergroup'=>[]];
$db = new class {
    public $table_prefix = 'mybb_'; public $ddl = 0; public $queries = 0; public $checks = 0;
    function table_exists($t) { ++$this->checks; return true; }
    function query($q) { ++$this->queries; return []; }
    function simple_select(...$a) { ++$this->queries; return []; }
    function fetch_array($q) { return false; }
};
$plugins = new class { function add_hook(...$a) {} };
$measure = static function(callable $run, int $iterations): float {
    $samples=[];
    for($sample=0;$sample<9;++$sample){$start=hrtime(true);for($i=0;$i<$iterations;++$i)$run();$samples[]=(hrtime(true)-$start)/1e6/$iterations;}
    sort($samples);return round($samples[4],6);
};
$initStart=hrtime(true);af_adaptivethemeframework_init();$initMs=(hrtime(true)-$initStart)/1e6;$initChecks=$db->checks;
foreach (range(1,60) as $i) af_adaptivethemeframework_register_component([
    'owner'=>'mybb', 'key'=>'benchmark_'.$i,
    'slot'=>['post.author.meta','post.author.rail','post.author.profile_fields','post.author.character'][$i%4],
    'html'=>'<span>provider</span>',
]);
$post=['pid'=>1,'tid'=>77,'uid'=>42,'username'=>'Author','message'=>str_repeat('Post text ',200), 'postdate'=>'now','posturl'=>'#pid1','profilelink'=>'<a href="member.php?uid=42">Author</a>'];
foreach(['button_edit','button_quote','button_report','button_pm','button_rep'] as $key)$post[$key]='<a class="'.$key.'" href="#">Action</a>';
$pids=implode(',',range(1,20));
if(function_exists('af_adaptivethemeframework_preload_postbit_data'))af_adaptivethemeframework_preload_postbit_data($post);
$queriesBefore=$db->queries;
$compose=$measure(static function()use($post){for($p=1;$p<=20;++$p){$row=$post;$row['pid']=$p;af_adaptivethemeframework_compose_postbit($row);}},100);
$page='<html><body '.(function_exists('af_adaptivethemeframework_render_footer')?'data-atf-owned="1" ':'').'class="af-apui-thread-page'.(function_exists('af_adaptivethemeframework_render_footer')?' atf-active':'').'">'.str_repeat('<article class="atf-post"><div>Post text</div></article>',4000).'</body></html>';
if(function_exists('af_adaptivethemeframework_render_footer'))$page=str_replace('</body>','<atf-footer-placement></atf-footer-placement></body>',$page);
$mark=$measure(static function()use($page){$copy=$page;af_adaptivethemeframework_mark_page($copy);},100);
$slots=$measure(static function()use($post){af_adaptivethemeframework_render_slot('post.author.meta',af_adaptivethemeframework_post_context($post));},1000);
echo json_encode(['php'=>PHP_VERSION,'workload'=>'20 posts / 1 author, 60 static slot providers; 208 KB final HTML; warmed median of 9 samples; cold init' ,'init_ms'=>round($initMs,6),'init_schema_checks'=>$initChecks,'compose_20_posts_ms'=>$compose,'mark_page_ms'=>$mark,'render_slot_with_context_ms'=>$slots,'composition_sql'=>$db->queries-$queriesBefore],JSON_PRETTY_PRINT)."\n";

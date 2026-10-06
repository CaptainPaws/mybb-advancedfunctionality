<?php
/** Production resolver; MyBB data/permission boundary is deliberately mocked. */
define('IN_MYBB', 1);
define('TIME_NOW', time());
$mybb = (object)['user' => ['uid' => 5], 'settings' => ['bburl' => 'https://forum.test'], 'cookies' => []];
$posts = [
    703 => ['pid'=>703,'tid'=>7,'fid'=>3,'uid'=>42,'username'=>'Old Name','visible'=>1],
    704 => ['pid'=>704,'tid'=>8,'fid'=>4,'uid'=>42,'visible'=>1],
    705 => ['pid'=>705,'tid'=>9,'fid'=>5,'uid'=>42,'visible'=>1],
    706 => ['pid'=>706,'tid'=>7,'fid'=>3,'uid'=>42,'visible'=>0],
    707 => ['pid'=>707,'tid'=>10,'fid'=>6,'uid'=>42,'visible'=>1],
];
function get_post($pid) { global $posts; return $posts[$pid] ?? []; }
function get_thread($tid) { return ['uid'=>42,'visible'=>1]; }
function get_user($uid) { return ['username'=>'Автор 😀','avatar'=>'uploads/avatars/avatar_42.png','avatardimensions'=>'48|48']; }
function get_forum($fid) { return ['parentlist'=>(string)$fid,'password'=>$fid===5?'secret':'']; }
function forum_permissions($fid) { return ['canview'=>$fid!==4,'canviewthreads'=>1,'canonlyviewownthreads'=>$fid===6]; }
function format_avatar($url, $dimensions, $maximum) { return ['image'=>'https://forum.test/'.$url]; }
function get_post_link($pid) { return 'showthread.php?pid='.$pid; }
function my_strlen($value) { return mb_strlen($value); }
function my_substr($value,$start,$length=null,$handle_entities=false) { return mb_substr($value,$start,$length); }
function my_date($format,$timestamp) { return 'date'; }
function htmlspecialchars_uni($value) { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false); }
require dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/advancededitor/quote_metadata.php';
$check = static function ($condition,$message) { if (!$condition) throw new RuntimeException($message); };
$input = '[quote="" pid=\'703\']Lorem ipsum[/quote]';
$source = af_advancededitor_quote_source_metadata($input);
$check(str_contains($source, 'quote="Автор 😀"'), 'Empty author must resolve from the visible post');
$check(str_contains($source, "pid='703'"), 'Author enrichment must preserve pid');
$check(af_advancededitor_quote_source_metadata($source)===$source, 'Author enrichment must be idempotent');
$check(af_advancededitor_quote_source_metadata('[code]'.$input.'[/code]')==='[code]'.$input.'[/code]', 'Code samples must remain literal');
foreach ([704,705,706,707] as $pid) {
    $hidden = '[quote="" pid=\''.$pid.'\']Text[/quote]';
    $check(af_advancededitor_quote_source_metadata($hidden)===$hidden, 'Unavailable post must not disclose its author');
}
$html = '<blockquote class="mycode_quote"><cite>Автор 😀 <a href="showthread.php?pid=703#pid703">↗</a></cite>Lorem ipsum</blockquote>';
$rendered = af_advancededitor_quote_html_metadata($html);
$check(str_contains($rendered,'data-pid="703"') && str_contains($rendered,'data-author="Автор 😀"'), 'Rendered quote must retain metadata');
$check(substr_count($rendered,'class="af-qa-avatar"')===1, 'Rendered quote needs one mini-avatar');
$check(af_advancededitor_quote_html_metadata($rendered)===$rendered, 'HTML enrichment must be idempotent');
// Optional real MyBB 1.8.40 parser, kept outside this plugin's repository.
if (!empty($argv[1])) {
    require rtrim($argv[1],'/').'/inc/class_parser.php';
    $lang=(object)['quote'=>'Quote','wrote'=>' Wrote:'];
    $templates=new class {
        public function get($name,...$unused) {
            if ($name==='postbit_gotopost') return '<a href=\"{$url}\">↗</a>';
            return '<blockquote class=\"mycode_quote\"><cite>{$username}{$lang->wrote}{$linkback}</cite>{$message}</blockquote>';
        }
    };
    $parser=new postParser;
    $native=$parser->mycode_parse_quotes($source);
    $native=af_advancededitor_quote_html_metadata($native);
    $check(str_contains($native,'Автор 😀') && str_contains($native,'pid=703'), 'Native MyBB quote parser must accept normalized attributes');
    $check(str_contains($native,'af-qa-avatar'), 'Native preview HTML must receive its mini-avatar');
    require dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/advancededitor/assets/bbcodes/spoiler/server.php';
    $spoilers = '[quote="Author" pid="703"][spoiler]One[/spoiler][spoiler="Title"]Two[/spoiler][/quote]';
    af_ae_bbcode_spoiler_parse_start($spoilers);
    $spoilers = $parser->mycode_parse_quotes($spoilers);
    af_ae_bbcode_spoiler_parse_end($spoilers);
    $check(substr_count($spoilers, 'class="mycode_quote af-aqr-spoiler"')===2, 'Native published content must render multiple spoilers inside a quote');
    $check(str_contains($spoilers, 'hidden') && str_contains($spoilers, 'aria-expanded="false"'), 'Published spoiler must initially be collapsed');
}
echo "AdvancedEditor quote metadata and reader permissions passed.\n";

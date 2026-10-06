<?php
define('IN_MYBB',1);define('THIS_SCRIPT','showthread.php');define('MYBB_ROOT',dirname(__DIR__).'/');define('TIME_NOW',1700000000);define('TABLE_PREFIX','mybb_');
class MyBB {public const INPUT_INT=1;}
$plugins=new class{function add_hook(...$args){}};
$mybb=new class{public $settings=['af_adaptivethemeframework_enabled'=>'1'];public $input=[];function get_input($key,$type=0){return '';}};
$theme=['tid'=>1];
$db=new class{public int $reads=0;function escape_string($s){return $s;}function table_exists($table){return false;}function simple_select(...$args){++$this->reads;return [];}function fetch_array($query){return false;}function fetch_field(...$args){return 0;}};
require MYBB_ROOT.'inc/plugins/advancedfunctionality.php';
require __DIR__.'/fixtures/atf_css.php';
function css_check(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
$addon=AF_ADDONS.'adaptivethemeframework';$manifest=require $addon.'/manifest.php';
$css=atf_test_css($addon,'showthread.php');
foreach(['atf-ucp','atf-modcp','atf-pm','atf-userlist','atf-user-card','atf-moderation','atf-index','atf-forum-card','atf-topic-card'] as $foreign)css_check(!str_contains($css,$foreign),'Foreign showthread CSS: '.$foreign);
css_check(strlen($css)<85000,'Showthread ATF CSS exceeded 85 KB budget');
$names=[];$files=[];
foreach($manifest['theme_stylesheets'] as $source){
 css_check(!isset($files[$source['file']]),'Duplicate CSS ownership');$files[$source['file']]=true;$names[]=$source['id'];
 if(!empty($source['disable_theme_integration'])){
  $reads=$db->reads;
  $decision=af_theme_stylesheet_delivery_decision('adaptivethemeframework',$source['file']);
  css_check($decision['include_file'] && $decision['reason']==='manifest_file_delivery','Surface overridden by unified delivery');
  css_check($db->reads===$reads,'Surface CSS delivery queried DB');
 }
}
foreach(['navigation','forum','showthread','postbit','compose','profile','memberlist','usercp','private','modcp','moderation'] as $surface)css_check(in_array('surface_'.$surface,$names,true),'Missing surface '.$surface);
$bundle=af_theme_stylesheet_build_bundle('adaptivethemeframework');
css_check(count($bundle['sections'])===1,'Surface CSS got integrated into global advancedstyles');
foreach(['atf-ucp','atf-post__','atf-pm','atf-modcp','atf-index'] as $foreign)css_check(!str_contains($bundle['source'],$foreign),'Global CSS contains '.$foreign);
af_collect_enabled_addon_assets();
$queued=json_encode($GLOBALS['af_assets_queue']['css'], JSON_UNESCAPED_SLASHES);
foreach(['navigation','showthread','postbit','compose'] as $surface)css_check(str_contains($queued,'surfaces/'.$surface.'.css'),'Surface not queued: '.$surface);
foreach(['usercp','modcp','private','memberlist','moderation','forum','modals'] as $surface)css_check(!str_contains($queued,'surfaces/'.$surface.'.css'),'Foreign/conditional surface queued: '.$surface);
printf("Stage 6 CSS delivery passed: showthread=%d bytes; global ATF sections=%d.\n",strlen($css),count($bundle['sections']));

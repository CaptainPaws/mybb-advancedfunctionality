<?php
define('IN_MYBB', 1);
define('TABLE_PREFIX', 'mybb_');
define('TIME_NOW', 1700000000);
define('AF_ADDONS', __DIR__.'/../inc/plugins/advancedfunctionality/addons/');
function htmlspecialchars_uni($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function af_avatar_render(array $user, string $context, array $options = []) { if (empty($user['uid'])) return '<span class="af-avatar af-avatar--guest"><img class="af-am-control-avatar-image" src="images/default_avatar.png" alt=""></span>'; return '<a class="af-avatar" href="member.php?action=profile&amp;uid='.$user['uid'].'"><img class="'.$options['img_class'].'" src="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2236%22 height=%2236%22%3E%3Crect width=%2236%22 height=%2236%22 fill=%22%23579%22/%3E%3C/svg%3E" alt="Player"></a>'; }
function my_date($format, $stamp) { return '<span title="MyBB date">recently</span>'; }
class MenuFixtureDB {
    public array $overrides = [];
    function table_exists($table) { return false; }
    function escape_string($s) { return addslashes($s); }
    function simple_select($table, $fields='*', $where='', $options=[]) {
        $rows = array_values($this->overrides);
        if ($where) $rows = array_values(array_filter($rows, fn($r) => str_contains($where, "item_key='".$r['item_key']."'") && str_contains($where, "source_addon='".$r['source_addon']."'")));
        if (str_contains($fields, 'COUNT')) $rows = [['total'=>count($rows)]];
        return (object)['rows'=>$rows, 'i'=>0];
    }
    function fetch_array($q) { return $q->rows[$q->i++] ?? null; }
    function fetch_field($q, $key) { return ($this->fetch_array($q) ?? [])[$key] ?? null; }
    function insert_query($table, $row) { $this->overrides[$row['source_addon'].'::'.$row['item_key']] = $row; }
    function update_query($table, $row, $where) { foreach ($this->overrides as &$r) if (str_contains($where, "item_key='".$r['item_key']."'")) $r = array_replace($r, $row); }
}
$custom = ['id'=>1,'enabled'=>1,'container'=>'user_drawer','location'=>'panel','section'=>'settings','sort_order'=>5,'slug'=>'custom_settings','title'=>'ACP custom action','url'=>'misc.php?action=custom','icon'=>'fa-solid fa-star'];
$cache = new class($custom) { function __construct(public array $custom) {} function read($key) { return ['items'=>[$this->custom]]; } };
$db = new MenuFixtureDB;
$mybb = (object)['settings'=>['bburl'=>'https://forum.test'],'user'=>['uid'=>42,'username'=>'Player','lastvisit'=>123],'usergroup'=>[],'post_code'=>'csrf-token'];
$lang = new class { function load($file) {} };
$theme_select = '<form id="theme_select"><select name="theme"><option>Тёмная</option></select></form>';
require AF_ADDONS.'advancedmenu/advancedmenu.php';
require AF_ADDONS.'adaptivethemeframework/adaptivethemeframework.php';
$GLOBALS['af_theme_switcher_preference_providers']['adaptivethemeframework'] = 'af_adaptivethemeframework_render_theme_preferences';
require AF_ADDONS.'advancedelementtheme/advancedelementtheme.php';
$plugins = new class { public array $hooks=[]; function add_hook(...$args) { $this->hooks[]=$args; } };
af_advancedelementtheme_init(); af_advancedelementtheme_init();
if (count($plugins->hooks)!==1 || $plugins->hooks[0][0]!=='misc_start') throw new RuntimeException('Preference endpoint hook missing/duplicated');
af_menu_collect_registry();
af_menu_register_item(['key'=>'fixture_main','label'=>'Main registry','icon'=>'fa-solid fa-house','default_sortorder'=>1,'default_container'=>'main','action'=>['url'=>'index.php']]);
af_menu_register_item(['key'=>'fixture_secondary','label'=>'Secondary registry','icon'=>'fa-solid fa-book','default_container'=>'secondary','action'=>['url'=>'help.php']]);
af_menu_register_item(['key'=>'fixture_hidden','label'=>'Hidden registry','default_container'=>'main','visibility'=>false]);
af_menu_register_item(['key'=>'fixture_modal','label'=>'Modal registry','icon'=>'fa-solid fa-users','type'=>'modal','default_container'=>'secondary','action'=>['trigger_selector'=>'#fixture-modal-trigger','modal_selector'=>'#fixture-modal']]);
$_SERVER['REQUEST_URI']='/index.php';
$member = af_advancedmenu_render_frontend_nav();
if (str_contains($member,'Hidden registry') || substr_count($member,'class="af-am-rail"')!==1) throw new RuntimeException('Rail/visibility contract');
if (!(strpos($member,'af-am-avatar-control') < strpos($member,'af-am-main') && strpos($member,'af-am-main') < strpos($member,'af-am-secondary') && strpos($member,'af-am-secondary') < strpos($member,'af-am-user-controls'))) throw new RuntimeException('Rail group order');
foreach (['profile','links','settings','theme'] as $section) if (substr_count($member, 'data-af-am-category="'.$section.'"') !== 1) throw new RuntimeException('Missing category '.$section);
foreach (['Main registry','Secondary registry','ACP custom action','logoutkey=csrf-token','<span title="MyBB date">recently</span>','aria-describedby="af-am-account-tooltip"','Стихийные анимации','name="effects_enabled"'] as $needle) if (!str_contains($member,$needle)) throw new RuntimeException('Missing '.$needle);
if (substr_count($member,'id="af-am-user-drawer"') !== 1 || str_contains($member,'af-am-drawer-identity') || str_contains($member,'af-am-burger')) throw new RuntimeException('Duplicate member identity/drawer or legacy burger');
if (substr_count($member,'>ACP custom action<') !== 1) throw new RuntimeException('Custom action duplicated');
$mybb->user=['uid'=>0];
$guest=af_advancedmenu_render_frontend_nav();
if (substr_count($guest,'data-af-am-category=') !== 1 || !str_contains($guest,'data-af-am-category="theme"') || !str_contains($guest,'af-am-guest-avatar') || str_contains($guest,'af-am-burger') || str_contains($guest,'data-af-am-panel="profile"')) throw new RuntimeException('Guest appearance/privacy contract');
foreach (['Добро пожаловать, гость!', 'images/default_avatar.png', 'atf-forum-layout-option', 'effects_postbit'] as $needle) if (!str_contains($guest,$needle)) throw new RuntimeException('Guest control missing '.$needle);
if (in_array('--browser-fixture',$argv,true)) { echo json_encode(compact('member','guest')); exit; }
echo "AdvancedMenu Game UI: registry containers/custom items, four sections, avatar/date, single drawer and guest controls passed.\n";

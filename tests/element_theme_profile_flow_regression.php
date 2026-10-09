<?php
define('IN_MYBB', true);
define('AF_CWF_TABLE', 'af_characterworkflow');
define('TIME_NOW', 1);
define('TABLE_PREFIX', 'mybb_');
define('AF_ADDONS', dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/');
function profile_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function htmlspecialchars_uni($value): string { return htmlspecialchars((string)$value, ENT_QUOTES); }
function af_kb_get_public_type_options($type, $limit = 500): array { return [['key' => 'fire'], ['key' => 'shadow']]; }
function af_characterworkflow_resolve_active_application(int $uid): ?array { return $uid === 1 ? ['tid' => 101, 'relation' => ['uid' => 1, 'accepted' => 1]] : ($uid === 2 ? ['tid' => 102, 'relation' => ['uid' => 2]] : null); }
function af_cwf_db_escape_array(array $row): array { return $row; }
function af_atf_get_profile_character_payload(int $tid): array { return ['tid' => $tid, 'fields' => ['character_element' => ['key' => 'character_element', 'value' => 'Огонь', 'raw' => $tid === 101 ? 'fire' : 'shadow', 'html' => 'Огонь']]]; }
function af_frontend_asset_allowed($addon, $resource = null, $context = null, $facts = []): bool { return !empty($facts['has_element_surface']); }
$mybb = (object)['settings' => ['bburl' => 'https://forum.test'], 'user' => ['uid' => 99]];
$db = new class {
    public array $rows = [101 => ['tid' => 101, 'state' => 'approved'], 102 => ['tid' => 102, 'state' => 'draft']];
    public int $reads = 0;
    function table_exists($name): bool { return $name === AF_CWF_TABLE; }
    function simple_select($table, $fields, $where, $options = []) {
        ++$this->reads; preg_match('/IN \(([^)]+)\)/', $where, $m);
        return (object)['rows' => array_values(array_intersect_key($this->rows, array_flip(array_map('intval', explode(',', $m[1]))))), 'i' => 0];
    }
    function fetch_array($q) { return $q->rows[$q->i++] ?? false; }
    function update_query($table, $data, $where) { $this->rows[(int)substr($where, 4)] = $data; }
    function insert_query($table, $data) { $this->rows[$data['tid']] = $data; }
}; $cache = null;
$workflowSource = file_get_contents(AF_ADDONS . 'characterworkflow/characterworkflow.php');
foreach (['af_cwf_preload_rows', 'af_cwf_get_row', 'af_cwf_upsert_row'] as $function) {
    preg_match('/function ' . $function . '\(.*?\n\}/s', $workflowSource, $match); eval($match[0]);
}
require AF_ADDONS . 'advancedelementtheme/advancedelementtheme.php';
require AF_ADDONS . 'advancedprofileui/advancedprofileui.php';
require AF_ADDONS . 'adaptivethemeframework/adaptivethemeframework.php';
$profiles = [];
foreach ([1 => 'fire', 2 => '', 3 => ''] as $uid => $expected) {
    $memprofile = ['uid' => $uid, 'username' => 'Character'];
    unset($GLOBALS['af_apui_profile_element']); // ATF must not depend on APUI hook output globals.
    af_adaptivethemeframework_compose_profile();
    profile_check($GLOBALS['atf_profile_context']['appearance']['element_theme_key'] === $expected, 'Approved/draft/missing profile identity');
    $GLOBALS['atf_profile_context']['appearance']['element_theme_key'] = '';
    $GLOBALS['af_apui_profile_element'] = 'fire'; // Correct for owner 1, forbidden for draft/missing owners.
    $html = '<head></head><body class="atf-active atf-profile-page af-apui-member-profile-page"><main class="atf-profile" data-element=""><div class="atf-profile-hero"><h2 class="atf-profile-hero__name">Name</h2></div><div class="atf-profile__workspace"><nav class="atf-profile__navigation"><a class="atf-profile-nav__item is-active">Info</a></nav><section class="atf-profile__panel"><h2>Character</h2><button>Ability</button></section></div></main>';
    af_advancedelementtheme_pre_output($html);
    $profiles[$uid] = $html;
    profile_check(str_contains($html, '<main class="atf-profile" data-element="' . $expected . '" data-element-surface="profile">'), 'Installed root without attributes lost profile context');
    profile_check(str_contains($html, 'data-af-element-effect-host="profile-page"') && substr_count($html, 'data-element="' . $expected . '"') === 2, 'Body and accent root must share owner context');
    unset($GLOBALS['atf_profile_context']); // Missing ATF globals must not preserve a stale markup key.
    $stale = '<main class="atf-profile" data-element="shadow"></main>'; af_advancedelementtheme_pre_output($stale);
    profile_check(str_contains($stale, 'data-element="' . $expected . '"'), 'Stale markup bypassed approved ATF profile context');
    profile_check(str_contains($html, '<div class="atf-profile-hero">') && str_contains($html, '<div class="atf-profile__workspace">'), 'Class prefixes falsely marked child nodes as surface roots');
}
// Same workflow source gates all user surfaces; application is the explicit exception.
foreach (['under_review', 'approved', 'under_review', 'rejected', 'revoked', 'approved'] as $state) {
    af_cwf_upsert_row(101, ['state' => $state]);
    $expected = $state === 'approved' ? 'fire' : '';
    foreach (['profile', 'postbit', 'sheet'] as $surface) {
        profile_check(af_elementtheme_resolve_surface_key(1, $surface, 'fire', 101) === $expected, $state . ' eligibility for ' . $surface);
    }
    profile_check(af_apui_profile_element_key(1) === $expected, 'Profile cache survived moderation change');
    profile_check(af_elementtheme_resolve_surface_key(1, 'application', 'fire', 101) === 'fire', 'Pending topic lost explicit exception');
}
af_elementtheme_preload_surface_contexts([1, 2, 3]);
$reads = $db->reads;
for ($i = 0; $i < 60; ++$i) {
    foreach ([1 => 'fire', 2 => '', 3 => ''] as $uid => $key) {
        profile_check(af_elementtheme_resolve_surface_key($uid, 'postbit', 'fire') === $key, 'Cross-author eligibility');
    }
}
profile_check($db->reads === $reads, 'Repeated posts queried workflow');
profile_check(af_elementtheme_resolve_surface_key(1, 'sheet', 'fire', 999) === '', 'Different sheet/application relation leaked styling');
profile_check(af_elementtheme_resolve_surface_key(1, 'profile', 'unknown') === '', 'Unknown KB key acquired style');
$before = file_get_contents(AF_ADDONS . 'adaptivethemeframework/templates/member_profile.html');
profile_check(str_contains($before, 'data-af-element-effect-host') && str_contains($before, "data-element=\"{\$atf_profile_context['appearance']['element_theme_key']}\""), 'ATF template root contract');
if (in_array('--browser-fixture', $argv ?? [], true)) { echo json_encode($profiles, JSON_UNESCAPED_SLASHES); exit; }
echo "ElementTheme profile flow: approved workflow/raw key -> APUI -> ATF -> installed DOM; draft/missing neutral; exact root classes passed.\n";

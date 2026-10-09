<?php
define('IN_MYBB', true);
define('IN_ADMINCP', true);
define('TABLE_PREFIX', 'mybb_');
define('AF_ADDONS', dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/');
function element_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function htmlspecialchars_uni($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$kbKeys = ['fire', 'shadow', 'water']; $kbCalls = 0; $kbUnavailable = false;
function af_kb_get_public_type_options(string $type, int $limit = 500): array {
    global $kbKeys, $kbCalls, $kbUnavailable;
    ++$kbCalls;
    element_check($type === 'arpg_element', 'Identity must come from arpg_element');
    if ($kbUnavailable) throw new RuntimeException('KB unavailable');
    return array_map(static fn($key) => ['key' => $key, 'label_ru' => $key === 'shadow' ? 'Тьма' : $key], $kbKeys);
}
$cache = new class {
    public array $data = [];
    function read($key) { return $this->data[$key] ?? false; }
    function update($key, $value) { $this->data[$key] = $value; }
    function delete($key) { unset($this->data[$key]); }
};
$db = new class {
    public int $queries = 0;
    public array $rows = ['af_element_theme_styles' => [], 'af_element_theme_surfaces' => []];
    public array $created = [];
    function build_create_table_collation() { return 'DEFAULT CHARSET=utf8mb4'; }
    function table_exists($table) { return isset($this->rows[$table]); }
    function write_query($sql) { $this->created[] = $sql; preg_match('/CREATE TABLE mybb_(\w+)/', $sql, $name); $this->rows[$name[1]] = []; }
    function escape_string($value) { return addslashes($value); }
    function simple_select($table, $fields = '*') { ++$this->queries; return (object)['rows' => $this->rows[$table], 'i' => 0]; }
    function fetch_array($query) { return $query->rows[$query->i++] ?? false; }
    function delete_query($table, $where) {
        preg_match("/element_key='([^']+)'/", $where, $key);
        preg_match("/surface='([^']+)'/", $where, $surface);
        $this->rows[$table] = array_values(array_filter($this->rows[$table], static fn($row) => $row['element_key'] !== $key[1] || (isset($surface[1]) && $row['surface'] !== $surface[1])));
    }
    function insert_query($table, $row) { $row['palette_json'] = stripslashes($row['palette_json']); $this->rows[$table][] = $row; }
};
$mybb = new class {
    public array $settings = ['bburl' => 'https://forum.test'];
    public string $request_method = 'get';
    function get_input($key) { return ''; }
};
$permission = true; $permissionCalls = [];
function af_frontend_asset_allowed($addon, $resource = null, $context = null, $facts = []): bool {
    global $permission, $permissionCalls;
    $permissionCalls[] = [$addon, $facts];
    return $permission && !empty($facts['has_element_surface']);
}
require AF_ADDONS . 'advancedelementtheme/advancedelementtheme.php';
require AF_ADDONS . 'advancedelementtheme/admin.php';
element_check(array_keys(af_elementtheme_get_elements()) === $kbKeys, 'Canonical registry differs from KB');
element_check(af_elementtheme_resolve_key(' SHADOW ') === 'shadow', 'Canonical normalization');
element_check(af_elementtheme_resolve_key('dark') === 'shadow', 'Legacy alias');
element_check(!af_elementtheme_is_known_key('dark'), 'Alias must not become an identity');
element_check(af_elementtheme_resolve_key('foobar') === '' && af_elementtheme_get_style('foobar') === [], 'Unknown must be neutral');
element_check(af_elementtheme_resolve_key('quantum') === '', 'Unbound source style must not authorize identity');
element_check(af_elementtheme_get_rows()['quantum']['state'] === 'Legacy / unbound', 'Unbound style missing');
$kbKeys[] = 'quantum'; $kbKeys[] = 'new_element'; unset($GLOBALS['af_elementtheme_elements']);
element_check(af_elementtheme_resolve_key('quantum') === 'quantum', 'Dynamic key requires PHP edits');
element_check(af_elementtheme_get_rows()['quantum']['state'] === 'Bound', 'Existing style did not bind automatically');
element_check(af_elementtheme_get_rows()['new_element']['state'] === 'Missing style', 'Missing style status');
ob_start(); AF_Admin_Advancedelementtheme::dispatch(); $admin = ob_get_clean();
element_check(str_contains($admin, 'Тьма') && str_contains($admin, 'Missing style') && str_contains($admin, 'Legacy / unbound'), 'ACP live registry/statuses');
$kbKeys[] = 'dark'; unset($GLOBALS['af_elementtheme_elements']);
element_check(af_elementtheme_resolve_key('dark') === 'dark', 'Real KB identity must win over alias');
array_pop($kbKeys); unset($GLOBALS['af_elementtheme_elements']);
af_elementtheme_save_style('dark', ['main' => '#112233', 'accent' => '#445566']);
element_check($db->rows['af_element_theme_styles'][0]['element_key'] === 'shadow', 'New style persisted alias identity');
af_elementtheme_save_style('shadow', ['soft' => 'rgba(1,2,3,.4)'], 'profile');
element_check(af_elementtheme_get_surface_style('shadow', 'profile')['main'] === '#112233', 'Surface lost global inheritance');
element_check(af_elementtheme_get_surface_style('shadow', 'profile')['soft'] === 'rgba(1,2,3,.4)', 'Surface override missing');
element_check(af_elementtheme_get_surface_style('shadow', 'sheet')['soft'] !== 'rgba(1,2,3,.4)', 'Override leaked into another surface');
try { af_elementtheme_save_style('shadow', ['main' => '#fff;}body{color:red']); throw new RuntimeException('CSS injection accepted'); }
catch (InvalidArgumentException $e) {}
$css = af_elementtheme_overrides()['css'];
element_check(str_contains($css, '[data-element="shadow"][data-element-surface="profile"]'), 'Surface CSS lacks scope');
element_check(!str_contains($css, 'body'), 'CSS scope escaped');
af_elementtheme_save_style('shadow', [], 'profile');
element_check(af_elementtheme_get_surface_style('shadow', 'profile')['soft'] === af_elementtheme_get_style('shadow')['soft'], 'Reset failed');
// One cold registry/cache load for any number of posts; next request uses MyBB presentation cache.
af_elementtheme_invalidate(); unset($GLOBALS['af_elementtheme_elements']); $db->queries = 0; $kbCalls = 0;
for ($i = 0; $i < 100; ++$i) { af_elementtheme_resolve_key('shadow'); af_elementtheme_get_surface_style('shadow', 'postbit'); }
element_check($kbCalls === 1 && $db->queries === 2, 'N+1 KB/style lookups');
unset($GLOBALS['af_elementtheme_overrides']); af_elementtheme_get_style('shadow');
element_check($db->queries === 2, 'Persistent style cache missed');
$page = '<html><head></head><body><div class="af-atf-display" data-element="dark"></div><div class="af-cs-page" data-element="shadow"></div><article class="atf-post af-atf-display" data-element="shadow"></article><main class="atf-profile" data-element="shadow"></main><div class="af-apui-profile-page" data-element="foobar"></div></body></html>';
af_advancedelementtheme_pre_output($page);
foreach (['application', 'sheet', 'postbit', 'profile'] as $surface) element_check(str_contains($page, 'data-element-surface="' . $surface . '"'), 'Surface missing: ' . $surface);
element_check(!str_contains($page, 'data-element="dark"') && !str_contains($page, 'data-element="foobar"'), 'Legacy/unknown HTML identity');
element_check(substr_count($page, 'data-af-element-theme>') === 1 && str_contains($page, 'data-af-element-theme-overrides'), 'Base/override delivery');
$previous = $page; af_advancedelementtheme_pre_output($page); element_check($page === $previous, 'Duplicate palette injection');
$plain = '<html><head></head><body>No component</body></html>'; $before = $plain; af_advancedelementtheme_pre_output($plain); element_check($plain === $before, 'Unrelated page loaded assets');
$permission = false; $denied = '<head></head><div class="af-cs-page"></div>'; $before = $denied; af_advancedelementtheme_pre_output($denied); element_check($denied === $before, 'Permission ignored'); $permission = true;
$fragment = '<div class="af-cs-page" data-element="shadow"></div>'; af_advancedelementtheme_pre_output($fragment); element_check(!str_contains($fragment, '<link') && str_contains($fragment, 'data-element-surface="sheet"'), 'Fragment assets/surface contract');
$trigger = '<head></head><a>Open sheet</a>'; $GLOBALS['af_charactersheets_has_frontend_component'] = true; af_advancedelementtheme_pre_output($trigger); element_check(str_contains($trigger, 'data-af-element-theme'), 'Modal caller palette missing'); unset($GLOBALS['af_charactersheets_has_frontend_component']);
// Actual postbit consumer retains its approved-payload source and remains neutral without it.
function af_apui_get_profile_character_payload(int $uid): array { return $uid === 42 ? ['fields' => ['character_element' => ['value' => 'shadow']]] : []; }
require AF_ADDONS . 'adaptivethemeframework/adaptivethemeframework.php';
element_check(af_adaptivethemeframework_post_element(42) === 'shadow' && af_adaptivethemeframework_post_element(43) === '', 'Postbit approved/neutral gating');
// Actual application renderer, with only its direct dependencies mocked.
function af_atf_get_values_by_tid($tid) { return [1 => 'shadow']; }
function af_atf_is_application_archive_forum($fid, $tid) { return false; }
function af_atf_get_fields_for_forum($fid) { return [['fieldid' => 1, 'name' => 'character_element', 'type' => 'text', 'show_thread' => 1, 'title' => 'Стихия']]; }
function af_atf_preload_field_kb_entries($fields, $values) {}
function af_atf_kb_preload_entries($pairs) {}
function af_atf_format_value_for_display($field, $value) { return htmlspecialchars_uni($value); }
function af_atf_get_wiki_area($field) { return 'main'; }
$templates = new class { function get($name) { return '<div class=\"af-atf-display\"></div>'; } };
$atf = file_get_contents(AF_ADDONS . 'advancedthreadfields/advancedthreadfields.php');
preg_match('/function af_atf_build_display_block_for_tid_fid\(.*?\n\}\r?\n/s', $atf, $function); eval($function[0]);
$app = af_atf_build_display_block_for_tid_fid(12, 34);
element_check(str_contains($app, 'data-element="shadow"') && str_contains($app, 'data-element-surface="application"'), 'Application renderer contract');
// Sheet resolver block and template root use the same canonical key.
$sheetSource = file_get_contents(AF_ADDONS . 'charactersheets/modules/render.php');
$sheetStart = strpos($sheetSource, '$sheet_element_theme_key = function_exists(');
$sheetResolver = substr($sheetSource, $sheetStart, strpos($sheetSource, ';', $sheetStart) - $sheetStart + 1);
$element_value = 'dark'; eval($sheetResolver);
$sheetTemplate = file_get_contents(AF_ADDONS . 'charactersheets/templates/charactersheet_inner_arpg.html');
$sheetRoot = explode('>', $sheetTemplate, 2)[0];
$sheetRoot = str_replace('{$sheet_element_theme_key}', $sheet_element_theme_key, $sheetRoot);
element_check(str_contains($sheetRoot, 'data-element="shadow"') && str_contains($sheetRoot, 'data-element-surface="sheet"'), 'Sheet rendered identity/surface');
// Profile uses the approved provider; missing approved character stays neutral.
function af_apui_get_approved_profile_character_payload(int $uid): array { return af_apui_get_profile_character_payload($uid); }
$profileSource = file_get_contents(AF_ADDONS . 'advancedprofileui/advancedprofileui.php');
preg_match('/function af_apui_profile_character_field_value\(.*?\n\}/s', $profileSource, $profileField); eval($profileField[0]);
preg_match('/function af_apui_profile_element_key\(.*?\n\}/s', $profileSource, $profileKeyFunction); eval($profileKeyFunction[0]);
$profileStart = strpos($profileSource, '$approvedElementField = (array)(($approvedCharacterPayload');
$profileEnd = strpos($profileSource, "    if (!empty(\$sheetPayload['enabled'])", $profileStart);
$profileResolver = substr($profileSource, $profileStart, $profileEnd - $profileStart);
foreach ([42 => 'shadow', 43 => ''] as $uid => $expected) {
    $approvedCharacterPayload = af_apui_get_approved_profile_character_payload($uid);
    eval($profileResolver);
    element_check($GLOBALS['af_apui_profile_element'] === $expected, 'Profile approved/neutral identity');
    $profileTemplate = file_get_contents(AF_ADDONS . 'advancedprofileui/templates/member_profile.html');
    $profileTemplate = str_replace('{$af_apui_profile_element}', $GLOBALS['af_apui_profile_element'], $profileTemplate);
    element_check(str_contains($profileTemplate, 'data-element="' . $expected . '"') && str_contains($profileTemplate, 'data-element-surface="profile"'), 'Profile rendered key/surface');
}
foreach (['charactersheets/templates/charactersheet_inner.html' => 'sheet', 'charactersheets/templates/charactersheet_inner_arpg.html' => 'sheet', 'adaptivethemeframework/templates/postbit_classic.html' => 'postbit', 'advancedprofileui/templates/member_profile.html' => 'profile', 'adaptivethemeframework/templates/member_profile.html' => 'profile'] as $file => $surface) {
    $template = file_get_contents(AF_ADDONS . $file);
    element_check(str_contains($template, 'data-element-surface="' . $surface . '"'), 'Template surface: ' . $file);
}
$kbUnavailable = true; unset($GLOBALS['af_elementtheme_elements']);
element_check(af_elementtheme_resolve_key('shadow') === '', 'Unavailable KB must fail neutral');
$kbUnavailable = false; $kbKeys = []; unset($GLOBALS['af_elementtheme_elements']);
element_check(af_elementtheme_resolve_key('shadow') === '' && af_elementtheme_get_rows()['shadow']['state'] === 'Legacy / unbound', 'Deleted identity deleted style or stayed valid');
$db->rows = []; af_elementtheme_invalidate(); element_check(af_elementtheme_get_style('new_element') === [], 'Absent schema failure');
af_advancedelementtheme_activate(); af_advancedelementtheme_activate();
element_check(count($db->created) === 2 && isset($db->rows['af_element_theme_styles'], $db->rows['af_element_theme_surfaces']), 'Activation/reactivation is not idempotent');
element_check(!str_contains(file_get_contents(AF_ADDONS . 'advancedelementtheme/advancedelementtheme.php'), 'update_query(\'templates\''), 'Addon owns templates');
echo "element theme regression: OK (registry, aliases, dynamic binding, ACP, palettes, surfaces, assets, gating, cache)\n";

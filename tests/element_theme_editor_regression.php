<?php
define('IN_MYBB', true);
define('IN_ADMINCP', true);
define('TABLE_PREFIX', 'mybb_');
define('AF_ADDONS', dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/');
define('MYBB_ROOT', dirname(__DIR__) . '/');
function editor_check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function htmlspecialchars_uni($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$bootstrapCalls = 0;
function af_get_addon_bootstrap_path(string $id): ?string {
    global $bootstrapCalls; ++$bootstrapCalls;
    editor_check($id === 'knowledgebase', 'Only the public KB dependency should be loaded');
    return AF_ADDONS . $id . '/knowledgebase.php';
}
$mybb = new class {
    public array $settings = ['bburl' => 'https://forum.test'];
    public array $input = [];
    public string $request_method = 'get';
    public string $post_code = 'test-key';
    function get_input($key) { return is_scalar($this->input[$key] ?? '') ? (string)($this->input[$key] ?? '') : ''; }
};
$db = new class {
    public array $rows = [
        'af_kb_entries' => [['key' => 'fire', 'title_ru' => 'Огонь', 'title_en' => 'Fire'], ['key' => 'shadow', 'title_ru' => 'Тьма', 'title_en' => 'Shadow']],
        'af_element_theme_styles' => [['element_key' => 'fire', 'palette_json' => '{"main":"#abcdef","soft":"rgba(1,2,3,.2)"}']],
        'af_element_theme_surfaces' => [],
    ];
    public int $kbQueries = 0;
    public bool $failKb = false;
    function table_exists($table) { return isset($this->rows[$table]); }
    function escape_string($value) { return addslashes($value); }
    function simple_select($table, $fields = '*', $where = '', $options = []) {
        if ($table === 'af_kb_entries') { ++$this->kbQueries; if ($this->failKb) throw new RuntimeException('Mock KB resolver failure'); editor_check(str_contains($where, "type='arpg_element'"), 'Wrong identity type'); }
        return (object)['rows' => $this->rows[$table], 'i' => 0];
    }
    function fetch_array($query) { return $query->rows[$query->i++] ?? false; }
    function delete_query($table, $where) {
        preg_match("/element_key='([^']+)'/", $where, $key); preg_match("/surface='([^']+)'/", $where, $surface);
        $this->rows[$table] = array_values(array_filter($this->rows[$table], static fn($r) => $r['element_key'] !== $key[1] || (isset($surface[1]) && $r['surface'] !== $surface[1])));
    }
    function insert_query($table, $row) { $row['palette_json'] = stripslashes($row['palette_json']); $this->rows[$table][] = $row; }
};
$cache = new class {
    public array $data = ['af_elementtheme' => ['styles' => ['fire' => ['main' => '#badbad']], 'surfaces' => [], 'css' => 'OLD CACHE']];
    function read($key) { return $this->data[$key] ?? false; }
    function update($key, $value) { $this->data[$key] = $value; }
    function delete($key) { unset($this->data[$key]); }
};
class ElementEditorRedirect extends RuntimeException {}
$flashes = []; $postChecks = 0;
function flash_message($message, $type) { global $flashes; $flashes[] = [$message, $type]; }
function admin_redirect($url) { throw new ElementEditorRedirect($url); }
function verify_post_check($key) { global $postChecks; ++$postChecks; editor_check($key === 'test-key', 'CSRF key not verified'); }
function editor_render(array $input): string {
    global $mybb; $mybb->input = $input; ob_start();
    try { AF_Admin_Advancedelementtheme::dispatch(); return ob_get_clean(); }
    catch (Throwable $e) { ob_end_clean(); throw $e; }
}
editor_check(!function_exists('af_kb_get_public_type_options'), 'Test must start without a bootstrapped KB API');
require AF_ADDONS . 'advancedelementtheme/advancedelementtheme.php';
require AF_ADDONS . 'advancedelementtheme/admin.php';
$list = editor_render(['action' => 'list']);
editor_check($bootstrapCalls === 1 && function_exists('af_kb_get_public_type_options'), 'ACP did not load real KB public API through AF helper');
editor_check($db->kbQueries === 1, 'Repeated KB queries');
$rows = af_elementtheme_get_rows();
editor_check($rows['fire']['state'] === 'Bound' && $rows['fire']['in_kb'] === true && $rows['fire']['element']['label_ru'] === 'Огонь', 'fire must bind automatically by key');
editor_check($rows['dark']['state'] === 'Alias → shadow' && $rows['dark']['kind'] === 'Legacy alias', 'Alias presented as independent canonical identity');
editor_check($rows['quantum']['state'] === 'Legacy / unbound', 'Standalone legacy status');
editor_check(str_contains($list, 'Palette preview') && str_contains($list, 'af-et-swatch') && str_contains($list, 'action=edit'), 'List preview/editor links');
editor_check(!str_contains($list, '<form') && !str_contains($list, 'textarea'), 'Editor is still below listing');
editor_check(af_elementtheme_get_style('fire')['main'] === '#abcdef', 'Legacy JSON adapter or cache format migration lost saved color');
editor_check(!str_contains(af_elementtheme_overrides()['css'], 'OLD CACHE'), 'Outdated compiler cache was reused');
$edit = editor_render(['action' => 'edit', 'element_key' => 'fire']);
editor_check(!str_contains($edit, 'af-et-list'), 'Editor includes the main table');
editor_check(str_contains($edit, 'Огонь') && str_contains($edit, 'arpg_element') && str_contains($edit, 'Открыть в Knowledge Base') && str_contains($edit, 'https://forum.test/' . htmlspecialchars_uni(af_kb_url(['type' => 'arpg_element', 'key' => 'fire']))), 'Visible KB binding/link');
foreach (['Global', 'Application', 'Sheet', 'Postbit', 'Profile'] as $tab) editor_check(str_contains($edit, '>' . $tab . '</a>'), 'Tab missing: ' . $tab);
editor_check(str_contains($edit, '--af-element-contrast') && str_contains($edit, 'Inherited:') && str_contains($edit, 'type="color"') && str_contains($edit, 'Custom CSS') && str_contains($edit, 'extra_names[]'), 'Editor fields missing');
$legacy = editor_render(['action' => 'edit', 'element_key' => 'quantum']);
editor_check(str_contains($legacy, 'пока отсутствует'), 'Unbound editor has no KB explanation');
$db->failKb = true; unset($GLOBALS['af_elementtheme_elements']);
$failed = editor_render(['action' => 'list']);
foreach (af_elementtheme_get_rows() as $row) editor_check($row['state'] === 'KB unavailable' && $row['in_kb'] === null && $row['kind'] === 'KB binding unavailable', 'KB failure mislabeled as legacy');
editor_check(str_contains($failed, 'Не удалось загрузить реестр arpg_element из Knowledge Base.') && str_contains($failed, 'Mock KB resolver failure') && !str_contains($failed, 'Legacy / unbound</td>'), 'Missing KB failure diagnostic');
try { af_elementtheme_save_style('fire', ['main' => '#fff']); throw new RuntimeException('Save with unavailable identity should fail'); }
catch (InvalidArgumentException $e) {}
$db->failKb = false; unset($GLOBALS['af_elementtheme_elements']);
$oldKb = $db->rows['af_kb_entries']; unset($db->rows['af_kb_entries']);
editor_check(!af_elementtheme_registry_status()['loaded'], 'Missing KB schema must be unavailable');
$db->rows['af_kb_entries'] = $oldKb; unset($GLOBALS['af_elementtheme_elements']);
$globalCss = '.hero { background-color: rgb(200, 10, 20); } :scope { border-left: 3px solid rgb(20, 30, 40); } @media (min-width: 1px) { .hero { padding-left: 7px; } }';
$surfaceCss = '.hero, :scope + [data-element="water"] .hero { color: rgb(10, 20, 200); } :scope { outline: 2px solid rgb(1, 2, 3); }';
af_elementtheme_save_style('fire', ['variables' => ['--af-element-main' => '#123456', '--af-element-glow' => '0 0 20px #000', '--af-element-gradient' => 'linear-gradient(#123, #456)'], 'custom_css' => $globalCss]);
af_elementtheme_save_style('fire', ['variables' => ['--af-element-accent' => 'hsl(120, 50%, 50%)'], 'custom_css' => $surfaceCss], 'profile');
editor_check(af_elementtheme_get_metadata('fire')['custom_css'] === $globalCss && af_elementtheme_get_metadata('fire', 'profile')['custom_css'] === $surfaceCss, 'Custom CSS storage lost global/surface source');
$stored = json_decode($db->rows['af_element_theme_styles'][0]['palette_json'], true);
editor_check(isset($stored['variables']['--af-element-glow']) && !isset($stored['main']), 'New JSON metadata shape');
$compiled = af_elementtheme_overrides()['css'];
editor_check(str_contains($compiled, '@scope ([data-element="fire"]:not([data-af-element-effect-only])) to (:scope [data-element])') && str_contains($compiled, '@scope ([data-element="fire"][data-element-surface="profile"]:not([data-af-element-effect-only]))'), 'Element/surface scoped compiler');
editor_check(str_contains($compiled, '--af-element-glow:0 0 20px #000 !important;'), 'Extra variable did not compile');
editor_check(af_elementtheme_get_surface_style('fire', 'sheet')['main'] === '#123456', 'Existing palette API global inheritance failed');
editor_check(af_elementtheme_get_surface_style('fire', 'profile')['accent'] === 'hsl(120, 50%, 50%)', 'Surface variable override lost');
foreach (['--global-color', 'color', '--af-element-text;}body'] as $name) {
    try { af_elementtheme_save_style('fire', ['variables' => [$name => '#fff'], 'custom_css' => '']); throw new RuntimeException('Invalid variable name accepted'); }
    catch (InvalidArgumentException $e) {}
}
foreach (['} body { color:red; }', '@import "other.css";', '@keyframes shared { from { color:red; } }', '@font-face {font-family:x;src:url(x)}', ':scope {content:"</style><script>"}', '/* never closed', '@\\6d edia { .hero {color:red} }'] as $css) {
    try { af_elementtheme_save_style('fire', ['variables' => [], 'custom_css' => $css]); throw new RuntimeException('Scope escape/global rule accepted: ' . $css); }
    catch (InvalidArgumentException $e) {}
}
$beforeOversizedSave = $db->rows['af_element_theme_styles'];
$oversized = []; for ($i = 0; $i < 20; ++$i) $oversized['--af-element-extra-' . $i] = str_repeat('a', 4000);
try { af_elementtheme_save_style('fire', ['variables' => $oversized, 'custom_css' => '']); throw new RuntimeException('Oversized metadata accepted'); }
catch (InvalidArgumentException $e) {}
editor_check($db->rows['af_element_theme_styles'] === $beforeOversizedSave, 'Invalid metadata deleted existing style before validation');
$mybb->request_method = 'post';
try {
    editor_render(['action' => 'edit', 'element_key' => 'dark', 'surface' => 'profile', 'my_post_key' => 'test-key', 'main' => '#987654', 'custom_css' => ':scope { border-radius: 8px; }', 'extra_names' => ['--af-element-text'], 'extra_values' => ['#ddd']]);
    throw new RuntimeException('Save did not redirect');
} catch (ElementEditorRedirect $e) {
    editor_check(str_contains($e->getMessage(), 'action=edit&element_key=shadow&surface=profile'), 'Save did not return to canonical editor tab');
}
editor_check($postChecks === 1 && end($flashes)[1] === 'success', 'Save CSRF/flash contract');
editor_check(af_elementtheme_get_metadata('shadow', 'profile')['variables']['--af-element-text'] === '#ddd', 'ACP extra variable submission failed');
editor_check(!isset(af_elementtheme_overrides()['surfaces']['dark']), 'Alias saved as canonical metadata');
$mybb->request_method = 'get';
$editor = editor_render(['action' => 'edit', 'element_key' => 'fire', 'surface' => 'profile']);
if (in_array('--browser-fixture', $argv ?? [], true)) echo json_encode(['editor' => $editor, 'css' => $compiled], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
else echo "ElementTheme ACP/bootstrap, metadata compatibility, scoped CSS and editor regressions: OK\n";

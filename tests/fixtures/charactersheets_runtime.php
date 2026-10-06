<?php
// Isolated MyBB boundary: all CharacterSheets/CWF logic and templates are real.
$root = $argv[1] ?? dirname(__DIR__, 2);
$mode = $argv[2] ?? 'full';
$script = match ($mode) {
    'showthread', 'threaded', 'escalate', 'moderation' => 'showthread.php',
    'member' => 'member.php', 'acp' => 'index.php',
    'accept', 'transfer', 'create_sheet', 'legacy', 'lifecycle' => 'misc.php',
    default => 'charactersheets.php',
};
define('IN_MYBB', 1);
define('MYBB_ROOT', $root . '/');
define('AF_ADDONS', MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/');
define('TABLE_PREFIX', 'mybb_');
define('TIME_NOW', 1000);
define('THIS_SCRIPT', $script);
if ($mode === 'acp') define('IN_ADMINCP', 1);
function cs_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
class CsResult { public int $position = 0; public function __construct(public array $rows) {} }
class CsDatabase {
    public array $queries = [];
    public array $sheet = ['id'=>1, 'tid'=>52, 'uid'=>7, 'slug'=>'thread-52', 'base_json'=>'{}', 'build_json'=>'{}', 'progress_json'=>'{}'];
    public function table_exists($table) { return in_array($table, ['af_charactersheets_accept', 'af_cs_sheets'], true); }
    public function escape_string($value) { return addslashes($value); }
    public function simple_select($table, $fields='*', $where='', $options=[]) {
        $this->queries[] = [$table, $fields, $where];
        $rows = match ($table) {
            'posts' => array_map(static fn($uid) => ['uid'=>$uid], [7,8,9,10,11]),
            'af_charactersheets_accept' => [['tid'=>52, 'uid'=>7, 'accepted'=>1, 'sheet_slug'=>'thread-52']],
            'af_cs_sheets' => str_contains($where, 'uid IN (') ? [['id'=>2, 'uid'=>8, 'slug'=>'fallback-8']] : [$this->sheet],
            'threads' => [['tid'=>52, 'fid'=>2, 'uid'=>7, 'subject'=>'Test Character']],
            'users' => [['uid'=>7, 'username'=>'Test owner']],
            default => [],
        };
        return new CsResult($rows);
    }
    public function fetch_array($result) { return $result->rows[$result->position++] ?? false; }
    public function fetch_field($result, $field) { return ($this->fetch_array($result) ?: [])[$field] ?? null; }
    public function write_query($sql) {
        $this->queries[] = ['sql', $sql];
        if (!str_contains($sql, 'WHERE r.uid IN')) return new CsResult([]);
        return new CsResult(array_map(static fn($uid) => [
            'uid'=>$uid, 'tid'=>45+$uid, 'live_tid'=>$uid === 9 ? 0 : 45+$uid, 'thread_uid'=>$uid === 10 ? 20 : $uid,
            'fid'=>$uid === 11 ? 99 : 2, 'firstpost'=>100+$uid, 'sheet_slug'=>$uid === 7 ? 'canonical-7' : '',
        ], [7, 8, 9, 10, 11]));
    }
    public function update_query(...$args) {}
}
class CsMybb {
    public array $settings = ['af_charactersheets_enabled'=>1, 'af_characterworkflow_target_forums'=>'2', 'bburl'=>'https://example.test'];
    public array $user = ['uid'=>0];
    public array $usergroup = [];
    public string $post_code = 'key';
    public string $request_method = 'POST';
    public function get_input($key, ...$args) {
        return ['slug'=>'thread-52', 'embed'=>$GLOBALS['mode'] === 'embed' ? '1' : '0', 'my_post_key'=>'key', 'action'=>match($GLOBALS['mode']) { 'accept'=>'af_charactersheets_accept', 'transfer'=>'af_charactersheets_transfer', 'create_sheet'=>'af_charactersheets_create_sheet', 'legacy'=>'af_charactersheet', default=>'' }][$key] ?? '';
    }
}
class CsTemplates {
    public function get($name) {
        $file = AF_ADDONS . 'charactersheets/templates/' . $name . '.html';
        if (!is_file($file)) $file = AF_ADDONS . 'charactersheets/templates/blocks/' . substr($name, strlen('charactersheet_')) . '.html';
        cs_check(is_file($file), 'Missing real template ' . $name);
        return addcslashes(file_get_contents($file), '\\"');
    }
}
function htmlspecialchars_uni($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function af_inv_equipment_slots() { return []; } // Optional Inventory provider boundary.
function forum_permissions($fid) { return ['canview'=>1, 'canviewthreads'=>1]; }
class CsActionBoundary extends RuntimeException {}
function verify_post_check($key) { cs_check($key === 'key', 'Invalid action token'); throw new CsActionBoundary('Reached verified action handler'); }
function error_no_permission() { throw new RuntimeException('No permission'); }
function af_load_addon_lang($id) {}
function af_frontend_asset_allowed($id, $asset, $route=null, $context=[]) { return !empty($context['has_charactersheet_component']); }
function af_add_css_once($url) { $GLOBALS['assets']['css'][$url] = true; }
function af_add_js_once($url) { $GLOBALS['assets']['js'][$url] = true; }
function output_page($html) {
    cs_check(str_contains($html, 'Test Character'), 'Valid sheet was not rendered');
    cs_check(str_contains($html, 'data-afcs-sheet-id="1"'), 'Inner sheet template did not render');
    cs_check(str_contains($html, 'data-af-layout="content-only"'), 'Direct/embed wrapper did not render');
    $urls = json_encode($GLOBALS['assets']);
    cs_check(str_contains($urls, 'charactersheets.js') && str_contains($urls, 'charactersheets.css'), 'Full route assets missing');
    cs_check(!str_contains($urls, 'charactersheets-trigger'), 'Trigger runtime leaked into full route');
    if (getenv('AF_TEST_HTML')) echo $html;
    else echo json_encode(['mode'=>$GLOBALS['mode'], 'rendered'=>true, 'modules'=>cs_modules(), 'queries'=>$GLOBALS['db']->queries, 'warnings'=>$GLOBALS['warnings']]);
}
function cs_modules(): array {
    return array_values(array_map('basename', array_filter(get_included_files(), static fn($path) => str_contains($path, '/charactersheets/modules/'))));
}
$db = new CsDatabase;
$mybb = new CsMybb;
$templates = new CsTemplates;
$theme = ['imgdir'=>'images'];
$headerinclude = '';
$lang = (object)[];
$assets = [];
$warnings = [];
set_error_handler(static function ($severity, $message) {
    // Two pre-existing nonfatal warnings in the sheet renderer are tracked,
    // not confused with this regression or silently discarded.
    if ($message !== 'Array to string conversion' && $message !== 'Undefined variable $page_title') throw new ErrorException($message, 0, $severity);
    $GLOBALS['warnings'][] = $message;
    return true;
});
try {
    require AF_ADDONS . 'charactersheets/charactersheets.php';
    if (in_array($mode, ['full', 'embed'], true)) {
        af_charactersheets_render_page(); // Real dispatch, calculator, renderer, eval and output.
    } elseif (in_array($mode, ['showthread', 'threaded'], true)) {
        require AF_ADDONS . 'characterworkflow/characterworkflow.php';
        $pids = $mode === 'threaded' ? '' : "pid IN (107,108)";
        $tid = 52;
        $post = ['uid'=>7];
        af_charactersheets_postbit_preload_current_page($post);
        $preloaded = count($db->queries);
        cs_check($preloaded === 3, 'Expected one author, one workflow and one fallback metadata query');
        foreach ([7=>'canonical-7', 8=>'fallback-8'] as $uid => $slug) {
            $payload = af_cs_get_postbit_sheet_payload($uid);
            cs_check($payload['sheet_slug'] === $slug, 'Canonical relation/fallback slug changed');
            cs_check(af_characterworkflow_resolve_active_application($uid)['uid'] === $uid, 'Workflow cache missed author');
        }
        foreach ([9,10,11] as $invalidUid) {
            cs_check(af_characterworkflow_resolve_active_application($invalidUid) === null, 'Orphan/wrong-owner/wrong-forum application was accepted');
            cs_check(!af_cs_get_postbit_sheet_payload($invalidUid)['enabled'], 'Invalid application exposed a sheet');
        }
        af_charactersheets_postbit_preload_current_page($post);
        cs_check(count($db->queries) === $preloaded, 'Query-per-author after batch preload');
        cs_check($db->queries[0][2] === ($mode === 'threaded' ? 'tid=52 AND uid>0' : 'pid IN (107,108) AND uid>0'), 'Author selection is not current-page/threaded aware');
        af_charactersheets_enqueue_assets();
        $urls = json_encode($assets);
        cs_check(str_contains($urls, 'charactersheets-trigger.js') && str_contains($urls, 'charactersheets-trigger.css'), 'Trigger assets missing');
        foreach (['bootstrap.php', 'render.php', 'calculator.php', 'ajax.php', 'sheets_crud.php', 'acp_skills.php'] as $heavy) cs_check(!in_array($heavy, cs_modules(), true), 'Heavy module on initial showthread: ' . $heavy);
        echo json_encode(['mode'=>$mode, 'modules'=>cs_modules(), 'queries'=>$db->queries, 'query_per_author'=>false]);
    } else {
        if (in_array($mode, ['accept','transfer','create_sheet','legacy'], true)) {
            try { af_charactersheets_misc_start(); } catch (CsActionBoundary $e) {}
            cs_check(in_array('bootstrap.php', cs_modules(), true) && in_array('sheets_crud.php', cs_modules(), true), 'Action wrapper did not load its dependencies');
        }
        // Legal lazy escalations (including lifecycle/ACP/moderation/action plans)
        // must safely combine lightweight and full modules in either order.
        if ($mode === 'moderation') af_charactersheets_handle_thread_move_for_acceptance([]);
        if ($mode === 'lifecycle') cs_check(af_charactersheets_is_installed(), 'Lifecycle install probe failed');
        foreach ([['frontend', 'metadata'], ['bootstrap', 'sheets_crud'], ['render', 'ajax', 'acp_skills'], ['metadata', 'frontend', 'bootstrap', 'sheets_crud']] as $plan) af_charactersheets_require_modules($plan);
        cs_check(af_charactersheets_zero_attributes() === array_fill_keys(['str','dex','con','int','wis','cha'], 0), 'Attribute API unavailable');
        $first = af_charactersheets_get_sheet_by_tid(52);
        $db->sheet['slug'] = 'after-write';
        cs_check(af_charactersheets_get_sheet_by_tid(52)['slug'] === 'after-write', 'Shared read cache returned stale data after mutation');
        echo json_encode(['mode'=>$mode, 'modules'=>cs_modules(), 'read_owner'=>(new ReflectionFunction('af_charactersheets_get_sheet_by_tid'))->getFileName(), 'frontend_owner'=>(new ReflectionFunction('af_charactersheets_is_enabled'))->getFileName()]);
    }
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}

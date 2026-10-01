<?php

declare(strict_types=1);

// Execute the production bundle synchronizer against an in-memory MyBB DB
// double. This covers the activation-critical row/state/force transitions
// without requiring a second MyBB installation in the test checkout.
$core = file_get_contents(dirname(__DIR__).'/inc/plugins/advancedfunctionality.php');
function extractFunction(string $source, string $name): string
{
    $start = strpos($source, 'function '.$name.'(');
    if ($start === false) throw new RuntimeException("Missing {$name}");
    $brace = strpos($source, '{', $start);
    $depth = 0;
    for ($i = $brace, $length = strlen($source); $i < $length; $i++) {
        if ($source[$i] === '{') $depth++;
        if ($source[$i] === '}' && --$depth === 0) return substr($source, $start, $i - $start + 1);
    }
    throw new RuntimeException("Unterminated {$name}");
}

define('AF_THEME_STYLESHEETS_TABLE', 'af_theme_stylesheets');
define('AF_THEME_BUNDLE_ADDON_ID', '__af_bundle__');
define('AF_THEME_BUNDLE_LOGICAL_ID', 'advancedstyles');
define('AF_THEME_BUNDLE_NAME', 'advancedstyles.css');
define('TIME_NOW', 1700000000);
define('TABLE_PREFIX', 'mybb_');

final class SchemaDb
{
    public array $columns = [
        'id', 'theme_tid', 'stylesheet_sid', 'addon_id', 'logical_id', 'stylesheet_name',
    ];
    public function table_exists(string $table): bool { return true; }
    public function escape_string(string $value): string { return $value; }
    public function assertSelectFields(string $fields): void
    {
        foreach (array_map('trim', explode(',', $fields)) as $field) {
            if (!in_array($field, $this->columns, true)) {
                throw new RuntimeException("[MyBB SQL Error (1054)] Unknown column '{$field}' in 'field list'");
            }
        }
    }
    public function write_query(string $sql): array
    {
        if (str_starts_with($sql, 'SHOW COLUMNS')) {
            return ['rows' => array_map(static fn(string $name): array => ['Field' => $name], $this->columns), 'position' => 0];
        }
        if (preg_match('~ADD COLUMN ([a-z_]+).* AFTER ([a-z_]+)~i', $sql, $match)) {
            if (!in_array($match[2], $this->columns, true)) throw new RuntimeException("Unknown column '{$match[2]}' in 'mybb_af_theme_stylesheets'");
            $position = array_search($match[2], $this->columns, true) + 1;
            array_splice($this->columns, $position, 0, [$match[1]]);
            return ['rows' => [], 'position' => 0];
        }
        throw new RuntimeException('Unexpected schema SQL: '.$sql);
    }
    public function fetch_array(array &$query): array|false { return $query['rows'][$query['position']++] ?? false; }
}

eval(extractFunction($core, 'af_db_table_columns'));
eval(extractFunction($core, 'af_theme_stylesheets_install_schema'));
$db = new SchemaDb();
$reproduced = '';
try {
    // This is the first new projection used by sync_bundle's legacy lookup.
    $db->assertSelectFields('stylesheet_sid,stylesheet_name,last_synced_checksum,manual_override');
} catch (RuntimeException $error) {
    $reproduced = $error->getMessage();
}
if ($reproduced !== "[MyBB SQL Error (1054)] Unknown column 'last_synced_checksum' in 'field list'") {
    throw new RuntimeException('Failed to reproduce the activation SQL error: '.$reproduced);
}
af_theme_stylesheets_install_schema();
$requiredColumns = ['source_file', 'seed_file', 'seed_checksum', 'last_synced_checksum', 'is_integrated', 'delivery_mode', 'discovered_from', 'is_admin_only', 'last_synced_at', 'manual_override', 'created_at', 'updated_at'];
foreach ($requiredColumns as $column) {
    if (!in_array($column, $db->columns, true)) throw new RuntimeException("Schema migration omitted {$column}");
}
$db->assertSelectFields('stylesheet_sid,stylesheet_name,last_synced_checksum,manual_override');

final class ActivationDb
{
    public array $styles = [];
    public array $registry = [];
    public bool $escapeValues = true;
    private int $nextSid = 10;

    public function table_exists(string $table): bool { return true; }
    public function write_query(string $sql): array
    {
        if (str_starts_with($sql, 'SHOW COLUMNS')) {
            $columns = [
                'id', 'theme_tid', 'stylesheet_sid', 'addon_id', 'logical_id', 'stylesheet_name',
                'source_file', 'seed_file', 'seed_checksum', 'last_synced_checksum', 'is_integrated',
                'delivery_mode', 'discovered_from', 'is_admin_only', 'last_synced_at', 'manual_override',
                'created_at', 'updated_at',
            ];
            return ['rows' => array_map(static fn(string $name): array => ['Field' => $name], $columns), 'position' => 0];
        }
        throw new RuntimeException('Unexpected SQL: '.$sql);
    }

    public function escape_string(string $value): string { return $this->escapeValues ? addslashes($value) : $value; }
    private function decodeStylesheetPayload(array $payload): array
    {
        // MyBB 1.8's query builder only adds surrounding SQL quotes. Simulate
        // the server decoding the value which the caller escaped exactly once.
        if (isset($payload['stylesheet'])) {
            if (preg_match("~(?<!\\\\)'~", (string)$payload['stylesheet'])) {
                throw new RuntimeException("You have an error in your SQL syntax near unescaped stylesheet quote");
            }
            $payload['stylesheet'] = stripslashes((string)$payload['stylesheet']);
        }
        return $payload;
    }
    public function simple_select(string $table, string $fields = '*', string $where = '', array $options = []): array
    {
        $rows = $table === 'themestylesheets' ? array_values($this->styles) : array_values($this->registry);
        $rows = array_values(array_filter($rows, static function (array $row) use ($where): bool {
            if (preg_match("~sid='?(\d+)'?~", $where, $m) && (int)($row['sid'] ?? $row['stylesheet_sid'] ?? 0) !== (int)$m[1]) return false;
            if (preg_match("~theme_tid='?(\d+)'?~", $where, $m) && (int)($row['theme_tid'] ?? 0) !== (int)$m[1]) return false;
            if (preg_match("~(?:^| )tid='?(\d+)'?~", $where, $m) && (int)($row['tid'] ?? 0) !== (int)$m[1]) return false;
            if (preg_match("~name='([^']+)'~", $where, $m) && (string)($row['name'] ?? '') !== $m[1]) return false;
            if (preg_match("~addon_id='([^']+)'~", $where, $m) && (string)($row['addon_id'] ?? '') !== $m[1]) return false;
            if (preg_match("~logical_id='([^']+)'~", $where, $m) && (string)($row['logical_id'] ?? '') !== $m[1]) return false;
            if (str_contains($where, "addon_id!='__af_bundle__'") && (string)($row['addon_id'] ?? '') === '__af_bundle__') return false;
            return true;
        }));
        return ['rows' => $rows, 'position' => 0];
    }
    public function fetch_array(array &$query): array|false
    {
        return $query['rows'][$query['position']++] ?? false;
    }
    public function insert_query(string $table, array $payload): int
    {
        if ($table === 'themestylesheets') {
            $payload = $this->decodeStylesheetPayload($payload);
            $sid = $this->nextSid++;
            $payload['sid'] = $sid;
            $this->styles[$sid] = $payload;
            return $sid;
        }
        $payload['id'] = count($this->registry) + 1;
        $this->registry[$payload['id']] = $payload;
        return $payload['id'];
    }
    public function update_query(string $table, array $payload, string $where): void
    {
        if ($table === 'themestylesheets') {
            $payload = $this->decodeStylesheetPayload($payload);
            $target =& $this->styles;
        } else {
            $target =& $this->registry;
        }
        foreach ($target as &$row) {
            if (preg_match("~sid='?(\d+)'?~", $where, $m) && (int)($row['sid'] ?? 0) !== (int)$m[1]) continue;
            if (preg_match("~(?:^| )id='?(\d+)'?~", $where, $m) && (int)($row['id'] ?? 0) !== (int)$m[1]) continue;
            if (preg_match("~theme_tid='?(\d+)'?~", $where, $m) && (int)($row['theme_tid'] ?? 0) !== (int)$m[1]) continue;
            if (str_contains($where, "addon_id!='__af_bundle__'") && (string)($row['addon_id'] ?? '') === '__af_bundle__') continue;
            $row = array_merge($row, $payload);
        }
    }
}

function af_theme_stylesheet_build_bundle(?string $onlyAddonId = null): array
{
    global $enabledAddonCss;
    $source = "/* AdvancedFunctionality theme bundle (structured v1). Edit sections in AF ACP. */\n\n";
    $sources = [];
    foreach ($enabledAddonCss as $addon => $css) {
        $source .= af_theme_stylesheet_encode_section([
            'addon_id' => $addon, 'logical_id' => 'main', 'source_file' => "assets/{$addon}.css",
            'seed_body_sha1' => sha1($css), 'seed_checksum' => sha1($css),
        ], $css)."\n";
        $sources[] = $addon.':assets/'.$addon.'.css:'.sha1($css);
    }
    return ['source' => $source, 'checksum' => sha1($source), 'sources' => $sources];
}
function af_theme_stylesheet_bundle_state(int $themeTid): array
{
    global $db;
    foreach ($db->registry as $row) {
        if ((int)$row['theme_tid'] === $themeTid && $row['addon_id'] === AF_THEME_BUNDLE_ADDON_ID) return $row;
    }
    return [];
}
eval(extractFunction($core, 'af_theme_stylesheet_section_id'));
eval(extractFunction($core, 'af_theme_stylesheet_encode_section'));
eval(extractFunction($core, 'af_theme_stylesheet_parse_bundle'));
eval(extractFunction($core, 'af_theme_stylesheet_validate_bundle'));
eval(extractFunction($core, 'af_theme_stylesheet_db_css'));
eval(extractFunction($core, 'af_theme_stylesheet_sql_stage'));
function af_theme_stylesheet_cache_row(int $themeTid, int $sid, string $css): array
{
    global $db;
    $db->update_query('themestylesheets', ['cachefile' => AF_THEME_BUNDLE_NAME], "sid='{$sid}'");
    return ['ok' => true];
}
eval(extractFunction($core, 'af_theme_stylesheet_sync_bundle'));

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
        exit(1);
    }
}

// Production upgrade state: bundle exists, registry does not.
$enabledAddonCss = ['layout' => ".card { grid-template-areas: 'avatar header' 'avatar body'; }"];
$db = new ActivationDb();
$custom = "/* hand edited pre-registry CSS */\n.custom { color: rebeccapurple; }";
$db->styles[7] = ['sid' => 7, 'tid' => 1, 'name' => AF_THEME_BUNDLE_NAME, 'stylesheet' => $custom, 'attachedto' => 'global'];
for ($cycle = 1; $cycle <= 3; $cycle++) {
    af_theme_stylesheet_sync_bundle(1, false);
    assertSameValue($custom, $db->styles[7]['stylesheet'], "reactivation {$cycle} changed user CSS");
    assertSameValue(1, count($db->registry), "reactivation {$cycle} duplicated registry row");
}
$state = array_values($db->registry)[0];
assertSameValue(sha1($custom), $state['last_synced_checksum'], 'adoption did not record the current CSS hash');
assertSameValue(1, $state['manual_override'], 'adopted CSS was not protected as a manual override');

// Clean install uses the real production stylesheet which exposed the SQL
// quoting bug. In particular it contains the single-quoted grid area names
// immediately before .af-aam-toast rules.
$productionCssPath = dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/advancedalertsandmentions/assets/advancedalertsandmentions.css';
$productionCss = file_get_contents($productionCssPath);
if (!is_string($productionCss)
    || !str_contains($productionCss, "'avatar header'")
    || !str_contains($productionCss, "'avatar body';")
    || !str_contains($productionCss, 'column-gap: 8px;')
    || !str_contains($productionCss, '.af-aam-toast')) {
    throw new RuntimeException('Production advancedalertsandmentions CSS fixture is missing');
}
$enabledAddonCss = [
    'advancedalertsandmentions' => $productionCss,
];
$db = new ActivationDb();
$db->escapeValues = false;
$stageDiagnostic = '';
try {
    af_theme_stylesheet_sync_bundle(1, false);
} catch (RuntimeException $error) {
    $stageDiagnostic = $error->getMessage();
}
if (!str_contains($stageDiagnostic, 'operation=sync_bundle theme_tid=1')
    || !str_contains($stageDiagnostic, 'function=af_theme_stylesheet_sync_bundle')
    || !str_contains($stageDiagnostic, 'stage=insert_themestylesheets')
    || !str_contains($stageDiagnostic, 'addon=__af_bundle__')) {
    throw new RuntimeException('Fresh INSERT exception lacks the SQL-stage diagnostic: '.$stageDiagnostic);
}

$db = new ActivationDb();
$first = af_theme_stylesheet_sync_bundle(1, false);
$sid = $first['sid'];
$secondTheme = af_theme_stylesheet_sync_bundle(2, false);
$secondSid = $secondTheme['sid'];
$generated = $db->styles[$sid]['stylesheet'];
for ($cycle = 1; $cycle <= 3; $cycle++) af_theme_stylesheet_sync_bundle(1, false);
assertSameValue($generated, $db->styles[$sid]['stylesheet'], 'generated bundle grew across activations');
assertSameValue(2, count($db->styles), 'fresh sync did not create one stylesheet per theme');
assertSameValue(2, count($db->registry), 'fresh sync did not create one registry row per theme');
assertSameValue(true, $sid > 0 && $secondSid > 0, 'fresh inserts did not return positive SIDs');
assertSameValue($sid, (int)$first['diagnostic']['registry_sid'], 'theme 1 registry/re-SELECT SID mismatch');
assertSameValue($secondSid, (int)$secondTheme['diagnostic']['registry_sid'], 'theme 2 registry/re-SELECT SID mismatch');
assertSameValue(true, $first['diagnostic']['insert_verified'], 'theme 1 INSERT re-SELECT was not confirmed');
assertSameValue(true, $secondTheme['diagnostic']['insert_verified'], 'theme 2 INSERT re-SELECT was not confirmed');
assertSameValue('global', $db->styles[$sid]['attachedto'], 'fresh bundle was not attached to every page');
assertSameValue(AF_THEME_BUNDLE_NAME, $db->styles[$sid]['cachefile'], 'fresh bundle cachefile was not registered');
$cleanParsed = af_theme_stylesheet_parse_bundle($db->styles[$sid]['stylesheet']);
assertSameValue(true, $cleanParsed['ok'], 'fresh bundle is not an authenticated structured bundle');
assertSameValue(count($enabledAddonCss), count($cleanParsed['sections']), 'fresh bundle omitted enabled CSS sections');
assertSameValue(count($enabledAddonCss), $first['source_count'], 'sync did not report all enabled CSS sources');
assertSameValue(count($cleanParsed['sections']), $first['section_count'], 'sync section count differs from persisted bundle');

// Exercise the public production entry point, not merely sync_bundle. Start
// from the exact production failure fixture: no stylesheet and no bundle row.
function af_theme_stylesheet_deduplicate_registry(): void {}
function af_discover_addons(): array { return [['id' => 'fixture']]; }
function af_discover_theme_stylesheets(?array $addons = null): array
{
    global $enabledAddonCss;
    $entries = [];
    foreach ($enabledAddonCss as $addon => $_css) {
        $entries[] = [
            'addon_id' => $addon, 'logical_id' => 'main', 'file' => "assets/{$addon}.css",
            'stylesheet_name' => "af_{$addon}.css", 'enabled_setting' => '',
            'addon_meta' => ['path' => __DIR__, 'name' => $addon], 'discovered_from' => 'fixture',
        ];
    }
    return $entries;
}
function af_get_theme_tids(): array { return [1]; }
function af_get_theme_stylesheet_source(array $meta, array $entry): ?array
{
    global $enabledAddonCss;
    $css = $enabledAddonCss[$entry['addon_id']] ?? null;
    return is_string($css) ? ['path' => __FILE__, 'source' => $css, 'checksum' => sha1($css)] : null;
}
function af_theme_stylesheet_repair_legacy_registry_row(int $themeTid, array $entry): void {}
function af_reconcile_theme_stylesheet_registry_state(int $themeTid, array $entry, ?array $seed = null): void {}
function af_ensure_theme_stylesheet_registry_row(int $themeTid, array $entry): void
{
    global $db;
    foreach ($db->registry as $row) {
        if ((int)$row['theme_tid'] === $themeTid && $row['addon_id'] === $entry['addon_id']) return;
    }
    $db->insert_query(AF_THEME_STYLESHEETS_TABLE, [
        'theme_tid' => $themeTid, 'stylesheet_sid' => 0, 'addon_id' => $entry['addon_id'],
        'logical_id' => $entry['logical_id'], 'stylesheet_name' => $entry['stylesheet_name'],
        'delivery_mode' => 'auto', 'manual_override' => 0,
    ]);
}
function af_theme_stylesheet_diagnostic_call(string $operation, string $context, callable $callback): mixed
{
    return $callback();
}
eval(extractFunction($core, 'af_sync_theme_stylesheets'));

$enabledAddonCss = [
    'layout' => '.layout { display: grid; }',
    'profile' => '.profile { container-type: inline-size; }',
    'menu' => '.menu { display: flex; }',
];
$db = new ActivationDb();
$sync = af_sync_theme_stylesheets(false);
assertSameValue([], $sync['errors'], 'public sync reported a fresh-creation error');
assertSameValue(1, $sync['created_or_updated'], 'public sync did not report bundle creation');
assertSameValue(1, count($db->styles), 'public sync did not create exactly one MyBB stylesheet');
$publicStyle = array_values($db->styles)[0];
$publicBundleRows = array_values(array_filter($db->registry, static fn(array $row): bool => ($row['addon_id'] ?? '') === AF_THEME_BUNDLE_ADDON_ID));
assertSameValue(1, count($publicBundleRows), 'public sync did not create exactly one bundle registry row');
assertSameValue((int)$publicStyle['sid'], (int)$publicBundleRows[0]['stylesheet_sid'], 'registry SID does not point at the created stylesheet');
assertSameValue('global', $publicStyle['attachedto'], 'public sync bundle attachment is not global');
assertSameValue(AF_THEME_BUNDLE_NAME, $publicStyle['cachefile'], 'public sync did not set the cachefile');
assertSameValue(true, trim($publicStyle['stylesheet']) !== '', 'public sync created an empty CSS body');
$publicParsed = af_theme_stylesheet_parse_bundle($publicStyle['stylesheet']);
assertSameValue(true, $publicParsed['ok'], 'public sync persisted an invalid bundle');
assertSameValue(count($enabledAddonCss), count($publicParsed['sections']), 'public sync source/section cardinality mismatch');
$publicSourceCount = count($enabledAddonCss);
$publicSectionCount = count($publicParsed['sections']);

// Rebuild the same bundle while several addons are disabled and enabled. The
// SQL payload must remain valid and the quotes must round-trip byte-for-byte.
unset($enabledAddonCss['layout'], $enabledAddonCss['quoted-content']);
af_theme_stylesheet_sync_bundle(1, false);
$disabledCss = af_theme_stylesheet_build_bundle()['source'];
assertSameValue($disabledCss, $db->styles[$sid]['stylesheet'], 'disable rebuild changed quoted CSS');
$enabledAddonCss['layout'] = ".card { grid-template-areas: 'avatar header' 'avatar body'; }";
$enabledAddonCss['quoted-content'] = ".quote::before { content: \"'\"; }";
af_theme_stylesheet_sync_bundle(1, false);
assertSameValue(af_theme_stylesheet_build_bundle()['source'], $db->styles[$sid]['stylesheet'], 'enable rebuild changed quoted CSS');

// An editor change remains byte-identical through activation.
$edited = $generated."\n/* manual section edit */";
$db->styles[$sid]['stylesheet'] = $edited;
af_theme_stylesheet_sync_bundle(1, false);
assertSameValue($edited, $db->styles[$sid]['stylesheet'], 'activation overwrote an edited registered bundle');

echo "AF activation SQL failure reproduced: {$reproduced}\n";
echo "AF fresh public sync fixture: SID={$publicStyle['sid']}; sources={$publicSourceCount}; sections={$publicSectionCount}.\n";
echo "AF production CSS fresh inserts: theme 1 SID={$sid}; theme 2 SID={$secondSid}; re-SELECT confirmed.\n";
echo "AF activation bundle runtime regression checks passed.\n";

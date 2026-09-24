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
    private int $nextSid = 10;

    public function escape_string(string $value): string { return addslashes($value); }
    private function decodeStylesheetPayload(array $payload): array
    {
        if (!array_key_exists('stylesheet', $payload)) return $payload;
        $withoutEscapedQuotes = str_replace("\\'", '', (string)$payload['stylesheet']);
        if (str_contains($withoutEscapedQuotes, "'")) {
            throw new RuntimeException('Unescaped stylesheet quote reached SQL serialization');
        }
        $payload['stylesheet'] = stripslashes((string)$payload['stylesheet']);
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
            $row = array_merge($row, $payload);
        }
    }
}

function af_theme_stylesheet_build_bundle(?string $onlyAddonId = null): array
{
    global $enabledAddonCss;
    $source = "/* generated */\n/* AF-SECTION-V1 fixture */\n";
    foreach ($enabledAddonCss as $addon => $css) $source .= "/* {$addon} */\n{$css}\n";
    return ['source' => $source, 'checksum' => sha1($source), 'sources' => []];
}
function af_theme_stylesheet_bundle_state(int $themeTid): array
{
    global $db;
    foreach ($db->registry as $row) {
        if ((int)$row['theme_tid'] === $themeTid && $row['addon_id'] === AF_THEME_BUNDLE_ADDON_ID) return $row;
    }
    return [];
}
function af_theme_stylesheet_encode_section(array $meta, string $css): string { return $css; }
function af_theme_stylesheet_cache_row(int $themeTid, int $sid, string $css): void {}
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

// Clean install creates one generated row and remains stable on activation.
$enabledAddonCss = [
    'layout' => ".card { grid-template-areas: 'avatar header' 'avatar body'; }",
    'quoted-content' => ".quote::before { content: \"'\"; }",
    'contraction' => ".note::after { content: \"it's\"; }",
];
$db = new ActivationDb();
$first = af_theme_stylesheet_sync_bundle(1, false);
$sid = $first['sid'];
$generated = $db->styles[$sid]['stylesheet'];
for ($cycle = 1; $cycle <= 3; $cycle++) af_theme_stylesheet_sync_bundle(1, false);
assertSameValue($generated, $db->styles[$sid]['stylesheet'], 'generated bundle grew across activations');
assertSameValue(1, count($db->styles), 'clean activation duplicated stylesheet rows');
assertSameValue(1, count($db->registry), 'clean activation duplicated registry rows');

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
echo "AF activation bundle runtime regression checks passed.\n";

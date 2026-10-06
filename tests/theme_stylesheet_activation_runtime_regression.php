<?php

declare(strict_types=1);

// Exercise the production per-source registration and mode switching against
// an in-memory MyBB DB double. This reuses the existing activation regression.
$root = dirname(__DIR__);
$core = file_get_contents($root.'/inc/plugins/advancedfunctionality.php');
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
define('AF_BASE', '/forum/inc/plugins/advancedfunctionality/');
define('TIME_NOW', 1700000000);
define('TABLE_PREFIX', 'mybb_');

final class StylesheetDb
{
    public array $columns = [
        'id', 'theme_tid', 'stylesheet_sid', 'addon_id', 'logical_id', 'stylesheet_name',
        'source_file', 'seed_file', 'seed_checksum', 'installed_checksum', 'current_checksum',
        'sync_state', 'last_synced_checksum', 'is_integrated', 'delivery_mode', 'discovered_from',
        'is_admin_only', 'last_synced_at', 'manual_override', 'created_at', 'updated_at',
    ];
    public array $styles = [];
    public array $registry = [];
    public bool $escapeValues = true;
    private int $nextSid = 10;

    public function table_exists(string $table): bool { return true; }
    public function build_create_table_collation(): string { return ''; }
    public function escape_string(string $value): string { return $this->escapeValues ? addslashes($value) : $value; }
    public function write_query(string $sql): array
    {
        if (str_starts_with($sql, 'SHOW COLUMNS')) {
            return ['rows' => array_map(static fn(string $name): array => ['Field' => $name], $this->columns), 'position' => 0];
        }
        throw new RuntimeException('Unexpected SQL: '.$sql);
    }
    private function decode(array $payload): array
    {
        if (isset($payload['stylesheet'])) {
            if (preg_match("~(?<!\\\\)'~", (string)$payload['stylesheet'])) {
                throw new RuntimeException('Unescaped stylesheet quote in SQL payload');
            }
            $payload['stylesheet'] = stripslashes((string)$payload['stylesheet']);
        }
        return $payload;
    }
    private function matches(array $row, string $where): bool
    {
        foreach ([
            'sid' => 'sid', 'tid' => 'tid', 'id' => 'id', 'theme_tid' => 'theme_tid',
            'addon_id' => 'addon_id', 'logical_id' => 'logical_id',
        ] as $column => $field) {
            if (preg_match("~(?:^| ){$column}='?([^' ]+)'?~", $where, $m)
                && (string)($row[$field] ?? '') !== (string)$m[1]) return false;
        }
        return true;
    }
    public function simple_select(string $table, string $fields = '*', string $where = '', array $options = []): array
    {
        $rows = $table === 'themestylesheets' ? array_values($this->styles) : array_values($this->registry);
        $rows = array_values(array_filter($rows, fn(array $row): bool => $this->matches($row, $where)));
        if (isset($options['limit'])) $rows = array_slice($rows, 0, (int)$options['limit']);
        return ['rows' => $rows, 'position' => 0];
    }
    public function fetch_array(array &$query): array|false { return $query['rows'][$query['position']++] ?? false; }
    public function fetch_field(array &$query, string $field): mixed
    {
        $row = $this->fetch_array($query);
        return $row[$field] ?? null;
    }
    public function insert_query(string $table, array $payload): int
    {
        if ($table === 'themestylesheets') {
            $payload = $this->decode($payload);
            $sid = $this->nextSid++;
            $payload['sid'] = $sid;
            $this->styles[$sid] = $payload;
            return $sid;
        }
        $id = count($this->registry) + 1;
        $payload['id'] = $id;
        $this->registry[$id] = $payload;
        return $id;
    }
    public function update_query(string $table, array $payload, string $where): void
    {
        if ($table === 'themestylesheets') {
            $payload = $this->decode($payload);
            $target =& $this->styles;
        } else {
            $target =& $this->registry;
        }
        foreach ($target as &$row) {
            if ($this->matches($row, $where)) $row = array_merge($row, $payload);
        }
    }
}

function af_theme_stylesheet_build_name(string $addonId, string $logicalId, string $sourceFileRel, string $preferred = ''): string
{
    return basename($sourceFileRel);
}
function af_theme_stylesheet_expected_legacy_name(string $addonId, string $sourceFileRel): string { return 'legacy.css'; }
function af_build_theme_stylesheet_attach_string(array $attach): string
{
    return implode('|', array_map(static fn(array $one): string => (string)($one['file'] ?? ''), $attach));
}
function cache_stylesheet(int $themeTid, string $name, string $css): string { return $name; }
function update_theme_stylesheet_list(int $themeTid): void {}
function af_theme_stylesheet_create_recovery(int $themeTid, array $row, string $css): array
{
    $GLOBALS['recovery_snapshots'][] = ['theme_tid' => $themeTid, 'sid' => (int)$row['sid'], 'css' => $css];
    return ['ok' => true, 'path' => '/recovery.css'];
}
function af_discover_theme_stylesheets(?array $addons = null): array { return [$GLOBALS['entry']]; }
function af_get_theme_stylesheet_source(array $meta, array $entry): ?array
{
    $css = (string)$GLOBALS['seed_css'];
    return ['path' => '/addon/'.$entry['file'], 'source' => $css, 'checksum' => sha1($css)];
}

eval(extractFunction($core, 'af_db_table_columns'));
eval(extractFunction($core, 'af_theme_stylesheets_install_schema'));
eval(extractFunction($core, 'af_mark_theme_stylesheet_managed'));
eval(extractFunction($core, 'af_register_theme_stylesheet'));
eval(extractFunction($core, 'af_ensure_theme_stylesheet_registry_row'));
eval(extractFunction($core, 'af_theme_stylesheet_set_delivery_mode'));

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("{$message}\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
    }
}
function sourceState(): array { return array_values($GLOBALS['db']->registry)[0] ?? []; }
function sourceStyle(): array { return array_values($GLOBALS['db']->styles)[0] ?? []; }
function syncSource(string $seed, bool $force = false): array
{
    $GLOBALS['seed_css'] = $seed;
    $seedInfo = af_get_theme_stylesheet_source([], $GLOBALS['entry']);
    return af_register_theme_stylesheet(1, [], $GLOBALS['entry'], $seedInfo, $force);
}

$db = new StylesheetDb();
$recovery_snapshots = [];
$entry = [
    'addon_id' => 'fixture', 'logical_id' => 'fixture__main', 'file' => 'assets/fixture.css',
    'stylesheet_name' => 'fixture.css', 'addon_meta' => ['path' => '/addon'],
    'attach' => [['file' => 'showthread.php']], 'enabled_setting' => '',
    'delivery_hint' => 'auto', 'disable_theme_integration' => false,
];
$GLOBALS['entry'] = $entry;

// A: create Theme CSS from the current physical file seed.
$seedA = ".fixture { color: red; }\n";
$created = syncSource($seedA);
$state = sourceState();
$style = sourceStyle();
assertSameValue($seedA, $style['stylesheet'], 'fresh Theme CSS does not match source seed');
assertSameValue(sha1($seedA), $state['seed_checksum'], 'fresh seed checksum was not stored');
assertSameValue(sha1($seedA), $state['installed_checksum'], 'fresh installed checksum was not stored');
assertSameValue(sha1($seedA), $state['current_checksum'], 'fresh current checksum was not stored');
assertSameValue('clean', $state['sync_state'], 'fresh source state is not clean');
assertSameValue(false, $created['manual_override'], 'fresh source was marked customized');
assertSameValue('showthread.php', $style['attachedto'], 'manifest attachment was not preserved');

// D: identical seed/current content is a no-op.
$unchanged = syncSource($seedA);
assertSameValue(false, $unchanged['updated_from_seed'], 'unchanged seed was rewritten');
assertSameValue($seedA, sourceStyle()['stylesheet'], 'no-op sync changed Theme CSS');

// B: update Theme CSS only while its bytes still equal the installed version.
$seedB = ".fixture { color: blue; }\n";
$updated = syncSource($seedB);
assertSameValue(true, $updated['updated_from_seed'], 'clean Theme CSS did not accept a changed seed');
assertSameValue($seedB, sourceStyle()['stylesheet'], 'changed seed was not installed');
assertSameValue(sha1($seedB), sourceState()['installed_checksum'], 'new installed checksum was not stored');

// C: preserve an administrator edit byte-for-byte while the seed changes.
$custom = "/* ACP bytes */\n.fixture { color: rebeccapurple; }\n";
$sid = (int)sourceStyle()['sid'];
$db->styles[$sid]['stylesheet'] = $custom;
$seedC = ".fixture { color: green; }\n";
$customized = syncSource($seedC);
assertSameValue(false, $customized['updated_from_seed'], 'ordinary sync replaced customized Theme CSS');
assertSameValue($custom, sourceStyle()['stylesheet'], 'customized Theme CSS bytes changed');
assertSameValue(true, sourceState()['manual_override'], 'customized state was not recorded');
assertSameValue('customized_seed_changed', sourceState()['sync_state'], 'seed change alongside customization was not recorded');
assertSameValue(sha1($seedC), sourceState()['seed_checksum'], 'latest physical seed checksum was not recorded');
assertSameValue(sha1($custom), sourceState()['current_checksum'], 'current Theme CSS checksum was not recorded');

// Theme ↔ File mode changes only delivery/attachment, never customized bytes.
$GLOBALS['seed_css'] = $seedC;
assertSameValue(true, af_theme_stylesheet_set_delivery_mode(1, 'fixture', 'fixture__main', 'file'), 'Theme → File failed');
assertSameValue('', sourceStyle()['attachedto'], 'File mode did not detach the Theme stylesheet');
assertSameValue($custom, sourceStyle()['stylesheet'], 'Theme → File discarded ACP edits');
assertSameValue(true, af_theme_stylesheet_set_delivery_mode(1, 'fixture', 'fixture__main', 'theme'), 'File → Theme failed');
assertSameValue('showthread.php', sourceStyle()['attachedto'], 'File → Theme did not restore manifest attachment');
assertSameValue($custom, sourceStyle()['stylesheet'], 'File → Theme replaced ACP edits');

// E: a return to the latest seed proves the Theme CSS is clean again.
$db->styles[$sid]['stylesheet'] = $seedC;
$reverted = syncSource($seedC);
assertSameValue(false, $reverted['manual_override'], 'return to current seed did not clear customization');
assertSameValue('clean', sourceState()['sync_state'], 'return to current seed did not restore clean state');
assertSameValue(sha1($seedC), sourceState()['installed_checksum'], 'reverted seed was not adopted as installed content');

// Force is destructive only on the explicit path and records a recovery copy.
$forcedCustom = ".fixture { color: black; }\n";
$db->styles[$sid]['stylesheet'] = $forcedCustom;
$forceResult = syncSource($seedC, true);
assertSameValue($seedC, sourceStyle()['stylesheet'], 'explicit force did not install the physical seed');
assertSameValue(false, $forceResult['manual_override'], 'force left a stale customized state');
assertSameValue(1, count($recovery_snapshots), 'force did not save the replaced Theme CSS');
assertSameValue($forcedCustom, $recovery_snapshots[0]['css'], 'force recovery snapshot did not preserve prior bytes');

echo "AF per-source stylesheet ownership regression checks passed.\n";

<?php

declare(strict_types=1);

$root = __DIR__ . '/../inc/plugins/advancedfunctionality/addons/knowledgebase/';
$renderer = file_get_contents($root . 'knowledgebase.php');
$css = file_get_contents($root . 'assets/knowledgebase.css');
$view = file_get_contents($root . 'templates/knowledgebase_view.html');
$characterView = file_get_contents($root . 'templates/knowledgebase_view_character.html');

function kb_banner_lifecycle_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$renderer, $css, $view, $characterView] as $source) {
    kb_banner_lifecycle_assert(is_string($source), 'Unable to read a KB source file');
}

kb_banner_lifecycle_assert(
    strpos($renderer, "\$kb_entry_heading = '<h1>'") !== false
        && strpos($renderer, "if (\$bannerUrl !== '')") !== false,
    'Entry renderer must retain a plain-H1 path when no banner is present'
);
kb_banner_lifecycle_assert(
    strpos($renderer, 'class="af-kb-entry-header-visual"') !== false,
    'Entry renderer must build a dedicated banner/title visual'
);
foreach ([$view, $characterView] as $template) {
    kb_banner_lifecycle_assert(
        strpos($template, '{$kb_entry_heading}') !== false,
        'Every entry template must use the conditional entry heading'
    );
    kb_banner_lifecycle_assert(
        strpos($template, '{$kb_banner}') === false,
        'Entry templates must not render a detached banner'
    );
}
kb_banner_lifecycle_assert(
    strpos($characterView, '{$kb_entry_heading}') < strpos($characterView, '{$kb_status_badge}'),
    'Character status/profile chips must remain below the banner heading'
);
foreach (['max-height: 350px', 'width: 100%', 'height: auto', 'object-position: center center'] as $rule) {
    kb_banner_lifecycle_assert(strpos($css, $rule) !== false, "Missing banner sizing rule: {$rule}");
}

$ensureStart = strpos($renderer, 'function af_kb_ensure_setting(');
$ensureEnd = strpos($renderer, 'function af_kb_migrate_legacy_categories_ui_setting(', $ensureStart);
$ensureBody = substr($renderer, $ensureStart, $ensureEnd - $ensureStart);
kb_banner_lifecycle_assert(
    strpos($ensureBody, "if (\$sid) {") !== false
        && strpos($ensureBody, "\$row['value'] =") > strpos($ensureBody, '} else {'),
    'Defaults must be assigned only while inserting a missing setting'
);
kb_banner_lifecycle_assert(
    strpos($ensureBody, "\$db->update_query('settings', \$row") !== false,
    'Existing settings metadata must still be refreshable without changing value'
);

$deactivateStart = strpos($renderer, 'function af_knowledgebase_deactivate(): bool');
$deactivateEnd = strpos($renderer, 'function af_kb_alias_target_path()', $deactivateStart);
$deactivateBody = substr($renderer, $deactivateStart, $deactivateEnd - $deactivateStart);
kb_banner_lifecycle_assert(
    strpos($deactivateBody, "delete_query('settings'") === false,
    'Deactivation must not delete KB settings'
);

// Exercise the real helper in a minimal MyBB DB double: three customized
// values survive two activation-like ensure cycles, while a newly introduced
// setting receives its default exactly once.
final class KbSettingsQuery
{
    public function __construct(public array $rows)
    {
    }
}

final class KbSettingsDb
{
    public array $settings = [];
    private int $nextSid = 1;

    public function escape_string(string $value): string
    {
        return $value;
    }

    public function simple_select(string $table, string $fields, string $where, array $options = []): KbSettingsQuery
    {
        preg_match("/name='([^']+)'/", $where, $matches);
        $name = $matches[1] ?? '';
        return new KbSettingsQuery(isset($this->settings[$name]) ? [$this->settings[$name]] : []);
    }

    public function fetch_field(KbSettingsQuery $query, string $field)
    {
        return $query->rows[0][$field] ?? null;
    }

    public function update_query(string $table, array $row, string $where): void
    {
        preg_match('/sid=(\d+)/', $where, $matches);
        foreach ($this->settings as $name => $setting) {
            if ((int)$setting['sid'] === (int)($matches[1] ?? 0)) {
                $this->settings[$name] = array_merge($setting, $row);
                return;
            }
        }
    }

    public function insert_query(string $table, array $row): void
    {
        $row['sid'] = $this->nextSid++;
        $this->settings[$row['name']] = $row;
    }
}

eval(substr($renderer, $ensureStart, $ensureEnd - $ensureStart));
$db = new KbSettingsDb();
foreach (['a', 'b', 'c'] as $order => $key) {
    af_kb_ensure_setting(7, 'af_kb_test_' . $key, strtoupper($key), '', 'text', 'default-' . $key, $order + 1);
    $db->settings['af_kb_test_' . $key]['value'] = 'custom-' . $key;
}
for ($cycle = 0; $cycle < 2; $cycle++) {
    foreach (['a', 'b', 'c'] as $order => $key) {
        af_kb_ensure_setting(7, 'af_kb_test_' . $key, strtoupper($key), 'updated metadata', 'text', 'default-' . $key, $order + 1);
    }
}
foreach (['a', 'b', 'c'] as $key) {
    kb_banner_lifecycle_assert(
        $db->settings['af_kb_test_' . $key]['value'] === 'custom-' . $key,
        "Customized setting {$key} changed during repeated activation"
    );
}
af_kb_ensure_setting(7, 'af_kb_test_new', 'New', '', 'text', 'default-new', 4);
kb_banner_lifecycle_assert(
    $db->settings['af_kb_test_new']['value'] === 'default-new',
    'A missing setting did not receive its default'
);

fwrite(STDOUT, "KB entry banner and settings lifecycle regression checks passed.\n");

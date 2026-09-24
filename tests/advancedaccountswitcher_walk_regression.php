<?php
/** Static regression contract for linked-account walking and UTF-8 UI. */
$root = dirname(__DIR__);
$php = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/advancedaccountswitcher.php');
$manifest = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/manifest.php');
$template = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/templates/advancedaccountswitcher.html');
$js = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedaccountswitcher/assets/advancedaccountswitcher.js');

$fail = static function (string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
foreach ([$php, $manifest, $template, $js] as $source) {
    if ($source === false || preg_match('//u', $source) !== 1) {
        $fail('all account switcher sources must be valid UTF-8');
    }
}
if (!str_contains($manifest, "'af_aas_header_label_accounts'    => 'Аккаунты'")) {
    $fail('the canonical Russian trigger label is missing');
}
if (!str_contains($php, 'af_aas_apply_manifest_language(false, $apply)')) {
    $fail('runtime language must be overlaid from the canonical UTF-8 manifest');
}
foreach (['af_aas_handle_walk', "new PostDataHandler('insert')", "'lastactive' => TIME_NOW", "'lastvisit' => TIME_NOW", "return '[img]' . \$url . '[/img]'", "return '+'", 'LOCK_EX | LOCK_NB'] as $needle) {
    if (!str_contains($php, $needle)) $fail("missing walk contract: {$needle}");
}
if (str_contains($php, 'INSERT INTO " . TABLE_PREFIX . "posts')) {
    $fail('walk must not insert directly into posts');
}
foreach (['af-aas-walk-button', 'af-aas-walk-autopost', 'operation_id'] as $needle) {
    if (!str_contains($template . $js . $php, $needle)) $fail("missing UI contract: {$needle}");
}
if (!str_contains($php, "'af_advancedaccountswitcher_walk_tid'")) {
    $fail('walk TID setting is missing');
}
echo "Advanced Account Switcher walk regression checks passed.\n";

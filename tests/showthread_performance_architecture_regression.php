<?php
$root = dirname(__DIR__);
function perf_assert(bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
$bundle = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedjsbandle/advancedjsbandle.php');
perf_assert(!preg_match('~foreach\s*\(\$jsFiles.*?remove_script_tags_for_file~s', $bundle), 'bundle still strips response once per JS file');
perf_assert(!preg_match('~foreach\s*\(\$cssFiles.*?remove_link_tags_for_file~s', $bundle), 'bundle still strips response once per CSS file');
$quotes = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedjsbandle/assets/quote-avatars.js');
perf_assert(strpos($quotes, "fetch('member.php?uid=") === false && strpos($quotes, 'fetchAvatarFromProfile') === false, 'quote avatars fetch profile HTML');
perf_assert(strpos($quotes, 'mo.observe(posts') !== false && strpos($quotes, 'document.documentElement') === false, 'quote observer is not posts-scoped');
$icons = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedjsbandle/assets/postbit-fa-icons.js');
perf_assert(strpos($icons, 'mo.observe(posts') !== false && strpos($icons, 'mo.observe(document.documentElement') === false, 'postbit icon observer is global');
$editor = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancededitor/advancededitor.php');
foreach (["'view_js'", "'view_css'", '$packJsAssets = $packs[\'view_js\']', "static \$requestCache"] as $needle) {
    perf_assert(strpos($editor, $needle) !== false, 'editor runtime/cache contract missing: '.$needle);
}
foreach (['copycode','spoiler','tabs','accordion','embedvideos'] as $pack) {
    $manifest = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancededitor/assets/bbcodes/'.$pack.'/manifest.php');
    perf_assert(strpos($manifest, "'runtime' => ['view' => true") !== false, $pack.' lacks declarative view runtime');
}
$modals = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/assets/adaptivethemeframework.modals.js');
perf_assert(strpos($modals, 'record.addedNodes') !== false, 'modal observer does not process added roots');
perf_assert(substr_count($modals, 'canonicalize();') === 1, 'modal observer repeats full canonicalization');
$atf = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/adaptivethemeframework.php');
perf_assert(strpos($atf, "THIS_SCRIPT === 'showthread.php'") !== false && strpos($atf, 'adaptivethemeframework.postbit-sticky.js') !== false, 'sticky runtime is not showthread-scoped');
$csBoot = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/charactersheets/modules/bootstrap.php');
perf_assert(strpos($csBoot, '$page = af_charactersheets_inject_modal($page)') === false, 'CharacterSheets eagerly injects its iframe shell');
$csJs = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/charactersheets/assets/charactersheets.js');
perf_assert(strpos($csJs, 'src=""') !== false && strpos($csJs, "setAttribute('src', loadUrl)") !== false, 'CharacterSheets iframe is not click-lazy');
$csPostbit = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/charactersheets/modules/postbit.php');
perf_assert(strpos($csPostbit, 'af_charactersheets_preload_postbit_metadata') !== false && strpos($csPostbit, 'uid IN ($in)') !== false, 'CharacterSheets lacks batch metadata cache');
echo "Showthread performance architecture contracts passed.\n";

<?php
/** Exercise the bundled installer in an isolated directory, without a forum DB. */
$core = file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality.php');
$start = strpos($core, 'function af_avatar_runtime_source(): string');
$end = strpos($core, 'function af_ensure_scaffold(', $start);
eval(substr($core, $start, $end - $start));
$directory = sys_get_temp_dir().'/af-avatar-install-'.bin2hex(random_bytes(6)).'/';
mkdir($directory);
define('AF_BASE', $directory);
function check_avatar_install($ok, $message) { if (!$ok) throw new RuntimeException($message); }
try {
    $expected = file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality/avatar.php');
    check_avatar_install(af_avatar_runtime_source() === $expected, 'Bundled source diverges from avatar.php');
    af_ensure_avatar_runtime();
    check_avatar_install(file_get_contents(AF_BASE.'avatar.php') === $expected, 'Missing runtime not restored');
    file_put_contents(AF_BASE.'avatar.php', '<?php // old runtime');
    af_ensure_avatar_runtime();
    check_avatar_install(file_get_contents(AF_BASE.'avatar.php') === $expected, 'Outdated runtime not refreshed');
    touch(AF_BASE.'avatar.php', 1000000000); clearstatcache();
    af_ensure_avatar_runtime(); clearstatcache();
    check_avatar_install(filemtime(AF_BASE.'avatar.php') === 1000000000, 'Current runtime rewritten unnecessarily');
    check_avatar_install(str_contains($core, "add_hook('usercp_start', 'advancedfunctionality_avatar_state_bridge', 0)"), 'State bridge not registered by Core');
    check_avatar_install(str_contains($core, '    af_ensure_avatar_runtime();'), 'Scaffold does not restore helper');
    echo "Avatar runtime installation checks passed\n";
} finally {
    foreach (glob($directory.'*') as $file) unlink($file);
    rmdir($directory);
}

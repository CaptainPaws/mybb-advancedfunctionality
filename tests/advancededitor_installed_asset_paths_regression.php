<?php
// Canonical AdvancedEditor BBCode layout is assets/bbcodes/<pack>.
define('IN_MYBB', 1);
define('AF_ADDONS', 1);
define('MYBB_ROOT', dirname(__DIR__) . '/');
require MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/advancededitor/advancededitor.php';

$base = sys_get_temp_dir() . '/af-editor-assets-' . bin2hex(random_bytes(6));
try {
    $layout = 'assets/bbcodes/';
    foreach (['align' => ['align.js'], 'charcountandprew' => ['charcountandprew_view.js', 'charcountandprew_view.css']] as $pack => $files) {
        $dir = $base . '/' . $layout . $pack . '/';
        mkdir($dir, 0700, true);
        foreach ($files as $file) {
            file_put_contents($dir . $file, 'fixture');
            $declared = 'bbcodes/' . $pack . '/' . $file;
            $actual = af_advancededitor_pack_asset_rel($declared, $dir, $base . '/');
            if ($actual !== $layout . $pack . '/' . $file) {
                throw new RuntimeException('Wrong installed asset: ' . $actual);
            }
            unlink($dir . $file);
        }
        rmdir($dir);
    }
    echo "PASS: alignment and view assets resolve in canonical flat BBCode layout\n";
} finally {
    foreach (['assets/bbcodes', 'assets', ''] as $dir) {
        if (is_dir($base . '/' . $dir)) rmdir($base . '/' . $dir);
    }
}

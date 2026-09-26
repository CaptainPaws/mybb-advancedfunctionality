<?php
define('IN_MYBB', 1);
$editorManifest = require __DIR__.'/../inc/plugins/advancedfunctionality/addons/advancededitor/manifest.php';
$forceManifest = require __DIR__.'/../inc/plugins/advancedfunctionality/addons/forcerefresh/manifest.php';
$editor = file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality/addons/advancededitor/advancededitor.php');
$force = file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality/addons/forcerefresh/forcerefresh.php');

if (($editorManifest['frontend']['response_rules'] ?? []) !== [['has_supported_editor' => true], ['has_post_content' => true]]
    || ($editorManifest['frontend']['directory_fallback'] ?? null) !== false) throw new RuntimeException('Editor manifest facts mismatch.');
if (($forceManifest['frontend']['response_rules'] ?? []) !== [['has_quick_reply' => true]]
    || ($forceManifest['frontend']['directory_fallback'] ?? null) !== false) throw new RuntimeException('Force Refresh manifest facts mismatch.');
foreach (["name=[\"\\']message", 'af-kb-editor', "af_frontend_asset_allowed(AF_AE_ID, 'pre_output'", 'textarea[name="message"]'] as $needle) {
    if (!str_contains($editor, $needle)) throw new RuntimeException('Editor component guard missing: '.$needle);
}
foreach (["\$script !== 'showthread.php'", "af_frontend_asset_allowed(AF_FORCEREFRESH_ID, 'quick_reply'", "['has_quick_reply' => true]"] as $needle) {
    if (!str_contains($force, $needle)) throw new RuntimeException('Force Refresh quick-reply guard missing: '.$needle);
}
if (str_contains($force, "in_array(\$script, ['newreply.php', 'newthread.php', 'editpost.php']")) throw new RuntimeException('Force Refresh still loads on full editor forms.');
echo "Editor and Force Refresh component permission checks passed.\n";

<?php
define('IN_MYBB', 1);
$manifest = require __DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedbyddylist/manifest.php';
$source = file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedbyddylist/advancedbyddylist.php');

if (($manifest['frontend']['mode'] ?? '') !== 'contextual'
    || ($manifest['frontend']['routes'] ?? null) !== []
    || ($manifest['frontend']['response_rules'] ?? []) !== [['has_buddy_trigger' => true]]
    || ($manifest['frontend']['directory_fallback'] ?? null) !== false) {
    throw new RuntimeException('Buddy frontend manifest is not component-aware.');
}
foreach (["empty(\$mybb->user['uid'])", "['has_buddy_trigger' => true]", "af_frontend_asset_allowed(AF_ABDL_ID, 'modal_caller'", "advancedbyddylist.js?v=1"] as $needle) {
    if (!str_contains($source, $needle)) throw new RuntimeException('Missing Buddy caller guard: '.$needle);
}
if (!str_contains($source, "\$action !== 'buddypopup'") || !str_contains($source, '$modal !== 1')) {
    throw new RuntimeException('Buddy AJAX endpoint scope changed.');
}
echo "Advanced Buddy List component permission checks passed.\n";

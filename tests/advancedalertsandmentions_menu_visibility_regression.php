<?php
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__.'/../inc/plugins/advancedfunctionality/addons/');
define('MYBB_ROOT', __DIR__.'/../');
define('TABLE_PREFIX', 'mybb_');

function htmlspecialchars_uni($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$mybb = (object)[
    'user' => ['uid' => 42],
    'usergroup' => [],
    'settings' => [
        'bburl' => 'https://board.test',
        'af_advancedalertsandmentions_enabled' => 1,
        'af_aam_enabled' => 1,
    ],
];

require AF_ADDONS.'advancedmenu/advancedmenu.php';
require AF_ADDONS.'advancedalertsandmentions/advancedalertsandmentions.php';

if (!af_aam_is_enabled()) {
    throw new RuntimeException('Enabled AAM addon was rejected by its owner lifecycle contract.');
}

af_advancedalertsandmentions_menu_provider();
$items = af_menu_collect_registry(true);
$alerts = $items['advanced_alerts'] ?? null;
if (!$alerts || !af_menu_item_is_visible($alerts)) {
    throw new RuntimeException('Logged-in user cannot see the enabled advanced_alerts provider.');
}

// This is the production failure mode: the obsolete switch is stale even
// though AF's canonical lifecycle switch says that the addon is active.
$mybb->settings['af_aam_enabled'] = 0;
if (!af_aam_is_enabled() || !af_menu_item_is_visible($alerts)) {
    throw new RuntimeException('Legacy af_aam_enabled still controls provider visibility.');
}

$mybb->settings['af_advancedalertsandmentions_enabled'] = 0;
$mybb->settings['af_aam_enabled'] = 1;
if (af_aam_is_enabled() || af_menu_item_is_visible($alerts)) {
    throw new RuntimeException('Canonical AF lifecycle switch does not disable the provider.');
}
$mybb->settings['af_advancedalertsandmentions_enabled'] = 1;

$GLOBALS['af_aam_unread'] = 7;
$html = af_advancedmenu_render_registry_item(af_menu_apply_override($alerts, null));
foreach ([
    'id="af_aam_header_link"',
    'class="af-am-link af-am-system-link af-am-modal-trigger"',
    'data-af-am-modal="#af_aam_modal"',
    'fa-solid fa-bell',
    'af-am-badge',
    '>7</span>',
] as $needle) {
    if (strpos($html, $needle) === false) {
        throw new RuntimeException('Alerts generic modal trigger misses: '.$needle."\n".$html);
    }
}

$template = file_get_contents(AF_ADDONS.'advancedalertsandmentions/templates/advancedalertsandmentions.html');
$javascript = file_get_contents(AF_ADDONS.'advancedalertsandmentions/assets/advancedalertsandmentions.js');
if (substr_count($template, 'id="af_aam_modal"') !== 1) {
    throw new RuntimeException('AAM owner must provide exactly one modal body.');
}
foreach (["qs('#af_aam_header_link')", "qs('#af_aam_modal')"] as $needle) {
    if (strpos($javascript, $needle) === false) {
        throw new RuntimeException('AAM owner JS does not connect trigger and modal: '.$needle);
    }
}

echo "advanced alerts menu visibility regression: OK\n";

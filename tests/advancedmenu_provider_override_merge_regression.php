<?php
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__.'/../inc/plugins/advancedfunctionality/addons/');

require AF_ADDONS.'advancedmenu/advancedmenu.php';

$fixtures = [
    ['key'=>'presets', 'source_addon'=>'advancedappearance', 'label'=>'Пресеты'],
    ['key'=>'fitting_room', 'source_addon'=>'advancedappearance', 'label'=>'Примерочная'],
    ['key'=>'account_switcher', 'source_addon'=>'advancedaccountswitcher', 'label'=>'Аккаунты'],
];

foreach ($fixtures as $fixture) {
    $provider = $fixture + [
        'default_container'=>'user_drawer', 'default_sortorder'=>50,
        'section'=>'links', 'allowed_containers'=>['user_drawer'], 'icon'=>'',
    ];
    $override = [
        'item_key'=>$provider['key'], 'source_addon'=>$provider['source_addon'],
        'container'=>'user_drawer', 'section'=>'settings', 'sortorder'=>7,
        'enabled'=>1, 'label_override'=>null, 'icon_override'=>null,
    ];
    $final = af_menu_apply_override($provider, $override);
    if ($final['section'] !== 'settings' || $final['sortorder'] !== 7) {
        throw new RuntimeException($provider['key'].' did not receive the DB override after provider defaults.');
    }
    if ($final['canonical_identity'] !== $provider['source_addon'].'::'.$provider['key']) {
        throw new RuntimeException($provider['key'].' identity depends on placement.');
    }
    // Emulate the next ACP UPDATE of the same override row.
    $override['section'] = 'profile';
    $movedBack = af_menu_apply_override($provider, $override);
    if ($movedBack['section'] !== 'profile' || $movedBack['canonical_identity'] !== $final['canonical_identity']) {
        throw new RuntimeException($provider['key'].' changed identity during a repeated section edit.');
    }
}

$runtime = file_get_contents(AF_ADDONS.'advancedmenu/advancedmenu.php');
$admin = file_get_contents(AF_ADDONS.'advancedmenu/admin.php');
foreach (['af_menu_repair_duplicate_overrides', "'order_by'=>'updated_at, created_at'", '$seenProviders[$identity]'] as $needle) {
    if (strpos($runtime, $needle) === false) throw new RuntimeException('Provider duplicate protection missing: '.$needle);
}
foreach (["if (\$exists)", 'update_query(AF_AM_TABLE_OVERRIDES', 'insert_query(AF_AM_TABLE_OVERRIDES'] as $needle) {
    if (strpos($admin, $needle) === false) throw new RuntimeException('Override upsert contract missing: '.$needle);
}

echo "advancedmenu provider override merge regression: OK\n";

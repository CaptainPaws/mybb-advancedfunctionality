<?php
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__.'/../inc/plugins/advancedfunctionality/addons/');

require AF_ADDONS.'advancedmenu/advancedmenu.php';

$fixtures = [
    ['key'=>'presets', 'source_addon'=>'advancedappearance', 'label'=>'Пресеты'],
    ['key'=>'fitting_room', 'source_addon'=>'advancedappearance', 'label'=>'Примерочная'],
    ['key'=>'post_activity', 'source_addon'=>'advancedpostcounter', 'label'=>'Постовая активность'],
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
foreach (["PRIMARY KEY (`source_addon`,`item_key`)", 'af_menu_migrate_known_provider_copies',
    "if (!\$urlMatches || (!\$labelMatches && !\$keyMatches))", 'SYSTEM / PROVIDER', 'Вернуть defaults'] as $needle) {
    if (strpos($runtime.$admin, $needle) === false) throw new RuntimeException('First-class provider management missing: '.$needle);
}

// Regression fixture: a custom item with the same visible label is not the
// provider entity. Only the source/key pair receives the override.
$provider = ['key'=>'presets', 'source_addon'=>'advancedappearance', 'label'=>'Пресеты', 'icon'=>'',
    'default_container'=>'user_drawer', 'default_sortorder'=>50, 'section'=>'links', 'allowed_containers'=>['user_drawer']];
$system = af_menu_apply_override($provider, ['source_addon'=>'advancedappearance', 'item_key'=>'presets',
    'container'=>'user_drawer', 'section'=>'settings', 'sortorder'=>5, 'enabled'=>1]);
$custom = ['id'=>42, 'title'=>'Пресеты', 'url'=>'somewhere-else.php'];
if ($system['section'] !== 'settings' || $system['canonical_identity'] !== 'advancedappearance::presets'
    || $custom['id'] !== 42) {
    throw new RuntimeException('Provider override and same-label custom item were not kept as distinct entities.');
}

echo "advancedmenu provider override merge regression: OK\n";

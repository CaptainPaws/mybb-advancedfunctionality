<?php
$root = dirname(__DIR__);
$core = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php');

$checks = [
    'settings use the AF addon group' => strpos($core, "\$groupName='af_advancedwanted'") !== false,
    'required integration settings have exact ACP labels' =>
        strpos($core, 'ID форума анкет ATF') !== false &&
        strpos($core, 'ID темы обсуждения Wanted') !== false &&
        strpos($core, 'Срок брони гостя, дней') !== false &&
        strpos($core, 'Срок брони пользователя, дней') !== false &&
        strpos($core, 'Разрешить бронь гостям') !== false &&
        strpos($core, 'Записей на страницу') !== false,
    'setting repair does not include value when a row already exists' =>
        strpos($core, "if(\$sid)\$db->update_query('settings',\$data,'sid='.\$sid)") !== false,
    'frontend has exactly one explicit outer pun wrapper' =>
        substr_count($core, "\$body='<div class=\"pun\"") === 1 && strpos($core, "\$body.='</main></div>'") !== false,
    'detail routes only textarea fields to main content' =>
        strpos($core, "if((\$f['type']??'')==='textarea')\$content.=\$row;else\$info.=\$row;") !== false,
    'title is not duplicated as a synthetic infobox name' =>
        strpos($core, '<dt>Имя</dt><dd>') === false,
    'variant display checks authoritative options' =>
        substr_count($core, "==='origin_variant'&&!af_wanted_options(\$f,\$byKey)") >= 2,
    'reservation deadline is date-only in public output' =>
        strpos($core, "my_date('d.m.y',\$until)") !== false,
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) $failed[] = $label;
}
exit($failed ? 1 : 0);

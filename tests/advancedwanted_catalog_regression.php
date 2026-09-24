<?php
$root = dirname(__DIR__);
$core = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php');
$js = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedwanted/assets/advancedwanted_catalog.js');

$checks = [
    'active and archive are isolated in SQL' => strpos($core, "e.status='archived'") !== false
        && strpos($core, "e.status IN ('open','reserved','application')") !== false,
    'filter fields are selected dynamically' => strpos($core, "['settings']['filterable']") !== false
        && strpos($core, 'field_id=".(int)$f[\'id\']') !== false,
    'search remains in SQL and targets semantic text fields' => strpos($core, 'vs.field_id IN (') !== false
        && strpos($core, "['settings']['is_title']") !== false,
    'GET fallback has an explicit action' => strpos($core, 'class="af-wanted-filters" method="get" action="wanted.php"') !== false,
    'dependent variant filter declares its configured parent' => strpos($core, 'data-depends-on=') !== false
        && strpos($core, "['depends_on']??'origin'") !== false
        && strpos($core, "!array_key_exists(\$value,af_wanted_options(\$f,\$mybb->input))") !== false,
    'pagination URL preserves catalog parameters' => strpos($core, "multipage(\$count,\$per,\$page,'wanted.php?'.\$query)") !== false,
    'AJAX endpoint returns only the replaceable catalog' => strpos($core, "get_input('ajax')==='1'") !== false
        && strpos($core, 'echo af_wanted_catalog();') !== false,
    'AJAX uses history and supports browser navigation' => strpos($js, 'window.history.pushState') !== false
        && strpos($js, "window.addEventListener('popstate'") !== false,
    'submit fallback is cancelled only after AJAX starts' => strpos($js, 'if (load(target, true)) event.preventDefault();') !== false,
    'failed AJAX falls back to normal navigation' => strpos($js, 'window.location.assign(target.href)') !== false,
    'pagination and tabs share delegated AJAX navigation' => strpos($js, '.af-wanted-tabs a, [data-af-wanted-catalog] .af-wanted-pagination a') !== false,
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$passed) $failed[] = $label;
}
exit($failed ? 1 : 0);

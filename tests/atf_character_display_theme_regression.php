<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$php = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php');
$admin = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedthreadfields/admin.php');
$template = file_get_contents($root.'/inc/plugins/advancedfunctionality/addons/advancedthreadfields/templates/advancedthreadfields.html');

function atf_display_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

atf_display_assert(strpos($template, '<h1 class="af-atf-wiki__title">{$wikiTitle}</h1>') !== false, 'Wiki header was removed');
atf_display_assert(strpos($php, "function af_atf_get_wiki_area(array \$field): string") !== false, 'Dynamic Wiki area resolver is missing');
atf_display_assert(strpos($admin, "'main' => 'Main content'") !== false, 'ACP Wiki area selector is missing');
atf_display_assert(strpos($php, "\$area = af_atf_get_wiki_area(\$f);") !== false, 'Renderer does not resolve area from field metadata');
atf_display_assert(strpos($php, "\$label = htmlspecialchars_uni((string)(\$f['title'] ?? ''));") !== false, 'Section title is not sourced from ATF metadata');
atf_display_assert(strpos($php, 'af_atf_format_value_for_display($f, $val)') !== false, 'Field type formatter is not used');
atf_display_assert(strpos($php, "(string)(\$f['type'] ?? '') === 'character_abilities'") !== false, 'Ability styling is not selected by field type');
atf_display_assert(strpos($php, "'abilities' => array_fill_keys") === false, 'Hardcoded Wiki section map remains');
atf_display_assert(strpos($php, "'player' => array_fill_keys") === false, 'Invented player Wiki section remains');
atf_display_assert(strpos($php, "\$wikiSection('Способности'") === false, 'Ability title remains hardcoded');
atf_display_assert(strpos($php, "\$wikiSection('Об игроке'") === false, 'Invented player title remains hardcoded');
atf_display_assert(strpos($php, '$wikiMainSections[]') !== false, 'Registry fields are not appended dynamically');
atf_display_assert(strpos($php, 'usort($wikiInfoboxRows') === false, 'Infobox still overrides ATF sortorder');
atf_display_assert(strpos($php, "strtolower(\$k) === 'wiki_area'") !== false, 'Wiki metadata can leak into selectable options');

// Model the data-driven loop's observable metadata behavior with the current
// registry plus a future field: order and renamed titles must need no PHP map.
$fields = [
    ['name' => 'character_app', 'title' => 'О персонаже', 'type' => 'textarea', 'sortorder' => 10],
    ['name' => 'character_abil', 'title' => 'Способности', 'type' => 'character_abilities', 'sortorder' => 20],
    ['name' => 'character_post', 'title' => 'Тестовое название', 'type' => 'textarea', 'sortorder' => 30],
    ['name' => 'character_userinfo', 'title' => 'Дополнительная информация', 'type' => 'textarea', 'sortorder' => 40],
    ['name' => 'character_food', 'title' => 'Любимые блюда', 'type' => 'textarea', 'sortorder' => 25, 'options' => 'wiki_area=main'],
];
usort($fields, static fn(array $a, array $b): int => $a['sortorder'] <=> $b['sortorder']);
atf_display_assert(array_column($fields, 'name') === [
    'character_app', 'character_abil', 'character_food', 'character_post', 'character_userinfo',
], 'ATF sortorder does not place existing/new main fields dynamically');
atf_display_assert($fields[3]['title'] === 'Тестовое название', 'Renamed ATF title is not preserved');
atf_display_assert($fields[1]['type'] === 'character_abilities', 'Actual ability field lost its specialized type');

// Required reorder acceptance: only metadata changes; renderer source is untouched.
$fields[0]['sortorder'] = 50;
usort($fields, static fn(array $a, array $b): int => $a['sortorder'] <=> $b['sortorder']);
atf_display_assert(end($fields)['name'] === 'character_app', 'Changing ATF sortorder did not change rendered sequence');

echo "OK\n";

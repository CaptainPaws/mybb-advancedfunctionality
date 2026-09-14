<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$atfPhp = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php');
$atfJs = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/assets/advancedthreadfields.js');
$kbPhp = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php');
$kbJs = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase.js');
$sheet = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/modules/render.php');

function ability_effect_assert(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

foreach (['cooldown_value', 'cooldown_unit', 'cost_value', 'cost_resource', 'effects'] as $key) {
    ability_effect_assert(strpos($atfPhp, "'{$key}'") !== false, "ATF normalizer misses {$key}");
    ability_effect_assert(strpos($kbPhp, "'{$key}'") !== false, "KB contract misses {$key}");
}
foreach ([
    'af-atf-ability-range' => 'Range',
    'af-atf-ability-cooldown-value' => 'Cooldown',
    'af-atf-ability-cost-value' => 'Cost',
    'af-atf-ability-cost-resource' => 'Cost resource',
    'af-atf-effect-add' => 'Add Effect button',
    'af-atf-effect-remove' => 'Remove Effect button',
] as $control => $label) {
    ability_effect_assert(strpos($atfJs, $control) !== false, "ATF EDIT misses {$label} control");
}
ability_effect_assert(strpos($atfJs, 'Object.assign(current, collected)') !== false, 'ATF ability editing does not preserve nested repeater object identity');
ability_effect_assert(substr_count($atfJs, 'state[index] = ability;') >= 3, 'ATF nested effect add/edit/remove does not synchronize the owning ability');
ability_effect_assert(strpos($atfPhp, "'range' => 'ability_range'") !== false, 'ATF Range does not use the shared KB mechanics option set');
ability_effect_assert(strpos($atfPhp, "filemtime(MYBB_ROOT . AF_ATF_ASSET_JS)") !== false && strpos($atfPhp, "'?v='") !== false, 'ATF editor JavaScript is not cache-busted after deployment');
foreach (['effect_type', 'value', 'formula_profile', 'coefficient', 'target', 'damage_type', 'element', 'duration_value', 'duration_unit', 'status_key', 'stat_key', 'operation', 'resource_key', 'notes'] as $key) {
    ability_effect_assert(strpos($atfPhp, "'{$key}'") !== false, "ATF effect contract misses {$key}");
    ability_effect_assert(strpos($kbPhp, "['key' => '{$key}'") !== false, "KB effect contract misses {$key}");
}
ability_effect_assert(strpos($atfJs, 'Схема расчёта') !== false, 'ATF Formula Profile label was not localized');
ability_effect_assert(strpos($kbJs, "label: 'Схема расчёта'") !== false, 'KB Formula Profile label was not localized');
ability_effect_assert(strpos($kbPhp, "'af_kb.character.contract.v1'") !== false, 'Character contract identifier changed');
ability_effect_assert(strpos($atfJs, 'af-atf-ability-cooldown-unit') === false, 'ATF still exposes cooldown unit');
ability_effect_assert(strpos($kbJs, "name: 'cooldown_unit'") === false, 'KB still exposes cooldown unit');
ability_effect_assert(strpos($atfJs, 'Коэффициент масштабирования') === false && strpos($kbJs, 'Коэффициент масштабирования') === false, 'Coefficient is still exposed');
ability_effect_assert(strpos($atfJs, 'af-atf-ability-duration-value') === false && strpos($kbJs, "name: 'duration_value', label: 'Продолжительность'") === false, 'Top-level duration is still exposed');
ability_effect_assert(strpos($atfJs, 'data-tooltip=') !== false && strpos($kbJs, "setAttribute('data-tooltip'") !== false, 'Compact accessible tooltips are missing');
ability_effect_assert(strpos($sheet, '$hasStructuredEffects ? []') !== false, 'Structured effects do not suppress the legacy computed duplicate');
ability_effect_assert(strpos($sheet, 'af_charactersheets_arpg_build_effect_lines') !== false, 'Character Sheet does not consume effects');
ability_effect_assert(strpos($sheet, "['damage' => 'Урон', 'heal' => 'Лечение', 'shield' => 'Щит']") !== false, 'Character Sheet lost legacy value fallback');
ability_effect_assert(strpos($sheet, "(!is_numeric(\$value) || (float)\$value != 0.0)") !== false, 'Character Sheet still presents zero legacy placeholders');
ability_effect_assert(strpos($sheet, '<summary>Параметры способности</summary>') !== false, 'Character Sheet ability technical spoiler is missing');
ability_effect_assert(strpos($atfPhp, '<details class="af-ability-effects"><summary>Эффекты</summary>') === false, 'ATF display still puts effects in a separate spoiler');

echo "Character ability effects contract regression checks passed\n";

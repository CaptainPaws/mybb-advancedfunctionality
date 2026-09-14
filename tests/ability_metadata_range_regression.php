<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$atfPhp = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php');
$atfJs = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/assets/advancedthreadfields.js');
$kbPhp = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php');
$kbJs = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase.js');

function ability_ui_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

ability_ui_assert(strpos($kbJs, "{ name: 'range', label: 'Дальность', type: 'select'") !== false, 'KB character ability editor does not expose the canonical mechanics-backed range field');
ability_ui_assert(strpos($kbPhp, "['path' => 'range', 'type' => 'string'") !== false, 'Range is absent from the Character ability schema');

ability_ui_assert(strpos($atfJs, 'ability.range = String(source.range ?? "")') !== false, 'ATF editor does not reopen a saved range');
ability_ui_assert(strpos($atfJs, 'range: AF_ATF.qs(".af-atf-ability-range", row).value') !== false, 'ATF editor does not serialize range');
ability_ui_assert(strpos($atfPhp, "['key' => 'range', 'label' => 'Дальность', 'set' => 'ability_range']") !== false, 'ATF display metadata omits range');
ability_ui_assert(strpos($kbPhp, "'range' => \$isRu ? 'Дальность' : 'Range'") !== false, 'KB Character display metadata omits range');

ability_ui_assert(substr_count($atfPhp, '<details class="af-ability-meta">') >= 1, 'ATF ability metadata is not in the shared details contract');
ability_ui_assert(substr_count($kbPhp, '<details class="af-ability-meta">') >= 1, 'Character Sheet ability metadata is not in the shared details contract');
ability_ui_assert(strpos($atfPhp, "['key' => 'range', 'label' => 'Дальность'") !== false, 'ATF does not render range metadata');
ability_ui_assert(strpos($kbPhp, "if (\$key === 'range' && \$rawValue === '') continue;") !== false, 'KB display does not hide an absent range');

echo "Ability metadata/range regression checks passed\n";

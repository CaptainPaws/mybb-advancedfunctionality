<?php
declare(strict_types=1);

function atf_display_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$root = dirname(__DIR__);
$php = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php');
$css = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/assets/advancedthreadfields.css');

atf_display_assert(is_string($php) && is_string($css), 'Cannot read ATF display sources');
atf_display_assert(strpos($php, "'character_prototype', 'character_element'") !== false, 'Element is not a profile field');
atf_display_assert(strpos($php, 'data-element=') !== false, 'Canonical element attribute is missing');
atf_display_assert(strpos($php, '$elementValue = strtolower') !== false, 'Theme does not use the stored element value');

foreach (['character_prototype', 'character_gen', 'character_faction', 'character_class', 'character_weapon', 'character_weapon_type', 'character_age', 'character_race', 'character_origin', 'character_origin_variant', 'character_activity', 'character_occupation'] as $field) {
    atf_display_assert(strpos($php, "'{$field}'") !== false, 'Unified profile component is missing field: ' . $field);
}

foreach (['fire', 'water', 'ice', 'lightning'] as $element) {
    atf_display_assert(strpos($css, '[data-element="' . $element . '"]') !== false, 'Element palette is missing: ' . $element);
}

foreach (['--af-element-main', '--af-element-accent', '--af-element-soft', '--af-element-border'] as $variable) {
    atf_display_assert(strpos($css, $variable) !== false, 'Theme variable is missing: ' . $variable);
}

atf_display_assert(strpos($css, '.af-atf-display .af-atf-profile-field') !== false, 'Shared profile field component is missing');
atf_display_assert(strpos($php, "af-atf-field-'.\$nameClass") !== false, 'Existing machine-key field class was removed');

echo "ATF character display theme regression checks passed.\n";

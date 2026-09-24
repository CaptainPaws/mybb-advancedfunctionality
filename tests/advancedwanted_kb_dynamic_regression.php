<?php
declare(strict_types=1);

define('IN_MYBB', 1);

function htmlspecialchars_uni($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$calls = [];
function af_kb_get_public_type_options(string $typeKey, int $limit = 500): array
{
    global $calls;
    $calls[] = ['public', $typeKey, $limit];
    if ($typeKey === 'arpg_origin') {
        return [
            ['key' => 'demihuman', 'label_ru' => 'Демихуман'],
            ['key' => 'construct', 'label_ru' => 'Конструкт'],
        ];
    }
    return [['key' => $typeKey . '_key', 'label_ru' => 'Название ' . $typeKey]];
}
function af_kb_get_arpg_mechanics_options(string $entryKey, string $serviceKind = 'snippet'): array
{
    global $calls;
    $calls[] = ['mechanics', $entryKey, $serviceKind];
    return [['key' => $entryKey . '_key', 'label_ru' => 'Название ' . $entryKey]];
}
function af_kb_get_origin_variants(string $originKey, bool $activeOnly = true): array
{
    global $calls;
    $calls[] = ['variants', $originKey, $activeOnly];
    $variants = [
        'demihuman' => [['variant' => ['key' => 'wolf', 'title_ru' => 'Волк', 'title_en' => 'Wolf']]],
        'construct' => [['variant' => ['key' => 'golem', 'title_ru' => 'Голем', 'title_en' => 'Golem']]],
    ];
    return $variants[$originKey] ?? [];
}

require_once __DIR__ . '/../inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

function kb_field(int $id, string $key, string $source, string $dependsOn = ''): array
{
    return [
        'id' => $id,
        'field_key' => $key,
        'title' => $key,
        'type' => 'kb_dynamic',
        'required' => 0,
        'settings' => ['source' => $source, 'depends_on' => $dependsOn],
    ];
}

$map = af_wanted_kb_source_map();
check(array_keys($map) === ['origin', 'origin_variant', 'archetype', 'faction', 'element', 'weapon', 'gender'], 'all accepted sources have an explicit resolver mapping');

foreach (['origin' => 'arpg_origin', 'archetype' => 'arpg_archetype', 'faction' => 'arpg_faction', 'element' => 'arpg_element'] as $source => $type) {
    $options = af_wanted_options(kb_field(1, $source, $source));
    $expected = $source === 'origin'
        ? ['demihuman' => 'Демихуман', 'construct' => 'Конструкт']
        : [$type . '_key' => 'Название ' . $type];
    check($options === $expected, $source . ' uses the public KB type registry and preserves its machine key');
}

$weapon = af_wanted_options(kb_field(5, 'weapon', 'weapon'));
$gender = af_wanted_options(kb_field(6, 'gender', 'gender'));
check($weapon === ['weapon_type_key' => 'Название weapon_type'], 'weapon uses the weapon_type mechanics service');
check($gender === ['character_gender_key' => 'Название character_gender'], 'gender uses the character_gender mechanics service');
check(in_array(['mechanics', 'weapon_type', 'weapon_type'], $calls, true), 'weapon service_kind is passed to the real resolver');
check(in_array(['mechanics', 'character_gender', 'snippet'], $calls, true), 'gender service_kind is passed to the real resolver');

$origin = kb_field(1, 'origin', 'origin');
$variant = kb_field(2, 'origin_variant', 'origin_variant', 'origin');
check(af_wanted_options($variant, ['origin' => 'demihuman']) === ['wolf' => 'Волк'], 'dependent variant unwraps the real relation row shape');
check(af_wanted_label($variant, 'wolf', ['origin' => 'demihuman']) === 'Волк', 'dependent label resolution receives full parent context');
check(af_wanted_options($variant, ['origin' => 'construct']) === ['golem' => 'Голем'], 'changing origin replaces the available variants');

[$valid, $validErrors] = af_wanted_validate([$origin, $variant], ['origin' => 'demihuman', 'origin_variant' => 'wolf']);
check($validErrors === [] && $valid[1] === 'demihuman' && $valid[2] === 'wolf', 'canonical machine keys survive server validation for storage');
[, $invalidErrors] = af_wanted_validate([$origin, $variant], ['origin' => 'unknown', 'origin_variant' => 'wolf']);
check(count($invalidErrors) === 2, 'server rejects an unknown origin and its incompatible variant');
[, $incompatibleErrors] = af_wanted_validate([$variant], ['origin' => 'construct', 'origin_variant' => 'wolf']);
check(count($incompatibleErrors) === 1, 'server rejects an incompatible origin and variant pair');

$dependencyData = af_wanted_dependency_data([$origin, $variant]);
check($dependencyData[0]['options']['demihuman']['wolf'] === 'Волк', 'frontend dependency payload contains labels sourced from KB');
$script = af_wanted_dependency_script([$origin, $variant]);
check(strpos($script, 'parent.addEventListener("change"') !== false, 'origin change updates variants without form submission');
check(strpos($script, 'child.value=""') !== false, 'origin change clears the previously selected variant');

echo "advancedwanted KB dynamic regression checks passed\n";

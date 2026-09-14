<?php

$source = file_get_contents(__DIR__ . '/../inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php');
if (!is_string($source)) {
    fwrite(STDERR, "Unable to read KnowledgeBase source\n");
    exit(1);
}

function arpg_docs_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$mechanics = [
    'ability_type', 'ability_subtype', 'ability_slot', 'ability_damage_type',
    'ability_targeting', 'ability_range', 'combat_duration_unit',
    'ability_effect_type', 'ability_effect_operation', 'ability_resource',
    'ability_value_mode', 'character_gender', 'formula_profile', 'weapon_type',
];

arpg_docs_assert(strpos($source, 'function af_kb_arpg_mechanics_documentation(): array') !== false, 'Documentation registry is missing');
arpg_docs_assert(strpos($source, 'function af_kb_fill_arpg_mechanics_documentation(): void') !== false, 'Safe documentation fill is missing');
foreach ($mechanics as $key) {
    arpg_docs_assert(substr_count($source, "'{$key}' =>") >= 2, "Mechanic {$key} is not both defined and documented");
}

$fillStart = strpos($source, 'function af_kb_fill_arpg_mechanics_documentation(): void');
$fillEnd = strpos($source, 'function af_kb_get_public_type_options', $fillStart);
$fillSource = substr($source, $fillStart, $fillEnd - $fillStart);
arpg_docs_assert(strpos($fillSource, "['body_ru', 'body_en', 'tech_ru', 'tech_en']") !== false, 'Documentation fill is not limited to documentation fields');
foreach (['data_json', 'meta_json', 'title_ru', 'label_ru'] as $forbidden) {
    arpg_docs_assert(strpos($fillSource, "['{$forbidden}']") === false, "Documentation fill may overwrite {$forbidden}");
}
arpg_docs_assert(strpos($fillSource, "trim((string)(\$existing[\$field] ?? '')) === ''") !== false, 'Existing non-empty documentation is not protected');

$viewStart = strpos($source, 'function af_kb_handle_view(): void');
$catalogStart = strpos($source, 'if ($type === \'\') {', $viewStart);
$typeListStart = strpos($source, 'if ($key === \'\') {', $catalogStart);
$catalogSource = substr($source, $catalogStart, $typeListStart - $catalogStart);
$typeListEnd = strpos($source, "\n    \$typeRow = af_kb_find_type_row(\$type);", $typeListStart);
$typeListSource = substr($source, $typeListStart, $typeListEnd - $typeListStart);
$catalogTemplate = file_get_contents(__DIR__ . '/../inc/plugins/advancedfunctionality/addons/knowledgebase/templates/knowledgebase_catalog.html');
arpg_docs_assert(strpos($catalogSource, '$kb_mechanics_link = af_kb_can_edit()') !== false, 'ARPG Mechanics button does not reuse the KB moderator permission');
arpg_docs_assert(strpos($catalogSource, 'href="kb.php?type=arpg_mechanics">ARPG Mechanics</a>') !== false, 'ARPG Mechanics moderator button is missing from the catalog');
arpg_docs_assert(is_string($catalogTemplate) && strpos($catalogTemplate, '{$kb_mechanics_link}') !== false, 'ARPG Mechanics button is not rendered in catalog actions');
arpg_docs_assert(strpos($typeListSource, 'href="kb.php?type=arpg_mechanics">ARPG Mechanics</a>') === false, 'ARPG Mechanics self-link remains on its type page');
arpg_docs_assert(strpos($source, 'Public arpg_mechanics') === false, 'Unexpected public navigation marker found');

echo "ARPG mechanics documentation regression checks passed\n";

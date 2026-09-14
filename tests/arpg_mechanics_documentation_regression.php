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

arpg_docs_assert(strpos($source, "if (af_kb_can_edit()) {\n            \$actions[] = '<a class=\"af-kb-btn af-kb-btn--create") !== false, 'Expected moderator action permission block is missing');
arpg_docs_assert(strpos($source, 'href="kb.php?type=arpg_mechanics">ARPG Mechanics</a>') !== false, 'ARPG Mechanics moderator button is missing');
arpg_docs_assert(strpos($source, 'Public arpg_mechanics') === false, 'Unexpected public navigation marker found');

echo "ARPG mechanics documentation regression checks passed\n";

<?php
declare(strict_types=1);

function advancedcharacters_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$root = dirname(__DIR__);
$addon = $root . '/inc/plugins/advancedfunctionality/addons/advancedcharacters';
$kb = (string)file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/knowledgebase/knowledgebase.php');
$js = (string)file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase_chips.js');
$page = (string)file_get_contents($root . '/characters.php');
$bootstrap = (string)file_get_contents($addon . '/advancedcharacters.php');

advancedcharacters_assert(is_file($addon . '/manifest.php'), 'AdvancedCharacters manifest is missing');
advancedcharacters_assert(strpos($page, "define('AF_CHARACTERS_PAGE_ALIAS', 1)") !== false, 'Public /characters.php entry point is missing');
advancedcharacters_assert(strpos($bootstrap, "\$mybb->input['type'] = 'character'") !== false, 'Showcase does not route to the KB Character query');
advancedcharacters_assert(strpos($bootstrap, 'af_kb_render_kb_page()') !== false, 'Showcase does not reuse the KB list renderer');
advancedcharacters_assert(strpos($kb, 'af_kb_catalog_entry_card($row, (array)$typeRow)') !== false, 'Shared KB Character card renderer is not used');
foreach (['kind', 'gender', 'origin', 'variant', 'element', 'archetype', 'faction', 'age'] as $filter) {
    advancedcharacters_assert(strpos($kb, "'{$filter}'") !== false, "Character filter is missing: {$filter}");
    advancedcharacters_assert(strpos($js, "'{$filter}'") !== false, "AJAX URL support is missing: {$filter}");
}
advancedcharacters_assert(strpos($kb, "COALESCE(NULLIF(type_key,''),type)<>'character'") !== false, 'Character type card is not hidden visually');
foreach (['af_advancedcharacters_menu_provider', 'af_characters_add_moderator_link', 'data-af-characters-mod-link', "'key'=>'characters'"] as $needle) {
    advancedcharacters_assert(strpos($bootstrap, $needle) === false, 'Automatic Characters menu integration remains: '.$needle);
}
advancedcharacters_assert(strpos($kb, 'af_kb_get_origin_variants($originKey, true)') !== false, 'Origin Variant does not use the KB resolver');
advancedcharacters_assert(strpos($kb, 'Персонажи пока не добавлены.') !== false, 'Required empty state is missing');
advancedcharacters_assert(strpos($bootstrap, 'CREATE TABLE') === false && strpos($bootstrap, 'af_characters_entries') === false, 'A parallel character storage was introduced');

echo "AdvancedCharacters catalog regression checks passed.\n";

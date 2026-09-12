<?php
declare(strict_types=1);

function dynamic_controls_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function dynamic_controls_function_source(string $source, string $start, string $end): string
{
    $startPosition = strpos($source, $start);
    $endPosition = $startPosition === false ? false : strpos($source, $end, $startPosition + strlen($start));
    dynamic_controls_assert($startPosition !== false && $endPosition !== false, 'Unable to locate dynamic control renderer: ' . $start);

    return substr($source, $startPosition, $endPosition - $startPosition);
}

$jsPath = __DIR__ . '/../inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase.js';
$jsSource = file_get_contents($jsPath);
dynamic_controls_assert(is_string($jsSource), 'Unable to inspect knowledgebase.js');

$seededEditor = dynamic_controls_function_source($jsSource, 'function renderSeededArrayEditor(', 'function renderRuleFields(');
$blocksEditor = dynamic_controls_function_source($jsSource, 'function renderBlocks()', 'function renderSimpleRules()');

dynamic_controls_assert(substr_count($seededEditor, "className = 'af-kb-add'") === 1, 'Seeded array editor renders more than one Add control');
dynamic_controls_assert(substr_count($blocksEditor, "className = 'af-kb-add'") === 1, 'Display / lore blocks renders more than one Add control');

foreach ([$seededEditor, $blocksEditor] as $editorSource) {
    dynamic_controls_assert(strpos($editorSource, "className = 'af-kb-remove'") !== false, 'A dynamic editor lost its Remove control');
    dynamic_controls_assert(strpos($editorSource, 'redraw();') !== false, 'A dynamic editor no longer redraws after changes');
}
dynamic_controls_assert(strpos($blocksEditor, 'syncBack();') !== false, 'Display / lore blocks no longer synchronizes data');
dynamic_controls_assert(strpos($blocksEditor, 'payload.blocks = blocksCompat.map') !== false, 'Display / lore block save mapping disappeared');

echo "KB dynamic control regression checks passed\n";

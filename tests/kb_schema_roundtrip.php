<?php
declare(strict_types=1);

define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__ . '/../inc/plugins/advancedfunctionality/addons/');
require_once AF_ADDONS . 'knowledgebase/knowledgebase.php';

function kb_roundtrip_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$legacyMeta = [
    'schema' => 'af_kb.meta.v1',
    'stats' => ['legacy_zero' => 0],
    'bonuses' => ['penalty' => -2.5],
    'links' => ['old_renderer' => true],
    'ui' => ['icon_url' => '/icon.png', 'extension_key' => ['enabled' => false]],
    'rules' => ['schema' => 'custom.rules.v1', 'unknown_rule' => ['decimal' => 1.25]],
    'legacy_extension' => ['nested' => ['value' => 0]],
];
$cleaned = af_kb_cleanup_meta_payload($legacyMeta);
kb_roundtrip_assert($cleaned === $legacyMeta, 'Meta cleanup removed or coerced legacy/unknown data');

$existingBlocks = [[
    'block_key' => 'overview',
    'level' => 1,
    'title' => ['ru' => 'До', 'en' => 'Before', 'legacy_locale' => 'Kept'],
    'effects' => [['value' => -0.5]],
    'data' => ['layout' => ['columns' => 0]],
    'renderer_extension' => ['enabled' => false],
]];
$postedBlocks = [[
    'block_key' => 'overview',
    'title_ru' => 'После',
    'title_en' => 'After',
    'data_json' => '{"level":0,"effects":[{"value":-0.5}],"layout":{"columns":0},"decimal":1.25}',
]];
$mergedBlocks = af_kb_merge_meta_blocks_for_save($existingBlocks, $postedBlocks);
kb_roundtrip_assert($mergedBlocks[0]['renderer_extension'] === ['enabled' => false], 'Unknown block key was lost');
kb_roundtrip_assert($mergedBlocks[0]['title']['legacy_locale'] === 'Kept', 'Unknown nested title key was lost');
kb_roundtrip_assert($mergedBlocks[0]['level'] === 0, 'Numeric zero was treated as empty');
kb_roundtrip_assert($mergedBlocks[0]['effects'][0]['value'] === -0.5, 'Negative decimal was coerced');
kb_roundtrip_assert($mergedBlocks[0]['data']['decimal'] === 1.25, 'Decimal block data was coerced');
kb_roundtrip_assert($mergedBlocks[0]['renderer_extension']['enabled'] === false, 'Boolean was coerced');

$normalized = af_kb_normalize_json('{"zero":0,"negative":-7,"decimal":1.25,"bool":false,"nested":[{"custom":true}]}');
$decoded = json_decode($normalized, true, 512, JSON_THROW_ON_ERROR);
kb_roundtrip_assert($decoded === ['zero' => 0, 'negative' => -7, 'decimal' => 1.25, 'bool' => false, 'nested' => [['custom' => true]]], 'JSON scalar types changed during normalization');

echo "KB schema round-trip checks passed\n";

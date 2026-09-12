<?php
declare(strict_types=1);

define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__ . '/../inc/plugins/advancedfunctionality/addons/');
require_once AF_ADDONS . 'knowledgebase/knowledgebase.php';

function kb_filter_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$sets = [
    'gender' => ['male' => 'Мужской', 'female' => 'Женский'],
    'origin' => ['yokai' => 'Ёкай'],
    'element' => ['ice' => 'Лёд'],
];
$filters = af_kb_character_normalize_filters([
    'kind' => 'original', 'gender' => 'female', 'origin' => 'yokai', 'element' => 'ice',
], $sets);
kb_filter_assert($filters === ['kind' => 'originals', 'gender' => 'female', 'origin' => 'yokai', 'element' => 'ice'], 'Valid filters changed contract values');

$invalid = af_kb_character_normalize_filters([
    'kind' => "original' OR 1=1", 'gender' => 'robot', 'origin' => 'unknown', 'element' => 'fire',
], $sets);
kb_filter_assert($invalid === ['kind' => '', 'gender' => '', 'origin' => '', 'element' => ''], 'Unknown GET values were not rejected');

$entry = ['data_json' => json_encode(['character_profile' => [
    'category' => 'originals', 'character_gen' => 'female', 'character_race' => 'yokai', 'character_element' => 'ice',
]], JSON_UNESCAPED_UNICODE)];
kb_filter_assert(af_kb_character_matches_filters($entry, $filters), 'Combined filters do not produce an intersection');
$filters['element'] = '';
kb_filter_assert(af_kb_character_matches_filters($entry, $filters), 'All/default element added a condition');
$filters['gender'] = 'male';
kb_filter_assert(!af_kb_character_matches_filters($entry, $filters), 'Mismatched gender was accepted');

$source = file_get_contents(AF_ADDONS . 'knowledgebase/knowledgebase.php');
kb_filter_assert(is_string($source) && strpos($source, 'af_kb_categories_enabled() && !$isCharacterList') !== false, 'Generic Character categories (including Roles) are still rendered');

echo "KB character filter regression checks passed.\n";

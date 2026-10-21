<?php
declare(strict_types=1);
define('IN_MYBB', 1);
define('MYBB_ROOT', dirname(__DIR__) . '/');
define('AF_ADDONS', MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/');
define('TABLE_PREFIX', 'test_');
define('TIME_NOW', 1000);
function htmlspecialchars_uni($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function my_strlen(string $value): int { return mb_strlen($value); }
function my_substr(string $value, int $start, ?int $length = null): string { return mb_substr($value, $start, $length); }
function af_charactersheets_json_decode(string $json): array { return json_decode($json, true) ?: []; }
final class AccuracyResult {
    public int $pos = 0;
    public function __construct(public array $rows = []) {}
}
final class AccuracyDb {
    public array $values = [];
    public function table_exists(string $table): bool { return $table === 'af_atf_values'; }
    public function escape_string(string $value): string { return addslashes($value); }
    public function simple_select(string $table, ...$args): AccuracyResult {
        return new AccuracyResult($table === 'af_atf_values' ? $this->values : []);
    }
    public function fetch_array(AccuracyResult $result) { return $result->rows[$result->pos++] ?? false; }
    public function write_query(string $sql): void {
        if (!preg_match("/VALUES \((\d+), (\d+), '([^']*)'\)/", $sql, $match)) throw new RuntimeException('Unexpected SQL');
        $this->values = [['fieldid' => (int)$match[2], 'value' => $match[3]]];
    }
    public function delete_query(string $table, string $where): void { $this->values = []; }
}
final class MyBB {
    const INPUT_ARRAY = 1;
    public array $input = [];
    public array $settings = [];
    public function get_input(string $key, int $type = 0) { return $this->input[$key] ?? ($type ? [] : ''); }
}
$field = ['fieldid' => 1, 'name' => 'character_accuracy', 'title' => 'Accuracy', 'type' => 'number', 'maxlen' => 32,
    'options' => '', 'required' => 0, 'regex' => '', 'active' => 1, 'group_active' => 1, 'sortorder' => 1, 'forums_set' => []];
$cache = new class($field) {
    public function __construct(private array $field) {}
    public function read(string $key): array { return ['fields' => [$this->field], 'groups' => [['active' => 1, 'character_mechanic_mode' => 'arpg']]]; }
};
$db = new AccuracyDb();
$mybb = new MyBB();
require_once AF_ADDONS . 'advancedthreadfields/advancedthreadfields.php';
require_once AF_ADDONS . 'knowledgebase/knowledgebase.php';
require_once AF_ADDONS . 'charactersheets/modules/render.php';
function accuracy_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function accuracy_handler(string $value): object {
    global $mybb;
    $mybb->input['af_tf'] = [1 => $value];
    $handler = new class {
        public array $data = ['fid' => 2, 'message' => 'Application'];
        public array $errors = [];
        public function set_error(string $error): void { $this->errors[] = $error; }
    };
    af_atf_dh_validate($handler);
    return $handler;
}
foreach (['0', '27.5', ''] as $input) {
    $handler = accuracy_handler($input);
    accuracy_assert(!$handler->errors, 'Valid Accuracy rejected by ATF');
    af_atf_save_values(52, $handler->data['af_atf_values']);
    $values = af_atf_get_values_by_tid(52);
    $html = af_atf_build_input_html($field, $values[1] ?? '');
    accuracy_assert(str_contains($html, 'value="' . $input . '"'), 'Reopening ATF changed Accuracy');
    accuracy_assert(str_contains($html, 'step="any"'), 'Fractional Accuracy unavailable');
    $index = ['character_accuracy' => ['value' => $values[1] ?? '']];
    $rules = af_atf_bridge_sync_accuracy(['schema' => AF_KB_CHARACTER_SCHEMA, 'type_profile' => 'character',
        'character_profile' => [], 'character_abilities' => [], 'character_links' => [], 'character_meta' => ['mechanic' => 'arpg']], $index);
    $errors = [];
    $json = af_kb_validate_rules_json_by_type_arpg('character', json_encode($rules, JSON_THROW_ON_ERROR), $errors);
    accuracy_assert(!$errors, 'KB save rejected Accuracy: ' . implode('; ', $errors));
    $entry = ['type' => 'character', 'key' => 'accuracy-fixture', 'data_json' => $json, 'meta_json' => '{}'];
    $payload = af_charactersheets_extract_character_contract_payload($entry);
    $prefill = af_kb_build_character_application_prefill($entry)['values'];
    if ($input === '') {
        accuracy_assert(!isset($payload['stats']['character_accuracy']), 'Legacy missing Accuracy became zero');
        accuracy_assert(!isset($prefill['character_accuracy']), 'Legacy prefill invented Accuracy');
        accuracy_assert(af_charactersheets_arpg_stat_from_sources($payload['stats'], 'character_accuracy', []) === '—', 'Missing Accuracy display became zero');
        continue;
    }
    accuracy_assert($payload['stats']['character_accuracy'] === (float)$input, 'KB → CharacterSheets changed Accuracy');
    accuracy_assert((float)$prefill['character_accuracy'] === (float)$input, 'KB → application prefill changed Accuracy');
    $base = af_charactersheets_arpg_merge_runtime_stats($payload['stats'], af_charactersheets_arpg_build_stats_from_kb(['accuracy_base' => 99], [], 1));
    accuracy_assert($base['character_accuracy'] === (float)$input, 'Explicit Accuracy including zero was overridden by origin');
    $item = ['base_stats' => [['stat_key' => 'accuracy', 'mode' => 'flat', 'value' => 3]],
        'modifiers' => [['stat_key' => 'accuracy', 'mode' => 'flat', 'value' => 100, 'condition_text' => 'during combat']]];
    $talent = ['modifiers' => [['stat_key' => 'accuracy', 'mode' => 'flat', 'value' => 2]]];
    $final = af_charactersheets_arpg_apply_equipment_rules($base, [$item, $talent]);
    accuracy_assert($final['character_accuracy'] === (float)$input + 5, 'Permitted equipment/talent bonuses misapplied');
    accuracy_assert((float)$prefill['character_accuracy'] === (float)$input, 'Computed equipment leaked into saved base');
}
foreach (['-1', 'not-a-number', '1e999'] as $invalid) accuracy_assert((bool)accuracy_handler($invalid)->errors, 'Invalid Accuracy accepted');
$computedLegacy = af_charactersheets_arpg_build_stats_from_kb([], [], 1);
accuracy_assert(!array_key_exists('character_accuracy', $computedLegacy), 'CharacterSheets synthesized a missing base');
accuracy_assert(!array_key_exists('character_accuracy', json_decode(af_atf_normalize_character_stats_json('{}'), true)), 'Grouped legacy normalization synthesized zero');
$legacy = af_atf_bridge_sync_accuracy(['character_profile' => []], []);
accuracy_assert(!isset($legacy['character_stats']), 'Missing legacy field materialized');
$grouped = af_atf_bridge_sync_accuracy([], ['character_stats' => ['value' => '{"character_accuracy":0}']]);
accuracy_assert($grouped['character_stats']['character_accuracy'] === 0.0, 'Grouped zero lost');
accuracy_assert(!in_array('character_accuracy', af_atf_character_stats_percent_keys(), true), 'Accuracy became a percentage');
echo "Accuracy ATF save/read → KB → CharacterSheets → prefill, zero/missing and bonuses: passed\n";

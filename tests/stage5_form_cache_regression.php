<?php
require __DIR__ . '/fixtures/stage5_form.php';
function stage5_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
stage5_assert(substr_count($formHtml, 'af-atf-form-field') === 15, 'Every field must own a wrapper');
stage5_assert(substr_count($formHtml, 'af-atf-field-label') === 15, 'Every field must own a label');
stage5_assert(substr_count($formHtml, 'af-atf-field-control') === 15, 'Every field must own a control');
stage5_assert(substr_count($formHtml, 'af-atf-form-section') === 3, 'Editor/abilities fields must be full-width sections');
stage5_assert(substr_count($formHtml, 'data-af-ae-editor="1"') === 2, 'AdvancedEditor lazy markers must survive');
// Exercise real KB resolver against a counting DB, including missing values.
$db = new class {
    public int $queries = 0;
    public function table_exists($table): bool { return $table === 'af_kb_entries'; }
    public function escape_string($s): string { return addslashes($s); }
    public function simple_select(...$args) { ++$this->queries; $rows = [
        ['type' => 'arpg_origin', 'key' => 'human', 'title_ru' => 'Человек'],
        ['type' => 'arpg_archetype', 'key' => 'mage', 'title_ru' => 'Маг'],
        ['type' => 'arpg_faction', 'key' => 'guild', 'title_ru' => 'Гильдия'],
        ['type' => 'arpg_element', 'key' => 'fire', 'title_ru' => 'Огонь'],
    ];
        $where=(string)($args[2] ?? '');
        $rows=array_values(array_filter($rows,static fn($row) => str_contains($where, "type='".$row['type']."' AND `key`='".$row['key']."'")));
        return (object)['rows'=>$rows,'index'=>0];
    }
    public function fetch_array($q) { return $q->rows[$q->index++] ?? false; }
};
$kbFields=[];$kbValues=[];
foreach (['origin'=>'human','archetype'=>'mage','faction'=>'guild','element'=>'fire','origin_variant'=>'missing'] as $name=>$value) {
    $id=count($kbFields)+1;
    $kbFields[]=['fieldid'=>$id,'name'=>'character_'.$name,'type'=>'kb_dynamic','options'=>'kb_type=arpg_'.$name];
    $kbValues[$id]=$value;
}
foreach ($kbFields as $field) af_atf_character_resolve_kb_type($field,$kbValues[$field['fieldid']]);
stage5_assert($db->queries===5, 'Diagnostic must expose five unbatched field lookups');
$GLOBALS['af_atf_kb_entry_cache']=[];
$db->queries=0;
af_atf_preload_field_kb_entries($kbFields,$kbValues);
foreach ($kbFields as $field) {
    af_atf_character_resolve_kb_type($field,$kbValues[$field['fieldid']]);
    af_atf_character_resolve_kb_type($field,$kbValues[$field['fieldid']]);
}
stage5_assert($db->queries===1,'KB type probing must reuse a single batch, including misses');
af_atf_preload_field_kb_entries($kbFields,$kbValues);
stage5_assert($db->queries===1,'Repeated field resolution must not repeat SQL');
$before=$db->queries;
$thread=['tid'=>77,'af_atf_forum_chips'=>'stale'];$fid=3;
af_atf_forumdisplay_thread();
stage5_assert($thread['af_atf_forum_chips']==='' && af_atf_render_forum_chips(['tid'=>77,'fid'=>3])==='', 'Forum topic cards must stay clean');
stage5_assert($db->queries===$before,'Forumdisplay must not resolve fields');
// The cache contract includes negative results and both ids.
$GLOBALS['af_atf_values_by_tid_cache'] = []; // Fake DB returns no values for this portion.
$db = new class {
    public int $queries=0;
    public function table_exists($t): bool { return false; }
};
$GLOBALS['af_atf_display_block_cache']=[];
stage5_assert(af_atf_build_display_block_for_tid_fid(991,3)==='', 'Empty display expected');
stage5_assert(array_key_exists('991:3',$GLOBALS['af_atf_display_block_cache']), 'Negative display block result must be cached');
$GLOBALS['af_atf_display_block_cache']['991:3']='cached';
stage5_assert(af_atf_build_display_block_for_tid_fid(991,3)==='cached','Display consumers must share one request cache');
echo "Stage 5 form structure, KB batch, forumdisplay and display cache passed.\n";

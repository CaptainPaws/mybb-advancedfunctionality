<?php
// Exercise the production section codec and manifest-derived extraction with edited bodies.
define('AF_PLUGIN_ID', 'advancedfunctionality');
$core = file_get_contents(dirname(__DIR__).'/inc/plugins/advancedfunctionality.php');
foreach (['af_theme_stylesheet_section_id','af_theme_stylesheet_encode_section','af_theme_stylesheet_parse_bundle','af_theme_stylesheet_canonical_source_file'] as $name) {
    $start = strpos($core, 'function '.$name.'(');
    $end = strpos($core, "\nfunction ", $start + 1);
    if ($start === false) throw new RuntimeException('Missing production function '.$name);
    eval(substr($core, $start, $end - $start));
}
define('AF_AE_ID', 'advancededitor');
require dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/advancededitor/theme_capabilities.php';
$file = 'assets/bbcodes/bbcodes/tables/tables.css';
$edited = ".af-bb-table{color:purple}\n.af-ae-tables-dropdown{border-radius:13px}";
$target = af_theme_stylesheet_encode_section(['addon_id'=>'advancededitor','logical_id'=>'legacy_tables','source_file'=>$file,'manual_override'=>true], $edited);
$unrelated = af_theme_stylesheet_encode_section(['addon_id'=>'knowledgebase','logical_id'=>'kb_main','source_file'=>'assets/knowledgebase.css'], '.af-kb-chip{color:green}');
$shell = af_theme_stylesheet_encode_section(['addon_id'=>'advancededitor','logical_id'=>'shell','source_file'=>'assets/advancededitor_shell.css'], '.af-ae-shell{width:100%}');
$manual = af_theme_stylesheet_encode_section(['addon_id'=>'manual','logical_id'=>'custom','source_file'=>'custom.css'], '/* manual tail */');
$css = "/* manual preamble */\n".$unrelated.$target.$shell.$manual;
$split = af_advancededitor_detach_feature_sections($css, [af_theme_stylesheet_canonical_source_file('advancededitor',$file)=>true]);
if (empty($split['ok']) || count($split['sections']) !== 1 || $split['sections'][0]['body'] !== $edited) throw new RuntimeException('Theme edits were not preserved for capability CSS export');
if (str_contains($split['source'], '.af-ae-tables-dropdown') || str_contains($split['source'], '.af-bb-table')) throw new RuntimeException('Feature CSS remained in the global bundle');
foreach ([$unrelated,$shell,'/* manual preamble */','/* manual tail */'] as $kept) if (!str_contains($split['source'],$kept)) throw new RuntimeException('Unrelated theme CSS changed');
if (af_advancededitor_detach_feature_sections($split['source'],[$file=>true])['source'] !== $split['source']) throw new RuntimeException('Migration is not idempotent');
if (!empty(af_advancededitor_detach_feature_sections('corrupt AF bundle',[$file=>true])['ok'])) throw new RuntimeException('Corrupt bundle must not be rewritten');
echo "AdvancedEditor feature CSS migration preserves edits and removes global sections.\n";

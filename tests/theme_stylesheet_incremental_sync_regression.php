<?php
declare(strict_types=1);
define('AF_PLUGIN_ID', 'advancedfunctionality');

$core = (string)file_get_contents(dirname(__DIR__).'/inc/plugins/advancedfunctionality.php');
function extractAfFunction(string $source, string $name): string {
    $start = strpos($source, 'function '.$name.'(');
    if ($start === false) throw new RuntimeException("missing {$name}");
    $brace = strpos($source, '{', $start); $depth = 0;
    for ($i=$brace, $n=strlen($source); $i<$n; $i++) {
        if ($source[$i] === '{') $depth++;
        elseif ($source[$i] === '}' && --$depth === 0) return substr($source, $start, $i-$start+1);
    }
    throw new RuntimeException("unterminated {$name}");
}
foreach (['af_theme_stylesheet_canonical_source_file','af_theme_stylesheet_section_id','af_theme_stylesheet_encode_section','af_theme_stylesheet_parse_bundle','af_theme_stylesheet_incremental_bundle'] as $fn) eval(extractAfFunction($core, $fn));
function section(string $addon, string $logical, string $file, string $body, string $seed): string {
    return af_theme_stylesheet_encode_section([
        'addon_id'=>$addon, 'logical_id'=>$logical, 'source_file'=>$file,
        'seed_body_sha1'=>sha1($seed), 'seed_checksum'=>sha1($seed),
    ], $body);
}
function bundle(array $sections): array {
    $source="/* AdvancedFunctionality theme bundle (structured v1). Edit sections in AF ACP. */\n\n".implode("\n",$sections);
    return ['source'=>$source,'checksum'=>sha1($source)];
}
$old = bundle([section('one','one__css','one.css','red','red'), section('two','two__css','two.css','blue','red')]);
$fresh = bundle([section('one','one__css','one.css','green','green'), section('two','two__css','two.css','green','green'), section('three','three__css','three.css','new','new')]);
$merged=af_theme_stylesheet_incremental_bundle($old['source'],$fresh);
if (empty($merged['ok'])) throw new RuntimeException((string)$merged['error']);
$parsed=af_theme_stylesheet_parse_bundle($merged['source']); $bodies=[]; $metadata=[];
foreach($parsed['sections'] as $s){$bodies[$s['meta']['addon_id']]=$s['body'];$metadata[$s['meta']['addon_id']]=$s['meta'];}
if ($bodies !== ['one'=>'green','two'=>'blue','three'=>'new']) throw new RuntimeException('three-way merge result mismatch: '.json_encode($bodies));
if (empty($metadata['two']['manual_override']) || empty($metadata['two']['seed_changed'])) throw new RuntimeException('manual seed conflict not recorded');
$again=af_theme_stylesheet_incremental_bundle($merged['source'],$fresh);
$againParsed=af_theme_stylesheet_parse_bundle($again['source']);
if ($againParsed['sections'][array_keys($againParsed['sections'])[1]]['body'] !== 'blue') throw new RuntimeException('reactivation overwrote manual body');
if (count($againParsed['sections']) !== 3) throw new RuntimeException('missing/new sections were not stable');
echo "AF incremental sync passed: automatic seed update, manual conflict preservation, missing section addition.\n";

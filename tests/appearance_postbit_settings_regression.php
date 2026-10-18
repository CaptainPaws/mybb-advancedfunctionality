<?php
/** Small schema/compiler regression; no MyBB database, browser, network or upload server. */
define('IN_MYBB',1);
define('AF_ADDONS',dirname(__DIR__).'/inc/plugins/advancedfunctionality/addons/');
define('AF_AA_TARGET_APUI_POSTBIT_PACK','postbit');
define('AF_AA_TARGET_APUI_PROFILE_PACK','profile');
define('AF_AA_TARGET_APUI_SHEET_PACK','sheet');
$mybb=(object)['settings'=>[]];
function af_advancededitor_collect_font_families_for_payload(): array {return [['name'=>'Example Local Font','system'=>0]];}
function ag_remote_is_domain_allowed(string $host): bool {return $host==='images.example.org';}
function ag_resolve_media_url(int $id,string $variant): string {return $id===42?'https://images.example.org/original.gif':'';}
function htmlspecialchars_uni($value): string {return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function af_aa_css_url_value(string $url): string {return $url===''?'none':'url("'.str_replace(['"','\\'],['%22','%5C'],$url).'")';}
require AF_ADDONS.'advancedappearance/personal.php';
require AF_ADDONS.'advancedappearance/studio.php';
require AF_ADDONS.'advancedappearance/surfaces.php';
function check($condition,string $message): void {if (!$condition) throw new RuntimeException($message);}
function reject(string $json): void {try {af_aa_personal_decode($json,7,false,false);}catch(AF_AA_PersonalException $e){check($e->getCode()===422,$e->getMessage());return;}throw new RuntimeException('Unsafe settings accepted: '.$json);}
$font=array_key_last(af_aa_personal_fonts());
$settings=['components'=>[
    'decoration'=>['gem_color'=>'#12abcd','gem_glow'=>8],
    'topbar'=>['haze_color'=>'#00aaff','haze_intensity'=>.2,'haze_angle'=>115,'background_url'=>'https://images.example.org/header.gif','overlay_mode'=>'darken','overlay_start'=>.8,'overlay_end'=>.1,'overlay_intensity'=>.6],
    'sidebar'=>['background_color'=>'#102030','overlay_mode'=>'lighten','overlay_intensity'=>.25],
    'primary_avatar'=>['image_media_id'=>42,'image_fit'=>'contain','image_position'=>'top'],
    'secondary_avatar'=>['image_url'=>'https://images.example.org/portrait.gif'],
    'plaque_icon'=>['image_media_id'=>42,'image_scale'=>125],
    'plaque_title'=>['label_text'=>'Новая подпись','font_family'=>$font,'font_style'=>'italic'],
    'plaque_subtitle'=>['label_text'=>'Вторая подпись','text_color'=>'#fedcba'],
    'signature'=>['font_family'=>$font,'text_color'=>'#abcdef','link_color'=>'#123abc','hover_color'=>'#aabbcc','overlay_mode'=>'lighten','glow_intensity'=>3],
    'statistics'=>['background_color'=>'#223344','value_color'=>'#556677','icon_color'=>'#8899aa'],
    'metadata'=>['background_color'=>'#445566'],
    'message'=>['text_color'=>'#aabbdd','glow_intensity'=>2],
], 'canvas'=>['preset'=>'obsolete-value']];
$decoded=af_aa_personal_decode(json_encode($settings),7,false,false);
check(!isset($decoded['canvas']),'Old postbit Canvas values must be ignored');
$roundtrip=af_aa_personal_decode(af_aa_personal_encode($decoded),7,false,false);
check($roundtrip===$decoded,'Sparse preset round-trip lost fields');
$css=af_aa_studio_compile(7,$decoded);
check(str_contains($css,'header.gif') && str_ends_with(af_aa_postbit_image($decoded['components']['primary_avatar']),'/original.gif') && str_ends_with(af_aa_postbit_image($decoded['components']['secondary_avatar']),'/portrait.gif'),'Images must keep original GIF URLs');
check(str_contains($css,'--aa-gem-color:#12abcd') && !str_contains($css,'hue-rotate'),'Only accent facets may be tinted');
check(str_contains($css,'--aa-avatar-fit:contain') && str_contains($css,'--aa-avatar-position:top'),'Avatar crop must reach ATF variables');
check(str_contains($css,'--aa-metadata-background:') && str_contains($css,'--aa-statistics-background:'),'Native priority rules must consume appearance variables');
check(str_contains($css,'Example Local Font') && str_contains($css,'sans-serif'),'Registered fonts require a fallback');
check(str_contains($css,'text-shadow:none') && str_contains($css,'.codeblock'),'Message glow must respect semantic components');
check(!str_contains($css,'position:sticky') && !str_contains($css,'grid-template') && !str_contains($css,'!important'),'Compiler must not change layout or add priority hacks');
check(str_contains(af_aa_studio_compile(7,$decoded,true),'#aa-studio-preview .atf-post.af-aa-postbit-user-7'),'Preview must be locally scoped');
check(!str_contains($css,'user-8') && str_contains($css,'user-7'),'Account isolation');
check(af_aa_studio_canvas(7,['canvas'=>[]])===[],'Postbit effects hard-disabled');
$plaque=af_aa_postbit_plaque_settings(7,$decoded);
check($plaque['postbit_plaque_title']==='Новая подпись' && str_ends_with($plaque['postbit_plaque_media_image_url'],'.gif'),'Plaque content adapter');
check(!isset($plaque['sheet_url']),'Plaque cannot change sheet identity');
$post=['username'=>'Author','useravatar'=>'native','af_atf_primary_avatar'=>'old-primary','af_atf_secondary_avatar'=>'old-secondary'];
af_aa_postbit_apply_images($post,$decoded);
check($post['useravatar']==='native' && str_contains($post['af_atf_secondary_avatar'],'portrait.gif'),'Native avatar must remain intact');
$restore=$post;af_aa_postbit_apply_images($restore,[]);check($restore===$post,'Absent overrides must preserve provider payload');
$deleted=af_aa_personal_decode('{"components":{"signature":{"font_family":"ae_000000000000000000000000"}}}',7,false,false);
check($deleted['components']['signature']['font_family']==='inherit','Removed fonts must fall back safely');
$legacy=af_aa_personal_decode('{"avatar_media_id":42,"components":{"topbar":{"radius":8,"background_color":"#123456"},"signature":{"decorative_line":"dashed"}}}',7,false,false);
check(str_contains(af_aa_studio_compile(7,$legacy),'border-radius:'),'Legacy presets remain readable');
foreach (['{"components":{"plaque_title":{"label_text":"<script>"}}}','{"components":{"plaque_title":{"label_text":false}}}','{"components":{"plaque_icon":{"image_url":"javascript:alert(1)"}}}','{"components":{"plaque_icon":{"image_url":"https://images.example.org/payload.svg"}}}','{"components":{"sidebar":{"background_url":"https://unapproved.example/a.png"}}}','{"components":{"topbar":{"overlay_intensity":2}}}','{"components":{"signature":{"font_family":"evil; color:red"}}}','{"components":{"metadata":{"position":"fixed"}}}'] as $bad) reject($bad);
foreach (af_aa_postbit_surface()['components'] as $component=>$meta) foreach ($meta['editable_fields'] as $field) check(isset(af_aa_studio_fields()[$field]),'Missing field schema: '.$field);
check(!in_array('image_url',af_aa_studio_surface_registry()['profile']['components']['hero']['fields'],true),'Postbit controls must not leak into other adapters');
echo "Postbit settings passed: round-trip, legacy compatibility, original GIFs, registered fonts, safe text/URLs, scoped CSS, crop variables and disabled Canvas.\n";

$profileCss=af_aa_signature_compile_css(7,$decoded);
check(str_contains($profileCss,'.af-aa-signature-user-7 .atf-post__signature'),'Profile signature scope missing');
check(!str_contains($profileCss,'user-8') && !str_contains($profileCss,'.atf-post__topbar'),'Signature adapter leaks other owners/components');
check(str_contains($profileCss,'--af-sig-overlay:') && str_contains($profileCss,'font-family:'),'Profile loses signature masks/fonts');
echo "Signature profile compiler passed: owner scope, shared masks/fonts and no postbit layout selectors.\n";
$mybb->settings['bburl']='https://warprift.ru';
$default=af_aa_signature_defaults();$defaultUrl=$default['background_url'];
foreach([af_aa_personal_compile_css(7,[]),af_aa_studio_compile(7,[],true),af_aa_signature_compile_css(7,[])] as $output) {
    check(str_contains($output,$defaultUrl) && str_contains($output,'background-size:cover') && str_contains($output,'background-position:center'),'Default background differs between composition contexts');
}
foreach([['background_url'=>''],['background_media_id'=>null],['background_url'=>'https://images.example.org/custom.gif']] as $values) {
    $preset=['components'=>['signature'=>$values]];$copy=$preset;
    $output=af_aa_personal_compile_css(7,$preset);
    check(!str_contains($output,$defaultUrl),'Default replaced an explicit background/removal');
    check($preset===$copy,'Compiler mutated the stored preset');
}
check(af_aa_postbit_effective_settings(['components'=>['signature'=>['overlay_mode'=>'none','text_color'=>'#123456']]])['components']['signature']['overlay_mode']==='none','Default overwrote a custom mask');
echo "Appearance background passed: default across roots, explicit removal/custom image preserved, no stored-preset mutation.\n";

foreach(['topbar','sidebar'] as $zone) {
 foreach([['overlay_intensity'=>.7],['overlay_color'=>'#123456'],['overlay_start'=>0,'overlay_end'=>1]] as $override) {
  $out=af_aa_personal_compile_css(7,['components'=>[$zone=>$override]],true);
  check(str_contains($out,'--aa-'.$zone.'-mask:linear-gradient('),'Sparse overlay not compiled for '.$zone);
 }
 $off=af_aa_personal_compile_css(7,['components'=>[$zone=>['overlay_mode'=>'none','overlay_intensity'=>1]]]);
 check(str_contains($off,'--aa-'.$zone.'-mask:none;'),'Explicit no-overlay ignored');
 $legacy=af_aa_personal_compile_css(7,['components'=>[$zone=>['darkening'=>.2,'overlay_color'=>'#000000']]]);
 check(str_contains($legacy,'#000000 20%'),'Legacy darkening precedence changed');
}
echo "Overlay settings passed: sparse edits, explicit none, legacy precedence and both zone scopes.\n";

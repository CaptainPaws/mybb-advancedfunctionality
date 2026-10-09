<?php
ob_start(); require __DIR__ . '/element_theme_editor_regression.php'; ob_end_clean();
function af_frontend_asset_allowed($addon, $resource = null, $context = null, $facts = []): bool { return !empty($facts['has_element_surface']); }
$mybb->request_method = 'get';
$old = af_elementtheme_get_metadata('fire');
editor_check(!af_elementtheme_get_effects('fire')['enabled'] && af_elementtheme_get_effects('fire')['preset'] === 'embers', 'Effects must start opt-in with a presentation suggestion');
editor_check(af_elementtheme_get_effects('water')['preset'] === 'mist' && af_elementtheme_get_effects('shadow')['preset'] === 'stardust', 'Initial reusable preset suggestions');
$editor = editor_render(['action' => 'edit', 'element_key' => 'fire', 'surface' => 'effects']);
editor_check(str_contains($editor, 'data-af-et-effects-editor') && str_contains($editor, 'Предпросмотр') && substr_count($editor, 'class="af-et-tab') === 7, 'Dedicated effects tab/editor missing');
$plain = '<head></head><main class="atf-profile" data-element="fire" data-element-surface="profile"></main>';
$GLOBALS['af_elementtheme_rendered_surfaces'] = []; $GLOBALS['af_elementtheme_surface_keys'] = [];
af_elementtheme_mark_surface('profile', 'fire'); af_advancedelementtheme_pre_output($plain);
editor_check(!str_contains($plain, 'element-effects.'), 'Disabled effects loaded assets');
$mybb->request_method = 'post';
try {
    editor_render(['action' => 'edit', 'element_key' => 'fire', 'surface' => 'effects', 'my_post_key' => 'test-key', 'effect_enabled' => '1', 'effect_preset' => 'embers', 'effect_intensity' => '65', 'effect_speed' => '40', 'effect_density' => '100', 'effect_opacity' => '45', 'effect_color' => '', 'effect_surfaces' => ['profile', 'sheet', 'application', 'postbit']]);
    throw new RuntimeException('Effects save did not redirect');
} catch (ElementEditorRedirect $e) { editor_check(str_contains($e->getMessage(), 'surface=effects'), 'Effects save returned to wrong tab'); }
$saved = af_elementtheme_get_metadata('fire');
editor_check($saved['variables'] === $old['variables'] && $saved['custom_css'] === $old['custom_css'] && $saved['effects']['enabled'], 'Effects save lost existing palette/custom CSS');
af_elementtheme_save_style('fire', ['variables' => $saved['variables'], 'custom_css' => $saved['custom_css']]);
editor_check(af_elementtheme_get_metadata('fire')['effects'] === $saved['effects'], 'Palette save lost animation metadata');
af_elementtheme_save_style('fire', ['variables' => ['--af-element-accent' => '#37c4ff'], 'custom_css' => ''], 'profile');
$queries = $db->kbQueries;
for ($i = 0; $i < 100; ++$i) { af_elementtheme_get_effects('fire'); af_elementtheme_overrides(); }
editor_check($queries === $db->kbQueries, 'Effects introduced repeated KB queries');
$effects = af_elementtheme_overrides()['effects'];
editor_check(substr_count(af_elementtheme_effect_texture(100), 'radial-gradient') === 12 && substr_count(af_elementtheme_effect_texture(100, true), 'radial-gradient') === 3 && af_elementtheme_effect_texture(0) === 'none', 'Particle limits/density');
editor_check(str_contains($effects['css'], 'var(--af-element-accent') && str_contains($effects['css'], 'af-effect-embers'), 'Effects do not follow surface palette/preset');
foreach (array_keys(af_elementtheme_effect_presets()) as $preset) {
    $config = array_replace($saved['effects'], ['preset' => $preset]);
    editor_check(str_contains(af_elementtheme_compile_effects(['fire' => ['effects' => $config]])['css'], 'af-effect-' . $preset), 'Preset did not compile');
}
foreach ([['preset' => 'unknown'], ['density' => 101], ['color' => '#fff;display:none'], ['color' => 'url(x)'], ['surfaces' => ['forum']], ['surfaces' => [[]]], ['enabled' => 'yes']] as $invalid) {
    try { af_elementtheme_normalize_effects(array_replace($saved['effects'], $invalid)); throw new RuntimeException('Invalid effect accepted'); }
    catch (InvalidArgumentException $e) {}
}
$GLOBALS['af_elementtheme_surface_keys'] = [];
editor_check(!af_elementtheme_effects_needed('<div data-element="shadow" data-element-surface="profile"></div>', $effects['settings']), 'Unrelated element requested effects');
editor_check(af_elementtheme_effects_needed('<div data-element="fire" data-element-surface="sheet"></div><div data-element="fire" data-element-surface="profile"></div>', ['fire' => array_replace($saved['effects'], ['surfaces' => ['profile']])]), 'Repeated key/second surface missed');
// A showthread sheet caller preloads effects even when postbit animation is disabled.
af_elementtheme_mark_surface('postbit', 'fire'); $GLOBALS['af_charactersheets_has_frontend_component'] = true;
editor_check(af_elementtheme_effects_needed('<head></head>', ['fire' => array_replace($saved['effects'], ['surfaces' => ['sheet']])]), 'Postbit sheet-modal caller missed effects');
unset($GLOBALS['af_charactersheets_has_frontend_component']); $GLOBALS['af_elementtheme_surface_keys'] = [];
// Disabling retains presentation metadata, stops delivery, and supports explicit color overrides.
$disabled = $saved; $disabled['effects']['enabled'] = false;
af_elementtheme_save_style('fire', $disabled);
editor_check(af_elementtheme_overrides()['effects']['settings'] === [], 'Disabled effects remained in runtime settings');
$color = $saved; $color['effects']['color'] = 'rgba(20,40,60,.5)';
af_elementtheme_save_style('fire', $color);
editor_check(str_contains(af_elementtheme_overrides()['effects']['css'], '--af-effect-color:rgba(20,40,60,.5)'), 'Individual particle color did not compile');
af_elementtheme_save_style('fire', $saved);
function effect_root(string $template, string $id, string $body): string {
    preg_match('/<(?:div|main|article)\b[^>]*>/s', file_get_contents(AF_ADDONS . $template), $match);
    $tag = preg_replace('/\{\$[^}]+\}/', '', $match[0]);
    $tag = preg_replace('/\bid="[^"]*"/', '', $tag);
    $tag = preg_replace('/data-element="[^"]*"/', 'data-element="fire"', $tag);
    preg_match('/^<(\w+)/', $tag, $name);
    return substr($tag, 0, -1) . ' id="' . $id . '">' . $body . '</' . $name[1] . '>';
}
$node = '<span hidden data-af-element-effect aria-hidden="true"></span>';
$profile = effect_root('adaptivethemeframework/templates/member_profile.html', 'profile', '<section class="atf-profile-hero" data-af-element-effect-host>' . $node . '<div class="atf-profile-hero__identity"><h1>Fire · Character Chronicle</h1><p>Огонь — история персонажа</p><button id="profile-button" type="button">Открыть анкету</button></div></section><section class="atf-profile__workspace"><nav>Profile tabs</nav><article class="atf-profile__panel"><h2>Abilities</h2><p>Character details and infobox</p></article></section>');
$sheet = effect_root('charactersheets/templates/charactersheet_inner_arpg.html', 'sheet', '<header class="af-cs-arpg-top" data-af-element-effect-host>' . $node . '<div class="af-cs-arpg-top__right"><h2>CharacterSheet</h2><button type="button">Навыки</button></div></header><section class="af-cs-section"><h2>Talents and equipment</h2><p>Sheet content</p></section>');
$post = effect_root('adaptivethemeframework/templates/postbit_classic.html', 'postbit', '<header class="post_head atf-post__topbar" data-af-element-effect-host>' . $node . '<div class="atf-post__name">Fire · Postbit</div></header>');
$app = '<div class="af-atf-display" id="application" data-element="fire" data-element-surface="application"><article class="af-atf-wiki"><header class="af-atf-wiki__header" data-af-element-effect-host>' . $node . '<h2>Анкета персонажа</h2></header><section><h2>Abilities and description</h2><p>Application content</p></section></article></div>';
$html = '<html><head></head><body class="atf-active atf-profile-page">' . $profile . $sheet . $app . $post . '<div class="atf-profile" data-element="shadow" data-element-surface="profile"><section class="atf-profile-hero" id="disabled-host">Shadow · disabled</section></div><div class="atf-profile" data-element="" data-element-surface="profile"><section class="atf-profile-hero" id="neutral-host">Neutral</section></div></body></html>';
af_elementtheme_mark_surface('profile', 'fire'); af_advancedelementtheme_pre_output($html);
$once = $html; af_advancedelementtheme_pre_output($html); editor_check($html === $once && substr_count($html, 'data-af-element-effects-config>') === 1, 'Effects owner delivery duplicated assets');
$fragment = $sheet; af_advancedelementtheme_pre_output($fragment); editor_check(!str_contains($fragment, '<script'), 'AJAX fragment injected effect assets');
$mybb->request_method = 'get'; $editor = editor_render(['action' => 'edit', 'element_key' => 'fire', 'surface' => 'effects']);
if (in_array('--browser-fixture', $argv ?? [], true)) echo json_encode(['html' => $html, 'sheet' => str_replace('id="sheet"', 'id="modal-sheet"', $sheet), 'editor' => $editor], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
else echo "ElementTheme effects: opt-in, ACP save, metadata preservation, presets, limits, validation, cache and conditional delivery passed.\n";

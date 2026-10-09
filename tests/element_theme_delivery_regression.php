<?php
// Reuse the existing small DB/cache and approved-character fixtures.
ob_start(); require __DIR__ . '/element_theme_regression.php'; ob_end_clean();
$kbKeys = ['fire', 'shadow', 'water']; unset($GLOBALS['af_elementtheme_elements'], $GLOBALS['af_elementtheme_rendered_surfaces']);
$oldCache = ['format' => 2, 'styles' => [], 'surfaces' => [], 'css' => 'STALE COMPILER'];
$cache->data['af_elementtheme'] = $oldCache; unset($GLOBALS['af_elementtheme_overrides']);
element_check(!str_contains(af_elementtheme_overrides()['css'], 'STALE COMPILER'), 'Compiler upgrade reused stale cache');
$global = ['variables' => ['--af-element-accent' => '#12ab34', '--af-element-main' => '#234567'],
    'custom_css' => '.atf-profile-hero__name { letter-spacing: 3px; color: rgb(90, 80, 70); } :scope { outline: 2px solid rgb(2, 3, 4); }'];
$profile = ['variables' => ['--af-element-main' => '#b012cd'],
    'custom_css' => '@media (min-width: 1px) { .atf-profile-hero__name {color: rgb(21, 32, 43)} } :scope { outline: 5px solid rgb(5, 6, 7); }'];
af_elementtheme_save_style('fire', $global);
af_elementtheme_save_style('fire', $profile, 'profile');
// Simulate a subsequent frontend request after ACP invalidation, including a warm MyBB cache.
unset($GLOBALS['af_elementtheme_overrides']);
$compiled = af_elementtheme_overrides()['css']; $queries = $db->queries;
unset($GLOBALS['af_elementtheme_overrides']);
element_check(af_elementtheme_overrides()['css'] === $compiled && $db->queries === $queries, 'Warm frontend cache queried metadata');
element_check(af_elementtheme_get_metadata('fire') === $global && af_elementtheme_get_metadata('fire', 'profile') === $profile, 'Saved source changed during compilation');
$complex = '.x:not([title="a;b"]) {content:"x;y} !important"; background: url("data:image/svg+xml;a;b"); color:red /* note */; border:0 !important; &:hover { opacity:.5 } }';
$priority = af_elementtheme_prioritize_custom_css($complex);
element_check(str_contains($priority, 'content:"x;y} !important" !important;') && str_contains($priority, 'url("data:image/svg+xml;a;b") !important;') && str_contains($priority, 'border:0 !important;') && str_contains($priority, 'opacity:.5 !important'), 'Declaration lexer damaged strings/functions/nesting/priority');
// Explicit renderer fact works even for an unknown future root class.
af_elementtheme_mark_surface('profile');
$page = '<html><head></head><body><section class="new-component" data-element="fire" data-element-surface="profile"></section></body></html>';
af_advancedelementtheme_pre_output($page);
element_check(str_contains($page, 'data-af-element-theme-overrides'), 'Explicit renderer fact did not deliver overrides');
$once = $page; af_advancedelementtheme_pre_output($page); element_check($page === $once, 'Repeated delivery duplicated assets');
$partial = '<head><style data-af-element-theme-overrides>already delivered</style></head>';
af_advancedelementtheme_pre_output($partial);
element_check(substr_count($partial, 'data-af-element-theme-overrides') === 1 && str_contains($partial, '<link'), 'Override marker prevented base palette delivery');
$permission = false; $denied = '<head></head>'; af_advancedelementtheme_pre_output($denied); element_check($denied === '<head></head>', 'Explicit fact bypassed frontend permissions'); $permission = true;
$fragment = '<section data-element="fire"></section>'; af_advancedelementtheme_pre_output($fragment); element_check(!str_contains($fragment, '<style'), 'AJAX fragment received page assets');
// Use actual shipped root markup and real component CSS in the browser fixture.
function delivery_root(string $template, string $id, string $key, string $child): string {
    $source = file_get_contents(AF_ADDONS . $template);
    preg_match('/<(?:main|article|div)\b[^>]*>/s', $source, $match);
    $tag = preg_replace('/\{\$[^}]+\}/', '', $match[0]);
    $tag = preg_replace('/data-element="[^"]*"/', 'data-element="' . $key . '"', $tag);
    $tag = preg_replace('/\bid="[^"]*"/', '', $tag);
    preg_match('/^<(\w+)/', $tag, $name);
    return substr($tag, 0, -1) . ' id="' . $id . '">' . $child . '</' . $name[1] . '>';
}
$sheet = delivery_root('charactersheets/templates/charactersheet_inner_arpg.html', 'sheet', 'fire', '<div class="af-cs-section"><h2 id="sheet-accent">Sheet</h2></div>');
$post = delivery_root('adaptivethemeframework/templates/postbit_classic.html', 'postbit', 'fire', '<div class="atf-post__name"><a id="postbit-accent">Postbit</a></div>');
$neutral = delivery_root('adaptivethemeframework/templates/postbit_classic.html', 'neutral', '', '<div class="atf-post__name"><a id="neutral-accent">Neutral</a></div>');
$profileRoot = delivery_root('adaptivethemeframework/templates/member_profile.html', 'profile', 'fire', '<div class="atf-profile-hero__name" id="profile-custom">Hero</div><a class="atf-profile-nav__item is-active" id="profile-accent">Profile</a>');
$app = '<div class="af-atf-display" id="application" data-element="fire" data-element-surface="application"><div class="af-atf-profile-field"><span class="af-atf-label" id="application-accent">Application</span></div></div>';
$html = '<html><head></head><body class="atf-active atf-profile-page">' . $app . $sheet . $post . $profileRoot . $neutral
    . '<div data-element="shadow" id="shadow" data-element-surface="postbit"></div><div data-element="water" class="atf-profile" data-element-surface="profile" id="water"><div class="atf-profile-hero__name" id="water-custom">Water</div></div></body></html>';
af_advancedelementtheme_pre_output($html);
if (in_array('--browser-fixture', $argv ?? [], true)) echo json_encode(['html' => $html, 'sheet' => str_replace(['id="sheet"', 'id="sheet-accent"'], ['id="modal-sheet"', 'id="modal-accent"'], $sheet)], JSON_UNESCAPED_SLASHES);
else echo "ElementTheme delivery: save/load/cache, explicit surfaces, deduplication, permissions, CSS priority lexer: OK\n";

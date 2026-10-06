<?php
/** Exercise production metadata discovery and pre-output, with only MyBB/DB boundary mocked. */
ob_start();
require __DIR__.'/fixtures/advancededitor_showthread.php';
$page = ob_get_clean();
function lazy_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
preg_match_all('~<script\b[^>]*src="([^"]+)"~i', $page, $scripts);
preg_match_all('~<link\b[^>]*href="([^"]+)"~i', $page, $styles);
$initial = implode("\n", array_merge($scripts[1], $styles[1]));
foreach (['jquery.sceditor', 'advancededitor_wysiwyg_bbcodes.js', '/tables.js', '/tables.css', '/jscolor.js', '/jscolorpiker.js', '/drafts.js', '/stikers.js', 'knowledgebase_insert.js'] as $asset) {
    lazy_assert(!str_contains($initial, $asset), "Eager editor asset: {$asset}");
}
lazy_assert(str_contains($initial, 'advancededitor_shell.js'), 'Source shell is missing');
foreach (['bold','italic','underline','color','af_tables','af_stikers','af_drafts','af_togglemode'] as $cmd) {
    lazy_assert(str_contains($page, 'data-af-command="'.$cmd.'"'), "Initial button missing: {$cmd}");
}
lazy_assert(str_contains($page, 'data-af-editor-shell="1"'), 'Server-rendered editor wrapper missing');
lazy_assert(str_contains($page, 'af-ccp-wrap'), 'Counter geometry must be reserved before paint');
lazy_assert(af_advancededitor_render_shells($page, '') === $page, 'Repeated response filtering duplicates shells');
$packs = af_advancededitor_discover_bbcode_packs($mybb->settings['bburl']);
$registry = af_advancededitor_shell_registry(af_advancededitor_get_available_buttons($mybb->settings['bburl']), [
    ['cmd'=>'af_custom_unicode', 'title'=>'Custom', 'opentag'=>'[тег]', 'closetag'=>'[/тег]'],
    ['cmd'=>'af_custom_dialog', 'title'=>'Custom dialog', 'handler'=>'custom_dialog', 'runtime'=>['activation'=>'click','js'=>['/dialog.js'],'css'=>[],'requires'=>['tables']]],
], $packs);
$buttons = array_column($registry['buttons'], null, 'cmd');
foreach (['bold','italic','underline','strike','quote','code','link','image','bulletlist','orderedlist','left','center','right','justify','spoiler','af_custom_unicode','af_accordion'] as $cmd) {
    lazy_assert(!empty($buttons[$cmd]['opentag']), "Simple tag metadata missing: {$cmd}");
    lazy_assert(empty($buttons[$cmd]['capability']), "Simple command raises a runtime: {$cmd}");
}
lazy_assert($buttons['af_custom_dialog']['capability'] === 'custom:af_custom_dialog', 'Custom runtime capability missing');
lazy_assert($registry['capabilities']['custom:af_custom_dialog']['requires'] === ['tables'], 'Custom dependencies lost');
foreach ($packs['packs'] as $id => $pack) {
    lazy_assert(isset($pack['runtime']['activation']), "Pack has no activation metadata: {$id}");
    foreach ($pack['buttons'] as $button) {
        lazy_assert(!empty($buttons[$button['cmd']]['title']), "Title requires JS: {$id}");
        if (!empty($buttons[$button['cmd']]['handler'])) lazy_assert(!empty($buttons[$button['cmd']]['capability']), "Handler has no capability: {$id}");
    }
    foreach (array_merge($pack['assets']['js'], $pack['assets']['css'], $pack['view_assets']['js'], $pack['view_assets']['css']) as $url) {
        $relative = substr(parse_url($url, PHP_URL_PATH), 1);
        lazy_assert(is_file(MYBB_ROOT.$relative), "Declared asset missing: {$url}");
    }
}
$helpToolbar = af_advancededitor_shell_toolbar(array_merge($registry['buttons'], [['cmd'=>'af_formathelp','title'=>'Help','label'=>'?']]), ['sections'=>[['type'=>'group','items'=>['bold']]]], ['enabled'=>true,'position'=>'left']);
lazy_assert(strpos($helpToolbar, 'data-af-command="af_formathelp"') < strpos($helpToolbar, 'data-af-command="bold"'), 'Configured help placement lost');
$inlineToolbar = af_advancededitor_shell_toolbar([['cmd'=>'af_icon','title'=>'Custom','icon'=>'<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>']], ['sections'=>[['type'=>'group','items'=>['af_icon']]]]);
lazy_assert(str_contains($inlineToolbar, 'data:image/svg+xml,') && str_contains($inlineToolbar, 'af-ae-shell-icon'), 'Custom SVG icon requires runtime');
$manifest = require MYBB_ROOT.'inc/plugins/advancedfunctionality/addons/advancededitor/manifest.php';
foreach ($manifest['theme_stylesheets'] as $entry) {
    if ($entry['file'] === 'assets/advancededitor_shell.css') continue;
    lazy_assert(!empty($entry['exclude_autodiscovery']) && !empty($entry['disable_theme_integration']), 'Feature CSS still enters global theme bundle');
}
$viewPage = '<html><head></head><body><blockquote class="af-aqr-spoiler"></blockquote><div data-af-tabs-root></div><div data-af-accordion="1"></div><div class="af-ev-embed"></div><div class="af-bb-table"></div></body></html>';
af_advancededitor_pre_output($viewPage);
foreach (['spoiler','tabs','accordion','embedvideos'] as $id) {
    lazy_assert(str_contains($viewPage, $id.'_view.js'), 'Published BBCode lost its view runtime: '.$id);
    lazy_assert(!preg_match('~src="[^"]*/'.$id.'\.js~', $viewPage), 'View loads editor package: '.$id);
}
lazy_assert(str_contains($viewPage, 'tables_view.css') && !str_contains($viewPage, 'tables.js'), 'Published tables must retain content-only CSS');
echo "AdvancedEditor feature-lazy metadata and initial response checks passed.\n";

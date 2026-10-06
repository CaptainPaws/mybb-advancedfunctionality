<?php
declare(strict_types=1);

function showthread_lazy_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$cs = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/charactersheets.php');
$csBootstrap = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/modules/bootstrap.php');
$csFrontend = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/modules/frontend.php');
$csMetadata = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/modules/metadata.php');
$csPostbit = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/modules/postbit.php');
$csTrigger = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/assets/charactersheets-trigger.js');
$csTriggerCss = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/assets/charactersheets-trigger.css');
$apui = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedprofileui/advancedprofileui.php');
$atf = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php');
$atfForm = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/assets/advancedthreadfields-form.js');
$atfView = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/assets/advancedthreadfields-view.js');
$cwf = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/characterworkflow/characterworkflow.php');

showthread_lazy_assert(
    is_string($cs) && is_string($csBootstrap) && is_string($csFrontend)
    && is_string($csMetadata) && is_string($csPostbit) && is_string($csTrigger)
    && is_string($csTriggerCss) && is_string($apui)
    && is_string($atf) && is_string($atfForm) && is_string($atfView) && is_string($cwf),
    'Unable to read showthread lazy-runtime sources'
);

// Initial showthread: lightweight modules only.
showthread_lazy_assert(
    str_contains($cs, "af_charactersheets_require_modules(['permissions', 'metadata', 'postbit', 'frontend']);"),
    'CharacterSheets trigger module plan is missing'
);
showthread_lazy_assert(
    !str_contains($cs, "af_charactersheets_require_modules(['permissions', 'experience', 'postbit', 'bootstrap']);"),
    'Legacy heavy showthread bootstrap is still present'
);
foreach (['sheets_crud', 'calculator', 'render', 'ajax', 'acp_skills'] as $module) {
    showthread_lazy_assert(
        !str_contains($csFrontend, $module),
        "Heavy CharacterSheets module leaked into frontend trigger runtime: {$module}"
    );
}
showthread_lazy_assert(
    !str_contains($csFrontend, 'bootstrap.php'),
    'Full CharacterSheets bootstrap leaked into trigger frontend'
);

// Trigger assets are lightweight and component-gated.
showthread_lazy_assert(
    str_contains($csFrontend, 'charactersheets-trigger.js')
    && str_contains($csFrontend, 'charactersheets-trigger.css')
    && str_contains($csFrontend, "['has_charactersheet_component' => $hasComponent]"),
    'CharacterSheets trigger assets are missing or not manifest-gated'
);
showthread_lazy_assert(
    str_contains($csFrontend, 'charactersheets.js')
    && str_contains($csFrontend, 'if (af_charactersheets_is_trigger_context())'),
    'Full CharacterSheets runtime is not explicitly removed from trigger pages'
);

// iframe is created empty and receives src only after delegated click.
$clickPos = strpos($csTrigger, "document.addEventListener('click'");
$buildPos = strpos($csTrigger, 'buildModal(url)', $clickPos === false ? 0 : $clickPos);
$srcPos = strpos($csTrigger, "frame.setAttribute('src', url)", $buildPos === false ? 0 : $buildPos);
showthread_lazy_assert(
    $clickPos !== false && $buildPos !== false && $srcPos !== false && $clickPos < $buildPos && $buildPos < $srcPos,
    'CharacterSheet iframe src is not assigned strictly after user click'
);
showthread_lazy_assert(
    str_contains($csTrigger, '<iframe class="af-cs-modal__frame" data-afcs-frame="1" title="Лист персонажа"></iframe>'),
    'Trigger runtime creates an eager iframe src'
);
foreach ([
    "closest('[data-afcs-open=\"1\"], [data-afcs-sheet]')",
    "frame.removeAttribute('src')",
    "event.key === 'Escape'",
    "modal.remove()",
    "data-afcs-close=\"1\"",
] as $needle) {
    showthread_lazy_assert(str_contains($csTrigger, $needle), 'CharacterSheets trigger lost modal lifecycle: ' . $needle);
}

// Current-page batch preload.
showthread_lazy_assert(
    str_contains($cs, "add_hook('postbit', 'af_charactersheets_postbit_preload_current_page', 1)")
    && str_contains($cs, '$pagePids')
    && str_contains($cs, 'pid IN (')
    && str_contains($cs, 'af_cs_showthread_page_uids'),
    'CharacterSheets current-page author preload is missing'
);
showthread_lazy_assert(
    !str_contains($cs, "function af_charactersheets_showthread_start(): void\n{\n    global $db, $tid;"),
    'showthread_start still scans all thread authors'
);

// Read-only metadata replaces CRUD reads.
showthread_lazy_assert(
    str_contains($csMetadata, 'function af_charactersheets_get_accept_row(int $tid): array')
    && str_contains($csMetadata, 'function af_charactersheets_get_sheet_by_tid(int $tid): array')
    && !str_contains($csMetadata, 'insert_query(')
    && !str_contains($csMetadata, 'update_query('),
    'CharacterSheets lightweight metadata layer is missing or mutating'
);

// CharacterWorkflow is batched once for the page, with request-level cache.
showthread_lazy_assert(
    str_contains($cwf, 'function af_characterworkflow_preload_active_applications(array $uids): void')
    && str_contains($cwf, 'WHERE r.uid IN ({$in})'),
    'CharacterWorkflow active application resolver is still per-author SQL'
);
showthread_lazy_assert(
    str_contains($cwf, "if (!array_key_exists($uid, $cache)) {\n        af_characterworkflow_preload_active_applications([$uid]);"),
    'Single-item fallback no longer shares the batch request cache'
);

// CharacterSheets resolves active application once; APUI reuses it.
showthread_lazy_assert(
    str_contains($csPostbit, 'af_charactersheets_get_application_payload_by_uid($uid, $activeApplication, true)')
    && str_contains($csPostbit, 'af_charactersheets_load_lang();')
    && !str_contains($csPostbit, 'af_charactersheets_lang();'),
    'CharacterSheets postbit repeats resolver work or depends on render.php'
);
$apuiPayloadStart = strpos($apui, 'function af_apui_get_charactersheet_postbit_payload');
$apuiPayloadEnd = strpos($apui, 'function af_apui_extract_query_int_from_url', $apuiPayloadStart);
$apuiPayloadSource = substr($apui, $apuiPayloadStart, $apuiPayloadEnd - $apuiPayloadStart);
showthread_lazy_assert(
    !str_contains($apuiPayloadSource, 'af_characterworkflow_resolve_active_application'),
    'AdvancedProfileUI still resolves active application separately'
);

// Active relation owns slug; acceptance-table SQL is not repeated.
showthread_lazy_assert(
    str_contains($csPostbit, 'relationSlug')
    && str_contains($csPostbit, 'af_charactersheets_get_sheet_slug_by_uid($uid)'),
    'Sheet slug resolution is not tied to the active application'
);
$preloadStart = strpos($csPostbit, 'function af_charactersheets_preload_postbit_metadata');
$preloadEnd = strpos($csPostbit, 'function af_cs_get_postbit_sheet_payload', $preloadStart);
$preloadSource = substr($csPostbit, $preloadStart, $preloadEnd - $preloadStart);
showthread_lazy_assert(
    !str_contains($preloadSource, 'simple_select(AF_CS_TABLE'),
    'Postbit slug preload still repeats acceptance-table SQL'
);

// ATF moderation button must not require CharacterSheets render.php.
$atfModerationStart = strpos($atf, 'function af_atf_render_character_kb_moderation_button');
$atfModerationSource = substr($atf, $atfModerationStart, 900);
showthread_lazy_assert(
    !str_contains($atfModerationSource, "function_exists('af_charactersheets_resolve_character_kb_entry')"),
    'ATF moderation button still depends on CharacterSheets render.php'
);

// ATF: form code is isolated and showthread intentionally has no JS runtime.
showthread_lazy_assert(
    str_contains($atf, "define('AF_ATF_ASSET_FORM_JS'")
    && str_contains($atf, "define('AF_ATF_ASSET_VIEW_JS'"),
    'ATF form/view assets are not split'
);
showthread_lazy_assert(
    str_contains($atf, "if (in_array(\$script, ['newthread.php', 'editpost.php'], true))")
    && str_contains($atf, "\$jsRel = AF_ATF_ASSET_FORM_JS;"),
    'ATF form runtime is not restricted to form routes'
);
showthread_lazy_assert(
    str_contains($atf, '// showthread is fully server-rendered. Interactive KB chips belong to')
    && str_contains($atf, "\$js = \$jsRel !== '' ?"),
    'ATF showthread no-JS contract is missing'
);
foreach (['initPointBuyAll()', 'initOriginVariantDependency()', 'initCharacterAbilities()', 'initCharacterStats()'] as $formOnly) {
    showthread_lazy_assert(str_contains($atfForm, $formOnly), 'ATF form runtime lost form feature: ' . $formOnly);
}
showthread_lazy_assert(
    !str_contains($atfForm, 'initKbChips()')
    && !str_contains($atfForm, 'af-atf-kb-modal-backdrop'),
    'ATF still duplicates Knowledge Base chip modal/runtime'
);
showthread_lazy_assert(
    str_contains($atf, 'class="af_kb_chip af-kb-chip"'),
    'ATF rendered KB fields do not expose the canonical KB chip class'
);

// One display-block cache and one KB entry batch per request.
showthread_lazy_assert(
    str_contains($atf, "\$cacheKey = \$tid . ':' . \$fid;")
    && str_contains($atf, "\$GLOBALS['af_atf_display_block_cache']")
    && str_contains($atf, 'array_key_exists($cacheKey, $GLOBALS[\'af_atf_display_block_cache\'])'),
    'ATF display block request cache is missing'
);
showthread_lazy_assert(
    !str_contains($atf, 'static $blockCache = [];'),
    'ATF postbit still owns a second display cache'
);
showthread_lazy_assert(
    str_contains($atf, 'function af_atf_kb_preload_entries(array $pairs): void')
    && str_contains($atf, "implode(' OR ', \$clauses)")
    && str_contains($atf, 'af_atf_kb_preload_entries($kbPairs);'),
    'ATF KB-backed display fields are not batch-preloaded'
);

echo "CharacterSheets/ATF showthread lazy-runtime regression checks passed.\n";

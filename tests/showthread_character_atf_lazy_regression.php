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
$csTrigger = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/assets/charactersheets-trigger.js');
$atf = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php');
$atfForm = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/assets/advancedthreadfields-form.js');
$atfView = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedthreadfields/assets/advancedthreadfields-view.js');
$cwf = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/characterworkflow/characterworkflow.php');

showthread_lazy_assert(
    is_string($cs) && is_string($csBootstrap) && is_string($csTrigger)
    && is_string($atf) && is_string($atfForm) && is_string($atfView) && is_string($cwf),
    'Unable to read showthread lazy-runtime sources'
);

// CharacterSheets: only lightweight modules are unconditional.
showthread_lazy_assert(
    str_contains($cs, "af_charactersheets_require_modules(['permissions', 'experience', 'postbit', 'bootstrap']);"),
    'CharacterSheets minimal bootstrap contract is missing'
);
foreach (['sheets_crud', 'calculator', 'render', 'ajax', 'acp_skills'] as $module) {
    showthread_lazy_assert(
        !str_contains(
            $cs,
            "require_once AF_CS_MODULES . '{$module}.php'"
        ),
        "Heavy CharacterSheets module remains unconditional: {$module}"
    );
}
showthread_lazy_assert(
    str_contains($cs, "if (\$afCsScript === 'charactersheets.php')")
    && str_contains($cs, "af_charactersheets_require_modules(['sheets_crud', 'calculator', 'render', 'ajax']);"),
    'Full CharacterSheets route modules are not route-gated'
);

// showthread/member use trigger-only frontend assets.
showthread_lazy_assert(
    str_contains($csBootstrap, "return in_array(\$script, ['showthread.php', 'member.php'], true);")
    && str_contains($csBootstrap, "charactersheets-trigger.js")
    && str_contains($csBootstrap, "charactersheets-trigger.css"),
    'CharacterSheets trigger context/assets are missing'
);
showthread_lazy_assert(
    str_contains($csBootstrap, "charactersheets.js")
    && str_contains($csBootstrap, "if (af_charactersheets_is_trigger_context())"),
    'Full CharacterSheets runtime is not explicitly excluded from trigger pages'
);

// Trigger creates and populates iframe only after a delegated click.
$clickPos = strpos($csTrigger, "document.addEventListener('click'");
$buildPos = strpos($csTrigger, 'buildModal(url)', $clickPos === false ? 0 : $clickPos);
$srcPos = strpos($csTrigger, "frame.setAttribute('src', url)", $buildPos === false ? 0 : $buildPos);
showthread_lazy_assert(
    $clickPos !== false && $buildPos !== false && $srcPos !== false && $clickPos < $buildPos && $buildPos < $srcPos,
    'CharacterSheet iframe src is not assigned strictly after user click'
);
foreach ([
    "closest('[data-afcs-open=\"1\"], [data-afcs-sheet]')",
    "frame.removeAttribute('src')",
    "event.key === 'Escape'",
    "modal.remove()",
] as $needle) {
    showthread_lazy_assert(str_contains($csTrigger, $needle), 'CharacterSheets trigger lost modal lifecycle: ' . $needle);
}

// Active application metadata is one batch query for all page authors.
showthread_lazy_assert(
    str_contains($cs, 'af_characterworkflow_preload_active_applications($uids);'),
    'CharacterSheets does not batch-preload active applications'
);
showthread_lazy_assert(
    str_contains($cwf, 'function af_characterworkflow_preload_active_applications(array $uids): void')
    && str_contains($cwf, 'WHERE r.uid IN ({$in})'),
    'CharacterWorkflow active application resolver is still per-author SQL'
);
showthread_lazy_assert(
    str_contains($cwf, "if (!array_key_exists(\$uid, \$cache)) {\n        af_characterworkflow_preload_active_applications([\$uid]);"),
    'Single-item fallback no longer shares the batch request cache'
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

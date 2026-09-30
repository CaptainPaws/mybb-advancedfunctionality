<?php

$root = dirname(__DIR__);

function cs_content_only_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$templateDir = $root . '/inc/plugins/advancedfunctionality/addons/charactersheets/templates/';
$contentOnlyTemplates = [
    'charactersheet_fullpage.html',
    'charactersheet_modal.html',
    'af_cs_modal_fullpage.html',
    'af_cs_page_modal.html',
];

foreach ($contentOnlyTemplates as $templateName) {
    $template = file_get_contents($templateDir . $templateName);
    cs_content_only_assert(is_string($template), 'Unable to read ' . $templateName);
    cs_content_only_assert(
        strpos($template, 'data-af-layout="content-only"') !== false,
        $templateName . ' must opt out of global navigation controls'
    );
    cs_content_only_assert(
        strpos($template, '{$header}') === false && strpos($template, '{$footer}') === false,
        $templateName . ' must not physically render forum chrome'
    );
}

$catalog = file_get_contents($templateDir . 'charactersheets_catalog.html');
cs_content_only_assert(
    is_string($catalog)
        && strpos($catalog, '{$header}') !== false
        && strpos($catalog, '{$footer}') !== false
        && strpos($catalog, 'data-af-layout="content-only"') === false,
    'The standalone CharacterSheets catalog must retain its normal layout'
);

$menu = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedmenu/advancedmenu.php');
$scrollButtons = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedjsbandle/assets/scroll-buttons.js');
cs_content_only_assert(
    is_string($menu) && strpos($menu, 'data-af-layout') !== false && strpos($menu, 'content-only') !== false,
    'AdvancedMenu must honor the content-only layout contract'
);
cs_content_only_assert(
    is_string($scrollButtons)
        && strpos($scrollButtons, "getAttribute('data-af-layout') === 'content-only'") !== false,
    'Global up/down controls must not be created in content-only documents'
);

$modalRuntime = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/charactersheets/assets/charactersheets.js');
cs_content_only_assert(
    is_string($modalRuntime)
        && strpos($modalRuntime, "event.target.closest('[data-afcs-close]')") !== false
        && strpos($modalRuntime, 'embed=1') !== false,
    'Character Sheet modal close controls and embed routing must remain intact'
);

fwrite(STDOUT, "CharacterSheets content-only layout regression checks passed.\n");

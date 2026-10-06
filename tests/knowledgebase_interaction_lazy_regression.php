<?php
declare(strict_types=1);

function kb_lazy_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$addon = $root . '/inc/plugins/advancedfunctionality/addons/knowledgebase';
$php = file_get_contents($addon . '/knowledgebase.php');
$chips = file_get_contents($addon . '/assets/knowledgebase_chips.js');
$bootstrap = file_get_contents($addon . '/assets/knowledgebase_chips_bootstrap.js');
$insert = file_get_contents($addon . '/assets/knowledgebase_insert.js');
$editorShell = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancededitor/assets/advancededitor_shell.js');

kb_lazy_assert(is_string($php) && is_string($chips) && is_string($bootstrap) && is_string($insert) && is_string($editorShell), 'Unable to read lazy KB sources');

kb_lazy_assert(
    str_contains($php, "knowledgebase_chips_bootstrap.js?v="),
    'Embedded KB chips do not use the tiny bootstrap runtime'
);
kb_lazy_assert(
    str_contains($php, "elseif (\$isKbViewPage)") && str_contains($php, "knowledgebase_chips.js?v="),
    'Real KB view pages lost their full view runtime'
);
kb_lazy_assert(
    str_contains($php, "'activation' => 'editor-focus'"),
    'KB insert integration is not registered for editor-focus lazy activation'
);
kb_lazy_assert(
    str_contains($editorShell, "registry[id].activation !== 'editor-focus'"),
    'AdvancedEditor does not activate editor-focus dependencies'
);
kb_lazy_assert(
    str_contains($editorShell, "ta.addEventListener('focus'"),
    'AdvancedEditor does not wait for a real editor focus'
);

kb_lazy_assert(
    str_contains(
        $php,
        "if (\$isKbEditorPage) {\n"
        . "                    // Heavy editor runtime must stay only on KB edit/create paths.\n"
        . "                    \$jsTag  .= '<script src=\"'.\$assetsBase.'/knowledgebase.js?v='"
    ),
    'knowledgebase.js is no longer guarded by KB editor page context'
);

kb_lazy_assert(
    !str_contains($bootstrap, 'fetch(')
    && !str_contains($bootstrap, 'af-kb-modal-backdrop')
    && !str_contains($bootstrap, 'createElement(\'div\')'),
    'Bootstrap contains entry fetch/modal/heavy KB logic'
);
foreach (["'pointerover'", "'focusin'", "'click'"] as $eventName) {
    kb_lazy_assert(str_contains($bootstrap, $eventName), "Bootstrap lost first-interaction listener {$eventName}");
}
kb_lazy_assert(
    str_contains($bootstrap, 'knowledgebase_chips.js')
    && str_contains($bootstrap, 'window.afKbHandleChipInteraction(context)'),
    'Bootstrap does not load/replay into the main chip runtime'
);

$hintPos = strpos($chips, "var techHint = chip.getAttribute('data-tech-hint')");
$fetchPos = strpos($chips, 'fetchEntry(type, key)', $hintPos === false ? 0 : $hintPos);
kb_lazy_assert(
    $hintPos !== false && $fetchPos !== false && $hintPos < $fetchPos,
    'data-tech-hint no longer short-circuits tooltip entry fetch'
);
kb_lazy_assert(
    str_contains($chips, 'window.afKbHandleChipInteraction = handleChipInteraction'),
    'Main chip runtime cannot receive the bootstrap replay'
);
kb_lazy_assert(
    str_contains($chips, "document.readyState === 'loading'"),
    'Dynamically loaded chip runtime cannot boot after DOMContentLoaded'
);

kb_lazy_assert(
    !str_contains($insert, "document.addEventListener('DOMContentLoaded', bootInsert); else bootInsert();")
        || str_contains($insert, 'window.afAdvancedEditorShell'),
    'KB insert runtime lost AdvancedEditor ownership guard'
);

echo "Knowledge Base interaction-lazy regression checks passed.\n";

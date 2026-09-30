<?php
declare(strict_types=1);

function kb_editor_height_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$source = file_get_contents(
    __DIR__ . '/../inc/plugins/advancedfunctionality/addons/knowledgebase/assets/knowledgebase.js'
);
kb_editor_height_assert(is_string($source), 'Unable to inspect knowledgebase.js');

kb_editor_height_assert(
    strpos($source, 'function getKbEditorHeight()') !== false,
    'KB-specific responsive editor height resolver is missing'
);
kb_editor_height_assert(
    strpos($source, "window.matchMedia('(max-width: 600px)').matches") !== false,
    'KB editor height no longer distinguishes mobile viewports'
);
kb_editor_height_assert(
    strpos($source, 'return 360;') !== false &&
    strpos($source, 'Math.max(240, Math.min(320, Math.round(viewportHeight * 0.45)))') !== false,
    'KB editor desktop/mobile usable height bounds changed unexpectedly'
);
kb_editor_height_assert(
    strpos($source, 'height: getKbEditorHeight()') !== false,
    'KB SCEditor initialization no longer overrides the inherited global height option'
);
kb_editor_height_assert(
    strpos($source, 'applyKbEditorHeight(existingInstance);') !== false,
    'KB does not repair an editor instance initialized first by AdvancedEditor'
);
kb_editor_height_assert(
    strpos($source, '.sceditor-container') === false || strpos($source, 'height: 360px !important') === false,
    'KB editor height must be configured through SCEditor rather than a global CSS override'
);

echo "KB editor height regression checks passed\n";

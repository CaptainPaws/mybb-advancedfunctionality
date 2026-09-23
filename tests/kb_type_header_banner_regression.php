<?php

declare(strict_types=1);

$root = __DIR__ . '/../inc/plugins/advancedfunctionality/addons/knowledgebase/';
$renderer = file_get_contents($root . 'knowledgebase.php');
$css = file_get_contents($root . 'assets/knowledgebase.css');
$templates = [
    file_get_contents($root . 'templates/knowledgebase_list.html'),
    file_get_contents($root . 'templates/knowledgebase_list_character.html'),
];

function kb_type_header_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

kb_type_header_assert(is_string($renderer) && is_string($css), 'Unable to read KB renderer assets');
kb_type_header_assert(
    strpos($renderer, "if (\$typeBannerUrl !== '')") !== false,
    'Type header must branch on the sanitized explicit banner value'
);
kb_type_header_assert(
    strpos($renderer, '$kb_type_heading = \'<h1>\'') !== false,
    'Bannerless types must render a plain heading without a visual wrapper'
);
kb_type_header_assert(
    strpos($renderer, 'class="af-kb-type-header-visual"') !== false,
    'Banner types must render the overlay visual wrapper'
);

foreach ($templates as $template) {
    kb_type_header_assert(is_string($template), 'Unable to read a KB list template');
    kb_type_header_assert(strpos($template, '{$kb_type_heading}') !== false, 'List template must use conditional type heading markup');
    kb_type_header_assert(strpos($template, '{$kb_banner}') === false, 'List template must not render a separate banner after the heading');
}

foreach (['position: absolute', 'inset: 0', 'align-items: center', 'justify-content: center', 'overflow-wrap: anywhere'] as $rule) {
    kb_type_header_assert(strpos($css, $rule) !== false, "Missing responsive banner overlay rule: {$rule}");
}

fwrite(STDOUT, "KB type header banner regression checks passed.\n");

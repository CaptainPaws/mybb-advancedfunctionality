<?php
/** Regression coverage for the AdvancedEditor WYSIWYG iframe body padding. */

$source = file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality/addons/advancededitor/assets/advancededitor.js');
if (!is_string($source)) {
    throw new RuntimeException('Unable to read AdvancedEditor JavaScript source.');
}

if (!preg_match(
    '~function\s+afAeApplyWysiwygAtfTheme\s*\([^)]*\)\s*\{(?<body>.*?)\n\s*\}\n\n\s*function\s+afAeApplyWysiwygCodeQuoteCss~s',
    $source,
    $match
)) {
    throw new RuntimeException('AdvancedEditor WYSIWYG ATF theme function is missing.');
}

if (str_contains($match['body'], 'padding:.75rem')) {
    throw new RuntimeException('AdvancedEditor WYSIWYG iframe body restored the extra .75rem padding.');
}

if (!str_contains($match['body'], "body{box-sizing:border-box}")) {
    throw new RuntimeException('AdvancedEditor WYSIWYG iframe body lost its padding-free box-sizing rule.');
}

echo "AdvancedEditor WYSIWYG padding regression checks passed.\n";

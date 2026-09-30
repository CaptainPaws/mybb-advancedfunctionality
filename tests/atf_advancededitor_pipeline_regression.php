<?php
/** Regression coverage for the ATF BBCode textarea -> SCEditor pipeline. */

$atf = file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php');
$editor = file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality/addons/advancededitor/advancededitor.php');
$javascript = file_get_contents(__DIR__.'/../inc/plugins/advancedfunctionality/addons/advancededitor/assets/advancededitor.js');

if (!is_string($atf) || !is_string($editor) || !is_string($javascript)) {
    throw new RuntimeException('Unable to read the ATF/AdvancedEditor pipeline sources.');
}

if (!str_contains($atf, 'af-atf-bbcode-editor') || !str_contains($atf, 'data-af-ae-editor="1"')) {
    throw new RuntimeException('ATF textarea does not declare the BBCode editor contract.');
}
if (preg_match("~case 'textarea'.*?build_mycode_inserter~s", $atf)) {
    throw new RuntimeException('ATF still starts a competing MyBB editor instance.');
}
if (!str_contains($editor, "'has_atf_editor' => \$hasAtfEditor")) {
    throw new RuntimeException('AdvancedEditor does not publish the ATF response fact.');
}
if (!str_contains($editor, "'textarea.af-atf-bbcode-editor'")) {
    throw new RuntimeException('AdvancedEditor payload does not target ATF editors.');
}
if (!str_contains($javascript, "root.tagName === 'TEXTAREA'")
    || !str_contains($javascript, 'root.matches(rootSelector)')) {
    throw new RuntimeException('Dynamically inserted textarea roots are not initialized.');
}

echo "ATF AdvancedEditor pipeline regression checks passed.\n";

<?php
/**
 * Regression contract: ATF must preserve native MyBB inline moderation for
 * FIMP while keeping the custom floating presentation.
 */
$root = dirname(__DIR__);
$showthread = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework/templates/showthread.html');
$fimp = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedjsbandle/assets/fimp.js');
$css = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedjsbandle/assets/fimp.css');
$manifest = file_get_contents($root . '/inc/plugins/advancedfunctionality/addons/advancedjsbandle/manifest.php');
$assert = static function (bool $condition, string $error): void {
    if (!$condition) {
        throw new RuntimeException($error);
    }
};
$assert(str_contains($showthread, '{$moderationoptions}{$forumjump}'), 'ATF must retain native thread moderation options.');
$assert(!str_contains($showthread, '{$inlinemod}'), 'Inline moderation is already included by showthread_moderationoptions; do not render it twice.');
$assert(str_contains($fimp, 'name^="inlinemod_"'), 'FIMP must recognize native named checkbox fields.');
$assert(str_contains($fimp, "this.id = 'inlinemod_'"), 'MyBB checkbox IDs must be normalized before native initialization.');
$assert(str_contains($fimp, 'change.afFimp'), 'FIMP should track dynamically inserted moderation checkboxes.');
$assert(str_contains($fimp, "if ($forms.length > 1)"), 'FIMP must fail closed when native moderation forms are duplicated.');
$assert(str_contains($fimp, "form.requestSubmit(submit)"), 'FIMP must submit using native MyBB form events.');
$assert(str_contains($fimp, "this.value === value"), 'FIMP must match the selected exact native option value.');
$assert(!str_contains($fimp, "inlineModeration.submit("), 'There is no inlineModeration.submit API in MyBB 1.8.40.');
$assert(str_contains($fimp, "modType !== 'inlinepost'"), 'FIMP should only mount for native inline moderation forms.');
$assert(str_contains($css, 'body.atf-active #fimp'), 'FIMP must remain visible over ATF controls.');
$assert(str_contains($manifest, "'fimp.js' => ['mode' => 'contextual'"), 'FIMP must retain its manifest permission declaration.');
echo "ATF FIMP moderation contracts passed.\n";

<?php

define('IN_MYBB', true);
define('AF_ADDONS', dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/');

$GLOBALS['atf_framework_enabled'] = false;
function af_is_addon_enabled(string $id): bool
{
    return $id === 'adaptivethemeframework' && $GLOBALS['atf_framework_enabled'];
}
function af_adaptivethemeframework_template_seeds(): array
{
    return [
        'newthread' => '/seed/newthread.html',
        'editpost' => '/seed/editpost.html',
        'showthread' => '/seed/showthread.html',
        'forumdisplay_thread' => '/seed/forumdisplay_thread.html',
    ];
}

require AF_ADDONS . 'advancedthreadfields/advancedthreadfields.php';

// A/F: without ATF, all legacy edit points continue to work.
$showSeed = "<main>\n<strong>{\$thread['displayprefix']} {\$thread['subject']}</strong>\n</main>\n";
$legacyShow = af_atf_tpl_force_edit_by_title('showthread', $showSeed);
if (!str_contains($legacyShow, AF_ATF_TPL_MARK_SHOW)
    || !str_contains($legacyShow, '{$af_atf_showthread_block}')) {
    throw new RuntimeException('ATF-off showthread legacy patch was lost.');
}

$threadSeed = "<tr><td><a href={\$thread['threadlink']}>{\$thread['subject']}</a>{\$thread['multipage']}</td></tr>";
$legacyThread = af_atf_tpl_force_edit_by_title('forumdisplay_thread', $threadSeed);
if (!str_contains($legacyThread, AF_ATF_TPL_MARK_CHIPS)) {
    throw new RuntimeException('ATF-off forumdisplay_thread legacy patch was lost.');
}

$formSeed = "<form><table><tr><td class=\"trow2\">{\$prefixselect}<input name=\"subject\"></td></tr></table></form>";
foreach (['newthread', 'editpost'] as $name) {
    $patched = af_atf_tpl_force_edit_by_title($name, $formSeed);
    if (!str_contains($patched, AF_ATF_TPL_MARK_INPUT) || !str_contains($patched, '{$af_atf_input_html}')) {
        throw new RuntimeException("{$name} legacy patch changed.");
    }
}

// B/C: catalogue ownership, rather than a hard-coded showthread exception,
// prevents both ATF-managed templates from being changed.
$GLOBALS['atf_framework_enabled'] = true;
if (af_atf_tpl_force_edit_by_title('showthread', $showSeed) !== $showSeed
    || af_atf_tpl_force_edit_by_title('forumdisplay_thread', $threadSeed) !== $threadSeed) {
    throw new RuntimeException('An ATF-owned template was legacy-patched.');
}
if (af_atf_tpl_force_edit_by_title('newthread', $formSeed) !== $formSeed
    || af_atf_tpl_force_edit_by_title('editpost', $formSeed) !== $formSeed) {
    throw new RuntimeException('ATF-owned compose templates were legacy-patched.');
}

// D: exact legacy bytes (including their surrounding whitespace) normalize to
// the byte-identical seed. Diagnostics contain counts/checksum evidence only.
$current = str_replace("\n</main>", "\n" . AF_ATF_TPL_MARK_SHOW
    . "\n{\$af_atf_showthread_block}\n\n</main>", $showSeed);
$recovery = af_atf_normalize_atf_template('showthread', $current);
if (!is_array($recovery)
    || $recovery['normalized_content'] !== $showSeed
    || $recovery['diagnostic']['marker_count'] !== 1
    || $recovery['diagnostic']['variable_count'] !== 1) {
    throw new RuntimeException('Exact showthread legacy delta did not normalize to its seed.');
}

// D2: compose templates historically received the exact INPUT marker + variable
// before ATF started owning newthread/editpost. That known delta must normalize
// back to the seed so activation can recover the old lease safely.
foreach (['newthread', 'editpost'] as $name) {
    $composeCurrent = str_replace(
        '</form>',
        AF_ATF_TPL_MARK_INPUT . "\n{\$af_atf_input_html}\n</form>",
        $formSeed
    );
    $composeRecovery = af_atf_normalize_atf_template($name, $composeCurrent);
    if (!is_array($composeRecovery)
        || $composeRecovery['normalized_content'] !== $formSeed
        || $composeRecovery['diagnostic']['marker_count'] !== 1
        || $composeRecovery['diagnostic']['variable_count'] !== 1) {
        throw new RuntimeException("Exact {$name} legacy INPUT delta did not normalize to its seed.");
    }
}

// E: normalization removes only attributable bytes; an unrelated edit remains
// different and ATF's checksum gate must consequently retain the conflict.
$manual = str_replace('<main>', '<main data-manual="1">', $current);
$manualRecovery = af_atf_normalize_atf_template('showthread', $manual);
if (!is_array($manualRecovery) || hash('sha256', $manualRecovery['normalized_content']) === hash('sha256', $showSeed)) {
    throw new RuntimeException('Unrelated manual edit was incorrectly accepted as the seed.');
}

$source = file_get_contents(AF_ADDONS . 'advancedthreadfields/advancedthreadfields.php');
if (!is_string($source)
    || !str_contains($source, "if (!af_atf_template_is_atf_owned('showthread')")) {
    throw new RuntimeException('ATF-owned showthread runtime fallback was not disabled.');
}

echo "AdvancedThreadFields ATF template ownership passed.\n";

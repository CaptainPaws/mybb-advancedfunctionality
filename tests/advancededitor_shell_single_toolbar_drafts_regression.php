<?php
/**
 * Regression contract: the lightweight AE shell owns the visible toolbar.
 * Drafts survive submit attempts, and clear only after a published post is
 * observed (AJAX) or a confirmed redirect.
 */
$root = dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/advancededitor/';
$wys = file_get_contents($root . 'assets/advancededitor.js');
$shell = file_get_contents($root . 'assets/advancededitor_shell.js');
$css = file_get_contents($root . 'assets/advancededitor_shell.css');
$check = static function ($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
};
$check(str_contains($wys, "toolbar: ta.__afAeShell ? '' : out.toolbar"),
    'The shell must suppress the nested native SCEditor toolbar.');
$check(str_contains($css, '.af-ae-shell .sceditor-container .sceditor-toolbar { display: none !important; }'),
    'A nested SCEditor toolbar must not be visible through theme overrides.');
$serverShell = file_get_contents($root . 'shell.php');
$check(!str_contains($serverShell, 'class="sceditor-container af-ae-shell"'),
    'The outer AF shell must never inherit native SCEditor fixed-height/flex styles.');
$check(str_contains($serverShell, 'class="af-ae-shell" data-af-editor-shell="1"'),
    'The server-rendered editor must use the dedicated AF shell class.');
$check(!str_contains($shell, "wrapper.className = 'sceditor-container af-ae-shell'"),
    'Dynamic shells must not impersonate native SCEditor containers.');
$check(str_contains($shell, "wrapper.className = 'af-ae-shell'"),
    'Dynamic shells must use the dedicated AF shell class.');
$check(!str_contains($wys, "shell.classList.add('sceditor-container')"),
    'Lazy WYSIWYG activation must not restore the native SCEditor class on the AF shell.');
$check(str_contains($shell, 'function finishPublished()'),
    'Draft storage and editor content must be cleared together after success.');
$check(str_contains($shell, 'postObserver.observe(posts'),
    'MyBB AJAX post insertion must confirm a successful quick reply.');
$check(str_contains($shell, 'post_\\d+') && str_contains($shell, 'pid_?\\d+'),
    'The post observer must support ATF article IDs and native MyBB anchors.');
$start = strpos($shell, 'function markSubmission()');
$end = strpos($shell, 'if (posts && typeof MutationObserver', $start);
$check($start !== false && $end !== false, 'Submission tracking must be present.');
$check(!str_contains(substr($shell, $start, $end - $start), 'localStorage.removeItem'),
    'Unconfirmed attempts must never erase draft storage.');
echo "AdvancedEditor shell toolbar and AJAX draft contracts passed.\\n";

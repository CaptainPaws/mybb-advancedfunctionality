<?php

$root = dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/';
$editor = file_get_contents($root . 'advancededitor/assets/advancededitor.js');
$editorPhp = file_get_contents($root . 'advancededitor/advancededitor.php');
$counter = file_get_contents($root . 'advancededitor/assets/bbcodes/bbcodes/charcountandprew/charcountandprew.js');
$css = file_get_contents($root . 'adaptivethemeframework/assets/adaptivethemeframework.css');
$postbit = file_get_contents($root . 'adaptivethemeframework/templates/postbit_classic.html');

foreach ([$editor, $editorPhp, $counter, $css, $postbit] as $source) {
    if ($source === false) {
        throw new RuntimeException('A required quick-edit source is missing.');
    }
}

if (!str_contains($editorPhp, <<<'PHP'
$payload['cfg']['quickEditSelector'] = 'textarea[id^="quickedit_"][name="value"]';
PHP)) {
    throw new RuntimeException('Advanced Editor payload does not publish the AJAX Quick Edit selector.');
}
if (!str_contains($editorPhp, "'textarea[name=\"message\"]'")) {
    throw new RuntimeException('Ordinary textarea[name=message] editor support was lost.');
}
if (!str_contains($postbit, '<div class="atf-post__message-body" id="pid_{$post[\'pid\']}">{$post[\'message\']}</div>')
    || str_contains($postbit, 'atf-post__message" id="pid_')) {
    throw new RuntimeException('MyBB pid host still includes the stable ATF meta-line.');
}

foreach ([
    'MutationObserver',
    'function isQuickEditTextarea(ta)',
    "match(/^quickedit_(\\d+)$/)",
    "(ta.getAttribute('name') || '') !== 'value'",
    "ta.closest('.atf-post__content')",
    "ta.closest('.atf-post__message, .post_body')",
    "ta.closest('#pid_' + match[1])",
    'if (isQuickEditTextarea(root)',
    "root.querySelectorAll('textarea[id^=\"quickedit_\"][name=\"value\"]')",
    'if (!isEligibleTextarea(ta)) return false;',
    '$ta.sceditor({',
    'var inst = safeGetInstance($ta);',
    "log('[AE] quick-edit instance contract failed'",
    "scanAndDestroyRemoved(removed, 'dom_removed')",
    "form.classList.add('atf-editor')",
    "form.classList.add('atf-editor--quick-edit')",
    "form.classList.add('atf-quick-edit')",
    'announceEditorReady(ta, inst)',
] as $needle) {
    if (!str_contains($editor, $needle)) {
        throw new RuntimeException("Quick-edit editor lifecycle is missing {$needle}.");
    }
}

// The positive contract must be numeric and exact. These negative fixtures
// document shapes which must never reach the owner initializer.
$contract = static function (string $id, string $name, bool $showthread, bool $hasPostContext): bool {
    return $showthread
        && $name === 'value'
        && (bool)preg_match('/^quickedit_\d+$/D', $id)
        && $hasPostContext;
};
if (!$contract('quickedit_699', 'value', true, true)) {
    throw new RuntimeException('Real MyBB textarea#quickedit_699[name=value] is not eligible.');
}
foreach ([
    ['quickedit_test', 'value', true, true],
    ['random', 'value', true, true],
    ['quickedit_699', 'message', true, true],
    ['quickedit_699', 'value', false, true],
    ['quickedit_699', 'value', true, false],
] as [$id, $name, $showthread, $context]) {
    if ($contract($id, $name, $showthread, $context)) {
        throw new RuntimeException("Unsafe Quick Edit fixture was accepted: {$id}[name={$name}].");
    }
}

foreach ([
    'if (ta.__afCcpBinding)',
    "parent.querySelector(':scope > .af-ccp-wrap')",
    'if (!ta.isConnected)',
    'clearInterval(binding.interval)',
    'detail.quickEdit',
    "post.querySelector('.atf-post__content .atf-post__message')",
] as $needle) {
    if (!str_contains($counter, $needle)) {
        throw new RuntimeException("Quick-edit counter lifecycle is missing {$needle}.");
    }
}

if (!str_contains($css, '.atf-post__content .atf-editor--quick-edit')) {
    throw new RuntimeException('Compact quick-edit styling is not scoped to post content.');
}
foreach (['.atf-post__sidebar', '.atf-post__sidebar-inner', '.atf-post__topbar', '.atf-post__meta-line'] as $protected) {
    if (str_contains($css, $protected . ' .atf-editor--quick-edit')) {
        throw new RuntimeException("Quick-edit styling leaked into protected postbit region {$protected}.");
    }
}

echo "Advanced Editor quick-edit lifecycle regression checks passed.\n";

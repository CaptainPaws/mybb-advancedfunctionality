<?php

$root = dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/';
$editor = file_get_contents($root . 'advancededitor/assets/advancededitor.js');
$counter = file_get_contents($root . 'advancededitor/assets/bbcodes/bbcodes/charcountandprew/charcountandprew.js');
$css = file_get_contents($root . 'adaptivethemeframework/assets/adaptivethemeframework.css');

foreach ([$editor, $counter, $css] as $source) {
    if ($source === false) {
        throw new RuntimeException('A required quick-edit source is missing.');
    }
}

foreach ([
    'MutationObserver',
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

foreach ([
    'if (ta.__afCcpBinding)',
    "parent.querySelector(':scope > .af-ccp-wrap')",
    'if (!ta.isConnected)',
    'clearInterval(binding.interval)',
    'detail.quickEdit',
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

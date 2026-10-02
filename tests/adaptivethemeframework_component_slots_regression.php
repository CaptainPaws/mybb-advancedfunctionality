<?php

define('IN_MYBB', true);
$addon = dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/adaptivethemeframework';
define('AF_ADDONS', dirname($addon) . '/');

$GLOBALS['test_enabled_owners'] = ['alpha' => true, 'beta' => true, 'off' => false];
function af_is_addon_enabled(string $owner): bool
{
    return !empty($GLOBALS['test_enabled_owners'][$owner]);
}

require $addon . '/adaptivethemeframework.php';

foreach (get_included_files() as $includedFile) {
    if (basename($includedFile) === 'advresponsivelayout.php') {
        throw new RuntimeException('The component slot API loaded the deprecated legacy addon.');
    }
}

$required = [
    'profile.hero', 'profile.navigation', 'profile.forum_info', 'profile.character_sheet',
    'profile.application', 'profile.timeline', 'profile.activity', 'profile.balance',
    'profile.post_counter', 'profile.before_content', 'profile.main', 'profile.after_content',
    'post.author.identity', 'post.author.meta', 'post.author.profile_fields', 'post.author.rail',
    'post.author.plaque', 'post.author.character', 'post.post_counter', 'post.before_body',
    'post.after_body', 'post.actions', 'thread.breadcrumbs', 'thread.meta', 'thread.atf_fields',
    'thread.before_posts', 'thread.after_posts', 'forum.lastposter_avatar',
    'thread.lastposter_avatar', 'thread.meta_chips', 'header.primary_navigation',
    'header.secondary_navigation', 'header.user_navigation', 'header.assets',
    'footer.components', 'footer.modals',
    'pm.navigation', 'pm.quota', 'pm.notice', 'pm.pagination', 'pm.actions',
    'pm.content', 'pm.before_content', 'pm.after_content',
    'ucp.global_navigation', 'ucp.local_navigation', 'ucp.before_content',
    'ucp.content', 'ucp.after_content', 'ucp.notice', 'ucp.actions',
    'userlist.before_list', 'userlist.card.meta',
    'userlist.card.actions', 'userlist.after_list',
];
if (af_adaptivethemeframework_slots() !== $required) {
    throw new RuntimeException('The audited slot catalogue changed.');
}

$register = static function (string $owner, string $key, int $order, $visibility = true, $context = []): bool {
    return af_adaptivethemeframework_register_component([
        'owner' => $owner, 'key' => $key, 'slot' => 'profile.main',
        'sortorder' => $order, 'visibility' => $visibility, 'context' => $context,
        'renderer' => static fn(array $ctx): string => $key . ':' . ($ctx['uid'] ?? 0) . ';',
    ]);
};

if (!$register('alpha', 'late', 30)
    || !$register('alpha', 'early', 10)
    || !$register('beta', 'middle', 20)
    || $register('alpha', 'early', 1)) {
    throw new RuntimeException('Registration or duplicate identity protection failed.');
}
$register('off', 'disabled', 1);
$register('alpha', 'hidden', 2, false);
$register('alpha', 'missing_visibility_callback', 2, 'af_missing_visibility_callback');
$register('alpha', 'moderator', 3, static fn(array $ctx): bool => !empty($ctx['moderator']));
$register('alpha', 'profile_only', 4, true, ['page' => 'profile', 'mode' => ['full', 'compact']]);

$actual = af_adaptivethemeframework_render_slot('profile.main', ['uid' => 7, 'page' => 'profile', 'mode' => 'full']);
$expected = 'profile_only:7;early:7;middle:7;late:7;';
if ($actual !== $expected) {
    throw new RuntimeException("Ordering, owner, visibility, context, or multi-component rendering failed: {$actual}");
}

if (!af_adaptivethemeframework_register_component([
    'owner' => 'alpha', 'key' => 'dual', 'slot' => 'thread.meta', 'sortorder' => 1,
    'renderer' => static fn(): string => '<new>',
    'legacy' => static fn(): string => '<legacy>', 'legacy_position' => 'before',
]) || af_adaptivethemeframework_render_slot('thread.meta') !== '<legacy><new>') {
    throw new RuntimeException('Explicit legacy dual rendering failed.');
}

if (af_adaptivethemeframework_register_component([
    'owner' => 'alpha', 'key' => 'unknown', 'slot' => 'legacy.dom.marker', 'html' => 'bad',
])) {
    throw new RuntimeException('An unknown compatibility slot was accepted.');
}

if (!af_adaptivethemeframework_register_pm_providers()
    || af_adaptivethemeframework_register_pm_providers()) {
    throw new RuntimeException('PM providers were not registered exactly once.');
}
$pmContext = af_adaptivethemeframework_pm_context([
    'action' => 'read', 'folder_id' => 7, 'folder_name' => 'Custom',
    'uid' => 42, 'current_url' => 'private.php?action=read&pmid=9',
    'folders' => [1 => 'Inbox', 2 => 'Sent', 5 => 'Custom'],
    'can_send' => true, 'can_track' => true,
], [
    'pmspacebar' => '<quota>', 'limitwarning' => '<warning>', 'multipage' => '<pages>',
    'composelink' => '<compose>', 'emptyexportlink' => '<empty-export>',
]);
if ($pmContext['folder'] !== ['id' => 7, 'name' => 'Custom']
    || $pmContext['user']['uid'] !== 42
    || !str_contains(af_adaptivethemeframework_render_slot('pm.navigation', $pmContext), 'private.php?fid=5')
    || !str_contains(af_adaptivethemeframework_render_slot('pm.navigation', $pmContext), 'action=advanced_search')
    || af_adaptivethemeframework_render_slot('pm.quota', $pmContext) !== '<quota>'
    || af_adaptivethemeframework_render_slot('pm.notice', $pmContext) !== '<warning>'
    || af_adaptivethemeframework_render_slot('pm.pagination', $pmContext) !== '<pages>'
    || af_adaptivethemeframework_render_slot('pm.actions', $pmContext) !== '<compose><empty-export>') {
    throw new RuntimeException('Closed PM context or native provider transport failed.');
}
$emptyPmContext = af_adaptivethemeframework_pm_context([], []);
if (array_filter($emptyPmContext['native'], static fn(string $value): bool => $value !== '')) {
    throw new RuntimeException('Surface-specific PM values did not default to empty strings.');
}

echo "Adaptive Theme Framework component slots passed.\n";

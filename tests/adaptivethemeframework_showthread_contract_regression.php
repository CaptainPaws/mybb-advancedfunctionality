<?php

define('IN_MYBB', true);
define('AF_ADDONS', dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/');

require AF_ADDONS . 'adaptivethemeframework/adaptivethemeframework.php';

if (!af_adaptivethemeframework_register_thread_providers()) {
    throw new RuntimeException('Breadcrumb provider was not registered.');
}
$context = af_adaptivethemeframework_showthread_context(
    ['tid' => 12, 'uid' => 7, 'username' => 'Owner', 'subject' => 'Subject', 'dateline' => 10, 'lastpost' => 20],
    3,
    ['breadcrumbs_html' => '<nav>crumbs</nav>', 'posts_html' => '<article id="post_1">post</article>']
);
if (af_adaptivethemeframework_render_slot('thread.breadcrumbs', $context) !== '<nav>crumbs</nav>') {
    throw new RuntimeException('Breadcrumb slot did not render explicit context.');
}
if (af_adaptivethemeframework_render_slot('thread.breadcrumbs', []) !== '') {
    throw new RuntimeException('Missing breadcrumb context did not produce an empty slot.');
}
foreach (['posts_html', 'quickreply_html', 'moderation_html', 'poll_html', 'thread_tools_html', 'pagination_html', 'javascript_html'] as $key) {
    if (!array_key_exists($key, $context)) throw new RuntimeException("Missing showthread contract key: {$key}");
}

$seeds = af_adaptivethemeframework_template_seeds();
$showthread = file_get_contents($seeds['showthread'] ?? '');
foreach (['id="posts_container"', 'id="posts"', '{$posts}', '{$quickreply}', '{$moderationoptions}',
          '{$pollbox}', '{$multipage}', '{$newreply}', '{$addremovesubscription}', '{$thread_deleted}',
          '{$atf_thread_breadcrumbs}', '{$atf_thread_meta}', '{$atf_thread_fields}',
          '{$atf_thread_before_posts}', '{$atf_thread_after_posts}'] as $anchor) {
    if (!str_contains($showthread, $anchor)) throw new RuntimeException("ATF showthread seed omits {$anchor}");
}
if (!str_contains($showthread, '/jscripts/thread.js?ver=1838')
    || !str_contains($showthread, '/jscripts/jeditable/jeditable.min.js')
    || !str_contains($showthread, '/jscripts/report.js?ver=1820')) {
    throw new RuntimeException('ATF showthread seed omits MyBB post action scripts.');
}
if (!str_contains(file_get_contents(AF_ADDONS . 'adaptivethemeframework/adaptivethemeframework.php'),
    "add_hook('showthread_end', 'af_adaptivethemeframework_compose_showthread'")) {
    throw new RuntimeException('Showthread composition hook is not registered.');
}

$threadFields = file_get_contents(AF_ADDONS . 'advancedthreadfields/advancedthreadfields.php');
if (!str_contains($threadFields, "'slot' => 'thread.atf_fields'")
    || !preg_match('~function af_atf_render_showthread_fields.*?return af_atf_build_display_block_for_tid_fid\(\$tid, \$fid\);~s', $threadFields)) {
    throw new RuntimeException('Thread fields provider does not delegate to the legacy rendering implementation.');
}
if (!str_contains($threadFields, 'AF_ATF_TPL_MARK_SHOW') || !str_contains($threadFields, "!af_atf_theme_is_active()")) {
    // The marker itself is retained. The second assertion protects the
    // already established ATF-off legacy branch used by forum/thread output.
    throw new RuntimeException('ATF-off marker compatibility was not retained.');
}

echo "ATF showthread contract passed.\n";

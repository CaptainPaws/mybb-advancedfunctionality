<?php
// Regression contract for profile/postbit navigation and post reputation UI.
define('IN_MYBB', 1);
define('TABLE_PREFIX', 'mybb_');
define('AF_ADDONS', __DIR__ . '/../inc/plugins/advancedfunctionality/addons/');

function htmlspecialchars_uni(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function my_number_format(int $value): string { return (string)$value; }

$mybb = (object)['settings' => ['af_advancedprofileui_enabled' => '0'], 'user' => ['uid' => 1]];
$plugins = new class {
    public function add_hook(...$args): void {}
};

require AF_ADDONS . 'adaptivethemeframework/adaptivethemeframework.php';
require AF_ADDONS . 'advancedprofileui/advancedprofileui.php';

function atf_reputation_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$routes = af_apui_user_stat_routes(73);
atf_reputation_assert($routes === [
    'messages' => 'search.php?action=finduser&uid=73',
    'threads' => 'search.php?action=finduserthreads&uid=73',
    'reputation' => 'reputation.php?uid=73',
], 'Canonical user routes are not dynamic or do not use MyBB endpoints.');

$profile = af_apui_render_profile_stats(['uid' => 73, 'member' => [
    'postnum' => 12, 'threadnum' => 4, 'reputation' => 3,
]]);
foreach (['search.php?action=finduser&amp;uid=73', 'search.php?action=finduserthreads&amp;uid=73', 'reputation.php?uid=73'] as $url) {
    atf_reputation_assert(str_contains($profile, 'href="' . $url . '"'), 'Profile stat link missing: ' . $url);
}

$source = file_get_contents(AF_ADDONS . 'advancedprofileui/advancedprofileui.php');
atf_reputation_assert(!str_contains($source, 'af-apui-stat-item--level'), 'Level returned to postbit statistics.');
atf_reputation_assert(str_contains($source, '$linkedStat(\'af-apui-stat-item--messages\''), 'Postbit messages are not linked.');
atf_reputation_assert(str_contains($source, '$linkedStat(\'af-apui-stat-item--threads\''), 'Postbit threads are not linked.');
atf_reputation_assert(str_contains($source, '$linkedStat(\'af-apui-stat-item--reputation\''), 'Postbit reputation is not linked.');

$postReputation = af_adaptivethemeframework_post_reputation([
    'pid' => 19, 'tid' => 8, 'uid' => 73, 'button_rep' => '<a>allowed</a>',
]);
atf_reputation_assert(str_contains($postReputation, 'MyBB.reputation(73,19)'), 'Heart does not invoke MyBB post reputation.');
atf_reputation_assert(str_contains($postReputation, 'atf-post-reputation__popover'), 'Post reputation voter popover is missing.');
atf_reputation_assert(substr_count($postReputation, 'fa-solid fa-heart') >= 1, 'Post reputation does not use the canonical Font Awesome heart icon.');

$css = file_get_contents(AF_ADDONS . 'adaptivethemeframework/assets/adaptivethemeframework.css');
atf_reputation_assert(str_contains($css, '.atf-thread__posts { overflow: visible; }'), 'Thread container still clips the popover.');
atf_reputation_assert((bool)preg_match('~\.atf-post-reputation__popover \{[^}]*z-index:\s*100~', $css), 'Popover has no explicit stacking layer.');
atf_reputation_assert((bool)preg_match('~button\.atf-post-reputation__heart[^}]*cursor:\s*pointer~s', $css), 'Clickable reputation heart is not normalized as a bare icon button.');
atf_reputation_assert((bool)preg_match('~\.atf-post-reputation__heart\s*\{[^}]*background:\s*transparent !important;[^}]*box-shadow:\s*none !important;~s', $css), 'Reputation heart still has button chrome.');
atf_reputation_assert((bool)preg_match('~\.atf-post-reputation__score\s*\{[^}]*display:\s*inline-flex;[^}]*width:\s*auto !important;[^}]*white-space:\s*nowrap;~s', $css), 'Reputation score can still wrap the plus sign above the number.');

$reputationTemplate = file_get_contents(AF_ADDONS . 'adaptivethemeframework/templates/reputation_vote.html');
foreach (["reputation_vote['username']", 'vote_reputation', 'last_updated', 'postrep_given', "reputation_vote['comments']"] as $field) {
    atf_reputation_assert(str_contains($reputationTemplate, $field), 'Reputation page omits ' . $field);
}

echo "ATF reputation/navigation regression checks passed.\n";

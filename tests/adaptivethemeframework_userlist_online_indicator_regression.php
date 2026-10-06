<?php
require_once __DIR__ . "/fixtures/atf_css.php";

define('IN_MYBB', true);
define('AF_ADDONS', dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/');

if (!function_exists('htmlspecialchars_uni')) {
    function htmlspecialchars_uni($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

require AF_ADDONS . 'adaptivethemeframework/adaptivethemeframework.php';

function atf_userlist_indicator_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$GLOBALS['af_adaptivethemeframework_components'] = [];
$base = AF_ADDONS . 'adaptivethemeframework/';
$css = atf_test_css($base, 'memberlist.php');
$page = [
    'title' => 'Users',
    'users' => [
        ['uid' => 1, 'username_raw' => 'Online', 'profile_url' => 'member.php?uid=1', 'avatar' => ['html' => '<img src="online.png" alt="">'], 'presence' => ['state' => 'online', 'can_disclose' => true, 'label' => 'Online']],
        ['uid' => 2, 'username_raw' => 'Offline', 'profile_url' => 'member.php?uid=2', 'avatar' => ['html' => '<img src="offline.png" alt="">'], 'presence' => ['state' => 'offline', 'can_disclose' => true, 'label' => 'Offline']],
        ['uid' => 3, 'username_raw' => 'Hidden', 'profile_url' => 'member.php?uid=3', 'avatar' => ['html' => '<img src="hidden.png" alt="">'], 'presence' => ['state' => 'online', 'can_disclose' => false, 'label' => 'Online']],
    ],
];

$html = af_adaptivethemeframework_render_userlist($page);
atf_userlist_indicator_assert(substr_count($html, 'atf-user-card__online-indicator') === 1, 'Only a disclosed online user may receive the indicator.');
atf_userlist_indicator_assert(str_contains($html, '<div class="atf-user-card__avatar atf-avatar"><img src="online.png" alt=""><span class="atf-user-card__online-indicator"'), 'Indicator must be a child of the avatar wrapper.');
atf_userlist_indicator_assert(!str_contains($html, 'atf-user-card__presence'), 'Legacy presence element must not be rendered.');
atf_userlist_indicator_assert(str_contains($css, 'body.atf-active .atf-userlist .atf-user-card__online-indicator'), 'Indicator CSS must be scoped to the ATF userlist.');
atf_userlist_indicator_assert(str_contains($css, 'width:8px;height:8px'), 'Indicator must retain its compact 8px geometry.');
atf_userlist_indicator_assert(str_contains($css, 'top:4px;right:4px'), 'Indicator must sit inside the avatar frame corner.');
atf_userlist_indicator_assert(str_contains($css, ':not(.atf-user-card__online-indicator)'), 'Avatar normalization must exclude the indicator.');
atf_userlist_indicator_assert(!preg_match('~(?:^|[},])\s*\.online(?:\W|$)~m', $css), 'Userlist fix must not introduce a global online selector.');

echo "ATF userlist online-indicator regression checks passed.\n";

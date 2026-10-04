<?php
// Regression contract for Task 13: postbit providers, no template replacement.
define('IN_MYBB', 1);
define('AF_ADDONS', __DIR__ . '/../inc/plugins/advancedfunctionality/addons/');
require AF_ADDONS . 'adaptivethemeframework/adaptivethemeframework.php';

$mybb = (object)['settings' => [
    'af_advancedprofileui_enabled' => '1',
    'af_advancedpostcounter_enabled' => '1',
]];
$GLOBALS['mybb'] = $mybb;
$GLOBALS['af_adaptivethemeframework_components'] = [];

function atf_post_assert($condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

atf_post_assert(af_adaptivethemeframework_register_post_providers(), 'Native action provider not registered.');
$fields = [
    'identity' => ['post.author.identity', 'profilelink'],
    'meta' => ['post.author.meta', 'af_apui_presence_html'],
    'profile_fields' => ['post.author.profile_fields', 'af_apui_profile_fields_html'],
    'rail' => ['post.author.rail', 'af_apui_author_statistics_html'],
    'plaque' => ['post.author.plaque', 'af_apui_plaque_html'],
    'character' => ['post.author.character', 'af_apui_actionbar_html'],
];
foreach ($fields as $key => [$slot, $field]) {
    atf_post_assert(af_adaptivethemeframework_register_component([
        'owner' => 'advancedprofileui', 'key' => 'post_' . $key, 'slot' => $slot,
        'renderer' => static fn(array $ctx): string => (string)($ctx['post'][$field] ?? ''),
    ]), 'APUI provider failed: ' . $key);
}
atf_post_assert(af_adaptivethemeframework_register_component([
    'owner' => 'advancedpostcounter', 'key' => 'post_counter', 'slot' => 'post.post_counter',
    'renderer' => static fn(array $ctx): string => (string)($ctx['post']['af_apc_atf_html'] ?? ''),
]), 'PostCounter provider failed.');
atf_post_assert(!af_adaptivethemeframework_register_component([
    'owner' => 'advancedpostcounter', 'key' => 'post_counter', 'slot' => 'post.post_counter', 'html' => 'duplicate',
]), 'Duplicate provider identity accepted.');

$post = [
    'pid' => 12, 'tid' => 34, 'uid' => 56, 'profilelink' => '<a href="member.php?action=profile&amp;uid=56">User</a>',
    'af_apui_presence_html' => '<i>online</i>', 'af_apui_profile_fields_html' => '<b>fields</b>',
    'af_apui_author_statistics_html' => '<b>rail</b>', 'af_apui_plaque_html' => '<b>plaque</b>',
    'af_apui_actionbar_html' => '<b>character</b>', 'af_apc_atf_html' => '<af_apc_uid_56>',
    'button_edit' => 'EDIT', 'button_quickdelete' => 'DELETE', 'button_quote' => 'QUOTE',
    'button_report' => 'REPORT', 'button_multiquote' => 'MULTI',
    'button_email' => '<a class="postbit_email" href="member.php?action=emailuser&amp;uid=56"><span>E-mail</span></a>',
    'button_pm' => '<a class="postbit_pm" href="private.php?action=send&amp;uid=56"><span>ЛС</span></a>',
    'button_www' => '<a class="postbit_www" href="https://example.test" data-site="native"><span>WWW</span></a>',
    'button_find' => '<a class="postbit_find" href="search.php?action=finduser&amp;uid=56"><span>Поиск</span></a>',
    'button_rep' => '<a class="postbit_rep" href="javascript:void(0)" onclick="MyBB.reputation(56); return false;" data-native="rep"><span>Оценить</span></a>',
];
af_adaptivethemeframework_compose_postbit($post);
atf_post_assert($post['af_atf_context'] === ['pid' => 12, 'tid' => 34, 'uid' => 56], 'Closed identifiers mismatch.');
atf_post_assert(!isset($post['af_atf_context']['post']), 'Full post leaked into public context metadata.');
atf_post_assert($post['af_atf_slots']['post.author.plaque'] === '<b>plaque</b>', 'Character/plaque slot missing.');
atf_post_assert($post['af_atf_slots']['post.author.character'] === '<b>character</b>', 'Character slot missing.');
atf_post_assert($post['af_atf_slots']['post.post_counter'] === '<af_apc_uid_56>', 'PostCounter slot missing.');
foreach (['EDIT','DELETE','QUOTE','REPORT','MULTI'] as $control) {
    atf_post_assert(str_contains($post['af_atf_slots']['post.actions'], $control), 'Action lost: ' . $control);
}
$profileActions = $post['af_atf_slots']['post.author.profile_actions'];
foreach ([
    ['Профиль', 'fa-user'],
    ['E-mail', 'fa-envelope'],
    ['ЛС', 'fa-message'],
    ['Сайт', 'fa-globe'],
    ['Поиск', 'fa-magnifying-glass'],
    ['Оценить', 'fa-thumbs-up'],
] as [$label, $icon]) {
    atf_post_assert(str_contains($profileActions, 'title="' . $label . '"'), "Profile action {$label} lost title.");
    atf_post_assert(str_contains($profileActions, 'data-af-title="' . $label . '"'), "Profile action {$label} lost data-af-title.");
    atf_post_assert(str_contains($profileActions, 'aria-label="' . $label . '"'), "Profile action {$label} lost aria-label.");
    atf_post_assert(str_contains($profileActions, '<i class="fa-solid ' . $icon . '" aria-hidden="true"></i>'), "Profile action {$label} lost its real Font Awesome icon.");
}
atf_post_assert(str_contains($profileActions, 'href="member.php?action=profile&amp;uid=56"'), 'Native profile href was lost.');
atf_post_assert(str_contains($profileActions, 'onclick="MyBB.reputation(56); return false;"'), 'Native reputation onclick was lost.');
atf_post_assert(str_contains($profileActions, 'data-native="rep"'), 'Native reputation data attribute was lost.');
atf_post_assert(!str_contains($profileActions, '>User<'), 'Username leaked into profile actions.');
foreach (['>E-mail<', '>ЛС<', '>WWW<', '>Поиск<', '>Оценить<'] as $visibleLabel) {
    atf_post_assert(!str_contains($profileActions, $visibleLabel), "Visible text leaked into profile actions: {$visibleLabel}");
}

$guest = ['pid' => 1, 'tid' => 2, 'uid' => 0, 'profilelink' => 'Guest'];
af_adaptivethemeframework_compose_postbit($guest);
atf_post_assert($guest['af_atf_slots']['post.author.identity'] === 'Guest', 'Guest identity unavailable.');
atf_post_assert($guest['af_atf_slots']['post.post_counter'] === '', 'Guest counter must be empty.');
$mybb->settings['af_advancedprofileui_enabled'] = '0';
atf_post_assert(af_adaptivethemeframework_render_slot('post.author.identity', af_adaptivethemeframework_post_context($post)) === '', 'Disabled provider rendered.');

echo "ATF postbit provider regression checks passed.\n";

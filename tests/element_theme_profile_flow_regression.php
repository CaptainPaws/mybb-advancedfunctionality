<?php
define('IN_MYBB', true);
define('TABLE_PREFIX', 'mybb_');
define('AF_ADDONS', dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/');
function profile_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function htmlspecialchars_uni($value): string { return htmlspecialchars((string)$value, ENT_QUOTES); }
function af_kb_get_public_type_options($type, $limit = 500): array { return [['key' => 'fire'], ['key' => 'shadow']]; }
function af_characterworkflow_resolve_active_application(int $uid): ?array { return $uid === 1 ? ['tid' => 101, 'relation' => ['uid' => 1, 'accepted' => 1]] : ($uid === 2 ? ['tid' => 102, 'relation' => ['uid' => 2]] : null); }
function af_cwf_get_row(int $tid): array { return ['state' => $tid === 101 ? 'approved' : 'draft']; }
function af_atf_get_profile_character_payload(int $tid): array { return ['tid' => $tid, 'fields' => ['character_element' => ['key' => 'character_element', 'raw' => $tid === 101 ? 'fire' : 'shadow', 'html' => 'Огонь']]]; }
function af_frontend_asset_allowed($addon, $resource = null, $context = null, $facts = []): bool { return !empty($facts['has_element_surface']); }
$mybb = (object)['settings' => ['bburl' => 'https://forum.test'], 'user' => ['uid' => 99]];
$db = null; $cache = null;
require AF_ADDONS . 'advancedelementtheme/advancedelementtheme.php';
require AF_ADDONS . 'advancedprofileui/advancedprofileui.php';
require AF_ADDONS . 'adaptivethemeframework/adaptivethemeframework.php';
foreach ([1 => 'fire', 2 => '', 3 => ''] as $uid => $expected) {
    $memprofile = ['uid' => $uid, 'username' => 'Character'];
    unset($GLOBALS['af_apui_profile_element']); // ATF must not depend on APUI hook output globals.
    af_adaptivethemeframework_compose_profile();
    profile_check($GLOBALS['atf_profile_context']['appearance']['element_theme_key'] === $expected, 'Approved/draft/missing profile identity');
    $html = '<head></head><main class="atf-profile"><div class="atf-profile-hero"><h2>Name</h2></div><div class="atf-profile__workspace">Content</div></main>';
    af_advancedelementtheme_pre_output($html);
    profile_check(str_contains($html, '<main class="atf-profile" data-element="' . $expected . '" data-element-surface="profile">'), 'Installed root without attributes lost profile context');
    $stale = '<main class="atf-profile" data-element="shadow"></main>'; af_advancedelementtheme_pre_output($stale);
    profile_check(str_contains($stale, 'data-element="' . $expected . '"'), 'Stale markup bypassed approved ATF profile context');
    profile_check(str_contains($html, '<div class="atf-profile-hero">') && str_contains($html, '<div class="atf-profile__workspace">'), 'Class prefixes falsely marked child nodes as surface roots');
}
$before = file_get_contents(AF_ADDONS . 'adaptivethemeframework/templates/member_profile.html');
profile_check(str_contains($before, 'data-af-element-effect-host') && str_contains($before, "data-element=\"{\$atf_profile_context['appearance']['element_theme_key']}\""), 'ATF template root contract');
echo "ElementTheme profile flow: approved workflow/raw key -> APUI -> ATF -> installed DOM; draft/missing neutral; exact root classes passed.\n";

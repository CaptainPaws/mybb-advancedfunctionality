<?php

define('IN_MYBB', true);
define('THIS_SCRIPT', 'index.php');
$addons = dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/';
define('AF_ADDONS', $addons);

$GLOBALS['test_enabled'] = ['adaptivethemeframework' => true, 'advancedposteravatar' => true];
function af_is_addon_enabled(string $id): bool { return !empty($GLOBALS['test_enabled'][$id]); }
function htmlspecialchars_uni($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function format_avatar($avatar, $dimensions, $max): array { return ['image' => $avatar]; }

final class ApaTestDb
{
    public array $users = [
        7 => ['uid' => 7, 'username' => 'Avatar User', 'avatar' => 'uploads/avatar.png', 'avatartype' => 'upload'],
        8 => ['uid' => 8, 'username' => 'Letter User', 'avatar' => '', 'avatartype' => ''],
    ];
    public function simple_select($table, $fields, $where, $options = [])
    {
        preg_match('/uid(?: IN \()?=?(\d+)/', $where, $m);
        return (object)['row' => $this->users[(int)($m[1] ?? 0)] ?? false, 'read' => false];
    }
    public function fetch_array($query)
    {
        if (!is_object($query) || $query->read) return false;
        $query->read = true;
        return $query->row;
    }
}
final class ApaTestLang
{
    public string $guest = 'Guest';
    public function load($name): void {}
}

$db = new ApaTestDb();
$lang = new ApaTestLang();
$theme = ['imgdir' => 'images/theme'];
$mybb = (object)['settings' => [
    'bburl' => 'https://example.test', 'useravatar' => '{theme}/default_avatar.png',
    'af_advancedposteravatar_enabled' => '1', 'af_adaptivethemeframework_enabled' => '1',
    'af_advancedposteravatar_position' => 'left', 'af_advancedposteravatar_size' => '44',
    'af_advancedposteravatar_letter' => '1', 'af_advancedposteravatar_onerror' => '1',
    'af_advancedposteravatar_index' => '1', 'af_advancedposteravatar_forumdisplay' => '1',
]];

require $addons . 'adaptivethemeframework/adaptivethemeframework.php';
require $addons . 'advancedposteravatar/advancedposteravatar.php';

if (!af_apa_register_atf_provider()) throw new RuntimeException('ATF provider was not registered.');
if (af_apa_register_atf_provider()) throw new RuntimeException('Duplicate provider identity was accepted.');
$components = af_adaptivethemeframework_components_for_slot('forum.lastposter_avatar');
if (count($components) !== 1 || key($components) !== 'advancedposteravatar::lastposter_avatar') {
    throw new RuntimeException('Stable forum avatar provider identity is missing.');
}
$threadComponents = af_adaptivethemeframework_components_for_slot('thread.lastposter_avatar');
if (count($threadComponents) !== 1 || key($threadComponents) !== 'advancedposteravatar::thread_lastposter_avatar') {
    throw new RuntimeException('Stable thread avatar provider identity is missing.');
}

$render = static fn(int $uid, string $name): string => af_adaptivethemeframework_render_slot(
    'forum.lastposter_avatar', ['fid' => 3, 'lastposteruid' => $uid, 'lastposter' => $name]
);
if (!str_contains($render(7, 'Avatar User'), 'uploads/avatar.png')) throw new RuntimeException('Stored user avatar was not rendered.');
if (!str_contains($render(8, 'Letter User'), 'class="apa_bg')) throw new RuntimeException('Existing letter fallback was not rendered.');
if (!str_contains($render(0, 'Visitor'), 'af-avatar--guest')) throw new RuntimeException('Guest poster fallback failed.');
if (!str_contains($render(999, 'Deleted'), 'af-avatar--guest')) throw new RuntimeException('Deleted poster fallback failed.');
if (af_adaptivethemeframework_render_slot('forum.lastposter_avatar', ['lastposteruid' => 7, 'lastposter' => 'Avatar User']) !== '') {
    throw new RuntimeException('Invalid forum context rendered a component.');
}
$threadContext = af_adaptivethemeframework_thread_card_context([
    'tid' => 11, 'subject' => 'Topic', 'lastposteruid' => 7,
    'lastposter' => 'Avatar User', 'lastpostpid' => 23, 'private' => 'must-not-leak',
], 3);
if (array_keys($threadContext) !== ['tid', 'fid', 'subject', 'lastposteruid', 'lastposter', 'lastpostpid']
    || isset($threadContext['private'])) {
    throw new RuntimeException('Thread-card context is not a closed, minimal contract.');
}
$threadAvatar = af_adaptivethemeframework_render_slot('thread.lastposter_avatar', $threadContext);
if (!str_contains($threadAvatar, 'uploads/avatar.png') || !str_contains($threadAvatar, 'apa_forumdisplay')) {
    throw new RuntimeException('ATF thread-card avatar output failed.');
}
$GLOBALS['test_enabled']['advancedposteravatar'] = false;
if (af_adaptivethemeframework_render_slot('thread.lastposter_avatar', $threadContext) !== '') {
    throw new RuntimeException('Disabled avatar provider did not leave its thread slot empty.');
}
$GLOBALS['test_enabled']['advancedposteravatar'] = true;

// Exercise the same server-side composition consumed by the ATF template, not
// merely provider registration or a direct slot call.
$lastpostTemplate = file_get_contents($addons . 'adaptivethemeframework/templates/forumbit_depth2_forum_lastpost.html');
$renderedCardLastpost = str_replace(
    ['{$forum[\'fid\']}', '{$lastpost_data[\'lastposteruid\']}'],
    ['3', '7'],
    (string)$lastpostTemplate
);
af_adaptivethemeframework_mark_page($renderedCardLastpost);
if (!str_contains($renderedCardLastpost, 'uploads/avatar.png')) {
    throw new RuntimeException('ATF forum-card output did not contain the provider avatar HTML.');
}
$GLOBALS['test_enabled']['advancedposteravatar'] = false;
$renderedWithoutProvider = str_replace(
    ['{$forum[\'fid\']}', '{$lastpost_data[\'lastposteruid\']}'],
    ['3', '7'],
    (string)$lastpostTemplate
);
af_adaptivethemeframework_mark_page($renderedWithoutProvider);
if (str_contains($renderedWithoutProvider, 'uploads/avatar.png') || !str_contains($renderedWithoutProvider, 'atf-forum-lastpost__content')) {
    throw new RuntimeException('ATF forum-card fallback without an avatar provider is broken.');
}
$GLOBALS['test_enabled']['advancedposteravatar'] = true;

$page = '<apa_uid_[7]>legacy<apa_end>';
af_advancedposteravatar_pre_output($page);
if (str_contains($page, 'uploads/avatar.png') || str_contains($page, '<apa_')) {
    throw new RuntimeException('ATF mode emitted legacy avatar output or leaked markers.');
}

$GLOBALS['test_enabled']['adaptivethemeframework'] = false;
$legacy = '<apa_uid_[7]>legacy<apa_end>';
af_advancedposteravatar_pre_output($legacy);
if (!str_contains($legacy, 'uploads/avatar.png') || !str_contains($legacy, 'apa_meta')) {
    throw new RuntimeException('ATF-off legacy marker rendering is unavailable.');
}

// Template lifecycle uses the seed catalogue as the ownership boundary. ATF
// mode must not even call MyBB's patch helper for an owned template, while the
// same legacy patch remains available with ATF disabled.
$GLOBALS['apa_template_patch_calls'] = [];
function find_replace_templatesets($title, $find, $replace, $limit = -1): void {
    $GLOBALS['apa_template_patch_calls'][] = $title;
}
$adminRoot = sys_get_temp_dir() . '/apa-admin-' . getmypid() . '/';
@mkdir($adminRoot . 'inc', 0777, true);
file_put_contents($adminRoot . 'inc/adminfunctions_templates.php', "<?php\n");
if (!defined('MYBB_ROOT')) define('MYBB_ROOT', $adminRoot);

$GLOBALS['test_enabled']['adaptivethemeframework'] = true;
af_apa_templates_apply(true);
if (in_array('forumbit_depth2_forum_lastpost', $GLOBALS['apa_template_patch_calls'], true)) {
    throw new RuntimeException('ATF mode patched an ATF-owned lastpost template.');
}

$GLOBALS['apa_template_patch_calls'] = [];
$GLOBALS['test_enabled']['adaptivethemeframework'] = false;
af_apa_templates_apply(true);
foreach (['forumbit_depth1_forum_lastpost', 'forumbit_depth2_forum_lastpost', 'forumdisplay_thread'] as $legacyTemplate) {
    if (!in_array($legacyTemplate, $GLOBALS['apa_template_patch_calls'], true)) {
        throw new RuntimeException("ATF-off mode did not patch {$legacyTemplate}.");
    }
}
@unlink($adminRoot . 'inc/adminfunctions_templates.php');
@rmdir($adminRoot . 'inc');
@rmdir($adminRoot);

echo "AdvancedPosterAvatar ATF provider passed.\n";

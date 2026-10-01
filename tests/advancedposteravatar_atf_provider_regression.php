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

echo "AdvancedPosterAvatar ATF provider passed.\n";

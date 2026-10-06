<?php

define('IN_MYBB', true);
define('THIS_SCRIPT', 'forumdisplay.php');
define('TABLE_PREFIX', 'mybb_');
define('AF_ADDONS', dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/');

$GLOBALS['test_enabled'] = ['adaptivethemeframework' => true, 'advancedthreadfields' => true];
function af_is_addon_enabled(string $id): bool { return !empty($GLOBALS['test_enabled'][$id]); }
function htmlspecialchars_uni($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

final class AtfThreadCache
{
    public function read($name): array
    {
        return ['fields' => [[
            'fieldid' => 4, 'group_active' => 1, 'active' => 1, 'show_forum' => 1,
            'sortorder' => 10, 'forums_set' => [3 => 1], 'title' => 'Site',
            'name' => 'site', 'type' => 'url', 'options' => '',
        ]]];
    }
}
final class AtfThreadDb
{
    public function table_exists($table): bool { return true; }
    public function simple_select($table, $fields, $where)
    {
        return (object)['rows' => [['fieldid' => 4, 'value' => 'https://example.test']], 'index' => 0];
    }
    public function fetch_array($query)
    {
        return $query->rows[$query->index++] ?? false;
    }
}
final class AtfThreadTemplates
{
    public function get($name): string { return '<i>{$label}:{$valueHtml}</i>'; }
}

$cache = new AtfThreadCache();
$db = new AtfThreadDb();
$templates = new AtfThreadTemplates();
$mybb = (object)['settings' => []];

require AF_ADDONS . 'adaptivethemeframework/adaptivethemeframework.php';
require AF_ADDONS . 'advancedthreadfields/advancedthreadfields.php';

if (!af_atf_register_theme_provider()) throw new RuntimeException('Thread metadata provider was not registered.');
if (af_atf_register_theme_provider()) throw new RuntimeException('Duplicate thread metadata provider was accepted.');
$components = af_adaptivethemeframework_components_for_slot('thread.meta_chips');
if (count($components) !== 0) {
    throw new RuntimeException('Forumdisplay field provider must not be registered.');
}
if (count(af_adaptivethemeframework_components_for_slot('thread.atf_fields')) !== 1) {
    throw new RuntimeException('Showthread field provider must remain registered.');
}

$context = af_adaptivethemeframework_thread_card_context([
    'tid' => 11, 'subject' => 'Topic', 'lastposteruid' => 7, 'lastposter' => 'User',
], 3);
$slot = af_adaptivethemeframework_render_slot('thread.meta_chips', $context);
if ($slot !== '' || af_atf_render_forum_chips($context) !== '') {
    throw new RuntimeException('Forumdisplay field output must be disabled.');
}

$thread = ['tid' => 11];
$fid = 3;
af_atf_forumdisplay_thread();
if ($thread['af_atf_forum_chips'] !== '') {
    throw new RuntimeException('ATF mode duplicated chips through the legacy template variable.');
}

$GLOBALS['test_enabled']['advancedthreadfields'] = false;
if (af_adaptivethemeframework_render_slot('thread.meta_chips', $context) !== '') {
    throw new RuntimeException('Disabled metadata provider did not leave its slot empty.');
}
if (af_adaptivethemeframework_render_slot('thread.atf_fields', ['tid' => 11, 'fid' => 3]) !== '') {
    throw new RuntimeException('Disabled showthread fields provider did not leave its slot empty.');
}
$GLOBALS['test_enabled']['advancedthreadfields'] = true;
$GLOBALS['test_enabled']['adaptivethemeframework'] = false;
af_atf_forumdisplay_thread();
if ($thread['af_atf_forum_chips'] !== '') {
    throw new RuntimeException('Legacy forumdisplay must also omit field output.');
}

echo "AdvancedThreadFields ATF provider passed.\n";

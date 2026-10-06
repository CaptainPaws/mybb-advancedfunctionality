<?php
// Real server form renderer and template seed, without a MyBB installation.
define('IN_MYBB', true);
define('MYBB_ROOT', dirname(__DIR__, 2) . '/');
define('AF_ADDONS', MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/');
define('TABLE_PREFIX', 'mybb_');
function htmlspecialchars_uni($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$mybb = (object)['settings' => ['bburl' => '', 'af_atf_enabled' => 1]];
$cache = new class { public function read($name): array { return ['fields' => [], 'groups' => []]; } };
$db = new class {
    public function table_exists($table): bool { return false; }
};
$templates = new class {
    public function get($name): string {
        $seed = file_get_contents(MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/advancedthreadfields/templates/advancedthreadfields.html');
        preg_match_all('/<!-- TEMPLATE: ([^ ]+) -->\s*(.*?)(?=<!-- TEMPLATE:|\z)/s', $seed, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = str_starts_with($match[1], 'af_atf_') ? $match[1] : 'af_atf_' . $match[1];
            if ($key === $name) return str_replace(['\\', '"'], ['\\\\', '\\"'], trim($match[2]));
        }
        throw new RuntimeException('Missing template: ' . $name);
    }
};
require MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/advancedthreadfields/advancedthreadfields.php';
$fields = [];
foreach (['name', 'name_ru', 'nicknames', 'age', 'gender', 'activity', 'occupation', 'prototype', 'pic', 'image', 'weapon', 'weapon_type'] as $i => $name) {
    $fields[] = ['fieldid' => $i+1, 'name' => 'character_' . $name, 'title' => $i === 0 ? 'Длинное название поля персонажа с пояснением' : 'Поле ' . ($i+1), 'type' => $i % 2 ? 'text' : 'select', 'required' => 1, 'options' => "one=Первый\ntwo=Второй", 'maxlen' => 0];
}
foreach (['О персонаже', 'Описание способностей', 'Способности'] as $i => $title) {
    $fields[] = ['fieldid' => 20+$i, 'name' => 'section_' . $i, 'title' => $title, 'type' => $i === 2 ? 'character_abilities' : 'textarea', 'required' => 0, 'options' => '', 'maxlen' => 10000];
}
$values = [22 => json_encode([['title' => 'Способность 1'], ['title' => 'Способность 2']], JSON_UNESCAPED_UNICODE)];
$GLOBALS['af_atf_context_fields'] = $fields;
$GLOBALS['af_atf_context_values'] = $values;
$formHtml = '<table style="width:100%;table-layout:fixed"><tbody>' . af_atf_render_inputs($fields, $values) . '</tbody></table>';
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) echo $formHtml;

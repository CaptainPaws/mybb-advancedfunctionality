<?php
define('IN_MYBB', 1);
define('IN_ADMINCP', 1);
define('TABLE_PREFIX', 'mybb_');
define('TIME_NOW', 1700000000);

function htmlspecialchars_uni($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function verify_post_check($key) { if ($key !== 'token') throw new RuntimeException('Bad CSRF token'); }
function flash_message($message, $type) { $GLOBALS['flashes'][] = [$type, $message]; }
function admin_redirect($url) { throw new RedirectForTest($url); }
function my_date($format, $timestamp) { return (string)$timestamp; }

class RedirectForTest extends RuntimeException {}
class FakeResult { public $rows; public $position = 0; public function __construct(array $rows) { $this->rows = array_values($rows); } }
class FakeMyBB {
    public $input = [];
    public $request_method = 'get';
    public $post_code = 'token';
    public function get_input($key) { return $this->input[$key] ?? ''; }
}
class FakeDB {
    public $fields = [];
    public $values = [];
    private $nextId = 1;
    public function table_exists($table) { return true; }
    public function escape_string($value) { return (string)$value; }
    public function simple_select($table, $columns = '*', $where = '', $options = []) {
        $rows = $table === AF_WANTED_FIELDS ? array_values($this->fields) : array_values($this->values);
        if (preg_match('/field_key=\'([^\']*)\'/', $where, $match)) $rows = array_filter($rows, fn($r) => $r['field_key'] === $match[1]);
        if (preg_match('/\bid=(\d+)/', $where, $match)) $rows = array_filter($rows, fn($r) => (int)($r['id'] ?? 0) === (int)$match[1]);
        if (preg_match('/id!=(\d+)/', $where, $match)) $rows = array_filter($rows, fn($r) => (int)($r['id'] ?? 0) !== (int)$match[1]);
        if (preg_match('/field_id=(\d+)/', $where, $match)) $rows = array_filter($rows, fn($r) => (int)$r['field_id'] === (int)$match[1]);
        if ($where === 'active=1') $rows = array_filter($rows, fn($r) => !empty($r['active']));
        if (stripos($columns, 'COUNT(*)') !== false) return new FakeResult([['total' => count($rows)]]);
        usort($rows, fn($a, $b) => [$a['sortorder'] ?? 0, $a['id'] ?? 0] <=> [$b['sortorder'] ?? 0, $b['id'] ?? 0]);
        return new FakeResult($rows);
    }
    public function fetch_array($result) { return $result instanceof FakeResult && isset($result->rows[$result->position]) ? $result->rows[$result->position++] : false; }
    public function fetch_field($result, $field) { $row = $this->fetch_array($result); return $row[$field] ?? false; }
    public function insert_query($table, $data) { $id = $this->nextId++; $data['id'] = $id; $this->fields[$id] = $data; return $id; }
    public function update_query($table, $data, $where) { preg_match('/id=(\d+)/', $where, $m); $id = (int)($m[1] ?? 0); if (isset($this->fields[$id])) $this->fields[$id] = array_merge($this->fields[$id], $data); }
    public function delete_query($table, $where) { preg_match('/id=(\d+)/', $where, $m); unset($this->fields[(int)($m[1] ?? 0)]); }
    public function write_query($sql) { return new FakeResult([]); }
}

require_once dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/advancedwanted/advancedwanted.php';
require_once dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons/advancedwanted/admin.php';

$db = new FakeDB();
$mybb = new FakeMyBB();
$flashes = [];
$failures = [];
function check($condition, $label) { global $failures; echo ($condition ? 'PASS' : 'FAIL') . ': ' . $label . "\n"; if (!$condition) $failures[] = $label; }
function saveField(array $input) {
    global $mybb;
    $mybb->request_method = 'post';
    $mybb->input = array_merge(['tab' => 'fields', 'my_post_key' => 'token', 'do' => 'save_field', 'id' => 0, 'title' => '', 'field_key' => '', 'type' => 'text', 'sortorder' => 0], $input);
    try { AF_Admin_Advancedwanted::render(); } catch (RedirectForTest $redirect) { return true; }
    return false;
}

check(saveField(['title' => 'Имя', 'field_key' => 'NAME', 'type' => 'text', 'required' => 1, 'active' => 1, 'show_card' => 1, 'show_detail' => 1, 'sortorder' => 10]), 'ACP create persists first field');
check(saveField(['title' => 'Описание', 'field_key' => 'description', 'type' => 'textarea', 'active' => 1, 'show_detail' => 1, 'sortorder' => 20]), 'ACP create persists second field');
check(saveField(['title' => 'Изображение', 'field_key' => 'image', 'type' => 'image', 'active' => 1, 'sortorder' => 30]), 'ACP create persists image field');
check(count($db->fields) === 3 && $db->fields[1]['field_key'] === 'name', 'field keys are stored in canonical lowercase');
$settings = json_decode($db->fields[1]['settings_json'], true);
check($db->fields[1]['required'] == 1 && $settings['show_card'] === true && $settings['show_detail'] === true, 'all visibility and required settings survive storage');

$mybb->request_method = 'get';
$mybb->input = ['tab' => 'fields', 'edit' => 1];
$html = AF_Admin_Advancedwanted::render();
check(strpos($html, 'value="name"') !== false && strpos($html, 'name="show_card" value="1" checked') !== false, 'ACP reload/edit form contains persisted configuration');

check(saveField(['id' => 1, 'title' => 'Имя героя', 'field_key' => 'name', 'type' => 'text', 'active' => 0, 'required' => 1, 'show_detail' => 1, 'sortorder' => 40, 'source' => 'manual', 'depends_on' => 'description', 'options' => "a=А\nb=Б"]), 'ACP edit redirects after update');
$editedSettings = json_decode($db->fields[1]['settings_json'], true);
check($db->fields[1]['active'] == 0 && $db->fields[1]['sortorder'] == 40 && $editedSettings['source'] === 'manual' && $editedSettings['depends_on'] === 'description' && count($editedSettings['options']) === 2, 'edit preserves every submitted setting');

$mybb->request_method = 'post';
$mybb->input = ['tab' => 'fields', 'my_post_key' => 'token', 'do' => 'save_field', 'title' => 'Duplicate', 'field_key' => 'name', 'type' => 'text'];
$html = AF_Admin_Advancedwanted::render();
check(count($db->fields) === 3 && strpos($html, 'уже существует') !== false, 'duplicate key is rejected with a readable error');
$mybb->input['field_key'] = '---';
$html = AF_Admin_Advancedwanted::render();
check(strpos($html, 'Ключ может содержать') !== false, 'invalid field key is rejected instead of silently stripped');

$multi = ['id' => 99, 'field_key' => 'roles', 'title' => 'Роли', 'type' => 'multi', 'required' => 0, 'settings' => ['options' => ['hero' => 'Герой', 'villain' => 'Злодей']]];
[, $errors] = af_wanted_validate([$multi], ['roles' => ['hero', 'forged']]);
check(count($errors) === 1, 'multi rejects a forged POST option');
[, $errors] = af_wanted_validate([$multi], ['roles' => ['hero']]);
check(!$errors, 'multi accepts only configured option keys');
check(af_wanted_display_value($multi, '["hero","villain"]') === 'Герой, Злодей', 'multi values render with configured labels');
$checkbox = ['id' => 101, 'field_key' => 'urgent', 'title' => 'Срочно', 'type' => 'checkbox', 'required' => 0, 'settings' => []];
check(af_wanted_display_value($checkbox, '1') === 'Да', 'checkbox values have a readable detail/card label');
$image = ['id' => 100, 'field_key' => 'image', 'title' => 'Изображение', 'type' => 'image', 'required' => 0, 'settings' => []];
[, $errors] = af_wanted_validate([$image], ['image' => 'javascript:alert(1)']);
check(count($errors) === 1 && strpos(af_wanted_field_control($image, '', []), 'type="url"') !== false, 'image uses an HTTP(S) URL contract');
[, $errors] = af_wanted_validate([$image], ['image' => '']);
check(!$errors, 'an optional image may be empty');

$mybb->input = ['tab' => 'fields', 'my_post_key' => 'token', 'do' => 'delete_field', 'id' => 3];
try { AF_Admin_Advancedwanted::render(); } catch (RedirectForTest $redirect) {}
check(!isset($db->fields[3]), 'unused field is deleted');
$db->values[] = ['wanted_id' => 1, 'field_id' => 2, 'value' => 'used'];
$mybb->input['id'] = 2;
try { AF_Admin_Advancedwanted::render(); } catch (RedirectForTest $redirect) {}
check(isset($db->fields[2]) && str_contains($flashes[count($flashes) - 1][1], 'не может быть удалено'), 'used field deletion is blocked without orphaning values');

exit($failures ? 1 : 0);

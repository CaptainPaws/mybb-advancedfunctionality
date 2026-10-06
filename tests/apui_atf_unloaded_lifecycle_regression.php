<?php
// Optional addon root lets this fixture reproduce the failure on historical code.
define('IN_MYBB', true);
define('TABLE_PREFIX', 'mybb_');
define('TIME_NOW', 12345);
define('AF_ADDONS', ($argv[1] ?? dirname(__DIR__) . '/inc/plugins/advancedfunctionality/addons') . '/');
require __DIR__ . '/fixtures/atf_ownership_database.php';
require __DIR__ . '/fixtures/template_bundle.php';
$GLOBALS['framework_enabled'] = true;
function af_is_addon_enabled(string $id): bool { return $id === 'adaptivethemeframework' && $GLOBALS['framework_enabled']; }
function apui_check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
class ApuiDatabase extends AtfDatabase {
    public function __construct(bool $unloaded = false) {
        if (!$unloaded) parent::__construct();
        else {
            foreach (['member_profile', 'postbit_classic', 'showthread'] as $i => $name) {
                foreach ([1, 2] as $sid) {
                    $tid = 10 * $sid + $i;
                    $content = "ATF LIVE {$name} sid={$sid}";
                    $this->tables['templates'][] = ['tid'=>$tid, 'title'=>$name, 'sid'=>$sid, 'template'=>$content, 'dateline'=>20];
                    $this->tables['af_adaptivethemeframework_template_ownership'][] = [
                        'id'=>$tid, 'template_name'=>$name, 'template_sid'=>$sid, 'template_tid'=>$tid,
                        'atf_installed_checksum'=>hash('sha256', $content), 'ownership_state'=>'owned',
                        'previous_content'=>"ORIGINAL {$name} sid={$sid}",
                    ];
                }
            }
        }
        $this->tables['settings'] = [];
        $this->tables['settinggroups'] = [];
        // Stale APUI backups deliberately disagree with the authoritative ATF lease.
        $this->tables['af_apui_template_backups'] = [];
        foreach ($this->tables['templates'] as $row) {
            if ($row['sid'] <= 0 || !in_array($row['title'], ['member_profile', 'postbit_classic', 'showthread'], true)) continue;
            $this->tables['af_apui_template_backups'][] = ['id'=>$row['tid'], 'template_tid'=>$row['tid'], 'title'=>$row['title'], 'sid'=>$row['sid'], 'original_template'=>'STALE APUI BACKUP', 'original_dateline'=>1];
        }
    }
    public function insert_query($table, $row) {
        if ($table === 'settings') $row['sid'] = $this->nextId;
        if ($table === 'settinggroups') $row['gid'] = $this->nextId;
        return parent::insert_query($table, $row);
    }
    public function simple_select($table, $fields='*', $where='', $options=[]) {
        $result = parent::simple_select($table, $fields, $where, $options);
        if (str_contains($where, "sid != '-2'")) $result->rows = array_values(array_filter($result->rows, static fn($row) => (int)$row['sid'] !== -2));
        return $result;
    }
    public function insert_id() { return $this->nextId - 1; }
    public function fetch_field($result, $field) { return ($this->fetch_array($result) ?: [])[$field] ?? null; }
}

apui_check(!function_exists('af_adaptivethemeframework_template_seeds'), 'Fixture must start with ATF PHP unloaded');
$db = new ApuiDatabase(true);
$before = $db->tables['templates'];
$ledger = $db->tables['af_adaptivethemeframework_template_ownership'];
require AF_ADDONS . 'advancedprofileui/advancedprofileui.php';
foreach (['af_advancedprofileui_activate', 'af_advancedprofileui_activate', 'af_advancedprofileui_deactivate', 'af_advancedprofileui_activate'] as $callback) {
    $callback();
    apui_check($db->tables['templates'] === $before, "{$callback} changed ATF-owned template bytes/metadata");
    apui_check($db->tables['af_adaptivethemeframework_template_ownership'] === $ledger, "{$callback} changed the ATF ledger");
}
apui_check(!function_exists('af_adaptivethemeframework_activate'), 'APUI must not load the sibling runtime');
foreach ($db->tables['templates'] as &$row) $row['template'] .= ' MANUAL EDIT';
unset($row);
foreach ($db->tables['af_adaptivethemeframework_template_ownership'] as &$row) $row['ownership_state'] = 'manual_override';
unset($row);
$manual = $db->tables['templates'];
$manualLedger = $db->tables['af_adaptivethemeframework_template_ownership'];
af_advancedprofileui_activate();
af_advancedprofileui_deactivate();
apui_check($db->tables['templates'] === $manual && $db->tables['af_adaptivethemeframework_template_ownership'] === $manualLedger, 'Manual edits/conflicts must fail closed');

// Load real ATF only after exercising the critical unloaded-sibling case.
require AF_ADDONS . 'adaptivethemeframework/adaptivethemeframework.php';
$db = new ApuiDatabase();
$db->tables['af_apui_template_backups'] = [];
$GLOBALS['framework_enabled'] = false;
af_advancedprofileui_activate();
$standalone = $db->tables['templates'];
$GLOBALS['framework_enabled'] = true;
apui_check(af_adaptivethemeframework_activate(), 'ATF acquisition failed');
$owned = $db->tables['templates'];
$leases = $db->tables['af_adaptivethemeframework_template_ownership'];
af_advancedprofileui_deactivate();
af_advancedprofileui_activate();
apui_check($db->tables['templates'] === $owned && $db->tables['af_adaptivethemeframework_template_ownership'] === $leases, 'APUI lifecycle disturbed real ATF leases');
apui_check(af_adaptivethemeframework_deactivate(), 'ATF release failed');
$GLOBALS['framework_enabled'] = false;
apui_check($db->tables['templates'] === $standalone, 'ATF did not restore the exact standalone APUI rows');
af_advancedprofileui_activate();
apui_check($db->tables['templates'] === $standalone, 'Standalone APUI reactivation changed restored rows');
$GLOBALS['framework_enabled'] = true;
apui_check(af_adaptivethemeframework_activate(), 'ATF reacquisition failed');
af_advancedprofileui_activate();
foreach ($db->tables['af_adaptivethemeframework_template_ownership'] as $lease) apui_check($lease['ownership_state'] === 'owned', 'APUI created a false ownership conflict');
echo "APUI unloaded-sibling/reactivation/ATF handoff lifecycle passed.\n";

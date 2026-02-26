<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

const AF_BALANCE_TABLE = 'af_balance';
const AF_BALANCE_TX_TABLE = 'af_balance_tx';
const AF_BALANCE_POST_REWARDS_TABLE = 'af_post_rewards';
const AF_BALANCE_EXP_SCALE = 100;
const AF_BALANCE_CREDITS_SCALE = 100;
const AF_BALANCE_BLACKLIST_DEFAULT = "index.php\nusercp.php\nuserlist.php\nsearch.php\ngallery.php\nkb.php\nforumdisplay.php";
const AF_BALANCE_PAGE_ALIAS_SIGNATURE = 'AF_BALANCE_PAGE_ALIAS';
const AF_BALANCE_PAGE_ALIAS_FILE = 'balancemanage.php';

function af_balance_is_installed(): bool { global $db; return $db->table_exists(AF_BALANCE_TABLE); }
function af_balance_install(): void { af_balance_ensure_schema(); af_balance_ensure_settings(); af_balance_templates_install_or_update(); af_balance_ensure_page_alias(); if (function_exists('rebuild_settings')) rebuild_settings(); }
function af_balance_activate(): bool { af_balance_ensure_schema(); af_balance_ensure_settings(); af_balance_templates_install_or_update(); af_balance_ensure_page_alias(); af_balance_migrate_from_charactersheets(); af_balance_migrate_credits_scale(); af_balance_migrate_level_settings_from_charactersheets(); return true; }
function af_balance_deactivate(): bool { return true; }
function af_balance_uninstall(): void { af_balance_remove_page_alias_if_owned(); }

function af_balance_alias_asset_path(): string
{
    return __DIR__ . '/assets/' . AF_BALANCE_PAGE_ALIAS_FILE;
}

function af_balance_alias_root_path(): string
{
    return MYBB_ROOT . AF_BALANCE_PAGE_ALIAS_FILE;
}

function af_balance_is_our_alias_file(string $path): bool
{
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }

    $content = @file_get_contents($path);
    if ($content === false) {
        return false;
    }

    return strpos($content, AF_BALANCE_PAGE_ALIAS_SIGNATURE) !== false;
}

function af_balance_notice_alias_conflict(): void
{
    if (defined('IN_ADMINCP') && function_exists('flash_message')) {
        flash_message('Balance alias conflict: /balancemanage.php exists and is not owned by Advanced Functionality Balance addon.', 'error');
    }
}

function af_balance_ensure_page_alias(): void
{
    $assetPath = af_balance_alias_asset_path();
    $rootPath = af_balance_alias_root_path();

    if (!is_file($assetPath)) {
        return;
    }

    if (is_file($rootPath) && !af_balance_is_our_alias_file($rootPath)) {
        af_balance_notice_alias_conflict();
        return;
    }

    $copied = @copy($assetPath, $rootPath);
    if ($copied === false) {
        return;
    }

    @chmod($rootPath, 0644);
}

function af_balance_remove_page_alias_if_owned(): void
{
    $rootPath = af_balance_alias_root_path();
    if (!is_file($rootPath)) {
        return;
    }

    if (!af_balance_is_our_alias_file($rootPath)) {
        return;
    }

    @unlink($rootPath);
}

function af_balance_build_manage_url(array $params = []): string
{
    global $mybb;

    $base = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    $url = $base . '/' . AF_BALANCE_PAGE_ALIAS_FILE;

    if ($params) {
        $url .= '?' . http_build_query($params);
    }

    return $url;
}

function af_balance_init(): void
{
    global $plugins;

    $plugins->add_hook('member_do_register_end', 'af_balance_member_do_register_end');

    // INSERT: используем *_end — на этом этапе пост уже в БД и pid гарантированно доступен
    $plugins->add_hook('datahandler_post_insert_post_end', 'af_balance_datahandler_post_insert');
    $plugins->add_hook('datahandler_post_insert_thread_end', 'af_balance_datahandler_post_insert');

    // UPDATE: quick edit часто вызывает datahandler_post_update (без _end)
    $plugins->add_hook('datahandler_post_update', 'af_balance_datahandler_post_update');
    $plugins->add_hook('datahandler_post_update_end', 'af_balance_datahandler_post_update');

    // Safety net (на всякий случай)
    $plugins->add_hook('post_do_newpost_end', 'af_balance_post_do_newpost_end');

    $plugins->add_hook('xmlhttp', 'af_balance_xmlhttp');
    $plugins->add_hook('misc_start', 'af_balance_misc_start');
    $plugins->add_hook('pre_output_page', 'af_balance_pre_output_page', 10);
}

function af_balance_xmlhttp_init(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // Регистрируем все нужные хуки (datahandler_post_update* и т.д.)
    af_balance_init();
}

function af_balance_ensure_schema(): void
{
    global $db;
    if (!$db->table_exists(AF_BALANCE_TABLE)) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . AF_BALANCE_TABLE . " (
            uid INT UNSIGNED NOT NULL,
            exp BIGINT NOT NULL DEFAULT 0,
            credits BIGINT NOT NULL DEFAULT 0,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!$db->table_exists(AF_BALANCE_TX_TABLE)) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . AF_BALANCE_TX_TABLE . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uid INT UNSIGNED NOT NULL,
            kind ENUM('exp','credits') NOT NULL,
            amount BIGINT NOT NULL,
            balance_after BIGINT NOT NULL,
            reason VARCHAR(64) NOT NULL,
            source VARCHAR(64) NOT NULL,
            ref_type VARCHAR(32) NOT NULL DEFAULT '',
            ref_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            meta_json MEDIUMTEXT NULL,
            actor_uid INT UNSIGNED NOT NULL DEFAULT 0,
            created_at INT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            KEY uid_kind (uid, kind),
            KEY created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!$db->table_exists(AF_BALANCE_POST_REWARDS_TABLE)) {
        $db->write_query("CREATE TABLE " . TABLE_PREFIX . AF_BALANCE_POST_REWARDS_TABLE . " (
            pid INT UNSIGNED NOT NULL,
            uid INT UNSIGNED NOT NULL,
            fid INT UNSIGNED NOT NULL,
            chars_count INT NOT NULL DEFAULT 0,
            exp_scaled BIGINT NOT NULL DEFAULT 0,
            credits_scaled BIGINT NOT NULL DEFAULT 0,
            last_hash CHAR(40) NULL,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (pid),
            KEY uid (uid),
            KEY fid (fid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        // Таблица уже есть (старый формат) — делаем миграцию колонок безопасно
        $table = TABLE_PREFIX . AF_BALANCE_POST_REWARDS_TABLE;

        $cols = [];
        $q = $db->write_query("SHOW COLUMNS FROM {$table}");
        while ($r = $db->fetch_array($q)) {
            $cols[strtolower((string)$r['Field'])] = true;
        }

        if (empty($cols['exp_scaled'])) {
            $db->write_query("ALTER TABLE {$table} ADD COLUMN exp_scaled BIGINT NOT NULL DEFAULT 0");
        }
        if (empty($cols['credits_scaled'])) {
            $db->write_query("ALTER TABLE {$table} ADD COLUMN credits_scaled BIGINT NOT NULL DEFAULT 0");
        }
        if (empty($cols['last_hash'])) {
            $db->write_query("ALTER TABLE {$table} ADD COLUMN last_hash CHAR(40) NULL");
        }
        if (empty($cols['updated_at'])) {
            $db->write_query("ALTER TABLE {$table} ADD COLUMN updated_at INT UNSIGNED NOT NULL DEFAULT 0");
        }
        if (empty($cols['chars_count'])) {
            $db->write_query("ALTER TABLE {$table} ADD COLUMN chars_count INT NOT NULL DEFAULT 0");
        }

        // Если вдруг у старой схемы были reward_exp/reward_credits — можно оставить, не мешает.
    }   
}

function af_balance_templates_install_or_update(): void
{
    global $db;

    $tplDir = __DIR__ . '/templates';
    if (!is_dir($tplDir)) {
        return;
    }

    $map = [
        'postbit_balance' => ['af_balance_postbit', 'af_balance_postbit_plaque'],
    ];

    foreach ($map as $basename => $titles) {
        $path = $tplDir . '/' . $basename . '.html';
        if (!is_file($path)) {
            continue;
        }

        $tpl = @file_get_contents($path);
        if ($tpl === false) {
            continue;
        }

        foreach ($titles as $titleRaw) {
            $title = $db->escape_string($titleRaw);
            $existing = $db->simple_select('templates', 'tid', "title='{$title}' AND sid='-1'", ['limit' => 1]);
            $tid = (int)$db->fetch_field($existing, 'tid');

            $row = [
                'title'    => $title,
                'template' => $db->escape_string($tpl),
                'sid'      => -1,
                'version'  => '1839',
                'dateline' => TIME_NOW,
            ];

            if ($tid) {
                $db->update_query('templates', $row, "tid='{$tid}'");
            } else {
                $db->insert_query('templates', $row);
            }
        }
    }
}

function af_balance_get_postbit_data(int $uid): array
{
    global $mybb;

    $uid = (int)$uid;
    if ($uid <= 0) {
        return [
            'credits_display' => '0.00',
            'currency_symbol' => (string)($mybb->settings['af_balance_currency_symbol'] ?? '¢'),
            'level' => 1,
            'progress_percent' => 0,
            'percent' => 0,
            'exp_current' => 0,
            'exp_need' => 0,
            'exp_display' => '0',
            'exp_need_display' => '0',
        ];
    }

    $bal = af_balance_get($uid);
    $expScaled = (int)($bal['exp'] ?? 0);

    $levelData = af_balance_compute_level($expScaled / AF_BALANCE_EXP_SCALE);
    $expCurrent = (int)($levelData['exp_current'] ?? 0);
    $expNeed = (int)($levelData['exp_need'] ?? 0);

    return [
        'credits_display' => af_balance_format_credits((int)($bal['credits'] ?? 0)),
        'currency_symbol' => (string)($mybb->settings['af_balance_currency_symbol'] ?? '¢'),
        'level' => (int)($levelData['level'] ?? 1),
        'progress_percent' => max(0, min(100, (int)($levelData['progress_percent'] ?? 0))),
        'percent' => max(0, min(100, (int)($levelData['progress_percent'] ?? 0))),
        'exp_current' => $expCurrent,
        'exp_need' => $expNeed,
        'exp_display' => number_format((float)$expCurrent, 0, '.', ' '),
        'exp_need_display' => number_format((float)$expNeed, 0, '.', ' '),
    ];
}

function af_balance_level_settings(): array
{
    global $mybb;

    $cap = (int)($mybb->settings['af_balance_level_cap'] ?? 60);
    $base = (int)($mybb->settings['af_balance_level_req_base'] ?? 2000);
    $step = (int)($mybb->settings['af_balance_level_req_step'] ?? 1000);

    if ($cap < 1) {
        $cap = 1;
    }
    if ($base < 0) {
        $base = 0;
    }
    if ($step < 0) {
        $step = 0;
    }

    return [
        'cap' => $cap,
        'base' => $base,
        'step' => $step,
    ];
}

function af_balance_compute_level(float $exp): array
{
    $settings = af_balance_level_settings();
    $cap = (int)$settings['cap'];
    $base = (int)$settings['base'];
    $step = (int)$settings['step'];

    $expCurrent = (int)floor(max(0, $exp));
    $level = 1;

    $reqForLevel = static function (int $targetLevel) use ($base, $step): int {
        if ($targetLevel <= 1) {
            return 0;
        }

        $need = $base + (($targetLevel - 2) * $step);

        return (int)max(0, $need);
    };

    while ($level < $cap && $expCurrent >= $reqForLevel($level + 1)) {
        $level++;
    }

    $nextLevel = min($level + 1, $cap);
    $needForNext = $reqForLevel($nextLevel);

    $progressPercent = 100;
    if ($needForNext > 0) {
        $progressPercent = (int)floor(($expCurrent / $needForNext) * 100);
        if ($progressPercent < 0) {
            $progressPercent = 0;
        } elseif ($progressPercent > 100) {
            $progressPercent = 100;
        }
    }

    return [
        'level' => $level,
        'cap' => $cap,
        'exp_current' => $expCurrent,
        'exp_need' => $needForNext,
        'progress_percent' => $progressPercent,
        // compatibility aliases
        'percent' => $progressPercent,
        'exp_in_level' => $expCurrent,
    ];
}

function af_balance_ensure_settings(): void
{
    global $db;
    $needsRebuild = false;
    $gid = (int)$db->fetch_field($db->simple_select('settinggroups', 'gid', "name='af_balance'", ['limit' => 1]), 'gid');
    if ($gid <= 0) {
        $gid = (int)$db->insert_query('settinggroups', [
            'name' => 'af_balance', 'title' => 'AF: Balance', 'description' => 'EXP and Credits settings', 'disporder' => 60, 'isdefault' => 0,
        ]);
    }
    $defs = [
        ['af_balance_exp_enabled','EXP enabled','yesno','1',1],['af_balance_exp_per_char','EXP per char','text','0.02',2],['af_balance_exp_on_register','EXP on register','text','0',3],['af_balance_exp_on_accept','EXP on accept','text','0',4],
        ['af_balance_exp_categories_csv','EXP categories CSV','text','',5],['af_balance_exp_forums_csv','EXP forums CSV','text','',6],['af_balance_exp_mode','EXP mode',"select\ninclude=include\nexclude=exclude",'include',7],
        ['af_balance_exp_allow_negative_award','EXP allow negative award','yesno','0',8],['af_balance_exp_allow_balance_negative','EXP allow negative balance','yesno','0',9],['af_balance_exp_manual_groups','EXP manual groups','text','4',10],
        ['af_balance_credits_enabled','Credits enabled','yesno','1',20],['af_balance_credits_per_char','Credits per char','text','0',21],['af_balance_credits_on_register','Credits on register','text','0',22],['af_balance_credits_on_accept','Credits on accept','text','0',23],
        ['af_balance_credits_categories_csv','Credits categories CSV','text','',24],['af_balance_credits_forums_csv','Credits forums CSV','text','',25],['af_balance_credits_mode','Credits mode',"select\ninclude=include\nexclude=exclude",'include',26],
        ['af_balance_credits_allow_negative_award','Credits allow negative award','yesno','0',27],['af_balance_credits_allow_balance_negative','Credits allow negative balance','yesno','0',28],['af_balance_credits_manual_groups','Credits manual groups','text','4',29],
        ['af_balance_tx_keep_limit','TX keep limit','text','5000',30],['af_balance_tx_enable','Enable tx log','yesno','1',31],
        ['af_balance_credits_scale_migrated','Credits scale migrated','yesno','0',32],
        ['af_balance_currency_symbol','Currency symbol','text','¢',33],
        ['af_balance_manage_groups','Balance manage groups','text','3,4',34],
        ['af_balance_blacklist','Balance assets blacklist','textarea',AF_BALANCE_BLACKLIST_DEFAULT,35],
        ['af_balance_debug_hooks','Debug post hooks','yesno','0',36],
        ['af_balance_level_cap','Level cap','text','60',37],
        ['af_balance_level_req_base','Level requirement base','text','2000',38],
        ['af_balance_level_req_step','Level requirement step','text','1000',39],
    ];
    $legacyMigratedSid = af_balance_pick_setting_sid('af_balance_migrated_credits_scale', $needsRebuild);
    if ($legacyMigratedSid > 0) {
        $legacyMigratedValue = $db->fetch_field($db->simple_select('settings', 'value', 'sid=' . $legacyMigratedSid, ['limit' => 1]), 'value');
        $targetSid = af_balance_pick_setting_sid('af_balance_credits_scale_migrated', $needsRebuild);
        if ($targetSid > 0) {
            if ($legacyMigratedValue !== null && $legacyMigratedValue !== '') {
                $db->update_query('settings', ['value' => $legacyMigratedValue], 'sid=' . $targetSid);
            }
            $db->delete_query('settings', 'sid=' . $legacyMigratedSid);
            $needsRebuild = true;
        } else {
            $db->update_query('settings', ['name' => 'af_balance_credits_scale_migrated'], 'sid=' . $legacyMigratedSid);
            $needsRebuild = true;
        }
    }

    foreach ($defs as [$name,$title,$opt,$val,$order]) {
        $sid = af_balance_pick_setting_sid($name, $needsRebuild);
        $row = ['name'=>$name,'title'=>$title,'description'=>$title,'optionscode'=>$opt,'disporder'=>$order,'gid'=>$gid,'isdefault'=>0];
        if ($sid > 0) {
            $db->update_query('settings',$row,"sid=".$sid);
        } else {
            $row['value'] = $val;
            $db->insert_query('settings',$row);
            $needsRebuild = true;
        }
    }

    if ($needsRebuild && function_exists('rebuild_settings')) {
        rebuild_settings();
    }

    af_balance_migrate_level_settings_from_charactersheets();
}

function af_balance_migrate_level_settings_from_charactersheets(): void
{
    global $db;

    $pairs = [
        'af_charactersheets_level_cap' => 'af_balance_level_cap',
        'af_charactersheets_level_req_base' => 'af_balance_level_req_base',
        'af_charactersheets_level_req_step' => 'af_balance_level_req_step',
    ];

    $defaults = [
        'af_balance_level_cap' => '60',
        'af_balance_level_req_base' => '2000',
        'af_balance_level_req_step' => '1000',
    ];

    $changed = false;
    foreach ($pairs as $oldKey => $newKey) {
        $oldSid = af_balance_pick_setting_sid($oldKey, $changed);
        $newSid = af_balance_pick_setting_sid($newKey, $changed);
        if ($oldSid <= 0 || $newSid <= 0) {
            continue;
        }

        $oldVal = (string)$db->fetch_field($db->simple_select('settings', 'value', 'sid=' . $oldSid, ['limit' => 1]), 'value');
        $newVal = (string)$db->fetch_field($db->simple_select('settings', 'value', 'sid=' . $newSid, ['limit' => 1]), 'value');
        $default = (string)($defaults[$newKey] ?? '');

        if ($oldVal === '') {
            continue;
        }

        if ($newVal === '' || $newVal === $default) {
            $db->update_query('settings', ['value' => $oldVal], 'sid=' . $newSid);
            $changed = true;
        }
    }

    if ($changed && function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_balance_pick_setting_sid(string $name, bool &$deduped = false): int
{
    global $db;

    $escaped = $db->escape_string($name);
    $q = $db->simple_select('settings', 'sid', "name='" . $escaped . "'", ['order_by' => 'sid', 'order_dir' => 'ASC']);

    $keepSid = 0;
    while ($row = $db->fetch_array($q)) {
        $sid = (int)($row['sid'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        if ($keepSid === 0) {
            $keepSid = $sid;
            continue;
        }
        $db->delete_query('settings', 'sid=' . $sid);
        $deduped = true;
    }

    return $keepSid;
}

function af_balance_parse_blacklist(string $raw): array
{
    $rules = [];
    $lines = preg_split('~\R~', $raw);
    if (!is_array($lines)) {
        return $rules;
    }

    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }

        $script = strtolower(basename(str_replace('\\', '/', strtok($line, '?') ?: '')));
        if ($script === '') {
            continue;
        }

        $rules[$script] = true;
    }

    return $rules;
}

function af_balance_assets_disabled_for_current_page(): bool
{
    global $mybb;

    $script = defined('THIS_SCRIPT') ? strtolower((string)THIS_SCRIPT) : '';
    $script = strtolower(basename(str_replace('\\', '/', $script)));
    if ($script === '') {
        return false;
    }

    if (function_exists('af_is_blacklisted') && af_is_blacklisted('balance', $script)) {
        return true;
    }

    $raw = trim((string)($mybb->settings['af_balance_blacklist'] ?? ''));
    if ($raw === '') {
        $raw = AF_BALANCE_BLACKLIST_DEFAULT;
    }
    $rules = af_balance_parse_blacklist($raw);

    return !empty($rules[$script]);
}

function af_balance_enqueue_assets(): void
{
    global $mybb;

    if (af_balance_assets_disabled_for_current_page()) {
        return;
    }

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    if ($bburl === '') {
        return;
    }

    $base = $bburl . '/inc/plugins/advancedfunctionality/addons/balance/assets';
    if (function_exists('af_add_css_once')) {
        af_add_css_once($base . '/balance.css');
    }
    if (function_exists('af_add_js_once')) {
        af_add_js_once($base . '/balance.js');
    }
}

function af_balance_pre_output_page(string &$page): void
{
    if (!af_balance_assets_disabled_for_current_page()) {
        return;
    }

    $page = preg_replace(
        '~<link\b[^>]*href=("|\')[^"\']*?/inc/plugins/advancedfunctionality/addons/balance/assets/[^"\']+\.css(?:\?[^"\']*)?\1[^>]*>\s*~i',
        '',
        $page
    );
    $page = preg_replace(
        '~<script\b[^>]*src=("|\')[^"\']*?/inc/plugins/advancedfunctionality/addons/balance/assets/[^"\']+\.js(?:\?[^"\']*)?\1[^>]*>\s*</script>\s*~i',
        '',
        $page
    );
}

function af_balance_get(int $uid): array
{
    global $db;
    if ($uid <= 0) return ['uid'=>0,'exp'=>0,'credits'=>0];
    $row = $db->fetch_array($db->simple_select(AF_BALANCE_TABLE, '*', 'uid='.(int)$uid, ['limit'=>1]));
    if (!$row) {
        $db->insert_query(AF_BALANCE_TABLE, ['uid'=>$uid, 'exp'=>0, 'credits'=>0, 'updated_at'=>TIME_NOW]);
        return ['uid'=>$uid,'exp'=>0,'credits'=>0];
    }
    return ['uid'=>$uid,'exp'=>(int)$row['exp'],'credits'=>(int)$row['credits']];
}

function af_balance_add($uid, string $kind, $amount, array $meta = []): array
{
    global $mybb;

    $uid = (int)$uid;
    if ($uid <= 0 || !in_array($kind, ['exp','credits'], true)) {
        return af_balance_get($uid);
    }

    $scale = ($kind === 'exp') ? AF_BALANCE_EXP_SCALE : AF_BALANCE_CREDITS_SCALE;
    $scaled = (int)floor(((float)$amount) * $scale);

    // если это отрицательное начисление и запрещено — отклоняем (обычное начисление)
    if ($scaled < 0 && empty($mybb->settings['af_balance_'.$kind.'_allow_negative_award'])) {
        return af_balance_get($uid);
    }

    return af_balance_apply_scaled_delta($uid, $kind, $scaled, $meta, false);
}

function af_balance_apply_scaled_delta(int $uid, string $kind, int $scaled, array $meta = [], bool $bypassNegativeAwardRule = false): array
{
    global $db, $mybb;

    $uid = (int)$uid;
    if ($uid <= 0 || !in_array($kind, ['exp','credits'], true)) {
        return af_balance_get($uid);
    }

    // если это отрицательная дельта и правило запрещает, то пропускаем (кроме bypass)
    if ($scaled < 0 && empty($mybb->settings['af_balance_'.$kind.'_allow_negative_award']) && !$bypassNegativeAwardRule) {
        return af_balance_get($uid);
    }

    $bal = af_balance_get($uid);
    $old = (int)$bal[$kind];
    $new = $old + $scaled;

    // баланс в минус — только если allow_balance_negative=1
    if ($new < 0 && empty($mybb->settings['af_balance_'.$kind.'_allow_balance_negative'])) {
        $new = 0;
    }

    $db->update_query(AF_BALANCE_TABLE, [$kind => $new, 'updated_at' => TIME_NOW], 'uid=' . $uid);

    if (!empty($mybb->settings['af_balance_tx_enable']) && $db->table_exists(AF_BALANCE_TX_TABLE)) {
        $reason = substr((string)($meta['reason'] ?? ''), 0, 64);
        $source = substr((string)($meta['source'] ?? 'balance'), 0, 64);
        $refType = substr((string)($meta['ref_type'] ?? ''), 0, 32);

        $db->insert_query(AF_BALANCE_TX_TABLE, [
            'uid' => $uid,
            'kind' => $db->escape_string($kind),
            'amount' => ($new - $old),
            'balance_after' => $new,
            'reason' => $db->escape_string($reason),
            'source' => $db->escape_string($source),
            'ref_type' => $db->escape_string($refType),
            'ref_id' => (int)($meta['ref_id'] ?? 0),
            'meta_json' => $db->escape_string(json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
            'actor_uid' => (int)($meta['actor_uid'] ?? 0),
            'created_at' => TIME_NOW,
        ]);

        af_balance_trim_tx();
    }

    if ($kind === 'exp' && function_exists('af_charactersheets_on_exp_changed')) {
        af_charactersheets_on_exp_changed($uid, $old, $new, $meta);
    }

    return af_balance_get($uid);
}

function af_balance_add_exp($uid, $amount, array $meta = []): array { return af_balance_add((int)$uid, 'exp', $amount, $meta); }
function af_balance_add_credits($uid, $amount, array $meta = []): array { return af_balance_add((int)$uid, 'credits', $amount, $meta); }

function af_balance_can_manual_adjust($uid, string $kind): bool
{
    global $mybb;
    $kind = $kind === 'credits' ? 'credits' : 'exp';
    $csv = (string)($mybb->settings['af_balance_'.$kind.'_manual_groups'] ?? '');
    $allowed = af_balance_csv_to_ids($csv);
    if (!$allowed) return false;
    $groups = [(int)($mybb->user['usergroup'] ?? 0)];
    foreach (explode(',', (string)($mybb->user['additionalgroups'] ?? '')) as $g) { $groups[] = (int)$g; }
    return (bool)array_intersect(array_filter($groups), $allowed);
}

function af_balance_member_do_register_end(): void
{
    global $mybb, $user_info;
    $uid = (int)($user_info['uid'] ?? 0);
    if ($uid <= 0) {
        $uid = (int)($mybb->input['regid'] ?? 0);
    }
    if ($uid <= 0) return;
    af_balance_apply_register_awards($uid);
}

function af_balance_datahandler_user_insert($userhandler): void
{
    if (!is_object($userhandler)) {
        return;
    }

    $uid = (int)($userhandler->uid ?? 0);
    if ($uid <= 0) {
        $uid = (int)($userhandler->data['uid'] ?? 0);
    }
    if ($uid <= 0) {
        return;
    }

    af_balance_apply_register_awards($uid);
}

function af_balance_count_award_chars(string $message): int
{
    $s = (string)$message;

    // вырежем quote-блоки целиком (в анкетах часто тонна цитат/вставок)
    $s = preg_replace('~\[(quote|code)(?:=[^\]]+)?\].*?\[/\1\]~si', '', $s);

    // вырежем основные mycode теги, оставив их содержимое
    $s = preg_replace('~\[(\/?)(b|i|u|s|center|left|right|size|color|font|url|img|table|tr|td|th|tbody|thead|list|\*|spoiler|hide|mention)(?:=[^\]]+)?\]~i', '', $s);

    // уберём остаточные [xxx] теги
    $s = preg_replace('~\[[^\]]+\]~', '', $s);

    // HTML теги тоже уберём
    $s = strip_tags($s);

    // нормализуем пробелы
    $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('~\s+~u', ' ', $s);
    $s = trim($s);

    if ($s === '') return 0;

    return function_exists('my_strlen') ? my_strlen($s) : strlen($s);
}

function af_balance_apply_register_awards(int $uid): void
{
    global $mybb;

    static $done = [];
    if ($uid <= 0) return;
    if (!empty($done[$uid])) return; // защита от повторного вызова в одном запросе
    $done[$uid] = 1;

    if (!empty($mybb->settings['af_balance_exp_enabled'])) {
        $amt = (float)($mybb->settings['af_balance_exp_on_register'] ?? 0);
        if ($amt != 0.0) {
            af_balance_add_exp($uid, $amt, ['reason'=>'register','source'=>'balance','ref_type'=>'uid','ref_id'=>$uid]);
        }
    }

    if (!empty($mybb->settings['af_balance_credits_enabled'])) {
        $amt = (float)($mybb->settings['af_balance_credits_on_register'] ?? 0);
        if ($amt != 0.0) {
            af_balance_add_credits($uid, $amt, ['reason'=>'register','source'=>'balance','ref_type'=>'uid','ref_id'=>$uid]);
        }
    }
}

function af_balance_datahandler_post_insert($posthandler): void
{
    global $db;

    if (!is_object($posthandler)) {
        return;
    }

    $insert = is_array($posthandler->post_insert_data ?? null) ? $posthandler->post_insert_data : [];
    $data   = is_array($posthandler->data ?? null) ? $posthandler->data : [];

    // На *_end pid должен быть, но делаем железобетон
    $pid = (int)($posthandler->pid ?? 0);
    if ($pid <= 0) {
        $pid = (int)($insert['pid'] ?? $data['pid'] ?? 0);
    }

    // Для insert_thread_end иногда удобнее восстановиться через firstpost
    if ($pid <= 0) {
        $tid = (int)($posthandler->tid ?? $insert['tid'] ?? $data['tid'] ?? 0);
        if ($tid > 0) {
            $pid = (int)$db->fetch_field(
                $db->simple_select('threads', 'firstpost', 'tid=' . (int)$tid, ['limit' => 1]),
                'firstpost'
            );
        }
    }

    if ($pid <= 0) {
        af_balance_debug_log('insert_end:pid_not_resolved', [
            'handler_pid' => (int)($posthandler->pid ?? 0),
            'insert_pid'  => (int)($insert['pid'] ?? 0),
            'data_pid'    => (int)($data['pid'] ?? 0),
            'tid'         => (int)($posthandler->tid ?? $insert['tid'] ?? $data['tid'] ?? 0),
        ]);
        return;
    }

    // Берём фактические данные из posts (самый надёжный источник на *_end)
    $post = $db->fetch_array($db->simple_select(
        'posts',
        'pid,uid,fid,visible,message',
        'pid=' . (int)$pid,
        ['limit' => 1]
    ));

    if (!is_array($post)) {
        af_balance_debug_log('insert_end:post_not_found', ['pid' => $pid]);
        return;
    }

    af_balance_recalc_post_awards(
        (int)($post['pid'] ?? 0),
        (int)($post['uid'] ?? 0),
        (int)($post['fid'] ?? 0),
        (int)($post['visible'] ?? 0),
        (string)($post['message'] ?? ''),
        'insert_end'
    );
}

function af_balance_datahandler_post_update($posthandler): void
{
    global $db, $mybb;

    if (!is_object($posthandler)) {
        return;
    }

    $update = is_array($posthandler->post_update_data ?? null) ? $posthandler->post_update_data : [];
    $data   = is_array($posthandler->data ?? null) ? $posthandler->data : [];

    $pid = (int)($posthandler->pid ?? $update['pid'] ?? $data['pid'] ?? 0);
    if ($pid <= 0) {
        af_balance_debug_log('update:pid_not_resolved', [
            'handler_pid' => (int)($posthandler->pid ?? 0),
            'script' => defined('THIS_SCRIPT') ? (string)THIS_SCRIPT : '',
        ]);
        return;
    }

    // КРИТИЧНО: для quick edit берём новый текст из datahandler (в БД он может быть ещё старым)
    $message = (string)($update['message'] ?? $data['message'] ?? '');

    $uid     = (int)($update['uid'] ?? $data['uid'] ?? 0);
    $fid     = (int)($update['fid'] ?? $data['fid'] ?? 0);
    $visible = (int)($update['visible'] ?? $data['visible'] ?? 1);

    // Если не хватает uid/fid/visible — добираем из БД (это ок даже в quick edit)
    if ($uid <= 0 || $fid <= 0) {
        $row = $db->fetch_array($db->simple_select('posts', 'pid,uid,fid,visible,message', 'pid=' . (int)$pid, ['limit' => 1]));
        if (is_array($row)) {
            if ($uid <= 0) $uid = (int)($row['uid'] ?? 0);
            if ($fid <= 0) $fid = (int)($row['fid'] ?? 0);
            $visible = (int)($row['visible'] ?? $visible);

            // message добираем из БД только если datahandler его не дал
            if ($message === '') {
                $message = (string)($row['message'] ?? '');
            }
        }
    }

    if ($uid <= 0 || $fid <= 0 || $message === '') {
        af_balance_debug_log('update:missing_data', [
            'pid' => $pid,
            'uid' => $uid,
            'fid' => $fid,
            'has_message' => ($message !== '') ? 1 : 0,
            'script' => defined('THIS_SCRIPT') ? (string)THIS_SCRIPT : '',
        ]);
        return;
    }

    // Источник (для дебага/мета)
    $source = 'update';
    $script = defined('THIS_SCRIPT') ? strtolower((string)THIS_SCRIPT) : '';
    if ($script === 'xmlhttp.php') {
        $action = (string)($mybb->input['action'] ?? '');
        $do     = (string)($mybb->input['do'] ?? '');
        if ($action === 'edit_post' && ($do === 'update_post' || $do === 'update')) {
            $source = 'quick_edit';
        } else {
            $source = 'xmlhttp';
        }
    } elseif ($script === 'editpost.php') {
        $source = 'editpost';
    }

    af_balance_recalc_post_awards($pid, $uid, $fid, $visible, $message, $source);
}

function af_balance_xmlhttp(): void
{
    global $mybb, $db;

    $action = (string)($mybb->input['action'] ?? '');
    $do     = (string)($mybb->input['do'] ?? '');

    // ---- API: получить свежие цифры баланса для поста (после quick edit)
    if ($action === 'af_balance_snapshot') {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $pid = (int)($mybb->input['pid'] ?? 0);
        if ($pid <= 0) {
            echo json_encode(['success' => false, 'error' => 'Bad pid'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $row = $db->fetch_array($db->simple_select('posts', 'pid,uid', 'pid=' . (int)$pid, ['limit' => 1]));
        $uid = (int)($row['uid'] ?? 0);
        if ($uid <= 0) {
            echo json_encode(['success' => false, 'error' => 'Post not found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $bal = af_balance_get($uid);
        $data = af_balance_get_postbit_data($uid);

        echo json_encode([
            'success' => true,
            'pid' => $pid,
            'uid' => $uid,
            'exp_scaled' => (int)($bal['exp'] ?? 0),
            'credits_scaled' => (int)($bal['credits'] ?? 0),
            'exp_current' => (int)($data['exp_current'] ?? 0),
            'exp_need' => (int)($data['exp_need'] ?? 0),
            'exp_display' => (string)($data['exp_display'] ?? '0'),
            'exp_need_display' => (string)($data['exp_need_display'] ?? '0'),
            'credits_display' => (string)($data['credits_display'] ?? '0.00'),
            'currency_symbol' => (string)($data['currency_symbol'] ?? '¢'),
            'level' => (int)($data['level'] ?? 1),
            'progress_percent' => (int)($data['progress_percent'] ?? 0),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ---- Quick edit detection (safety-net)
    if ($action === 'edit_post' && ($do === 'update_post' || $do === 'update')) {
        $pid = (int)($mybb->input['pid'] ?? $mybb->input['post_id'] ?? 0);
        if ($pid > 0) {
            af_balance_schedule_post_recalc_after_request($pid, 'quick_edit');
        }
        return;
    }
}

function af_balance_post_do_newpost_end(): void
{
    global $mybb, $db, $posthandler;

    $resolved_pid = 0;

    // 1) Самый надёжный вариант — posthandler
    if (isset($posthandler) && is_object($posthandler)) {
        $resolved_pid = (int)($posthandler->pid ?? 0);
    }

    // 2) Fallback: глобальные переменные, которые MyBB часто использует
    if ($resolved_pid <= 0) {
        $resolved_pid = (int)($GLOBALS['newpid'] ?? 0);
    }
    if ($resolved_pid <= 0) {
        $resolved_pid = (int)($GLOBALS['pid'] ?? 0);
    }

    // 3) Fallback: input (на некоторых путях может быть)
    if ($resolved_pid <= 0) {
        $resolved_pid = (int)($mybb->input['pid'] ?? $mybb->input['newpid'] ?? 0);
    }

    if ($resolved_pid <= 0) {
        return;
    }

    $post = $db->fetch_array($db->simple_select(
        'posts',
        'pid,uid,fid,visible,message',
        'pid=' . (int)$resolved_pid,
        ['limit' => 1]
    ));

    if (!is_array($post)) {
        return;
    }

    af_balance_recalc_post_awards(
        (int)($post['pid'] ?? 0),
        (int)($post['uid'] ?? 0),
        (int)($post['fid'] ?? 0),
        (int)($post['visible'] ?? 0),
        (string)($post['message'] ?? ''),
        'post_do_newpost_end'
    );
}

function af_balance_schedule_post_recalc_after_request(int $pid, string $source): void
{
    static $scheduled = [];

    if ($pid <= 0) {
        return;
    }

    $key = $pid . ':' . $source;
    if (isset($scheduled[$key])) {
        return;
    }
    $scheduled[$key] = true;

    register_shutdown_function(static function () use ($pid, $source): void {
        global $db;

        $post = $db->fetch_array($db->simple_select('posts', 'pid,uid,fid,visible,message', 'pid=' . (int)$pid, ['limit' => 1]));
        if (!is_array($post)) {
            af_balance_debug_log('shutdown_recalc:post_not_found', ['pid' => $pid, 'source' => $source]);
            return;
        }

        af_balance_recalc_post_awards(
            (int)($post['pid'] ?? 0),
            (int)($post['uid'] ?? 0),
            (int)($post['fid'] ?? 0),
            (int)($post['visible'] ?? 0),
            (string)($post['message'] ?? ''),
            $source
        );
        af_balance_debug_log('shutdown_recalc:done', ['pid' => $pid, 'source' => $source]);
    });
}

function af_balance_debug_log(string $message, array $context = []): void
{
    global $mybb;

    if (empty($mybb->settings['af_balance_debug_hooks'])) {
        return;
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if (!empty($context)) {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json) && $json !== '') {
            $line .= ' ' . $json;
        }
    }
    $line .= "\n";

    $primaryDir = MYBB_ROOT . 'inc/plugins/advancedfunctionality/cache/';
    $primaryFile = $primaryDir . 'af_balance_hooks.log';

    $fallbackDir = MYBB_ROOT . 'cache/';
    $fallbackFile = $fallbackDir . 'af_balance_hooks.log';

    // попытка создать директории, если их нет
    if (!is_dir($primaryDir)) {
        @mkdir($primaryDir, 0755, true);
    }
    if (!is_dir($fallbackDir)) {
        @mkdir($fallbackDir, 0755, true);
    }

    // пишем туда, где можно
    if (is_dir($primaryDir) && is_writable($primaryDir)) {
        @file_put_contents($primaryFile, $line, FILE_APPEND);
        return;
    }

    if (is_dir($fallbackDir) && is_writable($fallbackDir)) {
        @file_put_contents($fallbackFile, $line, FILE_APPEND);
        return;
    }

    // если вообще никуда — молча (чтобы не ломать вывод)
}

function af_balance_already_awarded(int $pid, int $uid, int $fid, int $chars): bool
{
    static $seen = [];

    // Если pid есть — ключ по pid самый надёжный
    if ($pid > 0) {
        $k = 'pid:' . $pid;
    } else {
        // fallback, если pid вдруг не проставился (редко, но бывает)
        $k = 'u:' . $uid . '|f:' . $fid . '|c:' . $chars . '|t:' . TIME_NOW;
    }

    if (!empty($seen[$k])) {
        return true;
    }
    $seen[$k] = 1;
    return false;
}

function af_balance_award_for_post(int $uid, int $fid, string $message, int $visible, int $pid = 0): void
{
    global $mybb;

    if ($visible !== 1 || $uid <= 0) {
        return;
    }

    $chars = af_balance_count_award_chars($message);
    if ($chars <= 0) {
        return;
    }

    // Дедуп: если тот же pid уже обработали — ничего не начисляем
    if (af_balance_already_awarded($pid, $uid, $fid, $chars)) {
        return;
    }

    if (!empty($mybb->settings['af_balance_exp_enabled']) && af_balance_is_forum_allowed($fid, 'exp')) {
        $rate = (float)($mybb->settings['af_balance_exp_per_char'] ?? 0);
        if ($rate != 0.0) {
            af_balance_add_exp($uid, $chars * $rate, [
                'reason'   => 'post_chars',
                'source'   => 'balance',
                'ref_type' => 'pid',
                'ref_id'   => $pid,
                'fid'      => $fid,
                'chars'    => $chars,
            ]);
        }
    }

    if (!empty($mybb->settings['af_balance_credits_enabled']) && af_balance_is_forum_allowed($fid, 'credits')) {
        $rate = (float)($mybb->settings['af_balance_credits_per_char'] ?? 0);
        if ($rate != 0.0) {
            af_balance_add_credits($uid, $chars * $rate, [
                'reason'   => 'post_chars',
                'source'   => 'balance',
                'ref_type' => 'pid',
                'ref_id'   => $pid,
                'fid'      => $fid,
                'chars'    => $chars,
            ]);
        }
    }
}

function af_balance_get_post_reward_row(int $pid): array
{
    global $db;
    if ($pid <= 0 || !$db->table_exists(AF_BALANCE_POST_REWARDS_TABLE)) {
        return [];
    }
    $row = $db->fetch_array($db->simple_select(AF_BALANCE_POST_REWARDS_TABLE, '*', 'pid=' . (int)$pid, ['limit' => 1]));
    return is_array($row) ? $row : [];
}

function af_balance_upsert_post_reward_row(int $pid, int $uid, int $fid, int $chars, int $expScaled, int $creditsScaled, string $hash): void
{
    global $db;

    if ($pid <= 0 || !$db->table_exists(AF_BALANCE_POST_REWARDS_TABLE)) {
        return;
    }

    static $cols = null;
    if ($cols === null) {
        $cols = [];
        $table = TABLE_PREFIX . AF_BALANCE_POST_REWARDS_TABLE;
        $q = $db->write_query("SHOW COLUMNS FROM {$table}");
        while ($r = $db->fetch_array($q)) {
            $cols[strtolower((string)$r['Field'])] = true;
        }
    }

    $data = [
        'pid' => $pid,
        'uid' => $uid,
        'fid' => $fid,
        'chars_count' => $chars,
        'last_hash' => $hash,
        'updated_at' => TIME_NOW,
    ];

    // новые поля (после миграции)
    if (!empty($cols['exp_scaled'])) {
        $data['exp_scaled'] = $expScaled;
    }
    if (!empty($cols['credits_scaled'])) {
        $data['credits_scaled'] = $creditsScaled;
    }

    // фоллбек на старую схему, если она была reward_exp/reward_credits
    if (empty($cols['exp_scaled']) && !empty($cols['reward_exp'])) {
        $data['reward_exp'] = $expScaled;
    }
    if (empty($cols['credits_scaled']) && !empty($cols['reward_credits'])) {
        $data['reward_credits'] = $creditsScaled;
    }

    $exists = (int)$db->fetch_field($db->simple_select(AF_BALANCE_POST_REWARDS_TABLE, 'pid', 'pid=' . $pid, ['limit' => 1]), 'pid');
    if ($exists > 0) {
        $db->update_query(AF_BALANCE_POST_REWARDS_TABLE, $data, 'pid=' . $pid);
    } else {
        $db->insert_query(AF_BALANCE_POST_REWARDS_TABLE, $data);
    }
}

function af_balance_calc_post_reward_scaled(int $chars, int $fid, string $kind): int
{
    global $mybb;

    if ($chars <= 0) return 0;
    if (empty($mybb->settings['af_balance_'.$kind.'_enabled']) || !af_balance_is_forum_allowed($fid, $kind)) {
        return 0;
    }

    $rate = (float)($mybb->settings['af_balance_'.$kind.'_per_char'] ?? 0);
    if ($rate == 0.0) return 0;

    $scale = ($kind === 'exp') ? AF_BALANCE_EXP_SCALE : AF_BALANCE_CREDITS_SCALE;
    return (int)floor($chars * $rate * $scale);
}

function af_balance_recalc_post_awards(int $pid, int $uid, int $fid, int $visible, string $message, string $source): void
{
    if ($pid <= 0 || $uid <= 0 || $fid <= 0 || $visible !== 1) {
        return;
    }

    $chars = af_balance_count_award_chars($message);
    $norm = (string)$message;
    $norm = html_entity_decode($norm, ENT_QUOTES, 'UTF-8');
    $hash = sha1((string)$chars . '|' . sha1($norm)); // достаточно для антидубля

    $tracked = af_balance_get_post_reward_row($pid);
    $oldHash = (string)($tracked['last_hash'] ?? '');

    // если уже пересчитывали этот exact контент — ничего не делаем
    if ($oldHash !== '' && hash_equals($oldHash, $hash)) {
        return;
    }

    $oldExp = (int)($tracked['exp_scaled'] ?? $tracked['reward_exp'] ?? 0);
    $oldCr  = (int)($tracked['credits_scaled'] ?? $tracked['reward_credits'] ?? 0);

    $newExp = af_balance_calc_post_reward_scaled($chars, $fid, 'exp');
    $newCr  = af_balance_calc_post_reward_scaled($chars, $fid, 'credits');

    $deltaExp = $newExp - $oldExp;
    $deltaCr  = $newCr - $oldCr;

    if ($deltaExp !== 0) {
        af_balance_apply_scaled_delta($uid, 'exp', $deltaExp, [
            'reason' => 'post_chars_recalc',
            'source' => 'balance',
            'ref_type' => 'pid',
            'ref_id' => $pid,
            'fid' => $fid,
            'chars' => $chars,
            'recalc_source' => $source,
        ], true); //  bypass: умеем уменьшать
    }

    if ($deltaCr !== 0) {
        af_balance_apply_scaled_delta($uid, 'credits', $deltaCr, [
            'reason' => 'post_chars_recalc',
            'source' => 'balance',
            'ref_type' => 'pid',
            'ref_id' => $pid,
            'fid' => $fid,
            'chars' => $chars,
            'recalc_source' => $source,
        ], true);
    }

    af_balance_upsert_post_reward_row($pid, $uid, $fid, $chars, $newExp, $newCr, $hash);
}

function af_balance_handle_accept(int $uid, int $tid, int $actor_uid = 0): void
{
    global $mybb;
    if ($uid <= 0) return;
    if (!empty($mybb->settings['af_balance_exp_enabled'])) {
        $amt = (float)($mybb->settings['af_balance_exp_on_accept'] ?? 0);
        if ($amt != 0.0) af_balance_add_exp($uid, $amt, ['reason'=>'accept','source'=>'balance','actor_uid'=>$actor_uid,'ref_type'=>'tid','ref_id'=>$tid]);
    }
    if (!empty($mybb->settings['af_balance_credits_enabled'])) {
        $amt = (float)($mybb->settings['af_balance_credits_on_accept'] ?? 0);
        if ($amt != 0.0) af_balance_add_credits($uid, $amt, ['reason'=>'accept','source'=>'balance','actor_uid'=>$actor_uid,'ref_type'=>'tid','ref_id'=>$tid]);
    }
}

function af_balance_csv_to_ids(string $csv): array { $out=[]; foreach (explode(',', $csv) as $p){$id=(int)trim($p); if($id>0)$out[$id]=$id;} return array_values($out);} 
function af_balance_expand_forum_ids_with_children(array $ids): array
{ global $db; if(!$ids)return[]; $all=$ids; $pending=$ids; while($pending){ $pid=array_pop($pending); $q=$db->simple_select('forums','fid','pid='.(int)$pid); while($r=$db->fetch_array($q)){ $fid=(int)$r['fid']; if($fid>0 && !in_array($fid,$all,true)){ $all[]=$fid; $pending[]=$fid; } } } return $all; }
function af_balance_is_forum_allowed(int $fid, string $kind): bool
{
    global $mybb; if ($fid<=0) return false;
    $mode = (string)($mybb->settings['af_balance_'.$kind.'_mode'] ?? 'include');
    $cats = af_balance_csv_to_ids((string)($mybb->settings['af_balance_'.$kind.'_categories_csv'] ?? ''));
    $forums = af_balance_csv_to_ids((string)($mybb->settings['af_balance_'.$kind.'_forums_csv'] ?? ''));
    $selected = array_values(array_unique(array_merge($forums, af_balance_expand_forum_ids_with_children($cats))));
    if ($mode === 'exclude') return !$selected || !in_array($fid, $selected, true);
    return !$selected || in_array($fid, $selected, true);
}

function af_balance_trim_tx(): void
{ global $db, $mybb; $limit=(int)($mybb->settings['af_balance_tx_keep_limit'] ?? 0); if($limit<=0)return; $cnt=(int)$db->fetch_field($db->simple_select(AF_BALANCE_TX_TABLE,'COUNT(*) AS c'),'c'); if($cnt <= $limit)return; $over=$cnt-$limit; $db->write_query("DELETE FROM ".TABLE_PREFIX.AF_BALANCE_TX_TABLE." ORDER BY id ASC LIMIT ".(int)$over); }

function af_balance_format_exp(int $scaledExp): string
{
    return number_format($scaledExp / AF_BALANCE_EXP_SCALE, 2, '.', ' ');
}

function af_balance_format_credits(int $scaledCredits): string
{
    return number_format($scaledCredits / AF_BALANCE_CREDITS_SCALE, 2, '.', ' ');
}

function af_balance_migrate_credits_scale(): void
{
    global $db, $mybb;
    if ((int)($mybb->settings['af_balance_credits_scale_migrated'] ?? 0) === 1) {
        return;
    }

    if ($db->table_exists(AF_BALANCE_TABLE)) {
        $db->write_query('UPDATE ' . TABLE_PREFIX . AF_BALANCE_TABLE . ' SET credits=credits*' . AF_BALANCE_CREDITS_SCALE);
    }
    if ($db->table_exists(AF_BALANCE_TX_TABLE)) {
        $db->write_query("UPDATE " . TABLE_PREFIX . AF_BALANCE_TX_TABLE . " SET amount=amount*" . AF_BALANCE_CREDITS_SCALE . ", balance_after=balance_after*" . AF_BALANCE_CREDITS_SCALE . " WHERE kind='credits'");
    }
    $db->update_query('settings', ['value' => '1'], "name='af_balance_credits_scale_migrated'");
    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_balance_can_manage(): bool
{
    global $mybb;
    $allowed = af_balance_csv_to_ids((string)($mybb->settings['af_balance_manage_groups'] ?? '3,4'));
    $groups = [(int)($mybb->user['usergroup'] ?? 0)];
    foreach (explode(',', (string)($mybb->user['additionalgroups'] ?? '')) as $g) {
        $groups[] = (int)$g;
    }
    return (bool)array_intersect(array_filter($groups), $allowed);
}

function af_balance_misc_start(): void
{
    global $mybb;
    if ((string)$mybb->get_input('action') !== 'balance_manage') {
        return;
    }

    if ((string)$mybb->get_input('do') !== 'adjust' && is_file(af_balance_alias_root_path())) {
        $query = $_GET;
        unset($query['action']);
        header('Location: ' . af_balance_build_manage_url($query), true, 302);
        exit;
    }

    $tab = (string)$mybb->get_input('tab');
    if (!in_array($tab, ['exp', 'credits', 'history'], true)) {
        $legacy_kind = (string)$mybb->get_input('kind');
        if (in_array($legacy_kind, ['exp', 'credits'], true)) {
            $mybb->input['tab'] = $legacy_kind;
        }
    }

    if ((string)$mybb->get_input('do') === 'adjust') {
        if (!af_balance_can_manage()) {
            error_no_permission();
        }
        af_balance_handle_manage_adjust();
    }

    af_balance_render_balancemanage();
}

function af_balance_render_balancemanage(): void
{
    if (!af_balance_can_manage()) {
        error_no_permission();
    }

    af_balance_render_manage_page();
    exit;
}

function af_balance_handle_manage_adjust(): void
{
    global $mybb, $db;

    /**
     * ВАЖНО:
     * - Некоторые аддоны печатают HTML/CSS в global_start/pre_output_page.
     * - Это попадает в буфер и "прилипает" перед JSON.
     * Поэтому: чистим ВСЕ выходные буферы перед тем как слать JSON.
     */
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    // Требуем POST
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Проверка post key (без verify_post_check, т.к. он может печатать HTML)
    $postKey = (string)$mybb->get_input('my_post_key');
    if ($postKey === '' || $postKey !== (string)$mybb->post_code) {
        echo json_encode(['success' => false, 'error' => 'Bad post key'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $uid    = (int)$mybb->get_input('uid');
    $kind   = (string)$mybb->get_input('kind');     // exp|credits
    $op     = (string)$mybb->get_input('op');       // add|sub
    $amount = (float)$mybb->get_input('amount');
    $reason = trim((string)$mybb->get_input('reason'));

    // ---- Idempotency: защита от двойного POST (двойной клик / двойной обработчик / повтор fetch)
    $reqId = trim((string)$mybb->get_input('req_id'));
    if ($reqId !== '') {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (!isset($_SESSION['af_balance_req'])) {
            $_SESSION['af_balance_req'] = [];
        }

        $key = (string)$uid . ':' . $kind . ':' . $reqId;

        // если уже был такой req — просто вернём текущий баланс без повторного списания/начисления
        if (!empty($_SESSION['af_balance_req'][$key])) {
            $data = af_balance_get_postbit_data($uid);

            echo json_encode([
                'success' => true,
                'uid' => $uid,
                'kind' => $kind,
                'exp_current' => (int)($data['exp_current'] ?? 0),
                'exp_need' => (int)($data['exp_need'] ?? 0),
                'exp_display' => (string)($data['exp_display'] ?? '0'),
                'exp_need_display' => (string)($data['exp_need_display'] ?? '0'),
                'credits_display' => (string)($data['credits_display'] ?? '0.00'),
                'currency_symbol' => (string)($data['currency_symbol'] ?? '¢'),
                'level' => (int)($data['level'] ?? 1),
                'progress_percent' => (int)($data['progress_percent'] ?? 0),
                'deduped' => 1
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        // помечаем как использованный
        $_SESSION['af_balance_req'][$key] = TIME_NOW;

        // чистим старьё (например старше 10 минут)
        foreach ($_SESSION['af_balance_req'] as $k => $t) {
            if ((int)$t < (TIME_NOW - 600)) {
                unset($_SESSION['af_balance_req'][$k]);
            }
        }
    }


    if ($uid <= 0 || !in_array($kind, ['exp', 'credits'], true)) {
        echo json_encode(['success' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($op !== 'add' && $op !== 'sub') {
        $op = 'add';
    }

    if (!is_finite($amount) || $amount <= 0) {
        echo json_encode(['success' => false, 'error' => 'Amount must be > 0'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Скалирование
    $scale = ($kind === 'exp') ? AF_BALANCE_EXP_SCALE : AF_BALANCE_CREDITS_SCALE;

    // Округление вниз тебе не нужно для ручных операций: 480.01 должно стать 48001, а не 48000.
    // Поэтому: round до "копеек" (scale=100).
    $scaledAbs = (int)round($amount * $scale);

    if ($scaledAbs <= 0) {
        echo json_encode(['success' => false, 'error' => 'Amount too small'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Берём текущий баланс
    $bal = af_balance_get($uid);
    $old = (int)($bal[$kind] ?? 0);

    // Списание: НИКОГДА не уводим в минус на этой странице управления
    $delta = ($op === 'sub') ? -$scaledAbs : $scaledAbs;

    // Если списываем больше, чем есть — зажимаем до нуля (или можно ошибку; ты просила "исправь", делаю безопасно)
    $new = $old + $delta;
    if ($new < 0) {
        $delta = -$old; // списываем ровно то, что есть
        $new = 0;
    }

    // Обновляем баланс
    $db->update_query(AF_BALANCE_TABLE, [
        $kind => $new,
        'updated_at' => TIME_NOW,
    ], 'uid=' . (int)$uid);

    // Лог транзакций (если включён)
    if (!empty($mybb->settings['af_balance_tx_enable']) && $db->table_exists(AF_BALANCE_TX_TABLE)) {
        $meta = [
            'reason'    => ($reason !== '' ? $reason : 'manual_adjust'),
            'source'    => 'balance_manage',
            'actor_uid' => (int)($mybb->user['uid'] ?? 0),
            'ref_type'  => 'uid',
            'ref_id'    => $uid,
            'op'        => $op,
            'amount_ui' => $amount,
        ];

        $db->insert_query(AF_BALANCE_TX_TABLE, [
            'uid'          => (int)$uid,
            'kind'         => $db->escape_string($kind),
            'amount'       => (int)$delta,
            'balance_after'=> (int)$new,
            'reason'       => $db->escape_string(substr((string)$meta['reason'], 0, 64)),
            'source'       => $db->escape_string('balance_manage'),
            'ref_type'     => $db->escape_string('uid'),
            'ref_id'       => (int)$uid,
            'meta_json'    => $db->escape_string(json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
            'actor_uid'    => (int)($mybb->user['uid'] ?? 0),
            'created_at'   => TIME_NOW,
        ]);

        af_balance_trim_tx();
    }

    // EXP: если есть интеграция с листом персонажа
    if ($kind === 'exp' && function_exists('af_charactersheets_on_exp_changed')) {
        af_charactersheets_on_exp_changed($uid, $old, $new, [
            'reason' => ($reason !== '' ? $reason : 'manual_adjust'),
            'source' => 'balance_manage',
            'actor_uid' => (int)($mybb->user['uid'] ?? 0),
            'ref_type' => 'uid',
            'ref_id' => $uid,
        ]);
    }

    $data = af_balance_get_postbit_data($uid);

    echo json_encode([
        'success'         => true,
        'uid'             => $uid,
        'kind'            => $kind,
        'exp_current'    => (int)($data['exp_current'] ?? 0),
        'exp_need'       => (int)($data['exp_need'] ?? 0),
        'exp_display'     => (string)($data['exp_display'] ?? '0'),
        'exp_need_display'=> (string)($data['exp_need_display'] ?? '0'),
        'credits_display' => (string)($data['credits_display'] ?? '0.00'),
        'currency_symbol' => (string)($data['currency_symbol'] ?? '¢'),
        'level'           => (int)($data['level'] ?? 1),
        'progress_percent'=> (int)($data['progress_percent'] ?? 0),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function af_balance_migrate_from_charactersheets(): void
{
    global $db;
    if (!$db->table_exists('af_cs_sheets') || !$db->table_exists(AF_BALANCE_TABLE)) return;
    $q = $db->simple_select('af_cs_sheets', 'uid, progress_json', 'uid IS NOT NULL AND uid>0');
    while ($row = $db->fetch_array($q)) {
        $uid = (int)$row['uid'];
        if ($uid <= 0) continue;
        $existing = af_balance_get($uid);
        if ((int)$existing['exp'] !== 0) continue;
        $progress = json_decode((string)($row['progress_json'] ?? ''), true);
        $expLegacy = (float)($progress['exp'] ?? 0);
        if ($expLegacy > 0) {
            $scaled = (int)floor($expLegacy * AF_BALANCE_EXP_SCALE);
            $db->update_query(AF_BALANCE_TABLE, ['exp' => $scaled, 'updated_at' => TIME_NOW], 'uid=' . $uid);
        }
    }
}

function af_balance_history_limit_window_where(): string
{
    global $db;

    if (!$db->table_exists(AF_BALANCE_TX_TABLE)) {
        return '1=0';
    }

    $threshold = 0;
    $q = $db->query('SELECT id FROM ' . TABLE_PREFIX . AF_BALANCE_TX_TABLE . ' ORDER BY id DESC LIMIT 1 OFFSET 1999');
    $row = $db->fetch_array($q);
    if (!empty($row['id'])) {
        $threshold = (int)$row['id'];
    }

    if ($threshold > 0) {
        return 'tx.id>=' . $threshold;
    }

    return '1=1';
}

function af_balance_render_manage_page(): void
{
    global $db, $mybb, $headerinclude, $header, $footer;

    $tab = (string)$mybb->get_input('tab');
    if (!in_array($tab, ['exp', 'credits', 'history'], true)) {
        $tab = '';
    }

    if ($tab === '') {
        $legacy_kind = (string)$mybb->get_input('kind');
        $tab = in_array($legacy_kind, ['exp', 'credits'], true) ? $legacy_kind : 'exp';
    }

    $kind_exp_active = $tab === 'exp' ? 'is-active' : '';
    $kind_credits_active = $tab === 'credits' ? 'is-active' : '';
    $history_active = $tab === 'history' ? 'is-active' : '';

    $currency_symbol = htmlspecialchars_uni((string)($mybb->settings['af_balance_currency_symbol'] ?? '¢'));
    $bburl = htmlspecialchars_uni((string)($mybb->settings['bburl'] ?? ''));
    $is_misc_script = strtolower((string)(defined('THIS_SCRIPT') ? THIS_SCRIPT : '')) === 'misc.php';
    $action_hidden_input = $is_misc_script ? '<input type="hidden" name="action" value="balance_manage">' : '';
    $qRaw = trim((string)$mybb->get_input('q'));
    $raceRaw = trim((string)$mybb->get_input('race'));

    $exp_tab_params = [
        'tab' => 'exp',
    ];
    if ($qRaw !== '') {
        $exp_tab_params['q'] = $qRaw;
    }
    if ($raceRaw !== '') {
        $exp_tab_params['race'] = $raceRaw;
    }

    $credits_tab_params = [
        'tab' => 'credits',
    ];
    if ($qRaw !== '') {
        $credits_tab_params['q'] = $qRaw;
    }
    if ($raceRaw !== '') {
        $credits_tab_params['race'] = $raceRaw;
    }

    $history_tab_params = [
        'tab' => 'history',
    ];

    $history_uid_tab = trim((string)$mybb->get_input('uid'));
    $history_actor_tab = trim((string)$mybb->get_input('actor'));
    $history_kind_tab = (string)$mybb->get_input('history_kind');
    $amount_type_tab = (string)$mybb->get_input('amount_type');
    $history_reason_tab = trim((string)$mybb->get_input('reason'));
    if ($history_uid_tab !== '') {
        $history_tab_params['uid'] = $history_uid_tab;
    }
    if ($history_actor_tab !== '') {
        $history_tab_params['actor'] = $history_actor_tab;
    }
    if (in_array($history_kind_tab, ['exp', 'credits', 'any'], true) && $history_kind_tab !== 'any') {
        $history_tab_params['history_kind'] = $history_kind_tab;
    }
    if (in_array($amount_type_tab, ['plus', 'minus', 'all'], true) && $amount_type_tab !== 'all') {
        $history_tab_params['amount_type'] = $amount_type_tab;
    }
    if ($history_reason_tab !== '') {
        $history_tab_params['reason'] = $history_reason_tab;
    }

    $exp_tab_url = htmlspecialchars_uni(af_balance_build_manage_url($exp_tab_params));
    $credits_tab_url = htmlspecialchars_uni(af_balance_build_manage_url($credits_tab_params));
    $history_tab_url = htmlspecialchars_uni(af_balance_build_manage_url($history_tab_params));

    $content_html = '';

    if ($tab === 'history') {
        $page = max(1, (int)$mybb->get_input('page'));
        $per_page = 50;

        $history_uid_raw = trim((string)$mybb->get_input('uid'));
        $history_actor_raw = trim((string)$mybb->get_input('actor'));
        $history_kind = (string)$mybb->get_input('history_kind');
        if (!in_array($history_kind, ['exp', 'credits'], true)) {
            $history_kind = 'any';
        }
        $amount_type = (string)$mybb->get_input('amount_type');
        if (!in_array($amount_type, ['all', 'plus', 'minus'], true)) {
            $amount_type = 'all';
        }
        $reason_q = trim((string)$mybb->get_input('reason'));

        $where_parts = [af_balance_history_limit_window_where()];

        if ($history_kind !== 'any') {
            $where_parts[] = "tx.kind='" . $db->escape_string($history_kind) . "'";
        }

        if ($amount_type === 'plus') {
            $where_parts[] = 'tx.amount>0';
        } elseif ($amount_type === 'minus') {
            $where_parts[] = 'tx.amount<0';
        }

        if ($reason_q !== '') {
            $like = $db->escape_string($reason_q);
            $like = str_replace(['%', '_'], ['\%', '\_'], $like);
            $where_parts[] = "tx.reason LIKE '%{$like}%'";
        }

        if ($history_uid_raw !== '') {
            if (ctype_digit($history_uid_raw)) {
                $where_parts[] = 'tx.uid=' . (int)$history_uid_raw;
            } else {
                $like = $db->escape_string($history_uid_raw);
                $like = str_replace(['%', '_'], ['\%', '\_'], $like);
                $where_parts[] = "u.username LIKE '%{$like}%'";
            }
        }

        if ($history_actor_raw !== '') {
            if (ctype_digit($history_actor_raw)) {
                $where_parts[] = 'tx.actor_uid=' . (int)$history_actor_raw;
            } else {
                $like = $db->escape_string($history_actor_raw);
                $like = str_replace(['%', '_'], ['\%', '\_'], $like);
                $where_parts[] = "au.username LIKE '%{$like}%'";
            }
        }

        $where_sql = implode(' AND ', $where_parts);

        $count_sql = "SELECT COUNT(*) AS c
            FROM " . TABLE_PREFIX . AF_BALANCE_TX_TABLE . " tx
            LEFT JOIN " . TABLE_PREFIX . "users u ON u.uid=tx.uid
            LEFT JOIN " . TABLE_PREFIX . "users au ON au.uid=tx.actor_uid
            WHERE {$where_sql}";
        $total_rows = (int)$db->fetch_field($db->query($count_sql), 'c');
        $total_pages = max(1, (int)ceil($total_rows / $per_page));
        if ($page > $total_pages) {
            $page = $total_pages;
        }
        $offset = ($page - 1) * $per_page;

        $sql = "SELECT tx.id,tx.created_at,tx.actor_uid,tx.uid,tx.kind,tx.amount,tx.balance_after,tx.reason,tx.source,tx.ref_type,tx.ref_id,tx.meta_json,
                       u.username AS target_username,
                       au.username AS actor_username
                FROM " . TABLE_PREFIX . AF_BALANCE_TX_TABLE . " tx
                LEFT JOIN " . TABLE_PREFIX . "users u ON u.uid=tx.uid
                LEFT JOIN " . TABLE_PREFIX . "users au ON au.uid=tx.actor_uid
                WHERE {$where_sql}
                ORDER BY tx.id DESC
                LIMIT {$offset}, {$per_page}";

        $result = $db->query($sql);
        $rows_html = [];
        while ($row = $db->fetch_array($result)) {
            $target_uid = (int)($row['uid'] ?? 0);
            $actor_uid = (int)($row['actor_uid'] ?? 0);
            $kind_val = (string)($row['kind'] ?? '');
            $amount = (int)($row['amount'] ?? 0);
            $balance_after = (int)($row['balance_after'] ?? 0);

            $kind_badge = '<span class="af-balance-kind af-balance-kind--' . htmlspecialchars_uni($kind_val) . '">' . htmlspecialchars_uni(strtoupper($kind_val)) . '</span>';
            $amount_class = $amount < 0 ? 'is-minus' : ($amount > 0 ? 'is-plus' : '');

            $amount_label = $kind_val === 'credits' ? af_balance_format_credits($amount) : af_balance_format_exp($amount);
            $balance_after_label = $kind_val === 'credits' ? af_balance_format_credits($balance_after) : af_balance_format_exp($balance_after);

            $target_name = trim((string)($row['target_username'] ?? ''));
            if ($target_name === '' && $target_uid > 0) {
                $target_name = 'UID ' . $target_uid;
            }
            $actor_name = trim((string)($row['actor_username'] ?? ''));
            if ($actor_name === '') {
                $actor_name = $actor_uid > 0 ? ('UID ' . $actor_uid) : 'System';
            }

            $target_link = $target_uid > 0 ? '<a href="member.php?action=profile&amp;uid=' . $target_uid . '">' . htmlspecialchars_uni($target_name) . '</a>' : htmlspecialchars_uni($target_name);
            $actor_link = $actor_uid > 0 ? '<a href="member.php?action=profile&amp;uid=' . $actor_uid . '">' . htmlspecialchars_uni($actor_name) . '</a>' : htmlspecialchars_uni($actor_name);

            $actor_filter_url = af_balance_build_manage_url(['tab' => 'history', 'actor' => (string)$actor_uid]);
            $target_filter_url = af_balance_build_manage_url(['tab' => 'history', 'uid' => (string)$target_uid]);

            $rows_html[] = '<tr>'
                . '<td>' . htmlspecialchars_uni(my_date('d.m.Y H:i', (int)($row['created_at'] ?? 0))) . '</td>'
                . '<td>' . $actor_link . ($actor_uid > 0 ? ' <a class="smalltext" href="' . htmlspecialchars_uni($actor_filter_url) . '">[filter]</a>' : '') . '</td>'
                . '<td>' . $target_link . ($target_uid > 0 ? ' <a class="smalltext" href="' . htmlspecialchars_uni($target_filter_url) . '">[filter]</a>' : '') . '</td>'
                . '<td>' . $kind_badge . '</td>'
                . '<td class="' . $amount_class . '">' . htmlspecialchars_uni($amount_label) . '</td>'
                . '<td>' . htmlspecialchars_uni($balance_after_label) . '</td>'
                . '<td>' . htmlspecialchars_uni((string)($row['reason'] ?? '')) . '</td>'
                . '<td>' . htmlspecialchars_uni((string)($row['source'] ?? '')) . '</td>'
                . '</tr>';
        }

        if (!$rows_html) {
            $rows_html[] = '<tr><td colspan="8">Нет записей</td></tr>';
        }

        $base_params = [
            'tab' => 'history',
            'uid' => $history_uid_raw,
            'actor' => $history_actor_raw,
            'history_kind' => $history_kind,
            'amount_type' => $amount_type,
            'reason' => $reason_q,
        ];

        $pagination = '';
        if ($total_pages > 1) {
            $links = [];
            for ($p = max(1, $page - 3); $p <= min($total_pages, $page + 3); $p++) {
                $params = $base_params;
                $params['page'] = $p;
                $url = htmlspecialchars_uni(af_balance_build_manage_url($params));
                if ($p === $page) {
                    $links[] = '<strong>' . $p . '</strong>';
                } else {
                    $links[] = '<a href="' . $url . '">' . $p . '</a>';
                }
            }
            $pagination = '<div class="af-balance-pagination">Страницы: ' . implode(' ', $links) . '</div>';
        }

        $content_html = ''
            . '<form method="get" class="af-balance-filters">'
            . $action_hidden_input
            . '<input type="hidden" name="tab" value="history">'
            . '<input type="text" name="uid" value="' . htmlspecialchars_uni($history_uid_raw) . '" placeholder="Кому (uid/username)">'
            . '<input type="text" name="actor" value="' . htmlspecialchars_uni($history_actor_raw) . '" placeholder="Кто (uid/username)">'
            . '<select name="history_kind">'
            . '<option value="any"' . ($history_kind === 'any' ? ' selected' : '') . '>Все типы</option>'
            . '<option value="exp"' . ($history_kind === 'exp' ? ' selected' : '') . '>EXP</option>'
            . '<option value="credits"' . ($history_kind === 'credits' ? ' selected' : '') . '>Credits</option>'
            . '</select>'
            . '<select name="amount_type">'
            . '<option value="all"' . ($amount_type === 'all' ? ' selected' : '') . '>Все суммы</option>'
            . '<option value="plus"' . ($amount_type === 'plus' ? ' selected' : '') . '>Начисления (+)</option>'
            . '<option value="minus"' . ($amount_type === 'minus' ? ' selected' : '') . '>Списания (-)</option>'
            . '</select>'
            . '<input type="text" name="reason" value="' . htmlspecialchars_uni($reason_q) . '" placeholder="Причина содержит">'
            . '<button type="submit" class="button">Фильтр</button>'
            . '</form>'
            . '<table class="tborder af-balance-table af-balance-history-table">'
            . '<tr><th>Date</th><th>Actor</th><th>Target</th><th>Kind</th><th>Amount</th><th>Balance after</th><th>Reason</th><th>Source</th></tr>'
            . implode("
", $rows_html)
            . '</table>'
            . $pagination;
    } else {
        $kind = $tab;

        $where = 'u.uid>0';
        if ($qRaw !== '') {
            $like = $db->escape_string($qRaw);
            $like = str_replace(['%', '_'], ['\%', '\_'], $like);
            $where .= " AND u.username LIKE '%{$like}%'";
        }

        $rows = [];
        $sql = "SELECT u.uid,u.username,u.avatar,b.exp,b.credits,s.progress_json
                FROM " . TABLE_PREFIX . "users u
                LEFT JOIN " . TABLE_PREFIX . AF_BALANCE_TABLE . " b ON b.uid=u.uid
                LEFT JOIN " . TABLE_PREFIX . "af_cs_sheets s ON s.uid=u.uid
                WHERE {$where}
                ORDER BY u.username ASC
                LIMIT 200";
        $res = $db->query($sql);

        while ($row = $db->fetch_array($res)) {
            $progress = json_decode((string)($row['progress_json'] ?? ''), true);
            if (!is_array($progress)) $progress = [];

            $rowRace = trim((string)($progress['race'] ?? ''));

            if ($raceRaw !== '') {
                $hay = function_exists('mb_strtolower') ? mb_strtolower($rowRace, 'UTF-8') : strtolower($rowRace);
                $needle = function_exists('mb_strtolower') ? mb_strtolower($raceRaw, 'UTF-8') : strtolower($raceRaw);
                if ($rowRace === '' || strpos($hay, $needle) === false) {
                    continue;
                }
            }

            $uid = (int)$row['uid'];
            $exp = (int)($row['exp'] ?? 0);
            $credits = (int)($row['credits'] ?? 0);

            $levelData = af_balance_compute_level($exp / AF_BALANCE_EXP_SCALE);

            $avatar = trim((string)($row['avatar'] ?? ''));
            if ($avatar === '') $avatar = 'images/default_avatar.png';

            $history_url = htmlspecialchars_uni(af_balance_build_manage_url(['tab' => 'history', 'uid' => $uid]));

            $add_button = '<button type="button" class="button af-balance-action" data-af-balance-adjust="1" data-op="add" data-uid="' . $uid . '">Начислить</button>';
            $sub_button = '<button type="button" class="button af-balance-action" data-af-balance-adjust="1" data-op="sub" data-uid="' . $uid . '">Списать</button>';
            $history_button = '<a class="button af-balance-action" href="' . $history_url . '">История</a>';

            $value_columns = '';
            if ($kind === 'exp') {
                $value_columns .= '<td data-af-balance-exp>' . af_balance_format_exp($exp) . '</td>';
                $value_columns .= '<td data-af-balance-level>' . (int)($levelData['level'] ?? 1) . '</td>';
            } else {
                $value_columns .= '<td data-af-balance-credits>' . af_balance_format_credits($credits) . '</td>';
            }

            $rows[] =
                '<tr data-af-balance-row="' . $uid . '">'
                . '<td><img src="' . htmlspecialchars_uni($avatar) . '" width="34" height="34" style="border-radius:50%"></td>'
                . '<td><a href="member.php?action=profile&amp;uid=' . $uid . '">' . htmlspecialchars_uni((string)$row['username']) . '</a>'
                . '<div class="smalltext">' . htmlspecialchars_uni($rowRace) . '</div></td>'
                . $value_columns
                . '<td>' . $add_button . ' ' . $sub_button . ' ' . $history_button . '</td>'
                . '</tr>';
        }

        $colspan = $kind === 'exp' ? 5 : 4;
        $rows_html = $rows ? implode("\n", $rows) : '<tr><td colspan="' . $colspan . '">Нет результатов</td></tr>';

        $table_head = '';
        if ($kind === 'exp') {
            $table_head = '<tr><th></th><th>User</th><th>EXP</th><th>Level</th><th>Actions</th></tr>';
        } else {
            $table_head = '<tr><th></th><th>User</th><th>Credits (' . $currency_symbol . ')</th><th>Actions</th></tr>';
        }

        $content_html = ''
            . '<form method="get" class="af-balance-filters">'
            . $action_hidden_input
            . '<input type="hidden" name="tab" value="' . htmlspecialchars_uni($tab) . '">'
            . '<input type="text" name="q" value="' . htmlspecialchars_uni($qRaw) . '" placeholder="Поиск по имени">'
            . '<input type="text" name="race" value="' . htmlspecialchars_uni($raceRaw) . '" placeholder="Поиск по расе">'
            . '<button type="submit" class="button">Фильтр</button>'
            . '</form>'
            . '<table class="tborder af-balance-table">'
            . $table_head
            . $rows_html
            . '</table>'
            . '<div class="af-balance-modal" data-af-balance-modal hidden>'
            . '  <div class="af-balance-modal__backdrop" data-af-balance-close></div>'
            . '  <div class="af-balance-modal__dialog">'
            . '    <h3>Изменить баланс</h3>'
            . '    <div class="af-balance-modal__error" data-af-balance-error></div>'
            . '    <input type="hidden" data-af-balance-uid>'
            . '    <label><input type="radio" name="af-balance-op" value="add" checked> Начислить</label>'
            . '    <label><input type="radio" name="af-balance-op" value="sub"> Списать</label>'
            . '    <input type="number" step="0.01" data-af-balance-amount placeholder="Сумма">'
            . '    <input type="text" data-af-balance-reason placeholder="Причина">'
            . '    <button type="button" class="button" data-af-balance-apply>Применить</button>'
            . '    <button type="button" class="button" data-af-balance-close>Отмена</button>'
            . '  </div>'
            . '</div>'
            . '<script>window.afBalanceConfig={kind:' . json_encode($kind) . ',postKey:' . json_encode($mybb->post_code) . '};</script>';
    }

    af_balance_enqueue_assets();

    $page_html = '<!DOCTYPE html><html lang="ru"><head>'
        . '<meta charset="utf-8">'
        . '<title>Balance manage</title>'
        . '<script src="' . $bburl . '/jscripts/jquery.js?ver=1823"></script>'
        . $headerinclude
        . '</head><body>'
        . $header
        . '<div class="af-balance-page">'
        . '<h1>Balance management</h1>'
        . '<div class="af-balance-tabs">'
        . '<a class="af-balance-tab ' . $kind_exp_active . '" href="' . $exp_tab_url . '">EXP</a>'
        . '<a class="af-balance-tab ' . $kind_credits_active . '" href="' . $credits_tab_url . '">Credits</a>'
        . '<a class="af-balance-tab ' . $history_active . '" href="' . $history_tab_url . '">История</a>'
        . '</div>'
        . $content_html
        . '</div>'
        . $footer
        . '</body></html>';

    output_page($page_html);
}

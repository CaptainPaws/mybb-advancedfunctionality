<?php
/**
 * AF Addon: AdvancedPostCounter
 * MyBB 1.8.38–1.8.39, PHP 8.0–8.4
 *
 * Считает посты пользователя в выбранных категориях (и всех форумах внутри) и хранит число в users.af_advancedpostcounter
 * + выводит в постбите и в профиле.
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { /* аддон предполагает наличие ядра AF */ }

const AF_APC_ID  = 'advancedpostcounter';
const AF_APC_COL = 'af_advancedpostcounter';

// markers для замены в pre_output_page
const AF_APC_MARK_POST = '<af_apc_uid_%d>';
const AF_APC_MARK_PROF = '<af_apc_profile_uid_%d>';

const AF_APC_DEFAULT_ASSETS_BLACKLIST = "index.php\nforumdisplay.php\nusercp.php\nuserlist.php\nsearch.php\ngallery.php\nmisc.php?action=kb";

/* -------------------- INSTALL / UNINSTALL -------------------- */

function af_advancedpostcounter_is_installed(): bool
{
    global $db;
    return $db->field_exists(AF_APC_COL, 'users');
}

function af_advancedpostcounter_install(): void
{
    global $db;

    if (!$db->field_exists(AF_APC_COL, 'users')) {
        $db->add_column('users', AF_APC_COL, "INT(11) NOT NULL DEFAULT 0");
    }

    af_advancedpostcounter_ensure_settings();
    af_advancedpostcounter_templates_install();
    af_advancedpostcounter_cleanup_legacy_profile_placeholders();
    af_advancedpostcounter_pages_install(); // <-- создаём /postsactivity.php в корне

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}


function af_advancedpostcounter_cleanup_legacy_profile_placeholders(): void
{
    if (!function_exists('find_replace_templatesets')) {
        require_once MYBB_ROOT . 'inc/adminfunctions_templates.php';
    }

    find_replace_templatesets('member_profile_customfields', "#\{\$memprofile\['advancedpostcounter'\]\}#i", '');
    find_replace_templatesets('member_profile_customfields_field', "#\{\$memprofile\['advancedpostcounter'\]\}#i", '');

    if (function_exists('cache_templatesets')) {
        cache_templatesets();
    }
}

function af_advancedpostcounter_uninstall(): void
{
    global $db;

    af_advancedpostcounter_templates_uninstall();
    af_advancedpostcounter_pages_uninstall(); // <-- удаляем /postsactivity.php (только если это наш файл)

    // удаляем наши настройки (но НЕ трогаем af_advancedpostcounter_enabled — его ведёт ядро AF)
    $db->delete_query('settings',
        "name IN (
            'af_advancedpostcounter_categories',
            'af_advancedpostcounter_forums',
            'af_advancedpostcounter_include_children',
            'af_advancedpostcounter_count_firstpost',
            'af_advancedpostcounter_show_postbit',
            'af_advancedpostcounter_show_profile',
            'af_advancedpostcounter_postbit_label_html',
            'af_apc_assets_blacklist'
        )"
    );

    // группа настроек
    $db->delete_query('settinggroups', "name='af_advancedpostcounter'");

    if ($db->field_exists(AF_APC_COL, 'users')) {
        $db->drop_column('users', AF_APC_COL);
    }

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}


/* -------------------- SETTINGS -------------------- */

function af_advancedpostcounter_is_enabled(): bool
{
    global $mybb;
    return !empty($mybb->settings['af_advancedpostcounter_enabled']);
}

function af_advancedpostcounter_count_firstpost_enabled(): bool
{
    global $mybb;
    return !empty($mybb->settings['af_advancedpostcounter_count_firstpost']);
}

function af_advancedpostcounter_include_children_enabled(): bool
{
    global $mybb;
    return !empty($mybb->settings['af_advancedpostcounter_include_children']);
}

function af_advancedpostcounter_show_postbit(): bool
{
    global $mybb;
    return !empty($mybb->settings['af_advancedpostcounter_show_postbit']);
}

function af_advancedpostcounter_show_profile(): bool
{
    global $mybb;
    return !empty($mybb->settings['af_advancedpostcounter_show_profile']);
}

function af_apc_parse_disable_conditions(string $raw): array
{
    $out = [];
    $lines = preg_split('~\R~', $raw);
    if (!is_array($lines)) {
        return $out;
    }

    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }

        $script = '';
        $action = null;

        $qPos = strpos($line, '?');
        if ($qPos === false) {
            $script = strtolower($line);
        } else {
            $script = strtolower(trim(substr($line, 0, $qPos)));
            $query = trim(substr($line, $qPos + 1));
            if ($query !== '') {
                $parts = explode('&', $query);
                foreach ($parts as $part) {
                    $part = trim((string)$part);
                    if ($part === '') {
                        continue;
                    }

                    $eqPos = strpos($part, '=');
                    if ($eqPos === false) {
                        continue;
                    }

                    $k = strtolower(trim(substr($part, 0, $eqPos)));
                    $v = trim(substr($part, $eqPos + 1));
                    if ($k === 'action') {
                        $action = strtolower($v);
                        break;
                    }
                }
            }
        }

        if ($script === '') {
            continue;
        }

        $out[] = ['script' => $script, 'action' => $action];
    }

    return $out;
}

function af_apc_assets_disabled_for_current_page(): bool
{
    global $mybb;

    $script = af_apc_current_script_name();
    if ($script === '') {
        return false;
    }

    $action = strtolower((string)($mybb->input['action'] ?? ''));

    $lines = [AF_APC_DEFAULT_ASSETS_BLACKLIST];
    $customRaw = trim((string)($mybb->settings['af_apc_assets_blacklist'] ?? ''));
    if ($customRaw !== '') {
        $lines[] = $customRaw;
    }

    $conditions = af_apc_parse_disable_conditions(implode("\n", $lines));
    foreach ($conditions as $cond) {
        $condScript = strtolower((string)($cond['script'] ?? ''));
        if ($condScript === '' || $condScript !== $script) {
            continue;
        }

        $condAction = $cond['action'] ?? null;
        if ($condAction === null || $condAction === '') {
            return true;
        }
        if ($action === strtolower((string)$condAction)) {
            return true;
        }
    }

    return false;
}

function af_apc_current_script_name(): string
{
    if (function_exists('af_current_script_name')) {
        $script = (string)af_current_script_name();
        if ($script !== '') {
            return strtolower($script);
        }
    }

    if (defined('THIS_SCRIPT')) {
        $script = strtolower((string)basename(str_replace('\\', '/', (string)THIS_SCRIPT)));
        if ($script !== '') {
            return $script;
        }
    }

    foreach (['SCRIPT_NAME', 'PHP_SELF'] as $key) {
        $raw = (string)($_SERVER[$key] ?? '');
        if ($raw === '') {
            continue;
        }

        $script = strtolower((string)basename(str_replace('\\', '/', $raw)));
        if ($script !== '') {
            return $script;
        }
    }

    return '';
}

function af_apc_should_load_assets_for_page(string $page): bool
{
    if (af_apc_assets_disabled_for_current_page()) {
        return false;
    }

    $page = (string)$page;
    if ($page === '') {
        return false;
    }

    $script = af_apc_current_script_name();
    if (in_array($script, ['postsactivity.php', 'postsbyuser.php'], true)) {
        return true;
    }

    return strpos($page, 'class="af-apc-tabs"') !== false
        || strpos($page, "class='af-apc-tabs'") !== false
        || strpos($page, 'data-apc-tab') !== false
        || strpos($page, 'data-apc-panel') !== false;
}

function af_apc_build_asset_url(string $file): string
{
    global $mybb;

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    if ($bburl === '') {
        return '';
    }

    $rel = 'inc/plugins/advancedfunctionality/addons/advancedpostcounter/assets/' . ltrim($file, '/');
    $abs = MYBB_ROOT . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $mtime = is_file($abs) ? (int)@filemtime($abs) : 0;

    return $bburl . '/' . $rel . '?v=' . $mtime;
}

function af_apc_dedupe_assets_in_html(string &$page): void
{
    $patterns = [
        '~<link\b[^>]*href=["\"][^"\"]*advancedpostcounter\.css(?:\?[^"\"]*)?["\"][^>]*>\s*~iu',
        '~<script\b[^>]*src=["\"][^"\"]*advancedpostcounter\.js(?:\?[^"\"]*)?["\"][^>]*>\s*</script>\s*~iu',
    ];

    foreach ($patterns as $pattern) {
        $seen = false;
        $page = (string)preg_replace_callback($pattern, static function ($m) use (&$seen) {
            if ($seen) {
                return '';
            }
            $seen = true;
            return $m[0];
        }, $page);
    }
}

function af_advancedpostcounter_categories_optionscode(): string
{
    // Встроенный (и стабильный) optionscode MyBB:
    // рисует multiselect дерева форумов/категорий и сохраняет CSV fid'ов.
    // Также поддерживает -1 ("all") и пустое ("none").
    return 'forumselect';
}


function af_advancedpostcounter_ensure_settings(): void
{
    global $db, $lang;

    af_advancedpostcounter_lang();

    // группа
    $gid = 0;
    $q = $db->simple_select('settinggroups', 'gid', "name='af_advancedpostcounter'", ['limit' => 1]);
    $row = $db->fetch_array($q);
    if ($row) {
        $gid = (int)$row['gid'];
    } else {
        $gid = (int)$db->insert_query('settinggroups', [
            'name'        => 'af_advancedpostcounter',
            'title'       => $lang->af_advancedpostcounter_group ?? 'AF: AdvancedPostCounter',
            'description' => $lang->af_advancedpostcounter_group_desc ?? 'AdvancedPostCounter settings',
            'disporder'   => 50,
            'isdefault'   => 0,
        ]);
    }

    $settings = [
        'af_advancedpostcounter_categories' => [
            'title'       => $lang->af_advancedpostcounter_categories ?? 'Категории/форумы для подсчёта',
            'description' => $lang->af_advancedpostcounter_categories_desc
                ?? 'Выбери (Custom) категории/форумы в списке. Обычно выбирают именно категории (разделы) — счётчик будет считать посты во всём дереве ниже выбранных пунктов.',
            // ВАЖНО: больше никакого php-eval. Только встроенный тип:
            'optionscode' => af_advancedpostcounter_categories_optionscode(), // forumselect
            'value'       => '',
            'disporder'   => 5,
        ],
        'af_advancedpostcounter_forums' => [
            'title'       => $lang->af_advancedpostcounter_forums ?? 'Forum IDs (legacy)',
            'description' => $lang->af_advancedpostcounter_forums_desc
                ?? 'LEGACY: ручной ввод ID форумов через запятую. Используется только если пусто поле выбора выше.',
            'optionscode' => 'text',
            'value'       => '',
            'disporder'   => 10,
        ],
        'af_advancedpostcounter_include_children' => [
            'title'       => $lang->af_advancedpostcounter_include_children ?? 'Include children',
            'description' => $lang->af_advancedpostcounter_include_children_desc ?? 'Include child forums',
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 20,
        ],
        'af_advancedpostcounter_count_firstpost' => [
            'title'       => $lang->af_advancedpostcounter_count_firstpost ?? 'Count first post',
            'description' => $lang->af_advancedpostcounter_count_firstpost_desc ?? 'Count thread starter post',
            'optionscode' => 'yesno',
            'value'       => '0',
            'disporder'   => 30,
        ],
        'af_advancedpostcounter_show_postbit' => [
            'title'       => $lang->af_advancedpostcounter_show_postbit ?? 'Show in postbit',
            'description' => $lang->af_advancedpostcounter_show_postbit_desc ?? 'Display in postbit',
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 40,
        ],
        'af_advancedpostcounter_show_profile' => [
            'title'       => $lang->af_advancedpostcounter_show_profile ?? 'Show in profile',
            'description' => $lang->af_advancedpostcounter_show_profile_desc ?? 'Display in profile',
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => 50,
        ],
        'af_advancedpostcounter_postbit_label_html' => [
            'title'       => $lang->af_advancedpostcounter_postbit_label_html ?? 'Postbit label html',
            'description' => $lang->af_advancedpostcounter_postbit_label_html_desc ?? 'HTML/FontAwesome для лейбла в постбите. Пусто = текст «Постов:»',
            'optionscode' => 'text',
            'value'       => '<i class="fa-solid fa-feather"></i>:',
            'disporder'   => 55,
        ],
        'af_apc_assets_blacklist' => [
            'title'       => $lang->af_apc_assets_blacklist ?? 'Assets blacklist (disable on pages)',
            'description' => $lang->af_apc_assets_blacklist_desc ?? 'One condition per line: script.php or script.php?action=name. APC assets are disabled when current page matches.',
            'optionscode' => 'textarea',
            'value'       => AF_APC_DEFAULT_ASSETS_BLACKLIST,
            'disporder'   => 60,
        ],
    ];

    foreach ($settings as $name => $s) {
        $nameEsc = $db->escape_string($name);
        $exists = $db->simple_select('settings', 'sid', "name='{$nameEsc}'", ['limit' => 1]);
        $ex = $db->fetch_array($exists);

        if ($ex) {
            $db->update_query('settings', [
                'title'       => $s['title'],
                'description' => $s['description'],
                'optionscode' => $s['optionscode'],
                'disporder'   => (int)$s['disporder'],
                'gid'         => $gid,
            ], "sid='".(int)$ex['sid']."'");
        } else {
            $db->insert_query('settings', [
                'name'        => $name,
                'title'       => $s['title'],
                'description' => $s['description'],
                'optionscode' => $s['optionscode'],
                'value'       => $s['value'],
                'disporder'   => (int)$s['disporder'],
                'gid'         => $gid,
            ]);
        }
    }
}

function af_advancedpostcounter_lang(): void
{
    global $lang, $mybb;

    if (!isset($lang) || !method_exists($lang, 'load') || !isset($mybb)) {
        return;
    }

    static $loaded = false;
    if ($loaded) {
        return;
    }

    // Языки в AF всегда генерятся ядром из manifest.php и называются:
    // advancedfunctionality_{addon_id}.lang.php
    $addonLang = 'advancedfunctionality_advancedpostcounter';

    $bblang = (string)($mybb->settings['bblanguage'] ?? 'english');
    $base  = MYBB_ROOT . 'inc/languages/' . $bblang . '/';

    $frontFile = $base . $addonLang . '.lang.php';
    $adminFile = $base . 'admin/' . $addonLang . '.lang.php';

    $inAdmin = (defined('IN_ADMINCP') && IN_ADMINCP) || (defined('IN_MODCP') && IN_MODCP);

    // Админский язык — только если реально есть файл (иначе некоторые сборки MyBB ругаются fatally)
    if ($inAdmin && is_file($adminFile)) {
        try {
            $lang->load($addonLang, true);
        } catch (Throwable $e) {
            // ignore
        }
    }

    // Фронтовый язык — аналогично, только если существует
    if (is_file($frontFile)) {
        try {
            $lang->load($addonLang);
        } catch (Throwable $e) {
            // ignore
        }
    }

    $loaded = true;
}

/* -------------------- FORUMS / CATEGORIES -------------------- */

function af_advancedpostcounter_normalize_id_list(string $raw): string
{
    $ids = [];
    foreach (explode(',', $raw) as $p) {
        $v = (int)trim($p);
        if ($v > 0) {
            $ids[$v] = true;
        }
    }
    ksort($ids);
    return implode(',', array_keys($ids));
}

function af_advancedpostcounter_parse_id_setting($raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return [];
    }

    // "all"
    if ($raw === '-1' || strtolower($raw) === 'all') {
        return [-1];
    }

    // Иногда MyBB/моды сохраняют multiselect как serialize(array)
    // Пример: a:2:{i:0;s:2:"12";i:1;s:2:"34";}
    if (($raw[0] === 'a' || $raw[0] === 's') && strpos($raw, ':') !== false) {
        $tmp = @unserialize($raw);
        if (is_array($tmp)) {
            $ids = [];
            foreach ($tmp as $v) {
                $iv = (int)$v;
                if ($iv === -1) {
                    return [-1];
                }
                if ($iv > 0) {
                    $ids[$iv] = true;
                }
            }
            ksort($ids);
            return array_map('intval', array_keys($ids));
        }
    }

    // CSV fallback
    $ids = [];
    foreach (explode(',', $raw) as $p) {
        $iv = (int)trim($p);
        if ($iv === -1) {
            return [-1];
        }
        if ($iv > 0) {
            $ids[$iv] = true;
        }
    }

    ksort($ids);
    return array_map('intval', array_keys($ids));
}


/**
 * Возвращает "корневые" ID из настройки categories (если выбрано),
 * иначе fallback на legacy forums.
 */
function af_advancedpostcounter_get_roots(): array
{
    global $mybb;

    // 1) Primary: categories/forumselect
    $rawCats = $mybb->settings['af_advancedpostcounter_categories'] ?? '';
    $cats = af_advancedpostcounter_parse_id_setting($rawCats);

    if (!empty($cats)) {
        return $cats; // может быть [-1] или список fid
    }

    // 2) Fallback: legacy forums CSV/text
    $rawForums = $mybb->settings['af_advancedpostcounter_forums'] ?? '';
    $forums = af_advancedpostcounter_parse_id_setting($rawForums);

    return $forums;
}

/**
 * Итоговый список форумов (fid), где реально считаем посты.
 * Если выбраны категории — считаем во всех форумах внутри (всё дерево вниз).
 */
function af_advancedpostcounter_get_tracked_forums(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    global $cache, $mybb, $db;

    $roots = af_advancedpostcounter_get_roots();
    if (empty($roots)) {
        $cached = [];
        return $cached;
    }

    // all forums
    if (in_array(-1, $roots, true)) {
        $all = [];

        // 1) try cache
        if (isset($cache) && method_exists($cache, 'read')) {
            $forums = $cache->read('forums');
            if (is_array($forums)) {
                foreach ($forums as $fid => $f) {
                    $fid = (int)$fid;
                    if ($fid > 0) {
                        $all[$fid] = true;
                    }
                }
            }
        }

        // 2) fallback DB if cache empty
        if (empty($all) && isset($db)) {
            $q = $db->simple_select('forums', 'fid', 'fid>0');
            while ($r = $db->fetch_array($q)) {
                $fid = (int)$r['fid'];
                if ($fid > 0) {
                    $all[$fid] = true;
                }
            }
        }

        $cached = array_map('intval', array_keys($all));
        return $cached;
    }

    // categoriesSelected => children ALWAYS
    $rawCats = trim((string)($mybb->settings['af_advancedpostcounter_categories'] ?? ''));
    $categoriesSelected = ($rawCats !== '' && $rawCats !== '-1' && strtolower($rawCats) !== 'all');
    $includeChildren = $categoriesSelected ? true : af_advancedpostcounter_include_children_enabled();

    $tracked = [];
    foreach ($roots as $r) {
        $r = (int)$r;
        if ($r > 0) {
            $tracked[$r] = true;
        }
    }

    if (!$includeChildren) {
        $cached = array_map('intval', array_keys($tracked));
        return $cached;
    }

    // Получаем список форумов с parentlist: cache->forums или DB
    $forums = null;
    if (isset($cache) && method_exists($cache, 'read')) {
        $forums = $cache->read('forums');
        if (!is_array($forums)) {
            $forums = null;
        }
    }

    if ($forums === null && isset($db)) {
        $forums = [];
        $q = $db->simple_select('forums', 'fid,parentlist', 'fid>0');
        while ($r = $db->fetch_array($q)) {
            $fid = (int)$r['fid'];
            if ($fid > 0) {
                $forums[$fid] = [
                    'parentlist' => (string)($r['parentlist'] ?? ''),
                ];
            }
        }
    }

    if (is_array($forums)) {
        foreach ($forums as $fid => $f) {
            $fid = (int)$fid;
            if ($fid <= 0) {
                continue;
            }
            $plRaw = (string)($f['parentlist'] ?? '');
            if ($plRaw === '') {
                continue;
            }

            $pl = ',' . $plRaw . ',';
            foreach ($roots as $rootId) {
                $rootId = (int)$rootId;
                if ($rootId <= 0) {
                    continue;
                }
                if (strpos($pl, ',' . $rootId . ',') !== false) {
                    $tracked[$fid] = true;
                    break;
                }
            }
        }
    }

    $cached = array_map('intval', array_keys($tracked));
    return $cached;
}


function af_advancedpostcounter_is_tracked_fid(int $fid): bool
{
    if ($fid <= 0) return false;
    return in_array($fid, af_advancedpostcounter_get_tracked_forums(), true);
}

/* -------------------- DB UPDATE -------------------- */

function af_advancedpostcounter_update_user_count(int $uid, int $delta): void
{
    global $db;

    if ($uid <= 0 || $delta === 0) return;

    // clamp >= 0
    $expr = "GREATEST(0, ".AF_APC_COL." + (".$delta."))";
    $db->update_query('users', [AF_APC_COL => $expr], "uid='{$uid}'", 1, true);
}

function af_advancedpostcounter_set_user_count(int $uid, int $value): void
{
    global $db;
    if ($uid <= 0) return;

    $v = max(0, (int)$value);
    $db->update_query('users', [AF_APC_COL => $v], "uid='{$uid}'");
}

/* -------------------- REBUILD (SAFE FALLBACK) -------------------- */

function af_advancedpostcounter_schedule_rebuild(): void
{
    if (!empty($GLOBALS['af_apc_rebuild_scheduled'])) {
        return;
    }
    $GLOBALS['af_apc_rebuild_scheduled'] = true;
    register_shutdown_function('af_advancedpostcounter_run_deferred_rebuild');
}

function af_advancedpostcounter_run_deferred_rebuild(): void
{
    if (empty($GLOBALS['af_apc_rebuild_scheduled'])) {
        return;
    }
    af_advancedpostcounter_rebuild_counts();
    unset($GLOBALS['af_apc_rebuild_scheduled']);
}

function af_advancedpostcounter_rebuild_counts(): void
{
    global $db;

    if (!af_advancedpostcounter_is_enabled()) {
        return;
    }

    $fids = af_advancedpostcounter_get_tracked_forums();

    // сброс
    $db->update_query('users', [AF_APC_COL => 0], '1=1');

    if (empty($fids)) {
        return;
    }

    $fidList = implode(',', array_map('intval', $fids));
    $prefix  = TABLE_PREFIX;

    $first_cond = af_advancedpostcounter_count_firstpost_enabled() ? '1=1' : 'p.pid != t.firstpost';

    $q = $db->query("
        SELECT p.uid, COUNT(*) AS c
        FROM {$prefix}posts p
        INNER JOIN {$prefix}threads t ON (t.tid = p.tid)
        WHERE p.visible = 1
          AND t.visible = 1
          AND p.fid IN ({$fidList})
          AND {$first_cond}
        GROUP BY p.uid
    ");

    while ($row = $db->fetch_array($q)) {
        $uid = (int)$row['uid'];
        $cnt = (int)$row['c'];
        if ($uid > 0 && $cnt > 0) {
            af_advancedpostcounter_set_user_count($uid, $cnt);
        }
    }
}


function af_advancedpostcounter_fetch_period_counts(array $uids): array
{
    global $db;

    $out = [];
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
    if (empty($uids)) {
        return $out;
    }

    $fids = af_advancedpostcounter_get_tracked_forums();
    if (empty($fids)) {
        // нет выбранных форумов — просто нули
        foreach ($uids as $u) {
            $out[$u] = ['week' => 0, 'month' => 0];
        }
        return $out;
    }

    $uidList = implode(',', $uids);
    $fidList = implode(',', array_map('intval', $fids));

    $weekTs  = TIME_NOW - 7 * 86400;
    $monthTs = TIME_NOW - 30 * 86400;

    $prefix = TABLE_PREFIX;
    $first_cond = af_advancedpostcounter_count_firstpost_enabled() ? '1=1' : 'p.pid != t.firstpost';

    $q = $db->query("
        SELECT
            p.uid,
            SUM(CASE WHEN p.dateline >= {$weekTs} THEN 1 ELSE 0 END)  AS w,
            SUM(CASE WHEN p.dateline >= {$monthTs} THEN 1 ELSE 0 END) AS m
        FROM {$prefix}posts p
        INNER JOIN {$prefix}threads t ON (t.tid = p.tid)
        WHERE p.visible = 1
          AND t.visible = 1
          AND p.uid IN ({$uidList})
          AND p.fid IN ({$fidList})
          AND {$first_cond}
        GROUP BY p.uid
    ");

    while ($row = $db->fetch_array($q)) {
        $uid = (int)$row['uid'];
        $out[$uid] = [
            'week'  => (int)$row['w'],
            'month' => (int)$row['m'],
        ];
    }

    // заполнить отсутствующих нулями
    foreach ($uids as $u) {
        if (!isset($out[$u])) {
            $out[$u] = ['week' => 0, 'month' => 0];
        }
    }

    return $out;
}

/**
 * Возвращает массив месяцев с количеством постов (по выбранным tracked forums),
 * отсортированный по убыванию месяца.
 *
 * Формат:
 * [
 *   ['ym' => '2026-01', 'count' => 123],
 *   ...
 * ]
 */
function af_advancedpostcounter_fetch_monthly_totals(int $limit = 0): array
{
    global $db;

    $out = [];

    $fids = af_advancedpostcounter_get_tracked_forums();
    if (empty($fids)) {
        return $out;
    }

    $fidList = implode(',', array_map('intval', $fids));
    $prefix  = TABLE_PREFIX;

    // Условие "не считать первый пост темы" (если выключено)
    $first_cond = af_advancedpostcounter_count_firstpost_enabled() ? '1=1' : 'p.pid != t.firstpost';

    // Драйвер-зависимое получение ключа YYYY-MM
    switch ((string)$db->type) {
        case 'pgsql':
            $monthExpr = "to_char(to_timestamp(p.dateline), 'YYYY-MM')";
            break;
        case 'sqlite':
            $monthExpr = "strftime('%Y-%m', p.dateline, 'unixepoch')";
            break;
        default: // mysql/mariadb
            $monthExpr = "DATE_FORMAT(FROM_UNIXTIME(p.dateline), '%Y-%m')";
            break;
    }

    $limitSql = '';
    if ($limit > 0) {
        $limit = (int)$limit;
        // в sqlite/pgsql LIMIT в конце одинаковый
        $limitSql = " LIMIT {$limit} ";
    }

    $q = $db->query("
        SELECT {$monthExpr} AS ym, COUNT(*) AS c
        FROM {$prefix}posts p
        INNER JOIN {$prefix}threads t ON (t.tid = p.tid)
        WHERE p.visible = 1
          AND t.visible = 1
          AND p.uid > 0
          AND p.fid IN ({$fidList})
          AND {$first_cond}
        GROUP BY ym
        ORDER BY ym DESC
        {$limitSql}
    ");

    while ($row = $db->fetch_array($q)) {
        $ym = trim((string)($row['ym'] ?? ''));
        if ($ym === '') {
            continue;
        }
        $out[] = [
            'ym'    => $ym,
            'count' => (int)($row['c'] ?? 0),
        ];
    }

    return $out;
}

/**
 * Превращает "YYYY-MM" в "Месяц YYYY" (рус).
 * Если в $lang есть month_1..month_12 — используем их, иначе fallback на массив.
 */
function af_advancedpostcounter_format_month_label(string $ym): string
{
    global $lang;

    $ym = trim($ym);
    if (!preg_match('~^(\d{4})-(\d{2})$~', $ym, $m)) {
        return $ym;
    }

    $year  = $m[1];
    $month = (int)$m[2];

    $fallback = [
        1=>'Январь', 2=>'Февраль', 3=>'Март', 4=>'Апрель', 5=>'Май', 6=>'Июнь',
        7=>'Июль', 8=>'Август', 9=>'Сентябрь', 10=>'Октябрь', 11=>'Ноябрь', 12=>'Декабрь'
    ];

    $name = $fallback[$month] ?? $ym;

    // MyBB lang обычно хранит month_1..month_12 (в некоторых языках)
    $key = 'month_'.$month;
    if (is_object($lang) && isset($lang->$key) && is_string($lang->$key) && trim($lang->$key) !== '') {
        $name = trim((string)$lang->$key);
    }

    return $name.' '.$year;
}

/* -------------------- DISPLAY (POSTBIT / PROFILE) -------------------- */
function af_advancedpostcounter_postbit(array &$post): void
{
    global $mybb, $templates;

    if (!af_advancedpostcounter_is_enabled() || !af_advancedpostcounter_show_postbit()) {
        $post['advancedpostcounter'] = '';
        return;
    }

    $uid = (int)($post['uid'] ?? 0);
    if ($uid <= 0) {
        $post['advancedpostcounter'] = '';
        return;
    }

    $marker = sprintf(AF_APC_MARK_POST, $uid);

    // Всегда задаём переменную для шаблона (если она там есть)
    $post['advancedpostcounter'] = $marker;

    // Если в используемых постбит-шаблонах уже есть {$post['advancedpostcounter']},
    // то НЕЛЬЗЯ дополнительно инжектить маркер в author_statistics/user_details,
    // иначе получим дубль.
    $tplHasPlaceholder = false;
    $re = '~\{\$post\[(?:\'|")advancedpostcounter(?:\'|")\]\}~i';

    if (isset($templates) && is_object($templates) && method_exists($templates, 'get')) {
        $t1 = (string)$templates->get('postbit');
        $t2 = (string)$templates->get('postbit_classic');

        if (($t1 !== '' && preg_match($re, $t1)) || ($t2 !== '' && preg_match($re, $t2))) {
            $tplHasPlaceholder = true;
        }
    }

    if ($tplHasPlaceholder) {
        return; // шаблон сам выведет переменную — никаких fallback-вставок
    }

    // -------- FALLBACK (только если шаблон НЕ содержит переменную) --------

    // 1) Втыкаем в author_statistics
    if (isset($post['author_statistics']) && is_string($post['author_statistics']) && $post['author_statistics'] !== '') {
        if (strpos($post['author_statistics'], $marker) === false) {
            $post['author_statistics'] .= "\n" . $marker . "\n";
        }
        return;
    }

    // 2) Или в user_details
    if (isset($post['user_details']) && is_string($post['user_details']) && $post['user_details'] !== '') {
        if (strpos($post['user_details'], $marker) === false) {
            $c = 0;
            $post['user_details'] = preg_replace('#</div>\s*$#i', "\n{$marker}\n</div>", $post['user_details'], 1, $c);
            if (!$c) {
                $post['user_details'] .= "\n" . $marker . "\n";
            }
        }
        return;
    }

    // 3) Последний шанс
    if (!isset($post['user_details']) || !is_string($post['user_details'])) {
        $post['user_details'] = $marker;
    } elseif (strpos($post['user_details'], $marker) === false) {
        $post['user_details'] .= "\n" . $marker . "\n";
    }
}

function af_advancedpostcounter_member_profile_end(): void
{
    global $memprofile;

    if (!af_advancedpostcounter_is_enabled() || !af_advancedpostcounter_show_profile()) {
        $memprofile['advancedpostcounter'] = '';
        return;
    }

    $uid = (int)($memprofile['uid'] ?? 0);
    if ($uid <= 0) {
        $memprofile['advancedpostcounter'] = '';
        return;
    }

    $marker = sprintf(AF_APC_MARK_PROF, $uid);

    // Всегда задаём переменную для шаблона member_profile (если она там есть)
    $memprofile['advancedpostcounter'] = $marker;

    // -------- Каноничный путь вывода в member.php: fallback-маркер в $profilefields --------
    if (!isset($GLOBALS['profilefields']) || !is_string($GLOBALS['profilefields'])) {
        return;
    }

    $profilefields = (string)$GLOBALS['profilefields'];

    // Защита от дублей: если маркер/строка APC уже присутствует — ничего не делаем.
    if (strpos($profilefields, $marker) !== false || stripos($profilefields, 'af-apc-count') !== false) {
        return;
    }

    // 1) Пытаемся вставить строго после последнего кастомного поля AdvancedProfileFields.
    $apfRowPattern = '~<tr\s+class="[^"]*\baf-apf-row\b[^"]*"[^>]*>.*?</tr>~si';
    if (preg_match_all($apfRowPattern, $profilefields, $matches, PREG_OFFSET_CAPTURE) && !empty($matches[0])) {
        $lastMatch = $matches[0][count($matches[0]) - 1];
        $insertPos = (int)$lastMatch[1] + strlen((string)$lastMatch[0]);
        $GLOBALS['profilefields'] = substr_replace($profilefields, "\n" . $marker . "\n", $insertPos, 0);
        return;
    }

    // 2) Fallback: перед последним </table>.
    $tableClosePos = strripos($profilefields, '</table>');
    if ($tableClosePos !== false) {
        $GLOBALS['profilefields'] = substr_replace($profilefields, "\n" . $marker . "\n", $tableClosePos, 0);
        return;
    }

    // 3) Fallback: в конец profilefields.
    $GLOBALS['profilefields'] = $profilefields . "\n" . $marker . "\n";
}


/* -------------------- ASSETS + MARKER REPLACE -------------------- */

function af_advancedpostcounter_is_redirect_page(string $page): bool
{
    // грубая, но практичная защита от страниц-редиректов
    if (stripos($page, '<meta http-equiv="refresh"') !== false) return true;
    if (stripos($page, 'id="redirect"') !== false) return true;
    if (stripos($page, '<!-- start: redirect -->') !== false) return true;
    return false;
}


/* -------------------- COUNTING HOOKS -------------------- */

function af_advancedpostcounter_increment_post($posthandler): void
{
    if (!af_advancedpostcounter_is_enabled()) return;

    $fid = (int)($posthandler->data['fid'] ?? 0);
    if ($fid <= 0 || !af_advancedpostcounter_is_tracked_fid($fid)) return;

    $visible = 1;
    if (isset($posthandler->post_insert_data['visible'])) {
        $visible = (int)$posthandler->post_insert_data['visible'];
    } elseif (isset($posthandler->data['visible'])) {
        $visible = (int)$posthandler->data['visible'];
    }
    if ($visible !== 1) return;

    $uid = (int)($posthandler->data['uid'] ?? 0);
    if ($uid > 0) {
        af_advancedpostcounter_update_user_count($uid, +1);
    }
}

function af_advancedpostcounter_increment_thread($posthandler): void
{
    if (!af_advancedpostcounter_is_enabled()) return;

    // первый пост темы: считаем только если включено
    if (!af_advancedpostcounter_count_firstpost_enabled()) return;

    $fid = (int)($posthandler->data['fid'] ?? 0);
    if ($fid <= 0 || !af_advancedpostcounter_is_tracked_fid($fid)) return;

    $visible = 1;
    if (isset($posthandler->post_insert_data['visible'])) {
        $visible = (int)$posthandler->post_insert_data['visible'];
    } elseif (isset($posthandler->data['visible'])) {
        $visible = (int)$posthandler->data['visible'];
    }
    if ($visible !== 1) return;

    $uid = (int)($posthandler->data['uid'] ?? 0);
    if ($uid > 0) {
        af_advancedpostcounter_update_user_count($uid, +1);
    }
}

function af_advancedpostcounter_decrement_post($pid): void
{
    if (!af_advancedpostcounter_is_enabled()) return;

    $post = get_post((int)$pid);
    if (!$post) return;

    $fid = (int)$post['fid'];
    if (!af_advancedpostcounter_is_tracked_fid($fid)) return;

    if ((int)$post['visible'] !== 1) return;

    // если это первый пост темы и мы его не считаем — выходим
    if (!af_advancedpostcounter_count_firstpost_enabled()) {
        $thread = get_thread((int)$post['tid']);
        if ($thread && (int)$thread['firstpost'] === (int)$post['pid']) {
            return;
        }
    }

    $uid = (int)$post['uid'];
    if ($uid > 0) {
        af_advancedpostcounter_update_user_count($uid, -1);
    }
}

function af_advancedpostcounter_on_move_thread($args): void
{
    if (!af_advancedpostcounter_is_enabled()) return;

    global $db;

    $tid = isset($args['tid']) ? (int)$args['tid'] : 0;
    if ($tid <= 0) return;

    $new_fid = 0;
    foreach (['moveto', 'new_fid', 'destination_fid', 'fid'] as $k) {
        if (isset($args[$k])) {
            $new_fid = (int)$args[$k];
            break;
        }
    }
    if ($new_fid <= 0) return;

    $thread = get_thread($tid);
    if (!$thread) return;

    $old_fid = (int)$thread['fid'];
    if ($old_fid === $new_fid) return;

    $oldTracked = af_advancedpostcounter_is_tracked_fid($old_fid);
    $newTracked = af_advancedpostcounter_is_tracked_fid($new_fid);

    if ($oldTracked === $newTracked) {
        return;
    }

    // считаем видимые посты по uid внутри темы и делаем дельту
    $firstpid = (int)$thread['firstpost'];
    $first_cond = af_advancedpostcounter_count_firstpost_enabled() ? '1=1' : "pid!='{$firstpid}'";

    $q = $db->simple_select('posts', 'uid, COUNT(*) AS c',
        "tid='{$tid}' AND visible='1' AND {$first_cond}",
        ['group_by' => 'uid']
    );

    while ($row = $db->fetch_array($q)) {
        $uid = (int)$row['uid'];
        $cnt = (int)$row['c'];
        if ($uid <= 0 || $cnt <= 0) continue;

        $delta = $newTracked ? +$cnt : -$cnt;
        af_advancedpostcounter_update_user_count($uid, $delta);
    }
}

/* -------------------- TEMPLATES -------------------- */
function af_advancedpostcounter_read_templates_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = (string)@file_get_contents($path);
    if (trim($raw) === '') {
        return [];
    }

    $out = [];
    if (preg_match_all('#<!--\s*TEMPLATE:\s*([a-z0-9_]+)\s*-->\s*(.*?)(?=(?:<!--\s*TEMPLATE:\s*[a-z0-9_]+\s*-->)|\z)#si', $raw, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $name = strtolower(trim((string)$match[1]));
            $tpl  = rtrim((string)$match[2]);
            if ($name !== '' && $tpl !== '') {
                $out[$name] = $tpl;
            }
        }
    }

    return $out;
}

function af_advancedpostcounter_templates_source(): array
{
    $base = defined('AF_ADDONS')
        ? AF_ADDONS
        : (MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/');

    $addonBase = rtrim($base, '/\\') . '/advancedpostcounter/';

    // ТЕПЕРЬ source of truth — /templates/advancedpostcounter.html
    // Остальные пути — только fallback, если ты вдруг что-то временно перекинешь.
    $candidates = [
        $addonBase . 'templates/advancedpostcounter.html',
        $addonBase . 'templates/advancedpostcounter_templates.html',
        $addonBase . 'advancedpostcounter.html', // legacy fallback
        $addonBase . 'templates/advancedpostcounter.tpl',
    ];

    foreach ($candidates as $path) {
        $tpls = af_advancedpostcounter_read_templates_file($path);
        if (!empty($tpls)) {
            return $tpls;
        }
    }

    return [];
}

function af_apc_templates_update_tid(int $tid, string $template, bool $touchDateline = true): void
{
    global $db;

    if ($tid <= 0) {
        return;
    }

    $tplEsc = $db->escape_string($template);

    $sql = "
        UPDATE ".TABLE_PREFIX."templates
        SET template='{$tplEsc}'".($touchDateline ? ", dateline='".(int)TIME_NOW."'" : "")."
        WHERE tid='".(int)$tid."'
        LIMIT 1
    ";

    $db->write_query($sql);
}

function af_apc_templates_insert_master(string $title, string $template): void
{
    global $db;

    $title = strtolower(trim($title));
    if ($title === '') {
        return;
    }

    $titleEsc = $db->escape_string($title);
    $tplEsc   = $db->escape_string($template);

    $sql = "
        INSERT INTO ".TABLE_PREFIX."templates
            (title, template, sid, version, dateline)
        VALUES
            ('{$titleEsc}', '{$tplEsc}', -1, '1', '".(int)TIME_NOW."')
    ";

    $db->write_query($sql);
}

function af_advancedpostcounter_templates_install(): void
{
    global $db;

    $tpls = af_advancedpostcounter_templates_source();
    if (!empty($tpls)) {
        foreach ($tpls as $title => $html) {
            $title = strtolower(trim((string)$title));
            $html  = (string)$html;

            if ($title === '' || $html === '') {
                continue;
            }

            $titleEsc = $db->escape_string($title);

            // Обновляем ВСЕ sid-копии (и master, и активные templateset'ы)
            $q = $db->simple_select('templates', 'tid', "title='{$titleEsc}'");
            $found = false;

            while ($row = $db->fetch_array($q)) {
                $found = true;
                af_apc_templates_update_tid((int)$row['tid'], $html, true);
            }

            // Если такого шаблона не было — создаём master (sid=-1)
            if (!$found) {
                af_apc_templates_insert_master($title, $html);
            }
        }
    }

    // Чистим legacy-вставки {$memprofile['advancedpostcounter']} из customfields-шаблонов.
    af_advancedpostcounter_cleanup_legacy_profile_placeholders();

    // -------------------- postbit / postbit_classic: вставляем {$post['advancedpostcounter']} после {$post['user_details']} --------------------
    $needle_post = '{$post[\'advancedpostcounter\']}';

    foreach (['postbit', 'postbit_classic'] as $pbTitle) {
        $qpb = $db->simple_select('templates', 'tid,template', "title='{$pbTitle}'");
        while ($r = $db->fetch_array($qpb)) {
            $tid = (int)$r['tid'];
            $tpl = (string)$r['template'];

            if ($tid <= 0 || $tpl === '') {
                continue;
            }

            if (strpos($tpl, $needle_post) !== false) {
                continue; // уже вставлено
            }

            $count = 0;
            // Вставляем сразу после {$post['user_details']} внутри author_statistics (у тебя это ровно то место)
            $pattern = '#(\{\$post\[(?:\'|")user_details(?:\'|")\]\})#i';
            $new = preg_replace($pattern, '$1' . "\n" . $needle_post . "\n", $tpl, 1, $count);

            if ($count && is_string($new) && $new !== '' && $new !== $tpl) {
                af_apc_templates_update_tid($tid, $new, false);
            }
        }
    }

    if (function_exists('cache_templatesets')) {
        cache_templatesets();
    }
}

function af_advancedpostcounter_templates_uninstall(): void
{
    global $db;

    require_once MYBB_ROOT.'inc/adminfunctions_templates.php';

    // профильные шаблоны: убираем нашу переменную
    find_replace_templatesets('member_profile_customfields', "#\\{\\$memprofile\\['advancedpostcounter'\\]\\}#i", '');
    find_replace_templatesets('member_profile_customfields_field', "#\\{\\$memprofile\\['advancedpostcounter'\\]\\}#i", '');
    find_replace_templatesets('member_profile', "#\\{\\$memprofile\\['advancedpostcounter'\\]\\}#i", '');

    // postbit/postbit_classic: убираем {$post['advancedpostcounter']}
    find_replace_templatesets('postbit', "#\\{\\$post\\['advancedpostcounter'\\]\\}#i", '');
    find_replace_templatesets('postbit_classic', "#\\{\\$post\\['advancedpostcounter'\\]\\}#i", '');

    // удаляем наши шаблоны (master)
    $db->delete_query(
        'templates',
        "title IN (
            'advancedpostcounter_bit',
            'advancedpostcounter_profile_bit',
            'advancedpostcounter_postsactivity',
            'advancedpostcounter_postsactivity_row',
            'af_apc_postsactivity_page',
            'advancedpostcounter_postsbyuser',
            'advancedpostcounter_postsbyuser_row',
            'af_apc_postsbyuser_page'
        ) AND sid='-1'"
    );
}

function af_advancedpostcounter_pages_install(): void
{
    $aliases = [
        [
            'src' => MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/advancedpostcounter/assets/postsactivity.php',
            'dst' => MYBB_ROOT . 'postsactivity.php',
            'signature' => 'AF_APC_PAGE_ALIAS',
        ],
        [
            'src' => MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/advancedpostcounter/assets/postsbyuser.php',
            'dst' => MYBB_ROOT . 'postsbyuser.php',
            'signature' => 'AF_APC_POSTSBYUSER_ALIAS',
        ],
    ];

    foreach ($aliases as $alias) {
        $src = (string)$alias['src'];
        $dst = (string)$alias['dst'];
        $signature = (string)$alias['signature'];

        if (!file_exists($src)) {
            continue;
        }

        $srcCode = @file_get_contents($src);
        if ($srcCode === false || trim($srcCode) === '') {
            continue;
        }

        if (file_exists($dst)) {
            $dstCode = @file_get_contents($dst);
            if ($dstCode !== false && strpos($dstCode, $signature) === false) {
                continue;
            }
            if ($dstCode !== false && hash('sha256', $dstCode) === hash('sha256', $srcCode)) {
                continue;
            }
        }

        @file_put_contents($dst, $srcCode);
    }
}

function af_advancedpostcounter_pages_uninstall(): void
{
    $aliases = [
        [
            'dst' => MYBB_ROOT . 'postsactivity.php',
            'signature' => 'AF_APC_PAGE_ALIAS',
        ],
        [
            'dst' => MYBB_ROOT . 'postsbyuser.php',
            'signature' => 'AF_APC_POSTSBYUSER_ALIAS',
        ],
    ];

    foreach ($aliases as $alias) {
        $dst = (string)$alias['dst'];
        $signature = (string)$alias['signature'];

        if (!file_exists($dst)) {
            continue;
        }

        $code = @file_get_contents($dst);
        if ($code === false || strpos($code, $signature) === false) {
            continue;
        }

        @unlink($dst);
    }
}


/* -------------------- INIT -------------------- */
function af_advancedpostcounter_init(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // язык можно грузить здесь — init точно вызывается ядром AF
    af_advancedpostcounter_lang();

    // не регистрируем дважды
    if (!empty($GLOBALS['af_apc_mybb_hooks_registered'])) {
        return;
    }

    $p = $GLOBALS['plugins'] ?? null;
    if (!is_object($p) || !method_exists($p, 'add_hook')) {
        return;
    }

    $GLOBALS['af_apc_mybb_hooks_registered'] = true;

    /* -------- counting hooks -------- */
    $p->add_hook('datahandler_post_insert_post',   'af_advancedpostcounter_increment_post');
    $p->add_hook('datahandler_post_insert_thread', 'af_advancedpostcounter_increment_thread');
    $p->add_hook('class_moderation_delete_post',   'af_advancedpostcounter_decrement_post');

    // сложные мод-операции => ребилд
    $p->add_hook('class_moderation_soft_delete_posts',   'af_advancedpostcounter_schedule_rebuild');
    $p->add_hook('class_moderation_restore_posts',       'af_advancedpostcounter_schedule_rebuild');
    $p->add_hook('class_moderation_delete_thread_start', 'af_advancedpostcounter_schedule_rebuild');
    $p->add_hook('class_moderation_soft_delete_threads', 'af_advancedpostcounter_schedule_rebuild');
    $p->add_hook('class_moderation_restore_threads',     'af_advancedpostcounter_schedule_rebuild');
    $p->add_hook('class_moderation_approve_posts',       'af_advancedpostcounter_schedule_rebuild');
    $p->add_hook('class_moderation_unapprove_posts',     'af_advancedpostcounter_schedule_rebuild');
    $p->add_hook('class_moderation_approve_threads',     'af_advancedpostcounter_schedule_rebuild');
    $p->add_hook('class_moderation_unapprove_threads',   'af_advancedpostcounter_schedule_rebuild');
    $p->add_hook('class_moderation_merge_threads',       'af_advancedpostcounter_schedule_rebuild');
    $p->add_hook('class_moderation_copy_thread',         'af_advancedpostcounter_schedule_rebuild');
    $p->add_hook('class_moderation_split_posts',         'af_advancedpostcounter_schedule_rebuild');

    // перенос темы — дельта/ребилд
    $p->add_hook('class_moderation_move_simple',          'af_advancedpostcounter_on_move_thread');
    $p->add_hook('class_moderation_move_thread_redirect', 'af_advancedpostcounter_on_move_thread');
    $p->add_hook('class_moderation_move_threads',         'af_advancedpostcounter_schedule_rebuild');

    /* -------- display hooks -------- */
    $p->add_hook('postbit',             'af_advancedpostcounter_postbit');
    $p->add_hook('member_profile_end',  'af_advancedpostcounter_member_profile_end');
    $p->add_hook('xmlhttp',             'af_advancedpostcounter_xmlhttp');

    /* -------- marker replace (страховка) --------
       Даже если AF ядро само вызывает *_pre_output, этот хук лишним не будет,
       но наш pre_output защищён от дублей/редиректов и работает пачкой.
    */
    $p->add_hook('pre_output_page', 'af_advancedpostcounter_pre_output');
}


function af_advancedpostcounter_pre_output(&$page): void
{
    global $mybb, $db, $lang;

    if (!af_advancedpostcounter_is_enabled()) {
        return;
    }

    // Не трогаем redirect-страницы
    if (af_advancedpostcounter_is_redirect_page((string)$page)) {
        return;
    }

    // Cупер-дешёвый early return: если APC-маркеров нет, дальше ничего не делаем.
    if (strpos((string)$page, '<af_apc_') === false) {
        return;
    }

    // -------------------- 1) Подключаем ассеты с filemtime-версией --------------------
    $shouldLoadAssets = af_apc_should_load_assets_for_page((string)$page);

    if (strpos((string)$page, 'advancedpostcounter.') !== false) {
        // Удаляем предыдущие инжекты APC (включая старые ?v), чтобы в финале оставить только каноничную версию.
        $page = (string)preg_replace('~<link\b[^>]*href=["\"][^"\"]*advancedpostcounter\.css(?:\?[^"\"]*)?["\"][^>]*>\s*~iu', '', (string)$page);
        $page = (string)preg_replace('~<script\b[^>]*src=["\"][^"\"]*advancedpostcounter\.js(?:\?[^"\"]*)?["\"][^>]*>\s*</script>\s*~iu', '', (string)$page);
    }

    if ($shouldLoadAssets) {
        $css = af_apc_build_asset_url('advancedpostcounter.css');
        $js  = af_apc_build_asset_url('advancedpostcounter.js');

        if ($css !== '' && stripos((string)$page, '</head>') !== false) {
            $page = preg_replace(
                '~</head>~i',
                '<link rel="stylesheet" href="' . htmlspecialchars_uni($css) . '" />' . "\n</head>",
                (string)$page,
                1
            );
        }

        if ($js !== '' && stripos((string)$page, '</body>') !== false) {
            $page = preg_replace(
                '~</body>~i',
                '<script src="' . htmlspecialchars_uni($js) . '"></script>' . "\n</body>",
                (string)$page,
                1
            );
        }
    }

    if (strpos((string)$page, 'advancedpostcounter.') !== false) {
        af_apc_dedupe_assets_in_html($page);
    }

    // -------------------- 2) Ищем маркеры на странице --------------------
    $uids = [];

    if (preg_match_all('#<af_apc_uid_(\d+)>#', $page, $m1)) {
        foreach ($m1[1] as $u) {
            $uids[(int)$u] = true;
        }
    }
    if (preg_match_all('#<af_apc_profile_uid_(\d+)>#', $page, $m2)) {
        foreach ($m2[1] as $u) {
            $uids[(int)$u] = true;
        }
    }

    if (empty($uids)) {
        return; // нечего заменять
    }

    $uids = array_keys($uids);
    $uids = array_values(array_filter(array_map('intval', $uids), static fn($v) => $v > 0));
    if (empty($uids)) {
        return;
    }

    // -------------------- 3) Достаём totals из users + week/month из posts --------------------
    $totals = [];
    $uidList = implode(',', $uids);

    $q = $db->simple_select('users', 'uid,' . AF_APC_COL, "uid IN ({$uidList})");
    while ($r = $db->fetch_array($q)) {
        $totals[(int)$r['uid']] = (int)$r[AF_APC_COL];
    }

    $periods = af_advancedpostcounter_fetch_period_counts($uids);

    // -------------------- 4) Рендерим HTML и заменяем маркеры --------------------
    $repl = [];

    foreach ($uids as $uid) {
        $snapshot = af_apc_build_snapshot_payload($uid, (int)($totals[$uid] ?? 0), (int)($periods[$uid]['week'] ?? 0), (int)($periods[$uid]['month'] ?? 0));
        $bitHtml = af_apc_render_postbit_html($snapshot);

        $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
        $findPostsLabel = 'Найти все посты';
        if (!empty($lang->af_apc_find_posts)) {
            $findPostsLabel = (string)$lang->af_apc_find_posts;
        }
        $findPostsUrl = ($bburl !== '' ? $bburl : '') . '/postsbyuser.php?uid=' . (int)$uid;
        $findPostsLink = '<a class="smalltext" href="' . htmlspecialchars_uni($findPostsUrl) . '">(' . htmlspecialchars_uni($findPostsLabel) . ')</a>';

        // Профильная строка (табличная)
        $profileHtml = '<tr>'
            . '<td class="trow1"><strong>' . $snapshot['label_plain'] . '</strong></td>'
            . '<td class="trow1">'
                . '<span class="af-apc-count">'
                    . '<span class="af-apc-num">' . $snapshot['total_formatted'] . '</span>'
                    . ' ' . $findPostsLink
                . '</span>'
            . '</td>'
        . '</tr>';

        $repl[sprintf(AF_APC_MARK_POST, $uid)] = $bitHtml;
        $repl[sprintf(AF_APC_MARK_PROF, $uid)] = $profileHtml;
    }

    // strtr быстрее пачкой, чем 1000 str_replace
    $page = strtr($page, $repl);
}

function af_apc_build_snapshot_payload(int $uid, int $total, int $week, int $month): array
{
    global $mybb;

    $labelPlain = 'Постов:';
    $labelHtml = '<span class="af-apc-label-text">' . htmlspecialchars_uni($labelPlain) . '</span>';

    $settingLabelHtml = af_apc_sanitize_label_html((string)($mybb->settings['af_advancedpostcounter_postbit_label_html'] ?? ''), 200);
    if ($settingLabelHtml !== '') {
        $labelHtml = $settingLabelHtml;
    }

    $tooltip = 'за месяц: ' . my_number_format($month) . ' || за неделю: ' . my_number_format($week);

    return [
        'uid' => $uid,
        'total' => $total,
        'week' => $week,
        'month' => $month,
        'tooltip' => $tooltip,
        'label_plain' => htmlspecialchars_uni($labelPlain),
        'label_html' => $labelHtml,
        'total_formatted' => htmlspecialchars_uni(my_number_format($total)),
    ];
}

function af_apc_sanitize_label_html(string $value, int $maxLen = 200): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = substr($value, 0, max(1, $maxLen));
    $value = preg_replace('~<\s*script\b~iu', '', $value);
    $value = preg_replace('~on(?:error|load)\s*=~iu', '', $value);
    $value = preg_replace('~javascript\s*:\s*~iu', '', $value);

    return trim((string)$value);
}

function af_apc_render_postbit_html(array $snapshot): string
{
    $uid = (int)($snapshot['uid'] ?? 0);
    $tooltip = htmlspecialchars_uni((string)($snapshot['tooltip'] ?? ''));
    $labelHtml = (string)($snapshot['label_html'] ?? '<span class="af-apc-label-text">Постов:</span>');
    $totalFormatted = htmlspecialchars_uni((string)($snapshot['total_formatted'] ?? '0'));

    return '<div class="af-apc" data-af-apc="1" data-uid="' . $uid . '" title="' . $tooltip . '" data-af-title="' . $tooltip . '">'
        . '<span class="af-apc-label">' . $labelHtml . '</span> '
        . '<span class="af-apc-count">'
            . '<span class="af-apc-num">' . $totalFormatted . '</span>'
        . '</span>'
    . '</div>';
}

function af_advancedpostcounter_xmlhttp(): void
{
    global $mybb, $db;

    $action = (string)($mybb->input['action'] ?? '');
    if ($action !== 'af_apc_snapshot') {
        return;
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $uid = (int)($mybb->input['uid'] ?? 0);
    if ($uid <= 0) {
        echo json_encode(['success' => false, 'error' => 'Bad uid'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $user = $db->fetch_array($db->simple_select('users', 'uid,' . AF_APC_COL, 'uid=' . $uid, ['limit' => 1]));
    $dbUid = (int)($user['uid'] ?? 0);
    if ($dbUid <= 0) {
        echo json_encode(['success' => false, 'error' => 'User not found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $periods = af_advancedpostcounter_fetch_period_counts([$dbUid]);
    $week = (int)($periods[$dbUid]['week'] ?? 0);
    $month = (int)($periods[$dbUid]['month'] ?? 0);
    $total = (int)($user[AF_APC_COL] ?? 0);

    $snapshot = af_apc_build_snapshot_payload($dbUid, $total, $week, $month);

    echo json_encode([
        'success' => true,
        'uid' => $dbUid,
        'total' => $total,
        'week' => $week,
        'month' => $month,
        'label_html' => (string)$snapshot['label_html'],
        'tooltip' => (string)$snapshot['tooltip'],
        'html' => af_apc_render_postbit_html($snapshot),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Рендер страницы /postsactivity.php
 * Канон: полный HTML-документ одним output_page($page).
 */
function af_advancedpostcounter_render_postsactivity_page(): void
{
    global $mybb, $db, $lang, $templates, $theme;
    global $headerinclude, $header, $footer;

    if (empty($mybb->settings['af_advancedpostcounter_enabled'])) {
        error_no_permission();
    }

    // доступ хотя бы тем, кто может смотреть memberlist (логично для “статистики пользователей”)
    if (empty($mybb->usergroup) || (int)($mybb->usergroup['canviewmemberlist'] ?? 0) !== 1) {
        error_no_permission();
    }

    // Язык аддона (если у тебя другой файл — поменяй имя тут)
    if (!is_object($lang)) {
        $lang = new MyLanguage();
    }
    $lang->load('advancedfunctionality_advancedpostcounter');

    $t = function(string $key, string $fallback = '') use ($lang): string {
        $v = '';
        if (is_object($lang) && isset($lang->$key) && is_string($lang->$key)) {
            $v = trim($lang->$key);
        }
        if ($v !== '') return $v;
        if ($fallback !== '') return $fallback;
        return $key;
    };

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');

    $pageTitle = $t('af_apc_page_title', 'Активность постов');
    add_breadcrumb($pageTitle, 'postsactivity.php');

    // пагинация
    $per_page = (int)$mybb->get_input('perpage', MyBB::INPUT_INT);
    if ($per_page < 1) $per_page = 20;
    if ($per_page > 200) $per_page = 200;

    $pageNum = (int)$mybb->get_input('page', MyBB::INPUT_INT);
    if ($pageNum < 1) $pageNum = 1;
    $start = ($pageNum - 1) * $per_page;

    // сортировка
    $sort = strtolower((string)$mybb->get_input('sort'));
    $order = strtolower((string)$mybb->get_input('order'));
    $orderSql = ($order === 'asc') ? 'ASC' : 'DESC';

    switch ($sort) {
        case 'username':
            $sortField = 'u.username';
            break;
        default:
            // по умолчанию — по нашему счётчику
            $sortField = 'u.' . AF_APC_COL;
            $sort = 'count';
            break;
    }

    // скрытые группы (showmemberlist=0) — как в memberlist, чтоб не светить тех, кого нельзя
    $where = "1=1";
    if (isset($GLOBALS['cache']) && is_object($GLOBALS['cache'])) {
        $ug = $GLOBALS['cache']->read('usergroups');
        if (is_array($ug)) {
            $hidden = [];
            foreach ($ug as $gid => $g) {
                if ((int)($g['showmemberlist'] ?? 1) === 0) {
                    $hidden[] = (int)$gid;
                }
            }
            if ($hidden) {
                $h = implode(',', $hidden);
                $where .= " AND u.usergroup NOT IN ({$h})";
                foreach ($hidden as $hidegid) {
                    switch ($db->type) {
                        case 'pgsql':
                        case 'sqlite':
                            $where .= " AND ','||u.additionalgroups||',' NOT LIKE '%,{$hidegid},%'";
                            break;
                        default:
                            $where .= " AND CONCAT(',',u.additionalgroups,',') NOT LIKE '%,{$hidegid},%'";
                            break;
                    }
                }
            }
        }
    }

    // всего
    $qTotal = $db->simple_select('users u', 'COUNT(*) AS c', $where);
    $total = (int)$db->fetch_field($qTotal, 'c');

    $baseUrl = 'postsactivity.php?sort=' . urlencode($sort) . '&order=' . urlencode($order) . '&perpage=' . (int)$per_page;
    $multipage = multipage($total, $per_page, $pageNum, $baseUrl);

    // данные
    $sql = "
        SELECT u.uid, u.username, u." . AF_APC_COL . " AS apc_count
        FROM " . TABLE_PREFIX . "users u
        WHERE {$where}
        ORDER BY {$sortField} {$orderSql}
        LIMIT " . (int)$start . ", " . (int)$per_page . "
    ";
    $res = $db->query($sql);

    $rows = '';
    $i = 0;

    while ($r = $db->fetch_array($res)) {
        $i++;
        $rowClass = ($i % 2 === 0) ? 'trow2' : 'trow1';

        $uid = (int)($r['uid'] ?? 0);
        $username = (string)($r['username'] ?? '');
        $cnt = (int)($r['apc_count'] ?? 0);

        if ($uid <= 0 || $username === '') continue;

        $profileUrl = $bburl . '/member.php?action=profile&uid=' . $uid;
        $userLink = '<a href="' . htmlspecialchars_uni($profileUrl) . '"><strong>' . htmlspecialchars_uni($username) . '</strong></a>';

        $rows .= '
        <tr>
            <td class="' . $rowClass . '">' . $userLink . '</td>
            <td class="' . $rowClass . '" style="text-align:center;white-space:nowrap;">' . my_number_format($cnt) . '</td>
        </tr>';
    }

    if (trim($rows) === '') {
        $rows = '<tr><td class="trow1" colspan="2"><em>' . htmlspecialchars_uni($t('af_apc_empty', 'Нет данных.')) . '</em></td></tr>';
    }

    $thUser  = htmlspecialchars_uni($t('af_apc_th_user', 'Пользователь'));
    $thCount = htmlspecialchars_uni($t('af_apc_th_count', 'Игровых постов'));

    $content = '
    <div class="af-apc-page">
        <h1 style="margin:0 0 10px 0;">' . htmlspecialchars_uni($pageTitle) . '</h1>
        ' . $multipage . '
        <table class="tborder" cellspacing="' . (int)($theme['borderwidth'] ?? 1) . '" cellpadding="' . (int)($theme['tablespace'] ?? 6) . '" border="0">
            <tr>
                <td class="thead">' . $thUser . '</td>
                <td class="thead" style="text-align:center;white-space:nowrap;">' . $thCount . '</td>
            </tr>
            ' . $rows . '
        </table>
        ' . $multipage . '
    </div>';

    // headerinclude/header/footer собираем ПОСЛЕ breadcrumbs
    if (is_object($templates)) {
        if (empty($headerinclude)) { eval('$headerinclude = "'.$templates->get('headerinclude').'";'); }
        if (empty($header))        { eval('$header        = "'.$templates->get('header').'";'); }
        if (empty($footer))        { eval('$footer        = "'.$templates->get('footer').'";'); }
    }

    // Если есть шаблон полного документа — используем его (как в AAS)
    $page = '';
    if (is_object($templates) && $templates->get('af_apc_postsactivity_page') !== '') {
        $af_apc_page_title = $pageTitle;
        $af_apc_content = $content;
        eval('$page = "'.$templates->get('af_apc_postsactivity_page').'";');
        output_page($page);
        exit;
    }

    // fallback: полный документ одним куском
    $page = '<!DOCTYPE html>
    <html>
    <head>
        <title>' . htmlspecialchars_uni($pageTitle) . ' - ' . htmlspecialchars_uni((string)$mybb->settings['bbname']) . '</title>
        ' . $headerinclude . '
    </head>
    <body>
    ' . $header . '
    ' . $content . '
    ' . $footer . '
    </body>
    </html>';

    output_page($page);
    exit;
}


function af_advancedpostcounter_admin_selfheal(): void
{
    if (!defined('IN_ADMINCP')) {
        return;
    }

    global $db;

    /* ---------- 1) SETTINGS SELFHEAL ---------- */

    $q = $db->simple_select('settings', 'sid,optionscode', "name='af_advancedpostcounter_categories'", ['limit' => 1]);
    $row = $db->fetch_array($q);

    if ($row) {
        $oc = trim((string)($row['optionscode'] ?? ''));
        if ($oc !== 'forumselect') {
            af_advancedpostcounter_ensure_settings();
            if (function_exists('rebuild_settings')) {
                rebuild_settings();
            }
        }
    } else {
        af_advancedpostcounter_ensure_settings();
        if (function_exists('rebuild_settings')) {
            rebuild_settings();
        }
    }

    /* ---------- 2) TEMPLATES SELFHEAL ---------- */

    af_advancedpostcounter_cleanup_legacy_profile_placeholders();

    $needFix = false;

    // Проверяем ВСЕ копии шаблона в templatesets (sid=-1 и sid>=0)
    $qt = $db->simple_select(
        'templates',
        'tid,sid,title,template',
        "title='advancedpostcounter_postsactivity'"
    );

    $foundAny = false;

    while ($tr = $db->fetch_array($qt)) {
        $foundAny = true;
        $t = (string)($tr['template'] ?? '');

        // a) признак старого кривого шаблона: вложенный #content/.wrapper
        if (stripos($t, 'id="content"') !== false || stripos($t, "id='content'") !== false) {
            $needFix = true;
            break;
        }
        if (stripos($t, 'class="wrapper"') !== false || stripos($t, "class='wrapper'") !== false) {
            $needFix = true;
            break;
        }

        // b) признак “не тот шаблон”: у тебя должен быть контейнер af-apc-activity-page
        if (stripos($t, 'af-apc-activity-page') === false) {
            $needFix = true;
            break;
        }

        // c) старое двойное экранирование
        if (strpos($t, '\&quot;') !== false || strpos($t, '\\"') !== false || strpos($t, '&quot;') !== false) {
            $needFix = true;
            break;
        }
    }

    // если шаблона вообще нет — тоже фикс
    if (!$foundAny) {
        $needFix = true;
    }

    if ($needFix) {
        af_advancedpostcounter_templates_install();

        if (function_exists('cache_templatesets')) {
            cache_templatesets();
        }
    }

    /* ---------- 3) PAGES SELFHEAL/UPDATE (только если это НАШИ alias-файлы) ---------- */

    $shouldInstall = false;
    $aliases = [
        [
            'dst' => MYBB_ROOT . 'postsactivity.php',
            'signature' => 'AF: AdvancedPostCounter postsactivity page',
        ],
        [
            'dst' => MYBB_ROOT . 'postsbyuser.php',
            'signature' => 'AF: AdvancedPostCounter postsbyuser page',
        ],
    ];

    foreach ($aliases as $alias) {
        $dst = (string)$alias['dst'];
        $signature = (string)$alias['signature'];

        if (!is_file($dst)) {
            $shouldInstall = true;
            continue;
        }

        $existing = (string)@file_get_contents($dst);
        if ($existing !== '' && strpos($existing, $signature) !== false) {
            $shouldInstall = true;
        }
    }

    if ($shouldInstall) {
        af_advancedpostcounter_pages_install();
    }
}

function af_advancedpostcounter_postsactivity_page(): void
{
    global $mybb, $db, $lang, $templates, $theme, $cache;
    global $headerinclude, $header, $footer;

    if (!af_advancedpostcounter_is_enabled()) {
        error_no_permission();
    }

    // Доступ: кто видит memberlist
    if ((int)($mybb->usergroup['canviewmemberlist'] ?? 0) !== 1) {
        error_no_permission();
    }

    af_advancedpostcounter_lang();

    $activity_title = 'Активность постов';
    if (!empty($lang->af_apc_postsactivity_title)) {
        $activity_title = (string)$lang->af_apc_postsactivity_title;
    } elseif (!empty($lang->af_advancedpostcounter_postsactivity_title)) {
        $activity_title = (string)$lang->af_advancedpostcounter_postsactivity_title;
    }

    add_breadcrumb($activity_title, 'postsactivity.php');

    // headerinclude/header/footer как в AAS
    af_apc_ensure_header_bits();

    $esc = static function ($s): string {
        return function_exists('htmlspecialchars_uni')
            ? htmlspecialchars_uni((string)$s)
            : htmlspecialchars((string)$s, ENT_QUOTES);
    };

    // tracked forums
    $trackedFids = af_advancedpostcounter_get_tracked_forums();
    $fidList = !empty($trackedFids) ? implode(',', array_map('intval', $trackedFids)) : '';

    /* -------------------- INPUT -------------------- */

    $perpage = (int)$mybb->get_input('perpage', MyBB::INPUT_INT);
    if ($perpage < 1) $perpage = 20;
    if ($perpage > 200) $perpage = 200;

    $pageNum = (int)$mybb->get_input('page', MyBB::INPUT_INT);
    if ($pageNum < 1) $pageNum = 1;
    $start = ($pageNum - 1) * $perpage;

    $sort  = strtolower(trim((string)$mybb->get_input('sort', MyBB::INPUT_STRING)));
    $order = strtolower(trim((string)$mybb->get_input('order', MyBB::INPUT_STRING)));
    $only  = strtolower(trim((string)$mybb->get_input('only', MyBB::INPUT_STRING)));
    $days  = (int)$mybb->get_input('days', MyBB::INPUT_INT);
    $q     = trim((string)$mybb->get_input('q', MyBB::INPUT_STRING));

    $allowedSort  = ['last', 'total', 'month', 'week', 'user', 'reg'];
    $allowedOrder = ['asc', 'desc'];
    $allowedOnly  = ['all', 'active', 'inactive', 'zero', 'has'];

    if (!in_array($sort, $allowedSort, true))   $sort  = 'last';
    if (!in_array($order, $allowedOrder, true)) $order = 'desc';
    if (!in_array($only, $allowedOnly, true))   $only  = 'all';
    if ($days <= 0) $days = 30;

    $thresholdTs = TIME_NOW - ($days * 86400);
    $orderSql = ($order === 'asc') ? 'ASC' : 'DESC';

    /* -------------------- HIDDEN GROUPS (как memberlist) -------------------- */

    $where = "u.uid > 0";
    if (isset($cache) && is_object($cache) && method_exists($cache, 'read')) {
        $ug = $cache->read('usergroups');
        if (is_array($ug)) {
            $hidden = [];
            foreach ($ug as $gid => $g) {
                if ((int)($g['showmemberlist'] ?? 1) === 0) {
                    $hidden[] = (int)$gid;
                }
            }
            if (!empty($hidden)) {
                $h = implode(',', $hidden);
                $where .= " AND u.usergroup NOT IN ({$h})";

                foreach ($hidden as $hidegid) {
                    switch ($db->type) {
                        case 'pgsql':
                        case 'sqlite':
                            $where .= " AND ','||u.additionalgroups||',' NOT LIKE '%,{$hidegid},%'";
                            break;
                        default:
                            $where .= " AND CONCAT(',',u.additionalgroups,',') NOT LIKE '%,{$hidegid},%'";
                            break;
                    }
                }
            }
        }
    }

    if ($q !== '') {
        $qLike = method_exists($db, 'escape_string_like')
            ? $db->escape_string_like($q)
            : $db->escape_string($q);
        $where .= " AND u.username LIKE '%{$qLike}%'";
    }

    // если форумы не выбраны — отдадим страницу, но месяцы будут пустые
    $months_rows = '';
    $months_total = 0;

    if ($fidList !== '') {
        // собираем вкладку "по месяцам"
        // Можно ограничить, например, 120 месяцев (~10 лет). Не просила — оставляю 0 = без лимита.
        $months = af_advancedpostcounter_fetch_monthly_totals(0);

        if (!empty($months)) {
            $i = 0;
            foreach ($months as $m) {
                $i++;
                $row_bg = ($i % 2 === 0) ? 'trow2' : 'trow1';
                $label = af_advancedpostcounter_format_month_label((string)$m['ym']);
                $cnt   = (int)($m['count'] ?? 0);
                $months_total += $cnt;

                $months_rows .= '<tr>'
                    .'<td class="'.$row_bg.'">'.$esc($label).'</td>'
                    .'<td class="'.$row_bg.'" style="text-align:center;white-space:nowrap;">'.my_number_format($cnt).'</td>'
                    .'</tr>';
            }

            // строка ИТОГО
            $months_rows .= '<tr>'
                .'<td class="tcat"><strong>'.$esc('ВСЕГО:').'</strong></td>'
                .'<td class="tcat" style="text-align:center;white-space:nowrap;"><strong>'.my_number_format($months_total).'</strong></td>'
                .'</tr>';
        } else {
            $months_rows = '<tr><td class="trow1" colspan="2"><em>'.$esc('Нет данных.').'</em></td></tr>';
        }
    } else {
        $months_rows = '<tr><td class="trow1" colspan="2"><em>'.$esc('Не выбраны категории/форумы для подсчёта.').'</em></td></tr>';
    }

    /* -------------------- USER TABLE (первая вкладка) -------------------- */

    $prefix = TABLE_PREFIX;
    $firstCond = af_advancedpostcounter_count_firstpost_enabled() ? '1=1' : 'p.pid != t.firstpost';

    // lastpost join (нужен для sort/active/inactive)
    $joins = "
        LEFT JOIN (
            SELECT p.uid, MAX(p.dateline) AS lastpost
            FROM {$prefix}posts p
            INNER JOIN {$prefix}threads t ON (t.tid = p.tid)
            WHERE p.visible = 1
              AND t.visible = 1
              AND p.uid > 0
              ".($fidList !== '' ? " AND p.fid IN ({$fidList}) " : "")."
              AND {$firstCond}
            GROUP BY p.uid
        ) apclp ON (apclp.uid = u.uid)
    ";

    if ($only === 'zero') {
        $where .= " AND u.".AF_APC_COL." = 0";
    } elseif ($only === 'has') {
        $where .= " AND u.".AF_APC_COL." > 0";
    } elseif ($only === 'inactive') {
        $where .= " AND COALESCE(apclp.lastpost, 0) > 0 AND COALESCE(apclp.lastpost, 0) < {$thresholdTs}";
    } elseif ($only === 'active') {
        $where .= " AND COALESCE(apclp.lastpost, 0) >= {$thresholdTs}";
    }

    switch ($sort) {
        case 'total':
            $orderBy = "u.".AF_APC_COL." {$orderSql}, COALESCE(apclp.lastpost,0) DESC, u.username ASC";
            break;
        case 'user':
            $orderBy = "u.username {$orderSql}, u.uid {$orderSql}";
            break;
        case 'reg':
            $orderBy = "u.regdate {$orderSql}, u.uid {$orderSql}";
            break;
        case 'last':
        default:
            $orderBy = "COALESCE(apclp.lastpost,0) {$orderSql}, u.".AF_APC_COL." DESC, u.username ASC";
            break;
    }

    // пагинация
    $qTotal = $db->query("
        SELECT COUNT(*) AS c
        FROM {$prefix}users u
        {$joins}
        WHERE {$where}
    ");
    $rTotal = $db->fetch_array($qTotal);
    $totalUsers = (int)($rTotal['c'] ?? 0);

    $baseArgs = [
        'sort'    => $sort,
        'order'   => $order,
        'only'    => $only,
        'days'    => (string)$days,
        'perpage' => (string)$perpage,
    ];
    if ($q !== '') {
        $baseArgs['q'] = $q;
    }
    $baseUrl = 'postsactivity.php?' . http_build_query($baseArgs);
    $multipage = function_exists('multipage') ? multipage($totalUsers, $perpage, $pageNum, $baseUrl) : '';

    // LIMIT/OFFSET
    if (in_array($db->type, ['pgsql', 'sqlite'], true)) {
        $limitSql = " LIMIT ".(int)$perpage." OFFSET ".(int)$start." ";
    } else {
        $limitSql = " LIMIT ".(int)$start.", ".(int)$perpage." ";
    }

    $users = [];
    $uids = [];

    $qList = $db->query("
        SELECT
            u.uid, u.username, u.usergroup, u.displaygroup,
            u.avatar, u.avatardimensions,
            u.regdate,
            u.".AF_APC_COL." AS total,
            COALESCE(apclp.lastpost, 0) AS lastpost_ts
        FROM {$prefix}users u
        {$joins}
        WHERE {$where}
        ORDER BY {$orderBy}
        {$limitSql}
    ");

    while ($u = $db->fetch_array($qList)) {
        $uid = (int)($u['uid'] ?? 0);
        if ($uid <= 0) continue;
        $users[$uid] = $u;
        $uids[] = $uid;
    }

    $periods = !empty($uids) ? af_advancedpostcounter_fetch_period_counts($uids) : [];

    $last = [];
    if (!empty($uids) && $fidList !== '') {
        $uidList2 = implode(',', array_map('intval', $uids));

        $qLast = $db->query("
            SELECT p.uid, p.pid, p.tid, p.dateline, t.subject
            FROM {$prefix}posts p
            INNER JOIN {$prefix}threads t ON (t.tid = p.tid)
            WHERE p.visible = 1
              AND t.visible = 1
              AND p.uid IN ({$uidList2})
              AND p.fid IN ({$fidList})
              AND {$firstCond}
            ORDER BY p.uid ASC, p.dateline DESC, p.pid DESC
        ");

        while ($r = $db->fetch_array($qLast)) {
            $uid = (int)$r['uid'];
            if ($uid <= 0) continue;
            if (isset($last[$uid])) continue;
            $last[$uid] = $r;
        }
    }

    // фильтры (как у тебя)
    $sortName = [
        'last'  => 'Последняя активность',
        'total' => 'Всего постов',
        'user'  => 'Имя',
        'reg'   => 'Регистрация',
    ];
    $onlyName = [
        'all'      => 'Все',
        'active'   => 'Активные (писали недавно)',
        'inactive' => 'Неактивные (давно не писали)',
        'zero'     => 'Нулёвки (0 постов)',
        'has'      => 'Только с постами',
    ];

    $filters = '<form action="postsactivity.php" method="get" class="af-apc-filters" style="margin:0 0 10px 0;">'
        .'<div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">'
        .'<label class="smalltext">Сортировка: '
            .'<select name="sort">'
                .'<option value="last"'.($sort==='last'?' selected':'').'>'.$esc($sortName['last']).'</option>'
                .'<option value="total"'.($sort==='total'?' selected':'').'>'.$esc($sortName['total']).'</option>'
                .'<option value="user"'.($sort==='user'?' selected':'').'>'.$esc($sortName['user']).'</option>'
                .'<option value="reg"'.($sort==='reg'?' selected':'').'>'.$esc($sortName['reg']).'</option>'
            .'</select>'
        .'</label>'
        .'<label class="smalltext">Порядок: '
            .'<select name="order">'
                .'<option value="desc"'.($order==='desc'?' selected':'').'>По убыванию</option>'
                .'<option value="asc"'.($order==='asc'?' selected':'').'>По возрастанию</option>'
            .'</select>'
        .'</label>'
        .'<label class="smalltext">Фильтр: '
            .'<select name="only">'
                .'<option value="all"'.($only==='all'?' selected':'').'>'.$esc($onlyName['all']).'</option>'
                .'<option value="active"'.($only==='active'?' selected':'').'>'.$esc($onlyName['active']).'</option>'
                .'<option value="inactive"'.($only==='inactive'?' selected':'').'>'.$esc($onlyName['inactive']).'</option>'
                .'<option value="zero"'.($only==='zero'?' selected':'').'>'.$esc($onlyName['zero']).'</option>'
                .'<option value="has"'.($only==='has'?' selected':'').'>'.$esc($onlyName['has']).'</option>'
            .'</select>'
        .'</label>'
        .'<label class="smalltext">Порог (дней): '
            .'<input type="text" name="days" value="'.$esc((string)$days).'" size="3" />'
        .'</label>'
        .'<label class="smalltext">Поиск: '
            .'<input type="text" name="q" value="'.$esc($q).'" size="18" />'
        .'</label>'
        .'<label class="smalltext">На странице: '
            .'<input type="text" name="perpage" value="'.$esc((string)$perpage).'" size="3" />'
        .'</label>'
        .'<input type="submit" class="button" value="Показать" />'
        .' <a class="smalltext" href="postsactivity.php" style="opacity:.85;">Сброс</a>'
        .'</div></form>';

    // TH
    $th_user  = $esc($lang->username ?? 'Пользователь');
    $th_total = $esc('Всего');
    $th_month = $esc('За месяц');
    $th_week  = $esc('За неделю');
    $th_last  = $esc('Последний пост');
    $th_all_posts = $esc(!empty($lang->af_apc_th_all_posts) ? (string)$lang->af_apc_th_all_posts : 'Все посты пользователя');

    // rows
    $rows = '';
    $rowTpl = (string)$templates->get('advancedpostcounter_postsactivity_row');

    if (empty($uids)) {
        $rows = '<tr><td class="trow1" colspan="7"><em>'.$esc('Нет данных.').'</em></td></tr>';
    } else {
        foreach ($uids as $uid) {
            $u = $users[$uid];

            $row_bg = function_exists('alt_trow') ? alt_trow() : 'trow1';

            $username = (string)($u['username'] ?? '');
            $usergroup = (int)($u['usergroup'] ?? 2);
            $displaygroup = (int)($u['displaygroup'] ?? 0);

            if (function_exists('format_name')) {
                $username_fmt = format_name($username, $usergroup, $displaygroup);
            } else {
                $username_fmt = $esc($username);
            }

            if (function_exists('build_profile_link')) {
                $row_user = build_profile_link($username_fmt, (int)$uid);
            } else {
                $row_user = '<a href="member.php?action=profile&amp;uid='.(int)$uid.'">'.$username_fmt.'</a>';
            }

            // avatar
            $avatarUrl  = trim((string)($u['avatar'] ?? ''));
            $avatarDims = (string)($u['avatardimensions'] ?? '');

            if ($avatarUrl === '' || $avatarUrl === '0') {
                $avatarUrl = af_apc_get_default_avatar_url();
                $avatarDims = '';
            }

            $imgUrl = $avatarUrl;
            $w = 50; $h = 50;

            if (function_exists('format_avatar')) {
                $av = format_avatar($avatarUrl, $avatarDims, 50, 50);
                if (!empty($av['image'])) {
                    $imgUrl = (string)$av['image'];
                }
                $w = (int)($av['width'] ?? 50);  if ($w <= 0) $w = 50;
                $h = (int)($av['height'] ?? 50); if ($h <= 0) $h = 50;
            }

            $row_avatar = '<img src="'.$esc($imgUrl).'" width="'.$w.'" height="'.$h.'" alt="" class="af-apc-avatar" />';

            $row_total = (string)(int)($u['total'] ?? 0);
            $row_week  = (string)(int)($periods[$uid]['week'] ?? 0);
            $row_month = (string)(int)($periods[$uid]['month'] ?? 0);

            $row_last = '';
            if (isset($last[$uid])) {
                $pid = (int)($last[$uid]['pid'] ?? 0);
                $tid = (int)($last[$uid]['tid'] ?? 0);
                $sub = $esc((string)($last[$uid]['subject'] ?? ''));

                $url = function_exists('get_post_link')
                    ? get_post_link($pid, $tid)
                    : (rtrim((string)$mybb->settings['bburl'], '/') . '/showthread.php?pid=' . $pid . '#pid' . $pid);

                $dt = (int)($last[$uid]['dateline'] ?? 0);
                $when = '';
                if ($dt > 0 && function_exists('my_date')) {
                    $when = my_date($mybb->settings['dateformat'], $dt) . ' ' . my_date($mybb->settings['timeformat'], $dt);
                }

                $row_last = '<a href="'.$esc((string)$url).'">'.$sub.'</a>';
                if ($when !== '') {
                    $row_last .= '<br /><span class="smalltext">'.$esc($when).'</span>';
                }
            } else {
                $row_last = '<span class="smalltext" style="opacity:.75;">'.$esc('Никогда').'</span>';
            }

            $row_all_posts_url = rtrim((string)$mybb->settings['bburl'], '/') . '/postsbyuser.php?uid=' . (int)$uid;
            $row_all_posts_label = $esc(!empty($lang->af_apc_btn_all_posts) ? (string)$lang->af_apc_btn_all_posts : 'Посты');

            if ($rowTpl !== '') {
                eval("\$rows .= \"".$rowTpl."\";");
            } else {
                $rows .= '<tr>'
                    .'<td class="'.$row_bg.'" style="text-align:center; white-space:nowrap;">'.$row_avatar.'</td>'
                    .'<td class="'.$row_bg.'">'.$row_user.'</td>'
                    .'<td class="'.$row_bg.'" style="text-align:center; white-space:nowrap;">'.$row_total.'</td>'
                    .'<td class="'.$row_bg.'" style="text-align:center; white-space:nowrap;">'.$row_month.'</td>'
                    .'<td class="'.$row_bg.'" style="text-align:center; white-space:nowrap;">'.$row_week.'</td>'
                    .'<td class="'.$row_bg.'">'.$row_last.'</td>'
                    .'<td class="'.$row_bg.'" style="text-align:center; white-space:nowrap;"><a class="button" href="'.$esc($row_all_posts_url).'">'.$row_all_posts_label.'</a></td>'
                    .'</tr>';
            }
        }
    }

    /* -------------------- INNER TEMPLATE -------------------- */

    $inner = '';
    $tplInner = (string)$templates->get('advancedpostcounter_postsactivity');
    if ($tplInner !== '') {
        // оставляем как есть — твой шаблон содержит {$header}{$footer} и это нормально,
        // но мы всё равно в конце рендерим fullpage af_apc_postsactivity_page (doctype),
        // поэтому тут убираем header/footer, чтобы не было дубля
        $tplInner = str_replace(['{$header}', '{$footer}'], '', $tplInner);

        // переменные для вкладки "по месяцам"
        $months_tab_title = 'Активность по месяцам';
        $users_tab_title  = 'Активность пользователей';

        eval("\$inner = \"".$tplInner."\";");
    } else {
        $inner = '<div class="af-apc-activity-page">'.$filters.'<table class="tborder" width="100%">'.$rows.'</table></div>';
    }

    /* -------------------- FULL DOCUMENT TEMPLATE -------------------- */

    $page = '';
    $af_apc_page_title = $activity_title;
    $af_apc_content = $inner;

    $tplDoc = (string)$templates->get('af_apc_postsactivity_page');
    if ($tplDoc !== '') {
        eval("\$page = \"".$tplDoc."\";");
    } else {
        $page = (string)$header . (string)$inner . (string)$footer;
    }

    output_page($page);
    exit;
}

function af_advancedpostcounter_wrap_with_forum_shell(string $page_inner, string $original_tpl): string
{
    global $templates;

    // Если шаблон уже содержит {$header} или {$footer} — НЕ вмешиваемся
    if (strpos($original_tpl, '{$header}') !== false || strpos($original_tpl, '{$footer}') !== false) {
        return $page_inner;
    }

    if (!isset($templates) || !method_exists($templates, 'get')) {
        return $page_inner;
    }

    // Стандартная связка MyBB: headerinclude -> header -> footer
    $headerinclude = '';
    $header = '';
    $footer = '';

    $hiTpl = (string)$templates->get('headerinclude');
    $hTpl  = (string)$templates->get('header');
    $fTpl  = (string)$templates->get('footer');

    if ($hiTpl !== '') {
        eval("\$headerinclude = \"{$hiTpl}\";");
    }
    if ($hTpl !== '') {
        eval("\$header = \"{$hTpl}\";");
    }
    if ($fTpl !== '') {
        eval("\$footer = \"{$fTpl}\";");
    }

    return (string)$header . (string)$page_inner . (string)$footer;
}

/**
 * Рендер страницы /postsactivity.php по “канону”:
 * полный HTML-документ + output_page($page) одним куском.
 */
function af_apc_render_postsactivity_page(): void
{
    if (function_exists('af_advancedpostcounter_postsactivity_page')) {
        af_advancedpostcounter_postsactivity_page();
        exit;
    }

    if (function_exists('error_no_permission')) {
        error_no_permission();
    }
    exit;
}

function af_advancedpostcounter_render_postsbyuser_page(): void
{
    if (function_exists('af_advancedpostcounter_postsbyuser_page')) {
        af_advancedpostcounter_postsbyuser_page();
        exit;
    }

    if (function_exists('error_no_permission')) {
        error_no_permission();
    }
    exit;
}

function af_advancedpostcounter_postsbyuser_page(): void
{
    global $mybb, $db, $lang;

    if (!af_advancedpostcounter_is_enabled()) {
        error_no_permission();
    }

    if ((int)($mybb->usergroup['canviewmemberlist'] ?? 0) !== 1) {
        error_no_permission();
    }

    af_advancedpostcounter_lang();
    af_apc_ensure_header_bits();

    $uid = (int)$mybb->get_input('uid', MyBB::INPUT_INT);
    if ($uid <= 0) {
        error_no_permission();
    }

    $user = get_user($uid);
    if (empty($user) || (int)($user['uid'] ?? 0) !== $uid) {
        error_no_permission();
    }

    $trackedFids = af_advancedpostcounter_get_tracked_forums();
    $trackedFids = array_values(array_unique(array_filter(array_map('intval', $trackedFids), static fn($fid): bool => $fid > 0)));

    $perpage = (int)$mybb->get_input('perpage', MyBB::INPUT_INT);
    if ($perpage < 1) {
        $perpage = 20;
    }
    if ($perpage > 100) {
        $perpage = 100;
    }

    $pageNum = (int)$mybb->get_input('page', MyBB::INPUT_INT);
    if ($pageNum < 1) {
        $pageNum = 1;
    }
    $start = ($pageNum - 1) * $perpage;

    $title = !empty($lang->af_apc_postsbyuser_title)
        ? (string)$lang->af_apc_postsbyuser_title
        : 'Посты пользователя';

    $emptyLabel = !empty($lang->af_apc_postsbyuser_empty)
        ? (string)$lang->af_apc_postsbyuser_empty
        : 'Постов не найдено.';

    $notConfiguredLabel = !empty($lang->af_apc_postsbyuser_not_configured)
        ? (string)$lang->af_apc_postsbyuser_not_configured
        : 'Форумы для подсчёта не выбраны.';

    $goToPostLabel = 'Перейти к сообщению';

    $esc = static function ($value): string {
        return function_exists('htmlspecialchars_uni')
            ? htmlspecialchars_uni((string)$value)
            : htmlspecialchars((string)$value, ENT_QUOTES);
    };

    $total = 0;
    $rows = '';
    $multipage = '';

    if (empty($trackedFids)) {
        $rows = '<tr><td class="trow1"><em>' . $esc($notConfiguredLabel) . '</em></td></tr>';
    } else {
        $fidList = implode(',', $trackedFids);
        $countWhere = "p.uid='{$uid}' AND p.visible='1' AND t.visible='1' AND p.fid IN ({$fidList})";

        if (!af_advancedpostcounter_count_firstpost_enabled()) {
            $countWhere .= ' AND p.pid<>t.firstpost';
        }

        $qTotal = $db->simple_select('posts p LEFT JOIN ' . TABLE_PREFIX . "threads t ON (t.tid=p.tid)", 'COUNT(*) AS cnt', $countWhere);
        $total = (int)($db->fetch_field($qTotal, 'cnt') ?? 0);

        if ($total > 0) {
            require_once MYBB_ROOT . 'inc/class_parser.php';
            $parser = new postParser();

            $query = $db->query(
                "SELECT p.pid, p.tid, p.dateline, p.message, p.subject AS post_subject, t.subject AS thread_subject\n"
                . "FROM " . TABLE_PREFIX . "posts p\n"
                . "LEFT JOIN " . TABLE_PREFIX . "threads t ON (t.tid=p.tid)\n"
                . "WHERE {$countWhere}\n"
                . "ORDER BY p.dateline DESC, p.pid DESC\n"
                . "LIMIT {$start}, {$perpage}"
            );

            $i = 0;
            while ($post = $db->fetch_array($query)) {
                $i++;
                $row_bg = ($i % 2 === 0) ? 'trow2' : 'trow1';

                $pid = (int)($post['pid'] ?? 0);
                $tid = (int)($post['tid'] ?? 0);
                $subject = trim((string)($post['thread_subject'] ?? ''));
                if ($subject === '') {
                    $subject = trim((string)($post['post_subject'] ?? ''));
                }
                if ($subject === '') {
                    $subject = '#' . $pid;
                }

                $message = trim((string)($post['message'] ?? ''));
                if (my_strlen($message) > 600) {
                    $message = my_substr($message, 0, 600) . '…';
                }

                $preview = $parser->parse_message($message, [
                    'allow_html' => 0,
                    'allow_mycode' => 1,
                    'allow_smilies' => 1,
                    'allow_imgcode' => 0,
                    'filter_badwords' => 1,
                ]);

                $postUrl = function_exists('get_post_link')
                    ? get_post_link($pid, $tid)
                    : ('showthread.php?pid=' . $pid . '#pid' . $pid);

                $row_thread_link = '<a href="' . $esc($postUrl) . '">' . $esc($subject) . '</a>';
                $row_post_link = '<a class="smalltext" href="' . $esc($postUrl) . '">' . $esc($goToPostLabel) . '</a>';

                $dateline = (int)($post['dateline'] ?? 0);
                $row_date = $dateline > 0
                    ? my_date($mybb->settings['dateformat'], $dateline) . ' ' . my_date($mybb->settings['timeformat'], $dateline)
                    : '';
                $row_date = $esc($row_date);
                $row_subject = $esc($subject);
                $row_preview = $preview;

                $rowHtml = af_apc_render_template('advancedpostcounter_postsbyuser_row', get_defined_vars());
                if ($rowHtml === '') {
                    $rowHtml = '<tr><td class="' . $row_bg . '">' . $row_thread_link . '<br /><span class="smalltext">' . $row_date . '</span><div>' . $row_preview . '</div>' . $row_post_link . '</td></tr>';
                }

                $rows .= $rowHtml;
            }

            $baseUrl = 'postsbyuser.php?uid=' . $uid . '&perpage=' . $perpage;
            $multipage = multipage($total, $perpage, $pageNum, $baseUrl);
        }

        if ($rows === '') {
            $rows = '<tr><td class="trow1"><em>' . $esc($emptyLabel) . '</em></td></tr>';
        }
    }

    $username = build_profile_link(format_name($user['username'], (int)$user['usergroup'], (int)$user['displaygroup']), $uid);
    $pageTitle = $title . ': ' . strip_tags((string)$user['username']);
    add_breadcrumb($title, 'postsbyuser.php?uid=' . $uid);

    $af_apc_content = af_apc_render_template('advancedpostcounter_postsbyuser', get_defined_vars());
    if ($af_apc_content === '') {
        $af_apc_content = '<div class="wrapper"><table class="tborder" width="100%">' . $rows . '</table>' . $multipage . '</div>';
    }

    $af_apc_page_title = $pageTitle;
    $page = af_apc_render_template('af_apc_postsbyuser_page', get_defined_vars());
    if ($page === '') {
        global $header, $footer;
        $page = (string)$header . $af_apc_content . (string)$footer;
    }

    output_page($page);
    exit;
}



/**
 * Гарантирует, что $headerinclude/$header/$footer заполнены шаблонами темы.
 */
function af_apc_ensure_header_bits(): void
{
    global $templates, $headerinclude, $header, $footer;

    // Эти глобалы реально используются внутри headerinclude/header/footer шаблонов
    global $mybb, $lang, $theme, $db, $cache, $config, $session, $plugins;
    global $settings, $charset, $stylesheets, $document_title, $navigation, $navbits;

    if (!isset($templates) || !is_object($templates) || !method_exists($templates, 'get')) {
        return;
    }

    // алиас $settings -> $mybb->settings (часто используется в шаблонах)
    if (!isset($settings) && isset($mybb) && is_object($mybb) && isset($mybb->settings) && is_array($mybb->settings)) {
        $settings =& $mybb->settings;
    }

    if (!isset($headerinclude) || $headerinclude === '') {
        $tpl = (string)$templates->get('headerinclude');
        if ($tpl !== '') {
            eval("\$headerinclude = \"".$tpl."\";");
        } else {
            $headerinclude = '';
        }
    }

    if (!isset($header) || $header === '') {
        $tpl = (string)$templates->get('header');
        if ($tpl !== '') {
            eval("\$header = \"".$tpl."\";");
        } else {
            $header = '';
        }
    }

    if (!isset($footer) || $footer === '') {
        $tpl = (string)$templates->get('footer');
        if ($tpl !== '') {
            eval("\$footer = \"".$tpl."\";");
        } else {
            $footer = '';
        }
    }
}

/**
 * Рендерит шаблон MyBB с подстановкой переменных.
 * (Канон как в AAS: подготовили shell -> eval шаблон -> output_page одним куском.)
 */
function af_apc_render_template(string $tpl_name, array $vars = []): string
{
    // ВАЖНО: eval() внутри функции видит ТОЛЬКО локальные переменные.
    // Поэтому подтягиваем все ключевые глобалы MyBB, которые используются в шаблонах.
    global $templates;

    global $mybb, $lang, $theme, $db, $cache, $config, $session, $plugins;
    global $headerinclude, $header, $footer;
    global $settings, $charset, $stylesheets, $document_title, $navigation, $navbits;

    if (!isset($templates) || !is_object($templates) || !method_exists($templates, 'get')) {
        return '';
    }

    // Переменные для шаблона
    foreach ($vars as $k => $v) {
        $k = (string)$k;
        if (preg_match('~^[a-zA-Z_][a-zA-Z0-9_]*$~', $k)) {
            ${$k} = $v;
        }
    }

    $tpl = (string)$templates->get($tpl_name);
    if ($tpl === '') {
        return '';
    }

    $out = '';
    try {
        // Каноничный стиль MyBB: templates->get() уже возвращает строку, пригодную для eval в двойных кавычках
        eval("\$out = \"".$tpl."\";");
    } catch (Throwable $e) {
        $out = '';
    }

    return (string)$out;
}


function af_apc_get_default_avatar_url(): string
{
    global $mybb;

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');

    // Попробуем типичные ключи настроек (в разных сборках/модах они отличаются)
    $keys = [
        'default_avatar',
        'defaultavatar',
        'useravatar',
        'noavatar',
        'default_user_avatar',
    ];

    foreach ($keys as $k) {
        if (!empty($mybb->settings[$k])) {
            $u = trim((string)$mybb->settings[$k]);
            if ($u === '') continue;

            // абсолютная ссылка или абсолютный путь
            if (preg_match('~^https?://~i', $u) || (isset($u[0]) && $u[0] === '/')) {
                return $u;
            }

            // относительный путь
            if ($bburl !== '') {
                return $bburl.'/'.ltrim($u, '/');
            }
            return $u;
        }
    }

    // Самый частый дефолт MyBB-тем
    if ($bburl !== '') {
        return $bburl.'/images/default_avatar.png';
    }
    return 'images/default_avatar.png';
}

/**
 * (Опционально) автоустановка алиаса /postsactivity.php в корень форума (как AAS userlist.php).
 * Если у тебя алиас уже лежит в корне — можно вообще не трогать, но пусть будет.
 */
function af_apc_install_postsactivity_alias(bool $force = false): void
{
    $src = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/advancedpostcounter/assets/postsactivity.php';
    $dst = MYBB_ROOT . 'postsactivity.php';

    if (!file_exists($src)) {
        return;
    }

    $srcCode = @file_get_contents($src);
    if ($srcCode === false || trim($srcCode) === '') {
        return;
    }

    if (file_exists($dst) && !$force) {
        $dstCode = @file_get_contents($dst);
        if ($dstCode !== false && strpos($dstCode, 'AF_APC_POSTSACTIVITY_ALIAS') === false) {
            // Не наш файл — не трогаем
            return;
        }
    }

    // Если наш и одинаковый — не пишем
    if (file_exists($dst)) {
        $dstCode = @file_get_contents($dst);
        if ($dstCode !== false && hash('sha256', $dstCode) === hash('sha256', $srcCode)) {
            return;
        }
    }

    @file_put_contents($dst, $srcCode);
}

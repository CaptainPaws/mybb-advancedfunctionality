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
    af_advancedpostcounter_pages_install(); // <-- создаём /postsactivity.php в корне

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
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
            'af_advancedpostcounter_show_profile'
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

/* -------------------- DISPLAY (POSTBIT / PROFILE) -------------------- */
function af_advancedpostcounter_postbit(array &$post): void
{
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
    $post['advancedpostcounter'] = $marker;

    // 1) Пытаемся в author_statistics (как задумано)
    if (isset($post['author_statistics']) && is_string($post['author_statistics']) && $post['author_statistics'] !== '') {
        if (strpos($post['author_statistics'], $marker) === false) {
            $post['author_statistics'] .= "\n" . $marker . "\n";
        }
        return;
    }

    // 2) Fallback: если у кастомного постбита author_statistics пустой,
    // втыкаем маркер прямо в user_details (он почти всегда выводится в шаблоне)
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

    // 3) Последний fallback — чтобы маркер всё равно оказался на странице
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
    $memprofile['advancedpostcounter'] = $marker;

    // ВАЖНО: у кастомных member_profile шаблонов может не быть {$memprofile['advancedpostcounter']}.
    // Поэтому втыкаем маркер ещё и в $profilefields, если он есть (часто выводится в шаблоне).
    if (isset($GLOBALS['profilefields']) && is_string($GLOBALS['profilefields'])) {
        if (strpos($GLOBALS['profilefields'], $marker) === false) {
            $GLOBALS['profilefields'] .= "\n" . $marker . "\n";
        }
    }
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

function af_advancedpostcounter_pre_output(string &$page): void
{
    if (!af_advancedpostcounter_is_enabled()) {
        return;
    }

    if (defined('IN_ADMINCP') || defined('IN_MODCP') || af_advancedpostcounter_is_redirect_page($page)) {
        return;
    }

    af_advancedpostcounter_lang();

    // 1) Добавляем ссылку "Активность" в навигацию (после userlist)
    if (strpos($page, '<!--af_apc_nav-->') === false) {
        global $mybb;
        $bburl = rtrim((string)$mybb->settings['bburl'], '/');
        $li = '<li><a href="'.$bburl.'/postsactivity.php" data-af-aas-nav="1" class="postsactivity">Активность</a></li>';

        $pattern = '#(<li>\s*<a[^>]+href="[^"]*/userlist\.php"[^>]*class="memberlist"[^>]*>.*?</a>\s*</li>)#i';
        $page2 = preg_replace($pattern, '$1'."\n<!--af_apc_nav-->\n".$li, $page, 1, $count);

        if (!empty($count)) {
            $page = (string)$page2;
        } else {
            // если не нашли точку вставки — просто метка, чтобы не пытаться бесконечно
            $page = "<!--af_apc_nav-->\n" . $page;
        }
    }

    // 2) Подключим CSS/JS один раз
    if (strpos($page, '<!--af_advancedpostcounter_assets-->') === false) {
        global $mybb;

        $base = rtrim($mybb->settings['bburl'], '/') . '/inc/plugins/advancedfunctionality/addons/advancedpostcounter';
        $css  = '<link rel="stylesheet" href="'.$base.'/advancedpostcounter.css" />';
        $js   = '<script src="'.$base.'/advancedpostcounter.js"></script>';

        $assets = "\n<!--af_advancedpostcounter_assets-->\n{$css}\n{$js}\n";

        if (stripos($page, '</head>') !== false) {
            $page = str_ireplace('</head>', $assets.'</head>', $page);
        } else {
            $page = $assets . $page;
        }
    }

    // 3) Собираем UID из маркеров
    $uids = [];

    if (preg_match_all('#<af_apc_uid_(\d+)>#', $page, $m)) {
        foreach ($m[1] as $u) { $u = (int)$u; if ($u > 0) $uids[$u] = true; }
    }
    if (preg_match_all('#<af_apc_profile_uid_(\d+)>#', $page, $m2)) {
        foreach ($m2[1] as $u) { $u = (int)$u; if ($u > 0) $uids[$u] = true; }
    }

    if (!$uids) {
        return;
    }

    global $db, $templates, $lang;
    $uidList = implode(',', array_keys($uids));

    // total
    $map = [];
    $q = $db->simple_select('users', 'uid,'.AF_APC_COL, "uid IN ({$uidList})");
    while ($row = $db->fetch_array($q)) {
        $map[(int)$row['uid']] = (int)$row[AF_APC_COL];
    }

    // week/month
    $periods = af_advancedpostcounter_fetch_period_counts(array_keys($uids));

    $label = isset($lang->af_advancedpostcounter_label) ? (string)$lang->af_advancedpostcounter_label : 'Постов:';
    if (function_exists('htmlspecialchars_uni')) {
        $label = htmlspecialchars_uni($label);
    } else {
        $label = htmlspecialchars($label, ENT_QUOTES);
    }

    $tpl_post = '';
    $tpl_prof = '';
    if (isset($templates) && method_exists($templates, 'get')) {
        $tpl_post = (string)$templates->get('advancedpostcounter_bit');
        $tpl_prof = (string)$templates->get('advancedpostcounter_profile_bit');
    }

    foreach ($uids as $uid => $_) {
        $cnt = (int)($map[$uid] ?? 0);
        $w   = (int)($periods[$uid]['week'] ?? 0);
        $mth = (int)($periods[$uid]['month'] ?? 0);

        $tip = "За месяц: {$mth} || За неделю: {$w}";
        if (function_exists('htmlspecialchars_uni')) {
            $tip = htmlspecialchars_uni($tip);
        } else {
            $tip = htmlspecialchars($tip, ENT_QUOTES);
        }

        // postbit html
        $postbit_html = '';
        if ($tpl_post !== '') {
            $apc_label = $label;
            $apc_count = $cnt;
            $apc_tip   = $tip;
            eval("\$postbit_html = \"{$tpl_post}\";");
        } else {
            $postbit_html = '<div class="af-apc"><span class="af-apc-label">'.$label.'</span> <span class="af-apc-count" title="'.$tip.'">'.$cnt.'</span></div>';
        }

        // profile html
        $profile_html = '';
        if ($tpl_prof !== '') {
            $apc_label = $label;
            $apc_count = $cnt;
            $apc_tip   = $tip;
            eval("\$profile_html = \"{$tpl_prof}\";");
        } else {
            $profile_html = '<tr><td class="trow1"><strong>'.$label.'</strong></td><td class="trow1"><span title="'.$tip.'">'.$cnt.'</span></td></tr>';
        }

        $page = str_replace(sprintf(AF_APC_MARK_POST, $uid), $postbit_html, $page);
        $page = str_replace(sprintf(AF_APC_MARK_PROF, $uid), $profile_html, $page);
    }
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

    $path = rtrim($base, '/\\') . '/advancedpostcounter/templates/advancedpostcounter.html';

    // НИКАКИХ fallback-HTML в PHP. Источник истины — файл шаблонов.
    return af_advancedpostcounter_read_templates_file($path);
}

function af_advancedpostcounter_templates_install(): void
{
    global $db;

    $tpls = af_advancedpostcounter_templates_source();
    if (empty($tpls)) {
        return;
    }

    foreach ($tpls as $title => $html) {
        $title = strtolower(trim((string)$title));
        $html  = (string)$html;

        if ($title === '' || $html === '') {
            continue;
        }

        $titleEsc = $db->escape_string($title);

        // Обновляем ВСЕ sid копии, чтобы снести кривую/старую версию в активном templateset
        $q = $db->simple_select('templates', 'tid', "title='{$titleEsc}'");
        $found = false;

        while ($row = $db->fetch_array($q)) {
            $found = true;
            $db->update_query('templates', [
                'template' => $html,      // БЕЗ escape_string (MyBB экранирует сам)
                'dateline' => TIME_NOW,
            ], "tid='" . (int)$row['tid'] . "'");
        }

        // Если шаблонов с таким названием не было — создаём master (sid=-1)
        if (!$found) {
            $db->insert_query('templates', [
                'title'    => $title,
                'template' => $html,      // БЕЗ escape_string
                'sid'      => -1,
                'version'  => 1,
                'dateline' => TIME_NOW,
            ]);
        }
    }

    // member_profile: вставляем {$memprofile['advancedpostcounter']} после Total Posts (во всех sid)
    $needle_profile = '{$memprofile[\'advancedpostcounter\']}';

    $q3 = $db->simple_select('templates', 'tid,template', "title='member_profile'");
    while ($row = $db->fetch_array($q3)) {
        $tid = (int)$row['tid'];
        $tpl = (string)$row['template'];

        if (strpos($tpl, $needle_profile) !== false) {
            continue;
        }

        $count = 0;
        $pattern = '#(<tr>\s*<td\s+class="trow1"><strong>\{\$lang->total_posts\}</strong></td>\s*<td\s+class="trow1">.*?\{\$memprofile\[(?:\'|")postnum(?:\'|")\]\}.*?</td>\s*</tr>)#si';
        $new = preg_replace($pattern, '$1' . "\n" . $needle_profile . "\n", $tpl, 1, $count);

        if ($count && is_string($new) && $new !== '' && $new !== $tpl) {
            $db->update_query('templates', [
                'template' => $new, // БЕЗ escape_string
            ], "tid='{$tid}'");
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

    // Удаляем вставку в member_profile (наша переменная)
    find_replace_templatesets('member_profile', "#\\{\\$memprofile\\['advancedpostcounter'\\]\\}#i", '');

    // Удаляем наши шаблоны
    $db->delete_query(
        'templates',
        "title IN (
            'advancedpostcounter_bit',
            'advancedpostcounter_profile_bit',
            'advancedpostcounter_postsactivity',
            'advancedpostcounter_postsactivity_row'
        ) AND sid='-1'"
    );
}


function af_advancedpostcounter_pages_install(): void
{
    // источник внутри аддона
    $src = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/advancedpostcounter/postsactivity.php';
    $dst = MYBB_ROOT . 'postsactivity.php';

    $signature = 'AF: AdvancedPostCounter postsactivity page';

    $payload = '';
    if (is_file($src)) {
        $payload = (string)@file_get_contents($src);
    }

    // fallback на всякий пожарный (если src не прочитался)
    if (trim($payload) === '') {
        $payload = "<?php\n"
            ."/**\n * {$signature}\n */\n"
            ."define('IN_MYBB', 1);\n"
            ."require_once __DIR__ . '/global.php';\n"
            ."if (!function_exists('af_advancedpostcounter_postsactivity_page')) {\n"
            ."    \$addon = MYBB_ROOT.'inc/plugins/advancedfunctionality/addons/advancedpostcounter/advancedpostcounter.php';\n"
            ."    if (is_file(\$addon)) require_once \$addon;\n"
            ."}\n"
            ."if (function_exists('af_advancedpostcounter_postsactivity_page')) { af_advancedpostcounter_postsactivity_page(); exit; }\n"
            ."if (function_exists('error_no_permission')) error_no_permission();\n"
            ."exit;\n";
    }

    // если файл уже есть и это наш — обновим (чтобы правки подтягивались)
    if (is_file($dst)) {
        $existing = (string)@file_get_contents($dst);
        if (strpos($existing, $signature) !== false) {
            @file_put_contents($dst, $payload, LOCK_EX);
        }
        return;
    }

    @file_put_contents($dst, $payload, LOCK_EX);
}

function af_advancedpostcounter_pages_uninstall(): void
{
    $dst = MYBB_ROOT . 'postsactivity.php';
    $signature = 'AF: AdvancedPostCounter postsactivity page';

    if (!is_file($dst)) {
        return;
    }

    $existing = (string)@file_get_contents($dst);
    // удаляем только если это наш файл, чтобы не снести чужой
    if (strpos($existing, $signature) !== false) {
        @unlink($dst);
    }
}

/* -------------------- INIT -------------------- */
function af_advancedpostcounter_init(): void
{
    if (!af_advancedpostcounter_is_enabled()) {
        return;
    }

    global $cache, $mybb;

    if (!isset($cache) || !method_exists($cache, 'read') || !method_exists($cache, 'update')) {
        // если кэша нет — хотя бы раз пересчитаем на shutdown
        af_advancedpostcounter_schedule_rebuild();
        return;
    }

    $meta = $cache->read('af_advancedpostcounter_meta');
    if (!is_array($meta)) {
        $meta = [];
    }

    // нормализуем обе настройки (и CSV, и serialize)
    $cats = af_advancedpostcounter_parse_id_setting((string)($mybb->settings['af_advancedpostcounter_categories'] ?? ''));
    $forums = af_advancedpostcounter_parse_id_setting((string)($mybb->settings['af_advancedpostcounter_forums'] ?? ''));

    $curr_categories_norm = implode(',', array_map('intval', array_filter($cats, fn($x) => $x > 0)));
    $curr_forums_norm     = implode(',', array_map('intval', array_filter($forums, fn($x) => $x > 0)));

    $curr_first = (int)!empty($mybb->settings['af_advancedpostcounter_count_firstpost']);
    $curr_child = (int)!empty($mybb->settings['af_advancedpostcounter_include_children']);

    $firstRun = empty($meta['initialized']);

    $changed =
        ($meta['categories_norm'] ?? null) !== $curr_categories_norm ||
        ($meta['forums_norm'] ?? null)      !== $curr_forums_norm ||
        (int)($meta['count_firstpost'] ?? -1) !== $curr_first ||
        (int)($meta['include_children'] ?? -1) !== $curr_child;

    if ($firstRun || $changed) {
        $cache->update('af_advancedpostcounter_meta', [
            'initialized'     => 1,
            'categories_norm' => $curr_categories_norm,
            'forums_norm'     => $curr_forums_norm,
            'count_firstpost' => $curr_first,
            'include_children'=> $curr_child,
            'updated'         => TIME_NOW,
        ]);

        af_advancedpostcounter_schedule_rebuild();
    }
}

function af_advancedpostcounter_admin_selfheal(): void
{
    if (!defined('IN_ADMINCP')) {
        return;
    }

    global $db;

    // 1) settings selfheal (как было)
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

    // 2) templates selfheal — проверяем на "двойное экранирование" (\\" и \&quot;)
    $needFix = false;

    $qt = $db->simple_select('templates', 'template', "title='advancedpostcounter_postsactivity'", ['limit' => 1]);
    $tr = $db->fetch_array($qt);

    if (!$tr || !isset($tr['template'])) {
        $needFix = true;
    } else {
        $t = (string)$tr['template'];

        // Твои симптомы:
        // id="\&quot;content\&quot;" / class="\&quot;wrapper\&quot;" и т.п.
        if (strpos($t, '\&quot;') !== false || strpos($t, '\\"') !== false) {
            $needFix = true;
        }

        // Иногда прилетает ещё и &quot; внутри атрибутов
        if (strpos($t, '&quot;') !== false) {
            $needFix = true;
        }
    }

    if ($needFix) {
        af_advancedpostcounter_templates_install();

        if (function_exists('cache_templatesets')) {
            cache_templatesets();
        }
    }

    // 3) pages selfheal/update (только если это НАШ postsactivity.php)
    $dst = MYBB_ROOT . 'postsactivity.php';
    $signature = 'AF: AdvancedPostCounter postsactivity page';

    if (!is_file($dst)) {
        af_advancedpostcounter_pages_install();
        return;
    }

    $existing = (string)@file_get_contents($dst);
    if ($existing !== '' && strpos($existing, $signature) !== false) {
        af_advancedpostcounter_pages_install();
    }
}

function af_advancedpostcounter_postsactivity_page(): void
{
    global $mybb, $db, $templates, $lang, $headerinclude, $header, $footer;

    if (!af_advancedpostcounter_is_enabled()) {
        if (function_exists('error_no_permission')) {
            error_no_permission();
        }
        exit;
    }

    af_advancedpostcounter_lang();

    $trackedFids = af_advancedpostcounter_get_tracked_forums();
    if (empty($trackedFids)) {
        if (function_exists('error')) {
            error('AdvancedPostCounter: не выбраны категории/форумы для подсчёта (настройка af_advancedpostcounter_categories).');
        }
        output_page('AdvancedPostCounter: not configured');
        return;
    }

    // 1) Берём шаблоны
    $pageTpl = (isset($templates) && method_exists($templates, 'get')) ? (string)$templates->get('advancedpostcounter_postsactivity') : '';
    $rowTpl  = (isset($templates) && method_exists($templates, 'get')) ? (string)$templates->get('advancedpostcounter_postsactivity_row') : '';

    // если шаблонов нет — ставим из файла и пробуем ещё раз
    if ($pageTpl === '' || $rowTpl === '') {
        af_advancedpostcounter_templates_install();

        $pageTpl = (isset($templates) && method_exists($templates, 'get')) ? (string)$templates->get('advancedpostcounter_postsactivity') : '';
        $rowTpl  = (isset($templates) && method_exists($templates, 'get')) ? (string)$templates->get('advancedpostcounter_postsactivity_row') : '';
    }

    if ($pageTpl === '' || $rowTpl === '') {
        if (function_exists('error')) {
            error('AdvancedPostCounter: отсутствуют шаблоны advancedpostcounter_postsactivity / advancedpostcounter_postsactivity_row. Проверь advancedpostcounter.html и установку шаблонов.');
        }
        output_page('AdvancedPostCounter: templates missing');
        return;
    }

    if (is_object($templates)) {
        if (empty($headerinclude)) { eval('$headerinclude = "'.$templates->get('headerinclude').'";'); }
        if (empty($header))        { eval('$header        = "'.$templates->get('header').'";'); }
        if (empty($footer))        { eval('$footer        = "'.$templates->get('footer').'";'); }
    }

    // 2) Хлебные крошки/заголовок
    $activity_title = 'Активность постов';
    if (function_exists('add_breadcrumb')) {
        add_breadcrumb($activity_title, 'postsactivity.php');
    }

    // 3) Пагинация
    $perpage = 20;
    $pageNum = (int)$mybb->get_input('page', MyBB::INPUT_INT);
    if ($pageNum < 1) $pageNum = 1;
    $start = ($pageNum - 1) * $perpage;

    $fidList = implode(',', array_map('intval', $trackedFids));
    $prefix  = TABLE_PREFIX;

    $first_cond = af_advancedpostcounter_count_firstpost_enabled() ? '1=1' : 'p.pid != t.firstpost';

    $qTotal = $db->query("
        SELECT COUNT(DISTINCT p.uid) AS c
        FROM {$prefix}posts p
        INNER JOIN {$prefix}threads t ON (t.tid = p.tid)
        WHERE p.visible = 1
          AND t.visible = 1
          AND p.uid > 0
          AND p.fid IN ({$fidList})
          AND {$first_cond}
    ");
    $rTotal = $db->fetch_array($qTotal);
    $totalUsers = (int)($rTotal['c'] ?? 0);

    $multipage = '';
    if (function_exists('multipage')) {
        $multipage = multipage($totalUsers, $perpage, $pageNum, 'postsactivity.php');
    }

    // 4) Топ юзеров
    $uids = [];
    $totals = [];

    $qTop = $db->query("
        SELECT p.uid, COUNT(*) AS c
        FROM {$prefix}posts p
        INNER JOIN {$prefix}threads t ON (t.tid = p.tid)
        WHERE p.visible = 1
          AND t.visible = 1
          AND p.uid > 0
          AND p.fid IN ({$fidList})
          AND {$first_cond}
        GROUP BY p.uid
        ORDER BY c DESC
        LIMIT " . (int)$start . ", " . (int)$perpage . "
    ");

    while ($row = $db->fetch_array($qTop)) {
        $uid = (int)($row['uid'] ?? 0);
        if ($uid <= 0) continue;
        $uids[] = $uid;
        $totals[$uid] = (int)($row['c'] ?? 0);
    }

    // 5) Если никого нет — пустая таблица, но С ОБЁРТКОЙ темы
    if (empty($uids)) {
        $rows = '';
        $page_inner = '';
        if (is_object($templates) && method_exists($templates, 'render')) {
            $page_inner = (string)$templates->render('advancedpostcounter_postsactivity');
        } else {
            eval("\$page_inner = \"{$pageTpl}\";");
        }

        output_page($page_inner);
        return;
    }

    $uidList = implode(',', array_map('intval', $uids));

    // 6) Данные юзеров
    $users = [];
    $qUsers = $db->simple_select(
        'users',
        'uid,username,usergroup,displaygroup,avatar,avatardimensions',
        "uid IN ({$uidList})"
    );
    while ($u = $db->fetch_array($qUsers)) {
        $users[(int)$u['uid']] = $u;
    }

    // 7) Неделя/месяц
    $periods = af_advancedpostcounter_fetch_period_counts($uids);

    // 8) Последний пост (берём по uid самый свежий)
    $last = [];
    $qLast = $db->query("
        SELECT p.uid, p.pid, p.tid, p.dateline, t.subject
        FROM {$prefix}posts p
        INNER JOIN {$prefix}threads t ON (t.tid = p.tid)
        WHERE p.visible = 1
          AND t.visible = 1
          AND p.uid IN ({$uidList})
          AND p.fid IN ({$fidList})
          AND {$first_cond}
        ORDER BY p.uid ASC, p.dateline DESC, p.pid DESC
    ");
    while ($row = $db->fetch_array($qLast)) {
        $uid = (int)$row['uid'];
        if ($uid <= 0) continue;
        if (isset($last[$uid])) continue;
        $last[$uid] = $row;
    }

    // 9) Рендер строк
    $rows = '';
    foreach ($uids as $uid) {
        $row_bg = function_exists('alt_trow') ? alt_trow() : 'trow1';

        $u = $users[$uid] ?? null;
        $username = is_array($u) ? (string)($u['username'] ?? '') : ('UID ' . $uid);

        if (function_exists('build_profile_link') && function_exists('htmlspecialchars_uni')) {
            $row_user = build_profile_link(htmlspecialchars_uni($username), (int)$uid);
        } else {
            $row_user = '<a href="member.php?action=profile&amp;uid=' . (int)$uid . '">' . htmlspecialchars($username, ENT_QUOTES) . '</a>';
        }

        $row_avatar = '';
        if (is_array($u)) {
            $avatarUrl  = (string)($u['avatar'] ?? '');
            $avatarDims = (string)($u['avatardimensions'] ?? '');

            if ($avatarUrl !== '') {
                if (function_exists('format_avatar')) {
                    $av  = format_avatar($avatarUrl, $avatarDims, 50, 50);
                    $img = (string)($av['image'] ?? '');
                    if ($img !== '') {
                        $imgEsc = function_exists('htmlspecialchars_uni') ? htmlspecialchars_uni($img) : htmlspecialchars($img, ENT_QUOTES);
                        $w = (int)($av['width'] ?? 50);  if ($w <= 0) $w = 50;
                        $h = (int)($av['height'] ?? 50); if ($h <= 0) $h = 50;
                        $row_avatar = '<img src="' . $imgEsc . '" width="' . $w . '" height="' . $h . '" alt="" style="border-radius:8px; max-width:50px; max-height:50px;" />';
                    }
                } else {
                    $row_avatar = '<img src="' . htmlspecialchars($avatarUrl, ENT_QUOTES) . '" alt="" style="border-radius:8px; max-width:50px; max-height:50px;" />';
                }
            }
        }

        $row_total = (string)(int)($totals[$uid] ?? 0);
        $row_week  = (string)(int)($periods[$uid]['week'] ?? 0);
        $row_month = (string)(int)($periods[$uid]['month'] ?? 0);

        $row_last = '';
        if (isset($last[$uid])) {
            $pid = (int)($last[$uid]['pid'] ?? 0);
            $tid = (int)($last[$uid]['tid'] ?? 0);

            $sub = (string)($last[$uid]['subject'] ?? '');
            $sub = function_exists('htmlspecialchars_uni') ? htmlspecialchars_uni($sub) : htmlspecialchars($sub, ENT_QUOTES);

            $url = function_exists('get_post_link')
                ? get_post_link($pid, $tid)
                : (rtrim((string)$mybb->settings['bburl'], '/') . '/showthread.php?pid=' . $pid . '#pid' . $pid);

            $dt = (int)($last[$uid]['dateline'] ?? 0);
            $when = $dt > 0 ? my_date($mybb->settings['dateformat'], $dt) . ' ' . my_date($mybb->settings['timeformat'], $dt) : '';

            $row_last = '<a href="' . htmlspecialchars((string)$url, ENT_QUOTES) . '">' . $sub . '</a>';
            if ($when !== '') {
                $row_last .= '<br /><span class="smalltext">' . $when . '</span>';
            }
        }

        eval("\$rows .= \"{$rowTpl}\";");
    }

    // 10) Рендер страницы
    $page_inner = '';
    if (is_object($templates) && method_exists($templates, 'render')) {
        $page_inner = (string)$templates->render('advancedpostcounter_postsactivity');
    } else {
        eval("\$page_inner = \"{$pageTpl}\";");
    }

    output_page($page_inner);
}

/* -------------------- HOOKS REGISTRATION -------------------- */

if (!empty($plugins) && empty($GLOBALS['af_apc_hooks_registered'])) {
    $GLOBALS['af_apc_hooks_registered'] = true;

    $plugins->add_hook('global_start', 'af_advancedpostcounter_lang');
    $plugins->add_hook('global_start', 'af_advancedpostcounter_init');
    $plugins->add_hook('admin_load', 'af_advancedpostcounter_admin_selfheal');


    // подсчёт
    $plugins->add_hook('datahandler_post_insert_post', 'af_advancedpostcounter_increment_post');
    $plugins->add_hook('datahandler_post_insert_thread', 'af_advancedpostcounter_increment_thread');
    $plugins->add_hook('class_moderation_delete_post', 'af_advancedpostcounter_decrement_post');

    // сложные мод-операции => ребилд
    $plugins->add_hook('class_moderation_soft_delete_posts', 'af_advancedpostcounter_schedule_rebuild');
    $plugins->add_hook('class_moderation_restore_posts', 'af_advancedpostcounter_schedule_rebuild');
    $plugins->add_hook('class_moderation_delete_thread_start', 'af_advancedpostcounter_schedule_rebuild');
    $plugins->add_hook('class_moderation_soft_delete_threads', 'af_advancedpostcounter_schedule_rebuild');
    $plugins->add_hook('class_moderation_restore_threads', 'af_advancedpostcounter_schedule_rebuild');
    $plugins->add_hook('class_moderation_approve_posts', 'af_advancedpostcounter_schedule_rebuild');
    $plugins->add_hook('class_moderation_unapprove_posts', 'af_advancedpostcounter_schedule_rebuild');
    $plugins->add_hook('class_moderation_approve_threads', 'af_advancedpostcounter_schedule_rebuild');
    $plugins->add_hook('class_moderation_unapprove_threads', 'af_advancedpostcounter_schedule_rebuild');
    $plugins->add_hook('class_moderation_merge_threads', 'af_advancedpostcounter_schedule_rebuild');
    $plugins->add_hook('class_moderation_copy_thread', 'af_advancedpostcounter_schedule_rebuild');
    $plugins->add_hook('class_moderation_split_posts', 'af_advancedpostcounter_schedule_rebuild');

    // перенос темы — считаем дельтой
    $plugins->add_hook('class_moderation_move_simple', 'af_advancedpostcounter_on_move_thread');
    $plugins->add_hook('class_moderation_move_thread_redirect', 'af_advancedpostcounter_on_move_thread');
    $plugins->add_hook('class_moderation_move_threads', 'af_advancedpostcounter_schedule_rebuild');

    // вывод
    $plugins->add_hook('postbit', 'af_advancedpostcounter_postbit');
    $plugins->add_hook('member_profile_end', 'af_advancedpostcounter_member_profile_end');

    // замена маркеров + подключение ассетов
    $plugins->add_hook('pre_output_page', 'af_advancedpostcounter_pre_output');
}

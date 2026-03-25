<?php
/**
 * AF Addon: Advanced Statistic
 * Path: /inc/plugins/advancedfunctionality/addons/advancedstatistic/advancedstatistic.php
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

/* -------------------- Install / Uninstall -------------------- */

function af_advancedstatistic_install(): void
{
    af_advancedstatistic_ensure_settings();
    af_advancedstatistic_install_templates();

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_advancedstatistic_uninstall(): void
{
    af_advancedstatistic_remove_settings();
    af_advancedstatistic_remove_templates();

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_advancedstatistic_activate(): void {}
function af_advancedstatistic_deactivate(): void {}

/* -------------------- AF Hook Entrypoints -------------------- */

function af_advancedstatistic_init(): void
{
    // no-op
}

function af_advancedstatistic_pre_output(&$page = ''): void
{
    global $mybb;

    if (!is_string($page) || $page === '') {
        return;
    }

    // Если мы уже вставляли блок — не трогаем
    if (strpos($page, '<!--af_advancedstatistic-->') !== false) {
        return;
    }

    if (!af_advancedstatistic_is_index()) {
        return;
    }

    $enabled = (int)($mybb->settings['af_advancedstatistic_enabled'] ?? 1);
    if ($enabled !== 1) {
        return;
    }

    // Маркер: хук реально сработал (проверяй view-source)
    if (strpos($page, '<!--af_as_ran-->') === false) {
        if (stripos($page, '</head>') !== false) {
            $page = str_ireplace('</head>', "\n<!--af_as_ran-->\n</head>", $page);
        } else {
            $page = "<!--af_as_ran-->\n" . $page;
        }
    }

    af_advancedstatistic_lang();
    af_advancedstatistic_inject_assets($page);

    $block = af_advancedstatistic_build_block_html();
    if (!is_string($block) || $block === '') {
        return;
    }

    $injected = false;

    // 1) Замена boardstats по стандартным маркерам (пробелы не важны)
    if (preg_match('#<!--\s*start:\s*index_boardstats\s*-->#i', $page)) {
        $new = preg_replace(
            '#<!--\s*start:\s*index_boardstats\s*-->.*?<!--\s*end:\s*index_boardstats\s*-->#si',
            $block,
            $page,
            1,
            $count
        );

        if (is_string($new) && !empty($count)) {
            $page = $new;
            $injected = true;
        }
    }

    // 2) Если маркеры не сработали — режем по реальным якорям boardstats (id=boardstats_e/img)
    // ВАЖНО: без требования <br/> после таблицы — у тем это часто отличается.
    if (!$injected) {
        $new = preg_replace(
            '#<table\b[^>]*>.*?(?:id\s*=\s*["\']boardstats_e["\']|id\s*=\s*["\']boardstats_img["\']).*?</table>#si',
            $block,
            $page,
            1,
            $count
        );

        if (is_string($new) && !empty($count)) {
            $page = $new;
            $injected = true;
        }
    }

    // 3) Если не нашли — вставляем ПЕРЕД закрытием #content (обычно стабильнее)
    if (!$injected) {
        // найдём последний </div> внутри блока content грубо: вставим перед закрывающим div сразу после контента
        // (без сложного парсинга DOM; зато работает на большинстве тем)
        if (preg_match('#<div[^>]*\bid\s*=\s*["\']content["\'][^>]*>#i', $page)) {
            $new = preg_replace(
                '#(<div[^>]*\bid\s*=\s*["\']content["\'][^>]*>)(.*?)(</div>)#si',
                '$1$2' . "\n" . $block . "\n" . '$3',
                $page,
                1,
                $count
            );

            if (is_string($new) && !empty($count)) {
                $page = $new;
                $injected = true;
            }
        }
    }

    // 4) Фоллбек — перед </body>
    if (!$injected && stripos($page, '</body>') !== false) {
        $page = preg_replace('#</body>#i', $block . "\n</body>", $page, 1);
        $injected = true;
    }

    // 5) Последний фоллбек — просто дописать в конец (работает даже на фрагментах)
    if (!$injected) {
        $page .= "\n" . $block . "\n";
        $injected = true;
    }
}

/* -------------------- Helpers -------------------- */

function af_advancedstatistic_is_index(): bool
{
    if (defined('THIS_SCRIPT') && strtolower((string)THIS_SCRIPT) === 'index.php') {
        return true;
    }
    return (isset($_SERVER['SCRIPT_NAME']) && stripos($_SERVER['SCRIPT_NAME'], 'index.php') !== false);
}

function af_advancedstatistic_lang(): void
{
    global $lang;
    if (!isset($lang) || !is_object($lang)) {
        return;
    }

    if (!property_exists($lang, 'af_advancedstatistic_title')) {
        if (function_exists('af_load_lang')) {
            @af_load_lang('advancedstatistic');
        } else {
            if (method_exists($lang, 'load')) {
                @($lang->load('advancedfunctionality_advancedstatistic'));
            }
        }
    }
}

function af_advancedstatistic_inject_assets(string &$page): void
{
    // если уже прогнали дедуп/инжект в этом прогоне — выходим
    if (strpos($page, '<!--af_advancedstatistic_assets-->') !== false) {
        return;
    }

    $baseCss = af_advancedstatistic_asset_url('assets/advancedstatistic.css');
    $baseJs  = af_advancedstatistic_asset_url('assets/advancedstatistic.js');

    // Версия по mtime, чтобы всегда была ОДНА “последняя” ссылка
    $dir = dirname(__FILE__);
    $cssFile = $dir . '/assets/advancedstatistic.css';
    $jsFile  = $dir . '/assets/advancedstatistic.js';

    $vCss = is_file($cssFile) ? (int)@filemtime($cssFile) : TIME_NOW;
    $vJs  = is_file($jsFile)  ? (int)@filemtime($jsFile)  : TIME_NOW;

    $css = $baseCss . (strpos($baseCss, '?') === false ? '?v=' : '&v=') . $vCss;
    $js  = $baseJs  . (strpos($baseJs,  '?') === false ? '?v=' : '&v=') . $vJs;

    // 1) ДЕДУП: вырезаем любые <link> на этот css (с любыми query)
    $cssQuoted = preg_quote($baseCss, '#');
    $page = preg_replace(
        '#<link\b[^>]*href=(["\'])' . $cssQuoted . '(?:\?[^"\']*)?\1[^>]*>\s*#i',
        '',
        $page
    );

    // 2) ДЕДУП: вырезаем любые <script> на этот js (с любыми query)
    $jsQuoted = preg_quote($baseJs, '#');
    $page = preg_replace(
        '#<script\b[^>]*src=(["\'])' . $jsQuoted . '(?:\?[^"\']*)?\1[^>]*>\s*</script>\s*#i',
        '',
        $page
    );

    // 3) Вставляем ровно один комплект
    $tag = "\n<!--af_advancedstatistic_assets-->\n"
         . '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars_uni($css) . '" />' . "\n"
         . '<script src="' . htmlspecialchars_uni($js) . '"></script>' . "\n";

    if (stripos($page, '</head>') !== false) {
        $page = str_ireplace('</head>', $tag . '</head>', $page);
    } else {
        $page = str_replace('{$headerinclude}', '{$headerinclude}' . $tag, $page);
    }
}

function af_advancedstatistic_asset_url(string $relative): string
{
    // Простой абсолютный URL через bburl/settings
    global $mybb;

    $bburl = (string)($mybb->settings['bburl'] ?? '');
    $bburl = rtrim($bburl, '/');

    return $bburl . '/inc/plugins/advancedfunctionality/addons/advancedstatistic/' . ltrim($relative, '/');
}

/* -------------------- Main block builder -------------------- */
function af_advancedstatistic_build_block_html(): string
{
    global $mybb, $lang, $templates;

    $recent_limit = (int)($mybb->settings['af_advancedstatistic_recent_limit'] ?? 5);
    if ($recent_limit <= 0) $recent_limit = 5;

    $online_limit = (int)($mybb->settings['af_advancedstatistic_online_limit'] ?? 12);
    if ($online_limit <= 0) $online_limit = 12;

    $today_limit = (int)($mybb->settings['af_advancedstatistic_today_limit'] ?? 40);
    if ($today_limit <= 0) $today_limit = 40;

    $avatar_size = (int)($mybb->settings['af_advancedstatistic_avatar_size'] ?? 48);
    if ($avatar_size <= 16) $avatar_size = 48;

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    $online_url = ($bburl !== '') ? ($bburl . '/online.php') : 'online.php';

    // ---- базовые статы форума + newest ----
    $stats = af_advancedstatistic_get_board_stats();
    $messages = my_number_format((int)($stats['numposts'] ?? 0));
    $threads  = my_number_format((int)($stats['numthreads'] ?? 0));
    $members  = my_number_format((int)($stats['numusers'] ?? 0));

    // “ВСЕГО постов” из APC
    $apc_posts = af_advancedstatistic_get_apc_posts_total();
    $apc_posts_fmt = ($apc_posts !== null) ? my_number_format((int)$apc_posts) : null;

    $newest_html = af_advancedstatistic_get_newest_member_html($stats);

    // ---- левый блок: последние темы ----
    $recent_html = af_advancedstatistic_build_recent_threads($recent_limit);

    // ---- правый верх: online now аватары ----
    $online_now = af_advancedstatistic_build_online_avatars($online_limit, $avatar_size);

    // ---- правый низ: online today список ----
    $online_today = af_advancedstatistic_build_online_today_links($today_limit);

    // ---- метки Online now: total / staff / users / guests ----
    $wolcutoff = (int)($mybb->settings['wolcutoffmins'] ?? 15);
    if ($wolcutoff <= 0) $wolcutoff = 15;
    $since_now = TIME_NOW - ($wolcutoff * 60);

    $counts_now = af_advancedstatistic_get_online_counts($since_now);
    $now_total  = (int)($counts_now['total'] ?? 0);
    $now_staff  = (int)($counts_now['staff'] ?? 0);
    $now_users  = (int)($counts_now['users'] ?? 0);
    $now_guests = (int)($counts_now['guests'] ?? 0);

    // lang meta labels (вместо хардкода)
    $lbl_meta_online = (string)($lang->af_advancedstatistic_meta_online_now ?? 'online now');
    $lbl_meta_staff  = (string)($lang->af_advancedstatistic_meta_staff ?? 'staff');
    $lbl_meta_users  = (string)($lang->af_advancedstatistic_meta_users ?? 'users');
    $lbl_meta_guests = (string)($lang->af_advancedstatistic_meta_guests ?? 'guests');

    $af_as_online_meta =
        '<span class="af_as_online_meta_item"><b>' . my_number_format($now_total) . '</b> ' . htmlspecialchars_uni($lbl_meta_online) . '</span>' .
        '<span class="af_as_online_meta_sep"></span>' .
        '<span class="af_as_online_meta_item"><b>' . my_number_format($now_staff) . '</b> ' . htmlspecialchars_uni($lbl_meta_staff) . '</span>' .
        '<span class="af_as_online_meta_sep"></span>' .
        '<span class="af_as_online_meta_item"><b>' . my_number_format($now_users) . '</b> ' . htmlspecialchars_uni($lbl_meta_users) . '</span>' .
        '<span class="af_as_online_meta_sep"></span>' .
        '<span class="af_as_online_meta_item"><b>' . my_number_format($now_guests) . '</b> ' . htmlspecialchars_uni($lbl_meta_guests) . '</span>';

    // ---- visitors today: total + breakdown users/guests ----
    $vis = function_exists('af_advancedstatistic_get_visitors_today_counts')
        ? af_advancedstatistic_get_visitors_today_counts()
        : ['users' => 0, 'guests' => 0, 'total' => (int)af_advancedstatistic_get_visitors_today_count()];

    $vis_users  = (int)($vis['users'] ?? 0);
    $vis_guests = (int)($vis['guests'] ?? 0);
    $vis_total  = (int)($vis['total'] ?? ($vis_users + $vis_guests));

    // ---- заголовки ----
    $title = (string)($lang->af_advancedstatistic_title ?? 'Forum statistics');

    $recent_title = (string)($lang->af_advancedstatistic_recent_threads ?? 'Recent threads');
    $online_now_title_text = (string)($lang->af_advancedstatistic_online_now ?? 'Online now');
    $online_today_title_text = (string)($lang->af_advancedstatistic_online_today ?? 'Online today');

    // ✅ Online now: ссылка ВНУТРИ слов "Online now"
    $online_now_title =
        '<span class="af_as_title_row">' .
        '  <a class="af_as_title_text af_as_online_link" href="' . htmlspecialchars_uni($online_url) . '">' . htmlspecialchars_uni($online_now_title_text) . '</a>' .
        '</span>';

    // Online today: число + расшифровка (users || guests) — теперь через lang key
    $today_break_tpl = (string)($lang->af_advancedstatistic_today_breakdown ?? '{1} users | {2} guests');
    $today_break = str_replace(
        ['{1}', '{2}'],
        [my_number_format($vis_users), my_number_format($vis_guests)],
        $today_break_tpl
    );

    $online_today_title =
        '<span class="af_as_title_row">' .
        '  <span class="af_as_title_text">' .
        '    <span class="af_as_today_count">' . my_number_format($vis_total) . '</span> ' .
             htmlspecialchars_uni($online_today_title_text) .
        '    <span class="af_as_today_breakdown">' . htmlspecialchars_uni($today_break) . '</span>' .
        '  </span>' .
        '</span>';

    // ---- лейблы нижних карточек ----
    $lbl_messages = (string)($lang->af_advancedstatistic_stat_messages ?? 'messages');
    $lbl_threads  = (string)($lang->af_advancedstatistic_stat_threads ?? 'threads');
    $lbl_members  = (string)($lang->af_advancedstatistic_stat_members ?? 'registered');
    $lbl_apc      = (string)($lang->af_advancedstatistic_stat_apcposts ?? 'posts written');
    $lbl_newest   = (string)($lang->af_advancedstatistic_stat_newest ?? 'newest member');

    // -------------------------
    // ВАЖНО: порядок карточек
    // messages -> threads -> APC -> members -> newest
    // -------------------------
    $stat_cards  = '';
    $stat_cards .= af_advancedstatistic_render_stat_card($messages, $lbl_messages);
    $stat_cards .= af_advancedstatistic_render_stat_card($threads,  $lbl_threads);

    if ($apc_posts_fmt !== null) {
        $stat_cards .= af_advancedstatistic_render_stat_card($apc_posts_fmt, $lbl_apc);
    }

    $stat_cards .= af_advancedstatistic_render_stat_card($members,  $lbl_members);
    $stat_cards .= af_advancedstatistic_render_stat_card($newest_html, $lbl_newest, true);

    // ---- рендер через шаблон ----
    if (isset($templates) && is_object($templates)) {
        $tpl = $templates->get('af_advancedstatistic_block');
        if (is_string($tpl) && $tpl !== '') {
            $af_as_title = $title;

            $af_as_recent_title = $recent_title;
            $af_as_recent_list = $recent_html;

            $af_as_online_now_title = $online_now_title;
            $af_as_online_now_grid = $online_now;

            $af_as_avatar_size = (int)$avatar_size;

            $af_as_online_today_title = $online_today_title;
            $af_as_online_today_list = $online_today;

            $af_as_online_meta = $af_as_online_meta;
            $af_as_stat_cards = $stat_cards;

            $out = '';
            eval('$out = "' . $tpl . '";');
            return (string)$out;
        }
    }

    return '<!--af_advancedstatistic--><div class="af_as_wrap">' . htmlspecialchars_uni($title) . '</div>';
}

function af_advancedstatistic_render_stat_card(string $value, string $label, bool $valueIsHtml = false): string
{
    $v = $valueIsHtml ? $value : htmlspecialchars_uni($value);
    $l = htmlspecialchars_uni($label);

    return '<div class="af_as_stat">'
         . '  <div class="af_as_stat_num">' . $v . '</div>'
         . '  <div class="af_as_stat_lbl">' . $l . '</div>'
         . '</div>';
}

/* -------------------- Data providers -------------------- */

function af_advancedstatistic_get_board_stats(): array
{
    global $cache;

    if (isset($cache) && is_object($cache) && method_exists($cache, 'read')) {
        $s = $cache->read('stats');
        if (is_array($s)) {
            return $s;
        }
    }
    return [];
}

function af_advancedstatistic_get_newest_member_html(array $stats): string
{
    global $db;

    $uid = (int)($stats['lastuid'] ?? 0);
    $username = (string)($stats['lastusername'] ?? '');

    if ($uid <= 0 || $username === '') {
        return htmlspecialchars_uni($username ?: '—');
    }

    $q = $db->simple_select('users', 'uid, username, usergroup, displaygroup', 'uid=' . $uid, ['limit' => 1]);
    $u = $db->fetch_array($q);

    $name = $username;
    if (is_array($u) && !empty($u['username'])) {
        $name = (string)$u['username'];
    }

    $formatted = function_exists('format_name')
        ? format_name($name, (int)($u['usergroup'] ?? 0), (int)($u['displaygroup'] ?? 0))
        : htmlspecialchars_uni($name);

    if (function_exists('build_profile_link')) {
        return build_profile_link($formatted, $uid);
    }

    return '<a href="member.php?action=profile&uid=' . $uid . '">' . $formatted . '</a>';
}

function af_advancedstatistic_get_apc_posts_total(): ?int
{
    global $mybb, $cache;

    // 1) Каноничный источник: backend helper из APC.
    if (function_exists('af_advancedpostcounter_get_total_posts')) {
        $v = @af_advancedpostcounter_get_total_posts();
        if (is_numeric($v)) return (int)$v;
    }
    if (function_exists('af_apc_get_total_posts')) {
        $v = @af_apc_get_total_posts();
        if (is_numeric($v)) return (int)$v;
    }

    // 2) Legacy fallback: если APC пишет в settings — попробуем несколько вариантов имён.
    $candidates = [
        'af_apc_total_posts',
        'af_advancedpostcounter_total_posts',
        'af_advancedpostcounter_posts_total',
    ];
    foreach ($candidates as $k) {
        if (isset($mybb->settings[$k]) && is_numeric($mybb->settings[$k])) {
            return (int)$mybb->settings[$k];
        }
    }

    // 3) Legacy fallback: если APC пишет в cache — попробуем.
    if (isset($cache) && is_object($cache) && method_exists($cache, 'read')) {
        $c = $cache->read('advancedpostcounter');
        if (is_array($c)) {
            foreach (['total_posts', 'posts_total', 'count', 'total'] as $kk) {
                if (isset($c[$kk]) && is_numeric($c[$kk])) {
                    return (int)$c[$kk];
                }
            }
        }
    }

    // 4) Фоллбек: считаем по правилам APC без сохранения своего кэша,
    // чтобы избежать устаревших значений.
    if (function_exists('af_advancedstatistic_query_apc_total_posts')) {
        $computed = af_advancedstatistic_query_apc_total_posts();
        if (is_numeric($computed)) {
            return (int)$computed;
        }
    }

    return null;
}

/**
 * Фоллбек: считаем “ВСЕГО” постов напрямую из БД по правилам APC:
 * - только видимые посты/темы
 * - uid > 0
 * - только в tracked forums APC
 * - опционально не считаем firstpost (если APC так настроен)
 */
function af_advancedstatistic_query_apc_total_posts(): ?int
{
    global $db;

    // Нужна функция APC, которая отдаёт список учитываемых форумов
    if (!function_exists('af_advancedpostcounter_get_tracked_forums')) {
        return null;
    }

    $fids = af_advancedpostcounter_get_tracked_forums();
    if (empty($fids) || !is_array($fids)) {
        return null;
    }

    $fidList = implode(',', array_map('intval', $fids));
    if ($fidList === '') {
        return null;
    }

    // Условие “не считать первый пост темы”, если APC это выключил
    $firstCond = '1=1';
    if (function_exists('af_advancedpostcounter_count_firstpost_enabled')) {
        $firstCond = af_advancedpostcounter_count_firstpost_enabled() ? '1=1' : 'p.pid != t.firstpost';
    }

    $prefix = TABLE_PREFIX;

    $q = $db->query("
        SELECT COUNT(*) AS c
        FROM {$prefix}posts p
        INNER JOIN {$prefix}threads t ON (t.tid = p.tid)
        WHERE p.visible = 1
          AND t.visible = 1
          AND p.uid > 0
          AND p.fid IN ({$fidList})
          AND {$firstCond}
    ");

    $c = (int)$db->fetch_field($q, 'c');
    return $c >= 0 ? $c : null;
}


function af_advancedstatistic_get_online_counts(int $since): array
{
    global $db, $mybb;

    $since = (int)$since;
    if ($since <= 0) $since = TIME_NOW - 900;

    $canViewInvisible = ((int)($mybb->usergroup['canviewinvisibles'] ?? 0) === 1);

    $sessionsHasInvisible = false;
    $usersHasInvisible = false;
    $groupsHasCanCp = false;
    $groupsHasCanModCp = false;
    $groupsHasIsSuperMod = false;

    if (method_exists($db, 'field_exists')) {
        $sessionsHasInvisible = (bool)$db->field_exists('invisible', 'sessions');
        $usersHasInvisible    = (bool)$db->field_exists('invisible', 'users');

        // MyBB usergroups flags (обычно есть)
        $groupsHasCanCp       = (bool)$db->field_exists('cancp', 'usergroups');
        $groupsHasCanModCp    = (bool)$db->field_exists('canmodcp', 'usergroups');
        $groupsHasIsSuperMod  = (bool)$db->field_exists('issupermod', 'usergroups');
    }

    $invUsersJoin = '';
    $invUsersWhere = '';
    $invSessionsWhere = '';

    if (!$canViewInvisible) {
        if ($sessionsHasInvisible) {
            $invSessionsWhere = " AND s.invisible = 0";
        } elseif ($usersHasInvisible) {
            // на всякий случай отдельный алиас, чтобы не ломать joins в разных запросах
            $invUsersJoin  = " LEFT JOIN " . TABLE_PREFIX . "users uinv ON (uinv.uid = s.uid)";
            $invUsersWhere = " AND (uinv.invisible = 0 OR uinv.invisible IS NULL)";
        }
    }

    // 1) Guests = distinct IP for uid=0
    $qg = $db->query("
        SELECT COUNT(DISTINCT s.ip) AS c
        FROM " . TABLE_PREFIX . "sessions s
        WHERE s.time >= {$since}
          AND s.uid = 0
    ");
    $guests = (int)$db->fetch_field($qg, 'c');

    // 2) Users = distinct uid > 0
    $qu = $db->query("
        SELECT COUNT(DISTINCT s.uid) AS c
        FROM " . TABLE_PREFIX . "sessions s
        {$invUsersJoin}
        WHERE s.time >= {$since}
          AND s.uid > 0
          {$invSessionsWhere}
          {$invUsersWhere}
    ");
    $users = (int)$db->fetch_field($qu, 'c');

    // 3) Staff = users whose primary group has admin/mod flags
    $staff = 0;

    $staffFlags = [];
    if ($groupsHasCanCp)      $staffFlags[] = "g.cancp = 1";        // админка
    if ($groupsHasCanModCp)   $staffFlags[] = "g.canmodcp = 1";     // модкп
    if ($groupsHasIsSuperMod) $staffFlags[] = "g.issupermod = 1";   // супермод

    if (!empty($staffFlags)) {
        $staffWhere = '(' . implode(' OR ', $staffFlags) . ')';

        $qs = $db->query("
            SELECT COUNT(DISTINCT s.uid) AS c
            FROM " . TABLE_PREFIX . "sessions s
            LEFT JOIN " . TABLE_PREFIX . "users u ON (u.uid = s.uid)
            LEFT JOIN " . TABLE_PREFIX . "usergroups g ON (g.gid = u.usergroup)
            WHERE s.time >= {$since}
              AND s.uid > 0
              {$invSessionsWhere}
              " . (!$canViewInvisible && !$sessionsHasInvisible && $usersHasInvisible ? " AND (u.invisible = 0 OR u.invisible IS NULL)" : "") . "
              AND {$staffWhere}
        ");
        $staff = (int)$db->fetch_field($qs, 'c');
    } else {
        // Если внезапно нет нужных колонок (крайне редко) — просто не считаем staff, чтобы не падало.
        $staff = 0;
    }

    return [
        'users'  => $users,
        'guests' => $guests,
        'staff'  => $staff,
        'total'  => ($users + $guests),
    ];
}

function af_advancedstatistic_get_visitors_today_count(): int
{
    $c = af_advancedstatistic_get_visitors_today_counts();
    return (int)($c['total'] ?? 0);
}

function af_advancedstatistic_get_visitors_today_counts(): array
{
    global $db, $mybb;

    $since = TIME_NOW - 86400;

    $canViewInvisible = ((int)($mybb->usergroup['canviewinvisibles'] ?? 0) === 1);

    // Users today (по lastactive)
    $whereUsers = "lastactive >= " . (int)$since;
    if (!$canViewInvisible) {
        $whereUsers .= " AND invisible = 0";
    }

    $qUsers = $db->simple_select('users', 'COUNT(*) AS c', $whereUsers);
    $usersToday = (int)$db->fetch_field($qUsers, 'c');

    // Guests today (distinct IP in sessions за 24ч)
    $qGuests = $db->query("
        SELECT COUNT(DISTINCT s.ip) AS c
        FROM " . TABLE_PREFIX . "sessions s
        WHERE s.time >= " . (int)$since . "
          AND s.uid = 0
    ");
    $guestsToday = (int)$db->fetch_field($qGuests, 'c');

    return [
        'users'  => (int)$usersToday,
        'guests' => (int)$guestsToday,
        'total'  => (int)($usersToday + $guestsToday),
    ];
}

function af_advancedstatistic_build_online_avatars(int $limit, int $size): string
{
    global $db, $mybb;

    $since = TIME_NOW - (int)($mybb->settings['wolcutoffmins'] ?? 15) * 60;
    if ($since <= 0) {
        $since = TIME_NOW - 900;
    }

    $canViewInvisible = ((int)($mybb->usergroup['canviewinvisibles'] ?? 0) === 1);

    $bburl = (string)($mybb->settings['bburl'] ?? '');
    $bburl = rtrim($bburl, '/');
    $defaultAvatar = $bburl . '/images/default_avatar.png';

    $whereParts = [];
    $whereParts[] = 's.time >= ' . (int)$since;
    $whereParts[] = 's.uid > 0';

    if (!$canViewInvisible) {
        $sessionsHasInvisible = false;
        $usersHasInvisible = false;

        if (method_exists($db, 'field_exists')) {
            $sessionsHasInvisible = (bool)$db->field_exists('invisible', 'sessions');
            $usersHasInvisible = (bool)$db->field_exists('invisible', 'users');
        }

        if ($sessionsHasInvisible) {
            $whereParts[] = 's.invisible = 0';
        } elseif ($usersHasInvisible) {
            $whereParts[] = '(u.invisible = 0 OR u.invisible IS NULL)';
        }
    }

    $where = implode(' AND ', $whereParts);

    $sql =
        'SELECT s.uid, MAX(s.time) AS lastseen, ' .
        'u.username, u.usergroup, u.displaygroup, u.avatar, u.avatardimensions ' .
        'FROM ' . TABLE_PREFIX . 'sessions s ' .
        'LEFT JOIN ' . TABLE_PREFIX . 'users u ON (u.uid = s.uid) ' .
        'WHERE ' . $where . ' ' .
        'GROUP BY s.uid ' .
        'ORDER BY lastseen DESC ' .
        'LIMIT ' . (int)$limit;

    $query = $db->query($sql);

    $items = [];
    while ($u = $db->fetch_array($query)) {
        $uid = (int)($u['uid'] ?? 0);
        $username = (string)($u['username'] ?? '');
        if ($uid <= 0 || $username === '') {
            continue;
        }

        $avatarSrc = '';
        $avatarDims = (string)($u['avatardimensions'] ?? '');

        if (function_exists('format_avatar')) {
            $av = format_avatar((string)($u['avatar'] ?? ''), $avatarDims, $size);
            if (is_array($av) && !empty($av['image'])) {
                $avatarSrc = (string)$av['image'];
            }
        }

        if ($avatarSrc === '') {
            $avatarSrc = $defaultAvatar;
        }

        // Группа для стилизации: displaygroup приоритетнее, иначе usergroup
        $gid = (int)($u['displaygroup'] ?? 0);
        if ($gid <= 0) $gid = (int)($u['usergroup'] ?? 0);

        $title = htmlspecialchars_uni($username);
        $href = 'member.php?action=profile&uid=' . $uid;

        // ВАЖНО:
        // 1) Убираем inline width/height — они ломают квадрат и дают “воздух”
        // 2) Добавляем data-af-as-group для CSS фильтров/градиентов
        $items[] =
            '<a class="af_as_avatar" data-af-as-group="' . (int)$gid . '" href="' . htmlspecialchars_uni($href) . '" title="' . $title . '" aria-label="' . $title . '">' .
            '<img src="' . htmlspecialchars_uni($avatarSrc) . '" alt="' . $title . '" loading="lazy" />' .
            '</a>';
    }

    return $items ? implode('', $items) : '';
}

function af_advancedstatistic_build_online_today_links(int $limit): string
{
    global $db, $mybb, $lang;

    $since = TIME_NOW - 86400;

    $canViewInvisible = ((int)($mybb->usergroup['canviewinvisibles'] ?? 0) === 1);
    $where = "lastactive >= " . (int)$since;
    if (!$canViewInvisible) {
        $where .= " AND invisible = 0";
    }

    $query = $db->simple_select(
        'users',
        'uid, username, usergroup, displaygroup, lastactive',
        $where,
        ['order_by' => 'lastactive', 'order_dir' => 'DESC', 'limit' => $limit]
    );

    $users = [];
    while ($u = $db->fetch_array($query)) {
        $uid = (int)$u['uid'];
        $username = (string)$u['username'];

        $formatted = function_exists('format_name')
            ? format_name($username, (int)$u['usergroup'], (int)$u['displaygroup'])
            : htmlspecialchars_uni($username);

        $link = function_exists('build_profile_link')
            ? build_profile_link($formatted, $uid)
            : '<a href="member.php?action=profile&uid=' . $uid . '">' . $formatted . '</a>';

        $users[] = $link;
    }

    if (!$users) {
        return (string)($lang->af_advancedstatistic_none ?? 'None');
    }

    return implode(', ', $users);
}

function af_advancedstatistic_build_recent_threads(int $limit): string
{
    global $db, $mybb, $lang;

    $unviewable = '';
    if (function_exists('get_unviewable_forums')) {
        $unviewable = get_unviewable_forums(true);
    }

    $where = "t.visible=1";
    if (!empty($unviewable)) {
        $where .= " AND t.fid NOT IN ({$unviewable})";
    }

    $query = $db->query("
        SELECT t.tid, t.fid, t.subject, t.lastpost, t.lastposter, t.lastposteruid,
               u.uid AS lp_uid, u.username AS lp_username, u.usergroup AS lp_usergroup, u.displaygroup AS lp_displaygroup,
               u.avatar AS lp_avatar, u.avatardimensions AS lp_avatardimensions
        FROM " . TABLE_PREFIX . "threads t
        LEFT JOIN " . TABLE_PREFIX . "users u ON (u.uid = t.lastposteruid)
        WHERE {$where}
        ORDER BY t.lastpost DESC
        LIMIT " . (int)$limit
    );

    $rows = [];
    while ($r = $db->fetch_array($query)) {
        $tid = (int)($r['tid'] ?? 0);
        $subject = (string)($r['subject'] ?? '');
        if ($tid <= 0 || $subject === '') {
            continue;
        }

        $threadUrl = function_exists('get_thread_link') ? get_thread_link($tid) : ('showthread.php?tid=' . $tid);

        // аватар последнего постера
        $avatarSrc = '';
        if (function_exists('format_avatar')) {
            $av = format_avatar((string)($r['lp_avatar'] ?? ''), (string)($r['lp_avatardimensions'] ?? ''), 40);
            if (is_array($av) && !empty($av['image'])) {
                $avatarSrc = (string)$av['image'];
            }
        }
        if ($avatarSrc === '') {
            $bburl = (string)($mybb->settings['bburl'] ?? '');
            $bburl = rtrim($bburl, '/');
            $avatarSrc = $bburl . '/images/default_avatar.png';
        }

        $lastposter = (string)($r['lastposter'] ?? '');
        $lastposteruid = (int)($r['lastposteruid'] ?? 0);

        $postedBy = (string)($lang->af_advancedstatistic_posted_by ?? 'posted by');

        // Ник (как ССЫЛКА) — теперь безопасно, потому что карточка НЕ <a>
        $lpHtml = htmlspecialchars_uni($lastposter ?: '—');
        if ($lastposteruid > 0) {
            $lpName = $lastposter;
            if (!empty($r['lp_username'])) $lpName = (string)$r['lp_username'];

            $formatted = function_exists('format_name')
                ? format_name($lpName, (int)($r['lp_usergroup'] ?? 0), (int)($r['lp_displaygroup'] ?? 0))
                : htmlspecialchars_uni($lpName);

            $lpHtml = function_exists('build_profile_link')
                ? build_profile_link($formatted, $lastposteruid)
                : '<a href="member.php?action=profile&uid=' . (int)$lastposteruid . '">' . $formatted . '</a>';
        }

        // укоротим заголовок безопасно
        $subjectShort = htmlspecialchars_uni($subject);
        if (function_exists('my_substr')) {
            $subjectShort = htmlspecialchars_uni(my_substr($subject, 0, 44));
        }

        $rows[] =
            '<div class="af_as_recent_item" role="group" aria-label="' . htmlspecialchars_uni($subject) . '">' .
            '  <a class="af_as_recent_avatar" href="' . htmlspecialchars_uni($threadUrl) . '" aria-label="' . htmlspecialchars_uni($subject) . '">' .
            '    <img src="' . htmlspecialchars_uni($avatarSrc) . '" alt="" loading="lazy" />' .
            '  </a>' .
            '  <div class="af_as_recent_text">' .
            '    <a class="af_as_recent_title" href="' . htmlspecialchars_uni($threadUrl) . '">' . $subjectShort . '</a>' .
            '    <div class="af_as_recent_meta">' . htmlspecialchars_uni($postedBy) . ' ' . $lpHtml . '</div>' .
            '  </div>' .
            '</div>';
    }

    return $rows ? implode("\n", $rows) : '';
}

/* -------------------- Settings -------------------- */

function af_advancedstatistic_ensure_settings(): void
{
    global $db, $lang;

    $groupName = 'af_advancedstatistic';
    $query = $db->simple_select('settinggroups', 'gid', "name='" . $db->escape_string($groupName) . "'", ['limit' => 1]);
    $gid = (int)$db->fetch_field($query, 'gid');

    if ($gid <= 0) {
        $insert = [
            'name'        => $groupName,
            'title'       => $db->escape_string($lang->af_advancedstatistic_group ?? 'Advanced Statistic'),
            'description' => $db->escape_string($lang->af_advancedstatistic_group_desc ?? ''),
            'disporder'   => 1,
            'isdefault'   => 0,
        ];
        $gid = (int)$db->insert_query('settinggroups', $insert);
    }

    af_advancedstatistic_upsert_setting([
        'name'        => 'af_advancedstatistic_enabled',
        'title'       => $lang->af_advancedstatistic_enabled ?? 'Enable',
        'description' => $lang->af_advancedstatistic_enabled_desc ?? '',
        'optionscode' => 'yesno',
        'value'       => '1',
        'disporder'   => 1,
        'gid'         => $gid,
    ]);

    af_advancedstatistic_upsert_setting([
        'name'        => 'af_advancedstatistic_online_limit',
        'title'       => $lang->af_advancedstatistic_online_limit ?? 'Online now avatar limit',
        'description' => $lang->af_advancedstatistic_online_limit_desc ?? '',
        'optionscode' => 'text',
        'value'       => '12',
        'disporder'   => 2,
        'gid'         => $gid,
    ]);

    af_advancedstatistic_upsert_setting([
        'name'        => 'af_advancedstatistic_today_limit',
        'title'       => $lang->af_advancedstatistic_today_limit ?? 'Online today list limit',
        'description' => $lang->af_advancedstatistic_today_limit_desc ?? '',
        'optionscode' => 'text',
        'value'       => '40',
        'disporder'   => 3,
        'gid'         => $gid,
    ]);

    af_advancedstatistic_upsert_setting([
        'name'        => 'af_advancedstatistic_recent_limit',
        'title'       => $lang->af_advancedstatistic_recent_limit ?? 'Recent threads limit',
        'description' => $lang->af_advancedstatistic_recent_limit_desc ?? '',
        'optionscode' => 'text',
        'value'       => '5',
        'disporder'   => 4,
        'gid'         => $gid,
    ]);

    af_advancedstatistic_upsert_setting([
        'name'        => 'af_advancedstatistic_avatar_size',
        'title'       => $lang->af_advancedstatistic_avatar_size ?? 'Online now avatar size',
        'description' => $lang->af_advancedstatistic_avatar_size_desc ?? '',
        'optionscode' => 'text',
        'value'       => '48',
        'disporder'   => 5,
        'gid'         => $gid,
    ]);
}

function af_advancedstatistic_upsert_setting(array $s): void
{
    global $db;

    $name = (string)($s['name'] ?? '');
    if ($name === '') return;

    $query = $db->simple_select('settings', 'sid', "name='" . $db->escape_string($name) . "'", ['limit' => 1]);
    $sid = (int)$db->fetch_field($query, 'sid');

    $row = [
        'name'        => $db->escape_string($name),
        'title'       => $db->escape_string((string)($s['title'] ?? '')),
        'description' => $db->escape_string((string)($s['description'] ?? '')),
        'optionscode' => $db->escape_string((string)($s['optionscode'] ?? 'text')),
        'value'       => $db->escape_string((string)($s['value'] ?? '')),
        'disporder'   => (int)($s['disporder'] ?? 1),
        'gid'         => (int)($s['gid'] ?? 0),
    ];

    if ($sid > 0) $db->update_query('settings', $row, "sid=" . (int)$sid);
    else $db->insert_query('settings', $row);
}

function af_advancedstatistic_remove_settings(): void
{
    global $db;

    $groupName = 'af_advancedstatistic';
    $query = $db->simple_select('settinggroups', 'gid', "name='" . $db->escape_string($groupName) . "'", ['limit' => 1]);
    $gid = (int)$db->fetch_field($query, 'gid');

    if ($gid > 0) {
        $db->delete_query('settings', "gid=" . (int)$gid);
        $db->delete_query('settinggroups', "gid=" . (int)$gid);
    }
}

/* -------------------- Templates -------------------- */

function af_advancedstatistic_install_templates(): void
{
    global $db;

    $templates = af_advancedstatistic_read_templates_file();
    if (!$templates) return;

    foreach ($templates as $title => $templateHtml) {
        $titleEsc = $db->escape_string($title);

        $query = $db->simple_select('templates', 'tid', "title='{$titleEsc}' AND sid=-2", ['limit' => 1]);
        $tid = (int)$db->fetch_field($query, 'tid');

        $row = [
            'title'    => $titleEsc,
            'template' => $db->escape_string($templateHtml),
            'sid'      => -2,
            'version'  => '1839',
            'dateline' => TIME_NOW,
        ];

        if ($tid > 0) $db->update_query('templates', $row, "tid=" . (int)$tid);
        else $db->insert_query('templates', $row);
    }
}

function af_advancedstatistic_remove_templates(): void
{
    global $db;
    $db->delete_query('templates', "sid=-2 AND title IN ('af_advancedstatistic_block')");
}

function af_advancedstatistic_read_templates_file(): array
{
    $path = dirname(__FILE__) . '/templates/advancedstatistic.html';
    if (!is_file($path)) return [];

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') return [];

    $out = [];
    if (preg_match_all('#<!--\s*TEMPLATE:\s*([a-z0-9_\-]+)\s*-->\s*(.*?)\s*<!--\s*/TEMPLATE\s*-->#is', $raw, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $name = trim((string)$match[1]);
            $html = (string)$match[2];
            if ($name !== '' && $html !== '') {
                $out[$name] = $html;
            }
        }
    }

    return $out;
}

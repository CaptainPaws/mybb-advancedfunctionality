<?php
/**
 * AF Addon: AdvancedMenu
 * MyBB 1.8.38–1.8.39, PHP 8.0–8.4
 *
 * Делает кастомные пункты меню и внедряет их в:
 *  - <ul class="menu top_links"> (header)
 *  - <ul class="menu panel_links"> (header_welcomeblock_member)
 *
 * Режимы:
 *  - append: оставить старое + дописать новое
 *  - replace: заменить на новое (panel_links при этом сохраняет защищённые пункты, напр. AAS/AAM)
 *
 * Скрытие старых пунктов делается через "паттерны" (substring match по HTML <li>).
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { /* аддон предполагает наличие ядра AF */ }

define('AF_AM_ID', 'advancedmenu');
define('AF_AM_TABLE_ITEMS', 'af_advancedmenu_items');
define('AF_AM_CACHE_KEY', 'af_advancedmenu_items');
define('AF_AM_ASSETS_MARK', '<!--af_advancedmenu_assets-->');
define('AF_AM_APPLIED_MARK', '<!--af_advancedmenu_applied-->');

/* =========================
   BOOTSTRAP / ENSURE
   ========================= */

function af_advancedmenu_ensure_installed(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    global $db, $cache;

    // 1) Таблица
    if (method_exists($db, 'table_exists') && !$db->table_exists(AF_AM_TABLE_ITEMS)) {
        af_advancedmenu_install_db();
    } else {
        // На всякий случай: если table_exists нет (старые обёртки), пробуем CREATE IF NOT EXISTS
        af_advancedmenu_install_db();
    }

    // 2) Настройки (делаем идемпотентно)
    af_advancedmenu_install_settings();

    // 3) Шаблоны (не обязательно, но полезно для кастомизации li)
    af_advancedmenu_sync_templates();

    // 4) Кэш
    if (!is_object($cache)) {
        return;
    }
    $cache->update(AF_AM_CACHE_KEY, null);
}

function af_advancedmenu_install_db(): void
{
    global $db;

    $collation = $db->build_create_table_collation();

    $db->write_query("
        CREATE TABLE IF NOT EXISTS `".TABLE_PREFIX.AF_AM_TABLE_ITEMS."` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `location` varchar(10) NOT NULL DEFAULT 'top',
            `slug` varchar(64) NOT NULL,
            `title` varchar(255) NOT NULL,
            `url` varchar(500) NOT NULL,
            `icon` varchar(255) NOT NULL DEFAULT '',
            `sort_order` int NOT NULL DEFAULT 10,
            `enabled` tinyint(1) NOT NULL DEFAULT 1,
            `visibility` varchar(255) NOT NULL DEFAULT '',
            `created_at` int unsigned NOT NULL DEFAULT 0,
            `updated_at` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_loc_slug` (`location`, `slug`),
            KEY `idx_loc_sort` (`location`, `sort_order`, `id`)
        ) {$collation}
    ");

    // Мягкий апгрейд: если таблица была создана раньше без icon — добавим.
    if (method_exists($db, 'field_exists') && !$db->field_exists('icon', AF_AM_TABLE_ITEMS)) {
        $db->write_query("ALTER TABLE `".TABLE_PREFIX.AF_AM_TABLE_ITEMS."` ADD COLUMN `icon` varchar(255) NOT NULL DEFAULT '' AFTER `url`");
    } else {
        // fallback для обёрток без field_exists: попробуем, и если упадёт — MyBB перехватит/логнет
        // (но чаще field_exists в MyBB есть)
    }
}

function af_advancedmenu_install_settings(): void
{
    global $db, $cache;

    $group_title = 'AdvancedMenu — меню шапки';
    $group_desc  = 'Управление пунктами меню в шапке: верхнее меню (top_links) и меню панели пользователя (panel_links). Можно дописывать или полностью заменять, а также скрывать старые пункты по “паттернам”.';

    // 1) Дедупликация групп
    $gids = [];
    $qg = $db->simple_select('settinggroups', 'gid', "name='af_advancedmenu'", ['order_by' => 'gid', 'order_dir' => 'ASC']);
    while ($r = $db->fetch_array($qg)) {
        $gids[] = (int)$r['gid'];
    }

    if (empty($gids)) {
        $db->insert_query('settinggroups', [
            'name'        => 'af_advancedmenu',
            'title'       => $group_title,
            'description' => $group_desc,
            'disporder'   => 100,
            'isdefault'   => 0,
        ]);
        $gid = (int)$db->insert_id();
    } else {
        $gid = (int)$gids[0];

        if (count($gids) > 1) {
            $extras = array_slice($gids, 1);
            foreach ($extras as $badGid) {
                $db->update_query('settings', ['gid' => $gid], "gid='".(int)$badGid."'");
                $db->delete_query('settinggroups', "gid='".(int)$badGid."'");
            }
        }

        $db->update_query('settinggroups', [
            'title'       => $group_title,
            'description' => $group_desc,
            'disporder'   => 100,
            'isdefault'   => 0,
        ], "gid='{$gid}'");
    }

    $ensure = function(
        string $name,
        string $title,
        string $desc,
        string $optionscode,
        string $value,
        int $disporder
    ) use ($db, $gid): void {
        $row = $db->fetch_array($db->simple_select('settings', 'sid', "name='".$db->escape_string($name)."'", ['limit' => 1]));
        $sid = isset($row['sid']) ? (int)$row['sid'] : 0;

        if ($sid > 0) {
            $db->update_query('settings', [
                'title'       => $title,
                'description' => $desc,
                'optionscode' => $optionscode,
                'disporder'   => $disporder,
                'gid'         => $gid,
            ], "sid='{$sid}'");
            return;
        }

        $db->insert_query('settings', [
            'name'        => $name,
            'title'       => $title,
            'description' => $desc,
            'optionscode' => $optionscode,
            'value'       => $value,
            'disporder'   => $disporder,
            'gid'         => $gid,
        ]);
    };

    $ensure('af_advancedmenu_enabled', 'Включить AdvancedMenu', 'Общий рубильник аддона. Если выключено — меню не меняем вообще.', 'yesno', '1', 1);

    $ensure('af_advancedmenu_top_mode', 'Верхнее меню (top_links): режим',
        "append — оставить старые пункты и добавить новые.\nreplace — заменить содержимое <ul> на ваши пункты (можно сохранить “защищённые” через защищённые паттерны).",
        "select\nappend=append\nreplace=replace", 'append', 10);

    $ensure('af_advancedmenu_panel_mode', 'Меню панели пользователя (panel_links): режим',
        "append — оставить старые пункты и добавить новые.\nreplace — заменить содержимое <ul> на ваши пункты (и при желании сохранить “защищённые”, например колокольчик/аккаунты).",
        "select\nappend=append\nreplace=replace", 'append', 20);

    $ensure('af_advancedmenu_top_hide', 'Верхнее меню: скрыть старые пункты (паттерны)',
        "Через запятую. Если HTML <li> содержит подстроку — пункт будет удалён из top_links.\nПример: private.php, memberlist.php, misc.php?action=help",
        'text', '', 30);

    $ensure('af_advancedmenu_panel_hide', 'Панель пользователя: скрыть старые пункты (паттерны)',
        "Через запятую. Если HTML <li> содержит подстроку — пункт будет удалён из panel_links.\nПример: private.php, search.php?action=getdaily",
        'text', '', 40);

    // НОВОЕ: user_links (buddylink/searchlink/pmslink)
    $ensure('af_advancedmenu_user_hide', 'Меню user_links: скрыть старые пункты (паттерны)',
        "Это <ul class=\"menu user_links\"> из header_welcomeblock_member.\nЧерез запятую. Если HTML <li> содержит подстроку — пункт будет удалён.\nПример: private.php (чтобы убрать ЛС из user_links).",
        'text', '', 45);

    $ensure('af_advancedmenu_panel_protect', 'Панель: защищённые пункты (паттерны)',
        "Работает только в panel_links и только в режиме replace.\nЕсли <li> содержит подстроку из списка — этот пункт сохраняется (например колокольчик/аккаунты).",
        'text', 'af_aam_,af-aam,af_aas_,af-aas,af_aam_header_link,af_aas_switch,action=af_aas,action=af_aam', 50);

    $ensure('af_advancedmenu_panel_protect_pos', 'Панель: позиция защищённых пунктов',
        "start — поставить защищённые пункты перед вашими.\nend — поставить защищённые пункты после ваших.",
        "select\nstart=start\nend=end", 'end', 60);

    $ensure('af_advancedmenu_top_protect', 'Верхнее меню: защищённые пункты (паттерны)',
        "Работает только в top_links и только в режиме replace.\nЕсли <li> содержит подстроку из списка — пункт будет сохранён.",
        'text', '', 65);

    $ensure('af_advancedmenu_top_protect_pos', 'Верхнее меню: позиция защищённых пунктов',
        "start — поставить защищённые пункты перед вашими.\nend — поставить защищённые пункты после ваших.",
        "select\nstart=start\nend=end", 'end', 66);

    $ensure('af_advancedmenu_extra_targets', 'Доп. меню (классы <ul>)',
        "Через запятую: классы <ul>, куда можно вставлять пункты.\nПример: footer_links, my_custom_menu",
        'text', '', 67);

    $ensure(
        'af_advancedmenu_top_strip_imgs',
        'Верхнее меню: убрать <img> у пунктов (паттерны)',
        "Через запятую. Если <li> содержит подстроку — из него будет удалён тег <img ...>.\nПример: search.php (чтобы убрать лупу).",
        'text',
        'search.php',
        68
    );

    $ensure(
        'af_advancedmenu_panel_strip_imgs',
        'Панель: убрать <img> у пунктов (паттерны)',
        "Через запятую. Если <li> содержит подстроку — из него будет удалён тег <img ...>.\nОбычно панель без картинок, но оставим на всякий.",
        'text',
        '',
        69
    );

    $ensure('af_advancedmenu_assets', 'Подключать CSS/JS AdvancedMenu',
        'Если выключено — ассеты не будут внедряться в <head>.',
        'yesno', '1', 70);

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }

    if (is_object($cache)) {
        $cache->update('settings', null);
    }
}

/* =========================
   TEMPLATES SYNC
   ========================= */

function af_advancedmenu_sync_templates(): void
{
    global $db;

    $src = AF_ADDONS.AF_AM_ID.'/templates/advancedmenu.html';
    if (!is_file($src) || !is_readable($src)) {
        return;
    }

    $html = file_get_contents($src);
    if ($html === false || trim($html) === '') {
        return;
    }

    $templates = af_advancedmenu_parse_templates($html);
    if (empty($templates)) {
        return;
    }

    foreach ($templates as $name => $tpl) {
        $title = $db->escape_string($name);
        $exists = (int)$db->fetch_field($db->simple_select('templates', 'tid', "title='{$title}' AND sid='-1'"), 'tid');

        $data = [
            'title'    => $name,
            'template' => $db->escape_string($tpl),
            'sid'      => -1,
            'version'  => 1800,
            'dateline' => TIME_NOW,
        ];

        if ($exists > 0) {
            $db->update_query('templates', $data, "tid='{$exists}'");
        } else {
            $db->insert_query('templates', $data);
        }
    }
}

/**
 * Парсер шаблонов из файла advancedmenu.html:
 * <!-- TEMPLATE: tpl_name --> ...html...
 */
function af_advancedmenu_parse_templates(string $html): array
{
    $out = [];

    $pattern = '~<!--\s*TEMPLATE:\s*([a-zA-Z0-9_\-]+)\s*-->\s*(.*?)\s*(?=(<!--\s*TEMPLATE:\s*[a-zA-Z0-9_\-]+\s*-->|$))~si';
    if (!preg_match_all($pattern, $html, $m, PREG_SET_ORDER)) {
        return $out;
    }

    foreach ($m as $row) {
        $name = trim($row[1]);
        $tpl  = trim($row[2]);
        if ($name !== '' && $tpl !== '') {
            $out[$name] = $tpl;
        }
    }

    return $out;
}

/* =========================
   LANG
   ========================= */

function af_advancedmenu_load_lang(bool $admin = false): void
{
    global $lang;

    if (!is_object($lang)) {
        if (class_exists('MyLanguage')) {
            $lang = new MyLanguage();
        } else {
            return;
        }
    }

    // AF core обычно сам подцепляет advancedfunctionality_{addon}.lang.php
    // но тут мягкая подстраховка.
    $file = $admin ? 'advancedfunctionality_advancedmenu' : 'advancedfunctionality_advancedmenu';
    if (method_exists($lang, 'load')) {
        // (true,true) в MyBB = admin/lang fallback, зависит от версии; лишним не будет
        $lang->load($file, true, true);
    }
}

/* =========================
   DATA / CACHE
   ========================= */

function af_advancedmenu_get_items(bool $force_db = false): array
{
    global $cache, $db;

    if (!$force_db && is_object($cache)) {
        $cached = $cache->read(AF_AM_CACHE_KEY);
        if (is_array($cached) && isset($cached['items']) && is_array($cached['items'])) {
            return $cached['items'];
        }
    }

    $items = [];
    $q = $db->simple_select(AF_AM_TABLE_ITEMS, '*', '', ['order_by' => 'location, sort_order, id', 'order_dir' => 'ASC']);
    while ($row = $db->fetch_array($q)) {
        $items[] = $row;
    }

    if (is_object($cache)) {
        $cache->update(AF_AM_CACHE_KEY, ['items' => $items, 'ts' => TIME_NOW]);
    }

    return $items;
}

function af_advancedmenu_rebuild_cache(): void
{
    global $cache;
    if (is_object($cache)) {
        $cache->update(AF_AM_CACHE_KEY, null);
    }
    af_advancedmenu_get_items(true);
}

/* =========================
   RENDER HELPERS
   ========================= */

function af_advancedmenu_normalize_url(string $url): string
{
    global $mybb;

    $url = trim($url);
    if ($url === '') {
        return '#';
    }

    // Уже абсолютная
    if (preg_match('~^(https?:)?//~i', $url)) {
        return $url;
    }

    // bburl
    $bburl = isset($mybb->settings['bburl']) ? rtrim($mybb->settings['bburl'], '/') : '';

    // относительная от корня
    if ($url[0] === '/') {
        return $bburl.$url;
    }

    // относительная "page.php"
    return $bburl.'/'.ltrim($url, '/');
}

function af_advancedmenu_parse_csv(string $csv): array
{
    $csv = (string)$csv;

    // поддерживаем ввод:
    // - через запятую
    // - через точку с запятой
    // - через перенос строки
    // - через табы/множественные пробелы (как разделители)
    //
    // Пример (всё ок):
    // usercp.php
    // memberlist.php, private.php; search.php
    //
    $parts = preg_split('~[,\r\n;]+~', $csv);

    if (!is_array($parts)) {
        $parts = [$csv];
    }

    $out = [];
    foreach ($parts as $p) {
        $p = trim((string)$p);

        // если кто-то вставил несколько паттернов через пробелы,
        // но без запятых/переносов — разобьём дополнительно
        if ($p !== '' && preg_match('~\s{2,}~', $p)) {
            foreach (preg_split('~\s{2,}~', $p) as $pp) {
                $pp = trim((string)$pp);
                if ($pp !== '') {
                    $out[] = $pp;
                }
            }
            continue;
        }

        if ($p !== '') {
            $out[] = $p;
        }
    }

    return array_values(array_unique($out));
}

function af_advancedmenu_get_user_group_ids(): array
{
    global $mybb;

    $ids = [];

    $ug = isset($mybb->user['usergroup']) ? (int)$mybb->user['usergroup'] : 0;
    if ($ug > 0) {
        $ids[] = $ug;
    }

    $add = isset($mybb->user['additionalgroups']) ? (string)$mybb->user['additionalgroups'] : '';
    if ($add !== '') {
        foreach (explode(',', $add) as $g) {
            $g = (int)trim($g);
            if ($g > 0) {
                $ids[] = $g;
            }
        }
    }

    $ids = array_values(array_unique($ids));
    return $ids;
}

/**
 * visibility форматы:
 *  - ''            => всем
 *  - 'guests'      => только гостям
 *  - 'users'       => только авторизованным
 *  - 'groups:1,4'  => только группам (gid)
 */
function af_advancedmenu_item_is_visible(array $item): bool
{
    global $mybb;

    $isGuest = empty($mybb->user['uid']) || (int)$mybb->user['uid'] === 0;

    // авто-санити: usercp/private для гостя скрываем даже если админ забыл visibility
    if ($isGuest) {
        $rawUrl = isset($item['url']) ? (string)$item['url'] : '';
        if ($rawUrl !== '') {
            $u = strtolower($rawUrl);
            if (strpos($u, 'usercp.php') !== false || strpos($u, 'private.php') !== false) {
                return false;
            }
        }
    }

    $vis = isset($item['visibility']) ? trim((string)$item['visibility']) : '';
    if ($vis === '' || strcasecmp($vis, 'all') === 0) {
        return true;
    }

    if (strcasecmp($vis, 'guest') === 0 || strcasecmp($vis, 'guests') === 0) {
        return $isGuest;
    }

    if (
        strcasecmp($vis, 'user') === 0 ||
        strcasecmp($vis, 'users') === 0 ||
        strcasecmp($vis, 'members') === 0 ||
        strcasecmp($vis, 'registered') === 0
    ) {
        return !$isGuest;
    }

    if (stripos($vis, 'groups:') === 0) {
        $list = trim(substr($vis, 7));
        if ($list === '') {
            return false;
        }

        $allowed = [];
        foreach (explode(',', $list) as $g) {
            $g = (int)trim($g);
            if ($g > 0) {
                $allowed[] = $g;
            }
        }
        if (empty($allowed)) {
            return false;
        }

        $userGids = af_advancedmenu_get_user_group_ids();
        foreach ($userGids as $gid) {
            if (in_array((int)$gid, $allowed, true)) {
                return true;
            }
        }
        return false;
    }

    // неизвестный формат — безопаснее скрыть
    return false;
}


/**
 * Очень простая "санитарка" под админский ввод:
 * - режем <script>
 * - режем on*=
 */
function af_advancedmenu_sanitize_icon_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    // Если в БД/инпуте прилетело &lt;img ...&gt; — раскодируем обратно в HTML
    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // На всякий случай — если там двойная кодировка, декодим второй раз (но аккуратно)
    if (strpos($decoded, '&lt;') !== false || strpos($decoded, '&amp;lt;') !== false) {
        $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    $html = trim($decoded);

    // режем <script>
    $html = preg_replace('~<\s*script\b.*?>.*?<\s*/\s*script\s*>~si', '', $html);

    // режем on*=
    $html = preg_replace('~\son\w+\s*=\s*(["\']).*?\1~si', '', $html);

    // примитивная защита от javascript: в href/src
    $html = preg_replace('~\b(href|src)\s*=\s*(["\'])\s*javascript\s*:[^"\']*\2~si', '$1=$2#$2', $html);

    return trim((string)$html);
}

function af_advancedmenu_apply_strip_img_patterns(string $ulInner, array $patterns): string
{
    $patterns = array_values(array_filter(array_map('trim', $patterns), static function($v) {
        return $v !== '';
    }));

    if (empty($patterns)) {
        return $ulInner;
    }

    $lis = af_advancedmenu_split_li($ulInner);
    if (empty($lis)) {
        return $ulInner;
    }

    $out = [];
    foreach ($lis as $li) {
        $match = false;
        foreach ($patterns as $p) {
            if (stripos($li, $p) !== false) {
                $match = true;
                break;
            }
        }

        if ($match) {
            // вырезаем <img ...> внутри этого li
            $li = preg_replace('~<img\b[^>]*>~i', '', $li);
        }

        $out[] = $li;
    }

    return implode("\n", $out);
}

function af_advancedmenu_css_escape(string $s): string
{
    // безопасно для вставки в CSS-строку в одинарных/двойных кавычках
    $s = (string)$s;
    $s = str_replace(["\\", "\r", "\n", "\t"], ["\\\\", " ", " ", " "], $s);
    $s = str_replace(["'", '"'], ["\\'", '\\"'], $s);
    // режем управляющие
    $s = preg_replace('~[\x00-\x1F\x7F]~', '', $s);
    return (string)$s;
}

/**
 * Понимает icon в форматах:
 *  - 🔔 (эмодзи/текст)
 *  - https://site/icon.png или /images/icon.svg (url картинки)
 *  - fa-solid fa-bell  (FontAwesome классы)
 *  - legacy: <img src="..."> или <i class="..."></i> (подхватим)
 */
function af_advancedmenu_icon_parse(string $raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return ['type' => 'none', 'value' => ''];
    }

    // если прилетело в виде &lt;img ...&gt; — декодим
    $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $decoded = trim($decoded);

    // legacy <img src="...">
    if (stripos($decoded, '<img') !== false) {
        if (preg_match('~\bsrc\s*=\s*(["\'])(.*?)\1~si', $decoded, $m)) {
            $src = trim((string)$m[2]);
            if ($src !== '') {
                return ['type' => 'img', 'value' => $src];
            }
        }
    }

    // legacy <i class="...">
    if (stripos($decoded, '<i') !== false) {
        if (preg_match('~\bclass\s*=\s*(["\'])(.*?)\1~si', $decoded, $m)) {
            $cls = trim((string)$m[2]);
            if ($cls !== '') {
                return ['type' => 'fa', 'value' => $cls];
            }
        }
    }

    // если похоже на URL картинки
    if (preg_match('~^(https?:)?//~i', $decoded) || str_starts_with($decoded, '/') || str_ends_with(strtolower($decoded), '.png') || str_ends_with(strtolower($decoded), '.jpg') || str_ends_with(strtolower($decoded), '.jpeg') || str_ends_with(strtolower($decoded), '.gif') || str_ends_with(strtolower($decoded), '.svg') || str_ends_with(strtolower($decoded), '.webp')) {
        return ['type' => 'img', 'value' => $decoded];
    }

    // если похоже на FontAwesome классы
    if (preg_match('~\bfa[a-z-]*\b~i', $decoded) || preg_match('~\bfa-[a-z0-9-]+\b~i', $decoded)) {
        return ['type' => 'fa', 'value' => $decoded];
    }

    // иначе — текст/эмодзи
    return ['type' => 'text', 'value' => $decoded];
}

function af_advancedmenu_render_item(array $item): string
{
    global $templates;

    $location = ($item['location'] === 'panel') ? 'panel' : 'top';
    $slug     = (string)$item['slug'];
    $title    = htmlspecialchars_uni((string)$item['title']);
    $url      = htmlspecialchars_uni(af_advancedmenu_normalize_url((string)$item['url']));

    // ИКОНКА: превращаем в безопасный HTML+CSS-хуки (без “шакаленья” кавычек)
    $iconRaw = isset($item['icon']) ? (string)$item['icon'] : '';
    $icon    = af_advancedmenu_icon_parse($iconRaw);

    $iconHtml = '';
    if ($icon['type'] === 'text' && $icon['value'] !== '') {
        $txt = af_advancedmenu_css_escape((string)$icon['value']);
        // ВАЖНО: для content нужен value с кавычками
        $style = '--af-am-icon-text:"'.$txt.'";';
        $iconHtml = '<span class="af-am-ico af-am-ico-text" style="'.htmlspecialchars_uni($style).'" aria-hidden="true"></span>';
    } elseif ($icon['type'] === 'img' && $icon['value'] !== '') {
        $src = af_advancedmenu_normalize_url((string)$icon['value']);
        $srcEsc = af_advancedmenu_css_escape($src);
        $style = "--af-am-icon-url:url('".$srcEsc."');";
        $iconHtml = '<span class="af-am-ico af-am-ico-img" style="'.htmlspecialchars_uni($style).'" aria-hidden="true"></span>';
    } elseif ($icon['type'] === 'fa' && $icon['value'] !== '') {
        // чистим классы, чтобы не пролезло ничего странного
        $cls = preg_replace('~[^a-z0-9_\-\s]~i', '', (string)$icon['value']);
        $cls = trim(preg_replace('~\s+~', ' ', $cls));
        if ($cls !== '') {
            $iconHtml = '<span class="af-am-ico af-am-ico-fa '.htmlspecialchars_uni($cls).'" aria-hidden="true"></span>';
        }
    }

    $tplName = 'af_advancedmenu_item';
    $tpl = '';
    if (is_object($templates) && method_exists($templates, 'get')) {
        $tpl = (string)$templates->get($tplName);
    }

    if ($tpl !== '') {
        $af_am_location = $location;
        $af_am_slug     = $slug;
        $af_am_title    = $title;
        $af_am_url      = $url;
        $af_am_icon     = $iconHtml;

        $out = '';
        eval("\$out = \"$tpl\";");
        return (string)$out;
    }

    // fallback
    return '<li class="af-am-item af-am-'.$location.' af-am-'.$slug.'"><a class="af-am-link" href="'.$url.'">'.$iconHtml.'<span class="af-am-title">'.$title.'</span></a></li>';
}


function af_advancedmenu_resolve_target(string $location): string
{
    $loc = trim(strtolower($location));

    // Обратная совместимость: старые значения 'top'/'panel'
    if ($loc === 'top') {
        return 'top_links';
    }
    if ($loc === 'panel') {
        return 'panel_links';
    }

    // Новые “типы меню”: разрешаем указывать прямо класс UL (например footer_links)
    // Оставим только безопасные символы класса
    $loc = preg_replace('~[^a-z0-9_\-]~i', '', $loc);
    return $loc !== '' ? $loc : 'top_links';
}

function af_advancedmenu_build_menu_html(string $targetUlClass): string
{
    $items = af_advancedmenu_get_items();
    $target = af_advancedmenu_resolve_target($targetUlClass);

    $out = '';
    foreach ($items as $it) {
        if ((int)$it['enabled'] !== 1) {
            continue;
        }

        if (!af_advancedmenu_item_is_visible($it)) {
            continue;
        }

        $itemTarget = af_advancedmenu_resolve_target((string)$it['location']);
        if ($itemTarget !== $target) {
            continue;
        }

        $out .= af_advancedmenu_render_item($it)."\n";
    }

    return $out;
}

function af_advancedmenu_set_template_vars(): void
{
    $items = af_advancedmenu_get_items();

    $topAgg   = '';
    $panelAgg = '';

    foreach ($items as $it) {
        if ((int)$it['enabled'] !== 1) {
            continue;
        }

        if (!af_advancedmenu_item_is_visible($it)) {
            continue;
        }

        $loc  = ($it['location'] === 'panel') ? 'panel' : 'top';
        $slug = (string)$it['slug'];
        if ($slug === '') {
            continue;
        }

        $li = af_advancedmenu_render_item($it);

        if ($loc === 'top') {
            $GLOBALS['menu_'.$slug] = $li;
            $topAgg .= $li."\n";
        } else {
            $GLOBALS['panel_'.$slug] = $li;
            $panelAgg .= $li."\n";
        }
    }

    $GLOBALS['af_advancedmenu_top']   = $topAgg;
    $GLOBALS['af_advancedmenu_panel'] = $panelAgg;
}

/* =========================
   FRONT GUARDS
   ========================= */
function af_advancedmenu_is_frontend_context(): bool
{
    global $mybb;

    // ACP — никогда не трогаем
    if (defined('IN_ADMINCP') && IN_ADMINCP) {
        return false;
    }

    // redirect page (не трогаем такие страницы)
    if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'misc.php') {
        $action = isset($mybb->input['action']) ? (string)$mybb->input['action'] : '';
        if ($action === 'redirect') {
            return false;
        }
    }

    return true;
}


/* =========================
   HTML MUTATION
   ========================= */
function af_advancedmenu_split_li(string $ulInner): array
{
    $html = (string)$ulInner;
    $len  = strlen($html);
    if ($len === 0) {
        return [];
    }

    $out = [];
    $pos = 0;

    while (true) {
        $start = stripos($html, '<li', $pos);
        if ($start === false) {
            break;
        }

        // найдём конец открывающего <li ...>
        $openEnd = strpos($html, '>', $start);
        if ($openEnd === false) {
            break;
        }

        $depth = 0;
        $scan  = $start;

        // балансируем li / /li
        while (true) {
            if (!preg_match('~</?li\b~i', $html, $m, PREG_OFFSET_CAPTURE, $scan)) {
                // нет закрытия — выходим, чтобы не ломать страницу
                $pos = $openEnd + 1;
                break;
            }

            $tagPos = (int)$m[0][1];
            $tag    = strtolower($m[0][0]);

            if ($tag === '<li') {
                $depth++;
                $scan = $tagPos + 3;
                continue;
            }

            // </li
            if ($tag === '</li') {
                $depth--;
                $closeEnd = strpos($html, '>', $tagPos);
                if ($closeEnd === false) {
                    $pos = $openEnd + 1;
                    break;
                }

                $scan = $closeEnd + 1;

                if ($depth <= 0) {
                    $block = substr($html, $start, ($closeEnd - $start + 1));
                    $out[] = $block;
                    $pos   = $closeEnd + 1;
                    break;
                }

                continue;
            }

            // safety
            $scan = $tagPos + 1;
        }
    }

    return $out;
}


function af_advancedmenu_apply_hide_patterns(string $ulInner, array $patterns): string
{
    $patterns = array_values(array_filter(array_map('trim', $patterns), static function($v) {
        return $v !== '';
    }));

    if (empty($patterns)) {
        return $ulInner;
    }

    $lis = af_advancedmenu_split_li($ulInner);
    if (empty($lis)) {
        // Если не смогли корректно выделить <li> — лучше не ломать разметку
        return $ulInner;
    }

    $kept = [];
    foreach ($lis as $li) {
        $remove = false;
        foreach ($patterns as $p) {
            if (stripos($li, $p) !== false) {
                $remove = true;
                break;
            }
        }
        if (!$remove) {
            $kept[] = $li;
        }
    }

    // Если реально всё удалили — так и возвращаем пусто (это ожидаемое поведение!)
    if (empty($kept)) {
        return '';
    }

    return implode("\n", $kept);
}


function af_advancedmenu_extract_protected_lis(string $ulInner, array $protectPatterns): string
{
    if (empty($protectPatterns)) {
        return '';
    }

    $lis = af_advancedmenu_split_li($ulInner);
    if (empty($lis)) {
        return '';
    }

    $out = [];
    foreach ($lis as $li) {
        foreach ($protectPatterns as $p) {
            if ($p === '') continue;
            if (stripos($li, $p) !== false) {
                $out[] = $li;
                break;
            }
        }
    }

    return implode("\n", $out);
}

function af_advancedmenu_inject_assets(string &$page): void
{
    global $mybb;

    if (strpos($page, AF_AM_ASSETS_MARK) !== false) {
        return;
    }
    if (empty($mybb->settings['af_advancedmenu_assets'])) {
        return;
    }

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');
    $css = $bburl.'/inc/plugins/advancedfunctionality/addons/advancedmenu/assets/advancedmenu.css?v=1';
    $js  = $bburl.'/inc/plugins/advancedfunctionality/addons/advancedmenu/assets/advancedmenu.js?v=1';

    $tags = "\n".AF_AM_ASSETS_MARK."\n"
        .'<link rel="stylesheet" href="'.$css.'" />'."\n"
        .'<script type="text/javascript" src="'.$js.'" defer="defer"></script>'."\n";

    // Вставка в head, если есть
    if (stripos($page, '</head>') !== false) {
        $page = preg_replace('~</head>~i', $tags.'</head>', $page, 1);
        return;
    }

    // Фолбэк: перед </body>
    if (stripos($page, '</body>') !== false) {
        $page = preg_replace('~</body>~i', $tags.'</body>', $page, 1);
    }
}

function af_advancedmenu_apply_to_ul(
    string &$page,
    string $ulClassNeedle,
    string $mode,
    string $newLisHtml,
    array $hidePatterns,
    array $protectPatterns = [],
    string $protectPos = 'end',
    array $stripImgPatterns = []
    ): void {
    if ($page === '' || $ulClassNeedle === '') {
        return;
    }

    $needle = trim($ulClassNeedle);
    if ($needle === '') {
        return;
    }

    $mode       = ($mode === 'replace') ? 'replace' : 'append';
    $protectPos = ($protectPos === 'start') ? 'start' : 'end';

    $hidePatterns = array_values(array_filter(array_map('trim', $hidePatterns), static function($v) {
        return $v !== '';
    }));
    $protectPatterns = array_values(array_filter(array_map('trim', $protectPatterns), static function($v) {
        return $v !== '';
    }));
    $stripImgPatterns = array_values(array_filter(array_map('trim', $stripImgPatterns), static function($v) {
        return $v !== '';
    }));

    // допускаем panel_links <-> panel-links
    $needles = [
        strtolower($needle),
        strtolower(str_replace('_', '-', $needle)),
        strtolower(str_replace('-', '_', $needle)),
    ];
    $needles = array_values(array_unique(array_filter($needles)));

    $html = (string)$page;
    $len  = strlen($html);

    $out = '';
    $pos = 0;

    while (true) {
        $ulStart = stripos($html, '<ul', $pos);
        if ($ulStart === false) {
            $out .= substr($html, $pos);
            break;
        }

        // текст до <ul
        $out .= substr($html, $pos, $ulStart - $pos);

        // конец открывающего <ul ...>
        $ulOpenEnd = strpos($html, '>', $ulStart);
        if ($ulOpenEnd === false) {
            $out .= substr($html, $ulStart);
            break;
        }

        $ulOpenTag = substr($html, $ulStart, ($ulOpenEnd - $ulStart + 1));

        // class=""
        $classStr = '';
        if (preg_match('~\bclass\s*=\s*(["\'])(.*?)\1~si', $ulOpenTag, $cm)) {
            $classStr = (string)$cm[2];
        }

        // id=""
        $idStr = '';
        if (preg_match('~\bid\s*=\s*(["\'])(.*?)\1~si', $ulOpenTag, $im)) {
            $idStr = (string)$im[2];
        }

        // Это наш UL?
        $isTarget = false;

        // 1) match по class: по токенам
        if ($classStr !== '') {
            $tokens = preg_split('~\s+~', trim($classStr));
            $tokens = array_values(array_filter(array_map('strtolower', (array)$tokens), static fn($v) => $v !== ''));
            foreach ($tokens as $tok) {
                if (in_array($tok, $needles, true)) {
                    $isTarget = true;
                    break;
                }
            }
        }

        // 2) match по id (важно для тем, где panel_links/user_links оформлены id-шником)
        if (!$isTarget && $idStr !== '') {
            $idLower = strtolower(trim($idStr));
            if (in_array($idLower, $needles, true)) {
                $isTarget = true;
            }
        }

        // найдём закрывающий </ul> с балансом вложенных ul
        $depth = 1;
        $scan  = $ulOpenEnd + 1;
        $ulCloseStart = null;
        $ulCloseEnd   = null;

        while ($scan < $len) {
            if (!preg_match('~</?ul\b~i', $html, $m, PREG_OFFSET_CAPTURE, $scan)) {
                break;
            }

            $tagPos = (int)$m[0][1];
            $tag    = strtolower($m[0][0]);

            if ($tag === '<ul') {
                $depth++;
                $scan = $tagPos + 3;
                continue;
            }

            if ($tag === '</ul') {
                $depth--;
                $closeEnd = strpos($html, '>', $tagPos);
                if ($closeEnd === false) {
                    break;
                }

                if ($depth <= 0) {
                    $ulCloseStart = $tagPos;
                    $ulCloseEnd   = $closeEnd;
                    break;
                }

                $scan = $closeEnd + 1;
                continue;
            }

            $scan = $tagPos + 1;
        }

        if ($ulCloseStart === null || $ulCloseEnd === null) {
            $out .= substr($html, $ulStart);
            break;
        }

        $ulInner = substr($html, $ulOpenEnd + 1, $ulCloseStart - ($ulOpenEnd + 1));
        $ulCloseTag = substr($html, $ulCloseStart, ($ulCloseEnd - $ulCloseStart + 1));

        if (!$isTarget) {
            $out .= $ulOpenTag.$ulInner.$ulCloseTag;
            $pos = $ulCloseEnd + 1;
            continue;
        }

        $origInner = $ulInner;

        // hide
        if (!empty($hidePatterns)) {
            $ulInner = af_advancedmenu_apply_hide_patterns($ulInner, $hidePatterns);
        }

        // strip img
        if (!empty($stripImgPatterns)) {
            $ulInner = af_advancedmenu_apply_strip_img_patterns($ulInner, $stripImgPatterns);
        }

        // protected — берём из оригинала
        $protectedHtml = '';
        if ($mode === 'replace' && !empty($protectPatterns)) {
            $protectedHtml = trim(af_advancedmenu_extract_protected_lis($origInner, $protectPatterns));
            if ($protectedHtml !== '' && !empty($stripImgPatterns)) {
                $protectedHtml = trim(af_advancedmenu_apply_strip_img_patterns($protectedHtml, $stripImgPatterns));
            }
        }

        $newLis = trim((string)$newLisHtml);

        if ($mode === 'append') {
            $finalInner = trim((string)$ulInner);
            if ($finalInner !== '' && $newLis !== '') {
                $finalInner .= "\n";
            }
            $finalInner .= $newLis;
        } else {
            // replace
            $finalInner = $newLis;

            if ($protectedHtml !== '') {
                if ($protectPos === 'start') {
                    $finalInner = $protectedHtml.($finalInner !== '' ? "\n".$finalInner : '');
                } else {
                    $finalInner = ($finalInner !== '' ? $finalInner."\n" : '').$protectedHtml;
                }
            }
        }

        $finalInner = trim($finalInner);

        // собираем обратно
        $out .= $ulOpenTag."\n".$finalInner."\n".$ulCloseTag;
        $pos = $ulCloseEnd + 1;
    }

    $page = $out;
}

/* =========================
   AF HOOKS
   ========================= */

/**
 * Вызывается ядром AF на global_start (лениво).
 */
function af_advancedmenu_init(): void
{
    global $mybb;

    af_advancedmenu_ensure_installed();
    af_advancedmenu_load_lang(false);

    if (empty($mybb->settings['af_advancedmenu_enabled'])) {
        return;
    }

    // Подготовим {$menu_slug} / {$panel_slug} / агрегаты
    af_advancedmenu_set_template_vars();
}

/**
 * Вызывается ядром AF на pre_output_page(&$page).
 */
function af_advancedmenu_pre_output(string &$page = ''): void
{
    global $mybb;

    if (strpos($page, AF_AM_APPLIED_MARK) !== false) {
        return;
    }
    if (!af_advancedmenu_is_frontend_context()) {
        return;
    }

    af_advancedmenu_ensure_installed();

    if (empty($mybb->settings['af_advancedmenu_enabled'])) {
        return;
    }

    af_advancedmenu_inject_assets($page);

    $topLis   = af_advancedmenu_build_menu_html('top_links');
    $panelLis = af_advancedmenu_build_menu_html('panel_links');

    $topMode   = isset($mybb->settings['af_advancedmenu_top_mode']) ? (string)$mybb->settings['af_advancedmenu_top_mode'] : 'append';
    $panelMode = isset($mybb->settings['af_advancedmenu_panel_mode']) ? (string)$mybb->settings['af_advancedmenu_panel_mode'] : 'append';

    $topHide   = isset($mybb->settings['af_advancedmenu_top_hide']) ? (string)$mybb->settings['af_advancedmenu_top_hide'] : '';
    $panelHide = isset($mybb->settings['af_advancedmenu_panel_hide']) ? (string)$mybb->settings['af_advancedmenu_panel_hide'] : '';
    $userHide  = isset($mybb->settings['af_advancedmenu_user_hide']) ? (string)$mybb->settings['af_advancedmenu_user_hide'] : '';

    $topHideArr   = af_advancedmenu_parse_csv($topHide);
    $panelHideArr = af_advancedmenu_parse_csv($panelHide);
    $userHideArr  = af_advancedmenu_parse_csv($userHide);

    $panelProtect = isset($mybb->settings['af_advancedmenu_panel_protect']) ? (string)$mybb->settings['af_advancedmenu_panel_protect'] : '';
    $panelProtectPos = isset($mybb->settings['af_advancedmenu_panel_protect_pos']) ? (string)$mybb->settings['af_advancedmenu_panel_protect_pos'] : 'end';
    $panelProtectArr = af_advancedmenu_parse_csv($panelProtect);

    $topProtect = isset($mybb->settings['af_advancedmenu_top_protect']) ? (string)$mybb->settings['af_advancedmenu_top_protect'] : '';
    $topProtectPos = isset($mybb->settings['af_advancedmenu_top_protect_pos']) ? (string)$mybb->settings['af_advancedmenu_top_protect_pos'] : 'end';
    $topProtectArr = af_advancedmenu_parse_csv($topProtect);

    $topStrip  = isset($mybb->settings['af_advancedmenu_top_strip_imgs']) ? (string)$mybb->settings['af_advancedmenu_top_strip_imgs'] : '';
    $panelStrip = isset($mybb->settings['af_advancedmenu_panel_strip_imgs']) ? (string)$mybb->settings['af_advancedmenu_panel_strip_imgs'] : '';

    $topStripArr   = af_advancedmenu_parse_csv($topStrip);
    $panelStripArr = af_advancedmenu_parse_csv($panelStrip);

    /**
     * AUTO-PROTECT системных ссылок в replace-режиме:
     * ModCP/AdminCP должны сохраняться, если они доступны пользователю.
     */
    $canAdminCp = !empty($mybb->usergroup['cancp']);
    $canModCp   = !empty($mybb->usergroup['canmodcp'])
        || !empty($mybb->usergroup['issupermod'])
        || (function_exists('is_moderator') && is_moderator());

    if ($canModCp) {
        $panelProtectArr[] = 'modcp.php';
        $topProtectArr[]   = 'modcp.php';
    }
    if ($canAdminCp) {
        $panelProtectArr[] = 'admin/index.php';
        $panelProtectArr[] = '/admin/';
        $topProtectArr[]   = 'admin/index.php';
        $topProtectArr[]   = '/admin/';
    }

    // дедуп
    $panelProtectArr = array_values(array_unique(array_filter(array_map('trim', $panelProtectArr), static fn($v) => $v !== '')));
    $topProtectArr   = array_values(array_unique(array_filter(array_map('trim', $topProtectArr), static fn($v) => $v !== '')));

    if (trim($topLis) !== '' || !empty($topHideArr) || ($topMode === 'replace' && !empty($topProtectArr)) || !empty($topStripArr)) {
        af_advancedmenu_apply_to_ul(
            $page,
            'top_links',
            $topMode,
            $topLis,
            $topHideArr,
            $topProtectArr,
            $topProtectPos,
            $topStripArr
        );
    }

    if (trim($panelLis) !== '' || !empty($panelHideArr) || ($panelMode === 'replace' && !empty($panelProtectArr)) || !empty($panelStripArr)) {
        af_advancedmenu_apply_to_ul(
            $page,
            'panel_links',
            $panelMode,
            $panelLis,
            $panelHideArr,
            $panelProtectArr,
            $panelProtectPos,
            $panelStripArr
        );
    }

    // user_links — только скрытие/strip
    if (!empty($userHideArr) || !empty($panelStripArr)) {
        af_advancedmenu_apply_to_ul(
            $page,
            'user_links',
            'append',
            '',
            $userHideArr,
            [],
            'end',
            $panelStripArr
        );
    }

    $extraTargets = isset($mybb->settings['af_advancedmenu_extra_targets']) ? (string)$mybb->settings['af_advancedmenu_extra_targets'] : '';
    $extraArr = af_advancedmenu_parse_csv($extraTargets);

    if (!empty($extraArr)) {
        foreach ($extraArr as $t) {
            $t = af_advancedmenu_resolve_target($t);
            if ($t === 'top_links' || $t === 'panel_links' || $t === 'user_links') {
                continue;
            }

            $lis = af_advancedmenu_build_menu_html($t);
            if (trim($lis) === '') {
                continue;
            }

            af_advancedmenu_apply_to_ul(
                $page,
                $t,
                'append',
                $lis,
                [],
                [],
                'end',
                []
            );
        }
    }

    $page .= "\n".AF_AM_APPLIED_MARK."\n";
}

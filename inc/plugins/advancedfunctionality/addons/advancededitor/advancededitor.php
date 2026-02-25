<?php
/**
 * AF Addon: AdvancedEditor
 * MyBB 1.8.39, PHP 8.4+
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_AE_ID', 'advancededitor');

define('AF_AE_GROUP', 'af_advancededitor');
define('AF_AE_SETTING_LAYOUT', 'af_advancededitor_toolbar_layout');
define('AF_AE_SETTING_FONTS',  'af_advancededitor_fontfamily_json');
define('AF_AE_SETTING_COUNTBBCODE',     'af_advancededitor_counter_count_bbcode');
define('AF_AE_SETTING_HIDE_POSTOPTIONS','af_advancededitor_hide_postoptions');
define('AF_AE_SETTING_POSTCOUNT_FORUMS', 'af_advancededitor_postcount_forum_ids');
define('AF_AE_SETTING_FORMFEATURE_FORUMS', 'af_advancededitor_formfeature_forum_ids');
define('AF_AE_SETTING_HTMLBB_ALLOWED_GROUPS', 'af_ae_htmlbb_allowed_groups');



// Таблица кастомных кнопок
define('AF_AE_TABLE', 'af_ae_buttons');

/**
 * === AE: helper paths/urls ===
 */
function af_advancededitor_base_rel(): string
{
    // web-relative base to addon folder (from forum root)
    return 'inc/plugins/advancedfunctionality/addons/advancededitor/';
}

function af_advancededitor_assets_rel(): string
{
    return af_advancededitor_base_rel() . 'assets/';
}

function af_advancededitor_bbcodes_rel(): string
{
    $base = af_advancededitor_assets_rel() . 'bbcodes/';

    static $resolved = null;
    if ($resolved === null) {
        $dir = af_advancededitor_bbcodes_dir();
        $primary = af_advancededitor_realpath_safe(MYBB_ROOT . $base);
        $resolved = (af_advancededitor_realpath_safe($dir) === $primary)
            ? $base
            : ($base . 'bbcodes/');
    }

    return $resolved;
}

function af_advancededitor_fs_bbcodes_dir(): string
{
    return af_advancededitor_bbcodes_dir();
}

function af_advancededitor_debug_enabled_for_admin(): bool
{
    global $mybb;

    if (defined('IN_ADMINCP') || defined('IN_MODCP')) return true;
    if (!isset($mybb) || !is_object($mybb)) return false;

    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0) return false;

    if (function_exists('is_super_admin')) {
        return (bool)is_super_admin($uid);
    }

    return !empty($mybb->usergroup['cancp']);
}

function af_advancededitor_debug_log(string $message, array $ctx = []): void
{
    if (!af_advancededitor_debug_enabled_for_admin()) return;

    $line = '[AF AE] ' . $message;
    if (!empty($ctx)) {
        $json = @json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json) && $json !== '') {
            $line .= ' ' . $json;
        }
    }

    @error_log($line);
}

function af_advancededitor_bbcodes_dir_has_anchor_or_modules(string $dir, array &$debug = []): bool
{
    $debug = ['anchors' => [], 'modules' => [], 'entries' => []];

    if (!is_dir($dir)) return false;

    $anchors = ['manifest.json', 'manifest.php', 'registry.json', 'bbcodes.json', 'bbcodes.php'];
    foreach ($anchors as $a) {
        if (is_file($dir . $a)) {
            $debug['anchors'][] = $a;
            return true;
        }
    }

    $entries = @scandir($dir);
    if (!is_array($entries)) return false;

    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        $debug['entries'][] = $e;
        $moduleDir = $dir . $e . '/';
        if (!is_dir($moduleDir)) continue;

        $moduleEntries = @scandir($moduleDir);
        if (!is_array($moduleEntries)) continue;

        $hasManifest = false;
        $hasAsset = false;
        foreach ($moduleEntries as $m) {
            if ($m === '.' || $m === '..') continue;
            if (preg_match('~^manifest\.(php|json)$~i', $m)) $hasManifest = true;
            if (preg_match('~\.(js|css)$~i', $m)) $hasAsset = true;
        }

        if ($hasManifest || $hasAsset) {
            $debug['modules'][] = $e;
            return true;
        }
    }

    return false;
}

function af_advancededitor_bbcodes_dir(): string
{
    static $resolved = null;
    if ($resolved !== null) return $resolved;

    $primary = MYBB_ROOT . af_advancededitor_assets_rel() . 'bbcodes/';
    $secondary = $primary . 'bbcodes/';

    $dbgPrimary = [];
    $dbgSecondary = [];

    $primaryValid = af_advancededitor_bbcodes_dir_has_anchor_or_modules($primary, $dbgPrimary);
    if ($primaryValid) {
        $resolved = $primary;
    } else {
        $secondaryValid = af_advancededitor_bbcodes_dir_has_anchor_or_modules($secondary, $dbgSecondary);
        if ($secondaryValid) {
            $resolved = $secondary;
        } else {
            $resolved = $primary;
            af_advancededitor_debug_log('bbcodes dir fallback to primary: no anchors/modules found', [
                'primary' => $primary,
                'secondary' => $secondary,
                'primary_scan' => $dbgPrimary,
                'secondary_scan' => $dbgSecondary,
            ]);
        }
    }

    return $resolved;
}

function af_advancededitor_bbcodes_url(): string
{
    return af_advancededitor_url(af_advancededitor_bbcodes_rel());
}

function af_advancededitor_url(string $rel): string
{
    global $mybb;
    $bburl = rtrim((string)$mybb->settings['bburl'], '/');
    $rel = ltrim($rel, '/');
    return $bburl . '/' . $rel;
}

function af_advancededitor_realpath_safe(string $path): string
{
    $rp = @realpath($path);
    return $rp ? $rp : $path;
}

function af_advancededitor_is_path_inside(string $path, string $baseDir): bool
{
    $path = af_advancededitor_realpath_safe($path);
    $baseDir = af_advancededitor_realpath_safe($baseDir);

    $path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    // case-insensitive on Windows, normal on Linux
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        return stripos($path, $baseDir) === 0;
    }
    return strpos($path, $baseDir) === 0;
}

/**
 * === AE: scan bbcodes packs ===
 *
 * Поддерживаем 2 формата манифеста:
 * 1) manifest.php (return [ 'js'=>[], 'css'=>[], 'buttons'=>[] ])
 * 2) manifest.json (то же самое)
 *
 * Можно класть:
 * - /bbcodes/<pack>/manifest.php
 * - /bbcodes/<pack>/manifest.json
 * - /bbcodes/manifest.php
 * - /bbcodes/manifest.json
 */
function af_advancededitor_collect_bbcode_packs(): array
{
    $baseFs = af_advancededitor_fs_bbcodes_dir();
    $baseRel = af_advancededitor_bbcodes_rel();

    $out = [
        'js' => [],
        'css' => [],
        'buttons' => [], // -> customDefs
    ];

    if (!@is_dir($baseFs)) {
        return $out;
    }

    $seen = [
        'js' => [],
        'css' => [],
        'cmd' => [],
    ];

    $addJs = function(string $rel) use (&$out, &$seen) {
        $rel = ltrim($rel, '/');
        if (isset($seen['js'][$rel])) return;
        $seen['js'][$rel] = true;
        $out['js'][] = af_advancededitor_url($rel);
    };

    $addCss = function(string $rel) use (&$out, &$seen) {
        $rel = ltrim($rel, '/');
        if (isset($seen['css'][$rel])) return;
        $seen['css'][$rel] = true;
        $out['css'][] = af_advancededitor_url($rel);
    };

    $addBtn = function(array $b) use (&$out, &$seen) {
        $cmd = isset($b['cmd']) ? (string)$b['cmd'] : '';
        $cmd = trim($cmd);

        if ($cmd === '' || $cmd === '|') return;

        // нормализуем: af_* lower
        $cmd = preg_replace('~^af_~i', 'af_', $cmd);
        $cmd = strtolower($cmd);

        if (isset($seen['cmd'][$cmd])) return;
        $seen['cmd'][$cmd] = true;

        $out['buttons'][] = [
            'cmd'      => $cmd,
            'title'    => isset($b['title']) ? (string)$b['title'] : $cmd,
            'hint'     => isset($b['hint']) ? (string)$b['hint'] : '',
            'icon'     => isset($b['icon']) ? (string)$b['icon'] : '',
            'opentag'  => isset($b['opentag']) ? (string)$b['opentag'] : '',
            'closetag' => isset($b['closetag']) ? (string)$b['closetag'] : '',
        ];
    };

    $readManifest = function(string $manifestFs, string $manifestRel) use ($baseFs, $addJs, $addCss, $addBtn): void {
        $manifestFs = af_advancededitor_realpath_safe($manifestFs);

        if (!@is_file($manifestFs)) return;
        if (!af_advancededitor_is_path_inside($manifestFs, $baseFs)) return;

        $data = null;

        if (preg_match('~\.php$~i', $manifestFs)) {
            // manifest.php должен возвращать массив
            $tmp = @include $manifestFs;
            if (is_array($tmp)) $data = $tmp;
        } else if (preg_match('~\.json$~i', $manifestFs)) {
            $raw = @file_get_contents($manifestFs);
            if ($raw !== false) {
                $tmp = @json_decode($raw, true);
                if (is_array($tmp)) $data = $tmp;
            }
        }

        if (!is_array($data)) return;

        // base rel path for this manifest folder
        $dirRel = preg_replace('~[^/]+$~', '', $manifestRel); // keep trailing slash

        if (!empty($data['css']) && is_array($data['css'])) {
            foreach ($data['css'] as $c) {
                $c = trim((string)$c);
                if ($c === '') continue;
                // относительные пути считаем относительно папки манифеста
                $rel = (strpos($c, '/') === 0 || preg_match('~^https?://~i', $c)) ? ltrim($c, '/') : $dirRel . ltrim($c, '/');
                $addCss($rel);
            }
        }

        if (!empty($data['js']) && is_array($data['js'])) {
            foreach ($data['js'] as $j) {
                $j = trim((string)$j);
                if ($j === '') continue;
                $rel = (strpos($j, '/') === 0 || preg_match('~^https?://~i', $j)) ? ltrim($j, '/') : $dirRel . ltrim($j, '/');
                $addJs($rel);
            }
        }

        if (!empty($data['buttons']) && is_array($data['buttons'])) {
            foreach ($data['buttons'] as $b) {
                if (is_array($b)) $addBtn($b);
            }
        }
    };

    // 1) корневые манифесты
    $rootPhp = $baseFs . 'manifest.php';
    $rootJson = $baseFs . 'manifest.json';
    if (@is_file($rootPhp))  $readManifest($rootPhp,  $baseRel . 'manifest.php');
    if (@is_file($rootJson)) $readManifest($rootJson, $baseRel . 'manifest.json');

    // 2) подпапки (pack dirs)
    $dh = @opendir($baseFs);
    if ($dh) {
        while (($e = readdir($dh)) !== false) {
            if ($e === '.' || $e === '..') continue;

            $p = $baseFs . $e;
            if (!@is_dir($p)) continue;

            $mPhp = $p . '/manifest.php';
            $mJson = $p . '/manifest.json';

            if (@is_file($mPhp))  $readManifest($mPhp,  $baseRel . $e . '/manifest.php');
            if (@is_file($mJson)) $readManifest($mJson, $baseRel . $e . '/manifest.json');
        }
        closedir($dh);
    }

    return $out;
}

/**
 * === AE: collect DB custom buttons (optional) ===
 */
function af_advancededitor_collect_db_buttons(): array
{
    global $db;

    $defs = [];

    if (!$db->table_exists(AF_AE_TABLE)) {
        return $defs;
    }

    $q = $db->simple_select(AF_AE_TABLE, '*', "active=1", ['order_by' => 'disporder', 'order_dir' => 'ASC']);
    while ($row = $db->fetch_array($q)) {
        $cmd = strtolower(preg_replace('~^af_~i', 'af_', (string)$row['name']));
        if ($cmd === '') continue;

        $defs[] = [
            'cmd'      => $cmd,
            'title'    => (string)$row['title'],
            'hint'     => '',
            'icon'     => (string)$row['icon'],
            'opentag'  => (string)$row['opentag'],
            'closetag' => (string)$row['closetag'],
        ];
    }

    return $defs;
}

/**
 * === AE: build payload (THIS is what makes buttons appear) ===
 */
function af_advancededitor_build_payload(): array
{
    // DB layout: sections/items
    $layoutJson = (string)af_advancededitor_cfg_get('layout_json', '');

    // Available commands: base + custom packs + custom buttons
    $available = af_advancededitor_get_available_buttons();

    // CustomDefs include pack buttons + DB custom buttons (same format)
    $customDefs = af_advancededitor_get_all_custom_defs();

    // Normalized assets base url (addon assets/)
    $assetsBaseUrl = af_advancededitor_url('inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/assets/');

    // Normalize icon urls for frontend:
    // - allow both "img/..." and "assets/img/..." (strip leading "assets/")
    // - convert relative => absolute url under /assets/
    $normalizeIcon = function(array $b) use ($assetsBaseUrl): array {
        $icon = isset($b['icon']) ? (string)$b['icon'] : '';
        $icon = trim($icon);

        if ($icon !== '') {
            // tolerate manifests that mistakenly write "assets/img/.."
            if (str_starts_with($icon, 'assets/')) {
                $icon = substr($icon, 7);
            }

            // if not absolute (http(s) or // or /), prefix assetsBaseUrl
            if (!preg_match('~^(https?:)?//|^/~i', $icon)) {
                $icon = $assetsBaseUrl . ltrim($icon, '/');
            }
        }

        $b['icon'] = $icon;
        return $b;
    };

    if (is_array($available)) {
        foreach ($available as $k => $b) {
            if (is_array($b)) {
                $available[$k] = $normalizeIcon($b);
            }
        }
    }

    if (is_array($customDefs)) {
        foreach ($customDefs as $k => $b) {
            if (is_array($b)) {
                $customDefs[$k] = $normalizeIcon($b);
            }
        }
    }

    // Post key for forms (to avoid "неверный ключ" при отправке)
    $postKey = isset($GLOBALS['mybb']->post_code) ? (string)$GLOBALS['mybb']->post_code : '';

    // Packs assets (css/js) that should be auto-loaded on frontend
    $packsAssets = af_advancededitor_collect_packs_assets();

    return [
        'enabled'     => (int)af_advancededitor_cfg_get('enabled', 1),
        'layoutJson'  => $layoutJson,
        'available'   => $available,
        'customDefs'  => $customDefs,
        'packsAssets' => $packsAssets,

        // IMPORTANT: used by JS for resolving icons/assets
        'assetsBaseUrl' => $assetsBaseUrl,

        // for my_post_key injection
        'postKey'     => $postKey,
    ];
}


/**
 * === AF hook: pre_output_page ===
 * Вставляет payload + грузит advancededitor.js
 */
function af_advancededitor_collect_font_families_for_payload(): array
{
    // 1) системные — как база (они пойдут в секцию "Системные")
    $system = [
        ['id' => 'arial',           'name' => 'Arial',           'system' => 1],
        ['id' => 'helvetica',       'name' => 'Helvetica',       'system' => 1],
        ['id' => 'verdana',         'name' => 'Verdana',         'system' => 1],
        ['id' => 'tahoma',          'name' => 'Tahoma',          'system' => 1],
        ['id' => 'trebuchet_ms',    'name' => 'Trebuchet MS',    'system' => 1],
        ['id' => 'georgia',         'name' => 'Georgia',         'system' => 1],
        ['id' => 'times_new_roman', 'name' => 'Times New Roman', 'system' => 1],
        ['id' => 'garamond',        'name' => 'Garamond',        'system' => 1],
        ['id' => 'courier_new',     'name' => 'Courier New',     'system' => 1],
    ];

    // 2) загруженные — сканируем assets/fonts рекурсивно
    $fontsDirAbs = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/assets/fonts/';
    if (!is_dir($fontsDirAbs)) {
        return $system;
    }

    $allowedExt = ['woff2', 'woff', 'ttf', 'otf'];

    $deriveFamily = function (string $filenameBase): string {
        $family = $filenameBase;
        if (preg_match('~^([^-_]+)[-_]~', $filenameBase, $m)) {
            $family = $m[1];
        }
        $family = trim($family);
        return $family !== '' ? $family : 'CustomFont';
    };

    $slug = function (string $s): string {
        $s = strtolower(trim($s));
        $s = preg_replace('~[^a-z0-9]+~i', '_', $s);
        $s = trim($s, '_');
        return $s !== '' ? $s : 'font';
    };

    $families = []; // familyName => ['id'=>..,'name'=>..,'system'=>0,'files'=>['woff2'=>..]]
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fontsDirAbs, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) continue;

        $abs = $file->getPathname();
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) continue;

        $rel = str_replace('\\', '/', substr($abs, strlen($fontsDirAbs)));
        $rel = ltrim($rel, '/');
        if ($rel === '' || strpos($rel, '..') !== false) continue;

        $base = pathinfo($abs, PATHINFO_FILENAME);
        $familyName = $deriveFamily($base);

        if (!isset($families[$familyName])) {
            $families[$familyName] = [
                'id'     => $slug($familyName),
                'name'   => $familyName,
                'system' => 0,
                'files'  => [],
            ];
        }

        // если у одной семьи несколько файлов одного типа — оставляем первый (дальше можно усложнять)
        if (empty($families[$familyName]['files'][$ext])) {
            $families[$familyName]['files'][$ext] = $rel;
        }
    }

    // нормализуем + сортировка (JS всё равно отсортит, но пусть будет чисто)
    $custom = array_values($families);
    usort($custom, function($a, $b) {
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });

    return array_merge($system, $custom);
}

function af_advancededitor_asset_ver(string $absPath): int
{
    return (is_file($absPath) ? (int)@filemtime($absPath) : 0);
}

function af_advancededitor_add_ver(string $url, int $ver): string
{
    if ($url === '' || $ver <= 0) return $url;
    return (strpos($url, '?') !== false) ? ($url . '&v=' . $ver) : ($url . '?v=' . $ver);
}

/**
 * === AE: local fonts CSS (generated file in /cache) ===
 */
function af_advancededitor_fonts_cache_rel(): string
{
    return 'cache/af_advancededitor_fonts.css';
}

function af_advancededitor_fonts_cache_url(): string
{
    // URL к cache-файлу
    $rel = (string)af_advancededitor_fonts_cache_rel();
    $rel = '/' . ltrim($rel, '/'); // нормализуем

    return af_advancededitor_url($rel);
}

function af_advancededitor_fonts_cache_abs(): string
{
    $rel = (string)af_advancededitor_fonts_cache_rel();
    $rel = ltrim($rel, '/'); // для FS лучше без ведущего /

    return rtrim(MYBB_ROOT, "/\\") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}

function af_advancededitor_fonts_css_safe_family(string $name): string
{
    $name = trim($name);
    // убираем управляющие и кавычки/слэши, чтобы не ломать CSS
    $name = preg_replace('~[\x00-\x1F\x7F]+~', '', $name);
    $name = str_replace(['"', "'", '\\'], '', $name);
    return $name;
}

function af_advancededitor_fonts_css_latest_mtime(): int
{
    $fontsDirAbs = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/assets/fonts/';
    if (!is_dir($fontsDirAbs)) return 0;

    $allowedExt = ['woff2', 'woff', 'ttf', 'otf'];

    $max = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fontsDirAbs, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) continue;

        $abs = $file->getPathname();
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) continue;

        $mt = (int)@filemtime($abs);
        if ($mt > $max) $max = $mt;
    }

    return $max;
}

function af_advancededitor_build_font_face_css(array $families): string
{
    // строим CSS @font-face для custom семейств (system=0) из payload-формата
    $assetsBase = af_advancededitor_url('inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/assets/');
    $css = "/* AF AdvancedEditor: local fonts */\n";

    foreach ($families as $f) {
        if (!is_array($f)) continue;
        if (!empty($f['system'])) continue;

        $name = isset($f['name']) ? af_advancededitor_fonts_css_safe_family((string)$f['name']) : '';
        if ($name === '') continue;

        $files = $f['files'] ?? null;
        if (!is_array($files) || empty($files)) continue;

        $src = [];

        $add = function(string $ext, string $fmt) use (&$src, $files, $assetsBase) {
            if (empty($files[$ext])) return;
            $file = trim((string)$files[$ext]);
            if ($file === '') return;

            // нормализуем относительный путь (внутри assets/fonts/)
            $file = str_replace(['\\', "\0"], ['/', ''], $file);
            $file = ltrim($file, '/');
            if ($file === '' || strpos($file, '..') !== false) return;

            $url = $assetsBase . 'fonts/' . str_replace('%2F', '/', rawurlencode($file));
            $src[] = 'url("' . str_replace('"', '\\"', $url) . '") format("' . $fmt . '")';
        };

        // приоритет woff2 -> woff -> ttf -> otf
        $add('woff2', 'woff2');
        $add('woff',  'woff');
        $add('ttf',   'truetype');
        $add('otf',   'opentype');

        if (!$src) continue;

        $css .= "@font-face{"
            . "font-family:'{$name}';"
            . "src:" . implode(',', $src) . ";"
            . "font-weight:400;"
            . "font-style:normal;"
            . "font-display:swap;"
            . "}\n";
    }

    return $css;
}

/**
 * Генерит /cache/af_advancededitor_fonts.css если он отсутствует или устарел.
 * Возвращает URL для <link>, либо '' если нечего/нельзя подключать.
 */
function af_advancededitor_ensure_local_fonts_css_file(): string
{
    $abs = af_advancededitor_fonts_cache_abs();

    // если cache/ не существует — пробуем создать (обычно есть, но мало ли)
    $cacheDir = dirname($abs);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }

    // на основе файлов шрифтов определяем “нужно ли пересобирать”
    $latestFontsMtime = af_advancededitor_fonts_css_latest_mtime();
    $fileExists = is_file($abs);
    $fileMtime  = $fileExists ? (int)@filemtime($abs) : 0;

    // если шрифтов вообще нет — не подключаем ничего
    if ($latestFontsMtime <= 0) {
        return '';
    }

    $needRebuild = (!$fileExists) || ($latestFontsMtime > $fileMtime);

    if ($needRebuild) {
        // берём ровно тот же список, что идёт в payload (system + custom),
        // но CSS строим только для custom (логика внутри build_font_face_css)
        $families = af_advancededitor_collect_font_families_for_payload();
        $css = af_advancededitor_build_font_face_css($families);

        // если вдруг CSS пустой — не пишем и не подключаем
        if (!is_string($css) || trim($css) === '') {
            return '';
        }

        // если cache недоступен для записи — НЕ ломаем фронт:
        // если уже есть старый файл, подключаем его; иначе — ничего
        if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
            if ($fileExists) {
                $ver = $fileMtime > 0 ? $fileMtime : $latestFontsMtime;
                return af_advancededitor_add_ver(af_advancededitor_fonts_cache_url(), $ver);
            }
            return '';
        }

        $tmp = $abs . '.tmp_' . uniqid('', true);
        $ok = (@file_put_contents($tmp, $css, LOCK_EX) !== false);

        if ($ok) {
            @chmod($tmp, 0644);

            // атомарная замена (на одной FS ок; если внезапно нет — будет fail)
            if (!@rename($tmp, $abs)) {
                @unlink($tmp);

                // если не удалось заменить, но старый файл есть — подключаем старый
                if ($fileExists) {
                    $ver = $fileMtime > 0 ? $fileMtime : $latestFontsMtime;
                    return af_advancededitor_add_ver(af_advancededitor_fonts_cache_url(), $ver);
                }
                return '';
            }
        } else {
            @unlink($tmp);

            // если не удалось записать, но старый файл есть — подключаем старый
            if ($fileExists) {
                $ver = $fileMtime > 0 ? $fileMtime : $latestFontsMtime;
                return af_advancededitor_add_ver(af_advancededitor_fonts_cache_url(), $ver);
            }
            return '';
        }
    }

    // версионируем по mtime самого cache-файла (или latestFontsMtime как fallback)
    $finalMtime = is_file($abs) ? (int)@filemtime($abs) : 0;
    $ver = $finalMtime > 0 ? $finalMtime : $latestFontsMtime;

    return af_advancededitor_add_ver(af_advancededitor_fonts_cache_url(), $ver);
}

function af_advancededitor_strip_own_assets(string &$page): void
{
    if ($page === '') return;

    // Убираем маркер (чтобы можно было безопасно переинжектить)
    $page = str_replace('<!--af_advancededitor-->', '', $page);

    // Убираем style с локальными шрифтами (старый режим)
    $page = preg_replace('~<style\b[^>]*\bid=("|\')af-ae-local-fonts\1[^>]*>.*?</style>\s*~is', '', $page);

    // Убираем link на локальные шрифты (новый режим через cache-файл)
    $page = preg_replace(
        '~<link\b[^>]*\bid=("|\')af-ae-local-fonts\1[^>]*\bhref=("|\')[^"\']*?/cache/af_advancededitor_fonts\.css(?:\?[^"\']*)?\2[^>]*>\s*~i',
        '',
        $page
    );

    // Убираем наши link/script из аддона (css/js)
    $page = preg_replace('~<link\b[^>]*\bhref=("|\')[^"\']*?/inc/plugins/advancedfunctionality/addons/advancededitor/assets/[^"\']+\.(?:css)(?:\?[^"\']*)?\1[^>]*>\s*~i', '', $page);
    $page = preg_replace('~<script\b[^>]*\bsrc=("|\')[^"\']*?/inc/plugins/advancedfunctionality/addons/advancededitor/assets/[^"\']+\.(?:js)(?:\?[^"\']*)?\1[^>]*>\s*</script>\s*~i', '', $page);

    // На всякий случай: если когда-то грузили advancededitor.(js|css) не из ожидаемой папки
    $page = preg_replace('~<script\b[^>]*\bsrc=("|\')[^"\']*advancededitor\.js(?:\?[^"\']*)?\1[^>]*>\s*</script>\s*~i', '', $page);
    $page = preg_replace('~<link\b[^>]*\bhref=("|\')[^"\']*advancededitor\.css(?:\?[^"\']*)?\1[^>]*>\s*~i', '', $page);
}

function af_advancededitor_asset_key_from_url(string $url): string
{
    $url = html_entity_decode(trim($url), ENT_QUOTES, 'UTF-8');
    if ($url === '') return '';

    $parts = @parse_url($url);
    if (is_array($parts) && isset($parts['path'])) {
        $url = (string)$parts['path'];
    }

    $url = str_replace('\\', '/', $url);
    $url = preg_replace('~/{2,}~', '/', $url);
    $url = strtolower((string)$url);

    return $url;
}

function af_advancededitor_dedupe_runtime_assets(string &$page): void
{
    if ($page === '') return;

    $patterns = [
        '~<script\b[^>]*\bsrc=("|\')([^"\']*?/inc/plugins/advancedfunctionality/addons/advancededitor/[^"\']*)\1[^>]*>\s*</script>\s*~is',
        '~<link\b[^>]*\bhref=("|\')([^"\']*?/inc/plugins/advancedfunctionality/addons/advancededitor/[^"\']*)\1[^>]*>\s*~is',
    ];

    foreach ($patterns as $pattern) {
        if (!preg_match_all($pattern, $page, $all, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        $matches = [];
        for ($i = 0, $cnt = count($all[0]); $i < $cnt; $i++) {
            $tag = $all[0][$i][0] ?? '';
            $offset = (int)($all[0][$i][1] ?? -1);
            $url = $all[2][$i][0] ?? '';
            if ($tag === '' || $offset < 0 || $url === '') continue;

            $key = af_advancededitor_asset_key_from_url($url);
            if ($key === '') continue;

            $matches[] = [
                'tag' => $tag,
                'offset' => $offset,
                'length' => strlen($tag),
                'key' => $key,
            ];
        }

        if (count($matches) <= 1) continue;

        $seen = [];
        $remove = [];
        for ($i = count($matches) - 1; $i >= 0; $i--) {
            $key = $matches[$i]['key'];
            if (isset($seen[$key])) {
                $remove[] = $matches[$i];
                continue;
            }
            $seen[$key] = true;
        }

        if (empty($remove)) continue;

        usort($remove, function(array $a, array $b): int {
            return $b['offset'] <=> $a['offset'];
        });

        foreach ($remove as $chunk) {
            $page = substr_replace($page, '', $chunk['offset'], $chunk['length']);
        }
    }
}

function af_advancededitor_resolve_sceditor_content_css_url(string $bburl): string
{
    $bburl = rtrim($bburl, '/');

    $candidates = [
        '/jscripts/sceditor/styles/jquery.sceditor.mybb.css',
        '/jscripts/sceditor/styles/jquery.sceditor.default.css',
        '/jscripts/sceditor/styles/jquery.sceditor.default.min.css',
    ];

    foreach ($candidates as $rel) {
        $fs = MYBB_ROOT . ltrim($rel, '/');
        if (is_file($fs)) {
            return $bburl . $rel;
        }
    }

    // fallback (пусть будет хоть что-то)
    return $bburl . '/jscripts/sceditor/styles/jquery.sceditor.mybb.css';
}

function af_advancededitor_resolve_sceditor_theme_css_url(string $bburl): string
{
    $bburl = rtrim($bburl, '/');

    $candidates = [
        '/jscripts/sceditor/themes/default.min.css',
        '/jscripts/sceditor/themes/default.css',
        '/jscripts/sceditor/themes/modern.min.css',
        '/jscripts/sceditor/themes/modern.css',
    ];

    foreach ($candidates as $rel) {
        $fs = MYBB_ROOT . ltrim($rel, '/');
        if (is_file($fs)) {
            return $bburl . $rel;
        }
    }

    return $bburl . '/jscripts/sceditor/themes/default.min.css';
}

function af_advancededitor_is_editor_whitelist_request(): bool
{
    global $mybb;

    $script = defined('THIS_SCRIPT') ? strtolower((string)THIS_SCRIPT) : '';
    if ($script === '' || $script === 'index.php') return false;

    if (in_array($script, ['newthread.php', 'newreply.php', 'editpost.php', 'showthread.php'], true)) {
        return true;
    }

    if ($script === 'private.php') {
        $action = strtolower((string)($mybb->input['action'] ?? ''));
        $pmid = (int)($mybb->input['pmid'] ?? 0);
        return ($action === 'read' && $pmid > 0);
    }

    if ($script === 'misc.php') {
        $action = strtolower((string)($mybb->input['action'] ?? ''));
        return in_array($action, ['kb', 'af_charactersheet'], true);
    }

    return false;
}

function af_advancededitor_page_has_editor_dom(string $page): bool
{
    return (
        (bool)preg_match('~<textarea[^>]+(?:name|id)\s*=\s*["\']message["\']~i', $page) ||
        (stripos($page, 'class="sceditor-container') !== false) ||
        (stripos($page, "class='sceditor-container") !== false)
    );
}

function af_advancededitor_filter_packs_for_request(array $packs, bool $loadEditorPacks): array
{
    if (empty($packs['packs']) || !is_array($packs['packs'])) {
        return $packs;
    }

    $alwaysPackIds = ['fontfamily' => true];
    $allowedIds = $alwaysPackIds;

    if ($loadEditorPacks) {
        foreach ($packs['packs'] as $packId => $_pack) {
            $allowedIds[(string)$packId] = true;
        }
    }

    $filteredPacks = [];
    $filteredButtons = [];
    $filteredCss = [];
    $filteredJs = [];
    $filteredParsers = [];

    foreach ($packs['packs'] as $packId => $pack) {
        $packId = (string)$packId;
        if (!isset($allowedIds[$packId])) continue;

        $filteredPacks[$packId] = $pack;

        if (!empty($pack['buttons']) && is_array($pack['buttons'])) {
            foreach ($pack['buttons'] as $btn) $filteredButtons[] = $btn;
        }
        if (!empty($pack['assets']['css']) && is_array($pack['assets']['css'])) {
            foreach ($pack['assets']['css'] as $u) $filteredCss[] = (string)$u;
        }
        if (!empty($pack['assets']['js']) && is_array($pack['assets']['js'])) {
            foreach ($pack['assets']['js'] as $u) $filteredJs[] = (string)$u;
        }
        if (!empty($pack['parser_abs']) && is_string($pack['parser_abs'])) {
            $filteredParsers[] = $pack['parser_abs'];
        }
    }

    $packs['packs'] = $filteredPacks;
    $packs['buttons'] = $filteredButtons;
    $packs['css'] = array_values(array_unique(array_filter($filteredCss, 'is_string')));
    $packs['js'] = array_values(array_unique(array_filter($filteredJs, 'is_string')));
    $packs['parsers'] = array_values(array_unique(array_filter($filteredParsers, 'is_string')));

    return $packs;
}

function af_advancededitor_pre_output(string &$page = ''): void
{
    global $mybb;

    $enabledKey = 'af_' . AF_AE_ID . '_enabled';
    if (empty($mybb->settings[$enabledKey])) return;

    if ($page === '' || stripos($page, '<html') === false) return;
    if (defined('IN_ADMINCP') || defined('IN_MODCP')) return;

    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    if ($bburl === '') return;

    // ---- определяем режим ----
    $isEditorScript = af_advancededitor_is_editor_whitelist_request();
    $hasTextarea = (stripos($page, '<textarea') !== false);
    $hasEditorDom = af_advancededitor_page_has_editor_dom($page);
    $shouldLoadEditorPacks = $isEditorScript;

    // Пакеты BB-кнопок/стилей (включая copycode)
    $packs = af_advancededitor_discover_bbcode_packs($bburl);
    $packs = af_advancededitor_filter_packs_for_request($packs, $shouldLoadEditorPacks);

    af_advancededitor_debug_log('pre_output bbcodes runtime', [
        'script' => defined('THIS_SCRIPT') ? (string)THIS_SCRIPT : '',
        'bbcodes_dir' => af_advancededitor_bbcodes_dir(),
        'bbcodes_base_url' => af_advancededitor_bbcodes_url(),
        'modules_count' => (int)count($packs['packs'] ?? []),
        'modules_sample' => array_slice(array_keys($packs['packs'] ?? []), 0, 5),
    ]);

    // Если не нужен редакторный набор и нет глобальных паков — выходим.
    if (!$hasTextarea && empty($packs['css']) && empty($packs['js'])) {
        return;
    }

    // ---- чистим возможный старый мусор (чтобы можно было переинжектить) ----
    af_advancededitor_strip_own_assets($page);

    // В режиме редактора: гасим MyBB clickable editor + вычищаем SCEditor ассеты MyBB
    // (в VIEW режиме это не трогаем вообще)
    if ($hasTextarea) {
        af_advancededitor_force_disable_mybb_clickable_editor();
        af_advancededitor_strip_mybb_sceditor_assets($page);
    }

    $assetsBase = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/assets/';
    $imgBase    = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/img/';

    // Версии файлов (cache busting)
    $aeCssAbs = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/assets/advancededitor.css';
    $aeJsAbs  = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/assets/advancededitor.js';
    $verCss   = af_advancededitor_asset_ver($aeCssAbs);
    $verJs    = af_advancededitor_asset_ver($aeJsAbs);
    $buildVer = max($verCss, $verJs, 1);

    $injectHead  = "\n<!--af_advancededitor-->\n";

    /**
     * ===== ВСЕГДА (контентные страницы + страницы с textarea) =====
     * ВАЖНО: именно тут грузим copycode.js/css для гостей.
     */

    // базовый CSS аддона (общие правила/переменные/иконки тулбара и т.п.)
    $injectHead .= '<link rel="stylesheet" href="' . htmlspecialchars_uni(af_advancededitor_add_ver($assetsBase . 'advancededitor.css', $buildVer)) . '" />' . "\n";

    // локальные @font-face — лучше отдельным файлом, а не inline style
    $fontsCssUrl = af_advancededitor_ensure_local_fonts_css_file(); // должна вернуть абсолютный URL или '' 
    if ($fontsCssUrl !== '') {
        $injectHead .= '<link rel="stylesheet" id="af-ae-local-fonts" href="' 
            . htmlspecialchars_uni(af_advancededitor_add_ver($fontsCssUrl, $buildVer)) 
            . "\" />\n";
    } else {
        // fallback: если не хочешь inline вообще — просто ничего.
        // если хочешь оставить запасной вариант — можешь вернуть старый build_fonts_css тут.
        /*
        $fontsCss = af_advancededitor_build_fonts_css($bburl);
        if ($fontsCss !== '') {
            $injectHead .= "<style id=\"af-ae-local-fonts\">\n" . $fontsCss . "\n</style>\n";
        }
        */
    }


    // CSS паков (table/float/copycode/…)
    if (!empty($packs['css']) && is_array($packs['css'])) {
        foreach ($packs['css'] as $u) {
            $u = (string)$u;
            if ($u === '') continue;
            $injectHead .= '<link rel="stylesheet" href="' . htmlspecialchars_uni(af_advancededitor_add_ver($u, $buildVer)) . '" />' . "\n";
        }
    }

    $packJsTags = '';
    if (!empty($packs['js']) && is_array($packs['js'])) {
        foreach ($packs['js'] as $u) {
            $u = (string)$u;
            if ($u === '') continue;
            $packJsTags .= '<script defer="defer" src="' . htmlspecialchars_uni(af_advancededitor_add_ver($u, $buildVer)) . '"></script>' . "\n";
        }
    }

    if (!$hasTextarea && $packJsTags !== '') {
        $injectHead .= $packJsTags;
    }

    /**
     * ===== ТОЛЬКО ЕСЛИ ЕСТЬ TEXTAREA (редакторный режим) =====
     */
    if ($hasTextarea) {

        // Ограничения по форумам (как было)
        $postcountCsv   = af_advancededitor_expand_forum_csv((string)af_advancededitor_load_setting_value_from_db(AF_AE_SETTING_POSTCOUNT_FORUMS));
        $formfeatureCsv = af_advancededitor_expand_forum_csv((string)af_advancededitor_load_setting_value_from_db(AF_AE_SETTING_FORMFEATURE_FORUMS));

        $injectHead .= '<script>'
            . 'window.afAePayload=window.afAePayload||{};'
            . 'window.afAePayload.cfg=window.afAePayload.cfg||{};'
            . 'window.afAePayload.cfg.bburl=' . json_encode($bburl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';'
            . 'window.afAePayload.cfg.postcountForumIds=' . json_encode($postcountCsv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';'
            . 'window.afAePayload.cfg.formFeatureForumIds=' . json_encode($formfeatureCsv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';'
            . '</script>' . "\n";

        // SCEditor: theme css (для тулбара)
        $sceditorThemeCss = af_advancededitor_resolve_sceditor_theme_css_url($bburl);
        $injectHead .= '<link rel="stylesheet" href="' . htmlspecialchars_uni(af_advancededitor_add_ver($sceditorThemeCss, $buildVer)) . '" />' . "\n";

        // SCEditor: content css (для iframe WYSIWYG)
        $sceditorContentCss = af_advancededitor_resolve_sceditor_content_css_url($bburl);

        // SCEditor core + bbcode plugin
        $core = af_advancededitor_url_if_file_exists($bburl, 'jscripts/sceditor/jquery.sceditor.min.js');
        if ($core === '') $core = af_advancededitor_url_if_file_exists($bburl, 'jscripts/sceditor/jquery.sceditor.js');

        $bb = af_advancededitor_url_if_file_exists($bburl, 'jscripts/sceditor/jquery.sceditor.bbcode.min.js');
        if ($bb === '') $bb = af_advancededitor_url_if_file_exists($bburl, 'jscripts/sceditor/jquery.sceditor.bbcode.js');

        if ($core !== '') $injectHead .= '<script src="' . htmlspecialchars_uni(af_advancededitor_add_ver($core, $buildVer)) . '"></script>' . "\n";
        if ($bb !== '')   $injectHead .= '<script src="' . htmlspecialchars_uni(af_advancededitor_add_ver($bb, $buildVer)) . '"></script>' . "\n";

        // MyBB bridge (submit-sync)
        $mybbBridge = af_advancededitor_resolve_sceditor_mybb_js_url($bburl);
        if ($mybbBridge !== '') {
            $injectHead .= '<script src="' . htmlspecialchars_uni(af_advancededitor_add_ver($mybbBridge, $buildVer)) . '"></script>' . "\n";
        }

        // layout/fonts/settings
        $customDefs = af_advancededitor_get_custom_button_defs($bburl);
        $available  = af_advancededitor_get_available_buttons($bburl, $customDefs);

        $layoutRaw = af_advancededitor_load_setting_value_from_db(AF_AE_SETTING_LAYOUT);
        $layout = null;
        if (trim($layoutRaw) !== '') {
            $decoded = json_decode($layoutRaw, true);
            if (is_array($decoded)) $layout = $decoded;
        }

        $fontsRaw = af_advancededitor_load_setting_value_from_db(AF_AE_SETTING_FONTS);
        $fonts = null;
        if (trim($fontsRaw) !== '') {
            $decoded = json_decode($fontsRaw, true);
            if (is_array($decoded)) $fonts = $decoded;
        }

        $countBbcodeRaw = af_advancededitor_load_setting_value_from_db(AF_AE_SETTING_COUNTBBCODE);
        $countBbcode = ((int)trim((string)$countBbcodeRaw) === 1) ? 1 : 0;

        $hideOptsRaw = af_advancededitor_load_setting_value_from_db(AF_AE_SETTING_HIDE_POSTOPTIONS);
        $hidePostOptions = ((int)trim((string)$hideOptsRaw) === 1) ? 1 : 0;

        if ($hidePostOptions) {
            $injectHead .= "<style id=\"af-ae-hide-postoptions\">
#post_options, #postoptions, .post_options, .postoptions,
fieldset.post_options, fieldset.postoptions,
.postoptions-container, .post-options, .postOptions,
table #post_options, table #postoptions{display:none!important;}
</style>\n";
        }

        $fontFamilies = af_advancededitor_collect_font_families_for_payload();
        $postKey = (string)($mybb->post_code ?? '');

        $editorSelector = '';
        if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'misc.php') {
            $action = (string)($mybb->input['action'] ?? '');
            if (in_array($action, ['kb_edit', 'kb_type_edit'], true)) {
                $editorSelector = 'textarea.af-kb-editor';
            }
        }

        $payload = [
            'v'                => 4,
            'assetVer'          => $buildVer,

            'bburl'             => $bburl,
            'assetsBase'        => $assetsBase,
            'imgBase'           => $imgBase,
            'bbcodesBaseUrl'    => af_advancededitor_bbcodes_url(),

            'sceditorContentCss'=> $sceditorContentCss,
            'sceditorThemeCss'  => $sceditorThemeCss,
            'sceditorCss'       => $sceditorContentCss,

            'available'         => $available,
            'layout'            => $layout,
            'fonts'             => $fonts,
            'packs'             => $packs,
            'bbcodesModulesDetected' => (int)count($packs['packs'] ?? []),
            'customDefs'        => $customDefs,

            'previewUrl'        => $bburl . '/misc.php?action=af_ae_postpreview',
            'postKey'           => $postKey,

            'countBbcode'       => $countBbcode,
            'hidePostOptions'   => $hidePostOptions,
            'cfg' => [
                'bburl' => $bburl,
                'fontFamilies' => $fontFamilies,
                'postcountForumIds'   => $postcountCsv,
                'formFeatureForumIds' => $formfeatureCsv,
            ],
        ];
        if ($editorSelector !== '') {
            $payload['cfg']['editorSelector'] = $editorSelector;
        }

        if (af_advancededitor_debug_enabled_for_admin()) {
            $payload['debug'] = [
                'enabled' => 1,
                'bbcodesDir' => af_advancededitor_bbcodes_dir(),
                'bbcodesBaseUrl' => af_advancededitor_bbcodes_url(),
                'modulesCount' => (int)count($packs['packs'] ?? []),
                'modulesSample' => array_slice(array_keys($packs['packs'] ?? []), 0, 5),
            ];
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') $json = '{}';

        $injectHead .= '<script>window.afAdvancedEditorPayload=' . $json . ';</script>' . "\n";
        $injectHead .= '<script>window.afAePayload=window.afAdvancedEditorPayload;</script>' . "\n";

        // advancededitor.js
        $injectHead .= '<script defer="defer" src="' . htmlspecialchars_uni(af_advancededitor_add_ver($assetsBase . 'advancededitor.js', $buildVer)) . '"></script>' . "\n";

        // JS паков грузим ПОСЛЕ ядра, чтобы AFAE гарантированно был объявлен раньше модулей.
        if ($packJsTags !== '') {
            $injectHead .= $packJsTags;
        }
    }

    // ---- вставляем в </head> ----
    if (stripos($page, '</head>') !== false) {
        $page = preg_replace('~</head>~i', $injectHead . '</head>', $page, 1);
    } else {
        $page = $injectHead . $page;
    }

    af_advancededitor_dedupe_runtime_assets($page);
}

/**
 * === AE: install/uninstall pack MyCodes ===
 * Конвенция:
 * - pack folder: assets/bbcodes/<pack>/
 * - manifest.php returns ['id'=>...]
 * - optional installer file: <pack>/<pack>.php (например indent/indent.php)
 * - functions:
 *     af_ae_<id>_install_mycode()
 *     af_ae_<id>_uninstall_mycode()
 */
function af_advancededitor_install_pack_mycodes(): void
{
    $base = rtrim(af_advancededitor_bbcodes_dir(), '/');
    if (!is_dir($base)) return;

    $dirs = @scandir($base);
    if (!is_array($dirs)) return;

    foreach ($dirs as $d) {
        if ($d === '.' || $d === '..') continue;

        $packDir = $base . '/' . $d;
        if (!is_dir($packDir)) continue;

        $mf = $packDir . '/manifest.php';
        if (!is_file($mf)) continue;

        $m = @include $mf;
        if (!is_array($m)) continue;

        $id = trim((string)($m['id'] ?? ''));
        if ($id === '') continue;

        // sanitize id for function names
        $idFn = preg_replace('~[^a-z0-9_]+~i', '_', strtolower($id));

        // include installer file if exists: <id>/<id>.php
        $installer = $packDir . '/' . $idFn . '.php';
        if (is_file($installer)) {
            require_once $installer;
        }

        $fn = 'af_ae_' . $idFn . '_install_mycode';
        if (function_exists($fn)) {
            $fn();
        }
    }
}

function af_advancededitor_uninstall_pack_mycodes(): void
{
    $base = rtrim(af_advancededitor_bbcodes_dir(), '/');
    if (!is_dir($base)) return;

    $dirs = @scandir($base);
    if (!is_array($dirs)) return;

    foreach ($dirs as $d) {
        if ($d === '.' || $d === '..') continue;

        $packDir = $base . '/' . $d;
        if (!is_dir($packDir)) continue;

        $mf = $packDir . '/manifest.php';
        if (!is_file($mf)) continue;

        $m = @include $mf;
        if (!is_array($m)) continue;

        $id = trim((string)($m['id'] ?? ''));
        if ($id === '') continue;

        $idFn = preg_replace('~[^a-z0-9_]+~i', '_', strtolower($id));

        $installer = $packDir . '/' . $idFn . '.php';
        if (is_file($installer)) {
            require_once $installer;
        }

        $fn = 'af_ae_' . $idFn . '_uninstall_mycode';
        if (function_exists($fn)) {
            $fn();
        }
    }
}


function af_advancededitor_install(): void
{
    global $db;

    // 1) таблица кастомных кнопок
    if (!$db->table_exists(AF_AE_TABLE)) {
        $collation = $db->build_create_table_collation();
        $db->write_query("
            CREATE TABLE " . TABLE_PREFIX . AF_AE_TABLE . " (
                bid INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(64) NOT NULL,
                title VARCHAR(255) NOT NULL,
                icon VARCHAR(255) NOT NULL DEFAULT '',
                opentag VARCHAR(255) NOT NULL,
                closetag VARCHAR(255) NOT NULL DEFAULT '',
                active TINYINT(1) NOT NULL DEFAULT 1,
                disporder INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (bid),
                UNIQUE KEY name (name),
                KEY active (active),
                KEY disporder (disporder)
            ) ENGINE=MyISAM {$collation};
        ");
    }

    // 2) группа настроек + настройки
    $q = $db->simple_select('settinggroups', 'gid', "name='" . $db->escape_string(AF_AE_GROUP) . "'", ['limit' => 1]);
    $gid = (int)$db->fetch_field($q, 'gid');

    if ($gid <= 0) {
        $gid = (int)$db->insert_query('settinggroups', [
            'name'        => AF_AE_GROUP,
            'title'       => 'Advanced Editor',
            'description' => 'Настройка тулбара, шрифтов и расширений редактора.',
            'disporder'   => 1,
            'isdefault'   => 0,
        ]);
    } else {
        $db->update_query('settinggroups', [
            'title'       => $db->escape_string('Advanced Editor'),
            'description' => $db->escape_string('Настройка тулбара, шрифтов и расширений редактора.'),
        ], "gid='{$gid}'");
    }

    // helper: ensure setting
    $ensure = function(string $name, string $title, string $desc, string $optionscode, string $value, int $disporder) use ($db, $gid): void {
        $nameEsc = $db->escape_string($name);
        $q = $db->simple_select('settings', 'sid', "name='{$nameEsc}'", ['limit' => 1]);
        $sid = (int)$db->fetch_field($q, 'sid');

        if ($sid > 0) {
            $db->update_query('settings', [
                'title'       => $db->escape_string($title),
                'description' => $db->escape_string($desc),
                'optionscode' => $db->escape_string($optionscode),
                'disporder'   => $disporder,
                'gid'         => $gid,
            ], "sid='{$sid}'");
            return;
        }

        $db->insert_query('settings', [
            'name'        => $db->escape_string($name),
            'title'       => $db->escape_string($title),
            'description' => $db->escape_string($desc),
            'optionscode' => $db->escape_string($optionscode),
            'value'       => $db->escape_string($value),
            'disporder'   => $disporder,
            'gid'         => $gid,
        ]);
    };

    // layout
    $ensure(
        AF_AE_SETTING_LAYOUT,
        'Toolbar layout JSON',
        'JSON раскладка секций/дропдаунов для тулбара.',
        'textarea',
        '',
        10
    );

    // fonts json
    $ensure(
        AF_AE_SETTING_FONTS,
        'Font families JSON',
        'JSON список font-family (системные и загруженные).',
        'textarea',
        '{"v":1,"families":[]}',
        20
    );

    // counter behavior
    $ensure(
        AF_AE_SETTING_COUNTBBCODE,
        'Счётчик символов: считать BBCode',
        'Если Да — считаем вместе с тегами [b], [url=...] и т.п. Если Нет — теги не учитываются.',
        'yesno',
        '0',
        30
    );

    // hide post options block
    $ensure(
        AF_AE_SETTING_HIDE_POSTOPTIONS,
        'Скрыть блок опций поста',
        'Скрывает левый блок чекбоксов: подпись, запретить смайлы и т.д. (newreply/newthread/editpost/showthread/PM).',
        'yesno',
        '1',
        40
    );

    // === НОВОЕ: ограничения по форумам ===
    // формат: "2,3,10" (пусто = везде)
    $ensure(
        AF_AE_SETTING_POSTCOUNT_FORUMS,
        'Счётчик “Символов в посте”: forum ids',
        'Список fid через запятую, где показывать “Символов в посте” под опубликованными постами. Пусто = везде. Пример: 2,3,10',
        'text',
        '',
        50
    );

    $ensure(
        AF_AE_SETTING_FORMFEATURE_FORUMS,
        'Счётчик/предпросмотр над формой: forum ids',
        'Список fid через запятую, где включать бар “Символов” и предпросмотр над формой ответа. Пусто = везде. Пример: 2,3',
        'text',
        '',
        60
    );

    // === HTMLBB: кто может ИСПОЛЬЗОВАТЬ тег [html] ===
    $ensure(
        AF_AE_SETTING_HTMLBB_ALLOWED_GROUPS,
        'HTMLBB: группы, которым доступен тег [html]',
        'ID групп через запятую, которым разрешено использовать тег [html] (и кнопку в тулбаре). Просмотр HTMLBB доступен всем.',
        'text',
        '3,4,6',
        70
    );


    if (!function_exists('rebuild_settings')) {
        require_once MYBB_ROOT . 'inc/functions.php';
    }
    rebuild_settings();

    // ставим MyCode из паков (включая indent/indent.php)
    af_advancededitor_install_pack_mycodes();


    // OPcache дружелюбие
    $settingsFile = MYBB_ROOT . 'inc/settings.php';
    @clearstatcache(true, $settingsFile);
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($settingsFile, true);
    }
}


function af_advancededitor_uninstall(): void
{
    global $db;

    // сначала убираем MyCode паков (только если пак даёт uninstall-функцию)
    af_advancededitor_uninstall_pack_mycodes();



    // Таблицу удаляем (это именно наши данные)
    if ($db->table_exists(AF_AE_TABLE)) {
        $db->drop_table(AF_AE_TABLE);
    }

    // настройки + группа
    $db->delete_query('settings', "name='" . $db->escape_string(AF_AE_SETTING_LAYOUT) . "'");
    $db->delete_query('settings', "name='" . $db->escape_string(AF_AE_SETTING_FONTS) . "'");
    $db->delete_query('settings', "name='" . $db->escape_string(AF_AE_SETTING_COUNTBBCODE) . "'");
    $db->delete_query('settings', "name='" . $db->escape_string(AF_AE_SETTING_HIDE_POSTOPTIONS) . "'");

    // НОВОЕ
    $db->delete_query('settings', "name='" . $db->escape_string(AF_AE_SETTING_POSTCOUNT_FORUMS) . "'");
    $db->delete_query('settings', "name='" . $db->escape_string(AF_AE_SETTING_FORMFEATURE_FORUMS) . "'");
    $db->delete_query('settings', "name='" . $db->escape_string(AF_AE_SETTING_HTMLBB_ALLOWED_GROUPS) . "'");


    $db->delete_query('settinggroups', "name='" . $db->escape_string(AF_AE_GROUP) . "'");

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    } else {
        require_once MYBB_ROOT . 'inc/functions.php';
        rebuild_settings();
    }

    $settingsFile = MYBB_ROOT . 'inc/settings.php';
    @clearstatcache(true, $settingsFile);
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($settingsFile, true);
    }
}

function af_ae_bbcode_dispatch_init(): void
{
    global $plugins;

    // ВАЖНО:
    // - parse_message_start: даём пакам шанс преобразовать BBCode ДО стандартного парсинга MyBB
    // - parse_message_end: даём пакам шанс превратить результат в нужный HTML ПОСЛЕ парсинга
    $plugins->add_hook('parse_message_start', 'af_ae_bbcode_dispatch_parse_message_start', 10);
    $plugins->add_hook('parse_message_end',   'af_ae_bbcode_dispatch_parse_message_end',   10);
}


/**
 * Вызывается MyBB после стандартного парсинга (BBCode + nl2br).
 * Здесь мы прогоняем кастомные теги паков AE (table и др.)
 */
function af_ae_bbcode_dispatch_parse_message_end(&$message): void
{
    if (!is_string($message) || $message === '') {
        return;
    }

    // быстрый skip
    if (strpos($message, '[') === false && stripos($message, '<blockquote') === false) {
        return;
    }

    static $packs = null;
    if ($packs === null) {
        $packs = [];

        $base = rtrim(af_advancededitor_bbcodes_dir(), '/');
        if (is_dir($base)) {
            $dirs = @scandir($base);
            if (is_array($dirs)) {
                foreach ($dirs as $d) {
                    if ($d === '.' || $d === '..') continue;
                    $packDir = $base . '/' . $d;
                    if (!is_dir($packDir)) continue;

                    $mf = $packDir . '/manifest.php';
                    if (!is_file($mf)) continue;

                    $manifest = @include $mf;
                    if (!is_array($manifest) || empty($manifest['id'])) continue;

                    $id = (string)$manifest['id'];
                    $tags = [];
                    if (!empty($manifest['tags']) && is_array($manifest['tags'])) {
                        foreach ($manifest['tags'] as $t) {
                            $t = trim((string)$t);
                            if ($t !== '') $tags[] = $t;
                        }
                    }

                    $parserRel = !empty($manifest['parser']) ? (string)$manifest['parser'] : '';
                    $parserPath = $parserRel ? ($packDir . '/' . $parserRel) : '';

                    $packs[] = [
                        'id'     => $id,
                        'tags'   => $tags,
                        'parser' => $parserPath,
                    ];
                }
            }
        }
    }

    if (empty($packs)) {
        return;
    }

    foreach ($packs as $p) {
        if (empty($p['tags'])) continue;

        // триггер по тегам пакета (для end тоже)
        $hit = false;
        foreach ($p['tags'] as $tag) {
            if (stripos($message, '[' . $tag) !== false) { $hit = true; break; }
            if (stripos($message, ']' . $tag) !== false) { $hit = true; break; }
        }
        // Для spoiler: после parse_start тегов [spoiler] уже нет — он превращается в [quote] с маркером,
        // поэтому end должен сработать даже без [spoiler] в тексте.
        if (!$hit && $p['id'] !== 'spoiler') continue;

        if (!empty($p['parser']) && is_file($p['parser'])) {
            require_once $p['parser'];
        }

        // ПРИОРИТЕТ: parse_end
        $fnEnd = 'af_ae_bbcode_' . $p['id'] . '_parse_end';
        if (function_exists($fnEnd)) {
            $fnEnd($message);
            continue;
        }

        // ФОЛЛБЭК: старый формат одного вызова ..._parse (для паков, где так сделано)
        $fn = 'af_ae_bbcode_' . $p['id'] . '_parse';
        if (function_exists($fn)) {
            $fn($message);
        }
    }
}


function af_ae_bbcode_dispatch_parse_message_start(&$message): void
{
    if (!is_string($message) || $message === '') {
        return;
    }

    // быстрый skip
    if (strpos($message, '[') === false) {
        return;
    }

    static $packs = null;
    if ($packs === null) {
        $packs = [];

        $base = rtrim(af_advancededitor_bbcodes_dir(), '/');
        if (is_dir($base)) {
            $dirs = @scandir($base);
            if (is_array($dirs)) {
                foreach ($dirs as $d) {
                    if ($d === '.' || $d === '..') continue;
                    $packDir = $base . '/' . $d;
                    if (!is_dir($packDir)) continue;

                    $mf = $packDir . '/manifest.php';
                    if (!is_file($mf)) continue;

                    $manifest = @include $mf;
                    if (!is_array($manifest) || empty($manifest['id'])) continue;

                    $id = (string)$manifest['id'];
                    $tags = [];
                    if (!empty($manifest['tags']) && is_array($manifest['tags'])) {
                        foreach ($manifest['tags'] as $t) {
                            $t = trim((string)$t);
                            if ($t !== '') $tags[] = $t;
                        }
                    }

                    $parserRel = !empty($manifest['parser']) ? (string)$manifest['parser'] : '';
                    $parserPath = $parserRel ? ($packDir . '/' . $parserRel) : '';

                    $packs[] = [
                        'id'     => $id,
                        'tags'   => $tags,
                        'parser' => $parserPath,
                    ];
                }
            }
        }
    }

    if (empty($packs)) {
        return;
    }

    foreach ($packs as $p) {
        if (empty($p['tags'])) continue;

        // триггер по тегам пакета
        $hit = false;
        foreach ($p['tags'] as $tag) {
            if (stripos($message, '[' . $tag) !== false) {
                $hit = true;
                break;
            }
        }
        if (!$hit) continue;

        // подключаем parser.php
        if (!empty($p['parser']) && is_file($p['parser'])) {
            require_once $p['parser'];
        }

        // ПРИОРИТЕТ: parse_start
        $fnStart = 'af_ae_bbcode_' . $p['id'] . '_parse_start';
        if (function_exists($fnStart)) {
            $fnStart($message);
        }
    }
}


/**
 * AF hook: init (можно оставить no-op)
 */
function af_advancededitor_force_disable_mybb_clickable_editor(): void
{
    global $mybb, $db, $cache;

    // 1) РАНТАЙМ: всегда гасим базовые вставлялки MyBB
    foreach (['bbcodeeditor', 'bbcodeinserter', 'smilieinserter'] as $k) {
        if (isset($mybb->settings[$k])) {
            $mybb->settings[$k] = 0;
        }
    }

    // 2) БД: фиксируем выключение (только супер-админ, не чаще раза/сутки)
    $uid = (int)($mybb->user['uid'] ?? 0);
    $isSa = false;

    if ($uid > 0) {
        if (function_exists('is_super_admin')) {
            $isSa = (bool)is_super_admin($uid);
        } else {
            $isSa = ($uid === 1);
        }
    }

    if (!$isSa) {
        return;
    }

    $ck = 'af_advancededitor_force_disable_editor_ts';
    $now = defined('TIME_NOW') ? (int)TIME_NOW : time();
    $lastTs = 0;

    if (isset($cache) && is_object($cache) && method_exists($cache, 'read') && method_exists($cache, 'update')) {
        $last = $cache->read($ck);
        $lastTs = is_array($last) && !empty($last['ts']) ? (int)$last['ts'] : 0;
    }

    if ($lastTs > 0 && ($now - $lastTs) < 86400) {
        return;
    }

    try {
        if (isset($db) && is_object($db)) {
            $needRebuild = false;

            foreach (['bbcodeeditor', 'bbcodeinserter', 'smilieinserter'] as $name) {
                $q = $db->simple_select('settings', 'value', "name='".$db->escape_string($name)."'", ['limit' => 1]);
                $val = $db->fetch_field($q, 'value');
                $val = is_string($val) ? trim($val) : '';

                if ($val !== '' && (int)$val === 1) {
                    $db->update_query('settings', ['value' => '0'], "name='".$db->escape_string($name)."'");
                    $needRebuild = true;
                }
            }

            if ($needRebuild) {
                if (!function_exists('rebuild_settings')) {
                    require_once MYBB_ROOT . 'inc/functions.php';
                }
                rebuild_settings();

                $settingsFile = MYBB_ROOT . 'inc/settings.php';
                @clearstatcache(true, $settingsFile);
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($settingsFile, true);
                }
            }
        }
    } catch (Throwable $e) {
        // не ломаем страницу
    }

    if (isset($cache) && is_object($cache) && method_exists($cache, 'update')) {
        $cache->update($ck, ['ts' => $now]);
    }
}


function af_advancededitor_strip_mybb_sceditor_assets(string &$page): void
{
    if ($page === '') return;

    // Если MyBB успел вставить свои ассеты SCEditor (при включённом bbcodeeditor) — вычищаем,
    // иначе будет двойная инициализация и “пляски” с режимами.
    // Удаляем только типовые ссылки на jscripts/sceditor/ и mybb sceditor bridge/styles.
    $patterns = [
        // <script ... jscripts/sceditor/...></script>
        '~<script\b[^>]*\bsrc=("|\')[^"\']*?/jscripts/sceditor/[^"\']*\1[^>]*>\s*</script>\s*~i',
        // <link ... jscripts/sceditor/...>
        '~<link\b[^>]*\bhref=("|\')[^"\']*?/jscripts/sceditor/[^"\']*\1[^>]*>\s*~i',
    ];

    foreach ($patterns as $p) {
        $page = preg_replace($p, '', $page);
    }
}

function af_advancededitor_init(): void
{
    global $plugins, $mybb, $cache;

    if (defined('IN_ADMINCP') || defined('IN_MODCP')) {
        return;
    }

    $enabledKey = 'af_' . AF_AE_ID . '_enabled';
    if (empty($mybb->settings[$enabledKey])) {
        return;
    }

    // 1) ГЛАВНОЕ: выключаем Clickable MyCode Editor MyBB (и в рантайме, и при необходимости фиксируем в БД).
    af_advancededitor_force_disable_mybb_clickable_editor();

    // 2) Разовый авто-ensure MyCode паков (чтобы после добавления indent всё появилось без reinstall)
    // делаем только для супер-админа и не чаще раза в сутки
    $uid = (int)($mybb->user['uid'] ?? 0);
    $isSa = false;
    if ($uid > 0) {
        if (function_exists('is_super_admin')) {
            $isSa = (bool)is_super_admin($uid);
        } else {
            $isSa = ($uid === 1);
        }
    }

    if ($isSa && isset($cache) && method_exists($cache, 'read') && method_exists($cache, 'update')) {
        $ck = 'af_advancededitor_pack_mycodes_ts';
        $last = $cache->read($ck);
        $lastTs = is_array($last) && !empty($last['ts']) ? (int)$last['ts'] : 0;

        $now = defined('TIME_NOW') ? (int)TIME_NOW : time();
        if ($lastTs <= 0 || ($now - $lastTs) > 86400) {
            af_advancededitor_install_pack_mycodes();
            $cache->update($ck, ['ts' => $now]);
        }
    }

    af_ae_bbcode_dispatch_init();

    // Быстрый предпросмотр через misc.php?action=af_ae_postpreview
    $plugins->add_hook('misc_start', 'af_advancededitor_misc_start');
}



function af_advancededitor_misc_start(): void
{
    global $mybb;

    $action = (string)($mybb->input['action'] ?? '');

    // поддержим и старое имя на всякий случай (если где-то осталось)
    if ($action !== 'af_ae_postpreview' && $action !== 'af_aqr_postpreview') {
        return;
    }

    @header('Content-Type: text/html; charset=UTF-8');
    @header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    @header('Pragma: no-cache');

    if (!function_exists('verify_post_check')) {
        require_once MYBB_ROOT . 'inc/functions.php';
    }

    $postKey = (string)($mybb->input['my_post_key'] ?? '');

    // “вежливая” защита post_key (не ломаем гостям страницу, но и не отдаём превью абы кому)
    if (!empty($mybb->user['uid'])) {
        $ok = true;
        try {
            $ok = (bool)verify_post_check($postKey, true);
        } catch (Throwable $e) {
            $ok = false;
        }
        if (!$ok) {
            echo '<div class="af-ae-previewempty">Ошибка: неверный my_post_key.</div>';
            exit;
        }
    } else {
        if ($postKey === '') {
            echo '<div class="af-ae-previewempty">Ошибка: предпросмотр доступен только авторизованным.</div>';
            exit;
        }
    }

    $raw = trim((string)($mybb->input['message'] ?? ''));
    if ($raw === '') {
        echo '<div class="af-ae-previewempty">Пусто. Напиши что-нибудь 🙂</div>';
        exit;
    }

    if (!class_exists('postParser')) {
        require_once MYBB_ROOT . 'inc/class_parser.php';
    }

    // (опционально) ограничения по форуму через tid
    $allowMyCode  = 1;
    $allowSmilies = 1;
    $allowImg     = 1;
    $allowVideo   = 1;

    $tid = isset($mybb->input['tid']) ? (int)$mybb->input['tid'] : 0;
    if ($tid > 0 && function_exists('get_thread') && function_exists('get_forum')) {
        $thread = get_thread($tid);
        if (!empty($thread['fid'])) {
            $forum = get_forum((int)$thread['fid']);
            if (is_array($forum) && !empty($forum)) {
                $allowMyCode  = !empty($forum['allowmycode']) ? 1 : 0;
                $allowSmilies = !empty($forum['allowsmilies']) ? 1 : 0;
                $allowImg     = !empty($forum['allowimgcode']) ? 1 : 0;
                $allowVideo   = !empty($forum['allowvideocode']) ? 1 : 0;
            }
        }
    }

    $parser = new postParser();

    $options = [
        'allow_html'      => 0, // безопасность как в старой версии
        'allow_mycode'    => $allowMyCode,
        'allow_smilies'   => $allowSmilies,
        'allow_imgcode'   => $allowImg,
        'allow_videocode' => $allowVideo,
        'filter_badwords' => 1,
    ];

    $html = $parser->parse_message($raw, $options);

    echo '<div class="af-ae-previewparsed">' . $html . '</div>';
    exit;
}


/**
 * AF hook: pre_output — сюда вставляем CSS/JS + payload, если на странице есть textarea/редактор.
 */
function af_advancededitor_build_fonts_css(string $bburl): string
{
    $bburl = rtrim($bburl, '/');

    $fontsDirAbs = MYBB_ROOT . 'inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/assets/fonts/';
    $fontsBaseUrl = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/assets/fonts/';

    if (!is_dir($fontsDirAbs)) {
        return '';
    }

    // собираем файлы шрифтов рекурсивно (1 уровень подпапок тоже ок)
    $files = [];

    $scan = function (string $dirAbs, string $prefixRel) use (&$files, &$scan) {
        $list = @scandir($dirAbs);
        if (!is_array($list)) return;

        foreach ($list as $x) {
            if ($x === '.' || $x === '..') continue;

            $abs = $dirAbs . $x;
            $rel = $prefixRel . $x;

            if (is_dir($abs)) {
                $scan(rtrim($abs, '/') . '/', rtrim($rel, '/') . '/');
                continue;
            }

            $ext = strtolower(pathinfo($x, PATHINFO_EXTENSION));
            if (!in_array($ext, ['woff2','woff','ttf','otf'], true)) continue;

            $files[] = ['abs' => $abs, 'rel' => $rel, 'ext' => $ext];
        }
    };

    $scan($fontsDirAbs, '');

    if (!$files) return '';

    // группируем по family (имя берём из имени файла до первого '-' или '_', иначе весь basename)
    $byFamily = [];
    foreach ($files as $f) {
        $base = pathinfo($f['rel'], PATHINFO_FILENAME);
        $family = $base;

        if (preg_match('~^([^-_]+)[-_]~', $base, $m)) {
            $family = $m[1];
        }

        $family = trim(str_replace(['__','--'], ['_','-'], $family));
        if ($family === '') $family = 'CustomFont';

        $meta = af_advancededitor_guess_font_meta($base);

        $byFamily[$family][] = [
            'url'   => $fontsBaseUrl . str_replace('%2F', '/', rawurlencode($f['rel'])),
            'ext'   => $f['ext'],
            'weight'=> $meta['weight'],
            'style' => $meta['style'],
        ];
    }

    // генерим @font-face
    $css = "/* AF AdvancedEditor: local fonts */\n";
    foreach ($byFamily as $family => $variants) {
        foreach ($variants as $v) {
            $format = 'woff2';
            if ($v['ext'] === 'woff') $format = 'woff';
            elseif ($v['ext'] === 'ttf') $format = 'truetype';
            elseif ($v['ext'] === 'otf') $format = 'opentype';

            $css .= "@font-face{font-family:'" . addslashes($family) . "';src:url('" . addslashes($v['url']) . "') format('" . $format . "');font-weight:" . (int)$v['weight'] . ";font-style:" . ($v['style'] === 'italic' ? 'italic' : 'normal') . ";font-display:swap;}\n";
        }
    }

    return $css;
}

function af_advancededitor_guess_font_meta(string $filenameBase): array
{
    $s = strtolower($filenameBase);

    $style = (strpos($s, 'italic') !== false || strpos($s, 'it') !== false) ? 'italic' : 'normal';

    $weight = 400;
    if (strpos($s, 'thin') !== false) $weight = 100;
    elseif (strpos($s, 'extralight') !== false || strpos($s, 'ultralight') !== false) $weight = 200;
    elseif (strpos($s, 'light') !== false) $weight = 300;
    elseif (strpos($s, 'regular') !== false || strpos($s, 'book') !== false) $weight = 400;
    elseif (strpos($s, 'medium') !== false) $weight = 500;
    elseif (strpos($s, 'semibold') !== false || strpos($s, 'demibold') !== false) $weight = 600;
    elseif (strpos($s, 'bold') !== false) $weight = 700;
    elseif (strpos($s, 'extrabold') !== false || strpos($s, 'ultrabold') !== false) $weight = 800;
    elseif (strpos($s, 'black') !== false || strpos($s, 'heavy') !== false) $weight = 900;

    // если в имени явно указан weight числом типа 500/700
    if (preg_match('~\b([1-9]00)\b~', $s, $m)) {
        $w = (int)$m[1];
        if ($w >= 100 && $w <= 900) $weight = $w;
    }

    return ['weight' => $weight, 'style' => $style];
}

/**
 * ===== Helpers =====
 */
function af_advancededitor_url_if_file_exists(string $bburl, string $rel): string
{
    $bburl = rtrim($bburl, '/');
    $rel = '/' . ltrim($rel, '/');

    $abs = MYBB_ROOT . ltrim($rel, '/');
    if (is_file($abs)) {
        return $bburl . $rel;
    }
    return '';
}

function af_advancededitor_load_setting_value_from_db(string $name): string
{
    global $db, $mybb;

    $nameEsc = $db->escape_string($name);
    $q = $db->simple_select('settings', 'value', "name='{$nameEsc}'", ['limit' => 1]);
    $raw = $db->fetch_field($q, 'value');

    if (is_string($raw)) return $raw;
    return (string)($mybb->settings[$name] ?? '');
}


/**
 * Parse CSV of forum ids ("2,3,10") into unique int[].
 */
function af_advancededitor_parse_forum_id_csv(string $csv): array
{
    $csv = trim($csv);
    if ($csv === '') return [];

    $out = [];
    foreach (explode(',', $csv) as $part) {
        $n = (int)trim($part);
        if ($n > 0) $out[$n] = true;
    }
    return array_keys($out);
}

/**
 * Expand forum ids with all child forums (supports categories too) using MyBB forum cache.
 * Returns unique sorted int[].
 */
function af_advancededitor_expand_forum_ids_with_children(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids), function($n){ return $n > 0; }));
    if (empty($ids)) return [];

    $want = array_fill_keys($ids, true);

    global $cache;
    $forums = null;

    if (isset($cache) && is_object($cache) && method_exists($cache, 'read')) {
        $forums = $cache->read('forums');
    }

    // если кеш почему-то пуст — попробуем обновить
    if (!is_array($forums) || empty($forums)) {
        if (function_exists('cache_forums')) {
            @cache_forums();
            if (isset($cache) && is_object($cache) && method_exists($cache, 'read')) {
                $forums = $cache->read('forums');
            }
        }
    }

    if (!is_array($forums) || empty($forums)) {
        // фоллбек: без кеша не умеем развернуть — вернём как есть
        sort($ids);
        return $ids;
    }

    $out = $want; // include originals

    foreach ($forums as $fid => $f) {
        $fid = (int)$fid;
        if ($fid <= 0) continue;

        // parentlist обычно есть в кешированном форуме: "1,2,10"
        $parentlist = '';
        if (is_array($f) && isset($f['parentlist'])) {
            $parentlist = (string)$f['parentlist'];
        }

        // если нет parentlist — пробуем по pid (редко, но пусть будет)
        if ($parentlist === '' && is_array($f) && isset($f['pid'])) {
            $pid = (int)$f['pid'];
            $chain = [$fid];
            $guard = 0;

            while ($pid > 0 && $guard++ < 50) {
                $chain[] = $pid;
                if (!isset($forums[$pid]) || !is_array($forums[$pid])) break;
                $pid = (int)($forums[$pid]['pid'] ?? 0);
            }
            $parentlist = implode(',', array_reverse($chain));
        }

        if ($parentlist === '') continue;

        // Проверяем: содержит ли parentlist любой из заданных ids
        $pl = ',' . $parentlist . ',';
        foreach ($ids as $x) {
            if (strpos($pl, ',' . $x . ',') !== false) {
                $out[$fid] = true;
                break;
            }
        }
    }

    $result = array_keys($out);
    sort($result);
    return $result;
}

/**
 * Expand CSV forum ids with children; returns CSV string.
 * Empty input => empty output (meaning "everywhere").
 */
function af_advancededitor_expand_forum_csv(string $csv): string
{
    $csv = trim($csv);
    if ($csv === '') return '';

    $ids = af_advancededitor_parse_forum_id_csv($csv);
    $expanded = af_advancededitor_expand_forum_ids_with_children($ids);
    return implode(',', $expanded);
}

function af_advancededitor_resolve_sceditor_css_url(string $bburl): string
{
    $bburl = rtrim($bburl, '/');

    $candidates = [
        '/jscripts/sceditor/themes/default.min.css',
        '/jscripts/sceditor/themes/default.css',
        '/jscripts/sceditor/themes/modern.min.css',
        '/jscripts/sceditor/themes/modern.css',
    ];

    foreach ($candidates as $rel) {
        $fs = MYBB_ROOT . ltrim($rel, '/');
        if (file_exists($fs)) {
            return $bburl . $rel;
        }
    }

    return $bburl . '/jscripts/sceditor/themes/default.min.css';
}

function af_advancededitor_resolve_sceditor_mybb_js_url(string $bburl): string
{
    $bburl = rtrim($bburl, '/');

    $candidates = [
        'jscripts/sceditor/jquery.sceditor.mybb.min.js',
        'jscripts/sceditor/jquery.sceditor.mybb.js',
    ];

    foreach ($candidates as $rel) {
        $url = af_advancededitor_url_if_file_exists($bburl, $rel);
        if ($url !== '') return $url;
    }
    return '';
}


function af_advancededitor_get_custom_button_defs(string $bburl): array
{
    global $db;

    $bburl = rtrim($bburl, '/');

    $defs = [];

    if (!$db->table_exists(AF_AE_TABLE)) {
        return $defs;
    }

    $q = $db->simple_select(AF_AE_TABLE, '*', "active=1", ['order_by' => 'disporder ASC, name ASC']);
    while ($r = $db->fetch_array($q)) {
        $name = trim((string)($r['name'] ?? ''));
        if ($name === '') continue;

        $cmd = (stripos($name, 'af_') === 0) ? $name : ('af_' . $name);
        $cmd = preg_replace('~\s+~', '', $cmd);

        $title = trim((string)($r['title'] ?? $cmd));
        if ($title === '') $title = $cmd;

        $icon = trim((string)($r['icon'] ?? ''));
        if ($icon === '') {
            $icon = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/img/af.svg';
        }

        $open = (string)($r['opentag'] ?? '');
        $close = (string)($r['closetag'] ?? '');

        // минимальная защита от пустых тегов
        if (trim($open) === '' && trim($close) === '') {
            continue;
        }

        $defs[] = [
            'cmd'      => $cmd,
            'title'    => $title,
            'icon'     => $icon,
            'opentag'  => $open,
            'closetag' => $close,
        ];
    }

    return $defs;
}


/**
 * Скан BB-паков
 */
function af_advancededitor_discover_bbcode_packs(string $bburl): array
{
    $bburl = rtrim($bburl, '/');

    // ВАЖНО: пакеты лежат в assets/bbcodes/*
    $baseDirAbs = af_advancededitor_bbcodes_dir();
    $assetsBaseUrl = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AE_ID . '/assets/';
    $bbcodesBaseUrl = af_advancededitor_bbcodes_url();

    $mkAssetUrl = function(string $rel) use ($assetsBaseUrl, $bbcodesBaseUrl): string {
        $rel = ltrim(trim($rel), '/');
        if ($rel === '') return '';

        if (stripos($rel, 'bbcodes/') === 0) {
            return rtrim($bbcodesBaseUrl, '/') . '/' . ltrim(substr($rel, 8), '/');
        }

        return rtrim($assetsBaseUrl, '/') . '/' . $rel;
    };

    $out = [
        // агрегаты для фронта/ACP
        'buttons' => [], // flattened list of buttons
        'js'      => [],
        'css'     => [],
        'parsers' => [], // absolute fs paths
        // детальная карта паков (полезно для дебага/будущего)
        'packs'   => [], // id => ['id','title','tags','buttons','assets','parser_abs','manifest_path']
    ];

    if (!is_dir($baseDirAbs)) {
        return $out;
    }

    $dirs = @scandir($baseDirAbs);
    if (!is_array($dirs)) {
        return $out;
    }

    foreach ($dirs as $d) {
        if ($d === '.' || $d === '..') continue;

        $packDir = $baseDirAbs . $d . '/';
        if (!is_dir($packDir)) continue;

        $manifestFile = $packDir . 'manifest.php';
        if (!is_file($manifestFile)) continue;

        $m = @include $manifestFile;
        if (!is_array($m)) continue;

        $packId = trim((string)($m['id'] ?? ''));
        if ($packId === '') continue;

        $packTitle = trim((string)($m['title'] ?? $packId));
        $tags = [];
        if (!empty($m['tags']) && is_array($m['tags'])) {
            foreach ($m['tags'] as $t) {
                $t = trim((string)$t);
                if ($t !== '') $tags[] = $t;
            }
        }

        // assets from manifest: paths are RELATIVE TO assets/
        $packCss = [];
        $packJs  = [];
        if (!empty($m['assets']['css']) && is_array($m['assets']['css'])) {
            foreach ($m['assets']['css'] as $rel) {
                $rel = trim((string)$rel);
                if ($rel === '') continue;
                $url = $mkAssetUrl($rel);
                if ($url !== '') $packCss[] = $url;
            }
        }
        if (!empty($m['assets']['js']) && is_array($m['assets']['js'])) {
            foreach ($m['assets']['js'] as $rel) {
                $rel = trim((string)$rel);
                if ($rel === '') continue;
                $url = $mkAssetUrl($rel);
                if ($url !== '') $packJs[] = $url;
            }
        }

        // fallback/канон для модулей: <module>/<module>.js и <module>/<module>.css
        // (чтобы CSS-пайплайн был 1:1 с JS и не зависел только от manifest assets)
        $moduleBase = $packDir . $d;
        $moduleJsAbs = $moduleBase . '.js';
        $moduleCssAbs = $moduleBase . '.css';

        $moduleJsUrl = '';
        if (is_file($moduleJsAbs)) {
            $moduleJsUrl = rtrim($bbcodesBaseUrl, '/') . '/' . rawurlencode($d) . '/' . rawurlencode($d) . '.js';
            $packJs[] = $moduleJsUrl;
        }

        $moduleCssUrl = '';
        if (is_file($moduleCssAbs)) {
            $moduleCssUrl = rtrim($bbcodesBaseUrl, '/') . '/' . rawurlencode($d) . '/' . rawurlencode($d) . '.css';
            $packCss[] = $moduleCssUrl;
        }

        $packCss = array_values(array_unique(array_filter($packCss, 'is_string')));
        $packJs  = array_values(array_unique(array_filter($packJs, 'is_string')));

        // parser path in manifest: RELATIVE TO pack folder
        $parserAbs = '';
        if (!empty($m['parser'])) {
            $parserRel = trim((string)$m['parser']);
            if ($parserRel !== '') {
                $cand = $packDir . ltrim($parserRel, '/');
                if (is_file($cand)) {
                    $parserAbs = $cand;
                    $out['parsers'][] = $parserAbs;
                }
            }
        }

        // buttons from manifest: отдаём и плоско, и в pack-map
        $packButtons = [];
        if (!empty($m['buttons']) && is_array($m['buttons'])) {
            foreach ($m['buttons'] as $b) {
                if (!is_array($b)) continue;

                $cmd = trim((string)($b['cmd'] ?? ''));
                if ($cmd === '') continue;

                $title = trim((string)($b['title'] ?? $b['name'] ?? $cmd));
                $name  = trim((string)($b['name'] ?? ''));
                $handler = trim((string)($b['handler'] ?? ''));

                $icon = trim((string)($b['icon'] ?? ''));
                if ($icon !== '') {
                    // icon in your packs is RELATIVE TO assets/
                    $icon = $mkAssetUrl($icon);
                }

                $btn = [
                    'cmd'     => $cmd,
                    'name'    => $name,
                    'title'   => ($title !== '' ? $title : $cmd),
                    'icon'    => $icon,
                    'handler' => $handler,

                    // meta
                    'packId'    => $packId,
                    'packTitle' => $packTitle,
                ];

                $packButtons[] = $btn;
                $out['buttons'][] = $btn;
            }
        }

        // merge assets
        foreach ($packCss as $u) $out['css'][] = $u;
        foreach ($packJs as $u)  $out['js'][]  = $u;

        $out['packs'][$packId] = [
            'id'            => $packId,
            'title'         => $packTitle,
            'tags'          => $tags,
            'buttons'       => $packButtons,
            'module_dir'    => $d,
            'has_js'        => ($moduleJsUrl !== ''),
            'has_css'       => ($moduleCssUrl !== ''),
            'js_url'        => $moduleJsUrl,
            'css_url'       => $moduleCssUrl,
            'assets'        => ['css' => $packCss, 'js' => $packJs],
            'parser_abs'    => $parserAbs,
            'manifest_path' => $manifestFile,
        ];
    }

    // дедуп ассетов, чтобы не дублировать <link>/<script>
    $out['css'] = array_values(array_unique(array_filter($out['css'], 'is_string')));
    $out['js']  = array_values(array_unique(array_filter($out['js'], 'is_string')));

    // parsers тоже дедуп
    $out['parsers'] = array_values(array_unique(array_filter($out['parsers'], 'is_string')));

    af_advancededitor_debug_log('bbcodes discovery', [
        'dir' => $baseDirAbs,
        'url' => $bbcodesBaseUrl,
        'modules_count' => count($out['packs']),
        'modules_sample' => array_slice(array_keys($out['packs']), 0, 5),
    ]);

    return $out;
}


function af_advancededitor_norm_asset_url(string $bburl, string $addonBaseUrl, string $rel): string
{
    $bburl = rtrim($bburl, '/');
    $addonBaseUrl = rtrim($addonBaseUrl, '/');

    $rel = trim($rel);
    if ($rel === '') return '';

    if (preg_match('~^(https?:)?//~i', $rel) || strpos($rel, 'data:') === 0) {
        return $rel;
    }

    if (isset($rel[0]) && $rel[0] === '/') {
        return $bburl . $rel;
    }

    // если дали "bbcodes/..." — это путь относительно аддона
    return $addonBaseUrl . '/' . ltrim($rel, '/');
}

/**
 * Доступные кнопки:
 * - стандартные команды SCEditor
 * - встроенные кнопки из bbcodes packs
 * - кастомные кнопки из БД (active=1)
 */
function af_advancededitor_get_available_buttons(string $bburl, array $customDefs = []): array
{
    global $db;

    $std = [
        ['cmd' => 'bold', 'label' => 'B', 'hint' => 'SCEditor: bold', 'title' => 'Жирный'],
        ['cmd' => 'italic', 'label' => 'I', 'hint' => 'SCEditor: italic', 'title' => 'Курсив'],
        ['cmd' => 'underline', 'label' => 'U', 'hint' => 'SCEditor: underline', 'title' => 'Подчёркнутый'],
        ['cmd' => 'strike', 'label' => 'S', 'hint' => 'SCEditor: strike', 'title' => 'Зачёркнутый'],
        ['cmd' => 'subscript', 'label' => 'x₂', 'hint' => 'SCEditor: subscript', 'title' => 'Нижний индекс'],
        ['cmd' => 'superscript', 'label' => 'x²', 'hint' => 'SCEditor: superscript', 'title' => 'Верхний индекс'],

        ['cmd' => 'font', 'label' => 'F', 'hint' => 'SCEditor: font', 'title' => 'Шрифт'],
        ['cmd' => 'size', 'label' => 'Sz', 'hint' => 'SCEditor: size', 'title' => 'Размер'],
        ['cmd' => 'color', 'label' => 'C', 'hint' => 'SCEditor: color', 'title' => 'Цвет'],
        ['cmd' => 'removeformat', 'label' => '×', 'hint' => 'SCEditor: removeformat', 'title' => 'Сбросить форматирование'],

        ['cmd' => 'undo', 'label' => '↶', 'hint' => 'SCEditor: undo', 'title' => 'Отменить'],
        ['cmd' => 'redo', 'label' => '↷', 'hint' => 'SCEditor: redo', 'title' => 'Повторить'],
        ['cmd' => 'pastetext', 'label' => 'Tx', 'hint' => 'SCEditor: pastetext', 'title' => 'Вставить как текст'],
        ['cmd' => 'horizontalrule', 'label' => '—', 'hint' => 'Горизонтальная линия', 'title' => 'Горизонтальная линия'],

        ['cmd' => 'left', 'label' => 'L', 'hint' => 'SCEditor: left', 'title' => 'По левому краю'],
        ['cmd' => 'center', 'label' => 'C', 'hint' => 'SCEditor: center', 'title' => 'По центру'],
        ['cmd' => 'right', 'label' => 'R', 'hint' => 'SCEditor: right', 'title' => 'По правому краю'],
        ['cmd' => 'justify', 'label' => 'J', 'hint' => 'SCEditor: justify', 'title' => 'По ширине'],

        ['cmd' => 'bulletlist', 'label' => '•', 'hint' => 'SCEditor: bulletlist', 'title' => 'Маркированный список'],
        ['cmd' => 'orderedlist', 'label' => '1.', 'hint' => 'SCEditor: orderedlist', 'title' => 'Нумерованный список'],

        ['cmd' => 'quote', 'label' => '❝', 'hint' => 'SCEditor: quote', 'title' => 'Цитата'],
        ['cmd' => 'code', 'label' => '</>', 'hint' => 'SCEditor: code', 'title' => 'Код'],

        ['cmd' => 'image', 'label' => '🖼', 'hint' => 'SCEditor: image', 'title' => 'Изображение'],
        ['cmd' => 'link', 'label' => '🔗', 'hint' => 'SCEditor: link', 'title' => 'Ссылка'],
        ['cmd' => 'unlink', 'label' => '⛓', 'hint' => 'SCEditor: unlink', 'title' => 'Убрать ссылку'],
        ['cmd' => 'email', 'label' => '@', 'hint' => 'SCEditor: email', 'title' => 'Email'],
        ['cmd' => 'youtube', 'label' => '▶', 'hint' => 'SCEditor: youtube', 'title' => 'YouTube'],
        ['cmd' => 'emoticon', 'label' => '☺', 'hint' => 'SCEditor: emoticon', 'title' => 'Смайлы'],

        ['cmd' => 'af_togglemode', 'label' => 'A↔', 'hint' => 'Переключить режим: BBCode / Визуальный', 'title' => 'BBCode ⇄ Визуальный'],
        ['cmd' => 'source', 'label' => '{ }', 'hint' => 'SCEditor: source', 'title' => 'Исходник'],

        ['cmd' => 'maximize', 'label' => '⤢', 'hint' => 'SCEditor: maximize', 'title' => 'Развернуть'],
        ['cmd' => '|', 'label' => '|', 'hint' => 'Разделитель группы', 'title' => 'Разделитель'],
    ];

    // === builtins from packs ===
    $packs = af_advancededitor_discover_bbcode_packs($bburl);
    $builtins = [];

    if (!empty($packs['buttons']) && is_array($packs['buttons'])) {
        foreach ($packs['buttons'] as $b) {
            if (!is_array($b) || empty($b['cmd'])) continue;

            $cmd  = (string)$b['cmd'];
            $t    = (string)($b['title'] ?? $cmd);

            $builtins[] = [
                'cmd'   => $cmd,
                'label' => 'BB',
                'hint'  => $t,
                'title' => $t,
                'icon'  => (string)($b['icon'] ?? ''),
            ];
        }
    }

    // === custom from DB (из переданных defs) ===
    $custom = [];
    if (!empty($customDefs)) {
        foreach ($customDefs as $d) {
            if (!is_array($d)) continue;

            $cmd = trim((string)($d['cmd'] ?? ''));
            if ($cmd === '') continue;

            $custom[] = [
                'cmd'   => $cmd,
                'label' => 'AF',
                'hint'  => (string)($d['title'] ?? $cmd),
                'title' => (string)($d['title'] ?? $cmd),
                'icon'  => (string)($d['icon'] ?? ''),
            ];
        }
    }

    return array_merge($std, $builtins, $custom);
}

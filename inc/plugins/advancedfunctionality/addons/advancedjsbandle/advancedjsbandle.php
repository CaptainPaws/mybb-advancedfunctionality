<?php
/**
 * AF Addon: AdvancedJSBand(le)
 * MyBB 1.8.39, PHP 8.0+
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_AJSB_ID', 'advancedjsbandle');
define('AF_AJSB_VER', '1.0.2');

define('AF_AJSB_BASE', AF_ADDONS . AF_AJSB_ID . '/');
define('AF_AJSB_ASSETS_DIR', AF_AJSB_BASE . 'assets/');

define('AF_AJSB_MARK_START', '<!-- af_advancedjsbandle_start -->');
define('AF_AJSB_MARK_END',   '<!-- af_advancedjsbandle_end -->');

function af_advancedjsbandle_install(): bool
{
    // как у advancedfontawesome — вставка делается на install
    af_ajsb_install_or_update_headerinclude();
    return true;
}

function af_advancedjsbandle_uninstall(): bool
{
    af_ajsb_remove_headerinclude();
    return true;
}

function af_advancedjsbandle_activate(): void
{
    // на случай если AF вызывает activate
    af_ajsb_install_or_update_headerinclude();
}

function af_advancedjsbandle_deactivate(): void
{
    af_ajsb_remove_headerinclude();
}

function af_advancedjsbandle_init(): void
{
    global $plugins;

    // Дедупликация на финальной странице:
    // - не добавляем никаких ?v=
    // - убираем любые повторные подключения (в т.ч. с query)
    $plugins->add_hook('pre_output_page', 'af_advancedjsbandle_pre_output');
}

function af_is_script(string $name): bool
{
    if (!defined('THIS_SCRIPT')) {
        return false;
    }

    return strtolower((string)THIS_SCRIPT) === strtolower($name);
}

function af_this_script_is(array $names): bool
{
    foreach ($names as $name) {
        if (af_is_script((string)$name)) {
            return true;
        }
    }

    return false;
}

function af_get_action(): string
{
    global $mybb;

    return strtolower(trim((string)$mybb->get_input('action')));
}

function af_ajsb_is_private_read_with_pmid(): bool
{
    global $mybb;

    if (!af_is_script('private.php')) {
        return false;
    }

    $pmid = (int)$mybb->get_input('pmid', MyBB::INPUT_INT);

    return af_get_action() === 'read' && $pmid > 0;
}

function af_ajsb_allowed_assets_for_page(): array
{
    $css = ['scroll-buttons.css'];
    $js  = ['scroll-buttons.js', 'postcontrols-tooltips.js'];

    $isShowthread  = af_is_script('showthread.php');
    $isForumdisplay = af_is_script('forumdisplay.php');
    $isPmRead = af_ajsb_is_private_read_with_pmid();

    if ($isShowthread) {
        $js[] = 'af_popup_detach.js';
    }

    if ($isShowthread || $isForumdisplay) {
        $css[] = 'af_quickquote.css';
        $js[] = 'af_quickquote.js';
        $css[] = 'fimp.css';
        $js[] = 'fimp.js';
        $js[] = 'kill-threaded-mode-link.js';
    }

    if ($isShowthread || $isForumdisplay || $isPmRead) {
        $css[] = 'postbit-fa-icons.css';
        $js[] = 'postbit-fa-icons.js';
    }

    if ($isShowthread || $isPmRead) {
        $css[] = 'quote-avatars.css';
        $js[] = 'quote-avatars.js';
    }

    return [
        'css' => array_values(array_unique($css)),
        'js' => array_values(array_unique($js)),
    ];
}


/**
 * pre_output_page:
 * 1) берём наш блок между маркерами как есть,
 * 2) чистим у него любые query в src/href (если вдруг появились),
 * 3) удаляем все <script src="...assets/<file>.js..."> и <link href="...assets/<file>.css..."> по всей странице,
 * 4) вставляем наш блок обратно (один раз).
 */
function af_advancedjsbandle_pre_output(string &$page = ''): void
{
    $startPos = strpos($page, AF_AJSB_MARK_START);
    if ($startPos === false) {
        return;
    }

    $endPos = strpos($page, AF_AJSB_MARK_END, $startPos);
    if ($endPos === false) {
        return;
    }

    $endPos += strlen(AF_AJSB_MARK_END);
    $blockHtml = substr($page, $startPos, $endPos - $startPos);
    if ($blockHtml === '' || $blockHtml === false) {
        return;
    }

    $jsFiles  = af_ajsb_list_js_files();
    $cssFiles = af_ajsb_list_css_files();
    $allowed  = af_ajsb_allowed_assets_for_page();

    $allowedJs = array_values(array_intersect($jsFiles, $allowed['js']));
    $allowedCss = array_values(array_intersect($cssFiles, $allowed['css']));

    if (!$jsFiles && !$cssFiles) {
        return;
    }

    $styles  = af_ajsb_build_link_tags($allowedCss, false);
    $scripts = af_ajsb_build_script_tags($allowedJs, false);
    $cleanBlock = "\n" . AF_AJSB_MARK_START . "\n"
        . ($styles ? $styles . "\n" : '')
        . ($scripts ? $scripts . "\n" : '')
        . AF_AJSB_MARK_END . "\n";

    // 2) вырезаем ВСЕ наши скрипты/стили по всей странице (и с query и без)
    foreach ($jsFiles as $fname) {
        $page = af_ajsb_remove_script_tags_for_file($page, $fname);
    }
    foreach ($cssFiles as $fname) {
        $page = af_ajsb_remove_link_tags_for_file($page, $fname);
    }

    // 3) возвращаем наш блок ровно один раз
    $pattern = '#'.preg_quote(AF_AJSB_MARK_START, '#').'.*?'.preg_quote(AF_AJSB_MARK_END, '#').'#si';
    $page = preg_replace($pattern, $cleanBlock, $page, 1);
}

function af_ajsb_install_or_update_headerinclude(): void
{
    require_once MYBB_ROOT . 'inc/adminfunctions_templates.php';

    // 1) удаляем старый блок
    af_ajsb_remove_headerinclude();

    // 2) вставляем ТОЛЬКО маркеры (без link/script), строго после {$stylesheets}
    $block =
        "\n" . AF_AJSB_MARK_START . "\n" .
        AF_AJSB_MARK_END . "\n";

    // ВАЖНО: в replacement для preg_replace нужно экранировать $
    $insert = '{$stylesheets}' . $block;
    $insert = str_replace('$', '\\$', $insert);

    find_replace_templatesets('headerinclude', '#\{\$stylesheets\}#i', $insert);
}

function af_ajsb_list_js_files(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $dir = rtrim((string)AF_AJSB_ASSETS_DIR, "/\\") . '/';
    if (!is_dir($dir)) {
        $cache = [];
        return $cache;
    }

    $files = @scandir($dir);
    if (!is_array($files)) {
        $cache = [];
        return $cache;
    }

    // Собираем ТОЛЬКО "полные" .js (без .min.js), исключаем .map
    $js = [];
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        if (!is_file($dir . $f)) continue;

        if (preg_match('~\.js\.map$~i', $f)) continue;
        if (preg_match('~\.min\.js$~i', $f)) continue;
        if (!preg_match('~\.js$~i', $f)) continue;

        $js[] = $f;
    }

    if (!$js) {
        $cache = [];
        return $cache;
    }

    natcasesort($js);
    $cache = array_values($js);
    return $cache;
}

function af_ajsb_asset_url_base(bool $template = false): string
{
    if ($template) {
        // Для headerinclude (шаблон MyBB)
        return '{$mybb->asset_url}/inc/plugins/advancedfunctionality/addons/' . AF_AJSB_ID . '/assets/';
    }

    // Для pre_output_page (финальный HTML)
    global $mybb;

    $base = '';
    if (is_object($mybb) && isset($mybb->asset_url) && $mybb->asset_url !== '') {
        $base = (string)$mybb->asset_url;
    } else {
        // Фолбэк на bburl если asset_url не определён
        $base = (string)($mybb->settings['bburl'] ?? '');
    }

    $base = rtrim($base, "/");
    return $base . '/inc/plugins/advancedfunctionality/addons/' . AF_AJSB_ID . '/assets/';
}

function af_ajsb_build_script_tags(array $files = [], bool $template = true): string
{
    if (!$files) {
        $files = af_ajsb_list_js_files();
    }
    if (!$files) {
        return '';
    }

    $base = af_ajsb_asset_url_base($template);

    $out = [];
    foreach ($files as $fname) {
        // Без ?v=..., как ты требуешь
        $src = $base . $fname;

        // defer оставляем
        $out[] = '<script type="text/javascript" src="' . htmlspecialchars($src, ENT_QUOTES) . '" defer></script>';
    }

    return implode("\n", $out);
}
/**
 * Удаляет из HTML любые <script ...src=".../advancedjsbandle/assets/<fname>.js[?...]">...</script>
 */
function af_ajsb_remove_script_tags_for_file(string $html, string $fname): string
{
    $qf = preg_quote($fname, '#');

    $pattern = '#<script\b[^>]*\bsrc=(["\'])[^"\']*/inc/plugins/advancedfunctionality/addons/'
        . preg_quote(AF_AJSB_ID, '#')
        . '/assets/' . $qf . '(?:\?[^"\']*)?\1[^>]*>\s*</script>\s*#is';

    return preg_replace($pattern, '', $html);
}

function af_ajsb_remove_headerinclude(): void
{
    require_once MYBB_ROOT . 'inc/adminfunctions_templates.php';

    find_replace_templatesets(
        'headerinclude',
        '#\s*<!--\s*af_advancedjsbandle_start\s*-->.*?<!--\s*af_advancedjsbandle_end\s*-->\s*#is',
        ''
    );

    // страховка на точные константы
    find_replace_templatesets(
        'headerinclude',
        '#\s*' . preg_quote(AF_AJSB_MARK_START, '#') . '.*?' . preg_quote(AF_AJSB_MARK_END, '#') . '\s*#is',
        ''
    );
}

function af_ajsb_list_css_files(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $dir = rtrim((string)AF_AJSB_ASSETS_DIR, "/\\") . '/';
    if (!is_dir($dir)) {
        $cache = [];
        return $cache;
    }

    $files = @scandir($dir);
    if (!is_array($files)) {
        $cache = [];
        return $cache;
    }

    // Собираем ТОЛЬКО "полные" .css (без .min.css), исключаем .map
    $css = [];
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        if (!is_file($dir . $f)) continue;

        if (preg_match('~\.css\.map$~i', $f)) continue;
        if (preg_match('~\.min\.css$~i', $f)) continue;
        if (!preg_match('~\.css$~i', $f)) continue;

        $css[] = $f;
    }

    if (!$css) {
        $cache = [];
        return $cache;
    }

    natcasesort($css);
    $cache = array_values($css);
    return $cache;
}

function af_ajsb_build_link_tags(array $files = [], bool $template = true): string
{
    if (!$files) {
        $files = af_ajsb_list_css_files();
    }
    if (!$files) {
        return '';
    }

    $base = af_ajsb_asset_url_base($template);

    $out = [];
    foreach ($files as $fname) {
        $href = $base . $fname;
        $fileRel = 'assets/' . ltrim((string)$fname, '/');

        if (!$template && function_exists('af_theme_stylesheet_delivery_decision')) {
            $decision = af_theme_stylesheet_delivery_decision(AF_AJSB_ID, $fileRel);
            if (!empty($decision['use_theme_stylesheet']) && !empty($decision['theme_href'])) {
                $href = (string)$decision['theme_href'];
            } elseif (empty($decision['include_file'])) {
                continue;
            }
        }

        $out[] = '<link rel="stylesheet" type="text/css" href="' . htmlspecialchars($href, ENT_QUOTES) . '" />';
    }

    return implode("\n", $out);
}

/**
 * Удаляет из HTML любые <link ...href=".../advancedjsbandle/assets/<fname>.css[?...]"...>
 */
function af_ajsb_remove_link_tags_for_file(string $html, string $fname): string
{
    $qf = preg_quote($fname, '#');

    $pattern = '#<link\b[^>]*\bhref=(["\'])[^"\']*/inc/plugins/advancedfunctionality/addons/'
        . preg_quote(AF_AJSB_ID, '#')
        . '/assets/' . $qf . '(?:\?[^"\']*)?\1[^>]*>\s*#is';

    return preg_replace($pattern, '', $html);
}

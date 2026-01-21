<?php
/**
 * AF Addon: AdvancedFontAwesome — Admin controller (AF router)
 */
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

$bootstrap = AF_ADDONS . 'advancedfontawesome/advancedfontawesome.php';
if (file_exists($bootstrap)) {
    require_once $bootstrap;
}

class AF_Admin_Advancedfontawesome
{
    private const ROUTER_MODULE = 'advancedfunctionality';
    private const ROUTER_VIEW = 'advancedfontawesome';

    private static function url(array $params = []): string
    {
        $base = [
            'module' => self::ROUTER_MODULE,
            'af_view' => self::ROUTER_VIEW,
        ];

        $all = array_merge($base, $params);
        unset($all['action']);

        return 'index.php?' . http_build_query($all, '', '&');
    }

    private static function go(array $params = []): void
    {
        admin_redirect(self::url($params));
    }

    private static function load_lang(): void
    {
        if (function_exists('af_afo_lang_load')) {
            af_afo_lang_load(true);
        }
    }

    private static function ensure_permission(): void
    {
        global $mybb;

        $uid = (int)($mybb->user['uid'] ?? 0);
        if (function_exists('is_super_admin') && is_super_admin($uid)) {
            return;
        }

        if (!empty($mybb->usergroup['cancp'])) {
            return;
        }

        error_no_permission();
    }

    public static function dispatch(): void
    {
        global $mybb, $page;

        self::load_lang();
        self::ensure_permission();

        $do = (string)$mybb->get_input('do');

        if (is_object($page) && method_exists($page, 'add_breadcrumb_item')) {
            $page->add_breadcrumb_item('AdvancedFontAwesome', self::url(['do' => 'forums']));
        }

        switch ($do) {
            case 'save_forums_icons':
                self::do_save_forums_icons();
                return;
            case 'forums':
            default:
                self::page_forums();
                return;
        }
    }

    private static function page_forums(): void
    {
        global $mybb, $page, $lang;

        self::enqueue_assets();

        $rows = '';
        $forums = self::get_forums_list();

        $pickLabel = $lang->af_advancedfontawesome_table_pick ?? 'Выбрать';
        $clearLabel = $lang->af_advancedfontawesome_table_clear ?? 'Очистить';

        foreach ($forums as $forum) {
            $fid = (int)$forum['fid'];
            $nameRaw = (string)($forum['name'] ?? '');
            $icon = af_afo_normalize_icon((string)($forum['af_fa_icon'] ?? ''));

            $nameLower = self::normalize_lower($nameRaw);
            $name = htmlspecialchars_uni($nameRaw);
            $iconSafe = htmlspecialchars_uni($icon);

            $preview = $iconSafe !== '' ? '<i class="' . $iconSafe . '"></i>' : '';
            $indent = (int)$forum['depth'] * 20;

            $rows .= af_afo_render_template('af_advancedfontawesome_admin_forums_row', [
                'fid' => (string)$fid,
                'name' => $name,
                'name_lc' => htmlspecialchars_uni($nameLower),
                'indent' => (string)$indent,
                'icon' => $iconSafe,
                'preview' => $preview,
                'has_icon' => $iconSafe !== '' ? '1' : '0',
                'pick_label' => htmlspecialchars_uni($pickLabel),
                'clear_label' => htmlspecialchars_uni($clearLabel),
            ]);
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6">' . htmlspecialchars_uni($lang->af_advancedfontawesome_empty ?? 'Форумы не найдены.') . '</td></tr>';
        }

        $forumSearch = (string)$mybb->get_input('forum_search');
        $filterIcon = (int)$mybb->get_input('filter_icon', MyBB::INPUT_INT) === 1;

        $html = af_afo_render_template('af_advancedfontawesome_admin_forums_page', [
            'rows' => $rows,
            'my_post_key' => $mybb->post_code,
            'forum_search' => htmlspecialchars_uni($forumSearch),
            'filter_icon_checked' => $filterIcon ? 'checked="checked"' : '',
            'title' => htmlspecialchars_uni($lang->af_advancedfontawesome_admin_title ?? 'Font Awesome для форумов'),
            'description' => htmlspecialchars_uni($lang->af_advancedfontawesome_admin_desc ?? 'Управление иконками Font Awesome для форумов.'),
            'forum_search_label' => htmlspecialchars_uni($lang->af_advancedfontawesome_forum_search_label ?? 'Поиск форума'),
            'forum_search_placeholder' => htmlspecialchars_uni($lang->af_advancedfontawesome_forum_search_placeholder ?? 'Введите название форума'),
            'filter_icon_label' => htmlspecialchars_uni($lang->af_advancedfontawesome_filter_icon_label ?? 'Показывать только с иконкой'),
            'save_label' => htmlspecialchars_uni($lang->af_advancedfontawesome_save_changes ?? 'Сохранить изменения'),
            'sticky_label' => htmlspecialchars_uni($lang->af_advancedfontawesome_sticky_save ?? 'Сохранить'),
            'th_forum' => htmlspecialchars_uni($lang->af_advancedfontawesome_table_forum ?? 'Форум'),
            'th_fid' => htmlspecialchars_uni($lang->af_advancedfontawesome_table_fid ?? 'FID'),
            'th_icon' => htmlspecialchars_uni($lang->af_advancedfontawesome_table_icon ?? 'Иконка'),
            'th_preview' => htmlspecialchars_uni($lang->af_advancedfontawesome_table_preview ?? 'Превью'),
            'th_pick' => htmlspecialchars_uni($lang->af_advancedfontawesome_table_pick ?? 'Выбрать'),
            'th_clear' => htmlspecialchars_uni($lang->af_advancedfontawesome_table_clear ?? 'Очистить'),
        ]);

        echo $html;
    }

    private static function do_save_forums_icons(): void
    {
        global $db, $mybb, $lang;

        if ($mybb->request_method !== 'post') {
            self::go(['do' => 'forums']);
            return;
        }

        verify_post_check($mybb->get_input('my_post_key'));
        self::ensure_permission();

        $icons = $mybb->get_input('icons', MyBB::INPUT_ARRAY);
        if (!is_array($icons)) {
            $icons = [];
        }

        $updated = 0;

        foreach ($icons as $fid => $rawIcon) {
            $fid = (int)$fid;
            if ($fid <= 0) {
                continue;
            }

            $icon = af_afo_normalize_icon((string)$rawIcon);
            $db->update_query('forums', ['af_fa_icon' => $db->escape_string($icon)], "fid='{$fid}'");
            $updated++;
        }

        if (self::is_ajax()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => 1, 'updated' => $updated], JSON_UNESCAPED_UNICODE);
            exit;
        }

        flash_message($lang->af_advancedfontawesome_saved ?? 'Иконки форумов сохранены.', 'success');
        self::go(['do' => 'forums']);
    }

    private static function enqueue_assets(): void
    {
        global $page, $lang;

        if (!is_object($page)) {
            return;
        }

        $assetsBase = '../inc/plugins/advancedfunctionality/addons/' . AF_AFO_ID . '/assets';
        $faCss = $assetsBase . '/font-awesome-6/css/all.css';

        $styles = [
            ['value' => 'fa-solid', 'label' => $lang->af_advancedfontawesome_style_solid ?? 'Solid'],
            ['value' => 'fa-regular', 'label' => $lang->af_advancedfontawesome_style_regular ?? 'Regular'],
            ['value' => 'fa-brands', 'label' => $lang->af_advancedfontawesome_style_brands ?? 'Brands'],
        ];

        $cfg = [
            'cssUrl' => $faCss,
            'defaultStyle' => 'fa-solid',
            'minSearchLength' => 2,
            'maxResults' => 400,
            'styles' => $styles,
            'texts' => [
                'searchIconsPlaceholder' => $lang->af_advancedfontawesome_icon_search ?? 'Поиск иконок...',
                'searchForumsPlaceholder' => $lang->af_advancedfontawesome_forum_search_placeholder ?? 'Введите название форума',
                'pickerTitle' => $lang->af_advancedfontawesome_picker_title ?? 'Выбор иконки',
                'close' => $lang->af_advancedfontawesome_picker_close ?? 'Закрыть',
                'found' => $lang->af_advancedfontawesome_found ?? 'Найдено',
                'typeMore' => $lang->af_advancedfontawesome_type_more ?? 'Введите 2+ символа',
                'noResults' => $lang->af_advancedfontawesome_no_icons ?? 'Иконки не найдены',
            ],
        ];

        $cfgJson = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $page->extra_header .= "\n<link rel=\"stylesheet\" type=\"text/css\" href=\"{$faCss}?ver=" . AF_AFO_VER . "\" />";
        $page->extra_header .= "\n<link rel=\"stylesheet\" type=\"text/css\" href=\"{$assetsBase}/advancedfontawesome-admin.css?ver=" . AF_AFO_VER . "\" />";
        $page->extra_header .= "\n<script>window.afAdvancedFontAwesomeAdminConfig={$cfgJson};</script>";
        $page->extra_header .= "\n<script src=\"{$assetsBase}/advancedfontawesome-admin.js?ver=" . AF_AFO_VER . "\"></script>";
    }

    private static function get_forums_list(): array
    {
        global $db;

        $rows = [];
        $byPid = [];

        $query = $db->query(
            "SELECT fid, pid, name, af_fa_icon, disporder
             FROM {$db->table_prefix}forums
             ORDER BY pid, disporder, fid"
        );

        while ($row = $db->fetch_array($query)) {
            $pid = (int)$row['pid'];
            $row['fid'] = (int)$row['fid'];
            $row['disporder'] = (int)$row['disporder'];
            $byPid[$pid][] = $row;
        }

        $walk = function (int $pid, int $depth) use (&$walk, &$byPid, &$rows): void {
            if (empty($byPid[$pid])) {
                return;
            }
            foreach ($byPid[$pid] as $forum) {
                $forum['depth'] = $depth;
                $rows[] = $forum;
                $walk((int)$forum['fid'], $depth + 1);
            }
        };

        $walk(0, 0);

        return $rows;
    }

    private static function normalize_lower(string $value): string
    {
        if (function_exists('my_strtolower')) {
            return my_strtolower($value);
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value);
        }

        return strtolower($value);
    }

    private static function is_ajax(): bool
    {
        global $mybb;

        if ((int)$mybb->get_input('ajax', MyBB::INPUT_INT) === 1) {
            return true;
        }

        $header = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return strtolower($header) === 'xmlhttprequest';
    }
}

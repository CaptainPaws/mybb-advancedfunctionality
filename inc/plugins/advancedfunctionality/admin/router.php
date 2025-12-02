<?php
// Router for Advanced functionality admin module
if (!defined('IN_MYBB')) { die('No direct access'); }

require_once MYBB_ROOT.'inc/plugins/advancedfunctionality.php';

class AF_Admin
{
    public static function dispatch()
    {
        global $mybb, $page, $lang, $db;

        af_ensure_scaffold(false);
        af_ensure_core_languages(false);
        $lang->load('advancedfunctionality');

        $page->add_breadcrumb_item($lang->af_admin_title);

        // Секции (для module_meta и активного заголовка)
        $sections = self::collectAdminSections();

        $action = $mybb->get_input('af_action');
        $addon  = $mybb->get_input('addon');
        $view   = $mybb->get_input('af_view');

        if ($action && $addon) {
            verify_post_check($mybb->get_input('my_post_key'));

            if ($action === 'enable') {
                self::enableAddon($addon);
                flash_message($lang->af_addon_enabled, 'success');
            } elseif ($action === 'disable') {
                self::disableAddon($addon);
                $bootstrap = self::addonBootstrap($addon);
                if ($bootstrap && is_file($bootstrap)) {
                    require_once $bootstrap;
                    $fn = 'af_'.$addon.'_deactivate';
                    if (function_exists($fn)) { $fn(); }
                }
                flash_message($lang->af_addon_disabled, 'success');
            }
            admin_redirect('index.php?module='.AF_PLUGIN_ID.'&_='.TIME_NOW);
        }

        af_reload_settings_runtime();

    // Заголовок ACP
    $page->output_header($lang->af_admin_title);

    // Никаких своих <div id="left_menu"> и <div id="content"> — всё внутри системного #page
    if ($view) {
        // Подпишем хлебные крошки для секции
        foreach ($sections as $sec) {
            if ($sec['slug'] === $view) {
                $page->add_breadcrumb_item($sec['title']);
                break;
            }
        }

        // Роутинг в контроллер аддона
        $ctrl = self::findAdminController($view);
        if ($ctrl && file_exists($ctrl['path'])) {
            require_once $ctrl['path'];
            $klass = $ctrl['class'];
            if (class_exists($klass) && method_exists($klass, 'dispatch')) {
                // ВАЖНО: контроллер НЕ должен вызывать output_header/output_footer
                call_user_func([$klass, 'dispatch']);
            } else {
                echo '<div class="error">Контроллер найден, но класс/метод не обнаружен.</div>';
            }
        } else {
            echo '<div class="error">Не найден контроллер для указанного раздела.</div>';
        }
    } else {
        // Обзор аддонов
        $table = new Table;
        $table->construct_header($lang->af_tbl_name_desc, ['width' => '55%']);
        $table->construct_header($lang->af_tbl_version,   ['width' => '10%', 'class' => 'align_center']);
        $table->construct_header($lang->af_tbl_status,    ['width' => '10%', 'class' => 'align_center']);
        $table->construct_header($lang->af_tbl_toggle,    ['width' => '15%', 'class' => 'align_center']);
        $table->construct_header($lang->af_tbl_settings,  ['width' => '10%', 'class' => 'align_center']);

        $addons = self::discoverAddons();

        if (!$addons) {
            $table->construct_cell($lang->af_no_addons, ['colspan' => 5, 'class'=>'align_center']);
            $table->construct_row();
        } else {
            foreach ($addons as $meta) {
                af_sync_addon_languages($meta, false);
                $enabled = self::isAddonEnabled($meta['id']);

                $name  = htmlspecialchars_uni($meta['name']);
                $desc  = htmlspecialchars_uni($meta['description'] ?? '');
                $ver   = htmlspecialchars_uni($meta['version'] ?? '1.0.0');

                $authorHtml = '';
                if (!empty($meta['author'])) {
                    $a = htmlspecialchars_uni($meta['author']);
                    if (!empty($meta['authorsite'])) {
                        $u = htmlspecialchars_uni($meta['authorsite']);
                        $authorHtml = "<br /><span class='smalltext' style='color:#666;'>{$lang->af_author}: <a href=\"{$u}\" target=\"_blank\" rel=\"noopener\">{$a}</a></span>";
                    } else {
                        $authorHtml = "<br /><span class='smalltext' style='color:#666;'>{$lang->af_author}: {$a}</span>";
                    }
                }

                if (!empty($meta['admin']['slug']) && $enabled) {
                    $desc .= ($desc ? ' ' : '').'(Админ-страница: '
                        . 'Advanced functionality → <a href="index.php?module='.AF_PLUGIN_ID.'&amp;af_view='.htmlspecialchars_uni($meta['admin']['slug']).'">'
                        . htmlspecialchars_uni($meta['admin']['title'] ?? $meta['name']).'</a>)';
                }
                $nameDesc = "<strong>{$name}</strong><br /><span class='smalltext'>{$desc}</span>{$authorHtml}";

                $table->construct_cell($nameDesc);
                $table->construct_cell($ver, ['class' => 'align_center']);
                $table->construct_cell($enabled ? "<span style='color:green;'>{$lang->af_on}</span>" : "<span style='color:#a00;'>{$lang->af_off}</span>", ['class'=>'align_center']);

                $btn_text  = $enabled ? $lang->af_btn_disable : $lang->af_btn_enable;
                $act       = $enabled ? 'disable' : 'enable';
                $form_html = '<form method="post" action="index.php?module='.AF_PLUGIN_ID.'" style="margin:0;">'
                        . '<input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni($mybb->post_code).'">'
                        . '<input type="hidden" name="af_action" value="'.htmlspecialchars_uni($act).'">'
                        . '<input type="hidden" name="addon" value="'.htmlspecialchars_uni($meta['id']).'">'
                        . '<input type="submit" class="submit_button" value="'.htmlspecialchars_uni($btn_text).'">'
                        . '</form>';
                $table->construct_cell($form_html, ['class'=>'align_center']);

                $gid = self::findSettingsGroup('af_'.$meta['id']);
                if ($gid) {
                    $gear = "⚙";
                    $url  = "index.php?module=config-settings&amp;action=change&amp;gid=".$gid;
                    $table->construct_cell("<a href=\"{$url}\" title=\"{$lang->af_open_settings}\" style=\"font-size:20px;text-decoration:none;\">{$gear}</a>", ['class'=>'align_center']);
                } else {
                    $table->construct_cell("—", ['class'=>'align_center']);
                }

                $table->construct_row();
            }
        }

        $table->output($lang->af_admin_title);
    }

    // Закрываем системный layout
    $page->output_footer();
    exit;

    }


    public static function collectAdminSections(): array
    {
        $out = [];
        foreach (self::discoverAddons() as $meta) {
            if (!self::isAddonEnabled($meta['id'])) continue;
            if (empty($meta['admin']['slug'])) continue;
            $out[] = [
                'id'    => $meta['id'],
                'slug'  => $meta['admin']['slug'],
                'title' => $meta['admin']['title'] ?? $meta['name'],
            ];
        }
        usort($out, fn($a,$b)=>strcasecmp($a['title'],$b['title']));
        return $out;
    }

    public static function findAdminController(string $slug): ?array
    {
        foreach (self::discoverAddons() as $meta) {
            if (!self::isAddonEnabled($meta['id'])) continue;
            if (!empty($meta['admin']['slug']) && $meta['admin']['slug'] === $slug) {
                $path = $meta['path'].($meta['admin']['controller'] ?? '');
                $klass = 'AF_Admin_'.preg_replace('~[^A-Za-z0-9]+~', '', ucfirst($slug));
                return ['path'=>$path, 'class'=>$klass];
            }
        }
        return null;
    }

    // ==== ниже — как у тебя было (enable/disable/discover и т.д.) ====

    public static function discoverAddons(): array { return af_discover_addons(); }
    public static function isAddonEnabled(string $id): bool
    {
        global $mybb;
        return isset($mybb->settings['af_'.$id.'_enabled']) && $mybb->settings['af_'.$id.'_enabled'] === '1';
    }
    public static function enableAddon(string $id): void
    {
        $bootstrap = self::addonBootstrap($id);
        if ($bootstrap && is_file($bootstrap)) {
            require_once $bootstrap;
            $fn = 'af_'.$id.'_install';
            if (function_exists($fn)) { $fn(); }
        }
        self::ensureEnabledSetting($id, 1);
        af_rebuild_and_reload_settings();
    }
    public static function disableAddon(string $id): void
    {
        self::ensureEnabledSetting($id, 0);
        af_rebuild_and_reload_settings();
    }
    public static function addonBootstrap(string $id): ?string
    {
        $list = self::discoverAddons();
        foreach ($list as $meta) { if ($meta['id'] === $id) return $meta['bootstrap'] ?? null; }
        return null;
    }
    public static function ensureEnabledSetting(string $id, int $value): void
    {
        global $db;
        $group_name = 'af_'.$id;
        $setting    = 'af_'.$id.'_enabled';

        $q = $db->simple_select('settinggroups','gid',"name='".$db->escape_string($group_name)."'", ['limit'=>1]);
        $gid = (int)$db->fetch_field($q, 'gid');
        if (!$gid) {
            $gid = af_ensure_settinggroup($group_name, 'AF: '.$id, 'Настройки внутреннего аддона '.$id);
        }

        af_ensure_setting($group_name, $setting, 'Включить аддон', 'Включает или отключает аддон.', 'yesno', ($value ? '1' : '0'), 1);

        $name_esc = $db->escape_string($setting);
        $dupes_q  = $db->simple_select('settings', 'sid', "name='{$name_esc}'", ['order_by' => 'sid', 'order_dir' => 'asc']);
        $sids     = [];
        while ($r = $db->fetch_array($dupes_q)) { $sids[] = (int)$r['sid']; }
        if (count($sids) > 1) {
            $keep = array_shift($sids);
            $db->delete_query('settings', "sid IN (".implode(',', array_map('intval',$sids)).")");
            $db->update_query('settings', ['gid' => (int)$gid, 'value' => ($value ? '1' : '0')], "sid=".(int)$keep);
        }
    }
    public static function findSettingsGroup(string $name): ?int
    {
        global $db;
        $q = $db->simple_select('settinggroups','gid',"name='".$db->escape_string($name)."'", ['limit'=>1]);
        $gid = $db->fetch_field($q, 'gid');
        return $gid ? (int)$gid : null;
    }
}

AF_Admin::dispatch();
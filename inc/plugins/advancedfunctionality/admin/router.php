<?php
// Router for Advanced functionality admin module
if (!defined('IN_MYBB')) { die('No direct access'); }

require_once MYBB_ROOT.'inc/plugins/advancedfunctionality.php';

class AF_Admin
{
    public static function dispatch()
    {
        global $mybb, $page, $lang;

        af_ensure_scaffold(false);
        af_ensure_core_languages(false);
        $lang->load('advancedfunctionality');

        $page->add_breadcrumb_item($lang->af_admin_title);

        $action = $mybb->get_input('af_action');
        $addon  = $mybb->get_input('addon');
        $view   = $mybb->get_input('af_view');

        if ($action === 'sync_theme_stylesheets') {
            verify_post_check($mybb->get_input('my_post_key'));
            af_sync_theme_stylesheets(true);
            flash_message('AF theme stylesheets resynced from seed files.', 'success');
            admin_redirect('index.php?module='.AF_PLUGIN_ID.'&_='.TIME_NOW);
        }

        if (strpos((string)$action, 'theme_stylesheets_') === 0) {
            verify_post_check($mybb->get_input('my_post_key'));
            self::handleThemeStylesheetsAction($action, $addon);
        }

        if ($action && $addon && strpos((string)$action, 'theme_stylesheets_') !== 0) {
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

        $page->output_header($lang->af_admin_title);

        if ($view === 'theme_stylesheets') {
            self::renderThemeStylesheetsPage();
        } elseif ($view) {
            $sections = self::collectAdminSections();
            foreach ($sections as $sec) {
                if ($sec['slug'] === $view) {
                    $page->add_breadcrumb_item($sec['title']);
                    break;
                }
            }

            $ctrl = self::findAdminController($view);
            if ($ctrl && file_exists($ctrl['path'])) {
                require_once $ctrl['path'];
                $klass = $ctrl['class'];

                if (class_exists($klass) && method_exists($klass, 'dispatch')) {
                    call_user_func([$klass, 'dispatch']);
                } else {
                    echo '<div class="error">Контроллер найден, но класс/метод не обнаружен.</div>';
                }
            } else {
                echo '<div class="error">Не найден контроллер для указанного раздела.</div>';
            }
        } else {
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

        $page->output_footer();
        exit;
    }

    private static function handleThemeStylesheetsAction(string $action, string $addon): void
    {
        global $mybb, $lang;

        $addonId = trim($addon);
        $logicalId = trim((string)$mybb->get_input('logical_id'));
        $confirmForce = $mybb->get_input('confirm_force', MyBB::INPUT_INT) === 1;
        $themeTid = $mybb->get_input('theme_tid', MyBB::INPUT_INT);
        $themeScope = strtolower(trim((string)$mybb->get_input('theme_scope')));
        if (!in_array($themeScope, ['current', 'all'], true)) {
            $themeScope = 'all';
        }

        if ($action === 'theme_stylesheets_force_resync' && !$confirmForce) {
            flash_message($lang->af_theme_stylesheets_force_confirm, 'error');
            admin_redirect(self::themeStylesheetsUrl($addonId !== '' ? $addonId : null, $themeTid > 0 ? $themeTid : null, $themeScope));
        }

        $op = 'status';
        if ($action === 'theme_stylesheets_sync_all') {
            $op = 'sync_all';
        } elseif ($action === 'theme_stylesheets_integrate') {
            $op = 'integrate';
        } elseif ($action === 'theme_stylesheets_set_file_mode') {
            $op = 'set_file_mode';
        } elseif ($action === 'theme_stylesheets_set_theme_mode') {
            $op = 'set_theme_mode';
        } elseif ($action === 'theme_stylesheets_set_auto_mode') {
            $op = 'set_auto_mode';
        } elseif ($action === 'theme_stylesheets_sync_addon') {
            $op = 'sync_addon';
        } elseif ($action === 'theme_stylesheets_rebuild_missing') {
            $op = 'rebuild_missing';
        } elseif ($action === 'theme_stylesheets_force_resync') {
            $op = 'force_resync';
        } elseif ($action === 'theme_stylesheets_restore_safe') {
            $op = 'restore_safe';
        } elseif ($action === 'theme_stylesheets_hash_status') {
            $op = 'hash_status';
        }

        $result = af_theme_stylesheets_execute_action($op, $addonId !== '' ? $addonId : null, $confirmForce, $themeTid > 0 ? $themeTid : null, $logicalId !== '' ? $logicalId : null);
        $message = af_theme_stylesheets_action_message($op, $result, $lang);
        flash_message($message, 'success');
        admin_redirect(self::themeStylesheetsUrl($addonId !== '' ? $addonId : null, $themeTid > 0 ? $themeTid : null, $themeScope));
    }

    private static function renderThemeStylesheetsPage(): void
    {
        global $mybb, $lang;

        $addonFilter = trim((string)$mybb->get_input('addon'));
        $themeFilter = strtolower(trim((string)$mybb->get_input('theme_scope')));
        if (!in_array($themeFilter, ['current', 'all'], true)) {
            $themeFilter = 'all';
        }

        $currentThemeTid = self::currentThemeTid();
        $rows = af_collect_theme_stylesheet_diagnostics($addonFilter !== '' ? $addonFilter : null);

        if ($themeFilter === 'current') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($currentThemeTid): bool {
                return (int)($row['theme_tid'] ?? 0) === $currentThemeTid;
            }));
        }

        echo '<style>
            .af-ts-top-actions{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 14px 0;align-items:center;}
            .af-ts-help{margin:0 0 12px 0;padding:11px 13px;border:1px solid #74556a;background:#efe4ea;border-radius:4px;color:#2a1421;}
            .af-ts-help strong{color:#2a1421;}
            .af-ts-help ul{margin:6px 0 0 18px;}
            .af-ts-help li{margin:2px 0;}
            .af-ts-row-actions{display:flex;flex-direction:column;gap:7px;}
            .af-ts-row-primary,.af-ts-row-secondary{display:flex;flex-wrap:wrap;gap:6px;}
            .af-ts-row-primary{margin-bottom:1px;}
            .af-ts-btn-primary,.af-ts-btn-primary:link,.af-ts-btn-primary:visited{
                display:inline-block;
                font-weight:700;
                padding:6px 16px;
                background:rgb(78, 36, 59);
                border-radius:4px;
                color:#fff;
                text-decoration:none;
                border:1px solid rgba(255,255,255,.08);
                line-height:1.2;
                cursor:pointer;
            }
            .af-ts-btn-primary:hover,.af-ts-btn-primary:focus{color:#fff;text-decoration:none;background:rgb(96, 44, 72);}
            .af-ts-btn-secondary,.af-ts-btn-secondary:link,.af-ts-btn-secondary:visited{
                display:inline-block;
                font-size:11px;
                font-weight:600;
                padding:3px 9px;
                border-radius:3px;
                line-height:1.2;
                text-decoration:none;
                cursor:pointer;
            }
        </style>';
        echo '<h2>'.htmlspecialchars_uni($lang->af_theme_stylesheets_title).'</h2>';
        echo '<p class="smalltext">'.htmlspecialchars_uni($lang->af_theme_stylesheets_help).'</p>';
        echo '<div class="af-ts-help">';
        echo '<strong>'.htmlspecialchars_uni($lang->af_theme_stylesheets_help_actions_title).'</strong>';
        echo '<ul class="smalltext">';
        echo '<li><strong>'.htmlspecialchars_uni($lang->af_theme_stylesheets_integrate).':</strong> '.htmlspecialchars_uni($lang->af_theme_stylesheets_help_integrate).'</li>';
        echo '<li><strong>'.htmlspecialchars_uni($lang->af_theme_stylesheets_edit_stylesheet).':</strong> '.htmlspecialchars_uni($lang->af_theme_stylesheets_help_edit_stylesheet).'</li>';
        echo '<li><strong>'.htmlspecialchars_uni($lang->af_theme_stylesheets_edit_properties).':</strong> '.htmlspecialchars_uni($lang->af_theme_stylesheets_help_edit_properties).'</li>';
        echo '<li><strong>'.htmlspecialchars_uni($lang->af_theme_stylesheets_set_file_mode).':</strong> '.htmlspecialchars_uni($lang->af_theme_stylesheets_help_file_mode).'</li>';
        echo '<li><strong>'.htmlspecialchars_uni($lang->af_theme_stylesheets_set_theme_mode).':</strong> '.htmlspecialchars_uni($lang->af_theme_stylesheets_help_theme_mode).'</li>';
        echo '<li><strong>'.htmlspecialchars_uni($lang->af_theme_stylesheets_sync_addon).':</strong> '.htmlspecialchars_uni($lang->af_theme_stylesheets_help_sync_addon).'</li>';
        echo '<li><strong>'.htmlspecialchars_uni($lang->af_theme_stylesheets_force_resync).':</strong> '.htmlspecialchars_uni($lang->af_theme_stylesheets_help_force_resync).'</li>';
        echo '<li><strong>'.htmlspecialchars_uni($lang->af_theme_stylesheets_rebuild_missing).':</strong> '.htmlspecialchars_uni($lang->af_theme_stylesheets_help_rebuild_missing).'</li>';
        echo '</ul>';
        echo '</div>';

        self::renderThemeStylesheetFilters($addonFilter, $themeFilter);

        echo '<div class="af-ts-top-actions">';
        echo self::renderThemeStylesheetActionForm('theme_stylesheets_sync_all', $lang->af_theme_stylesheets_sync_all, '', true, false, $themeFilter, null, '', 'secondary');
        echo self::renderThemeStylesheetActionForm('theme_stylesheets_rebuild_missing', $lang->af_theme_stylesheets_rebuild_missing, '', true, false, $themeFilter, null, '', 'secondary');
        echo self::renderThemeStylesheetActionForm('theme_stylesheets_force_resync', $lang->af_theme_stylesheets_force_resync, '', true, true, $themeFilter, null, '', 'secondary');
        echo self::renderThemeStylesheetActionForm('theme_stylesheets_hash_status', $lang->af_theme_stylesheets_hash_status, '', true, false, $themeFilter, null, '', 'secondary');
        echo '</div>';

        $table = new Table;
        $table->construct_header($lang->af_theme_stylesheets_col_theme_id, ['width' => '4%']);
        $table->construct_header($lang->af_theme_stylesheets_col_theme_title, ['width' => '10%']);
        $table->construct_header($lang->af_theme_stylesheets_col_addon, ['width' => '8%']);
        $table->construct_header($lang->af_theme_stylesheets_col_logical, ['width' => '9%']);
        $table->construct_header($lang->af_theme_stylesheets_col_name, ['width' => '8%']);
        $table->construct_header($lang->af_theme_stylesheets_col_seed, ['width' => '12%']);
        $table->construct_header($lang->af_theme_stylesheets_col_mode, ['width' => '5%']);
        $table->construct_header($lang->af_theme_stylesheets_col_status, ['width' => '11%']);
        $table->construct_header($lang->af_theme_stylesheets_col_attached, ['width' => '9%']);
        $table->construct_header($lang->af_theme_stylesheets_col_sync, ['width' => '8%']);
        $table->construct_header($lang->af_theme_stylesheets_col_actions, ['width' => '16%']);

        if (!$rows) {
            $table->construct_cell($lang->af_theme_stylesheets_empty, ['colspan' => 11, 'class' => 'align_center']);
            $table->construct_row();
        } else {
            foreach ($rows as $row) {
                $addonId = (string)$row['addon_id'];
                $themeTid = (int)($row['theme_tid'] ?? 0);
                $statusRaw = (string)$row['status'];
                $statusLabelKey = 'af_theme_stylesheets_status_'.$statusRaw;
                $statusLabel = isset($lang->{$statusLabelKey}) ? $lang->{$statusLabelKey} : $statusRaw;
                $statusColor = '#2f6f2f';
                if ($statusRaw === 'missing' || $statusRaw === 'outdated') $statusColor = '#a66900';
                if ($statusRaw === 'not_integrated') $statusColor = '#555';
                if ($statusRaw === 'manual_override' || $statusRaw === 'duplicate_risk') $statusColor = '#a00';

                $lastSync = !empty($row['last_synced_at']) ? my_date('relative', (int)$row['last_synced_at']) : '—';
                $mode = htmlspecialchars_uni((string)$row['mode']);
                $attachedRaw = (string)$row['attached_to'];
                $seedRaw = str_replace('\\', '/', (string)$row['seed_file']);
                $logicalRaw = (string)$row['logical_id'];
                $nameRaw = (string)$row['db_stylesheet_name'];
                $statusHint = '';
                if ($statusRaw === 'manual_override') {
                    $statusHint = isset($lang->af_theme_stylesheets_status_manual_override_help) ? (string)$lang->af_theme_stylesheets_status_manual_override_help : '';
                }
                $attached = self::shortCell($attachedRaw, 36);
                $seedFile = self::shortCell($seedRaw, 38);
                $logicalView = self::shortCell($logicalRaw, 26);
                $nameView = self::shortCell($nameRaw, 28);

                $primaryActions = [];
                if (empty($row['is_integrated'])) {
                    $primaryActions[] = self::renderThemeStylesheetActionForm('theme_stylesheets_integrate', $lang->af_theme_stylesheets_integrate, $addonId, true, false, $themeFilter, $themeTid, (string)$row['logical_id'], 'primary');
                }
                $primaryActions[] = self::buildThemeStylesheetEditLink($row, $lang->af_theme_stylesheets_edit_stylesheet, 'edit_stylesheet', 'primary');
                $primaryActions[] = self::buildThemeStylesheetEditLink($row, $lang->af_theme_stylesheets_edit_properties, 'stylesheet_properties', 'primary');
                $secondaryActions = [];
                $secondaryActions[] = self::renderThemeStylesheetActionForm('theme_stylesheets_set_file_mode', $lang->af_theme_stylesheets_set_file_mode, $addonId, true, false, $themeFilter, $themeTid, (string)$row['logical_id'], 'secondary');
                $secondaryActions[] = self::renderThemeStylesheetActionForm('theme_stylesheets_set_theme_mode', $lang->af_theme_stylesheets_set_theme_mode, $addonId, true, false, $themeFilter, $themeTid, (string)$row['logical_id'], 'secondary');
                $secondaryActions[] = self::renderThemeStylesheetActionForm('theme_stylesheets_sync_addon', $lang->af_theme_stylesheets_sync_addon, $addonId, true, false, $themeFilter, $themeTid, '', 'secondary');
                $secondaryActions[] = self::renderThemeStylesheetActionForm('theme_stylesheets_force_resync', $lang->af_theme_stylesheets_force_resync, $addonId, true, true, $themeFilter, $themeTid, '', 'secondary');
                $secondaryActions[] = self::renderThemeStylesheetActionForm('theme_stylesheets_rebuild_missing', $lang->af_theme_stylesheets_rebuild_missing, $addonId, true, false, $themeFilter, $themeTid, '', 'secondary');

                $table->construct_cell((string)$themeTid, ['class' => 'align_center']);
                $table->construct_cell(self::shortCell((string)($row['theme_title'] ?? ('Theme #'.$themeTid)), 24));
                $table->construct_cell(htmlspecialchars_uni($addonId));
                $table->construct_cell($logicalView);
                $table->construct_cell($nameView);
                $table->construct_cell($seedFile);
                $table->construct_cell($mode, ['class' => 'align_center']);
                $statusHtml = '<span style="color:'.$statusColor.';font-weight:600;"';
                if ($statusHint !== '') {
                    $statusHtml .= ' title="'.htmlspecialchars_uni($statusHint).'"';
                }
                $statusHtml .= '>'.htmlspecialchars_uni($statusLabel).'</span>';
                if ($statusHint !== '') {
                    $statusHtml .= '<br><span class="smalltext">'.htmlspecialchars_uni($statusHint).'</span>';
                }
                $table->construct_cell($statusHtml);
                $table->construct_cell($attached);
                $table->construct_cell(htmlspecialchars_uni($lastSync), ['class' => 'align_center']);
                $primary = implode('', $primaryActions);
                $secondary = implode('', $secondaryActions);
                $table->construct_cell('<div class="af-ts-row-actions"><div class="af-ts-row-primary">'.$primary.'</div><div class="af-ts-row-secondary">'.$secondary.'</div></div>');
                $table->construct_row();
            }
        }

        $table->output($lang->af_theme_stylesheets_title);
    }

    private static function renderThemeStylesheetFilters(string $addonFilter, string $themeScope): void
    {
        global $lang;

        $addons = [];
        foreach (self::discoverAddons() as $meta) {
            $id = (string)($meta['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $addons[$id] = (string)($meta['name'] ?? $id);
        }
        ksort($addons, SORT_STRING);

        echo '<form method="get" action="index.php" style="margin:10px 0 12px 0;">';
        echo '<input type="hidden" name="module" value="'.AF_PLUGIN_ID.'">';
        echo '<input type="hidden" name="af_view" value="theme_stylesheets">';
        echo '<label style="margin-right:8px;">'.htmlspecialchars_uni($lang->af_theme_stylesheets_filter_addon).': ';
        echo '<select name="addon">';
        echo '<option value="">'.htmlspecialchars_uni($lang->af_theme_stylesheets_filter_all_addons).'</option>';
        foreach ($addons as $id => $title) {
            $selected = ($addonFilter === $id) ? ' selected="selected"' : '';
            echo '<option value="'.htmlspecialchars_uni($id).'"'.$selected.'>'.htmlspecialchars_uni($title).' ('.htmlspecialchars_uni($id).')</option>';
        }
        echo '</select></label>';

        echo '<label style="margin-right:8px;">'.htmlspecialchars_uni($lang->af_theme_stylesheets_filter_theme_scope).': ';
        echo '<select name="theme_scope">';
        echo '<option value="all"'.($themeScope === 'all' ? ' selected="selected"' : '').'>'.htmlspecialchars_uni($lang->af_theme_stylesheets_filter_all_themes).'</option>';
        echo '<option value="current"'.($themeScope === 'current' ? ' selected="selected"' : '').'>'.htmlspecialchars_uni($lang->af_theme_stylesheets_filter_current_theme).'</option>';
        echo '</select></label>';

        echo '<input type="submit" class="submit_button" value="'.htmlspecialchars_uni($lang->af_theme_stylesheets_apply_filter).'">';
        echo '&nbsp;<a href="index.php?module='.AF_PLUGIN_ID.'&amp;af_view=theme_stylesheets&amp;theme_scope=all">'.htmlspecialchars_uni($lang->af_theme_stylesheets_clear_filter).'</a>';
        echo '</form>';
    }

    private static function buildThemeStylesheetEditLink(array $row, string $label, string $action, string $variant = 'primary'): string
    {
        $themeTid = (int)($row['theme_tid'] ?? 0);
        $file = trim((string)($row['db_stylesheet_name'] ?? ($row['stylesheet_name'] ?? '')));

        if ($themeTid <= 0 || $file === '') {
            return '<span style="color:#777;">'.htmlspecialchars_uni($label).'</span>';
        }

        $resolvedAction = 'edit_stylesheet';
        if ($action === 'stylesheet_properties') {
            $resolvedAction = 'stylesheet_properties';
        }

        $url = 'index.php?module=style-themes&amp;action='.$resolvedAction.'&amp;file='.rawurlencode($file).'&amp;tid='.$themeTid;
        if ($resolvedAction === 'edit_stylesheet') {
            $url .= '&amp;mode=advanced';
        }

        $class = 'button '.($variant === 'secondary' ? 'af-ts-btn-secondary' : 'af-ts-btn-primary');

        return '<a class="'.trim($class).'" href="'.$url.'">'.htmlspecialchars_uni($label).'</a>';
    }

    private static function renderThemeStylesheetActionForm(string $action, string $label, string $addon = '', bool $inline = false, bool $confirm = false, string $themeScope = 'all', ?int $themeTid = null, string $logicalId = '', string $variant = 'primary'): string
    {
        global $mybb;

        $html = '<form method="post" action="index.php?module='.AF_PLUGIN_ID.'" style="margin:0;'.($inline ? 'display:inline-block;' : '').'">';
        $html .= '<input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni($mybb->post_code).'">';
        $html .= '<input type="hidden" name="af_view" value="theme_stylesheets">';
        $html .= '<input type="hidden" name="theme_scope" value="'.htmlspecialchars_uni($themeScope).'">';
        $html .= '<input type="hidden" name="af_action" value="'.htmlspecialchars_uni($action).'">';
        if ($addon !== '') {
            $html .= '<input type="hidden" name="addon" value="'.htmlspecialchars_uni($addon).'">';
        }
        if ($themeTid !== null && $themeTid > 0) {
            $html .= '<input type="hidden" name="theme_tid" value="'.(int)$themeTid.'">';
        }
        if ($logicalId !== '') {
            $html .= '<input type="hidden" name="logical_id" value="'.htmlspecialchars_uni($logicalId).'">';
        }
        if ($confirm) {
            $html .= '<input type="hidden" name="confirm_force" value="1">';
        }
        $buttonClass = $inline ? 'button' : 'submit_button';
        $buttonClass .= ($variant === 'secondary') ? ' af-ts-btn-secondary' : ' af-ts-btn-primary';
        $html .= '<input type="submit" class="'.$buttonClass.'" value="'.htmlspecialchars_uni($label).'">';
        $html .= '</form>';

        return $html;
    }

    private static function shortCell(string $value, int $limit = 32): string
    {
        $value = trim($value);
        if ($value === '') {
            return '—';
        }
        if (function_exists('my_substr') && my_strlen($value) > $limit) {
            return '<span title="'.htmlspecialchars_uni($value).'">'.htmlspecialchars_uni(my_substr($value, 0, $limit)).'…</span>';
        }
        if (strlen($value) > $limit) {
            return '<span title="'.htmlspecialchars_uni($value).'">'.htmlspecialchars_uni(substr($value, 0, $limit)).'…</span>';
        }
        return '<span title="'.htmlspecialchars_uni($value).'">'.htmlspecialchars_uni($value).'</span>';
    }

    private static function currentThemeTid(): int
    {
        $theme = $GLOBALS['theme'] ?? null;
        if (is_array($theme ?? null)) {
            $tid = (int)($theme['tid'] ?? 0);
            if ($tid > 0) {
                return $tid;
            }
        }

        global $mybb;
        $tid = (int)($mybb->settings['theme'] ?? 0);
        if ($tid > 1) {
            return $tid;
        }
        global $db;
        $q = $db->simple_select('themes', 'tid', "tid > 1", ['order_by' => 'tid', 'order_dir' => 'asc', 'limit' => 1]);
        $fallback = (int)$db->fetch_field($q, 'tid');
        return $fallback > 1 ? $fallback : 1;
    }

    private static function themeStylesheetsUrl(?string $addon = null, ?int $themeTid = null, string $themeScope = 'all'): string
    {
        $url = 'index.php?module='.AF_PLUGIN_ID.'&af_view=theme_stylesheets';
        if ($addon !== null && $addon !== '') {
            $url .= '&addon='.rawurlencode($addon);
        }
        if ($themeTid !== null && $themeTid > 0) {
            $url .= '&theme_tid='.(int)$themeTid;
        }
        if (!in_array($themeScope, ['all', 'current'], true)) {
            $themeScope = 'all';
        }
        $url .= '&theme_scope='.$themeScope;
        return $url;
    }

    public static function collectAdminSections(): array
    {
        $out = [[
            'id' => 'core',
            'slug' => 'theme_stylesheets',
            'title' => 'Theme Stylesheets',
        ]];
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
                $path  = $meta['path'].($meta['admin']['controller'] ?? '');
                $klass = 'AF_Admin_'.preg_replace('~[^A-Za-z0-9]+~', '', ucfirst($slug));
                return ['path'=>$path, 'class'=>$klass];
            }
        }
        return null;
    }

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
        af_sync_theme_stylesheets(false, $id);
    }

    public static function disableAddon(string $id): void
    {
        self::ensureEnabledSetting($id, 0);
        af_rebuild_and_reload_settings();
        af_sync_theme_stylesheets(false, $id);
    }

    public static function addonBootstrap(string $id): ?string
    {
        foreach (self::discoverAddons() as $meta) {
            if (($meta['id'] ?? '') === $id) {
                return $meta['bootstrap'] ?? null;
            }
        }
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

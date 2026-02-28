<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('IN_ADMINCP')) { define('IN_ADMINCP', 1); }

class AF_Admin_Advancedshop
{
    public static function dispatch(string $action = ''): string
    {
        $html = self::render($action);
        echo $html;
        return $html;
    }

    private static function shops_table(): string
    {
        global $db;
        return $db->table_exists('af_shop_shops') ? 'af_shop_shops' : 'af_shop';
    }

    private static function selected_shop_code(): string
    {
        global $db, $mybb;

        $code = trim((string)$mybb->get_input('shop_code'));
        $shopsTable = self::shops_table();
        if ($code !== '') {
            $row = $db->fetch_array($db->simple_select($shopsTable, 'code', "code='" . $db->escape_string($code) . "'", ['limit' => 1]));
            if ($row) {
                return (string)$row['code'];
            }
        }

        $where = $shopsTable === 'af_shop_shops' ? 'enabled=1' : '1=1';
        $order = $shopsTable === 'af_shop_shops' ? 'sortorder ASC, shop_id ASC' : 'shop_id ASC';
        $fallback = $db->fetch_array($db->simple_select($shopsTable, 'code', $where, ['order_by' => $order, 'limit' => 1]));
        return (string)($fallback['code'] ?? '');
    }

    private static function resolve_shop_by_code(string $code): array
    {
        global $db;

        if ($code === '') {
            return [];
        }

        $shopsTable = self::shops_table();
        return (array)$db->fetch_array($db->simple_select($shopsTable, '*', "code='" . $db->escape_string($code) . "'", ['limit' => 1]));
    }

    public static function render(string $action = ''): string
    {
        global $db, $lang, $mybb;

        $shopsTable = self::shops_table();
        if ($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));
            $do = trim((string)$mybb->get_input('do'));

            if ($do === 'create_shop') {
                $code = preg_replace('~[^a-z0-9_\-]~', '', strtolower(trim((string)$mybb->get_input('code'))));
                $titleRu = trim((string)$mybb->get_input('title_ru'));
                $titleEn = trim((string)$mybb->get_input('title_en'));
                $bgUrl = trim((string)$mybb->get_input('bg_url'));
                $iconUrl = trim((string)$mybb->get_input('icon_url'));
                $enabled = (int)$mybb->get_input('enabled');
                $sortorder = (int)$mybb->get_input('sortorder');

                if ($code !== '') {
                    $exists = $db->fetch_array($db->simple_select($shopsTable, 'shop_id', "code='" . $db->escape_string($code) . "'", ['limit' => 1]));
                    if (!$exists) {
                        if ($shopsTable === 'af_shop_shops') {
                            $db->insert_query($shopsTable, [
                                'code' => $db->escape_string($code),
                                'title_ru' => $db->escape_string($titleRu),
                                'title_en' => $db->escape_string($titleEn),
                                'bg_url' => $db->escape_string($bgUrl),
                                'icon_url' => $db->escape_string($iconUrl),
                                'enabled' => $enabled ? 1 : 0,
                                'sortorder' => $sortorder,
                                'settings_json' => null,
                            ]);
                        } else {
                            $title = $titleRu !== '' ? $titleRu : ($titleEn !== '' ? $titleEn : $code);
                            $db->insert_query($shopsTable, [
                                'code' => $db->escape_string($code),
                                'title' => $db->escape_string($title),
                                'bg_url' => $db->escape_string($bgUrl),
                                'icon_url' => $db->escape_string($iconUrl),
                                'enabled' => $enabled ? 1 : 0,
                                'created_at' => TIME_NOW,
                                'updated_at' => TIME_NOW,
                            ]);
                        }
                    }
                }
                flash_message('Shop created.', 'success');
                admin_redirect('index.php?module=advancedfunctionality&af_view=advancedshop&tab=shops');
            }

            if ($do === 'update_shop') {
                $shopId = (int)$mybb->get_input('shop_id');
                $code = preg_replace('~[^a-z0-9_\-]~', '', strtolower(trim((string)$mybb->get_input('code'))));
                $titleRu = trim((string)$mybb->get_input('title_ru'));
                $titleEn = trim((string)$mybb->get_input('title_en'));
                $bgUrl = trim((string)$mybb->get_input('bg_url'));
                $iconUrl = trim((string)$mybb->get_input('icon_url'));
                $enabled = (int)$mybb->get_input('enabled') === 1 ? 1 : 0;
                $sortorder = (int)$mybb->get_input('sortorder');

                if ($shopId > 0 && $code !== '') {
                    $update = [
                        'code' => $db->escape_string($code),
                        'enabled' => $enabled,
                    ];
                    if ($shopsTable === 'af_shop_shops') {
                        $update['title_ru'] = $db->escape_string($titleRu);
                        $update['title_en'] = $db->escape_string($titleEn);
                        $update['bg_url'] = $db->escape_string($bgUrl);
                        $update['icon_url'] = $db->escape_string($iconUrl);
                        $update['sortorder'] = $sortorder;
                    } else {
                        $title = $titleRu !== '' ? $titleRu : ($titleEn !== '' ? $titleEn : $code);
                        $update['title'] = $db->escape_string($title);
                        $update['bg_url'] = $db->escape_string($bgUrl);
                        $update['icon_url'] = $db->escape_string($iconUrl);
                        $update['updated_at'] = TIME_NOW;
                    }
                    $db->update_query($shopsTable, $update, 'shop_id=' . $shopId);
                    flash_message('Shop updated.', 'success');
                } else {
                    flash_message('Shop update failed: code is required.', 'error');
                }
                admin_redirect('index.php?module=advancedfunctionality&af_view=advancedshop&tab=shops');
            }

            if ($do === 'create_category') {
                $shopCode = trim((string)$mybb->get_input('shop_code'));
                $shop = self::resolve_shop_by_code($shopCode);
                if (!$shop) {
                    flash_message('Shop is required for category creation.', 'error');
                    admin_redirect('index.php?module=advancedfunctionality&af_view=advancedshop&tab=categories');
                }

                $title = trim((string)$mybb->get_input('title'));
                $description = trim((string)$mybb->get_input('description'));
                $sortorder = (int)$mybb->get_input('sortorder');
                if ($title !== '') {
                    $db->insert_query('af_shop_categories', [
                        'shop_id' => (int)$shop['shop_id'],
                        'parent_id' => 0,
                        'title' => $db->escape_string($title),
                        'description' => $db->escape_string($description),
                        'sortorder' => $sortorder,
                        'enabled' => 1,
                    ]);
                    flash_message('Category created.', 'success');
                } else {
                    flash_message('Category title is required.', 'error');
                }

                admin_redirect('index.php?module=advancedfunctionality&af_view=advancedshop&tab=categories&shop_code=' . rawurlencode((string)$shop['code']));
            }
        }

        $gid = (int)$db->fetch_field($db->simple_select('settinggroups', 'gid', "name='af_advancedshop'", ['limit' => 1]), 'gid');
        $settingsUrl = 'index.php?module=config-settings' . ($gid > 0 ? '&action=change&gid=' . $gid : '');
        $activeTab = trim((string)$mybb->get_input('tab'));
        if (!in_array($activeTab, ['shops', 'categories'], true)) {
            $activeTab = 'shops';
        }

        $html = '';
        $html .= '<div class="af-box">';
        $html .= '<h2>Advanced Shop</h2>';
        $html .= '<p>' . htmlspecialchars_uni($lang->af_advancedshop_description ?? 'Game shop and inventory addon.') . '</p>';
        $html .= '<p><a class="button button-primary" href="' . htmlspecialchars_uni($settingsUrl) . '">Settings</a></p>';

        $html .= '<p>';
        $html .= '<a class="button' . ($activeTab === 'shops' ? ' button-primary' : '') . '" href="index.php?module=advancedfunctionality&amp;af_view=advancedshop&amp;tab=shops">Shops</a> ';
        $html .= '<a class="button' . ($activeTab === 'categories' ? ' button-primary' : '') . '" href="index.php?module=advancedfunctionality&amp;af_view=advancedshop&amp;tab=categories">Categories</a>';
        $html .= '</p>';

        $qShops = $db->simple_select($shopsTable, '*', '', ['order_by' => ($shopsTable === 'af_shop_shops' ? 'sortorder ASC, shop_id ASC' : 'shop_id ASC')]);
        $shops = [];
        while ($row = $db->fetch_array($qShops)) {
            $shops[] = $row;
        }

        if ($activeTab === 'shops') {
            $html .= '<h3>Shops</h3>';
            $html .= '<table class="tborder" cellpadding="6" cellspacing="1" width="100%"><tr>';
            $html .= '<th>shop_id</th><th>code</th><th>title_ru</th><th>title_en</th><th>bg_url</th><th>icon_url</th><th>enabled</th><th>sortorder</th><th>manage</th><th>save</th></tr>';
            $allowManageLink = function_exists('af_advancedshop_can_manage') ? af_advancedshop_can_manage() : true;
            foreach ($shops as $shop) {
                $html .= '<tr>';
                $html .= '<form method="post" action="index.php?module=advancedfunctionality&amp;af_view=advancedshop&amp;tab=shops">';
                $html .= '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
                $html .= '<input type="hidden" name="do" value="update_shop">';
                $html .= '<input type="hidden" name="shop_id" value="' . (int)$shop['shop_id'] . '">';
                $html .= '<td>' . (int)$shop['shop_id'] . '</td>';
                $html .= '<td><input type="text" name="code" value="' . htmlspecialchars_uni((string)$shop['code']) . '" maxlength="32" required style="width:100%"></td>';
                if ($shopsTable === 'af_shop_shops') {
                    $html .= '<td><input type="text" name="title_ru" value="' . htmlspecialchars_uni((string)$shop['title_ru']) . '" maxlength="255" style="width:100%"></td>';
                    $html .= '<td><input type="text" name="title_en" value="' . htmlspecialchars_uni((string)$shop['title_en']) . '" maxlength="255" style="width:100%"></td>';
                    $bg = (string)($shop['bg_url'] ?? '');
                    $icon = (string)($shop['icon_url'] ?? '');
                    $bgPreview = $bg !== '' ? '<div><a target="_blank" href="' . htmlspecialchars_uni($bg) . '">preview</a></div>' : '';
                    $iconPreview = $icon !== '' ? '<div><a target="_blank" href="' . htmlspecialchars_uni($icon) . '">preview</a></div>' : '';
                    $html .= '<td><input type="text" name="bg_url" value="' . htmlspecialchars_uni($bg) . '" maxlength="255" style="width:100%">' . $bgPreview . '</td>';
                    $html .= '<td><input type="text" name="icon_url" value="' . htmlspecialchars_uni($icon) . '" maxlength="255" style="width:100%">' . $iconPreview . '</td>';
                    $html .= '<td><select name="enabled"><option value="1"' . ((int)$shop['enabled'] === 1 ? ' selected' : '') . '>1</option><option value="0"' . ((int)$shop['enabled'] === 0 ? ' selected' : '') . '>0</option></select></td>';
                    $html .= '<td><input type="number" name="sortorder" value="' . (int)$shop['sortorder'] . '" style="width:90px"></td>';
                } else {
                    $title = (string)($shop['title'] ?? '');
                    $bg = (string)($shop['bg_url'] ?? '');
                    $icon = (string)($shop['icon_url'] ?? '');
                    $bgPreview = $bg !== '' ? '<div><a target="_blank" href="' . htmlspecialchars_uni($bg) . '">preview</a></div>' : '';
                    $iconPreview = $icon !== '' ? '<div><a target="_blank" href="' . htmlspecialchars_uni($icon) . '">preview</a></div>' : '';
                    $html .= '<td><input type="text" name="title_ru" value="' . htmlspecialchars_uni($title) . '" maxlength="255" style="width:100%"></td>';
                    $html .= '<td><input type="text" name="title_en" value="' . htmlspecialchars_uni($title) . '" maxlength="255" style="width:100%"></td>';
                    $html .= '<td><input type="text" name="bg_url" value="' . htmlspecialchars_uni($bg) . '" maxlength="255" style="width:100%">' . $bgPreview . '</td>';
                    $html .= '<td><input type="text" name="icon_url" value="' . htmlspecialchars_uni($icon) . '" maxlength="255" style="width:100%">' . $iconPreview . '</td>';
                    $html .= '<td><select name="enabled"><option value="1"' . ((int)$shop['enabled'] === 1 ? ' selected' : '') . '>1</option><option value="0"' . ((int)$shop['enabled'] === 0 ? ' selected' : '') . '>0</option></select></td>';
                    $html .= '<td><input type="number" name="sortorder" value="' . (int)$shop['shop_id'] . '" style="width:90px"></td>';
                }
                if ($allowManageLink) {
                    $html .= '<td><a class="button" target="_blank" href="../shop_manage.php?shop=' . rawurlencode((string)$shop['code']) . '">Manage (front)</a></td>';
                } else {
                    $html .= '<td>—</td>';
                }
                $html .= '<td><button class="button" type="submit">Save</button></td>';
                $html .= '</form>';
                $html .= '</tr>';
            }
            $html .= '</table>';

            $html .= '<h3>Create Shop</h3>';
            $html .= '<form method="post" action="index.php?module=advancedfunctionality&amp;af_view=advancedshop&amp;tab=shops">';
            $html .= '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
            $html .= '<input type="hidden" name="do" value="create_shop">';
            $html .= '<p>Code: <input type="text" name="code" maxlength="32" required> </p>';
            $html .= '<p>Title RU: <input type="text" name="title_ru" maxlength="255"> </p>';
            $html .= '<p>Title EN: <input type="text" name="title_en" maxlength="255"> </p>';
            $html .= '<p>BG URL: <input type="text" name="bg_url" maxlength="255" style="width:60%"> </p>';
            $html .= '<p>Icon URL: <input type="text" name="icon_url" maxlength="255" style="width:60%"> </p>';
            $html .= '<p>Sortorder: <input type="number" name="sortorder" value="0"> Enabled: <label><input type="checkbox" name="enabled" value="1" checked> yes</label></p>';
            $html .= '<p><button class="button button-primary" type="submit">Create</button></p>';
            $html .= '</form>';
        } else {
            $selectedCode = self::selected_shop_code();
            $selectedShop = self::resolve_shop_by_code($selectedCode);

            $html .= '<h3>Categories</h3>';
            $html .= '<form method="get" action="index.php">';
            $html .= '<input type="hidden" name="module" value="advancedfunctionality">';
            $html .= '<input type="hidden" name="af_view" value="advancedshop">';
            $html .= '<input type="hidden" name="tab" value="categories">';
            $html .= '<p>Shop: <select name="shop_code" onchange="this.form.submit()">';
            foreach ($shops as $shop) {
                $code = (string)$shop['code'];
                $titleRu = $shopsTable === 'af_shop_shops' ? (string)($shop['title_ru'] ?? '') : (string)($shop['title'] ?? '');
                $titleEn = $shopsTable === 'af_shop_shops' ? (string)($shop['title_en'] ?? '') : (string)($shop['title'] ?? '');
                $label = trim($titleRu) !== '' ? $titleRu : (trim($titleEn) !== '' ? $titleEn : $code);
                $selected = $code === $selectedCode ? ' selected' : '';
                $html .= '<option value="' . htmlspecialchars_uni($code) . '"' . $selected . '>' . htmlspecialchars_uni($code . ' — ' . $label) . '</option>';
            }
            $html .= '</select></p></form>';

            $shopId = (int)($selectedShop['shop_id'] ?? 0);
            $qCats = $db->simple_select('af_shop_categories', '*', 'shop_id=' . $shopId, ['order_by' => 'sortorder ASC, title ASC, cat_id ASC']);
            $html .= '<table class="tborder" cellpadding="6" cellspacing="1" width="100%"><tr><th>cat_id</th><th>title</th><th>description</th><th>enabled</th><th>sortorder</th></tr>';
            while ($cat = $db->fetch_array($qCats)) {
                $html .= '<tr>';
                $html .= '<td>' . (int)$cat['cat_id'] . '</td>';
                $html .= '<td>' . htmlspecialchars_uni((string)$cat['title']) . '</td>';
                $html .= '<td>' . htmlspecialchars_uni((string)$cat['description']) . '</td>';
                $html .= '<td>' . ((int)$cat['enabled'] === 1 ? '1' : '0') . '</td>';
                $html .= '<td>' . (int)$cat['sortorder'] . '</td>';
                $html .= '</tr>';
            }
            $html .= '</table>';

            $html .= '<h3>Create Category</h3>';
            $html .= '<form method="post" action="index.php?module=advancedfunctionality&amp;af_view=advancedshop&amp;tab=categories">';
            $html .= '<input type="hidden" name="my_post_key" value="' . htmlspecialchars_uni($mybb->post_code) . '">';
            $html .= '<input type="hidden" name="do" value="create_category">';
            $html .= '<p>Shop: <select name="shop_code" required>';
            $html .= '<option value="">-- select shop --</option>';
            foreach ($shops as $shop) {
                $code = (string)$shop['code'];
                $titleRu = $shopsTable === 'af_shop_shops' ? (string)($shop['title_ru'] ?? '') : (string)($shop['title'] ?? '');
                $titleEn = $shopsTable === 'af_shop_shops' ? (string)($shop['title_en'] ?? '') : (string)($shop['title'] ?? '');
                $label = trim($titleRu) !== '' ? $titleRu : (trim($titleEn) !== '' ? $titleEn : $code);
                $selected = $code === $selectedCode ? ' selected' : '';
                $html .= '<option value="' . htmlspecialchars_uni($code) . '"' . $selected . '>' . htmlspecialchars_uni($code . ' — ' . $label) . '</option>';
            }
            $html .= '</select></p>';
            $html .= '<p>Title: <input type="text" name="title" maxlength="255" required></p>';
            $html .= '<p>Description: <textarea name="description" rows="3" cols="80"></textarea></p>';
            $html .= '<p>Sortorder: <input type="number" name="sortorder" value="0"></p>';
            $html .= '<p><button class="button button-primary" type="submit">Create category</button></p>';
            $html .= '</form>';
        }

        $html .= '</div>';

        return $html;
    }
}

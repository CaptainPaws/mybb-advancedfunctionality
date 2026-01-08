<?php
/**
 * AF Addon: AdvancedGiphy
 * MyBB 1.8.38–1.8.39, PHP 8.0–8.4
 *
 * Функции:
 *  - Кнопка GIPHY в SCEditor (поиск + вставка как [img]URL[/img])
 *  - Поддержка Rin Editor (если он использует SCEditor/toolbar переменную)
 *  - Ограничение max-width для giphy-картинок в постах через CSS (настраивается в ACP)
 *
 * ВАЖНО:
 *  - Никаких правок шаблонов. Всё подключаем через pre_output_page.
 *  - API key передаётся в JS (как и в ABP Giphy). Если хочешь скрыть ключ — нужно делать серверный прокси.
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_AGIPHY_ID', 'advancedgiphy');
define('AF_AGIPHY_VER', '1.0.0');

define('AF_AGIPHY_MARK_DONE',  '<!--af_advancedgiphy_done-->');
define('AF_AGIPHY_MARK_ASSETS','<!--af_advancedgiphy_assets-->');

/* -------------------- INSTALL / UNINSTALL -------------------- */

function af_advancedgiphy_install(): bool
{
    global $db;

    // settings group
    $groupName = 'af_advancedgiphy';

    $gid = 0;
    $q = $db->simple_select('settinggroups', 'gid', "name='".$db->escape_string($groupName)."'", ['limit' => 1]);
    $row = $db->fetch_array($q);
    if (!empty($row['gid'])) {
        $gid = (int)$row['gid'];
    } else {
        $ins = [
            'name'        => $groupName,
            'title'       => 'AdvancedGiphy',
            'description' => 'GIPHY button + max-width for giphy images.',
            'disporder'   => 1,
            'isdefault'   => 0
        ];
        $db->insert_query('settinggroups', $ins);
        $gid = (int)$db->insert_id();
    }

    // helper to add setting if missing
    $addSetting = function(string $name, array $data) use ($db, $gid): void {
        $q = $db->simple_select('settings', 'sid', "name='".$db->escape_string($name)."'", ['limit' => 1]);
        $row = $db->fetch_array($q);
        if (!empty($row['sid'])) {
            return;
        }
        $data['name'] = $name;
        $data['gid']  = $gid;
        $db->insert_query('settings', $data);
    };

    // (enabled) setting создаёт ядро AF автоматически: af_advancedgiphy_enabled

    $addSetting('af_advancedgiphy_key', [
        'title'       => 'GIPHY API Key',
        'description' => 'API key from developers.giphy.com dashboard.',
        'optionscode' => 'text',
        'value'       => '',
        'disporder'   => 10,
    ]);

    $addSetting('af_advancedgiphy_limit', [
        'title'       => 'Limit',
        'description' => 'Results per request (5–100).',
        'optionscode' => 'numeric',
        'value'       => '25',
        'disporder'   => 20,
    ]);

    $addSetting('af_advancedgiphy_rating', [
        'title'       => 'Rating',
        'description' => 'g / pg / pg-13 / r',
        'optionscode' => "select\ng=G\npg=PG\npg-13=PG-13\nr=R",
        'value'       => 'g',
        'disporder'   => 30,
    ]);

    $addSetting('af_advancedgiphy_maxwidth', [
        'title'       => 'Max width (px)',
        'description' => 'Max ширина giphy-картинок в постах. 0 = без ограничения.',
        'optionscode' => 'numeric',
        'value'       => '100',
        'disporder'   => 40,
    ]);

    rebuild_settings();
    return true;
}

function af_advancedgiphy_uninstall(): bool
{
    global $db;

    $db->delete_query('settings', "name IN (
        'af_advancedgiphy_key',
        'af_advancedgiphy_limit',
        'af_advancedgiphy_rating',
        'af_advancedgiphy_maxwidth'
    )");

    $db->delete_query('settinggroups', "name='af_advancedgiphy'");

    rebuild_settings();
    return true;
}

/* -------------------- RUNTIME -------------------- */

function af_advancedgiphy_init(): void
{
    // no-op (подключение ассетов делаем в pre_output_page)
}

function af_advancedgiphy_pre_output(&$page = ''): void
{
    global $mybb;

    if (!af_advancedgiphy_is_frontend()) {
        return;
    }

    if (empty($mybb->settings['af_advancedgiphy_enabled'])) {
        return;
    }

    if (strpos($page, AF_AGIPHY_MARK_DONE) !== false) {
        return;
    }

    // ВАЖНО: ассеты аддона всегда грузим с bburl (а не asset_url/CDN)
    $bburl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    if ($bburl === '') {
        return;
    }

    $assetsBase = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_AGIPHY_ID . '/assets';

    /* 1) CSS + CSS vars (max-width) */
    $cssTag = '<link rel="stylesheet" type="text/css" href="'.$assetsBase.'/advancedgiphy.css?ver='.AF_AGIPHY_VER.'" />';
    $maxw   = (int)($mybb->settings['af_advancedgiphy_maxwidth'] ?? 100);
    $maxwCss = ($maxw <= 0) ? 'none' : ($maxw.'px');
    $varsTag = '<style id="af_advancedgiphy_vars">:root{--af-giphy-max-width:'.$maxwCss.';}</style>';

    if (stripos($page, '</head>') !== false) {
        if (strpos($page, 'advancedgiphy.css') === false) {
            $page = str_ireplace('</head>', AF_AGIPHY_MARK_ASSETS.$cssTag.$varsTag.'</head>', $page);
        } else {
            if (strpos($page, 'id="af_advancedgiphy_vars"') === false) {
                $page = str_ireplace('</head>', $varsTag.'</head>', $page);
            }
        }
    }

    /* 2) JS только если на странице есть редактор */
    if (af_advancedgiphy_page_has_editor($page)) {
        if (strpos($page, 'advancedgiphy.js') === false) {

            $cfg = [
                'key'        => (string)($mybb->settings['af_advancedgiphy_key'] ?? ''),
                'limit'      => (int)($mybb->settings['af_advancedgiphy_limit'] ?? 25),
                'rating'     => (string)($mybb->settings['af_advancedgiphy_rating'] ?? 'g'),
                'poweredImg' => $assetsBase.'/gpowered.png',
            ];

            if ($cfg['limit'] < 5) { $cfg['limit'] = 5; }
            if ($cfg['limit'] > 100) { $cfg['limit'] = 100; }

            $cfgJson = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $cfgTag  = '<script>window.afAdvancedGiphyConfig='.$cfgJson.';</script>';
            // defer помогает если у тебя часть скриптов уезжает в низ/деферится
            $jsTag   = '<script src="'.$assetsBase.'/advancedgiphy.js?ver='.AF_AGIPHY_VER.'"></script>';

            $inserted = false;

            // Лучшее место: сразу после bbcodes_sceditor.js (до инициализации редактора на ready)
            $pattern = '~(<script[^>]+bbcodes_sceditor\.js[^>]*></script>)~i';
            if (preg_match($pattern, $page)) {
                $page = preg_replace($pattern, '$1'.$cfgTag.$jsTag, $page, 1);
                $inserted = true;
            }

            // fallback: перед </head> (тоже успеет до ready почти всегда)
            if (!$inserted && stripos($page, '</head>') !== false) {
                $page = str_ireplace('</head>', $cfgTag.$jsTag.'</head>', $page);
                $inserted = true;
            }

            // fallback: перед </body>
            if (!$inserted && stripos($page, '</body>') !== false) {
                $page = str_ireplace('</body>', $cfgTag.$jsTag.'</body>', $page);
            }
        }
    }

    $page .= "\n".AF_AGIPHY_MARK_DONE;
}

function af_advancedgiphy_is_frontend(): bool
{
    // не лезем в ACP
    if (defined('IN_ADMINCP') && IN_ADMINCP) {
        return false;
    }

    // не лезем в модпанель
    if (defined('THIS_SCRIPT')) {
        $s = (string)THIS_SCRIPT;
        if ($s === 'modcp.php') {
            return false;
        }
    }

    return true;
}

/**
 * Грубая эвристика: есть ли на странице редактор.
 * Мы не патчим шаблоны, поэтому ориентируемся по наличию bbcodes_sceditor.js
 * и/или явных следов SCEditor.
 */
function af_advancedgiphy_page_has_editor(string $page): bool
{
    if (stripos($page, 'bbcodes_sceditor.js') !== false) {
        return true;
    }
    if (stripos($page, 'sceditor') !== false && stripos($page, 'toolbar') !== false) {
        return true;
    }
    // Rin Editor часто оставляет следы "rin" в путях
    if (stripos($page, '/rin/') !== false && stripos($page, 'editor') !== false) {
        return true;
    }
    return false;
}

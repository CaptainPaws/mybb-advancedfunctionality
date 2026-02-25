<?php
/**
 * AF Addon: Advanced Buddy List
 * - Переписывает содержимое шаблона misc_buddypopup на нормальную модалку с табами
 * - Добавляет игнор-лист в вывод buddypopup
 * - Подключает свои CSS/JS только для этого окна
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_ABDL_ID', 'advancedbyddylist');
define('AF_ABDL_TPL_MARK', '<!--af_abdl-->');

function af_advancedbyddylist_install(): void
{
    af_abdl_ensure_settings();
    af_abdl_patch_misc_buddypopup_template(true);
}

function af_advancedbyddylist_uninstall(): void
{
    af_abdl_unpatch_misc_buddypopup_template();
    af_abdl_remove_settings();
}

function af_advancedbyddylist_activate(): void
{
    af_abdl_ensure_settings();
    af_abdl_patch_misc_buddypopup_template(false);
}

function af_advancedbyddylist_deactivate(): void
{
    // В деактивации возвращаем стандартный шаблон, если мы его патчили
    af_abdl_unpatch_misc_buddypopup_template();
}

function af_advancedbyddylist_init(): void
{
    global $plugins;
    $plugins->add_hook('misc_start', 'af_abdl_hook_misc_start');
}


function af_abdl_is_enabled(): bool
{
    global $mybb;
    return !empty($mybb->settings['af_abdl_enabled']);
}

/**
 * Подмешиваем данные игнора + ассеты, но ТОЛЬКО для action=buddypopup&modal=1
 */
function af_abdl_hook_misc_start(): void
{
    global $mybb;

    if (!af_abdl_is_enabled()) return;
    if (THIS_SCRIPT !== 'misc.php') return;

    $action = (string)($mybb->input['action'] ?? '');
    if ($action !== 'buddypopup') return;

    $modal = (int)($mybb->input['modal'] ?? 0);
    if ($modal !== 1) return;

    // Теперь мы в том самом окне popupWindow(modal=1).
    // Дальше: готовим переменные, которые будут использоваться в misc_buddypopup (наш переписанный шаблон).
    af_abdl_prepare_popup_vars();
}

/**
 * Готовим переменные для шаблона:
 * - $af_abdl_css, $af_abdl_js
 * - $af_abdl_ignore_rows (HTML строк игнора)
 * - $af_abdl_strings (JSON строк для JS: подписи табов)
 */
function af_abdl_prepare_popup_vars(): void
{
    global $mybb, $lang;

    if ($mybb->user['uid'] <= 0) {
        // гости не должны сюда попадать нормально, но не ломаемся
        return;
    }

    // язык (AF-ядро генерит lang файл advancedfunctionality_advancedbyddylist.lang.php)
    if (function_exists('af_abdl_lang')) {
        af_abdl_lang();
    } else {
        // фоллбек: пробуем руками
        if (method_exists($lang, 'load')) {
            $lang->load('advancedfunctionality_advancedbyddylist');
        }
    }

    $bburl = rtrim((string)$mybb->settings['bburl'], '/');
    $assets = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_ABDL_ID . '/assets/';

    // Эти переменные подхватит шаблон misc_buddypopup (который мы перепишем)
    $GLOBALS['af_abdl_css'] = $assets . 'advancedbyddylist.css?v=1';
    $GLOBALS['af_abdl_js']  = $assets . 'advancedbyddylist.js?v=1';

    // Игнор: собираем строки как таблицу, в стиле buddy rows
    $GLOBALS['af_abdl_ignore_rows'] = af_abdl_build_list_rows('ignore');

    // Тексты для JS
    $strings = [
        'tab_friends'  => $lang->af_abdl_tab_friends ?? 'Friends',
        'tab_ignore'   => $lang->af_abdl_tab_ignore ?? 'Ignore',
        'online'       => $lang->af_abdl_online ?? 'Online',
        'offline'      => $lang->af_abdl_offline ?? 'Offline',
        'send_pm'      => $lang->af_abdl_send_pm ?? 'Send private message',
        'manage_lists' => $lang->af_abdl_manage_lists ?? 'Friends/Ignore list',
        'close'        => $lang->af_abdl_close ?? 'Close',
        'empty'        => $lang->af_abdl_empty ?? 'Empty.',
        'edit_url'     => $bburl . '/usercp.php?action=editlists',
    ];

    $GLOBALS['af_abdl_strings'] = json_encode($strings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * $type = 'ignore' (позже можно расширить)
 * Возвращает <tr>... строки для вставки в таблицу.
 */
function af_abdl_build_list_rows(string $type): string
{
    global $mybb, $db;

    $uid = (int)$mybb->user['uid'];
    if ($uid <= 0) return '';

    $csv = '';
    if ($type === 'ignore') {
        $csv = (string)($mybb->user['ignorelist'] ?? '');
    } else {
        $csv = (string)($mybb->user['buddylist'] ?? '');
    }

    $ids = af_abdl_parse_uid_csv($csv);
    if (!$ids) {
        return '';
    }

    $in = implode(',', array_map('intval', $ids));

    // users
    $q = $db->simple_select('users', 'uid,username,usergroup,displaygroup,avatar,avatardimensions,lastactive', "uid IN ($in)");
    $users = [];
    while ($u = $db->fetch_array($q)) {
        $users[(int)$u['uid']] = $u;
    }

    // online sessions
    $online = [];
    $cutoff = TIME_NOW - 15 * 60;
    $qs = $db->simple_select('sessions', 'uid', "uid IN ($in) AND time > " . (int)$cutoff);
    while ($s = $db->fetch_array($qs)) {
        $online[(int)$s['uid']] = true;
    }

    // порядок как в csv
    $onlineRows = '';
    $offlineRows = '';

    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id <= 0 || !isset($users[$id])) continue;

        $u = $users[$id];

        $avatar = trim((string)$u['avatar']);
        if ($avatar === '') {
            $avatar = 'images/default_avatar.png';
        }

        $profile = 'member.php?action=profile&uid=' . (int)$id;
        $sendpm  = 'private.php?action=send&uid=' . (int)$id;

        $username = htmlspecialchars_uni($u['username']);
        $rowClass = !empty($online[$id]) ? 'trow2' : 'trow1';

        $row = '';
        $row .= '<tr>';
        $row .= '  <td class="' . $rowClass . '" width="1%">';
        $row .= '    <div class="buddy_avatar float_left"><img src="' . htmlspecialchars_uni($avatar) . '" alt="" width="44" height="44" style="margin-top: 3px;"></div>';
        $row .= '  </td>';
        $row .= '  <td class="' . $rowClass . '">';
        $row .= '    <a href="' . htmlspecialchars_uni($profile) . '" target="_blank" onclick="if(window.opener){ window.opener.location = this.href; return false; }">' . $username . '</a>';
        $row .= '    <div class="buddy_action">';
        $row .= '      <span class="smalltext"><a href="' . htmlspecialchars_uni($sendpm) . '" target="_blank" onclick="if(window.opener){ window.opener.location.href=this.href; return false; }">' . htmlspecialchars_uni($GLOBALS['lang']->af_abdl_send_pm ?? 'Send private message') . '</a></span>';
        $row .= '    </div>';
        $row .= '  </td>';
        $row .= '</tr>';

        if (!empty($online[$id])) $onlineRows .= $row;
        else $offlineRows .= $row;
    }

    $out = '';
    if ($onlineRows !== '') {
        $out .= '<tr><td class="tcat" colspan="2"><strong>' . htmlspecialchars_uni($GLOBALS['lang']->af_abdl_online ?? 'Online') . '</strong></td></tr>';
        $out .= $onlineRows;
    }
    if ($offlineRows !== '') {
        $out .= '<tr><td class="tcat" colspan="2"><strong>' . htmlspecialchars_uni($GLOBALS['lang']->af_abdl_offline ?? 'Offline') . '</strong></td></tr>';
        $out .= $offlineRows;
    }

    return $out;
}

function af_abdl_parse_uid_csv(string $csv): array
{
    $csv = trim($csv);
    if ($csv === '') return [];

    $parts = preg_split('~\s*,\s*~', $csv);
    $out = [];
    foreach ($parts as $p) {
        $n = (int)$p;
        if ($n > 0) $out[$n] = $n;
    }
    return array_values($out);
}

/**
 * Настройки (минимум: enable)
 */
function af_abdl_ensure_settings(): void
{
    global $db;

    // группа
    $gid = 0;
    $q = $db->simple_select('settinggroups', 'gid', "name='af_abdl'");
    $row = $db->fetch_array($q);
    if ($row) $gid = (int)$row['gid'];

    if ($gid <= 0) {
        $disp = 1;
        $q2 = $db->simple_select('settinggroups', 'MAX(disporder) AS mx');
        $r2 = $db->fetch_array($q2);
        $disp = (int)($r2['mx'] ?? 0) + 1;

        $db->insert_query('settinggroups', [
            'name'        => 'af_abdl',
            'title'       => 'AF: Advanced Buddy List',
            'description' => 'Settings for Advanced Buddy List modal',
            'disporder'   => $disp,
            'isdefault'   => 0,
        ]);
        $gid = (int)$db->insert_id();
    }

    // setting
    $exists = $db->fetch_field($db->simple_select('settings', 'sid', "name='af_abdl_enabled'"), 'sid');
    if (!$exists) {
        $order = 1;
        $db->insert_query('settings', [
            'name'        => 'af_abdl_enabled',
            'title'       => 'Enable Advanced Buddy List',
            'description' => 'If enabled, misc_buddypopup template is replaced with improved modal (tabs + close).',
            'optionscode' => 'yesno',
            'value'       => '1',
            'disporder'   => $order,
            'gid'         => $gid,
        ]);
    }

    rebuild_settings();
}

function af_abdl_remove_settings(): void
{
    global $db;

    $db->delete_query('settings', "name='af_abdl_enabled'");
    $db->delete_query('settinggroups', "name='af_abdl'");
    rebuild_settings();
}

/**
 * Перезапись misc_buddypopup в БД.
 * - если force=true: пишем всегда
 * - иначе: пишем только если ещё не патчено
 */
function af_abdl_patch_misc_buddypopup_template(bool $force): void
{
    global $db;

    // берём master template (sid=-2 или -1) — в разных установках бывает по-разному,
    // поэтому патчим все совпадения title='misc_buddypopup' кроме пользовательских theme sets.
    $q = $db->simple_select('templates', 'tid,template', "title='misc_buddypopup'");
    while ($t = $db->fetch_array($q)) {
        $tid = (int)$t['tid'];
        $tpl = (string)$t['template'];

        if (!$force && strpos($tpl, AF_ABDL_TPL_MARK) !== false) {
            continue;
        }

        $new = af_abdl_new_misc_buddypopup_template();

        $db->update_query('templates', ['template' => $db->escape_string($new)], "tid={$tid}");
    }
}

/**
 * Возврат к простому виду (чтобы деактивация не оставляла твой форум “навсегда патченным”)
 * Это НЕ “оригинал MyBB”, это “тот всратый минимальный”, который ты прислала — но зато predictable.
 */
function af_abdl_unpatch_misc_buddypopup_template(): void
{
    global $db;

    $fallback = "<div class=\"modal\">\n\t<div style=\"overflow-y: auto; max-height: 400px;\">\n\t\t<table cellspacing=\"{\$theme['borderwidth']}\" cellpadding=\"{\$theme['tablespace']}\" class=\"tborder\">\n\t\t<tr>\n\t\t\t<td class=\"thead\"{\$colspan}>\n\t\t\t\t<div><strong>{\$lang->buddy_list}</strong></div>\n\t\t\t</td>\n\t\t</tr>\n\t\t{\$buddies}\n\t\t</table>\n\t</div>\n</div>";

    $q = $db->simple_select('templates', 'tid,template', "title='misc_buddypopup'");
    while ($t = $db->fetch_array($q)) {
        $tid = (int)$t['tid'];
        $tpl = (string)$t['template'];

        if (strpos($tpl, AF_ABDL_TPL_MARK) === false) {
            continue;
        }

        $db->update_query('templates', ['template' => $db->escape_string($fallback)], "tid={$tid}");
    }
}

/**модалка**/
function af_abdl_new_misc_buddypopup_template(): string
{
    return AF_ABDL_TPL_MARK . <<<HTML
<div class="modal af-abdl-modal" id="af_abdl_modal" aria-hidden="false">
  <div class="af-abdl-modal-backdrop" data-af-abdl-close="1" aria-hidden="true"></div>

  <div class="af-abdl-modal-panel" role="dialog" aria-modal="true" aria-labelledby="af_abdl_title">
    <button type="button"
            class="af-abdl-modal-close"
            data-af-abdl-close="1"
            aria-label="{\$lang->af_abdl_close}"
            title="{\$lang->af_abdl_close}">×</button>

    <table cellspacing="{\$theme['borderwidth']}" cellpadding="{\$theme['tablespace']}" class="tborder af-abdl-modal-table">
      <tr>
        <td class="thead" colspan="2">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
            <strong id="af_abdl_title">{\$lang->buddy_list}</strong>
          </div>
        </td>
      </tr>

      <tr>
        <td class="tcat" colspan="2">
          <div class="af-abdl-tabs">
            <a href="#" class="button af-abdl-tab is-active" data-tab="friends">{\$lang->af_abdl_tab_friends}</a>
            <a href="#" class="button af-abdl-tab" data-tab="ignore">{\$lang->af_abdl_tab_ignore}</a>
          </div>
        </td>
      </tr>

      <tbody class="af-abdl-pane" data-pane="friends">
        {\$buddies}
      </tbody>

      <tbody class="af-abdl-pane" data-pane="ignore" style="display:none;">
        {\$af_abdl_ignore_rows}
      </tbody>

      <tr>
        <td class="tfoot" colspan="2" style="text-align:right;">
          <a href="usercp.php?action=editlists" target="_blank" rel="noopener">{\$lang->af_abdl_manage_lists}</a>
        </td>
      </tr>
    </table>
  </div>
</div>
HTML;
}


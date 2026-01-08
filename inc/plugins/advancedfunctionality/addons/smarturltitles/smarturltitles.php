<?php
/**
 * AF Addon: Smart URL Titles
 * MyBB 1.8.38–1.8.39, PHP 8.0–8.4
 *
 * Идея/вдохновение: URL Titles (doylecc, GPL). Логика адаптирована под экосистему AF.
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_SUT_ID', 'smarturltitles');

define('AF_SUT_GROUP', 'af_smarturltitles');
define('AF_SUT_SETTING_ENABLED',      'af_smarturltitles_enabled');
define('AF_SUT_SETTING_TITLE_LENGTH', 'af_sut_title_length');
define('AF_SUT_SETTING_URL_COUNT',    'af_sut_url_count');
define('AF_SUT_SETTING_TIMEOUT',      'af_sut_timeout');
define('AF_SUT_SETTING_RANGE',        'af_sut_range');

global $plugins;
if (isset($plugins) && is_object($plugins)) {
    // вставка в отправку (до записи)
    $plugins->add_hook('newreply_do_newreply_start', 'af_sut_insert_post');
    $plugins->add_hook('newreply_start',             'af_sut_insert_post');
    $plugins->add_hook('newthread_do_newthread_start','af_sut_insert_post');
    $plugins->add_hook('newthread_start',            'af_sut_insert_post');
    $plugins->add_hook('editpost_do_editpost_start', 'af_sut_insert_post');
    $plugins->add_hook('editpost_start',             'af_sut_insert_post');
    $plugins->add_hook('private_send_do_send',       'af_sut_insert_post');
    $plugins->add_hook('private_send_start',         'af_sut_insert_post');

    // превью/формы
    $plugins->add_hook('newreply_end',  'af_sut_preview_message');
    $plugins->add_hook('newthread_end', 'af_sut_preview_message');
    $plugins->add_hook('editpost_end',  'af_sut_preview_message');
    $plugins->add_hook('private_end',   'af_sut_preview_message');

    // quick edit / xmlhttp
    $plugins->add_hook('xmlhttp', 'af_sut_xmlhttp');
}

/* -------------------- AF lifecycle -------------------- */

function af_smarturltitles_install(): bool
{
    global $db;

    // settings group
    $gid = 0;
    $q = $db->simple_select('settinggroups', 'gid', "name='". $db->escape_string(AF_SUT_GROUP) ."'");
    if ($db->num_rows($q)) {
        $gid = (int)$db->fetch_field($q, 'gid');
    } else {
        $gid = (int)$db->insert_query('settinggroups', [
            'name'        => AF_SUT_GROUP,
            'title'       => 'Smart URL Titles',
            'description' => 'Подстановка заголовков страниц для ссылок без имени.',
            'disporder'   => 1,
            'isdefault'   => 0,
        ]);
    }

    // helper: upsert setting
    $upsert = function(string $name, array $row) use ($db, $gid): void {
        $q = $db->simple_select('settings', 'sid', "name='". $db->escape_string($name) ."'");
        if ($db->num_rows($q)) {
            $sid = (int)$db->fetch_field($q, 'sid');
            $row['gid'] = $gid;
            $db->update_query('settings', $row, "sid={$sid}");
        } else {
            $row['name'] = $name;
            $row['gid']  = $gid;
            $db->insert_query('settings', $row);
        }
    };

    // enabled (на случай, если ядро AF не создаёт авто-настройку — не мешает даже если создаёт)
    $upsert(AF_SUT_SETTING_ENABLED, [
        'title'       => 'Включить Smart URL Titles',
        'description' => 'Если выключено — аддон ничего не делает.',
        'optionscode' => 'yesno',
        'value'       => '1',
        'disporder'   => 1,
    ]);

    $upsert(AF_SUT_SETTING_TITLE_LENGTH, [
        'title'       => 'Максимальная длина заголовка',
        'description' => 'Обрезать заголовки длиннее указанного числа символов. 0 = без ограничений.',
        'optionscode' => 'text',
        'value'       => '150',
        'disporder'   => 2,
    ]);

    $upsert(AF_SUT_SETTING_URL_COUNT, [
        'title'       => 'Максимум ссылок на пост',
        'description' => 'Сколько ссылок максимум обрабатывать в одном сообщении. 0 = без ограничений.',
        'optionscode' => 'text',
        'value'       => '10',
        'disporder'   => 3,
    ]);

    $upsert(AF_SUT_SETTING_TIMEOUT, [
        'title'       => 'Таймаут запроса (сек)',
        'description' => 'Сколько ждать ответа сайта при попытке получить заголовок.',
        'optionscode' => "select\n1=1\n2=2\n3=3\n4=4\n5=5\n6=6\n7=7\n8=8\n9=9\n10=10",
        'value'       => '4',
        'disporder'   => 4,
    ]);

    $upsert(AF_SUT_SETTING_RANGE, [
        'title'       => 'Лимит скачиваемых данных (байт)',
        'description' => 'Сколько максимум данных читать с сайта, чтобы вытащить заголовок.',
        'optionscode' => 'text',
        'value'       => '500000',
        'disporder'   => 5,
    ]);

    rebuild_settings();
    af_sut_install_templates();

    return true;
}

function af_smarturltitles_uninstall(): bool
{
    global $db;

    // удаляем настройки
    $db->delete_query('settings', "name IN ('"
        .$db->escape_string(AF_SUT_SETTING_ENABLED)."','"
        .$db->escape_string(AF_SUT_SETTING_TITLE_LENGTH)."','"
        .$db->escape_string(AF_SUT_SETTING_URL_COUNT)."','"
        .$db->escape_string(AF_SUT_SETTING_TIMEOUT)."','"
        .$db->escape_string(AF_SUT_SETTING_RANGE)."')"
    );

    // удаляем группу
    $db->delete_query('settinggroups', "name='".$db->escape_string(AF_SUT_GROUP)."'");

    // удаляем шаблоны
    $db->delete_query('templates', "title LIKE 'af_sut_%'");

    rebuild_settings();
    return true;
}

function af_smarturltitles_activate(): bool
{
    return true;
}

function af_smarturltitles_deactivate(): bool
{
    return true;
}

function af_smarturltitles_init(): void
{
    // no-op
}

function af_smarturltitles_pre_output(&$page): void
{
    // no-op
}

/* -------------------- hooks -------------------- */

function af_sut_enabled(): bool
{
    global $mybb;
    return !empty($mybb->settings[AF_SUT_SETTING_ENABLED]);
}

function af_sut_insert_post(): void
{
    global $mybb, $message;

    if (!af_sut_enabled()) {
        return;
    }

    $raw = '';
    if (isset($message) && is_string($message)) {
        $raw = $message;
    } elseif (isset($mybb->input['message'])) {
        $raw = (string)$mybb->input['message'];
    }

    if ($raw === '') {
        return;
    }

    $new = af_sut_process_message($raw);
    if ($new !== '' && $new !== $raw) {
        $mybb->input['message'] = $new;
        $message = $new;
    }
}

function af_sut_preview_message(): void
{
    global $message;
    if (!af_sut_enabled() || !isset($message) || !is_string($message) || $message === '') {
        return;
    }

    $new = af_sut_process_message($message);
    if ($new !== '' && $new !== $message) {
        $message = $new;
    }
}

function af_sut_xmlhttp(): void
{
    global $mybb;

    if (!af_sut_enabled()) {
        return;
    }

    // inline quick edit posts
    if (!empty($mybb->input['do']) && $mybb->input['do'] === 'update_post' && isset($mybb->input['value'])) {
        $raw = (string)$mybb->input['value'];
        if ($raw === '') {
            return;
        }
        $new = af_sut_process_message($raw);
        if ($new !== '' && $new !== $raw) {
            $mybb->input['value'] = $new;
        }
    }
}

/* -------------------- core logic -------------------- */
function af_sut_process_message(string $message): string
{
    global $mybb, $data_string, $af_sut_last_content_type;

    // badwords как в MyBB
    if (file_exists(MYBB_ROOT.'inc/class_parser.php')) {
        require_once MYBB_ROOT.'inc/class_parser.php';
        $parser = new postParser();
        $message = $parser->parse_badwords($message);
    }

    // === Ключевой фикс: прячем медиа/код блоки на всё время обработки ===
    $store = [];
    $work = af_sut_protect_bbcode_blocks($message, $store);

    // превращаем “голые” URL в [url]...[/url]
    $work = af_sut_auto_url($work);

    // только ссылки без имени
    preg_match_all('/\[url\](.*)\[\/url\]/iU', $work, $links);
    $list = $links[1] ?? [];
    if (empty($list)) {
        return af_sut_restore_bbcode_blocks($work, $store);
    }

    $max_count = (int)($mybb->settings[AF_SUT_SETTING_URL_COUNT] ?? 10);
    $title_len = (int)($mybb->settings[AF_SUT_SETTING_TITLE_LENGTH] ?? 150);
    if ($title_len < 0) { $title_len = 0; }

    // невидимые форумы
    $not_allowed_threads = [];
    $not_allowed_forums  = [];
    if (function_exists('get_unviewable_forums')) {
        $hidden_threads = get_unviewable_forums(true);
        $hidden_forums  = get_unviewable_forums();
        $not_allowed_threads = array_filter(array_map('trim', explode(',', (string)$hidden_threads)));
        $not_allowed_forums  = array_filter(array_map('trim', explode(',', (string)$hidden_forums)));
    }

    $url_counter = 0;

    foreach ($list as $url) {
        $url = trim((string)$url);
        if ($url === '') {
            continue;
        }

        if ($max_count > 0 && ++$url_counter > $max_count) {
            break;
        }

        $site  = $url;
        $title = '';
        $data_string = '';
        $af_sut_last_content_type = '';

        // внутренние ссылки: thread
        if (my_strpos($site, $mybb->settings['bburl'].'/showthread.php') !== false
            || my_strpos($site, $mybb->settings['bburl'].'/thread-') !== false
        ) {
            preg_match("/(tid=|thread-)([\d]+)/i", $site, $m);
            $tid = (int)($m[2] ?? 0);
            if ($tid > 0 && function_exists('get_thread')) {
                $thread = get_thread($tid);
                if (!empty($thread['fid']) && !in_array((string)$thread['fid'], $not_allowed_threads, true)) {
                    $title = (string)$thread['subject'];
                }
            }
        }

        // внутренние ссылки: forumdisplay
        if ($title === '' && (my_strpos($site, $mybb->settings['bburl'].'/forumdisplay.php') !== false
            || my_strpos($site, $mybb->settings['bburl'].'/forum-') !== false)
        ) {
            preg_match("/(fid=|forum-)([\d]+)/i", $site, $m);
            $fid = (int)($m[2] ?? 0);
            if ($fid > 0 && function_exists('get_forum')) {
                $forum = get_forum($fid);
                if (!empty($forum['fid']) && !in_array((string)$forum['fid'], $not_allowed_forums, true)) {
                    $title = (string)$forum['name'];
                }
            }
        }

        // внутренние ссылки: профиль
        if ($title === '' && (my_strpos($site, $mybb->settings['bburl'].'/member.php') !== false
            || my_strpos($site, $mybb->settings['bburl'].'/user-') !== false)
        ) {
            preg_match("/(uid=|user-)([\d]+)/i", $site, $m);
            $uid = (int)($m[2] ?? 0);
            if ($uid > 0 && function_exists('get_user')) {
                $u = get_user($uid);
                if (!empty($u['uid'])) {
                    $title = (string)$u['username'];
                }
            }
        }

        // внешние ссылки (и вообще любые не-наши страницы)
        if ($title === '' && my_strpos($site, $mybb->settings['bburl']) === false) {
            $data_string = (string)@af_sut_fetch_title($site);

            if ($data_string !== '') {
                $title_og   = af_sut_match('/property=\"og:title\" content=\"(.*)\"/iU', $data_string);
                $title_desc = af_sut_match('/property=\"og:description\" content=\"(.*)\"/iU', $data_string);
                $title_html = af_sut_match('/<title>(.*)<\/title>/isU', $data_string);

                if ($title_og !== '' && my_strpos($title_og, 'Twitter') === false && my_strpos($title_html, 'YouTube') === false) {
                    $title = strip_tags($title_og);
                    if ($title_desc !== '') {
                        $title .= ' - '.strip_tags($title_desc);
                    }
                } else {
                    $title = strip_tags($title_html);
                }
            }

            if ($title === '') {
                $title = $site;
            }
        }

        // --- charset detection (важно!) ---
        $charset = '';

        // 1) из Content-Type
        if (!empty($af_sut_last_content_type) && preg_match('~charset\s*=\s*([a-zA-Z0-9\-_]+)~i', $af_sut_last_content_type, $mm)) {
            $charset = (string)$mm[1];
        }

        // 2) из meta (включая без кавычек)
        if ($charset === '' && $data_string !== '') {
            if (preg_match('~<meta[^>]+http-equiv\s*=\s*["\']?content-type["\']?[^>]+content\s*=\s*["\'][^"\']*charset\s*=\s*([a-zA-Z0-9\-_]+)~i', $data_string, $mm)) {
                $charset = (string)$mm[1];
            } elseif (preg_match('~<meta[^>]+charset\s*=\s*["\']?([a-zA-Z0-9\-_]+)~i', $data_string, $mm)) {
                $charset = (string)$mm[1];
            }
        }

        $charset = trim($charset);
        $charset = str_replace(['"', "'", ';'], '', $charset);
        $charset = strtolower($charset);

        // нормализация названий
        if ($charset === 'utf8') $charset = 'utf-8';
        if ($charset === 'win-1251') $charset = 'windows-1251';

        // --- convert to UTF-8 BEFORE any cleanup ---
        $n = (string)$title;

        $is_external = (my_strpos($site, $mybb->settings['bburl']) === false);

        if ($is_external) {
            $is_utf8 = (bool)preg_match('//u', $n);

            if ($charset === '' && !$is_utf8) {
                $try = $n;
                if (function_exists('iconv')) {
                    $try = (string)@iconv('windows-1251', 'UTF-8//IGNORE', $n);
                } elseif (function_exists('mb_convert_encoding')) {
                    $try = (string)@mb_convert_encoding($n, 'UTF-8', 'Windows-1251');
                }

                if ($try !== '' && preg_match('/\p{Cyrillic}/u', $try)) {
                    $n = $try;
                    $charset = 'windows-1251';
                }
            } elseif ($charset !== '' && $charset !== 'utf-8') {
                if (function_exists('iconv')) {
                    $n = (string)@iconv($charset, 'UTF-8//IGNORE', $n);
                } elseif (function_exists('mb_convert_encoding')) {
                    $n = (string)@mb_convert_encoding($n, 'UTF-8', $charset);
                }
            }
        }

        // декодируем HTML entities уже в UTF-8
        $n = html_entity_decode($n, ENT_QUOTES | (defined('ENT_HTML5') ? ENT_HTML5 : 0), 'UTF-8');
        $n = strip_tags($n);
        $n = str_replace(["\r\n", "\r", "\n"], ' ', $n);
        $n = preg_replace('/\s{2,}/', ' ', $n);
        $n = trim($n);

        if ($n === '' || in_array($n, ['301 Moved Permanently','302 Moved','Redirect','403 Forbidden'], true)) {
            continue;
        }

        // защита от поломки BBCode
        $n = str_replace(['[', ']'], ['&#91;', '&#93;'], $n);

        // обрезка длины
        if ($title_len > 0) {
            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($n, 'UTF-8') > $title_len) {
                    $n = mb_substr($n, 0, max(0, $title_len - 3), 'UTF-8').'...';
                }
            } else {
                if (strlen($n) > $title_len) {
                    $n = substr($n, 0, max(0, $title_len - 3)).'...';
                }
            }
        }

        // заменяем конкретно этот URL один раз
        $replaced = false;

        $pattern1 = '!\[url\]\s*' . preg_quote($url, '!') . '\s*\[/url\]!i';
        $new = preg_replace($pattern1, '[url='.$url.']'.$n.'[/url]', $work, 1);
        if (is_string($new) && $new !== $work) {
            $work = $new;
            $replaced = true;
        }

        // вариант с &amp;
        if (!$replaced && strpos($url, '&') !== false) {
            $url_amp = str_replace('&', '&amp;', $url);
            $pattern2 = '!\[url\]\s*' . preg_quote($url_amp, '!') . '\s*\[/url\]!i';
            $new2 = preg_replace($pattern2, '[url='.$url_amp.']'.$n.'[/url]', $work, 1);
            if (is_string($new2) && $new2 !== $work) {
                $work = $new2;
                $replaced = true;
            }
        }
    }

    // возвращаем медиа/код блоки
    $work = af_sut_restore_bbcode_blocks($work, $store);

    return $work;
}

function af_sut_match(string $pattern, string $subject): string
{
    $m = [];
    if (@preg_match($pattern, $subject, $m) && isset($m[1])) {
        return (string)$m[1];
    }
    return '';
}

function af_sut_protect_bbcode_blocks(string $message, array &$store): string
{
    // Защищаем контент внутри тегов, где URL должен остаться "чистым"
    // чтобы не ломать [img], [video], [media] и т.д.
    $pattern = '#\[(img|video|media|youtube|vimeo|dailymotion|tiktok|instagram|audio|code|php)(?:=[^\]]+)?\].*?\[/\1\]#si';

    $message = preg_replace_callback($pattern, function($m) use (&$store) {
        $key = '%%AF_SUT_PROTECT_' . count($store) . '%%';
        $store[$key] = $m[0];
        return $key;
    }, $message);

    return $message;
}

function af_sut_restore_bbcode_blocks(string $message, array $store): string
{
    if (empty($store)) {
        return $message;
    }
    return strtr($message, $store);
}

function af_sut_is_media_url(string $url): bool
{
    $u = trim($url);
    if ($u === '') {
        return false;
    }

    // отрезаем query/fragment
    $u = preg_split('~[?#]~', $u, 2)[0] ?? $u;

    $path = (string)(@parse_url($u, PHP_URL_PATH) ?? '');
    $path = strtolower($path);

    // если пути нет — не медиа
    if ($path === '') {
        return false;
    }

    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === '') {
        return false;
    }

    // картинка/видео/аудио — НЕ ЗАВОРАЧИВАЕМ В [url]
    $media_ext = [
        'png','jpg','jpeg','gif','webp','bmp','svg','avif',
        'mp4','webm','mov','m4v','mkv',
        'mp3','ogg','wav','flac',
    ];

    return in_array($ext, $media_ext, true);
}

/* -------------------- auto-url -------------------- */
function af_sut_auto_url(string $message): string
{
    // 1) прячем медиа/код блоки, чтобы не трогать URL внутри них
    $store = [];
    $message = af_sut_protect_bbcode_blocks($message, $store);

    // 2) автоссылки: ищем URL
    $url_pattern = "~(?<prefix>\\b)(?<link>(?:https?://|ftp://|www\\.)[^\\s\\[\\]<>\"']+)~i";
    $message = preg_replace_callback($url_pattern, 'af_sut_auto_url_callback', $message);

    // 3) возвращаем медиа/код блоки обратно
    $message = af_sut_restore_bbcode_blocks($message, $store);

    return $message;
}

function af_sut_auto_url_callback(array $matches): string
{
    if (count($matches) < 3) {
        return $matches[0] ?? '';
    }

    $external = '';
    $link = $matches['link'];

    // разрешаем ) в конце, но следим за балансом скобок
    while (my_substr($link, -1) === ')') {
        if (substr_count($link, ')') > substr_count($link, '(')) {
            $link = my_substr($link, 0, -1);
            $external = ')'.$external;
        } else {
            break;
        }
    }

    // убираем финальные знаки пунктуации
    $last_char = my_substr($link, -1);
    while ($last_char === '.' || $last_char === ',' || $last_char === '?' || $last_char === '!') {
        $link = my_substr($link, 0, -1);
        $external = $last_char.$external;
        $last_char = my_substr($link, -1);
    }

    $url = $matches['prefix'].$link;

    // если уже внутри BBCode url — не трогаем
    if (stripos($matches[0], '[url') !== false) {
        return $matches[0];
    }

    // КРИТИЧНО: не трогаем прямые медиа-файлы (gif/png/mp4/etc)
    // иначе потом люди начинают получать "ссылка вместо гифки"
    if (af_sut_is_media_url($url)) {
        return $matches[0];
    }

    // заворачиваем любой обычный URL (вне [img]/[media] блоков они уже защищены)
    return "[url]".$url."[/url]".$external;
}


function af_sut_extract_thread_id(string $url): int
{
    global $mybb;

    $u = trim($url);
    if ($u === '') {
        return 0;
    }

    $bburl = (string)($mybb->settings['bburl'] ?? '');
    $bburl = rtrim($bburl, '/');

    // поддержка относительных ссылок вида /showthread.php?tid=123
    if ($bburl !== '' && my_substr($u, 0, 1) === '/') {
        $u = $bburl . $u;
    }

    // быстрые проверки без parse_url (на случай кривых URL)
    $is_internal = ($bburl !== '' && my_strpos($u, $bburl) !== false);
    if (!$is_internal) {
        // если это www. без схемы, parse_url может быть капризным — но нам всё равно нужен только наш домен
        return 0;
    }

    // showthread.php?tid=123
    if (my_strpos($u, '/showthread.php') !== false) {
        if (preg_match('~[?&]tid=(\d+)~i', $u, $m)) {
            return (int)$m[1];
        }
    }

    // SEO thread-123 или thread-123.html
    if (my_strpos($u, '/thread-') !== false) {
        if (preg_match('~thread-(\d+)~i', $u, $m)) {
            return (int)$m[1];
        }
    }

    return 0;
}

function af_sut_clean_title(string $title, int $title_len = 150): string
{
    $n = (string)$title;

    // чистим
    $n = html_entity_decode($n, ENT_QUOTES | (defined('ENT_HTML5') ? ENT_HTML5 : 0), 'UTF-8');
    $n = strip_tags($n);
    $n = str_replace(["\r\n", "\r", "\n"], ' ', $n);
    $n = preg_replace('/\s{2,}/', ' ', $n);
    $n = trim($n);

    if ($n === '' || in_array($n, ['301 Moved Permanently','302 Moved','Redirect','403 Forbidden'], true)) {
        return '';
    }

    // защита от поломки BBCode
    $n = str_replace(['[', ']'], ['&#91;', '&#93;'], $n);

    // обрезка длины (по символам, не по байтам)
    if ($title_len > 0) {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($n, 'UTF-8') > $title_len) {
                $n = mb_substr($n, 0, max(0, $title_len - 3), 'UTF-8').'...';
            }
        } else {
            if (strlen($n) > $title_len) {
                $n = substr($n, 0, max(0, $title_len - 3)).'...';
            }
        }
    }

    return $n;
}


/* -------------------- cURL fetch -------------------- */

global $af_sut_data_string;
$af_sut_data_string = '';

function af_sut_write_function($handle, $data)
{
    global $mybb, $af_sut_data_string;

    $range = (int)($mybb->settings[AF_SUT_SETTING_RANGE] ?? 500000);
    if ($range < 10) {
        $range = 10;
    }

    $af_sut_data_string .= $data;

    if (strlen($af_sut_data_string) > $range) {
        return 0; // стопаем чтение
    }

    return strlen($data);
}

function af_sut_header_function($ch, $headerLine)
{
    global $af_sut_last_content_type;

    // Сохраняем Content-Type (последний встреченный, на случай редиректов)
    if (stripos($headerLine, 'Content-Type:') === 0) {
        $af_sut_last_content_type = trim(substr($headerLine, strlen('Content-Type:')));
    }

    return strlen($headerLine);
}

function af_sut_fetch_title(string $url, int $max_redirects = 20)
{
    global $mybb, $config, $af_sut_data_string, $af_sut_last_content_type;

    if (!function_exists('curl_init')) {
        return false;
    }

    // timeout
    $to = (int)($mybb->settings[AF_SUT_SETTING_TIMEOUT] ?? 4);
    if ($to < 1) { $to = 1; }
    if ($to > 15) { $to = 15; }

    $url_components = @parse_url($url);
    if (empty($url_components['host'])) {
        return false;
    }
    if (empty($url_components['scheme'])) {
        $url = 'http://'.$url;
        $url_components = @parse_url($url);
        if (empty($url_components['host'])) {
            return false;
        }
    }
    if (empty($url_components['port'])) {
        $url_components['port'] = (strtolower((string)$url_components['scheme']) === 'https') ? 443 : 80;
    }

    // SSRF защита
    if (!function_exists('get_ip_by_hostname') || !function_exists('fetch_ip_range') || !function_exists('my_inet_pton')) {
        return false;
    }

    $addresses = get_ip_by_hostname($url_components['host']);
    if (empty($addresses[0])) {
        return false;
    }
    $destination_address = $addresses[0];

    if (!empty($config['disallowed_remote_addresses']) && is_array($config['disallowed_remote_addresses'])) {
        foreach ($config['disallowed_remote_addresses'] as $disallowed_address) {
            $ip_range = fetch_ip_range($disallowed_address);
            $packed_address = my_inet_pton($destination_address);

            if (is_array($ip_range) && isset($ip_range[0], $ip_range[1]) && $packed_address !== false) {
                if ($packed_address >= $ip_range[0] && $packed_address <= $ip_range[1]) {
                    return false;
                }
            }
        }
    }

    $ch = curl_init();
    $af_sut_data_string = '';
    $af_sut_last_content_type = '';

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, 'af_sut_write_function');
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, 'af_sut_header_function');
    curl_setopt($ch, CURLOPT_TIMEOUT, $to);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36');

    if (function_exists('get_ca_bundle_path') && ($ca_bundle_path = get_ca_bundle_path())) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_CAINFO, $ca_bundle_path);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    }

    // DNS pinning
    $curl_version_info = curl_version();
    $curl_version = $curl_version_info['version'] ?? '0';

    if (version_compare(PHP_VERSION, '7.0.7', '>=') && version_compare($curl_version, '7.49', '>=')) {
        curl_setopt($ch, 10243, [
            $url_components['host'].':'.$url_components['port'].':'.$destination_address
        ]);
    } elseif (version_compare(PHP_VERSION, '5.5', '>=') && version_compare($curl_version, '7.21.3', '>=')) {
        curl_setopt($ch, 10203, [
            $url_components['host'].':'.$url_components['port'].':'.$destination_address
        ]);
    }

    $response = curl_exec($ch);
    if ($response === false || $response === null) {
        curl_close($ch);
        return false;
    }

    $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirect_url = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);

    curl_close($ch);

    // отделяем заголовки/тело (на первом блоке)
    $parts = preg_split("/\r\n\r\n/", (string)$response, 2);
    $header = $parts[0] ?? '';
    $body   = $parts[1] ?? '';

    // ручной редирект
    if (($http_code === 301 || $http_code === 302) && $max_redirects > 0) {
        $next = '';
        if ($redirect_url !== '') {
            $next = $redirect_url;
        } else {
            if (preg_match('/\nLocation:\s*(.+?)\s*(\n|$)/i', $header, $m)) {
                $next = trim((string)$m[1]);
            }
        }

        if ($next !== '') {
            return af_sut_fetch_title($next, $max_redirects - 1);
        }
    }

    return $body;
}

/* -------------------- templates install -------------------- */

function af_sut_install_templates(): void
{
    global $db;

    $file = AF_ADDONS . AF_SUT_ID . '/templates/smarturltitles.html';
    if (!file_exists($file)) {
        return;
    }

    $src = file_get_contents($file);
    if ($src === false || trim($src) === '') {
        return;
    }

    // формат: <!-- TEMPLATE: name --> ...html...
    preg_match_all('/<!--\s*TEMPLATE:\s*([a-zA-Z0-9_\-]+)\s*-->\s*(.*?)(?=(<!--\s*TEMPLATE:)|\z)/s', $src, $m, PREG_SET_ORDER);
    if (empty($m)) {
        return;
    }

    // все sets: sid=-1 (global) + все sid из templatesets
    $sids = [-1];
    $q = $db->simple_select('templatesets', 'sid');
    while ($row = $db->fetch_array($q)) {
        $sids[] = (int)$row['sid'];
    }
    $sids = array_values(array_unique($sids));

    foreach ($m as $chunk) {
        $name = trim((string)$chunk[1]);
        $html = rtrim((string)$chunk[2]);

        if ($name === '' || $html === '') {
            continue;
        }

        $title = 'af_sut_'.$name;

        foreach ($sids as $sid) {
            $qq = $db->simple_select('templates', 'tid', "title='".$db->escape_string($title)."' AND sid=".(int)$sid);
            if ($db->num_rows($qq)) {
                $tid = (int)$db->fetch_field($qq, 'tid');
                $db->update_query('templates', [
                    'template' => $html,
                    'version'  => '1800',
                    'dateline' => TIME_NOW,
                ], "tid=".$tid);
            } else {
                $db->insert_query('templates', [
                    'title'    => $title,
                    'template' => $html,
                    'sid'      => (int)$sid,
                    'version'  => '1800',
                    'dateline' => TIME_NOW,
                ]);
            }
        }
    }
}

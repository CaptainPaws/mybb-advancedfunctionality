<?php
/**
 * Advanced Mentions — внутренний аддон AF
 * MyBB 1.8.38–1.8.39, PHP 8.0–8.4
 */

if (!defined('IN_MYBB')) {
    die('No direct access');
}

/**
 * УСТАНОВКА
 */
function af_advancedmentions_install(): void
{
    global $db, $mybb;

    // === группа настроек ===
    $query = $db->simple_select('settinggroups', 'gid', "name='af_advancedmentions'");
    $group = $db->fetch_array($query);

    if (!$group) {
        $gid = (int)$db->insert_query('settinggroups', [
            'name'        => 'af_advancedmentions',
            'title'       => 'AF: Advanced Mentions',
            'description' => 'Настройки упоминаний пользователей (@username) во фронтенде.',
            'disporder'   => 50,
            'isdefault'   => 0,
        ]);
    } else {
        $gid = (int)$group['gid'];
    }

    $add_setting = static function (string $name, string $title, string $description, string $optionscode, string $value, int $disporder) use ($db, $gid): void {
        $query = $db->simple_select('settings', 'sid', "name='".$db->escape_string($name)."'");
        $existing = $db->fetch_array($query);
        if ($existing) {
            return;
        }

        $db->insert_query('settings', [
            'name'        => $name,
            'title'       => $db->escape_string($title),
            'description' => $db->escape_string($description),
            'optionscode' => $optionscode,
            'value'       => $db->escape_string($value),
            'disporder'   => $disporder,
            'gid'         => $gid,
        ]);
    };

    $add_setting(
        'af_advancedmentions_enabled',
        'Включить Advanced Mentions',
        'Если включено, пользователи смогут упоминать друг друга по @username.',
        'onoff',
        '1',
        1
    );

    $add_setting(
        'af_advancedmentions_click_insert',
        'Клик по нику вставляет упоминание',
        'Если включено, клик по нику в постбите вставляет @"username" в форму ответа вместо перехода в профиль.',
        'onoff',
        '1',
        2
    );

    $add_setting(
        'af_advancedmentions_suggest_min',
        'Минимум символов для подсказок',
        'Сколько символов после @ нужно ввести, чтобы показать список пользователей (по умолчанию 2).',
        'text',
        '2',
        3
    );

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }

    // Зарегистрировать тип алерта в Advanced Alerts, если он уже есть
    af_advancedmentions_register_alert_type_if_possible();

    // Подключение JS/CSS через headerinclude (по аналогии с Advanced Alerts)
    af_advancedmentions_add_assets_to_headerinclude();
}

/**
 * УСТАНОВЛЕН ЛИ
 */
function af_advancedmentions_is_installed(): bool
{
    global $db;
    $query = $db->simple_select('settinggroups', 'gid', "name='af_advancedmentions'");
    $group = $db->fetch_array($query);

    return (bool)$group;
}

/**
 * УДАЛЕНИЕ
 */
function af_advancedmentions_uninstall(): void
{
    global $db;

    $query = $db->simple_select('settinggroups', 'gid', "name='af_advancedmentions'");
    $group = $db->fetch_array($query);
    if ($group) {
        $gid = (int)$group['gid'];
        $db->delete_query('settings', "gid=".$gid);
        $db->delete_query('settinggroups', "gid=".$gid);
    }

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }

    if (function_exists('af_advancedalerts_unregister_type')) {
        af_advancedalerts_unregister_type('mention');
    }

    // Чистим блок подключения ассетов из headerinclude
    af_advancedmentions_remove_assets_from_headerinclude();
}

/**
 * Фронт это, а не админка/модпанель
 */
function af_advancedmentions_is_frontend(): bool
{
    if (defined('IN_ADMINCP') || defined('IN_MODCP')) {
        return false;
    }
    return true;
}

/**
 * Регистрация типа алерта "mention" в Advanced Alerts
 */
function af_advancedmentions_register_alert_type_if_possible(): void
{
    static $done = false;

    if ($done) {
        return;
    }

    if (!function_exists('af_advancedalerts_register_type')) {
        return;
    }

    $done = true;

    af_advancedalerts_register_type('mention', [
        'title'       => 'Упоминание пользователя',
        'description' => 'Уведомление, когда вас упоминают в сообщении через @username или @"Имя".',
    ]);
}

/**
 * INIT — вызывает ядро AF из global_start
 */
function af_advancedmentions_init(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }

    $booted = true;

    global $mybb, $plugins;

    if (!af_advancedmentions_is_frontend()) {
        return;
    }

    if (empty($mybb->settings['af_advancedmentions_enabled'])) {
        return;
    }

    // На всякий случай регистрируем тип алерта, если Advanced Alerts включили позже
    af_advancedmentions_register_alert_type_if_possible();

    $plugins->add_hook('postbit', 'af_advancedmentions_postbit');
    $plugins->add_hook('postbit_prev', 'af_advancedmentions_postbit');
    $plugins->add_hook('postbit_announcement', 'af_advancedmentions_postbit');
    $plugins->add_hook('pre_output_page', 'af_advancedmentions_pre_output');

    $plugins->add_hook('misc_start', 'af_advancedmentions_misc');

    $plugins->add_hook('datahandler_post_insert_post', 'af_advancedmentions_on_post_insert');
    $plugins->add_hook('datahandler_post_insert_thread', 'af_advancedmentions_on_post_insert');
}

/**
 * pre_output_page — гарантированно НЕ перетираем чужие вставки и возвращаем $page.
 * Сигнатура унифицирована под AF: по ссылке + возвращаем строку.
 */
function af_advancedmentions_pre_output(&$page = '')
{
    global $mybb;

    // Гигиена входа
    if ($page === null) { $page = ''; }
    if (!is_string($page)) { $page = (string)$page; }
    if ($page === '') { return $page; }

    // Только фронт
    if (defined('IN_ADMINCP') || defined('IN_MODCP')) {
        return $page;
    }

    if (empty($mybb->settings['af_advancedmentions_enabled'])) {
        return $page;
    }

    $bburl       = rtrim($mybb->settings['bburl'], '/');
    $js_url      = $bburl.'/inc/plugins/advancedfunctionality/addons/advancedmentions/advancedmentions.js';
    $css_url     = $bburl.'/inc/plugins/advancedfunctionality/addons/advancedmentions/advancedmentions.css';
    $suggest_url = $bburl.'/misc.php?action=af_mention_suggest';

    $click_insert = (!empty($mybb->settings['af_advancedmentions_click_insert']) && (int)$mybb->settings['af_advancedmentions_click_insert'] === 1) ? 'true' : 'false';
    $min_chars    = (int)($mybb->settings['af_advancedmentions_suggest_min'] ?? 2);
    if ($min_chars <= 0) { $min_chars = 2; }

    $inject = '';

    // Аккуратно проверяем наличие ассетов — не дублируем
    if (strpos($page, 'advancedmentions.js') === false) {
        $inject .= '<script type="text/javascript" src="'.htmlspecialchars_uni($js_url).'"></script>'."\n";
    }
    if (strpos($page, 'advancedmentions.css') === false) {
        $inject .= '<link type="text/css" rel="stylesheet" href="'.htmlspecialchars_uni($css_url).'" />'."\n";
    }
    if (strpos($page, 'afAdvancedMentionsConfig') === false) {
        $inject .= "<script type=\"text/javascript\">
window.afAdvancedMentionsConfig = {
    suggestUrl: '".htmlspecialchars_uni($suggest_url)."',
    clickInsert: {$click_insert},
    minChars: {$min_chars}
};
</script>\n";
    }

    if ($inject !== '') {
        if (stripos($page, '</head>') !== false) {
            // Вставляем перед </head>, не трогая тело с чужими вставками (например, <!--af_fastnews-->)
            $page = preg_replace('~</head>~i', $inject.'</head>', $page, 1);
        } else if (stripos($page, '<body') !== false) {
            // Фолбэк: сразу после <body ...>
            $page = preg_replace('~<body([^>]*)>~i', '<body$1>'."\n".$inject, $page, 1);
        } else {
            // Совсем уж фолбэк: в начало, но это редкость
            $page = $inject.$page;
        }
    }

    return $page;
}


/**
 * Вставка блока JS/CSS в шаблон headerinclude (после {$stylesheets})
 * по образцу Advanced Alerts.
 */
function af_advancedmentions_add_assets_to_headerinclude(): void
{
    global $db, $mybb;

    $bburl = rtrim($mybb->settings['bburl'], '/');

    $block = "\n<!--AF_ADVANCEDMENTIONS_ASSETS_START-->\n"
        ."<script type=\"text/javascript\" src=\"{$bburl}/inc/plugins/advancedfunctionality/addons/advancedmentions/advancedmentions.js\"></script>\n"
        ."<link type=\"text/css\" rel=\"stylesheet\" href=\"{$bburl}/inc/plugins/advancedfunctionality/addons/advancedmentions/advancedmentions.css\" />\n"
        ."<!--AF_ADVANCEDMENTIONS_ASSETS_END-->\n";

    $query = $db->simple_select('templates', 'tid,template', "title='headerinclude'");
    while ($tpl = $db->fetch_array($query)) {
        $tid      = (int)$tpl['tid'];
        $template = $tpl['template'];

        // Уже есть — ничего не делаем
        if (strpos($template, 'AF_ADVANCEDMENTIONS_ASSETS_START') !== false) {
            continue;
        }

        $needle = '{$stylesheets}';
        $pos    = strpos($template, $needle);

        if ($pos !== false) {
            $before   = substr($template, 0, $pos + strlen($needle));
            $after    = substr($template, $pos + strlen($needle));
            $template = $before.$block.$after;
        } else {
            $template .= $block;
        }

        $db->update_query('templates', [
            'template' => $db->escape_string($template),
        ], "tid={$tid}");
    }
}

/**
 * Удаление блока JS/CSS из headerinclude
 */
function af_advancedmentions_remove_assets_from_headerinclude(): void
{
    global $db;

    $query = $db->simple_select('templates', 'tid,template', "title='headerinclude'");
    while ($tpl = $db->fetch_array($query)) {
        $tid      = (int)$tpl['tid'];
        $template = $tpl['template'];

        if (strpos($template, 'AF_ADVANCEDMENTIONS_ASSETS_START') === false) {
            continue;
        }

        $template = preg_replace(
            '#\s*<!--AF_ADVANCEDMENTIONS_ASSETS_START-->.*?<!--AF_ADVANCEDMENTIONS_ASSETS_END-->\s*#s',
            "\n",
            $template
        );

        $db->update_query('templates', [
            'template' => $db->escape_string($template),
        ], "tid={$tid}");
    }
}

/**
 * AJAX-подсказки @username
 * URL: misc.php?action=af_mention_suggest&query=...
 *
 * Логика поиска приближена к memberlist:
 *  - поиск по подстроке в нике (LIKE %term%);
 *  - без учёта регистра;
 *  - ограничение по количеству результатов.
 */
function af_advancedmentions_misc(): void
{
    global $mybb, $db;

    if ($mybb->get_input('action') !== 'af_mention_suggest') {
        return;
    }

    // Всегда JSON
    header('Content-Type: application/json; charset=utf-8');

    // Только для залогиненных
    if (empty($mybb->user['uid'])) {
        echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
        exit;
    }

    $query_raw = trim($mybb->get_input('query'));

    $min_chars = (int)($mybb->settings['af_advancedmentions_suggest_min'] ?? 2);
    if ($min_chars <= 0) {
        $min_chars = 2;
    }

    // Слишком короткий запрос — ничего не ищем
    if ($query_raw === '' || mb_strlen($query_raw, 'UTF-8') < $min_chars) {
        echo json_encode(['ok' => true, 'results' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Нужны форматирование ника и свои str-функции
    require_once MYBB_ROOT.'inc/functions_user.php';
    require_once MYBB_ROOT.'inc/functions.php';

    // Приводим к нижнему регистру, как это делает memberlist
    $term_l = my_strtolower($query_raw);
    $like   = $db->escape_string_like($term_l);
    $pattern = "%{$like}%";

    $limit = 10;

    // Поиск как в memberlist: по подстроке, без регистра
    // LOWER(username) для надёжного case-insensitive сопоставления.
    $sql = "
        SELECT uid, username, usergroup, displaygroup
        FROM ".TABLE_PREFIX."users
        WHERE LOWER(username) LIKE '".$db->escape_string($pattern)."'
        ORDER BY username
        LIMIT {$limit}
    ";

    $res = $db->query($sql);

    $results = [];
    while ($row = $db->fetch_array($res)) {
        $formatted = format_name($row['username'], (int)$row['usergroup'], (int)$row['displaygroup']);
        $results[] = [
            'uid'       => (int)$row['uid'],
            'username'  => $row['username'],
            'display'   => $row['username'],
            'formatted' => $formatted,
        ];
    }

    echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}


/**
 * postbit-хук — парсим текст и добавляем кнопку "Упомянуть"
 */
function af_advancedmentions_postbit(array &$post): void
{
    global $mybb;

    // 1) парсинг @упоминаний в тексте поста
    if (!empty($post['message'])) {
        $post['message'] = af_advancedmentions_parse_message((string)$post['message']);
    }

    // 2) кнопка "Упомянуть" — после "Оценить" (button_rep), если есть
    if (!empty($mybb->user['uid']) && !empty($post['username'])) {
        $username_attr = htmlspecialchars_uni($post['username']);
        $title_attr    = htmlspecialchars_uni($post['username']);

        $button_html = '<a href="#" class="af-mention-button" data-username="'.$username_attr.'" title="Упомянуть '.$title_attr.'">'
            .'<span class="af-mention-at">@</span>'
            .'<span class="af-mention-text">Упомянуть</span>'
            .'</a>';

        if (isset($post['button_rep']) && $post['button_rep'] !== '') {
            $post['button_rep'] .= ' '.$button_html;
        } elseif (isset($post['button_pm']) && $post['button_pm'] !== '') {
            $post['button_pm'] .= ' '.$button_html;
        } elseif (isset($post['author_buttons'])) {
            $post['author_buttons'] .= ' '.$button_html;
        } else {
            if (empty($post['af_mention_button'])) {
                $post['af_mention_button'] = '';
            }
            $post['af_mention_button'] .= $button_html;
        }
    }
}

/**
 * Парсинг текста поста — превращаем @"Имя" и @username в ссылки на профиль
 */
function af_advancedmentions_parse_message(string $message): string
{
    global $db, $mybb;

    if ($message === '') {
        return $message;
    }

    // защитим емейлы
    $emailRegex = "#\\b[^@[\"|'|`]][A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,4}\\b#i";
    preg_match_all($emailRegex, $message, $emails, PREG_SET_ORDER);
    $message = preg_replace($emailRegex, "<af-email>\n", $message);

    $names = [];

    // 1) @"Имя Фамилия"
    if (preg_match_all('~@"([^"\r\n]{1,60})"~u', $message, $m1)) {
        foreach ($m1[1] as $raw) {
            $name = trim($raw);
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name, 'UTF-8');
            $names[$key] = $name;
        }
    }

    // 2) @Имя или @Имя Фамилия (без кавычек, с пробелами)
    if (preg_match_all('~@([^\r\n]{1,60})~u', $message, $m3)) {
        foreach ($m3[1] as $rawChunk) {
            $chunk = trim($rawChunk);
            if ($chunk === '') {
                continue;
            }

            $rawTokens = preg_split('~\s+~u', $chunk);
            if (!is_array($rawTokens)) {
                continue;
            }

            $accClean = '';
            foreach ($rawTokens as $rawToken) {
                // чистим токен от явной пунктуации по краям
                $cleanToken = trim($rawToken, " \t,:;!?()<>\"'");
                if ($cleanToken === '') {
                    continue;
                }

                $accClean = ($accClean === '') ? $cleanToken : $accClean.' '.$cleanToken;
                $key      = mb_strtolower($accClean, 'UTF-8');

                if (!isset($names[$key])) {
                    $names[$key] = $accClean;
                }
            }
        }
    }

    if (empty($names)) {
        foreach ($emails as $email) {
            $message = preg_replace("#\<af-email>\n?#", $email[0], $message, 1);
        }
        return $message;
    }

    // запросим пользователей по всем кандидатам
    $escaped = [];
    foreach ($names as $original) {
        $escaped[] = "'".$db->escape_string($original)."'";
    }

    $map = [];
    if (!empty($escaped)) {
        $sql = "
            SELECT uid, username
            FROM ".TABLE_PREFIX."users
            WHERE username IN (".implode(',', $escaped).")
        ";
        $res = $db->query($sql);
        while ($row = $db->fetch_array($res)) {
            $key = mb_strtolower($row['username'], 'UTF-8');
            $map[$key] = [
                'uid'      => (int)$row['uid'],
                'username' => $row['username'],
            ];
        }
    }

    if (empty($map)) {
        foreach ($emails as $email) {
            $message = preg_replace("#\<af-email>\n?#", $email[0], $message, 1);
        }
        return $message;
    }

    $bburl = rtrim($mybb->settings['bburl'], '/');

    // @"Имя" — оставляем поддержку
    $message = preg_replace_callback(
        '~@"([^"\r\n]{1,60})"~u',
        static function (array $m) use ($map, $bburl): string {
            $name = trim($m[1]);
            if ($name === '') {
                return $m[0];
            }
            $key = mb_strtolower($name, 'UTF-8');
            if (!isset($map[$key])) {
                return $m[0];
            }

            $uid   = $map[$key]['uid'];
            $href  = htmlspecialchars_uni($bburl.'/member.php?action=profile&uid='.$uid);
            $label = htmlspecialchars_uni('@'.$name);

            return '<a href="'.$href.'" class="af_mention">'.$label.'</a>';
        },
        $message
    );


    // 2) @Имя / @Имя Фамилия / @Имя Фамилия что-то
    $message = preg_replace_callback(
        '~@([^\r\n]{1,60})~u',
        static function (array $m) use ($map, $bburl): string {
            $chunk = trim($m[1]);
            if ($chunk === '') {
                return $m[0];
            }

            $rawTokens = preg_split('~\s+~u', $chunk);
            if (!is_array($rawTokens) || !$rawTokens) {
                return $m[0];
            }

            $accClean   = '';
            $bestKey    = null;
            $bestLabel  = null;
            $usedTokens = 0;

            foreach ($rawTokens as $i => $rawToken) {
                $cleanToken = trim($rawToken, " \t,:;!?()<>\"'");
                if ($cleanToken === '') {
                    continue;
                }

                $accClean = ($accClean === '') ? $cleanToken : $accClean.' '.$cleanToken;
                $key      = mb_strtolower($accClean, 'UTF-8');

                if (isset($map[$key])) {
                    $bestKey    = $key;
                    $bestLabel  = $accClean; // как в БД (по сути — имя без пунктуации)
                    $usedTokens = $i + 1;
                }
            }

            if ($bestLabel === null || $bestKey === null) {
                return $m[0];
            }

            $uid  = $map[$bestKey]['uid'];
            $href = htmlspecialchars_uni($bburl.'/member.php?action=profile&uid='.$uid);
            $labelText = '@'.$bestLabel;
            $label = htmlspecialchars_uni($labelText);

            // собираем хвост (оставшиеся слова после ника)
            $suffix = '';
            if ($usedTokens < count($rawTokens)) {
                $suffix = ' '.implode(' ', array_slice($rawTokens, $usedTokens));
            }

            return '<a href="'.$href.'" class="af_mention">'.$label.'</a>'.$suffix;
        },
        $message
    );

    // вернём емейлы
    foreach ($emails as $email) {
        $message = preg_replace("#\<af-email>\n?#", $email[0], $message, 1);
    }

    return $message;
}


/**
 * Пост вставлен — шлём алерты
 */
function af_advancedmentions_on_post_insert($datahandler): void
{
    global $db;

    if (!is_object($datahandler) || empty($datahandler->data['message'])) {
        return;
    }

    $message  = (string)$datahandler->data['message'];
    $from_uid = (int)($datahandler->data['uid'] ?? 0);
    $pid      = (int)($datahandler->pid ?? 0);
    $tid      = (int)($datahandler->data['tid'] ?? 0);

    if ($from_uid <= 0 || ($pid <= 0 && $tid <= 0)) {
        return;
    }

    // защитим емейлы
    $emailRegex = "#\\b[^@[\"|'|`]][A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,4}\\b#i";
    $message = preg_replace($emailRegex, "<af-email>\n", $message);

    $sentKey = $pid > 0 ? 'pid:'.$pid : ($tid > 0 ? 'tid:'.$tid : null);

    if (function_exists('afaa_collect_mentions') && function_exists('af_advancedalerts_add')) {
        $buckets = afaa_collect_mentions($message, $from_uid);

        foreach ($buckets['mention'] as $uid) {
            af_advancedalerts_add('mention', (int)$uid, [
                'from_uid' => $from_uid,
                'pid'      => $pid,
                'tid'      => $tid,
            ]);
        }

        foreach ($buckets['group_mention'] as $uid) {
            af_advancedalerts_add('group_mention', (int)$uid, [
                'from_uid' => $from_uid,
                'pid'      => $pid,
                'tid'      => $tid,
            ]);
        }

        if ($sentKey !== null) {
            $GLOBALS['afaa_mentions_sent'][$sentKey] = true;
        }

        return;
    }

    // Fallback: простые @username без групп/@all, если по какой-то причине AF Alerts недоступен
    $names = [];

    if (preg_match_all('~@"([^"\r\n]{1,60})"~u', $message, $m1)) {
        foreach ($m1[1] as $raw) {
            $name = trim($raw);
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name, 'UTF-8');
            $names[$key] = $name;
        }
    }

    if (preg_match_all('~@([^\r\n]{1,60})~u', $message, $m3)) {
        foreach ($m3[1] as $rawChunk) {
            $chunk = trim($rawChunk);
            if ($chunk === '') {
                continue;
            }

            $rawTokens = preg_split('~\s+~u', $chunk);
            if (!is_array($rawTokens)) {
                continue;
            }

            $accClean = '';
            foreach ($rawTokens as $rawToken) {
                $cleanToken = trim($rawToken, " \t,:;!?()<>\"'");
                if ($cleanToken === '') {
                    continue;
                }

                $accClean = ($accClean === '') ? $cleanToken : $accClean.' '.$cleanToken;
                $key      = mb_strtolower($accClean, 'UTF-8');

                if (!isset($names[$key])) {
                    $names[$key] = $accClean;
                }
            }
        }
    }

    if (empty($names)) {
        return;
    }

    $escaped = [];
    foreach ($names as $original) {
        $escaped[] = "'".$db->escape_string($original)."'";
    }

    $uids = [];
    if (!empty($escaped)) {
        $sql = "
            SELECT uid, username
            FROM ".TABLE_PREFIX."users
            WHERE username IN (".implode(',', $escaped).")
        ";
        $res = $db->query($sql);
        while ($row = $db->fetch_array($res)) {
            $uid = (int)$row['uid'];
            if ($uid <= 0 || $uid === $from_uid) {
                continue;
            }
            $uids[$uid] = $uid;
        }
    }

    if (empty($uids)) {
        return;
    }

    af_advancedmentions_notify_users(array_values($uids), [
        'from_uid' => $from_uid,
        'pid'      => $pid,
        'tid'      => $tid,
    ]);
}

/**
 * Отправка алертов через Advanced Alerts
 */
function af_advancedmentions_notify_users(array $uids, array $context): void
{
    $from_uid = (int)($context['from_uid'] ?? 0);
    $pid      = (int)($context['pid'] ?? 0);
    $tid      = (int)($context['tid'] ?? 0);

    if (empty($uids) || $from_uid <= 0 || $pid <= 0) {
        return;
    }

    if (!function_exists('af_advancedalerts_add')) {
        return;
    }

    foreach ($uids as $uid) {
        $uid = (int)$uid;
        if ($uid <= 0 || $uid === $from_uid) {
            continue;
        }

        af_advancedalerts_add('mention', $uid, [
            'from_uid' => $from_uid,
            'pid'      => $pid,
            'tid'      => $tid,
        ]);
    }
}

// Подстраховка: гарантируем регистрацию хуков даже если ядро AF не дернуло init
if (defined('IN_MYBB')) {
    af_advancedmentions_init();
}

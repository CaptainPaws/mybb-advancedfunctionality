<?php

if (!defined('IN_MYBB')) {
    die('No direct access');
}
if (!defined('AF_ADDONS')) {
    die('AdvancedFunctionality core required');
}

define('AF_APUI_ID', 'advancedprofileui');
define('AF_APUI_BASE', AF_ADDONS . AF_APUI_ID . '/');
define('AF_APUI_TEMPLATES_DIR', AF_APUI_BASE . 'templates/');
define('AF_APUI_ASSETS_DIR', AF_APUI_BASE . 'assets/');
define('AF_APUI_BACKUP_TABLE_NAME', 'af_apui_template_backups');
define('AF_APUI_BACKUP_TABLE', TABLE_PREFIX . AF_APUI_BACKUP_TABLE_NAME);
define('AF_APUI_MARKER_PROFILE_START', '<!-- AF_APUI member_profile START -->');
define('AF_APUI_MARKER_POSTBIT_START', '<!-- AF_APUI postbit_classic START -->');
define('AF_APUI_ASSET_MARK', '<!--af_apui_assets-->');

function af_advancedprofileui_install(): void
{
    global $db;

    af_apui_ensure_schema();
    af_apui_ensure_settings();
    af_apui_apply_overrides();

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_advancedprofileui_activate(): void
{
    af_apui_ensure_schema();
    af_apui_apply_overrides();
}

function af_advancedprofileui_deactivate(): void
{
    af_apui_restore_overrides();
}

function af_advancedprofileui_uninstall(): void
{
    global $db;

    af_apui_restore_overrides();

    if ($db->table_exists(AF_APUI_BACKUP_TABLE_NAME)) {
        $db->drop_table(AF_APUI_BACKUP_TABLE_NAME);
    }

    $db->delete_query('settings', "name LIKE 'af_" . AF_APUI_ID . "_%'");
    $db->delete_query('settinggroups', "name='af_" . AF_APUI_ID . "'");

    if (function_exists('rebuild_settings')) {
        rebuild_settings();
    }
}

function af_apui_is_enabled(): bool
{
    global $mybb;

    return !empty($mybb->settings['af_' . AF_APUI_ID . '_enabled']);
}

function af_apui_ensure_settings(): void
{
    if (!function_exists('af_ensure_settinggroup') || !function_exists('af_ensure_setting')) {
        return;
    }

    af_ensure_settinggroup(
        'af_' . AF_APUI_ID,
        'AdvancedProfileUI',
        'Каркас кастомного UI профиля и postbit_classic с безопасной подменой шаблонов.'
    );

    af_ensure_setting(
        'af_' . AF_APUI_ID,
        'af_' . AF_APUI_ID . '_enabled',
        'Включить AdvancedProfileUI',
        'Включает подмену шаблонов member_profile и postbit_classic и подключение ассетов.',
        'yesno',
        '1',
        1
    );

    af_ensure_setting(
        'af_' . AF_APUI_ID,
        'af_' . AF_APUI_ID . '_member_profile_body_cover_url',
        'member_profile: фон body (большое изображение)',
        'URL большого фонового изображения для body на странице профиля.',
        'text',
        'https://warprift.ru/uploads/af_gallery/1/2026/03/06994bcde9e4d4c7919130bb88049916.webp',
        2
    );

    af_ensure_setting(
        'af_' . AF_APUI_ID,
        'af_' . AF_APUI_ID . '_member_profile_body_tile_url',
        'member_profile: фон body (бесшовная плитка)',
        'URL маленького бесшовного изображения для tiled-фона body.',
        'text',
        '',
        3
    );

    af_ensure_setting(
        'af_' . AF_APUI_ID,
        'af_' . AF_APUI_ID . '_member_profile_body_bg_mode',
        'member_profile: режим фона body',
        'cover или tile.',
        'text',
        'cover',
        4
    );

    af_ensure_setting(
        'af_' . AF_APUI_ID,
        'af_' . AF_APUI_ID . '_member_profile_body_overlay',
        'member_profile: оверлей фона body',
        'CSS background-image слой для оверлея body.',
        'text',
        'none',
        5
    );

    af_ensure_setting(
        'af_' . AF_APUI_ID,
        'af_' . AF_APUI_ID . '_profile_banner_url',
        'member_profile: баннер по умолчанию',
        'URL картинки баннера для member_profile.',
        'text',
        'https://warprift.ru/uploads/af_gallery/1/2026/03/f861bca4f289f989560bd8a641d261fc.webp',
        6
    );

    af_ensure_setting(
        'af_' . AF_APUI_ID,
        'af_' . AF_APUI_ID . '_profile_banner_overlay',
        'member_profile: оверлей баннера',
        'CSS background-image слой для оверлея баннера.',
        'text',
        'linear-gradient(180deg, rgba(8, 12, 24, 0.06) 0%, rgba(8, 12, 24, 0.30) 42%, rgba(8, 12, 24, 0.82) 100%)',
        7
    );

    af_ensure_setting(
        'af_' . AF_APUI_ID,
        'af_' . AF_APUI_ID . '_member_profile_css',
        'member_profile: пользовательский CSS',
        'Дополнительный CSS для member_profile.',
        'textarea',
        '',
        8
    );

    af_ensure_setting(
        'af_' . AF_APUI_ID,
        'af_' . AF_APUI_ID . '_postbit_author_bg_url',
        'postbit_classic: фон профиля по умолчанию',
        'URL фоновой картинки авторского блока postbit.',
        'text',
        'https://warprift.ru/uploads/af_gallery/1/2026/03/e7a9f325d3838aad3e94e3e498f81edd.webp',
        20
    );

    af_ensure_setting(
        'af_' . AF_APUI_ID,
        'af_' . AF_APUI_ID . '_postbit_author_overlay',
        'postbit_classic: оверлей фона профиля',
        'CSS background-image слой для оверлея авторского блока postbit.',
        'text',
        'linear-gradient(180deg, rgba(8, 12, 24, 0.06) 0%, rgba(9, 9, 9, 0.87) 42%, rgb(0, 0, 0) 100%)',
        21
    );

    af_ensure_setting(
        'af_' . AF_APUI_ID,
        'af_' . AF_APUI_ID . '_postbit_name_bg_url',
        'postbit_classic: фон никнейма по умолчанию',
        'URL фоновой картинки для блока никнейма.',
        'text',
        'https://warprift.ru/uploads/af_gallery/1/2026/03/acb421937b268acaa4a999feac5aedc9.webp',
        22
    );

    af_ensure_setting(
        'af_' . AF_APUI_ID,
        'af_' . AF_APUI_ID . '_postbit_name_overlay',
        'postbit_classic: оверлей никнейма',
        'CSS background-image слой для оверлея блока никнейма.',
        'text',
        'linear-gradient(180deg, rgba(0, 0, 0, 0.24), rgba(69, 69, 69, 0.28))',
        23
    );

    af_ensure_setting(
        'af_' . AF_APUI_ID,
        'af_' . AF_APUI_ID . '_postbit_plaque_bg_url',
        'postbit_classic: фон кнопки листа персонажа',
        'URL фоновой картинки для кнопки/плашки листа персонажа.',
        'text',
        'https://warprift.ru/uploads/af_gallery/1/2026/03/844fae67d94b689f6e827278722ec152.webp',
        24
    );

    af_ensure_setting(
        'af_' . AF_APUI_ID,
        'af_' . AF_APUI_ID . '_postbit_plaque_overlay',
        'postbit_classic: оверлей кнопки листа персонажа',
        'CSS background-image слой для оверлея кнопки/плашки листа персонажа.',
        'text',
        'linear-gradient(180deg, rgba(10, 14, 24, 0.17), rgba(0, 0, 0, 0.85))',
        25
    );

    af_ensure_setting(
        'af_' . AF_APUI_ID,
        'af_' . AF_APUI_ID . '_postbit_css',
        'postbit_classic: пользовательский CSS',
        'Дополнительный CSS для postbit_classic.',
        'textarea',
        '',
        26
    );
}

function af_apui_ensure_schema(): void
{
    global $db;

    if ($db->table_exists(AF_APUI_BACKUP_TABLE_NAME)) {
        return;
    }

    $charset = method_exists($db, 'build_create_table_collation')
        ? $db->build_create_table_collation()
        : 'ENGINE=InnoDB';

    $db->write_query(
        "CREATE TABLE IF NOT EXISTS " . AF_APUI_BACKUP_TABLE . " (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_tid INT UNSIGNED NOT NULL,
            title VARCHAR(120) NOT NULL,
            sid SMALLINT NOT NULL,
            original_template MEDIUMTEXT NOT NULL,
            original_dateline BIGINT UNSIGNED NOT NULL DEFAULT 0,
            marker VARCHAR(64) NOT NULL DEFAULT 'af_apui',
            checksum CHAR(40) NOT NULL,
            created_at BIGINT UNSIGNED NOT NULL,
            updated_at BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_template_tid (template_tid),
            KEY idx_title_sid (title, sid)
        ) " . $charset
    );
}

function af_apui_register_hooks(): void
{
    global $plugins;

    if (!af_apui_is_enabled()) {
        return;
    }

    $plugins->add_hook('member_profile_end', 'af_apui_member_profile_end', 5);

    // ВАЖНО: ставим поздний приоритет, чтобы другие плагины успели
    // дописать user_details до того, как APUI начнет его разбирать.
    $plugins->add_hook('postbit', 'af_apui_postbit_compose_userdetails', 100);
    $plugins->add_hook('postbit_prev', 'af_apui_postbit_compose_userdetails', 100);
    $plugins->add_hook('postbit_pm', 'af_apui_postbit_compose_userdetails', 100);

    $plugins->add_hook('pre_output_page', 'af_apui_pre_output_page', 10);
    $plugins->add_hook('global_start', 'af_apui_global_start', 10);
}
af_apui_register_hooks();



function af_apui_postbit_extract_number(string $value): string
{
    $clean = trim(preg_replace('~\s+~u', ' ', strip_tags($value)));
    if ($clean === '') {
        return '0';
    }

    if (preg_match('~[+\-]?\d[\d\s.,]*~u', $clean, $m)) {
        return trim((string)$m[0]);
    }

    return htmlspecialchars_uni($clean);
}

function af_apui_dom_outer_html($node): string
{
    if (!is_object($node)) {
        return '';
    }

    $doc = $node->ownerDocument ?? null;
    if (!is_object($doc) || !method_exists($doc, 'saveHTML')) {
        return '';
    }

    return (string)$doc->saveHTML($node);
}

function af_apui_postbit_extract_profile_fields(string $userDetails): string
{
    $userDetails = trim($userDetails);
    if ($userDetails === '') {
        return '';
    }

    // Сначала нормальная попытка через DOM, чтобы не ломаться на вложенных span/div
    if (class_exists('DOMDocument') && class_exists('DOMXPath')) {
        $prevUseErrors = libxml_use_internal_errors(true);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $html = '<?xml encoding="utf-8" ?><div id="af-apui-root">' . $userDetails . '</div>';

        $loaded = $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        if ($loaded) {
            $xpath = new DOMXPath($dom);
            $nodes = $xpath->query('//*[@class and contains(concat(" ", normalize-space(@class), " "), " af-apf-postbit-field ")]');

            if ($nodes !== false && $nodes->length > 0) {
                $out = [];
                foreach ($nodes as $node) {
                    $chunk = trim(af_apui_dom_outer_html($node));
                    if ($chunk !== '') {
                        $out[] = $chunk;
                    }
                }

                libxml_clear_errors();
                libxml_use_internal_errors($prevUseErrors);

                return implode("\n", $out);
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($prevUseErrors);
    }

    // Фолбэк: если DOM не сработал, пробуем более широкий regex
    if (preg_match_all('~<([a-z0-9:_-]+)\b[^>]*class=(["\'])[^"\']*\baf-apf-postbit-field\b[^"\']*\2[^>]*>.*?</\1>~is', $userDetails, $m)) {
        return implode("\n", $m[0]);
    }

    return '';
}
function af_apui_postbit_strip_profile_field_names(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    // Удаляем название поля и двоеточие после него:
    // <span class="af-apf-name">ЛЗ</span>:
    $html = preg_replace(
        '~<span[^>]*class=(["\'])[^"\']*\baf-apf-name\b[^"\']*\1[^>]*>.*?</span>\s*(?:&nbsp;|&#160;|\s)*:\s*~isu',
        '',
        $html
    ) ?? $html;

    // Фолбэк: если двоеточия нет, но span с названием есть — убираем просто его
    $html = preg_replace(
        '~<span[^>]*class=(["\'])[^"\']*\baf-apf-name\b[^"\']*\1[^>]*>.*?</span>\s*~isu',
        '',
        $html
    ) ?? $html;

    return trim($html);
}

function af_apui_format_duration_ru(int $seconds): string
{
    $seconds = max(0, $seconds);

    if ($seconds < 60) {
        return 'только что';
    }

    $minutes = (int) floor($seconds / 60);
    if ($minutes < 60) {
        return $minutes . ' ' . af_apui_ru_plural($minutes, 'минуту', 'минуты', 'минут');
    }

    $hours = (int) floor($minutes / 60);
    if ($hours < 24) {
        return $hours . ' ' . af_apui_ru_plural($hours, 'час', 'часа', 'часов');
    }

    $days = (int) floor($hours / 24);
    return $days . ' ' . af_apui_ru_plural($days, 'день', 'дня', 'дней');
}

function af_apui_ru_plural(int $num, string $one, string $few, string $many): string
{
    $n = abs($num) % 100;
    $n1 = $n % 10;

    if ($n > 10 && $n < 20) {
        return $many;
    }

    if ($n1 > 1 && $n1 < 5) {
        return $few;
    }

    if ($n1 === 1) {
        return $one;
    }

    return $many;
}

function af_apui_build_postbit_presence_html(array $post): string
{
    global $db, $mybb;

    $uid = (int)($post['uid'] ?? 0);
    if ($uid <= 0) {
        $title = htmlspecialchars_uni('Оффлайн');
        return '<span class="af-apui-presence-dot af-apui-presence-dot--offline" title="' . $title . '" data-af-title="' . $title . '" aria-label="' . $title . '"></span>';
    }

    static $presenceCache = [];

    if (isset($presenceCache[$uid])) {
        return $presenceCache[$uid];
    }

    $lastActive = 0;

    if ($db->table_exists('sessions')) {
        $query = $db->simple_select(
            'sessions',
            'MAX(time) AS last_time',
            "uid='" . $uid . "'"
        );
        $lastActive = (int)$db->fetch_field($query, 'last_time');
    }

    if ($lastActive <= 0 && !empty($post['lastactive'])) {
        $lastActive = (int)$post['lastactive'];
    }

    $cutoffSeconds = 15 * 60;

    if (!empty($mybb->settings['wolcutoff'])) {
        $tmp = (int)$mybb->settings['wolcutoff'];
        if ($tmp > 0) {
            $cutoffSeconds = $tmp;
        }
    } elseif (!empty($mybb->settings['wolcutoffmins'])) {
        $tmp = (int)$mybb->settings['wolcutoffmins'];
        if ($tmp > 0) {
            $cutoffSeconds = $tmp * 60;
        }
    }

    $isOnline = $lastActive > 0 && $lastActive >= (TIME_NOW - $cutoffSeconds);

    if ($isOnline) {
        $delta = max(0, TIME_NOW - $lastActive);
        $title = 'Онлайн';
        if ($delta < 60) {
            $title .= ' · активен только что';
        } else {
            $title .= ' · активен ' . af_apui_format_duration_ru($delta) . ' назад';
        }

        $title = htmlspecialchars_uni($title);

        $presenceCache[$uid] =
            '<span class="af-apui-presence-dot af-apui-presence-dot--online"'
            . ' title="' . $title . '"'
            . ' data-af-title="' . $title . '"'
            . ' aria-label="' . $title . '"></span>';

        return $presenceCache[$uid];
    }

    $title = 'Оффлайн';
    if ($lastActive > 0) {
        $title .= ' · был активен ' . af_apui_format_duration_ru(max(0, TIME_NOW - $lastActive)) . ' назад';
    }

    $title = htmlspecialchars_uni($title);

    $presenceCache[$uid] =
        '<span class="af-apui-presence-dot af-apui-presence-dot--offline"'
        . ' title="' . $title . '"'
        . ' data-af-title="' . $title . '"'
        . ' aria-label="' . $title . '"></span>';

    return $presenceCache[$uid];
}

function af_apui_postbit_compose_userdetails(array &$post): void
{
    $userDetails = (string)($post['user_details'] ?? '');
    $profileFields = af_apui_postbit_extract_profile_fields($userDetails);

    if ($profileFields === '' && strpos($userDetails, 'af-apf-postbit-field') !== false) {
        $profileFields = $userDetails;
    }

    $profileFields = af_apui_postbit_strip_profile_field_names($profileFields);

    $postsValue = af_apui_postbit_extract_number((string)($post['postnum'] ?? '0'));
    $threadsValue = af_apui_postbit_extract_number((string)($post['threadnum'] ?? '0'));
    $reputationValue = af_apui_postbit_extract_number((string)($post['replink'] ?? ($post['reputation'] ?? '0')));

    $uid = (int)($post['uid'] ?? 0);
    $pid = (int)($post['pid'] ?? 0);

    $creditsValue = '0.00';
    $currencySymbol = '¢';
    $tokensHtml = '';
    $levelValue = '1';

    $tooltipMessages = htmlspecialchars_uni('Сообщений');
    $tooltipThreads = htmlspecialchars_uni('Тем');
    $tooltipReputation = htmlspecialchars_uni('Репутация');
    $tooltipPosts = htmlspecialchars_uni('Постов');
    $tooltipCredits = htmlspecialchars_uni('Кредитов');
    $tooltipTokens = htmlspecialchars_uni('Абилити токенов');
    $tooltipLevel = htmlspecialchars_uni('Уровень');

    if ($uid > 0 && function_exists('af_balance_get_postbit_data')) {
        $balanceData = af_balance_get_postbit_data($uid);
        $creditsValue = htmlspecialchars_uni((string)($balanceData['credits_display'] ?? '0.00'));
        $currencySymbol = htmlspecialchars_uni((string)($balanceData['currency_symbol'] ?? '¢'));
        $levelValue = (string)((int)($balanceData['level'] ?? 1));

        if (!empty($balanceData['ability_tokens_show_postbit'])) {
            $tokensValue = htmlspecialchars_uni((string)($balanceData['ability_tokens_display'] ?? '0.00'));
            $tokensSymbol = htmlspecialchars_uni((string)($balanceData['ability_tokens_symbol'] ?? '♦'));

            $tokensHtml =
                '<span class="af-apui-stat-item af-apui-stat-item--tokens"'
                . ' title="' . $tooltipTokens . '"'
                . ' data-af-title="' . $tooltipTokens . '"'
                . ' data-af-balance-ability="1"'
                . ' data-af-balance-ability-scaled="' . (int)($balanceData['ability_tokens_scaled'] ?? 0) . '"'
                . ' data-pid="' . $pid . '"'
                . ' data-uid="' . $uid . '">'
                    . '<span class="af-apui-stat-item__icon"><i class="fa-solid fa-gem" aria-hidden="true"></i></span>'
                    . '<span class="af-apui-stat-item__value" data-af-balance-ability-value="1">' . $tokensValue . ' ' . $tokensSymbol . '</span>'
                . '</span>';
        }
    }

    $isQuickReplyContext = (defined('THIS_SCRIPT') && strtolower((string)THIS_SCRIPT) === 'newreply.php') || defined('IN_XMLHTTP');
    $afApcPostbitHtml = $isQuickReplyContext ? '' : '<af_apc_uid_' . $uid . '>';

    $post['af_apui_presence_html'] = af_apui_build_postbit_presence_html($post);
    $post['af_apui_profile_fields_html'] = $profileFields;
    $post['af_apui_author_statistics_html'] =
        '<div class="author_statistics af-apui-postbit-userdetails">'
        . '<span class="af-apui-stat-item af-apui-stat-item--messages" title="' . $tooltipMessages . '" data-af-title="' . $tooltipMessages . '"><span class="af-apui-stat-item__icon"><i class="fa-solid fa-comments" aria-hidden="true"></i></span><span class="af-apui-stat-item__value">' . htmlspecialchars_uni($postsValue) . '</span></span>'
        . '<span class="af-apui-stat-item af-apui-stat-item--threads" title="' . $tooltipThreads . '" data-af-title="' . $tooltipThreads . '"><span class="af-apui-stat-item__icon"><i class="fa-solid fa-copy" aria-hidden="true"></i></span><span class="af-apui-stat-item__value">' . htmlspecialchars_uni($threadsValue) . '</span></span>'
        . '<span class="af-apui-stat-item af-apui-stat-item--reputation" title="' . $tooltipReputation . '" data-af-title="' . $tooltipReputation . '"><span class="af-apui-stat-item__icon"><i class="fa-solid fa-heart" aria-hidden="true"></i></span><span class="af-apui-stat-item__value">' . htmlspecialchars_uni($reputationValue) . '</span></span>'
        . '<span class="af-apui-stat-item af-apui-stat-item--posts" title="' . $tooltipPosts . '" data-af-title="' . $tooltipPosts . '" data-af-balance-posts="1" data-pid="' . $pid . '" data-uid="' . $uid . '"><span class="af-apui-stat-item__icon"><i class="fa-solid fa-pen" aria-hidden="true"></i></span><span class="af-apui-stat-item__value"><span class="af-apc-slot" data-af-apc-slot="1" data-uid="' . $uid . '">' . $afApcPostbitHtml . '</span></span></span>'
        . '<span class="af-apui-stat-item af-apui-stat-item--credits" title="' . $tooltipCredits . '" data-af-title="' . $tooltipCredits . '" data-af-balance-credits="1" data-pid="' . $pid . '" data-uid="' . $uid . '"><span class="af-apui-stat-item__icon"><i class="fa-solid fa-coins" aria-hidden="true"></i></span><span class="af-apui-stat-item__value" data-af-balance-credits-value="1">' . $creditsValue . ' ' . $currencySymbol . '</span></span>'
        . $tokensHtml
        . '<span class="af-apui-stat-item af-apui-stat-item--level" title="' . $tooltipLevel . '" data-af-title="' . $tooltipLevel . '" data-af-balance-level="1" data-pid="' . $pid . '" data-uid="' . $uid . '"><span class="af-apui-stat-item__icon"><i class="fa-solid fa-signal" aria-hidden="true"></i></span><span class="af-apui-stat-item__value" data-af-balance-level-value="1">' . htmlspecialchars_uni($levelValue) . '</span></span>'
        . '</div>';
}

function af_apui_global_start(): void
{
    if (defined('IN_ADMINCP')) {
        return;
    }

    if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'member.php') {
        af_apui_member_profile_init_vars();
    }
}

function af_apui_member_profile_end(): void
{
    af_apui_member_profile_init_vars();
    af_apui_member_profile_prepare_layout_vars();
}

function af_apui_member_profile_init_vars(): void
{
    foreach ([
        'af_apui_character_sheet_tab',
        'af_apui_application_tab',
        'af_apui_inventory_tab',
        'af_apui_timeline_tab',
        'af_apui_activity_tab',
        'af_apui_forum_info_grid',
        'af_apui_profilefields_grid',
    ] as $varName) {
        if (!isset($GLOBALS[$varName]) || !is_string($GLOBALS[$varName])) {
            $GLOBALS[$varName] = '';
        }
    }
}

function af_apui_member_profile_prepare_layout_vars(): void
{
    global $lang, $memregdate, $memlastvisitdate, $memprofile, $timeonline;
    global $referrals, $reputation, $warning_level, $profilefields, $contact_details;

    if (!defined('THIS_SCRIPT') || THIS_SCRIPT !== 'member.php') {
        return;
    }

    $forumPairs = [
        [
            'label' => (string)($lang->joined ?? 'Дата регистрации'),
            'value' => (string)$memregdate,
        ],
        [
            'label' => (string)($lang->lastvisit ?? 'Последний визит'),
            'value' => (string)$memlastvisitdate,
        ],
        [
            'label' => (string)($lang->total_posts ?? 'Сообщения'),
            'value' => (string)((int)($memprofile['postnum'] ?? 0))
                . (!empty($lang->ppd_percent_total) ? ' <span class="af-apui-inline-note">(' . $lang->ppd_percent_total . ')</span>' : ''),
        ],
        [
            'label' => (string)($lang->total_threads ?? 'Темы'),
            'value' => (string)((int)($memprofile['threadnum'] ?? 0))
                . (!empty($lang->tpd_percent_total) ? ' <span class="af-apui-inline-note">(' . $lang->tpd_percent_total . ')</span>' : ''),
        ],
        [
            'label' => (string)($lang->timeonline ?? 'Время онлайн'),
            'value' => (string)$timeonline,
        ],
    ];

    $forumPairs = array_merge(
        $forumPairs,
        af_apui_extract_pairs_from_html((string)$referrals),
        af_apui_extract_pairs_from_html((string)$reputation),
        af_apui_extract_pairs_from_html((string)$warning_level)
    );

    $extraPairs = array_merge(
        af_apui_extract_pairs_from_html((string)$profilefields),
        af_apui_extract_pairs_from_html((string)$contact_details)
    );

    $GLOBALS['af_apui_forum_info_grid'] = af_apui_render_info_grid(
        $forumPairs,
        'Основная информация пока недоступна.'
    );

    $GLOBALS['af_apui_profilefields_grid'] = af_apui_render_info_grid(
        $extraPairs,
        'Дополнительная информация не заполнена.'
    );
}

function af_apui_extract_pairs_from_html(string $html): array
{
    $html = trim($html);
    if ($html === '') {
        return [];
    }

    $pairs = [];

    if (!preg_match_all('~<tr\b[^>]*>(.*?)</tr>~is', $html, $rowMatches) || empty($rowMatches[1])) {
        return [];
    }

    foreach ($rowMatches[1] as $rowHtml) {
        if (!preg_match_all('~<t[dh]\b[^>]*>(.*?)</t[dh]>~is', $rowHtml, $cellMatches) || count($cellMatches[1]) < 2) {
            continue;
        }

        $label = af_apui_normalize_label_text((string)$cellMatches[1][0]);
        $value = af_apui_normalize_value_html((string)$cellMatches[1][1]);

        if ($label === '') {
            continue;
        }

        $pairs[] = [
            'label' => $label,
            'value' => $value !== '' ? $value : '&mdash;',
        ];
    }

    return $pairs;
}
function af_apui_normalize_label_text(string $html): string
{
    $html = str_replace('&nbsp;', ' ', $html);
    $html = preg_replace('~<br\s*/?>~i', ' ', $html) ?? $html;
    $html = strip_tags($html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = preg_replace('~\s+~u', ' ', $html) ?? $html;

    return trim($html, " \t\n\r\0\x0B:");
}
function af_apui_normalize_value_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $html = str_replace('&nbsp;', ' ', $html);
    $html = preg_replace('~^\s*<strong\b[^>]*>(.*?)</strong>\s*$~is', '$1', $html) ?? $html;
    $html = preg_replace('~^\s*<span\b[^>]*>(.*?)</span>\s*$~is', '$1', $html) ?? $html;
    $html = preg_replace('~^\s*<div\b[^>]*>(.*?)</div>\s*$~is', '$1', $html) ?? $html;

    return trim($html);
}
function af_apui_render_info_grid(array $pairs, string $emptyMessage = ''): string
{
    if (empty($pairs)) {
        return $emptyMessage !== ''
            ? '<div class="af-apui-empty">' . htmlspecialchars_uni($emptyMessage) . '</div>'
            : '';
    }

    $out = '';

    foreach ($pairs as $pair) {
        $label = (string)($pair['label'] ?? '');
        $value = (string)($pair['value'] ?? '');

        if ($label === '') {
            continue;
        }

        $out .= '<div class="af-apui-info-item">';
        $out .= '<div class="af-apui-info-label">' . htmlspecialchars_uni($label) . '</div>';
        $out .= '<div class="af-apui-info-value">' . ($value !== '' ? $value : '&mdash;') . '</div>';
        $out .= '</div>';
    }

    return $out;
}
function af_apui_pre_output_page(string &$page): void
{
    if (defined('IN_ADMINCP') || !af_apui_is_enabled() || $page === '') {
        return;
    }

    $script = defined('THIS_SCRIPT') ? strtolower((string)THIS_SCRIPT) : '';
    $action = strtolower((string)($GLOBALS['mybb']->input['action'] ?? ''));

    $needAssets = false;

    if ($script === 'member.php' && $action === 'profile') {
        $needAssets = true;
    }

    if (!$needAssets) {
        if (
            strpos($page, AF_APUI_MARKER_POSTBIT_START) !== false
            || strpos($page, AF_APUI_MARKER_PROFILE_START) !== false
            || strpos($page, 'af-apui-profile-page') !== false
            || strpos($page, 'af-apui-postbit') !== false
            || strpos($page, 'af-apui-tab') !== false
        ) {
            $needAssets = true;
        }
    }

    $page = af_apui_strip_asset_includes($page);

    if (!$needAssets) {
        return;
    }

    $bburl = rtrim((string)($GLOBALS['mybb']->settings['bburl'] ?? ''), '/');
    $base = $bburl . '/inc/plugins/advancedfunctionality/addons/' . AF_APUI_ID . '/assets';

    $cssUrl = af_apui_add_ver($base . '/advancedprofileui.css', AF_APUI_ASSETS_DIR . 'advancedprofileui.css');
    $jsUrl = af_apui_add_ver($base . '/advancedprofileui.js', AF_APUI_ASSETS_DIR . 'advancedprofileui.js');

    $injection = "\n" . AF_APUI_ASSET_MARK . "\n";
    $injection .= '<link rel="stylesheet" href="' . htmlspecialchars_uni($cssUrl) . '">' . "\n";
    $injection .= af_apui_build_runtime_style_tag();
    $injection .= '<script src="' . htmlspecialchars_uni($jsUrl) . '" defer></script>' . "\n";

    if (stripos($page, '</head>') !== false) {
        $page = preg_replace('~</head>~i', $injection . '</head>', $page, 1) ?? $page;
    } else {
        $page .= $injection;
    }
}
function af_apui_strip_asset_includes(string $page): string
{
    $patterns = [
        '~<!--\s*af_apui_assets\s*-->\s*~i',
        '~<link\b[^>]*href=(["\'])[^"\']*advancedprofileui\.css(?:\?[^"\']*)?\1[^>]*>\s*~i',
        '~<script\b[^>]*src=(["\'])[^"\']*advancedprofileui\.js(?:\?[^"\']*)?\1[^>]*>\s*</script>\s*~is',
        '~<style\b[^>]*id=(["\'])af-apui-runtime-css\1[^>]*>.*?</style>\s*~is',
    ];

    foreach ($patterns as $pattern) {
        $page = preg_replace($pattern, '', $page) ?? $page;
    }

    return $page;
}
function af_apui_add_ver(string $url, string $absFile): string
{
    $ver = is_file($absFile) ? (int)@filemtime($absFile) : 0;
    if ($ver <= 0) {
        return $url;
    }

    return $url . (strpos($url, '?') === false ? '?' : '&') . 'v=' . $ver;
}
function af_apui_get_setting_value(string $suffix, string $default = ''): string
{
    global $mybb;

    $key = 'af_' . AF_APUI_ID . '_' . $suffix;
    if (!isset($mybb->settings[$key])) {
        return $default;
    }

    return trim((string)$mybb->settings[$key]);
}

function af_apui_css_url_value(string $url): string
{
    $url = trim($url);
    if ($url === '' || !preg_match('~^https?://~i', $url)) {
        return 'none';
    }

    $url = str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\"', '', ''], $url);

    return 'url("' . $url . '")';
}

function af_apui_css_raw_value(string $value, string $default = 'none'): string
{
    $value = trim($value);
    if ($value === '') {
        return $default;
    }

    $value = str_replace(["\r", "\n", ';'], [' ', ' ', ''], $value);
    $value = str_replace(['</style', '<style'], ['<\/style', ''], $value);

    return trim($value);
}

function af_apui_sanitize_custom_css(string $css): string
{
    $css = trim($css);
    if ($css === '') {
        return '';
    }

    $css = str_replace(['</style', '<style'], ['<\/style', ''], $css);

    return $css;
}

function af_apui_build_runtime_style_tag(): string
{
    $profileBanner = af_apui_css_url_value(af_apui_get_setting_value('profile_banner_url', 'https://warprift.ru/uploads/af_gallery/1/2026/03/f861bca4f289f989560bd8a641d261fc.webp'));
    $profileBannerOverlay = af_apui_css_raw_value(
        af_apui_get_setting_value(
            'profile_banner_overlay',
            'linear-gradient(180deg, rgba(8, 12, 24, 0.06) 0%, rgba(8, 12, 24, 0.30) 42%, rgba(8, 12, 24, 0.82) 100%)'
        )
    );

    $bodyMode = strtolower(af_apui_get_setting_value('member_profile_body_bg_mode', 'cover'));
    if ($bodyMode !== 'tile') {
        $bodyMode = 'cover';
    }

    $bodyCoverImage = af_apui_css_url_value(af_apui_get_setting_value('member_profile_body_cover_url', 'https://warprift.ru/uploads/af_gallery/1/2026/03/06994bcde9e4d4c7919130bb88049916.webp'));
    $bodyTileImage = af_apui_css_url_value(af_apui_get_setting_value('member_profile_body_tile_url', ''));
    $bodyOverlay = af_apui_css_raw_value(af_apui_get_setting_value('member_profile_body_overlay', 'none'));

    $selectedBodyImage = $bodyMode === 'tile' ? $bodyTileImage : $bodyCoverImage;
    if ($selectedBodyImage === 'none') {
        $selectedBodyImage = $bodyMode === 'tile' ? $bodyCoverImage : $bodyTileImage;
        if ($selectedBodyImage !== 'none') {
            $bodyMode = $bodyMode === 'tile' ? 'cover' : 'tile';
        }
    }

    $bodyRepeat = $bodyMode === 'tile' ? 'repeat' : 'no-repeat';
    $bodyPosition = $bodyMode === 'tile' ? 'left top' : 'center center';
    $bodyAttachment = $bodyMode === 'tile' ? 'scroll' : 'fixed';
    $bodySize = $bodyMode === 'tile' ? 'auto' : 'cover';

    $postbitAuthorBg = af_apui_css_url_value(
        af_apui_get_setting_value('postbit_author_bg_url', 'https://warprift.ru/uploads/af_gallery/1/2026/03/e7a9f325d3838aad3e94e3e498f81edd.webp')
    );
    $postbitAuthorOverlay = af_apui_css_raw_value(
        af_apui_get_setting_value('postbit_author_overlay', 'linear-gradient(180deg, rgba(8, 12, 24, 0.06) 0%, rgba(24, 24, 24, 0.81) 42%, rgb(0, 0, 0) 100%)')
    );

    $postbitNameBg = af_apui_css_url_value(
        af_apui_get_setting_value('postbit_name_bg_url', 'https://warprift.ru/uploads/af_gallery/1/2026/03/acb421937b268acaa4a999feac5aedc9.webp')
    );
    $postbitNameOverlay = af_apui_css_raw_value(
        af_apui_get_setting_value('postbit_name_overlay', 'linear-gradient(180deg, rgba(10, 14, 24, .18), rgba(10, 14, 24, .28))')
    );

    $postbitPlaqueBg = af_apui_css_url_value(
        af_apui_get_setting_value('postbit_plaque_bg_url', 'https://warprift.ru/uploads/af_gallery/1/2026/03/844fae67d94b689f6e827278722ec152.webp')
    );
    $postbitPlaqueOverlay = af_apui_css_raw_value(
        af_apui_get_setting_value('postbit_plaque_overlay', 'linear-gradient(180deg, rgba(10, 14, 24, .10), rgba(10, 14, 24, .18))')
    );

    $memberCss = af_apui_sanitize_custom_css(af_apui_get_setting_value('member_profile_css', ''));
    $postbitCss = af_apui_sanitize_custom_css(af_apui_get_setting_value('postbit_css', ''));

    $css = ":root{";
    $css .= "--af-apui-profile-banner-image:" . $profileBanner . ";";
    $css .= "--af-apui-profile-banner-overlay:" . $profileBannerOverlay . ";";
    $css .= "--af-apui-postbit-author-bg-image:" . $postbitAuthorBg . ";";
    $css .= "--af-apui-postbit-author-overlay:" . $postbitAuthorOverlay . ";";
    $css .= "--af-apui-postbit-name-bg-image:" . $postbitNameBg . ";";
    $css .= "--af-apui-postbit-name-overlay:" . $postbitNameOverlay . ";";
    $css .= "--af-apui-postbit-plaque-bg-image:" . $postbitPlaqueBg . ";";
    $css .= "--af-apui-postbit-plaque-overlay:" . $postbitPlaqueOverlay . ";";
    $css .= "}\n";

    $css .= "body.af-apui-member-profile-page{";
    $css .= "background-image:" . $bodyOverlay . "," . $selectedBodyImage . ";";
    $css .= "background-repeat:no-repeat," . $bodyRepeat . ";";
    $css .= "background-position:center center," . $bodyPosition . ";";
    $css .= "background-attachment:scroll," . $bodyAttachment . ";";
    $css .= "background-size:cover," . $bodySize . ";";
    $css .= "}\n";

    $css .= ".af-apui-profile-hero__banner{background-image:var(--af-apui-profile-banner-image, none);}\n";
    $css .= ".af-apui-profile-hero__banner::after{background:var(--af-apui-profile-banner-overlay, linear-gradient(180deg, rgba(8, 12, 24, 0.06) 0%, rgba(8, 12, 24, 0.30) 42%, rgba(8, 12, 24, 0.82) 100%));}\n";

    if ($memberCss !== '') {
        $css .= "\n/* member_profile custom css */\n" . $memberCss . "\n";
    }

    if ($postbitCss !== '') {
        $css .= "\n/* postbit_classic custom css */\n" . $postbitCss . "\n";
    }

    return '<style id="af-apui-runtime-css">' . $css . '</style>' . "\n";
}

function af_apui_apply_overrides(): void
{
    global $db;

    af_apui_ensure_schema();

    $customTemplates = af_apui_load_custom_templates();
    $targetTitles = ['member_profile', 'postbit_classic'];

    foreach ($targetTitles as $title) {
        if (!isset($customTemplates[$title])) {
            continue;
        }

        $titleEsc = $db->escape_string($title);
        $query = $db->simple_select('templates', 'tid,title,sid,template,dateline', "title='" . $titleEsc . "' AND sid != '-2'");

        while ($row = $db->fetch_array($query)) {
            $tid = (int)$row['tid'];
            $currentTemplate = (string)$row['template'];

            af_apui_backup_template_row($row);

            if ($currentTemplate === $customTemplates[$title]) {
                continue;
            }

            $db->update_query('templates', [
                'template' => $db->escape_string($customTemplates[$title]),
                'dateline' => TIME_NOW,
            ], "tid='" . $tid . "'");
        }
    }
}

function af_apui_restore_overrides(): void
{
    global $db;

    if (!$db->table_exists(AF_APUI_BACKUP_TABLE_NAME)) {
        return;
    }

    $q = $db->simple_select(AF_APUI_BACKUP_TABLE_NAME, '*');
    while ($backup = $db->fetch_array($q)) {
        $tid = (int)$backup['template_tid'];
        $title = (string)$backup['title'];
        $sid = (int)$backup['sid'];
        $template = (string)$backup['original_template'];
        $dateline = (int)$backup['original_dateline'];

        $existing = $db->simple_select('templates', 'tid', "tid='" . $tid . "'", ['limit' => 1]);
        $existingTid = (int)$db->fetch_field($existing, 'tid');

        $payload = [
            'title' => $db->escape_string($title),
            'sid' => $sid,
            'template' => $db->escape_string($template),
            'version' => '1800',
            'dateline' => $dateline > 0 ? $dateline : TIME_NOW,
        ];

        if ($existingTid > 0) {
            $db->update_query('templates', $payload, "tid='" . $existingTid . "'");
        } else {
            $db->insert_query('templates', $payload);
        }

        $db->update_query(AF_APUI_BACKUP_TABLE_NAME, [
            'updated_at' => TIME_NOW,
        ], "id='" . (int)$backup['id'] . "'");
    }
}

function af_apui_backup_template_row(array $row): void
{
    global $db;

    $tid = (int)($row['tid'] ?? 0);
    if ($tid <= 0) {
        return;
    }

    $exists = $db->simple_select(AF_APUI_BACKUP_TABLE_NAME, 'id', "template_tid='" . $tid . "'", ['limit' => 1]);
    $backupId = (int)$db->fetch_field($exists, 'id');
    if ($backupId > 0) {
        return;
    }

    $template = (string)($row['template'] ?? '');

    $db->insert_query(AF_APUI_BACKUP_TABLE_NAME, [
        'template_tid' => $tid,
        'title' => $db->escape_string((string)($row['title'] ?? '')),
        'sid' => (int)($row['sid'] ?? -1),
        'original_template' => $db->escape_string($template),
        'original_dateline' => (int)($row['dateline'] ?? 0),
        'marker' => $db->escape_string('af_apui'),
        'checksum' => $db->escape_string(sha1($template)),
        'created_at' => TIME_NOW,
        'updated_at' => TIME_NOW,
    ]);
}

function af_apui_load_custom_templates(): array
{
    $file = AF_APUI_TEMPLATES_DIR . 'member_profile.html';
    $rawProfile = is_file($file) ? (string)file_get_contents($file) : '';

    $filePostbit = AF_APUI_TEMPLATES_DIR . 'postbit_classic.html';
    $rawPostbit = is_file($filePostbit) ? (string)file_get_contents($filePostbit) : '';

    $templates = [];
    $templates = array_merge($templates, af_parse_templates_bundle($rawProfile));
    $templates = array_merge($templates, af_parse_templates_bundle($rawPostbit));

    return $templates;
}

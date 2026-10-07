<?php
if (!defined('IN_MYBB')) {
    die('No direct access');
}

/**
 * Lightweight CharacterSheets frontend contract for showthread/member.
 * Full sheet rendering, calculator, CRUD and AJAX handlers do not belong here.
 */

function af_charactersheets_is_enabled(): bool
{
    global $mybb;
    return !empty($mybb->settings['af_charactersheets_enabled']);
}

function af_charactersheets_alias_target_path(): string
{
    return MYBB_ROOT . 'charactersheets.php';
}

function af_charactersheets_alias_is_ours(string $path): bool
{
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }

    $content = (string)file_get_contents($path);
    return strpos($content, AF_CS_ALIAS_MARKER) !== false;
}

function af_charactersheets_alias_available(): bool
{
    if (defined('THIS_SCRIPT') && THIS_SCRIPT === 'charactersheets.php') {
        return true;
    }

    return af_charactersheets_alias_is_ours(af_charactersheets_alias_target_path());
}

function af_charactersheets_url(array $params = []): string
{
    $useAlias = af_charactersheets_alias_available();
    $script = $useAlias ? 'charactersheets.php' : 'misc.php';

    if (!$useAlias) {
        $legacyAction = (string)($params['action'] ?? 'af_charactersheets');
        unset($params['action']);
        $params = array_merge(['action' => $legacyAction], $params);
    }

    if (!$params && !$useAlias) {
        $params = ['action' => 'af_charactersheets'];
    }

    $url = $script;
    if ($params) {
        $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

function af_charactersheets_load_lang(): void
{
    if (function_exists('af_load_addon_lang')) {
        af_load_addon_lang(AF_CS_ID);
    }
}

function af_charactersheets_is_accepted(int $tid): bool
{
    $row = af_charactersheets_get_accept_row($tid);
    return !empty($row['accepted']);
}

function af_charactersheets_is_in_accepted_forum(int $fid): bool
{
    if ($fid <= 0) {
        return false;
    }

    if (function_exists('af_cwf_get_target_forum_ids')) {
        $targetForumIds = af_cwf_get_target_forum_ids();
        if (!empty($targetForumIds)) {
            return in_array($fid, $targetForumIds, true);
        }
    }

    return false;
}

function af_charactersheets_resolve_existing_sheet_for_thread(int $tid, int $uid, array $acceptRow = []): array
{
    if ($tid > 0) {
        $sheet = af_charactersheets_get_sheet_by_tid($tid);
        if (!empty($sheet['id'])) {
            return $sheet;
        }
    }

    $acceptSlug = trim((string)($acceptRow['sheet_slug'] ?? ''));
    if ($acceptSlug !== '') {
        $sheet = af_charactersheets_get_sheet_by_slug($acceptSlug);
        if (!empty($sheet['id'])) {
            return $sheet;
        }
    }

    // Для тредовой анкеты не считаем лист по uid "дубликатом":
    // у одного пользователя может быть несколько персонажей.
    if ($uid > 0 && $tid <= 0) {
        $sheet = af_charactersheets_get_sheet_by_uid($uid);
        if (!empty($sheet['id'])) {
            return $sheet;
        }
    }

    return [];
}

function af_charactersheets_showthread_start_impl(): void
{
    global $mybb, $thread, $lang;

    if (!af_charactersheets_is_enabled()) {
        return;
    }

    if (!is_array($thread)) {
        return;
    }

    af_charactersheets_load_lang();

    $tid = (int)($thread['tid'] ?? 0);
    $fid = (int)($thread['fid'] ?? 0);
    if ($tid <= 0 || $fid <= 0) {
        return;
    }

    if (!function_exists('af_cwf_is_allowed_forum') || !af_cwf_is_allowed_forum($fid)) {
        return;
    }

    // Права (группы)
    if (!af_charactersheets_user_can_accept($mybb->user, $fid)) {
        return;
    }

    $acceptRow = af_charactersheets_get_accept_row($tid);
    $workflowContext = function_exists('af_cwf_get_context')
        ? af_cwf_get_context($tid, $thread, $acceptRow)
        : [];
    $was_accepted = !empty($workflowContext['was_accepted']) || af_charactersheets_is_accepted($tid);

    $acceptText = $was_accepted
        ? ($lang->af_charactersheets_accept_button_reaccept ?? 'Принять заново')
        : ($lang->af_charactersheets_accept_button ?? 'Принять анкету');

    $uid = (int)($thread['uid'] ?? 0);

    $sheetExists = af_charactersheets_resolve_existing_sheet_for_thread($tid, $uid, $acceptRow);

    $acceptUrl = af_charactersheets_url(['action' => 'af_charactersheets_accept', 'tid' => $tid, 'my_post_key' => $mybb->post_code]);
    $transferUrl = af_charactersheets_url(['action' => 'af_charactersheets_transfer', 'tid' => $tid, 'my_post_key' => $mybb->post_code]);
    $revisionUrl = af_charactersheets_url(['action' => 'af_charactersheets_request_revision', 'tid' => $tid, 'my_post_key' => $mybb->post_code]);
    $sheetUrl = af_charactersheets_url(['action' => 'af_charactersheets_create_sheet', 'tid' => $tid, 'my_post_key' => $mybb->post_code]);

    $buttons = [];
    $canAccept = function_exists('af_cwf_can_accept') ? af_cwf_can_accept($tid, $thread, $acceptRow) : false;
    $canTransfer = function_exists('af_cwf_can_transfer') ? af_cwf_can_transfer($tid, $thread, $acceptRow) : false;
    $canRequestRevision = function_exists('af_cwf_can_request_revision') ? af_cwf_can_request_revision($tid, $thread, $acceptRow) : false;
    $canCreateSheet = function_exists('af_cwf_can_create_sheet') ? af_cwf_can_create_sheet($tid, $thread, $acceptRow) : empty($sheetExists);

    if ($canAccept) {
        $buttons[] = '<a class="atf-button af-cs-workflow-button" href="' . htmlspecialchars_uni($acceptUrl) . '"><span>' . htmlspecialchars_uni($acceptText) . '</span></a>';
    }
    if ($canTransfer) {
        $transferText = $lang->af_charactersheets_transfer_button ?? 'Перенести анкету';
        $buttons[] = '<a class="atf-button atf-button--secondary af-cs-workflow-button af-cs-workflow-button--transfer" href="' . htmlspecialchars_uni($transferUrl) . '"><span>' . htmlspecialchars_uni($transferText) . '</span></a>';
    }
    if ($canRequestRevision) {
        $revisionText = $lang->af_charactersheets_request_revision_button ?? 'Отправить на доработку';
        $buttons[] = '<a class="atf-button atf-button--secondary af-cs-workflow-button af-cs-workflow-button--revision" href="' . htmlspecialchars_uni($revisionUrl) . '"><span>' . htmlspecialchars_uni($revisionText) . '</span></a>';
    }
    if (function_exists('af_atf_render_character_kb_moderation_button')) {
        $kbButton = (string)af_atf_render_character_kb_moderation_button($tid, $uid, $acceptRow, (string)$mybb->post_code);
        if ($kbButton !== '') {
            $buttons[] = $kbButton;
        }
    }
    if ($canCreateSheet) {
        $buttons[] = '<a class="atf-button atf-button--secondary af-cs-workflow-button af-cs-workflow-button--sheet" target="_blank" rel="noopener" href="' . htmlspecialchars_uni($sheetUrl) . '"><span>' . htmlspecialchars_uni($lang->af_charactersheets_create_sheet_button ?? 'Создать лист персонажа') . '</span></a>';
    }

    if (empty($buttons)) {
        return;
    }

    $GLOBALS['af_charactersheets_accept_button'] = '<div class="af-cs-workflow-actions">' . implode("\n", $buttons) . '</div>';
}

function af_charactersheets_pre_output_impl(&$page): void
{
    if (!defined('THIS_SCRIPT') || !in_array(THIS_SCRIPT, ['showthread.php', 'member.php'], true)) {
        return;
    }

    $hasComponent = !empty($GLOBALS['af_charactersheets_has_frontend_component']);
    if (function_exists('af_frontend_asset_allowed')
        && !af_frontend_asset_allowed(AF_CS_ID, 'pre_output', null, ['has_charactersheet_component' => $hasComponent])) {
        return;
    }

    $assetsDisabled = af_cs_assets_disabled_for_current_page();

    if (!$assetsDisabled && !empty($GLOBALS['af_charactersheets_needs_assets'])) {
        $page = af_charactersheets_inject_assets($page);
    }

    // Дедуп ассетов на showthread тоже
    if (!empty($GLOBALS['af_charactersheets_needs_assets'])) {
        $page = af_charactersheets_canonicalize_assets_html($page);
    }

    // The browser runtime owns the shell and creates its empty iframe on the
    // first user interaction. Keeping a second PHP-owned shell inflated every
    // showthread response and made ownership ambiguous.


    if (empty($GLOBALS['af_charactersheets_accept_button'])) {
        return;
    }

    if (strpos($page, AF_CS_TPL_MARK) !== false) {
        return;
    }

    $insert = "\n" . AF_CS_TPL_MARK . "\n" . $GLOBALS['af_charactersheets_accept_button'] . "\n";

    // в блоке quick reply — рядом с кнопками Preview/Submit
    // Сначала пробуем вставить ПОСЛЕ preview (если есть), иначе — перед submit.
    $count = 0;
    $page2 = @preg_replace(
        '~(<input\b[^>]*\bid=("|\')quick_reply_preview\2[^>]*>)~i',
        '$1' . "\n" . $insert,
        $page,
        1,
        $count
    );
    if ($count > 0 && is_string($page2)) {
        $page = $page2;
        return;
    }

    $count = 0;
    $page2 = @preg_replace(
        '~(<input\b[^>]*\bid=("|\')quick_reply_submit\2[^>]*>)~i',
        $insert . '$1',
        $page,
        1,
        $count
    );
    if ($count > 0 && is_string($page2)) {
        $page = $page2;
        return;
    }


    // 2) Альтернатива: рядом с формой quick reply (если id на submit вдруг кастомный)
    $count = 0;
    $page2 = @preg_replace(
        '~(<form\b[^>]*\bname=("|\')quick_reply\2[^>]*>)~i',
        '$1' . $insert,
        $page,
        1,
        $count
    );
    if ($count > 0 && is_string($page2)) {
        $page = $page2;
        return;
    }

    // 3) Запасной якорь: перед контентом
    $count = 0;
    $page2 = @preg_replace(
        '~(<div\s+id=("|\')content\2\b[^>]*>)~i',
        $insert . '$1',
        $page,
        1,
        $count
    );
    if ($count > 0 && is_string($page2)) {
        $page = $page2;
        return;
    }

    // 4) Старые темы: td.thead strong
    $count = 0;
    $page2 = @preg_replace(
        '~(<td\b[^>]*\bclass=("\')thead\2[^>]*>.*?<strong\b[^>]*>.*?</strong>)~is',
        '$1' . $insert,
        $page,
        1,
        $count
    );
    if ($count > 0 && is_string($page2)) {
        $page = $page2;
        return;
    }

    // 5) Фолбэк: перед </body>
    $count = 0;
    $page2 = @preg_replace('~</body>~i', $insert . "\n</body>", $page, 1, $count);
    if ($count > 0 && is_string($page2)) {
        $page = $page2;
        return;
    }

    // 6) Совсем крайний случай
    $page .= $insert;
}

function af_charactersheets_get_asset_urls(bool $lightweight = false): array
{
    global $mybb;
    $baseUrl = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    $prefix = $baseUrl . '/inc/plugins/advancedfunctionality/addons/' . AF_CS_ID . '/assets/';
    return $lightweight
        ? ['css' => $prefix . 'charactersheets-trigger.css', 'js' => $prefix . 'charactersheets-trigger.js']
        : ['css' => $prefix . 'charactersheets.css', 'js' => $prefix . 'charactersheets.js'];
}

function af_charactersheets_is_trigger_context(): bool
{
    $script = strtolower(defined('THIS_SCRIPT') ? (string)THIS_SCRIPT : '');
    return in_array($script, ['showthread.php', 'member.php'], true);
}

/** Mark an inline member-profile sheet so its parent response loads tab runtime. */
function af_charactersheets_mark_embedded_profile_component(): bool
{
    if (!defined('THIS_SCRIPT') || THIS_SCRIPT !== 'member.php' || !af_charactersheets_is_enabled()) {
        return false;
    }

    $facts = ['has_embedded_charactersheet' => true];
    if (function_exists('af_frontend_asset_allowed')
        && !af_frontend_asset_allowed(AF_CS_ID, 'embedded_runtime', null, $facts)) {
        return false;
    }

    $GLOBALS['af_charactersheets_has_embedded_component'] = true;
    $GLOBALS['af_charactersheets_has_frontend_component'] = true;
    $GLOBALS['af_charactersheets_needs_assets'] = true;
    return true;
}

/** The full runtime is allowed only when an inline sheet component is present. */
function af_charactersheets_embedded_runtime_allowed(): bool
{
    if (empty($GLOBALS['af_charactersheets_has_embedded_component'])) {
        return false;
    }

    return !function_exists('af_frontend_asset_allowed')
        || af_frontend_asset_allowed(
            AF_CS_ID,
            'embedded_runtime',
            null,
            ['has_embedded_charactersheet' => true]
        );
}

function af_charactersheets_get_asset_version(bool $lightweight = false): string
{
    $files = $lightweight
        ? [AF_CS_BASE . 'assets/charactersheets-trigger.css', AF_CS_BASE . 'assets/charactersheets-trigger.js']
        : [AF_CS_BASE . 'assets/charactersheets.css', AF_CS_BASE . 'assets/charactersheets.js'];
    $timestamps = [];
    foreach ($files as $file) {
        if (is_file($file)) {
            $timestamps[] = (int)filemtime($file);
        }
    }
    if (!$timestamps) {
        return AF_CS_ASSET_FALLBACK_VERSION;
    }
    return (string)max($timestamps);
}

function af_cs_assets_disabled_for_current_page(?bool $hasComponent = null): bool
{
    $hasComponent = $hasComponent ?? !empty($GLOBALS['af_charactersheets_has_frontend_component']);
    if (function_exists('af_frontend_asset_allowed')) {
        return !af_frontend_asset_allowed(
            AF_CS_ID,
            af_charactersheets_is_trigger_context() ? 'trigger_runtime' : 'runtime',
            null,
            ['has_charactersheet_component' => $hasComponent]
        );
    }
    return false;
}

function af_charactersheets_enqueue_assets(): void
{
    if (af_cs_assets_disabled_for_current_page()) {
        return;
    }

    $hasComponent = !empty($GLOBALS['af_charactersheets_has_frontend_component']);
    $embeddedRuntime = af_charactersheets_embedded_runtime_allowed();
    if (!$embeddedRuntime && function_exists('af_frontend_asset_allowed')
        && !af_frontend_asset_allowed(AF_CS_ID, 'runtime', null, ['has_charactersheet_component' => $hasComponent])) {
        return;
    }

    $lightweight = af_charactersheets_is_trigger_context() && !$embeddedRuntime;
    $assets = af_charactersheets_get_asset_urls($lightweight);
    $version = af_charactersheets_get_asset_version($lightweight);
    if (function_exists('af_add_css_once')) {
        af_add_css_once((string)($assets['css'] ?? '') . '?v=' . rawurlencode($version));
    }
    if (function_exists('af_add_js_once')) {
        af_add_js_once((string)($assets['js'] ?? '') . '?v=' . rawurlencode($version));
    }
}

function af_charactersheets_ensure_assets_in_headerinclude(): void
{
    af_charactersheets_enqueue_assets();
}

function af_charactersheets_canonicalize_assets_html(string $html): string
{
    if (af_cs_assets_disabled_for_current_page()) {
        $html = preg_replace(
            '~<link\b[^>]*href=("|\')[^"\']*charactersheets(?:-trigger)?\.css(?:\?[^"\']*)?\1[^>]*>\s*~i',
            '',
            $html
        );
        $html = preg_replace(
            '~<script\b[^>]*src=("|\')[^"\']*charactersheets(?:-trigger)?\.js(?:\?[^"\']*)?\1[^>]*>\s*</script>\s*~i',
            '',
            $html
        );
    }

    // Keep one ownership mode per response: trigger-only pages use the light
    // opener runtime; an actual inline profile sheet owns the full tab runtime.
    if (af_charactersheets_is_trigger_context() && !af_charactersheets_embedded_runtime_allowed()) {
        $html = preg_replace('~<link\b[^>]*href=("|\')[^"\']*/charactersheets\.css(?:\?[^"\']*)?\1[^>]*>\s*~i', '', $html);
        $html = preg_replace('~<script\b[^>]*src=("|\')[^"\']*/charactersheets\.js(?:\?[^"\']*)?\1[^>]*>\s*</script>\s*~i', '', $html);
    } else {
        $html = preg_replace('~<link\b[^>]*href=("|\')[^"\']*/charactersheets-trigger\.css(?:\?[^"\']*)?\1[^>]*>\s*~i', '', $html);
        $html = preg_replace('~<script\b[^>]*src=("|\')[^"\']*/charactersheets-trigger\.js(?:\?[^"\']*)?\1[^>]*>\s*</script>\s*~i', '', $html);
    }

    return $html;
}

function af_charactersheets_inject_assets(string $page): string
{
    af_charactersheets_enqueue_assets();
    return af_charactersheets_canonicalize_assets_html($page);
}

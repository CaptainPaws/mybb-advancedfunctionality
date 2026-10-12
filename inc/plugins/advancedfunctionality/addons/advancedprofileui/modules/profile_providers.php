<?php

if (!defined('IN_MYBB') || !defined('AF_APUI_ID')) {
    die('No direct access');
}

/**
 * Register or replace a profile quick-link provider.
 * The callback receives profile context and returns a view-model item
 * (`label`, `icon`, `url`, `available`) or null when the link does not apply.
 */
function af_apui_register_profile_quick_link_provider(string $key, callable $provider): bool
{
    $key = strtolower(trim($key));
    if ($key === '' || !preg_match('/^[a-z0-9_-]+$/', $key)) {
        return false;
    }

    if (!isset($GLOBALS['af_apui_profile_quick_link_providers']) || !is_array($GLOBALS['af_apui_profile_quick_link_providers'])) {
        $GLOBALS['af_apui_profile_quick_link_providers'] = [];
    }
    $GLOBALS['af_apui_profile_quick_link_providers'][$key] = $provider;
    return true;
}

/** Register optional profile reward content. The callback receives the owner UID and returns HTML. */
function af_apui_register_profile_reward_provider(string $key, callable $provider): bool
{
    $key = strtolower(trim($key));
    if (!in_array($key, ['achievements', 'gifts'], true)) {
        return false;
    }

    if (!isset($GLOBALS['af_apui_profile_reward_providers']) || !is_array($GLOBALS['af_apui_profile_reward_providers'])) {
        $GLOBALS['af_apui_profile_reward_providers'] = [];
    }
    $GLOBALS['af_apui_profile_reward_providers'][$key] = $provider;
    return true;
}

function af_apui_profile_sheet_section_url(int $uid, string $section): string
{
    if ($uid <= 0 || !in_array($section, ['arsenal', 'talents'], true)) {
        return '';
    }

    $url = function_exists('get_profile_link')
        ? (string)get_profile_link($uid)
        : 'member.php?action=profile&uid=' . $uid;
    if (function_exists('af_apui_append_query_arg')) {
        $url = af_apui_append_query_arg($url, 'action', 'profile');
        $url = af_apui_append_query_arg($url, 'uid', $uid);
        $url = af_apui_append_query_arg($url, 'af_profile_section', $section);
    } else {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query([
            'action' => 'profile',
            'uid' => $uid,
            'af_profile_section' => $section,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    return preg_replace('/#.*$/', '', $url) . '#af-tab-sheet';
}

function af_apui_profile_inventory_entity_url(int $uid, string $entity, string $subfilter = ''): string
{
    global $mybb;

    if ($uid <= 0 || !function_exists('af_advancedinventory_url')
        || !function_exists('af_advinv_get_entities') || !function_exists('af_inv_user_can_view')) {
        return '';
    }

    $viewerUid = (int)($mybb->user['uid'] ?? 0);
    if (!af_inv_user_can_view($viewerUid, $uid)) {
        return '';
    }

    $entities = af_advinv_get_entities(true);
    if (!isset($entities[$entity])) {
        return '';
    }

    $params = ['uid' => $uid, 'entity' => $entity];
    if ($subfilter !== '') {
        if (!function_exists('af_advinv_get_ui_subfilters')) {
            return '';
        }
        $subfilters = af_advinv_get_ui_subfilters($entity);
        $hasSubfilter = false;
        foreach ($subfilters as $filter) {
            if ((string)($filter['code'] ?? '') === $subfilter) {
                $hasSubfilter = true;
                break;
            }
        }
        if (!$hasSubfilter) {
            return '';
        }
        $params['sub'] = $subfilter;
    }

    return af_advancedinventory_url('entity', $params, false);
}

function af_apui_profile_quick_link_item(string $key, string $label, string $icon, string $url = '', bool $available = true): array
{
    $url = trim($url);
    return [
        'key' => $key,
        'label' => $label,
        'icon' => $icon,
        'url' => $available ? $url : '',
        'available' => $available && $url !== '',
    ];
}

function af_apui_get_profile_quick_link_items(array $context): array
{
    $uid = (int)($context['uid'] ?? 0);
    if ($uid <= 0) {
        return [];
    }

    $providers = [
        'application' => static function (array $ctx): ?array {
            $url = af_apui_resolve_application_url(['uid' => (int)($ctx['uid'] ?? 0)], (array)($ctx['sheet_payload'] ?? []));
            return $url !== '' ? af_apui_profile_quick_link_item('application', 'Анкета', 'fa-solid fa-file-lines', $url) : null;
        },
        'sheet' => static function (array $ctx): ?array {
            $url = trim((string)($ctx['sheet_payload']['sheet_url'] ?? ''));
            return $url !== '' ? af_apui_profile_quick_link_item('sheet', 'Лист персонажа', 'fa-solid fa-id-card', $url) : null;
        },
        'arsenal' => static function (array $ctx): ?array {
            $payload = (array)($ctx['sheet_payload'] ?? []);
            if (trim((string)($payload['sheet_slug'] ?? '')) === '' || trim((string)($payload['sheet_url'] ?? '')) === '') {
                return null;
            }
            if (af_apui_profile_inventory_entity_url((int)($ctx['uid'] ?? 0), 'equipment') === '') {
                return null;
            }
            return af_apui_profile_quick_link_item(
                'arsenal', 'Арсенал', 'fa-solid fa-shield-halved',
                af_apui_profile_inventory_entity_url((int)($ctx['uid'] ?? 0), 'equipment')
            );
        },
        'talents' => static function (array $ctx): ?array {
            $url = af_apui_profile_inventory_entity_url((int)($ctx['uid'] ?? 0), 'abilities', 'talent');
            return $url !== '' ? af_apui_profile_quick_link_item('talents', 'Таланты', 'fa-solid fa-wand-magic-sparkles', $url) : null;
        },
        'resources' => static function (array $ctx): ?array {
            $url = af_apui_profile_inventory_entity_url((int)($ctx['uid'] ?? 0), 'resources');
            return $url !== '' ? af_apui_profile_quick_link_item('resources', 'Ресурсы', 'fa-solid fa-gem', $url) : null;
        },
        'pets' => static function (array $ctx): ?array {
            $url = af_apui_profile_inventory_entity_url((int)($ctx['uid'] ?? 0), 'pets');
            return $url !== '' ? af_apui_profile_quick_link_item('pets', 'Питомцы', 'fa-solid fa-paw', $url) : null;
        },
        'collections' => static fn(array $ctx): array => af_apui_profile_quick_link_item('collections', 'Коллекции', 'fa-solid fa-layer-group', '', false),
        'home' => static fn(array $ctx): array => af_apui_profile_quick_link_item('home', 'Дом', 'fa-solid fa-house', '', false),
        'playlist' => static fn(array $ctx): array => af_apui_profile_quick_link_item('playlist', 'Плейлист', 'fa-solid fa-music', '', false),
        'relationships' => static fn(array $ctx): array => af_apui_profile_quick_link_item('relationships', 'Отношения', 'fa-solid fa-heart', '', false),
    ];

    $registered = (array)($GLOBALS['af_apui_profile_quick_link_providers'] ?? []);
    foreach ($registered as $key => $provider) {
        if (is_callable($provider)) {
            $providers[(string)$key] = $provider;
        }
    }

    $items = [];
    foreach ($providers as $key => $provider) {
        try {
            $item = $provider($context);
        } catch (Throwable $error) {
            error_log('[AdvancedProfileUI] quick-link provider ' . $key . ' failed: ' . $error->getMessage());
            continue;
        }
        if (!is_array($item) || trim((string)($item['label'] ?? '')) === '') {
            continue;
        }
        $item['key'] = (string)($item['key'] ?? $key);
        $item['icon'] = trim((string)($item['icon'] ?? '')) ?: 'fa-solid fa-link';
        $item['url'] = !empty($item['available']) ? trim((string)($item['url'] ?? '')) : '';
        $item['available'] = !empty($item['available']) && $item['url'] !== '';
        if (!isset($item['modal_kind']) && $item['available']) {
            if ($item['key'] === 'sheet' && !isset($registered[$key])) {
                $item['modal_kind'] = 'sheet';
                $item['modal_url'] = af_apui_append_query_arg($item['url'], 'embed', '1');
            } elseif (in_array($item['key'], ['application','arsenal','talents','resources','pets'], true) && !isset($registered[$key])) {
                $item['modal_kind'] = 'fragment';
                $item['modal_url'] = 'member.php?action=profile&uid='.$uid.'&af_profile_tab=quick&af_profile_quick='.rawurlencode($item['key']);
            }
        }
        $items[] = $item;
    }

    return $items;
}

function af_apui_render_profile_quick_links(array $context): string
{
    $items = af_apui_get_profile_quick_link_items($context);
    if (!$items) {
        return '';
    }

    $html = '<nav class="af-apui-profile-quick-links" aria-label="Быстрые ссылки профиля">';
    foreach ($items as $item) {
        $label = htmlspecialchars_uni((string)$item['label']);
        $icon = htmlspecialchars_uni((string)$item['icon']);
        $key = htmlspecialchars_uni((string)$item['key']);
        if (!empty($item['available'])) {
            $modal = !empty($item['modal_kind']) && !empty($item['modal_url'])
                ? ' data-af-apui-modal-owner="1" data-af-apui-modal-kind="'.htmlspecialchars_uni($item['modal_kind']).'" data-af-apui-modal-url="'.htmlspecialchars_uni($item['modal_url']).'" data-af-apui-modal-title="'.$label.'"' : '';
            $html .= '<a'.$modal.' class="af-apui-profile-quick-link" data-af-apui-quick-link="' . $key . '" href="' . htmlspecialchars_uni((string)$item['url']) . '" title="' . $label . '">'
                . '<i class="' . $icon . '" aria-hidden="true"></i><span>' . $label . '</span></a>';
        } else {
            $html .= '<span class="af-apui-profile-quick-link is-disabled" data-af-apui-quick-link="' . $key . '" aria-disabled="true" title="Раздел пока недоступен">'
                . '<i class="' . $icon . '" aria-hidden="true"></i><span>' . $label . '</span></span>';
        }
    }
    return $html . '</nav>';
}

function af_apui_profile_inventory_reward_html(int $uid, string $entity): string
{
    global $mybb;

    if (!function_exists('af_advinv_get_entities') || !function_exists('af_advinv_render_entity_tab')
        || !function_exists('af_inv_user_can_view')) {
        return '';
    }
    $viewerUid = (int)($mybb->user['uid'] ?? 0);
    if (!af_inv_user_can_view($viewerUid, $uid)) {
        return '<div class="af-apui-empty">Содержимое скрыто настройками доступа.</div>';
    }
    if (!isset(af_advinv_get_entities(true)[$entity])) {
        return '';
    }
    return (string)af_advinv_render_entity_tab($entity, $uid, 'all', 1, true);
}

function af_apui_render_profile_rewards_tab(int $uid): string
{
    $labels = [
        'achievements' => ['Ачивки', 'Ачивки пока не добавлены.'],
        'gifts' => ['Подарки', 'Подарков пока нет.'],
    ];
    $providers = [
        'achievements' => static fn(int $ownerUid): string => af_apui_profile_inventory_reward_html($ownerUid, 'achievements'),
        'gifts' => static fn(int $ownerUid): string => af_apui_profile_inventory_reward_html($ownerUid, 'gifts'),
    ];
    foreach ((array)($GLOBALS['af_apui_profile_reward_providers'] ?? []) as $key => $provider) {
        if (isset($labels[$key]) && is_callable($provider)) {
            $providers[$key] = $provider;
        }
    }

    $html = '<div class="af-apui-rewards-grid">';
    foreach ($labels as $key => [$title, $empty]) {
        $content = '';
        try {
            $content = trim((string)($providers[$key]($uid) ?? ''));
        } catch (Throwable $error) {
            error_log('[AdvancedProfileUI] reward provider ' . $key . ' failed: ' . $error->getMessage());
        }
        if ($content === '') {
            $content = '<div class="af-apui-empty">' . htmlspecialchars_uni($empty) . '</div>';
        }
        $html .= '<section class="af-apui-card af-apui-reward-card"><h2>' . htmlspecialchars_uni($title) . '</h2>'
            . '<div class="af-apui-reward-card__scroll">' . $content . '</div></section>';
    }
    return $html . '</div>';
}

/** Native action markup is already permission-filtered by MyBB. Keep forms intact. */
function af_apui_profile_action_markup(string $html): string
{
    if (trim($html) === '' || !preg_match('~<(?:a|form|button|input)\b~i', $html)) return '';
    if (!class_exists('DOMDocument')) {
        $html = preg_replace_callback('~<tr\b[^>]*>.*?</tr>~is', static fn(array $row) => preg_match('~<(?:a|form|button|input)\b~i', $row[0]) ? $row[0] : '', $html);
        return preg_replace('~<(\/?)(?:table|thead|tbody|tfoot|tr|td|th)\b([^>]*)>~i', '<$1div$2>', $html);
    }
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><div id="apui-native-actions">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);
    foreach (iterator_to_array($xpath->query('//tr')) as $row) {
        if (!$xpath->query('.//a|.//form|.//button|.//input', $row)->length) $row->parentNode->removeChild($row);
    }
    foreach (['table','thead','tbody','tfoot','tr','td','th'] as $tag) {
        foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
            $replacement = $dom->createElement('div');
            foreach ($node->attributes as $attribute) if (!in_array($attribute->name, ['class','style','width','height','align','border','cellpadding','cellspacing'], true)) $replacement->setAttribute($attribute->name, $attribute->value);
            $replacement->setAttribute('class', 'af-apui-native-action-'.$tag.' '.trim(preg_replace('/\b(?:tborder|trow[0-9]+|thead)\b/', '', $node->getAttribute('class'))));
            while ($node->firstChild) $replacement->appendChild($node->firstChild);
            $node->parentNode->replaceChild($replacement, $node);
        }
    }
    $container = $dom->getElementById('apui-native-actions');
    $result = '';
    if ($container) foreach ($container->childNodes as $node) $result .= $dom->saveHTML($node);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    return $result;
}
function af_apui_render_profile_extra(array $native): string
{
    $html = '';
    foreach (['Связаться с пользователем'=>['contact_details'], 'Взаимодействие'=>['buddy_options','ignore_options','report_options'], 'Функции модератора и администратора'=>['modoptions','adminoptions']] as $title=>$keys) {
        $content = '';
        $icons = ['contact_details'=>'fa-envelope', 'buddy_options'=>'fa-user-plus', 'ignore_options'=>'fa-user-slash', 'report_options'=>'fa-flag', 'modoptions'=>'fa-shield-halved', 'adminoptions'=>'fa-screwdriver-wrench'];
        foreach ($keys as $key) {
            $action = af_apui_profile_action_markup((string)($native[$key] ?? ''));
            if ($action !== '') $content .= '<div class="af-apui-native-action-group"><i class="fa-solid '.$icons[$key].'" aria-hidden="true"></i><div>'.$action.'</div></div>';
        }
        if ($content !== '') $html .= '<section class="af-apui-card af-apui-extra-group"><h2>'.htmlspecialchars_uni($title).'</h2><div class="af-apui-extra-actions-grid">'.$content.'</div></section>';
    }
    return $html !== '' ? $html : '<p class="af-apui-empty">Нет доступных действий.</p>';
}

/** Only asset tags from provider-owned header fragments, never message HTML. */
function af_apui_profile_asset_manifest(string $header): array
{
    $assets = [];
    if (preg_match_all('~<(link|script)\b[^>]*(?:href|src)=["\']([^"\']+)["\'][^>]*>~i', $header, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) $assets[] = ['type'=>strtolower($match[1]) === 'link' ? 'css' : 'js', 'url'=>html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')];
    }
    return $assets;
}

/** Narrow profile content route: no postbit, thread replies, or global navigation. */
function af_apui_profile_quick_content(int $uid, string $key): array
{
    global $mybb;
    if (empty($mybb->usergroup['canviewprofiles']) || (isset($mybb->usergroup['canview']) && empty($mybb->usergroup['canview'])) || $uid <= 0) return [403, ['error'=>'Содержимое недоступно.']];
    $payload = af_apui_get_charactersheet_postbit_payload($uid);
    $assets = ''; $html = '';
    if ($key === 'application') {
        $tid = (int)($payload['application']['tid'] ?? $payload['application_tid'] ?? 0);
        $thread = $tid > 0 ? get_thread($tid) : [];
        if (!$thread) return [404, ['error'=>'Анкета не найдена.']];
        $applicationOwner = (int)($thread['uid'] ?? 0);
        $fid = (int)$thread['fid']; $permissions = forum_permissions($fid);
        $moderator = function_exists('is_moderator') && is_moderator($fid);
        if (empty($permissions['canview']) || empty($permissions['canviewthreads'])
            || (!empty($permissions['canonlyviewownthreads']) && (int)$thread['uid'] !== (int)($mybb->user['uid'] ?? 0) && !$moderator)
            || ((int)($thread['visible'] ?? 1) === 0 && !(function_exists('is_moderator') && is_moderator($fid, 'canviewunapprove')))
            || ((int)($thread['visible'] ?? 1) === -1 && !(function_exists('is_moderator') && is_moderator($fid, 'canviewdeleted')))) return [403, ['error'=>'Анкета недоступна.']];
        check_forum_password($fid);
        if (!function_exists('af_atf_build_display_block_for_tid_fid')) return [503, ['error'=>'Рендерер анкеты недоступен.']];
        $html = af_atf_build_display_block_for_tid_fid($tid, $fid);
        $base = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
        foreach (['AF_ATF_ASSET_CSS'=>'css','AF_ATF_ASSET_VIEW_JS'=>'js'] as $constant=>$type) if (defined($constant)) {
            $assets .= $type === 'css' ? '<link href="'.htmlspecialchars_uni($base.'/'.constant($constant)).'">' : '<script src="'.htmlspecialchars_uni($base.'/'.constant($constant)).'"></script>';
        }
        $document = '<html><head>'.$assets.'</head><body><div class="af-apui-application-fragment af-aa-context af-aa-context--application af-aa-surface-user-'.$applicationOwner.'" data-af-apui-owner-uid="'.$applicationOwner.'" data-uid="'.$applicationOwner.'" data-af-apui-surface="application">'.$html.'</div></body></html>';
        if (function_exists('af_advancedelementtheme_pre_output')) af_advancedelementtheme_pre_output($document);
        // This is our own content-only scaffold, not a showthread response.
        $headStart = strpos($document, '<head>') + 6; $headEnd = strpos($document, '</head>');
        $assets = substr($document, $headStart, $headEnd - $headStart);
        $bodyStart = strpos($document, '>', strpos($document, '<body')) + 1;
        $html = substr($document, $bodyStart, strrpos($document, '</body>') - $bodyStart);
        if (preg_match_all('~<style\b[^>]*data-af-element[^>]*>.*?</style>~is', $assets, $styles)) $html = implode('', $styles[0]).$html;
        if (preg_match_all('~<script\b[^>]*type=["\']application/json["\'][^>]*>.*?</script>~is', $assets, $jsonScripts)) $html = implode('', $jsonScripts[0]).$html;
    } elseif (in_array($key, ['arsenal','talents','resources','pets'], true)) {
        if ($key === 'arsenal' && (empty($payload['sheet_slug']) || empty($payload['sheet_url']))) return [404, ['error'=>'Лист персонажа недоступен.']];
        if (!function_exists('af_inv_user_can_view') || !af_inv_user_can_view((int)($mybb->user['uid'] ?? 0), $uid)) return [403, ['error'=>'Раздел недоступен.']];
        $entity = ['arsenal'=>'equipment','talents'=>'abilities','resources'=>'resources','pets'=>'pets'][$key];
        if (af_apui_profile_inventory_entity_url($uid, $entity, $key === 'talents' ? 'talent' : '') === '') return [404, ['error'=>'Раздел недоступен.']];
        if ($key === 'arsenal' && function_exists('af_advancedinventory_build_equipment_fragment')) $html = af_advancedinventory_build_equipment_fragment($uid);
        elseif (function_exists('af_advinv_render_entity_tab')) {
            $mode = in_array($entity, ['equipment','abilities'], true) && function_exists('af_advinv_resolve_owner_sheet_mode') ? af_advinv_resolve_owner_sheet_mode($uid, '') : '';
            $sub = function_exists('af_advinv_resolve_active_subfilter') ? af_advinv_resolve_active_subfilter($entity, $key === 'talents' ? 'talent' : '', $uid, $mode) : '';
            $html = af_advinv_render_entity_tab($entity, $uid, $sub, 1, true, '', $mode);
        }
        if (function_exists('af_advancedinventory_append_embedded_assets')) af_advancedinventory_append_embedded_assets($assets, true);
    } else return [400, ['error'=>'Неизвестный раздел.']];
    if (trim($html) === '') return [404, ['error'=>'Содержимое недоступно.']];
    return [200, ['html'=>$html, 'assets'=>af_apui_profile_asset_manifest($assets)]];
}

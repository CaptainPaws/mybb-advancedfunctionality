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
                af_apui_profile_sheet_section_url((int)($ctx['uid'] ?? 0), 'arsenal')
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
            $html .= '<a class="af-apui-profile-quick-link" data-af-apui-quick-link="' . $key . '" href="' . htmlspecialchars_uni((string)$item['url']) . '" title="' . $label . '">'
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

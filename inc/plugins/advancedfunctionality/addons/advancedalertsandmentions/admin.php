<?php
/**
 * AdvancedFunctionality Gateway (STUB, source of truth)
 * AF-GENERATED: advancedfunctionality_gateway v2
 *
 * Этот файл не должен подключаться MyBB напрямую как плагин.
 * Его задача — быть эталоном, из которого AF пишет runtime-файл в корень форума.
 */

if (!defined('IN_MYBB')) { die('No direct access'); }

if (!function_exists('af_gateway_xmlhttp_router')) {

    /**
     * Общий роутер для xmlhttp.php под аддоны AF.
     *
     * Контракт для аддонов:
     *  - аддон может объявить функцию af_{addon_id}_xmlhttp(): bool
     *  - функция сама читает $mybb->input['action'] и, если обработала запрос, возвращает true (и сама может exit).
     */
    function af_gateway_xmlhttp_router(): void
    {
        if (defined('IN_ADMINCP')) {
            return;
        }

        global $mybb;

        $action = (string)$mybb->get_input('action');
        if ($action === '') {
            return;
        }

        $action_lc = strtolower($action);

        // Пропускаем только:
        // 1) все AF-* экшены
        // 2) myalerts-совместимые экшены, которые AAM умеет обслуживать (иначе они никогда не дойдут до аддона)
        $myalertsCompat = [
            'getlatestalerts',
            'getnewalerts',
            'getnumunreadalerts',
            'get_num_unread_alerts',
            'markallread',
            'mark_all_read',
            'myalerts_mark_read',
            'myalerts_mark_unread',
        ];

        $allowed = (strpos($action, 'af_') === 0) || in_array($action_lc, $myalertsCompat, true);
        if (!$allowed) {
            return;
        }

        // AF core должен быть доступен
        if (!function_exists('af_discover_addons') || !function_exists('af_is_addon_enabled')) {
            return;
        }

        $addons = af_discover_addons();

        foreach ($addons as $meta) {
            $id = (string)($meta['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if (!af_is_addon_enabled($id)) {
                continue;
            }

            // Подключаем bootstrap аддона
            $bootstrap = (string)($meta['bootstrap'] ?? '');
            if ($bootstrap !== '' && is_file($bootstrap)) {
                require_once $bootstrap;
            }

            // Каноничное имя хендлера
            $fn = 'af_' . $id . '_xmlhttp';
            if (function_exists($fn)) {
                $handled = $fn();
                if ($handled === true) {
                    exit;
                }
            }
        }

        // Legacy fallback (на всякий)
        if (function_exists('af_aam_xmlhttp')) {
            $handled = af_aam_xmlhttp();
            if ($handled === true) {
                exit;
            }
        }

        return;
    }
}

// ВАЖНО: в runtime-файле этот хук должен быть зарегистрирован.
// Если AF-кор уже сам это делает — повторная регистрация безопасна (MyBB не умрёт),
// но если НЕ делает — без этого роутер вообще не вызовется.
global $plugins;
if (isset($plugins) && is_object($plugins)) {
    $plugins->add_hook('xmlhttp', 'af_gateway_xmlhttp_router', -1);
}

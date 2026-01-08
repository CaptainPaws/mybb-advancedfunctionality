<?php
/**
 * AF Addon: IndexRedirect
 * MyBB 1.8.x, PHP 8.0–8.4
 *
 * Задача: редиректить только прямой заход на /index.php -> /
 */

if (!defined('IN_MYBB')) { die('No direct access'); }
if (!defined('AF_ADDONS')) { die('AdvancedFunctionality core required'); }

define('AF_INDEXREDIRECT_ID', 'indexredirect');

/**
 * Install/Uninstall — можно оставить пустыми, AF сам ведёт enabled-настройку.
 */
function af_indexredirect_install(): bool
{
    return true;
}

function af_indexredirect_uninstall(): bool
{
    return true;
}

/**
 * Хук: global_start (через ядро AF)
 * Редиректим ТОЛЬКО если реально запросили /index.php в адресной строке.
 */
function af_indexredirect_init(): void
{
    global $mybb;

    // выключено в настройках (AF обычно создаёт af_{id}_enabled)
    $enabledKey = 'af_' . AF_INDEXREDIRECT_ID . '_enabled';
    if (empty($mybb->settings[$enabledKey])) {
        return;
    }

    // на всякий пожарный — не лезем в ACP/ModCP
    if (defined('IN_ADMINCP') && IN_ADMINCP) {
        return;
    }

    // безопасность: редиректим только GET/HEAD
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET' && $method !== 'HEAD') {
        return;
    }

    // Важно: THIS_SCRIPT == index.php будет и на '/', поэтому этого недостаточно.
    // Нам нужно понять, что в URL прямо присутствует /index.php
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    if ($requestUri === '') {
        return;
    }

    $path = parse_url($requestUri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return;
    }

    // матчим только /index.php (с любыми query/хвостами)
    // НЕ трогаем просто '/' (иначе будет петля)
    if (strcasecmp($path, '/index.php') !== 0) {
        return;
    }

    // куда редиректим
    $base = '';
    if (!empty($mybb->settings['bburl'])) {
        $base = rtrim($mybb->settings['bburl'], '/');
    }

    // если вдруг bburl пустой — собираем из запроса
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = $scheme . '://' . $host;
    }

    $target = $base . '/';

    $query = parse_url($requestUri, PHP_URL_QUERY);
    if (is_string($query) && $query !== '') {
        $target .= '?' . $query;
    }

    // 301 permanent
    header('Location: ' . $target, true, 301);
    exit;
}

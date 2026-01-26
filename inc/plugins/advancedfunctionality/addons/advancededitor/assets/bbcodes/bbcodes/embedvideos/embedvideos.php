<?php
/**
 * AE BBCode Pack: EmbedVideos
 * Tag: [video]URL_OR_IFRAME[/video]
 *
 * IMPORTANT:
 * We do NOT output <iframe> directly here, because many forums sanitize iframes in post bodies.
 * Instead we output a safe placeholder <div data-af-ev-*> and let embedvideos.js render embeds on frontend.
 */

if (!defined('IN_MYBB')) { die('No direct access'); }

function af_ae_bbcode_embedvideos_manifest(): array
{
    static $m = null;
    if (is_array($m)) return $m;

    $path = __DIR__ . '/manifest.php';
    $m = is_file($path) ? (array) require $path : [];
    return $m;
}

function af_ae_bbcode_embedvideos_payload(): array
{
    $m = af_ae_bbcode_embedvideos_manifest();

    $cmd = 'af_embedvideos';
    if (!empty($m['buttons'][0]['cmd'])) {
        $cmd = (string)$m['buttons'][0]['cmd'];
    }

    return [
        'id'        => (string)($m['id'] ?? 'embedvideos'),
        'command'   => $cmd,
        'title'     => (string)($m['title'] ?? 'Вставить видео'),
        'providers' => (array)($m['providers'] ?? []),
    ];
}

/**
 * AE dispatch will call: af_ae_bbcode_embedvideos_parse_end(&$message)
 */
function af_ae_bbcode_embedvideos_parse_end(&$message): void
{
    if (!is_string($message) || $message === '') {
        return;
    }

    // Protect <pre> and <code> blocks from replacements
    $store = [];
    $i = 0;

    $message = preg_replace_callback('~<(pre|code)\b[^>]*>.*?</\1>~is', function($m) use (&$store, &$i) {
        $key = '%%AF_EMBEDVIDEOS_PROTECT_' . (++$i) . '%%';
        $store[$key] = $m[0];
        return $key;
    }, $message);

    // helper: build placeholder
    $build = function (string $fallbackUrlOrText, array $info) {
        $safeUrl = htmlspecialchars_uni($fallbackUrlOrText);
        $fallback = '<a href="' . $safeUrl . '" rel="nofollow ugc noopener" target="_blank">' . $safeUrl . '</a>';

        if (empty($info['type'])) {
            return $fallback;
        }

        $type = htmlspecialchars_uni((string)$info['type']);

        $html = '<div class="af-ev-embed" data-af-ev-type="' . $type . '"';

        if (!empty($info['src'])) {
            $html .= ' data-af-ev-src="' . htmlspecialchars_uni((string)$info['src']) . '"';
        }
        if (!empty($info['id'])) {
            $html .= ' data-af-ev-id="' . htmlspecialchars_uni((string)$info['id']) . '"';
        }
        if (!empty($info['allow'])) {
            $html .= ' data-af-ev-allow="' . htmlspecialchars_uni((string)$info['allow']) . '"';
        }
        if (!empty($info['allowfullscreen'])) {
            $html .= ' data-af-ev-allowfullscreen="1"';
        }

        $html .= '>' . $fallback . '</div>';

        return $html;
    };

    // Основное: [video]...[/video]
    if (stripos($message, '[video]') !== false) {
        $message = preg_replace_callback('~\[video\](.*?)\[/video\]~is', function($m) use ($build) {
            $raw = trim((string)$m[1]);
            if ($raw === '') return $m[0];

            // 1) iframe-режим (для “Другой”)
            $iframe = af_ae_embedvideos_extract_iframe($raw);
            if (!empty($iframe['src'])) {
                $info = [
                    'type' => 'iframe_raw',
                    'src'  => $iframe['src'],
                    'allow' => $iframe['allow'] ?? '',
                    'allowfullscreen' => !empty($iframe['allowfullscreen']) ? 1 : 0,
                ];

                // fallback ссылкой — по src (чтобы было куда кликнуть, если JS отключён)
                return $build($iframe['src'], $info);
            }

            // 2) обычный URL
            $url = af_ae_embedvideos_normalize_url($raw);
            if ($url === '') return $m[0];

            $info = af_ae_embedvideos_build_embed_info($url);
            return $build($url, $info);
        }, $message);
    }

    if (!empty($store)) {
        $message = strtr($message, $store);
    }
}

/* =========================
 * Helpers
 * ========================= */

function af_ae_embedvideos_extract_iframe(string $html): array
{
    $html = trim($html);
    if ($html === '') return [];

    // Быстрая проверка
    if (stripos($html, '<iframe') === false) return [];

    // Вытащим атрибуты из первого iframe
    if (!preg_match('~<iframe\b([^>]*)>~i', $html, $m)) {
        return [];
    }

    $attrs = (string)$m[1];

    $getAttr = function(string $name) use ($attrs): string {
        // name="..." | name='...' | name=...
        if (preg_match('~\b' . preg_quote($name, '~') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))~i', $attrs, $mm)) {
            $v = $mm[1] !== '' ? $mm[1] : ($mm[2] !== '' ? $mm[2] : ($mm[3] ?? ''));
            $v = html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8');
            return trim($v);
        }
        return '';
    };

    $src = $getAttr('src');
    if ($src === '') return [];

    // Нормализуем src (разрешаем только http/https)
    $src = html_entity_decode($src, ENT_QUOTES, 'UTF-8');
    $src = trim($src);

    if (strpos($src, '//') === 0) {
        $src = 'https:' . $src;
    }
    if (!preg_match('~^https?://~i', $src)) {
        // если без протокола — аккуратно добавим
        $src = 'https://' . ltrim($src, '/');
    }

    $p = @parse_url($src);
    if (!is_array($p) || empty($p['host'])) return [];

    $allow = $getAttr('allow');
    $allowfullscreen = false;
    if (preg_match('~\ballowfullscreen\b~i', $attrs)) {
        $allowfullscreen = true;
    }

    return [
        'src' => $src,
        'allow' => $allow,
        'allowfullscreen' => $allowfullscreen ? 1 : 0,
    ];
}

function af_ae_embedvideos_normalize_url(string $u): string
{
    $u = trim($u);
    if ($u === '') return '';

    // важное: в HTML часто &amp;
    $u = html_entity_decode($u, ENT_QUOTES, 'UTF-8');
    $u = trim($u);

    if ($u === '') return '';

    if (strpos($u, '//') === 0) {
        $u = 'https:' . $u;
    }

    if (!preg_match('~^https?://~i', $u)) {
        $u = 'https://' . $u;
    }

    $p = @parse_url($u);
    if (!is_array($p) || empty($p['host'])) return '';

    return $u;
}

/**
 * Types:
 * - youtube, rutube, coub, kodik, telegram, telegram_iframe
 */
function af_ae_embedvideos_build_embed_info(string $url): array
{
    $p = @parse_url($url);
    if (!is_array($p) || empty($p['host'])) return [];

    $host  = strtolower((string)$p['host']);
    $path  = (string)($p['path'] ?? '');
    $query = (string)($p['query'] ?? '');

    // ---- YouTube ----
    if ($host === 'youtu.be') {
        $id = trim($path, '/');
        if ($id !== '') {
            return [
                'type' => 'youtube',
                'src'  => 'https://www.youtube.com/embed/' . rawurlencode($id),
                'id'   => $id,
            ];
        }
        return [];
    }

    if (strpos($host, 'youtube.com') !== false || strpos($host, 'm.youtube.com') !== false) {
        parse_str($query, $qs);
        $id = isset($qs['v']) ? (string)$qs['v'] : '';
        if ($id !== '') {
            return [
                'type' => 'youtube',
                'src'  => 'https://www.youtube.com/embed/' . rawurlencode($id),
                'id'   => $id,
            ];
        }
        return [];
    }

    // ---- RuTube ----
    if ($host === 'rutube.ru') {
        if (preg_match('~^/video/([a-f0-9]{16,})/?~i', $path, $mm)) {
            $uuid = $mm[1];
            return [
                'type' => 'rutube',
                'src'  => 'https://rutube.ru/play/embed/' . rawurlencode($uuid),
                'id'   => $uuid,
            ];
        }
        return [];
    }

    // ---- Coub ----
    if ($host === 'coub.com') {
        if (preg_match('~^/view/([a-z0-9]+)~i', $path, $mm)) {
            $code = $mm[1];
            return [
                'type' => 'coub',
                'src'  => 'https://coub.com/embed/' . rawurlencode($code),
                'id'   => $code,
            ];
        }
        return [];
    }

    // ---- Telegram ----
    if ($host === 't.me') {
        if (preg_match('~^/([a-z0-9_]+)/(\d+)~i', $path, $mm)) {
            $post = $mm[1] . '/' . $mm[2];
            return [
                'type' => 'telegram',
                'id'   => $post,
            ];
        }

        $base = 'https://t.me' . $path;
        $qs = [];
        if ($query !== '') parse_str($query, $qs);
        $qs['embed'] = '1';
        $final = $base . '?' . http_build_query($qs);

        return [
            'type' => 'telegram_iframe',
            'src'  => $final,
        ];
    }

    // ---- Kodik ----
    if ($host === 'kodik.info' || substr($host, -10) === '.kodik.info') {
        return [
            'type' => 'kodik',
            'src'  => $url,
        ];
    }

    return [];
}

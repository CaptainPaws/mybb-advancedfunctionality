<?php
/** Resolve post quotes through MyBB's current-reader permissions. */
function af_advancededitor_quote_metadata(int $pid): array
{
    global $mybb;
    static $cache = [];
    if ($pid <= 0 || !function_exists('get_post') || !function_exists('get_thread')
        || !function_exists('get_forum') || !function_exists('forum_permissions')) return [];
    if (isset($cache[$pid])) return $cache[$pid];
    $cache[$pid] = [];
    $post = get_post($pid);
    if (!$post || (int)$post['visible'] !== 1) return [];
    $thread = get_thread((int)$post['tid']);
    if (!$thread || (int)$thread['visible'] !== 1) return [];
    $permissions = forum_permissions((int)$post['fid']);
    if (empty($permissions['canview']) || empty($permissions['canviewthreads'])) return [];
    if (!empty($permissions['canonlyviewownthreads']) && (int)$thread['uid'] !== (int)$mybb->user['uid']) return [];
    $forum = get_forum((int)$post['fid']);
    if (!$forum) return [];
    // A parser must never redirect to a password form or reveal its author.
    foreach (explode(',', (string)($forum['parentlist'] ?? $post['fid'])) as $fid) {
        $parent = get_forum((int)$fid);
        if (!empty($parent['password']) &&
            ($mybb->cookies['forumpass'][(int)$fid] ?? '') !== md5($mybb->user['uid'] . $parent['password'])) return [];
    }
    $user = !empty($post['uid']) && function_exists('get_user') ? get_user((int)$post['uid']) : [];
    $name = (string)($user['username'] ?? $post['username'] ?? '');
    $avatar = '';
    if (!empty($user['avatar']) && function_exists('format_avatar')) {
        $formatted = format_avatar($user['avatar'], $user['avatardimensions'] ?? '', '24x24');
        $avatar = (string)($formatted['image'] ?? '');
    }
    return $cache[$pid] = ['pid' => $pid, 'author' => $name, 'avatar' => $avatar];
}

function af_advancededitor_quote_source_metadata(string $message): string
{
    // Code samples are literal and must never resolve/alter their quote text.
    $parts = preg_split('~(\[(?:code|php)\][\s\S]*?\[/(?:code|php)\])~i', $message, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $index => &$part) {
        if ($index % 2) continue;
        $part = preg_replace_callback('~\[quote=(?:""|\'\')(?P<attrs>\s+[^\]]*)\]~i', static function ($match) {
            if (!preg_match('~\bpid\s*=\s*["\']?(\d+)~i', $match['attrs'], $id)) return $match[0];
            $metadata = af_advancededitor_quote_metadata((int)$id[1]);
            if (empty($metadata['author'])) return $match[0];
            $author = str_replace(['&', '"', '[', ']'], ['&amp;', '&quot;', '&#91;', '&#93;'], $metadata['author']);
            return '[quote="' . $author . '"' . $match['attrs'] . ']';
        }, $part) ?? $part;
    }
    unset($part);
    return implode('', $parts);
}

function af_advancededitor_quote_html_metadata(string $message): string
{
    return preg_replace_callback('~(?P<open><blockquote\b[^>]*\bclass=["\'][^"\']*\bmycode_quote\b[^"\']*["\'][^>]*>)\s*<cite(?P<attrs>[^>]*)>(?P<header>[\s\S]*?)</cite>~i', static function ($match) {
        if (!preg_match('~\bhref=["\'][^"\']*[?&](?:amp;)?pid=(\d+)~i', $match['header'], $id)) return $match[0];
        $metadata = af_advancededitor_quote_metadata((int)$id[1]);
        if (!$metadata) return $match[0];
        $escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $open = str_contains($match['open'], 'data-pid=') ? $match['open'] : substr($match['open'], 0, -1) . ' data-pid="' . (int)$id[1] . '" data-author="' . $escape($metadata['author']) . '">';
        $avatar = '';
        if ($metadata['avatar'] !== '' && !str_contains($match['header'], 'af-qa-avatar')) {
            $avatar = '<img class="af-qa-avatar" src="' . $escape($metadata['avatar']) . '" width="24" height="24" alt="" loading="lazy" />';
        }
        return $open . '<cite' . $match['attrs'] . '>' . $avatar . $match['header'] . '</cite>';
    }, $message) ?? $message;
}

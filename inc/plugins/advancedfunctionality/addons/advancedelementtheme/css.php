<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Values are single declaration values, never a second declaration or HTML. */
function af_elementtheme_validate_value(string $value): void
{
    if (strlen($value) > 4096 || preg_match('/[;{}<>\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)) {
        throw new InvalidArgumentException('Недопустимое значение CSS variable.');
    }
    af_elementtheme_validate_css_balance($value, false);
}

/** Only scoped style rules and conditional groups; no global CSS definitions. */
function af_elementtheme_validate_custom_css(string $css): void
{
    if (strlen($css) > 65535 || preg_match('~</style|[\x00-\x08\x0b\x0c\x0e-\x1f]~i', $css)) {
        throw new InvalidArgumentException('Недопустимый Custom CSS.');
    }
    af_elementtheme_validate_css_balance($css, true);
}

/** Lex strings/comments/escapes before validating delimiters and @rules. */
function af_elementtheme_validate_css_balance(string $css, bool $rules): void
{
    $stack = []; $quote = ''; $comment = false; $length = strlen($css);
    for ($i = 0; $i < $length; ++$i) {
        $char = $css[$i]; $next = $css[$i + 1] ?? '';
        if ($comment) {
            if ($char === '*' && $next === '/') { $comment = false; ++$i; }
            continue;
        }
        if ($quote !== '') {
            if ($char === '\\') { ++$i; continue; }
            if ($char === $quote) $quote = '';
            elseif ($char === "\n" || $char === "\r") throw new InvalidArgumentException('Незакрытая строка CSS.');
            continue;
        }
        if ($char === '/' && $next === '*') { $comment = true; ++$i; continue; }
        if ($char === '"' || $char === "'") { $quote = $char; continue; }
        // Escaped syntax outside strings makes validation ambiguous. Quoted URLs/content may contain escapes.
        if ($char === '\\') throw new InvalidArgumentException('CSS escapes вне строк не поддерживаются.');
        if ($char === '@') {
            if (!$rules || !preg_match('/\G@(media|supports|container)(?=[\s(])/i', $css, $match, 0, $i)) {
                throw new InvalidArgumentException('Разрешены только @media, @supports и @container внутри scope.');
            }
            $i += strlen($match[0]) - 1;
            continue;
        }
        if (str_contains('([{', $char)) {
            if (!$rules && $char === '{') throw new InvalidArgumentException('Недопустимое значение CSS variable.');
            $stack[] = $char;
        } elseif (str_contains(')]}', $char)) {
            $expected = [')' => '(', ']' => '[', '}' => '{'][$char];
            if (array_pop($stack) !== $expected) throw new InvalidArgumentException('Несбалансированные скобки CSS.');
        }
    }
    if ($stack || $quote !== '' || $comment) throw new InvalidArgumentException('Незакрытый CSS блок, строка или комментарий.');
}

/** Prioritize declarations only; leave selectors, conditional groups and strings intact. */
function af_elementtheme_prioritize_custom_css(string $css): string
{
    $out = ''; $start = 0; $depth = 0; $parens = 0; $brackets = 0;
    $quote = ''; $comment = false; $length = strlen($css);
    $declaration = static function (string $text, int $depth): string {
        $plain = preg_replace('~/\*.*?\*/~s', '', $text);
        if ($depth > 0 && preg_match('/^\s*(?:--[a-z0-9_-]+|[a-z-]+)\s*:/i', $plain)
            && !preg_match('/!\s*important\s*$/i', $plain)) return rtrim($text) . ' !important';
        return $text;
    };
    for ($i = 0; $i < $length; ++$i) {
        $char = $css[$i]; $next = $css[$i + 1] ?? '';
        if ($comment) { if ($char === '*' && $next === '/') { $comment = false; ++$i; } continue; }
        if ($quote !== '') { if ($char === '\\') ++$i; elseif ($char === $quote) $quote = ''; continue; }
        if ($char === '/' && $next === '*') { $comment = true; ++$i; continue; }
        if ($char === '"' || $char === "'") { $quote = $char; continue; }
        if ($char === '(') ++$parens;
        elseif ($char === ')') --$parens;
        elseif ($char === '[') ++$brackets;
        elseif ($char === ']') --$brackets;
        if ($parens || $brackets) continue;
        if ($char === '{') {
            $out .= substr($css, $start, $i - $start) . '{'; ++$depth; $start = $i + 1;
        } elseif ($char === ';' || $char === '}') {
            $out .= $declaration(substr($css, $start, $i - $start), $depth) . $char;
            if ($char === '}') --$depth;
            $start = $i + 1;
        }
    }
    return $out . substr($css, $start);
}

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

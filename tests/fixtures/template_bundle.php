<?php
// AF core's template-bundle boundary for isolated addon lifecycle fixtures.
function af_parse_templates_bundle(string $raw): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $out = [];
    if (preg_match_all('~<!--\s*TEMPLATE:\s*([a-zA-Z0-9_\-\.]+)\s*-->\s*(.*?)(?=(?:<!--\s*TEMPLATE:)|\z)~s', $raw, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $out[trim($match[1])] = trim(preg_replace("~\n{3,}~", "\n\n", $match[2]));
        }
    }
    return $out;
}

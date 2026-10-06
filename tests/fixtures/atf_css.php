<?php
/** Read precisely the manifest-owned CSS delivered to a route. */
function atf_test_css(string $addon, string $script, bool $modals = false): string
{
    $manifest = require $addon . '/manifest.php';
    $css = '';
    foreach ($manifest['theme_stylesheets'] as $source) {
        if (!empty($source['conditional'])) {
            if (!$modals) continue;
        } else {
            $matches = false;
            foreach ($source['attach'] as $attach) {
                if (in_array($attach['file'], ['global', $script], true)) $matches = true;
            }
            if (!$matches) continue;
        }
        $css .= file_get_contents($addon . '/' . $source['file']) . "\n";
    }
    return $css;
}

<?php
declare(strict_types=1);

function kb_character_ajax_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$root = __DIR__ . '/../inc/plugins/advancedfunctionality/addons/knowledgebase/';
$php = file_get_contents($root . 'knowledgebase.php');
$editorJs = file_get_contents($root . 'assets/knowledgebase.js');
$js = file_get_contents($root . 'assets/knowledgebase_chips.js');
$template = file_get_contents($root . 'templates/knowledgebase_list_character.html');

kb_character_ajax_assert(is_string($php) && is_string($editorJs) && is_string($js) && is_string($template), 'Unable to read Character AJAX sources');
kb_character_ajax_assert(strpos($template, 'id="kb-character-results"') !== false, 'Character results wrapper is missing');
kb_character_ajax_assert(strpos($template, '{$kb_character_results}') !== false, 'Character page does not use the shared results fragment');
kb_character_ajax_assert(strpos($php, 'if ($isCharacterList && $isAjax)') !== false, 'AJAX response is not restricted to Character lists');
kb_character_ajax_assert(strpos($php, 'echo $kb_character_results;') !== false, 'AJAX response does not reuse the normal Character results renderer');
kb_character_ajax_assert(strpos($php, '<button type="submit" class="af-kb-btn">Применить</button>') !== false, 'Apply control is no longer a progressive-enhancement submit button');
kb_character_ajax_assert(strpos($js, 'function initCharacterAjaxFilters()') !== false, 'Character AJAX is missing from the lightweight view runtime');
kb_character_ajax_assert(strpos($editorJs, 'function initCharacterAjaxFilters()') === false, 'Character AJAX is duplicated in the editor-only runtime');

foreach (['kind', 'gender', 'origin', 'element'] as $parameter) {
    kb_character_ajax_assert(strpos($js, "'" . $parameter . "'") !== false, 'AJAX client lost GET parameter: ' . $parameter);
}
kb_character_ajax_assert(strpos($js, "url.searchParams.delete('page')") !== false, 'Changing filters no longer resets pagination');
kb_character_ajax_assert(strpos($js, 'controller.abort()') !== false, 'Previous Character request is not aborted');
kb_character_ajax_assert(strpos($js, 'requestSequence !== sequence') !== false, 'Stale Character response guard is missing');
kb_character_ajax_assert(strpos($js, 'window.history.pushState') !== false, 'Character filter URL history update is missing');
kb_character_ajax_assert(strpos($js, "window.addEventListener('popstate'") !== false, 'Back/Forward restoration is missing');
kb_character_ajax_assert(strpos($js, ".closest('.af-kb-pagination a')") !== false, 'AJAX pagination delegation is missing');
kb_character_ajax_assert(
    preg_match(
        "/form\\.addEventListener\\('change',[\\s\\S]*?event\\.preventDefault\\(\\);[\\s\\S]*?event\\.stopPropagation\\(\\);[\\s\\S]*?load\\(urlFromForm\\(\\), true\\);/",
        $js
    ) === 1,
    'Character select changes can escape into legacy GET navigation'
);
kb_character_ajax_assert(
    preg_match(
        "/form\\.addEventListener\\('submit', function \\(event\\) \\{\\s*event\\.preventDefault\\(\\);\\s*load\\(urlFromForm\\(\\), true\\);/",
        $js
    ) === 1,
    'Character native submit is not cancelled synchronously before the AJAX load'
);
kb_character_ajax_assert(
    strpos($js, "document.addEventListener('DOMContentLoaded', function () {") < strpos($js, 'initCharacterAjaxFilters();'),
    'Character AJAX is not initialized by the delivered view runtime'
);

echo "KB Character AJAX regression checks passed.\n";

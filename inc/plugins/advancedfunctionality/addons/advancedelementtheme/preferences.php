<?php
if (!defined('IN_MYBB')) die('No direct access');

/** Reuse ATF's shared presentation store; one compact JSON row per viewer UID. */
function af_elementtheme_preferences_table(): string { return 'af_presentation_preferences'; }
function af_elementtheme_preferences_defaults(): array
{
    return array_fill_keys(['effects_enabled', 'effects_profile', 'effects_sheet', 'effects_application', 'effects_postbit'], true);
}
function af_elementtheme_preferences_normalize(array $values): array
{
    $out = af_elementtheme_preferences_defaults();
    foreach ($out as $name => $_) if (array_key_exists($name, $values)) {
        $out[$name] = in_array($values[$name], [true, 1, '1'], true);
    }
    return $out;
}
function af_elementtheme_preferences_schema(): void
{
    global $db;
    if ($db->table_exists(af_elementtheme_preferences_table())) return;
    $db->write_query('CREATE TABLE IF NOT EXISTS '.TABLE_PREFIX.af_elementtheme_preferences_table()." (
        uid int unsigned NOT NULL, preference_key varchar(64) NOT NULL DEFAULT '',
        preference_value varchar(255) NOT NULL DEFAULT '', updated_at int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (uid, preference_key), KEY preference_key (preference_key)
    )".$db->build_create_table_collation());
}
function af_elementtheme_preferences_available(): bool
{
    if (!array_key_exists('af_elementtheme_preferences_available', $GLOBALS)) {
        $GLOBALS['af_elementtheme_preferences_available'] = isset($GLOBALS['db']) && $GLOBALS['db']->table_exists(af_elementtheme_preferences_table());
    }
    return $GLOBALS['af_elementtheme_preferences_available'];
}
function af_elementtheme_preferences(): array
{
    global $mybb, $db;
    $uid = max(0, (int)($mybb->user['uid'] ?? 0));
    if (isset($GLOBALS['af_elementtheme_preferences'][$uid])) return $GLOBALS['af_elementtheme_preferences'][$uid];
    $values = [];
    if ($uid > 0 && af_elementtheme_preferences_available()) {
        $json = $db->fetch_field($db->simple_select(af_elementtheme_preferences_table(), 'preference_value',
            "uid='{$uid}' AND preference_key='element_effects'", ['limit'=>1]), 'preference_value');
        $decoded = json_decode((string)$json, true);
        if (is_array($decoded)) $values = $decoded;
    }
    return $GLOBALS['af_elementtheme_preferences'][$uid] = af_elementtheme_preferences_normalize($values);
}
function af_elementtheme_preferences_bootstrap(): string
{
    $payload = ['uid'=>max(0, (int)($GLOBALS['mybb']->user['uid'] ?? 0)), 'preferences'=>af_elementtheme_preferences()];
    return '<script type="application/json" data-af-element-preferences>'.json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).'</script>';
}

/** Provider content, never an independent menu item or a second settings page. */
function af_elementtheme_render_preferences_widget(array $item = []): string
{
    global $mybb;
    $uid = (int)($mybb->user['uid'] ?? 0);
    $preferences = af_elementtheme_preferences();
    $root = rtrim((string)($mybb->settings['bburl'] ?? ''), '/');
    $assets = $root.'/inc/plugins/advancedfunctionality/addons/advancedelementtheme/assets/';
    $html = '<link rel="stylesheet" href="'.htmlspecialchars_uni($assets.'element-preferences.css?v='.filemtime(__DIR__.'/assets/element-preferences.css')).'">'
        .af_elementtheme_preferences_bootstrap()
        .'<script src="'.htmlspecialchars_uni($assets.'element-preferences.js?v='.filemtime(__DIR__.'/assets/element-preferences.js')).'" defer></script>'
        .'<form class="af-element-preferences" data-af-element-preferences-form data-uid="'.$uid.'" method="post" action="'.htmlspecialchars_uni($root.'/misc.php?action=af_element_preferences').'">'
        .'<input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni((string)($mybb->post_code ?? '')).'">'
        .'<fieldset><legend>Стихийные анимации</legend><p>Настройте эффекты для разных частей форума. Цвета и фоны сохраняются.</p>';
    foreach (['effects_enabled'=>'Все анимации', 'effects_profile'=>'Профили', 'effects_sheet'=>'Листы персонажей', 'effects_application'=>'Анкеты персонажей', 'effects_postbit'=>'Postbit'] as $key => $label) {
        $html .= '<label class="af-element-preference-row"><span>'.$label.'</span><input type="hidden" name="'.$key.'" value="0"><input type="checkbox" name="'.$key.'" value="1"'.($preferences[$key] ? ' checked' : '').'><span class="af-element-preference-switch" aria-hidden="true"></span></label>';
    }
    return $html.'</fieldset><p class="af-element-preferences-status" role="status" aria-live="polite"></p>'
        .'<small>'.($uid > 0 ? 'Настройки сохраняются для вашего аккаунта.' : 'Гостевые настройки сохраняются только в этом браузере.').'</small></form>';
}

/** Testable request boundary: never accepts a target UID from client input. */
function af_elementtheme_preferences_save_request(): array
{
    global $mybb, $db;
    if ($mybb->request_method !== 'post') return [405, ['error'=>'Требуется POST.']];
    $uid = (int)($mybb->user['uid'] ?? 0);
    if ($uid <= 0) return [403, ['error'=>'Войдите в аккаунт.']];
    if (!verify_post_check($mybb->get_input('my_post_key'), true)) return [403, ['error'=>'Сессия устарела. Обновите страницу.']];
    $values = [];
    foreach (af_elementtheme_preferences_defaults() as $key => $_) {
        $value = $mybb->get_input($key);
        if (!in_array($value, ['0', '1'], true)) return [422, ['error'=>'Недопустимое значение настройки.']];
        $values[$key] = $value === '1';
    }
    if (!af_elementtheme_preferences_available()) return [503, ['error'=>'Хранилище настроек недоступно. Администратору нужно выполнить upgrade AdvancedElementTheme.']];
    try {
        $result = $db->replace_query(af_elementtheme_preferences_table(), ['uid'=>$uid, 'preference_key'=>'element_effects',
            'preference_value'=>$db->escape_string(json_encode($values)), 'updated_at'=>TIME_NOW]);
        if ($result === false) throw new RuntimeException('Preference write failed');
    } catch (Throwable $e) {
        error_log('[AdvancedElementTheme] preference save failed');
        return [500, ['error'=>'Не удалось сохранить настройки. Повторите попытку.']];
    }
    $GLOBALS['af_elementtheme_preferences'][$uid] = $values;
    return [200, ['preferences'=>$values]];
}
function af_elementtheme_preferences_misc(): void
{
    global $mybb;
    if ($mybb->get_input('action') !== 'af_element_preferences') return;
    [$status, $body] = af_elementtheme_preferences_save_request();
    if ($status === 200 && strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') { redirect('index.php'); return; }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    exit;
}

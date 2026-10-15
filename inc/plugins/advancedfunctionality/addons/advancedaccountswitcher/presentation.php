<?php
/** Shared account UI; native UCP handlers retain all mutation and auth checks. */
if (!defined('IN_MYBB') || !defined('AF_ADDONS')) die('No direct access');
function af_aas_is_ajax(): bool
{
    global $mybb;
    return defined('THIS_SCRIPT') && THIS_SCRIPT === 'usercp.php'
        && $mybb->get_input('action') === 'af_aas' && $mybb->request_method === 'post'
        && $mybb->get_input('aas_ajax') === '1';
}
function af_aas_prepare_ajax(): void
{
    global $plugins;
    if (!af_aas_is_ajax()) return;
    af_aas_walk_begin_json_transport();
    $plugins->add_hook('error', 'af_aas_ui_error', 10000);
}
function af_aas_ui_error(&$message): void
{
    if (af_aas_is_ajax()) af_aas_ui_json(['ok'=>false,'message'=>html_entity_decode(strip_tags((string)$message),ENT_QUOTES,'UTF-8')]);
}
function af_aas_ui_json(array $data): void { af_aas_walk_json($data); }
function af_aas_ui_template(string $name, array $vars): string
{
    global $templates;
    if (!in_array($name,['create','link','workspace'],true)) return '';
    extract($vars, EXTR_SKIP);
    $templates->cache['af_aas_ui_'.$name] = file_get_contents(__DIR__.'/templates/ui_'.$name.'.html');
    $out = '';
    eval('$out = "'.$templates->get('af_aas_ui_'.$name).'";');
    return $out;
}
function af_aas_add_forms(string $prefix): string
{
    global $mybb, $lang;
    if ((int)$mybb->user['uid'] !== af_aas_get_master_uid((int)$mybb->user['uid'])) return '';
    $vars = ['prefix'=>$prefix, 'action'=>htmlspecialchars_uni(rtrim((string)$mybb->settings['bburl'],'/').'/usercp.php?action=af_aas'),
        'token'=>htmlspecialchars_uni((string)$mybb->post_code), 'hint'=>htmlspecialchars_uni((string)($lang->af_aas_ucp_create_hint ?? 'Действуют настройки создания дополнительных аккаунтов.'))];
    $forms = '';
    if (!empty($mybb->settings['af_advancedaccountswitcher_allow_create'])) $forms .= af_aas_ui_template('create',$vars);
    if (!empty($mybb->settings['af_advancedaccountswitcher_allow_link_existing'])) $forms .= af_aas_ui_template('link',$vars);
    return $forms;
}
function af_aas_workspace(): string
{
    global $mybb, $lang, $templates;
    $raw = file_get_contents(__DIR__.'/templates/advancedaccountswitcher.html');
    if (preg_match('~<!-- TEMPLATE: af_aas_usercp_atf -->\s*([\s\S]*?)\s*<!-- /TEMPLATE -->~', $raw, $match)) {
        $templates->cache['af_aas_usercp_atf'] = preg_replace('~<header class="atf-ucp__header">[\s\S]*?</header>~', '', $match[1]);
    }
    $uid = (int)$mybb->user['uid'];
    return af_aas_ui_template('workspace', [
        'cards'=>af_aas_render_panel_widget(true), 'forms'=>af_aas_add_forms('aas-ucp'),
        'action'=>htmlspecialchars_uni(rtrim((string)$mybb->settings['bburl'],'/').'/usercp.php?action=af_aas'),
        'walk'=>htmlspecialchars_uni(rtrim((string)$mybb->settings['bburl'],'/').'/misc.php?action=af_aas_walk'),
        'token'=>htmlspecialchars_uni((string)$mybb->post_code),
        'checked'=>!empty($mybb->user['af_aas_hide_in_list']) ? 'checked' : '',
        'privacy_hint'=>htmlspecialchars_uni((string)($lang->af_aas_privacy_hint ?? 'Если включено — в списке пользователей не будет показан мастер-аккаунт и привязки связанных аккаунтов.')),
    ]);
}

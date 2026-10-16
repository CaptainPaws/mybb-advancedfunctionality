<?php
if (!defined('IN_MYBB') || !defined('AF_ADDONS')) die('AdvancedFunctionality required');
define('AF_ASB_ID','advancedsignaturebuilder');
define('AF_ASB_BASE',__DIR__.'/');
foreach (['schema','model','storage','renderer','preview','ajax','publishing','integrations','usercp','assets'] as $module) require_once AF_ASB_BASE.'modules/'.$module.'.php';
function af_advancedsignaturebuilder_install(): void { af_advancedsignaturebuilder_activate(); }
function af_advancedsignaturebuilder_activate(): void {
    af_asb_install_schema();af_asb_install_settings();
    if (function_exists('rebuild_settings')) rebuild_settings();
    if (function_exists('af_sync_theme_stylesheets')) af_sync_theme_stylesheets(false,AF_ASB_ID);
}
function af_advancedsignaturebuilder_upgrade(): void { af_advancedsignaturebuilder_activate(); }
function af_advancedsignaturebuilder_deactivate(): void { /* No template leases or runtime overrides persist. */ }
function af_advancedsignaturebuilder_uninstall(): void {
    // Intentionally retain signatures, drafts, legacy backup and portable users.signature.
    global $db;
    $db->delete_query('settings',"name LIKE 'af_advancedsignaturebuilder_%'");
    $db->delete_query('settinggroups',"name='af_advancedsignaturebuilder'");
    if (function_exists('rebuild_settings')) rebuild_settings();
}
function af_asb_enabled(): bool { return (string)($GLOBALS['mybb']->settings['af_advancedsignaturebuilder_enabled']??'0')==='1'; }
function af_advancedsignaturebuilder_init(): void {
    global $plugins;
    if (!af_asb_enabled() || !empty($GLOBALS['af_asb_initialized'])) return;
    $GLOBALS['af_asb_initialized']=true;
    $plugins->add_hook('usercp_start','af_asb_start',100);
    $plugins->add_hook('usercp_editsig_end','af_asb_prepare_page',900);
    $plugins->add_hook('usercp_do_editsig_start','af_asb_publish_start',1000);
    $plugins->add_hook('usercp_do_editsig_end','af_asb_publish_end',1000);
    $plugins->add_hook('usercp_do_editsig_end','af_asb_saved_redirect',1100);
    foreach (['postbit','postbit_prev','postbit_pm'] as $hook) $plugins->add_hook($hook,'af_asb_postbit',110);
    $plugins->add_hook('pre_output_page','af_asb_pre_output',1400);
    if (function_exists('af_adaptivethemeframework_register_component')) af_adaptivethemeframework_register_component([
        'owner'=>AF_ASB_ID,'key'=>'signature_builder','slot'=>'ucp.content','section_title'=>'Конструктор подписи',
        'context'=>static fn($ctx)=>($ctx['route']['action']??'')==='editsig',
        'renderer'=>static fn()=> (string)($GLOBALS['af_asb_builder_html']??''),
    ]);
}

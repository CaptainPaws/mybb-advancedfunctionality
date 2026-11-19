<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

final class AF_ACS_StandalonePage
{
    public static function output(string $title, string $body, string $assets = ''): never
    {
        global $mybb, $header, $headerinclude, $footer;
        $heading=htmlspecialchars_uni($title);
        $style='';
        if (af_frontend_asset_allowed('advancedcombatsystem','standalone',null,['has_acs_standalone'=>true])) {
            $url=rtrim((string)$mybb->settings['bburl'],'/').'/inc/plugins/advancedfunctionality/addons/advancedcombatsystem/assets/standalone.css?v=1300';
            $style='<link rel="stylesheet" href="'.htmlspecialchars_uni($url).'">';
        }
        header('Cache-Control: private, no-store');
        output_page('<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.$heading.'</title>'.$headerinclude.$assets.$style.'</head><body>'.str_replace('<navigation>','',(string)$header)
            .'<main class="pun atf-page-shell af-acs-standalone"><navigation><h1>'.$heading.'</h1>'.$body.'</main>'
            .$footer.'<atf-footer-placement></atf-footer-placement></body></html>');
        exit;
    }
}

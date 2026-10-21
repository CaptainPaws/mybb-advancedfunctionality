<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Preparation only: no SnapshotStore/session/entity or public endpoint. */
final class AF_ACS_CharacterSheetAdapter
{
    public function createSnapshot(int $sheetId, int $actorUid, array $context = []): array
    {
        global $mybb;
        if ($actorUid <= 0 || $actorUid !== (int)($mybb->user['uid'] ?? 0)) {
            return ['ready'=>false,'diagnostics'=>[['code'=>'access_denied','path'=>'actor','severity'=>'error']]];
        }
        if (!function_exists('af_is_addon_enabled') || !af_is_addon_enabled('advancedcombatsystem')
            || !af_is_addon_enabled('charactersheets') || !empty($GLOBALS['af_acs_deactivated'])) {
            return ['ready'=>false,'diagnostics'=>[['code'=>'dependency_unavailable','path'=>'ACS/CharacterSheets','severity'=>'error']]];
        }
        require_once AF_ADDONS . 'charactersheets/charactersheets.php';
        return af_charactersheets_create_combat_snapshot($sheetId,$actorUid,$context);
    }
}

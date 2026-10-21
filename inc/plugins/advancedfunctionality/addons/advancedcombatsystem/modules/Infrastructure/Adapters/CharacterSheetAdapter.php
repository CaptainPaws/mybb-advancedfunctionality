<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/CapabilityValidator.php';

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
        try {
            require_once AF_ADDONS . 'charactersheets/charactersheets.php';
            if (!function_exists('af_charactersheets_create_combat_snapshot')) return ['ready'=>false,'diagnostics'=>[['code'=>'unsupported_character_contract','path'=>'CharacterSheets','severity'=>'error']]];
            $snapshot = af_charactersheets_create_combat_snapshot($sheetId,$actorUid,$context);
            if (!isset($snapshot['identity'])) return $snapshot; // Preserve the owner's minimal denial response.
            $validation = AF_ACS_CapabilityValidator::character($snapshot);
            $snapshot['ready'] = $validation['ready'];
            $snapshot['diagnostics'] = $validation['diagnostics'];
            $snapshot['capabilities'] = $validation['capabilities'];
            $snapshot['adapter_version'] = 'acs-character-adapter-1';
            // fingerprint remains the owner-issued snapshot hash, not a hash of this envelope.
            return $snapshot;
        } catch (Throwable $error) {
            return ['ready'=>false,'diagnostics'=>[['code'=>'source_read_failed','path'=>'snapshot','severity'=>'error']]];
        }
    }
}

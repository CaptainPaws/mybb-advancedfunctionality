<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Server-owned authorization policy, never constructed from a request's grants/flags. */
interface AF_ADVINV_OwnerAccess
{
    public function uid(): int;
    /** Must recheck spending/release rights and current scope, including on replay. */
    public function authorize(string $operation, int $ownerUid, array $scope, string $authorizationRef, bool $mutation): void;
}

/** Owner-only Inventory commands. ACS delegation requires a separately proved policy. */
final class AF_ADVINV_MybbOwnerAccess implements AF_ADVINV_OwnerAccess
{
    public function uid(): int
    {
        global $mybb;
        $uid = (int)($mybb->user['uid'] ?? 0);
        if ($uid < 1 || !empty($mybb->usergroup['isbannedgroup']) || empty($mybb->usergroup['canview'])) {
            throw new RuntimeException('INV_ACCESS_DENIED');
        }
        return $uid;
    }

    public function authorize(string $operation, int $ownerUid, array $scope, string $authorizationRef, bool $mutation): void
    {
        global $mybb;
        if ($operation==='grant') throw new RuntimeException('INV_GRANT_NOT_AUTHORIZED');
        if ($ownerUid !== $this->uid() || $scope !== ['system'=>'inventory', 'context_key'=>'owner:'.$ownerUid]
            || $authorizationRef !== 'inventory-owner-v1') {
            throw new RuntimeException('INV_SPENDING_NOT_AUTHORIZED');
        }
        if ($mutation && (($mybb->request_method ?? '') !== 'post'
            || !function_exists('verify_post_check') || !verify_post_check($mybb->get_input('my_post_key'), true))) {
            throw new RuntimeException('INV_CSRF_REQUIRED');
        }
    }
}

/** Narrow server composition; ordinary authorize() retains its authenticated actor policy. */
interface AF_ADVINV_PreparedOwnerPolicy extends AF_ADVINV_OwnerAccess
{
    public function preparedGrantGeneration(array $binding):string;
    public function authorizePreparedObligation(array $binding,array $intent,bool $history):void;
}

<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Thin server binding. Never creates schema, writes Inventory SQL, or uses remove_item.
 * No production gateway is registered until the audited legacy writers participate.
 * A trusted composition root can supply the owner engine; never request data/flags.
 */
final class AF_ACS_InventoryAdapter
{
    public function __construct(private ?object $owner = null) {}

    public function reserve(array $request): array { return $this->call('reserve', $request); }
    public function consume(array $request): array { return $this->call('consume', $request); }
    public function release(array $request): array { return $this->call('release', $request); }
    public function lookup(array $request): array { return $this->call('lookup', $request); }

    private function call(string $operation, array $request): array
    {
        if (!function_exists('af_is_addon_enabled') || !af_is_addon_enabled('advancedinventory')) {
            return ['status'=>'dependency_unavailable','code'=>'ACS_INVENTORY_UNAVAILABLE'];
        }
        if (!$this->owner instanceof AF_ADVINV_OwnerProtocol) {
            // No setting, validated=true, or client capability can bypass this boundary.
            return ['status'=>'dependency_unavailable','code'=>'ACS_INVENTORY_WRITERS_NOT_INTEGRATED'];
        }
        return $this->owner->$operation($request);
    }
}

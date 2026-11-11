<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Default binding stays closed. forCombat explicitly composes the same-writer own-property policy.
 * Never creates schema, writes Inventory SQL, or calls remove_item. No remote commit support.
 */
final class AF_ACS_InventoryAdapter
{
    public function __construct(private ?object $owner = null) {}

    public static function forCombat(object $database,int $actor): self
    {
        if (!function_exists('af_is_addon_enabled') || !af_is_addon_enabled('advancedinventory')) throw new RuntimeException('ACS_INVENTORY_UNAVAILABLE');
        require_once __DIR__.'/CombatInventoryAccess.php';
        $link=$database instanceof mysqli?$database:($database->write_link??null);
        if (!$link instanceof mysqli || isset($database->read_link) && $database->read_link!==$link) throw new RuntimeException('ACS_AUTHORITATIVE_CONNECTION_REQUIRED');
        $owner=new AF_ADVINV_OwnerProtocol($link,new AF_ACS_CombatInventoryAccess($link,$actor));
        $owner->assertStorage();return new self($owner);
    }
    /** Only internal same-writer InnoDB participation, never a remote API fallback. */
    public function participate(string $operation,array $request): AF_ADVINV_ProvisionalOwnerResult
    {
        if (!$this->owner instanceof AF_ADVINV_OwnerProtocol || !af_is_addon_enabled('advancedinventory')) throw new RuntimeException('ACS_INVENTORY_UNAVAILABLE');
        return $this->owner->participate($operation,$request);
    }
    public function lookupParticipant(array $request): array
    {
        if (!$this->owner instanceof AF_ADVINV_OwnerProtocol || !af_is_addon_enabled('advancedinventory')) throw new RuntimeException('ACS_INVENTORY_UNAVAILABLE');
        return $this->owner->lookupParticipant($request);
    }

    public function transfer(array $request): array { return $this->call('transfer',$request); }
    public function grant(array $request): array { return $this->call('grant',$request); }
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
            return ['status'=>'dependency_unavailable','code'=>'ACS_INVENTORY_COMPOSITION_REQUIRED'];
        }
        return $this->owner->$operation($request);
    }
}

<?php
require __DIR__.'/combat_snapshot_readonly.php';
$db->tables=$baseline;
foreach (['af_advinv_owner_state','af_advinv_external_operations','af_advinv_reservations'] as $t) $db->tables[$t]=[];
define('AF_CACHE','/tmp/');
$plugins=new class {public function add_hook(...$args) {}};
require AF_ADDONS.'advancedinventory/advancedinventory.php';
require AF_ADDONS.'advancedcombatsystem/modules/Infrastructure/Adapters/EquipmentAdapter.php';
// Explicit KB slot, using a slot supported by Inventory rather than a historical fixture alias.
$db->tables['af_advinv_equipped'][0]['equip_slot']='accessory_1';
$itemRules['item']['equip']['slots']=['accessory_1'];
$db->tables['af_kb_entries'][1]['data_json']=json_encode($itemRules);
$db->tables['af_kb_entries'][1]['meta_json']=json_encode(['rules'=>$itemRules]);
$cs=$adapter->createSnapshot(3,7);
snapshot_assert($cs['ready'],'CS source unavailable '.json_encode($cs['diagnostics']));
$data=['owner_uid'=>7,'inventory_version'=>1,'total'=>1,'items'=>$db->tables['af_advinv_items'],'equipped'=>$db->tables['af_advinv_equipped'],'support'=>[],'reservations'=>[]];
$read=new class($data) { public function __construct(public array $data){} public function serverData(): array{return $this->data;} };
$equipment=new AF_ACS_EquipmentAdapter();
$bundle=$equipment->prepare($cs,$read,'1',7);
snapshot_assert(count($bundle['records'])===1,'Missing selected item');
snapshot_assert(AF_ACS_Equipment::baseline($bundle,$cs)['accuracy']===25.0,'Bonus applied twice');
snapshot_assert($bundle['records'][0]['kb_ref']===$cs['equipment'][0]['kb_ref'],'KB pin lost');
if (($argv[1]??'')==='--export') file_put_contents($argv[2],json_encode(['bundle'=>$bundle,'cs'=>$cs],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
$db->tables['af_aa_assignments']=[['mask_id'=>999]];
snapshot_assert($bundle===$equipment->prepare($cs,$read,'1',7),'Mask changed equipment');
function equipment_denied(callable $fn): void { try {$fn();} catch(RuntimeException $e) {return;} throw new RuntimeException('Expected blocked provenance'); }
$bad=$bundle;$bad['records'][0]['baseline_included']=false;
equipment_denied(fn()=>AF_ACS_Equipment::baseline($bad,$cs));
$bad=$bundle;$bad['records'][]=$bad['records'][0];$bad['fingerprint']=AF_ACS_Equipment::fingerprint(array_diff_key($bad,['fingerprint'=>1]));
equipment_denied(fn()=>AF_ACS_Equipment::baseline($bad,$cs));
$db->tables['af_kb_entries']=array_values(array_filter($db->tables['af_kb_entries'],fn($r)=>$r['key']!=='ring'));
equipment_denied(fn()=>$equipment->prepare($cs,$read,'1',7));
echo "Equipment provenance: OK\n";

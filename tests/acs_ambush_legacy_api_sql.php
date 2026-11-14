<?php
/** A confirmed legacy pre-round ability is readyable, never silently upgraded to start. */
$GLOBALS['acsPreparedStartFixture']=static function(array $fixture):void {
    extract($fixture);global $mybb;
    require_once AF_ADDONS.'advancedcombatsystem/modules/Presentation/AmbushController.php';
    $paid=$service->publish($publishRequest($sealPlan($actor,'paid-preparation',['prepared_items'=>[['cost_index'=>2,'inventory_item_id'=>'22'],['cost_index'=>3,'inventory_item_id'=>'23']]])));
    $panel=new AF_ACS_AmbushPanelService($repo,$access);$scope=['session_id'=>$sid,'encounter_id'=>$eid];$before=$captureAll();
    $dto=AF_ACS_AmbushController::handle($panel,'ambush_status',$scope,'get');snapshot_assert($dto['prepared_count']===0&&$dto['unsupported_count']===1&&$dto['can_ready']&&$captureAll()===$before,'Unsupported legacy DTO upgraded/discarded paid preparation');
    AF_ACS_AmbushController::handle($panel,'ambush_ready',$ambushRequest(),'post');$ready=$panel->snapshot($sid,$eid);snapshot_assert(!$ready['can_start']&&$ready['unsupported_count']===1,'Unsupported start button enabled');$before=$captureAll();itemFail(fn()=>AF_ACS_AmbushController::handle($panel,'ambush_start',$ambushRequest(),'post'),'ACS_AMBUSH_EXECUTION_BLOCKED');snapshot_assert($captureAll()===$before,'Unsupported API start wrote intent/round or changed reservations');
    echo "ACS ambush legacy API: paid confirmed preparation ready, unsupported start disabled/rejected without writes, reservation and commitment preserved passed\n";
};
require __DIR__.'/acs_pre_round_sql.php';
